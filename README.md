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

## 版本化计划规则

规则仍保存在原 `crontab` 字段，最多 64 字符，无需修改表结构：

| 文本 | 行为 |
| --- | --- |
| `0 9 * * 1-5` | 兼容旧 Cron 语法和原时区 |
| `@every:v1:<interval_seconds>:<first_run_unix_seconds>` | 从首次绝对时刻按固定秒数推进，间隔为正整数分钟 |
| `@cron:v1:<start_unix_seconds>:<minimum_seconds>:<timezone>:<expression>` | 在 IANA 时区解释标准五字段 Cron，保存生效边界及可选最小触发间隔 |

通过 `ScheduleRule::every()`、`ScheduleRule::cron()` 构造，`toString()` 输出持久规则，`parse()` 读取规则。`nextAfter()` 严格返回游标之后的下一点，`preview()` 支持有界数量与包含边界的截止时间；结果统一转换到现有服务存储时区，不修改全局默认时区。新 Cron 仅支持数字、星号、列表、范围、步长及月份/星期英文名，不接受秒/年字段、宏或 L/W/#/?；旧裸 Cron 仍沿原解析库。

`minimum_seconds=0` 表示不启用频率约束，包不设置产品级默认值。调用方负责用户身份、时区来源及产品政策，包不读取用户设置或套餐。超过 64 字符、非法版本、非整数或溢出参数明确拒绝；数字列表可等价压缩为范围，不截断或替换时区。

`TaskConfigDomainService` 新增 `IntervalRepeat`、`CronRepeat` 及可选命名参数，使用 `getScheduleRule()` 获取新规则。间隔的 `includeStart` 默认 false，即从开始后的第一个间隔执行；true 使用开始时刻。旧 `getCrontabRule()` 继续只返回裸 Cron，单次仍沿原执行项入口。历史规则不批量改写，修改用户时区不自动改写已保存的规则。

升级前先确保所有生成、预览和执行进程安装能识别新格式的版本，再开放新规则写入。回滚到旧版本前须停用新规则并处理未认领执行项，不支持将扩展文本直接交给旧 Cron 解析器。

### 有界生成与续建

`createByCrontab()` 在既有事务入口中先按环境锁定并重读规则，每批至多计算100个触发点，批量查重及插入后只更新 `last_gen_time`。满批推进到最后一个触发点，不满批推进到窗口末尾，空窗口同样有进度。`crontab_days` 控制目标窗口，`generation_low_water_days` 默认1天，后台用ID游标扫描需要补充的规则；不保证保存时一次生成完整窗口。

新规则跳过未生成的旧周期，保留当前分钟供首次触发；已生成到期项继续沿既有执行规则。生成到未来截止不提前关闭规则。无数据库唯一约束时，防重依赖同规则的统一事务入口及行锁；调用方须保持一个业务标识对应一个活动计划，不能用此保证替代绕过包直接写表的约束。

### 生命周期与预览

通过 Hyperf 容器获取 `TaskSchedulerDomainService`，使既有 `Transactional` 切面生效。生成、修改与暂停使用同一连接的外层事务；业务方的任务保存也应参与同一事务。直接手工实例化服务不会自动获得切面事务，不能据此保证行锁或原子性。

- `saveCrontab()` 锁定并比较持久规则。计划/截止/启停变化会取消未认领项，启用时按原规则基准补建未来项；无关内容修改保留生成游标，并批量同步未认领项元数据。
- `pauseByExternalIds()` 单批最多100个业务标识，锁定规则后批量停用、分批取消 Pending/Retry，并批量保存取消日志；Running 不取消。更大范围由业务方在自己的外层事务内有序分批，不在包内承诺多事务原子。
- 恢复使用原规则文本和时间基准，将覆盖游标重置到恢复边界，不再次执行首次周期。业务标识和已确定的非空环境不可借修改规则跨域变更。
- `previewCrontab()` 不修改游标或数据库；`getNextExecutionTime()` 返回开启规则下一时刻，暂停返回 null，不表示业务已经完成。
- 后台新 Cron 的最小间隔约束失败时，生成事务回滚，再通过独立暂停用例停止规则并取消未认领项。规则文本保留供诊断与修正，业务方不能把此状态当作自然完成。

旧清理接口保留原派生执行项清理用途，不用于暂停或恢复。已执行/失败/取消的历史项不批量迁移，取消项不会阻止后续按同一触发点重建。
