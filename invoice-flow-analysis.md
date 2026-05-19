# SolidInvoice 发票状态与发送动作全链路代码分析报告

## 1. 状态机设计与边界规则

### 1.1 状态枚举定义

**文件**：`src/InvoiceBundle/Enum/InvoiceStatus.php`

```php
enum InvoiceStatus: string implements HasStatusLabel
{
    case New = 'new';           // 新建（初始状态）
    case Draft = 'draft';       // 草稿
    case Pending = 'pending';   // 待付款（已发送）
    case Paid = 'paid';         // 已付款
    case Active = 'active';     // 激活中（周期性发票）
    case Overdue = 'overdue';   // 已逾期
    case Cancelled = 'cancelled'; // 已取消
    case Archived = 'archived'; // 已归档
}
```

### 1.2 状态机配置

**文件**：`config/packages/workflow.php`

核心转换规则：

| 转换名称 | 起始状态 | 目标状态 | 触发场景 |
|---------|----------|----------|----------|
| `new` | New | Draft | 创建发票时初始化 |
| `accept` | New, Draft | Pending | 发送/发布发票 |
| `cancel` | Draft, Pending, Overdue | Cancelled | 取消发票 |
| `overdue` | Pending | Overdue | 逾期自动标记 |
| `pay` | Pending, Overdue | Paid | 付款完成 |
| `reopen` | Cancelled | Draft | 重新打开 |
| `archive` | New, Draft, Cancelled, Paid | Archived | 归档 |
| `edit` | Cancelled, Draft, Pending, Overdue | Draft | 编辑发票（配置定义，但代码中未主动调用） |

**关键边界规则**：
- `accept` 是发送动作的核心边界：只有 New/Draft 状态可以转换到 Pending
- `Paid` 状态没有出站转换，是最终状态之一
- `Archived` 是终态，不可逆转
- `edit` 转换在配置中定义，但在 Edit/Create 等业务入口中**未被主动调用**

### 1.3 状态转换服务层

**文件**：`src/InvoiceBundle/Service/InvoiceStatusTransitionService.php`

```php
public function applyTransition(BaseInvoice $invoice, string $transition): void
{
    // 边界检查：先验证转换是否允许
    if (! $this->invoiceStateMachine->can($invoice, $transition)) {
        throw new InvalidTransitionException($transition);
    }

    $this->invoiceStateMachine->apply($invoice, $transition);
    
    // 自动持久化
    $em = $this->registry->getManager();
    $em->persist($invoice);
    $em->flush();
}
```

**边界保护机制**：
- 前置 `can()` 检查确保转换合法性
- 不合法转换抛出 `InvalidTransitionException`
- 服务内部自动处理持久化，调用方无需关心

---

## 2. 发送动作完整调用链

### 2.1 发送动作的四个入口

#### 入口1：独立发送路由 (Send Action)

**文件**：`src/InvoiceBundle/Action/Transition/Send.php`
**路由**：`_send_invoice` → `/action/send/{id}`

```php
public function __invoke(Request $request, Invoice $invoice): RedirectResponse
{
    $route = $this->router->generate('_invoices_view', ['id' => $invoice->getId()]);

    // 边界1：邮箱验证闸门 ✅
    if ($this->emailVerificationGate->isGated()) {
        return new class($route) extends RedirectResponse implements FlashResponse {
            public function getFlash(): Generator
            {
                yield FlashResponse::FLASH_ERROR => 'email_verification.flash.send_invoice';
            }
        };
    }

    // 边界2：状态转换（核心边界）
    if (InvoiceStatus::Pending !== $invoice->getStatus() 
        && $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_ACCEPT)) {
        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
    }

    // 持久化
    $this->save($invoice);

    // 发送邮件
    $this->mailer->send(new InvoiceEmail($invoice));

    return new class($route) extends RedirectResponse implements FlashResponse {
        public function getFlash(): Generator
        {
            yield FlashResponse::FLASH_SUCCESS => 'invoice.transition.action.sent';
        }
    };
}
```

**关键特征**：
- ✅ 有邮箱验证闸门
- ✅ 有状态转换（幂等性保护）
- ❌ 无 CSRF 保护（GET 请求即可触发）
- ❌ 无异常处理（邮件发送失败直接抛出）
- ❌ 无日志记录

#### 入口2：创建时发送 (Create Action)

**文件**：`src/InvoiceBundle/Action/Create.php`

```php
if ($form->isSubmitted() && $form->isValid()) {
    $action = $request->request->get('save');

    // 转换为实体
    $invoice = $this->formManager->createInvoiceFromDTO($dto);

    // 初始化转换 New → Draft
    if (! $invoice->getId() instanceof Ulid) {
        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_NEW);
    }

    // 发布发票
    if ('send' === $action || 'publish' === $action) {
        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
    }

    $entityManager->persist($invoice);
    $entityManager->flush();

    // 仅在 'send' 动作时发送邮件
    if ('send' === $action) {
        $this->mailer->send(new InvoiceEmail($invoice));
    }
}
```

**关键特征**：
- ❌ **无邮箱验证闸门**（重要差异！）
- ✅ 有状态转换
- ✅ 有表单 CSRF 保护（通过 Symfony Form）
- ❌ 无异常处理

#### 入口3：编辑时发送 (Edit Action)

**文件**：`src/InvoiceBundle/Action/Edit.php`

```php
if ($form->isSubmitted() && $form->isValid()) {
    $action = $request->request->get('save');

    // 从 DTO 更新实体
    $this->formManager->updateInvoiceFromDTO($invoice, $dto);

    // 发布发票
    if ('send' === $action || 'publish' === $action) {
        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
    }

    $this->doctrine->getManager()->flush();

    // 仅在 'send' 动作时发送邮件
    if ('send' === $action) {
        $this->mailer->send(new InvoiceEmail($invoice));
    }
}
```

