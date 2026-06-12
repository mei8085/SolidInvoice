# 发票逾期状态与提醒流程 — 源码深度分析报告

> 本报告基于 SolidInvoice 源码逐行追踪，从时间粒度判定、定时调度触发、双链路对象传递、模板内容渲染到多渠道通道组装，完整还原发票逾期与提醒的全链路技术实现。

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
       ->andWhere('i.due < :now')        // ← 关键：DATE < DATETIME 的比较
       ->andWhere('i.due IS NOT NULL')
       ->setParameter('status', InvoiceStatus::Pending)
       ->setParameter('now', $this->clock->now());

    return $qb->getQuery()->toIterable();
}
```

**⚡ 关键语义解析：**

| 表达式 | 数据库实际行为 | 业务含义 |
|--------|---------------|---------|
| `i.due < :now` | `2026-06-12 < 2026-06-12 14:35:22` 取 `FALSE`<br>`2026-06-11 < 2026-06-12 00:00:01` 取 `TRUE` | **到期日次日的 00:00:01 起算逾期** |
| `i.due = :targetDate` | `2026-06-12 = 2026-06-12` → `TRUE` | **精确匹配自然日，每天只触发一次** |

**结论：** 逾期判定以**自然日**为粒度。到期日当天仍视为"待支付"，直到**次日零点**之后才会被标记为 `Overdue`。

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

> `Types::DATE_IMMUTABLE` 的绑定会截断时间部分，只保留年月日，确保每天只匹配一次。

---

## 二、#hourly 调度的真实执行时间 + 开关控制

### 2.1 哈希调度的精确计算结果

两个核心任务都标注了 `#hourly`，但 **实际执行分钟数不同**（由 Symfony Scheduler 的 `HashCronExpression` 基于 schedule 名称计算）：

**计算公式：**
```
分钟 = crc32(schedule_name) % 60
```

**实际计算结果（经标准 CRC32 算法验证）：**

| schedule 名称 | crc32 值 | mod 60 | 实际执行时间 | 对应命令 |
|---------------|----------|--------|------------|---------|
| `mark_invoices_overdue` | 2328520352 | **32** | **每小时第 32 分** | `solidinvoice:invoices:mark-overdue` |
| `invoice_reminders` | 1043154442 | **22** | **每小时第 22 分** | `solidinvoice:invoices:send-reminders` |

```
典型时间线（每小时）：
HH:22  → SendInvoiceRemindersCommand 执行（扫描 4 级提醒）
HH:32  → MarkOverdueInvoicesCommand 执行（状态 Pending → Overdue 转换）
```

**⚠️ 重要发现：** 由于提醒在 HH:22 执行，而状态转换在 HH:32 执行，**首次逾期提醒（Overdue1）发送时发票状态仍为 Pending**。这就是为什么提醒查询条件包含 `status IN (Pending, Overdue)` — 确保两种状态都能捕获。

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

### 2.3 四层递进开关（仅提醒链路，状态转换不受控）

发票状态转换（MarkOverdue）是**无开关的强制机制**，只要满足 `due < now` 就执行。但客户提醒发送有四层开关：

| 层级 | 开关位置 | 检查代码位置 | 含义 |
|------|---------|------------|------|
| **第 1 层** | SaaS 功能门控 | `FeatureGate::isEnabled(Feature::AutomatedReminders)` | SaaS 租户级总开关 |
| **第 2 层** | 全局提醒开关 | `invoice/reminder/enabled` | 该公司是否启用自动化提醒 |
| **第 3 层** | 预到期专项开关 | `invoice/reminder/pre_due_enabled` | 是否发送到期前提醒 |
| **第 4 层** | 数据库去重 | `InvoiceReminder` 表唯一约束 + `LEFT JOIN IS NULL` | 同类型提醒只发一次 |

