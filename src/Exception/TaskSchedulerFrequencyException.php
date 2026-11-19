<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\TaskScheduler\Exception;

/**
 * 通用规则频率约束失效，供生成用例暂停不可执行计划。
 */
class TaskSchedulerFrequencyException extends TaskSchedulerParamsSchedulerException
{
}
