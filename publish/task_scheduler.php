<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */
return [
    // 定时生成未来 n 天的数据
    'crontab_days' => 3,
    // 覆盖进度低于未来一天时补充，不影响旧配置未声明此项的行为。
    'generation_low_water_days' => 1,
    // 超过 n 天的数据会被清理
    'clear_days' => 10,

    // 开启环境隔离
    'environment_enabled' => true,

    // 环境隔离开启时，是否同时处理 environment 为空字符串的历史任务和周期规则
    // 共享数据库只能由一个环境开启，避免多个环境共同承接历史任务
    'include_empty_environment' => false,

    // 同时执行的定时任务数量，协程数量控制
    'concurrency' => 500,

    // Running持续一小时仍未结束时最多恢复一次，仅限计划时间最近两小时内的实例。
    // 滚动升级可临时设0关闭恢复；所有执行器升级后再设3600，正值必须小于7200。
    'running_timeout_seconds' => 3600,

    // 锁超时时间
    'lock_timeout' => 600,
];
