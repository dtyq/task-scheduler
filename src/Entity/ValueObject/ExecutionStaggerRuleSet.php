<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Entity\ValueObject;

use DateTimeInterface;
use Dtyq\TaskScheduler\Entity\TaskScheduler;

/**
 * 错峰规则集：校验配置、为任务找到规则，并判断任务是否已到有效执行时刻。
 * 未命中任何规则的任务按原计划时间判断，行为与未启用错峰时一致。
 */
final class ExecutionStaggerRuleSet
{
    public const DEFAULT_TICK_SECONDS = 5;

    public const MAX_WINDOW_SECONDS = 600;

    public const MAX_SCAN_INTERVAL_SECONDS = 60;

    /**
     * @param array<string, ExecutionStaggerRule> $rules 以回调匹配键索引
     * @param list<string> $problems 配置校验发现的问题
     */
    private function __construct(
        private readonly bool $enabled,
        private readonly int $tickSeconds,
        private readonly array $rules,
        private readonly array $problems,
    ) {
    }

    /**
     * 从 task_scheduler.stagger 配置构建规则集。
     * tick 不合法时整体关闭；单条规则不合法或回调重复时只丢弃该条，并记录问题。
     */
    public static function fromConfig(array $config): self
    {
        if (! (bool) ($config['enabled'] ?? false)) {
            return new self(false, self::DEFAULT_TICK_SECONDS, [], []);
        }

        $tick = $config['tick_seconds'] ?? self::DEFAULT_TICK_SECONDS;
        if (! self::isValidTick($tick)) {
            return new self(false, self::DEFAULT_TICK_SECONDS, [], ['tick_seconds 必须是 1～60 且能整除 60 的整数，错峰已整体关闭']);
        }

        $rules = [];
        $problems = [];
        $items = $config['rules'] ?? [];
        if (! is_array($items)) {
            $problems[] = 'rules 必须是数组';
            $items = [];
        }
        foreach ($items as $index => $item) {
            $rule = self::parseRule($item, $tick, (string) $index, $problems);
            if ($rule === null) {
                continue;
            }
            if (isset($rules[$rule->getCallbackKey()])) {
                $problems[] = "规则 {$index}：回调重复，已忽略";
                continue;
            }
            $rules[$rule->getCallbackKey()] = $rule;
        }

        return new self($rules !== [], $tick, $rules, $problems);
    }

    /**
     * tick 必须是 1～60 且能整除 60 的整数，保证每个 tick 时刻都是 tick 的整数倍。
     */
    public static function isValidTick(mixed $tick): bool
    {
        return is_int($tick) && $tick >= 1 && $tick <= 60 && 60 % $tick === 0;
    }

    /**
     * 是否存在至少一条有效规则。
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getTickSeconds(): int
    {
        return $this->tickSeconds;
    }

    /**
     * @return list<string>
     */
    public function getProblems(): array
    {
        return $this->problems;
    }

    /**
     * 返回任务命中的规则，未命中时返回 null。
     */
    public function ruleFor(TaskScheduler $task): ?ExecutionStaggerRule
    {
        if ($this->rules === []) {
            return null;
        }
        $key = ExecutionStaggerRule::callbackKey($task->getCallbackMethod());
        return $key === null ? null : ($this->rules[$key] ?? null);
    }

    /**
     * 有效执行时刻 = 计划时间 + 规则偏移；未命中规则时偏移为 0。
     */
    public function isDue(TaskScheduler $task, DateTimeInterface $now): bool
    {
        $offset = $this->ruleFor($task)?->offsetSeconds($task->getExternalId()) ?? 0;
        return $task->getExpectTime()->getTimestamp() + $offset <= $now->getTimestamp();
    }

    /**
     * 将时间戳向下取整到 tick 时刻。
     */
    public function tickSlot(int $timestamp): int
    {
        return intdiv($timestamp, $this->tickSeconds) * $this->tickSeconds;
    }

    /**
     * 返回给定 tick 时刻需要快扫的规则，以回调匹配键索引。
     * @return array<string, ExecutionStaggerRule>
     */
    public function rulesToScanAt(int $tickSlot): array
    {
        return array_filter($this->rules, static fn (ExecutionStaggerRule $rule): bool => $rule->shouldScanAt($tickSlot));
    }

    /**
     * 校验单条规则，不合法时记录问题并返回 null。
     * @param list<string> $problems
     */
    private static function parseRule(mixed $item, int $tick, string $index, array &$problems): ?ExecutionStaggerRule
    {
        if (! is_array($item)) {
            $problems[] = "规则 {$index}：必须是数组，已忽略";
            return null;
        }
        $callback = $item['callback'] ?? null;
        $key = is_array($callback) ? ExecutionStaggerRule::callbackKey($callback) : null;
        if ($key === null) {
            $problems[] = "规则 {$index}：callback 必须是 [类名, 方法名]，已忽略";
            return null;
        }
        $window = $item['window_seconds'] ?? null;
        if (! is_int($window) || $window < 0 || $window > self::MAX_WINDOW_SECONDS) {
            $problems[] = "规则 {$index}：window_seconds 必须是 0～" . self::MAX_WINDOW_SECONDS . ' 的整数，已忽略';
            return null;
        }
        $interval = $item['scan_interval_seconds'] ?? null;
        if (! is_int($interval) || $interval < $tick || $interval > self::MAX_SCAN_INTERVAL_SECONDS || $interval % $tick !== 0) {
            $problems[] = "规则 {$index}：scan_interval_seconds 必须是 tick_seconds 的整数倍且不超过 " . self::MAX_SCAN_INTERVAL_SECONDS . '，已忽略';
            return null;
        }
        return new ExecutionStaggerRule($key, $window, $interval);
    }
}
