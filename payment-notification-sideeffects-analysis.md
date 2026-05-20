# SolidInvoice 付款链路通知与时间副作用分析

## 1. 概述

当API或MCP直接执行 `apply('pay')` 操作时，会绕过正常的 `payment.complete` 事件链路，导致一系列通知和时间相关的副作用不一致。本文档详细分析这些差异。

---

## 2. paidDate 写入条件对比

### 2.1 paidDate 字段定义

**位置**：`src/InvoiceBundle/Entity/Invoice.php:182`

```php
#[ORM\Column(type: 'datetime_immutable', nullable: true)]
#[ApiFilter(DateFilter::class, properties: ['invoiceDate', 'due', 'paidDate'])]
private ?DateTimeInterface $paidDate = null;
```

**设置方法**：`src/InvoiceBundle/Entity/Invoice.php:319-322`

```php
public function setPaidDate(?DateTimeImmutable $paidDate): self
{
    $this->paidDate = $paidDate;
    return $this;
}
```

### 2.2 唯一写入路径

`paidDate` 只有**一个写入点**：在 `WorkFlowSubscriber` 中监听 `workflow.invoice.entered` 事件。

**位置**：`src/InvoiceBundle/Listener/WorkFlowSubscriber.php:57-59`

```php
if (Graph::TRANSITION_PAY === $transition->getName()) {
    $invoice->setPaidDate(CarbonImmutable::now());
}
```

**关键条件**：
1. 必须触发 `TRANSITION_PAY` 转换
2. 必须通过工作流的 `apply()` 方法触发
3. `paidDate` 被设置为转换发生时的**当前时间**

### 2.3 不同路径的 paidDate 写入对比

| 路径 | 是否写入 paidDate | 写入时间 | 代码依据 |
|------|------------------|---------|---------|
| 在线支付回调 | ✅ 是 | 支付回调时，状态确认为 Captured 且全额付款后 | `PaymentCompleteListener.php:71` → 触发 `apply('pay')` → `WorkFlowSubscriber.php:58` |
| 线下付款（Prepare） | ✅ 是 | 提交线下付款表单时，全额付款后 | `Prepare.php:221` → 触发事件 → `PaymentCompleteListener.php:71` → `apply('pay')` |
| API记录付款 | ✅ 是 | 调用API时，**无论金额多少** | `RecordPaymentProcessor.php:97` 直接 `apply('pay')` → `WorkFlowSubscriber.php:58` |
| MCP记录付款 | ✅ 是 | 调用MCP工具时，**无论金额多少** | `PaymentWriteTools.php:139` 直接 `apply('pay')` → `WorkFlowSubscriber.php:58` |
| 手动调用 pay 转换 | ✅ 是 | 调用转换时，**无论是否有付款** | `InvoiceTransitionProcessor.php:44` 直接 `apply('pay')` → `WorkFlowSubscriber.php:58` |

### 2.4 paidDate 不一致的场景

**场景**：API记录部分付款 $300（发票总金额 $1000）

```
时间线：
T0: 发票创建，状态 Pending，paidDate = null
T1: 调用 API /invoices/{id}/payments，金额 $300
    ↓
    RecordPaymentProcessor:97 → apply('pay')
    ↓
    WorkFlowSubscriber:58 → setPaidDate(T1)
    ↓
    发票状态变为 Paid
```

**问题**：
- `paidDate` 被设置为 T1，但实际上只收到了 $300，不是全额付款
- 后续无法收到剩余 $700（见死锁分析文档）
- 即使后续修复了状态，`paidDate` 也不会自动更新为实际全额付款的时间

---

## 3. 发票状态通知触发条件

### 3.1 通知触发点

**位置**：`src/InvoiceBundle/Listener/WorkFlowSubscriber.php:75-77`

```php
if (! $isNew) {
    $this->notification->sendNotification(new InvoiceStatusNotification(['invoice' => $invoice]));
}
```

**触发条件**：
1. 监听 `workflow.invoice.entered` 事件（**所有**状态转换都会触发）
2. 排除 `New` 状态（`$isNew = false`）
3. 发送 `InvoiceStatusNotification` 通知

### 3.2 InvoiceStatusNotification 定义

**位置**：`src/InvoiceBundle/Notification/InvoiceStatusNotification.php`

