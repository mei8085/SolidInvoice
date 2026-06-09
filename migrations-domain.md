# SolidInvoice 领域实体演进史：迁移脚本 × 实体定义 × 运行时映射

本文档从代码实现角度梳理 SolidInvoice 项目中「领域实体定义」「数据库迁移历史」「运行时 ORM 映射」三者的关系，解释它们是如何逐层叠加、共同构成数据访问层的。

---

## 一、三层架构总览

```
┌───────────────────────────────────────────────────────────┐
│                    运行时映射层 (Runtime)                   │
│  Doctrine ORM → PHP Attributes + Traits + Filters         │
│  负责：对象-关系映射、查询过滤、类型转换、多租户隔离          │
├───────────────────────────────────────────────────────────┤
│                   实体定义层 (Code)                        │
│  Entity Classes + Traits + Embeddables + Enums            │
│  负责：领域模型表达、业务行为、API 序列化、验证规则          │
├───────────────────────────────────────────────────────────┤
│                   迁移历史层 (Schema)                      │
│  Doctrine Migrations → 版本化的 Schema 变更脚本            │
│  负责：数据库结构演进、数据迁移、版本追踪                    │
└───────────────────────────────────────────────────────────┘
```

三者关系：
- **迁移脚本**定义了数据库表结构的「历史演进轨迹」
- **实体定义**是「当前状态」的领域模型表达
- **运行时映射**是将实体定义转换为实际数据库操作的「执行引擎」

---

## 二、迁移历史：从 2.0 到 3.0 的版本演进

### 2.1 迁移命名规范

迁移文件位于 `migrations/` 目录，命名规则：

| 时期 | 命名格式 | 示例 |
|------|---------|------|
| 2.3 及以前 | `Version{major}{minor}{patch}.php` | `Version20300.php` (2.3.0) |
| 2.4 起 | `Version{major}{minor}{patch}_{part}.php` | `Version20400_1.php` (2.4.0 第 1 部分) |

**关键设计决策**：从 2.4 版本开始，大版本迁移拆分为多个小文件，降低单次迁移的风险。

### 2.2 主要版本里程碑

#### 🔹 Version20000 — 初始基准版本
[Version20000.php](migrations/Version20000.php)

创建项目的初始数据库 Schema，包含以下核心表：
- `clients` / `contacts` / `addresses` / `contact_details` / `client_credit` — 客户管理
- `invoices` / `invoice_lines` / `recurring_invoices` — 发票管理
- `quotes` / `quote_lines` — 报价管理
- `payments` / `payment_methods` / `security_token` — 支付管理
- `tax_rates` — 税率管理
- `users` / `api_tokens` / `api_token_history` — 用户与认证
- `app_config` — 系统配置
- `version` — 版本追踪
- `ext_translations` / `ext_log_entries` — Gedmo 扩展表

**技术特征**：
- 主键使用自增 `integer` ID
- 金额使用 `amount + currency` 双列模式（MoneyPHP 风格）
- 使用 `deleted` 字段实现软删除
- 用户表继承自 FOSUserBundle 结构

#### 🔹 Version20100 — 清理与重构
[Version20100.php](migrations/Version20100.php)

主要变更：
- **删除软删除字段**：移除所有表的 `deleted` 列（改用 Archivable 模式）
- **用户表简化**：移除 `username_canonical`、`email_canonical`、`salt` 列（脱离 FOSUserBundle）
- **RecurringInvoice 增强**：从简单的「附加到发票」变为独立实体，拥有自己的 status/terms/notes/total 等字段
- **新增 `recurringinvoice_contact` 关联表**
- **发票行支持循环发票**：`invoice_lines` 增加 `recurringInvoice_id` 列
- **移除 `invoices.is_recurring` 标志**

#### 🔹 Version20200 — 多租户架构引入
[Version20200.php](migrations/Version20200.php)

**这是一次重大架构变更**，引入多租户（Company）隔离：

1. 新建 `companies` 表和 `user_company` 关联表 — **`companies` 表主键直接使用 ULID**（Symfony `UlidType`）
2. 新增 `user_invitations` 表 — 主键同样直接使用 ULID
3. 为**所有业务表**增加 `company_id` 列（**ULID 类型**，因为引用 `companies` 表主键）
4. 业务表主键从单 `id`（integer 自增）改为复合主键 `(id, company_id)`，`id` 仍为 integer
5. **删除所有引用单 `id` 列的外键约束**（因为主键变成复合的了，单例外键不再匹配）
6. 调整唯一约束以包含 `company_id`（如 `clients.name` → `(name, company_id)`）
7. `payment.details` 列类型从 `object` 改为 `json`

