# SolidInvoice 通知传输机制详解

本文档详细解析 SolidInvoice 中通知系统的传输配置加载、事件订阅与通知分发器的衔接机制。

## 一、整体架构概览

SolidInvoice 的通知系统基于 **Symfony Notifier** 组件构建，采用分层设计：

```
┌─────────────────────────────────────────────────────────┐
│                    事件触发层                            │
│  WorkFlowSubscriber / PaymentListener / ClientListener  │
└────────────────────────┬────────────────────────────────┘
                         │ 调用 sendNotification()
                         ▼
┌─────────────────────────────────────────────────────────┐
│              NotificationManager (分发器)                │
│  - 读取用户通知配置 (UserNotification)                   │
│  - 构建 channels 数组                                   │
│  - 委托给 NotifierInterface 发送                        │
└────────────────────────┬────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│              Symfony Notifier (核心)                    │
│  - 按 channel 分发到对应 transport                       │
│  - 触发 MessageEvent                                    │
└────────────────────────┬────────────────────────────────┘
                         │
         ┌───────────────┴───────────────┐
         ▼                               ▼
┌───────────────────┐         ┌───────────────────┐
│  Email Transport  │         │  Chat/SMS Transport│
│  (邮件通道)        │         │  (第三方集成)      │
└───────────────────┘         └─────────┬─────────┘
                                        │
                                        ▼
                              ┌───────────────────┐
                              │ Transports (自定义)│
                              │  + 动态加载数据库  │
                              │    中的传输配置    │
                              └───────────────────┘
```

---

## 二、传输配置加载机制

### 2.1 传输方式静态定义

**文件：** [transports.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Resources/config/transports.php)

这是传输方式的"目录"，定义了系统支持的所有传输类型：

- **两大类别：**
  - `texter` - 短信类（24种）：Twilio, Vonage, 阿里云短信等
  - `chatter` - 聊天类（14种）：Slack, Discord, Telegram, Microsoft Teams 等

- **每个传输的信息：**
  - `package` - 对应的 Symfony Notifier 包名
  - `dsn` - DSN 格式模板

### 2.2 传输配置器 (Configurator)

**接口：** [ConfiguratorInterface.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Configurator/ConfiguratorInterface.php)

每个传输方式都有一个对应的 Configurator 类，负责：

```php
interface ConfiguratorInterface
{
    public const DI_TAG = 'notification.configurator';

    public static function getName(): string;      // 传输名称，如 'Slack'
    public static function getType(): string;      // 类型：'chatter' 或 'texter'
    public function getForm(): string;             // 对应的表单类型类
    public function configure(array $config): Dsn; // 将配置数组转为 DSN 对象
}
```

**示例 - SlackConfigurator：** [SlackConfigurator.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Configurator/SlackConfigurator.php)

```php
final class SlackConfigurator implements ConfiguratorInterface
{
    public static function getName(): string { return 'Slack'; }
    public static function getType(): string { return 'chatter'; }
    public function getForm(): string { return SlackType::class; }

    public function configure(array $config): Dsn
    {
        return new Dsn(sprintf(
            'slack://%s@default?channel=%s',
            urlencode($config['token']),
            urlencode($config['channel'])
        ));
    }
}
```

**自动注册：** 通过 `#[AutoconfigureTag(ConfiguratorInterface::DI_TAG)]` 自动打上 `notification.configurator` 标签，然后通过 `#[TaggedLocator]` 注入到 ServiceLocator 中。

### 2.3 传输设置存储

**实体：** [TransportSetting.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Entity/TransportSetting.php)

用户配置的传输实例存储在数据库中：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | Ulid | 主键 |
| name | string | 用户自定义名称（如"团队 Slack"） |
| transport | string | 传输类型名（对应 Configurator::getName()） |
| settings | json | 具体配置参数（如 token, channel 等） |
| user | User | 所属用户 |
| company | Company | 所属公司（多租户） |

### 2.4 传输工厂

**类：** [NotificationTransportFactory.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Factory/NotificationTransportFactory.php)

核心方法 `fromStrings()` 动态加载数据库中的传输配置：

```php
public function fromStrings(array $dsns): Transports
{
    $transports = [];

    // 遍历数据库中所有传输配置
    foreach ($this->transportSettingRepository->findAll() as $setting) {
        // 获取对应类型的 Configurator
        $configurator = $this->transportConfigurations->get($setting->getTransport());
        
        try {
            // 用配置器将 settings 数组转为 DSN，再创建 Transport 实例
            $transports[$setting->getId()->toString()] = 
                $this->transport->fromDsnObject($configurator->configure($setting->getSettings()));
        } catch (UnsupportedSchemeException) {
            continue; // 忽略不支持的传输方式
        }
    }

    return new Transports($transports);
}
```

