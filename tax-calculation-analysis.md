# SolidInvoice 税率计算与发票合计源码分析报告

## 概述

本报告深入分析 SolidInvoice 中税率配置如何影响每个行项目（Line Item）金额，并最终汇总到发票合计（Invoice Total）的完整计算流程。核心逻辑集中在 [TotalCalculator](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L31-L116)，配合 [Line](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/Line.php#L89-L257) 实体的行级计算和 [Tax](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/TaxBundle/Entity/Tax.php#L62-L228) 实体的税率配置共同完成。

本报告在上一版基础上，特别深入回答三个问题：
1. 创建正式发票时，既有税额可能从哪些途径提前赋值给对象
2. 总额与税额的回写顺序是怎样的
3. 这些分支如何影响百分比折扣的计算基数

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

**构造函数默认值**（[第 125-131 行](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php#L125-L131)）：

```php
public function __construct()
{
    $this->discount = new Discount();
    $this->baseTotal = BigDecimal::zero();
    $this->tax = BigDecimal::zero();
    $this->total = BigDecimal::zero();
}
```

新建发票后未做任何赋值时，三者初始值均为 0。

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

**示例**:
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

**示例**:
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

**示例**:
- price = 15000 (美分), qty = 2, rate = 2 (即 2 美元)
- rowTotal = 30000
- taxAmount = 2 × 100 = 200 (美分)
- total = 30000 + 200 = 30200
- baseTotal = 30000, tax = 200, total = 30200

**关键行为**: Flat Rate 税增加 `total`，`subTotal`(baseTotal) 保持不变。注意 `rate × 100` 的转换 — Tax.rate 在 Flat Rate 模式下以主货币单位存储（如 2 = 2 美元），而所有内部计算以最小单位（美分）进行。

### 3.4 三种税率对累计变量的影响对比

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

## 4. 折扣计算详解

### 4.1 折扣模型

**文件**: [Discount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Entity/Discount.php#L26-L116)

折扣有两种类型：
- `TYPE_PERCENTAGE` — 百分比折扣
- `TYPE_MONEY` — 固定金额折扣

### 4.2 总额与税额的回写顺序（⭐ 关键）

这是理解折扣基数的核心。[TotalCalculator.updateTotal](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L99-L106) 中的回写顺序是：

```php
// 第99行: 先写 baseTotal
$entity->setBaseTotal($subTotal);

// 第102行: 然后算折扣（此时 entity.tax 还没更新！）
if ($entity->getDiscount()->getValue()) {
    $total = $this->setDiscount($entity, $total);
}

// 第105行: 最后写 total
$entity->setTotal($total);
// 第106行: 最后写 tax
$entity->setTax($tax);
```

调用时序表：

| 执行顺序 | 代码 | entity.baseTotal | entity.tax | entity.total |
|----------|------|------------------|------------|--------------|
| 1 | `setBaseTotal($subTotal)` | ✅ **新值** | ❌ **旧值** | ❌ 旧值 |
| 2 | `setDiscount($entity, $total)` → 内部读 `entity.getBaseTotal() + entity.getTax()` | ✅ 新值 | ❌ 旧值 | ❌ 旧值 |
| 3 | `setTotal($total)` | ✅ 新值 | ❌ 旧值 | ✅ **新值** |
| 4 | `setTax($tax)` | ✅ 新值 | ✅ **新值** | ✅ 新值 |

折扣计算发生在第2步，此时 `entity.tax` 还**保留着 calculateTotals 调用之前的值**，而 `entity.baseTotal` 已经是本次新算的值。

### 4.3 百分比折扣的数值处理

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

隐含约定：
- 如果 `percentage > 100`，系统认为用户输入的是"千分位"表示（如输入 1500 意为 15%），自动除以 100
- 如果 `percentage <= 100`，系统认为就是百分比值（如输入 15 就是 15%）

最终计算：`discount = amount × (percentage / 100)`

### 4.4 含税税率配折扣的完整推演（⭐ 核心问题）

**场景**：price=15000, qty=2, Inclusive 20%, percentage discount=1500

**逐步推演**：

| 步骤 | 代码位置 | 操作 | 局部total | 局部subTotal | 局部tax | entity.baseTotal | entity.tax |
|------|---------|------|-----------|-------------|---------|------------------|------------|
| 构造函数 | [BaseInvoice.php L125](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php#L125) | new Invoice() | — | — | — | 0 | 0 |
| 进入循环 | [TotalCalculator L60](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L60) | 局部变量初始化 | 0 | 0 | 0 | 0 | 0 |
| line.updateTotal() | [Line L246](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/Line.php#L246) | rowTotal = 15000×2 = 30000 | — | — | — | — | — |
| total += rowTotal | [TotalCalculator L108](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L108) | | 30000 | — | — | — | — |
| subTotal += rowTotal | [TotalCalculator L109](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L109) | | — | 30000 | — | — | — |
| Inclusive 税处理 | [TotalCalculator L113](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L113) | taxAmount = 5000 | 30000 | 25000 | 5000 | 0 | 0 |
| **setBaseTotal** | [TotalCalculator L99](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L99) | entity.baseTotal = 25000 | 30000 | 25000 | 5000 | **25000** | **0(旧)** |
| **算折扣** | [Calculator L37](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L37) | base = 25000 + 0 = **25000**<br>discount = 25000 × 15% = 3750<br>total = 30000 - 3750 = **26250** | **26250** | 25000 | 5000 | 25000 | 0 |
| setTotal | [TotalCalculator L105](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L105) | entity.total = 26250 | 26250 | 25000 | 5000 | 25000 | 0 |
| setTax | [TotalCalculator L106](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L106) | entity.tax = 5000 | 26250 | 25000 | 5000 | 25000 | **5000** |

**最终**: total=26250, baseTotal=25000, tax=5000 — 与 [测试断言](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L219-L224) 完全吻合。

**关键理解**：折扣基数是 `baseTotal + entity.tax`，但 `entity.tax` 在折扣计算时还是旧值（新建时为 0），所以基数 = 25000 + 0 = 25000。

### 4.5 百分比折扣完整生命周期 — 从算出数值到落库取整（⭐ 新增）

百分比折扣对总额的影响经历 **4 个阶段**。每个阶段的数值变化如下：

```
算出折扣数值  →  从总额中扣除  →  挂回实体对象  →  落库取整
   (Calculator)   (setDiscount)    (setter)       (BigIntegerType)
```

#### 阶段 1：算出折扣数值

**文件**: [Calculator.calculateDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L33-L44) + [Calculator.calculatePercentage](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Calculator.php#L49-L56)

```php
// 第37行: 计算基数 = 新baseTotal + entity.tax(旧值!)
$invoiceTotal = $entity->getBaseTotal()->toBigDecimal()->plus($entity->getTax());

// 第40行: 调用 calculatePercentage 算出折扣额
return BigDecimal::of((string) $this->calculatePercentage($invoiceTotal, $discount->getValue()));
```

`calculatePercentage` 内部：
- 若 `percentage > 100`，自动除以 100（千分位约定：输入 1500 表示 15%）
- `折扣率 = percentage / 100`，除法保留 10 位小数精度
- `折扣额 = 基数 × 折扣率`
- 结果先转 float，再转 string，最后转 BigDecimal 返回

> **注意 float 中转**：`calculatePercentage` 返回 `float`，然后 `BigDecimal::of((string) $floatValue)` 转回 BigDecimal。由于中间有一次 float 转换，极端精度场景下可能有微小误差，但在实际金额（整数美分）场景中可以忽略。

#### 阶段 2：从总额中扣除

**文件**: [TotalCalculator.setDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L112-L115)

```php
private function setDiscount(BaseInvoice|Quote $entity, BigDecimal|BigInteger $total): BigNumber
{
    return $total->minus($this->calculator->calculateDiscount($entity));
}
```

- 输入 `$total` 是局部变量，代表**含税总额**（所有行项目 + 所有税额）
- 减去折扣额后，返回扣完折扣的新 total
- 这一步是总额**真正减少**的地方

#### 阶段 3：挂回实体对象

**文件**: [TotalCalculator.updateTotal](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L99-L106) + [BaseInvoice.setTotal](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php#L141-L146)

```php
$entity->setTotal($total);    // 第105行: 写回实体
$entity->setTax($tax);        // 第106行: 税额写回（在折扣之后！）
```

setter 内部：
```php
$this->total = BigNumber::of($total);  // 用 BigNumber 包装，不改变数值
```

这一步只是把局部计算结果存入实体属性，**数值本身不变**。

#### 阶段 4：落库取整

**文件**: [BigIntegerType.convertToDatabaseValue](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Doctrine/Type/BigIntegerType.php#L50-L65)

```php
return $value->toScale(0, RoundingMode::HalfEven)->toInt();
```

Doctrine 持久化时，通过自定义类型 `BigIntegerType` 转换：
1. `toScale(0, RoundingMode::HalfEven)` — **银行家舍入**到 0 位小数（即整数美分）
2. `toInt()` — 转成整型存入数据库

> **何时会有小数？**
> - Inclusive 税的 `dividedBy(..., 2, HalfEven)` 保留 2 位小数，所以 subTotal/tax 可能有小数
> - 百分比折扣的中间计算精度很高，但最终结果通常是整数
> - 如果总额本身有小数，落库时会被取整

#### 完整生命周期示例追踪

**场景**：price=15000, qty=2, Inclusive 20%, 百分比折扣 15%（即 1500 千分位）

| 阶段 | 操作 | total 值 | 说明 |
|------|------|----------|------|
| 行循环结束 | 累加 line.total + Inclusive 税处理 | 30000（整数） | total 是含税价，不变；subTotal 减了 5000 税 |
| 阶段1：算出折扣 | 基数=25000+0=25000, 折扣=25000×15%=3750 | — | 折扣额 = 3750 |
| 阶段2：从总额扣除 | total = 30000 - 3750 | **26250** | 总额真正减少 3750 |
| 阶段3：挂回对象 | entity.setTotal(26250) | 26250 | 数值不变，只是存入实体 |
| 阶段4：落库取整 | toScale(0, HalfEven) → toInt() | 26250 | 已是整数，无变化 |

**有小数的场景**：price=1000, qty=1, Inclusive 3%, 百分比折扣 10%

| 阶段 | 操作 | total 值 | subTotal 值 | tax 值 |
|------|------|----------|-------------|--------|
| 行循环结束 | divisor=1.03, taxAmount=29.13 | 1000 | 970.87 | 29.13 |
| 阶段1：算折扣 | 基数=970.87 + 0(旧) = 970.87, 折扣=97.087 | — | | |
| 阶段2：扣折扣 | total = 1000 - 97.087 | **902.913** | | |
| 阶段3：挂回对象 | entity.setTotal(902.913) | 902.913 | 970.87 | 29.13 |
| 阶段4：落库取整 | toScale(0, HalfEven) | **903**（四舍五入） | 971 | 29 |

> 注意：落库时 total、baseTotal、tax 三个字段**各自独立取整**，可能出现 `baseTotal + tax ≠ total` 的微小偏差（1 美分以内），这是银行家舍入的正常现象。

---

## 5. 七大创建路径中税额的预赋值与折扣基数分析（⭐ 新章节）

在 SolidInvoice 中，创建 Invoice 的代码路径共有 **7 条**。每条路径在调用 `calculateTotals()` 之前，对 `entity.tax`、`entity.baseTotal`、`entity.total` 的预赋值行为都不同，这直接影响百分比折扣的计算基数。

### 5.0 两类路径总览

所有路径可分为 **两大类别**：

| 类别 | 特点 | 代表路径 | 折扣基数是否可能有偏差 |
|------|------|---------|----------------------|
| **A. 创建时直接带旧税额** | 新 Invoice 从**另一个已有实体**复制 tax 值过来，创建时就有非零税额 | Quote 转换、RecurringInvoice 转换、克隆 | 行项目不变时无偏差；行项目改动后有偏差 |
| **B. 后期修正流程** | 初始 tax=0 或由 DTO 传入，税额通过 calculateTotals 逐步计算得出 | MCP API、传统表单创建、Live 组件、编辑已有发票、DummyData、removeTax | 首次计算有偏差；后续计算逐步修正 |

**核心区别**：
- **A 类**：税额是"继承"来的，来源是另一个实体的持久化值
- **B 类**：税额是"算出来"的，经过 0 → 第一次计算 → 第二次计算 → 最终落库的修正过程

### 路径总览表

| # | 路径 | 代表文件 | 预赋值tax? | 预赋值baseTotal? | 预赋值total? | 触发calculateTotals时机 | 折扣基数中的tax值 |
|---|------|---------|-----------|------------------|-------------|----------------------|------------------|
| 1 | MCP API 创建 | [InvoiceWriteTools](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Mcp/InvoiceWriteTools.php#L88-L159) | ❌ 否 | ❌ 否 | ❌ 否 | 显式调用 + prePersist 监听（二次） | 0（构造函数默认） |
| 2 | 表单创建（传统控制器） | [Create action](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Action/Create.php#L100-L131) | ✅ 是（来自DTO） | ✅ 是（来自DTO） | ✅ 是（来自DTO） | prePersist 监听 | DTO 中的 tax 值 |
| 3 | 表单编辑（传统控制器） | [Edit action](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Action/Edit.php#L83-L106) | ✅ 是（来自DTO） | ✅ 是（来自DTO） | ✅ 是（来自DTO） | preUpdate 监听 | DTO 中的 tax 值（即原发票持久化值） |
| 4 | 表单创建/编辑（Live 组件） | [CreateInvoice](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Twig/Components/CreateInvoice.php#L117-L136) | ✅ 是（来自DTO） | ✅ 是（来自DTO） | ✅ 是（来自DTO） | PreReRender 显式 + prePersist/preUpdate 监听 | DTO 中的 tax 值 |
| 5 | 从 Quote/RecurringInvoice 转换 | [InvoiceManager](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php#L105-L149) | ✅ 是（源对象） | ✅ 是（源对象） | ✅ 是（源对象） | prePersist 监听 | 源 Quote/RecurringInvoice 的税额 |
| 6 | 克隆已有 Invoice | [InvoiceCloner](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Cloner/InvoiceCloner.php#L49-L104) | ✅ 是（原发票） | ✅ 是（原发票） | ✅ 是（原发票） | prePersist 监听 | 原发票的税额 |
| 7 | DummyData / 测试数据 / LineRepository | [InvoiceDummyDataLoader](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/DummyData/InvoiceDummyDataLoader.php#L54-L162) | ✅ 是（手动计算） | ✅ 是（手动计算） | ✅ 是（手动计算） | prePersist 监听 | 手动预填的税额 |

以下逐一详解：

---

### 路径 1：MCP API 创建发票

**文件**: [InvoiceWriteTools::createInvoice](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Mcp/InvoiceWriteTools.php#L88-L159)

**调用时序**：
1. `$invoice = new Invoice()` → 构造函数：baseTotal=0, tax=0, total=0
2. 设置 client、date、lines、discount 等
3. **第 154 行**：`$this->totalCalculator->calculateTotals($invoice)` ← 第一次计算
4. **第 156 行**：`$invoiceManager->create($invoice)` → 内部 persist+flush → 触发 `InvoiceSaveListener.prePersist` → 第二次 calculateTotals

**预赋值情况**：第 154 行之前，代码从未调用过 `setTax()`、`setBaseTotal()`、`setTotal()`，所以三者都保留构造函数默认值 0。

**对折扣基数的影响**：
- 第一次 calculateTotals（第 154 行）：`entity.tax = 0`（构造函数默认值），百分比折扣基数 = `新baseTotal + 0`
- 第二次 calculateTotals（prePersist 监听）：此时 `entity.tax` 已经在第一次计算中被写入了正确的新值，但第二次计算的循环会重新从局部变量 0 开始累加。然而，在**第二次的折扣计算阶段**，`entity.tax` 是**第一次计算写入的正确税额**。这意味着第二次计算的折扣基数 = `新baseTotal(重新算的) + 第一次算的tax`

> **注意**：这条路径会触发两次 calculateTotals。第一次是显式调用，第二次是 prePersist 事件。两次计算中折扣计算使用的 entity.tax 是不同的——第一次是 0，第二次是第一次计算后的值。

---

### 路径 2：表单创建发票（传统控制器）

**文件**: [Create::__invoke](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Action/Create.php#L100-L131)

**调用时序**：
1. `$dto = new InvoiceFormDTO()` → DTO 构造函数：total='0', baseTotal='0', tax='0'
2. 表单提交后：
   - 第 106 行：`$invoice = $this->formManager->createInvoiceFromDTO($dto)`
3. 第 118 行：`persist($invoice)` → 触发 prePersist → calculateTotals

进入 [InvoiceFormManager::createInvoiceFromDTO](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L40-L78)：

```php
public function createInvoiceFromDTO(InvoiceFormDTO $dto): Invoice
{
    $invoice = new Invoice();
    // ...
    $invoice->setTotal($dto->total);        // 第55行: 从DTO写回
    $invoice->setBaseTotal($dto->baseTotal); // 第56行: 从DTO写回
    $invoice->setTax($dto->tax);             // 第57行: 从DTO写回
    // ...
    return $invoice;
}
```

**预赋值情况**：createInvoiceFromDTO 把 DTO 中的 `total/baseTotal/tax` 全部预写到 Invoice 上。DTO 默认值都是字符串 '0'。

**对折扣基数的影响**：
- DTO 初始 tax='0'，所以 prePersist 触发 calculateTotals 时，`entity.tax = 0`，百分比折扣基数 = `新baseTotal + 0`
- 但在 Live 组件交互中（见路径 4），DTO.tax 在每次 PreReRender 中被 calculateTotals 的输出刷新，所以正式提交时 DTO.tax 是上一次算出来的税额。此时折扣基数 = `新baseTotal + 上次算的tax`

---

### 路径 3：表单编辑发票（传统控制器）

**文件**: [Edit::__invoke](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Action/Edit.php#L83-L106)

**调用时序**：
1. 第 76 行：`$dto = $this->formManager->createDTOFromInvoice($invoice)`
2. 表单提交后，第 87 行：`$this->formManager->updateInvoiceFromDTO($invoice, $dto)`
3. 第 94 行：`flush()` → 触发 preUpdate → calculateTotals

进入 [InvoiceFormManager::updateInvoiceFromDTO](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L84-L107)：

```php
public function updateInvoiceFromDTO(Invoice $invoice, InvoiceFormDTO $dto): void
{
    // ...
    $invoice->setTotal($dto->total);        // 第92行
    $invoice->setBaseTotal($dto->baseTotal); // 第93行
    $invoice->setTax($dto->tax);             // 第94行
    // ...
}
```

**预赋值情况**：DTO 从原 Invoice 实体读取（createDTOFromInvoice [第 127-129 行](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L127-L129)）：
```php
$dto->total = (string) $invoice->getTotal();
$dto->baseTotal = (string) $invoice->getBaseTotal();
$dto->tax = (string) $invoice->getTax();
```
所以 tax 预赋值 = 原发票上次持久化的税额。

**对折扣基数的影响**：
- 如果用户**没有修改行项目的税率**：`entity.tax(旧值) ≈ 本次循环算出的新tax`，折扣基数 ≈ `新baseTotal + 新tax`，误差很小
- 如果用户**修改了行项目税率**（如从 20% 改为 10%）：`entity.tax(旧值) ≠ 新tax`，折扣基数中的 tax 部分仍用旧值。例如旧 tax=5000 但新 tax=2500，折扣基数会多出 2500

---

### 路径 4：表单创建/编辑（Live 组件）

**文件**: [CreateInvoice](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Twig/Components/CreateInvoice.php#L117-L136)

**调用时序**：
每次 PreReRender 时（priority -10，在表单提交之后）：
1. 第 129 行：`$tempInvoice = $this->formManager->createInvoiceFromDTO($this->dto)`
2. 第 130 行：`$this->totalCalculator->calculateTotals($tempInvoice)`
3. 第 133-135 行：把算好的值写回 DTO

```php
$this->dto->total = (string) $tempInvoice->getTotal();
$this->dto->baseTotal = (string) $tempInvoice->getBaseTotal();
$this->dto->tax = (string) $tempInvoice->getTax();
```

这意味着在用户交互过程中，DTO 的 total/baseTotal/tax 会被反复刷新为 calculateTotals 的输出。

最终点击保存按钮时：
- 创建模式：走 InvoiceFormManager::createInvoiceFromDTO → persist → prePersist → calculateTotals
- 编辑模式：走 InvoiceFormManager::updateInvoiceFromDTO → flush → preUpdate → calculateTotals

**预赋值情况**：DTO.tax 是上一次 PreReRender 中 calculateTotals 算出的税额。

**对折扣基数的影响**：
- 假设用户每一步操作都触发了 PreReRender（Live 组件确实如此），DTO.tax 基本等于当前行项目算出的税额
- 在最终的 prePersist/preUpdate calculateTotals 中，entity.tax 预赋值 = "上一次渲染时算的税额"
- 只要用户最后一次操作和这次持久化之间行项目没变化（通常就是这样），这个预赋值等于本次循环将要算出的新税额
- 所以折扣基数 ≈ `新baseTotal + 新tax`，结果接近理想值

---

### 路径 5：从 Quote/RecurringInvoice 转换创建

**文件**: [InvoiceManager::createFromObject](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php#L105-L149)

```php
private function createFromObject(RecurringInvoice | Quote $object): Invoice
{
    $invoice = new Invoice();
    // ...
    $invoice->setBaseTotal($object->getBaseTotal());  // 第115行
    $invoice->setDiscount($object->getDiscount());    // 第116行
    $invoice->setTotal($object->getTotal());          // 第118行
    // ...
    if (null !== $object->getTax()) {
        $invoice->setTax($object->getTax());          // 第128-130行
    }

    // 同时复制所有行项目，每行的 Tax 对象也一并复制
    foreach ($object->getLines() as $item) {
        // ...
        if ($item->getTax() instanceof Tax) {
            $invoiceItem->setTax($item->getTax());    // 第141-143行
        }
        $invoice->addLine($invoiceItem);
    }

    return $invoice;
}
```

创建后，调用方（如 `createFromQuote` 或 `createFromRecurring`）返回 Invoice，后续流程中 persist 时触发 prePersist → calculateTotals。

**预赋值情况**：`entity.tax = $object->getTax()`，即源 Quote 或源 RecurringInvoice 的税额。

**对折扣基数的影响**：
- 如果源对象和目标发票行项目完全一致（正常转换场景），源对象的税额 ≈ 本次 Invoice 循环算出的新税额，折扣基数正确
- 如果在转换后、持久化前**修改了行项目**，折扣基数中的 tax 部分仍使用源对象的旧税额，可能产生偏差

---

### 路径 6：克隆已有 Invoice

**文件**: [InvoiceCloner::clone](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Cloner/InvoiceCloner.php#L49-L104)

```php
public function clone(Invoice|RecurringInvoice $invoice): Invoice|RecurringInvoice
{
    $newInvoice = new $class();
    // ...
    $newInvoice->setBaseTotal($invoice->getBaseTotal());  // 第61行
    $newInvoice->setDiscount($invoice->getDiscount());    // 第62行
    $newInvoice->setTotal($invoice->getTotal());          // 第64行
    // ...
    if (null !== $tax = $invoice->getTax()) {
        $newInvoice->setTax($tax);                        // 第93-95行
    }
    // ...行项目和 Tax 也一并复制...

    if ($newInvoice instanceof Invoice) {
        $this->invoiceManager->create($newInvoice);  // 第99-101行: persist+flush → prePersist
    }
    return $newInvoice;
}
```

**预赋值情况**：`newInvoice.tax = 原invoice.tax`，克隆时将原发票的税额全部复制过来。

**对折扣基数的影响**：
- 克隆场景下行项目完全一致，新旧税额相同，折扣基数正确
- 如果克隆后、persist 前修改了行项目，折扣基数可能有偏差

---

### 路径 7：DummyData / 测试数据 / LineRepository.removeTax

#### 7a. DummyData 加载器

**文件**: [InvoiceDummyDataLoader::load](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/DummyData/InvoiceDummyDataLoader.php#L54-L162)

```php
// 手动计算 baseTotal 和 taxTotal（只算了 Exclusive 税）
for ($j = 0; $j < $lineCount; ++$j) {
    // ...
    if ($tax->getType() === Tax::TYPE_EXCLUSIVE && $tax->getRate() > 0.0) {
        $taxAmount = $lineTotal->multipliedBy((string) $tax->getRate())->dividedBy(100, 0, RoundingMode::HalfUp);
        $taxTotal = $taxTotal->plus($taxAmount);
    }
}

$total = $baseTotal->plus($taxTotal);

$invoice->setBaseTotal($baseTotal)   // 第140行
    ->setTax($taxTotal)              // 第141行
    ->setTotal($total);              // 第142行

$em->persist($invoice);  // → prePersist → calculateTotals
```

**注意**：DummyDataLoader 手动计算税额时**只处理了 Exclusive 税**（见第 129 行条件 `$tax->getType() === Tax::TYPE_EXCLUSIVE`），Inclusive 和 Flat Rate 完全被忽略。但这些值随后在 prePersist 触发的 calculateTotals 中会被完整覆盖。

**预赋值情况**：entity.tax = 手动算的税额（只含 Exclusive）。

**对折扣基数的影响**：
- 如果有 Inclusive 或 Flat Rate 税，手动算的 taxTotal 与实际新 tax 不一致，折扣基数可能出错
- 最终持久化的值由 calculateTotals 重新计算，是正确的

#### 7b. LineRepository::removeTax

**文件**: [LineRepository::removeTax](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Repository/LineRepository.php#L40-L59)

```php
foreach ($query->toIterable() as $invoiceLine) {
    $invoiceLine->setTax(null);
    $invoiceLine->getInvoice()?->setTax(0);   // 第51行: 先手动把发票级 tax 置 0
    $this->calculator->calculateTotals($invoiceLine->getInvoice());  // 第53行
}
```

**预赋值情况**：显式调用 `setTax(0)` 后才调 calculateTotals，entity.tax = 0。

**对折扣基数的影响**：百分比折扣基数 = `新baseTotal + 0`，完全正确，因为移除税率后 tax 本来就应为 0。

---

### 路径汇总：折扣基数对照表

| 路径 | entity.tax(预赋值) | 折扣基数 = 新baseTotal + ? | 与理想值(新baseTotal+新tax)的偏差 |
|------|---------------------|---------------------------|--------------------------------|
| 1. MCP API（第一次 calculateTotals） | 0 | 新baseTotal + 0 | 新tax 部分缺失 |
| 1. MCP API（第二次 prePersist） | 第一次算的正确 tax | 新baseTotal + 第一次tax | 几乎无偏差（两次算的相同） |
| 2. 传统表单创建（首次提交） | '0' (DTO 默认) | 新baseTotal + 0 | 新tax 部分缺失 |
| 3. 传统表单编辑（税率未变） | 原发票持久化 tax | 新baseTotal + 旧tax | 几乎无偏差 |
| 3. 传统表单编辑（税率已改） | 原发票持久化 tax（旧） | 新baseTotal + 旧tax | **有偏差**，偏差 = 旧tax - 新tax |
| 4. Live 组件（最终提交） | 上次 PreReRender 算出的 tax | 新baseTotal + 上次tax | 几乎无偏差 |
| 5. Quote/Recurring 转换 | 源对象的 tax | 新baseTotal + 源对象tax | 行项目一致时无偏差 |
| 6. 克隆 | 原发票 tax | 新baseTotal + 原发票tax | 行项目一致时无偏差 |
| 7a. DummyData | 手动算的 Exclusive-only tax | 新baseTotal + 手动tax | 有 Inclusive/Flat Rate 时偏差 |
| 7b. removeTax | 0 | 新baseTotal + 0 | 完全正确 |

---

## 6. 重新汇总时旧税额会不会被带进去？

### 6.1 循环累加层面：完全不会

[TotalCalculator.updateTotal](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Billing/TotalCalculator.php#L60-L63) 在方法开头用**局部变量**从零开始：

```php
$total = BigDecimal::zero();
$subTotal = BigDecimal::zero();
$tax = BigDecimal::zero();
```

这三个局部变量与 `entity.total`、`entity.baseTotal`、`entity.tax` **完全没有关系**。所有累加基于局部变量，最后通过 setter 覆盖实体上的值。

**所以在税额累加层面，旧值完全不可能混入。**

### 6.2 折扣基数层面：会（受 entity.tax 旧值影响）

如上文 4.2 和第 5 章分析，`setTax()` 在折扣计算**之后**才执行，所以折扣基数中的 tax 部分读取的是 calculateTotals 调用之前 entity.tax 的值。

这是唯一会受"旧值"影响的地方。影响程度因创建路径而异（见第 5 章对照表）。

### 6.3 极端场景演示

假设用户编辑一张已有发票：
- 原发票：baseTotal=25000, tax=5000, total=30000（Inclusive 20%, 无折扣）
- 用户修改：把税率改成 Inclusive 10%，同时加上 15% 百分比折扣

**preUpdate 触发 calculateTotals 时**：
1. 循环中：rowTotal=30000, divisor=1.1, taxAmount=|30000/1.1 - 30000|≈2727, subTotal=27273
2. setBaseTotal(27273)
3. 算折扣：base = 27273 + entity.getTax() = 27273 + **5000（旧值！）** = 32273
4. discount = 32273 × 15% ≈ 4841
5. total = 30000 - 4841 = 25159
6. setTotal(25159), setTax(2727)（新税）

如果使用"正确"的新 tax=2727，折扣应该是 (27273+2727)×15% = 30000×15% = 4500，total=25500。实际结果 25159 与理想值 25500 差了 341 美分。

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
│  ⚠️ 此时 entity.tax 仍是【调用 calculateTotals 之前的旧值】      │
│                            │                                      │
│                            ▼                                      │
│  ┌─ 有折扣? ───────────────────────────────────────────────────┐  │
│  │                                                              │  │
│  │  百分比折扣:                                                 │  │
│  │    base = entity.baseTotal(新) + entity.tax(旧!)            │  │
│  │    discount = base × (percentage/100)                        │  │
│  │                                                              │  │
│  │  固定金额折扣:                                               │  │
│  │    discount = discount.valueMoney  (不走 base 逻辑)         │  │
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

以下逐一验证 [TotalCalculatorTest](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L34-L334) 中每个场景（单位：美分）。

### 8.1 testUpdateWithSingleItem

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=1, 无税 | 15000 | 15000 | 0 |
| 无折扣 | | | | |
| 结果 | | **15000** | **15000** | **0** |

### 8.2 testUpdateWithSingleItemAndMultipleQtys

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=2, 无税 | 30000 | 30000 | 0 |
| 结果 | | **30000** | **30000** | **0** |

### 8.3 testUpdateWithPercentageDiscount

| 步骤 | 计算 | total | subTotal | tax | entity.tax(旧) |
|------|------|-------|----------|-----|----------------|
| 行循环 | price=15000, qty=2, 无税 | 30000 | 30000 | 0 | 0 |
| 折扣 | base=30000+0=30000, 15%→4500 | **25500** | | | |
| 结果 | | 25500 | **30000** | **0** | |

### 8.4 testUpdateWithMonetaryDiscount

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 | price=15000, qty=2, 无税 | 30000 | 30000 | 0 |
| 折扣(固定) | 80 | 29920 | | |
| 结果 | | **29920** | **30000** | **0** |

### 8.5 testUpdateWithTaxIncl

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 + Inclusive 税 | price=15000, qty=2, 20% | 30000 | 25000 | 5000 |
| 无折扣 | | | | |
| 结果 | | **30000** | **25000** | **5000** |

### 8.6 testUpdateWithTaxFlat

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 + Flat Rate | rate=2 → taxAmount=200 | 30200 | 30000 | 200 |
| 结果 | | **30200** | **30000** | **200** |

### 8.7 testUpdateWithTaxExcl

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| 行循环 + Exclusive | taxAmount=30000×20%=6000 | 36000 | 30000 | 6000 |
| 结果 | | **36000** | **30000** | **6000** |

### 8.8 testUpdateWithTaxInclAndPercentageDiscount ⭐

| 步骤 | 计算 | total(局部) | subTotal | tax | entity.baseTotal | entity.tax(旧) |
|------|------|------------|----------|-----|------------------|----------------|
| Inclusive 税 | divisor=1.2, taxAmount=5000 | 30000 | 25000 | 5000 | 0 | 0 |
| setBaseTotal | | | | | **25000** | 0 |
| 折扣 | base=25000+0=25000, 1500→15%→3750 | **26250** | | | 25000 | 0 |
| setTotal/setTax | | | | | 25000 | **5000** |
| **最终** | | **26250** | **25000** | **5000** | | |

### 8.9 testUpdateWithTaxExclAndMonetaryDiscount

| 步骤 | 计算 | total | subTotal | tax |
|------|------|-------|----------|-----|
| Exclusive 税 | taxAmount=6000 | 36000 | 30000 | 6000 |
| 折扣(固定) | 80 | 35920 | | |
| 结果 | | **35920** | **30000** | **6000** |

### 8.10 testUpdateTotalsWithPayments

测试 [testUpdateTotalsWithPayments](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Tests/Billing/TotalCalculatorTest.php#L255-L284) 先在 calculateTotals 之前**手动预赋值** `setTotal(30000)`、`setBaseTotal(30000)`、`setBalance(30000)`。但在 calculateTotals 中：
- 局部变量从零累加算出 total=30000, subTotal=30000, tax=0，与预赋值一致
- 扣除已支付 1000，balance = 30000 - 1000 = 29000

这个测试证明：即便手动预赋值了错误的 total/baseTotal，calculateTotals 也会用基于行项目重新计算的值覆盖它们（只是在这个特殊测试中恰好数值一致）。

---

## 9. 触发时机 — 所有调用 calculateTotals 的位置

| 触发方式 | 文件 | 事件/调用点 |
|---------|------|------------|
| Doctrine 生命周期 | [InvoiceSaveListener](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Listener/Doctrine/InvoiceSaveListener.php#L27-L75) | `prePersist` 和 `preUpdate` |
| 显式调用(MCP) | [InvoiceWriteTools](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Mcp/InvoiceWriteTools.php#L154) | createInvoice 第 154 行 |
| 显式调用(MCP 周期) | [RecurringInvoiceWriteTools](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Mcp/RecurringInvoiceWriteTools.php#L149) | createRecurringInvoice |
| 显式调用(Live 组件) | [CreateInvoice](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Twig/Components/CreateInvoice.php#L117-L136) | PreReRender(priority -10) |
| 显式调用(Live 周期) | [CreateRecurringInvoice](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Twig/Components/CreateRecurringInvoice.php#L52-L56) | PreReRender |
| 显式调用(控制器校验失败) | [Create action](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Action/Create.php#L133-L144) / [Edit action](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Action/Edit.php#L108-L119) | 表单不合法时刷新 DTO |
| 显式调用(控制器周期) | [CreateRecurring](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Action/CreateRecurring.php#L114-L116) / [EditRecurring](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Action/EditRecurring.php#L76-L78) | 表单不合法时 |
| 显式调用(删除税率) | [LineRepository::removeTax](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Repository/LineRepository.php#L53) | 移除某个 Tax 关联后 |
| Quote 侧（对称） | [QuoteSaveListener](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/QuoteBundle/Listener/Doctrine/QuoteSaveListener.php) / [QuoteWriteTools](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/QuoteBundle/Mcp/QuoteWriteTools.php) / [CreateQuote](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/QuoteBundle/Twig/Components/CreateQuote.php) 等 | 与 Invoice 完全对称 |

---

## 10. 数值精度与舍入策略

### 10.1 金额存储

所有金额以**最小货币单位**（如美分）存储为整数，使用 `Brick\Math\BigNumber` 确保无浮点精度损失。

### 10.2 舍入模式

全局使用 `RoundingMode::HalfEven`（银行家舍入）。

| 场景 | 精度 |
|------|------|
| Inclusive 税额计算 | `dividedBy(divisor, 2, HalfEven)` → 保留 2 位小数 |
| Exclusive 税额计算 | `toScale(0, HalfEven)` → 舍入到整数美分 |
| Flat Rate 税额 | `toScale(0, HalfEven)` → 舍入到整数美分 |
| 折扣百分比中间计算 | `dividedBy(100, 10, HalfEven)` → 保留 10 位小数 |

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

百分比折扣:
  折扣基数 = 新baseTotal + 【调用calculateTotals前entity.tax的旧值】
  discount = 折扣基数 × (percentage / 100)
  具体"旧值"取决于创建路径（见第5章对照表）

固定金额折扣:
  discount = 固定金额值（不经过基数逻辑）

baseTotal 和 新tax 不受折扣影响
```

### 余额计算（仅 Invoice）

```
balance = total - totalPaid
```

---

## 12. 关键设计洞察

1. **税在行级别配置，在发票级别汇总**: 每个 Line 关联一个 Tax 对象，但税额在 TotalCalculator 中统一计算和累加，不存在"行级税额"的持久化字段。

2. **行合计不含税**: `Line.total` 始终是 `price × qty`，无论税率类型如何。税额只在发票级别处理。

3. **Inclusive 税的特殊处理**: 含税价模式下，`total`（应付总额）不变，而是从 `baseTotal`（净额）中反推剥离税额。

4. **回写顺序是设计关键**: `setBaseTotal → 折扣计算 → setTotal → setTax` 的顺序导致折扣计算时 `entity.tax` 仍为旧值。这不是 bug，而是有意识的实现决策——但在不同创建路径下行为不一致。

5. **7 条创建路径分两大类**:
   - **A 类（创建时带旧税额）**: Quote/RecurringInvoice 转换、克隆 —— 税额从源实体"继承"而来，行项目不变时折扣基数正确
   - **B 类（后期修正流程）**: MCP API、传统表单、Live 组件、DummyData、removeTax —— 初始 tax=0 或手动值，经过多次 calculateTotals 逐步修正到正确值

6. **重新计算不会残留旧值**: `updateTotal()` 用局部变量从零累加，完全覆盖实体上的旧值。唯一的"旧值影响"是折扣基数读取的 `entity.tax`。

7. **折扣生命周期四阶段**: 算出数值（Calculator）→ 从总额扣除（setDiscount）→ 挂回实体（setter）→ 落库取整（BigIntegerType）。只有第 2 阶段真正改变了总额数值，其他阶段只是传递或取整。

8. **落库时独立取整**: total、baseTotal、tax 三个字段在持久化时各自独立调用 `toScale(0, HalfEven)` 取整，可能出现 `round(baseTotal) + round(tax) ≠ round(total)` 的 1 美分偏差，属正常现象。

9. **精度保障**: 使用 `Brick\Math` 库的任意精度运算，中间计算保留充足精度，仅在最终结果和落库时按银行家舍入法舍入。

10. **自动化触发**: 通过 Doctrine 生命周期监听器在 `prePersist` 和 `preUpdate` 时自动重新计算，确保数据一致性。但在 MCP 等路径中会出现 calculateTotals 被调用两次的情况（显式+监听）。

---

## 13. 三个关键问题的深入澄清（⭐ 新增章节）

### 13.1 问题一：传统表单单次调用 vs MCP 两次调用 — 折扣基数是否系统性偏小？

#### 核心发现："传统表单"其实用的是 Live 组件，DTO.tax 已被刷新

模板 [create.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Resources/views/Default/create.html.twig#L18) 第 18 行：

```twig
<twig:CreateInvoice :form="form" :dto="dto" :isEdit="isEdit" :invoice="invoice|default(null)" />
```

也就是说，**浏览器正常用户走的不是 Create 控制器的 HTTP form 提交分支，而是 Live 组件的 AJAX 提交**。Live 组件每次用户操作都会触发 `PreReRender(priority -10)`，在 [CreateInvoice.calculateTotals](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/InvoiceBundle/Twig/Components/CreateInvoice.php#L117-L136) 中：
1. 建临时 Invoice 并 calculateTotals
2. 把算出的 `total/baseTotal/tax` 写回 DTO（第 133-135 行）

**所以最终提交时，DTO.tax 已经是「上次 PreReRender 算出的正确税额」，不是默认 '0'。**

#### 两条真正的代码路径对比

| 路径 | 场景 | calculateTotals 调用次数 | 进入 prePersist 时 entity.tax | 折扣基数偏差 |
|------|------|------------------------|-------------------------------|-------------|
| **Live 组件提交**（正常用户） | 浏览器 JS 正常，走 AJAX | 多次 PreReRender + prePersist 1 次 | 上次 PreReRender 算出的税额 ≈ 新税 | **几乎无偏差** |
| **Create 控制器 HTTP 提交**（JS 禁用 fallback） | JS 被禁用或 Live 组件失效 | 仅 prePersist 1 次 | DTO 默认值 '0' | **有偏差（tax 部分缺失）** |
| **MCP API 创建** | 系统 API 调用 | 显式 1 次 + prePersist 1 次 = 2 次 | 第 1 次：0 → 第 2 次：第 1 次算出的税 | 第 1 次有偏差，**第 2 次自收敛** |

#### MCP "自收敛"的完整时序

```
显式调用 calculateTotals（L154）
  ├─ 循环算税 → 局部 tax=5000
  ├─ setBaseTotal(25000)
  ├─ 算折扣: base=25000 + entity.tax(0!) = 25000  ← ⚠️ 有偏差
  ├─ setTotal(26250)
  └─ setTax(5000)   ← 写回正确税额（BigDecimal，尚未取整）

persist → prePersist 触发 InvoiceSaveListener
  └─ 第二次 calculateTotals
      ├─ 循环算税 → 局部 tax=5000（与上次相同）
      ├─ setBaseTotal(25000)
      ├─ 算折扣: base=25000 + entity.tax(5000!) = 30000  ← ✅ 已修正
      ├─ setTotal(30000 - 4500 = 25500)
      └─ setTax(5000)
```

**结论**：MCP 两次调用确实会让折扣基数"自己收敛"（从 25000 → 30000）。但 Live 组件正常路径因为 PreReRender 先刷过 DTO.tax，prePersist 时已经是正确值，不需要"收敛"。**只有 JS 禁用的 Create 控制器 fallback 路径才会出现「单次调用 + DTO.tax='0'」导致折扣基数系统性偏小。** 在实际生产中这个路径几乎不被使用。

---

### 13.2 问题二：两次调用之间 entity.tax 是 BigDecimal 还是已取整整数？取整时点在哪？

#### Doctrine flush 精确时序

Doctrine ORM 在 `flush()` 时的执行顺序是：

```
flush() 被调用
  │
  ▼
1. 遍历所有 NEW/MANAGED 实体
  │
  ▼
2. 触发生命周期事件（prePersist / preUpdate）
  │   └─ InvoiceSaveListener 调用 calculateTotals()
  │       └─ setTax($tax)  ← 写的是内存 BigDecimal 对象，可能带小数
  │
  ▼
3. 计算 changeset（对比实体原始值和当前值）
  │   └─ 对每个字段调用 Type::convertToDatabaseValue()
  │       └─ BigIntegerType: $value->toScale(0, HalfEven)->toInt()
  │           ← 【★ 取整在这里才发生！】
  │
  ▼
4. 生成 INSERT / UPDATE SQL（使用取整后的 int 值）
  │
  ▼
5. 执行 SQL
  │
  ▼
6. 触发 postPersist / postUpdate 事件
```

**关键结论**：
1. **在 calculateTotals 执行过程中，entity.tax 始终是内存中的 BigNumber/BigDecimal 对象**，可能保留 2 位小数（Inclusive 税场景）
2. **取整发生在所有生命周期事件之后、SQL 生成之前**
3. MCP 路径中两次 calculateTotals 调用之间**没有 flush**，所以中间夹着的 entity.tax 是**内存 BigDecimal，尚未取整**

#### 完整 MCP 时序+取整演示

场景：price=15000, qty=2, Inclusive 20%, 15% 折扣（MCP 传 discount_value=1500 经过 DiscountTransformer 约定）

```
显式 calculateTotals（L154）
  ├─ subTotal = 25000, tax = 5000, total_before_discount = 30000
  ├─ setBaseTotal(25000)
  ├─ 折扣: base = 25000 + 0(构造函数值) = 25000
  │        discount = 25000 × 15% = 3750
  │        total = 30000 - 3750 = 26250
  ├─ setTotal(26250)        ← 内存 BigNumber，整数
  └─ setTax(5000)           ← 内存 BigNumber，整数（本次为整数，可能带小数）
        │
        ▼ 无 flush，entity.tax 仍是内存 BigDecimal 5000

invoiceManager->create() -> persist()
  │
  ▼
prePersist -> InvoiceSaveListener -> 第二次 calculateTotals
  ├─ subTotal = 25000, tax = 5000, total_before_discount = 30000
  ├─ setBaseTotal(25000)
  ├─ 折扣: base = 25000 + 5000(上次写入的值!) = 30000  ← 已自收敛
  │        discount = 30000 × 15% = 4500
  │        total = 30000 - 4500 = 25500
  ├─ setTotal(25500)
  └─ setTax(5000)           ← 仍是内存 BigDecimal

flush() 发生在 prePersist 之后
  │
  ▼
UnitOfWork computeChangeSet
  ├─ total: convertToDatabaseValue(25500) → toScale(0) → (int)25500
  ├─ baseTotal: convertToDatabaseValue(25000) → (int)25000
  └─ tax: convertToDatabaseValue(5000) → (int)5000
        │
        ▼
INSERT SQL（三个整数）

最终落库: total=25500, baseTotal=25000, tax=5000 ✅
```

**MCP 最终结果正确**——第二次 calculateTotals 已自收敛，取整只是最后一步把内存 BigNumber 转为 int，不改变数值（如果是整数的话）。

> **有小数场景**：如果 Inclusive 税算出的 tax 是 29.13（保留 2 位小数），那么：
> - calculateTotals 内部 setTax(29.13) — 内存 BigDecimal
> - flush 时 convertToDatabaseValue(29.13) → 29（HalfEven 取整）
> - 落库值是 29，与内存值可能差 ±0.5 美分以内

---

### 13.3 问题三：`percentage > 100 就除以 100` 分支的真实语义

#### 文档此前解读不准确，真实链路是「表单整数化约定」

此前文档将此标为"千分位约定"，这个说法不准确。完整调用链路如下：

##### 链路 A：表单（浏览器用户）路径

**关键文件**: [DiscountTransformer](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/CoreBundle/Form/Transformer/DiscountTransformer.php#L27-L60)

```
前端用户输入: "15" （表示 15%）
    │
    ▼
DiscountTransformer.reverseTransform()   （表单提交时，L52-L59）
    BigNumber::of('15')->multipliedBy(100) = 1500
    │
    ▼
Discount.setValue(1500)                   （L100-L115 of Discount.php）
    case TYPE_PERCENTAGE:
        setValuePercentage(1500.0)         ← Discount.valuePercentage = 1500.0
        setValueMoney(0)
    │
    ▼
Calculator.calculateDiscount() 调用 getValue()   （L90 of Discount.php）
    match TYPE_PERCENTAGE => getValuePercentage() = 1500.0
    │
    ▼
Calculator.calculatePercentage(amount, 1500.0)    （L49-L56 of Calculator.php）
    if (1500 > 100) { 1500 /= 100; }  ← percentage = 15.0  ✅ 还原为 15%
    return amount × (15.0 / 100) = amount × 0.15
```

##### 链路 B：API/程序内部直接调用

**关键文件**: [LineItemBuilder.buildDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/McpBundle/Mcp/Tool/LineItemBuilder.php#L82-L86)、[CalculatorTest.testCalculateDiscount](file:///d:/fz/0601-1/solo-dogfeeding/code/48-SolidInvoice/src/MoneyBundle/Tests/CalculatorTest.php#L29-L40)

```php
// MCP API 路径: 直接 setValuePercentage((float)$value)
// 测试: discount_value = 10，表示 10%
$discount->setValuePercentage((float)10);  // = 10.0

// CalculatorTest.testCalculateDiscount L35:
$discount->setValue(10);  // 直接传 10
```

这种情况下 `getValuePercentage() = 10.0`，`calculatePercentage(amount, 10.0)` 中 10 ≤ 100，直接用 `10 / 100 = 0.10`。

#### 正确解读

`percentage > 100 时除以 100` **不是容错，也不是千分位约定，而是「表单层把百分比乘以 100 存成整数」的桥接逻辑**：

| 约定类型 | 说明 | 是否准确 |
|---------|------|---------|
| ❌ 千分位约定 | 千分位通常是 ×1000（存 15000 表示 15%），与代码 ÷100 不匹配 | 不准确 |
| ❌ 容错逻辑 | 容错是"用户输错了，系统猜一个合理值"，但这是链路 A/B 两条路径的有意设计 | 不准确 |
| ✅ 表单整数化约定 | 表单层把用户输入的百分比 ×100 存整数避免浮点精度问题；Calculator 用时 ÷100 还原。链路 B 直接传小数/整数 ≤100，不需要还原。 | **准确** |

> 为什么要这么做？因为用户输入 15.3 这样的百分比时，用 float 存储可能有 IEEE 754 精度损失。表单层把它乘以 100 变成 1530（整数）存储，用时再还原，是一种精度保护手段。

#### 两条链路传入的 percentage 值范围对比

| 入口 | 用户/调用方传入 | 中间处理 | 传入 calculatePercentage 的值 | >100 分支是否触发 |
|------|---------------|---------|------------------------------|------------------|
| 表单（链路 A） | "15"（用户输入） | ×100 → 1500 | 1500.0 | ✅ 是，÷100 → 15.0 |
| MCP API（链路 B） | discount_value=10（调用方传 10 表示 10%） | 直接 setValuePercentage(10.0) | 10.0 | ❌ 否 |
| 程序直接 setValue(15)（链路 B） | 15（开发者传 15 表示 15%） | setValuePercentage(15.0) | 15.0 | ❌ 否 |
| DummyData/测试 | 1500（测试模拟表单链路） | setValue(1500) | 1500.0 | ✅ 是，÷100 → 15.0 |

所以这是**两条合法链路**，不是"容错"——链路 A 触发 ÷100 分支，链路 B 不触发，两者都是正常预期行为。
