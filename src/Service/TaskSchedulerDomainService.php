<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Service;

use DateInterval;
use DateTime;
use Dtyq\TaskScheduler\Entity\Query\Page;
use Dtyq\TaskScheduler\Entity\Query\TaskSchedulerCrontabQuery;
use Dtyq\TaskScheduler\Entity\Query\TaskSchedulerQuery;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\TaskSchedulerCrontab;
use Dtyq\TaskScheduler\Entity\ValueObject\ScheduleRule;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerExecuteResult;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerStatus;
use Dtyq\TaskScheduler\Exception\TaskSchedulerParamsSchedulerException;
use Dtyq\TaskScheduler\Factory\TaskSchedulerCrontabFactory;
use Dtyq\TaskScheduler\Factory\TaskSchedulerFactory;
use Dtyq\TaskScheduler\Factory\TaskSchedulerLogFactory;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerCrontabRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerLogRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerRepository;
use Hyperf\DbConnection\Annotation\Transactional;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class TaskSchedulerDomainService
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly TaskSchedulerRepository $scheduleTaskRepository,
        private readonly TaskSchedulerLogRepository $scheduleTaskLogRepository,
        private readonly TaskSchedulerCrontabRepository $scheduleTaskCrontabRepository,
        private readonly LoggerFactory $loggerFactory
    ) {
        $this->logger = $this->loggerFactory->get('task_scheduler');
    }

    public function getById(int $id): ?TaskScheduler
    {
        return $this->scheduleTaskRepository->getById($id);
    }

    public function getByCrontabId(int $crontabId): ?TaskSchedulerCrontab
    {
        $crontab = $this->scheduleTaskCrontabRepository->getByCrontabId($crontabId);
        if (! $crontab) {
            return null;
        }
        // 转TaskSchedulerCrontab实体
        return TaskSchedulerCrontabFactory::modelToEntity($crontab);
    }

    /**
     * 创建指定调度.
     */
    public function create(TaskScheduler $scheduleTask): void
    {
        $scheduleTask->prepareForCreation();
        $this->scheduleTaskRepository->save($scheduleTask);
    }

    /**
     * 创建定时调度.
     */
    public function createCrontab(TaskSchedulerCrontab $scheduleTaskCrontab): TaskSchedulerCrontab
    {
        $scheduleTaskCrontab->prepareForCreate();
        return $this->scheduleTaskCrontabRepository->create($scheduleTaskCrontab);
    }

    /**
     * 批量创建定时调度.
     */
    public function batchCreate(array $scheduleTasks): void
    {
        $newScheduleTasks = [];
        foreach ($scheduleTasks as $scheduleTask) {
            /*
             *  TaskScheduler $scheduleTask
             */
            $scheduleTask->prepareForCreation();
            $newScheduleTasks[] = $scheduleTask;
        }
        $this->scheduleTaskRepository->batchCreate($newScheduleTasks);
    }

    /**
     * 修改定时调度.
     */
    #[Transactional]
    public function saveCrontab(TaskSchedulerCrontab $scheduleTaskCrontab): void
    {
        $scheduleTaskCrontab->prepareForUpdate();
        $previous = $this->scheduleTaskCrontabRepository->lockById($scheduleTaskCrontab->getId());
        if ($previous === null || $previous->getExternalId() !== $scheduleTaskCrontab->getExternalId()) {
            throw new TaskSchedulerParamsSchedulerException('规则不存在或业务标识不可修改');
        }
        if ($previous->getEnvironment() !== '' && $previous->getEnvironment() !== $scheduleTaskCrontab->getEnvironment()) {
            throw new TaskSchedulerParamsSchedulerException('规则环境不可修改');
        }
        $changed = $previous->getCrontab() !== $scheduleTaskCrontab->getCrontab()
            || $previous->isEnabled() !== $scheduleTaskCrontab->isEnabled()
            || $previous->getDeadline()?->getTimestamp() !== $scheduleTaskCrontab->getDeadline()?->getTimestamp();
        $scheduleTaskCrontab->setCreatedAt($previous->getCreatedAt());
        if (! $changed) {
            $scheduleTaskCrontab->setLastGenTime(clone $previous->getLastGenTime());
            $this->scheduleTaskCrontabRepository->save($scheduleTaskCrontab);
            $this->scheduleTaskRepository->updatePendingMetadata($scheduleTaskCrontab);
            return;
        }
        $this->cancelPending([$previous->getExternalId()]);
        $start = ScheduleRule::parse($scheduleTaskCrontab->getCrontab())->getStartTimestamp();
        $scheduleTaskCrontab->setLastGenTime((new DateTime())->setTimestamp(max(time(), ($start ?? 0) - 1)));
        $this->scheduleTaskCrontabRepository->save($scheduleTaskCrontab);
        if ($scheduleTaskCrontab->isEnabled()) {
            $this->createByCrontab($scheduleTaskCrontab);
        }
    }

    /**
     * 批量暂停逻辑计划并取消未认领项，接入方保持一个业务标识对应一个活动计划。
     */
    #[Transactional]
    public function pauseByExternalIds(array $externalIds): int
    {
        if ($externalIds === []) {
            return 0;
        }
        if (count($externalIds) > 100) {
            throw new TaskSchedulerParamsSchedulerException('单批暂停业务标识不得超过100个');
        }
        foreach ($externalIds as $externalId) {
            if (! is_string($externalId) || $externalId === '') {
                throw new TaskSchedulerParamsSchedulerException('业务标识必须为非空字符串');
            }
        }
        $externalIds = array_values(array_unique($externalIds));
        $rules = $this->scheduleTaskCrontabRepository->lockByExternalIds($externalIds);
        $ids = array_map(static fn (TaskSchedulerCrontab $rule): int => $rule->getId(), $rules);
        $this->scheduleTaskCrontabRepository->disableByIds($ids);
        return $this->cancelPending($externalIds);
    }

    /**
     * 纯预览复用运行规则，不修改生成游标或数据库。
     * @return DateTime[]
     */
    public function previewCrontab(TaskSchedulerCrontab $rule, DateTime $after, int $limit = 3): array
    {
        return ScheduleRule::parse($rule->getCrontab())->preview($after, $limit, $rule->getDeadline());
    }

    /**
     * 返回开启规则的下一时刻，暂停不等于任务自然完成。
     */
    public function getNextExecutionTime(TaskSchedulerCrontab $rule, ?DateTime $after = null): ?DateTime
    {
        if (! $rule->isEnabled()) {
            return null;
        }
        return $this->previewCrontab($rule, $after ?? new DateTime(), 1)[0] ?? null;
    }

    // 清理scheduler 调度 和 crontab 规则
    #[Transactional]
    public function clearByExternalId(string $externalId): void
    {
        $this->scheduleTaskCrontabRepository->lockByExternalIds([$externalId]);
        // 原清理接口保持派生数据清理语义；安全启停使用pauseByExternalIds。
        // 清理 scheduler 调度
        $this->scheduleTaskRepository->clearByExternalId($externalId);
        // 清理 crontab 规则
        $this->scheduleTaskCrontabRepository->clearByExternalId($externalId);
    }

    // 清理scheduler 调度
    #[Transactional]
    public function clearTaskByExternalId(string $externalId): void
    {
        $this->scheduleTaskCrontabRepository->lockByExternalIds([$externalId]);
        // 清理 scheduler 调度
        $this->scheduleTaskRepository->clearByExternalId($externalId);
    }

    /**
     * 通过定时规则创建指定调度.
     */
    #[Transactional]
    public function createByCrontab(TaskSchedulerCrontab $scheduleTaskCrontab, int $days = 3): void
    {
        if ($days < 1) {
            throw new TaskSchedulerParamsSchedulerException('生成窗口天数必须为正整数');
        }
        $rule = $this->scheduleTaskCrontabRepository->lockById($scheduleTaskCrontab->getId());
        if ($rule === null || ! $rule->isEnabled()) {
            return;
        }
        $rule->prepareForCreateScheduleTask();
        $now = new DateTime();
        $endTime = (clone $now)->add(new DateInterval('P' . $days . 'D'));
        if ($rule->getDeadline() !== null && $rule->getDeadline() < $endTime) {
            $endTime = clone $rule->getDeadline();
        }
        if ($endTime < $rule->getLastGenTime()) {
            return;
        }
        // 新规则跳过未生成的旧周期，保留当前分钟以支持首次执行本次。
        $floor = intdiv($now->getTimestamp(), 60) * 60 - 1;
        if (ScheduleRule::parse($rule->getCrontab())->isExtended() && $rule->getLastGenTime()->getTimestamp() < $floor) {
            $rule->setLastGenTime((new DateTime())->setTimestamp($floor));
        }
        if ($endTime < $rule->getLastGenTime()) {
            return;
        }
        $dates = $rule->listCycleDate($endTime, 100);
        $existing = array_fill_keys($this->scheduleTaskRepository->findExistingTimes($rule->getExternalId(), $dates), true);
        $tasks = [];
        foreach ($dates as $date) {
            if (isset($existing[$date->format('Y-m-d H:i:s')])) {
                continue;
            }
            $task = TaskSchedulerFactory::createByCrontab($rule, $date);
            $task->prepareForCreation();
            $tasks[] = $task;
        }
        if ($tasks !== []) {
            $this->scheduleTaskRepository->batchCreate($tasks);
        }
        $this->scheduleTaskCrontabRepository->updateGenerationTime($rule->getId(), $rule->getLastGenTime());
        $scheduleTaskCrontab->setLastGenTime(clone $rule->getLastGenTime());
        // 已生成到截止不等于执行结束，不能提前关闭尚有未来执行项的规则。
    }

    /**
     * 为后台续建提供不受条件变动影响的有界扫描。
     * @return TaskSchedulerCrontab[]
     */
    public function listCrontabsForGeneration(DateTime $lowWater, int $afterId, int $limit = 100): array
    {
        return $this->scheduleTaskCrontabRepository->listForGeneration($lowWater, $afterId, $limit);
    }

    /**
     * 查询调度任务.
     * @return array{total: int, list: array<TaskScheduler>}
     */
    public function queries(TaskSchedulerQuery $query, Page $page): array
    {
        return $this->scheduleTaskRepository->queries($query, $page);
    }

    /**
     * 查询定时调度任务.
     * @return array{total: int, list: array<TaskSchedulerCrontab>}
     */
    public function queriesCrontab(TaskSchedulerCrontabQuery $query, Page $page): array
    {
        return $this->scheduleTaskCrontabRepository->queries($query, $page);
    }

    /**
     * 执行调度任务.
     */
    public function execute(TaskScheduler $scheduleTask): TaskSchedulerExecuteResult
    {
        $this->logger->info('execute_task_scheduler_start', ['id' => $scheduleTask->getId()]);
        $scheduleTask->prepareForExecution();

        try {
            $this->scheduleTaskRepository->changeStatus($scheduleTask->getId(), TaskSchedulerStatus::Running);
            $result = $scheduleTask->execute();
            if ($result->isSuccess()) {
                $scheduleTask->setStatus(TaskSchedulerStatus::Success);
            } else {
                $retryTimes = max(0, $scheduleTask->getRetryTimes() - 1);
                $scheduleTask->setRetryTimes($retryTimes);
                if ($retryTimes > 0) {
                    $scheduleTask->setStatus(TaskSchedulerStatus::Retry);
                } else {
                    // 重试次数用完，标记为失败
                    $scheduleTask->setStatus(TaskSchedulerStatus::Failed);
                }
            }
            $this->scheduleTaskRepository->save($scheduleTask);
            $this->createLog($scheduleTask, $result);
            $this->logger->info('execute_task_scheduler_success', ['id' => $scheduleTask->getId(), 'result' => $result->toArray()]);
        } catch (Throwable $throwable) {
            $this->scheduleTaskRepository->changeStatus($scheduleTask->getId(), TaskSchedulerStatus::Failed);
            $this->logger->error('execute_task_scheduler_fail', ['id' => $scheduleTask->getId(), 'exception' => $throwable->getMessage()]);
            throw $throwable;
        }
        return $result;
    }

    /**
     * 取消调度任务.
     */
    #[Transactional]
    public function cancel(TaskSchedulerQuery $query): void
    {
        if (empty($query->getIds()) || empty($query->getExternalIds())) {
            return;
        }
        if (count($query->getIds()) > 100 || count($query->getExternalIds()) > 100) {
            throw new TaskSchedulerParamsSchedulerException('Too many ids to cancel');
        }
        $data = $this->queries($query, new Page(1, 500));
        $cancelIds = [];
        foreach ($data['list'] as $task) {
            $task->prepareForCancel();
            $cancelIds[] = $task->getId();
        }

        $this->scheduleTaskRepository->cancelByIds($cancelIds);
        foreach ($data['list'] as $task) {
            $this->createLog($task);
        }
        $this->logger->info('cancel_task_scheduler', ['ids' => $cancelIds]);
    }

    public function deleteByIds(array $clearIds): void
    {
        $this->scheduleTaskRepository->deleteByIds($clearIds);
    }

    public function existsByExternalId(string $externalId): bool
    {
        return $this->scheduleTaskCrontabRepository->existsByExternalId($externalId);
    }

    /**
     * 按ID分批取消与归档日志，调用者已持有规则锁和外层事务。
     */
    private function cancelPending(array $externalIds): int
    {
        $afterId = 0;
        $count = 0;
        while (true) {
            $tasks = $this->scheduleTaskRepository->cancelPendingBatch($externalIds, $afterId);
            if ($tasks === []) {
                return $count;
            }
            $logs = [];
            foreach ($tasks as $task) {
                $afterId = $task->getId();
                $log = TaskSchedulerLogFactory::createByScheduleTask($task);
                $log->prepareForCreation();
                // 历史实例日志保留原环境，不将空环境伪造为当前环境。
                $log->setEnvironment($task->getEnvironment());
                $logs[] = $log;
            }
            $this->scheduleTaskLogRepository->batchCreate($logs);
            $count += count($tasks);
        }
    }

    /**
     * 归档.
     */
    private function createLog(TaskScheduler $scheduleTask, ?TaskSchedulerExecuteResult $result = null): void
    {
        $log = TaskSchedulerLogFactory::createByScheduleTask($scheduleTask);
        $log->setResult($result);
        $log->prepareForCreation();
        $this->scheduleTaskLogRepository->create($log);
    }
}
