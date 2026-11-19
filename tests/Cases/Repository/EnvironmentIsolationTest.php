<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Repository;

use DateTime;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerStatus;
use Dtyq\TaskScheduler\Repository\Persistence\AbstractRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerCrontabRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerLogRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerRepository;
use Dtyq\TaskScheduler\Test\Cases\EnvironmentTestCase;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Query\Builder as QueryBuilder;
use Hyperf\Database\Query\Grammars\MySqlGrammar;
use Hyperf\Database\Query\Processors\Processor;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionMethod;

/**
 * 使用真实查询构造器和 Mock 连接验证环境集合条件，不连接数据库。
 * @internal
 * @coversNothing
 */
class EnvironmentIsolationTest extends EnvironmentTestCase
{
    /**
     * 同一环境范围不能绕过业务标识、状态、到期时间或任务 ID。
     */
    #[DataProvider('scopeCases')]
    public function testBusinessFiltersRemainRequired(string $repositoryClass, bool $enabled, ?bool $includeEmpty, string $environmentSql, array $environmentBindings): void
    {
        $this->setEnvironment();
        $this->config->set('task_scheduler.environment_enabled', $enabled);
        if ($includeEmpty !== null) {
            $this->config->set('task_scheduler.include_empty_environment', $includeEmpty);
        }

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())
            ->method('select');
        $connection->expects(self::never())
            ->method('update');
        $connection->expects(self::never())
            ->method('delete');
        $builder = $this->builder($connection);
        $builder->where('external_id', 'mock-external');
        $scoped = (new ReflectionMethod(AbstractRepository::class, 'createBuilder'))
            ->invoke(new $repositoryClass(), $builder);
        self::assertSame($builder, $scoped);
        $builder->where('status', 0)
            ->where('expect_time', '<=', '2030-01-01 00:00:00')
            ->where('id', 123);

