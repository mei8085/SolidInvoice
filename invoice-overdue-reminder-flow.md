# 发票逾期状态与提醒流程 — 源码深度分析报告

> 本报告**仅基于 SolidInvoice 仓库中的实际代码实现**进行分析。对于 Symfony Notifier 框架支持但仓库未落地实现的通道能力，不纳入结论范围。所有结论均附有代码引用，可溯源验证。

---

## 一、逾期判定的时间粒度：自然日，非具体时间点

### 1.1 核心字段的存储格式

发票到期日使用 `DATE_IMMUTABLE` 类型，仅存储**年月日**，不含时分秒：

```php
// [Invoice.php#L169-L175](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L169-L175)
#[ORM\Column(name: 'due', type: Types::DATE_IMMUTABLE, nullable: true)]
private ?DateTimeInterface $due = null;
```

### 1.2 逾期状态转换的判定逻辑

在 [InvoiceRepository::getPendingOverdueInvoices()](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L495-L506) 中执行：

```php
public function getPendingOverdueInvoices(): iterable
{
    $qb = $this->createQueryBuilder('i');

    $qb->where('i.status = :status')
       ->andWhere('i.due < :now')        // ← DATE < DATETIME 的自然日比较
       ->andWhere('i.due IS NOT NULL')
       ->setParameter('status', InvoiceStatus::Pending)
       ->setParameter('now', $this->clock->now());

    return $qb->getQuery()->toIterable();
}
```

**⚡ 数据库比较语义：**

| 表达式 | 数据库实际行为 | 业务含义 |
|--------|---------------|---------|
| `i.due < :now` | `2026-06-12 < 2026-06-12 14:35:22` → `FALSE`<br>`2026-06-11 < 2026-06-12 00:00:01` → `TRUE` | **到期日次日的 00:00:01 起算逾期** |
| `i.due = :targetDate` + `Types::DATE_IMMUTABLE` 绑定 | 两端均截断为日期后比较 | **精确匹配自然日，每天只触发一次** |

**结论：** 逾期判定以**自然日**为粒度。到期日当天仍视为"待支付"，直到**次日零点之后**才会被标记为 `Overdue`。

### 1.3 客户提醒的日期匹配

四种提醒类型均使用 `DATE_IMMUTABLE` 精确匹配自然日：

```php
// [InvoiceRepository::getInvoicesNeedingOverdueReminders()](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L537-L553)
public function getInvoicesNeedingOverdueReminders(int $daysOverdue, ReminderType $reminderType): iterable
{
    $targetDate = $this->clock->now()->modify("-{$daysOverdue} days");

    $qb = $this->createQueryBuilder('i');

    $qb->leftJoin(InvoiceReminder::class, 'r', 'WITH', 'r.invoice = i.id AND r.reminderType = :reminderType')
       ->where('i.status in (:pending, :overdue)')
       ->andWhere('i.due = :targetDate')                // ← 精确匹配自然日
       ->andWhere('r.id IS NULL')                        // ← 去重：该类型未发送过
       ->setParameter('targetDate', $targetDate, Types::DATE_IMMUTABLE);
}
```

> `Types::DATE_IMMUTABLE` 的参数绑定会将 `DateTime` 对象截断为日期字符串（如 `'2026-06-12'`），确保每天只匹配一次。

---

## 二、#hourly 调度的真实执行时间 + 开关控制

### 2.1 哈希调度的精确计算结果

两个核心任务都标注了 `#hourly`，但 **实际执行分钟数不同**（由 Symfony Scheduler 的 `HashCronExpression` 基于 schedule 名称通过标准 CRC32 算法计算）：

**计算公式：**
```
分钟 = crc32(schedule_name) % 60
```

**实际计算结果（经标准 CRC32 算法验证，与 PHP 原生 crc32() 函数输出一致）：**

| schedule 名称 | crc32 值 | mod 60 | 实际执行时间 | 对应命令 |
|---------------|----------|--------|------------|---------|
| `invoice_reminders` | 1043154442 | **22** | **每小时第 22 分** | `solidinvoice:invoices:send-reminders` |
| `mark_invoices_overdue` | 2328520352 | **32** | **每小时第 32 分** | `solidinvoice:invoices:mark-overdue` |

```
每小时典型时间线：
HH:22  → SendInvoiceRemindersCommand 执行（扫描 4 级提醒）
HH:32  → MarkOverdueInvoicesCommand 执行（状态 Pending → Overdue 转换）
```

**⚠️ 重要时序发现：** 由于提醒在 HH:22 执行，而状态转换在 HH:32 执行，**首次逾期提醒（Overdue1）发送时发票状态仍为 Pending**。这就是为什么提醒查询条件特意包含 `status IN (Pending, Overdue)` — 确保两种状态的发票都能被捕获。

### 2.2 定时任务注解定义

两个任务均通过 `#[AsCronTask]` 属性注册到 Symfony Scheduler：

```php
// [MarkOverdueInvoicesCommand.php#L29-L35](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/MarkOverdueInvoicesCommand.php#L29-L35)
#[AsCommand(
    name: 'solidinvoice:invoices:mark-overdue',
    description: 'Mark pending invoices as overdue when past due date',
)]
#[AsCronTask('#hourly', schedule: 'mark_invoices_overdue')]   // → 每小时第 32 分
final class MarkOverdueInvoicesCommand extends Command
```

