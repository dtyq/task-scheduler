<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Repository\Persistence;

use DateTime;
use Dtyq\TaskScheduler\Entity\Query\Page;
use Dtyq\TaskScheduler\Entity\Query\TaskSchedulerQuery;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\TaskSchedulerCrontab;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerStatus;
use Dtyq\TaskScheduler\Factory\TaskSchedulerFactory;
use Dtyq\TaskScheduler\Repository\Persistence\Model\TaskSchedulerModel;

class TaskSchedulerRepository extends AbstractRepository
{
    public function save(TaskScheduler $scheduleTask): TaskScheduler
    {
        if ($scheduleTask->shouldCreate()) {
            $model = new TaskSchedulerModel();
            // 如果是创建过的，就不创建了
            if ($this->existsByExternalIdAndExpectTime($scheduleTask->getExternalId(), $scheduleTask->getExpectTime())) {
                return $scheduleTask;
            }
        } else {
            $model = $this->createBuilder(TaskSchedulerModel::query())->find($scheduleTask->getId());
            if (! $model) {
                return $scheduleTask;
            }
        }
        $model->fill($scheduleTask->toModelArray());
        $model->save();

        if (isset($model->id)) {
            $scheduleTask->setId($model->id);
        }

        return $scheduleTask;
    }

    // 批量写入
    public function batchCreate(array $scheduleTasks): void
    {
        $models = [];
        foreach ($scheduleTasks as $scheduleTask) {
            $models[] = $scheduleTask->toModelString();
        }

        // 拆分批量写入,每次写入500条
        $newModels = array_chunk($models, 500);
        foreach ($newModels as $model) {
            TaskSchedulerModel::query()->insert($model);
        }
    }

    /**
     * 在规则锁内一次查出本批已有时刻；取消项允许重新生成。
     * @param DateTime[] $dates
     * @return string[]
     */
    public function findExistingTimes(string $externalId, array $dates): array
    {
        if ($dates === []) {
            return [];
        }
        $times = array_map(static fn (DateTime $date): string => $date->format('Y-m-d H:i:s'), $dates);
        return $this->createBuilder(TaskSchedulerModel::query())
            ->where('external_id', $externalId)
            ->whereIn('expect_time', $times)
            ->where('status', '!=', TaskSchedulerStatus::Canceled->value)
            ->pluck('expect_time')->map(static fn ($time): string => (string) $time)->all();
    }

    public function existsByExternalIdAndExpectTime(string $externalId, DateTime $expectTime): bool
    {
        return $this->createBuilder(TaskSchedulerModel::query())
            ->where('external_id', $externalId)
            ->where('expect_time', $expectTime->format('Y-m-d H:i:s'))
            ->exists();
    }

    /**
     * @return array{total: int, list: array<TaskScheduler>}
     */
    public function queries(TaskSchedulerQuery $query, Page $page): array
    {
        $queryBuilder = $this->createBuilder(TaskSchedulerModel::query());
        if ($query->getIds()) {
            $queryBuilder->whereIn('id', $query->getIds());
        }
        if ($query->getExternalIds()) {
            $queryBuilder->whereIn('external_id', $query->getExternalIds());
        }
        if ($query->getStatus()) {
            $queryBuilder->where('status', $query->getStatus()->value);
        }
        if ($query->getExpectTimeLt()) {
            $queryBuilder->where('expect_time', '<=', $query->getExpectTimeLt()->format('Y-m-d H:i:s'));
        }
        foreach ($query->getOrder() as $column => $order) {
            $queryBuilder->orderBy($column, $order);
        }

        if (! $page->isEnable()) {
            $collection = $queryBuilder->get();
            $resultList = [];

            foreach ($collection as $model) {
                if ($model instanceof TaskSchedulerModel) {
                    $resultList[] = TaskSchedulerFactory::modelToEntity($model);
                }
            }

            return [
                'total' => 0,
                'list' => $resultList,
            ];
        }

        $total = $queryBuilder->count();
        $list = [];
        if ($total !== 0) {
            $collection = $queryBuilder->forPage($page->getPage(), $page->getPageNum())->get();

            foreach ($collection as $model) {
                if ($model instanceof TaskSchedulerModel) {
                    $list[] = TaskSchedulerFactory::modelToEntity($model);
                }
            }
        }

        return [
            'total' => $total,
            'list' => $list,
        ];
    }

    /**
     * 分批锁定并取消未认领实例，Running及终态均不进入取消集合。
     * @return TaskScheduler[]
     */
    public function cancelPendingBatch(array $externalIds, int $afterId, int $limit = 500): array
    {
        if ($externalIds === []) {
            return [];
        }
        $models = $this->createBuilder(TaskSchedulerModel::query())
            ->whereIn('external_id', $externalIds)
            ->whereIn('status', [TaskSchedulerStatus::Pending->value, TaskSchedulerStatus::Retry->value])
            ->where('id', '>', $afterId)->orderBy('id')->limit($limit)->lockForUpdate()->get();
        $tasks = [];
        foreach ($models as $model) {
            $task = TaskSchedulerFactory::modelToEntity($model);
            $task->setStatus(TaskSchedulerStatus::Canceled);
            $tasks[] = $task;
        }
        $ids = array_map(static fn (TaskScheduler $task): int => $task->getId(), $tasks);
        if ($ids !== []) {
            $this->createBuilder(TaskSchedulerModel::query())->whereIn('id', $ids)
                ->whereIn('status', [TaskSchedulerStatus::Pending->value, TaskSchedulerStatus::Retry->value])
                ->update(['status' => TaskSchedulerStatus::Canceled->value]);
        }
        return $tasks;
    }

