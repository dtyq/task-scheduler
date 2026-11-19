<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Service;

use DateTime;
use Dtyq\TaskScheduler\Crontab\TaskSchedulerExecuteCrontab;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerStatus;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use Dtyq\TaskScheduler\Util\Locker;
use Hyperf\Coroutine\Concurrent;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * 用合成任务和Mock持久层验证一小时超时、两小时窗口及一次恢复。
 * @internal
 * @coversNothing
 */
class RunningRecoveryTest extends SchedulerServiceTestCase
{
    /**
     * 一批内只查询一次恢复记录，首次超时归档后恢复，已归档实例直接关闭。
     */
    public function testBatchRecoveryUsesDurableTimeoutRecords(): void
    {
        $now = new DateTime('2030-01-01 12:00:00');
        $tasks = [
            $this->runningTask(10, '2030-01-01 10:30:00'),
            $this->runningTask(20, '2030-01-01 10:40:00'),
        ];
        $tasks[0]->setActualTime(null);
        $this->tasks->expects(self::once())->method('lockStaleRunning')
            ->with($now, new DateTime('2030-01-01 10:00:00'), new DateTime('2030-01-01 11:00:00'), 0, 200)
            ->willReturn($tasks);
        $this->logs->expects(self::once())->method('findTimedOutTaskIds')->with([10, 20])->willReturn([20]);
        $this->logs->expects(self::once())->method('batchCreate')->willReturnCallback(static function (array $logs) use ($now): void {
            self::assertCount(1, $logs);
            $row = $logs[0]->toModelString();
            self::assertSame(10, $row['task_id']);
            self::assertSame(TaskSchedulerStatus::Timeout->value, $row['status']);
            self::assertNull($row['actual_time']);
            self::assertSame($now->format('Y-m-d H:i:s'), $row['created_at']);
        });
        $transitions = [];
        $this->tasks->expects(self::exactly(2))->method('transitionRunning')
            ->willReturnCallback(static function (array $ids, TaskSchedulerStatus $target) use (&$transitions): void {
                $transitions[$target->name] = $ids;
            });
        $this->rules->expects(self::never())->method('disableByIds');
        $result = $this->service->recoverRunningBatch($now, 3600);
        self::assertSame([10], $result['retried']);
        self::assertSame([20], $result['timed_out']);
        self::assertSame(20, $result['last_id']);
        self::assertSame(['Pending' => [10], 'Timeout' => [20]], $transitions);
    }

    /**
     * 配置覆盖实际改变超时边界，不在查询里写死默认一小时。
     */
    public function testConfiguredThresholdChangesStaleBoundary(): void
    {
        $now = new DateTime('2030-01-01 12:00:00');
        $this->tasks->expects(self::once())->method('lockStaleRunning')
            ->with($now, new DateTime('2030-01-01 10:00:00'), new DateTime('2030-01-01 11:30:00'), 0, 200)
            ->willReturn([]);
        self::assertSame([], $this->service->recoverRunningBatch($now, 1800)['retried']);
    }

    /**
     * 恢复机会记录在仓储中，换新服务实例也不能第二次恢复。
     */
    public function testRecoveryIsNotResetByNewServiceInstance(): void
    {
        $now = new DateTime('2030-01-01 12:00:00');
        $task = $this->runningTask(10, '2030-01-01 11:00:00');
        $this->tasks->method('lockStaleRunning')->willReturn([$task]);
        $recorded = [];
        $this->logs->method('findTimedOutTaskIds')->willReturnCallback(static function () use (&$recorded): array {
            return $recorded;
        });
        $this->logs->expects(self::once())->method('batchCreate')->willReturnCallback(static function (array $logs) use (&$recorded): void {
            $recorded[] = $logs[0]->toModelString()['task_id'];
        });
        self::assertSame([10], $this->service->recoverRunningBatch($now, 3600)['retried']);
        $task->setActualTime(clone $now);
        $other = new TaskSchedulerDomainService($this->tasks, $this->logs, $this->rules, $this->loggerFactory);
        $later = (clone $now)->modify('+1 hour');
        $result = $other->recoverRunningBatch($later, 3600);
        self::assertSame([], $result['retried']);
        self::assertSame([10], $result['timed_out']);
    }

    /**
     * 归档失败向外传播，状态尚未更新，外层事务可回滚并下轮重试。
     */
    public function testRecoveryRecordFailureDoesNotResetRunning(): void
    {
        $this->tasks->method('lockStaleRunning')->willReturn([$this->runningTask(10, '2030-01-01 10:30:00')]);
        $this->logs->method('batchCreate')->willThrowException(new RuntimeException('mock archive failure'));
        $this->tasks->expects(self::never())->method('transitionRunning');
        $this->expectException(RuntimeException::class);
        $this->service->recoverRunningBatch(new DateTime('2030-01-01 12:00:00'), 3600);
    }

    /**
     * 两小时外历史只关闭当前实例，不产生回调、归档或周期停用。
     */
    public function testHistoricalRunningOnlyClosesInstances(): void
    {
        $now = new DateTime('2030-01-01 12:00:00');
        $this->tasks->expects(self::once())->method('closeRunningBefore')
            ->with(new DateTime('2030-01-01 10:00:00'), 200)->willReturn([10]);
        $this->logs->expects(self::never())->method('batchCreate');
        $this->logs->expects(self::never())->method('create');
        $this->tasks->expects(self::never())->method('claimForExecution');
        $this->rules->expects(self::never())->method('disableByIds');
        self::assertSame([10], $this->service->closeExpiredRunning($now));
    }