**关键特征**：
- ❌ **无邮箱验证闸门**（重要差异！）
- ⚠️ **编辑保存不会自动回退状态**（重要修正！）
- ✅ 有状态转换（仅当 action=send/publish 时）
- ✅ 有表单 CSRF 保护
- ❌ 无异常处理

> **重要修正**：编辑保存路径**不会**自动触发 `edit` 转换回退到 Draft。只有当用户明确选择 'send' 或 'publish' 动作时，才会应用 `accept` 转换。普通保存（action=save）不会改变发票状态。

#### 入口4：LiveComponent 发送

**文件**：`src/InvoiceBundle/Twig/Components/CreateInvoice.php`

```php
#[LiveAction]
public function saveSend(): ?Response
{
    // 边界1：邮箱验证闸门 ✅
    if ($this->emailVerificationGate->isGated()) {
        $this->addFlash('error', 'email_verification.flash.send_invoice');
        return null;
    }

    return $this->saveInvoice('send');
}

private function saveInvoice(string $action): ?Response
{
    $this->submitForm();

    if (! $this->getForm()->isValid()) {
        return null;
    }

    $dto = $this->getForm()->getData();

    if ($this->isEdit) {
        assert($this->invoice instanceof Invoice);
        $this->formManager->updateInvoiceFromDTO($this->invoice, $dto);

        if ('send' === $action || 'publish' === $action) {
            $this->invoiceStateMachine->apply($this->invoice, Graph::TRANSITION_ACCEPT);
        }

        $this->entityManager->flush();

        if ('send' === $action) {
            $this->mailer->send(new InvoiceEmail($this->invoice));
        }
    } else {
        // 创建逻辑...
    }
}
```

**关键特征**：
- ✅ 有邮箱验证闸门
- ✅ 有状态转换
- ✅ 有 CSRF 保护（LiveComponent 自动处理）
- ❌ 无异常处理

### 2.2 邮箱验证闸门差异对比表

| 发送入口 | 邮箱验证闸门 | 代码位置 |
|---------|------------|----------|
| Send Action (独立路由 `/action/send/{id}`) | ✅ 有 | `Send.php:46-53` |
| Create Action (创建时发送) | ❌ **无** | `Create.php:100-124` |
| Edit Action (编辑时发送) | ❌ **无** | `Edit.php:83-99` |
| CreateInvoice LiveComponent | ✅ 有 | `CreateInvoice.php:180-188` |
| SendManualReminder (手动催款) | ✅ 有 | `SendManualReminder.php:45-54` |

**风险提示**：Create/Edit Action 的 send 分支绕过了邮箱验证闸门，这可能是设计疏漏。

---

## 3. 三类邮件的处理逻辑与 PDF 附件对比

### 3.1 三类邮件总览

SolidInvoice 中有三类与发票相关的邮件，它们的处理逻辑、监听器触发和附件行为完全不同：

| 邮件类型 | 邮件类 | 使用场景 | PDF 附件 | 自动补主题 | 自动补收件人 | BCC 注入 |
|---------|--------|----------|----------|----------|------------|----------|
| **发票发送** | `InvoiceEmail` | 首次发送发票给客户 | ✅ **有**（InvoicePdfListener） | ✅ **有**（InvoiceSubjectListener） | ✅ **有**（InvoiceReceiverListener） | ✅ **有**（InvoiceReceiverListener） |
| **手动催款** | `ManualInvoiceReminderEmail` | 用户手动点击催款按钮 | ❌ **无** | ✅ **构造函数硬编码** | ✅ **构造函数硬编码** | ❌ **无** |
| **自动提醒** | `InvoiceReminderEmail` | 系统自动发送到期/逾期提醒 | ❌ **无** | ✅ **有**（ReminderSubjectListener） | ✅ **有**（ReminderReceiverListener） | ✅ **有**（ReminderReceiverListener） |

### 3.2 邮件监听器触发矩阵

| 监听器 | 监听类型 | InvoiceEmail | ManualInvoiceReminderEmail | InvoiceReminderEmail |
|--------|---------|-------------|---------------------------|---------------------|
| InvoicePdfListener (PDF 附件) | MessageEvent | ✅ 触发 | ❌ 跳过 | ❌ 跳过 |
| InvoiceSubjectListener (补主题) | MessageEvent | ✅ 触发 | ❌ 跳过 | ❌ 跳过 |
| InvoiceReceiverListener (补收件人+BCC) | MessageEvent | ✅ 触发 | ❌ 跳过 | ❌ 跳过 |
| ReminderSubjectListener (补主题) | MessageEvent | ❌ 跳过 | ❌ 跳过 | ✅ 触发 |
| ReminderReceiverListener (补收件人+BCC) | MessageEvent | ❌ 跳过 | ❌ 跳过 | ✅ 触发 |
| InvoiceMailerListener (事件驱动发送) | InvoiceEvents | ✅ 发送 | ❌ 不适用 | ❌ 不适用 |

### 3.3 第一类：发票发送邮件（InvoiceEmail）

**文件**：`src/InvoiceBundle/Email/InvoiceEmail.php`

```php
final class InvoiceEmail extends TemplatedEmail
{
    public function __construct(private readonly Invoice $invoice)
    {
        parent::__construct();
        $this->htmlTemplate('@SolidInvoiceInvoice/Email/invoice.html.twig');
        $this->context(['invoice' => $this->invoice]);
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
```

**特征**：
- ✅ 会被 `InvoicePdfListener` 识别并附加 PDF
- ✅ 支持所有四个发送入口（Send/Create/Edit/LiveComponent）
- ❌ 构造函数中**不设置**主题和收件人（通过监听器自动补全）

#### 专用监听器 1：InvoiceSubjectListener

**文件**：`src/InvoiceBundle/Listener/Mailer/InvoiceSubjectListener.php`

