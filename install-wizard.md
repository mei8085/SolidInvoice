# SolidInvoice 安装向导代码实现深度解析

本文从代码实现角度梳理 SolidInvoice 安装向导的完整流程，包括步骤控制器、环境配置读写、状态判断与锁定机制。

## 目录

- [整体架构概览](#整体架构概览)
- [安装状态判断与重定向](#安装状态判断与重定向)
- [表单步骤流程 (Form Flow)](#表单步骤流程-form-flow)
- [安装执行步骤 (Installation Steps)](#安装执行步骤-installation-steps)
- [环境配置读写助手](#环境配置读写助手)
- [数据库配置处理](#数据库配置处理)
- [应用密钥生成与机密保管](#应用密钥生成与机密保管)
- [前端交互与 SSE 流式输出](#前端交互与-sse-流式输出)
- [命令行安装入口](#命令行安装入口)

---

## 整体架构概览

SolidInvoice 的安装向导位于 `src/InstallBundle/` 目录，采用 **Symfony Form Flow** 多步表单 + **服务定位器** 执行步骤的架构模式。

```
InstallBundle/
├── Action/Install.php              # 主控制器（Web 入口）
├── Command/InstallCommand.php      # 命令行安装入口
├── Listener/RequestListener.php    # 请求拦截器（安装状态判断）
├── Form/
│   ├── Type/InstallationType.php   # 表单流程定义（7个步骤）
│   ├── FormFlow/                   # 表单流程处理
│   └── Step/                       # 各步骤表单类型
├── Step/                           # 安装执行步骤（6个）
├── Config/DatabaseConfig.php       # 数据库 URL 构建器
├── DTO/                            # 数据传输对象
└── Twig/Components/                # 前端 Live 组件
```

---

## 安装状态判断与重定向

### 请求监听器

核心入口：[RequestListener.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Listener/RequestListener.php)

`RequestListener` 是一个 `KernelEvents::REQUEST` 事件订阅者，优先级为 10，在每个请求到达时判断应用是否已安装。

#### 状态判断逻辑

```php
// 构造函数注入 $installed 参数，来自环境变量 SOLIDINVOICE_INSTALLED
public function __construct(
    private readonly RouterInterface $router,
    private readonly ContainerInterface $locator,
    private readonly ?string $installed,  // 核心：安装状态标记
    private readonly bool $debug = false
) { ... }
```

**判断逻辑：**

1. **未安装状态** (`$installed` 为 null 或空)：
   - 所有非白名单路由都重定向到 `_system_install` 安装页面
   - 白名单路由包括：安装页面、UX Live 组件、调试模式下的 Profiler 等
   - 启动 Session，并将 **Session ID 临时作为 App Secret**（见下文）

2. **已安装状态** (`$installed` 有值)：
   - 如果访问安装路由，重定向到首页 `_home`
   - 正常访问其他路由

#### 临时应用密钥机制

在安装完成前，系统没有正式的 `APP_SECRET`，这是 Symfony 框架必需的配置。SolidInvoice 采用了一个巧妙的临时方案：

```php
// RequestListener.php L102
$_SERVER['SOLIDINVOICE_APP_SECRET'] = $_ENV['SOLIDINVOICE_APP_SECRET'] = $request->getSession()->getId();

if ($this->locator->has(ContainerInterface::class)) {
    $container = $this->locator->get(ContainerInterface::class);
    if ($container instanceof Container) {
        $container->resetEnvCache();  // 重置环境变量缓存
    }
}
```

**关键点：**
- 用 Session ID 作为临时的 `APP_SECRET`
- 同时设置 `$_SERVER` 和 `$_ENV` 全局变量
- 调用 `resetEnvCache()` 确保容器重新读取环境变量
- 安装过程中生成正式密钥后会替换掉这个临时值

---

## 表单步骤流程 (Form Flow)

### 流程定义

核心文件：[InstallationType.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php)

安装向导共定义 **7 个步骤**，使用 Symfony Form Flow 组件管理多步表单状态：

| 步骤名称 | 表单类型 | 说明 |
|---------|---------|------|
| `start` | StartStep | 欢迎页（无表单字段） |
| `system_requirements` | SystemRequirementsStep | 系统要求检查（FrankenPHP 环境下跳过） |
| `database_config` | DatabaseConfigStep | 数据库配置 |
| `user_account` | UserAccountStep | 用户账户与应用 URL |
| `review` | ReviewStep | 确认信息 + CSRF Token |
| `install` | （无） | 安装执行进度页 |
| `finish` | （无） | 安装完成页 |

#### 步骤构建代码

```php
// InstallationType.php L44-L94
$builder->addStep('start', StartStep::class, ['mapped' => false]);
$builder->addStep('system_requirements', SystemRequirementsStep::class, [
    'mapped' => false,
    'skip' => fn () => 'frankenphp' === $this->runtime,  // FrankenPHP 跳过
]);
$builder->addStep('database_config', DatabaseConfigStep::class, [
    'property_path' => 'databaseConfig',
]);
$builder->addStep('user_account', UserAccountStep::class, [
    'property_path' => 'userAccount',
]);
$builder->addStep('review', ReviewStep::class, ['inherit_data' => true]);
$builder->addStep('install', options: ['inherit_data' => true]);
$builder->addStep('finish', options: ['mapped' => false]);
```

#### 数据存储

表单数据使用 Session 存储：

```php
'data_storage' => new SessionDataStorage('installation_flow', $this->requestStack),
'step_property_path' => 'currentStep',  // 当前步骤存储在 Installation::currentStep
```

#### 验证组机制

每个步骤有对应的验证组，实现分步验证：

```php
'validation_groups' => static function (FormFlowInterface $form) {
    $groups = ['Default', $form->getCursor()->getCurrentStep()];
    
    // 数据库配置步骤根据驱动类型添加额外验证组
    if (null !== $form->getData()->databaseConfig->driver 
        && $form->getCursor()->getCurrentStep() === 'database_config') {
        $groups[] = 'database_config_' . $form->getData()->databaseConfig->driver;
    }
    
    return $groups;
},
```

### 数据库配置步骤

文件：[DatabaseConfigStep.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Form/Step/DatabaseConfigStep.php)

使用 **Symfonycasts DynamicForms** 实现动态表单：
- 选择 `sqlite` 驱动时，隐藏主机、端口、用户、密码等字段
- 选择 MySQL/PostgreSQL 时，显示完整连接信息

数据库配置还包含 **实时连接验证**，在 DTO 的 `validate` 回调中测试连接：

```php
// DTO/DatabaseConfig.php L47-L62
public static function validate(self $data, ExecutionContextInterface $executionContext): void
{
    if (null !== $data->driver && 'sqlite' !== $data->driver 
        && null !== $data->name && null !== $data->host) {
        try {
            $params = (array) $data;
            unset($params['name']);
            $params['driver'] = Drivers::getDriver($data->driver);
            $params['driverOptions'] = [PDO::ATTR_TIMEOUT => 5];
            DriverManager::getConnection($params)->getNativeConnection();  // 测试连接
        } catch (Throwable $e) {
            $executionContext->addViolation($e->getMessage());
        }
    }
}
```

### 数据库配置提交处理

文件：[InstallFlowType.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Form/FormFlow/InstallFlowType.php)

当用户在数据库配置步骤点击"下一步"时，表单流程处理器会：

1. **SQLite 特殊处理**：自动创建数据库目录和文件路径
2. **连接验证**：使用 Doctrine DriverManager 测试连接
3. **获取版本**：获取数据库服务器版本
4. **保存配置**：将数据库 URL 写入机密保管库

```php
// InstallFlowType.php L48-L78
'handler' => function (mixed $data, ButtonFlowInterface $button, FormFlowInterface $flow): void {
    $formData = $flow->getData();
    
    if ($formData->databaseConfig->driver === 'sqlite') {
        new Filesystem()->mkdir($this->configDir . '/db');
        $formData->databaseConfig->name = $this->configDir . '/db/solidinvoice.db';
    }
    
    try {
        $dbOptions = (array) $formData->databaseConfig;
        unset($dbOptions['name']);
        
        $nativeConnection = DriverManager::getConnection(
            ['driver' => Drivers::getDriver($dbOptions['driver'])] + $dbOptions
        )->getNativeConnection();
        
        $dbOptions['version'] = $nativeConnection->getAttribute(PDO::ATTR_SERVER_VERSION);
        $dbOptions['name'] = $formData->databaseConfig->name;
        
        // 保存到配置
        $this->systemConfigWriter->save([
            'database_url' => DatabaseConfig::paramsToDatabaseUrl($dbOptions)
        ]);
        
        $flow->moveNext();  // 进入下一步
    } catch (Throwable $e) {
        $flow->addError(new FormError($e->getMessage()));
    }
},
```

---

## 安装执行步骤 (Installation Steps)

### 步骤接口

文件：[InstallationStepInterface.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Step/InstallationStepInterface.php)

所有安装步骤都实现此接口，通过 **服务标签** `solidinvoice.installation_step` 自动注册：

```php
#[AutoconfigureTag(InstallationStepInterface::DI_TAG)]
interface InstallationStepInterface
{
    public const string DI_TAG = 'solidinvoice.installation_step';
    
    public static function priority(): int;           // 执行优先级
    public function execute(Installation $installationData, ?callable $callback = null): Generator;
    public static function getLabel(): string;        // 步骤显示名称
}
```

### 步骤列表与执行顺序

按优先级从高到低排列（数字越大越先执行）：

| 优先级 | 步骤类 | 标签 | 功能 |
|-------|--------|------|------|
| 30 | GenerateSecretStep | Generating secret | 生成应用密钥 |
| 25 | GenerateBuildIdStep | Generating build id | 生成构建 ID |
| 20 | CreateDatabaseStep | Creating database | 创建数据库 |
| 10 | RunMigrationsStep | Creating database schema | 执行数据库迁移 |
| 5 | CreateUserStep | Creating admin user | 创建管理员用户 |

**服务定位器注入：**

```php
// 使用 AutowireLocator 自动收集所有步骤服务
#[AutowireLocator(
    services: InstallationStepInterface::DI_TAG, 
    defaultIndexMethod: 'getLabel',    // 用 getLabel() 作为服务键
    defaultPriorityMethod: 'priority'  // 用 priority() 排序
)]
private readonly ServiceLocator $steps,
```

### 各步骤详细分析

#### 1. GenerateSecretStep - 生成应用密钥

文件：[GenerateSecretStep.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Step/GenerateSecretStep.php)

```php
public function execute(Installation $installationData, ?callable $callback = null): Generator
{
    $this->vault->generateKeys();  // 生成机密保管库密钥对
    $this->configWriter->save([
        'APP_SECRET' => Key::createNewRandomKey()->saveToAsciiSafeString(),
    ]);
    
    if ($callback !== null) {
        yield from $callback(str_replace('; you can commit it', '', $this->vault->getLastMessage()));
    }
}
```

**关键点：**
- 使用 Symfony Secrets Vault (`AbstractVault`) 生成加密密钥对
- 使用 `defuse/php-encryption` 库生成随机密钥作为 `APP_SECRET`
- 通过 ConfigWriter 保存到机密保管库

#### 2. GenerateBuildIdStep - 生成构建 ID

文件：[GenerateBuildIdStep.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Step/GenerateBuildIdStep.php)

```php
public function execute(Installation $installationData, ?callable $callback = null): Generator
{
    $this->configWriter->save(['BUILD_ID' => (string) Uuid::v7()]);
    
    if ($callback !== null) {
        yield from $callback('Build ID generated');
    }
}
```

生成 UUID v7 作为构建 ID，用于版本追踪和缓存失效。

#### 3. CreateDatabaseStep - 创建数据库

文件：[CreateDatabaseStep.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Step/CreateDatabaseStep.php)

```php
public function execute(Installation $installationData, ?callable $callback = null): Generator
{
    $connection = $this->doctrine->getConnection();
    $params = $connection->getParams();
    
    $dbName = '';
    
    if ($params['driver'] !== 'pdo_sqlite') {
        $dbName = $params['dbname'];
        unset($params['dbname']);
    }
    
    // 不指定数据库名建立临时连接
    $tmpConnection = DriverManager::getConnection($params, $connection->getConfiguration());
    
    if ($params['driver'] === 'pdo_sqlite') {
        $tmpConnection->connect();  // SQLite 只需连接即创建文件
        $tmpConnection->close();
    } else {
        $schemaManager = $tmpConnection->createSchemaManager();
        if (! in_array($dbName, $schemaManager->listDatabases(), true)) {
            $schemaManager->createDatabase($dbName);  // 新建数据库
        }
    }
    
    yield from $callback(sprintf('Database %s created', $dbName));
}
```

**关键点：**
- 先移除 dbname 参数，建立不指定数据库的连接
- SQLite 通过连接自动创建文件
- MySQL/PostgreSQL 通过 SchemaManager 检查并创建数据库

#### 4. RunMigrationsStep - 执行数据库迁移

文件：[RunMigrationsStep.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Step/RunMigrationsStep.php)

```php
public function execute(Installation $installationData, ?callable $callback = null): Generator
{
    yield from $this->migration->migrate($callback);  // 执行迁移
    
    // 记录版本号
    $version = SolidInvoiceCoreBundle::VERSION;
    $entityManager = $this->registry->getManager();
    $repository = $entityManager->getRepository(Version::class);
    $repository->updateVersion($version);
}
```

实际迁移逻辑在 [Migration.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Installer/Database/Migration.php) 中：

```php
public function migrate(?callable $callback = null): Generator
{
    $metadataStorage->ensureInitialized();
    
    // 使用 SchemaTool 直接更新 schema（首次安装快速路径）
    $tables = $em->getMetadataFactory()->getAllMetadata();
    $schemaTool = new SchemaTool($em);
    $updateSchemaSql = $schemaTool->getUpdateSchemaSql($tables, true);
    
    if (count($updateSchemaSql) > 0) {
        foreach ($updateSchemaSql as $sql) {
            $conn->executeStatement($sql);
            yield from $callback($sql);  // 通过生成器逐行输出进度
        }
    }
    
    // 标记所有迁移为已执行
    foreach ($plan->getItems() as $item) {
        $metadataStorage->complete(new ExecutionResult($item->getVersion(), $item->getDirection(), $now));
    }
}
```

**策略：** 首次安装时直接用 SchemaTool 创建完整 schema，然后标记所有迁移为已执行，比逐个执行迁移更快。

#### 5. CreateUserStep - 创建管理员用户

文件：[CreateUserStep.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Step/CreateUserStep.php)

```php
public function execute(Installation $installationData, ?callable $callback = null): Generator
{
    $user = new User();
    $encoder = $this->passwordHasherFactory->getPasswordHasher($user);
    $password = $encoder->hash($installationData->userAccount->password);
    
    $user->setEmail($installationData->userAccount->emailAddress)
        ->setFirstName($installationData->userAccount->firstName)
        ->setLastName($installationData->userAccount->lastName)
        ->setPassword($password)
        ->setVerified(true)
        ->setEnabled(true);
    
    try {
        $this->userRepository->save($user);
        yield from $callback('Admin user created');
    } catch (UniqueConstraintViolationException) {
        yield from $callback('Admin user already exists, skipping creation');
    }
}
```

---

## 环境配置读写助手

### ConfigWriter 配置写入器

文件：[ConfigWriter.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/CoreBundle/ConfigWriter.php)

`ConfigWriter` 是 SolidInvoice 核心包提供的配置写入工具，封装了 Symfony Secrets Vault 的操作。

```php
readonly class ConfigWriter
{
    public const string CONFIG_PREFIX = 'SOLIDINVOICE_';
    
    private string $pathPrefix;
    
    public function __construct(
        private AbstractVault $vault,
        #[Autowire(env: 'SOLIDINVOICE_CONFIG_DIR')]
        string $secretsDir,
    ) {
        $this->pathPrefix = rtrim(..., DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($secretsDir) . '.';
    }
    
    public function save(array $config): void
    {
        $this->vault->generateKeys();  // 确保密钥对存在
        
        $opCacheEnabled = function_exists('opcache_invalidate');
        
        foreach ($config as $key => $value) {
            if (! str_starts_with($key, self::CONFIG_PREFIX)) {
                $key = self::CONFIG_PREFIX . $key;  // 自动添加前缀
            }
            
            $this->vault->seal(strtoupper($key), (string) $value);  // 加密存储
            
            // 清除 OPcache 确保下次读取生效
            if ($opCacheEnabled && opcache_is_script_cached($this->pathPrefix . 'list.php')) {
                opcache_invalidate($this->pathPrefix . 'list.php', true);
            }
        }
    }
}
```

**核心特性：**
1. **自动前缀**：所有配置键自动添加 `SOLIDINVOICE_` 前缀
2. **加密存储**：使用 Symfony Secrets Vault (defuse/php-encryption) 加密保存
3. **OPCache 处理**：保存后自动失效 OPcache，确保后续请求读取到新值
4. **密钥自动生成**：首次使用时自动生成加密密钥对

### 配置存储位置

配置存储在 `SOLIDINVOICE_CONFIG_DIR` 环境变量指定的目录，通常为 `config/secrets/`。

文件结构（Symfony Secrets Vault 标准格式）：
```
config/secrets/
├── dev/
│   ├── dev.decrypt.private.php    // 私钥（开发环境可提交）
│   ├── dev.list.php               // 机密列表（加密的键名清单）
│   └── dev.SOLIDINVOICE_*.php     // 各加密配置项
├── prod/
│   ├── prod.decrypt.private.php   // 私钥（生产环境不提交）
│   ├── prod.list.php
│   └── prod.SOLIDINVOICE_*.php
└── .env                           // 环境变量文件
```

---

## 数据库配置处理

### DatabaseConfig DTO

文件：[DatabaseConfig.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php)

数据传输对象，封装数据库连接参数，包含验证逻辑。

### 数据库 URL 构建器

文件：[Config/DatabaseConfig.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Config/DatabaseConfig.php)

`paramsToDatabaseUrl()` 方法将数组参数转换为 Doctrine 数据库 URL 格式：

```php
public static function paramsToDatabaseUrl(array $params): string
{
    // SQLite 格式: sqlite:///path/to/db.sqlite
    if ($params['driver'] === 'sqlite') {
        return sprintf('%s:///%s', $params['driver'], $params['name']);
    }
    
    // 其他数据库格式: driver://user:pass@host:port/dbname?serverVersion=x.y
    return sprintf(
        '%s://%s%s%s%s%s/%s?serverVersion=%s',
        $params['driver'],
        $params['user'] ?? '',
        ($params['password'] ?? '') !== '' ? ':' . $params['password'] : '',
        ($params['user'] ?? '') !== '' ? '@' : '',
        $params['host'],
        (string) ($params['port'] ?? '') !== '' ? ':' . $params['port'] : '',
        $params['name'],
        $params['version'] ?? ''
    );
}
```

### 驱动映射

文件：[Drivers.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Doctrine/Drivers.php)

提供驱动名称到 PDO 驱动名称的映射，以及表单选项列表。

---

## 应用密钥生成与机密保管

### 密钥生成流程

1. **临时阶段**（安装前）：Session ID 作为临时密钥
   - [RequestListener.php L102](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Listener/RequestListener.php#L102-L102)

2. **正式生成**（安装步骤）：
   - 调用 `$vault->generateKeys()` 生成加密密钥对
   - 使用 `Key::createNewRandomKey()` 生成随机密钥
   - 保存为 `SOLIDINVOICE_APP_SECRET`

3. **配置锁定**（安装完成）：
   - 写入 `SOLIDINVOICE_INSTALLED` 标记
   - 后续请求检测到此标记则不再进入安装流程

### Symfony Secrets Vault 机制

SolidInvoice 使用 Symfony 的机密保管系统存储敏感配置：

- **加密密钥对**：公钥用于加密（seal），私钥用于解密（reveal）
- **列表文件**：`*.list.php` 记录所有机密名称（加密存储）
- **机密文件**：每个配置项单独加密存储
- **环境变量集成**：机密值通过 `$_ENV` 和 `$_SERVER` 自动注入

### 安装完成标记

安装完成后，主控制器写入最终配置：

```php
// Install.php L78-L83
$this->configWriter->save([
    'installed' => date(DateTimeInterface::ATOM),  // 安装时间
    'locale' => $formData->userAccount->locale,    // 默认语言
    'installation_id' => Uuid::v4()->toString(),   // 安装 ID
    'application_url' => (string) $formData->applicationUrl,  // 应用 URL
]);
```

这些配置写入后，`SOLIDINVOICE_INSTALLED` 环境变量就有值了，`RequestListener` 检测到后就认为应用已安装，锁定安装向导入口。

---

## 前端交互与 SSE 流式输出

### 主控制器执行逻辑

文件：[Install.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Action/Install.php)

#### 安装步骤执行接口

当 URL 包含 `action` 和 `token` 参数时，触发单个安装步骤的执行：

```php
// Install.php L70-L72
if ($request->query->has('action') && $request->query->has('token')) {
    return $this->handleInstallationStep($form, $request);
}
```

#### SSE 流式响应

使用 Symfony `EventStreamResponse` 实现 Server-Sent Events，实时推送安装进度：

```php
// Install.php L119-L137
return new EventStreamResponse(function () use ($data, $step): Generator {
    try {
        yield from $step->execute($data, function (string $content): Generator {
            yield new ServerEvent($content);  // 每个进度消息作为一个 SSE 事件
        });
        
        yield new ServerEvent(json_encode(['status' => 'success'], JSON_THROW_ON_ERROR), 'complete');
    } catch (Throwable $e) {
        yield new ServerEvent(
            json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], JSON_THROW_ON_ERROR),
            'error'
        );
    }
});
```

**事件类型：**
- `message`（默认）：普通进度消息
- `complete`：步骤完成，附带状态 JSON
- `error`：步骤失败，附带错误信息

#### CSRF 保护

每个步骤执行都需要验证 CSRF Token：

```php
// Install.php L105-L109
if (! $this->csrfTokenManager->isTokenValid(
    new CsrfToken('system_installation', $request->query->get('token')),
)) {
    throw new BadRequestHttpException();
}
```

### 前端 Live 组件

文件：[SystemInstallation.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Twig/Components/SystemInstallation.php)

使用 Symfony UX Live Component 实现交互式安装向导，支持无刷新步骤切换。

### 前端 JavaScript 逻辑

模板：[install.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Resources/views/Steps/install.html.twig)

安装执行页使用 EventSource 连接服务器，按顺序执行所有步骤：

1. 页面加载后自动开始第一步
2. 每个步骤通过 SSE 接收实时进度
3. 步骤完成后（收到 `complete` 事件）自动进入下一步
4. 全部完成后激活"继续"按钮
5. 失败时显示错误信息和重试按钮

---

## 命令行安装入口

文件：[InstallCommand.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Command/InstallCommand.php)

除了 Web 安装向导，SolidInvoice 还提供了命令行安装方式：

```bash
bin/console app:install --database-host=localhost --database-user=root ...
```

### 命令执行流程

```
execute()
├── validate()         // 验证必填参数
├── saveConfig()       // 保存数据库配置到机密保管库
│   ├── 测试数据库连接
│   ├── 获取数据库版本
│   └── 生成 APP_SECRET
└── install()          // 执行安装
    ├── 遍历执行所有 InstallationStep
    ├── 创建管理员用户
    ├── 更新版本记录
    └── 写入 installed 标记
```

### 交互模式

如果运行命令时没有提供参数，会进入交互模式，逐个询问配置信息：

```php
// InstallCommand.php L280-L331
protected function interact(InputInterface $input, OutputInterface $output): void
{
    // 使用 QuestionHelper 交互式获取配置
    $options = [
        'database-driver' => new ChoiceQuestion(...),
        'database-host' => new Question(...),
        // ...
    ];
}
```

---

## 数据传输对象 (DTO)

### Installation - 安装主数据

文件：[Installation.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/DTO/Installation.php)

```php
final class Installation
{
    public function __construct(
        #[Valid(groups: [...])]
        public DatabaseConfig $databaseConfig = new DatabaseConfig(),
        #[Valid(groups: ['user_account'])]
        public UserAccount $userAccount = new UserAccount(),
        public ?string $applicationUrl = null,
        public string $currentStep = 'start',  // 当前步骤
        public ?string $token = '',             // CSRF Token
    ) {
    }
}
```

### UserAccount - 用户账户

文件：[UserAccount.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/DTO/UserAccount.php)

包含：locale、firstName、lastName、emailAddress、password，各字段都有对应的验证约束。

---

## 关键设计模式总结

### 1. 服务定位器模式 (Service Locator)
安装步骤通过 `ServiceLocator` 动态加载，支持灵活的优先级排序和标签自动发现。

### 2. 生成器模式 (Generator)
安装步骤的 `execute()` 方法返回 `Generator`，通过 `yield` 逐行输出进度信息，天然适配 SSE 流式响应。

### 3. 表单流程模式 (Form Flow)
使用 Symfony Form Flow 管理多步表单状态，内置验证组、步骤跳转、数据持久化等能力。

### 4. 机密保管模式 (Secrets Vault)
所有敏感配置通过 Symfony Secrets Vault 加密存储，配置与代码分离，支持不同环境独立密钥。

### 5. 请求拦截模式 (Request Listener)
通过事件订阅器在请求入口判断安装状态，自动重定向到安装向导或首页。

---

## 配置锁定流程总结

整个安装过程的配置写入时序：

```
1. 请求到达
   └── RequestListener 检测未安装 → 重定向到 /install

2. 数据库配置步骤提交
   └── InstallFlowType handler → ConfigWriter::save() 
       → 写入 SOLIDINVOICE_DATABASE_URL

3. 点击"安装"按钮 → 前端 JS 逐个调用步骤接口
   ├── GenerateSecretStep → 生成 APP_SECRET + Vault 密钥
   ├── GenerateBuildIdStep → 生成 BUILD_ID
   ├── CreateDatabaseStep → 创建数据库
   ├── RunMigrationsStep → 执行数据库迁移
   └── CreateUserStep → 创建管理员用户

4. 所有步骤完成 → 前端点击 Finish
   └── Install 控制器最终保存
       → 写入 SOLIDINVOICE_INSTALLED (安装时间)
       → 写入 SOLIDINVOICE_LOCALE
       → 写入 SOLIDINVOICE_INSTALLATION_ID
       → 写入 SOLIDINVOICE_APPLICATION_URL

5. 后续请求
   └── RequestListener 检测到 installed → 正常访问
```

配置一旦通过 `ConfigWriter` 写入 Symfony Secrets Vault，就处于加密锁定状态，只能通过 Vault 解密读取，不能直接明文修改，确保了配置的安全性。

---

## 补充：邮件投递配置的完整链路

SolidInvoice 的邮件配置与数据库配置不同——**它不在安装向导中直接配置**，而是在安装完成后的系统设置中设置。本节完整梳理邮件环境变量的读入、配置写出、运行时生效的完整链路，以及 `email_settings` 翻译键存在却未挂上安装向导的事实。

### 邮件相关环境变量的框架读入入口

邮件配置在框架层面有两个环境变量被直接读取：

#### `SOLIDINVOICE_MAILER_DSN` 和 `SOLIDINVOICE_MAILER_SENDER`

**读入入口一：容器参数默认值定义**

文件：[config/services.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/config/services.php#L46-L47)

```php
$parameters->set('env(SOLIDINVOICE_MAILER_DSN)', 'null://null');
$parameters->set('env(SOLIDINVOICE_MAILER_SENDER)', 'SolidInvoice <no-reply@solidinvoice.co>');
```

这里定义了两个环境变量的**默认值**：
- `SOLIDINVOICE_MAILER_DSN` 默认值为 `null://null`（即不使用任何真实邮件传输）
- `SOLIDINVOICE_MAILER_SENDER` 默认值为 `SolidInvoice <no-reply@solidinvoice.co>`

**读入入口二：框架 Mailer 组件配置**

文件：[config/packages/mailer.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/config/packages/mailer.php#L17-L23)

```php
return static function (FrameworkConfig $frameworkConfig): void {
    $frameworkConfig->mailer()
        ->dsn(env('SOLIDINVOICE_MAILER_DSN'))
        ->envelope()
        ->sender(env('SOLIDINVOICE_MAILER_SENDER'))
    ;
};
```

这里将两个环境变量直接注入到 Symfony Mailer 组件的配置中。这两个是**部署级配置**（Deployment-level），通过环境变量或 Symfony Secrets Vault 写入，适合固定部署时配置。

---

### `email_settings` 翻译键的"留存但未使用"事实

文件：[messages.en.yml](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Resources/translations/messages.en.yml#L40-L43)

```yaml
config:
    title:
        requirements_check: 'System Requirements Check'
        database_config: 'Database Config'
        email_settings: 'Email Settings'
```

**事实**：`email_settings` 翻译键确实存在于安装向导的翻译文件中，与 `database_config` 并列，说明早期版本或计划中将邮件配置作为安装向导的一个独立步骤。

**但实际未使用**：在 [InstallationType.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L44-L94) 定义的 7 个步骤中，只有 `start` → `system_requirements` → `database_config` → `user_account` → `review` → `install` → `finish`，**没有 `email_settings` 步骤**，与翻译键对应的表单类型、Form Step、模板都不存在。

这说明邮件配置从安装向导中被移除了，但翻译键残留未清理。

---

### MailerBundle 配置写回与运行时 Transport 生效的真实路径

邮件传输的真正配置入口位于**安装完成后的系统设置**（Settings），走一条独立的"设置页"链路，而非安装向导链路。

#### 链路一：设置项的初始播种（首次创建公司时）

邮件设置的**初始播种发生在公司创建时**，通过 ConfigProvider 机制注入默认值。

**ConfigProvider 接口**：[ProviderInterface.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Config/ProviderInterface.php)

各 Bundle 通过实现 `ProviderInterface` 提供默认设置项，由 `DefaultData` 在创建公司时播种到数据库：

```php
// MailerBundle/Config/ConfigProvider.php
final class ConfigProvider implements ProviderInterface
{
    public function provide(array $data): array
    {
        return [
            new Config(
                'email/from_address',
                'no-reply@solidinvoice.co',
                null,
                EmailType::class,
                ['trial_restricted' => true]
            ),
            new Config('email/from_name', $data['company_name'] ?? '', null, TextType::class),
            new Config(
                'email/sending_options/provider',
                null,
                null,
                MailTransportType::class,
                ['trial_restricted' => true]
            ),
        ];
    }
}
```

播种发生在公司创建流程中（[DefaultData.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/CoreBundle/Company/DefaultData.php#L87-L107)）：

```php
private function createAppConfig(Company $company, array $data): void
{
    foreach ($this->configProviders as $provider) {
        foreach ($provider->provide($data + ['company_name' => $company->getName()]) as $config) {
            $settingEntity = new Setting();
            $settingEntity->setKey($config->key);
            $settingEntity->setValue($config->value);
            $settingEntity->setDescription($config->description);
            $settingEntity->setType($config->formType);
            $settingEntity->setFormOptions($config->formOptions);
            $settingEntity->setDefaultValue($config->value);
            $settingEntity->setCompany($company);
            $this->em->persist($settingEntity);
        }
    }
}
```

**三个邮件相关设置项**：

| 设置键 | 默认值 | 表单类型 | 说明 |
|--------|--------|---------|------|
| `email/from_address` | `no-reply@solidinvoice.co` | EmailType | 发件人地址 |
| `email/from_name` | 公司名称 | TextType | 发件人名称 |
| `email/sending_options/provider` | null | **MailTransportType** | 邮件传输提供商配置 |

---

#### 链路二：Settings 页面的读写路径

安装完成后，用户在 **Settings → Email** 标签页配置邮件。

**设置页 Live 组件**：[Settings.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Twig/Components/Settings.php#L51-L59)

Email 标签页在设置组件中以 section 形式存在：

```php
public array $settingIconMap = [
    'company' => 'building',
    'invoice' => 'file-invoice',
    'quote' => 'file-text-o',
    'email' => 'envelope',
    'payment' => 'credit-card',
    'tax' => 'balance-scale',
    'system' => 'cog',
];
```

**设置表单类型**：[SettingsType.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Form/Type/SettingsType.php)

根据数据库中存储的 `Setting` 实体动态构建表单字段，每个 `Setting` 的 `getType()` 决定渲染的表单控件，`getValue()` 作为初始数据。

**保存写回**（[Settings.php#L146-L157](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Twig/Components/Settings.php#L146-L157)）：

```php
#[LiveAction]
public function save(Request $request): RedirectResponse
{
    $files = $request->files->all();

    if (isset($files['settings']['company']['logo'])) {
        $this->formValues['company']['logo'] = $files['settings']['company']['logo'];
    }

    $this->submitForm();

    $this->settingsRepository->store([$this->section => $this->getForm()->getData()]);

    $route = $this->generateUrl('_settings', ['section' => $this->section]);

    return new class($route) extends RedirectResponse implements FlashResponse {
        public function getFlash(): Generator
        {
            yield self::FLASH_SUCCESS => 'settings.saved.success';
        }
    };
}
```

**SettingsRepository::store()** 方法（[SettingsRepository.php#L44-L81](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Repository/SettingsRepository.php#L44-L81)）将嵌套数组扁平化为路径键并更新数据库：

```php
public function store(array $settings): void
{
    $settings = $this->flatten($settings);
    // 例如 ['email' => ['sending_options' => ['provider' => '...']]]
    //   → ['email/sending_options/provider' => '...']

    $entityManager->wrapInTransaction(function () use ($settings): void {
        foreach ($settings as $key => $value) {
            // ... 特殊处理 custom_domain ...

            $this->createQueryBuilder('s')
                ->update()
                ->set('s.value', ':val')
                ->where('s.key = :key')
                ->setParameter('key', $key)
                ->setParameter('val', empty($value) ? null : $value)
                ->getQuery()
                ->execute();

            // ... 特殊处理 company_name ...
        }
    });
}
```

---

#### 链路三：MailTransportType —— 多 Provider 表单与 JSON 序列化

`email/sending_options/provider` 字段使用特殊的复合表单类型 [MailTransportType](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php)，这是邮件传输配置的核心枢纽。

它根据已注册的 `ConfiguratorInterface`（标签：`solidinvoice_mailer.transport.configurator`）动态构建子表单。

**核心机制**：

1. **动态 Provider 选择**：ChoiceType 下拉选择邮件提供商（Gmail/SMTP/Mailgun/Sendgrid/Postmark/Amazon SES/Mailchimp Mandrill）

2. **对应子表单**：每个 Provider 对应一个 Configurator，它提供专属配置字段（SMTP 的 host/port/user/password；Gmail 的 username/password；Mailgun 的 domain/key 等）

3. **JSON 序列化存储**：DataTransformer 将 provider + config 编码为 JSON 字符串存储（[MailTransportType.php#L94-L126](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php#L94-L126)）：

```php
$builder->addModelTransformer(new class() implements DataTransformerInterface {
    public function transform(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return [
            'provider' => $data['provider'] ?? null,
            str_replace(' ', '-', (string) $data['provider']) => $data['config'] ?? [],
        ];
    }

    public function reverseTransform(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $provider = $value['provider'] ?? null;

        if (null === $provider) {
            return null;
        }

        return json_encode(
            ['provider' => $value['provider'], 'config' => $value[str_replace(' ', '-', (string) $provider)]],
            JSON_THROW_ON_ERROR
        );
    }
});
```

**数据库存储的典型值示例**：

```json
{"provider":"SMTP","config":{"host":"smtp.example.com","port":587,"user":"admin","password":"secret"}}
```

---

#### 链路四：Configurator 列表与 Transport 写回 DSN（运行时生效）

所有邮件 Provider Configurator 都实现 [ConfiguratorInterface](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/ConfiguratorInterface.php)：

```php
interface ConfiguratorInterface
{
    public function getName(): string;
    public function getForm(): string;
    public function configure(array $config): Dsn;
}
```

**已注册的 Configurator**：

| Configurator | 名称 | DSN 格式 |
|---------------|------|----------|
| [SmtpConfigurator](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/SmtpConfigurator.php) | SMTP | `smtp://user:pass@host:port` |
| [GmailConfigurator](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/GmailConfigurator.php) | Gmail | `gmail+smtp://username:password@default` |
| MailgunConfigurator | Mailgun | `mailgun+api://key@default?region=...` |
| PostmarkConfigurator | Postmark | `postmark+api://key@default` |
| SendgridConfigurator | Sendgrid | `sendgrid+api://key@default` |
| SesConfigurator | Amazon SES | `ses+api://...` |
| MailchimpConfigurator | Mailchimp Mandrill | `mandrill+api://...` |

运行时生效的关键是 [MailerConfigFactory](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Factory/MailerConfigFactory.php)——它通过 Symfony CompilerPass **装饰**了框架原生的 `mailer.transport_factory` 服务（[MailerTransportConfigCompilerPass.php#L25-L38](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/DependencyInjection/CompilerPass/MailerTransportConfigCompilerPass.php#L25-L38)）：

```php
public function process(ContainerBuilder $container): void
{
    if (!$container->hasDefinition('mailer.transport_factory')) {
        return;
    }

    $definition = new Definition(MailerConfigFactory::class);
    $definition->setDecoratedService('mailer.transport_factory');
    $definition->addArgument(new Reference(MailerConfigFactory::class . '.inner'));
    $definition->setArgument('$transports', new TaggedIteratorArgument('solidinvoice_mailer.transport.configurator'));
    $definition->setAutowired(true);

    $container->setDefinition(MailerConfigFactory::class, $definition);
}
```

**MailerConfigFactory::fromStrings()** 在发送邮件时被调用（[MailerConfigFactory.php#L44-L67](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Factory/MailerConfigFactory.php#L44-L67)）：

```php
public function fromStrings(array $dsns = []): ?TransportInterface
{
    try {
        $mailerConfig = $this->config->get(self::CONFIG_KEY);

        if (null === $mailerConfig) {
            return $this->inner->fromStrings($dsns);
        }

        $config = json_decode($mailerConfig, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Invalid mailer config', $e->getCode(), $e);
    }

    $provider = $config['provider'] ?? '';

    foreach ($this->transports as $transport) {
        if ($transport->getName() === $provider) {
            return $this->inner->fromDsnObject($transport->configure($config['config'] ?? []));
        }
    }

    throw new RuntimeException('Invalid mailer config');
}
```

**运行时生效路径优先级**：

```
发送邮件时触发 mailer.transport_factory
           ↓
MailerConfigFactory::fromStrings() 被调用
           ↓
┌─ 数据库中 email/sending_options/provider 有值？
│ 是 → 读取 JSON，解析 provider + config
│       → 匹配 Configurator → configure() 生成 Symfony Dsn 对象
│       → 框架 Transport 实例化（SMTP/API 客户端）
│
否 → 回退到框架原生
       → 使用 $_ENV[SOLIDINVOICE_MAILER_DSN]
       → 默认为 null://null（不发送）
```

---

### 邮件配置完整链路总结

```
┌─────────────────────────────────────────────────────────────────────┐
│               环境变量层级（部署级，Secrets Vault）                    │
│                                                                     │
│  config/services.php:                                               │
│    ├── env(SOLIDINVOICE_MAILER_DSN) = 'null://null'                │
│    └── env(SOLIDINVOICE_MAILER_SENDER) = 默认发件人                 │
│                                                                     │
│  config/packages/mailer.php:                                        │
│       └── 注入 FrameworkConfig::mailer                              │
│             → dsn + envelope.sender                                  │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│             设置层级（公司级，数据库 Setting 表）                       │
│                                                                     │
│  公司创建 DefaultData:                                               │
│    └── ConfigProvider::provide() → 播种 3 条 Setting 行              │
│        ├── email/from_address                                       │
│        ├── email/from_name                                          │
│        └── email/sending_options/provider (MailTransportType)       │
│                                                                     │
│  Settings Live Component:                                            │
│    └── 用户在 Email 标签页填写表单                                    │
│        ├── MailTransportType 动态渲染                                │
│        │     ├── 选择 Provider (ChoiceType)                         │
│        │     └── 对应子表单字段                                      │
│        │     └── JSON 序列化: {"provider":"SMTP","config":{...}}    │
│        └── SettingsRepository::store() → UPDATE setting 表          │
└─────────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────────┐
│             运行时层级（发信时动态生效）                               │
│                                                                     │
│  MailerConfigFactory (装饰 mailer.transport_factory)                 │
│    └── fromStrings():                                               │
│         ├── 检查数据库 config->get('email/sending_options/provider')│
│         ├── 未配置 → 回退 SOLIDINVOICE_MAILER_DSN                   │
│         ├── 已配置 → 解析 JSON                                      │
│         └── Configurator::configure() → Dsn 对象                    │
│         └── 框架创建 Transport                                      │
│         └── 发送邮件                                                │
└─────────────────────────────────────────────────────────────────────┘
```

### 关键设计决策分析

**为什么邮件配置不在安装向导中？**

1. **部署 vs 业务分离**：
   - 数据库配置是**部署基础设施**，必须在安装前配置
   - 邮件配置是**业务配置**，可以安装后由管理员在 Settings 中设置

2. **两套机制的双重配置路径**：
   - **环境变量（Secrets Vault）**：适合 DevOps 部署时注入（Docker/Helm），无需进 Settings
   - **数据库 Setting**：适合 SaaS 场景，每个公司可独立配置邮件

3. **运行时回退机制**：
   - MailerConfigFactory 优先用数据库 Setting 配置
   - 回退到环境变量，为默认值 `null://null` 不发信（null transport）
   - 部署环境（Helm Chart）可以在部署时直接设置 `SOLIDINVOICE_MAILER_DSN` 环境变量

4. **遗留翻译键的历史残留**：
   - 翻译键 `email_settings` 作为安装向导的翻译与数据库配置同级存在，说明早期可能计划把邮件配置并入安装步骤
   - 最终选择了"安装后在 Settings 中配置"，翻译键未清理

---

### 补充：发件人 From 字段的设置与回退机制

邮件的发件人（From Header）独立于 transport DSN 之外，由 Symfony Mailer 的 [MessageEvent](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/CoreBundle/Listener/EmailFromListener.php#L60-L65) 事件监听器在发送前注入。

#### EmailFromListener 事件订阅者

文件：[EmailFromListener.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/CoreBundle/Listener/EmailFromListener.php#L28-L65)

它订阅 `MessageEvent::class`（Symfony Mailer 发送消息事件），在每次发信前动态覆盖 `From` 头。

**回退优先级链**：

```
发信前触发 MessageEvent
        ↓
EmailFromListener::__invoke()
        ↓
┌─ 数据库中 email/from_address 有值（非空字符串）？
│   是 → 读取 email/from_name（可为空）
│        → $message->from(new Address($fromAddress, $fromName))
│
│   否 → 回退到当前登录用户
│        → $tokenStorage->getToken()
│        → $user->getEmail()
│        → $message->from($user->getEmail())
│
│   连 token 都不存在（如 Console 命令发信）
│        → 不覆盖 From，使用邮件类里直接设置的默认值
│        → 最终用 env(SOLIDINVOICE_MAILER_SENDER) 作为 Envelope Sender
```

**源码**：

```php
// EmailFromListener.php L36-L58
public function __invoke(MessageEvent $event): void
{
    /** @var TemplatedEmail $message */
    $message = $event->getMessage();

    $fromAddress = (string) $this->config->get('email/from_address');

    if ('' !== $fromAddress) {
        $fromName = (string) $this->config->get('email/from_name');
        $message->from(new Address($fromAddress, $fromName));
    } else {
        // If a from address is not specified in the config,
        // then we use the currently logged-in user's address
        $token = $this->tokenStorage->getToken();

        if ($token instanceof TokenInterface) {
            /** @var User $user */
            $user = $token->getUser();
            $message->from($user->getEmail());
        }
    }
}
```

**关键点**：
- `email/from_address` 为空字符串而非 `null` 时，触发回退
- `from_name` 可留空，仅在 `from_address` 有值时一起读取
- 该监听器不负责 Envelope（信封）发送地址，那由 Symfony Mailer 根据 `env(SOLIDINVOICE_MAILER_SENDER)` 自动决定

---

### 补充：7 种邮件 Provider 的 DSN 生成差异

每个 Configurator 的 `configure(array $config): Dsn` 方法将用户填写的表单字段拼接为 Symfony Mailer 支持的 DSN 字符串。所有 Provider 的差异如下：

| Provider | 配置字段 | DSN 格式 | 对应 Configurator |
|----------|---------|----------|-------------------|
| **SMTP** | host、port、user、password | `smtp://user:urlencode(pass)@host:port`（无凭据时去掉 `user:pass@` 段） | [SmtpConfigurator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/SmtpConfigurator.php#L40-L47) |
| **Gmail** | username、password | `gmail+smtp://username:password@default` | [GmailConfigurator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/GmailConfigurator.php#L37-L40) |
| **Mailgun** | key、domain | `mailgun+api://key:domain@default` | [MailgunConfigurator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/MailgunConfigurator.php#L37-L40) |
| **Postmark** | key | `postmark+api://key@default` | [PostmarkConfigurator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/PostmarkConfigurator.php#L37-L40) |
| **Sendgrid** | key | `sendgrid+api://key@default` | [SendgridConfigurator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/SendgridConfigurator.php#L37-L40) |
| **Amazon SES** | accessKey、accessSecret、region | `ses+api://accessKey:accessSecret@default?region=xx`（region 为空时不带 query 参数） | [SesConfigurator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/SesConfigurator.php#L37-L45) |
| **Mailchimp Mandrill** | key | `mandrill+api://key@default` | [MailchimpConfigurator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Configurator/MailchimpConfigurator.php#L37-L40) |

**SMTP 的特殊分支（无凭据时）**：

```php
// SmtpConfigurator.php L42-L46
if (empty($config['user']) && empty($config['password'])) {
    return Dsn::fromString(sprintf('smtp://%s:%d', $config['host'], $config['port'] ?? 25));
}
return Dsn::fromString(sprintf('smtp://%s:%s@%s:%d',
    $config['user'], urlencode($config['password'] ?? ''),
    $config['host'], $config['port'] ?? 25));
```

**Amazon SES 的 region 参数**：

```php
// SesConfigurator.php L39-L42
$dsn = sprintf('ses+api://%s:%s@default', $config['accessKey'], $config['accessSecret']);
if (array_key_exists('region', $config) && null !== $config['region']) {
    $dsn .= '?region=' . $config['region'];
}
return Dsn::fromString($dsn);
```

---

### 补充：默认设置播种到运行时生效的完整衔接

#### 阶段一：容器编译期（服务注册与标签）

文件：[SolidInvoiceMailerExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/DependencyInjection/SolidInvoiceMailerExtension.php#L24-L31)

通过 `registerForAutoconfiguration` 为所有实现 `ConfiguratorInterface` 的类自动打上服务标签：

```php
$container->registerForAutoconfiguration(ConfiguratorInterface::class)
    ->addTag('solidinvoice_mailer.transport.configurator');
```

文件：[MailerTransportConfigCompilerPass.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/DependencyInjection/CompilerPass/MailerTransportConfigCompilerPass.php#L25-L38)

CompilerPass 装饰框架的 `mailer.transport_factory`，并通过 `TaggedIteratorArgument` 把所有打了标签的 Configurator 注入到 `MailerConfigFactory`：

```php
$definition = new Definition(MailerConfigFactory::class);
$definition->setDecoratedService('mailer.transport_factory');
$definition->addArgument(new Reference(MailerConfigFactory::class . '.inner'));
$definition->setArgument('$transports', new TaggedIteratorArgument('solidinvoice_mailer.transport.configurator'));
$definition->setAutowired(true);
```

#### 阶段二：公司创建（默认设置播种）

文件：[DefaultData.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/CoreBundle/Company/DefaultData.php#L87-L107) → 调用 [ConfigProvider.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Config/ConfigProvider.php#L27-L46)

用户注册公司时，`DefaultData` 通过 `#[AutowireIterator(ProviderInterface::class)]` 收集所有 ConfigProvider，遍历后将 3 条邮件设置以 `Setting` 实体形式写入数据库：

| Setting key | 初始值 | 表单类型 |
|---|---|---|
| `email/from_address` | `no-reply@solidinvoice.co` | EmailType |
| `email/from_name` | `$company->getName()`（公司名） | TextType |
| `email/sending_options/provider` | `null` | MailTransportType |

此时 `email/sending_options/provider` 为 `null`，意味着**尚未选择任何邮件 Provider**。

#### 阶段三：Settings 页面交互（用户配置 Provider）

1. 页面加载：[Settings Live Component](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Twig/Components/Settings.php) 根据 section 分组读取 Setting 记录，通过 [SettingsType.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/Form/Type/SettingsType.php) 渲染表单

2. `MailTransportType` 构建子表单：根据注入的 `iterable $transports`（即 7 个 Configurator），循环调用 `getForm()` 获得子表单类型（如 `SmtpTransportConfigType`、`UsernamePasswordTransportConfigType` 等），每个 Provider 对应一组专属字段

3. DataTransformer 将 JSON 字符串 ↔ 表单数组互相转换

4. 用户提交后，`SettingsRepository::store()` 通过 DQL UPDATE 把 JSON 字符串写回 `email/sending_options/provider` 的 Setting.value 字段

#### 阶段四：首次发信（运行时 Transport 生效）

```
邮件被发送
    ↓
Symfony Mailer 调用 mailer.transport_factory
    ↓
（装饰器）MailerConfigFactory::fromStrings()
    ↓
SystemConfig::get('email/sending_options/provider')
    ↓
┌─ value !== null
│   → json_decode 得到 ['provider' => 'SMTP', 'config' => [...]]
│   → 遍历 iterable<ConfiguratorInterface>，匹配 getName() === 'SMTP'
│   → SmtpConfigurator::configure(config) 返回 Symfony\Component\Mailer\Transport\Dsn
│   → $this->inner->fromDsnObject($dsn) 创建真实 Transport 实例
│   → 邮件通过 SMTP 服务发送
│
└─ value === null
    → return $this->inner->fromStrings($dsns)
    → 框架使用 env(SOLIDINVOICE_MAILER_DSN)
    → 默认值 null://null → NullTransport（静默丢弃不发信）
```

#### 阶段四同时：From Header 注入

```
MessageEvent 派发（Mailer sendMessage 过程中）
    ↓
EmailFromListener::__invoke()
    ↓
SystemConfig::get('email/from_address')
    ↓
┌─ 非空 → new Address(from_address, from_name) 设置到 message.from
└─ 为空 → TokenStorage 当前登录用户的 email 设置到 message.from
```

两条链路互不干扰：
- **Transport 链路**决定邮件通过什么服务器/API 发出去（物理通道）
- **From 链路**决定收件人看到的"来自谁"（显示信息）

---

## 补充：发信配置的完整生命周期

发信配置不是静态写入就结束的，而是从"公司创建"到"首次发信"经历了一个完整的状态变迁。本节从代码层面将这些环节的关系串清楚。

### 公司创建触发链

公司（Company）实体被 persist 到数据库后，Doctrine 事件监听器触发设置播种：

```
EntityManager::persist($company) + flush()
    ↓
Doctrine postPersist 事件
    ↓
CompanyCreatedListener::postPersist()      ← Doctrine Entity Listener
    ├── dispatch(CompanyCreatedEvent)      ← Symfony EventDispatcher
    │     └── CompanyEventSubscriber       ← SaaS 订阅/试用创建（仅 SaaS 模式）
    │
    ├── CompanySelector::switchCompany()   ← 切换到新公司的多租户上下文
    │
    └── DefaultData::__invoke()            ← 播种默认设置
          ├── createAppConfig()            ← 遍历 ConfigProvider 播种 Setting 行
          ├── createDefaultCustomFields()  ← 播种联系人自定义字段
          └── createPaymentMethods()       ← 播种默认支付方式
```

文件：[CompanyCreatedListener.php](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/CoreBundle/Doctrine/Listener/CompanyCreatedListener.php#L25-L46)

```php
#[AsEntityListener(Events::postPersist, entity: Company::class)]
final readonly class CompanyCreatedListener
{
    public function postPersist(Company $company): void
    {
        $this->eventDispatcher->dispatch(new CompanyCreatedEvent($company));

        $this->companySelector->switchCompany($company->getId());

        ($this->defaultData)($company, ['currency' => $company->currency]);
    }
}
```

**关键细节**：
- `#[AsEntityListener(Events::postPersist, entity: Company::class)]` 只对 `Company` 实体的 postPersist 事件生效
- 在播种默认设置前，先通过 `CompanySelector::switchCompany()` 切换多租户上下文，确保后续数据库查询走新公司的数据
- `CompanyCreatedEvent` 是一个独立的事件，供其他模块（如 SaasBundle）订阅

---

### 默认设置播种的细节

[DefaultData::createAppConfig()](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/CoreBundle/Company/DefaultData.php#L87-L107) 通过 `#[AutowireIterator(ProviderInterface::class)]` 收集所有 ConfigProvider，包括 MailerBundle 的 [ConfigProvider](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Config/ConfigProvider.php#L27-L46)。

**播种时邮件设置的初始值**：

| Setting.key | Setting.value | Setting.defaultValue | Setting.type | 说明 |
|---|---|---|---|---|
| `email/from_address` | `no-reply@solidinvoice.co` | `no-reply@solidinvoice.co` | EmailType | 初始就是可用值 |
| `email/from_name` | `$company->getName()` | `$company->getName()` | TextType | 初始就是可用值 |
| `email/sending_options/provider` | `null` | `null` | MailTransportType | **初始为空，用户必须手动选择 Provider** |

`email/sending_options/provider` 初始值为 `null` 的含义：**播种时并没有选定邮件传输方式，邮件系统处于"只设了发件人、但无法发出"的中间态**。

---

### SystemConfig 读取的多层回退

[SystemConfig::get()](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SettingsBundle/SystemConfig.php#L40-L47) 是所有设置读取的统一入口，它内置了一层关键的回退：

```php
public function get(string $key, ?Company $company = null): ?string
{
    if (null === $this->installed || '' === $this->installed) {
        return null;  // ← 安装前：所有设置读取一律返回 null
    }

    return $this->repository->getSetting($key, $company)?->getValue();
}
```

**回退层次**：

```
SystemConfig::get('email/from_address')
    ↓
┌─ $this->installed 为 null 或空？
│   是 → 直接返回 null（安装向导阶段，数据库可能还不存在）
│
└─ $this->installed 有值
    ↓
    ┌─ Setting 表中该 key 存在？
    │   是 → 返回 Setting.value（可能为 null 或空字符串）
    │   └─ 否 → getSetting() 返回 null → getValue() 不调用 → 整体返回 null
```

**实际影响**：调用方对 `null` 的处理各有不同——

| 调用方 | 代码 | 对 `null` 的处理 |
|---|---|---|
| [EmailFromListener](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/CoreBundle/Listener/EmailFromListener.php#L41-L46) | `(string) $this->config->get('email/from_address')` | `null` → `''`（空字符串）→ 触发回退到当前用户邮箱 |
| [MailerConfigFactory](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/MailerBundle/Factory/MailerConfigFactory.php#L47-L51) | `$this->config->get(self::CONFIG_KEY)` | `null` → 回退到 `env(SOLIDINVOICE_MAILER_DSN)` |
| [SendOnboardingEmailHandler](file:///d:/fz/0601-2/solo-dogfeeding/code/11-SolidInvoice/src/SaasBundle/MessageHandler/SendOnboardingEmailHandler.php#L128-L133) | `$this->systemConfig->get('email/from_address')` | `null` → 不设置 `from()` → 由 EmailFromListener 在 MessageEvent 中注入 |

注意 **EmailFromListener 与 SendOnboardingEmailHandler 的交互**：
- `SendOnboardingEmailHandler` 通过 Messenger 异步处理，在 CLI worker 中运行
- 它有条件地设置 `$email->from()`，当 `from_address` 为 null 时不设置
- 随后 `$this->mailer->send($email)` 触发 Symfony Mailer 管线
- `EmailFromListener` 监听 `MessageEvent`，如果邮件还没有 From，会再次尝试注入
- 但在 CLI worker 中 `TokenStorage` 通常没有 token，所以 EmailFromListener 也不会设置 From
- 这种情况下，**邮件的 From 头为空**，Symfony Mailer 会使用 Envelope Sender 作为兜底

---

### From Header 与 Envelope Sender 的边界

邮件发送中有两个不同的"发件人"概念，SolidInvoice 将它们分属不同的配置层：

| 概念 | RFC 定义 | 控制方 | 配置来源 | 用户可见 |
|---|---|---|---|---|
| **From Header** | RFC 5322 `From:` 头 | EmailFromListener（MessageEvent） | 数据库 `email/from_address` + `email/from_name`；回退当前用户邮箱 | ✅ 收件人看到的发件人 |
| **Envelope Sender** | RFC 5321 `MAIL FROM` 命令 | Symfony Framework 配置 | `env(SOLIDINVOICE_MAILER_SENDER)` → `config/packages/mailer.php` | ❌ 对用户不可见，仅 SMTP 对话中使用 |

**配置来源对比**：

```php
// From Header：公司级数据库设置（每个公司可不同）
// 由 EmailFromListener 在 MessageEvent 中注入
$fromAddress = (string) $this->config->get('email/from_address');
$fromName = (string) $this->config->get('email/from_name');
$message->from(new Address($fromAddress, $fromName));

// Envelope Sender：部署级环境变量（全局统一）
// 由 config/packages/mailer.php 在框架初始化时注入
$frameworkConfig->mailer()
    ->envelope()
    ->sender(env('SOLIDINVOICE_MAILER_SENDER'));
// 默认值：'SolidInvoice <no-reply@solidinvoice.co>'
```

**边界场景**：

1. **正常发信**：From Header = 公司设置的发件人，Envelope Sender = `SOLIDINVOICE_MAILER_SENDER`
2. **from_address 为空 + Web 请求**：From Header = 当前登录用户邮箱，Envelope Sender 不变
3. **from_address 为空 + CLI worker**：From Header 无值（EmailFromListener 找不到 token），Symfony Mailer 自动将 Envelope Sender 填入 From Header
4. **安装前**：`SystemConfig::get()` 一律返回 `null`，EmailFromListener 得到空字符串，回退到 token 或留空，最终依赖 Envelope Sender

**为什么需要分开？**
- Envelope Sender 是 SMTP 协议层的概念，用于退信（bounce）通知和 SPF/DKIM 验证
- From Header 是邮件展示层的概念，收件人在邮件客户端看到的"来自"
- 两者可以不同：Envelope Sender 可以是 `noreply@solidinvoice.co`（SPF 认证的域），From Header 可以是用户公司名称 `Acme Inc <billing@acme.com>`

---

### 发信配置生命周期总图

```
┌──────────────────────────────────────────────────────────────────────────┐
│                         安装前                                          │
│                                                                          │
│  SOLIDINVOICE_INSTALLED = null                                          │
│  SOLIDINVOICE_MAILER_DSN = 'null://null'                               │
│  SOLIDINVOICE_MAILER_SENDER = 'SolidInvoice <no-reply@solidinvoice.co>'│
│                                                                          │
│  SystemConfig::get() → 一律返回 null                                    │
│  MailerConfigFactory → 回退 env → NullTransport（静默丢弃）             │
│  EmailFromListener → 回退当前用户或留空 → Envelope Sender 兜底          │
└──────────────────────────────────────────────────────────────────────────┘
                              ↓ 安装完成
┌──────────────────────────────────────────────────────────────────────────┐
│                         安装后、公司创建前                               │
│                                                                          │
│  SOLIDINVOICE_INSTALLED = '2026-06-17T...'                              │
│  SystemConfig::get() → 查询 Setting 表（但尚无 Setting 行）            │
│  → 返回 null                                                            │
│  → MailerConfigFactory 仍回退 env → NullTransport                       │
│  → EmailFromListener 仍回退当前用户邮箱或 Envelope Sender              │
└──────────────────────────────────────────────────────────────────────────┘
                              ↓ 用户创建公司
┌──────────────────────────────────────────────────────────────────────────┐
│                         公司创建后（播种完成）                           │
│                                                                          │
│  CompanyCreatedListener::postPersist()                                  │
│    └── DefaultData → ConfigProvider::provide()                          │
│          ├── email/from_address = 'no-reply@solidinvoice.co'            │
│          ├── email/from_name = 'Acme Inc'                               │
│          └── email/sending_options/provider = null                      │
│                                                                          │
│  SystemConfig::get('email/from_address') → 'no-reply@solidinvoice.co'  │
│  SystemConfig::get('email/sending_options/provider') → null            │
│  → MailerConfigFactory 仍回退 env → NullTransport                       │
│  → EmailFromListener 使用 from_address + from_name 设置 From           │
│  → 邮件有 From 头了，但仍然发不出去（没有 Transport）                    │
└──────────────────────────────────────────────────────────────────────────┘
                              ↓ 用户在 Settings → Email 选择 Provider
┌──────────────────────────────────────────────────────────────────────────┐
│                         用户配置邮件 Provider 后                        │
│                                                                          │
│  email/sending_options/provider = '{"provider":"SMTP","config":{...}}'  │
│                                                                          │
│  MailerConfigFactory::fromStrings()                                      │
│    → SystemConfig::get('email/sending_options/provider') → JSON 字符串│
│    → json_decode → 匹配 SmtpConfigurator                               │
│    → SmtpConfigurator::configure() → Dsn('smtp://...')                  │
│    → $this->inner->fromDsnObject($dsn) → SmtpTransport 实例            │
│                                                                          │
│  EmailFromListener                                                       │
│    → SystemConfig::get('email/from_address') → 设置 From Header        │
│                                                                          │
│  Envelope Sender                                                         │
│    → env(SOLIDINVOICE_MAILER_SENDER) → MAIL FROM 命令                   │
│                                                                          │
│  ✅ 邮件完整可发：Transport 通道 + From 显示 + Envelope 退信           │
└──────────────────────────────────────────────────────────────────────────┘
```

**核心洞察**：发信配置的生命周期是**渐进式就绪**的——安装锁定了应用身份、公司创建锁定了发件人信息、用户选择 Provider 锁定了传输通道，三个阶段各解决一个问题，任何一个阶段缺失都会通过回退机制优雅降级而非报错。