    /**
     * 空批次保留扫描ID，不额外查询日志或进行状态写入。
     */
    public function testEmptyRecoveryBatchDoesNotWrite(): void
    {
        $this->tasks->method('lockStaleRunning')->willReturn([]);
        $this->logs->expects(self::never())->method('findTimedOutTaskIds');
        $this->tasks->expects(self::never())->method('transitionRunning');
        self::assertSame(321, $this->service->recoverRunningBatch(new DateTime('2030-01-01'), 3600, 321)['last_id']);
    }

    /**
     * 旧回调已完成但批次过期时，不覆盖新状态且不写旧结果日志。
     */
    public function testExpiredAttemptResultIsDiscarded(): void
    {
        $task = $this->runningTask(10, '2030-01-01 10:30:00');
        $task->setStatus(TaskSchedulerStatus::Pending);
        $this->tasks->method('claimForExecution')->willReturn(true);
        $this->tasks->method('finishExecution')->willReturn(false);
        $this->tasks->expects(self::never())->method('save');
        $this->tasks->expects(self::never())->method('markRunningFailed');
        $this->logs->expects(self::never())->method('create');
        self::assertFalse($this->service->execute($task)->isSuccess());
    }

    /**
     * 默认一小时维护先于Pending扫描；所有依赖均为Mock，不启动协程。
     */
    public function testWorkerUsesOneHourDefaultBeforeNormalScan(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $steps = [];
        $service->expects(self::once())->method('closeExpiredRunning')->willReturnCallback(static function () use (&$steps): array {
            $steps[] = 'close';
            return [];
        });
        $service->expects(self::once())->method('recoverRunningBatch')
            ->with(self::isInstanceOf(DateTime::class), 3600, 0, 200)
            ->willReturnCallback(static function () use (&$steps): array {
                $steps[] = 'recover';
                return [
                    'last_id' => 0,
                    'retried' => [],
                    'timed_out' => [],
                ];
            });
        $service->expects(self::once())->method('listTasksForExecution')->willReturnCallback(static function () use (&$steps): array {
            $steps[] = 'pending';
            return [];
        });
        $this->worker($service, [])->execute();
        self::assertSame(['close', 'recover', 'pending'], $steps);
    }

    /**
     * 滚动升级关闭恢复时不影响原Pending执行扫描。
     */
    public function testDisabledRecoveryKeepsNormalScan(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $service->expects(self::never())->method('closeExpiredRunning');
        $service->expects(self::never())->method('recoverRunningBatch');
        $service->expects(self::once())->method('listTasksForExecution')->willReturn([]);
        $this->worker($service, ['running_timeout_seconds' => 0])->execute();
    }

    /**
     * 没拿到Redis锁时不释放，防止清除仍在运行的旧回调租约。
     */
    public function testFailedMutexDoesNotReleaseAnotherAttempt(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $service->expects(self::never())->method('getById');
        $worker = $this->worker($service, []);
        $locker = $this->createMock(Locker::class);
        $locker->method('mutexLock')->willReturn(false);
        $locker->expects(self::never())->method('release');
        (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, 'locker'))->setValue($worker, $locker);
        (new ReflectionMethod(TaskSchedulerExecuteCrontab::class, 'run'))->invoke($worker, $this->runningTask(10, '2030-01-01 10:30:00'));
    }

    /**
     * 每轮持有独立Redis所有者，旧执行的finally不能释放恢复执行的锁。
     */
    public function testMutexOwnerIsUniquePerExecution(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $service->method('getById')->willReturn(null);
        $worker = $this->worker($service, []);
        $owners = [];
        $locker = $this->createMock(Locker::class);
        $locker->expects(self::exactly(2))->method('mutexLock')->willReturnCallback(static function (string $key, string $owner) use (&$owners): bool {
            $owners[] = $owner;
            return true;
        });
        $locker->expects(self::exactly(2))->method('release')->willReturnCallback(static function (string $key, string $owner) use (&$owners): bool {
            self::assertSame($owners[array_key_last($owners)], $owner);
            return true;
        });
        (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, 'locker'))->setValue($worker, $locker);
        $run = new ReflectionMethod(TaskSchedulerExecuteCrontab::class, 'run');
        $task = $this->runningTask(10, '2030-01-01 10:30:00');
        $run->invoke($worker, $task);
        $run->invoke($worker, $task);
        self::assertNotSame($owners[0], $owners[1]);
    }

    /**
     * 构造包含既有业务重试次数为零的任务，恢复机会不依赖该计数。
     */
    private function runningTask(int $id, string $started): TaskScheduler
    {
        $task = new TaskScheduler();
        $task->setExternalId('mock-recovery');
        $task->setName('mock-recovery');
        $task->setExpectTime(new DateTime($started));
        $task->setCallbackMethod([RunningRecoveryCallback::class, 'complete']);
        $task->prepareForCreation();
        $task->setId($id);
        $task->setStatus(TaskSchedulerStatus::Running);
        $task->setActualTime(new DateTime($started));
        return $task;
    }

    /**
     * 跳过真实并发引擎构造，用替身验证扫描编排。
     */
    private function worker(TaskSchedulerDomainService $service, array $config): TaskSchedulerExecuteCrontab
    {
        $worker = (new ReflectionClass(TaskSchedulerExecuteCrontab::class))->newInstanceWithoutConstructor();
        foreach ([
            'scheduleTaskDomainService' => $service,
            'config' => $config,
            'logger' => new NullLogger(),
            'concurrent' => $this->createMock(Concurrent::class),
        ] as $property => $value) {
            (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, $property))->setValue($worker, $value);
        }
        return $worker;
    }
}

/**
 * 本地无副作用回调，测试不调用真实业务。
 */
class RunningRecoveryCallback
{
    /**
     * 返回合成结果供执行批次校验。
     */
    public static function complete(): array
    {
        return ['mock' => true];
    }
}