**关键点：** transport 的 key 是 `TransportSetting` 的 Ulid，这样可以通过 ID 直接定位到具体的传输实例。

### 2.5 自定义 Transports 集合

**类：** [Transports.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Notification/Transports.php)

实现 `TransportInterface`，内部持有多个传输实例：

- `send()` - 根据消息指定的 transport 名称选择对应传输
- `supports()` - 检查是否有传输支持该消息
- key 是传输配置的 Ulid 字符串

### 2.6 编译器 Pass 装饰

**类：** [NotificationTransportConfigCompilerPass.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/DependencyInjection/CompilerPass/NotificationTransportConfigCompilerPass.php)

在容器编译阶段，用 `NotificationTransportFactory` 装饰 Symfony 原生的 `chatter.transport_factory` 和 `texter.transport_factory`：

```php
public function process(ContainerBuilder $container): void
{
    foreach (['chatter.transport_factory', 'texter.transport_factory'] as $factory) {
        $definition = new Definition(NotificationTransportFactory::class);
        $definition->setDecoratedService($factory);
        // ...
    }
}
```

这样当 Notifier 需要创建传输时，会走自定义的工厂，从数据库动态加载配置。

---

## 三、通知消息定义

### 3.1 AsNotification 属性

**文件：** [AsNotification.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Attribute/AsNotification.php)

标记一个类为通知消息：

```php
#[Attribute(Attribute::TARGET_CLASS)]
final class AsNotification
{
    public function __construct(
        public string $name,           // 事件名（唯一标识）
        public string $title = '',     // 显示名称
        public string $description = '', // 描述
        public string $icon = 'tabler:bell',
        public NotificationCategory $category = NotificationCategory::OTHER,
    ) {}
}
```

**自动注册：** 在 [SolidInvoiceNotificationExtension.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/DependencyInjection/SolidInvoiceNotificationExtension.php) 中，通过 `registerAttributeForAutoconfiguration` 自动给带 `#[AsNotification]` 的类打上 `solid_invoice_notification.notification` 标签。

### 3.2 NotificationMessage 基类

**文件：** [NotificationMessage.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Notification/NotificationMessage.php)

所有通知消息的抽象基类：

```php
abstract class NotificationMessage extends Notification 
    implements EmailNotificationInterface, ChatNotificationInterface
{
    private array $parameters; // 模板参数

    abstract public function getTextContent(Environment $twig): string;

    public function asEmailMessage(...): EmailMessage { ... }
    public function asChatMessage(...): ChatMessage { ... }
}
```

**特点：** 同时支持邮件和聊天两种通知方式。

### 3.3 具体通知消息示例

**示例：** [InvoiceStatusNotification.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceStatusNotification.php)

```php
#[AsNotification(
    name: self::EVENT,                           // 'invoice_status_update'
    title: 'Invoice Status Changed',
    description: 'When an invoice status changes',
    icon: 'tabler:file-invoice',
    category: NotificationCategory::INVOICE,
)]
class InvoiceStatusNotification extends NotificationMessage
{
    public const EVENT = 'invoice_status_update';
    
    public function getTextContent(Environment $twig): string
    {
        return $twig->render(self::TEXT_TEMPLATE, $this->getParameters());
    }
    // ...
}
```

### 3.4 已有的通知类型

| 通知类 | 事件名 | 触发时机 |
|--------|--------|----------|
| `InvoiceStatusNotification` | `invoice_status_update` | 发票状态变更 |
| `InvoiceOverdueNotification` | `invoice_overdue` | 发票逾期 |
| `InvoiceReminderNotification` | `invoice_reminder` | 发票催缴提醒 |
| `InvoiceReminderStoppedNotification` | `invoice_reminder_stopped` | 催缴停止 |
| `QuoteStatusNotification` | `quote_status_update` | 报价状态变更 |
| `PaymentReceivedNotification` | `payment_received` | 收到付款 |
| `ClientCreateNotification` | `client_created` | 客户创建 |

---

## 四、事件订阅与通知分发

### 4.1 用户通知配置

**实体：** [UserNotification.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Entity/UserNotification.php)