```php
public function __invoke(MessageEvent $event): void
{
    $message = $event->getMessage();

    // 触发条件：是 InvoiceEmail 实例 AND 主题为空
    if ($message instanceof InvoiceEmail && null === $message->getSubject()) {
        $message->subject(str_replace(
            '{id}', 
            (string) $message->getInvoice()->getInvoiceId(), 
            $this->config->get('invoice/email_subject')
        ));
    }
}
```

**触发条件**：
1. 类型检查：`$message instanceof InvoiceEmail`
2. 状态检查：`null === $message->getSubject()`（主题未设置）
3. 从系统配置 `invoice/email_subject` 读取模板，替换 `{id}` 占位符

#### 专用监听器 2：InvoiceReceiverListener

**文件**：`src/InvoiceBundle/Listener/Mailer/InvoiceReceiverListener.php`

```php
public function __invoke(MessageEvent $event): void
{
    $message = $event->getMessage();

    // 触发条件：是 InvoiceEmail 实例 AND 收件人为空
    if ($message instanceof InvoiceEmail && [] === $message->getTo()) {
        $invoice = $message->getInvoice();

        // 自动补全收件人：从发票关联的联系人
        foreach ($invoice->getUsers() as $user) {
            $message->addTo(new Address($user->getEmail(), 
                trim(sprintf('%s %s', $user->getFirstName(), $user->getLastName()))));
        }

        // BCC 注入：从系统配置 invoice/bcc_address 读取
        if ('' !== ($bcc = (string) $this->config->get('invoice/bcc_address'))) {
            $message->addBcc($bcc);
        }
    }
}
```

**触发条件**：
1. 类型检查：`$message instanceof InvoiceEmail`
2. 状态检查：`[] === $message->getTo()`（收件人为空数组）
3. BCC 注入条件：配置 `invoice/bcc_address` 不为空

**调用链路**：
```
[Action 层] → new InvoiceEmail($invoice) → $mailer->send()
    ↓
MessageEvent 触发
    ↓
[监听器组]
    ├─► InvoiceSubjectListener → 补主题（如果为空）
    ├─► InvoiceReceiverListener → 补收件人+BCC（如果收件人为空）
    └─► InvoicePdfListener → 附加 PDF
            ↓
发送
```

### 3.4 第二类：手动催款邮件（ManualInvoiceReminderEmail）

**文件**：`src/InvoiceBundle/Email/ManualInvoiceReminderEmail.php`

```php
final class ManualInvoiceReminderEmail extends TemplatedEmail
{
    public function __construct(private readonly Invoice $invoice)
    {
        parent::__construct();
        // 主题硬编码，不通过监听器
        $this->subject("Payment Reminder: Invoice {$invoice->getInvoiceId()}");
        $this->htmlTemplate('@SolidInvoiceInvoice/Email/manual_reminder.html.twig');
        $this->textTemplate('@SolidInvoiceInvoice/Email/manual_reminder.text.twig');
        $this->context(['invoice' => $this->invoice]);
        
        // 收件人硬编码，不通过监听器
        $this->to(...$this->invoice->getUsers()->map(
            fn (Contact $user) => Address::create(
                sprintf('%s %s <%s>', $user->getFirstName(), $user->getLastName(), $user->getEmail())
            )
        )->toArray());
    }
}
```

**调用入口**：`src/InvoiceBundle/Action/SendManualReminder.php:63`

```php
// 发送手动催款邮件
try {
    $this->mailer->send(new ManualInvoiceReminderEmail($invoice));
    // ...
} catch (TransportExceptionInterface $e) {
    // 异常处理
}
```

**特征**：
- ❌ **不会**附加 PDF（不是 `InvoiceEmail` 实例）
- ❌ **不会**触发任何监听器（主题和收件人在构造函数中硬编码）
- ❌ **没有** BCC 注入（没有监听器处理）
- ✅ 有 HTML 和纯文本双版本模板
- ✅ 有完整的异常处理和日志记录

**调用链路**：
```
用户点击"发送催款"
    ↓
[Action 层] SendManualReminder
    ├─► CSRF 验证 ✅
    ├─► 邮箱验证闸门 ✅
    ├─► 联系人存在性检查 ✅
    └─► new ManualInvoiceReminderEmail($invoice) → $mailer->send()
            ↓
MessageEvent 触发
    ↓
所有监听器检查类型：不是 InvoiceEmail 也不是 InvoiceReminderEmail，全部跳过 ❌
    ↓
发送（无 PDF 附件，无 BCC）
```

### 3.5 第三类：自动提醒邮件（InvoiceReminderEmail）

**文件**：`src/InvoiceBundle/Email/InvoiceReminderEmail.php`

```php
final class InvoiceReminderEmail extends TemplatedEmail
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly ReminderType $reminderType,
        private readonly ?int $daysUntilDue = null,
    ) {
        parent::__construct();
        $this->htmlTemplate('@SolidInvoiceInvoice/Email/reminder.html.twig');
        $this->textTemplate('@SolidInvoiceInvoice/Email/reminder.text.twig');
        $this->context([
            'invoice' => $this->invoice,
            'reminder_type' => $this->reminderType->value,
            'days_until_due' => $this->daysUntilDue,
        ]);
    }
}
```

**调用入口**：`src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php:127-133`

```php
// 发送邮件给客户联系人
$email = new InvoiceReminderEmail($invoice, $message->reminderType, $message->daysUntilDue);
try {
    $this->mailer->send($email);
    $emailSent = true;
} catch (TransportExceptionInterface $e) {
    // 异常处理
}
```

#### 专用监听器 1：ReminderSubjectListener

**文件**：`src/InvoiceBundle/Listener/Mailer/ReminderSubjectListener.php`

