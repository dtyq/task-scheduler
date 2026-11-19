<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Service;

use DateTime;
use Dtyq\TaskScheduler\Crontab\TaskSchedulerCreateCrontab;
use Dtyq\TaskScheduler\Entity\ValueObject\ScheduleRule;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use RuntimeException;

/**
 * 验证有界生成、重复续建、游标与扫描，不把 Mock 当真实数据库并发证据。
 * @internal
 * @coversNothing
 */
class ScheduleGenerationTest extends SchedulerServiceTestCase
{
    /**
     * 满批推进到最后一点，第二批包含已存在项时只批量创建缺失点。
     */
    public function testGeneratesBatchesUnderFreshRuleLock(): void
    {
        $first = new DateTime('+5 minutes');
        $rule = $this->rule(ScheduleRule::every(1800, $first)->toString());
        $locked = clone $rule;
        $this->rules->expects(self::exactly(2))->method('lockById')->with(123)->willReturn($locked);
        $this->tasks->expects(self::exactly(2))->method('findExistingTimes')->willReturnCallback(static fn (string $external, array $dates): array => [$dates[0]->format('Y-m-d H:i:s')]);
        $this->tasks->expects(self::exactly(2))->method('batchCreate')->willReturnCallback(static function (array $tasks): void {
            self::assertNotEmpty($tasks);
            self::assertLessThanOrEqual(99, count($tasks));
            self::assertSame('test-current', $tasks[0]->getEnvironment());
        });
        $this->rules->expects(self::exactly(2))->method('updateGenerationTime');
        $this->rules->expects(self::never())->method('save');
        $this->service->createByCrontab($rule);
        self::assertSame($first->getTimestamp() + 99 * 1800, $rule->getLastGenTime()->getTimestamp());
        $cursor = $rule->getLastGenTime()->getTimestamp();
        $this->service->createByCrontab($rule);
        self::assertGreaterThan($cursor, $rule->getLastGenTime()->getTimestamp());
    }

    /**
     * 调用方旧对象仍开启但数据库已暂停时，不生成任何执行项。
     */
    public function testDoesNotGenerateFromStaleEnabledSnapshot(): void
    {
        $rule = $this->rule();
        $locked = clone $rule;
        $locked->setEnabled(false);
        $this->rules->method('lockById')->willReturn($locked);
        $this->tasks->expects(self::never())->method('batchCreate');
        $this->rules->expects(self::never())->method('updateGenerationTime');
        $this->service->createByCrontab($rule);
    }

    /**
     * 插入失败不持久推进游标，让外层事务完整回滚。
     */
    public function testFailedInsertDoesNotSaveCursor(): void
    {
        $rule = $this->rule();
        $this->rules->method('lockById')->willReturn(clone $rule);
        $this->tasks->method('batchCreate')->willThrowException(new RuntimeException('mock insert failure'));
        $this->rules->expects(self::never())->method('updateGenerationTime');
        $this->expectException(RuntimeException::class);
        $this->service->createByCrontab($rule);
    }

    /**
     * 稀疏窗口没有命中点时仍推进覆盖边界，避免每分钟重复扫描同一空窗。
     */
    public function testEmptyWindowAdvancesCoverage(): void
    {
        $rule = $this->rule('0 9 1 1 *');
        $rule->setLastGenTime(new DateTime('2030-01-02'));
        $end = new DateTime('2030-01-03');
        self::assertSame([], $rule->listCycleDate($end, 100));
        self::assertEquals($end, $rule->getLastGenTime());
    }

    /**
     * 扫描条件变化时继续按最后ID翻页，不采用会跳行的offset。
     */
    public function testBackgroundScanUsesKeyset(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $cursor = [];
        $service->expects(self::exactly(3))->method('listCrontabsForGeneration')->willReturnCallback(function (DateTime $lowWater, int $afterId) use (&$cursor): array {
            $cursor[] = $afterId;
            return match ($afterId) {
                0 => [$this->rule(id: 10)],
                10 => [$this->rule(id: 20)],
                default => [],
            };
        });
        $service->expects(self::exactly(2))->method('createByCrontab');
        (new TaskSchedulerCreateCrontab($service, $this->loggerFactory))->execute();
        self::assertSame([0, 10, 20], $cursor);
    }

    /**
     * 生成到未来截止后不提前关闭规则，执行项仍可在未来运行。
     */
    public function testGenerationDoesNotDisableBeforeDeadline(): void
    {
        $first = new DateTime('+5 minutes');
        $rule = $this->rule(ScheduleRule::every(2400, $first)->toString());
        $rule->setDeadline(clone $first);
        $this->rules->method('lockById')->willReturn(clone $rule);
        $this->tasks->expects(self::once())->method('batchCreate')->with(self::callback(static fn (array $tasks): bool => count($tasks) === 1));
        $this->service->createByCrontab($rule);
        self::assertTrue($rule->isEnabled());
        self::assertEquals($first, $rule->getLastGenTime());
    }
}