存储每个用户对每个事件的通知偏好：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | Ulid | 主键 |
| event | string | 事件名（对应 AsNotification::name） |
| email | bool | 是否通过邮件通知 |
| transports | Collection | 通过哪些第三方传输通知（多对多 TransportSetting） |
| user | User | 所属用户 |
| company | Company | 所属公司 |

**核心逻辑：** 每个用户、每个事件可以配置：
- 是否发送邮件
- 通过哪些已配置的第三方传输（Slack、短信等）发送

### 4.2 事件监听器（触发通知）

系统中有多个事件监听器/订阅者，在特定业务事件发生时触发通知：

#### 示例 1：工作流状态变更

**文件：** [WorkFlowSubscriber.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/WorkFlowSubscriber.php)

```php
class WorkFlowSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly NotificationManager $notification  // 注入通知管理器
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.invoice.entered' => 'onWorkflowTransitionApplied',
            'workflow.recurring_invoice.entered' => 'onWorkflowTransitionApplied',
        ];
    }

    public function onWorkflowTransitionApplied(Event $event): void
    {
        $invoice = $event->getSubject();
        // ... 业务逻辑 ...
        
        if (! $isNew) {
            // 发送通知：传入通知消息对象
            $this->notification->sendNotification(
                new InvoiceStatusNotification(['invoice' => $invoice])
            );
        }
    }
}
```

#### 其他触发点

- **PaymentReceivedListener** - 支付完成时
- **ClientListener** - 客户创建时
- **InvoiceOverdueListener** - 发票逾期时
- **SendInvoiceReminderHandler** - 催缴提醒时

### 4.3 NotificationManager - 分发核心

**文件：** [NotificationManager.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php)

这是通知分发的核心协调者：

```php
class NotificationManager
{
    public function __construct(
        private readonly NotifierInterface $notifier,           // Symfony Notifier
        private readonly UserNotificationRepository $userNotificationRepository,
        #[TaggedLocator(tag: ConfiguratorInterface::DI_TAG)]
        private readonly ServiceLocator $transportConfigurations, // 所有配置器
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {}

    public function sendNotification(NotificationMessage $message): void
    {
        // 1. 从消息对象反射获取 AsNotification 属性
        $attributes = (new ReflectionObject($message))->getAttributes(AsNotification::class);
        $event = $attributes[0]->getArguments()['name'];

        // 2. 查询所有订阅了该事件的用户配置
        $userNotifications = $this->userNotificationRepository->findBy(['event' => $event]);

        // 3. 为每个用户构建 channels 并发送
        foreach ($userNotifications as $userNotification) {
            $channels = [];

            // 3.1 添加邮件通道
            if ($userNotification->isEmail()) {
                $channels[] = 'email';
            }

            // 3.2 添加第三方传输通道
            foreach ($userNotification->getTransports() as $transport) {
                $configurator = $this->transportConfigurations->get($transport->getTransport());
                
                // 通道格式：{type}/{transportId}
                // 例如：chat/01HQXYZ... 或 sms/01HQABC...
                $channels[] = sprintf(
                    '%s/%s',
                    match ($configurator::getType()) {
                        'texter' => 'sms',
                        'chatter' => 'chat',
                        default => $configurator::getType(),
                    },
                    $transport->getId()->toString(),
                );
            }

            // 3.3 设置消息的 channels
            $message->channels($channels);

            // 3.4 委托给 Symfony Notifier 发送
            try {
                $this->notifier->send(
                    $message, 
                    new Recipient($userNotification->getUser()->getEmail(), ...)
                );
            } catch (TransportExceptionInterface $e) {
                $this->logger->error(...);
                $hasTransportFailure = true;
            }
        }
    }
}
```

**关键流程详解：**

1. **反射获取事件名：** 从 `NotificationMessage` 对象的 `#[AsNotification]` 属性中提取事件名
2. **查询用户配置：** 根据事件名从 `UserNotification` 表中找到所有订阅用户
3. **构建 channels 数组：**
   - `email` - 邮件通道
   - `chat/{transportId}` - 聊天类传输
   - `sms/{transportId}` - 短信类传输
4. **委托 Notifier 发送：** Symfony Notifier 根据 channel 名称路由到对应 transport

### 4.4 Channel 到 Transport 的映射

**命名约定：**

| Channel 格式 | 对应 Transport | 说明 |
|-------------|---------------|------|
| `email` | 邮件传输 | 内置邮件通道 |
| `chat/{id}` | chatter.transport_factory | 聊天类传输，id 是 TransportSetting 的 Ulid |
| `sms/{id}` | texter.transport_factory | 短信类传输，id 是 TransportSetting 的 Ulid |