```php
#[AsNotification(
    name: self::EVENT,
    title: 'Invoice Status Changed',
    description: 'When an invoice status changes (draft, sent, paid, etc.)',
    icon: 'tabler:file-invoice',
    category: NotificationCategory::INVOICE,
)]
class InvoiceStatusNotification extends NotificationMessage
{
    public const EVENT = 'invoice_status_update';
    // ...
}
```

### 3.3 不同路径的状态通知对比

| 路径 | 是否触发状态通知 | 通知时机 | 通知内容 |
|------|------------------|---------|---------|
| 在线支付回调（全额） | ✅ 是 | 状态从 Pending/Overdue → Paid 时 | 发票状态变为 Paid |
| 在线支付回调（部分） | ❌ 否 | 不触发状态转换，只更新余额 | 无 |
| 线下付款（全额） | ✅ 是 | 状态从 Pending/Overdue → Paid 时 | 发票状态变为 Paid |
| 线下付款（部分） | ❌ 否 | 不触发状态转换，只更新余额 | 无 |
| API记录付款（任何金额） | ✅ 是 | 调用 `apply('pay')` 时 | 发票状态变为 Paid **即使是部分付款** |
| MCP记录付款（任何金额） | ✅ 是 | 调用 `apply('pay')` 时 | 发票状态变为 Paid **即使是部分付款** |
| 手动调用 pay 转换 | ✅ 是 | 调用 `apply('pay')` 时 | 发票状态变为 Paid **即使无付款** |

### 3.4 状态通知的副作用问题

**场景**：API记录部分付款 $300

**用户收到的通知**：
> 主题：Invoice Status Change  
> 内容：发票状态变为 "Paid"

**实际情况**：
- 只收到了 $300，还有 $700 未付
- 用户可能误以为款项已全部收到
- 会计对账时会发现差异

---

## 4. 收款通知链路断点分析

### 4.1 正常的收款通知链路

在线支付回调和线下付款的完整链路：

```
付款完成
  ↓
触发 PaymentEvents::PAYMENT_COMPLETE 事件
  ↓
├─ PaymentCompleteListener（余额更新 + 状态转换）
│   ├─ 全额付款 → apply('pay') → 状态变 Paid
│   │   └─ WorkFlowSubscriber
│   │       ├─ setPaidDate()
│   │       └─ 发送 InvoiceStatusNotification（状态变更通知）
│   └─ 部分付款 → 只更新余额，不触发状态转换
└─ PaymentReceivedListener
    └─ 发送 PaymentReceivedNotification（收款通知）
```

**两个独立通知**：
1. **InvoiceStatusNotification**：发票状态变更（仅状态改变时发送）
2. **PaymentReceivedNotification**：收到付款（每次付款成功都发送）

### 4.2 PaymentReceivedNotification 定义

**位置**：`src/PaymentBundle/Notification/PaymentReceivedNotification.php`

```php
#[AsNotification(
    name: self::EVENT,
    title: 'Payment Received',
    description: 'When a payment is received for an invoice',
    icon: 'tabler:cash',
    category: NotificationCategory::PAYMENT,
)]
class PaymentReceivedNotification extends NotificationMessage
{
    public const EVENT = 'payment_made';
    // ...
}
```

**触发条件**：监听 `PaymentEvents::PAYMENT_COMPLETE` 事件

**位置**：`src/PaymentBundle/Listener/PaymentReceivedListener.php:30-42`

```php
public static function getSubscribedEvents(): array
{
    return [
        PaymentEvents::PAYMENT_COMPLETE => 'onPaymentCapture',
    ];
}

public function onPaymentCapture(PaymentCompleteEvent $event): void
{
    $this->notification->sendNotification(new PaymentReceivedNotification(['payment' => $event->getPayment()]));
}
```

### 4.3 PaymentEvents::PAYMENT_COMPLETE 的触发点

搜索整个代码库，**只有两个地方**触发此事件：

| 触发位置 | 场景 | 代码 |
|---------|------|------|
| `Done.php:63` | 在线支付回调完成 | `$this->eventDispatcher->dispatch($event, PaymentEvents::PAYMENT_COMPLETE);` |
| `Prepare.php:221` | 线下付款提交 | `$this->eventDispatcher->dispatch($event, PaymentEvents::PAYMENT_COMPLETE);` |