```php
public function __invoke(MessageEvent $event): void
{
    $message = $event->getMessage();

    // 触发条件：是 InvoiceReminderEmail 实例 AND 主题为空
    if ($message instanceof InvoiceReminderEmail && null === $message->getSubject()) {
        $invoice = $message->getInvoice();
        $invoiceId = $invoice->getInvoiceId();
        $reminderType = $message->getReminderType();

        $subject = match ($reminderType) {
            ReminderType::PreDue => "Upcoming Payment Due: Invoice {$invoiceId}",
            ReminderType::Overdue1 => "Payment Reminder: Invoice {$invoiceId}",
            ReminderType::Overdue7 => "Payment Overdue: Invoice {$invoiceId}",
            ReminderType::Overdue14 => "URGENT: Invoice {$invoiceId} - Immediate Action Required",
        };

        $message->subject($subject);
    }
}
```

**触发条件**：
1. 类型检查：`$message instanceof InvoiceReminderEmail`
2. 状态检查：`null === $message->getSubject()`（主题未设置）
3. 根据提醒类型生成不同主题

#### 专用监听器 2：ReminderReceiverListener

**文件**：`src/InvoiceBundle/Listener/Mailer/ReminderReceiverListener.php`

```php
public function __invoke(MessageEvent $event): void
{
    $message = $event->getMessage();

    // 触发条件：是 InvoiceReminderEmail 实例 AND 收件人为空
    if ($message instanceof InvoiceReminderEmail && [] === $message->getTo()) {
        $invoice = $message->getInvoice();

        // 自动补全收件人：从发票关联的联系人
        foreach ($invoice->getUsers() as $user) {
            $message->addTo(new Address($user->getEmail(), 
                trim(sprintf('%s %s', $user->getFirstName(), $user->getLastName()))));
        }

        // BCC 注入：从系统配置 invoice/bcc_address 读取
        if ('' !== ($bcc = (string) $this->config->get('invoice/bcc_address'))) {
            $message->addBcc($bcc);
        }
    }
}
```

**触发条件**：
1. 类型检查：`$message instanceof InvoiceReminderEmail`
2. 状态检查：`[] === $message->getTo()`（收件人为空数组）
3. BCC 注入条件：配置 `invoice/bcc_address` 不为空

**完整调用链路**：
```
[定时任务] SendInvoiceRemindersCommand
    ↓
[消息队列] SendInvoiceReminderMessage
    ↓
[消息处理器] SendInvoiceReminderHandler
    ├─► 公司上下文切换
    ├─► SaaS 功能闸门检查
    ├─► 公司级提醒开关检查
    ├─► 幂等性检查（该类型提醒是否已发送）
    ├─► 联系人存在性检查
    ├─► new InvoiceReminderEmail(...)
    ├─► $mailer->send()
    │       ↓
    │   MessageEvent 触发
    │       ↓
    │   [监听器组]
    │       ├─► ReminderSubjectListener → 补主题（如果为空）
    │       ├─► ReminderReceiverListener → 补收件人+BCC（如果收件人为空）
    │       └─► InvoicePdfListener → 类型不匹配，跳过 ❌
    │
    ├─► 保存 InvoiceReminder 记录（Sent/Failed）
    └─► 发送内部通知（可选）
```

### 3.6 PDF 附件的触发条件

**关键代码**：`src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php:45`

```php
public function __invoke(MessageEvent $event): void
{
    $message = $event->getMessage();

    // ⚠️  重要：只对 InvoiceEmail 实例附加 PDF
    if ($message instanceof InvoiceEmail && $this->generator->canPrintPdf()) {
        $content = $this->generator->generate(
            $this->twig->render('@SolidInvoiceInvoice/Pdf/invoice.html.twig', 
                ['invoice' => $message->getInvoice()]
            )
        );

        $message->attach($content, 
            "invoice_{$message->getInvoice()->getInvoiceId()}.pdf", 
            'application/pdf'
        );
    }
}
```

**触发条件解析**：
1. **类型检查**：`$message instanceof InvoiceEmail` —— 只有 `InvoiceEmail` 会被处理
2. **功能检查**：`$this->generator->canPrintPdf()` —— 检查 mbstring 和 gd 扩展是否可用
3. **两者必须同时满足**才会附加 PDF

> **关键修正**：`ManualInvoiceReminderEmail` 和 `InvoiceReminderEmail` 都不会触发 `InvoicePdfListener` 的 PDF 附加逻辑，因为类型检查不通过。

### 3.7 BCC 注入路径对比

| 邮件类 | BCC 注入方式 | 注入位置 | 配置项 |
|--------|-------------|----------|--------|
| InvoiceEmail | ✅ 通过监听器注入 | InvoiceReceiverListener | `invoice/bcc_address` |
| ManualInvoiceReminderEmail | ❌ 无任何 BCC 注入 | N/A | N/A |
| InvoiceReminderEmail | ✅ 通过监听器注入 | ReminderReceiverListener | `invoice/bcc_address` |

> **关键修正**：手动催款（`ManualInvoiceReminderEmail`）是唯一不会注入 BCC 的邮件类型，因为它没有对应的监听器处理，且构造函数中也没有硬编码 BCC。

---

## 4. 模板渲染上下文数据来源

### 4.1 邮件模板上下文

三类邮件都直接传递 `Invoice` 实体对象给模板：

| 邮件类 | 模板路径 | 上下文数据 |
|--------|----------|-----------|
| InvoiceEmail | `@SolidInvoiceInvoice/Email/invoice.html.twig` | `['invoice' => $invoice]` |
| ManualInvoiceReminderEmail | `@SolidInvoiceInvoice/Email/manual_reminder.html.twig` | `['invoice' => $invoice]` |
| InvoiceReminderEmail | `@SolidInvoiceInvoice/Email/reminder.html.twig` | `['invoice' => $invoice, 'reminder_type' => ..., 'days_until_due' => ...]` |

### 4.2 邮件模板数据来源详解

**模板**：`src/InvoiceBundle/Resources/views/Email/invoice.html.twig`

