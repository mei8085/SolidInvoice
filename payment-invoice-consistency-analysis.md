# SolidInvoice API与MCP付款入口一致性分析

## 1. 代码对比概述

本文档对比分析 SolidInvoice 中两条独立的付款记录入口：
- **API处理器**：`src/ApiBundle/State/Processor/RecordPaymentProcessor.php`
- **MCP工具**：`src/PaymentBundle/Mcp/PaymentWriteTools.php::recordPayment()`

两条路径都实现了"记录线下付款"的功能，但在金额校验、门禁和状态处理上存在关键差异。

---

## 2. 逐行代码对比

### 2.1 金额校验对比

| 校验项 | API处理器 (RecordPaymentProcessor) | MCP工具 (PaymentWriteTools) | 代码依据 |
|--------|-----------------------------------|------------------------------|---------|
| **金额 > 0** | ✅ DTO注解校验 | ✅ 手动检查 | API: `RecordPaymentInput.php:22` `#[Assert\GreaterThan(0)]`<br>MCP: `PaymentWriteTools.php:71-73` |
| **货币一致性** | ✅ 严格匹配 | ✅ 不区分大小写 | API: `RecordPaymentProcessor.php:68-72`<br>MCP: `PaymentWriteTools.php:88-94` `strtoupper()` |
| **超额付款检查** | ❌ **完全缺失** | ✅ 拒绝超过余额的付款 | API: 无对应代码<br>MCP: `PaymentWriteTools.php:96-106` |

#### MCP超额付款检查代码（API中无对应逻辑）

```php
// src/PaymentBundle/Mcp/PaymentWriteTools.php:96-106
// Reject overpayment so the MCP tool matches the behaviour of the
// Prepare action (src/PaymentBundle/Action/Prepare.php).
$balance = $invoice->getBalance();

if ($balance->isLessThan($amount)) {
    throw new ToolCallException(sprintf(
        'Payment amount %d exceeds invoice balance %s.',
        $amount,
        $balance->__toString(),
    ));
}
```

**注意**：MCP的注释称要匹配Prepare动作的行为，但实际上Prepare动作仅对credit支付方式检查超额付款，MCP对所有支付方式都检查。

### 2.2 工作流门禁对比

两条入口都有工作流前置检查，但检查后的处理逻辑完全相同（都有问题）：

| 步骤 | API处理器 | MCP工具 | 代码位置 |
|------|----------|---------|---------|
| 检查 `can('pay')` | ✅ 有 | ✅ 有 | API: `RecordPaymentProcessor.php:74-78`<br>MCP: `PaymentWriteTools.php:108-113` |
| 检查 `isFullyPaid()` | ❌ 无 | ❌ 无 | 两处都没有调用 |
| 直接 `apply('pay')` | ✅ 无条件 | ✅ 无条件 | API: `RecordPaymentProcessor.php:97`<br>MCP: `PaymentWriteTools.php:139` |

**关键代码**：

```php
// API处理器第97行 - 直接转换，不检查金额
$this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_PAY);

// MCP工具第139行 - 同样直接转换
$this->invoiceWorkflow->apply($invoice, InvoiceGraph::TRANSITION_PAY);
```

### 2.3 付款创建与持久化对比

| 操作 | API处理器 | MCP工具 |
|------|----------|---------|
| 设置 `Captured` 状态 | ✅ `PaymentStatus::Captured` | ✅ `PaymentStatus::Captured` |
| 设置 `completed` 日期 | ✅ `new DateTimeImmutable()` | ✅ `new DateTimeImmutable()` |
| 关联发票/客户/公司 | ✅ | ✅ |
| 持久化顺序 | persist payment → apply转换 → flush | persist payment → apply转换 → flush |

### 2.4 其他差异

| 特性 | API处理器 | MCP工具 |
|------|----------|---------|
| 权限体系 | Symfony Security + API Token | McpScopeGuard + `McpScope::Write` |
| 货币大小写 | 原样使用 | `strtoupper()` 标准化 |
| 异常类型 | `UnprocessableEntityHttpException` | `ToolCallException` |
| 返回值 | `Payment` 实体 | 标准化数组 |

---

## 3. 余额与状态不一致的场景分析

### 3.1 场景1：API记录部分付款（高风险）

**前置条件**：
- 发票总金额：$1000
- 发票状态：`Pending`
- 发票余额：$1000
- 无任何付款记录

**操作**：
```http
POST /api/invoices/{id}/payments
{
  "amount": 300,
  "currency": "USD"
}
```

**实际结果**：
| 字段 | 预期值 | 实际值 | 是否一致 |
|------|--------|--------|---------|
| 付款状态 | `Captured` | `Captured` | ✅ |
| 发票状态 | `Pending` | `Paid` | ❌ **不一致** |
| 发票余额 | $700 | $1000 | ❌ **不一致** |
| 付款日期 | 已设置 | 已设置 | ✅ |