**关键发现**：`RecordPaymentProcessor` 和 `PaymentWriteTools` **都没有触发这个事件**！

### 4.4 API/MCP路径的通知断点

```
API/MCP记录付款
  ↓
直接创建 Payment 实体，状态设为 Captured
  ↓
直接调用 apply('pay')
  ↓
├─ WorkFlowSubscriber 被触发（通过 workflow.entered 事件）
│   ├─ setPaidDate() ✅
│   └─ 发送 InvoiceStatusNotification ✅（状态变更通知）
└─ ❌ PaymentEvents::PAYMENT_COMPLETE 事件 **未触发**
    ├─ ❌ PaymentCompleteListener 不执行（余额不更新）
    └─ ❌ PaymentReceivedListener 不执行（收款通知不发送）
```

### 4.5 通知断点对比表

| 通知类型 | 在线支付 | 线下付款 | API记录 | MCP记录 | 代码依据 |
|---------|---------|---------|---------|---------|---------|
| InvoiceStatusNotification（状态变更） | ✅ 是（仅全额） | ✅ 是（仅全额） | ✅ 是（任何金额） | ✅ 是（任何金额） | `WorkFlowSubscriber.php:76` |
| PaymentReceivedNotification（收款通知） | ✅ 是（任何付款成功） | ✅ 是（任何付款成功） | ❌ **否** | ❌ **否** | `PaymentReceivedListener.php:41` |
| 余额更新 | ✅ 是 | ✅ 是 | ❌ **否** | ❌ **否** | `PaymentCompleteListener.php:76` |

### 4.6 断点导致的业务影响

| 影响方 | 表现 | 业务后果 |
|-------|------|---------|
| 系统用户 | 收到"发票已付款"通知，但没收到"收到付款"通知 | 用户困惑，需要手动检查付款记录 |
| 客户 | 可能收到"发票已付款"的状态通知邮件，但实际上是部分付款 | 客户误解，可能拒绝支付剩余款项 |
| 会计 | 系统显示"已付款"，但余额不为零，也没有付款通知 | 对账困难，需要人工核对 |
| 审计 | 付款记录存在，但缺少付款接收的审计事件 | 审计轨迹不完整 |

---

## 5. 完整的副作用对比矩阵

### 5.1 在线支付回调（正确路径）

```
假设：发票总金额 $1000，支付 $300（部分付款）
```

| 操作 | 结果 |
|------|------|
| 付款状态 | Captured |
| 发票状态 | Pending（不变） |
| 发票余额 | $700（更新） |
| paidDate | null（不设置） |
| 状态通知 | 不发送（状态未变） |
| 收款通知 | 发送 PaymentReceivedNotification |
| 能否继续收款 | ✅ 是 |

### 5.2 API记录部分付款（错误路径）

```
假设：发票总金额 $1000，API记录 $300
```

| 操作 | 结果 | 是否正确 |
|------|------|---------|
| 付款状态 | Captured | ✅ |
| 发票状态 | Paid（错误） | ❌ 应为 Pending |
| 发票余额 | $1000（未更新） | ❌ 应为 $700 |
| paidDate | 被设置为当前时间 | ❌ 不应设置 |
| 状态通知 | 发送"发票已付款" | ❌ 不应发送 |
| 收款通知 | 未发送 | ❌ 应该发送 |
| 能否继续收款 | ❌ 否（死锁） | ❌ 应该可以 |

### 5.3 API记录全额付款（碰巧正确）

```
假设：发票总金额 $1000，API记录 $1000
```

| 操作 | 结果 | 是否正确 |
|------|------|---------|
| 付款状态 | Captured | ✅ |
| 发票状态 | Paid | ✅ |
| 发票余额 | $1000（未更新） | ❌ 应为 $0 |
| paidDate | 被设置为当前时间 | ✅ |
| 状态通知 | 发送"发票已付款" | ✅ |
| 收款通知 | 未发送 | ❌ 应该发送 |
| 能否继续收款 | 不需要 | - |

**注意**：即使金额恰好全额，**余额仍然错误**（$1000 而非 $0），且**收款通知缺失**。

---

## 6. 时间相关副作用分析

### 6.1 paidDate 的语义问题

