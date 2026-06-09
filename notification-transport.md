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

### 2.3 所有通知类型总览

| 通知类 | 事件名 | 分类 | 直接调用 sendNotification 的位置 |
|--------|--------|------|--------------------------------|
| [InvoiceStatusNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceStatusNotification.php) | `invoice_status_update` | INVOICE | ① [InvoiceManager::applyTransition()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php#L195)<br>② [Invoice WorkFlowSubscriber::onWorkflowTransitionApplied()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/WorkFlowSubscriber.php#L76) |
| [InvoiceOverdueNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceOverdueNotification.php) | `invoice_overdue` | INVOICE | [InvoiceOverdueListener::onInvoiceOverdue()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/InvoiceOverdueListener.php#L56) |
| [InvoiceReminderNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderNotification.php) | `invoice_reminder` | INVOICE | [SendInvoiceReminderHandler::sendReminder()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L171) |
| [InvoiceReminderStoppedNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderStoppedNotification.php) | `invoice_reminder_stopped` | INVOICE | [SendInvoiceReminderHandler::sendReminder()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L191) |
| [QuoteStatusNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Notification/QuoteStatusNotification.php) | `quote_status_update` | QUOTE | ① [QuoteMailer::applyTransition()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Mailer/QuoteMailer.php#L59)<br>② [Quote WorkFlowSubscriber::onWorkflowTransitionApplied()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Listener/WorkFlowSubscriber.php#L88) |
| [PaymentReceivedNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/PaymentBundle/Notification/PaymentReceivedNotification.php) | `payment_made` | PAYMENT | [PaymentReceivedListener::onPaymentCapture()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/PaymentBundle/Listener/PaymentReceivedListener.php#L41) |
| [ClientCreateNotification](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/ClientBundle/Notification/ClientCreateNotification.php) | `client_create` | CLIENT | [ClientListener::postPersist()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/ClientBundle/Listener/ClientListener.php#L88) |

> **重要概念区分：** 通知系统的"邮件通道"≠ 业务邮件发送。
> - **通知系统**：给**内部用户**发通知（状态变更提醒），走 `email` channel（Notifier → EmailNotification）
> - **业务邮件**：给**客户**发实体邮件（发票/报价 PDF），走 `MailerInterface::send()`（直接发 InvoiceEmail/QuoteEmail）

---

### 2.4 链路一：发票状态通知（invoice_status_update）

#### 2.4.1 两个直接触发点

发票状态通知有 **2 个直接调用 `sendNotification` 的位置**，分别对应不同场景。

**触发点 1：InvoiceManager::applyTransition() - 创建发票时**

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
        'transition' => Graph::TRANSITION_NEW,
    ];

    $this->notification->sendNotification(new InvoiceStatusNotification($parameters));
}
```

- **触发时机：** 创建发票时（`create()` → `applyTransition()`）
- **Transition：** `new`
- **参数：** 完整（有 old_status、new_status、transition）
- **调用链：** `InvoiceManager::create()` → `applyTransition()` → `sendNotification()`

**触发点 2：WorkFlowSubscriber - 工作流状态变更时**

**文件：** [Invoice WorkFlowSubscriber::onWorkflowTransitionApplied()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/WorkFlowSubscriber.php#L43-L78)

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

- **触发时机：** 任何工作流状态进入新状态时
- **监听事件：** `workflow.invoice.entered`（所有状态）
- **触发条件：** 状态不是 `New` 或 `Draft`
- **参数：** 只有 invoice 对象
- **覆盖的 transitions：** accept, pay, cancel, overdue, reopen, archive, activate 等

#### 2.4.2 发送发票动作：间接触发

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
    $this->mailer->send(new InvoiceEmail($invoice)); // 业务邮件：给客户
    
    // 没有直接调用 NotificationManager
    // 通知通过 WorkFlowSubscriber 间接触发
}
```

- **触发方式：** 间接（通过 `accept` 转换 → 触发 `entered` 事件 → WorkFlowSubscriber）
- **业务动作：** 给客户发发票邮件（业务邮件，不是通知系统）
- **通知触发链路：** `SendAction` → `apply('accept')` → `workflow.invoice.entered` → `WorkFlowSubscriber` → `sendNotification()`

#### 2.4.3 触发汇总

| 位置 | 触发方式 | Transition | 参数 | 场景 |
|------|---------|-----------|------|------|
| InvoiceManager::applyTransition | 直接调用 | `new` | 完整 | 创建发票 |
| WorkFlowSubscriber | 监听 entered 事件 | 所有状态变更 | 只有 invoice | 状态变化（accept/pay/cancel 等）|
| SendAction | 间接触发（通过 WorkFlowSubscriber） | `accept` | 只有 invoice | 发送发票 |

> **是否重复？** 
> - 创建发票时：InvoiceManager 发一次，WorkflowSubscriber 也发一次 → **可能重复**
> - 发送发票时：只有 WorkFlowSubscriber 发一次 → 不重复
> - 其他状态变更：只有 WorkFlowSubscriber 发一次 → 不重复

---

### 2.5 链路二：发票提醒通知（invoice_reminder / invoice_reminder_stopped）

#### 2.5.1 唯一触发源：SendInvoiceReminderHandler

**文件：** [SendInvoiceReminderHandler::sendReminder()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L104-L212)

这是一个 Messenger 消息处理器，负责发送催缴提醒。

```php
private function sendReminder(Invoice $invoice, SendInvoiceReminderMessage $message, ...): void
{
    // 1. 幂等检查：是否已经发送过
    if ($this->reminderRepository->hasReminderBeenSent($invoice, $message->reminderType)) {
        return;
    }

    // 2. 给客户发业务邮件（催缴邮件）
    $email = new InvoiceReminderEmail($invoice, ...);
    try {
        $this->mailer->send($email);
        $emailSent = true;
    } catch (TransportExceptionInterface $e) {
        $failureReason = $e->getMessage();
    }

    // 3. 保存提醒记录
    $reminder = new InvoiceReminder();
    // ... 设置状态 ...
    $entityManager->persist($reminder);
    $entityManager->flush();

    // 4. 只有邮件发送成功时，才发内部通知
    if ($emailSent) {
        // 通知一：发票催缴提醒
        $this->notificationManager->sendNotification(
            new InvoiceReminderNotification([
                'invoice' => $invoice,
                'reminder_type' => $message->reminderType,
                'days_until_due' => $message->daysUntilDue,
            ])
        );

        // 通知二：最后一期催缴后，发"催缴停止"通知（只在 Overdue14 时触发）
        if ($message->reminderType === ReminderType::Overdue14) {
            $this->notificationManager->sendNotification(
                new InvoiceReminderStoppedNotification([
                    'invoice' => $invoice,
                    'days_overdue' => $daysOverdue,
                ])
            );
        }
    }
}
```

#### 2.5.2 两种通知事件

| 通知类 | 事件名 | 触发条件 | 说明 |
|--------|--------|---------|------|
| InvoiceReminderNotification | `invoice_reminder` | 每次催缴邮件发送成功后 | 催缴提醒通知 |
| InvoiceReminderStoppedNotification | `invoice_reminder_stopped` | 只有 Overdue14（最后一期）催缴后 | 催缴停止/升级通知 |

#### 2.5.3 关键特点

- **单一触发源**：只有 `SendInvoiceReminderHandler` 调用，没有其他触发点
- **不重复**：有幂等检查（`hasReminderBeenSent`），且两个通知是不同事件
- **有前置条件**：只有给客户的业务邮件发送成功后，才发内部通知
- **不触发发票状态通知**：只发 invoice_reminder / invoice_reminder_stopped，**不触发** invoice_status_update

---

### 2.6 链路三：报价状态通知（quote_status_update）

#### 2.6.1 两个直接触发点

报价状态通知有 **2 个直接调用 `sendNotification` 的位置**。

**触发点 1：QuoteMailer::applyTransition() - 发送报价时**

**文件：** [QuoteMailer::applyTransition()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Mailer/QuoteMailer.php#L40-L60)

```php
private function applyTransition(Quote $quote): void
{
    if (! $this->quoteStateMachine->can($quote, Graph::TRANSITION_SEND)) {
        throw new InvalidTransitionException(Graph::TRANSITION_SEND);
    }

    $oldStatus = $quote->getStatus();
    $this->quoteStateMachine->apply($quote, Graph::TRANSITION_SEND); // 触发 entered 事件
    $newStatus = $quote->getStatus();

    $parameters = [
        'quote' => $quote,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'transition' => Graph::TRANSITION_SEND,
    ];

    $this->notification->sendNotification(new QuoteStatusNotification($parameters)); // 第二次发通知？
}
```

- **触发时机：** 报价从 Draft 发送时
- **Transition：** `send`（Draft → Pending）
- **参数：** 完整（有 old_status、new_status、transition）
- **调用者：** [QuoteMailer::send()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Mailer/QuoteMailer.php#L65-L74)

**触发点 2：WorkFlowSubscriber::onWorkflowTransitionApplied() - 通用状态变更**

**文件：** [Quote WorkFlowSubscriber](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Listener/WorkFlowSubscriber.php#L49-L90)

```php
public static function getSubscribedEvents(): array
{
    return [
        'workflow.quote.entered.accepted' => 'onQuoteAccepted',
        'workflow.quote.entered' => 'onWorkflowTransitionApplied',
    ];
}

public function onWorkflowTransitionApplied(Event $event): void
{
    $quote = $event->getSubject();

    // archive 特殊处理
    if ($event->getTransition()?->getName() === QuoteGraph::TRANSITION_ARCHIVE) {
        $quote->archive();
    }

    $em = $this->registry->getManager();
    $em->persist($quote);
    $em->flush();

    // 如果是 send 转换，调用 QuoteMailer 发业务邮件
    if ($event->getTransition()?->getName() === QuoteGraph::TRANSITION_SEND) {
        $this->quoteMailer->send($quote);
    }

    // 非 New 状态都发通知
    if (QuoteStatus::New !== $quote->getStatus()) {
        $this->notification->sendNotification(new QuoteStatusNotification(['quote' => $quote]));
    }
}
```

- **触发时机：** 所有工作流状态变更
- **触发条件：** 状态不是 `New`
- **参数：** 只有 quote 对象

#### 2.6.2 ⚠️ Draft→Send 的重复通知问题

**关键发现：报价从 Draft 发送时，会触发两次通知！**

调用链路：

```
QuoteMailer::send()  (状态 = Draft)
    │
    └─ applyTransition()
          │
          ├─ $workflow->apply($quote, 'send')
          │     │
          │     └─ 触发 workflow.quote.entered 事件
          │           │
          │           ▼
          │     WorkFlowSubscriber::onWorkflowTransitionApplied()
          │           │
          │           ├─ transition = 'send' → 调用 quoteMailer->send($quote)
          │           │     （此时状态 = Pending，不是 Draft）
          │           │     → 只发业务邮件，不触发通知 ✓
          │           │
          │           └─ 状态不是 New → sendNotification()  ← 第 1 次通知
          │
          └─ sendNotification(QuoteStatusNotification)  ← 第 2 次通知
```

**结论：** Draft → send 转换时，由于 QuoteMailer 和 WorkFlowSubscriber 都调用了 `sendNotification`，**会发送两次重复的 quote_status_update 通知**。

#### 2.6.3 触发汇总

| 触发入口 | 触发方式 | Transition | 参数 | 是否重复 |
|---------|---------|-----------|------|---------|
| QuoteMailer::applyTransition | 直接调用 | `send` | 完整 | Draft→Send 时会和 WorkFlowSubscriber 重复 |
| WorkFlowSubscriber::onWorkflowTransitionApplied | 监听 entered 事件 | 所有状态变更 | 只有 quote | - |
| （其他状态变更） | 监听 entered 事件 | accept/decline/cancel 等 | 只有 quote | 不重复 |

---

### 2.7 链路四：报价接受 → 转发票

#### 2.7.1 触发入口

**文件：** [Quote WorkFlowSubscriber::onQuoteAccepted()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/QuoteBundle/Listener/WorkFlowSubscriber.php#L57-L64)

```php
public function onQuoteAccepted(Event $event): void
{
    $quote = $event->getSubject();
    assert($quote instanceof Quote);
    
    // 创建发票
    $invoice = $this->invoiceManager->createFromQuote($quote);
    
    // 应用发票状态转换（这会触发发票状态通知）
    $this->invoiceStateMachine->apply($invoice, InvoiceGraph::TRANSITION_NEW);
}
```

> ⚠️ **代码核实：** `onQuoteAccepted()` **不直接调用** `sendNotification` 发报价状态通知！
> 它只做一件事：把报价转换成发票。
> 报价状态通知是由 `onWorkflowTransitionApplied()` 通用监听发的（因为 accepted 状态也不是 New）。

#### 2.7.2 完整调用链路

```
客户接受报价
    │
    ▼
workflow.quote.entered.accepted 事件
    │
    ├─ onQuoteAccepted() ← 专门监听 accepted
    │     │
    │     ├─ InvoiceManager::createFromQuote() → 创建发票
    │     └─ invoiceStateMachine->apply('new') → 触发发票状态通知
    │           ↓
    │       触发 invoice_status_update 通知
    │
    └─ onWorkflowTransitionApplied() ← 通用监听
          │
          └─ 状态不是 New → sendNotification()
                ↓
          触发 quote_status_update 通知
```

#### 2.7.3 两条通知同时触发

报价被接受时，会同时触发**两条独立的通知**：

| 通知事件 | 触发源 | 说明 |
|---------|-------|------|
| `quote_status_update` | `onWorkflowTransitionApplied()` 通用监听 | 报价状态变更通知 |
| `invoice_status_update` | `InvoiceManager::applyTransition()` | 新发票创建通知 |

#### 2.7.4 关键判断

- **onQuoteAccepted 本身不发报价通知** ✓（已核实代码，没有调用 sendNotification）
- **报价接受通知是由通用监听发出的** ✓（onWorkflowTransitionApplied 第 87-88 行）
- **报价接受会触发发票状态通知** ✓（通过 InvoiceManager::createFromQuote → applyTransition）

---

### 2.8 发票 vs 报价：通知触发模式对比

| 对比项 | 发票 (Invoice) | 报价 (Quote) |
|--------|---------------|-------------|
| 状态通知直接触发点 | 2 个（InvoiceManager + WorkFlowSubscriber） | 2 个（QuoteMailer + WorkFlowSubscriber） |
| 发送操作的通知 | 间接（通过 WorkFlowSubscriber） | 直接（QuoteMailer 显式调用） |
| 创建时的通知 | 有（InvoiceManager::applyTransition） | 无专门创建管理器 |
| 接受状态专门监听 | 无 | 有（onQuoteAccepted，但只转发票，不专门发通知） |
| Draft→Send 是否重复通知 | 不重复（SendAction 不直接调用通知） | **重复**（QuoteMailer + WorkFlowSubscriber 各发一次） |
| 提醒类通知 | 有（invoice_reminder / invoice_reminder_stopped） | 无 |
| 业务邮件和通知的关系 | 分离（各走各的） | 耦合（QuoteMailer 里同时处理） |

---

### 2.9 其他通知触发入口

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

## 六、完整调用链路详解

### 6.1 示例一：发送发票通知的完整链路

这是最典型的场景：用户点击"发送发票"按钮 → 工作流状态变更 → 通知系统分发到各传输通道。

```
  业务操作层          事件层            分发层              传输层
      │                 │                 │                   │
      │  点击发送按钮     │                 │                   │
      ├─────────────────►│                 │                   │
      │  Send Action     │                 │                   │
      │  (Transition/Send.php)            │                   │
      │                 │                 │                   │
      │  apply('accept') │                 │                   │
      ├─────────────────►│                 │                   │
      │                 │                 │                   │
      │                 │  workflow.      │                   │
      │                 │  invoice.       │                   │
      │                 │  entered        │                   │
      │                 ├────────────────►│                   │
      │                 │                 │                   │
      │                 │  WorkFlow-      │                   │
      │                 │  Subscriber     │                   │
      │                 │                 │                   │
      │                 │  检查状态:       │                   │
      │                 │  不是New/Draft  │                   │
      │                 │  → 触发通知      │                   │
      │                 │                 │                   │
      │                 │  sendNotification                   │
      │                 ├────────────────►│                   │
      │                 │                 │                   │
      │                 │                 │  Notification-    │
      │                 │                 │  Manager          │
      │                 │                 │                   │
      │                 │                 │  1. 反射获取事件名 │
      │                 │                 │     'invoice_     │
      │                 │                 │     status_update'│
      │                 │                 │                   │
      │                 │                 │  2. 查User-      │
      │                 │                 │     Notification表│
      │                 │                 │     (用户订阅配置) │
      │                 │                 │                   │
      │                 │                 │  3. 构建channels  │
      │                 │                 │     ['email',     │
      │                 │                 │      'chat/xxx']  │
      │                 │                 │                   │
      │                 │                 │  4. Notifier.send │
      │                 │                 ├───────────────────►
      │                 │                 │                   │
      │                 │                 │  Symfony Notifier │
      │                 │                 │                   │
      │                 │                 │  遍历 channels:   │
      │                 │                 │  ├─ 'email'       │
      │                 │                 │  │   → Mailer     │
      │                 │                 │  │   → SMTP       │
      │                 │                 │  │                │
      │                 │                 │  └─ 'chat/xxx'    │
      │                 │                 │      → chatter.   │
      │                 │                 │        transport_ │
      │                 │                 │        factory    │
      │                 │                 │                   │
      │                 │                 │      被装饰器拦截 │
      │                 │                 │      → 从数据库   │
      │                 │                 │        加载配置    │
      │                 │                 │      → Configurator
      │                 │                 │      → Transports │
      │                 │                 │      → Slack API  │
      │                 │                 │                   │
```

**关键节点对照代码：**

| 步骤 | 代码位置 | 说明 |
|------|---------|------|
| 发送按钮动作 | [Invoice/Action/Transition/Send.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Action/Transition/Send.php) | 执行 accept 转换 + 发业务邮件 |
| 工作流监听 | [WorkFlowSubscriber.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/InvoiceBundle/Listener/WorkFlowSubscriber.php) | 监听 entered 事件，触发通知 |
| 通知分发 | [NotificationManager::sendNotification()](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php#L47-L103) | 查用户配置 → 构建 channels → Notifier 发送 |
| 传输工厂装饰 | [NotificationTransportFactory.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/Factory/NotificationTransportFactory.php) | 从数据库加载传输配置 |
| 消息发送前处理 | [NotificationOptionConfigurator.php](file:///d:/fz/0508-2/solo-dogfeeding/code/116-SolidInvoice/src/NotificationBundle/EventListener/NotificationOptionConfigurator.php) | 翻译、模板渲染、选项解析 |

> **注意区分：**
> - 给**客户**的发票邮件：通过 `MailerInterface::send(new InvoiceEmail())` 直接发送（业务邮件）
> - 给**内部用户**的通知：通过 `NotificationManager::sendNotification()` 走通知系统（可能发邮件 + Slack + 短信等）

### 6.2 示例二：支付到账通知的完整链路

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
