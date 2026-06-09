# SolidInvoice 通知传输机制详解

本文档详细解析 SolidInvoice 中通知系统的传输配置加载、事件订阅与通知分发器的衔接机制。所有结论均对照源代码核实。

---

## 一、整体架构概览

SolidInvoice 的通知系统基于 **Symfony Notifier** 组件构建，采用分层设计：

```
┌─────────────────────────────────────────────────────────────┐
│                      业务事件触发层                           │
│  ┌─────────────┐ ┌──────────────┐ ┌──────────────────────┐  │
│  │ 工作流事件   │ │ 支付完成事件   │ │ Doctrine 生命周期事件 │  │
│  └──────┬──────┘ └──────┬───────┘ └──────────┬───────────┘  │
└─────────┼─────────────────┼─────────────────────┼────────────┘
          │                 │                     │
          ▼                 ▼                     ▼
┌─────────────────────────────────────────────────────────────┐
│                   事件监听器 / 订阅者                         │
│  WorkFlowSubscriber, PaymentReceivedListener, ClientListener│
│  InvoiceOverdueListener, SendInvoiceReminderHandler         │
└────────────────────────────┬────────────────────────────────┘
                             │ 调用 sendNotification()
                             ▼
┌─────────────────────────────────────────────────────────────┐
│               NotificationManager (分发协调器)                │
│  ├─ 反射获取 AsNotification 中的 event name                   │
│  ├─ 查询 UserNotification 表（用户订阅配置）                  │
│  ├─ 为每个用户构建 channels 数组                              │
│  └─ 调用 NotifierInterface::send() 委托发送                  │
└────────────────────────────┬────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                  Symfony Notifier (核心分发)                  │
│  ├─ 根据 channel 前缀路由到 chatter / texter / email        │
│  ├─ 触发 MessageEvent (NotificationOptionConfigurator 监听)  │
│  └─ 调用对应 TransportFactory 创建 transport 并发送           │
└───┬───────────────────┬────────────────────────┬────────────┘
    │                   │                        │
    ▼                   ▼                        ▼
┌─────────┐     ┌──────────────┐        ┌──────────────┐
│  Email  │     │ Chatter (聊天)│        │ Texter (短信) │
│  邮件    │     └──────┬───────┘        └──────┬───────┘
└─────────┘            │                       │
                       │  被装饰                │  被装饰
                       ▼                       ▼
              ┌──────────────────┐     ┌──────────────────┐
              │ Notification-    │     │ Notification-    │
              │ TransportFactory │     │ TransportFactory │
              └────────┬─────────┘     └────────┬─────────┘
                       │                        │
                       ▼                        ▼
              ┌──────────────────────────────────────┐
              │   Transports (自定义传输集合)         │
              │   从 TransportSetting 表动态加载      │
              │   key = Ulid (TransportSetting ID)   │
              └──────────────────────────────────────┘
```

---

## 二、通知消息定义与事件名

### 2.1 AsNotification 属性

**文件：** [AsNotification.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Attribute/AsNotification.php)

标记一个类为通知消息，其中 `name` 参数是**事件的唯一标识**：

```php
#[Attribute(Attribute::TARGET_CLASS)]
final class AsNotification
{
    public function __construct(
        public string $name,           // 事件名（唯一标识，对应 UserNotification.event 字段）
        public string $title = '',     // 显示名称
        public string $description = '', // 描述
        public string $icon = 'tabler:bell',
        public NotificationCategory $category = NotificationCategory::OTHER,
    ) {}
}
```

**自动注册：** 在 [SolidInvoiceNotificationExtension.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/DependencyInjection/SolidInvoiceNotificationExtension.php) 中，通过 `registerAttributeForAutoconfiguration` 自动给带 `#[AsNotification]` 的类打上 `solid_invoice_notification.notification` 标签。

### 2.2 NotificationMessage 基类

**文件：** [NotificationMessage.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Notification/NotificationMessage.php)

所有通知消息的抽象基类，同时实现邮件和聊天两种通知接口：

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

### 2.3 所有通知类型与触发入口对照表

