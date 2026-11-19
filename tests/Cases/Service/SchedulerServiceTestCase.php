<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Service;

use Dtyq\TaskScheduler\Entity\TaskSchedulerCrontab;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerCrontabRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerLogRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerRepository;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use Dtyq\TaskScheduler\Test\Cases\EnvironmentTestCase;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\NullLogger;

/**
 * 为包用例提供纯 Mock 仓储和合成规则，不启动应用或存储。
 * @internal
 * @coversNothing
 */
abstract class SchedulerServiceTestCase extends EnvironmentTestCase
{
    protected TaskSchedulerRepository $tasks;

    protected TaskSchedulerCrontabRepository $rules;

    protected TaskSchedulerLogRepository $logs;

    protected LoggerFactory $loggerFactory;

    protected TaskSchedulerDomainService $service;

    /**
     * 每个用例独立装配仓储替身，避免事务或业务状态泄漏。
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnvironment();
        $this->tasks = $this->createMock(TaskSchedulerRepository::class);
        $this->rules = $this->createMock(TaskSchedulerCrontabRepository::class);
        $this->logs = $this->createMock(TaskSchedulerLogRepository::class);
        $this->loggerFactory = $this->createMock(LoggerFactory::class);
        $this->loggerFactory->method('get')->willReturn(new NullLogger());
        $this->service = new TaskSchedulerDomainService($this->tasks, $this->logs, $this->rules, $this->loggerFactory);
    }

    /**
     * 构造已持久化的占位规则，不使用真实回调。
     */
    protected function rule(string $text = '* * * * *', int $id = 123): TaskSchedulerCrontab
    {
        $rule = new TaskSchedulerCrontab();
        $rule->setName('mock-rule');
        $rule->setExternalId('mock-external');
        $rule->setCrontab($text);
        $rule->setEnabled(true);
        $rule->setCallbackMethod(['MockCallback', 'execute']);
        $rule->prepareForCreate();
        $rule->setId($id);
        return $rule;
    }
}
