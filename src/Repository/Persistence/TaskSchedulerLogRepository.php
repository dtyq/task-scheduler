<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Repository\Persistence;

use Dtyq\TaskScheduler\Entity\TaskSchedulerLog;
use Dtyq\TaskScheduler\Repository\Persistence\Model\TaskSchedulerLogModel;

class TaskSchedulerLogRepository
{
    /**
     * 取消日志与状态同事务按批次写入，不逐任务查询或保存。
     * @param TaskSchedulerLog[] $logs
     */
    public function batchCreate(array $logs): void
    {
        foreach (array_chunk($logs, 500) as $batch) {
            $rows = array_map(static fn (TaskSchedulerLog $log): array => $log->toModelString(), $batch);
            TaskSchedulerLogModel::query()->insert($rows);
        }
    }

    public function create(TaskSchedulerLog $log): TaskSchedulerLog
    {
        $model = new TaskSchedulerLogModel();
        $model->fill($log->toModelArray());
        $model->save();
        $log->setId($model->id);
        return $log;
    }
}