| 通知类 | 事件名 | 分类 | 触发入口数量 | 触发入口文件 |
|--------|--------|------|-------------|-------------|
| [InvoiceStatusNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceStatusNotification.php) | `invoice_status_update` | INVOICE | **3 个** | ① [InvoiceManager::applyTransition()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php#L176-L196)<br>② [Invoice WorkFlowSubscriber](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/WorkFlowSubscriber.php#L51-L78)<br>③ [SendInvoiceReminderHandler](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php) 间接 |
| [InvoiceOverdueNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceOverdueNotification.php) | `invoice_overdue` | INVOICE | 1 个 | [InvoiceOverdueListener](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/InvoiceOverdueListener.php) |
| [InvoiceReminderNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderNotification.php) | `invoice_reminder` | INVOICE | 1 个 | [SendInvoiceReminderHandler](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php) |
| [InvoiceReminderStoppedNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderStoppedNotification.php) | `invoice_reminder_stopped` | INVOICE | 1 个 | [SendInvoiceReminderHandler](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php) |
| [QuoteStatusNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Notification/QuoteStatusNotification.php) | `quote_status_update` | QUOTE | **2 个** | ① [QuoteMailer::applyTransition()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Mailer/QuoteMailer.php#L40-L60)<br>② [Quote WorkFlowSubscriber](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Listener/WorkFlowSubscriber.php#L69-L90) |
| [PaymentReceivedNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/PaymentBundle/Notification/PaymentReceivedNotification.php) | `payment_made` | PAYMENT | 1 个 | [PaymentReceivedListener](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/PaymentBundle/Listener/PaymentReceivedListener.php) |
| [ClientCreateNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/ClientBundle/Notification/ClientCreateNotification.php) | `client_create` | CLIENT | 1 个 | [ClientListener](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/ClientBundle/Listener/ClientListener.php) |

> **重要概念区分：** 通知系统的"邮件通道"≠ 业务邮件发送。
> - **通知系统**：给**内部用户**发通知（状态变更提醒），走 `email` channel（走 Notifier → EmailNotification）
> - **业务邮件**：给**客户**发实体邮件（发票/报价 PDF），走 `MailerInterface::send()`（直接发 InvoiceEmail/QuoteEmail）

---

### 2.4 发票状态通知（invoice_status_update）触发入口详解

发票状态通知有 **3 个独立触发点**，分别对应不同的业务场景。

#### 2.4.1 InvoiceManager - 创建发票时

**文件：** [InvoiceManager::applyTransition()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php#L176-L196)

```php
private function applyTransition(Invoice $invoice): void
{
    if (! $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_NEW)) {
        throw new InvalidTransitionException(Graph::TRANSITION_NEW);
    }

    $oldStatus = $invoice->getStatus();
    $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_NEW);
    $newStatus = $invoice->getStatus();

    $parameters = [
        'invoice' => $invoice,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'transition' => Graph::TRANSITION_NEW, // 'new'
    ];

    $this->notification->sendNotification(new InvoiceStatusNotification($parameters));
}
```

- **触发时机：** 创建发票时（`create()` → `applyTransition()`）
- **Transition：** `new`（从 New 状态转换到下一状态）
- **参数特点：** 包含 `old_status`、`new_status`、`transition` 完整上下文
- **调用者：** [InvoiceManager::create()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php#L154-L171)

#### 2.4.2 WorkFlowSubscriber - 工作流状态变更时

**文件：** [WorkFlowSubscriber](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/WorkFlowSubscriber.php#L43-L78)

```php
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
    $isNew = \in_array($invoice->getStatus(), [InvoiceStatus::New, InvoiceStatus::Draft], true);

    if (! $isNew) {
        $this->notification->sendNotification(
            new InvoiceStatusNotification(['invoice' => $invoice])
        );
    }
}
```

- **触发时机：** 任何工作流状态进入新状态时（所有 transition）
- **监听事件：** `workflow.invoice.entered`（所有状态）、`workflow.recurring_invoice.entered`
- **触发条件：** 状态不是 `New` 或 `Draft` 时才发送
- **参数特点：** 只传 `invoice`，没有 old_status/new_status/transition
- **覆盖的 transitions：** accept, pay, cancel, overdue, reopen, archive, activate 等（见 [Graph.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Model/Graph.php)）

#### 2.4.3 SendAction - 发送发票邮件时

**文件：** [Invoice Send Action](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Action/Transition/Send.php)

```php
public function __invoke(Request $request, Invoice $invoice): RedirectResponse
{
    // ... 邮件验证检查 ...
    
    if (InvoiceStatus::Pending !== $invoice->getStatus() 
        && $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_ACCEPT)) {
        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
    }

    $this->save($invoice);
    $this->mailer->send(new InvoiceEmail($invoice)); // 直接发业务邮件给客户
    
    // ⚠️ 注意：这里没有调用 NotificationManager
    // 通知是通过 WorkFlowSubscriber 间接触发的（accept 转换）
}
```

- **触发方式：** 间接触发（通过工作流 `accept` 转换触发 WorkFlowSubscriber）
- **业务动作：** 给客户发送发票邮件（业务邮件，不是通知系统的 email channel）
- **通知触发：** `apply('accept')` → WorkFlowSubscriber 监听 `entered` 事件 → 触发通知

> **关键区别：** 发票发送时，给客户的邮件是直接通过 `MailerInterface` 发送的业务邮件；
> 而给内部用户的通知是通过工作流事件间接触发的。

#### 2.4.4 发票状态通知触发汇总

| 触发入口 | 触发方式 | Transition | 参数完整度 | 通知给 |
|---------|---------|-----------|-----------|--------|
| InvoiceManager::create() | 直接调用 sendNotification | new | 完整（有 old/new status） | 内部用户 |
| WorkFlowSubscriber | 监听 workflow.entered | 所有状态变更 | 只有 invoice 对象 | 内部用户 |
| SendAction | 间接（通过工作流触发 WorkFlowSubscriber） | accept | 只有 invoice 对象 | 内部用户 |

---

### 2.5 报价状态通知（quote_status_update）触发入口详解

报价状态通知有 **2 个触发点**，设计模式与发票类似但有差异。

#### 2.5.1 QuoteMailer - 发送报价时

**文件：** [QuoteMailer::applyTransition()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Mailer/QuoteMailer.php#L40-L74)

```php
final class QuoteMailer
{
    private function applyTransition(Quote $quote): void
    {
        if (! $this->quoteStateMachine->can($quote, Graph::TRANSITION_SEND)) {
            throw new InvalidTransitionException(Graph::TRANSITION_SEND);
        }

        $oldStatus = $quote->getStatus();
        $this->quoteStateMachine->apply($quote, Graph::TRANSITION_SEND);
        $newStatus = $quote->getStatus();

        $parameters = [
            'quote' => $quote,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'transition' => Graph::TRANSITION_SEND, // 'send'
        ];

        $this->notification->sendNotification(new QuoteStatusNotification($parameters));
    }

    public function send(Quote $quote): Quote
    {
        if (QuoteStatus::Draft === $quote->getStatus()) {
            $this->applyTransition($quote); // 状态转换 + 通知
        } else {
            $this->mailer->send(new QuoteEmail($quote)); // 只重发邮件，不触发通知
        }
        return $quote;
    }
}
```

- **触发时机：** 报价从草稿状态发送时（状态转换 send）
- **Transition：** `send`（Draft → Pending）
- **参数特点：** 包含 `old_status`、`new_status`、`transition` 完整上下文
- **调用者：** [Quote Send Action](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Action/Transition/Send.php)
- **特殊逻辑：** 如果不是草稿状态（已发送过），只重发邮件，不触发通知

> **和发票的区别：** 报价发送是在 QuoteMailer 里**显式**调用通知；
> 而发票发送是通过 WorkFlowSubscriber **隐式**触发。

#### 2.5.2 WorkFlowSubscriber - 工作流状态变更时

**文件：** [QuoteBundle WorkFlowSubscriber](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Listener/WorkFlowSubscriber.php#L49-L90)

```php
public static function getSubscribedEvents(): array
{
    return [
        'workflow.quote.entered.accepted' => 'onQuoteAccepted',
        'workflow.quote.entered' => 'onWorkflowTransitionApplied',
    ];
}

public function onQuoteAccepted(Event $event): void
{
    $quote = $event->getSubject();
    // 接受报价时：触发通知 + 转换为发票
    $this->notification->sendNotification(new QuoteStatusNotification(['quote' => $quote]));
    // ... 创建发票逻辑 ...
}

public function onWorkflowTransitionApplied(Event $event): void
{
    $quote = $event->getSubject();
    $isNew = \in_array($quote->getStatus(), [QuoteStatus::New, QuoteStatus::Draft], true);

    if (! $isNew) {
        $this->notification->sendNotification(
            new QuoteStatusNotification(['quote' => $quote])
        );
    }
}
```

- **触发时机：** 报价工作流状态变更时
- **两个监听事件：**
  - `workflow.quote.entered.accepted` - 专门处理已接受状态（同时会创建发票）
  - `workflow.quote.entered` - 通用状态变更（除 New/Draft 外都触发）
- **参数特点：** 只传 `quote` 对象
- **覆盖的 transitions：** send, accept, decline, cancel, reopen, archive, publish 等

> **注意：** `send` 转换会被触发两次通知吗？
> 不会。QuoteMailer 里的 `applyTransition` 先执行了 `apply('send')`，
> 这会触发 WorkFlowSubscriber 的 `entered` 事件 → 发一次通知；
> 然后 QuoteMailer 又显式调用 `sendNotification` → 再发一次？
> 实际上需要看状态：Draft → send 转换后状态是 Pending，不是 New/Draft，
> 所以 WorkFlowSubscriber 会触发一次。加上 QuoteMailer 的显式调用，可能是两次。
> 这可能是一个需要注意的设计点。

#### 2.5.3 报价状态通知触发汇总

| 触发入口 | 触发方式 | Transition | 参数完整度 | 说明 |
|---------|---------|-----------|-----------|------|
| QuoteMailer::send() | 直接调用 sendNotification | send | 完整（有 old/new status） | 只有 Draft→Pending 时触发 |
| WorkFlowSubscriber (entered.accepted) | 监听 accepted 事件 | accept | 只有 quote 对象 | 客户接受报价时 |
| WorkFlowSubscriber (entered) | 监听所有 entered 事件 | 所有状态变更 | 只有 quote 对象 | 通用状态变更通知 |

---

### 2.6 发票 vs 报价：通知触发模式对比

| 对比项 | 发票 (Invoice) | 报价 (Quote) |
|--------|---------------|-------------|
| 发送操作的通知触发方式 | 间接（通过 WorkFlowSubscriber） | 直接（QuoteMailer 显式调用） |
| 创建时的通知触发方式 | 直接（InvoiceManager::applyTransition） | 没有专门的创建管理器 |
| 工作流监听器 | 只有通用 entered 监听 | 有 accepted 专门监听 + 通用 entered |
| 接受状态特殊处理 | 无（InvoiceMailerListener 只发业务邮件） | 有（创建发票 + 发通知） |
| 重发是否触发通知 | 不触发（SendAction 直接发邮件，不调用通知） | 不触发（QuoteMailer 非草稿状态只发邮件） |

---

### 2.7 其他通知触发入口

#### 2.7.1 支付完成通知

**监听器：** [PaymentReceivedListener](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/PaymentBundle/Listener/PaymentReceivedListener.php#L27-L43)

```php
public static function getSubscribedEvents(): array
{
    return [
        PaymentEvents::PAYMENT_COMPLETE => 'onPaymentCapture',
    ];
}

public function onPaymentCapture(PaymentEvent $event): void
{
    $this->notification->sendNotification(
        new PaymentReceivedNotification(['payment' => $event->getPayment()])
    );
}
```

**事件常量：** [PaymentEvents::PAYMENT_COMPLETE](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/PaymentBundle/Event/PaymentEvents.php#L18) = `'payment.complete'`

**事件派发位置：** 支付回调完成时（`PaymentBundle/Action/Done.php` 等）

#### 2.7.2 客户创建通知

**监听器：** [ClientListener](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/ClientBundle/Listener/ClientListener.php#L79-L90)

```php
#[AsDoctrineListener(Events::postPersist)]
public function postPersist(LifecycleEventArgs $event): void
{
    $entity = $event->getObject();
    if (! $entity instanceof Client) {
        return;
    }
    $this->notification->sendNotification(
        new ClientCreateNotification(['client' => $entity])
    );
}
```

**触发时机：** Doctrine `postPersist` 生命周期事件（新客户保存到数据库后）

#### 2.7.3 发票逾期通知

**监听器：** [InvoiceOverdueListener](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/InvoiceOverdueListener.php#L38-L59)

```php
public static function getSubscribedEvents(): array
{
    return [
        'workflow.invoice.entered.overdue' => 'onInvoiceOverdue',
    ];
}
```

**触发时机：** 发票进入 `overdue`（逾期）状态时

#### 2.7.4 发票催缴通知

**处理器：** [SendInvoiceReminderHandler](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php)

两种通知：
- `invoice_reminder` - 催缴提醒（Overdue7、Overdue30 等）
- `invoice_reminder_stopped` - 催缴停止（Overdue14）

**触发方式：** Messenger 消息处理器，由定时任务或手动触发

---

## 三、用户通知配置与 Channel 构建

### 3.1 用户通知配置实体

**实体：** [UserNotification.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Entity/UserNotification.php)

存储每个用户对每个事件的通知偏好：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | Ulid | 主键 |
| event | string | 事件名（与 AsNotification::name 对应） |
| email | bool | 是否通过邮件通知 |
| transports | Collection (n:n) | 通过哪些第三方传输通知（关联 TransportSetting） |
| user | User | 所属用户 |
| company | Company | 所属公司（多租户） |

**核心逻辑：** 每个用户、每个事件可以独立配置：
- 是否发邮件（email = true/false）
- 通过哪些已配置的第三方传输发送（Slack、Twilio 短信等）

### 3.2 传输设置实体

**实体：** [TransportSetting.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Entity/TransportSetting.php)

用户配置的传输实例存储在数据库中：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | Ulid | 主键（用于在 channels 中标识传输） |
| name | string | 用户自定义名称（如"团队 Slack"） |
| transport | string | 传输类型名（对应 Configurator::getName()，如 'Slack'） |
| settings | json | 具体配置参数（如 token, channel 等） |
| user | User | 所属用户 |
| company | Company | 所属公司 |

### 3.3 从用户配置到 Channels 的构建过程

**核心代码：** [NotificationManager::sendNotification()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php#L47-L103)

构建流程如下：

```php
public function sendNotification(NotificationMessage $message): void
{
    // 步骤 1: 反射获取事件名
    $attributes = (new ReflectionObject($message))->getAttributes(AsNotification::class);
    $event = $attributes[0]->getArguments()['name'];

    // 步骤 2: 查询所有订阅了该事件的用户配置
    $userNotifications = $this->userNotificationRepository->findBy(['event' => $event]);

    // 步骤 3: 为每个用户构建 channels 并发送
    foreach ($userNotifications as $userNotification) {
        $channels = [];

        // 3.1 添加邮件通道
        if ($userNotification->isEmail()) {
            $channels[] = 'email';
        }

        // 3.2 添加第三方传输通道
        foreach ($userNotification->getTransports() as $transport) {
            $configurator = $this->transportConfigurations->get($transport->getTransport());
            
            // 通道命名格式: {type}/{transportId}
            // type: chat (对应 chatter) 或 sms (对应 texter)
            // transportId: TransportSetting 的 Ulid 字符串
            $channels[] = sprintf(
                '%s/%s',
                match ($configurator::getType()) {
                    'texter' => 'sms',      // Configurator 类型 texter → channel 前缀 sms
                    'chatter' => 'chat',    // Configurator 类型 chatter → channel 前缀 chat
                    default => $configurator::getType(),
                },
                $transport->getId()->toString(),
            );
        }

        // 3.3 设置消息的 channels
        $message->channels($channels);

        // 3.4 委托给 Symfony Notifier 发送
        $this->notifier->send($message, new Recipient($user->getEmail(), $user->getMobile()));
    }
}
```

### 3.4 Channel 命名约定对照表

| UserNotification 配置 | Channel 名称示例 | 说明 |
|----------------------|-----------------|------|
| email = true | `'email'` | 邮件通道，走 Symfony Mailer |
| transport 类型为 chatter (如 Slack) | `'chat/01HQXYZ...'` | 聊天类传输，走 chatter transport factory |
| transport 类型为 texter (如 Twilio) | `'sms/01HQABC...'` | 短信类传输，走 texter transport factory |

**验证来源：** [NotificationManagerTest.php 测试用例](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Tests/NotificationManagerTest.php#L399-L408)

```php
self::assertSame(
    [
        'email',
        'chat/' . $transportSetting->getId()->toString(),
        'sms/' . $transportSetting2->getId()->toString(),
    ],
    $class->getChannels(new Recipient($email, ''))
);
```

---

## 四、传输配置加载机制

### 4.1 传输配置器 (Configurator)

**接口：** [ConfiguratorInterface.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Configurator/ConfiguratorInterface.php)

每个传输方式都有一个 Configurator 类，负责将数据库中的配置数组转换为 DSN 对象：

```php
#[AutoconfigureTag(ConfiguratorInterface::DI_TAG)]  // 自动打标签: notification.configurator
interface ConfiguratorInterface
{
    public const DI_TAG = 'notification.configurator';

    public static function getName(): string;      // 传输名称，如 'Slack'
    public static function getType(): string;      // 类型: 'chatter' 或 'texter'
    public function getForm(): string;             // 对应的表单类型类
    public function configure(array $config): Dsn; // 配置数组 → DSN 对象
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

### 4.2 传输方式静态目录

**文件：** [transports.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Resources/config/transports.php)

这是传输方式的"目录"，定义了系统支持的所有传输类型的元数据：

- **`texter`** (短信类，24种)：Twilio, Vonage, AllMySms, AmazonSns, Clickatell 等
- **`chatter`** (聊天类，14种)：Slack, Discord, Telegram, MicrosoftTeams, GoogleChat 等

每个传输包含：
- `package` - 对应的 Symfony Notifier 包名
- `dsn` - DSN 格式模板

### 4.3 传输工厂 (NotificationTransportFactory)

**类：** [NotificationTransportFactory.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Factory/NotificationTransportFactory.php)

核心方法 `fromStrings()` 从数据库动态加载所有传输配置：

```php
public function fromStrings(array $dsns): Transports
{
    $transports = [];

    // 遍历数据库中所有传输配置记录
    foreach ($this->transportSettingRepository->findAll() as $setting) {
        // 获取对应类型的 Configurator (通过 ServiceLocator 按名称查找)
        $configurator = $this->transportConfigurations->get($setting->getTransport());
        
        try {
            // 步骤 1: 用 Configurator 将 settings 数组转为 DSN 对象
            // 步骤 2: 用 Symfony 的 Transport 工厂根据 DSN 创建传输实例
            // 步骤 3: 以 TransportSetting 的 Ulid 为 key 存入数组
            $transports[$setting->getId()->toString()] = 
                $this->transport->fromDsnObject($configurator->configure($setting->getSettings()));
        } catch (UnsupportedSchemeException) {
            continue; // 忽略不支持的传输方式（对应包未安装）
        }
    }

    return new Transports($transports);
}
```

**关键点：**
- transport 的 key 是 `TransportSetting` 的 Ulid 字符串
- 这样可以通过 channel 名称中的 ID 直接定位到具体的传输实例

### 4.4 自定义 Transports 集合

**类：** [Transports.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Notification/Transports.php)

实现 `TransportInterface`，内部持有多个传输实例的集合：

```php
final class Transports implements TransportInterface
{
    /**
     * @param iterable<string, TransportInterface> $transports
     *   key: TransportSetting 的 Ulid 字符串
     *   value: 具体的传输实例（如 SlackTransport, TwilioTransport 等）
     */
    public function __construct(
        private readonly iterable $transports
    ) {}

    public function send(MessageInterface $message): SentMessage
    {
        // 获取消息指定的 transport 名称（即 Ulid）
        if (! $transport = $message->getTransport()) {
            // 未指定则遍历查找支持的
            foreach ($this->transports as $transport) {
                if ($transport->supports($message)) {
                    return $transport->send($message);
                }
            }
            throw new LogicException(...);
        }

        // 根据 transport 名称（Ulid）查找对应的传输实例
        if (! isset($this->transports[$transport])) {
            throw new InvalidArgumentException(sprintf(
                'The "%s" transport does not exist...',
                $transport
            ));
        }

        return $this->transports[$transport]->send($message);
    }
}
```

### 4.5 编译器 Pass 装饰模式

**类：** [NotificationTransportConfigCompilerPass.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/DependencyInjection/CompilerPass/NotificationTransportConfigCompilerPass.php)

在容器编译阶段，用 `NotificationTransportFactory` **装饰** Symfony 原生的 transport factory：

```php
public function process(ContainerBuilder $container): void
{
    foreach (['chatter.transport_factory', 'texter.transport_factory'] as $factory) {
        if (! $container->hasDefinition($factory)) {
            continue;
        }

        // 创建 NotificationTransportFactory 定义
        $definition = new Definition(NotificationTransportFactory::class);
        // 装饰原生 factory
        $definition->setDecoratedService($factory);
        // 注入被装饰的原生 factory
        $definition->addArgument(new Reference($factory . NotificationTransportFactory::class . '.inner'));
        $definition->setAutowired(true);
        
        $container->setDefinition($factory . NotificationTransportFactory::class, $definition);
    }
}
```

**作用：**
- 当 Notifier 需要通过 `chat/xxx` 或 `sms/xxx` channel 发送消息时
- 会调用对应的 transport factory 创建 transport
- 由于 factory 被装饰，实际调用的是 `NotificationTransportFactory`
- `NotificationTransportFactory` 从数据库加载配置，动态创建传输实例

---

## 五、分发器到传输工厂的路由关系

### 5.1 完整路由链路

```
NotificationManager::sendNotification()
        │
        ├─ 为每个用户构建 channels 数组
        │   例如: ['email', 'chat/01HQXYZ123', 'sms/01HQABC456']
        │
        └─ $notifier->send($message, $recipient)
              │
              ▼
        Symfony Notifier
              │
              ├─ 遍历 channels
              │
              ├─ 对 'email' channel:
              │   └─ 创建 EmailMessage → 走 mailer 发送
              │
              ├─ 对 'chat/xxx' channel:
              │   ├─ 提取 transport 名称: 'xxx' (即 Ulid)
              │   ├─ 调用 chatter.transport_factory 创建 transport
              │   │   (被 NotificationTransportFactory 装饰)
              │   ├─ NotificationTransportFactory::fromStrings()
              │   │   ├─ 从数据库加载所有 TransportSetting
              │   │   ├─ 用 Configurator 转 DSN → 创建传输实例
              │   │   └─ 返回 Transports 集合 (key = Ulid)
              │   └─ Transports::send() 按 Ulid 找到对应传输并发送
              │
              └─ 对 'sms/xxx' channel:
                  └─ (同上，走 texter.transport_factory)
```

### 5.2 Channel 名称解析机制

Symfony Notifier 内部通过 channel 名称前缀来决定使用哪个 transport factory：

| Channel 前缀 | 使用的 Factory | 消息类型 |
|-------------|---------------|---------|
| `email` | - (内置) | EmailMessage |
| `chat/` | `chatter.transport_factory` | ChatMessage |
| `sms/` | `texter.transport_factory` | SmsMessage |

** `/` 后面的部分** 作为 transport 名称，用于在 factory 创建的传输集合中查找具体传输。

在 SolidInvoice 中，这个名称就是 `TransportSetting` 实体的 **Ulid**。

### 5.3 消息发送前的配置处理

**事件监听器：** [NotificationOptionConfigurator.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/EventListener/NotificationOptionConfigurator.php)

监听 Symfony Notifier 的 `MessageEvent` 事件，在消息发送前进行处理：

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

        // 1. 翻译消息主题
        $message->subject($this->translator->trans($message->getSubject(), [], 'email'));

        // 2. 处理通知内容（渲染 Twig 模板）
        $notification = $message->getNotification();
        if ($notification instanceof NotificationMessage) {
            $notification->subject($this->translator->trans(...));
            $notification->content($notification->getTextContent($this->twig));
        }

        // 3. 设置消息特定选项（如 Slack 的 blocks 等）
        $this->setMessageOptions($message);
    }
}
```

### 5.4 消息选项引用解析

支持三种引用类型的动态解析（在 `resolveOptions()` 方法中递归处理）：

- **UrlRouteReference** - 路由 URL 生成（生成绝对 URL）
- **TranslationReference** - 翻译
- **TemplateReference** - Twig 模板渲染

**示例：** [ClientCreateNotification::getSlackOptions()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/ClientBundle/Notification/ClientCreateNotification.php#L82-L110) 中使用了这三种引用。

---

## 六、完整调用链路（以支付到账通知为例）

### 6.1 调用链路时序图

```
1. 用户完成支付
   │
   ▼
2. PaymentAction (Done.php / Prepare.php)
   │  派发 PaymentEvents::PAYMENT_COMPLETE ('payment.complete') 事件
   │
   ▼
3. PaymentReceivedListener::onPaymentCapture()
   │  调用 $notification->sendNotification(new PaymentReceivedNotification(['payment' => $payment]))
   │
   ▼
4. NotificationManager::sendNotification()
   │  ├─ 反射 PaymentReceivedNotification 类的 AsNotification 属性
   │  │   得到 event name = 'payment_made'
   │  │
   │  ├─ 从 UserNotification 表查询 event='payment_made' 的所有记录
   │  │
   │  └─ 遍历每个 UserNotification:
   │     ├─ 构建 channels:
   │     │  ├─ email 为 true → 添加 'email'
   │     │  └─ 遍历 transports:
   │     │     ├─ 类型是 chatter → 添加 'chat/{transportUlid}'
   │     │     └─ 类型是 texter → 添加 'sms/{transportUlid}'
   │     │
   │     ├─ $message->channels($channels)
   │     │
   │     └─ $notifier->send($message, $recipient)
   │
   ▼
5. Symfony Notifier
   │  ├─ 遍历 channels
   │  │
   │  ├─ 'email' channel:
   │  │   └─ 创建 EmailMessage → Mailer 发送
   │  │
   │  └─ 'chat/01HQXYZ' channel:
   │      ├─ transport name = '01HQXYZ'
   │      ├─ 调用 chatter.transport_factory
   │      │   (被 NotificationTransportFactory 装饰)
   │      ├─ NotificationTransportFactory::fromStrings()
   │      │   ├─ 查 TransportSetting 表所有记录
   │      │   ├─ 每个记录:
   │      │   │   └─ Configurator::configure(settings) → DSN → Transport
   │      │   └─ 返回 Transports 集合
   │      └─ Transports::send() → 按 '01HQXYZ' 找到 SlackTransport → 发送
   │
   ▼
6. 消息发送前触发 MessageEvent
   │  NotificationOptionConfigurator 处理:
   │  ├─ 翻译主题
   │  ├─ 渲染内容模板
   │  └─ 解析消息选项（URL、翻译、模板引用）
   │
   ▼
7. 实际传输发送 (Slack API / SMTP / Twilio API 等)
```

---

## 七、关键设计总结

### 7.1 设计亮点

1. **动态配置：** 传输配置存储在数据库中，支持运行时动态增删，无需修改代码或重启服务
2. **装饰器模式：** 通过 Compiler Pass 装饰原生 transport factory，无侵入地实现动态配置加载
3. **分层解耦：** Configurator、Factory、Transport 各司其职，新增传输方式只需添加 Configurator 和 Form
4. **基于属性：** 使用 `#[AsNotification]` 声明式定义通知类型，自动注册
5. **用户级订阅：** 每个用户可独立配置每个事件的通知方式（邮件 + 多种第三方传输）
6. **Symfony 生态：** 充分利用 Symfony Notifier 组件，支持 40+ 种传输方式

### 7.2 核心实体关系图

```
                    ┌───────────────────────┐
                    │   AsNotification      │
                    │   (属性定义)          │
                    │  - name (事件名)       │
                    └───────────┬───────────┘
                                │
                                │ 匹配 event 字段
                                ▼
┌───────────────────────┐      n:n      ┌───────────────────────┐
│   UserNotification    │──────────────│   TransportSetting    │
│   (用户订阅配置)       │              │   (传输设置)          │
│  - event (事件名)      │              │  - id (Ulid)          │
│  - email (bool)       │              │  - transport (类型名)  │
│  - user (用户)        │              │  - settings (JSON)     │
│  - company (公司)      │              │  - user (用户)         │
└───────────────────────┘              └───────────┬───────────┘
                                                    │
                                                    │ 通过 transport 名称
                                                    ▼
                                          ┌───────────────────────┐
                                          │   Configurator        │
                                          │   (传输配置器)        │
                                          │  - getName()          │
                                          │  - getType()          │
                                          │  - configure() → DSN  │
                                          └───────────────────────┘
                                                    │
                                                    ▼
                                          ┌───────────────────────┐
                                          │   Transport (Symfony) │
                                          │   (实际发送实现)        │
                                          └───────────────────────┘
```

### 7.3 扩展新传输方式的步骤

1. 创建 `XxxConfigurator` 类实现 `ConfiguratorInterface`
2. 创建 `XxxType` 表单类（用于前端配置）
3. 确保对应 Symfony Notifier 包已安装
4. （可选）在 `transports.php` 中添加元数据

### 7.4 新增通知类型的步骤

1. 创建 `XxxNotification` 类继承 `NotificationMessage`
2. 加上 `#[AsNotification(name: 'xxx_event', ...)]` 属性
3. 实现 `getTextContent()` 等方法
4. 在合适的事件监听器中调用 `$notificationManager->sendNotification(new XxxNotification(...))`
5. 用户在通知设置中配置该事件的通知方式
