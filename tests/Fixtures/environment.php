<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */
use function Hyperf\Config\config;

if (! function_exists('app_env')) {
    /**
     * 从隔离测试配置读取合成环境，允许覆盖非法返回值场景。
     */
    function app_env(): mixed
    {
        return config('test.environment');
    }
}
