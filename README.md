# dtyq/task-scheduler

## 安装
```
composer require dtyq/task-scheduler
php bin/hyperf.php vendor:publish dtyq/task-scheduler
```

## 环境隔离与历史任务兼容

发布配置默认开启环境隔离，默认不包含空环境历史数据：

```php
'environment_enabled' => true,
'include_empty_environment' => false,
```

开启隔离时，应用必须提供全局 `app_env()` 函数并返回非空、非纯空白的字符串。包保留原环境值，不自动修改大小写或去除两侧空格。读取环境时若函数缺失或返回无效值，会抛出 `TaskSchedulerParamsSchedulerException`，不会退化为全环境查询。

| environment_enabled | include_empty_environment | 任务与周期规则的处理范围 |
| --- | --- | --- |
| `false` | 任意 | 不限制环境，保留旧行为 |
| `true` | `false` 或未配置 | 仅 `environment = app_env()` |
| `true` | `true` | 当前环境，以及 `environment = ''` 的历史数据 |

`include_empty_environment` 同时影响任务与周期规则的查询、续建、防重复检查、更新、取消和清理，不仅影响到期任务扫描。空环境仅指空字符串，不包含 `NULL`。

新建任务和周期规则未显式指定环境时，仍写入当前环境；开启历史兼容不会使新数据继续写空环境。应用显式指定环境的既有契约保持不变。

历史空环境周期规则续建出的新任务会写入当前环境；自动续建保存生成进度时，原规则仍保留空环境。通过 `saveCrontab()` 编辑历史规则时，沿用已有行为，将空环境补为当前环境。已生成的历史任务不会被批量迁移，执行日志保留对应任务的环境。

### 升级与共享数据库

- 已发布到应用中的配置不会因包升级自动覆盖。应用需要显式决定是否开启隔离，以及由谁承接历史空环境数据。
- 对新安装或未覆盖包默认值的应用，默认隔离会使历史空环境数据不可见。需要处理这些数据时，显式开启 `include_empty_environment`。
- 多个环境共享数据库时，只指定一个环境开启历史兼容；其他环境仅处理自己的任务。此约束由部署配置保证，包不会自动选出历史数据的承接环境。
- 必须同步更新或停止仍关闭隔离的旧执行器。旧执行器不限制环境，仍可能读取其他环境的新任务。
- 不需要为本功能修改表结构或自动回填历史记录。回滚包或关闭隔离前，应先协调执行器及其他环境的调度；关闭隔离会恢复全环境查询。
- 本配置仅决定调度数据的环境范围，不自动隔离业务回调所访问的数据。

## 使用方式请见
```
\Dtyq\TaskScheduler\Service\TaskSchedulerDomainService
```

## 说明
> 仅支持分钟级调用

调度方式
1. 定时调度
2. 指定调度

创建调度任务
1. 定时调度需要有个定时器去生成未来 n 小时内的需要执行的任务数据
2. 根据任务时间生成调度任务

执行任务
1. 执行已到时间的任务，改变状态，如果有误则执行报错事件
2. 调度结束后，记录到归档表

后台执行
1. 每天检测超过 n 天已完成的调度任务，删除。防止调度表过大
2. 每分钟检测未来 n 天内需要执行的任务，生成调度任务
3. 每分钟检测已到时间的任务，执行

数据库
1. 任务调度表(task_scheduler) 用于具体执行的任务记录
2. 任务归档表(task_scheduler_log) 用于保存已完成的任务记录，仅做归档，方便以后回档查看历史记录
3. 定时任务表(task_scheduler_crontab) 用于保存定时任务规则

## 记得创建表结构
```shell
php bin/hyperf.php migrate
```

```sql
-- auto-generated definition
create table task_scheduler
(
    id              bigint unsigned         not null primary key,
    external_id     varchar(64)             not null comment '业务 id',
    name            varchar(64)             not null comment '名称',
    expect_time     datetime                not null comment '预期执行时间',
    actual_time     datetime                null comment '实际执行时间',
    type            tinyint      default 2  not null comment '调度类型：1 定时调度，2 指定调度',
    cost_time       int          default 0  not null comment '耗时 毫秒',
    retry_times     int          default 0  not null comment '剩余重试次数',
    status          tinyint      default 0  not null comment '状态',
    callback_method json                    not null comment '回调方法',
    callback_params json                    not null comment '回调参数',
    remark          varchar(255) default '' not null comment '备注',
    creator         varchar(64)  default '' not null comment '创建人',
    created_at      datetime                not null comment '创建时间'
)
    collate = utf8mb4_unicode_ci;

create index task_scheduler_external_id_index
    on task_scheduler (external_id);

create index task_scheduler_status_expect_time_index
    on task_scheduler (status, expect_time);

-- auto-generated definition
create table task_scheduler_crontab
(
    id              bigint unsigned         not null primary key,
    name            varchar(64)             not null comment '名称',
    crontab         varchar(64)             not null comment 'crontab表达式',
    last_gen_time   datetime                null comment '最后生成时间',
    enabled         tinyint(1)   default 1  not null comment '是否启用',
    retry_times     int          default 0  not null comment '总重试次数',
    callback_method json                    not null comment '回调方法',
    callback_params json                    not null comment '回调参数',
    remark          varchar(255) default '' not null comment '备注',
    creator         varchar(64)  default '' not null comment '创建人',
    created_at      datetime                not null comment '创建时间'
)
    collate = utf8mb4_unicode_ci;



-- auto-generated definition
-- auto-generated definition
create table task_scheduler_log
(
    id              bigint unsigned         not null primary key,
    task_id         bigint unsigned         not null comment '任务ID',
    external_id     varchar(64)             not null comment '业务标识',
    name            varchar(64)             not null comment '名称',
    expect_time     datetime                not null comment '预期执行时间',
    actual_time     datetime                null comment '实际执行时间',
    type            tinyint      default 2  not null comment '类型',
    cost_time       int          default 0  not null comment '耗时',
    status          tinyint      default 0  not null comment '状态',
    callback_method json                    not null comment '回调方法',
    callback_params json                    not null comment '回调参数',
    remark          varchar(255) default '' not null comment '备注',
    creator         varchar(64)  default '' not null comment '创建人',
    created_at      datetime                not null comment '创建时间',
    result          json                    null comment '结果'
)
    collate = utf8mb4_unicode_ci;

create index task_scheduler_log_external_id_index
    on task_scheduler_log (external_id);

create index task_scheduler_log_status_expect_time_index
    on task_scheduler_log (status, expect_time);

create index task_scheduler_log_task_id_index
    on task_scheduler_log (task_id);
```