**代码路径分析**：
1. API处理器创建付款，状态设为 `Captured`
2. 直接调用 `apply('pay')`，发票状态变为 `Paid`
3. **没有**触发 `PAYMENT_COMPLETE` 事件
4. **没有**调用 `PaymentCompleteListener` 来更新余额
5. 余额字段停留在 $1000 不变

---

### 3.2 场景2：MCP记录部分付款（高风险）

**前置条件**：同上

**操作**：调用 MCP 工具 `record_payment`，参数 `amount: 300`

**实际结果**：与API场景完全相同
| 字段 | 预期值 | 实际值 | 是否一致 |
|------|--------|--------|---------|
| 付款状态 | `Captured` | `Captured` | ✅ |
| 发票状态 | `Pending` | `Paid` | ❌ **不一致** |
| 发票余额 | $700 | $1000 | ❌ **不一致** |

**代码路径分析**：
1. MCP工具比API多了超额付款检查（但这里300 < 1000，通过检查）
2. 后续逻辑与API完全相同：直接 `apply('pay')`
3. 同样没有触发 `PAYMENT_COMPLETE` 事件
4. 余额同样不更新

---

### 3.3 场景3：API记录超额付款（高风险）

**前置条件**：
- 发票总金额：$1000
- 已有付款：$500（Captured）
- 发票状态：`Pending`
- 发票余额：$500

**操作**：
```http
POST /api/invoices/{id}/payments
{
  "amount": 800,
  "currency": "USD"
}
```

**实际结果**：
| 字段 | 预期值 | 实际值 | 是否一致 |
|------|--------|--------|---------|
| 操作结果 | 应被拒绝 | 成功执行 | ❌ |
| 付款状态 | - | `Captured` | - |
| 发票状态 | `Paid` | `Paid` | ✅ |
| 发票余额 | $0 | $500 | ❌ **不一致** |
| 客户信用 | 增加$300 | 增加$300 | ✅（由InvoicePaidListener处理） |

**代码路径分析**：
1. API没有超额付款检查，$800 > $500 仍被接受
2. 付款创建成功，总额变为 $1300
3. `apply('pay')` 触发状态变为 `Paid`
4. `InvoicePaidListener` 检测到 $1300 > $1000，超额 $300 转为信用
5. 但余额仍为 $500，因为 `PaymentCompleteListener` 没有被调用

---

### 3.4 场景4：MCP记录超额付款（正确拒绝）

**前置条件**：同上

**操作**：调用 MCP 工具 `record_payment`，参数 `amount: 800`

**实际结果**：
- 操作被拒绝，抛出 `ToolCallException`："Payment amount 800 exceeds invoice balance 500"
- 没有创建付款记录
- 状态和余额保持不变 ✅

---

### 3.5 场景5：手动调用pay转换（极高风险）

**前置条件**：
- 发票总金额：$1000
- 无任何付款记录
- 发票状态：`Pending`

**操作**：
```http
POST /api/invoices/{id}/transitions/pay
```

**实际结果**：
| 字段 | 预期值 | 实际值 | 是否一致 |
|------|--------|--------|---------|
| 操作结果 | 应被拒绝 | 成功执行 | ❌ |
| 发票状态 | `Pending` | `Paid` | ❌ **不一致** |
| 发票余额 | $1000 | $1000 | ✅（但状态错误） |

**代码路径分析**：
- `InvoiceTransitionProcessor.php:44` 直接调用 `apply($data, $transition)`
- **没有任何业务规则校验**，工作流的 `can()` 只检查状态，不检查付款金额

---

### 3.6 场景6：删除已捕获的付款（极高风险）

**前置条件**：
- 发票总金额：$1000
- 有一笔 $1000 的付款（Captured）
- 发票状态：`Paid`
- 发票余额：$0

**操作**：从数据库删除该付款记录

**实际结果**：
| 字段 | 预期值 | 实际值 | 是否一致 |
|------|--------|--------|---------|
| 发票状态 | `Pending` | `Paid` | ❌ **不一致** |
| 发票余额 | $1000 | $0 | ❌ **不一致** |
| 客户信用 | 应回滚（如有超额） | 不回滚 | ❌ |

**代码路径分析**：
- 没有 `preRemove` 或 `postRemove` 事件监听器处理付款删除
- `orphanRemoval=true` 会删除数据库记录，但没有任何业务逻辑回滚
- 工作流没有定义从 `Paid` 到 `Pending` 的反向转换

---

## 4. 不一致根源分析

### 4.1 架构设计问题