**数据迁移策略**：
- `preUp()` 关闭外键检查
- `up()` 修改 Schema 结构
- `postUp()` 创建默认公司，将所有现有数据关联到该公司

> 💡 **关键洞察**：这是一次破坏性变更。迁移后所有查询都必须带上 `company_id` 过滤条件，由运行时的 `CompanyFilter` 自动处理。
>
> **重要时间点**：Version20200 是 ULID 首次出现在数据库中。`companies.id`、`user_invitations.id`、所有表的 `company_id` 都是 ULID 类型，但业务表自身的主键 `id` 仍然是 integer。

#### 🔹 Version20201 — 主键从 Integer 迁移到 ULID（核心转换）
[Version20201.php](migrations/Version20201.php)

**这是 ID 类型转换的核心迁移**，也是整个迁移历史中最复杂的迁移之一。它将所有表的主键从自增整数切换为 ULID（Universally Unique Lexicographically Sortable Identifier）。

**迁移算法采用「双写过渡 + 原子切换」策略**，核心步骤如下：

```
步骤 1: 检测需要迁移的表
   ↓ 遍历所有表，找出既有 company_id 又有 integer 类型 id 的表
   
步骤 2: 添加临时 UUID 列
   ↓ 为主表添加 __uuid__ 列 (UlidType)
   ↓ 为所有引用该表的外键表添加临时列 (如 invoice_id_to_uuid)
   
步骤 3: 生成 ULID 并填充数据
   ↓ 为主表每条记录生成新的 ULID，更新到 __uuid__ 列
   ↓ 按 company_id 分组维护 id → ULID 的映射表
   ↓ 遍历外键表，根据映射表填充临时 ULID 列
   
步骤 4: 删除旧的外键列
   ↓ 删除旧的 integer 外键列和相关索引
   
步骤 5: 重命名临时列
   ↓ 临时外键列重命名为原列名 (如 invoice_id_to_uuid → invoice_id)
   
步骤 6: 切换主键
   ↓ 删除旧的 integer 主键列 id
   ↓ 删除临时的 __uuid__ 列
   ↓ 新增 id 列 (UlidType) 并设为主键
   
步骤 7: 重建约束
   ↓ 重建外键约束、主键约束、索引
```

**特殊处理**：
- `users` 表单独处理（`$linkCompany = false`），因为 user 是跨 company 的
- `user_company` 关联表重建主键顺序为 `[company_id, user_id]`
- 多对多关联表（`invoice_contact`、`quote_contact`、`recurringinvoice_contact`）添加 `company_id` 列（ULID 类型），但外键数据转换有遗留问题（TODO 注释）
- 删除 `ext_log_entries` 表（Gedmo 日志不再使用）
- `invoices` 表新增 `invoice_id` 字符串列，`quotes` 表新增 `quote_id` 字符串列，并用原 id 值填充（作为友好的可读编号）

> 💡 **为什么这么复杂？**
> 因为数据库有外键约束，不能直接改列类型。必须先加新列、迁移数据、删旧列、重命名新列，整个过程要维护数据一致性和引用完整性。
>
> 这也是为什么这个迁移有 500+ 行代码，是整个项目中最复杂的迁移。

#### 🔹 Version20202 — 补充唯一约束
[Version20202.php](migrations/Version20202.php)

补充 `contact_types` 表的 `(name, company_id)` 唯一索引。

#### 🔹 Version20300 — 金额大升级 + 遗留修复 + 结构调整
[Version20300.php](migrations/Version20300.php)

**注意**：这个版本的 ID 已经是 ULID 了。它主要做三件事：

**一、金额类型升级为 BigInteger**：
- 所有 `*_amount` 列类型从 `integer` 改为 `BigIntegerType`
- 使用 `Brick\Math\BigInteger` 处理大整数
- 删除所有 `*_currency` 列（如 `total_currency`、`price_currency` 等）
- 货币信息统一由 Client 实体的 `currency` 字段决定

**二、ULID 遗留问题修复**：
- **关联表外键修复**：显式设置 `invoice_contact`、`quote_contact`、`recurringinvoice_contact` 等关联表的外键列类型为 `UlidType`
- **关联表 company_id 外键**：为这些关联表的 `company_id` 添加指向 `companies` 表的外键约束
- **Ramsey UUID 兜底转换**：遍历所有表，如果发现列类型是 Ramsey 的 `UuidBinaryOrderedTimeType`，则转换为 Symfony `UlidType`
  - 这是**兜底/防御性**代码，标准迁移路径（20000→20200→20201）不会触发
  - Version20201 用的就是 Symfony UlidType，不存在 Ramsey → Symfony 的转换
  - 可能是为了兼容某些特殊升级路径或第三方 bundle

