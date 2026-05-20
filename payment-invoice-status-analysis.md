# SolidInvoice 付款记录写入链路深度分析

## 1. 部分付款与全额付款的判定入口

### 1.1 核心判定方法

**定义位置**：`src/InvoiceBundle/Repository/InvoiceRepository.php:257-266`

```php
public function isFullyPaid(Invoice $invoice): bool
{
    $invoiceTotal = $invoice->getTotal();
    $totalPaid = $this->getEntityManager()
        ->getRepository(Payment::class)
        ->getTotalPaidForInvoice($invoice);

    return $totalPaid->isEqualTo($invoiceTotal) || $totalPaid->isGreaterThan($invoiceTotal);
}
```

**判定规则**：
- 已付款总额 >= 发票总金额 → 全额付款
- 已付款总额 < 发票总金额 → 部分付款

### 1.2 已付款总额计算

**定义位置**：`src/PaymentBundle/Repository/PaymentRepository.php:125-147`

```php
public function getTotalPaidForInvoice(Invoice $invoice): BigNumber
{
    $queryBuilder = $this->createQueryBuilder('p');
    $queryBuilder
        ->select('SUM(p.totalAmount) as total')
        ->where('p.invoice = :invoice')
        ->andWhere('p.status = :status')  // 关键：只统计 Captured 状态
        ->setParameter('status', PaymentStatus::Captured->value);
    // ...
}
```

**关键约束**：只有状态为 `PaymentStatus::Captured` 的付款才会被计入已付款总额。其他状态（New、Pending、Authorized、Failed等）均不参与计算。

### 1.3 判定入口汇总

| 入口位置 | 触发场景 | 判定逻辑 |
|---------|---------|---------|
| `PaymentCompleteListener.php:70` | 在线支付回调完成 | 先判断 `isFullyPaid()`，再决定是否触发状态转换 |
| `RecordPaymentProcessor.php:74-97` | API手动记录付款 | 直接触发 `pay` 转换，**不判断是否全额** |
| `PaymentWriteTools.php:108-139` | MCP工具记录付款 | 直接触发 `pay` 转换，**不判断是否全额** |
| `InvoiceWriteTools.php:194-203` | MCP工具手动转换 | 调用 `apply_invoice_transition` 直接转换，**不校验付款金额** |
| `InvoiceTransitionProcessor.php:38-44` | API手动转换 | 直接转换，**不校验付款金额** |
| `Prepare.php:216-221` | 线下付款立即捕获 | 触发 `PAYMENT_COMPLETE` 事件，由监听器判断 |

---

## 2. 余额更新与状态迁移触发条件

### 2.1 余额计算公式

**通用公式**：
```
余额 = 发票总金额 - 已付款总额（仅Captured状态）
```

**代码实现**（两处独立实现）：

1. **PaymentCompleteListener 中**（`src/PaymentBundle/Listener/PaymentCompleteListener.php:73-76`）：
   ```php
   $invoiceTotal = $invoice->getTotal();
   $totalPaid = $paymentRepository->getTotalPaidForInvoice($invoice);
   $invoice->setBalance($invoiceTotal->toBigDecimal()->minus($totalPaid));
   ```

2. **TotalCalculator 中**（`src/CoreBundle/Billing/TotalCalculator.php:46-51`）：
   ```php
   $totalPaid = $this->paymentRepository->getTotalPaidForInvoice($entity);
   $total = $entity->getTotal();
   $entity->setBalance($total->minus($totalPaid));
   ```

### 2.2 余额更新触发时机

| 触发时机 | 触发源 | 说明 |
|---------|-------|------|
| 付款完成事件 | `PaymentCompleteListener.php:76` | 部分付款时更新余额 |
| 发票保存前 | `InvoiceSaveListener.php:39-50` | `prePersist` 和 `preUpdate` 事件调用 `TotalCalculator` |
| 发票取消时 | `InvoiceCancelListener.php:60` | 余额重置为发票总金额 |
| 创建发票时 | `InvoiceManager.php:120` | 初始余额 = 发票总金额 |
| 克隆发票时 | `InvoiceCloner.php:72` | 新发票余额 = 新发票总金额 |

