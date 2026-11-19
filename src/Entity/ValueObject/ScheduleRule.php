<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Entity\ValueObject;

use Cron\CronExpression;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Dtyq\TaskScheduler\Exception\TaskSchedulerFrequencyException;
use Dtyq\TaskScheduler\Exception\TaskSchedulerParamsSchedulerException;

/**
 * 解析既有 Cron 及版本化间隔/时区规则，统一计算绝对触发时刻。
 * 扩展参数只保存在原 crontab 文本中，不持有另一份运行游标。
 */
final class ScheduleRule
{
    /**
     * 只允许工厂创建已校验的规则。
     */
    private function __construct(
        private readonly string $text,
        private readonly ?CronExpression $cron,
        private readonly ?int $interval = null,
        private readonly ?int $start = null,
        private readonly int $minimum = 0,
        private readonly ?string $timezone = null,
    ) {
    }

    /**
     * 严格区分旧规则和扩展版本，损坏或未知前缀不得回退成 Cron。
     */
    public static function parse(string $text): self
    {
        if ($text === '' || strlen($text) > 64) {
            throw new TaskSchedulerParamsSchedulerException('定时规则不能为空或超过64字符');
        }
        if (str_starts_with($text, '@every:v1:')) {
            $parts = explode(':', $text);
            if (count($parts) !== 4) {
                throw new TaskSchedulerParamsSchedulerException('间隔规则格式错误');
            }
            $interval = self::integer($parts[2]);
            $start = self::integer($parts[3]);
            if ($interval === 0 || $interval % 60 !== 0) {
                throw new TaskSchedulerParamsSchedulerException('间隔必须为正整数分钟');
            }
            return new self($text, null, $interval, $start);
        }
        if (str_starts_with($text, '@cron:v1:')) {
            $parts = explode(':', $text);
            if (count($parts) !== 6) {
                throw new TaskSchedulerParamsSchedulerException('Cron扩展规则格式错误');
            }
            $start = self::integer($parts[2]);
            $minimum = self::integer($parts[3]);
            if (! in_array($parts[4], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                throw new TaskSchedulerParamsSchedulerException('必须使用有效的IANA时区');
            }
            self::validateStandardCron($parts[5]);
            return new self($text, new CronExpression($parts[5]), null, $start, $minimum, $parts[4]);
        }
        // 旧库支持的宏与扩展语法继续保留；仅拒绝本包未定义的内部格式。
        if (str_starts_with($text, '@every:') || str_starts_with($text, '@cron:') || ! CronExpression::isValidExpression($text)) {
            throw new TaskSchedulerParamsSchedulerException('定时规则格式或版本错误');
        }
        return new self($text, new CronExpression($text));
    }

    /**
     * 首次时刻由调用方决定，后续始终沿此基准推进。
     */
    public static function every(int $seconds, DateTimeInterface $firstRun): self
    {
        return self::parse('@every:v1:' . $seconds . ':' . $firstRun->getTimestamp());
    }

    /**
     * 包装标准五字段及通用约束，不在包内设定产品最小频率。
     */
    public static function cron(string $expression, DateTimeInterface $start, string $timezone, int $minimumSeconds = 0): self
    {
        self::validateStandardCron($expression);
        $expression = self::compactCron($expression);
        return self::parse('@cron:v1:' . $start->getTimestamp() . ':' . $minimumSeconds . ':' . $timezone . ':' . $expression);
    }

    /**
     * 返回可直接保存到既有 crontab 字段的完整文本。
     */
    public function toString(): string
    {
        return $this->text;
    }

    /**
     * 只有新格式应用绝对基准和未生成旧周期的跳过策略。
     */
    public function isExtended(): bool
    {
        return $this->start !== null;
    }

    /**
     * 返回首次生效边界，裸 Cron 保留旧调用方游标语义。
     */
    public function getStartTimestamp(): ?int
    {
        return $this->start;
    }

    /**
     * 计算严格晚于游标的下一点，统一转回现有服务存储时区。
     */
    public function nextAfter(DateTimeInterface $after): DateTime
    {
        $timestamp = $after->getTimestamp();
        if ($timestamp === PHP_INT_MAX) {
            throw new TaskSchedulerParamsSchedulerException('时间戳超出可计算范围');
        }
        if ($this->interval !== null) {
            $start = $this->start;
            $steps = $timestamp < $start ? 0 : intdiv($timestamp - $start, $this->interval) + 1;
            if ($steps > intdiv(PHP_INT_MAX - $start, $this->interval)) {
                throw new TaskSchedulerParamsSchedulerException('间隔计算超出整数范围');
            }
            $next = (new DateTime())->setTimestamp($start + $steps * $this->interval);
        } elseif ($this->timezone !== null) {
            $lower = max($timestamp + 1, $this->start);
            $base = (new DateTime())->setTimestamp($lower);
            $next = $this->cron->getNextRunDate($base, 0, true, $this->timezone);
            if ($next->getTimestamp() < $lower) {
                $next = $this->cron->getNextRunDate($next, 0, false, $this->timezone);
            }
            if ($this->minimum > 0) {
                $previous = $this->cron->getPreviousRunDate($next, 0, false, $this->timezone);
                if ($previous->getTimestamp() >= $this->start && $next->getTimestamp() - $previous->getTimestamp() < $this->minimum) {
                    throw new TaskSchedulerFrequencyException('Cron相邻触发时间小于配置的最小间隔');
                }
            }
        } else {
            $next = $this->cron->getNextRunDate($after);
        }
        return $next->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * 有界预览与生成复用同一计算，截止包含边界且不修改入参。
     * @return DateTime[]
     */
    public function preview(DateTimeInterface $after, int $limit = 3, ?DateTimeInterface $deadline = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new TaskSchedulerParamsSchedulerException('预览或生成批次必须在1至1000之间');
        }
        $dates = [];
        for ($i = 0; $i < $limit; ++$i) {
            $next = $this->nextAfter($after);
            if ($deadline !== null && $next > $deadline) {
                break;
            }
            $dates[] = $next;
            $after = $next;
        }
        return $dates;
    }

    /**
     * 数字文本禁止符号、小数、指数、前导零及整数溢出。
     */
    private static function integer(string $value): int
    {
        if (! preg_match('/^(0|[1-9][0-9]*)$/D', $value)) {
            throw new TaskSchedulerParamsSchedulerException('规则整数参数格式错误');
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new TaskSchedulerParamsSchedulerException('规则整数参数溢出');
        }
        return (int) $value;
    }

    /**
     * 新 Cron 仅接受标准字段；旧裸规则不经过此限制。
     */
    private static function validateStandardCron(string $expression): void
    {
        $fields = preg_split('/\s+/', trim($expression));
        if (count($fields) !== 5 || ! CronExpression::isValidExpression($expression)) {
            throw new TaskSchedulerParamsSchedulerException('Cron必须为有效五字段表达式');
        }
        foreach ($fields as $index => $field) {
            $value = match ($index) {
                3 => '(?:[0-9]+|JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)',
                4 => '(?:[0-9]+|SUN|MON|TUE|WED|THU|FRI|SAT)',
                default => '[0-9]+',
            };
            $part = '(?:\*|' . $value . '(?:-' . $value . ')?)(?:/[0-9]+)?';
            if (! preg_match('~^' . $part . '(?:,' . $part . ')*$~iD', $field)) {
                throw new TaskSchedulerParamsSchedulerException('Cron包含非标准字段扩展');
            }
        }
    }

    /**
     * 只压缩纯数字列表的连续段，保留日期字段的显式范围语义。
     */
    private static function compactCron(string $expression): string
    {
        $fields = preg_split('/\s+/', trim($expression));
        foreach ($fields as &$field) {
            if (! preg_match('/^[0-9]+(?:,[0-9]+)+$/D', $field)) {
                continue;
            }
            $values = array_unique(array_map('intval', explode(',', $field)));
            sort($values);
            $ranges = [];
            $start = $end = array_shift($values);
            foreach ($values as $value) {
                if ($value === $end + 1) {
                    $end = $value;
                    continue;
                }
                $ranges[] = $start === $end ? (string) $start : $start . '-' . $end;
                $start = $end = $value;
            }
            $ranges[] = $start === $end ? (string) $start : $start . '-' . $end;
            $field = implode(',', $ranges);
        }
        unset($field);
        return implode(' ', $fields);
    }
}
