<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Test\Cases\Util;

use Dtyq\TaskScheduler\Exception\TaskSchedulerParamsSchedulerException;
use Dtyq\TaskScheduler\Test\Cases\EnvironmentTestCase;
use Dtyq\TaskScheduler\Util\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * 验证环境缺失不会静默放宽隔离范围。
 * @internal
 * @coversNothing
 */
class FunctionsTest extends EnvironmentTestCase
{
    /**
     * 缺少全局环境函数时明确拒绝隔离查询。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMissingEnvironmentFunctionIsRejected(): void
    {
        self::assertFalse(function_exists('app_env'));
        $this->expectException(TaskSchedulerParamsSchedulerException::class);
        Functions::getEnv();
    }

    /**
     * 关闭隔离不依赖全局环境函数。
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDisabledIsolationDoesNotRequireEnvironmentFunction(): void
    {
        $this->config->set('task_scheduler.environment_enabled', false);
        self::assertFalse(function_exists('app_env'));
        self::assertSame('', Functions::getEnv());
    }

    /**
     * 非字符串或空白环境不能退化为全环境查询。
     */
    #[DataProvider('invalidEnvironments')]
    public function testInvalidEnvironmentIsRejected(mixed $environment): void
    {
        $this->setEnvironment($environment);
        $this->expectException(TaskSchedulerParamsSchedulerException::class);
        Functions::getEnv();
    }

    /**
     * 提供合成的无效环境值。
     */
    public static function invalidEnvironments(): array
    {
        return [
            [
                '',
            ],
            [
                '   ',
            ],
            [
                "\t\n",
            ],
            [
                null,
            ],
            [
                false,
            ],
            [
                0,
            ],
            [
                [],
            ],
        ];
    }

    /**
     * 正常环境保留原值，包括数字字符串和非空值两侧的空格。
     */
    #[DataProvider('validEnvironments')]
    public function testValidEnvironmentIsPreserved(string $environment): void
    {
        $this->setEnvironment($environment);
        self::assertSame($environment, Functions::getEnv());
    }

    /**
     * 提供可区分于空串的有效环境值。
     */
    public static function validEnvironments(): array
    {
        return [
            [
                'test-current',
            ],
            [
                '0',
            ],
            [
                ' test-current ',
            ],
        ];
    }

    /**
     * 发布配置与运行时缺省共同保持严格隔离。
     */
    public function testPublishedConfigurationDefaults(): void
    {
        $config = require dirname(__DIR__, 3) . '/publish/task_scheduler.php';
        self::assertTrue($config['environment_enabled']);
        self::assertFalse($config['include_empty_environment']);
        $this->setEnvironment();
        $this->config->set('task_scheduler', []);
        self::assertSame('test-current', Functions::getEnv());
    }
}
