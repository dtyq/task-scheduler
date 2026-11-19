<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Service;

use DateTime;
use Dtyq\TaskScheduler\Crontab\TaskSchedulerCreateCrontab;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\TaskSchedulerCrontab;
use Dtyq\TaskScheduler\Entity\ValueObject\ScheduleRule;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerStatus;
use Dtyq\TaskScheduler\Exception\TaskSchedulerFrequencyException;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use RuntimeException;

/**
 * 通过仓储替身验证生命周期边界，保留规则基准和已认领任务。
 * @internal
 * @coversNothing
 */
class ScheduleLifecycleTest extends SchedulerServiceTestCase
{
    /**
     * 内容修改使用数据库游标，而不是调用方旧快照重新生成。
     */
    public function testMetadataUpdateKeepsCursorAndOriginalCreationTime(): void
    {
        $previous = $this->rule();
        $previous->setLastGenTime(new DateTime('+2 days'));
        $edited = clone $previous;
        $edited->setName('mock-updated');
        $edited->setLastGenTime(new DateTime('-1 day'));
        $this->rules->method('lockById')->willReturn($previous);
        $this->rules->expects(self::once())->method('save');
        $this->tasks->expects(self::once())->method('updatePendingMetadata');
        $this->tasks->expects(self::never())->method('cancelPendingBatch');
        $this->tasks->expects(self::never())->method('batchCreate');
        $this->service->saveCrontab($edited);
        self::assertEquals($previous->getLastGenTime(), $edited->getLastGenTime());
        self::assertEquals($previous->getCreatedAt(), $edited->getCreatedAt());
    }

    /**
     * 批量暂停保留规则文本，取消日志按实际取消批次一起保存。
     */
    public function testPauseBatchesLogsAndPreservesLegacyEnvironment(): void
    {
        $this->rules->expects(self::once())->method('lockByExternalIds')->with(['mock-external'])->willReturn([$this->rule()]);
        $this->rules->expects(self::once())->method('disableByIds')->with([123]);
        $task = new TaskScheduler();
        $task->setName('mock-task');
        $task->setExternalId('mock-external');
        $task->setExpectTime(new DateTime('+1 hour'));
        $task->setCallbackMethod(['MockCallback', 'execute']);
        $task->prepareForCreation();
        $task->setId(456);
        $task->setStatus(TaskSchedulerStatus::Canceled);
        $task->setEnvironment('');
        $this->tasks->expects(self::exactly(2))->method('cancelPendingBatch')->willReturnOnConsecutiveCalls([$task], []);
        $this->logs->expects(self::once())->method('batchCreate')->willReturnCallback(static function (array $logs): void {
            $row = $logs[0]->toModelString();
            self::assertSame('', $row['environment']);
            self::assertSame(TaskSchedulerStatus::Canceled->value, $row['status']);
            self::assertSame(1, $row['type']);
            self::assertIsString($row['callback_method']);
        });
        self::assertSame(1, $this->service->pauseByExternalIds(['mock-external', 'mock-external']));
    }

    /**
     * 恢复重置覆盖窗口而不重设首次锚点，未来旧游标不能跳过近期执行点。
     */
    public function testResumeKeepsAnchorAndRegeneratesNearFuture(): void
    {
        $first = new DateTime('-1 day');
        $stored = $this->rule(ScheduleRule::every(2400, $first)->toString());
        $stored->setEnabled(false);
        $stored->setLastGenTime(new DateTime('+3 days'));
        $edited = clone $stored;
        $edited->setEnabled(true);
        $text = $stored->getCrontab();
        $this->rules->method('lockById')->willReturnCallback(static function () use (&$stored): TaskSchedulerCrontab {
            return clone $stored;
        });
        $this->rules->method('save')->willReturnCallback(static function (TaskSchedulerCrontab $rule) use (&$stored): void {
            $stored = clone $rule;
        });
        $this->tasks->expects(self::once())->method('batchCreate')->willReturnCallback(static function (array $tasks) use ($first): void {
            $next = $tasks[0]->getExpectTime()->getTimestamp();
            self::assertGreaterThan(time(), $next);
            self::assertLessThanOrEqual(time() + 2400, $next);
            self::assertSame(0, ($next - $first->getTimestamp()) % 2400);
        });
        $this->service->saveCrontab($edited);
        self::assertSame($text, $edited->getCrontab());
    }

    /**
     * 暂停写入异常向外传播，让外层事务回滚，不继续执行取消。
     */
    public function testPauseDoesNotHidePersistenceFailure(): void
    {
        $this->rules->method('disableByIds')->willThrowException(new RuntimeException('mock pause failure'));
        $this->tasks->expects(self::never())->method('cancelPendingBatch');
        $this->expectException(RuntimeException::class);
        $this->service->pauseByExternalIds(['mock-external']);
    }

    /**
     * 预览没有存储副作用，暂停规则无下次执行但仍可查看计划本身。
     */
    public function testPreviewDoesNotAdvanceGeneration(): void
    {
        $start = new DateTime('2030-01-01');
        $rule = $this->rule(ScheduleRule::cron('0 9 * * *', $start, 'Asia/Shanghai')->toString());
        $before = clone $rule->getLastGenTime();
        $this->rules->expects(self::never())->method('save');
        self::assertCount(3, $this->service->previewCrontab($rule, $start));
        self::assertEquals($before, $rule->getLastGenTime());
        $rule->setEnabled(false);
        self::assertNull($this->service->getNextExecutionTime($rule, $start));
    }

    /**
     * 后台约束失败后通过独立生命周期用例暂停，不在回滚事务中假装停用成功。
     */
    public function testBackgroundFrequencyFailurePausesPlan(): void
    {
        $service = $this->createMock(TaskSchedulerDomainService::class);
        $rule = $this->rule();
        $service->method('listCrontabsForGeneration')->willReturnOnConsecutiveCalls([$rule], []);
        $service->method('createByCrontab')->willThrowException(new TaskSchedulerFrequencyException('mock frequency failure'));
        $service->expects(self::once())->method('pauseByExternalIds')->with(['mock-external']);
        (new TaskSchedulerCreateCrontab($service, $this->loggerFactory))->execute();
    }
}