### 2.3 状态迁移触发条件

#### 工作流配置（`config/packages/workflow.php:82-85`）

```php
TRANSITION_PAY:
  from: [Pending, Overdue]
  to:   Paid
```

#### 触发路径分析

**路径1：在线支付回调（正确路径）**

```
Done.php:57-63 → 设置支付状态为Captured → 触发 PAYMENT_COMPLETE 事件
    ↓
PaymentCompleteListener.php:70-81
    ├─ 条件：status == Captured AND isFullyPaid()
    │   ├─ 是 → 应用 pay 转换 → 状态变为 Paid
    │   └─ 否 → 只更新余额，状态保持 Pending/Overdue
```

**路径2：线下付款（Prepare.php）**

```
Prepare.php:216-221 → 直接设置 Captured 状态 → 触发 PAYMENT_COMPLETE 事件
    ↓
（同路径1的后续处理）
```

**路径3：API记录付款（有问题的路径）**

```
RecordPaymentProcessor.php:97 → 直接应用 pay 转换
    ↓
状态变为 Paid，但不检查是否真的全额付款
```

**路径4：MCP工具记录付款（有问题的路径）**

```
PaymentWriteTools.php:139 → 直接应用 pay 转换
    ↓
状态变为 Paid，但不检查是否真的全额付款
```

**路径5：手动转换（最危险的路径）**

```
InvoiceTransitionProcessor.php:44 或 InvoiceWriteTools.php:202
    ↓
直接应用 pay 转换，不检查任何付款记录
    ↓
状态变为 Paid，但余额可能不为0
```

### 2.4 状态转换后的连锁处理

#### WorkFlowSubscriber（`src/InvoiceBundle/Listener/WorkFlowSubscriber.php:57-59`）

```php
if (Graph::TRANSITION_PAY === $transition->getName()) {
    $invoice->setPaidDate(CarbonImmutable::now());  // 设置付款日期
}
```

#### InvoicePaidListener（`src/InvoiceBundle/Listener/InvoicePaidListener.php:47-71`）

```php
$totalPaid = $paymentRepository->getTotalPaidForInvoice($invoice)->toBigDecimal();
if ($totalPaid->isGreaterThan($invoice->getTotal())) {
    // 超额付款转为客户信用
    $creditRepository->addCredit($client, $totalPaid->minus($invoice->getTotal()));
}
```

---

## 3. 删除付款后的回滚约束

### 3.1 当前实现分析

**重要结论：代码库中没有任何针对付款删除的回滚处理逻辑。**

#### 级联配置（`src/InvoiceBundle/Entity/Invoice.php:187-190`）

```php
#[ORM\OneToMany(
    mappedBy: 'invoice', 
    targetEntity: Payment::class, 
    cascade: ['persist'],      // 只级联持久化，不级联删除
    orphanRemoval: true         // 从集合移除时自动删除数据库记录
)]
private Collection $payments;
```

#### 移除付款方法（`src/InvoiceBundle/Entity/Invoice.php:366-370`）

```php
public function removePayment(Payment $payment): self
{
    $this->payments->removeElement($payment);
    return $this;
}
```

**问题**：调用 `removePayment()` 后，由于 `orphanRemoval=true`，数据库记录会被删除，但：
- ❌ 余额不会自动更新
- ❌ 状态不会自动回滚
- ❌ 客户信用不会自动调整

### 3.2 缺失的回滚处理

删除付款后应该执行但未执行的操作：

#### 1. 余额回滚

```php
// 应该执行：
$totalPaid = $paymentRepository->getTotalPaidForInvoice($invoice);
$invoice->setBalance($invoice->getTotal()->minus($totalPaid));
```

#### 2. 状态回滚

```php
// 应该检查并执行：
if ($invoice->getStatus() === InvoiceStatus::Paid && !$isFullyPaid) {
    // 注意：当前工作流没有定义从 Paid 到 Pending 的转换
    // 需要先添加反向转换配置
    $this->invoiceStateMachine->apply($invoice, 'reopen');
}
```

