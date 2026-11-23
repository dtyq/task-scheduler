<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Entity;

use DateTime;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\ValueObject\ExecutionStaggerRule;
use Dtyq\TaskScheduler\Entity\ValueObject\ExecutionStaggerRuleSet;
use Dtyq\TaskScheduler\Test\Cases\AbstractTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 错峰规则集的配置校验、回调匹配、偏移与跳拍，只使用合成任务与字符串回调。
 * @internal
 * @coversNothing
 */
class ExecutionStaggerRuleSetTest extends AbstractTestCase
{
    /**
     * 首个接入方的回调以字符串夹具表示，包不依赖接入方代码。
     */
    private const SCHEDULE_CALLBACK = ['App\Application\SuperMagic\Message\Service\MessageScheduleAppService', 'messageScheduleCallback'];

    /**
     * 关闭或缺省配置不产生任何规则，也不报告问题。
     */
    public function testDisabledConfigHasNoRules(): void
    {
        foreach ([[], ['enabled' => false, 'rules' => [$this->ruleConfig()]]] as $config) {
            $rules = ExecutionStaggerRuleSet::fromConfig($config);
            self::assertFalse($rules->isEnabled());
            self::assertSame([], $rules->getProblems());
            self::assertNull($rules->ruleFor($this->task('message_schedule_1')));
        }
    }

    /**
     * 回调匹配忽略类名开头的反斜杠与大小写，方法名不同则不匹配。
     */
    public function testCallbackMatchingIsNormalized(): void
    {
        $rules = $this->rules();
        self::assertNotNull($rules->ruleFor($this->task('a', ['\App\Application\SuperMagic\Message\Service\MessageScheduleAppService', 'messageScheduleCallback'])));
        self::assertNotNull($rules->ruleFor($this->task('a', ['app\application\supermagic\message\service\messagescheduleappservice', 'MESSAGESCHEDULECALLBACK'])));
        self::assertNull($rules->ruleFor($this->task('a', [self::SCHEDULE_CALLBACK[0], 'otherCallback'])));
        self::assertNull($rules->ruleFor($this->task('a', ['App\Other\Service', 'messageScheduleCallback'])));
    }

    /**
     * 同一业务标识的偏移稳定且落在窗口内；窗口为 0 时不偏移。
     */
    public function testOffsetIsStableAndBounded(): void
    {
        $rule = new ExecutionStaggerRule('k', 120, 5);
        $again = new ExecutionStaggerRule('k', 120, 5);
        $offsets = [];
        for ($i = 1; $i <= 500; ++$i) {
            $externalId = 'message_schedule_' . $i;
            $offset = $rule->offsetSeconds($externalId);
            self::assertSame($offset, $again->offsetSeconds($externalId));
            self::assertGreaterThanOrEqual(0, $offset);
            self::assertLessThanOrEqual(120, $offset);
            $offsets[$offset] = true;
        }
        // 500 个合成标识应分散到窗口内的大部分秒数，而不是集中在少数几个值。
        self::assertGreaterThan(100, count($offsets));
        self::assertSame(crc32('message_schedule_1') % 121, $rule->offsetSeconds('message_schedule_1'));
        self::assertSame(0, (new ExecutionStaggerRule('k', 0, 5))->offsetSeconds('message_schedule_1'));
    }

    /**
     * 命中规则时到期判断以计划时间加偏移为准，边界当秒即到期；未命中规则按原计划时间。
     */
    public function testDueUsesExpectTimePlusOffset(): void
    {
        $rules = $this->rules();
        $externalId = $this->externalIdWithPositiveOffset(120);
        $offset = crc32($externalId) % 121;
        $expect = new DateTime('2030-01-01 08:00:00');
        $task = $this->task($externalId, self::SCHEDULE_CALLBACK, $expect);

        self::assertFalse($rules->isDue($task, $this->at($expect, $offset - 1)));
        self::assertTrue($rules->isDue($task, $this->at($expect, $offset)));

        $other = $this->task($externalId, ['App\Other\Service', 'run'], $expect);
        self::assertTrue($rules->isDue($other, $this->at($expect, 0)));
        self::assertFalse($rules->isDue($other, $this->at($expect, -1)));
    }