| 数据项 | 来源路径 | 说明 |
|--------|----------|------|
| **发票标识** | | |
| 发票ID | `invoice.invoiceId` | 实体属性 |
| UUID | `invoice.uuid` | 用于生成外部链接 |
| **客户信息** | | |
| 客户名称 | `invoice.client.name` | 关联 Client 实体 |
| 客户货币 | `invoice.client.currency` | 用于金额格式化 |
| **日期信息** | | |
| 创建日期 | `invoice.created` | TimeStampable Trait |
| 发票日期 | `invoice.invoiceDate` | 实体属性 |
| 到期日期 | `invoice.due` | 实体属性 |
| **金额信息** | | |
| 小计 | `invoice.baseTotal` | BaseInvoice 基类 |
| 税额 | `invoice.tax` | BaseInvoice 基类 |
| 折扣 | `invoice.discount` | 嵌入式 Discount 对象 |
| 总金额 | `invoice.total` | BaseInvoice 基类 |
| 余额 | `invoice.balance` | Invoice 实体 |
| **付款信息** | | |
| 付款记录 | `invoice.payments` | 关联 Payment 集合 |
| 付款状态过滤 | `payment.status == PaymentStatus::Captured` | 只显示已捕获付款 |
| 付款方式 | `payment.method.name` | 关联 PaymentMethod |
| **其他信息** | | |
| 条款 | `invoice.terms` | 实体属性 |
| 备注 | `invoice.notes` | 实体属性 |
| 公司名称 | `setting('system/company/company_name')` | Twig 全局函数，读取系统设置 |
| 支付配置 | `payments_configured(false)` | Twig 全局函数 |

### 4.3 PDF 模板上下文

**模板**：`src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig`

PDF 模板使用与邮件模板相同的上下文数据 (`invoice` 实体)，但包含更多 PDF 特定的渲染逻辑：

| 数据项 | 来源 | 说明 |
|--------|------|------|
| **公司信息（PDF页眉）** | | |
| Logo | `setting('system/company/logo')` | 系统设置 |
| 公司名称 | `company_name()` | Twig 全局函数 |
| VAT 税号 | `setting('system/company/vat_number')` | 系统设置 |
| 联系邮箱 | `setting('system/company/contact_details/email')` | 系统设置 |
| 联系电话 | `setting('system/company/contact_details/phone_number')` | 系统设置 |
| 公司地址 | `setting('system/company/contact_details/address')` | 系统设置 |
| **状态水印** | | |
| 水印文本 | `invoice.status.value` | 状态枚举值大写 |
| 水印开关 | `setting('invoice/watermark')` | 系统设置 |
| **逾期计算** | | |
| 到期计算 | `daysDiff = (dueTimestamp - now) / 86400` | 模板内计算 |
| 逾期状态 | `isOverdue = daysDiff < 0` | 模板内计算 |
| 逾期天数 | `daysOverdue = daysDiff * -1` | 模板内计算 |

### 4.4 上下文数据组装流程图

```
Invoice 实体
    ├─► 基本属性 (id, invoiceId, uuid, status, invoiceDate, due, paidDate)
    ├─► 金额属性 (total, baseTotal, tax, balance)
    ├─► 关联对象
    │   ├─► Client (name, currency, vatNumber, addresses)
    │   ├─► User/Contact 集合 (email 等)
    │   ├─► Line 集合 (description, price, qty, tax, total)
    │   ├─► Payment 集合 (status, method, totalAmount)
    │   └─► Company (通过 CompanyAware Trait)
    ├─► 嵌入式对象
    │   └─► Discount (type, value)
    ├─► Trait 注入
    │   ├─► Archivable (archived)
    │   ├─► TimeStampable (created, updated)
    │   └─► CompanyAware (company)
    └─► 动态计算
        ├─► Twig 过滤器：formatCurrency, date, nl2br
        ├─► Twig 函数：setting, company_name, payments_configured, url
        └─► 模板内逻辑：逾期天数计算、余额判断
```

---

## 5. 异常路径分析

### 5.1 重复发送场景

**核心代码**：`Send.php:55-57`

```php
if (InvoiceStatus::Pending !== $invoice->getStatus() 
    && $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_ACCEPT)) {
    $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
}
```

**异常路径矩阵**：

| 场景 | 当前状态 | 状态转换 | 邮件发送 | 结果 |
|------|----------|----------|----------|------|
| 正常首次发送 | Draft | ✅ 执行 accept → Pending | ✅ 发送（带 PDF，带 BCC） | 正常流程 |
| 重复点击发送 | Pending | ❌ 跳过（已在 Pending） | ✅ 仍发送（带 PDF，带 BCC） | 状态不变，邮件重复发送 ⚠️ |
| 已取消发票重发 | Cancelled | ❌ 跳过（can() 返回 false） | ✅ 仍发送（带 PDF，带 BCC） | 邮件发送，但状态不变 ⚠️ |
| 已支付发票 | Paid | ❌ 跳过（can() 返回 false） | ✅ 仍发送（带 PDF，带 BCC） | 邮件发送，但状态不变 ⚠️ |
| 已逾期发票 | Overdue | ❌ 跳过（can() 返回 false） | ✅ 仍发送（带 PDF，带 BCC） | 邮件发送，但状态不变 ⚠️ |

**风险点**：
- 状态检查只保护了状态转换，未阻止邮件发送
- Pending 状态的发票可以无限次重复发送邮件
- 缺乏发送次数限制或发送日志记录
- 没有"已发送"标记来防止重复发送

### 5.2 草稿修改场景

**核心代码**：`Edit.php:58-64, 83-99`

```php
// 仅禁止已支付发票的编辑
if (InvoiceStatus::Paid === $invoice->getStatus()) {
    $session->getFlashBag()->add('warning', 'invoice.edit.paid');
    return new RedirectResponse($this->router->generate('_invoices_index'));
}

// 编辑保存逻辑（注意：没有 edit 转换调用）
if ($form->isSubmitted() && $form->isValid()) {
    $action = $request->request->get('save');
    $this->formManager->updateInvoiceFromDTO($invoice, $dto);

    // 只有 send/publish 才触发状态转换
    if ('send' === $action || 'publish' === $action) {
        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
    }

    $this->doctrine->getManager()->flush();
}
```