#### 3. 信用回滚

```php
// 如果删除的付款导致超额付款减少或消失，应该回滚信用：
if ($deletedPaymentAmount > $originalBalanceAtPaymentTime) {
    $excessToRollback = min($deletedPaymentAmount - $originalBalance, $currentCredit);
    $creditRepository->deductCredit($client, $excessToRollback);
}
```

### 3.3 工作流约束

当前工作流配置中，**没有定义从 `Paid` 状态回退的转换**：

```php
// 现有转换：
TRANSITION_REOPEN:
  from: Cancelled
  to:   Draft

// 缺失的转换：
TRANSITION_REOPEN: （需要扩展）
  from: [Cancelled, Paid]
  to:   Pending
```

这意味着即使代码想回滚状态，工作流也不允许。

---

## 4. 导致余额或状态不一致的代码路径

### 4.1 不一致路径汇总

| 路径 | 问题描述 | 风险等级 |
|------|---------|---------|
| **API记录部分付款** | `RecordPaymentProcessor.php:97` 直接应用 `pay` 转换，不检查是否全额 | ⚠️ 高 |
| **MCP工具记录部分付款** | `PaymentWriteTools.php:139` 直接应用 `pay` 转换，不检查是否全额 | ⚠️ 高 |
| **手动调用 pay 转换** | `InvoiceTransitionProcessor.php` 或 `InvoiceWriteTools.php` 直接转换，不校验付款 | ⚠️ 极高 |
| **删除付款记录** | 无回滚处理，余额和状态停留在删除前 | ⚠️ 极高 |
| **修改已付款发票的金额** | 修改行项目或折扣后，`TotalCalculator` 会重新计算余额，但状态仍为 Paid | ⚠️ 中 |
| **付款状态从 Captured 改为其他** | 已付款总额减少，但余额和状态不会自动更新 | ⚠️ 高 |

### 4.2 详细分析

#### 路径1：API记录部分付款导致状态错误

**代码**：`src/ApiBundle/State/Processor/RecordPaymentProcessor.php:97`

```php
// 问题：无论付款金额多少，直接转换为 Paid
$this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_PAY);
```

**场景**：
- 发票总金额：$1000
- 通过API记录付款：$300（部分付款）
- 结果：发票状态变为 `Paid`，但余额为 $700

**不一致表现**：
- 状态：`Paid` ❌（应为 `Pending`）
- 余额：`$700` ✓（正确）

#### 路径2：MCP工具记录部分付款导致状态错误

**代码**：`src/PaymentBundle/Mcp/PaymentWriteTools.php:139`

与API路径相同的问题。

#### 路径3：手动调用 pay 转换导致完全不一致

**代码**：`src/ApiBundle/State/Processor/InvoiceTransitionProcessor.php:44` 或 `src/InvoiceBundle/Mcp/InvoiceWriteTools.php:202`

```php
// 问题：完全不检查付款记录，直接转换
$this->invoiceStateMachine->apply($data, $transition);
```

**场景**：
- 发票总金额：$1000
- 无任何付款记录
- 调用 `/api/invoices/{id}/transitions/pay`
- 结果：发票状态变为 `Paid`，余额仍为 $1000

**不一致表现**：
- 状态：`Paid` ❌
- 余额：`$1000` ❌（应为 $0 或状态不为 Paid）

#### 路径4：删除付款记录导致不一致

**场景**：
- 发票总金额：$1000
- 有一笔 $1000 的付款（状态 Captured）
- 发票状态：`Paid`，余额：$0
- 删除该付款记录

**结果**：
- 状态：`Paid` ❌（应为 `Pending`）
- 余额：`$0` ❌（应为 $1000）

#### 路径5：修改已付款发票的金额

**触发源**：`InvoiceSaveListener.php:39-50` → `TotalCalculator.php:47-51`

**场景**：
- 发票总金额：$1000
- 有一笔 $1000 的付款
- 发票状态：`Paid`，余额：$0
- 修改行项目，总金额变为 $1200