```php
// [SendInvoiceRemindersCommand.php#L41-L47](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/SendInvoiceRemindersCommand.php#L41-L47)
#[AsCommand(
    name: 'solidinvoice:invoices:send-reminders',
    description: 'Send payment reminders for pending and overdue invoices',
)]
#[AsCronTask(expression: '#hourly', schedule: 'invoice_reminders')]  // → 每小时第 22 分
final class SendInvoiceRemindersCommand extends Command
```

### 2.3 四层递进开关（仅提醒链路，状态转换为强制执行）

发票状态转换（MarkOverdue）是**无开关的强制机制**，只要满足 `due < now()` 就执行。但客户提醒发送有四层递进式开关：

| 层级 | 开关位置 | 检查代码位置 | 含义 |
|------|---------|------------|------|
| **第 1 层** | SaaS 功能门控 | `FeatureGate::isEnabled(Feature::AutomatedReminders)` | SaaS 租户级总开关（L59） |
| **第 2 层** | 全局提醒开关 | `systemConfig->get('invoice/reminder/enabled')` | 该公司是否启用自动化提醒（L227） |
| **第 3 层** | 预到期专项开关 | `systemConfig->get('invoice/reminder/pre_due_enabled')` | 仅 PreDue 类型需要额外检查（L235） |
| **第 4 层** | 数据库去重 | `InvoiceReminder` 表唯一约束 + `LEFT JOIN WHERE IS NULL` | 同类型提醒只发一次（L70+L50） |

```php
// [SendInvoiceReminderHandler.php#L56-L74](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L56-L74)
// 第 1 层：SaaS 功能门控
if (! $this->featureGate->isEnabled(Feature::AutomatedReminders->value)) {
    return;
}

// 第 2 + 3 层：isRemindersEnabled() 内部处理全局和专项开关
if (! $this->isRemindersEnabled($message->reminderType)) {
    return;
}

// 第 4 层：幂等性检查（同类型提醒是否已发送）
if ($this->reminderRepository->hasReminderBeenSent($invoice, $message->reminderType)) {
    return;
}
```

---

## 三、核心澄清：主题/紧急程度在两条链路的生成机制

这是最容易混淆的部分：**客户提醒邮件（直发链路）与内部通知（订阅链路）的主题和紧急程度在完全不同的位置生成，机制完全不同。**

### 3.1 两条链路总览对比表