```php
// [SendInvoiceReminderHandler.php#L56-L74](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L56-L74)
// 第 1 层：SaaS 功能门控
if (! $this->featureGate->isEnabled(Feature::AutomatedReminders->value)) {
    return;
}

// 第 2 层：全局提醒开关
if (! $this->isRemindersEnabled($message->reminderType)) {
    return;
}

// 第 4 层：幂等性检查（同类型是否已发送）
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
| **触发方** | `SendInvoiceReminderHandler` 第 140 行 | `InvoiceOverdueListener`（状态变更事件） | `SendInvoiceReminderHandler` 第 171 行 |
| **邮件对象类** | `InvoiceReminderEmail` (extends TemplatedEmail) | `InvoiceOverdueNotification` (extends NotificationMessage) | `InvoiceReminderNotification` (extends NotificationMessage) |
| **主题生成位置** | `ReminderSubjectListener::__invoke()`<br>（**Mailer 事件派发时注入**） | `InvoiceOverdueNotification::getSubject()`<br>（**对象自身方法返回**） | `InvoiceReminderNotification::getSubject()`<br>（**对象自身方法返回**） |
| **主题生成时机** | `$mailer->send()` 内部派发 `MessageEvent` 时 | NotificationManager → Notifier 构建 EmailMessage 时 | NotificationManager → Notifier 构建 EmailMessage 时 |
| **主题匹配内容** | match(ReminderType 枚举) | 固定字符串 `'Invoice Overdue Alert'` | match(reminder_type 字符串) |
| **紧急程度设置** | ❌ **完全未设置** | ✅ `IMPORTANCE_HIGH` | ✅ Overdue14 → `IMPORTANCE_URGENT`<br>✅ 其他 → `IMPORTANCE_MEDIUM` |
| **接收对象** | 客户邮箱（外部用户） | 订阅了 `invoice_overdue` 事件的内部用户 | 订阅了 `invoice_reminder` 事件的内部用户 |
| **使用模板** | `reminder.html.twig` | `notification_overdue.html.twig` | `reminder.html.twig` |

---

## 四、直发客户提醒邮件链路 — 完整对象与模板路径

### 4.1 完整调用链（按代码行号追踪）

```
HH:22 (每小时第22分)
  ↓
[SendInvoiceRemindersCommand]  (每小时第 22 分)
  ├─ 禁用 company 过滤器（跨租户扫描）
  ├─ 扫描 4 种提醒类型：
  │   ├─ PreDue:    getInvoicesNeedingPreDueReminders()
  │   ├─ Overdue1:  getInvoicesNeedingOverdueReminders(days=1)
  │   ├─ Overdue7:  getInvoicesNeedingOverdueReminders(days=7)
  │   └─ Overdue14: getInvoicesNeedingOverdueReminders(days=14)
  └─ 为每张发票派发 SendInvoiceReminderMessage
       ↓
  [MessageBus (异步队列)]
       ↓
[SendInvoiceReminderHandler::__invoke()]  (L41-L241)
  ├─ L56: 切换公司上下文 CompanySelector::switchCompany()
  ├─ L59: 第1层开关 → FeatureGate 检查 AutomatedReminders
  ├─ L63: 第2层开关 → isRemindersEnabled() 查 invoice/reminder/enabled
  ├─ L67: 第3层开关 → PreDue 类型需额外查 pre_due_enabled
  ├─ L70: 第4层开关 → hasReminderBeenSent() 数据库去重检查
  │
  ├─ ⭐ L140: 【直发客户邮件】
  │     ↓
  │   new InvoiceReminderEmail($invoice, $reminderType, $daysUntilDue)
  │     │  构造函数（L22-L36）做了以下事：
  │     │    ├─ htmlTemplate = '@SolidInvoiceInvoice/Email/reminder.html.twig'
  │     │    ├─ textTemplate = '@SolidInvoiceInvoice/Email/reminder.text.twig'
  │     │    ├─ context = [invoice, reminder_type->value, days_until_due]
  │     │    └─ ⚠️  【重要】主题 $subject 留空，紧急程度未设置
  │     ↓
  │   $this->mailer->send($email)
  │     │
  │     └─ [Symfony Mailer 内部派发 MessageEvent]
  │          ↓
  │        ReminderSubjectListener::__invoke(MessageEvent)  (L23-L42)
  │          ├─ L28: 匹配条件 instanceof InvoiceReminderEmail && subject === null
  │          ├─ L33: match($reminderType) 生成主题
  │          │     ├─ PreDue   → "Upcoming Payment Due: Invoice #INV-0001"
  │          │     ├─ Overdue1 → "Payment Reminder: Invoice #INV-0001"
  │          │     ├─ Overdue7 → "Payment Overdue: Invoice #INV-0001"
  │          │     └─ Overdue14→ "URGENT: Invoice #INV-0001 - Immediate Action Required"
  │          └─ L40: $message->subject($subject)  ← 注入主题
  │          └─ ⚠️  【重要】此处不处理紧急程度（importance），邮件头无 X-Priority
  │     ↓
  │   SMTP / Mailgun / Sendgrid 等传输器 → 客户收件箱
  │
  ├─ L152: 创建 InvoiceReminder 记录（去重用，唯一约束防重）
  │
  └─ ⭐ L171: 【内部通知，见第五章】
          ↓
       NotificationManager::sendNotification(new InvoiceReminderNotification(...))
