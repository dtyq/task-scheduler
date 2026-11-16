<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Util;

use Dtyq\TaskScheduler\Exception\TaskSchedulerParamsSchedulerException;

use function Hyperf\Config\config;

/**
 * 读取调度环境，防止隔离开启后因环境缺失退化为全环境查询。
 */
class Functions
{
    /**
     * 获取当前调度环境；只有显式关闭隔离时才允许返回空字符串。
     */
    public static function getEnv(): string
    {
        if (! config('task_scheduler.environment_enabled', true)) {
            return '';
        }

        if (! function_exists('app_env')) {
            throw new TaskSchedulerParamsSchedulerException('开启任务环境隔离时必须提供 app_env()');
        }

        $appEnv = app_env();
        if (! is_string($appEnv) || trim($appEnv) === '') {
            throw new TaskSchedulerParamsSchedulerException('开启任务环境隔离时 app_env() 必须返回非空环境字符串');
        }

        return $appEnv;
    }
}
