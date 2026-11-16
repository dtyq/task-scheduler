<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases;

use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * 为环境测试提供独立配置和 Mock 容器，不连接外部系统。
 * @internal
 * @coversNothing
 */
abstract class EnvironmentTestCase extends AbstractTestCase
{
    protected Config $config;

    private ?ContainerInterface $previousContainer;

    /**
     * 保存原容器并安装本测试专用配置。
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = ApplicationContext::hasContainer() ? ApplicationContext::getContainer() : null;
        $this->config = new Config([
            'task_scheduler' => [
                'environment_enabled' => true,
            ],
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->with(ConfigInterface::class)
            ->willReturn(true);
        $container->method('get')
            ->with(ConfigInterface::class)
            ->willReturn($this->config);
        ApplicationContext::setContainer($container);
    }

    /**
     * 恢复原容器，避免环境配置泄漏到其他测试。
     */
    protected function tearDown(): void
    {
        (new ReflectionProperty(ApplicationContext::class, 'container'))
            ->setValue(null, $this->previousContainer);
        parent::tearDown();
    }

    /**
     * 按需安装全局环境替身，缺少函数的测试不加载此夹具。
     */
    protected function setEnvironment(mixed $environment = 'test-current'): void
    {
        require_once dirname(__DIR__) . '/Fixtures/environment.php';
        $this->config->set('test.environment', $environment);
    }
}