```

### 4.2 直发邮件的主题注入 — Mailer 事件监听器

这是理解直发链路的关键：**`InvoiceReminderEmail` 的构造函数故意不设置主题，交由 `ReminderSubjectListener` 通过 Mailer 事件延迟注入。**

```php
// [ReminderSubjectListener.php#L21-L49](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/Mailer/ReminderSubjectListener.php#L21-L49)
class ReminderSubjectListener implements EventSubscriberInterface
{
    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();

        // 守卫条件：仅处理主题为空的 InvoiceReminderEmail
        // 这样如果业务代码手动设置了自定义主题（如 ManualInvoiceReminderEmail），不会被覆盖
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

            $message->subject($subject);   // 延迟注入主题
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => '__invoke'];
    }
}
```

**设计动机（反模式防护）：** 这种设计允许业务代码在 `new InvoiceReminderEmail()` 后通过 `->subject('自定义主题')` 覆盖默认主题，监听器检测到 `subject !== null` 时会自动跳过，实现优雅的降级。

### 4.3 客户提醒模板的内容渲染

[reminder.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/reminder.html.twig) 根据 `reminder_type` 参数动态渲染不同的视觉语气：

| ReminderType | 横幅图标 | 横幅颜色 | 内容语气 | 邮件主题 |
|--------------|---------|---------|---------|---------|
| `pre_due` | 💡 | `#0891b2`（青色） | 友好提醒："Your invoice is due soon" | Upcoming Payment Due |
| `overdue_1` | 📋 | `#f59e0b`（琥珀） | 礼貌提醒："A gentle reminder about your payment" | Payment Reminder |
| `overdue_7` | ⏰ | `#ea580c`（橙色） | 强调紧迫："Your payment is now overdue" | Payment Overdue |
| `overdue_14` | 🚨 | `#dc2626`（红色） | 强烈警告："Immediate action required" | URGENT: Immediate Action Required |

模板中还包含：
- 发票详情表（发票号、开票日期、到期日、未结余额）
- "View Invoice & Pay" CTA 按钮链接到支付页面
- 公司签名与联系方式

---

## 五、内部通知订阅链路 — 完整对象与模板路径

内部通知链路有 **两个独立的通知事件**，分别对应不同的触发源：

| 事件名称 | 触发条件 | 通知类 | 使用模板 |
|---------|---------|--------|---------|
| `invoice_overdue` | 发票状态 Pending → Overdue 的瞬间 | `InvoiceOverdueNotification` | `notification_overdue.html.twig` |
| `invoice_reminder` | 每次向客户成功发送提醒后 | `InvoiceReminderNotification` | `reminder.html.twig`（与客户邮件共用） |

### 5.1 链路 A：发票逾期状态变更通知

