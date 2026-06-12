# 发票逾期状态与提醒流程 - 源码深度解析

## 目录

- [一、逾期判定的时间粒度分析](#一逾期判定的时间粒度分析)
- [二、定时任务与开关控制体系](#二定时任务与开关控制体系)
- [三、客户提醒模板实施细节](#三客户提醒模板实施细节)
- [四、内部逾期通知模板实施](#四内部逾期通知模板实施)
- [五、多渠道通知通道组合机制](#五多渠道通知通道组合机制)
- [六、完整流程图](#六完整流程图)
- [七、核心代码速查表](#七核心代码速查表)

---

## 一、逾期判定的时间粒度分析

### 1.1 到期日字段存储格式

发票到期日字段 `due` 使用 **`DATE_IMMUTABLE` 类型**（仅存储日期，不存储具体时分秒）：

```php
// [Invoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L169-L172)
#[ORM\Column(name: 'due', type: Types::DATE_IMMUTABLE, nullable: true)]
#[Assert\Type(type: DateTimeInterface::class)]
#[Groups(['invoice_api:read', 'invoice_api:write'])]
private ?DateTimeInterface $due = null;
```

| 存储特性 | 说明 |
|----------|------|
| 数据库类型 | `DATE`（如 `2026-06-10`） |
| PHP 类型 | `DateTimeImmutable` |
| 粒度 | **自然日级别**，无时间分量 |

### 1.2 逾期状态判定逻辑（自然日粒度）

[InvoiceRepository::getPendingOverdueInvoices()](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L495-L506)

```php
public function getPendingOverdueInvoices(): iterable
{
    $qb = $this->createQueryBuilder('i');

    $qb->where('i.status = :status')
        ->andWhere('i.due < :now')           // 关键：due 日期 < 当前时间戳
        ->andWhere('i.due IS NOT NULL')
        ->setParameter('status', InvoiceStatus::Pending)
        ->setParameter('now', $this->clock->now());  // 带完整时间的 DateTime

    return $qb->getQuery()->toIterable();
}
```

**判定规则详解：**

由于 `due` 存储的是纯日期（如 `2026-06-10`），在与 `now()`（如 `2026-06-11 03:15:22`）比较时，数据库会将 `due` 自动补零为 `2026-06-10 00:00:00`，因此：

| 场景 | due 值 | now() 值 | 比较结果 | 是否逾期 |
|------|--------|----------|----------|----------|
| 到期当天早上 | `2026-06-10` | `2026-06-10 09:00:00` | `2026-06-10 00:00 < 09:00` = true | **是** ✅ |
| 到期当天 00:00 前 | `2026-06-10` | `2026-06-09 23:59:59` | `2026-06-10 < 2026-06-09` = false | 否 ❌ |
| 到期次日 | `2026-06-10` | `2026-06-11 03:00:00` | true | **是** ✅ |
| 到期日前一天 | `2026-06-10` | `2026-06-09 12:00:00` | false | 否 ❌ |

> **结论：** 逾期状态基于 **自然日** 判定，只要进入到期日当天的 00:00:01 即算作逾期。由于定时任务每小时执行，实际生效时间为到期日当天的首个整点小时。

### 1.3 提醒触发的日期匹配（精确自然日）

提醒查询使用 **精确日期匹配**（`i.due = :targetDate`），确保每个提醒在特定日期只触发一次：

```php
// [InvoiceRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L537-L553)
public function getInvoicesNeedingOverdueReminders(int $daysOverdue, ReminderType $reminderType): iterable
{
    // 计算目标日期：N 天前的那一天（精确日期）
    $targetDate = $this->clock->now()->modify("-{$daysOverdue} days");

    $qb = $this->createQueryBuilder('i');

    $qb->leftJoin(InvoiceReminder::class, 'r', 'WITH', 'r.invoice = i.id AND r.reminderType = :reminderType')
        ->where('i.status  in (:pending, :overdue)')
        ->andWhere('i.due = :targetDate')    // 精确匹配日期
        ->andWhere('r.id IS NULL')            // 且此类型提醒未发送过
        ->setParameter('targetDate', $targetDate, Types::DATE_IMMUTABLE);

    return $qb->getQuery()->toIterable();
}
```

预到期提醒同理：

```php
// [InvoiceRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L514-L529)
public function getInvoicesNeedingPreDueReminders(int $daysBeforeDue): iterable
{
    // 目标日期 = N 天后的那一天
    $targetDate = $this->clock->now()->modify("+{$daysBeforeDue} days");
    // ...
    ->andWhere('i.due = :targetDate')
    ->setParameter('targetDate', $targetDate, Types::DATE_IMMUTABLE)
}
```

**提醒触发时机（以到期日 D 为例）：**

| 提醒类型 | days 参数 | 目标日期计算 | 触发日期 |
|----------|-----------|--------------|----------|
| PreDue（预到期） | `pre_due_days`（如 3） | `now + 3天 = D` | `D - 3` |
| Overdue1（逾期1天） | 1 | `now - 1天 = D` | `D + 1` |
| Overdue7（逾期7天） | 7 | `now - 7天 = D` | `D + 7` |
| Overdue14（逾期14天） | 14 | `now - 14天 = D` | `D + 14` |

---

## 二、定时任务与开关控制体系

### 2.1 两个核心定时任务

#### 任务一：标记逾期状态

[MarkOverdueInvoicesCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/MarkOverdueInvoicesCommand.php#L29-L105)

```php
#[AsCommand(
    name: 'solidinvoice:invoices:mark-overdue',
    description: 'Mark pending invoices as overdue when past due date',
)]
#[AsCronTask('#hourly', schedule: 'mark_invoices_overdue')]  // 每小时执行
final class MarkOverdueInvoicesCommand extends Command
```

**职责：**
1. 跨公司查询所有 `Pending` 且 `due < now` 的发票
2. 为每张发票派发异步 `MarkInvoiceOverdue` 消息
3. 使用 `toIterable()` + `detach()` 处理大数据量

**Cron 表达式说明：** `#hourly` 是 Symfony Scheduler 的哈希语法，表示每小时执行一次，分钟数由名称哈希确定（避免所有任务同时运行）。

#### 任务二：发送付款提醒

[SendInvoiceRemindersCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/SendInvoiceRemindersCommand.php#L41-L216)

```php
#[AsCommand(
    name: 'solidinvoice:invoices:send-reminders',
    description: 'Send payment reminders for pending and overdue invoices',
)]
#[AsCronTask(expression: '#hourly', schedule: 'invoice_reminders')]  // 每小时执行
final class SendInvoiceRemindersCommand extends Command
```

**职责：**
1. 按公司维度遍历（先查询所有开启了提醒的公司）
2. **预到期提醒：** 根据公司配置的 `pre_due_days` 查找对应发票
3. **逾期提醒：** 按固定天数（1、7、14天）分三档处理
4. 每档提醒单独派发 `SendInvoiceReminderMessage` 异步消息

**逾期提醒的天数与类型映射：**

```php
// [SendInvoiceRemindersCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/SendInvoiceRemindersCommand.php#L51-L55)
private array $reminderTypes = [
    1 => ReminderType::Overdue1,    // 逾期 1 天
    7 => ReminderType::Overdue7,    // 逾期 7 天
    14 => ReminderType::Overdue14,  // 逾期 14 天
];
```

### 2.2 四层开关控制

提醒系统采用 **四层递进式开关**，任一关卡关闭即跳过处理：

```
异步消息到达 SendInvoiceReminderHandler
    ↓
【第1层】SaaS 功能门控 → Feature::AutomatedReminders
    ↓ 开启才继续
【第2层】全局提醒开关 → invoice/reminder/enabled = '1'
    ↓ 开启才继续
【第3层】类型专项开关 → pre_due 需检查 pre_due_enabled
    ↓ 开启才继续
【第4层】幂等性检查 → hasReminderBeenSent() 去重
    ↓ 未发送才继续
执行发送
```

#### 第 1 层：SaaS 功能门控

[SendInvoiceReminderHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L66-L74)

```php
if (! $this->featureGate->isEnabled(Feature::AutomatedReminders->value)) {
    $this->logger->info('Automated reminders feature is disabled for plan, skipping reminder', [...]);
    return;  // 当前订阅套餐未开通自动化提醒
}
```

**适用场景：** SaaS 多租户环境，不同订阅等级的功能限制。

#### 第 2 层：全局提醒开关

```php
// [SendInvoiceReminderHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L225-L240)
private function isRemindersEnabled(ReminderType $reminderType): bool
{
    $enabled = $this->systemConfig->get('invoice/reminder/enabled');
    if ($enabled !== '1') {
        return false;  // 公司级主开关关闭
    }
    // ...
}
```

**配置键：** `invoice/reminder/enabled`，值为 `'1'` 表示开启。

#### 第 3 层：预到期专项开关

```php
// PreDue 类型需要额外检查专属开关
if ($reminderType === ReminderType::PreDue) {
    return $this->systemConfig->get('invoice/reminder/pre_due_enabled') === '1';
}
// 逾期提醒（Overdue1/7/14）只需全局开启即可
return true;
```

**配置键：**
- `invoice/reminder/pre_due_enabled`：预到期提醒开关
- `invoice/reminder/pre_due_days`：预到期提醒的提前天数（如 `3` = 到期前3天提醒）

#### 第 4 层：数据库去重（幂等性）

```php
// [SendInvoiceReminderHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L106-L114)
if ($this->reminderRepository->hasReminderBeenSent($invoice, $message->reminderType)) {
    $this->logger->info('Reminder already sent, skipping duplicate creation', [...]);
    return;
}
```

对应数据库层的唯一约束：

```php
// [InvoiceReminder.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/InvoiceReminder.php#L27-L28)
#[ORM\UniqueConstraint(columns: ['company_id', 'invoice_id', 'reminder_type'])]
```

---

## 三、客户提醒模板实施细节

### 3.1 邮件类构造

[InvoiceReminderEmail.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Email/InvoiceReminderEmail.php#L20-L52)

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
            'invoice'         => $this->invoice,
            'reminder_type'   => $this->reminderType->value,  // 传递枚举值字符串
            'days_until_due'  => $this->daysUntilDue,         // 仅 PreDue 有值
        ]);
    }
}
```

### 3.2 提醒类型枚举

[ReminderType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/ReminderType.php#L16-L22)

```php
enum ReminderType: string
{
    case PreDue    = 'pre_due';      // 到期前 N 天
    case Overdue1  = 'overdue_1';    // 逾期 1 天
    case Overdue7  = 'overdue_7';    // 逾期 7 天
    case Overdue14 = 'overdue_14';   // 逾期 14 天
}
```

### 3.3 Twig 模板动态内容渲染

[reminder.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/reminder.html.twig) 使用 **多级 if-else** 根据 `reminder_type` 渲染完全不同的内容：

#### 3.3.1 动态邮件标题

```twig
{%- block title -%}
    {%- if reminder_type == 'pre_due' -%}        Upcoming Payment Due
    {%- elseif reminder_type == 'overdue_1' -%}   Payment Reminder
    {%- elseif reminder_type == 'overdue_7' -%}   Payment Overdue
    {%- elseif reminder_type == 'overdue_14' -%}  Urgent: Payment Required
    {%- else -%}                                  Invoice Reminder
    {%- endif -%}
{%- endblock -%}
```

#### 3.3.2 分级内容与视觉样式

每种提醒类型使用不同的 **颜色、图标、语气** 形成渐进式的压力传导：

| 类型 | 颜色代码 | 视觉标识 | 语气风格 | 内容片段 |
|------|----------|----------|----------|----------|
| **PreDue** | `#0891b2` 青色 | 💡 | 友好提示 | "This is a friendly reminder that invoice ... is due in X days" |
| **Overdue1** | `#f59e0b` 琥珀色 | 📋 | 礼貌提醒 | "Invoice ... became overdue yesterday. If you've already sent payment, please disregard." |
| **Overdue7** | `#ea580c` 橙色 | ⏰ | 强调紧迫 | "Invoice ... is now 7 days overdue. Please arrange payment at your earliest convenience." |
| **Overdue14** | `#dc2626` 红色 | 🚨 | 强烈警告 | "Invoice ... remains unpaid after 14 days. This is our final automated reminder. Immediate payment is required." |

模板代码片段（逾期14天示例）：

```twig
{%- elseif reminder_type == 'overdue_14' -%}
    <p style="color: #dc2626; font-size: 18px; font-weight: 600; line-height: 1.5;">
        🚨 Urgent: Immediate Action Required
    </p>
    <p style="color: #1e293b; font-size: 16px; line-height: 1.5;">
        Invoice <strong>{{ invoice.invoiceId }}</strong> remains unpaid after 14 days.
        This is our final automated reminder. Immediate payment is required to avoid
        service interruption. Please contact us if you need to discuss payment arrangements.
    </p>
```

#### 3.3.3 公共信息区块

无论哪种类型，模板都包含统一的发票详情展示：

```twig
{# 使用 email 组件宏渲染信息行 #}
{{ email.info_row('Invoice Number', invoice.invoiceId) }}
{{ email.spacer('xs') }}
{{ email.info_row('Invoice Date', invoice.invoiceDate|date('Y-m-d')) }}
{{ email.spacer('xs') }}
{{ email.info_row('Due Date', invoice.due|date('Y-m-d')) }}
{{ email.spacer('xs') }}
{{ email.info_row('Amount Due', invoice.balance|formatCurrency(invoice.client.currency), true) }}
```

以及 CTA 按钮（链接到外部支付页面）：

```twig
{{ email.button('View Invoice & Pay', url("_view_invoice_external", {"uuid" : invoice.uuid}), 'primary', 'large') }}
```

### 3.4 邮件主题动态生成（PHP 端）

[InvoiceReminderNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderNotification.php#L62-L75)

```php
public function getSubject(): string
{
    $parameters = $this->getNormalizedParameters();
    $reminderType = $parameters['reminder_type'] ?? '';
    $invoiceId = $parameters['invoice']?->getInvoiceId() ?? '';

    return match ($reminderType) {
        'pre_due'    => "Upcoming Payment Due: Invoice {$invoiceId}",
        'overdue_1'  => "Payment Reminder: Invoice {$invoiceId}",
        'overdue_7'  => "Payment Overdue: Invoice {$invoiceId}",
        'overdue_14' => "URGENT: Invoice {$invoiceId} - Immediate Action Required",
        default      => "Invoice Payment Reminder: {$invoiceId}",
    };
}
```

同时设置邮件重要性等级：

```php
// [InvoiceReminderNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderNotification.php#L90-L94)
$importance = in_array($reminderType, ['overdue_14'])
    ? NotificationEmail::IMPORTANCE_URGENT   // 14天逾期标记为紧急
    : NotificationEmail::IMPORTANCE_MEDIUM;  // 其他为中等
$email->importance($importance);
```

---

## 四、内部逾期通知模板实施

### 4.1 通知类定义

[InvoiceOverdueNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceOverdueNotification.php#L24-L64)

```php
#[AsNotification(
    name: self::EVENT,                          // 'invoice_overdue'
    title: 'Invoice Overdue',
    description: 'When an invoice becomes overdue (past due date while still pending)',
    icon: 'tabler:alert-triangle',
    category: NotificationCategory::INVOICE,
)]
class InvoiceOverdueNotification extends NotificationMessage
{
    public const EVENT = 'invoice_overdue';
    final public const HTML_TEMPLATE = '@SolidInvoiceInvoice/Email/notification_overdue.html.twig';
    final public const TEXT_TEMPLATE = '@SolidInvoiceInvoice/Email/notification_overdue.text.twig';
}
```

### 4.2 触发时机

[InvoiceOverdueListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/InvoiceOverdueListener.php#L38-L43)

```php
public static function getSubscribedEvents(): array
{
    return [
        // 监听 Symfony Workflow 的状态进入事件
        'workflow.invoice.entered.overdue' => 'onInvoiceOverdue',
    ];
}
```

> 每当发票通过状态机从 `Pending` 成功转换到 `Overdue` 状态时，该监听器被触发，向订阅了 `invoice_overdue` 事件的内部用户发送通知。

### 4.3 模板结构

[notification_overdue.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/notification_overdue.html.twig)

```twig
{% extends "@SolidInvoiceCore/Layout/Email/notification.html.twig" %}

{%- block title -%}
    {{ 'invoice.notification_overdue.heading'|trans({}, 'email') }}
{%- endblock -%}

{%- block content -%}
    {# 红色警告横幅 #}
    <p style="color: #dc2626; font-size: 16px; font-weight: 600;">
        ⚠️ {{ 'invoice.notification_overdue.alert'|trans({}, 'email') }}
    </p>
    <p style="color: #1e293b; font-size: 16px; line-height: 1.5;">
        {{ 'invoice.notification_overdue.message'|trans({}, 'email') }}
    </p>

    {# 发票详情表格 #}
    {{ email.info_row('Invoice Number', invoice.invoiceId ?? invoice.id) }}
    {{ email.info_row('Client', client.name ?? invoice.client.name) }}
    {{ email.info_row('Due Date', invoice.due|date('Y-m-d')) }}
    {{ email.info_row('Outstanding Balance', invoice.balance|formatCurrency(invoice.client.currency)) }}

    {# 状态标签 #}
    <p style="font-weight: 600; color: #1e293b;">Status</p>
    <div>{{ invoice_label(invoice.status) }}</div>  {# 渲染红色 Overdue 标签 #}

    {# CTA 按钮（后台查看链接） #}
    {{ email.button('View Invoice', url('_invoices_view', {'id': invoice.id}), 'primary', 'large') }}
{%- endblock -%}
```

### 4.4 邮件主题

```php
// [InvoiceOverdueNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceOverdueNotification.php#L44-L47)
public function getSubject(): string
{
    return 'Invoice Overdue Alert';
}
```

同时标记为 **高优先级**：

```php
$email->importance(NotificationEmail::IMPORTANCE_HIGH);
```

---

## 五、多渠道通知通道组合机制

### 5.1 整体架构分层

多渠道通知基于 **Symfony Notifier 组件** 构建，通过 NotificationManager 统一调度：

```
业务层（InvoiceOverdueListener / SendInvoiceReminderHandler）
    ↓ 调用 sendNotification()
[NotificationManager]  ← 核心调度器
    ├─ 读取用户订阅配置（UserNotificationRepository）
    ├─ 动态组合 channels 数组
    └─ 调用 $notifier->send()
        ↓
[Symfony Notifier]
    ├─ channels: [email, sms/{id}, chat/{id}]
    ├─ 按通道类型分发到对应 Transporter
    └─ 实际发送（Email / Texter / Chatter）
        ↓
具体渠道：SMTP / SendGrid / Twilio / Slack / Telegram ...
```

### 5.2 通知管理器核心逻辑

[NotificationManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php#L47-L103)

```php
public function sendNotification(NotificationMessage $message): void
{
    // ========== 步骤1：获取通知事件名称 ==========
    $attributes = (new ReflectionObject($message))->getAttributes(AsNotification::class);
    $event = $attributes[0]->getArguments()['name'] ?? null;
    // 如: 'invoice_overdue' 或 'invoice_reminder'

    // ========== 步骤2：查询所有订阅用户 ==========
    $userNotifications = $this->userNotificationRepository->findBy(['event' => $event]);
    // 每个 UserNotification 包含：用户ID、是否邮件、绑定的传输渠道列表

    foreach ($userNotifications as $userNotification) {
        $channels = [];

        // ========== 步骤3：添加 Email 通道 ==========
        if ($userNotification->isEmail()) {
            $channels[] = 'email';  // Symfony Notifier 的内置通道名
        }

        // ========== 步骤4：添加 SMS / Chat 通道 ==========
        foreach ($userNotification->getTransports() as $transport) {
            // 获取该传输类型的配置器
            $transportConfiguration = $this->transportConfigurations->get($transport->getTransport());

            // 根据配置器类型映射到 Symfony 通道前缀
            $channelType = match ($transportConfiguration::getType()) {
                'texter'  => 'sms',    // TexterInterface → SMS 类
                'chatter' => 'chat',   // ChatterInterface → 聊天类
                default   => $transportConfiguration::getType(),
            };

            // 组装通道字符串：类型/传输ID
            $channels[] = sprintf('%s/%s', $channelType, $transport->getId()->toString());
        }

        // ========== 步骤5：设置通知的通道 ==========
        $message->channels($channels);
        // 示例 channels 数组：['email', 'sms/01ARZ3NDEKTSV4RRFFQ69G5FAV', 'chat/01ARZ3NDEKTSV4RRFFQ69G5FA6']

        // ========== 步骤6：通过 Symfony Notifier 发送 ==========
        $this->notifier->send(
            $message,
            new Recipient(
                $userNotification->getUser()->getEmail(),   // 收件邮箱
                (string) $userNotification->getUser()->getMobile()  // 手机号（短信用）
            )
        );
    }
}
```

### 5.3 通道命名规范

Symfony Notifier 支持 **类型 + 可选传输ID** 的通道命名语法：

| 通道格式 | 说明 | 示例 |
|----------|------|------|
| `email` | 内置 Email 通道，走默认邮件传输器 | `['email']` |
| `sms` | 使用默认 Texter（短信）传输器 | `['sms']` |
| `sms/{transportId}` | 使用指定 ID 的 Texter 配置 | `['sms/01ARZ3NDEKTSV4RRFFQ69G5FAV']` |
| `chat` | 使用默认 Chatter（聊天）传输器 | `['chat']` |
| `chat/{transportId}` | 使用指定 ID 的 Chatter 配置 | `['chat/01ARZ3NDEKTSV4RRFFQ69G5FA6']` |

### 5.4 传输器类型与渠道对应

每种通知渠道通过 `Configurator` 声明自己的类型（`texter` 或 `chatter`）：

```php
// 短信类示例：[YunpianConfigurator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Configurator/YunpianConfigurator.php#L33-L36)
public static function getType(): string
{
    return 'texter';  // 云片网短信 → texter 类型
}

// 聊天类示例：[ZulipConfigurator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Configurator/ZulipConfigurator.php#L33-L36)
public static function getType(): string
{
    return 'chatter';  // Zulip 聊天 → chatter 类型
}
```

**完整渠道分类表：**

| 类型 | 通道前缀 | 支持的渠道（示例） |
|------|----------|-------------------|
| **邮件** | `email` | SMTP、SendGrid、Mailgun、Brevo、Mailjet、Amazon SES |
| **Texter（短信）** | `sms` | Twilio、Vonage、Nexmo、Infobip、Sinch、MessageBird、云片网(Yunpian)、Telnyx、Clickatell、Esendex、GatewayApi、SMS77、SMSAPI、SMSC、SpotHit、SmsBiuras、Mobyt、Octopush、AllMySms、LightSMS、TurboSMS、IQSMS、OVHCloud、FreeMobile、Firebase、FakeSMS（测试用） |
| **Chatter（聊天）** | `chat` | Slack、Discord、Telegram、Microsoft Teams、Mattermost、RocketChat、Google Chat、Gitter、Zulip、LinkedIn、Mercure、FakeChat（测试用） |

### 5.5 通知消息的多通道接口

[NotificationMessage.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationMessage.php#L26-L80) 同时实现两个接口，确保能被不同通道消费：

```php
abstract class NotificationMessage extends Notification
    implements EmailNotificationInterface,    // 邮件通道
               ChatNotificationInterface      // 聊天通道
{
    // 文本内容（用于短信、聊天等纯文本场景）
    abstract public function getTextContent(Environment $twig): string;

    // 邮件通道：构造 EmailMessage
    public function asEmailMessage(EmailRecipientInterface $recipient, ?string $transport = null): EmailMessage
    {
        $message = EmailMessage::fromNotification($this, $recipient);
        if ($email = $message->getMessage() instanceof NotificationEmail) {
            $email->markAsPublic();
        }
        return $message;
    }

    // 聊天通道：构造 ChatMessage
    public function asChatMessage(RecipientInterface $recipient, ?string $transport = null): ChatMessage
    {
        return ChatMessage::fromNotification($this);
        // 内部会使用 getSubject() + getTextContent() 作为消息内容
    }
}
```

### 5.6 通道组合示例场景

**场景：用户 A 订阅了 `invoice_overdue` 事件，配置为 Email + Slack 通知**

```
NotificationManager 处理：
    event = 'invoice_overdue'
    查询 UserNotification:
        user_id: A
        isEmail: true
        transports: [{ id: 'X', transport: 'slack' }]

动态组装 channels：
    1. isEmail() → 'email'
    2. Slack 传输 → SlackConfigurator::getType() = 'chatter' → 'chat/X'

最终 channels = ['email', 'chat/X']

Symfony Notifier 分发：
    → 'email' 通道 → 发送邮件到 userA@example.com（HTML 模板）
    → 'chat/X' 通道 → SlackConfigurator 构建 DSN → 发送到 Slack 频道（纯文本内容）
```

---

## 六、完整流程图

```
┌────────────────────────────────────────────────────────────────────────────┐
│                          定时调度层 (每小时)                                │
│                                                                            │
│  MarkOverdueInvoicesCommand           SendInvoiceRemindersCommand          │
│       #hourly                                #hourly                       │
│                                                                            │
│  查：Pending + due<now               查：due = ±N天前 + 未发送              │
└───────────────┬─────────────────────────────────────────┬──────────────────┘
                │                                         │
                ▼                                         ▼
      ┌──────────────────┐                  ┌────────────────────────┐
      │ MessageBus 异步  │                  │   MessageBus 异步      │
      │ MarkInvoiceOverdue│                 │ SendInvoiceReminderMsg │
      └────────┬─────────┘                  └────────────┬───────────┘
               │                                         │
               ▼                                         ▼
┌─────────────────────────────────────┐  ┌──────────────────────────────────┐
│ MarkInvoiceOverdueHandler           │  │ SendInvoiceReminderHandler       │
│  ① switchCompany()                  │  │  ① switchCompany()               │
│  ② 仍为 Pending? 幂等检查           │  │  ② SaaS 开关 (AutomatedReminders)│
│  ③ Workflow: Pending → Overdue      │  │  ③ invoice/reminder/enabled      │
│  ④ 持久化                           │  │  ④ PreDue 专属开关               │
└──────────────┬──────────────────────┘  │  ⑤ hasReminderBeenSent() 去重    │
               │                          │  ⑥ 发送客户邮件(InvoiceReminderEmail)│
               ▼                          │  ⑦ 写 InvoiceReminder 记录       │
   ┌───────────────────────────────┐     │  ⑧ 发送内部通知 (NotificationMgr) │
   │ Symfony Workflow              │     │  ⑨ 逾期14天 → 升级通知            │
   │ 触发 entered.overdue 事件     │     └─────────────────┬────────────────┘
   └──────────────┬────────────────┘                       │
                  │                                        │
                  ▼                                        │
   ┌───────────────────────────────┐                       │
   │ InvoiceOverdueListener        │                       │
   │ 构建 InvoiceOverdueNotification│                      │
   └───────────────┬───────────────┘                       │
                   │                                       │
                   └──────────────────┬────────────────────┘
                                      ▼
                        ┌──────────────────────────┐
                        │   NotificationManager    │
                        │                          │
                        │  ① Reflection 取 #[AsNotification] 的 event 名│
                        │  ② findBy(event=xxx) 查询订阅用户            │
                        │  ③ 动态组装 channels:                       │
                        │     - isEmail() → 'email'                   │
                        │     - getTransports()                       │
                        │         texter  → 'sms/{id}'                │
                        │         chatter → 'chat/{id}'               │
                        │  ④ $notifier->send(msg, Recipient)          │
                        └─────────────┬────────────────────────────┘
                                      │
                    ┌─────────────────┼─────────────────┐
                    ▼                 ▼                 ▼
              ┌───────────┐     ┌───────────┐     ┌───────────┐
              │   Email   │     │    SMS    │     │   Chat    │
              │ (SMTP/    │     │ (Twilio/  │     │ (Slack/   │
              │ SendGrid) │     │ 云片网等) │     │ Telegram) │
              └───────────┘     └───────────┘     └───────────┘
                    │                 │                 │
                    ▼                 ▼                 ▼
               客户/用户           用户手机         Slack/Telegram
               收件箱             短信收件箱       群组/机器人
                                                             │
                                                             ▼
                                         ┌────────────────────────────┐
                                         │  客户提醒：                 │
                                         │  reminder.html.twig        │
                                         │  (4种类型分级内容/颜色)     │
                                         │                            │
                                         │  内部通知：                 │
                                         │  notification_overdue.html │
                                         │  (红色警报 + 详情表格)     │
                                         └────────────────────────────┘
```

---

## 七、核心代码速查表

### 7.1 逾期状态相关

| 功能 | 文件 | 关键行 |
|------|------|--------|
| 状态枚举 | [InvoiceStatus.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Enum/InvoiceStatus.php#L18-L56) | L25 Overdue 定义 |
| 到期日字段 | [Invoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L169-L172) | L169 DATE_IMMUTABLE |
| 逾期查询 SQL | [InvoiceRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L495-L506) | L500 `due < :now` |
| 状态机配置 | [workflow.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/config/packages/workflow.php#L76-L78) | L76 `TRANSITION_OVERDUE` |
| 定时任务（标记逾期） | [MarkOverdueInvoicesCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/MarkOverdueInvoicesCommand.php#L33-L33) | L33 `#hourly` |
| 异步消息（标记逾期） | [MarkInvoiceOverdue.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Message/MarkInvoiceOverdue.php) | 完整文件 |
| 消息处理器 | [MarkInvoiceOverdueHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Message/Handler/MarkInvoiceOverdueHandler.php#L31-L94) | L68 applyTransition |
| 状态转换服务 | [InvoiceStatusTransitionService.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Service/InvoiceStatusTransitionService.php#L41-L53) | L47 Workflow::apply |
| 逾期事件监听器 | [InvoiceOverdueListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Listener/InvoiceOverdueListener.php#L30-L68) | L41 事件订阅 |

### 7.2 提醒流程相关

| 功能 | 文件 | 关键行 |
|------|------|--------|
| 提醒类型枚举 | [ReminderType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/ReminderType.php#L16-L22) | 4 种类型定义 |
| 提醒记录实体 | [InvoiceReminder.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Entity/InvoiceReminder.php#L26-L28) | L28 唯一约束 |
| 定时任务（发送提醒） | [SendInvoiceRemindersCommand.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Command/SendInvoiceRemindersCommand.php#L45-L55) | L51 天数映射 |
| 预到期查询 | [InvoiceRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L514-L529) | L516 `+N days` |
| 逾期提醒查询 | [InvoiceRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L537-L553) | L539 `-N days` |
| 提醒消息 | [SendInvoiceReminderMessage.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Message/SendInvoiceReminderMessage.php) | 完整文件 |
| 提醒消息处理器 | [SendInvoiceReminderHandler.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/MessageHandler/SendInvoiceReminderHandler.php#L56-L241) | L67 四层开关 |
| 客户邮件类 | [InvoiceReminderEmail.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Email/InvoiceReminderEmail.php#L20-L52) | L29-35 模板绑定 |

### 7.3 模板与通知

| 功能 | 文件 | 关键行 |
|------|------|--------|
| 客户提醒邮件模板 | [reminder.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/reminder.html.twig) | L13-120 分级渲染 |
| 内部逾期通知模板 | [notification_overdue.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Resources/views/Email/notification_overdue.html.twig) | L13-76 警告样式 |
| 客户提醒通知类 | [InvoiceReminderNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceReminderNotification.php#L24-L99) | L68 主题匹配 |
| 内部逾期通知类 | [InvoiceOverdueNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/InvoiceBundle/Notification/InvoiceOverdueNotification.php#L24-L64) | L35-37 模板常量 |

### 7.4 多渠道分发

| 功能 | 文件 | 关键行 |
|------|------|--------|
| 通知管理器（核心） | [NotificationManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationManager.php#L47-L103) | L64-86 通道组装 |
| 通知消息基类 | [NotificationMessage.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Notification/NotificationMessage.php#L26-L80) | L63-79 多接口实现 |
| 通知属性定义 | [AsNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Attribute/AsNotification.php) | event 名称绑定 |
| 用户订阅实体 | [UserNotification.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Entity/UserNotification.php) | 事件 + 通道配置 |
| 配置器接口 | [ConfiguratorInterface.php](file:///d:/fz/0601-1/solo-dogfeeding/code/20-SolidInvoice/src/NotificationBundle/Configurator/ConfiguratorInterface.php) | getType() 方法 |