**异常路径矩阵**（**修正版**）：

| 场景 | 当前状态 | 编辑权限 | 状态变化 | 结果 |
|------|----------|----------|----------|------|
| 正常修改草稿 | Draft | ✅ 允许 | → Draft（不变） | 正常修改 |
| 修改待付款发票（普通保存） | Pending | ✅ 允许 | → Pending（**保持不变**） | 内容已修改，状态未变 ⚠️ |
| 修改待付款发票（选择发送） | Pending | ✅ 允许 | → Pending（accept 转换无变化） | 内容已修改，重新发送邮件 |
| 修改已逾期发票（普通保存） | Overdue | ✅ 允许 | → Overdue（**保持不变**） | 内容已修改，逾期状态保留 ✅ |
| 修改已逾期发票（选择发送） | Overdue | ✅ 允许 | → Overdue（can() 返回 false） | 内容已修改，邮件发送，但状态仍为 Overdue |
| 修改已取消发票 | Cancelled | ✅ 允许 | → Cancelled（**保持不变**） | 内容已修改，取消状态保留 |
| 修改已支付发票 | Paid | ❌ 拒绝 | ❌ 不适用 | 重定向到列表页，保护机制生效 |

> **重要修正**：编辑保存**不会**自动触发 `edit` 转换回退到 Draft。状态机配置中的 `edit` 转换定义了 `Cancelled/Draft/Pending/Overdue → Draft` 的路径，但在 Edit/CreateInvoice 等业务入口中并未调用此转换。普通保存时状态保持原样，只有明确选择 send/publish 时才会尝试应用 `accept` 转换。

**风险点**：
- Pending 状态发票可被编辑，可能导致已发送内容与实际不符
- 缺乏修改历史记录或版本追踪
- 没有"锁定"机制防止已发送发票被修改

### 5.3 直接发送 vs 手动催款对比

**直接发送**：`src/InvoiceBundle/Action/Transition/Send.php`
**手动催款**：`src/InvoiceBundle/Action/SendManualReminder.php`

| 维度 | 直接发送 (Send Action) | 手动催款 (SendManualReminder) |
|------|----------------------|--------------------------|
| **请求防护** | | |
| HTTP 方法 | 无限制（GET 即可触发） | 限制为 POST 方法 |
| CSRF 保护 | ❌ 无 | ✅ 有 (`isCsrfTokenValid`) |
| 邮箱验证闸门 | ✅ 有 | ✅ 有 |
| 联系人检查 | ❌ 无 | ✅ 有 (`getUsers()->isEmpty()`) |
| **副作用边界** | | |
| 状态转换 | ✅ 触发 accept 转换 | ❌ 无状态变更 |
| 持久化 | ✅ 保存发票实体 | ❌ 不修改不保存 |
| 日志记录 | ❌ 无 | ✅ 有 (info/error) |
| 异常处理 | ❌ 无（直接抛出） | ✅ 有 try-catch TransportException |
| **邮件特征** | | |
| 邮件类型 | `InvoiceEmail` | `ManualInvoiceReminderEmail` |
| 模板 | `@SolidInvoiceInvoice/Email/invoice.html.twig` | `@SolidInvoiceInvoice/Email/manual_reminder.html.twig` |
| PDF 附件 | ✅ **有**（通过 InvoicePdfListener） | ❌ **无**（重要修正！） |
| 主题设置 | ✅ 通过 InvoiceSubjectListener 自动补全 | ✅ 构造函数硬编码 |
| 收件人设置 | ✅ 通过 InvoiceReceiverListener 自动补全 | ✅ 构造函数硬编码 |
| BCC 注入 | ✅ 通过 InvoiceReceiverListener 注入 | ❌ **无**（重要修正！） |

**设计意图差异**：
- **直接发送**：用于首次发送发票，会改变状态，操作相对"重"，包含 PDF 附件和 BCC 抄送
- **手动催款**：用于后续提醒，不改变状态，操作相对"轻"，有更完善的防护和日志，但**不包含 PDF 附件，也没有 BCC 抄送**

### 5.4 自动提醒的异常路径

**核心代码**：`SendInvoiceReminderHandler.php:107-114`

```php
// 幂等性保护：检查该类型提醒是否已发送
if ($this->reminderRepository->hasReminderBeenSent($invoice, $message->reminderType)) {
    $this->logger->info('Reminder already sent, skipping duplicate creation', [...]);
    return;
}
```

**异常路径矩阵**：

| 场景 | 处理结果 |
|------|----------|
| SaaS 自动化提醒功能禁用 | 跳过，记录日志，不重试 |
| 公司级提醒开关关闭 | 跳过，记录日志，不重试 |
| 发票不存在 | 跳过，记录警告日志 |
| 发票无联系人 | 跳过，记录警告日志 |
| 该类型提醒已发送过 | 跳过，幂等性保护生效 ✅ |
| 邮件发送失败 | 标记为 Failed，记录失败原因，不重试 |

**自动提醒的保护机制**：
- ✅ 多层功能闸门（SaaS + 公司级）
- ✅ 幂等性保护（同类型提醒只发一次）
- ✅ 发送状态追踪（InvoiceReminder 实体记录 Sent/Failed）
- ✅ 完整的异常处理和日志记录
- ✅ 有 BCC 抄送（通过 ReminderReceiverListener）
- ❌ 不包含 PDF 附件

### 5.5 API 层面异常处理

**文件**：`src/ApiBundle/State/Processor/InvoiceTransitionProcessor.php`