**四、其他结构调整**：
- 新增 `invoice_date` 列（独立的发票日期字段）
- 新增 `recurring_invoice_id` 外键列
- 新增 `recurring_options` 表（循环发票选项独立为实体）
- 用户表移除 `username` 列（邮箱作为唯一标识）
- 新增通知系统相关表
- 发票行增加 `type` 字段（区分 `invoice` 和 `recurring_invoice`）
- `user_company` 主键顺序改为 `[user_id, company_id]`

#### 🔹 Version20305 — 关联表 company_id 清理
[Version20305.php](migrations/Version20305.php)

- 删除 `invoice_contact`、`recurringinvoice_contact`、`quote_contact` 三个多对多关联表的 `company_id` 列
- 这些列在 Version20201 中添加，但实际上是冗余的 —— 关联表的 company 可以通过主表（invoice/quote）间接确定
- 同时将 `invoices.due`、`invoices.invoice_date`、`quotes.due` 的类型改为 `DATETIME_IMMUTABLE`

> 💡 **架构洞察**：这是一个「先加上、后移除」的典型案例。
> Version20201 加上 company_id 是为了 CompanyFilter 能直接过滤关联表；
> 但后来发现 Doctrine 的 SQL 过滤器是作用在实体上的，关联表不是实体，所以不需要。
> 而且多对多关联表的数据完整性由主实体的关联关系保证，冗余的 company_id 反而可能造成不一致。
> 所以在 Version20305 中又移除了这些冗余列。

#### 🔹 Version20306 — 密码重置重构
[Version20306.php](migrations/Version20306.php)

- 新增 `reset_password_request` 表（Symfony 标准重置密码表结构）
- 从 `users` 表移除 `confirmation_token` 和 `password_requested_at`

#### 🔹 Version20400_1 ~ 20400_5 — 2.4 系列增量变更
- `users` 表增加 `verified` 字段
- 其他小型功能增强

#### 🔹 Version30000_1 ~ 30000_8 — 3.0 系列

| 版本 | 内容 |
|------|------|
| [30000_1](migrations/Version30000_1.php) | 新增 `user_settings` 表，支持用户级配置 |
| [30000_3](migrations/Version30000_3.php) | `app_config` 增加 `form_options` 和 `default_value` 列 |
| [30000_5](migrations/Version30000_5.php) | 为所有外键添加 `ON DELETE CASCADE/SET NULL` 约束 |
| [30000_8](migrations/Version30000_8.php) | 重命名 `custom_domain` 设置的 key |

---

## 三、实体定义层：当前领域模型结构

### 3.1 实体映射方式

SolidInvoice 使用 **PHP 8 Attributes** 进行 Doctrine ORM 映射，取代了传统的 YAML/XML 配置。

以 [Invoice](src/InvoiceBundle/Entity/Invoice.php) 为例：

```php
#[ORM\Table(name: Invoice::TABLE_NAME)]
#[ORM\Index(columns: ['quote_id'])]
#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
class Invoice extends BaseInvoice implements Stringable
{
    #[ORM\Column(name: 'id', type: UlidType::NAME)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    private ?Ulid $id = null;
    
    // ...
}
```

### 3.2 代码复用机制

实体层通过**四种机制**实现代码复用：

#### 机制 1：Trait 横向复用
位于 [src/CoreBundle/Traits/Entity/](src/CoreBundle/Traits/Entity/)

| Trait | 字段 | 作用 |
|-------|------|------|
| [Archivable](src/CoreBundle/Traits/Entity/Archivable.php) | `archived` | 软归档（非删除，只是标记归档） |
| [TimeStampable](src/CoreBundle/Traits/Entity/TimeStampable.php) | `created`, `updated` | 自动时间戳（Gedmo Timestampable） |
| [CompanyAware](src/CoreBundle/Traits/Entity/CompanyAware.php) | `company` | 多租户公司关联 |

**典型实体的 Trait 组合**：
```php
class Invoice extends BaseInvoice
{
    use Archivable;
    use InvoiceStatusTrait;
    use TimeStampable;
    // BaseInvoice 内部已 use CompanyAware
}
```

#### 机制 2：MappedSuperclass 纵向继承
[BaseInvoice](src/InvoiceBundle/Entity/BaseInvoice.php) 作为发票类的基类：

```php
#[ORM\MappedSuperclass]
abstract class BaseInvoice
{
    use CompanyAware;
    
    protected BigNumber $total;
    protected BigNumber $baseTotal;
    protected BigNumber $tax;
    protected Discount $discount;
    protected ?string $terms = null;
    protected ?string $notes = null;
}
```

`Invoice` 和 `RecurringInvoice` 都继承自 `BaseInvoice`，共享金额、折扣、条款等字段。