    /**
     * tick=5 时 5 秒规则每拍参与，10 秒规则隔拍参与；tick 时刻向下取整。
     */
    public function testRulesSkipTicksByScanInterval(): void
    {
        $rules = ExecutionStaggerRuleSet::fromConfig([
            'enabled' => true,
            'tick_seconds' => 5,
            'rules' => [
                $this->ruleConfig(self::SCHEDULE_CALLBACK, 120, 5),
                $this->ruleConfig(['App\Flow\Service', 'routine'], 300, 10),
            ],
        ]);
        self::assertSame(1700000005, $rules->tickSlot(1700000007));
        $at5 = $rules->rulesToScanAt(1700000005);
        $at10 = $rules->rulesToScanAt(1700000010);
        self::assertCount(1, $at5);
        self::assertSame(120, array_values($at5)[0]->getWindowSeconds());
        self::assertCount(2, $at10);
    }

    /**
     * 非法 tick 让错峰整体关闭。
     */
    #[DataProvider('invalidTicks')]
    public function testInvalidTickDisablesStagger(mixed $tick): void
    {
        $rules = ExecutionStaggerRuleSet::fromConfig(['enabled' => true, 'tick_seconds' => $tick, 'rules' => [$this->ruleConfig()]]);
        self::assertFalse($rules->isEnabled());
        self::assertCount(1, $rules->getProblems());
        self::assertNull($rules->ruleFor($this->task('message_schedule_1')));
    }

    /**
     * 覆盖越界、不能整除 60 及非整数；缺省（含 null）回落默认值，不属于非法。
     */
    public static function invalidTicks(): array
    {
        return [[0], [7], [61], ['5'], [5.0]];
    }

    /**
     * 单条非法或重复的规则只丢弃自身，其他规则继续生效，问题逐条汇总。
     */
    public function testInvalidRulesAreDroppedIndividually(): void
    {
        $rules = ExecutionStaggerRuleSet::fromConfig([
            'enabled' => true,
            'tick_seconds' => 5,
            'rules' => [
                $this->ruleConfig(),
                $this->ruleConfig(['\\' . self::SCHEDULE_CALLBACK[0], self::SCHEDULE_CALLBACK[1]], 60, 10),
                $this->ruleConfig(['OnlyClass'], 120, 5),
                $this->ruleConfig(['App\A', 'run'], 601, 5),
                $this->ruleConfig(['App\B', 'run'], 120, 7),
                $this->ruleConfig(['App\C', 'run'], 120, 65),
                $this->ruleConfig(['App\D', 'run'], 120, 0),
                'not-an-array',
                $this->ruleConfig(['App\E', 'run'], 0, 60),
            ],
        ]);
        self::assertTrue($rules->isEnabled());
        self::assertCount(7, $rules->getProblems());
        self::assertSame(120, $rules->ruleFor($this->task('a'))?->getWindowSeconds());
        self::assertNotNull($rules->ruleFor($this->task('a', ['App\E', 'run'])));
        self::assertNull($rules->ruleFor($this->task('a', ['App\A', 'run'])));
    }

    /**
     * 所有规则都不合法时视为未启用。
     */
    public function testNoValidRuleMeansDisabled(): void
    {
        $rules = ExecutionStaggerRuleSet::fromConfig(['enabled' => true, 'tick_seconds' => 5, 'rules' => [$this->ruleConfig(['App\A', 'run'], -1, 5)]]);
        self::assertFalse($rules->isEnabled());
        self::assertCount(1, $rules->getProblems());
    }

    private function rules(): ExecutionStaggerRuleSet
    {
        return ExecutionStaggerRuleSet::fromConfig(['enabled' => true, 'tick_seconds' => 5, 'rules' => [$this->ruleConfig()]]);
    }

    private function ruleConfig(array $callback = self::SCHEDULE_CALLBACK, int $window = 120, int $interval = 5): array
    {
        return ['callback' => $callback, 'window_seconds' => $window, 'scan_interval_seconds' => $interval];
    }

    /**
     * 构造合成执行项，回调只作为匹配用字符串，不会被调用。
     */
    private function task(string $externalId, array $callback = self::SCHEDULE_CALLBACK, ?DateTime $expect = null): TaskScheduler
    {
        $task = new TaskScheduler();
        $task->setExternalId($externalId);
        $task->setExpectTime($expect ?? new DateTime('2030-01-01 08:00:00'));
        $task->setCallbackMethod($callback);
        return $task;
    }

    private function at(DateTime $base, int $seconds): DateTime
    {
        return (clone $base)->setTimestamp($base->getTimestamp() + $seconds);
    }

    /**
     * 找一个偏移大于 0 的合成标识，使“偏移前一秒未到期”的断言有意义。
     */
    private function externalIdWithPositiveOffset(int $window): string
    {
        for ($i = 1;; ++$i) {
            $externalId = 'message_schedule_' . $i;
            if (crc32($externalId) % ($window + 1) > 0) {
                return $externalId;
            }
        }
    }
}