```php
public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Invoice
{
    assert($data instanceof Invoice);

    $transition = (string) ($context['request']?->attributes->get('transition') ?? '');

    if (! $this->invoiceStateMachine->can($data, $transition)) {
        throw new UnprocessableEntityHttpException(
            sprintf('Transition "%s" cannot be applied to invoice in status "%s".', 
                $transition, 
                $data->getStatus()?->value ?? 'unknown'
            )
        );
    }

    $this->invoiceStateMachine->apply($data, $transition);
    $this->registry->getManager()->flush();

    return $data;
}
```

**API 异常处理特点**：
- 返回 422 Unprocessable Entity 状态码
- 包含明确的错误信息，说明哪个状态不允许哪个转换
- 与 Web 界面的异常处理一致（都依赖状态机的 can() 检查）

### 5.6 邮件发送失败处理

**文件**：`src/InvoiceBundle/Listener/Mailer/InvoiceMailerListener.php`

```php
try {
    $this->mailer->send(new InvoiceEmail($invoice));
} catch (TransportExceptionInterface $e) {
    $this->logger->error('Failed to send invoice email: ' . $e->getMessage(), [
        'exception' => $e,
    ]);
    $this->addFlashError('invoice.email.send_failed');
}
```

**处理逻辑**：
- 捕获 `TransportExceptionInterface` 异常
- 记录错误日志（包含异常信息）
- 添加 Flash 错误消息提示用户
- **注意**：状态转换已经完成（Pending），但邮件发送失败，状态不会回滚

### 5.7 多租户安全边界

**测试验证**：`src/InvoiceBundle/Tests/Functional/Api/InvoiceTransitionTest.php`

```php
public function testTransitionOnForeignCompanyInvoice(): void
{
    // 切换到其他公司创建发票
    $otherCompany = CompanyFactory::new()->create();
    self::getContainer()->get(CompanySelector::class)->switchCompany($otherCompany->getId());
    $foreignClient = ClientFactory::createOne(['company' => $otherCompany]);
    $foreignInvoice = InvoiceFactory::createOne([
        'client' => $foreignClient,
        'status' => InvoiceStatus::Draft,
    ])->_real();
    
    // 切回原公司尝试操作
    self::getContainer()->get(CompanySelector::class)->switchCompany($this->company->getId());

    self::$client->request('POST', sprintf('/api/invoices/%s/transitions/accept', $foreignInvoice->getId()), [...]);

    // 期望结果：404 Not Found
    static::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
}
```

**安全机制**：
- 通过 Doctrine Filter（CompanyFilter）自动实现数据隔离
- 用户只能看到和操作所属公司的发票
- 跨公司操作返回 404，不暴露资源存在性

---

## 6. 架构总结

### 6.1 架构优点

1. **清晰的边界划分**：状态机负责状态合法性，Action 负责业务流程，Listener 负责副作用
2. **统一的转换入口**：`InvoiceStatusTransitionService` 提供了可复用的状态转换服务
3. **事件驱动设计**：PDF 附件生成、主题/收件人补全通过事件监听解耦
4. **多租户安全**：通过 Doctrine Filter 自动实现公司级数据隔离
5. **幂等性设计**：状态转换前检查当前状态，避免重复转换
6. **多层验证**：CSRF 保护（表单/LiveComponent）、邮箱验证闸门、状态机验证形成多重防护
7. **自动提醒完善**：自动提醒有完整的幂等性保护、状态追踪和异常处理
8. **监听器解耦**：每类邮件有专用监听器，职责单一，易于扩展

### 6.2 关键设计决策

| 决策 | 说明 | 影响 |
|------|------|------|
| 直接传递实体到模板 | 不使用 DTO，模板直接访问 Invoice 实体 | 简单但耦合度高，模板可访问所有实体属性 |
| 状态转换与邮件发送解耦 | 状态转换成功后才发送邮件 | 邮件发送失败不影响状态，但可能导致状态与实际不一致 |
| 多入口发送 | Send/Create/Edit/LiveComponent 都可以触发发送 | 代码有重复，且邮箱验证闸门不一致 |
| Paid 状态为终态 | 已支付发票不允许编辑 | 保护财务数据一致性 |
| edit 转换配置但不调用 | 状态机定义了 edit 转换，但业务代码未使用 | 配置与实现脱节，可能造成困惑 |
| 仅 InvoiceEmail 附加 PDF | 只有首次发送邮件带 PDF，催款/提醒不带 | 减少不必要的 PDF 生成，但可能不符合用户预期 |
| 手动催款无监听器 | 主题和收件人在构造函数中硬编码 | 简单直接，但失去了监听器的灵活性，也没有 BCC |
| 三类邮件独立监听器 | 每类邮件有专用监听器处理主题和收件人 | 职责清晰，但代码有重复（InvoiceReceiverListener 和 ReminderReceiverListener 逻辑几乎相同） |

### 6.3 潜在风险与改进建议

| 风险点 | 严重程度 | 改进建议 |
|--------|----------|----------|
| Create/Edit 发送无邮箱验证闸门 | 高 | 在 Create/Edit 的 send 分支添加邮箱验证检查，保持与其他入口一致 |
| 重复发送邮件无限制 | 中 | 添加发送日志，限制发送频率或次数；增加"已发送"标记 |
| Pending 状态可编辑 | 中 | 考虑将 Pending 状态设为只读，或要求明确的"重新编辑"操作并记录 |
| 编辑保存状态不回退 | 中 | 明确设计意图：要么调用 edit 转换回退到 Draft，要么禁止编辑非 Draft 状态 |
| 手动催款无 PDF 附件 | 中 | 根据业务需求决定是否为手动催款也添加 PDF 附件 |
| 手动催款无 BCC 注入 | 中 | 为 ManualInvoiceReminderEmail 添加专用监听器，或在构造函数中支持 BCC |
| 自动提醒无 PDF 附件 | 低 | 可配置是否为自动提醒添加 PDF 附件 |
| 缺乏修改历史 | 低 | 考虑添加实体版本追踪或审计日志 |
| 邮件发送失败状态不回滚 | 中 | 可考虑使用事务或补偿机制，或添加"发送失败"状态 |
| 两套邮件发送机制并行 | 低 | 统一使用事件驱动或统一直接调用，避免混淆 |
| 直接发送无 CSRF 保护 | 中 | 将 Send Action 改为 POST 方法并添加 CSRF 保护 |
| edit 转换配置与实现脱节 | 低 | 要么在编辑时调用 edit 转换，要么从配置中移除避免混淆 |
| 收件人监听器代码重复 | 低 | 提取公共逻辑到抽象基类或 Trait |