```
在线支付回调（Correct Path）:
  Done.php → 设置Captured → 触发 PAYMENT_COMPLETE 事件
    → PaymentCompleteListener
        ├─ 检查 isFullyPaid()
        │   ├─ 是 → apply('pay')
        │   └─ 否 → setBalance()
        └─ 设置重定向响应

API/MCP入口（Bug Path）:
  RecordPaymentProcessor / PaymentWriteTools
    → 直接设置Captured
    → 直接 apply('pay')  ❌ 绕过了事件和全额检查
    → 不触发 PAYMENT_COMPLETE 事件
    → 不更新余额
```

### 4.2 缺失的核心逻辑

两条入口都缺少以下关键步骤（对比PaymentCompleteListener）：

```php
// 缺失的逻辑：
$isFullyPaid = $invoiceRepository->isFullyPaid($invoice);
if ($isFullyPaid) {
    $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_PAY);
} else {
    // 部分付款：只更新余额，不改变状态
    $totalPaid = $paymentRepository->getTotalPaidForInvoice($invoice);
    $invoice->setBalance($invoiceTotal->minus($totalPaid));
}
```

### 4.3 事件触发缺失

API和MCP入口都**没有触发** `PaymentEvents::PAYMENT_COMPLETE` 事件，导致：
- `PaymentCompleteListener` 不执行（负责余额更新和全额判断）
- `PaymentReceivedListener` 不执行（负责发送付款通知）

---

## 5. 修复建议

### 5.1 短期修复：统一两条入口的逻辑

在 `RecordPaymentProcessor` 和 `PaymentWriteTools` 中添加完整逻辑：

```php
// 修复后的逻辑
$payment = new Payment();
// ... 设置付款字段 ...
$payment->setStatus(PaymentStatus::Captured);
$payment->setCompleted(new DateTimeImmutable());

$em->persist($payment);
$em->flush();  // 先保存，确保getTotalPaidForInvoice能统计到

// 关键：判断是否全额付款
$totalPaid = $paymentRepository->getTotalPaidForInvoice($invoice);
if ($totalPaid->isGreaterThanOrEqualTo($invoice->getTotal())) {
    $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_PAY);
} else {
    // 部分付款：更新余额，不改变状态
    $invoice->setBalance($invoice->getTotal()->minus($totalPaid));
    $em->persist($invoice);
    $em->flush();
}

// 可选：触发事件以通知其他监听器
$event = new PaymentCompleteEvent($payment);
$this->eventDispatcher->dispatch($event, PaymentEvents::PAYMENT_COMPLETE);
```

### 5.2 中期修复：添加付款删除监听器

创建 `PaymentRemoveListener` 处理删除回滚：

```php
#[AsDoctrineListener(Events::preRemove)]
final class PaymentRemoveListener
{
    public function preRemove(LifecycleEventArgs $event): void
    {
        $payment = $event->getObject();
        if (!$payment instanceof Payment || $payment->getStatus() !== PaymentStatus::Captured) {
            return;
        }
        
        $invoice = $payment->getInvoice();
        if (!$invoice instanceof Invoice) {
            return;
        }
        
        // 计算删除后的已付款总额
        $currentTotalPaid = $this->paymentRepository->getTotalPaidForInvoice($invoice);
        $newTotalPaid = $currentTotalPaid->minus($payment->getTotalAmount());
        
        // 更新余额
        $invoice->setBalance($invoice->getTotal()->minus($newTotalPaid));
        
        // 检查是否需要回滚状态（需要先扩展工作流）
        if ($invoice->getStatus() === InvoiceStatus::Paid && 
            $newTotalPaid->isLessThan($invoice->getTotal())) {
            // 需要先在工作流中添加反向转换
            // $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_REOPEN);
        }
    }
}
```

### 5.3 长期修复：重构为单一入口

建议将付款记录逻辑抽取到独立的服务中，由所有入口调用：

```php
final class PaymentRecorder
{
    public function recordPayment(Invoice $invoice, int $amount, ...): Payment
    {
        // 统一的校验逻辑
        // 统一的状态判断
        // 统一的事件触发
    }
}
```

---

## 6. 关键代码位置速查表

| 功能 | 文件 | 行号 |
|------|------|------|
| API记录付款 | `src/ApiBundle/State/Processor/RecordPaymentProcessor.php` | 43-102 |
| MCP记录付款 | `src/PaymentBundle/Mcp/PaymentWriteTools.php` | 62-143 |
| 正确的付款完成处理 | `src/PaymentBundle/Listener/PaymentCompleteListener.php` | 54-101 |
| 在线支付回调入口 | `src/PaymentBundle/Action/Done.php` | 46-82 |
| 超额付款检查（MCP独有） | `src/PaymentBundle/Mcp/PaymentWriteTools.php` | 96-106 |
| 付款后信用处理 | `src/InvoiceBundle/Listener/InvoicePaidListener.php` | 47-71 |
| 工作流配置 | `config/packages/workflow.php` | 82-85 |
| API付款测试 | `src/PaymentBundle/Tests/Functional/Api/RecordPaymentTest.php` | 39-63 |