#### 机制 3：Embeddable 值对象
[Discount](src/CoreBundle/Entity/Discount.php) 使用 `#[ORM\Embeddable]` 标记：

```php
#[ORM\Embeddable]
class Discount
{
    #[ORM\Column(name: 'valueMoney_amount', type: BigIntegerType::NAME)]
    private BigNumber $valueMoney;
    
    #[ORM\Column(name: 'value_percentage', type: Types::FLOAT, nullable: true)]
    private ?float $valuePercentage = null;
    
    #[ORM\Column(name: 'type', type: Types::STRING, nullable: true)]
    private ?string $type = self::TYPE_PERCENTAGE;
}
```

在实体中使用：
```php
#[ORM\Embedded(class: Discount::class)]
protected Discount $discount;
```

> 💡 **数据库表现**：Embeddable 的字段会「展开」到所属实体的表中，列名前缀为 embeddable 的字段名（如 `discount_valueMoney_amount`）。这解释了为什么迁移脚本中有 `discount_valueMoney_amount` 这样的列名。

#### 机制 4：Enum 字段类型
状态字段使用 PHP 8.1 枚举 + Doctrine enumType 映射：

```php
#[ORM\Column(name: 'status', type: Types::STRING, length: 25, enumType: InvoiceStatus::class)]
protected ?InvoiceStatus $status = null;
```

### 3.3 核心实体关系图

```
Company (1) ──┐
              │
Client (1) ───┼── (*) Invoice  ── (*) Line
              │         │
              │         ├── (1) Quote
              │         ├── (*) Payment
              │         └── (1) RecurringInvoice
              │
              ├── (*) Quote
              ├── (*) Contact
              └── (*) Address
```

所有业务实体都通过 `CompanyAware` trait 关联到 Company，形成多租户架构。

---

## 四、运行时映射层：Doctrine 的动态能力

### 4.1 自定义类型 (Custom Types)

#### BigIntegerType
[BigIntegerType](src/CoreBundle/Doctrine/Type/BigIntegerType.php)

- **数据库层**：使用 `BIGINT` SQL 类型
- **PHP 层**：转换为 `Brick\Math\BigInteger` 对象
- **作用**：处理大额金额，避免浮点数精度问题

配置在 [config/packages/doctrine.php](config/packages/doctrine.php)：
```php
$dbalConfig->type(BigIntegerType::NAME)->class(BigIntegerType::class);
```

### 4.2 全局过滤器 (SQL Filters)

两个全局过滤器在运行时自动修改 SQL 查询：

#### CompanyFilter — 多租户隔离
[CompanyFilter](src/CoreBundle/Doctrine/Filter/CompanyFilter.php)

```php
if ($targetEntity->hasAssociation('company') && $this->hasParameter('companyId')) {
    return sprintf('%s.company_id = %s', $targetTableAlias, $this->getParameter('companyId'));
}
```

- **作用**：自动为所有 `CompanyAware` 实体的查询添加 `company_id = ?` 条件
- **特殊处理**：`User` 实体通过 `user_company` 关联表过滤
- **SQLite 适配**：ULID 二进制比较需要用 `HEX()` 函数转换

#### ArchivableFilter — 归档过滤
[ArchivableFilter](src/CoreBundle/Doctrine/Filter/ArchivableFilter.php)

```php
if (in_array(Archivable::class, $targetEntity->reflClass->getTraitNames(), true)) {
    return sprintf('(%s.archived IS NULL OR %s.archived = false)', ...);
}
```

- **作用**：默认不返回已归档的记录
- **检测方式**：通过反射检查实体类是否使用了 `Archivable` trait
- **可绕过**：DataGrid 中有专门的 `disableForGrid()` 方法临时关闭过滤

### 4.3 生命周期回调

实体通过 `#[ORM\HasLifecycleCallbacks]` 和 `#[ORM\PrePersist]` 等注解定义生命周期钩子：

```php
#[ORM\PrePersist]
public function updateLines(): void
{
    foreach ($this->lines as $line) {
        $line->setInvoice($this);
    }
}
```

### 4.4 自动映射配置

在 [config/packages/doctrine.php](config/packages/doctrine.php) 中：

```php
$entityManagerConfig->autoMapping(true);
```

Doctrine 自动扫描 `src/` 目录下带有 `#[ORM\Entity]` 注解的类，无需手动注册。

---

## 五、三者叠加：从迁移到运行时的完整链路

### 5.1 以 Invoice 实体为例的纵向对比

