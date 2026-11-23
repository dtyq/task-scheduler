<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Entity\ValueObject;

/**
 * 单条错峰规则：命中指定回调的任务在计划时间后再等待一个稳定偏移才认领，并按自身间隔参与快扫。
 */
final class ExecutionStaggerRule
{
    public function __construct(
        private readonly string $callbackKey,
        private readonly int $windowSeconds,
        private readonly int $scanIntervalSeconds,
    ) {
    }

    /**
     * 将 [类名, 方法名] 规范化为匹配键；类名去掉开头的反斜杠，类名与方法名都按小写比较。
     * 格式不合法时返回 null。
     */
    public static function callbackKey(array $callbackMethod): ?string
    {
        if (count($callbackMethod) !== 2) {
            return null;
        }
        [$class, $method] = array_values($callbackMethod);
        if (! is_string($class) || ! is_string($method)) {
            return null;
        }
        $class = ltrim(trim($class), '\\');
        $method = trim($method);
        if ($class === '' || $method === '') {
            return null;
        }
        return strtolower($class) . '::' . strtolower($method);
    }

    public function getCallbackKey(): string
    {
        return $this->callbackKey;
    }

    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function getScanIntervalSeconds(): int
    {
        return $this->scanIntervalSeconds;
    }

    /**
     * 判断回调方法是否命中本规则。
     */
    public function matches(array $callbackMethod): bool
    {
        return self::callbackKey($callbackMethod) === $this->callbackKey;
    }

    /**
     * 偏移只由业务标识决定，同一任务跨日、跨进程重启都得到相同结果。
     */
    public function offsetSeconds(string $externalId): int
    {
        if ($this->windowSeconds === 0) {
            return 0;
        }
        return crc32($externalId) % ($this->windowSeconds + 1);
    }

    /**
     * 本规则是否参与给定 tick 时刻的快扫；tick 时刻是基础 tick 的整数倍。
     */
    public function shouldScanAt(int $tickSlot): bool
    {
        return $tickSlot % $this->scanIntervalSeconds === 0;
    }
}
