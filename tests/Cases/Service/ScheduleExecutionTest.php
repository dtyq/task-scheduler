<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Service;

use DateTime;
use Dtyq\TaskScheduler\Crontab\TaskSchedulerExecuteCrontab;
use Dtyq\TaskScheduler\Entity\Query\TaskSchedulerQuery;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerStatus;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use Hyperf\Coroutine\Concurrent;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * 真实执行实体配合Mock仓储验证认领结果和原有回调契约。
 * @internal
 * @coversNothing
 */
class ScheduleExecutionTest extends SchedulerServiceTestCase
{
    /**
     * 两个旧快照竞争时，只有认领成功的一方回调、写状态与日志。
     */
    public function testOnlySuccessfulClaimExecutesAndBusinessArrayIsNotFailure(): void
    {
        MockScheduledCallback::$calls = 0;
        $task = $this->task();
        $stale = clone $task;
        $this->tasks->expects(self::exactly(2))->method('claimForExecution')->with(321)->willReturnOnConsecutiveCalls(true, false);
        $this->tasks->expects(self::once())->method('save')->with(self::callback(static fn (TaskScheduler $saved): bool => $saved->getStatus() === TaskSchedulerStatus::Success))->willReturnArgument(0);
        $this->logs->expects(self::once())->method('create')->willReturnArgument(0);
        self::assertTrue($this->service->execute($task)->isSuccess());
        $skipped = $this->service->execute($stale);
        self::assertFalse($skipped->isSuccess());
        self::assertSame(0, $skipped->getCosTime());
        self::assertSame(1, MockScheduledCallback::$calls);
        self::assertSame(TaskSchedulerStatus::Pending, $stale->getStatus());
    }

    /**
     * 已取消/认领的数据库行不能被调用方的旧Pending对象重新执行。
     */
    public function testFailedClaimDoesNotWriteAnything(): void
    {
        $this->tasks->method('claimForExecution')->willReturn(false);
        $this->tasks->expects(self::never())->method('save');
        $this->tasks->expects(self::never())->method('markRunningFailed');
        $this->logs->expects(self::never())->method('create');
        self::assertFalse($this->service->execute($this->task())->isSuccess());
    }

    /**
     * 回调异常沿原剩余重试次数更新，不新增重试或把普通业务返回当异常。
     */
    #[DataProvider('retryCases')]
    public function testCallbackExceptionKeepsRetryPolicy(int $retries, TaskSchedulerStatus $expected, int $remaining): void
    {
        $task = $this->task();
        $task->setRetryTimes($retries);
        $task->setCallbackMethod([MockScheduledCallback::class, 'fail']);
        $this->tasks->method('claimForExecution')->willReturn(true);
        $this->tasks->expects(self::once())->method('save')->willReturnArgument(0);
        $this->logs->expects(self::once())->method('create')->willReturnArgument(0);
        self::assertFalse($this->service->execute($task)->isSuccess());
        self::assertSame($expected, $task->getStatus());
        self::assertSame($remaining, $task->getRetryTimes());
    }

    /**
     * 原包重试次数的核心边界。
     */
    public static function retryCases(): array
    {
        return [[2, TaskSchedulerStatus::Retry, 1], [1, TaskSchedulerStatus::Failed, 0]];
    }

    /**
     * 已提交执行结果后写日志失败，失败处理只能条件修改仍为Running的行。
     */
    public function testLogFailureDoesNotRequestUnconditionalStatusOverwrite(): void
    {
        $task = $this->task();
        $this->tasks->method('claimForExecution')->willReturn(true);
        $this->tasks->method('save')->willReturnArgument(0);
        $this->logs->method('create')->willThrowException(new RuntimeException('mock log failure'));
        $this->tasks->expects(self::once())->method('markRunningFailed')->with(321);
        $this->tasks->expects(self::never())->method('changeStatus');
        $this->expectException(RuntimeException::class);
        $this->service->execute($task);
    }

    /**
     * 取消没有锁到可取消项时，不为已认领任务生成虚假取消日志。
     */
    public function testCancelSkipsAlreadyClaimedRows(): void
    {
        $query = new TaskSchedulerQuery();
        $query->setIds([321]);
        $query->setExternalIds(['mock-external']);
        $this->tasks->method('lockCancelable')->willReturn([]);
        $this->tasks->expects(self::once())->method('cancelByIds')->with([]);
        $this->logs->expects(self::never())->method('batchCreate');
        $this->service->cancel($query);
    }

    /**
     * 执行器扫描使用ID游标，测试用并发替身不启动协程引擎或运行回调。
     */
    public function testExecutorUsesStableCursorWhileStatusesChange(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $seen = [];
        $service->method('listTasksForExecution')->willReturnCallback(function (DateTime $cutoff, int $afterId) use (&$seen): array {
            $seen[] = $afterId;
            $task = $this->task();
            $task->setId($afterId === 0 ? 10 : 20);
            return $afterId < 20 ? [$task] : [];
        });
        $concurrent = $this->createMock(Concurrent::class);
        $concurrent->expects(self::exactly(2))->method('create');
        $worker = (new ReflectionClass(TaskSchedulerExecuteCrontab::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, 'scheduleTaskDomainService'))->setValue($worker, $service);
        (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, 'concurrent'))->setValue($worker, $concurrent);
        $worker->execute();
        self::assertSame([0, 10, 20], $seen);
    }

    /**
     * 仅创建合成执行项，回调为本地无外部副作用夹具。
     */
    private function task(): TaskScheduler
    {
        $task = new TaskScheduler();
        $task->setExternalId('mock-external');
        $task->setName('mock-task');
        $task->setExpectTime(new DateTime());
        $task->setCallbackMethod([MockScheduledCallback::class, 'execute']);
        $task->prepareForCreation();
        $task->setId(321);
        return $task;
    }
}

/**
 * 静态占位回调仅用于检查调用次数与数组返回契约。
 */
class MockScheduledCallback
{
    public static int $calls = 0;

    /**
     * 业务数组内success=false不意味着调度回调抛出了异常。
     */
    public static function execute(): array
    {
        ++self::$calls;
        return ['success' => false];
    }

    /**
     * 用合成异常模拟回调失败。
     */
    public static function fail(): void
    {
        throw new RuntimeException('mock callback failure');
    }
}