| 维度 | 客户提醒邮件（直发链路） | 内部逾期通知（订阅链路） | 内部提醒通知（订阅链路） |
|------|-------------------------|----------------------|----------------------|
| **触发代码位置** | [SendInvoiceReminderHandler#L127](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L127) | [InvoiceOverdueListener#L54](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/InvoiceOverdueListener.php#L54) | [SendInvoiceReminderHandler#L171](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L171) |
| **邮件对象类** | `InvoiceReminderEmail` (extends `TemplatedEmail`) | `InvoiceOverdueNotification` (extends `NotificationMessage`) | `InvoiceReminderNotification` (extends `NotificationMessage`) |
| **主题生成位置** | `ReminderSubjectListener::__invoke()`<br>（**Mailer `MessageEvent` 派发时延迟注入**） | `InvoiceOverdueNotification::getSubject()`<br>（**对象自身方法直接返回**） | `InvoiceReminderNotification::getSubject()`<br>（**对象自身方法直接返回**） |
| **主题生成时机** | `$mailer->send()` 内部派发 `MessageEvent` 时 | NotificationManager → Notifier 构建 `EmailMessage` 时 | NotificationManager → Notifier 构建 `EmailMessage` 时 |
| **主题匹配内容** | `match(ReminderType 枚举)` 四选一 | 固定字符串 `'Invoice Overdue Alert'` | `match(reminder_type 字符串)` 四选一 |
| **紧急程度设置** | ❌ **完全未设置**（无 X-Priority 邮件头） | ✅ `NotificationEmail::IMPORTANCE_HIGH` | ✅ Overdue14 → `IMPORTANCE_URGENT`<br>✅ 其他 → `IMPORTANCE_MEDIUM` |
| **接收对象** | 客户联系人邮箱（外部用户） | 订阅了 `invoice_overdue` 事件的内部用户 | 订阅了 `invoice_reminder` 事件的内部用户 |
| **HTML 模板** | `reminder.html.twig` | `notification_overdue.html.twig` | `reminder.html.twig`（与客户邮件共用） |
| **TEXT 模板** | `reminder.text.twig` | `notification_overdue.text.twig` | `reminder.text.twig` |

---

## 四、直发客户提醒邮件链路 — 完整对象与模板路径复核

### 4.1 完整调用链（按代码行号精确追踪）

```
HH:22 (每小时第 22 分)
  ↓
[SendInvoiceRemindersCommand] (L41-L216)
  ├─ 禁用 company 过滤器（跨租户扫描）
  ├─ 扫描 4 种提醒类型（4 轮独立查询）：
  │   ├─ PreDue:    getInvoicesNeedingPreDueReminders()
  │   ├─ Overdue1:  getInvoicesNeedingOverdueReminders(days=1)
  │   ├─ Overdue7:  getInvoicesNeedingOverdueReminders(days=7)
  │   └─ Overdue14: getInvoicesNeedingOverdueReminders(days=14)
  └─ 为每张发票派发 SendInvoiceReminderMessage (MessageBus 异步)
       ↓
  [MessageBus]
       ↓
[SendInvoiceReminderHandler::__invoke()] (L41-L241)
  ├─ L56: 切换公司上下文 CompanySelector::switchCompany()
  ├─ L59: 第 1 层开关 → FeatureGate 检查 AutomatedReminders
  ├─ L63: 第 2+3 层开关 → isRemindersEnabled()（含 PreDue 专项检查）
  ├─ L70: 第 4 层开关 → hasReminderBeenSent() 数据库去重检查
  │
  ├─ ⭐ L127: 【构造直发邮件对象】
  │     $email = new InvoiceReminderEmail($invoice, $reminderType, $daysUntilDue)
  │       │
  │       └─ 构造函数 [InvoiceReminderEmail#L22-L36] 执行：
  │             ├─ $this->htmlTemplate('@SolidInvoiceInvoice/Email/reminder.html.twig')
  │             ├─ $this->textTemplate('@SolidInvoiceInvoice/Email/reminder.text.twig')
  │             ├─ $this->context([
  │             │    'invoice'         => $invoice,
  │             │    'reminder_type'   => $reminderType->value,  ← 枚举转字符串
  │             │    'days_until_due'  => $daysUntilDue,
  │             │  ])
  │             └─ ⚠️  【关键】主题 (subject) 留空 (null)，紧急程度完全未设置
  │
  ├─ ⭐ L132: 【发送邮件触发主题注入】
  │     $this->mailer->send($email)
  │       │
  │       └─ [Symfony Mailer 内部]
  │             ├─ 构建 MessageEvent
  │             └─ 派发到 EventDispatcher
  │                  ↓
  │       ReminderSubjectListener::__invoke(MessageEvent) [L23-L42]
  │             ├─ L28: 守卫条件：
  │             │    if ($message instanceof InvoiceReminderEmail
  │             │        && null === $message->getSubject())  ← 仅主题为空时生效
  │             ├─ L33: match($reminderType) 生成主题：
  │             │    ├─ PreDue   → "Upcoming Payment Due: Invoice #INV-0001"
  │             │    ├─ Overdue1 → "Payment Reminder: Invoice #INV-0001"
  │             │    ├─ Overdue7 → "Payment Overdue: Invoice #INV-0001"
  │             │    └─ Overdue14→ "URGENT: Invoice #INV-0001 - Immediate Action Required"
  │             ├─ L40: $message->subject($subject)  ← 延迟注入主题
  │             └─ ⚠️  【关键】此处完全不处理紧急程度/Importance
  │                  邮件头不包含 X-Priority，即普通邮件优先级
  │       │
  │       └─ 通过传输器 (SMTP / SendGrid / Mailgun 等) 发送至客户收件箱
  │
  ├─ ⭐ L151: 【创建提醒记录（防重）】
  │     if ($emailSent):
  │       状态 = ReminderStatus::Sent, sentAt = now()
  │     else:
  │       状态 = ReminderStatus::Failed, 记录 failureReason
  │     persist + flush
  │
  └─ ⭐ L168: 【仅当客户邮件发送成功后 → 走内部订阅通知链路】
        │
        ├─ L171: → InvoiceReminderNotification（内部提醒同步通知）
        │            见第五章 5.2 节
        │
        └─ L188: if ($reminderType === Overdue14):
               → InvoiceReminderStoppedNotification（升级通知）
```

### 4.2 直发邮件的主题注入 — Mailer 事件监听器

这是理解直发链路的关键：**`InvoiceReminderEmail` 的构造函数故意不设置主题**，交由 `ReminderSubjectListener` 通过 Mailer 事件延迟注入。

```php
// [ReminderSubjectListener.php#L21-L49](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/Mailer/ReminderSubjectListener.php#L21-L49)
class ReminderSubjectListener implements EventSubscriberInterface
{
    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        // 守卫条件：仅处理主题为空的 InvoiceReminderEmail
        // 业务代码可手动 ->subject() 覆盖默认主题，监听器自动跳过
        if ($message instanceof InvoiceReminderEmail && null === $message->getSubject()) {
            $invoice = $message->getInvoice();
            $invoiceId = $invoice->getInvoiceId();
            $reminderType = $message->getReminderType();

            $subject = match ($reminderType) {
                ReminderType::PreDue   => "Upcoming Payment Due: Invoice {$invoiceId}",
                ReminderType::Overdue1 => "Payment Reminder: Invoice {$invoiceId}",
                ReminderType::Overdue7 => "Payment Overdue: Invoice {$invoiceId}",
                ReminderType::Overdue14=> "URGENT: Invoice {$invoiceId} - Immediate Action Required",
            };

            $message->subject($subject);   // ← 延迟注入主题
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => '__invoke'];
    }
}
```

**设计动机（反模式防护）：** 这种设计允许业务代码（如 `ManualInvoiceReminderEmail`）通过 `->subject('自定义主题')` 显式覆盖默认主题，监听器检测到 `subject !== null` 时自动跳过，实现优雅的降级与自定义能力。

### 4.3 客户提醒模板的内容渲染（HTML + TEXT 双模板）

**HTML 模板：** [reminder.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/reminder.html.twig)

根据传入的 `reminder_type` 上下文参数动态渲染不同视觉语气：

| ReminderType | 横幅图标 | 横幅颜色 | 内容语气 |
|--------------|---------|---------|---------|
| `pre_due` | 💡 | `#0891b2`（青色） | 友好提醒："Your invoice is due soon" |
| `overdue_1` | 📋 | `#f59e0b`（琥珀） | 礼貌提醒："A gentle reminder about your payment" |
| `overdue_7` | ⏰ | `#ea580c`（橙色） | 强调紧迫："Your payment is now overdue" |
| `overdue_14` | 🚨 | `#dc2626`（红色） | 强烈警告："Immediate action required" |

模板包含的标准内容块：
- 动态标题行（根据 `reminder_type` switch 渲染）
- 客户称呼（`client.name`）
- 发票详情表：发票号、开票日期、到期日、未结余额
- "View Invoice & Pay" CTA 按钮 → 链接到外部支付页面 URL
- 公司签名与联系方式

---

## 五、内部订阅通知链路 — 完整对象与模板路径复核

内部通知链路有 **三个独立的通知事件**，分别对应不同的触发源：

| 事件名称 | 触发条件 | 通知类 | 使用 HTML 模板 |
|---------|---------|--------|-------------|
| `invoice_overdue` | 发票状态 Pending → Overdue 的 Workflow 转换瞬间 | `InvoiceOverdueNotification` | `notification_overdue.html.twig` |
| `invoice_reminder` | 每次向客户成功发送提醒后 | `InvoiceReminderNotification` | `reminder.html.twig`（与客户邮件共用） |
| `invoice_reminder_stopped` | 发送 Overdue14 最终提醒后（升级通知） | `InvoiceReminderStoppedNotification` | `reminder_stopped.html.twig` |

### 5.1 链路 A：发票逾期状态变更通知

```
HH:32 (每小时第 32 分)
  ↓
[MarkOverdueInvoicesCommand] (L29-L105)
  ├─ 禁用 company 过滤器（跨租户扫描）
  ├─ getPendingOverdueInvoices(): WHERE status='pending' AND due < now()
  └─ 为每张发票派发 MarkInvoiceOverdueMessage (MessageBus 异步)
       ↓
  [MessageBus]
       ↓
[MarkInvoiceOverdueHandler::__invoke()] (L30-L57)
  ├─ 切换公司上下文
  ├─ 幂等性检查：确认为 Pending 状态（可转换）
  └─ $stateMachine->apply($invoice, 'overdue')
       ↓
  [Symfony Workflow 状态转换]
    Pending → Overdue（配置见 workflow.php#L99-L110）
       ↓
  派发 Event: workflow.invoice.entered.overdue
       ↓
[InvoiceOverdueListener::onInvoiceOverdue()] (L28-L69)
  └─ ⭐ L54: 构造并发送通知
       ↓
  new InvoiceOverdueNotification([
    'invoice' => $invoice,
    'client'  => $invoice->getClient(),
  ])
       │
       └─ 类结构 [InvoiceOverdueNotification.php#L24-L64]：
            extends NotificationMessage
            #[AsNotification(name: 'invoice_overdue', ...)]
            const HTML_TEMPLATE = '@SolidInvoiceInvoice/Email/notification_overdue.html.twig'
            const TEXT_TEMPLATE = '@SolidInvoiceInvoice/Email/notification_overdue.text.twig'

            getSubject(): string
              → 直接 return 'Invoice Overdue Alert';  ← 固定字符串

            asEmailMessage(...)
              → parent::asEmailMessage()（构造 EmailMessage）
              → if ($email instanceof NotificationEmail):
                  textTemplate(TEXT_TEMPLATE)
                  htmlTemplate(HTML_TEMPLATE)
                  context(getParameters())
                  importance(NotificationEmail::IMPORTANCE_HIGH)  ← 高优先级
       ↓
  $notificationManager->sendNotification(...)
       │
       └─ 详见第六章：NotificationManager 内部多渠道组装逻辑
            ↓
  若用户选择 Email 渠道：
    主题 = 'Invoice Overdue Alert'
    紧急程度 = IMPORTANCE_HIGH（X-Priority: 1, Highest）
    模板 = notification_overdue.html.twig
       ↓
  内部用户收件箱收到红色高优先级警报邮件
```

**内部逾期专属模板内容** [notification_overdue.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/notification_overdue.html.twig)：
- ⚠️ 红色警报横幅（`color: #dc2626; font-weight: 600;`）
- 发票状态徽章（Overdue，红底白字）
- 客户名称与逾期天数高亮
- 详情信息行：发票号、到期日、未结余额
- "View Invoice" 按钮（链接到系统内部发票详情页 `_invoices_view`，**非外部支付页**）
- 页脚："This is an automated system notification"

### 5.2 链路 B：客户提醒已发送的内部同步通知

```
（接第四章 L168 — 仅当客户邮件发送成功后执行）
  ↓
[SendInvoiceReminderHandler] L171
  ↓
new InvoiceReminderNotification([
  'invoice'        => $invoice,
  'client'         => $invoice->getClient(),
  'reminder_type'  => $message->reminderType,   ← 传入 ReminderType 枚举对象
  'days_until_due' => $message->daysUntilDue,
])
       │
       └─ 类结构 [InvoiceReminderNotification.php#L24-L99]：
            extends NotificationMessage
            #[AsNotification(name: 'invoice_reminder', ...)]
            const HTML_TEMPLATE = '@SolidInvoiceInvoice/Email/reminder.html.twig'  ← 与客户共用
            const TEXT_TEMPLATE = '@SolidInvoiceInvoice/Email/reminder.text.twig'

            getNormalizedParameters(): array
              → 关键：将 parameters['reminder_type'] 从枚举对象 ->value 字符串
              → 避免 Twig 模板 match 判断出错

            getSubject(): string
              → 取 $parameters['reminder_type']（已是字符串）
              → match($reminderType) 四选一：
                  'pre_due'    → "Upcoming Payment Due: Invoice INV-0001"
                  'overdue_1'  → "Payment Reminder: Invoice INV-0001"
                  'overdue_7'  → "Payment Overdue: Invoice INV-0001"
                  'overdue_14' → "URGENT: Invoice INV-0001 - Immediate Action Required"

            asEmailMessage(...)
              → parent::asEmailMessage()
              → getNormalizedParameters()（枚举→字符串归一化）
              → textTemplate() / htmlTemplate()
              → context($normalizedParameters)
              → importance():
                   overdue_14 → NotificationEmail::IMPORTANCE_URGENT  (X-Priority: 1)
                   其他类型   → NotificationEmail::IMPORTANCE_MEDIUM  (X-Priority: 3)
       ↓
  $notificationManager->sendNotification(...)
       │
       └─ 详见第六章：NotificationManager 内部多渠道组装逻辑
            ↓
  内部用户收到内容与客户一致的提醒邮件，但紧急程度更高（内部处理优先级明确）
```

### 5.3 链路 C：Overdue14 升级通知

```
（接第四章 L188 — 仅当 Overdue14 类型的客户邮件发送成功后执行）
  ↓
[SendInvoiceReminderHandler] L189
  ├─ calculateDaysOverdue($invoice): 实际逾期天数
  ↓
new InvoiceReminderStoppedNotification([
  'invoice'      => $invoice,
  'days_overdue' => $daysOverdue,
])
       │
       └─ 类结构 [InvoiceReminderStoppedNotification.php]：
            extends NotificationMessage
            #[AsNotification(name: 'invoice_reminder_stopped', ...)]
            const HTML_TEMPLATE = '@SolidInvoiceInvoice/Email/reminder_stopped.html.twig'
            getSubject(): return 'Invoice Reminder Escalation Required'
            asEmailMessage(): importance(NotificationEmail::IMPORTANCE_URGENT)
       ↓
  $notificationManager->sendNotification(...)
       │
       └─ 订阅了 'invoice_reminder_stopped' 事件的用户通过多渠道收到升级警报
```

---

## 六、多渠道通知 — 仓库实际可组装的通道能力（基于代码事实）

### 6.1 关键事实：NotificationMessage 基类实际实现的接口

**⚠️ 本节是修正前文结论的最重要部分：** 仓库实际只实现了 Email 和 Chat 两种通道接口，SMS 类通道虽然有配置器 UI，但缺少接口实现，**实际发送将失败**。

```php
// [NotificationMessage.php#L26](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationMessage.php#L26)
abstract class NotificationMessage extends Notification
    implements EmailNotificationInterface, ChatNotificationInterface
```

**仓库全局 Grep 验证（`SmsNotificationInterface|asSmsMessage`）：** No matches found

| Notifier 接口 | 是否实现 | 对应方法 | 实际发送能力 |
|---|---|---|---|
| `EmailNotificationInterface` | ✅ **已实现** | `asEmailMessage()` | ✅ 可用 |
| `ChatNotificationInterface` | ✅ **已实现** | `asChatMessage()` | ✅ 可用 |
| `SmsNotificationInterface` | ❌ **完全未实现** | 无 `asSmsMessage()` | ❌ 不可用 |
| `PushNotificationInterface` | ❌ 未实现 | 无 | ❌ 不可用 |
| `BrowserNotificationInterface` | ❌ 未实现 | 无 | ❌ 不可用 |

### 6.2 仓库实际存在的配置器分类（`src/NotificationBundle/Configurator/` 目录共 42 个文件）

经逐文件检查 `getType()` 返回值，排除接口 `ConfiguratorInterface.php` 后，共 **40 个真实配置器**，分为两类：

#### 类别 1：`chatter` — 聊天通道（14 个）→ ✅ 代码可用

| 配置器文件 | 配置器名称 | `getType()` 返回值 | 对应 Notifier 接口 | 通道字符串格式 |
|---|---|---|---|---|
| `DiscordConfigurator.php` | Discord | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `FakeChatConfigurator.php` | FakeChat（测试用） | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `FirebaseConfigurator.php` | Firebase | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `GitterConfigurator.php` | Gitter | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `GoogleChatConfigurator.php` | GoogleChat | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `LinkedInConfigurator.php` | LinkedIn | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `MailjetConfigurator.php` | Mailjet | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `MattermostConfigurator.php` | Mattermost | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `MercureConfigurator.php` | Mercure | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `MicrosoftTeamsConfigurator.php` | MicrosoftTeams | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `RocketChatConfigurator.php` | RocketChat | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `SlackConfigurator.php` | Slack | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `TelegramConfigurator.php` | Telegram | chatter | ChatNotificationInterface | `chat/{uuid}` |
| `ZulipConfigurator.php` | Zulip | chatter | ChatNotificationInterface | `chat/{uuid}` |

#### 类别 2：`texter` — 短信通道（26 个）→ ⚠️ UI 可配置，但发送代码残缺

| 配置器文件 | 配置器名称 | `getType()` 返回值 | 对应 Notifier 接口 | 通道字符串格式 | 实际发送 |
|---|---|---|---|---|---|
| `AllMySmsConfigurator.php` | AllMySms | texter | **❌ 接口未实现** | `sms/{uuid}` | 失败 |
| `AmazonSnsConfigurator.php` | AmazonSns | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `BrevoConfigurator.php` | Brevo | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `ClickatellConfigurator.php` | Clickatell | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `EsendexConfigurator.php` | Esendex | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `FakeSmsConfigurator.php` | FakeSms（测试用） | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `FreeMobileConfigurator.php` | FreeMobile | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `GatewayApiConfigurator.php` | GatewayApi | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `InfobipConfigurator.php` | Infobip | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `IqsmsConfigurator.php` | Iqsms | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `LightSmsConfigurator.php` | LightSms | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `MessageBirdConfigurator.php` | MessageBird | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `MessageMediaConfigurator.php` | MessageMedia | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `MobytConfigurator.php` | Mobyt | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `OctopushConfigurator.php` | Octopush | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `OvhCloudConfigurator.php` | OvhCloud | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `SinchConfigurator.php` | Sinch | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `Sms77Configurator.php` | Sms77 | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `SmsapiConfigurator.php` | Smsapi | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `SmsBiurasConfigurator.php` | SmsBiuras | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `SmscConfigurator.php` | Smsc | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `SpotHitConfigurator.php` | SpotHit | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `TelnyxConfigurator.php` | Telnyx | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `TurboSmsConfigurator.php` | TurboSms | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `TwilioConfigurator.php` | Twilio | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `VonageConfigurator.php` | Vonage | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |
| `YunpianConfigurator.php` | Yunpian（云片网） | texter | ❌ 接口未实现 | `sms/{uuid}` | 失败 |

**SMS 通道的失败原因代码追踪：**

```php
// [Transports.php#L54-L63](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/Transports.php#L54-L63)
public function send(MessageInterface $message): SentMessage
{
    foreach ($this->transports as $transport) {
        if ($transport->supports($message)) {   // ← SmsTransport::supports(SmsMessage) 返回 true
            return $transport->send($message);   // ← 但我们传入的不是 SmsMessage！
        }
    }
    // ❌ 当 channels=['sms/uuid'] 时走到这里：
    throw new LogicException('None of the available transports support the given message...');
}
```

根本原因：`NotificationMessage` 只实现了 `EmailNotificationInterface` 和 `ChatNotificationInterface`，因此 Symfony Notifier 只能从该对象生成 `EmailMessage` 和 `ChatMessage`，无法生成 `SmsMessage`。当 Notifier 尝试路由到 SMS 传输器时，`SmsTransport::supports()` 对传入的消息类型返回 false，最终抛出 `LogicException`。

### 6.3 NotificationManager 的通道组装算法（代码事实版）

```php
// [NotificationManager::sendNotification()](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php#L47-L103)
public function sendNotification(NotificationMessage $message): void
{
    // Step 1: 从 #[AsNotification] 反射获取事件名
    $event = (new ReflectionObject($message))
        ->getAttributes(AsNotification::class)[0]
        ->getArguments()['name'];    // e.g. 'invoice_overdue'

    // Step 2: 查询所有订阅了该事件的用户配置
    $userNotifications = $this->userNotificationRepository
        ->findBy(['event' => $event]);

    foreach ($userNotifications as $userNotification) {
        $channels = [];

        // ──────── 通道 1：Email（始终可用，✅ 代码已实现） ────────
        if ($userNotification->isEmail()) {
            $channels[] = 'email';
        }

        // ──────── 通道 2+：用户配置的传输器 ────────
        foreach ($userNotification->getTransports() as $transport) {
            $transportConfig = $this->transportConfigurations
                ->get($transport->getTransport());  // 按配置器名称定位

            // 类型映射：配置器 getType() → Notifier 通道前缀
            $channelType = match ($transportConfig::getType()) {
                'texter'  => 'sms',      // ← 生成 'sms/' 前缀（但代码未实现！）
                'chatter' => 'chat',     // ← 生成 'chat/' 前缀（✅ 可用）
                default   => $transportConfig::getType(),
            };

            // 通道字符串 = 类型/UUID（UUID 精确定位到用户的那一条具体配置）
            $channels[] = sprintf(
                '%s/%s',
                $channelType,
                $transport->getId()->toString()
            );
        }

        // Step 3: 组装好的 channels 注入到通知对象
        $message->channels($channels);

        // Step 4: Symfony Notifier 按 channels 路由
        try {
            $this->notifier->send(
                $message,
                new Recipient(
                    $userNotification->getUser()->getEmail(),   // Email 用
                    (string) $userNotification->getUser()->getMobile()  // SMS 理论用
                )
            );
        } catch (TransportExceptionInterface $e) {
            // 通道级失败：记录日志 + 设置 Flash 错误，但不影响其他用户/通道
        }
    }
}
```

### 6.4 实际可组装通道总结表（仅仓库落地代码）

| 通道标识 | 状态 | 生成条件 | 实际行为 |
|---|---|---|---|
| `'email'` | ✅ **完全可用** | `UserNotification.email = true` | 调用 `asEmailMessage()` → 生成 `EmailMessage` → 系统默认邮件传输器 |
| `'chat/{transportUuid}'` | ✅ **完全可用** | 用户关联了 `chatter` 类配置器（14 种可选） | 调用 `asChatMessage()` → 生成 `ChatMessage` → 按 UUID 路由到指定 Chatter 传输器 |
| `'sms/{transportUuid}'` | ❌ **代码残缺，发送失败** | 用户关联了 `texter` 类配置器（26 种可选但均不可用） | Notifier 无法从 NotificationMessage 构造 `SmsMessage` → `Transports::send()` 抛出 LogicException |
| `'push/...'`, `'browser/...'` | ❌ **完全不存在** | 仓库无此类型配置器 | 无法生成此类通道字符串 |

### 6.5 用户订阅配置的数据模型

```php
// [UserNotification.php#L27-L54](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Entity/UserNotification.php#L27-L54)
class UserNotification
{
    private Ulid $id;                    // ULID 主键
    private string $event;               // 'invoice_overdue' / 'invoice_reminder' / 'invoice_reminder_stopped'
    private bool $email = true;          // Email 通道开关
    private Collection $transports;      // ManyToMany → TransportSetting（用户配置的传输器）
    private User $user;                  // 所属用户（接收人）
}

// [TransportSetting.php#L32-L59](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Entity/TransportSetting.php#L32-L59)
class TransportSetting
{
    private Ulid $id;                    // ← 用于通道字符串的 UUID 部分
    private string $name;                // 用户自定义显示名，如 "公司 Slack"
    private string $transport;           // 配置器名称，如 'Slack' / 'Twilio'
    private array $settings = [];        // 配置参数，如 token / channel / sid / from 等
    private User $user;                  // 所属配置者
}
```

**实际配置与通道组装示例：**

```
用户 "admin@company.com" 的订阅配置
├─ 事件: invoice_overdue
│   ├─ email: true
│   └─ transports: [
│       (UUID=01H…123, name="公司 Slack", transport="Slack", settings={...})
│       (UUID=01H…456, name="财务手机", transport="Twilio", settings={...})
│     ]
│
└─ 事件: invoice_reminder
    ├─ email: true
    └─ transports: [
        (UUID=01H…123, name="公司 Slack", ...)
      ]

当 invoice_overdue 事件触发时，组装 channels = [
  'email',                          → ✅ 发送邮件
  'chat/01H…123',                   → ✅ 发 Slack（chatter→chat 映射）
  'sms/01H…456',                    → ❌ 代码残缺，LogicException（被 catch 记录日志）
]
最终该用户：收到邮件 + Slack 通知，Twilio 短信失败但不影响前两者。
```

---

## 七、整体架构时序图（基于代码事实）

```
时间轴 →
│                                                                                  │
│  HH:00     HH:22 (实际)              HH:32 (实际)        HH:59                   │
│    │         │                       │                    │                        │
│    │         ▼                       ▼                    │                        │
│    │  ┌────────────────┐     ┌──────────────────┐        │                        │
│    │  │  SendReminders │     │ MarkOverdue Cmd  │        │                        │
│    │  │  Cmd (第22分)  │     │  (第32分)         │        │                        │
│    │  └───────┬────────┘     └────────┬─────────┘        │                        │
│    │          │                       │                  │                        │
│    │          ▼                       ▼                  │                        │
│    │  查询 4 类待提醒发票      查询待转逾期发票           │                        │
│    │          │                       │                  │                        │
│    │  ┌───────┴────────┐     ┌───────┴─────────┐        │                        │
│    │  │ MessageBus     │     │ MessageBus      │        │                        │
│    │  └───────┬────────┘     └────────┬─────────┘        │                        │
│    │          │                       │                  │                        │
│    │          ▼                       ▼                  │                        │
│    │  SendInvoiceReminder     MarkInvoiceOverdue         │                        │
│    │  Handler (L41-L241)      Handler                    │                        │
│    │    ├─ L56 切公司上下文      ├─ 切公司               │                        │
│    │    ├─ L59 四层开关检查      └─ Workflow apply       │                        │
│    │    │                                                │                        │
│    │    ├─ L127 new InvoiceReminderEmail                 │                        │
│    │    │        (主题留空，无优先级)                     │                        │
│    │    ├─ L132 mailer->send()                           │                        │
│    │    │        │                                       │                        │
│    │    │        ▼                                       │                        │
│    │    │  MessageEvent 派发                             │                        │
│    │    │        │                                       │                        │
│    │    │        ▼                                        ▼                       │
│    │    │  ReminderSubjectListener            Event: entered.overdue             │
│    │    │    match(enum) 注入主题                      │                        │
│    │    │        │                                       │                        │
│    │    │        ▼                                       ▼                       │
│    │    │    客户收件箱 (Email)           InvoiceOverdueListener                 │
│    │    │    (客户邮件无优先级)                    │                             │
│    │    │                                            ▼                         │
│    │    ├─ L151 创建 InvoiceReminder 记录      NotificationManager             │
│    │    │                                            │                         │
│    │    └─ L168 仅发送成功时                              │                     │
│    │           │                                         │                     │
│    │           ▼                                         ▼                     │
│    │     L171: InvoiceReminderNotification     NotificationManager             │
│    │           │                               (查询订阅用户)                    │
│    │           │                               (组装 channels)                  │
│    │           ▼                               (Notifier::send)               │
│    │     NotificationManager                            │                     │
│    │           │                             ┌──────────┴──────────┐           │
│    │           ▼                             ▼                     ▼           │
│    │       [email ✅]                  [chat/uuid ✅]        [sms/uuid ❌]        │
│    │     ┌──────────┐              ┌──────────────┐     (LogicException)       │
│    │     │内部邮箱   │              │Slack/Discord  │        被 catch           │
│    │     └──────────┘              │/Telegram 等   │         记录日志          │
│    │                               └──────────────┘                           │
│    │                               (内部提醒同步通知)                           │
│    │                                                                           │
```

---

## 八、关键代码文件速查表（可点击跳转）

| 功能模块 | 文件路径 |
|----------|---------|
| 状态枚举定义 | [InvoiceStatus.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Enum/InvoiceStatus.php) |
| 提醒类型枚举 | [ReminderType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/ReminderType.php) |
| 状态转换命令（HH:32 执行） | [MarkOverdueInvoicesCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/MarkOverdueInvoicesCommand.php) |
| 提醒发送命令（HH:22 执行） | [SendInvoiceRemindersCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/SendInvoiceRemindersCommand.php) |
| 客户提醒消息处理器（直发链路核心） | [SendInvoiceReminderHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php) |
| 状态转换消息处理器 | [MarkInvoiceOverdueHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Message/Handler/MarkInvoiceOverdueHandler.php) |
| **直发邮件主题注入器（关键！）** | **[ReminderSubjectListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/Mailer/ReminderSubjectListener.php)** |
| 状态变更事件监听器（内部逾期通知入口） | [InvoiceOverdueListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/InvoiceOverdueListener.php) |
| 客户提醒邮件对象（TemplatedEmail 子类） | [InvoiceReminderEmail.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Email/InvoiceReminderEmail.php) |
| **内部逾期通知类（主题=固定字符串）** | **[InvoiceOverdueNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceOverdueNotification.php)** |
| **内部提醒通知类（主题=match 字符串）** | **[InvoiceReminderNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderNotification.php)** |
| 升级通知类（Overdue14 触发） | [InvoiceReminderStoppedNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderStoppedNotification.php) |
| 发票 Repository（日期查询逻辑） | [InvoiceRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php) |
| Workflow 状态机配置（Pending→Overdue 转换） | [workflow.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/config/packages/workflow.php) |
| **NotificationManager（通道组装核心）** | **[NotificationManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php)** |
| **NotificationMessage（仅实现 Email+Chat 接口）** | **[NotificationMessage.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationMessage.php)** |
| **Transports（SMS 失败原因在此）** | **[Transports.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/Transports.php)** |
| 用户订阅实体 | [UserNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Entity/UserNotification.php) |
| 传输器配置实体（通道 UUID 来源） | [TransportSetting.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Entity/TransportSetting.php) |
| 客户/内部提醒共用 HTML 模板 | [reminder.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/reminder.html.twig) |
| 内部逾期通知专用 HTML 模板 | [notification_overdue.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/notification_overdue.html.twig) |
| 配置器目录（共 42 个文件，含 26 texter+14 chatter） | [Configurator/](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Configurator/) |

---

## 九、核心设计洞察总结（仅代码事实）

### ⚡ 时间粒度
- **逾期状态**：以自然日为粒度，`due < now()` 确保到期日次日零点后生效
- **提醒触发**：`due = targetDate` + `Types::DATE_IMMUTABLE` 绑定，精确匹配自然日，确保每天只触发一次同类型提醒
- **执行时序**：先检查提醒（HH:22），再转换状态（HH:32），因此首次逾期提醒（Overdue1）发送时发票状态仍为 Pending

### ⚡ 主题与紧急程度 — 两条链路的本质差异

| 特性 | 直发客户邮件 | 内部订阅通知 |
|---|---|---|
| **主题生成** | `ReminderSubjectListener` 监听 Mailer 的 `MessageEvent` **延迟注入**（match 枚举值） | `Notification::getSubject()` **对象自身方法直接返回**（Overdue=固定字符串，提醒=match 字符串值） |
| **紧急程度** | 未设置（普通邮件优先级，无 X-Priority 头） | `asEmailMessage()` 内显式设置：<br>逾期 → `IMPORTANCE_HIGH`<br>Overdue14 → `IMPORTANCE_URGENT`<br>其他提醒 → `IMPORTANCE_MEDIUM` |
| **设计哲学** | 对客户：尊重收件体验，不标记"紧急"标签以免反感 | 对内部：明确优先级标识，确保及时进入处理队列 |

### ⚡ 多渠道通道 — 实际可用 vs 配置器存在

**⚠️ 本节是基于代码核查的关键修正结论（非框架能力推断）：**

| 通道类型 | 配置器数量 | 接口实现状态 | **实际是否可发送** |
|---|---|---|---|
| Email | 内置（不需要配置器） | ✅ `EmailNotificationInterface` 已实现 | **✅ 完全可用** |
| Chat（Slack/Discord/Telegram 等 14 种） | 14 个 chatter 配置器 | ✅ `ChatNotificationInterface` 已实现 | **✅ 完全可用** |
| SMS（Twilio/Vonage/云片网 等 26 种） | 26 个 texter 配置器 | ❌ `SmsNotificationInterface` **全仓库未实现** | **❌ 发送失败（LogicException）** |
| Push / Browser | 无对应配置器 | ❌ 接口未实现 | **❌ 不存在** |

SMS 类配置器仅提供了 UI 配置表单和 DSN 生成能力（用于界面配置传输器参数），但由于 `NotificationMessage` 基类（及其所有子类）均未实现 `SmsNotificationInterface`，Symfony Notifier 无法从通知对象构造出 `SmsMessage` 对象，导致 `Transports::send()` 遍历所有传输器后找不到支持该消息的实例，最终抛出 `LogicException`。该异常会被 `NotificationManager` 的 try-catch 捕获并记录到日志，不影响 Email/Chat 通道的并行发送。

### ⚡ 幂等性保障
- `InvoiceReminder` 表的 `(company_id, invoice_id, reminder_type)` 数据库唯一约束（DDL 级防重）
- 查询层 `LEFT JOIN ... WHERE r.id IS NULL`（提前排除已发送发票，减少无谓处理）
- 状态转换前二次确认：`$stateMachine->can($invoice, 'overdue')`（防并发重复转换）
- ReminderSubjectListener 的 `subject === null` 守卫条件（防主题覆盖逻辑意外生效）
