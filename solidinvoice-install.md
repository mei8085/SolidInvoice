# SolidInvoice 安装向导代码链路详解

本文档按代码执行顺序，详细解析 SolidInvoice 安装向导的完整链路，包括表单校验、数据库连接与迁移、环境配置写入、缓存清理以及访问拦截规则。

---

## 目录

1. [整体架构概览](#整体架构概览)
2. [访问拦截机制](#访问拦截机制)
3. [表单步骤流转](#表单步骤流转)
4. [数据库配置写入阶段](#数据库配置写入阶段)
5. [安装步骤执行阶段](#安装步骤执行阶段)
6. [环境变量文件改写与缓存清理](#环境变量文件改写与缓存清理)
7. [安装完成标记](#安装完成标记)
8. [升级自动迁移](#升级自动迁移)
9. [关键文件索引](#关键文件索引)

---

## 整体架构概览

SolidInvoice 安装向导采用 **多步骤表单流（Form Flow） + 异步安装步骤（Server-Sent Events）** 的两阶段架构：

**第一阶段：表单收集**
- 5 个表单步骤逐步收集配置信息
- 每步提交都有服务端校验
- 数据存储在 Session 中

**第二阶段：异步执行**
- 进入 install 步骤后，前端通过 SSE 逐个执行安装步骤
- 每个步骤独立执行，支持查看日志和重试

### 核心组件

| 组件 | 位置 | 职责 |
|------|------|------|
| RequestListener | [RequestListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Listener/RequestListener.php) | 请求拦截、未安装重定向 |
| Install Action | [Install.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Action/Install.php) | 安装向导入口、步骤执行 |
| InstallationType | [InstallationType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php) | 表单流定义、步骤配置 |
| InstallFlowType | [InstallFlowType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/FormFlow/InstallFlowType.php) | 数据库配置写入处理 |
| ConfigWriter | [ConfigWriter.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/CoreBundle/ConfigWriter.php) | 环境配置加密写入 |
| 5 个 InstallationStep | [Step/](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/) | 具体安装步骤执行 |

---

## 访问拦截机制

### 拦截入口

**文件**：[RequestListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Listener/RequestListener.php)

**事件**：`KernelEvents::REQUEST`，优先级 `10`

**核心逻辑**（第 82-111 行）：

```php
public function onKernelRequest(RequestEvent $event): void
{
    if (! $event->isMainRequest()) {
        return;
    }

    $route = $request->attributes->get('_route');

    if (! $this->installed) {
        if (null === $route || ! in_array($route, $this->allowRoutes, true)) {
            $this->redirectToRoute($event, self::INSTALLER_ROUTE);
        }
        // ... 临时 APP_SECRET 设置、缓存重置
    }
}
```

### 拦截规则

1. **只拦截主请求**（`isMainRequest()`）
2. **判断依据**：`$this->installed` 参数，来源于环境变量 `SOLIDINVOICE_INSTALLED`
   - 为 `null` 或空字符串 → 未安装
   - 有值（通常是安装日期的 ISO 格式）→ 已安装

3. **白名单路由**（未安装时允许访问）：
   - `_system_install` - 安装向导主路由
   - `ux_live_component` - Symfony UX Live 组件端点
   - 调试模式下额外允许 Web 调试工具栏路由（`_wdt`、`_profiler` 等）

4. **未安装时的额外处理**：
   - 启动 Session
   - 将 Session ID 设为临时的 `SOLIDINVOICE_APP_SECRET`（第 102 行），**同时写入 `$_SERVER` 和 `$_ENV`**
   - 重置环境变量缓存 `$container->resetEnvCache()`（第 107 行）

### 注意点：重定向后代码继续执行

`redirectToRoute()` 方法只设置 Response 并调用 `stopPropagation()`，但**方法内没有 return**，所以重定向之后的代码仍然会执行：

```php
if (null === $route || ! in_array($route, $this->allowRoutes, true)) {
    $this->redirectToRoute($event, self::INSTALLER_ROUTE);
    // 没有 return，下面的代码继续跑
}

// 即使重定向了，以下代码仍会执行
$session = $request->getSession();
if (! $session->isStarted()) {
    $session->start();
}
$_SERVER['SOLIDINVOICE_APP_SECRET'] = $_ENV['SOLIDINVOICE_APP_SECRET'] = $request->getSession()->getId();
// ... resetEnvCache
```

也就是说：**即使请求被重定向到安装页，Session 启动、临时 APP_SECRET 设置、环境缓存重置这三件事依然会发生。**

### 二次拦截（Action 内部）

除了 RequestListener，[Install.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Action/Install.php) 第 61-63 行还有一层防护：

```php
if ($this->installed) {
    throw $this->createNotFoundException();
}
```

已安装状态下直接访问 `/install` 会返回 404。

---

## 表单步骤流转

### 步骤定义

**文件**：[InstallationType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php)

共定义了 **7 个步骤**（第 44-92 行）：

| 步骤名 | 表单类型 | 说明 |
|--------|----------|------|
| `start` | StartStep | 欢迎页 |
| `system_requirements` | SystemRequirementsStep | 系统要求检查 |
| `database_config` | DatabaseConfigStep | 数据库配置 |
| `user_account` | UserAccountStep | 用户账户信息 |
| `review` | ReviewStep | 配置确认 |
| `install` | （无表单） | 安装执行 |
| `finish` | （无表单） | 完成页 |

> **注意**：FrankenPHP 运行时会跳过 `system_requirements` 步骤（第 67 行）。

### 数据存储

表单数据存储在 Session 中，使用 `SessionDataStorage`，键名为 `installation_flow`（第 101 行）。

数据载体是 [Installation DTO](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/DTO/Installation.php)，包含：
- `databaseConfig` - 数据库配置（DatabaseConfig DTO）
- `userAccount` - 用户账户（UserAccount DTO）
- `applicationUrl` - 应用 URL
- `currentStep` - 当前步骤
- `token` - CSRF Token

### 每步提交校验机制

表单流使用 `validation_groups` 动态确定校验组（第 103-111 行）：

```php
'validation_groups' => static function (FormFlowInterface $form) {
    $groups = ['Default', $form->getCursor()->getCurrentStep()];
    
    if (null !== $form->getData()->databaseConfig->driver 
        && $form->getCursor()->getCurrentStep() === 'database_config') {
        $groups[] = 'database_config_' . $form->getData()->databaseConfig->driver;
    }
    
    return $groups;
}
```

#### 步骤 1：start
- 无表单字段，纯展示
- 点击"下一步"进入系统要求检查

#### 步骤 2：system_requirements

**文件**：[SystemRequirementsStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/Step/SystemRequirementsStep.php)

**校验逻辑**（InstallationType 第 56-65 行）：
- 使用 `Callback` 约束
- 调用 `AppRequirements::getFailedRequirements()`
- 存在未通过的要求时添加全局错误

#### 步骤 3：database_config

**文件**：[DatabaseConfigStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/Step/DatabaseConfigStep.php)

**表单字段**：
- `driver` - 数据库驱动（MySQL、MariaDB、PostgreSQL、SQLite）
- `host`、`port`、`user`、`password`、`name` - 动态字段（SQLite 时隐藏）

**校验逻辑**：
1. **驱动选择校验**：`NotBlank`，组 `database_config`
2. **驱动相关字段校验**：根据驱动类型使用不同校验组（`database_config_mysql`、`database_config_mariadb`、`database_config_pgsql`）
3. **连接性校验**：[DatabaseConfig DTO](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php) 第 45-60 行的 `validate` 回调方法

```php
public static function validate(self $data, ExecutionContextInterface $executionContext): void
{
    if (null !== $data->driver && 'sqlite' !== $data->driver 
        && null !== $data->name && null !== $data->host) {
        try {
            $params = (array) $data;
            unset($params['name']);   // ← 关键：测连前 unset 数据库名
            $params['driver'] = Drivers::getDriver($data->driver);
            $params['driverOptions'] = [
                PDO::ATTR_TIMEOUT => 5,
            ];
            DriverManager::getConnection($params)->getNativeConnection();
        } catch (Throwable $e) {
            $executionContext->addViolation($e->getMessage());
        }
    }
}
```

> **为什么测连前必须 unset dbname？**
> 
> 这是整个安装流程时序设计的核心，理解这点才能看清全貌：
> 
> **时序约束**：
> 1. **database_config 表单阶段**（当前）：用户填写数据库信息 → 点击"下一步"
> 2. **CreateDatabaseStep 异步安装阶段**（后续）：真正执行 `CREATE DATABASE` 语句
> 
> **如果不 unset 会发生什么**：
> - `DriverManager::getConnection($params)` 会在建立连接后立即执行 `USE <dbname>`
> - 但此时数据库还没被创建（创建是在后面的 CreateDatabaseStep）
> - 必然返回 `Unknown database '<dbname>'` 错误，用户永远无法通过这一步
> 
> **unset 后的行为**：
> - MySQL：连接到默认的 `mysql` 系统库
> - PostgreSQL：连接到默认的 `postgres` 系统库
> - 只要用户名、密码、主机、端口正确，就能连上
> - 目的是验证"用户有没有权限连接到这台数据库服务器"，而不关心具体数据库
>
> 其他细节：
> - `PDO::ATTR_TIMEOUT => 5`：5 秒连接超时，避免界面长时间卡住
> - `Drivers::getDriver($data->driver)`：将用户选择的 `mysql`/`mariadb` 等映射为 `pdo_mysql` 等真实驱动名

#### 步骤 4：user_account

**文件**：[UserAccountStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/Step/UserAccountStep.php)

**表单字段**：
- `applicationUrl` - 应用 URL（URL 格式校验）
- `locale` - 语言区域
- `firstName`、`lastName` - 姓名
- `emailAddress` - 邮箱
- `password` - 密码

**特殊处理**：
- `applicationUrl` 默认值从当前请求自动推断（第 60-76 行）
- 使用 `POST_SUBMIT` 事件将 `applicationUrl` 同步到根 DTO（第 78-87 行）

#### 步骤 5：review

**文件**：[ReviewStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/Step/ReviewStep.php)

- 使用 `inherit_data: true` 继承所有父级数据
- 只有一个隐藏的 `token` 字段（CSRF Token，用于后续 SSE 请求校验）
- 这一步显示配置摘要，点击"安装"按钮进入下一阶段

---

## 数据库配置写入阶段

当用户在 review 步骤点击"安装"按钮时，触发 `InstallFlowType` 的 handler。

**文件**：[InstallFlowType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/FormFlow/InstallFlowType.php)

### 执行流程（第 47-78 行）

1. **SQLite 特殊处理**（第 51-59 行）
   - 创建 `config/db` 目录
   - 设置数据库文件路径为 `config/db/solidinvoice.db`

2. **数据库连接测试并获取版本**（第 61-70 行）
   - 使用 `DriverManager::getConnection()` 建立连接
   - 获取 `PDO::ATTR_SERVER_VERSION` 服务器版本
   - 这一步会再次验证数据库连接可用性

3. **写入数据库配置**（第 72 行）
   ```php
   $this->systemConfigWriter->save(['database_url' => DatabaseConfig::paramsToDatabaseUrl($dbOptions)]);
   ```
   
   将数据库参数转换为标准的 `DATABASE_URL` 格式后写入配置。

4. **进入下一步**（第 74 行）
   - 成功则调用 `$flow->moveNext()` 进入 `install` 步骤
   - 失败则添加表单错误，停留在当前步骤

### DATABASE_URL 格式转换

**文件**：[Config/DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Config/DatabaseConfig.php)

**MySQL/PostgreSQL 格式**：
```
{driver}://{user}:{password}@{host}:{port}/{name}?serverVersion={version}
```

**SQLite 格式**：
```
sqlite:///{path_to_db_file}
```

---

## 安装步骤执行阶段

进入 `install` 步骤后，页面加载时前端自动通过 Server-Sent Events (SSE) 逐个执行安装步骤。

### 前端触发逻辑

**文件**：[install.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Resources/views/Steps/install.html.twig)

- DOMContentLoaded 时自动调用 `runStep(0)`
- 每个步骤完成后自动执行下一个
- 使用 EventSource 连接 `/_system_install?action={stepId}&token={csrfToken}`

### 后端执行入口

**文件**：[Install.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Action/Install.php) 第 99-137 行

**执行流程**：
1. 校验 CSRF Token（第 104-108 行）
2. action 参数经 title 化后从 ServiceLocator 查找步骤（第 110-116 行）
3. 返回 `EventStreamResponse`，在 Generator 中执行步骤
4. 步骤通过 `yield` 推送进度信息
5. 成功时推送 `complete` 事件，失败时推送 `error` 事件

#### action 参数经 title 化转 ServiceLocator key 的完整链路

前端用蛇形命名发 SSE 请求，后端用 `u()->replace()->title()` 转成 Title Case，再从 ServiceLocator 按 `getLabel()` 索引查找。完整链路分 5 步：

**第 1 步：ServiceLocator 以 getLabel() 为索引**

[Install.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Action/Install.php#L48-L50) 构造函数中：

```php
#[AutowireLocator(
    services: InstallationStepInterface::DI_TAG,        // 标签: solidinvoice.installation_step
    defaultIndexMethod: 'getLabel',                     // 索引方法: getLabel()
    defaultPriorityMethod: 'priority'                   // 排序方法: priority()
)]
private readonly ServiceLocator $steps,
```

- `defaultIndexMethod: 'getLabel'` → ServiceLocator 内部以每个步骤的 `getLabel()` 返回值作为 key
- `defaultPriorityMethod: 'priority'` → 按 `priority()` 返回值排序（数字越大越优先）
- 所有实现了 `InstallationStepInterface` 的类通过 `#[AutoconfigureTag]` 自动注册

**第 2 步：每个步骤的 getLabel() 返回值**

| 步骤类 | getLabel() | priority() |
|--------|-----------|------------|
| GenerateSecretStep | `'Generating secret'` | 30 |
| GenerateBuildIdStep | `'Generating build id'` | 25 |
| CreateDatabaseStep | `'Creating database'` | 20 |
| RunMigrationsStep | `'Creating database schema'` | 10 |
| CreateUserStep | `'Creating admin user'` | 5 |

**第 3 步：前端发送蛇形 action 参数**

前端 SSE 请求 URL 中的 action 是蛇形命名：
```
/_system_install?action=generating_secret&token=xxx
/_system_install?action=creating_database_schema&token=xxx
```

**第 4 步：后端 title 化转换**

[Install.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Action/Install.php#L110) 第 110 行：

```php
$action = u($request->query->get('action'))  // "creating_database_schema"
    ->replace('_', ' ')                      // "creating database schema"
    ->title()                                // "Creating Database Schema"
    ->toString();
```

`u()` 是 Symfony String 组件的辅助函数，返回 `UnicodeString` 对象。`title()` 将每个单词首字母大写。

**第 5 步：从 ServiceLocator 查找并执行**

```php
if (! $this->steps->has($action)) {         // 检查 key 是否存在
    throw new BadRequestException('Invalid action: ' . $action);
}
$step = $this->steps->get($action);          // 按 getLabel() 值取步骤
```

ServiceLocator 的 `has()`/`get()` 对大小写不敏感，因此 `"Creating Database Schema"` 能匹配到 `'Creating database schema'`。

**完整映射表**：

| 前端 action | replace → title 化后 | 匹配 getLabel() | 步骤类 |
|-------------|---------------------|-----------------|--------|
| `generating_secret` | `Generating Secret` | `Generating secret` | GenerateSecretStep |
| `generating_build_id` | `Generating Build Id` | `Generating build id` | GenerateBuildIdStep |
| `creating_database` | `Creating Database` | `Creating database` | CreateDatabaseStep |
| `creating_database_schema` | `Creating Database Schema` | `Creating database schema` | RunMigrationsStep |
| `creating_admin_user` | `Creating Admin User` | `Creating admin user` | CreateUserStep |

### 安装步骤顺序

步骤通过 `priority()` 方法定义优先级，**数字越大优先级越高（越先执行）**。

执行顺序（从先到后）：

| 顺序 | 步骤 | 优先级 | 文件 | 作用 |
|------|------|--------|------|------|
| 1 | GenerateSecretStep | 30 | [GenerateSecretStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/GenerateSecretStep.php) | 生成 APP_SECRET 和密钥对 |
| 2 | GenerateBuildIdStep | 25 | [GenerateBuildIdStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/GenerateBuildIdStep.php) | 生成 BUILD_ID |
| 3 | CreateDatabaseStep | 20 | [CreateDatabaseStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/CreateDatabaseStep.php) | 创建数据库（如不存在） |
| 4 | RunMigrationsStep | 10 | [RunMigrationsStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/RunMigrationsStep.php) | 执行数据库迁移 |
| 5 | CreateUserStep | 5 | [CreateUserStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/CreateUserStep.php) | 创建管理员用户 |

### 各步骤详解

#### 1. GenerateSecretStep

**作用**：生成应用密钥和加密密钥对

**操作**：
- 调用 `$this->vault->generateKeys()` 生成加密密钥对
- 生成随机 `APP_SECRET` 并通过 ConfigWriter 写入

#### 2. GenerateBuildIdStep

**作用**：生成唯一构建 ID

**操作**：
- 生成 UUID v7 作为 `BUILD_ID`
- 通过 ConfigWriter 写入

#### 3. CreateDatabaseStep

**文件**：[CreateDatabaseStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/CreateDatabaseStep.php)

**作用**：创建数据库（如果不存在）

**执行逻辑**（第 31-59 行）：
1. 获取 Doctrine 连接参数
2. 对于非 SQLite：移除 dbname，创建临时连接
3. 对于 SQLite：直接连接（会自动创建文件）
4. 检查数据库是否存在，不存在则创建

> 注意：此步骤使用的是上一阶段写入的 `DATABASE_URL` 配置。

#### 4. RunMigrationsStep

**文件**：[RunMigrationsStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/RunMigrationsStep.php)

此步骤完成两件独立的事：**建表** 和 **记录版本号**。建表委托给 [Migration](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Installer/Database/Migration.php)，版本号更新在本步骤内直接完成。

**完整 execute() 方法**（第 34-46 行）：

```php
public function execute(Installation $installationData, ?callable $callback = null): \Generator
{
    // ── 第一件：建表 + 标记迁移版本 ──
    yield from $this->migration->migrate($callback);

    // ── 第二件：更新 Version 实体 ──
    $version = SolidInvoiceCoreBundle::VERSION;
    $entityManager = $this->registry->getManager();
    /** @var VersionRepository $repository */
    $repository = $entityManager->getRepository(Version::class);
    $repository->updateVersion($version);
}
```

**第一件：Migration::migrate() 做了什么**（[Migration.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Installer/Database/Migration.php#L43-L81) 第 43-81 行）

Migration 只负责建表和标记迁移版本，**不涉及 Version 实体**：

1. `ensureInitialized()` — 初始化迁移元数据表 `doctrine_migration_versions`
2. `SchemaTool::getUpdateSchemaSql()` — 从所有 Entity 元数据计算出建表 SQL
3. 逐条执行 SQL（通过 `yield from $callback($sql)` 回传进度）
4. 批量将所有迁移版本标记为已执行（`$metadataStorage->complete()`）

> 安装时不按迁移文件逐个跑，而是用 SchemaTool 一次性生成完整 schema 再批量标记，更快且避免迁移脚本兼容性问题。

**第二件：Version 实体更新归 RunMigrationsStep 管**

`$repository->updateVersion($version)` 是在 `yield from` **之后**、RunMigrationsStep 自身代码中直接调用的。它不在 Migration::migrate() 里。

- 传入的版本号来自 `SolidInvoiceCoreBundle::VERSION` 常量
- `VersionRepository::updateVersion()` 是 upsert 操作（存在则更新，不存在则插入）
- 写入的 `Version` 实体存放在 `Version` 表中，供 UpgradeListener 判断是否需要自动迁移

> **为什么归 RunMigrationsStep 不归 Migration？** Migration 是纯粹的数据库迁移工具类（建表 + 标记迁移版本），不关心业务语义。Version 实体是业务概念，代表"当前安装的应用版本号"，由调用方（RunMigrationsStep）决定何时更新更合理。

#### 5. CreateUserStep

**文件**：[CreateUserStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/CreateUserStep.php)

**作用**：创建管理员用户

**执行逻辑**（第 33-59 行）：
1. 从 `Installation` DTO 获取用户信息
2. 哈希密码
3. 创建 User 实体并保存
4. 捕获 `UniqueConstraintViolationException`（用户已存在时跳过）

---

## 环境变量文件改写与缓存清理

### ConfigWriter 工作原理

**文件**：[ConfigWriter.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/CoreBundle/ConfigWriter.php)

**核心特点**：
- 不直接写入 `.env` 文件
- 使用 Symfony 的 Secrets Vault（`AbstractVault`）加密存储
- 所有配置键自动添加 `SOLIDINVOICE_` 前缀

### 写入流程（第 43-60 行）

```php
public function save(array $config): void
{
    $this->vault->generateKeys();  // 确保密钥存在

    foreach ($config as $key => $value) {
        if (! str_starts_with($key, self::CONFIG_PREFIX)) {
            $key = self::CONFIG_PREFIX . $key;  // 添加 SOLIDINVOICE_ 前缀
        }
        $this->vault->seal(strtoupper($key), (string) $value);  // 加密写入
    }
}
```

### 存储位置

配置存储在 `SOLIDINVOICE_CONFIG_DIR` 目录中，包括：
- 加密密钥对（公钥/私钥）
- `*.list.php` - 配置列表文件
- 各配置项的加密文件

### 缓存清理机制

#### 1. 安装过程中的缓存重置

**RequestListener**（第 104-109 行）：
```php
if ($container instanceof Container) {
    $container->resetEnvCache();
}
```
每次请求（未安装状态）都重置环境变量缓存，确保配置变更立即生效。

#### 2. 命令行安装的容器重置

**InstallCommand**（第 265-268 行）：
```php
if ($container instanceof ResetInterface) {
    $container->reset();
    $container->set('kernel', $this->kernel);
}
```
保存配置后重置整个容器，使新配置生效。

#### 3. OPcache 失效

**代码位置**：[ConfigWriter.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/CoreBundle/ConfigWriter.php#L43-L59)

`opcache_invalidate()` 调用位于 `foreach` 循环内部，紧接在每次 `seal()` 之后：

```php
public function save(array $config): void
{
    $this->vault->generateKeys();
    $opCacheEnabled = function_exists('opcache_invalidate');

    foreach ($config as $key => $value) {
        if (! str_starts_with($key, self::CONFIG_PREFIX)) {
            $key = self::CONFIG_PREFIX . $key;
        }

        $this->vault->seal(strtoupper($key), (string) $value);    // ← seal() 加密写入

        // ↓ 紧接在 seal 之后、下一次循环之前
        if ($opCacheEnabled && opcache_is_script_cached($this->pathPrefix . 'list.php')) {
            opcache_invalidate($this->pathPrefix . 'list.php', true);
        }
    }
}
```

**为什么每次 seal 后都要失效？** `seal()` 每写入一个配置项，vault 的 `list.php` 文件内容就会变化。如果不清 OPcache，下一次 PHP 请求可能读到旧的列表文件，找不到刚写入的配置项。因此在循环内部、每写一条就失效一次，确保下一次请求一定能读到最新列表。

- 失效的文件是 `{SOLIDINVOICE_CONFIG_DIR}/{env}.{env}.list.php`
- 保存 N 个配置项 → 触发 N 次 `opcache_invalidate()`
- `true` 参数表示强制递归失效

---

## 安装完成标记

### 标记时机

当所有安装步骤执行完毕，用户进入 `finish` 步骤并点击"完成"时，表单提交触发 `isFinished()` 判断。

**文件**：[Install.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Action/Install.php) 第 73-92 行

```php
if ($form->isSubmitted() && $form->isValid() && $form->isFinished()) {
    $formData = $form->getData();
    
    $this->configWriter->save([
        'installed' => date(DateTimeInterface::ATOM),  // 关键：标记已安装
        'locale' => $formData->userAccount->locale,
        'installation_id' => Uuid::v4()->toString(),
        'application_url' => (string) $formData->applicationUrl,
    ]);
    
    $form->reset();
    
    // 自动登录
    $user = $this->userRepository->findOneBy(['email' => $formData->userAccount->emailAddress]);
    if ($user instanceof User) {
        return $this->security->login($user, ...);
    }
    
    return $this->redirectToRoute('_login_main');
}
```

### 写入的配置项

| 配置键 | 说明 |
|--------|------|
| `SOLIDINVOICE_INSTALLED` | 安装日期时间（ISO 格式），是判断是否已安装的核心标志 |
| `SOLIDINVOICE_LOCALE` | 默认语言区域 |
| `SOLIDINVOICE_INSTALLATION_ID` | 安装唯一标识（UUID v4） |
| `SOLIDINVOICE_APPLICATION_URL` | 应用 URL |

---

## 升级自动迁移

### 自动升级机制

**文件**：[UpgradeListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Listener/UpgradeListener.php)

**事件**：`KernelEvents::REQUEST`，优先级 `10`

**触发条件**（第 43-56 行）：
1. 应用已安装（`$this->installed` 有值）
2. 是主请求
3. 数据库迁移不是最新状态（`!$this->migration->isUpToDate()`）

**执行操作**：自动调用 `$this->migration->migrate()` 执行迁移。

> 这意味着：即使在已安装状态下，如果代码更新带来了新的迁移，第一次访问会自动执行数据库迁移。

---

## 命令行安装链路（InstallCommand）

除了 Web 安装向导，SolidInvoice 还提供了完整的命令行安装方式。这是一条独立的执行链路。

**文件**：[InstallCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Command/InstallCommand.php)

**命令名**：`app:install`（执行方式：`bin/console app:install`）

**完整执行链路**：

```
execute() → validate() → saveConfig() → install()
```

### execute() 入口（第 100-124 行）

1. **检查是否已安装**（第 110-114 行）：
   ```php
   if ($this->installed !== null) {
       throw new ApplicationInstalledException();  // 已安装则抛异常
   }
   ```
   异常会被 ExceptionListener 捕获，重定向到首页。

2. **判断是否交互模式**（第 116-122 行）：
   - 没有传任何必需参数 → 进入交互模式，逐个提问
   - 传了必需参数 → 非交互模式，直接执行

3. **调用三个阶段方法**：
   ```php
   $this->validate($input);          // 阶段 1：参数校验
   $this->saveConfig($input);        // 阶段 2：保存配置
   $this->install($input, $output);  // 阶段 3：执行安装
   ```

### 是否启用

通过 `isEnabled()` 方法控制（第 79-82 行），已安装时命令不可用，执行 `list` 时看不到此命令。

---

#### 第一阶段：validate() - 参数校验（第 128-153 行）

校验必填选项：
- `--database-host`、`--database-user`、`--locale`、`--application-url`
- 如果不跳过用户创建（无 `--skip-user`），还需 `--admin-password`、`--admin-email`

校验内容：
1. 必填选项是否都提供了
2. locale 是否合法（检查 `Locale::exists()`）
3. application-url 是否包含 http/https 协议且格式有效

---

#### 第二阶段：saveConfig() - 保存配置（第 229-271 行）

**完整流程**：

```php
private function saveConfig(InputInterface $input): self
{
    $config = [
        'database_driver' => $input->getOption('database-driver'),
        'database_host' => $input->getOption('database-host'),
        'database_port' => $input->getOption('database-port'),
        'database_name' => $input->getOption('database-name'),
        'database_user' => $input->getOption('database-user'),
        'database_password' => $input->getOption('database-password'),
        'locale' => $input->getOption('locale'),
        'application_url' => $input->getOption('application-url'),
        'app_secret' => Key::createNewRandomKey()->saveToAsciiSafeString(),  // ← defuse 生成
    ];

    // 测试数据库连接
    $nativeConnection = DriverManager::getConnection([
        'host' => $config['database_host'] ?? null,
        'port' => $config['database_port'] ?? null,
        'name' => $config['database_name'] ?? null,  // ← 注意：没有 unset dbname！
        'user' => $config['database_user'] ?? null,
        'password' => $config['database_password'] ?? null,
        'driver' => $config['database_driver'] ?? null,
    ])->getNativeConnection();

    // 获取数据库版本
    $version = $nativeConnection->getAttribute(PDO::ATTR_SERVER_VERSION);
    $config['database_version'] = $version;

    // 批量保存所有配置
    $this->configWriter->save($config);

    // 重置容器
    $container = $this->kernel->getContainer();
    if ($container instanceof ResetInterface) {
        $container->reset();
        $container->set('kernel', $this->kernel);
    }
}
```

**关键细节**：
1. **APP_SECRET 由 defuse 生成**（第 241 行）：和 Web 向导一致，使用 `Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString()`
2. **没有 unset dbname**：命令行安装的连接测试**带 dbname** 连接，这意味着如果数据库不存在，这一步会失败。命令行安装要求数据库已提前创建好（和 Web 向导设计不同）
3. **配置键名自动加前缀**：传入的是 `app_secret`、`database_driver` 等，ConfigWriter 会自动转成 `SOLIDINVOICE_APP_SECRET`、`SOLIDINVOICE_DATABASE_DRIVER`
4. **批量写入 + 容器重置**：一次性批量写入所有配置，然后重置整个容器使新配置生效

> **与 Web 向导的区别**：
> - Web 向导分两阶段写：先在 InstallFlowType 中写数据库配置，最后在 Install Action 中写安装标记
> - 命令行是一次性批量写入所有配置

---

#### 第三阶段：install() - 执行安装（第 158-180 行）

**完整代码**：

```php
private function install(InputInterface $input, OutputInterface $output): void
{
    // 1. 遍历执行所有安装步骤（共 5 个）
    foreach ($this->installationSteps as $step) {
        $output->writeln(sprintf('<info>Running step: %s</info>', $step->getLabel()));
        $step->execute(new Installation(), function (string $content) use ($output): void {
            $output->writeln($content, OutputInterface::VERBOSITY_VERBOSE);
        });
    }
    // ↑ RunMigrationsStep 内部已经调用了 $repository->updateVersion($version)

    // 2. 创建管理员用户（如果不跳过）
    if (! $input->getOption('skip-user')) {
        $this->createAdminUser($input, $output);
    }

    // 3. 再次更新 Version（冗余操作）
    $version = SolidInvoiceCoreBundle::VERSION;
    $entityManager = $this->registry->getManager();
    /** @var VersionRepository $repository */
    $repository = $entityManager->getRepository(Version::class);
    $repository->updateVersion($version);  // ← RunMigrationsStep 已经更新过一次

    // 4. 最后写入 installed 标记
    $time = new DateTime('NOW');
    $config = ['installed' => $time->format(DateTimeInterface::ATOM)];
    $this->configWriter->save($config);
}
```

**关键细节**：
1. **Version 被更新两次**：
   - 第一次：在 `RunMigrationsStep::execute()` 内（第 428 行）
   - 第二次：在 `InstallCommand::install()` 内（第 176 行）
   - 这是冗余设计，但不影响功能（`updateVersion()` 内部是 upsert 操作）

2. **installed 标记最后写入**：确保前面所有步骤都成功后才标记为已安装

> **与 Web 向导的区别**：
> - Web 向导中 Version 只由 RunMigrationsStep 更新一次
> - Web 向导中 installed 标记由 Install Action 在 finish 步骤写入
> - 命令行都在 `install()` 方法内完成

---

### 交互模式

如果命令选项未提供参数，会进入交互模式（`interact()` 方法），逐个提问：
- 数据库类型（从可用 PDO 驱动中选择，SQLite 被排除）
- 数据库主机、端口、库名、用户名、密码
- locale
- application URL
- 管理员邮箱和密码

### IsInstalledCommand

**文件**：[IsInstalledCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Command/IsInstalledCommand.php)

**命令名**：`solidinvoice:is-installed`（隐藏命令）

**用途**：脚本中判断应用是否已安装。已安装退出码 0，未安装退出码 1。常用于 CI/CD 或容器启动脚本中。

**核心逻辑**（第 36-42 行）：
```php
protected function execute(InputInterface $input, OutputInterface $output): int
{
    if ($this->installed !== null) {
        return Command::SUCCESS;    // 已安装 → 退出码 0
    }
    return Command::FAILURE;        // 未安装 → 退出码 1
}
```

---

## 关键文件索引

### 核心流程
- 路由定义：[routing.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Resources/config/routing.php)
- 入口 Action：[Install.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Action/Install.php)
- 请求拦截：[RequestListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Listener/RequestListener.php)
- 异常处理：[ExceptionListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Listener/ExceptionListener.php)
- 自动升级：[UpgradeListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Listener/UpgradeListener.php)

### 表单相关
- 表单流定义：[InstallationType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php)
- 安装按钮处理：[InstallFlowType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/FormFlow/InstallFlowType.php)
- 导航按钮：[InstallNavigatorType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/FormFlow/InstallNavigatorType.php)
- 步骤表单：[Step/](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Form/Step/)

### DTO
- 安装数据：[Installation.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/DTO/Installation.php)
- 数据库配置：[DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php)
- 用户账户：[UserAccount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/DTO/UserAccount.php)

### 安装步骤
- 步骤接口：[InstallationStepInterface.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/InstallationStepInterface.php)
- 创建数据库：[CreateDatabaseStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/CreateDatabaseStep.php)
- 运行迁移：[RunMigrationsStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/RunMigrationsStep.php)
- 创建用户：[CreateUserStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/CreateUserStep.php)
- 生成密钥：[GenerateSecretStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/GenerateSecretStep.php)
- 生成构建 ID：[GenerateBuildIdStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Step/GenerateBuildIdStep.php)

### 配置与数据库
- 配置写入器：[ConfigWriter.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/CoreBundle/ConfigWriter.php)
- 数据库 URL 构建：[Config/DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Config/DatabaseConfig.php)
- 迁移执行器：[Migration.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Installer/Database/Migration.php)
- 驱动列表：[Drivers.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Doctrine/Drivers.php)

### 前端模板
- 主模板：[install.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Resources/views/install.html.twig)
- 安装步骤模板：[Steps/install.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Resources/views/Steps/install.html.twig)
- Live 组件：[SystemInstallation.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Twig/Components/SystemInstallation.php)
- 组件模板：[Components/SystemInstallation.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Resources/views/Components/SystemInstallation.html.twig)

### 命令行安装
- 安装命令：[InstallCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Command/InstallCommand.php)
- 检查命令：[IsInstalledCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Command/IsInstalledCommand.php)

### 服务配置
- 服务定义：[services.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Resources/config/services/services.php)

### 测试辅助
- 安装确保特性：[EnsureApplicationInstalled.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Test/EnsureApplicationInstalled.php)
- 已安装异常：[ApplicationInstalledException.php](file:///d:/fz/0601-1/solo-dogfeeding/code/96-SolidInvoice/src/InstallBundle/Exception/ApplicationInstalledException.php)
