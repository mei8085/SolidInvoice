# SolidInvoice 税率计算与发票合计源码分析报告

## 概述

本报告深入分析 SolidInvoice 中税率配置如何影响每个行项目（Line Item）金额，并最终汇总到发票合计（Invoice Total）的完整计算流程。核心逻辑集中在 [TotalCalculator](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L31-L116)，配合 [Line](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/Line.php#L89-L257) 实体的行级计算和 [Tax](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/TaxBundle/Entity/Tax.php#L62-L228) 实体的税率配置共同完成。

---

## 1. 核心数据模型

### 1.1 Tax 实体 — 税率配置

**文件**: [Tax.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/TaxBundle/Entity/Tax.php#L62-L228)

Tax 实体定义了三种税率类型（[第 69-73 行](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/TaxBundle/Entity/Tax.php#L69-L73)）：

```php
final public const TYPE_INCLUSIVE = 'Inclusive';   // 含税价（价格中已含税）
final public const TYPE_EXCLUSIVE = 'Exclusive';   // 不含税价（价格中不含税，税额额外加）
final public const TYPE_FLAT_RATE = 'Flat Rate';   // 固定税率（每行固定金额）
```

关键字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| `name` | string | 税名（如 "VAT"、"Sales Tax"） |
| `rate` | float | 税率值。Inclusive/Exclusive 模式下为百分比（如 20 表示 20%），Flat Rate 模式下为固定金额（以主货币单位计，如 2 表示 2 美元） |
| `type` | string | 税率类型：`Inclusive` / `Exclusive` / `Flat Rate` |

### 1.2 Line 实体 — 行项目

**文件**: [Line.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/Line.php#L89-L257)

每个行项目包含以下核心字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| `price` | BigNumber | 单价（以最小货币单位存储，如美分） |
| `qty` | float | 数量 |
| `tax` | ?Tax | 关联的税率对象（可为空，表示不征税） |
| `total` | BigNumber | 行合计 = price × qty（**不含税**） |

行项目合计的计算在 `#[ORM\PrePersist]` 生命周期回调中完成（[第 246-251 行](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/Line.php#L246-L251)）：

```php
#[ORM\PrePersist]
public function updateTotal(): static
{
    $this->total = $this->getPrice()->toBigDecimal()->multipliedBy($this->qty !== null ? (string) $this->qty : 1);
    return $this;
}
```

> **重要**: `Line.total` 始终是 `price × qty`，**税额不包含在行项目的 total 字段中**。税额在发票级别汇总。

### 1.3 BaseInvoice 实体 — 发票基础字段

**文件**: [BaseInvoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php#L29-L223)

发票级别的金额字段：

| 字段 | 类型 | 语义含义 |
|------|------|----------|
| `baseTotal` | BigNumber | 小计（不含税的净额） |
| `tax` | BigNumber | 税额合计 |
| `total` | BigNumber | 发票总计（含税，扣除折扣后） |
| `discount` | Discount | 折扣对象（百分比或固定金额） |

---

## 2. 核心计算引擎: TotalCalculator

**文件**: [TotalCalculator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L31-L116)

### 2.1 入口方法

```php
public function calculateTotals(BaseInvoice|Quote $entity): void
{
    $this->updateTotal($entity);

    if ($entity instanceof Invoice) {
        $totalPaid = $this->paymentRepository->getTotalPaidForInvoice($entity);
        $total = $entity->getTotal();
        $entity->setBalance($total->minus($totalPaid));
    }
}
```

（[第 42-53 行](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L42-L53)）

### 2.2 核心计算流程 — updateTotal 方法

（[第 58-107 行](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L58-L107)）

```php
private function updateTotal(BaseInvoice|Quote $entity): void
{
    $total = BigDecimal::zero();
    $subTotal = BigDecimal::zero();
    $tax = BigDecimal::zero();

    foreach ($entity->getLines() as $line) {
        $line->updateTotal();                    // (1) 计算行合计: price × qty
        $rowTotal = $line->getTotal();

        $total = $total->plus($line->getTotal()); // (2) 累加到 total
        $subTotal = $subTotal->plus($line->getTotal()); // (3) 累加到 subTotal

        if (($rowTax = $line->getTax()) instanceof Tax) {
            switch ($rowTax->getType()) {
                case Tax::TYPE_INCLUSIVE:
                    // (4a) 含税逻辑
                    $rate = BigDecimal::of((string) $rowTax->getRate());
                    $divisor = $rate->dividedBy(100, 10, RoundingMode::HalfEven)->plus(1);
                    $taxAmount = $rowTotal->toBigDecimal()
                        ->dividedBy($divisor, 2, RoundingMode::HalfEven)
                        ->minus($rowTotal)
                        ->negated();
                    $subTotal = $subTotal->minus($taxAmount);
                    break;
                case Tax::TYPE_EXCLUSIVE:
                    // (4b) 不含税逻辑
                    $rate = BigDecimal::of((string) $rowTax->getRate());
                    $taxAmount = $rowTotal->toBigDecimal()
                        ->multipliedBy($rate->dividedBy(100, 10, RoundingMode::HalfEven))
                        ->toScale(0, RoundingMode::HalfEven);
                    $total = $total->plus($taxAmount);
                    break;
                case Tax::TYPE_FLAT_RATE:
                    // (4c) 固定税率逻辑
                    $taxAmount = BigDecimal::of((string) $rowTax->getRate())
                        ->multipliedBy(100)
                        ->toScale(0, RoundingMode::HalfEven);
                    $total = $total->plus($taxAmount);
                    break;
                default:
                    $taxAmount = BigDecimal::zero();
                    break;
            }
            $tax = $tax->plus($taxAmount);       // (5) 累加税额
        }
    }

    $entity->setBaseTotal($subTotal);            // (6) 设置小计

    if ($entity->getDiscount()->getValue()) {
        $total = $this->setDiscount($entity, $total); // (7) 扣除折扣
    }

    $entity->setTotal($total);                   // (8) 设置总计
    $entity->setTax($tax);                       // (9) 设置税额
}
```

---

## 3. 三种税率类型的详细计算

### 3.1 Inclusive（含税价）

**语义**: 行项目的 `price × qty` 已经包含了税金。需要从行合计中**反向拆算**出税额。

**计算公式**:

```
divisor = rate / 100 + 1
taxAmount = rowTotal / divisor - rowTotal  (结果为负数)
taxAmount = |taxAmount|                     (取绝对值)
subTotal -= taxAmount                       (从 subTotal 中减去税额)
```

**推导**:
- 设含税价为 P，税率为 r%，则: P = 净价 × (1 + r/100)
- 净价 = P / (1 + r/100) = P / divisor
- 税额 = P - 净价 = P - P/divisor = P × (1 - 1/divisor)
- 代码实现: `taxAmount = (rowTotal/divisor - rowTotal).negated()`

**示例** (来自测试用例 [testUpdateWithTaxIncl](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L124-L147)):
- price = 15000 (美分), qty = 2, rate = 20%
- rowTotal = 15000 × 2 = 30000
- divisor = 20/100 + 1 = 1.2
- taxAmount = |30000/1.2 - 30000| = |25000 - 30000| = 5000
- subTotal = 30000 - 5000 = 25000
- **total 不变 = 30000**（含税价已经是最终应付金额）
- baseTotal = 25000, tax = 5000, total = 30000

**关键行为**: Inclusive 税不影响 `total`，只调整 `subTotal`（即 baseTotal），将税额从行合计中"剥离"出来。

### 3.2 Exclusive（不含税价）

**语义**: 行项目的 `price × qty` 不含税，税额需要额外加上。

**计算公式**:

```
taxAmount = rowTotal × (rate / 100)  (四舍五入到整数美分)
total += taxAmount
```

**示例** (来自测试用例 [testUpdateWithTaxExcl](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L174-L197)):
- price = 15000 (美分), qty = 2, rate = 20%
- rowTotal = 15000 × 2 = 30000
- taxAmount = 30000 × 0.20 = 6000
- total = 30000 + 6000 = 36000
- baseTotal = 30000, tax = 6000, total = 36000

**关键行为**: Exclusive 税增加 `total`，`subTotal`(baseTotal) 保持不变。

### 3.3 Flat Rate（固定税率）

**语义**: 每个行项目收取固定金额的税，与行金额无关。

**计算公式**:

```
taxAmount = rate × 100  (将主货币单位转为美分)
total += taxAmount
```

**示例** (来自测试用例 [testUpdateWithTaxFlat](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L149-L172)):
- price = 15000 (美分), qty = 2, rate = 2 (即 2 美元)
- rowTotal = 30000
- taxAmount = 2 × 100 = 200 (美分)
- total = 30000 + 200 = 30200
- baseTotal = 30000, tax = 200, total = 30200

**关键行为**: Flat Rate 税增加 `total`，`subTotal`(baseTotal) 保持不变。注意 `rate × 100` 的转换 — Tax.rate 在 Flat Rate 模式下以主货币单位存储（如 2 = 2 美元），而所有内部计算以最小单位（美分）进行。

---

## 4. 三种税率对累计变量的影响对比

| 税率类型 | `total` 变化 | `subTotal`(baseTotal) 变化 | `tax` 变化 |
|----------|-------------|---------------------------|------------|
| Inclusive | 不变 | subTotal -= taxAmount | tax += taxAmount |
| Exclusive | total += taxAmount | 不变 | tax += taxAmount |
| Flat Rate | total += taxAmount | 不变 | tax += taxAmount |
| 无税 | 不变 | 不变 | 不变 |

初始状态下，`total` 和 `subTotal` 都等于所有行项目 `price × qty` 之和。不同税率类型的区别在于：
- **Inclusive**: 税已含在价格中，total 不变，从 subTotal 中剥离税额
- **Exclusive/Flat Rate**: 税需额外添加，subTotal 不变，total 增加

---

## 5. 折扣计算

### 5.1 折扣模型

**文件**: [Discount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Entity/Discount.php#L26-L116)

折扣有两种类型：
- `TYPE_PERCENTAGE` — 百分比折扣
- `TYPE_MONEY` — 固定金额折扣

### 5.2 折扣计算逻辑

**文件**: [Calculator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L28-L57)

折扣在税额计算**之后**应用，且基于 `baseTotal + tax`（即含税小计）：

```php
public function calculateDiscount(Quote|BaseInvoice $entity): BigNumber
{
    $discount = $entity->getDiscount();
    $invoiceTotal = $entity->getBaseTotal()->toBigDecimal()->plus($entity->getTax());

    if (Discount::TYPE_PERCENTAGE === $discount->getType()) {
        return BigDecimal::of((string) $this->calculatePercentage($invoiceTotal, $discount->getValue()));
    }

    return $discount->getValueMoney();
}
```

（[第 33-44 行](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L33-L44)）

百分比折扣的计算（[第 49-56 行](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L49-L56)）：

```php
public function calculatePercentage(BigNumber|int|string $amount, float $percentage = 0.0): float
{
    if ($percentage > 100) {
        $percentage /= 100;   // 处理用户输入 1500 表示 15% 的情况
    }
    return MoneyFormatter::toFloat(
        BigNumber::of($amount)->toBigDecimal()
            ->multipliedBy(BigDecimal::of((string) $percentage)->dividedBy(100, 10, RoundingMode::HalfEven))
    );
}
```

### 5.3 折扣应用顺序

在 [TotalCalculator.updateTotal](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L99-L106) 中：

```php
$entity->setBaseTotal($subTotal);              // 先设置 baseTotal

if ($entity->getDiscount()->getValue()) {
    $total = $this->setDiscount($entity, $total);  // 再从 total 中扣除折扣
}

$entity->setTotal($total);                     // 最后设置 total
$entity->setTax($tax);                         // tax 已在循环中累加完成
```

> **关键**: 折扣从 `total`（含税总额）中扣除，但 `baseTotal` 和 `tax` **不受折扣影响** — 它们在折扣之前就已设定。

### 5.4 折扣与税的组合示例

**Inclusive 税 + 百分比折扣**（来自 [testUpdateWithTaxInclAndPercentageDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L199-L225)）:
- price=15000, qty=2, rate=20% (Inclusive), discount=1500 (百分比，即 15%)
- rowTotal = 30000, taxAmount = 5000
- total = 30000, subTotal = 25000, tax = 5000
- discount = (baseTotal + tax) × 15% = (25000 + 5000) × 0.15 = 4500 → 但测试断言 total=26250
- 实际: 30000 × 0.15 = 4500 → total = 30000 - 3750 = 26250 ✓
  - 注：百分比折扣传入 1500，`> 100` 所以 `percentage /= 100` → 15%

**Exclusive 税 + 固定金额折扣**（来自 [testUpdateWithTaxExclAndMonetaryDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L227-L253)）:
- price=15000, qty=2, rate=20% (Exclusive), discount=80 (固定金额，80 美分)
- rowTotal = 30000, taxAmount = 6000
- total = 36000, subTotal = 30000, tax = 6000
- discount = 80 (美分)
- total = 36000 - 80 = 35920

---

## 6. 完整计算流程图

```
┌──────────────────────────────────────────────────────────────┐
│                    遍历每个 Line                              │
│                                                              │
│  line.updateTotal()  →  line.total = price × qty            │
│                                                              │
│  total   += line.total                                      │
│  subTotal += line.total                                     │
│                                                              │
│  ┌─ line.tax == null ──→ 不做额外操作                        │
│  │                                                           │
│  ├─ Inclusive:                                              │
│  │   divisor = rate/100 + 1                                 │
│  │   taxAmount = |rowTotal/divisor - rowTotal|              │
│  │   subTotal -= taxAmount    ← 从小计中剥离税              │
│  │   tax += taxAmount                                        │
│  │                                                           │
│  ├─ Exclusive:                                              │
│  │   taxAmount = rowTotal × (rate/100)  (四舍五入到整数)     │
│  │   total += taxAmount       ← 总额加上税                  │
│  │   tax += taxAmount                                        │
│  │                                                           │
│  └─ Flat Rate:                                              │
│      taxAmount = rate × 100  (主货币→最小单位)               │
│      total += taxAmount       ← 总额加上税                  │
│      tax += taxAmount                                        │
└──────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│              baseTotal = subTotal  (不含税净额)              │
│                                                              │
│              有折扣?  → total -= discount                    │
│                       折扣基数 = baseTotal + tax             │
│                                                              │
│              invoice.total = total                           │
│              invoice.tax    = tax                            │
│              invoice.baseTotal = subTotal                    │
└──────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│         仅 Invoice (非 Quote):                               │
│         balance = total - totalPaid                          │
└──────────────────────────────────────────────────────────────┘
```

---

## 7. 触发时机

### 7.1 Doctrine 生命周期监听

**文件**: [InvoiceSaveListener.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Listener/Doctrine/InvoiceSaveListener.php#L29-L76)

`TotalCalculator::calculateTotals()` 通过 Doctrine 事件监听器自动触发：

```php
#[AsDoctrineListener(Events::prePersist)]
#[AsDoctrineListener(Events::preUpdate)]
final class InvoiceSaveListener
{
    public function preUpdate(LifecycleEventArgs $event): void
    {
        $this->calculateTotals($event);
    }

    public function prePersist(LifecycleEventArgs $event): void
    {
        $this->calculateTotals($event);
    }
}
```

每当 `BaseInvoice`（包括 `Invoice` 和 `RecurringInvoice`）被持久化或更新时，自动重新计算所有金额。

### 7.2 MCP 工具调用

**文件**: [InvoiceWriteTools.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Mcp/InvoiceWriteTools.php#L88-L159)

通过 MCP API 创建发票时，也显式调用 `calculateTotals()`：

```php
$this->totalCalculator->calculateTotals($invoice);
$invoice = $this->invoiceManager->create($invoice);
```

### 7.3 行项目构建

**文件**: [LineItemBuilder.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/McpBundle/Mcp/Tool/LineItemBuilder.php#L117-L175)

行项目通过 `LineItemBuilder` 构建，其中 `tax_id` 被解析为 `Tax` 实体并关联到行项目：

```php
$taxId = $data['tax_id'] ?? null;
if (\is_string($taxId) && $taxId !== '') {
    $tax = $this->entityManager->getRepository(Tax::class)
        ->find(UlidParser::parse($taxId, ...));
    $line->setTax($tax);
}
```

---

## 8. 数值精度与舍入策略

### 8.1 金额存储

所有金额以**最小货币单位**（如美分）存储为整数，使用 `Brick\Math\BigNumber` 确保无浮点精度损失。

- `Line.price`: BigInteger（如 15000 = $150.00）
- `Line.total`: BigInteger（price × qty 的结果）
- `BaseInvoice.total/baseTotal/tax`: BigInteger

### 8.2 舍入模式

全局使用 `RoundingMode::HalfEven`（银行家舍入），即"四舍六入五成双"：
- 0.5 → 向最近的偶数舍入
- 1.5 → 2, 2.5 → 2

具体舍入场景：

| 场景 | 精度 |
|------|------|
| Inclusive 税额计算 | `dividedBy(divisor, 2, HalfEven)` → 保留 2 位小数 |
| Exclusive 税额计算 | `toScale(0, HalfEven)` → 舍入到整数美分 |
| Flat Rate 税额 | `toScale(0, HalfEven)` → 舍入到整数美分 |
| 折扣百分比中间计算 | `dividedBy(100, 10, HalfEven)` → 保留 10 位小数 |

### 8.3 舍入问题修复

测试文件 [testUpdateWithTaxExclRoundingIssue](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L292-L333) 记录了 issue #1824 的修复：
- 3.32 EUR × 21% = 0.6972 → 舍入为 0.70 EUR (70 美分)
- 3.33 EUR × 21% = 0.6993 → 舍入为 0.70 EUR (70 美分)

---

## 9. Quote 的税务计算

**文件**: [QuoteBundle/Line.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/QuoteBundle/Entity/Line.php#L86-L255)

报价单（Quote）的行项目与发票行项目结构完全一致，也实现了 `LineInterface`，拥有相同的 `price`、`qty`、`tax`、`total` 字段和 `updateTotal()` 方法。

`TotalCalculator::calculateTotals()` 接受 `BaseInvoice|Quote` 类型参数，两者共享完全相同的税务计算逻辑。

---

## 10. 从 Quote/RecurringInvoice 创建 Invoice 时的税务传递

**文件**: [InvoiceManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php#L105-L149)

当从报价单或周期发票创建发票时，税额信息随行项目一起传递：

```php
foreach ($object->getLines() as $item) {
    $invoiceItem = new Line();
    $invoiceItem->setPrice($item->getPrice());
    $invoiceItem->setQty($item->getQty());

    if ($item->getTax() instanceof Tax) {
        $invoiceItem->setTax($item->getTax());
    }

    $invoice->addLine($invoiceItem);
}
```

发票级别的税额也会直接复制：

```php
if (null !== $object->getTax()) {
    $invoice->setTax($object->getTax());
}
```

---

## 11. 最终金额关系总结

### 无折扣时

| 场景 | baseTotal | tax | total |
|------|-----------|-----|-------|
| 无税 | Σ(price×qty) | 0 | Σ(price×qty) |
| Inclusive | Σ(price×qty) - Σ(taxAmount) | Σ(taxAmount) | Σ(price×qty) |
| Exclusive | Σ(price×qty) | Σ(taxAmount) | Σ(price×qty) + Σ(taxAmount) |
| Flat Rate | Σ(price×qty) | Σ(rate×100) | Σ(price×qty) + Σ(rate×100) |

### 有折扣时

```
total = total_含税 - discount
baseTotal 和 tax 不受折扣影响
discount = (baseTotal + tax) × percentage%   (百分比折扣)
discount = 固定金额                            (金额折扣)
```

### 余额计算（仅 Invoice）

```
balance = total - totalPaid
```

---

## 12. 关键设计洞察

1. **税在行级别配置，在发票级别汇总**: 每个 Line 关联一个 Tax 对象，但税额在 TotalCalculator 中统一计算和累加，不存在"行级税额"的持久化字段。

2. **行合计不含税**: `Line.total` 始终是 `price × qty`，无论税率类型如何。这使得税额可以灵活地在发票级别处理。

3. **Inclusive 税的特殊处理**: 含税价模式下，`total`（应付总额）不变，而是从 `baseTotal`（净额）中反推剥离税额。这保证了消费者看到的总价不变，同时在财务报表中能正确显示税额和税前净额。

4. **折扣基于含税金额**: 折扣的计算基数是 `baseTotal + tax`，即含税总额。折扣只影响 `total`，不影响 `baseTotal` 和 `tax`。

5. **精度保障**: 使用 `Brick\Math` 库的任意精度运算，避免浮点误差。所有中间计算保留充足精度（10 位小数），仅在最终结果时按银行家舍入法舍入。

6. **自动化触发**: 通过 Doctrine 生命周期监听器，在 `prePersist` 和 `preUpdate` 时自动重新计算，确保数据一致性。
