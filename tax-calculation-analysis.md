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
    $total = BigDecimal::zero();     // ← 局部变量，从零开始
    $subTotal = BigDecimal::zero();  // ← 局部变量，从零开始
    $tax = BigDecimal::zero();       // ← 局部变量，从零开始

    foreach ($entity->getLines() as $line) {
        $line->updateTotal();                    // (1) 计算行合计: price × qty
        $rowTotal = $line->getTotal();

        $total = $total->plus($line->getTotal()); // (2) 累加到 total
        $subTotal = $subTotal->plus($line->getTotal()); // (3) 累加到 subTotal

        if (($rowTax = $line->getTax()) instanceof Tax) {
            switch ($rowTax->getType()) {
                case Tax::TYPE_INCLUSIVE:
                    // ...
                    $subTotal = $subTotal->minus($taxAmount);
                    break;
                case Tax::TYPE_EXCLUSIVE:
                    // ...
                    $total = $total->plus($taxAmount);
                    break;
                case Tax::TYPE_FLAT_RATE:
                    // ...
                    $total = $total->plus($taxAmount);
                    break;
            }
            $tax = $tax->plus($taxAmount);
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

## 5. 折扣计算 — 三个核心问题

### 5.1 折扣模型

**文件**: [Discount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Entity/Discount.php#L26-L116)

折扣有两种类型：
- `TYPE_PERCENTAGE` — 百分比折扣
- `TYPE_MONEY` — 固定金额折扣

### 5.2 百分比折扣的基数到底是什么？

这是最容易困惑的地方。答案藏在两段代码的**执行时序**中。

**第一步**：[TotalCalculator.updateTotal](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L99-L106) 循环结束后：

```php
$entity->setBaseTotal($subTotal);           // 第99行 — 先把 baseTotal 写到实体上

if ($entity->getDiscount()->getValue()) {
    $total = $this->setDiscount($entity, $total);  // 第102行 — 再算折扣
}

$entity->setTotal($total);                  // 第105行 — 最后写 total
$entity->setTax($tax);                      // 第106行 — 最后写 tax
```

**第二步**：折扣方法 [setDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L112-L115) 调用 [Calculator.calculateDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L33-L44)：

```php
private function setDiscount(BaseInvoice|Quote $entity, BigDecimal|BigInteger $total): BigNumber
{
    return $total->minus($this->calculator->calculateDiscount($entity));
}
```

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

**时序真相**：

| 执行顺序 | 代码 | 实体上的 baseTotal | 实体上的 tax | 实体上的 total |
|----------|------|--------------------|--------------|----------------|
| 1 | `$entity->setBaseTotal($subTotal)` | ✅ 新值 | ❌ 旧值（初始 0） | ❌ 旧值 |
| 2 | `$this->setDiscount($entity, $total)` | ✅ 新值 | ❌ 旧值（初始 0） | ❌ 旧值 |
| 3 | `$entity->setTax($tax)` | ✅ 新值 | ✅ 新值 | ❌ 旧值 |
| 4 | `$entity->setTotal($total)` | ✅ 新值 | ✅ 新值 | ✅ 新值 |

**结论**：当 `Calculator.calculateDiscount()` 读取 `$entity->getTax()` 时，第106行的 `setTax()` **尚未执行**，因此 `$entity->getTax()` 返回的还是旧值。

- **首次创建发票**时，[BaseInvoice 构造函数](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php#L125-L131)将 `tax` 初始化为 `BigDecimal::zero()`，所以 `$entity->getTax()` 返回 **0**。百分比折扣的基数 = `baseTotal + 0` = **baseTotal**。
- **更新已有发票**时，`$entity->getTax()` 返回的是上一次持久化的旧税额，百分比折扣的基数 = `baseTotal + 旧税额`。

这是一个重要的时序细节——折扣计算时读取的 `tax` 不是本次循环算出的新税额，而是实体上尚未被覆盖的旧值。

### 5.3 百分比折扣的数值处理

**文件**: [Calculator.calculatePercentage](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L49-L56)

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

这段代码有一个**隐含约定**：
- 如果 `percentage > 100`，系统认为用户输入的是"千分位"表示（如输入 1500 意为 15%），会自动除以 100
- 如果 `percentage <= 100`，系统认为就是百分比值（如输入 15 就是 15%）

最终计算：`discount = amount × (percentage / 100)`

### 5.4 含税税率配折扣时，总额为什么会得到现在这个值？

这是最容易困惑的组合场景。我们用测试用例 [testUpdateWithTaxInclAndPercentageDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L199-L225) 逐行推演。

**输入**：
- price = 15000 (美分), qty = 2
- tax = Inclusive 20%
- discount = percentage, value = 1500

**第一步 — 遍历行项目**：

```
line.updateTotal() → rowTotal = 15000 × 2 = 30000

total   += 30000  → total = 30000
subTotal += 30000 → subTotal = 30000
```

**第二步 — 处理 Inclusive 税**：

```
rate = 20
divisor = 20/100 + 1 = 1.2
taxAmount = |30000 / 1.2 - 30000| = |25000 - 30000| = 5000

subTotal -= 5000  → subTotal = 25000   ← 从 subTotal 剥离税额
tax += 5000       → tax = 5000
total 不变         → total = 30000       ← Inclusive 税不改 total
```

循环结束后局部变量：
- `total = 30000`
- `subTotal = 25000`
- `tax = 5000`

**第三步 — 写入 baseTotal**：

```php
$entity->setBaseTotal($subTotal);  // entity.baseTotal = 25000
```

此时实体上的状态：
- `entity.baseTotal = 25000`（刚写入）
- `entity.tax = 0`（旧值，因为 setTax 还没执行！）

**第四步 — 计算折扣**：

```php
$total = $this->setDiscount($entity, $total);
// 内部调用 Calculator.calculateDiscount($entity)
```

进入 [Calculator.calculateDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L33-L44)：

```php
$invoiceTotal = $entity->getBaseTotal()->toBigDecimal()->plus($entity->getTax());
//             = 25000 + 0 = 25000      ← 旧税额是0！
```

然后因为 `percentage = 1500 > 100`，自动除以 100 → `15`：

```
discount = 25000 × (15 / 100) = 25000 × 0.15 = 3750
```

**第五步 — 扣除折扣**：

```
total = 30000 - 3750 = 26250
```

**第六步 — 写入剩余字段**：

```php
$entity->setTotal($total);  // entity.total = 26250
$entity->setTax($tax);      // entity.tax = 5000
```

**最终结果**：total = 26250, baseTotal = 25000, tax = 5000 — 与测试断言完全吻合。

**关键理解**：为什么折扣基数是 25000 而不是 30000？因为在折扣计算时 `entity.getTax()` 还没被更新（还是旧值 0），所以 `baseTotal + tax = 25000 + 0 = 25000`。如果 `setTax` 在折扣之前执行，基数就会变成 `25000 + 5000 = 30000`，折扣额 = 4500，total = 25500——但这**不是**当前代码的行为。

### 5.5 Exclusive 税 + 固定金额折扣的推演

测试用例 [testUpdateWithTaxExclAndMonetaryDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L227-L253)

**输入**：
- price = 15000, qty = 2
- tax = Exclusive 20%
- discount = money, value = 80 (80 美分)

**第一步 — 遍历行项目**：

```
rowTotal = 30000
total = 30000, subTotal = 30000
```

**第二步 — Exclusive 税**：

```
taxAmount = 30000 × 0.20 = 6000
total += 6000 → total = 36000
tax += 6000   → tax = 6000
subTotal 不变  → subTotal = 30000
```

**第三步 — 写入 baseTotal**：

```
entity.baseTotal = 30000
entity.tax 仍是旧值 0
```

**第四步 — 计算折扣**：

固定金额折扣直接返回 `discount.getValueMoney()` = 80，不走 `baseTotal + tax` 的路径。

**第五步**：

```
total = 36000 - 80 = 35920
```

最终：total = 35920, baseTotal = 30000, tax = 6000 ✓

### 5.6 无税 + 百分比折扣的推演

测试用例 [testUpdateWithPercentageDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L80-L100)

**输入**：
- price = 15000, qty = 2
- 无税
- discount = percentage, value = 15

**第一步**：

```
rowTotal = 30000
total = 30000, subTotal = 30000, tax = 0
```

**第二步 — 无税，跳过**

**第三步 — 写入 baseTotal**：

```
entity.baseTotal = 30000
entity.tax 旧值 = 0
```

**第四步 — 折扣**：

```
invoiceTotal = 30000 + 0 = 30000
percentage = 15 (不大于100，不除)
discount = 30000 × (15 / 100) = 4500
total = 30000 - 4500 = 25500
```

最终：total = 25500, baseTotal = 30000, tax = 0 ✓

---

## 6. 重新汇总时旧税额会不会被带进去？

### 6.1 答案：不会

[TotalCalculator.updateTotal](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L60-L63) 在方法开头用**局部变量**从零开始：

```php
$total = BigDecimal::zero();
$subTotal = BigDecimal::zero();
$tax = BigDecimal::zero();
```

这三个局部变量和实体上已有的 `entity.total`、`entity.baseTotal`、`entity.tax` **完全没有关系**。循环中所有累加都基于局部变量，循环结束后才通过 setter 覆盖实体上的值。

**所以旧税额不可能被带进新计算中**——每次都是从头算。

### 6.2 但有一个微妙之处：折扣计算时读取的旧 tax

如上文 5.2 节分析，虽然循环中算出的新 `tax` 不会混入累加过程，但由于 `setTax()` 在 `setDiscount()` **之后**执行，折扣计算时从实体读取的 `$entity->getTax()` 是旧值。

- **首次创建**：旧值 = 0（构造函数初始化），不影响结果
- **更新已有发票**（preUpdate 触发）：旧值 = 上次持久化的税额

这意味着在更新场景下，百分比折扣的基数 = `新baseTotal + 旧tax`，而不是 `新baseTotal + 新tax`。这在绝大多数情况下不构成问题（因为发票一旦创建税额很少变化），但严格来说是一个**时序依赖**：如果行项目的税率被修改后更新发票，折扣基数中的 tax 部分仍使用旧值。

---

## 7. 完整计算流程图

```
┌───────────────────────────────────────────────────────────────────┐
│  updateTotal() 入口                                              │
│                                                                   │
│  局部变量 total=0, subTotal=0, tax=0  （与实体旧值无关）          │
│                                                                   │
│  ┌─ 遍历每个 Line ──────────────────────────────────────────────┐ │
│  │                                                               │ │
│  │  line.updateTotal()  →  line.total = price × qty             │ │
│  │                                                               │ │
│  │  total   += line.total                                       │ │
│  │  subTotal += line.total                                      │ │
│  │                                                               │ │
│  │  ┌─ line.tax == null ──→ 不做额外操作                        │ │
│  │  │                                                            │ │
│  │  ├─ Inclusive:                                               │ │
│  │  │   divisor = rate/100 + 1                                  │ │
│  │  │   taxAmount = |rowTotal/divisor - rowTotal|               │ │
│  │  │   subTotal -= taxAmount    ← 从小计中剥离税               │ │
│  │  │   tax += taxAmount                                         │ │
│  │  │                                                            │ │
│  │  ├─ Exclusive:                                               │ │
│  │  │   taxAmount = rowTotal × (rate/100)  (四舍五入到整数)      │ │
│  │  │   total += taxAmount       ← 总额加上税                   │ │
│  │  │   tax += taxAmount                                         │ │
│  │  │                                                            │ │
│  │  └─ Flat Rate:                                               │ │
│  │      taxAmount = rate × 100  (主货币→最小单位)                │ │
│  │      total += taxAmount       ← 总额加上税                   │ │
│  │      tax += taxAmount                                         │ │
│  └───────────────────────────────────────────────────────────────┘ │
│                            │                                      │
│                            ▼                                      │
│  entity.setBaseTotal(subTotal)   →  实体 baseTotal = 新值        │
│  (此时 entity.tax 仍是旧值)                                       │
│                            │                                      │
│                            ▼                                      │
│  ┌─ 有折扣? ───────────────────────────────────────────────────┐  │
│  │                                                              │  │
│  │  百分比折扣:                                                 │  │
│  │    base = entity.baseTotal + entity.tax  ← tax 是旧值!      │  │
│  │    discount = base × (percentage/100)                        │  │
│  │                                                              │  │
│  │  固定金额折扣:                                               │  │
│  │    discount = discount.valueMoney                            │  │
│  │                                                              │  │
│  │  total = total - discount                                    │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                            │                                      │
│                            ▼                                      │
│  entity.setTotal(total)          →  实体 total = 新值            │
│  entity.setTax(tax)              →  实体 tax = 新值（最后写入）  │
└───────────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│         仅 Invoice (非 Quote):                               │
│         balance = total - totalPaid                          │
└──────────────────────────────────────────────────────────────┘
```

---

## 8. 所有测试用例逐步验证

以下用表格形式逐一验证 [TotalCalculatorTest](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L34-L334) 中的每个场景，所有金额单位为最小货币单位（美分）。

### 8.1 testUpdateWithSingleItem (第44行)

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=1, 无税 | 15000 | 15000 | 0 |
| setBaseTotal | | | 15000→entity | |
| 折扣 | 无 | | | |
| 结果 | | **15000** | **15000** | **0** |

断言: total=15000 ✓, balance=15000 ✓, baseTotal=15000 ✓

### 8.2 testUpdateWithSingleItemAndMultipleQtys (第62行)

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=2, 无税 | 30000 | 30000 | 0 |
| 结果 | | **30000** | **30000** | **0** |

### 8.3 testUpdateWithPercentageDiscount (第80行)

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=2, 无税 | 30000 | 30000 | 0 |
| setBaseTotal | entity.baseTotal=30000 | | | |
| 折扣 | base=30000+0=30000, 15%→4500 | 30000-4500=25500 | | |
| 结果 | | **25500** | **30000** | **0** |

### 8.4 testUpdateWithMonetaryDiscount (第102行)

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=2, 无税 | 30000 | 30000 | 0 |
| 折扣 | 固定金额=80 | 30000-80=29920 | | |
| 结果 | | **29920** | **30000** | **0** |

### 8.5 testUpdateWithTaxIncl (第124行)

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=2, Inclusive 20% | 30000 | 30000 | 0 |
| 税处理 | divisor=1.2, taxAmount=5000 | 30000 | 25000 | 5000 |
| 无折扣 | | | | |
| 结果 | | **30000** | **25000** | **5000** |

### 8.6 testUpdateWithTaxFlat (第149行)

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=2, Flat Rate 2 | 30000 | 30000 | 0 |
| 税处理 | taxAmount=2×100=200 | 30200 | 30000 | 200 |
| 结果 | | **30200** | **30000** | **200** |

### 8.7 testUpdateWithTaxExcl (第174行)

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=2, Exclusive 20% | 30000 | 30000 | 0 |
| 税处理 | taxAmount=30000×0.20=6000 | 36000 | 30000 | 6000 |
| 结果 | | **36000** | **30000** | **6000** |

### 8.8 testUpdateWithTaxInclAndPercentageDiscount (第199行) ⭐ 重点

| 步骤 | 计算 | total(局部) | subTotal(局部) | tax(局部) | entity.baseTotal | entity.tax |
|------|------|------------|---------------|-----------|-----------------|------------|
| 初始 | | 0 | 0 | 0 | 0 | 0 |
| 行循环 | rowTotal=30000 | 30000 | 30000 | 0 | | |
| Inclusive | taxAmount=5000 | 30000 | 25000 | 5000 | | |
| setBaseTotal | | | | | **25000** | 0(旧) |
| 折扣 | base=25000+0=25000, 1500→15%→3750 | **26250** | | | | |
| setTotal | | | | | | |
| setTax | | | | | | **5000** |
| **最终** | | **26250** | **25000** | **5000** | | |

断言: total=26250 ✓, balance=26250 ✓, baseTotal=25000 ✓, tax=5000 ✓

### 8.9 testUpdateWithTaxExclAndMonetaryDiscount (第227行)

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | rowTotal=30000, Exclusive 20% | 36000 | 30000 | 6000 |
| setBaseTotal | entity.baseTotal=30000 | | | |
| 折扣 | 固定金额=80 | 36000-80=35920 | | |
| 结果 | | **35920** | **30000** | **6000** |

---

## 9. 触发时机

### 9.1 Doctrine 生命周期监听

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

### 9.2 MCP 工具调用

**文件**: [InvoiceWriteTools.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Mcp/InvoiceWriteTools.php#L88-L159)

通过 MCP API 创建发票时，也显式调用 `calculateTotals()`：

```php
$this->totalCalculator->calculateTotals($invoice);
$invoice = $this->invoiceManager->create($invoice);
```

### 9.3 行项目构建

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

## 10. 数值精度与舍入策略

### 10.1 金额存储

所有金额以**最小货币单位**（如美分）存储为整数，使用 `Brick\Math\BigNumber` 确保无浮点精度损失。

- `Line.price`: BigInteger（如 15000 = $150.00）
- `Line.total`: BigInteger（price × qty 的结果）
- `BaseInvoice.total/baseTotal/tax`: BigInteger

### 10.2 舍入模式

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

### 10.3 舍入问题修复

测试文件 [testUpdateWithTaxExclRoundingIssue](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L292-L333) 记录了 issue #1824 的修复：
- 3.32 EUR × 21% = 0.6972 → 舍入为 0.70 EUR (70 美分)
- 3.33 EUR × 21% = 0.6993 → 舍入为 0.70 EUR (70 美分)

---

## 11. Quote 的税务计算

**文件**: [QuoteBundle/Line.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/QuoteBundle/Entity/Line.php#L86-L255)

报价单（Quote）的行项目与发票行项目结构完全一致，也实现了 `LineInterface`，拥有相同的 `price`、`qty`、`tax`、`total` 字段和 `updateTotal()` 方法。

`TotalCalculator::calculateTotals()` 接受 `BaseInvoice|Quote` 类型参数，两者共享完全相同的税务计算逻辑。

---

## 12. 从 Quote/RecurringInvoice 创建 Invoice 时的税务传递

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

## 13. 最终金额关系总结

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

百分比折扣:
  首次创建时: discount = baseTotal × (percentage/100)
  更新时:     discount = (baseTotal + 旧tax) × (percentage/100)

固定金额折扣:
  discount = 固定金额值

baseTotal 和 新tax 不受折扣影响
```

### 余额计算（仅 Invoice）

```
balance = total - totalPaid
```

---

## 14. 关键设计洞察

1. **税在行级别配置，在发票级别汇总**: 每个 Line 关联一个 Tax 对象，但税额在 TotalCalculator 中统一计算和累加，不存在"行级税额"的持久化字段。

2. **行合计不含税**: `Line.total` 始终是 `price × qty`，无论税率类型如何。这使得税额可以灵活地在发票级别处理。

3. **Inclusive 税的特殊处理**: 含税价模式下，`total`（应付总额）不变，而是从 `baseTotal`（净额）中反推剥离税额。这保证了消费者看到的总价不变，同时在财务报表中能正确显示税额和税前净额。

4. **折扣基数的时序依赖**: 百分比折扣的基数是 `entity.baseTotal + entity.tax`，但由于 `setTax()` 在折扣计算之后才执行，折扣读取的 `tax` 是旧值。首次创建时旧值为 0，更新时为上次持久化的值。这是一个隐含的设计决策。

5. **重新计算不会残留旧值**: `updateTotal()` 用局部变量从零累加，完全覆盖实体上的旧值，不存在旧税额混入新计算的风险。唯一需要注意的是上条提到的折扣基数时序问题。

6. **精度保障**: 使用 `Brick\Math` 库的任意精度运算，避免浮点误差。所有中间计算保留充足精度（10 位小数），仅在最终结果时按银行家舍入法舍入。

7. **自动化触发**: 通过 Doctrine 生命周期监听器，在 `prePersist` 和 `preUpdate` 时自动重新计算，确保数据一致性。