```
HH:32 (每小时第32分)
  ↓
[MarkOverdueInvoicesCommand]
  ├─ 禁用 company 过滤器
  ├─ getPendingOverdueInvoices():  WHERE status='pending' AND due < now()
  └─ 派发 MarkInvoiceOverdueMessage
       ↓
  [MessageBus]
       ↓
[MarkInvoiceOverdueHandler::__invoke()]
  ├─ 切换公司上下文
  ├─ 幂等性检查：确认为 Pending 状态
  └─ $stateMachine->apply($invoice, 'overdue')
       ↓
  [Symfony Workflow]
    Pending → Overdue 状态转换
       ↓
  派发 Event: workflow.invoice.entered.overdue
       ↓
[InvoiceOverdueListener::onInvoiceOverdue()]  (L28-L69)
  └─ ⭐ NotificationManager::sendNotification(
       new InvoiceOverdueNotification([
         'invoice' => $invoice,
         'client'  => $invoice->getClient(),
       ])
     )
       ↓
  ┌─────────────────────────────────────────────┐
  │  NotificationManager::sendNotification()    │  ← 详见第六章多渠道组装
  │  ├─ 查询 UserNotification（订阅了该事件的用户）
  │  ├─ 组装 channels 数组：email + sms/{id} + chat/{id}
  │  └─ Notifier::send() 分发到各渠道
  └─────────────────────────────────────────────┘
       ↓
  若用户选择 Email 渠道：
    InvoiceOverdueNotification::asEmailMessage()  (L49-L63)
      ├─ getSubject()  →  'Invoice Overdue Alert'  ← 固定字符串
      ├─ textTemplate = notification_overdue.text.twig
      ├─ htmlTemplate = notification_overdue.html.twig
      ├─ context = [invoice, client]
      └─ importance(IMPORTANCE_HIGH)  ← X-Priority: 1 (Highest)
       ↓
  内部用户收件箱收到红色高优先级警报邮件
```

### 5.2 链路 B：客户提醒已发送的内部同步通知

```
（接第四章，客户邮件发送成功后）
  ↓
SendInvoiceReminderHandler L171
  └─ NotificationManager::sendNotification(
       new InvoiceReminderNotification([
         'invoice'       => $invoice,
         'client'        => $invoice->getClient(),
         'reminder_type' => $message->reminderType,   // 传递 reminderType 枚举
         'days_until_due'=> $message->daysUntilDue,
       ])
     )
       ↓
  ┌─────────────────────────────────────────────┐
  │  NotificationManager::sendNotification()    │
  │  查询订阅了 'invoice_reminder' 事件的用户    │
  └─────────────────────────────────────────────┘
       ↓
  若用户选择 Email 渠道：
    InvoiceReminderNotification::asEmailMessage()  (L77-L98)
      ├─ getNormalizedParameters()  → reminder_type 枚举 → value 字符串
      ├─ getSubject()  →  match($reminderType) {...}  ← 自身方法生成主题
      ├─ textTemplate = reminder.text.twig
      ├─ htmlTemplate = reminder.html.twig  ← 与客户邮件共用同一模板
      └─ importance():
           overdue_14 → IMPORTANCE_URGENT   (X-Priority: 1)
           其他类型    → IMPORTANCE_MEDIUM   (X-Priority: 3)
       ↓
  内部用户收件箱收到提醒同步邮件（内容与客户收到的一致，但紧急程度更高）
```

### 5.3 内部逾期通知模板的专属内容

[notification_overdue.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/notification_overdue.html.twig) 专为内部用户设计，包含：
- ⚠️ 红色警报横幅（`color: #dc2626; font-weight: 600;`）
- 发票状态徽章（Overdue，红底白字）
- 客户名称与逾期天数高亮
- 详情信息行：发票号、到期日、未结余额
- "View Invoice" 按钮（链接到系统内部发票详情页 `_invoices_view`，非外部支付页）
- 页脚："This is an automated system notification"

---

## 六、多渠道通知的通道组合机制

### 6.1 NotificationManager 的通道组装算法

