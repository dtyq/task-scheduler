<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Repository\Persistence;

use Dtyq\TaskScheduler\Entity\TaskSchedulerLog;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerStatus;
use Dtyq\TaskScheduler\Repository\Persistence\Model\TaskSchedulerLogModel;

/**
 * 归档执行结果并提供同环境的超时恢复记录查询。
 */
class TaskSchedulerLogRepository extends AbstractRepository
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

    /**
     * 在实例锁之后批量当前读超时记录，重启后仍不会重复恢复。
     * @return int[]
     */
    public function findTimedOutTaskIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $taskIds = $this->createBuilder(TaskSchedulerLogModel::query())
            ->whereIn('task_id', $ids)
            ->where('status', TaskSchedulerStatus::Timeout->value)
            ->orderBy('task_id')
            ->lockForUpdate()
            ->pluck('task_id')
            ->all();
        return array_values(array_unique(array_map('intval', $taskIds)));
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
