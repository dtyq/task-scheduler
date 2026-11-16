<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Repository\Persistence;

use Dtyq\TaskScheduler\Util\Functions;
use Hyperf\Database\Model\Builder;

use function Hyperf\Config\config;

/**
 * 统一任务及周期规则的环境范围，确保扫描、更新与清理使用同一隔离策略。
 */
abstract class AbstractRepository
{
    /**
     * 可选包含空环境历史数据，环境条件分组后再与其他业务条件组合。
     */
    protected function createBuilder(Builder $builder): Builder
    {
        $appEnv = Functions::getEnv();
        if ($appEnv === '') {
            return $builder;
        }

        if (config('task_scheduler.include_empty_environment', false)) {
            $builder->where(static function (Builder $query) use ($appEnv): void {
                $query->where('environment', $appEnv)
                    ->orWhere('environment', '');
            });
        } else {
            $builder->where('environment', $appEnv);
        }

        return $builder;
    }
}
