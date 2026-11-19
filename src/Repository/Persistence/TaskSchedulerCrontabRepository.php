<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Repository\Persistence;

use DateTime;
use Dtyq\TaskScheduler\Entity\Query\Page;
use Dtyq\TaskScheduler\Entity\Query\TaskSchedulerCrontabQuery;
use Dtyq\TaskScheduler\Entity\TaskSchedulerCrontab;
use Dtyq\TaskScheduler\Factory\TaskSchedulerCrontabFactory;
use Dtyq\TaskScheduler\Repository\Persistence\Model\TaskSchedulerCrontabModel;

class TaskSchedulerCrontabRepository extends AbstractRepository
{
    /**
     * 在调用方事务内按现有环境范围锁定并重读规则。
     */
    public function lockById(int $id): ?TaskSchedulerCrontab
    {
        $model = $this->createBuilder(TaskSchedulerCrontabModel::query())->where('id', $id)->lockForUpdate()->first();
        return $model === null ? null : TaskSchedulerCrontabFactory::modelToEntity($model);
    }

    /**
     * 仅更新覆盖游标，不用调用方旧实体覆盖其他规则字段。
     */
    public function updateGenerationTime(int $id, DateTime $time): void
    {
        $this->createBuilder(TaskSchedulerCrontabModel::query())->where('id', $id)
            ->update(['last_gen_time' => $time->format('Y-m-d H:i:s')]);
    }

    /**
     * 按稳定 ID 游标扫描需要补充窗口的规则，跳过已过截止的规则。
     * @return TaskSchedulerCrontab[]
     */
    public function listForGeneration(DateTime $lowWater, int $afterId, int $limit = 100): array
    {
        $models = $this->createBuilder(TaskSchedulerCrontabModel::query())
            ->where('enabled', true)
            ->where('id', '>', $afterId)
            ->where('last_gen_time', '<=', $lowWater->format('Y-m-d H:i:s'))
            ->where(static function ($query): void {
                $query->whereNull('deadline')->orWhere('deadline', '>=', date('Y-m-d H:i:s'));
            })
            ->orderBy('id')->limit($limit)->get();
        $rules = [];
        foreach ($models as $model) {
            $rules[] = TaskSchedulerCrontabFactory::modelToEntity($model);
        }
        return $rules;
    }

    /**
     * 批量生命周期操作先按规则ID排序锁定，沿用当前环境范围。
     * @return TaskSchedulerCrontab[]
     */
    public function lockByExternalIds(array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }
        $models = $this->createBuilder(TaskSchedulerCrontabModel::query())
            ->whereIn('external_id', $externalIds)->orderBy('id')->lockForUpdate()->get();
        $rules = [];
        foreach ($models as $model) {
            $rules[] = TaskSchedulerCrontabFactory::modelToEntity($model);
        }
        return $rules;
    }

    /**
     * 在已取得的规则锁内批量暂停，不改变规则文本和游标。
     */
    public function disableByIds(array $ids): void
    {
        if ($ids !== []) {
            $this->createBuilder(TaskSchedulerCrontabModel::query())->whereIn('id', $ids)->update(['enabled' => false]);
        }
    }

    public function create(TaskSchedulerCrontab $crontab): TaskSchedulerCrontab
    {
        $existing = $this->createBuilder(TaskSchedulerCrontabModel::query())
            ->where('external_id', $crontab->getExternalId())->where('crontab', $crontab->getCrontab())->first();
        if ($existing !== null) {
            return TaskSchedulerCrontabFactory::modelToEntity($existing);
        }
        $model = new TaskSchedulerCrontabModel();
        $model->fill($crontab->toModelArray());
        $model->save();

        $crontab->setId($model->id);
        return $crontab;
    }

    public function save(TaskSchedulerCrontab $crontab): void
    {
        $builder = $this->createBuilder(TaskSchedulerCrontabModel::query());

        $model = $builder->find($crontab->getId());
        if (! $model) {
            return;
        }
        $model->fill($crontab->toModelArray());
        $model->save();
    }

    public function existsByExternalIdAndCrontab(string $externalId, string $crontab): bool
    {
        return $this->createBuilder(TaskSchedulerCrontabModel::query())->where('external_id', $externalId)->where('crontab', $crontab)->exists();
    }

    public function existsByExternalId(string $externalId): bool
    {
        return $this->createBuilder(TaskSchedulerCrontabModel::query())->where('external_id', $externalId)->exists();
    }

    /**
     * @return array{total: int, list: array<TaskSchedulerCrontab>}
     */
    public function queries(TaskSchedulerCrontabQuery $query, Page $page): array
    {
        $queryBuilder = $this->createBuilder(TaskSchedulerCrontabModel::query());
        if ($query->getLastGenTimeGt()) {
            $queryBuilder->where('last_gen_time', '<=', $query->getLastGenTimeGt()->format('Y-m-d H:i:s'));
        }
        if (! is_null($query->getEnable())) {
            $queryBuilder->where('enabled', $query->getEnable());
        }

        if ($query->getCreator()) {
            $queryBuilder->where('creator', $query->getCreator());
        }

        if ($query->getFilterId()) {
            $queryBuilder->where('filter_id', 'like', '%' . $query->getFilterId() . '%');
        }

        foreach ($query->getOrder() as $column => $order) {
            $queryBuilder->orderBy($column, $order);
        }
        if (! $page->isEnable()) {
            $collection = $queryBuilder->get();
            $resultList = [];

            foreach ($collection as $model) {
                if ($model instanceof TaskSchedulerCrontabModel) {
                    $resultList[] = TaskSchedulerCrontabFactory::modelToEntity($model);
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
                if ($model instanceof TaskSchedulerCrontabModel) {
                    $list[] = TaskSchedulerCrontabFactory::modelToEntity($model);
                }
            }
        }

        return [
            'total' => $total,
            'list' => $list,
        ];
    }

    public function clearByExternalId(string $externalId): void
    {
        $queryBuilder = $this->createBuilder(TaskSchedulerCrontabModel::query());
        $queryBuilder->where('external_id', $externalId)->delete();
    }

    public function getByCrontabId(int $crontabId): ?TaskSchedulerCrontabModel
    {
        return $this->createBuilder(TaskSchedulerCrontabModel::query())->where('id', $crontabId)->first();
    }
}