        self::assertSame(
            'select * from `mock_tasks` where `external_id` = ?' . $environmentSql . ' and `status` = ? and `expect_time` <= ? and `id` = ?',
            $builder->toSql()
        );
        self::assertSame(
            array_merge([
                'mock-external',
            ], $environmentBindings, [
                0,
                '2030-01-01 00:00:00',
                123,
            ]),
            $builder->getBindings()
        );
    }

    /**
     * 任务与规则仓储均覆盖严格、兼容、关闭和配置缺省模式。
     */
    public static function scopeCases(): iterable
    {
        foreach ([
            TaskSchedulerRepository::class,
            TaskSchedulerCrontabRepository::class,
        ] as $repository) {
            foreach ([
                [
                    true,
                    null,
                    ' and `environment` = ?',
                    [
                        'test-current',
                    ],
                ],
                [
                    true,
                    false,
                    ' and `environment` = ?',
                    [
                        'test-current',
                    ],
                ],
                [
                    true,
                    true,
                    ' and `environment` in (?, ?)',
                    [
                        'test-current',
                        '',
                    ],
                ],
                [
                    false,
                    false,
                    '',
                    [],
                ],
                [
                    false,
                    true,
                    '',
                    [],
                ],
            ] as $case) {
                yield array_merge([
                    $repository,
                ], $case);
            }
        }
    }

    /**
     * 数字字符串环境同样必须追加过滤，不能使用 empty() 判为未隔离。
     */
    public function testZeroStringEnvironmentIsScoped(): void
    {
        $this->setEnvironment('0');
        $builder = $this->builder($this->createMock(ConnectionInterface::class));
        (new ReflectionMethod(AbstractRepository::class, 'createBuilder'))
            ->invoke(new TaskSchedulerRepository(), $builder);
        self::assertSame([
            '0',
        ], $builder->getBindings());
    }

    /**
     * 真实仓储的取消、状态修改和清理必须包含相同的历史兼容范围。
     */
    #[DataProvider('writeCases')]
    public function testWriteOperationsRemainScoped(string $operation, bool $includeEmpty): void
    {
        $this->setEnvironment();
        $this->config->set('task_scheduler.include_empty_environment', $includeEmpty);
        $connection = $this->connection();
        $method = in_array($operation, [
            'cancel',
            'status',
        ], true) ? 'update' : 'delete';
        $connection->expects(self::once())
            ->method($method)
            ->willReturnCallback(static function (string $sql, array $bindings) use ($includeEmpty): int {
                self::assertStringContainsString(
                    $includeEmpty ? '`environment` in (?, ?)' : '`environment` = ?',
                    $sql
                );
                self::assertContains('test-current', $bindings);
                self::assertSame($includeEmpty, in_array('', $bindings, true));
                self::assertStringContainsString(' and ', $sql);
                return 0;
            });
        $repository = new TaskSchedulerRepository();
        match ($operation) {
            'cancel' => $repository->cancelByIds([
                123,
            ]),
            'status' => $repository->changeStatus(123, TaskSchedulerStatus::Running),
            'delete' => $repository->deleteByIds([
                123,
            ]),
            'clear_tasks' => $repository->clearByExternalId('mock-external'),
            'clear_rules' => (new TaskSchedulerCrontabRepository())
                ->clearByExternalId('mock-external'),
        };
    }

    /**
     * 严格和兼容模式都验证真实仓储的写入入口。
     */
    public static function writeCases(): iterable
    {
        foreach ([
            'cancel',
            'status',
            'delete',
            'clear_tasks',
            'clear_rules',
        ] as $operation) {
            foreach ([
                false,
                true,
            ] as $includeEmpty) {
                yield [
                    $operation,
                    $includeEmpty,
                ];
            }
        }
    }

    /**
     * 开启兼容后，任务和规则的防重复查询也必须包含历史空环境。
     */
    #[DataProvider('duplicateCases')]
    public function testDuplicateChecksIncludeLegacyEnvironment(bool $isRule): void
    {
        $this->setEnvironment();
        $this->config->set('task_scheduler.include_empty_environment', true);
        $connection = $this->connection();
        $connection->expects(self::once())
            ->method('select')
            ->willReturnCallback(static function (string $sql, array $bindings): array {
                self::assertStringContainsString('`environment` in (?, ?)', $sql);
                self::assertSame([
                    'test-current',
                    '',
                ], array_slice($bindings, 0, 2));
                self::assertContains('mock-external', $bindings);
                return [
                    [
                        'exists' => 1,
                    ],
                ];
            });
        $exists = $isRule
            ? (new TaskSchedulerCrontabRepository())
                ->existsByExternalIdAndCrontab('mock-external', '* * * * *')
            : (new TaskSchedulerRepository())
                ->existsByExternalIdAndExpectTime('mock-external', new DateTime('2030-01-01'));
        self::assertTrue($exists);
    }

    /**
     * 提供两种持久化对象的防重复场景。
     */
    public static function duplicateCases(): array
    {
        return [
            [
                false,
            ],
            [
                true,
            ],
        ];
    }

    /**
     * 真实查询构造器验证条件认领SQL及受影响行数，不连接数据库。
     */
    #[DataProvider('claimCases')]
    public function testClaimUsesStatusGuardAndAffectedRows(bool $includeEmpty, int $affected): void
    {
        $this->setEnvironment();
        $this->config->set('task_scheduler.include_empty_environment', $includeEmpty);
        $connection = $this->connection();
        $connection->expects(self::once())->method('update')->willReturnCallback(static function (string $sql, array $bindings) use ($includeEmpty, $affected): int {
            self::assertStringContainsString('`id` = ?', $sql);
            self::assertStringContainsString('`status` in (?, ?)', $sql);
            self::assertStringContainsString($includeEmpty ? '`environment` in (?, ?)' : '`environment` = ?', $sql);
            self::assertSame([TaskSchedulerStatus::Pending->value, TaskSchedulerStatus::Retry->value], array_slice($bindings, -2));
            self::assertSame(TaskSchedulerStatus::Running->value, $bindings[0]);
            self::assertSame('2030-01-01 11:00:00', $bindings[1]);
            self::assertStringContainsString('`actual_time` = ?', $sql);
            return $affected;
        });
        self::assertSame($affected === 1, (new TaskSchedulerRepository())->claimForExecution(123, new DateTime('2030-01-01 11:00:00')));
    }

    /**
     * 严格及历史兼容环境下都覆盖认领胜出和失败。
     */
    public static function claimCases(): array
    {
        return [[false, 0], [false, 1], [true, 0], [true, 1]];
    }

    /**
     * 规则锁必须带环境且使用for update，不能仅靠进程内对象模拟互斥。
     */
    public function testGenerationLockUsesScopedCurrentRead(): void
    {
        $this->setEnvironment();
        $connection = $this->connection();
        $connection->expects(self::once())->method('select')->willReturnCallback(static function (string $sql, array $bindings): array {
            self::assertStringContainsString('for update', $sql);
            self::assertStringContainsString('`environment` = ?', $sql);
            self::assertSame(['test-current', 123], $bindings);
            return [];
        });
        self::assertNull((new TaskSchedulerCrontabRepository())->lockById(123));
    }

    /**
     * 认领后的错误处理不能覆盖已提交结果，只允许Running转Failed。
     */
    public function testFailureUpdateOnlyTouchesRunning(): void
    {
        $this->setEnvironment();
        $connection = $this->connection();
        $connection->expects(self::once())->method('update')->willReturnCallback(static function (string $sql, array $bindings): int {
            self::assertSame([TaskSchedulerStatus::Failed->value, 'test-current', 123, TaskSchedulerStatus::Running->value, '2030-01-01 11:00:00'], $bindings);
            self::assertStringContainsString('`id` = ? and `status` = ? and `actual_time` = ?', $sql);
            return 0;
        });
        (new TaskSchedulerRepository())->markRunningFailed(123, new DateTime('2030-01-01 11:00:00'));
    }

    /**
     * 近期Running查询同时限制计划窗口和运行时长，NULL旧数据回退分支必须括号分组。
     */
    public function testRunningRecoverySelectKeepsWindowAndLegacyFallback(): void
    {
        $this->setEnvironment();
        $connection = $this->connection();
        $connection->expects(self::once())->method('select')->willReturnCallback(static function (string $sql, array $bindings): array {
            self::assertSame([
                'test-current',
                TaskSchedulerStatus::Running->value,
                '2030-01-01 10:00:00',
                '2030-01-01 12:00:00',
                123,
                '2030-01-01 11:00:00',
                '2030-01-01 11:00:00',
            ], $bindings);
            self::assertStringContainsString('`expect_time` >= ? and `expect_time` <= ?', $sql);
            self::assertStringContainsString('(`actual_time` <= ? or (`actual_time` is null and `expect_time` <= ?))', $sql);
            self::assertStringContainsString('order by `id` asc limit 200 for update', $sql);
            return [];
        });
        self::assertSame([], (new TaskSchedulerRepository())->lockStaleRunning(
            new DateTime('2030-01-01 12:00:00'),
            new DateTime('2030-01-01 10:00:00'),
            new DateTime('2030-01-01 11:00:00'),
            123,
            200
        ));
    }

    /**
     * 历史Running严格早于两小时边界选择，直接批量Timeout不走回调。
     */
    public function testHistoricalRunningUsesIndexedRangeAndConditionalClose(): void
    {
        $this->setEnvironment();
        $connection = $this->connection();
        $connection->expects(self::once())->method('select')->willReturnCallback(static function (string $sql, array $bindings): array {
            self::assertSame(['test-current', TaskSchedulerStatus::Running->value, '2030-01-01 10:00:00'], $bindings);
            self::assertStringContainsString('`expect_time` < ?', $sql);
            self::assertStringContainsString('order by `expect_time` asc, `id` asc limit 200 for update', $sql);
            return [['id' => 123]];
        });
        $connection->expects(self::once())->method('update')->willReturnCallback(static function (string $sql, array $bindings): int {
            self::assertSame([TaskSchedulerStatus::Timeout->value, 'test-current', 123, TaskSchedulerStatus::Running->value], $bindings);
            return 1;
        });
        self::assertSame([123], (new TaskSchedulerRepository())->closeRunningBefore(new DateTime('2030-01-01 10:00:00'), 200));
    }

    /**
     * 补偿前必须清空旧批次时间，使旧回调无法覆盖Pending或新Running。
     */
    public function testRecoveryResetClearsAttemptTimestamp(): void
    {
        $this->setEnvironment();
        $connection = $this->connection();
        $connection->expects(self::once())->method('update')->willReturnCallback(static function (string $sql, array $bindings): int {
            self::assertSame([TaskSchedulerStatus::Pending->value, null, 0, 'test-current', 123, TaskSchedulerStatus::Running->value], $bindings);
            self::assertStringContainsString('`actual_time` = ?', $sql);
            return 1;
        });
        (new TaskSchedulerRepository())->transitionRunning([123], TaskSchedulerStatus::Pending);
    }

    /**
     * 结果更新必须同时匹配当前Running和本轮已落库开始时间。
     */
    public function testResultUpdateUsesAttemptTimestamp(): void
    {
        $this->setEnvironment();
        $task = new TaskScheduler();
        $task->setId(123);
        $task->setStatus(TaskSchedulerStatus::Success);
        $task->setRetryTimes(0);
        $connection = $this->connection();
        $connection->expects(self::once())->method('update')->willReturnCallback(static function (string $sql, array $bindings): int {
            self::assertSame([TaskSchedulerStatus::Success->value, 0, 0, 'test-current', 123, TaskSchedulerStatus::Running->value, '2030-01-01 11:00:00'], $bindings);
            self::assertStringContainsString('`id` = ? and `status` = ? and `actual_time` = ?', $sql);
            return 0;
        });
        self::assertFalse((new TaskSchedulerRepository())->finishExecution($task, new DateTime('2030-01-01 11:00:00')));
    }

    /**
     * 恢复凭据按task_id集合批量当前读，并保持相同环境范围。
     */
    public function testTimeoutMarkersAreReadInBatchWithCurrentScope(): void
    {
        $this->setEnvironment();
        $connection = $this->connection();
        $connection->expects(self::once())->method('select')->willReturnCallback(static function (string $sql, array $bindings): array {
            self::assertSame(['test-current', 123, 456, TaskSchedulerStatus::Timeout->value], $bindings);
            self::assertStringContainsString('`task_id` in (?, ?)', $sql);
            self::assertStringContainsString('for update', $sql);
            return [['task_id' => 123]];
        });
        self::assertSame([123], (new TaskSchedulerLogRepository())->findTimedOutTaskIds([123, 456]));
    }

    /**
     * 给真实 Model 装配 Mock 连接和容器，SQL 仅交给 Mock 回调。
     */
    private function connection(): ConnectionInterface
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getQueryGrammar')
            ->willReturn(new MySqlGrammar());
        $connection->method('getPostProcessor')
            ->willReturn(new Processor());
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')
            ->willReturn($connection);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturn(true);
        $container->method('get')
            ->willReturnMap([
                [
                    ConfigInterface::class,
                    $this->config,
                ],
                [
                    ConnectionResolverInterface::class,
                    $resolver,
                ],
                [
                    EventDispatcherInterface::class,
                    null,
                ],
            ]);
        ApplicationContext::setContainer($container);
        return $connection;
    }

    /**
     * 构造可进行嵌套条件编译的 ORM Builder，所有连接均为 Mock。
     */
    private function builder(ConnectionInterface $connection): Builder
    {
        $model = $this->createMock(Model::class);
        $model->method('getTable')
            ->willReturn('mock_tasks');
        $model->method('newModelQuery')
            ->willReturnCallback(fn (): Builder => $this->builder($connection));
        return (new Builder(new QueryBuilder($connection, new MySqlGrammar(), new Processor())))
            ->setModel($model);
    }
}