    /**
     * 内容类修改同步未认领实例，不重新计算时间或改动生成游标。
     */
    public function updatePendingMetadata(TaskSchedulerCrontab $rule): void
    {
        $this->createBuilder(TaskSchedulerModel::query())
            ->where('external_id', $rule->getExternalId())
            ->whereIn('status', [TaskSchedulerStatus::Pending->value, TaskSchedulerStatus::Retry->value])
            ->update([
                'name' => $rule->getName(),
                'callback_method' => json_encode($rule->getCallbackMethod(), JSON_THROW_ON_ERROR),
                'callback_params' => json_encode($rule->getCallbackParams(), JSON_THROW_ON_ERROR),
                'retry_times' => $rule->getRetryTimes(),
                'remark' => $rule->getRemark(),
                'creator' => $rule->getCreator(),
            ]);
    }

    /**
     * 以单条条件更新认领，Redis租约到期后也只允许一个执行者成功。
     */
    public function claimForExecution(int $id): bool
    {
        return $this->createBuilder(TaskSchedulerModel::query())->where('id', $id)
            ->whereIn('status', [TaskSchedulerStatus::Pending->value, TaskSchedulerStatus::Retry->value])
            ->update(['status' => TaskSchedulerStatus::Running->value]) === 1;
    }

    /**
     * 只标记本次尚未持久化结果的运行项，不覆盖已提交成功或取消状态。
     */
    public function markRunningFailed(int $id): void
    {
        $this->createBuilder(TaskSchedulerModel::query())->where('id', $id)
            ->where('status', TaskSchedulerStatus::Running->value)
            ->update(['status' => TaskSchedulerStatus::Failed->value]);
    }

    /**
     * 原扫描仍只处理Pending，以ID游标避免认领导致offset跳项。
     * @return TaskScheduler[]
     */
    public function listForExecution(DateTime $cutoff, int $afterId, int $limit = 200): array
    {
        $models = $this->createBuilder(TaskSchedulerModel::query())
            ->where('status', TaskSchedulerStatus::Pending->value)
            ->where('expect_time', '<=', $cutoff->format('Y-m-d H:i:s'))
            ->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get();
        $tasks = [];
        foreach ($models as $model) {
            $tasks[] = TaskSchedulerFactory::modelToEntity($model);
        }
        return $tasks;
    }

    /**
     * 旧取消入口先锁定实际可取消项，避免查后认领导致虚假的取消日志。
     * @return TaskScheduler[]
     */
    public function lockCancelable(TaskSchedulerQuery $query): array
    {
        if ($query->getIds() === [] && $query->getExternalIds() === []) {
            return [];
        }
        $builder = $this->createBuilder(TaskSchedulerModel::query())
            ->whereIn('status', [TaskSchedulerStatus::Pending->value, TaskSchedulerStatus::Retry->value]);
        if ($query->getIds() !== []) {
            $builder->whereIn('id', $query->getIds());
        }
        if ($query->getExternalIds() !== []) {
            $builder->whereIn('external_id', $query->getExternalIds());
        }
        if ($query->getExpectTimeLt() !== null) {
            $builder->where('expect_time', '<=', $query->getExpectTimeLt()->format('Y-m-d H:i:s'));
        }
        if ($query->getStatus() !== null) {
            $builder->where('status', $query->getStatus()->value);
        }
        $tasks = [];
        foreach ($builder->orderBy('id')->limit(500)->lockForUpdate()->get() as $model) {
            $tasks[] = TaskSchedulerFactory::modelToEntity($model);
        }
        return $tasks;
    }

    public function cancelByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $ids = array_values(array_unique($ids));
        $this->createBuilder(TaskSchedulerModel::query())->whereIn('id', $ids)
            ->whereIn('status', [TaskSchedulerStatus::Pending->value, TaskSchedulerStatus::Retry->value])
            ->update(['status' => TaskSchedulerStatus::Canceled->value]);
    }

    public function deleteByIds(array $clearIds): void
    {
        if (empty($clearIds)) {
            return;
        }
        $this->createBuilder(TaskSchedulerModel::query())->whereIn('id', $clearIds)->delete();
    }

    public function changeStatus($id, TaskSchedulerStatus $status): void
    {
        if (empty($id)) {
            return;
        }

        $ids = is_array($id) ? $id : [$id];
        $this->createBuilder(TaskSchedulerModel::query())->whereIn('id', $ids)->update(['status' => $status->value]);
    }

    public function getById(int $id): ?TaskScheduler
    {
        /** @var TaskSchedulerModel $model */
        $model = $this->createBuilder(TaskSchedulerModel::query())->find($id);
        return $model === null ? null : TaskSchedulerFactory::modelToEntity($model);
    }

    public function clearByExternalId(string $externalId): void
    {
        $queryBuilder = $this->createBuilder(TaskSchedulerModel::query());
        $queryBuilder->where('external_id', $externalId)->delete();
    }
}
