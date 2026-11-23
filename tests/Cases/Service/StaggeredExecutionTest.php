<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Service;

use DateTime;
use Dtyq\TaskScheduler\Crontab\TaskSchedulerExecuteCrontab;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\ValueObject\ExecutionStaggerRuleSet;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use Dtyq\TaskScheduler\Util\Locker;
use Hyperf\Coroutine\Concurrent;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Stringable;

/**
 * 两个执行入口的错峰过滤、快扫范围与锁后复查；Mock 服务与并发替身不启动协程或调用回调。
 * @internal
 * @coversNothing
 */
class StaggeredExecutionTest extends SchedulerServiceTestCase
{
    private const SCHEDULE_CALLBACK = ['App\Application\SuperMagic\Message\Service\MessageScheduleAppService', 'messageScheduleCallback'];

    private const OTHER_CALLBACK = ['App\Other\Service', 'run'];

    /**
     * 分钟入口跳过命中规则且未到期的任务，游标照常前进；未命中规则与已到期任务照常执行。
     */
    public function testMinuteScanDefersOnlyStaggeredTasksNotYetDue(): void
    {
        $now = time();
        $tasks = [
            $this->task(1, 'message_schedule_waiting', self::SCHEDULE_CALLBACK, $this->notDueExpect('message_schedule_waiting', $now)),
            $this->task(2, 'other', self::OTHER_CALLBACK, $now - 1),
            $this->task(3, 'message_schedule_due', self::SCHEDULE_CALLBACK, $now - 121),
        ];
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $seen = [];
        $service->method('listTasksForExecution')->willReturnCallback(static function (DateTime $cutoff, int $afterId, int $limit, ?DateTime $notBefore = null) use (&$seen, $tasks): array {
            self::assertNull($notBefore);
            $seen[] = $afterId;
            return $afterId === 0 ? $tasks : [];
        });
        $concurrent = $this->createMock(Concurrent::class);
        $concurrent->expects(self::exactly(2))->method('create');
        $worker = $this->worker($service, ['running_timeout_seconds' => 0], $concurrent, $this->rules());
        $worker->execute();
        self::assertSame([0, 3], $seen);
    }

    /**
     * 未启用错峰时分钟入口与原行为一致，不依赖规则集。
     */
    public function testMinuteScanWithoutStaggerExecutesEverything(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $service->method('listTasksForExecution')->willReturnCallback(fn (DateTime $cutoff, int $afterId): array => $afterId === 0
            ? [$this->task(1, 'message_schedule_waiting', self::SCHEDULE_CALLBACK, time() + 600)]
            : []);
        $concurrent = $this->createMock(Concurrent::class);
        $concurrent->expects(self::once())->method('create');
        $this->worker($service, ['running_timeout_seconds' => 0], $concurrent, null)->execute();
    }

    /**
     * 快扫带下界查询，只执行命中本拍规则且已到期的任务，并跨批次推进游标。
     */
    public function testStaggeredScanUsesLowerBoundAndFiltersByRule(): void
    {
        $now = time();
        $first = [
            $this->task(10, 'message_schedule_due', self::SCHEDULE_CALLBACK, $now - 121),
            $this->task(11, 'other', self::OTHER_CALLBACK, $now - 1),
        ];
        $second = [
            $this->task(20, 'message_schedule_waiting', self::SCHEDULE_CALLBACK, $this->notDueExpect('message_schedule_waiting', $now)),
            $this->task(21, 'message_schedule_due_2', self::SCHEDULE_CALLBACK, $now - 200),
        ];
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $seen = [];
        $service->method('listTasksForExecution')->willReturnCallback(static function (DateTime $cutoff, int $afterId, int $limit, ?DateTime $notBefore = null) use (&$seen, $first, $second): array {
            self::assertNotNull($notBefore);
            // 下界 = 当前时间 - 最大窗口 120 - 回看 120，允许执行期间跨秒。
            $gap = $cutoff->getTimestamp() - $notBefore->getTimestamp();
            self::assertSame(240, $gap);
            $seen[] = $afterId;
            return match ($afterId) {
                0 => $first,
                11 => $second,
                default => [],
            };
        });
        $stagger = $this->createMock(Concurrent::class);
        $stagger->expects(self::exactly(2))->method('create');
        $minute = $this->createMock(Concurrent::class);
        $minute->expects(self::never())->method('create');
        $worker = $this->worker($service, [], $minute, $this->rules(), $stagger);
        $worker->executeStaggered();
        self::assertSame([0, 11, 21], $seen);
    }

    /**
     * 快扫在未启用或本拍无规则时不查询数据库，也不做 Running 维护。
     */
    public function testStaggeredScanReturnsEarlyWithoutRules(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $service->expects(self::never())->method('listTasksForExecution');
        $service->expects(self::never())->method('closeExpiredRunning');
        $service->expects(self::never())->method('recoverRunningBatch');
        $this->worker($service, [], $this->createMock(Concurrent::class), null)->executeStaggered();

        // 扫描间隔 60 秒的规则只在整分 tick 参与；用一个不可能命中的时刻验证跳拍后直接返回。
        $rules = ExecutionStaggerRuleSet::fromConfig([
            'enabled' => true,
            'tick_seconds' => 1,
            'rules' => [['callback' => self::SCHEDULE_CALLBACK, 'window_seconds' => 120, 'scan_interval_seconds' => 60]],
        ]);
        self::assertSame([], $rules->rulesToScanAt(1700000001));
        self::assertCount(1, $rules->rulesToScanAt(1700000040));
    }