**结果**：
- 状态：`Paid` ❌（应为 `Pending`）
- 余额：`$200` ✓（由 TotalCalculator 自动更新）

#### 路径6：修改付款状态

**场景**：
- 发票总金额：$1000
- 有一笔 $1000 的付款（状态 Captured）
- 发票状态：`Paid`，余额：$0
- 将付款状态改为 `Cancelled`

**结果**：
- 状态：`Paid` ❌（应为 `Pending`）
- 余额：`$0` ❌（应为 $1000，因为 `getTotalPaidForInvoice` 只统计 Captured）

---

## 5. 修复建议

### 5.1 记录付款时的修复

在 `RecordPaymentProcessor` 和 `PaymentWriteTools` 中添加全额付款检查：

```php
// 在 apply 转换前添加检查
$totalPaid = $paymentRepository->getTotalPaidForInvoice($invoice);
if ($totalPaid->isLessThan($invoice->getTotal())) {
    // 部分付款：只更新余额，不触发状态转换
    $invoice->setBalance($invoice->getTotal()->minus($totalPaid));
    $em->flush();
} else {
    // 全额付款：触发转换
    $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_PAY);
}
```

### 5.2 添加付款删除的监听器

创建 Doctrine 事件监听器处理付款删除：

```php
#[AsDoctrineListener(Events::preRemove)]
final class PaymentRemoveListener
{
    public function preRemove(LifecycleEventArgs $event): void
    {
        $payment = $event->getObject();
        if (!$payment instanceof Payment) {
            return;
        }
        
        $invoice = $payment->getInvoice();
        if (!$invoice instanceof Invoice) {
            return;
        }
        
        // 1. 临时从已付款中扣除
        $totalPaid = $this->paymentRepository->getTotalPaidForInvoice($invoice);
        $newTotalPaid = $totalPaid->minus($payment->getTotalAmount());
        
        // 2. 更新余额
        $invoice->setBalance($invoice->getTotal()->minus($newTotalPaid));
        
        // 3. 检查是否需要回滚状态（需要先扩展工作流）
        if ($invoice->getStatus() === InvoiceStatus::Paid && 
            $newTotalPaid->isLessThan($invoice->getTotal())) {
            $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_REOPEN);
        }
        
        // 4. 处理信用回滚（如果需要）
    }
}
```

### 5.3 扩展工作流

在 `config/packages/workflow.php` 中添加反向转换：

```php
$invoiceWorkflow
    ->transition()
    ->name(Graph::TRANSITION_REOPEN)
    ->from(InvoiceStatus::Cancelled->value)
    ->from(InvoiceStatus::Paid->value)        // 新增
    ->to(InvoiceStatus::Pending->value);      // 改为 Pending 而非 Draft
```

### 5.4 限制手动转换

在 `InvoiceTransitionProcessor` 中添加业务规则校验：

```php
if ($transition === Graph::TRANSITION_PAY) {
    if (!$this->invoiceRepository->isFullyPaid($data)) {
        throw new UnprocessableEntityHttpException(
            'Cannot mark invoice as paid: total payments less than invoice amount.'
        );
    }
}
```

---

## 6. 关键代码位置速查表

| 功能 | 文件位置 | 行号 |
|------|---------|------|
| 全额付款判定 | `InvoiceRepository.php` | 257-266 |
| 已付款总额计算 | `PaymentRepository.php` | 125-147 |
| 付款完成监听器 | `PaymentCompleteListener.php` | 54-101 |
| API记录付款 | `RecordPaymentProcessor.php` | 43-102 |
| MCP记录付款 | `PaymentWriteTools.php` | 62-143 |
| 手动转换处理器 | `InvoiceTransitionProcessor.php` | 32-48 |
| 余额自动计算 | `TotalCalculator.php` | 42-53 |
| 付款后超额信用处理 | `InvoicePaidListener.php` | 47-71 |
| 工作流配置 | `workflow.php` | 82-85 |
| 发票状态枚举 | `InvoiceStatus.php` | 18-56 |
| 付款状态枚举 | `PaymentStatus.php` | 18-65 |