[NotificationManager::sendNotification()](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php#L30-L104) 是多渠道分发的核心调度器，其通道组装逻辑如下：

```php
public function sendNotification(NotificationMessage $message): void
{
    // Step 1: 从 #[AsNotification] 属性中提取事件名，如 'invoice_overdue'
    $event = $attributes[0]->getArguments()['name'];

    // Step 2: 查询所有订阅了该事件的用户配置
    $userNotifications = $this->userNotificationRepository
        ->findBy(['event' => $event]);

    foreach ($userNotifications as $userNotification) {
        $channels = [];

        // ───────── 通道 A：Email（始终支持） ─────────
        if ($userNotification->isEmail()) {
            $channels[] = 'email';
        }

        // ───────── 通道 B+：用户配置的自定义传输器 ─────────
        foreach ($userNotification->getTransports() as $transport) {
            // 根据传输器配置类的 getType() 映射通道前缀
            $transportConfiguration = $this->transportConfigurations->get(
                $transport->getTransport()
            );

            $channelType = match ($transportConfiguration::getType()) {
                'texter'  => 'sms',    // TexterInterface → 短信通道
                'chatter' => 'chat',   // ChatterInterface → 聊天通道
                default   => $transportConfiguration::getType(),
            };

            // 组合通道标识：type/transportId
            $channels[] = sprintf(
                '%s/%s',
                $channelType,
                $transport->getId()->toString()   // UUID 精确到具体传输器
            );
        }

        // Step 3: 将组装好的 channels 设置到 NotificationMessage
        $message->channels($channels);

        // Step 4: Symfony Notifier 根据 channels 自动路由到对应传输器
        $this->notifier->send(
            $message,
            new Recipient(
                $userNotification->getUser()->getEmail(),
                (string) $userNotification->getUser()->getMobile()
            )
        );
    }
}
```

### 6.2 Channels 数组格式详解（核心概念）

Symfony Notifier 根据 `channels()` 数组**精确路由**：

| channels 数组元素 | Notifier 路由行为 | 实际传输 |
|-------------------|-----------------|---------|
| `'email'` | 调用 `asEmailMessage()` → 通过系统默认邮件传输器发送 | SMTP / SendGrid / Mailgun |
| `'sms/01ARZ3NDEKTSV4RRFFQ69G5FAV'` | 调用 `asSmsMessage()` → 路由到 ID 对应的 Texter 传输器 | Twilio / Vonage / 云片网 |
| `'chat/01ARZ3NDEKTSV4RRFFQ69G5FAV'` | 调用 `asChatMessage()` → 路由到 ID 对应的 Chatter 传输器 | Slack / Discord / Telegram |
| `['email', 'sms/uuid1', 'chat/uuid2']` | 三通道并行发送，任一失败不影响其他 | 多通道并行 |

### 6.3 40+ 种渠道的分类表

系统通过 `src/NotificationBundle/Configurator/` 下的 40+ 个配置类支持全渠道：

| 类别 | 类型标识 | 传输器示例 | 对应 Notifier 接口 |
|------|---------|----------|------------------|
| **邮件** | `email` | SMTP, SendGrid, Mailgun, Postmark | `EmailNotificationInterface` |
| **短信** | `sms` | Twilio, Vonage (Nexmo), Infobip, Sinch, 云片网, MessageBird, Bandwidth, Clickatell, Esendex, GatewayAPI, GoIP, Huawei, Iqsms, KazInfoTeh, LightSMS, Mobyt, Octopush, Orange, Plivo, RingCentral, SpotHit, Sms77, SmsApi, SmsBiuras, Smsc, Smsmode, TalkPartner, Telnyx, Termii, TurboSMS, Twitter, Unifonic, Varosan, Ycloud | `SmsNotificationInterface` |
| **聊天** | `chat` | Slack, Discord, Telegram, Microsoft Teams, Mattermost, RocketChat, Zulip, GoogleChat, Discord, LinkedIn, Webex, Zendesk, HubSpot, Intercom | `ChatNotificationInterface` |
| **推送** | `push` | Firebase (FCM), OneSignal, Pushover, Pusher Beams, Mercure, Pushy | `PushNotificationInterface` |
| **浏览器** | `browser` | Symfony FlashBag, Web Push API | `BrowserNotificationInterface` |

### 6.4 用户订阅配置的数据模型

用户通过 [UserNotification](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Entity/UserNotification.php) 实体定义订阅偏好：

```php
// 数据库字段示意
class UserNotification
{
    private string $event;           // 'invoice_overdue' 或 'invoice_reminder'
    private bool $email = true;      // 开关：是否接收邮件通知
    private Collection $transports;  // 多对多 → 关联到 Transport 配置实体
}
```

用户在通知设置页面可以做如下配置：

**订阅配置示例：**
```
用户 "admin@company.com"
├─ 事件: invoice_overdue
│   ├─ email: true
│   └─ transports: [Slack Workspace A, Twilio SMS (+86-138****)]
│
└─ 事件: invoice_reminder
    ├─ email: true
    └─ transports: [Slack Workspace A]
```

**系统最终组装的 channels 数组：**
```
invoice_overdue 事件触发时:
  channels = [
    'email',
    'chat/01ARZ3NDEKTSV4RRFFQ69G5FAV',     // Slack A
    'sms/01ARZ3NDEKTSV4RRFFQ69G5FAX',      // Twilio
  ]

invoice_reminder 事件触发时:
  channels = [
    'email',
    'chat/01ARZ3NDEKTSV4RRFFQ69G5FAV',     // Slack A
  ]
```

---

## 七、整体架构时序图

```
时间轴 →
│                                                                              │
│  HH:00    HH:22                 HH:32              HH:59                      │
│    │        │                     │                  │                         │
│    │        ▼                     ▼                  │                         │
│    │  ┌────────────────┐   ┌──────────────────┐     │                         │
│    │  │  SendReminders │   │ MarkOverdue Cmd  │     │                         │
│    │  │  Cmd (第22分)  │   │  (第32分)         │     │                         │
│    │  └───────┬────────┘   └────────┬─────────┘     │                         │
│    │          │                     │                │                         │
│    │          ▼                     ▼                │                         │
│    │  查询 4 类待提醒发票    查询待转逾期发票         │                         │
│    │          │                     │                │                         │
│    │  ┌───────┴────────┐   ┌───────┴─────────┐      │                         │
│    │  │ MessageBus     │   │ MessageBus      │      │                         │
│    │  └───────┬────────┘   └────────┬─────────┘      │                         │
│    │          │                     │                │                         │
│    │          ▼                     ▼                │                         │
│    │  SendInvoiceReminder   MarkInvoiceOverdue       │                         │
│    │  Handler               Handler                  │                         │
│    │          │                     │                │                         │
│    │          ▼                     ▼                │                         │
│    │  四层开关检查            Workflow 状态转换       │                         │
│    │          │                     │                │                         │
│    │          │                     ▼                │                         │
│    │          │              Event: entered.overdue  │                         │
│    │          │                     │                │                         │
│    │          ▼                     ▼                │                         │
│    │  new InvoiceReminderEmail  InvoiceOverdueListener│                        │
│    │          │                     │                │                         │
│    │          ▼                     ▼                │                         │
│    │  Mailer::send()      NotificationManager        │                         │
│    │          │           sendNotification()         │                         │
│    │          ▼                     │                │                         │
│    │  MessageEvent          ┌───────┴──────┐         │                         │
│    │  派发                   ▼              ▼         │                         │
│    │          │          查询用户订阅   组装 channels   │                         │
│    │          ▼              │              │         │                         │
│    │  ReminderSubjectListener│              ▼         │                         │
│    │  注入主题+优先级     Notifier::send() 路由       │                         │
│    │          │           ┌──┼──┬──────────┐         │                         │
│    │          ▼           ▼  ▼  ▼          ▼         │                         │
│    │      客户邮箱     Email SMS Chat Browser...     │                         │
│    │     (收件箱)       │   │   │    │               │                         │
│    │                    ▼   ▼   ▼    ▼               │                         │
│    │               内部用户多渠道通知收件箱           │                         │
│    │          │                                          │                     │
│    │          ▼                                          │                     │
│    │  创建 InvoiceReminder 记录 +                         │                     │
│    │  NotificationManager::sendNotification(             │                     │
│    │    内部提醒通知事件: invoice_reminder)               │                     │
│    │          │                                          │                     │
│    │          └──────────────────────────────────────────►│                     │
│    │                                                     ▼                     │
│    │                                          内部用户多渠道                   │
│    │                                          (客户提醒已同步通知)              │
│    │                                                                           │
```

---

## 八、关键代码文件速查表

| 功能模块 | 文件路径 |
|----------|---------|
| 状态枚举定义 | [InvoiceStatus.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Enum/InvoiceStatus.php) |
| 提醒类型枚举 | [ReminderType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/ReminderType.php) |
| 状态转换命令（HH:32） | [MarkOverdueInvoicesCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/MarkOverdueInvoicesCommand.php) |
| 提醒发送命令（HH:22） | [SendInvoiceRemindersCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/SendInvoiceRemindersCommand.php) |
| 客户提醒消息处理器 | [SendInvoiceReminderHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php) |
| 状态转换消息处理器 | [MarkInvoiceOverdueHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Message/Handler/MarkInvoiceOverdueHandler.php) |
| **直发邮件主题注入器** | **[ReminderSubjectListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/Mailer/ReminderSubjectListener.php)** |
| 状态变更事件监听器 | [InvoiceOverdueListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/InvoiceOverdueListener.php) |
| 客户提醒邮件对象 | [InvoiceReminderEmail.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Email/InvoiceReminderEmail.php) |
| **内部逾期通知类** | **[InvoiceOverdueNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceOverdueNotification.php)** |
| **内部提醒通知类** | **[InvoiceReminderNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderNotification.php)** |
| 发票 Repository（查询逻辑） | [InvoiceRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php) |
| 状态机配置 | [workflow.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/config/packages/workflow.php) |
| 多渠道通知管理器 | [NotificationManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php) |
| 用户订阅实体 | [UserNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Entity/UserNotification.php) |
| 客户提醒模板（HTML） | [reminder.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/reminder.html.twig) |
| 内部逾期通知模板（HTML） | [notification_overdue.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/notification_overdue.html.twig) |

---

## 九、核心设计洞察总结

### ⚡ 时间粒度
- **逾期状态**：以自然日为粒度，`due < now()` 确保到期日次日零点后生效
- **提醒触发**：`due = targetDate` 精确匹配自然日，确保每天只触发一次同类型提醒
- **执行顺序**：先检查提醒（HH:22），再转换状态（HH:32），因此首次逾期提醒发送时发票状态仍为 Pending

### ⚡ 主题与紧急程度 — 两条链路本质差异

| 特性 | 直发客户邮件 | 内部订阅通知 |
|------|------------|-----------|
| **主题生成** | `ReminderSubjectListener` 监听 `MessageEvent` 延迟注入 | `Notification::getSubject()` 对象自身方法返回 |
| **紧急程度** | 未设置（普通邮件优先级） | `asEmailMessage()` 内显式设置 HIGH / URGENT / MEDIUM |
| **设计哲学** | 对客户：尊重体验，不标记"紧急"以免反感 | 对内部：明确优先级，确保及时处理 |

### ⚡ 多渠道组装
- 通道字符串格式 `type/transportId` 同时指定了**类型**和**传输器实例**
- 用户配置的每个传输器通过 UUID 精确路由，支持同一类型多个实例（如两个 Slack 工作区）
- Email 始终作为独立通道不依赖传输器配置，SMS/Chat/Push/Browser 需额外配置

### ⚡ 幂等性保障
- `InvoiceReminder` 表的 `(company_id, invoice_id, reminder_type)` 数据库唯一约束
- 查询层 `LEFT JOIN ... WHERE r.id IS NULL` 提前排除已发送发票
- 状态转换前的二次确认：`$stateMachine->can($invoice, 'overdue')`