Symfony Notifier 会根据 channel 前缀（`chat/` 或 `sms/`）选择对应的 transport factory，然后用后面的 ID 查找具体的传输实例。

---

## 五、通知选项配置器

### 5.1 NotificationOptionConfigurator

**文件：** [NotificationOptionConfigurator.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/EventListener/NotificationOptionConfigurator.php)

监听 Symfony Notifier 的 `MessageEvent` 事件，在消息发送前进行配置：

```php
class NotificationOptionConfigurator
{
    #[AsEventListener(MessageEvent::class)]
    public function configureOptions(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (! $message instanceof ChatMessage) {
            return; // 只处理聊天消息
        }

        // 1. 翻译主题
        $message->subject($this->translator->trans($message->getSubject(), [], 'email'));

        // 2. 处理通知内容
        $notification = $message->getNotification();
        if ($notification instanceof NotificationMessage) {
            $notification->subject($this->translator->trans(...));
            $notification->content($notification->getTextContent($this->twig));
        }

        // 3. 设置消息选项（如 Slack 的 channel 等）
        $this->setMessageOptions($message);
    }
}
```

### 5.2 选项引用解析

支持三种引用类型的动态解析：

- **UrlRouteReference** - 路由 URL 生成
- **TranslationReference** - 翻译
- **TemplateReference** - Twig 模板渲染

这些引用会在 `resolveOptions()` 方法中被递归解析为实际值。

---

## 六、完整调用链路

### 6.1 发送通知的完整流程

```
1. 业务事件发生
   │
   ▼
2. 事件监听器触发 (WorkFlowSubscriber / PaymentListener 等)
   │  调用 $notificationManager->sendNotification(new XxxNotification($params))
   │
   ▼
3. NotificationManager::sendNotification()
   │  ├─ 反射获取 AsNotification 属性中的事件名
   │  ├─ 查询 UserNotification 表获取订阅用户
   │  └─ 为每个用户：
   │     ├─ 构建 channels 数组 (email + chat/sms transports)
   │     ├─ $message->channels($channels)
   │     └─ $notifier->send($message, $recipient)
   │
   ▼
4. Symfony Notifier 分发
   │  ├─ 根据 channel 名称选择 transport
   │  ├─ 触发 MessageEvent
   │  │   └─ NotificationOptionConfigurator 处理
   │  │      ├─ 翻译主题
   │  │      ├─ 渲染内容
   │  │      └─ 设置消息选项
   │  └─ 调用 Transport::send() 实际发送
   │
   ▼
5. Transports (自定义)
   │  根据 transport ID（Ulid）查找具体传输实例
   │
   ▼
6. 实际传输发送
   （Slack API / Twilio API / SMTP 等）
```

### 6.2 传输配置加载时机

传输配置并不是在系统启动时一次性加载的，而是**按需动态加载**：

1. `NotificationTransportFactory` 装饰了原生的 transport factory
2. 当 Notifier 需要通过某个 channel 发送消息时，会调用 factory 创建 transport
3. `NotificationTransportFactory::fromStrings()` 从数据库加载所有 `TransportSetting`
4. 对每个配置，通过对应 Configurator 生成 DSN，再创建 Transport 实例
5. 返回包装了所有传输的 `Transports` 集合

---

## 七、关键设计总结

### 7.1 设计亮点

1. **动态配置：** 传输配置存储在数据库中，支持运行时动态增删，无需修改代码或重启服务
2. **分层解耦：** Configurator、Factory、Transport 各司其职，新增传输方式只需添加 Configurator 和 Form
3. **基于属性：** 使用 `#[AsNotification]` 声明式定义通知类型，自动注册
4. **用户级订阅：** 每个用户可独立配置每个事件的通知方式（邮件 + 多种第三方传输）
5. **Symfony 生态：** 充分利用 Symfony Notifier 组件，支持 40+ 种传输方式

### 7.2 核心实体关系

```
UserNotification (n) ──(event)──▶ AsNotification (属性定义)
       │
       ├── email: bool
       │
       └── (n:n)── TransportSetting (n)
                        │
                        ├── transport: string (对应 Configurator::getName)
                        └── settings: json (配置参数)
```

### 7.3 扩展新传输方式的步骤

1. 创建 `XxxConfigurator` 实现 `ConfiguratorInterface`
2. 创建 `XxxType` 表单类（继承对应 form type）
3. 确保对应 Symfony Notifier 包已安装
4. 在 `transports.php` 中添加元数据（可选，用于展示）