| 层面 | Invoice 实体的关键特征 | 对应代码 |
|------|----------------------|----------|
| **迁移 (Version20000)** | integer 主键、deleted 软删除、amount+currency 双列 | `invoices` 表定义 |
| **迁移 (Version20100)** | 移除 deleted、移除 is_recurring | 删除列操作 |
| **迁移 (Version20200)** | 增加 company_id、复合主键 | `addCompanyToTable()` |
| **迁移 (Version20201)** | 主键和外键从 integer 转 ULID（核心转换） | `migrate()` 七步算法 |
| **迁移 (Version20300)** | BigInteger 金额、移除 currency、修复关联表外键遗留 | 金额升级 + 遗留修复 |
| **实体定义** | PHP 属性 + ORM Attributes + Traits + Embeddable | [Invoice.php](src/InvoiceBundle/Entity/Invoice.php) |
| **运行时映射** | CompanyFilter 自动加 company_id 条件、ArchivableFilter 过滤归档 | [CompanyFilter.php](src/CoreBundle/Doctrine/Filter/CompanyFilter.php) |

### 5.2 为什么「对不上号」？

用户感觉到「对不上号」的根本原因：

1. **迁移是增量历史，实体是当前快照**
   - 迁移脚本记录了每一步变更（从简单到复杂）
   - 实体类只反映最新状态，看不出历史演变

2. **Traits 打散了字段定义**
   - `archived` 字段在 Archivable trait 中
   - `created/updated` 在 TimeStampable trait 中
   - `company` 在 CompanyAware trait 中
   - `total/baseTotal/tax` 在 BaseInvoice 基类中
   - 直接看实体类，会发现「少了很多字段」

3. **Embeddable 的隐式展开**
   - 实体中只有 `$discount` 一个属性
   - 数据库中展开为 `discount_type`、`discount_valueMoney_amount`、`discount_value_percentage` 多列
   - 迁移脚本中看到的是展开后的列名

4. **运行时过滤器的「隐形」条件**
   - `company_id` 和 `archived` 的过滤不在实体中显式体现
   - 但所有查询都会自动带上这些条件
   - 理解这一点对调试「为什么查不到数据」至关重要

5. **ID 类型和生成策略的变化**
   - 迁移 20000：自增整数 ID
   - 迁移 20200：复合主键 (id integer + company_id ULID)
   - 迁移 20201：ULID 单主键（核心转换，外键同步转换）
   - 迁移 20300：修复关联表外键遗留（兜底）
   - 当前实体：ULID + UlidGenerator 自定义生成器

### 5.3 关键架构决策的迁移轨迹

#### 决策 1：从软删除到归档模式
```
Version20000: 所有表有 deleted 列 (datetime)
        ↓
Version20100: 删除所有 deleted 列
        ↓
引入 Archivable trait + archived (boolean, nullable)
        ↓
ArchivableFilter 默认过滤已归档记录
```

**原因**：从「可恢复删除」转变为「归档/活跃」二元状态，更符合业务语义。

#### 决策 2：从单租户到多租户
```
Version20000-20100: 单租户，无 company 概念
        ↓
Version20200: 新增 companies 表，所有表加 company_id
        ↓
CompanyAware trait 统一封装关联
        ↓
CompanyFilter 运行时自动过滤
```

**原因**：支持 SaaS 模式，数据按公司隔离。

#### 决策 3：金额类型从 MoneyPHP 到 BigInteger
```
Version20000-20200: total_amount (int) + total_currency (string) 双列
        ↓
Version20300: 删除 *_currency 列，amount 升级为 BigInteger
        ↓
货币从 Client 实体获取，金额统一为最小单位整数
```

**原因**：
- 货币由客户决定，同一张发票的所有金额必然同币种
- 使用 BigInteger 避免浮点数精度问题
- 减少冗余列

#### 决策 4：主键从 Integer 到 ULID
```
Version20000: 自增 integer 单主键
        ↓
Version20200: 复合主键 (id integer + company_id ULID)
        ↓
Version20201: 业务表主键转为 ULID (Symfony UlidType) + 外键自动转换
        ↓  关联表外键有遗留
Version20300: 修复关联表外键遗留 + Ramsey UUID 兜底转换
        ↓
Version20305: 关联表删除冗余 company_id
```

**关键节点说明**：

| 版本 | 变更内容 | 类型 |
|------|---------|------|
| 20200 | `companies.id` 首次用 ULID；所有表的 `company_id` 是 ULID | 部分 ULID 化 |
| 20201 | 业务表主键和大部分外键从 integer → ULID（核心转换） | 基本完成 ULID 化 |
| 20300 | 修复关联表外键遗留；Ramsey UUID → Symfony ULID 兜底 | 完全 ULID 化 |
| 20305 | 清理关联表冗余 company_id | 优化清理 |

**原因**：
- ULID 可在客户端生成，无需数据库 round-trip
- 按时间有序，索引性能好
- 分布式系统友好
- 与多租户架构配合，避免跨租户 ID 碰撞

