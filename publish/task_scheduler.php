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

    // 按回调方法错峰执行：命中规则的任务到 expect_time 后，再等一个稳定的偏移才认领执行。
    // 偏移 = crc32(external_id) % (window_seconds + 1)，同一个任务每次的偏移都相同；不落库，不改 expect_time。
    'stagger' => [
        // 总开关。快扫入口是否注册由环境变量 TASK_SCHEDULER_STAGGER_ENABLED 决定，这里应引用同一个变量。
        'enabled' => (bool) \Hyperf\Support\env('TASK_SCHEDULER_STAGGER_ENABLED', false),

        // 快扫入口的基础 tick（秒），取值 1～60 且能整除 60。
        // 入口按 TASK_SCHEDULER_STAGGER_TICK_SECONDS 注册，这里必须与之一致；不合法时整个错峰按关闭处理。
        'tick_seconds' => (int) \Hyperf\Support\env('TASK_SCHEDULER_STAGGER_TICK_SECONDS', 5),

        // 错峰规则，每个回调最多一条。未命中任何规则的任务保持原有分钟扫描，行为不变。
        // 单条规则不合法时只丢弃这一条（相关任务按原时间执行），并记录一次警告。
        'rules' => [
            // [
            //     // 回调方法，与创建任务时的 callback_method 一致；类名开头的 \ 和大小写不影响匹配。
            //     'callback' => [App\Application\Demo\Service\DemoAppService::class, 'demoCallback'],
            //     // 打散窗口（秒），0～600；0 表示不偏移。
            //     'window_seconds' => 120,
            //     // 快扫间隔（秒），必须是 tick_seconds 的整数倍，最大 60。
            //     'scan_interval_seconds' => 5,
            // ],
        ],
    ],
];
