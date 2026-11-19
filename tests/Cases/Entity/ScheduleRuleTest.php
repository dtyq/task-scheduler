<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Entity;

use DateTime;
use Dtyq\TaskScheduler\Entity\ValueObject\ScheduleRule;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskType;
use Dtyq\TaskScheduler\Exception\TaskSchedulerFrequencyException;
use Dtyq\TaskScheduler\Exception\TaskSchedulerParamsSchedulerException;
use Dtyq\TaskScheduler\Service\TaskConfigDomainService;
use Dtyq\TaskScheduler\Test\Cases\AbstractTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 用合成时刻验证规则契约，不读取用户或调用真实业务。
 * @internal
 * @coversNothing
 */
class ScheduleRuleTest extends AbstractTestCase
{
    /**
     * 四十分钟间隔跨小时/日期仍按同一绝对基准推进。
     */
    public function testIntervalKeepsAnchorAndDoesNotMutateInput(): void
    {
        $first = new DateTime('2030-01-01 23:50:00');
        $after = new DateTime('2030-01-01 23:49:59');
        $rule = ScheduleRule::every(2400, $first);
        $dates = $rule->preview($after, 3);
        self::assertSame(['2030-01-01 23:50', '2030-01-02 00:30', '2030-01-02 01:10'], array_map(static fn (DateTime $date): string => $date->format('Y-m-d H:i'), $dates));
        self::assertSame('2030-01-01 23:49:59', $after->format('Y-m-d H:i:s'));
        self::assertSame($dates[2]->getTimestamp() + 2400, $rule->nextAfter($dates[2])->getTimestamp());
    }

    /**
     * 首周期默认跳过本次，显式开启后使用原始开始时刻。
     */
    public function testConfigurationControlsFirstRunAndLegacyApiStaysSeparate(): void
    {
        $start = new DateTime('2030-01-01 09:10:00');
        $next = new TaskConfigDomainService(TaskType::IntervalRepeat, startAt: $start, intervalSeconds: 2400);
        $now = new TaskConfigDomainService(TaskType::IntervalRepeat, startAt: $start, intervalSeconds: 2400, includeStart: true);
        self::assertSame($start->getTimestamp() + 2400, ScheduleRule::parse($next->getScheduleRule())->getStartTimestamp());
        self::assertSame($start->getTimestamp(), ScheduleRule::parse($now->getScheduleRule())->getStartTimestamp());
        $this->expectException(InvalidArgumentException::class);
        $next->getCrontabRule();
    }

    /**
     * 包无产品频率默认值，调用方可按需约束。
     */
    public function testGenericIntervalAllowsOneMinute(): void
    {
        $config = new TaskConfigDomainService(TaskType::IntervalRepeat, intervalSeconds: 60);
        self::assertStringStartsWith('@every:v1:60:', $config->getScheduleRule());
        $this->expectException(InvalidArgumentException::class);
        new TaskConfigDomainService(TaskType::IntervalRepeat, intervalSeconds: 60, minimumIntervalSeconds: 1800);
    }

    /**
     * 用户时区的九点转换到原服务时区，截止包含等值触发点。
     */
    public function testCronUsesSavedTimezoneAndInclusiveDeadline(): void
    {
        $after = new DateTime('2030-01-01T00:00:00+00:00');
        $rule = ScheduleRule::cron('0 9 * * *', $after, 'America/New_York', 1800);
        $dates = $rule->preview($after, 3, new DateTime('2030-01-02T14:00:00+00:00'));
        self::assertCount(2, $dates);
        self::assertSame('2030-01-01 22:00:00 +08:00', $dates[0]->format('Y-m-d H:i:s P'));
        self::assertSame('Asia/Shanghai', date_default_timezone_get());
    }

    /**
     * 保存边界含秒时不能提前生成同一分钟已经过去的触发点。
     */
    public function testCronStartDoesNotRoundBackward(): void
    {
        $start = new DateTime('2030-01-01 09:00:30');
        $rule = ScheduleRule::cron('* * * * *', $start, 'Asia/Shanghai');
        self::assertSame('2030-01-01 09:01:00', $rule->nextAfter(new DateTime('2029-12-31'))->format('Y-m-d H:i:s'));
    }

    /**
     * 夏令时前后保持当地九点，绝对间隔可以是二十三小时。
     */
    public function testCronRetainsLocalTimeAcrossDaylightSaving(): void
    {
        $after = new DateTime('2030-03-09T00:00:00+00:00');
        $dates = ScheduleRule::cron('0 9 * * *', $after, 'America/New_York')->preview($after, 2);
        self::assertSame(23 * 3600, $dates[1]->getTimestamp() - $dates[0]->getTimestamp());
    }

    /**
     * 跨小时的二十分钟短间隔不能被每四十分钟字段掩盖。
     */
    public function testCronChecksMinimumAgainstActualPreviousPoint(): void
    {
        $start = new DateTime('2030-01-01 00:00:00');
        $rule = ScheduleRule::cron('*/40 * * * *', $start, 'Asia/Shanghai', 1800);
        $this->expectException(TaskSchedulerFrequencyException::class);
        $rule->preview($start, 3);
    }

    /**
     * 长数字列表等价压缩为范围，不能换成改变日期组合语义的星号。
     */
    public function testCompactCronPreservesExplicitDayRange(): void
    {
        $rule = ScheduleRule::cron('0 9 ' . implode(',', range(1, 31)) . ' * 1', new DateTime('2030-01-01'), 'Asia/Shanghai');
        self::assertStringEndsWith(':0 9 1-31 * 1', $rule->toString());
        self::assertLessThanOrEqual(64, strlen($rule->toString()));
    }

    /**
     * 旧裸规则和宏继续沿既有语法与时区，不被新协议限制。
     */
    public function testLegacyTextIsPreserved(): void
    {
        foreach (['0 9 * * MON-FRI', '@daily', '0 9 L * *'] as $text) {
            $rule = ScheduleRule::parse($text);
            self::assertSame($text, $rule->toString());
            self::assertFalse($rule->isExtended());
            self::assertSame($text === '@daily' ? '00:00' : '09:00', $rule->nextAfter(new DateTime('2030-01-01'))->format('H:i'));
        }
    }

    /**
     * 协议错误一律拒绝，不尝试修补为其他计划。
     */
    #[DataProvider('invalidRules')]
    public function testRejectsInvalidRule(string $text): void
    {
        $this->expectException(TaskSchedulerParamsSchedulerException::class);
        ScheduleRule::parse($text);
    }

    /**
     * 覆盖版本、单位、整数、时区、标准语法及持久字段长度边界。
     */
    public static function invalidRules(): array
    {
        return array_map(static fn (string $text): array => [$text], [
            '@every:v2:2400:1893456000',
            '@every:v1:61:1893456000',
            '@every:v1:0:1893456000',
            '@every:v1:2e3:1893456000',
            '@every:v1:999999999999999999999:1893456000',
            '@cron:v1:1893456000:-1:Asia/Shanghai:0 9 * * *',
            '@cron:v1:1893456000:0:Bad/Timezone:0 9 * * *',
            '@cron:v1:1893456000:0:Asia/Shanghai:0 9 L * *',
            '@cron:v1:1893456000:0:Asia/Shanghai:@daily',
            '@cron:v1:1893456000:0:Asia/Shanghai:0 0 9 * * *',
            '@cron:v1:1893456000:0:America/Argentina/ComodRivadavia:0,30 9,17 * * *',
        ]);
    }
}