---

## 六、ID &amp; 外键迁移链路深度解析

本章以「主键和外键从 integer 到 ULID 的迁移」为深度案例，拆解三个版本的职责边界、外键演化过程，以及与实体定义、运行时过滤的叠加关系。

### 6.1 三个时间点的三层状态对比

以发票（Invoice）相关的表为例，对比三个关键时间点的状态：

| 维度 | Version20000 (2.0) | Version20201 (2.2.1) | Version20300 (2.3) |
|------|-------------------|---------------------|-------------------|
| **invoices.id** | integer 自增，主键 | ULID (Symfony)，主键 | ULID (Symfony)，主键 |
| **invoices.company_id** | 不存在 | ULID 类型，从主键之一改为单列索引 | ULID 类型，普通列（有索引） |
| **invoice_lines.invoice_id** | integer，外键约束 | ULID，已转换（自动发现） | ULID，约束已重建 |
| **invoice_contact.invoice_id** | integer，外键约束 | 遗留（自动发现失效） | ULID（手动修复） |
| **invoice_contact.company_id** | 不存在 | ULID 类型，新增 | ULID 类型，有外键约束（后被删除） |
| **外键约束状态** | 完整 | 大部分已重建，关联表缺失 | 全部修复完成 |
| **实体 ID 定义** | — (早期可能是 XML/Annotation) | 逐步迁移中 | UlidType + UlidGenerator |
| **CompanyFilter** | 不存在 | 已启用，过滤主实体 | 已启用，过滤主实体 |

### 6.2 当前实体的 ID 定义模式

当前所有实体的 ID 都遵循统一的四注解模式，以 Invoice.php 为例：

```php
#[ORM\Column(name: 'id', type: UlidType::NAME)]
#[ORM\Id]
#[ORM\GeneratedValue(strategy: 'CUSTOM')]
#[ORM\CustomIdGenerator(class: UlidGenerator::class)]
private ?Ulid $id = null;
```

**四层含义**：
1. `#[ORM\Column(type: UlidType::NAME)]` — 数据库列类型为 ULID（二进制 16 字节）
2. `#[ORM\Id]` — 标记为主键
3. `#[ORM\GeneratedValue(strategy: 'CUSTOM')]` — 使用自定义生成策略
4. `#[ORM\CustomIdGenerator(class: UlidGenerator::class)]` — 使用 Symfony 的 ULID 生成器

**关键特性**：
- **PHP 端生成**：ID 在 PHP 代码中生成（`new Ulid()`），不需要数据库自增
- **二进制存储**：数据库中是 16 字节二进制，比字符串形式节省空间
- **时间有序**：ULID 按时间排序，索引性能优于随机 UUID
- **与迁移的对应**：Version20201 完成了从数据库自增 integer 到 PHP 端生成 ULID 的转变

### 6.3 外键的定义与隐式转换

**实体中外键是「隐身」的**——你在实体代码中看到的是对象属性，而不是数据库列：

```php
// 实体中定义的是对象引用
#[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
#[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
protected ?Invoice $invoice = null;
```

Doctrine ORM 在运行时会自动处理：
1. **隐式列名**：根据属性名推断数据库列名（`$invoice` → `invoice_id`）
2. **类型自动对齐**：外键列类型自动与目标实体的主键类型对齐
3. **参数自动转换**：查询时绑定的参数会通过 `UlidType` 自动转换为二进制格式

**与迁移的对应**：
- Version20200 之前：外键列是 integer 类型
- Version20201：能自动发现的外键随主键一起转换为 ULID
- Version20300：手动修复遗漏的关联表外键

### 6.4 多对多关联表的演化时间线

关联表（`invoice_contact`、`quote_contact`、`recurringinvoice_contact`）是最特殊的存在，演化最曲折：

```
Version20000: 关联表存在，复合主键 (invoice_id, contact_id)
                外键类型：integer，有外键约束
                       ↓
Version20200: 删除外键约束（因为主键变复合了）
                外键列还是 integer，但没了约束
                       ↓
Version20201: 手动加 company_id 列 (ULID 类型)
                外键列：因自动发现机制失效，未正确转换
                遗留问题
                       ↓
Version20300: 手动设置外键列类型为 UlidType
                添加 company_id 的外键约束
                修复遗留问题
                       ↓
Version20305: 删除 company_id 列（发现是冗余的）
                回归纯粹的关联表
```

**为什么关联表这么特殊？**

1. **没有 id 列**：关联表是复合主键，不会被 Version20201 的 migrate 循环命中
2. **外键约束被删了**：Version20200 删了外键约束，导致「自动发现外键」机制失效
3. **company_id 先加后删**：从以为需要直接过滤，到发现通过主表 join 自然隔离

