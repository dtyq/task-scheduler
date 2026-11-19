<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Crontab;

use DateTime;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

use function Hyperf\Config\config;

class TaskSchedulerCreateCrontab
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly TaskSchedulerDomainService $scheduleTaskDomainService,
        private readonly LoggerFactory $loggerFactory
    ) {
        $this->logger = $this->loggerFactory->get('task_scheduler');
    }

    public function execute(): void
    {
        $days = (int) config('task_scheduler.crontab_days', 3);
        $lowWaterDays = max(0, min($days, (int) config('task_scheduler.generation_low_water_days', 1)));
        $lowWater = (new DateTime())->modify('+' . $lowWaterDays . ' days');
        $afterId = 0;
        // 单次最多扫描一万条；下一分钟重新扫描低水位，避免无限占用生成进程。
        for ($batch = 0; $batch < 100; ++$batch) {
            $rules = $this->scheduleTaskDomainService->listCrontabsForGeneration($lowWater, $afterId, 100);
            if ($rules === []) {
                break;
            }
            foreach ($rules as $rule) {
                $afterId = $rule->getId();
                try {
                    $this->scheduleTaskDomainService->createByCrontab($rule, $days);
                } catch (Throwable $throwable) {
                    $this->logger->notice('创建调度失败', [
                        'task_scheduler_id' => $rule->getId(),
                        'exception' => $throwable->getMessage(),
                    ]);
                }
            }
        }
    }
}
