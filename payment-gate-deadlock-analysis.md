# SolidInvoice 付款门禁死锁分析

## 1. 概述

当部分付款被错误地提前标记为 `Paid` 状态后，SolidInvoice 的工作流门禁设计会形成一个**无法自行恢复的死锁**。本文档详细分析这一机制的代码依据、阻断路径和业务影响。

---

## 2. pay 门禁对再次收款的阻断路径

### 2.1 工作流配置

**定义位置**：`config/packages/workflow.php:81-85`

```php
$invoiceWorkflow
    ->transition()
    ->name(InvoiceGraph::TRANSITION_PAY)
    ->from(InvoiceStatus::Pending->value)    // 仅允许从 Pending
    ->from(InvoiceStatus::Overdue->value)    // 或 Overdue
    ->to(InvoiceStatus::Paid->value);        // 转换到 Paid
```

**关键约束**：`TRANSITION_PAY` 的前置状态**只有** `Pending` 和 `Overdue`。一旦状态变为 `Paid`，`can('pay')` 永远返回 `false`。

### 2.2 三层门禁阻断

所有付款入口在执行前都会检查 `can('pay')`，形成三层阻断：

#### 第一层：UI层阻断（Twig模板）

**位置**：`src/InvoiceBundle/Resources/views/Default/view.html.twig:125`

```twig
{% if workflow_can(invoice, 'pay') %}
    <a href="{{ path('_payments_create', {'uuid' : invoice.uuid}) }}" class="btn btn-primary btn-hero">
        {{ "invoice.view.toolbar.pay_now"|trans }}
    </a>
{% endif %}
```

**效果**：状态为 `Paid` 时，"立即支付"按钮**不显示**。

#### 第二层：Controller层阻断（Prepare动作）

**位置**：`src/PaymentBundle/Action/Prepare.php:100-117`

```php
if (! $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_PAY)) {
    // 直接重定向，不允许进入支付页面
    return new RedirectResponse($route);
}
```

**效果**：即使用户手动输入支付URL，也会被**重定向回发票列表**，并显示错误消息：`payment.create.exception.invoice_cannot_be_paid`。

#### 第三层：API层阻断（RecordPaymentProcessor）

**位置**：`src/ApiBundle/State/Processor/RecordPaymentProcessor.php:74-78`

```php
if (! $this->invoiceStateMachine->can($invoice, Graph::TRANSITION_PAY)) {
    throw new UnprocessableEntityHttpException(
        sprintf('Pay transition cannot be applied to invoice in status "%s".', $invoice->getStatus()?->value ?? 'unknown')
    );
}
```

**效果**：API调用会直接**抛出异常**，HTTP状态码 422。

#### 第四层：MCP工具阻断（PaymentWriteTools）

**位置**：`src/PaymentBundle/Mcp/PaymentWriteTools.php:108-113`

```php
if (! $this->invoiceWorkflow->can($invoice, InvoiceGraph::TRANSITION_PAY)) {
    throw new ToolCallException(sprintf(
        '"pay" transition cannot be applied to invoice in status "%s".',
        $invoice->getStatus()?->value ?? 'unknown'
    ));
}
```

**效果**：MCP工具调用会**抛出异常**。

### 2.3 阻断路径示意图

```
用户尝试收款
    ↓
[UI层] workflow_can('pay')? → 否 → 按钮隐藏
    ↓
[Controller层] can('pay')? → 否 → 重定向 + 错误
    ↓
[API层] can('pay')? → 否 → 422异常
    ↓
[MCP层] can('pay')? → 否 → ToolCallException
    ↓
所有路径被阻断 ❌
```

---

## 3. 工作流中状态回退能力的限制

### 3.1 现有转换矩阵

根据 `config/packages/workflow.php`，发票状态的所有允许转换：

| 转换名称 | 源状态 | 目标状态 |
|---------|--------|---------|
| `new` | New | Draft |
| `accept` | New, Draft | Pending |
| `cancel` | Draft, Pending, Overdue | Cancelled |
| `overdue` | Pending | Overdue |
| `pay` | Pending, Overdue | Paid |
| `reopen` | Cancelled | Draft |
| `archive` | New, Draft, Cancelled, Paid | Archived |
| `edit` | Cancelled, Draft, Pending, Overdue | Draft |

### 3.2 关键缺失：Paid状态的出口

**Paid 状态的允许转换只有 1 个**：
- ✅ `archive` → 可以归档
- ❌ `reopen` → **不允许**（仅允许从 Cancelled）
- ❌ `edit` → **不允许**（仅允许从 Cancelled, Draft, Pending, Overdue）
- ❌ `cancel` → **不允许**（仅允许从 Draft, Pending, Overdue）
- ❌ 任何其他转换 → **都不允许**

### 3.3 状态回退的代码限制

#### 限制1：工作流配置层面

`config/packages/workflow.php:88-91` 定义的 `reopen` 转换：