### 6.4 关键文件索引

| 功能模块 | 文件路径 |
|----------|----------|
| 状态枚举 | `src/InvoiceBundle/Enum/InvoiceStatus.php` |
| 状态机配置 | `config/packages/workflow.php` |
| 状态转换服务 | `src/InvoiceBundle/Service/InvoiceStatusTransitionService.php` |
| 发送动作（独立路由） | `src/InvoiceBundle/Action/Transition/Send.php` |
| 创建动作 | `src/InvoiceBundle/Action/Create.php` |
| 编辑动作 | `src/InvoiceBundle/Action/Edit.php` |
| LiveComponent 创建 | `src/InvoiceBundle/Twig/Components/CreateInvoice.php` |
| 手动催款 | `src/InvoiceBundle/Action/SendManualReminder.php` |
| 自动提醒处理器 | `src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php` |
| 邮件类 - 发票发送 | `src/InvoiceBundle/Email/InvoiceEmail.php` |
| 邮件类 - 手动催款 | `src/InvoiceBundle/Email/ManualInvoiceReminderEmail.php` |
| 邮件类 - 自动提醒 | `src/InvoiceBundle/Email/InvoiceReminderEmail.php` |
| PDF 生成监听器 | `src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php` |
| 发票主题监听器 | `src/InvoiceBundle/Listener/Mailer/InvoiceSubjectListener.php` |
| 发票收件人监听器 | `src/InvoiceBundle/Listener/Mailer/InvoiceReceiverListener.php` |
| 提醒主题监听器 | `src/InvoiceBundle/Listener/Mailer/ReminderSubjectListener.php` |
| 提醒收件人监听器 | `src/InvoiceBundle/Listener/Mailer/ReminderReceiverListener.php` |
| 邮件发送监听器 | `src/InvoiceBundle/Listener/Mailer/InvoiceMailerListener.php` |
| 工作流订阅器 | `src/InvoiceBundle/Listener/WorkFlowSubscriber.php` |
| 事件定义 | `src/InvoiceBundle/Event/InvoiceEvents.php` |
| API 转换处理器 | `src/ApiBundle/State/Processor/InvoiceTransitionProcessor.php` |
| 邮件模板 - 发票发送 | `src/InvoiceBundle/Resources/views/Email/invoice.html.twig` |
| 邮件模板 - 手动催款 | `src/InvoiceBundle/Resources/views/Email/manual_reminder.html.twig` |
| 邮件模板 - 自动提醒 | `src/InvoiceBundle/Resources/views/Email/reminder.html.twig` |
| PDF 模板 | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` |
| 路由配置 | `src/InvoiceBundle/Resources/config/routing.php` |
| 发票实体 | `src/InvoiceBundle/Entity/Invoice.php` |
| 发票基类 | `src/InvoiceBundle/Entity/BaseInvoice.php` |
| 发票管理器 | `src/InvoiceBundle/Manager/InvoiceManager.php` |

---

## 7. 修正说明

本报告针对以下关键问题进行了多轮修正：

### 修正1：编辑保存路径的状态转换
- ❌ 错误结论：编辑保存会触发 `edit` 转换回退到 Draft
- ✅ 正确结论：编辑保存**不会**自动触发状态转换，普通保存时状态保持原样，只有明确选择 send/publish 时才会应用 `accept` 转换

### 修正2：邮箱验证闸门差异
- ❌ 错误结论：所有发送入口都有邮箱验证闸门
- ✅ 正确结论：Create/Edit Action 的 send 分支**没有**邮箱验证闸门，只有 Send Action、LiveComponent 和手动催款有

### 修正3：直接发送与手动催款的差异
- ❌ 错误结论：两者差异不明确，且手动催款有 PDF 附件
- ✅ 正确结论：两者在 HTTP 方法、CSRF 保护、状态转换、日志记录、异常处理等多个维度有明确差异，且**手动催款没有 PDF 附件**

### 修正4：三类邮件的 PDF 附件行为
- ❌ 错误结论：手动催款和自动提醒都有 PDF 附件
- ✅ 正确结论：只有 `InvoiceEmail`（发票发送）会通过 `InvoicePdfListener` 附加 PDF，`ManualInvoiceReminderEmail`（手动催款）和 `InvoiceReminderEmail`（自动提醒）都**不会**附加 PDF

### 修正5：补充自动提醒的完整链路
- ❌ 缺失：自动提醒的发送链路、专用监听器、幂等性保护等
- ✅ 补充：完整描述了自动提醒的消息队列处理流程、专用监听器（ReminderSubjectListener、ReminderReceiverListener）、以及完善的幂等性保护和状态追踪机制

### 修正6：直接发送邮件的监听器触发
- ❌ 错误结论：`InvoiceEmail` 不自动设置收件人和主题
- ✅ 正确结论：`InvoiceEmail` 有两个专用监听器：`InvoiceSubjectListener`（主题为空时自动补全）和 `InvoiceReceiverListener`（收件人为空时自动补全并注入 BCC）

### 修正7：三类邮件的 BCC 注入路径差异
- ❌ 错误结论：手动催款有 BCC 注入
- ✅ 正确结论：只有 `InvoiceEmail` 和 `InvoiceReminderEmail` 通过各自的监听器注入 BCC，`ManualInvoiceReminderEmail` **没有** BCC 注入