    /**
     * 拿锁重读后仍未到有效时刻时不认领、不回调，并释放锁。
     */
    public function testRunRechecksDueAfterLock(): void
    {
        $now = time();
        $task = $this->task(30, 'message_schedule_waiting', self::SCHEDULE_CALLBACK, $this->notDueExpect('message_schedule_waiting', $now));
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $service->expects(self::once())->method('getById')->with(30)->willReturn($task);
        $service->expects(self::never())->method('execute');
        $locker = $this->createMock(Locker::class);
        $locker->method('mutexLock')->willReturn(true);
        $locker->expects(self::once())->method('release');
        $worker = $this->worker($service, [], $this->createMock(Concurrent::class), $this->rules());
        (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, 'locker'))->setValue($worker, $locker);
        (new ReflectionMethod(TaskSchedulerExecuteCrontab::class, 'run'))->invoke($worker, $task);
    }

    /**
     * 重读后已到期的任务进入原认领流程。
     */
    public function testRunExecutesDueTaskAfterLock(): void
    {
        $task = $this->task(31, 'message_schedule_due', self::SCHEDULE_CALLBACK, time() - 121);
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $service->method('getById')->willReturn($task);
        $service->expects(self::once())->method('execute')->with($task);
        $locker = $this->createMock(Locker::class);
        $locker->method('mutexLock')->willReturn(true);
        $worker = $this->worker($service, [], $this->createMock(Concurrent::class), $this->rules());
        (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, 'locker'))->setValue($worker, $locker);
        (new ReflectionMethod(TaskSchedulerExecuteCrontab::class, 'run'))->invoke($worker, $task);
    }

    /**
     * 构建规则时配置问题只汇总记录一次；有效规则返回规则集，无有效规则时返回 null。
     */
    public function testBuildStaggerRulesLogsProblemsOnce(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $worker = $this->worker($this->createMock(TaskSchedulerDomainService::class), [
            'stagger' => [
                'enabled' => true,
                'tick_seconds' => 5,
                'rules' => [
                    ['callback' => self::SCHEDULE_CALLBACK, 'window_seconds' => 120, 'scan_interval_seconds' => 5],
                    ['callback' => self::OTHER_CALLBACK, 'window_seconds' => 999, 'scan_interval_seconds' => 5],
                ],
            ],
        ], $this->createMock(Concurrent::class), null);
        (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, 'logger'))->setValue($worker, $logger);
        $built = (new ReflectionMethod(TaskSchedulerExecuteCrontab::class, 'buildStaggerRules'))->invoke($worker);

        $problems = array_values(array_filter($logger->records, static fn (array $record): bool => $record[1] === '错峰配置存在问题，相关规则已忽略'));
        self::assertCount(1, $problems);
        self::assertCount(1, $problems[0][2]['problems']);
        self::assertInstanceOf(ExecutionStaggerRuleSet::class, $built);
        self::assertTrue($built->isEnabled());

        $disabled = $this->worker($this->createMock(TaskSchedulerDomainService::class), ['stagger' => ['enabled' => false]], $this->createMock(Concurrent::class), null);
        self::assertNull((new ReflectionMethod(TaskSchedulerExecuteCrontab::class, 'buildStaggerRules'))->invoke($disabled));
    }

    private function rules(): ExecutionStaggerRuleSet
    {
        return ExecutionStaggerRuleSet::fromConfig([
            'enabled' => true,
            'tick_seconds' => 5,
            'rules' => [['callback' => self::SCHEDULE_CALLBACK, 'window_seconds' => 120, 'scan_interval_seconds' => 5]],
        ]);
    }

    /**
     * 计划时间刚到但偏移未过：计划时间 = 当前时间 - 偏移 + 30。
     */
    private function notDueExpect(string $externalId, int $now): int
    {
        return $now - crc32($externalId) % 121 + 30;
    }

    /**
     * 构造合成执行项，回调只用于匹配，不会被调用。
     */
    private function task(int $id, string $externalId, array $callback, int $expectTimestamp): TaskScheduler
    {
        $task = new TaskScheduler();
        $task->setExternalId($externalId);
        $task->setName('mock-task');
        $task->setExpectTime((new DateTime())->setTimestamp($expectTimestamp));
        $task->setCallbackMethod($callback);
        $task->prepareForCreation();
        $task->setId($id);
        return $task;
    }

    /**
     * 绕过构造函数装配执行器，避免读取全局配置或创建真实协程池。
     */
    private function worker(TaskSchedulerDomainService $service, array $config, Concurrent $concurrent, ?ExecutionStaggerRuleSet $rules, ?Concurrent $staggerConcurrent = null): TaskSchedulerExecuteCrontab
    {
        $worker = (new ReflectionClass(TaskSchedulerExecuteCrontab::class))->newInstanceWithoutConstructor();
        foreach ([
            'scheduleTaskDomainService' => $service,
            'config' => $config,
            'logger' => new NullLogger(),
            'concurrent' => $concurrent,
            'staggerRules' => $rules,
            'staggerConcurrent' => $rules === null ? null : ($staggerConcurrent ?? $this->createMock(Concurrent::class)),
        ] as $property => $value) {
            (new ReflectionProperty(TaskSchedulerExecuteCrontab::class, $property))->setValue($worker, $value);
        }
        return $worker;
    }
}