```php
->name(InvoiceGraph::TRANSITION_REOPEN)
->from(InvoiceStatus::Cancelled->value)  // 仅从 Cancelled
->to(InvoiceStatus::Draft->value);       // 到 Draft
```

**问题**：即使想手动调用 `reopen`，工作流也**不允许**从 `Paid` 执行此转换。

#### 限制2：代码层面的额外门禁

`src/InvoiceBundle/Action/Edit.php:58-64` 对编辑操作的额外保护：

```php
if (InvoiceStatus::Paid === $invoice->getStatus()) {
    $session->getFlashBag()->add('warning', 'invoice.edit.paid');
    return new RedirectResponse($this->router->generate('_invoices_index'));
}
```

**效果**：Paid状态的发票**无法进入编辑页面**，即使工作流允许也不行。

#### 限制3：状态设置的封装保护

`src/InvoiceBundle/Entity/Invoice.php:253-263` 通过 markingStore 封装状态访问：

```php
public function getStatusValue(): ?string
{
    return $this->status?->value;
}

public function setStatusValue(string $status): static
{
    $this->status = InvoiceStatus::from($status);
    return $this;
}
```

**效果**：状态变更**必须通过工作流**，绕过工作流直接设置状态是不推荐的（但技术上可行）。

---

## 4. 死锁场景复现与影响分析

### 4.1 死锁形成过程

**前置条件**：
- 发票总金额：$1000
- 发票状态：`Pending`
- 无付款记录

**触发操作**：
通过 API 或 MCP 工具记录部分付款 $300（这两个入口有bug，不检查是否全额）

**死锁形成**：

| 步骤 | 操作 | 结果 | 代码位置 |
|------|------|------|---------|
| 1 | 调用 `recordPayment` | 创建 $300 付款，状态 `Captured` | `RecordPaymentProcessor.php:80-92` |
| 2 | 调用 `apply('pay')` | 发票状态变为 `Paid` ❌ | `RecordPaymentProcessor.php:97` |
| 3 | 余额未更新 | 余额仍为 $1000 ❌ | （缺少逻辑） |
| 4 | 后续尝试收款 | 所有路径被 `can('pay')` 阻断 | 见2.2节 |

**最终状态**：
- 状态：`Paid` ❌（应为 `Pending`）
- 余额：`$1000` ❌（应为 $700）
- 已付款：`$300` ✓
- **无法收取剩余 $700** ⚠️

### 4.2 业务影响全景

#### 影响1：无法收取剩余款项

用户发现余额还有 $700 未收，但：
- UI上没有"立即支付"按钮
- 手动访问支付页面被重定向
- API调用返回422错误
- MCP工具调用抛出异常

**客户体验**：用户感到困惑，为什么"已付款"的发票还有余额？为什么不能继续收款？

#### 影响2：客户视图数据不一致

`src/ClientBundle/Entity/Client.php:474-481` 中 `getOutstandingInvoices()` 的过滤逻辑：

```php
public function getOutstandingInvoices(): iterable
{
    foreach ($this->invoices as $invoice) {
        if (in_array($invoice->getStatus(), [InvoiceStatus::Pending, InvoiceStatus::Overdue], true) 
            && $invoice->getBalance()->isPositive()) {
            yield $invoice;
        }
    }
}
```

**问题**：Paid状态的发票**不会出现在未结发票列表中**，即使余额为正数。

**影响**：
- 客户列表页的"未结余额"统计不准确
- 员工看不到这张发票还有欠款
- 可能导致坏账

#### 影响3：财务统计偏差

`src/ClientBundle/DataGrid/BaseClientGrid.php:57-64` 中总余额统计：

```php
if (
    $invoice->getStatus() === InvoiceStatus::Paid ||
    $invoice->getStatus() === InvoiceStatus::Pending ||
    $invoice->getStatus() === InvoiceStatus::Overdue
) {
    $total = $total->plus($invoice->getTotal());
}
```

**问题**：Paid状态的发票会被计入总余额（使用 `getTotal()` 而非 `getBalance()`），但未结余额统计不包含它。

**影响**：财务报表数据不一致，审计困难。

#### 影响4：PDF和邮件显示错误

`src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig:128`：

```twig
{% if invoice.status != enum('SolidInvoice\\InvoiceBundle\\Enum\\InvoiceStatus').Paid %}
    {# 显示逾期提示和付款链接 #}
{% endif %}
```

**问题**：状态为Paid时，PDF发票**不显示**：
- 逾期提示（即使实际未付清）
- 付款链接（即使还有余额）

**影响**：客户收到的发票显示"已付款"，但实际上还有欠款，可能导致客户拒绝支付剩余款项。

#### 影响5：无法编辑修正

`src/InvoiceBundle/Action/Edit.php:58-64`：

```php
if (InvoiceStatus::Paid === $invoice->getStatus()) {
    // 重定向，不允许编辑
}
```

