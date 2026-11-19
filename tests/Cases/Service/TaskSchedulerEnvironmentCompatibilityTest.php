<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Service;

use DateTime;
use Dtyq\TaskScheduler\Entity\TaskScheduler;
use Dtyq\TaskScheduler\Entity\TaskSchedulerCrontab;
use Dtyq\TaskScheduler\Factory\TaskSchedulerLogFactory;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerCrontabRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerLogRepository;
use Dtyq\TaskScheduler\Repository\Persistence\TaskSchedulerRepository;
use Dtyq\TaskScheduler\Service\TaskSchedulerDomainService;
use Dtyq\TaskScheduler\Test\Cases\EnvironmentTestCase;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\NullLogger;

/**
 * 使用 Mock 仓储验证历史规则与新实例的环境传递。
 * @internal
 * @coversNothing
 */
class TaskSchedulerEnvironmentCompatibilityTest extends EnvironmentTestCase
{
    /**
     * 空环境规则续建时产生当前环境实例，保留原规则环境并保存生成进度。
     */
    public function testLegacyRuleCreatesCurrentEnvironmentTasks(): void
    {
        $this->setEnvironment();
        $rule = $this->rule();
        $lastGenTime = clone $rule->getLastGenTime();
        $tasks = $this->createMock(TaskSchedulerRepository::class);
        $tasks->expects(self::once())
            ->method('batchCreate')
            ->willReturnCallback(static function (array $batch): void {
                self::assertNotEmpty($batch);
                foreach ($batch as $task) {
                    self::assertSame('test-current', $task->getEnvironment());
                    self::assertSame('mock-external', $task->getExternalId());
                }
            });
        $rules = $this->createMock(TaskSchedulerCrontabRepository::class);
        $rules->method('lockById')->willReturn(clone $rule);
        $rules->expects(self::once())
            ->method('updateGenerationTime')
            ->with(456, self::callback(static fn (DateTime $time): bool => $time > $lastGenTime));

        $this->service($tasks, $rules)
            ->createByCrontab($rule, 1);
    }

    /**
     * 现有编辑入口继续将空环境规则归属到当前环境。
     */
    public function testEditingLegacyRuleAssignsCurrentEnvironment(): void
    {
        $this->setEnvironment();
        $rules = $this->createMock(TaskSchedulerCrontabRepository::class);
        $rules->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (TaskSchedulerCrontab $rule): bool => $rule->getEnvironment() === 'test-current'));
        $this->service($this->createMock(TaskSchedulerRepository::class), $rules)
            ->saveCrontab($this->rule());
    }

    /**
     * 历史实例日志保留空环境，避免伪造其原始归属。
     */
    public function testLegacyTaskLogPreservesEmptyEnvironment(): void
    {
        $this->setEnvironment();
        $task = new TaskScheduler();
        $task->setExternalId('mock-external');
        $task->setName('mock-task');
        $task->setExpectTime(new DateTime('2030-01-01'));
        $task->setCallbackMethod([
            'MockCallback',
            'execute',
        ]);
        $task->prepareForCreation();
        $task->setId(123);
        $task->setEnvironment('');
        self::assertSame('', TaskSchedulerLogFactory::createByScheduleTask($task)
            ->getEnvironment());
    }

    /**
     * 创建已持久化的合成历史规则，避免真实业务数据和外部回调。
     */
    private function rule(): TaskSchedulerCrontab
    {
        $rule = new TaskSchedulerCrontab();
        $rule->setName('mock-rule');
        $rule->setExternalId('mock-external');
        $rule->setCrontab('* * * * *');
        $rule->setEnabled(true);
        $rule->setCallbackMethod([
            'MockCallback',
            'execute',
        ]);
        $rule->prepareForCreate();
        $rule->setId(456);
        $rule->setEnvironment('');
        return $rule;
    }

    /**
     * 装配纯 Mock 持久化和无输出日志，不启动应用或访问存储。
     */
    private function service(TaskSchedulerRepository $tasks, TaskSchedulerCrontabRepository $rules): TaskSchedulerDomainService
    {
        $loggerFactory = $this->createMock(LoggerFactory::class);
        $loggerFactory->method('get')
            ->willReturn(new NullLogger());
        return new TaskSchedulerDomainService($tasks, $this->createMock(TaskSchedulerLogRepository::class), $rules, $loggerFactory);
    }
}