**Doctrine 视角下的关联表**：

关联表不是实体，由 Doctrine 自动管理。当你定义 `ManyToMany` 关联时：
- 自动创建 join table
- 自动管理表中的数据
- 通过主实体的查询自动 join 和过滤

这就是为什么 `company_id` 是冗余的——CompanyFilter 作用在主实体上，join 出来的关联数据自然就是隔离的。

### 6.5 CompanyFilter 与 ULID 的叠加

**CompanyFilter 对 ID 类型基本透明**。它的工作原理很简单：

```php
// 伪代码逻辑
public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias)
{
    // 检查实体是否有 company 字段（通过 CompanyAware trait）
    if (! $this->hasCompanyField($targetEntity)) {
        return '';
    }
    
    // 追加 WHERE 条件
    return $targetTableAlias . '.company_id = ' . $this->getParameter('company_id');
}
```

**类型转换的处理**：
- `company_id` 参数的绑定由 Doctrine DBAL 处理
- 因为列类型是 `UlidType`，绑定时会自动从 Ulid 对象转换为二进制
- **对业务完全透明**，开发者不需要关心 ID 是 integer 还是 ULID

**SQLite 特殊适配**：

SQLite 对二进制的处理比较特殊，在 CompanyFilter.php 中有针对 SQLite 的特殊逻辑，使用 `HEX()` 函数确保 ULID 比较的正确性。

### 6.6 从 ID 视角解释「对不上号」

初次接触这个项目时，你可能会有以下疑惑，从 ID 演化视角可以解释清楚：

**疑惑 1：为什么迁移里有 integer 主键，但实体里是 ULID？**
- 迁移记录了历史：最早确实是 integer
- 实体只反映当前状态：现在已经是 ULID 了
- Version20201 是转换的分水岭

**疑惑 2：为什么有的外键在迁移里是手动转换的，有的是自动的？**
- 有外键约束的：Version20201 的自动发现机制能找到，随主键一起转
- 没有外键约束的（关联表）：自动发现失效，Version20300 手动修
- 根本原因：Version20200 的复合主键改造删除了一批外键约束

**疑惑 3：为什么关联表有 company_id 又删掉了？**
- 这是认知迭代的过程
- 先加：直觉认为关联表也需要 company_id 来过滤
- 后删：深入理解后发现 SQL 过滤器只作用在实体上，关联表通过 join 自然隔离

**疑惑 4：Version20300 为什么还有 Ramsey UUID 转换？20201 不是转了吗？**
- Version20201 转的是主路径上的表，用的就是 Symfony UlidType
- Version20300 的 Ramsey → Symfony 转换是**兜底/防御性**代码
- 可能是为了兼容某些特殊升级路径或第三方 bundle
- 在标准迁移路径（20000→20200→20201→20300）上，这段代码不会执行

### 6.7 外键迁移的自动发现算法

Version20201 中最巧妙的设计之一是「自动发现外键」。它不需要你手动枚举哪些表引用了当前表，而是直接查询数据库元数据：

```
算法步骤：
1. 遍历数据库中所有表
2. 对每个表，列出它的所有外键约束
3. 如果外键指向当前迁移的表
4. 记录：表名、列名、是否可空、约束名
5. 迁移时，这些外键列会随主键一起转换
```

**优点**：
- 不需要人工维护外键列表
- 不会遗漏（只要有数据库约束就会被发现）
- 新增外键也会自动被处理

**局限**：
- 依赖数据库外键约束，约束被删了就发现不了
- 这就是关联表外键被遗漏的根本原因

---

## 七、开发时的心智模型

### 7.1 修改实体时的检查清单

当你修改实体字段时，需要考虑三层是否同步：

1. **实体定义层**：
   - [ ] 添加/修改 Entity 类的属性和 ORM Attribute
   - [ ] 如果是通用字段，考虑是否抽到 Trait 中
   - [ ] 如果是值对象，考虑是否用 Embeddable

2. **迁移脚本层**：
   - [ ] 生成新的迁移版本（`bin/console doctrine:migrations:diff`）
   - [ ] 检查迁移是否需要数据迁移（`postUp`/`preUp`）
   - [ ] 考虑 down() 方法的可逆性

3. **运行时映射层**：
   - [ ] 是否需要新的自定义 Type？
   - [ ] 是否需要调整 Filter？
   - [ ] 是否需要 LifecycleCallback？

### 7.2 常见疑惑解答

**Q: 为什么实体里看不到 `company_id` 字段？**
A: 因为它封装在 `CompanyAware` trait 中，属性名是 `$company`（对象），不是 `$company_id`（整数）。Doctrine 会自动管理外键列。