**问题**：即使发现错误，也**无法通过正常流程编辑**发票来修正。

**影响**：只能通过数据库直接修改，或删除发票重新创建，增加操作成本和风险。

---

## 5. 死锁的不可逆性分析

### 5.1 正常渠道无法恢复

| 恢复方式 | 可行性 | 原因 |
|---------|--------|------|
| 通过UI继续收款 | ❌ 不可行 | `workflow_can('pay')` 返回 false，按钮隐藏 |
| 通过支付页面收款 | ❌ 不可行 | Prepare动作检查 `can('pay')` 后重定向 |
| 通过API收款 | ❌ 不可行 | RecordPaymentProcessor检查 `can('pay')` 抛422 |
| 通过MCP收款 | ❌ 不可行 | PaymentWriteTools检查 `can('pay')` 抛异常 |
| 通过工作流reopen | ❌ 不可行 | Paid不在reopen的源状态列表中 |
| 通过编辑修正 | ❌ 不可行 | Edit动作直接重定向 |
| 取消发票 | ❌ 不可行 | `cancel` 转换不允许从Paid执行 |

### 5.2 仅有的恢复方式

#### 方式1：数据库直接修改（不推荐）

```sql
-- 手动回退状态
UPDATE invoice 
SET status = 'pending', 
    balance = 70000  -- $700.00 (以分为单位)
WHERE id = 'invoice-id';

-- 或者删除错误的付款记录
DELETE FROM payment WHERE id = 'payment-id';
-- 然后手动更新发票状态和余额
```

**风险**：
- 绕过业务逻辑，可能触发其他不一致
- 没有审计日志
- 需要数据库访问权限

#### 方式2：归档后重建（业务 workaround）

```
1. 将Paid状态的发票归档（唯一允许的转换）
2. 创建一张新的发票
3. 重新记录正确的付款
```

**问题**：
- 发票编号不连续
- 审计轨迹混乱
- 客户可能收到两张发票

#### 方式3：代码修复后重新部署（推荐）

见第6章的修复方案。

---

## 6. 修复建议

### 6.1 紧急修复：RecordPaymentProcessor和PaymentWriteTools

在调用 `apply('pay')` 前添加全额付款检查：

```php
// 在 apply 前添加此检查
$totalPaid = $this->entityManager->getRepository(Payment::class)
    ->getTotalPaidForInvoice($invoice);

if ($totalPaid->isLessThan($invoice->getTotal())) {
    // 部分付款：只更新余额，不改变状态
    $invoice->setBalance($invoice->getTotal()->minus($totalPaid));
    $this->entityManager->persist($invoice);
    $this->entityManager->flush();
} else {
    // 全额付款：触发状态转换
    $this->invoiceWorkflow->apply($invoice, InvoiceGraph::TRANSITION_PAY);
}
```

**代码位置**：
- `src/ApiBundle/State/Processor/RecordPaymentProcessor.php:96` 前
- `src/PaymentBundle/Mcp/PaymentWriteTools.php:138` 前

### 6.2 中期修复：扩展工作流配置

在 `config/packages/workflow.php` 中添加Paid状态的回退转换：

```php
$invoiceWorkflow
    ->transition()
    ->name(InvoiceGraph::TRANSITION_REOPEN)
    ->from(InvoiceStatus::Cancelled->value)
    ->from(InvoiceStatus::Paid->value)        // 新增：允许从Paid重新打开
    ->to(InvoiceStatus::Pending->value);       // 修改：到Pending而非Draft
```

同时修改 `Edit.php` 的门禁，允许Paid状态的发票在特定条件下编辑。

### 6.3 长期修复：添加付款删除监听器

创建 `PaymentRemoveListener` 处理付款删除时的回滚逻辑，详见 `payment-invoice-consistency-analysis.md` 第5.2节。

---

## 7. 关键代码位置速查表

| 功能 | 文件 | 行号 |
|------|------|------|
| pay转换定义 | `config/packages/workflow.php` | 81-85 |
| reopen转换定义 | `config/packages/workflow.php` | 87-91 |
| UI层支付按钮门禁 | `src/InvoiceBundle/Resources/views/Default/view.html.twig` | 125 |
| Controller层支付门禁 | `src/PaymentBundle/Action/Prepare.php` | 100-117 |
| API层支付门禁 | `src/ApiBundle/State/Processor/RecordPaymentProcessor.php` | 74-78 |
| MCP层支付门禁 | `src/PaymentBundle/Mcp/PaymentWriteTools.php` | 108-113 |
| 编辑操作门禁 | `src/InvoiceBundle/Action/Edit.php` | 58-64 |
| 未结发票过滤 | `src/ClientBundle/Entity/Client.php` | 474-481 |
| 客户总余额统计 | `src/ClientBundle/DataGrid/BaseClientGrid.php` | 57-64 |
| PDF显示逻辑 | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | 128 |
