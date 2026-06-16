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