`paidDate` 的设计意图是记录**发票全额付清的日期**，但在 API/MCP 路径中：

1. **部分付款时被错误设置**：只付了 $300，`paidDate` 却被设置了
2. **时间不准确**：如果后续修复了状态，`paidDate` 不会更新为实际全额付款的时间
3. **无法区分**：系统无法区分"真正全额付款"和"错误标记为Paid"

### 6.2 通知时间线混乱

正常的通知时间线：
```
T1: 收到部分付款 $300
    → 发送 PaymentReceivedNotification（收到 $300）
T2: 收到剩余 $700
    → 发送 PaymentReceivedNotification（收到 $700）
    → 状态变为 Paid
    → 发送 InvoiceStatusNotification（已付款）
```

API路径的通知时间线：
```
T1: API记录部分付款 $300
    → 发送 InvoiceStatusNotification（已付款）❌
    → 不发送 PaymentReceivedNotification ❌
T2: 无法继续收款（死锁）
```

### 6.3 审计影响

- 付款实体的 `completed` 字段正确记录了付款时间
- 但 `paidDate` 字段的语义被破坏
- 缺少 `PaymentReceivedNotification` 对应的审计事件
- 状态变更通知的审计记录不准确

---

## 7. 修复建议

### 7.1 紧急修复：添加事件触发

在 `RecordPaymentProcessor` 和 `PaymentWriteTools` 中，创建付款后触发 `PAYMENT_COMPLETE` 事件：

```php
// 新增：在 persist 之后触发事件
$em->persist($payment);
$em->flush();  // 先保存，确保监听器能查询到

// 触发付款完成事件，让正确的监听器处理
$event = new PaymentCompleteEvent($payment);
$this->eventDispatcher->dispatch($event, PaymentEvents::PAYMENT_COMPLETE);

// 移除直接的 apply('pay') 调用，让 PaymentCompleteListener 处理
// $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_PAY);

// 返回响应（如果事件设置了响应）
if (($response = $event->getResponse()) instanceof Response) {
    return $response;
}
```

### 7.2 移除直接的 apply 调用

删除 `RecordPaymentProcessor.php:97` 和 `PaymentWriteTools.php:139` 中的直接 `apply('pay')` 调用，改为通过事件链路由 `PaymentCompleteListener` 处理。

### 7.3 统一通知链路

确保所有付款路径都经过相同的事件链路：

```
所有付款入口
  ↓
创建 Payment 实体
  ↓
触发 PaymentEvents::PAYMENT_COMPLETE
  ↓
PaymentCompleteListener 处理
  ├─ 判断是否全额
  │   ├─ 是 → apply('pay') → WorkFlowSubscriber → paidDate + 状态通知
  │   └─ 否 → 更新余额
  └─ PaymentReceivedListener → 收款通知
```

---

## 8. 关键代码位置速查表

| 功能 | 文件 | 行号 |
|------|------|------|
| paidDate 写入 | `src/InvoiceBundle/Listener/WorkFlowSubscriber.php` | 58 |
| 状态通知发送 | `src/InvoiceBundle/Listener/WorkFlowSubscriber.php` | 76 |
| 收款通知发送 | `src/PaymentBundle/Listener/PaymentReceivedListener.php` | 41 |
| PAYMENT_COMPLETE 事件触发 | `src/PaymentBundle/Action/Done.php` | 63 |
| PAYMENT_COMPLETE 事件触发 | `src/PaymentBundle/Action/Prepare.php` | 221 |
| PaymentCompleteListener 处理 | `src/PaymentBundle/Listener/PaymentCompleteListener.php` | 54-101 |
| API直接 apply pay | `src/ApiBundle/State/Processor/RecordPaymentProcessor.php` | 97 |
| MCP直接 apply pay | `src/PaymentBundle/Mcp/PaymentWriteTools.php` | 139 |
| InvoiceStatusNotification 定义 | `src/InvoiceBundle/Notification/InvoiceStatusNotification.php` | 24-33 |
| PaymentReceivedNotification 定义 | `src/PaymentBundle/Notification/PaymentReceivedNotification.php` | 24-33 |
| 工作流 entered 事件监听 | `src/InvoiceBundle/Listener/WorkFlowSubscriber.php` | 46 |