**Q: 为什么迁移里有 `discount_valueMoney_amount` 这样的长列名？**
A: 因为 `Discount` 是 `#[ORM\Embeddable]`，它的字段会展开到主表中，列名规则是 `{embeddable字段名}_{embeddable内部字段名}`。

**Q: 迁移里的 `deleted` 列为什么实体里没有？**
A: 因为在 Version20100 中已经删除了这些列，改用 `archived` 字段（Archivable trait）。迁移是历史记录，实体是当前状态。

**Q: 为什么查询时自动带上了 `company_id` 条件？**
A: 因为 `CompanyFilter` 是全局启用的 SQL Filter，会自动为所有 CompanyAware 实体的查询添加该条件。

**Q: 主键 ID 从 integer 转 ULID 是在哪一个迁移完成的？**
A: 核心转换在 [Version20201.php](migrations/Version20201.php) 完成，这是整个迁移历史中最复杂的迁移，用「双写过渡 + 原子切换」策略处理了所有表的主键和外键转换。Version20300 主要是修复关联表外键遗留问题，以及 Ramsey UUID → Symfony ULID 的兜底转换（标准迁移路径不触发）。

**Q: 外键列（如 `invoice_id`）在实体里为什么找不到？**
A: 因为在实体中，外键是以对象关联的形式存在的（如 `$invoice` 属性，类型是 `Invoice`）。Doctrine 会自动把对象关联映射为数据库中的 `invoice_id` 外键列，列名和类型都由 Doctrine 自动管理。

**Q: 为什么多对多关联表（如 `invoice_contact`）里曾经有 `company_id`，现在又没有了？**
A: 这是一个「认知迭代」的过程。Version20201 时以为关联表也需要 company_id 来过滤，但后来发现 SQL 过滤器只作用于实体，关联查询通过主实体自动 join 就保证了 company 隔离，冗余的 company_id 反而有数据不一致风险，所以在 Version20305 中删除了。

**Q: ULID 类型的 ID 是在数据库里生成的还是代码里生成的？**
A: 是在 PHP 代码里生成的。使用 `#[ORM\CustomIdGenerator(class: UlidGenerator::class)]`，在 `persist` 时就已经生成了 ID，不需要等 `flush` 后才能拿到。这是和自增 integer ID 最大的不同。

---

## 八、关键文件速查表

### 8.1 实体与 Trait

| 文件 | 作用 |
|------|------|
| [Invoice.php](src/InvoiceBundle/Entity/Invoice.php) | 发票实体（ID 定义、ManyToMany 关联参考） |
| [Line.php](src/InvoiceBundle/Entity/Line.php) | 发票行实体（ManyToOne 外键参考） |
| [BaseInvoice.php](src/InvoiceBundle/Entity/BaseInvoice.php) | 发票基类（MappedSuperclass） |
| [CompanyAware.php](src/CoreBundle/Traits/Entity/CompanyAware.php) | 多租户 Trait |
| [Archivable.php](src/CoreBundle/Traits/Entity/Archivable.php) | 归档 Trait |
| [TimeStampable.php](src/CoreBundle/Traits/Entity/TimeStampable.php) | 时间戳 Trait |
| [Discount.php](src/CoreBundle/Entity/Discount.php) | 折扣值对象（Embeddable） |

### 8.2 运行时映射

| 文件 | 作用 |
|------|------|
| [doctrine.php](config/packages/doctrine.php) | Doctrine 配置（自定义类型、过滤器） |
| [BigIntegerType.php](src/CoreBundle/Doctrine/Type/BigIntegerType.php) | 大整数自定义类型 |
| [CompanyFilter.php](src/CoreBundle/Doctrine/Filter/CompanyFilter.php) | 多租户 SQL 过滤器 |
| [ArchivableFilter.php](src/CoreBundle/Doctrine/Filter/ArchivableFilter.php) | 归档 SQL 过滤器 |

### 8.3 关键迁移

| 文件 | 核心作用 |
|------|---------|
| [Version20000.php](migrations/Version20000.php) | 初始 Schema，integer 自增主键 |
| [Version20200.php](migrations/Version20200.php) | 多租户架构，添加 company_id，复合主键 |
| **[Version20201.php](migrations/Version20201.php)** | **ID 从 integer 转 ULID 的核心迁移（最复杂）** |
| [Version20300.php](migrations/Version20300.php) | **金额转 BigInteger（核心）** + 修复关联表外键遗留 + Ramsey UUID → Symfony ULID 兜底转换 |
| [Version20305.php](migrations/Version20305.php) | 删除关联表冗余 company_id |
| [Version20306.php](migrations/Version20306.php) | 密码重置表重构 |
| [doctrine_migrations.php](config/packages/doctrine_migrations.php) | 迁移配置 |
