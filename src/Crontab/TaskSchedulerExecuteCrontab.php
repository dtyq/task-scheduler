<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Crontab;

use DateTime;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\ValueObject\TaskSchedulerStatus;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use Dtyq\TaskScheduler\Util\Locker;
use Hyperf\Coroutine\Concurrent;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

use function Hyperf\Config\config;

class TaskSchedulerExecuteCrontab
{
    protected Concurrent $concurrent;

    private LoggerInterface $logger;

    private array $config;

    public function __construct(
        private readonly TaskSchedulerDomainService $scheduleTaskDomainService,
        private readonly LoggerFactory $loggerFactory,
        private readonly Locker $locker
    ) {
        $this->config = config('task_scheduler');
        $this->logger = $this->loggerFactory->get('task_scheduler');
        $limit = (int) ($this->config['concurrent_limit'] ?? 500);
        $this->concurrent = new Concurrent(max($limit, 1));
    }

    public function execute(): void
    {
        $cutoff = new DateTime();
        $this->recoverRunning($cutoff);
        $afterId = 0;
        for ($batch = 0; $batch < 1000; ++$batch) {
            $tasks = $this->scheduleTaskDomainService->listTasksForExecution($cutoff, $afterId, 200);
            if ($tasks === []) {
                break;
            }
            foreach ($tasks as $task) {
                $afterId = $task->getId();
                $this->concurrent->create(function () use ($task): void {
                    $this->run($task);
                });
            }
        }
    }

    /**
     * 历史与近期Running分开限量维护，恢复后的Pending沿原执行流程认领。
     */
    private function recoverRunning(DateTime $now): void
    {
        $timeout = (int) ($this->config['running_timeout_seconds'] ?? 3600);
        if ($timeout <= 0) {
            return;
        }
        if ($timeout >= 7200) {
            $this->logger->warning('运行超时阈值必须小于恢复窗口，跳过本轮Running维护');
            return;
        }
        for ($batch = 0; $batch < 100; ++$batch) {
            try {
                $ids = $this->scheduleTaskDomainService->closeExpiredRunning($now, 200);
                if ($ids === []) {
                    break;
                }
                $this->logger->warning('关闭超出两小时恢复窗口的Running实例', [
                    'task_scheduler_ids' => $ids,
                    'count' => count($ids),
                ]);
            } catch (Throwable $error) {
                $this->logger->error('历史Running关闭失败', [
                    'exception_type' => get_class($error),
                ]);
                break;
            }
        }
        $afterId = 0;
        for ($batch = 0; $batch < 100; ++$batch) {
            try {
                $result = $this->scheduleTaskDomainService->recoverRunningBatch($now, $timeout, $afterId, 200);
                $lastId = $result['last_id'] ?? $afterId;
                if ($lastId <= $afterId) {
                    break;
                }
                $afterId = $lastId;
                $this->logger->warning('Running超时维护完成', [
                    'retried_ids' => $result['retried'],
                    'timed_out_ids' => $result['timed_out'],
                ]);
            } catch (Throwable $error) {
                $this->logger->error('Running超时恢复失败', [
                    'exception_type' => get_class($error),
                ]);
                break;
            }
        }
    }

    private function run(TaskScheduler $taskScheduler): void
    {
        $expire = (int) ($this->config['lock_timeout'] ?? 60 * 10);
        $lockKey = "TaskSchedulerExecuteCrontab-{$taskScheduler->getId()}";
        // 恢复后的执行可能与旧回调并存，锁所有者必须逐次唯一。
        $lockOwner = 'TaskSchedulerExecuteCrontab-' . bin2hex(random_bytes(16));
        $locked = false;

        try {
            $locked = $this->locker->mutexLock($lockKey, $lockOwner, max($expire, 1));
            if (! $locked) {
                return;
            }

            // 实时查询最新数据
            $taskScheduler = $this->scheduleTaskDomainService->getById($taskScheduler->getId());
            if (! $taskScheduler || ! in_array($taskScheduler->getStatus(), [TaskSchedulerStatus::Pending, TaskSchedulerStatus::Retry], true)) {
                return;
            }
            $this->scheduleTaskDomainService->execute($taskScheduler);
        } catch (Throwable $throwable) {
            $this->logger->notice('执行调度失败', [
                'task_scheduler_id' => $taskScheduler->getId(),
                'exception' => $throwable->getMessage(),
            ]);
        } finally {
            if ($locked) {
                $this->locker->release($lockKey, $lockOwner);
            }
        }
    }
}
