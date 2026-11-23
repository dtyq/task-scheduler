<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Crontab;

use DateTime;
use Dtyq\TaskScheduler\ConfigProvider;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\ValueObject\ExecutionStaggerRule;
use Dtyq\TaskScheduler\Entity\ValueObject\ExecutionStaggerRuleSet;
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
    /**
     * 快扫下界在最大窗口之外再多看的秒数，覆盖分钟入口的扫描间隔与时钟误差，
     * 保证两个入口交接处的任务至少被其中一个扫到。
     */
    private const STAGGER_LOOKBACK_SECONDS = 120;

    protected Concurrent $concurrent;

    private LoggerInterface $logger;

    private array $config;

    /**
     * 本进程已记录过的错峰配置警告。Hyperf crontab 每次触发都会新建执行器实例，
     * 警告按内容在进程内去重，避免每个 tick 重复记录。
     * @var array<string, true>
     */
    private static array $reportedStaggerWarnings = [];

    /**
     * 错峰规则集；为 null 时等同于未启用错峰。
     */
    private ?ExecutionStaggerRuleSet $staggerRules = null;

    public function __construct(
        private readonly TaskSchedulerDomainService $scheduleTaskDomainService,
        private readonly LoggerFactory $loggerFactory,
        private readonly Locker $locker
    ) {
        $this->config = config('task_scheduler');
        $this->logger = $this->loggerFactory->get('task_scheduler');
        $limit = (int) ($this->config['concurrent_limit'] ?? 500);
        // Hyperf crontab 每次触发都会新建执行器实例，分钟入口与快扫入口各自持有本次触发的协程池。
        $this->concurrent = new Concurrent(max($limit, 1));
        $this->staggerRules = $this->buildStaggerRules();
    }

    public function execute(): void
    {
        $cutoff = new DateTime();
        $this->recoverRunning($cutoff);
        $afterId = 0;
        $deferred = 0;
        for ($batch = 0; $batch < 1000; ++$batch) {
            $tasks = $this->scheduleTaskDomainService->listTasksForExecution($cutoff, $afterId, 200);
            if ($tasks === []) {
                break;
            }
            foreach ($tasks as $task) {
                $afterId = $task->getId();
                // 命中错峰规则但未到有效时刻的任务保持 Pending，交给快扫或后续分钟扫描。
                if (! $this->isDue($task, $cutoff)) {
                    ++$deferred;
                    continue;
                }
                $this->concurrent->create(function () use ($task): void {
                    $this->run($task);
                });
            }
        }
        if ($deferred > 0) {
            $this->logger->debug('分钟扫描跳过未到错峰时刻的任务', ['count' => $deferred]);
        }
    }

    /**
     * 错峰快扫入口：按基础 tick 触发，只处理本 tick 需要扫描的规则下已到有效时刻的任务。
     * 不做 Running 维护，Running 维护仍只由分钟入口负责。
     */
    public function executeStaggered(): void
    {
        $rules = $this->staggerRules;
        if ($rules === null || ! $rules->isEnabled()) {
            return;
        }
        $now = new DateTime();
        $scanRules = $rules->rulesToScanAt($rules->tickSlot($now->getTimestamp()));
        if ($scanRules === []) {
            return;
        }
        $maxWindow = max(array_map(static fn (ExecutionStaggerRule $rule): int => $rule->getWindowSeconds(), $scanRules));
        $notBefore = (clone $now)->setTimestamp($now->getTimestamp() - $maxWindow - self::STAGGER_LOOKBACK_SECONDS);

        $afterId = 0;
        $deferred = 0;
        for ($batch = 0; $batch < 1000; ++$batch) {
            $tasks = $this->scheduleTaskDomainService->listTasksForExecution($now, $afterId, 200, $notBefore);
            if ($tasks === []) {
                break;
            }
            foreach ($tasks as $task) {
                $afterId = $task->getId();
                // 回调不在 SQL 中筛选，未命中本 tick 规则的任务交给分钟入口。
                $rule = $rules->ruleFor($task);
                if ($rule === null || ! isset($scanRules[$rule->getCallbackKey()])) {
                    continue;
                }
                if (! $rules->isDue($task, $now)) {
                    ++$deferred;
                    continue;
                }
                $this->concurrent->create(function () use ($task): void {
                    $this->run($task);
                });
            }
        }
        if ($deferred > 0) {
            $this->logger->debug('错峰快扫中尚未到有效时刻的任务', ['count' => $deferred]);
        }
    }

    /**
     * 构建错峰规则集，没有有效规则时返回 null；配置问题每个进程只记录一次，不按任务或 tick 刷日志。
     */
    private function buildStaggerRules(): ?ExecutionStaggerRuleSet
    {
        $rules = ExecutionStaggerRuleSet::fromConfig((array) ($this->config['stagger'] ?? []));
        if ($rules->getProblems() !== []) {
            $this->warnStaggerOnce('错峰配置存在问题，相关规则已忽略', ['problems' => $rules->getProblems()]);
        }
        if (! $rules->isEnabled()) {
            return null;
        }
        if ($rules->getTickSeconds() !== ConfigProvider::staggerTickSeconds()) {
            $this->warnStaggerOnce('错峰 tick_seconds 与快扫入口注册频率不一致，跳拍按配置值计算', [
                'tick_seconds' => $rules->getTickSeconds(),
                'registered_tick_seconds' => ConfigProvider::staggerTickSeconds(),
            ]);
        }
        return $rules;
    }

    /**
     * 同一内容的错峰配置警告在本进程只记录一次；配置变化后内容不同，会重新记录。
     */
    private function warnStaggerOnce(string $message, array $context): void
    {
        $key = md5($message . json_encode($context));
        if (isset(self::$reportedStaggerWarnings[$key])) {
            return;
        }
        self::$reportedStaggerWarnings[$key] = true;
        $this->logger->warning($message, $context);
    }

    /**
     * 未启用错峰时，扫描结果本身已满足计划时间到期。
     */
    private function isDue(TaskScheduler $task, DateTime $now): bool
    {
        return $this->staggerRules === null || $this->staggerRules->isDue($task, $now);
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
            // 以重读后的任务为准再确认一次有效时刻，未到期则保持 Pending。
            if (! $this->isDue($taskScheduler, new DateTime())) {
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
