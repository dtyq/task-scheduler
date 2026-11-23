<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler;

use Dtyq\TaskScheduler\Crontab\TaskSchedulerClearCrontab;
use Dtyq\TaskScheduler\Crontab\TaskSchedulerCreateCrontab;
use Dtyq\TaskScheduler\Crontab\TaskSchedulerExecuteCrontab;
use Dtyq\TaskScheduler\Entity\ValueObject\ExecutionStaggerRuleSet;
use Hyperf\Crontab\Crontab;

use function Hyperf\Support\env;

class ConfigProvider
{
    /**
     * 是否注册错峰快扫入口。加载 ConfigProvider 时运行时配置尚未就绪，只能读环境变量；
     * 接入方的 task_scheduler.stagger.enabled 应引用同一个环境变量。
     */
    public static function staggerScanEnabled(): bool
    {
        return filter_var(env('TASK_SCHEDULER_STAGGER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 错峰快扫入口的基础 tick（秒），非法时回落默认值；接入方的 tick_seconds 应引用同一个环境变量。
     */
    public static function staggerTickSeconds(): int
    {
        $tick = filter_var(env('TASK_SCHEDULER_STAGGER_TICK_SECONDS', ExecutionStaggerRuleSet::DEFAULT_TICK_SECONDS), FILTER_VALIDATE_INT);
        return ExecutionStaggerRuleSet::isValidTick($tick) ? $tick : ExecutionStaggerRuleSet::DEFAULT_TICK_SECONDS;
    }

    public function __invoke(): array
    {
        $appEnv = '';
        if (function_exists('env')) {
            $appEnv = env('APP_ENV', '');
        }

        return [
            'dependencies' => [],
            'commands' => [],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'crontab' => [
                'crontab' => [
                    (new Crontab())
                        ->setName($appEnv . '.TaskSchedulerClearCrontab')
                        ->setRule('0 2 * * *')
                        ->setCallback([TaskSchedulerClearCrontab::class, 'execute'])
                        ->setSingleton(true)
                        ->setMutexExpires(600)
                        ->setOnOneServer(true)
                        ->setMemo('清理超过 n 天的调度数据'),
                    (new Crontab())
                        ->setName($appEnv . '.TaskSchedulerCreateCrontab')
                        ->setRule('* * * * *')
                        ->setCallback([TaskSchedulerCreateCrontab::class, 'execute'])
                        ->setSingleton(true)
                        ->setMutexExpires(90)
                        ->setOnOneServer(true)
                        ->setMemo('创建未来 n 天的调度数据'),
                    (new Crontab())
                        ->setName($appEnv . '.TaskSchedulerExecuteCrontab')
                        ->setRule('* * * * *')
                        ->setCallback([TaskSchedulerExecuteCrontab::class, 'execute'])
                        ->setSingleton(true)
                        ->setMutexExpires(90)
                        ->setOnOneServer(true)
                        ->setMemo('执行调度'),
                    (new Crontab())
                        ->setName($appEnv . '.TaskSchedulerStaggerExecuteCrontab')
                        ->setRule('*/' . self::staggerTickSeconds() . ' * * * * *')
                        ->setCallback([TaskSchedulerExecuteCrontab::class, 'executeStaggered'])
                        ->setSingleton(true)
                        ->setMutexExpires(30)
                        ->setOnOneServer(true)
                        ->setEnable(self::staggerScanEnabled())
                        ->setMemo('按回调规则错峰的快速扫描'),
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for schedule-task.',
                    'source' => __DIR__ . '/../publish/task_scheduler.php',
                    'destination' => BASE_PATH . '/config/autoload/task_scheduler.php',
                ],
                [
                    'id' => 'migration',
                    'description' => 'migration file.',
                    'source' => __DIR__ . '/../publish/migrations/2024_08_28_110155_create_task_scheduler.php',  // 对应的配置文件路径
                    'destination' => BASE_PATH . '/migrations/2024_08_28_110155_create_task_scheduler.php', // 复制为这个路径下的该文件
                ],
                [
                    'id' => 'migration',
                    'description' => 'migration file.',
                    'source' => __DIR__ . '/../publish/migrations/2024_08_28_110158_create_task_scheduler_log.php',  // 对应的配置文件路径
                    'destination' => BASE_PATH . '/migrations/2024_08_28_110158_create_task_scheduler_log.php', // 复制为这个路径下的该文件
                ],
                [
                    'id' => 'migration',
                    'description' => 'migration file.',
                    'source' => __DIR__ . '/../publish/migrations/2024_08_28_110202_create_task_scheduler_crontab.php',  // 对应的配置文件路径
                    'destination' => BASE_PATH . '/migrations/2024_08_28_110202_create_task_scheduler_crontab.php', // 复制为这个路径下的该文件
                ],
                [
                    'id' => 'migration',
                    'description' => 'migration file.',
                    'source' => __DIR__ . '/../publish/migrations/2024_10_22_101130_task_scheduler_add_environment.php',  // 对应的配置文件路径
                    'destination' => BASE_PATH . '/migrations/2024_10_22_101130_task_scheduler_add_environment.php', // 复制为这个路径下的该文件
                ],
            ],
        ];
    }
}
