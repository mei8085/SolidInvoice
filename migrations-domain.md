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

**二、关联表遗留问题修复**：
- 显式设置 `invoice_contact`、`quote_contact`、`recurringinvoice_contact` 等关联表的外键列类型为 `UlidType`
- 为这些关联表的 `company_id` 添加指向 `companies` 表的外键约束
- 修复原因：Version20201 的「自动发现外键」机制因 Version20200 删除了外键约束而失效

**三、Ramsey UUID → Symfony ULID 兜底转换**：
- 遍历所有表，检查列类型，如果发现是 Ramsey 的 `UuidBinaryOrderedTimeType` 则转换为 Symfony `UlidType`
- **注意：这是兜底/防御性代码**，标准迁移路径（20000→20200→20201）不会触发
- Version20201 直接用的就是 Symfony UlidType，不存在 Ramsey → Symfony 的主路径转换
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
| **迁移 (Version20300)** | ULID 主键、BigInteger 金额、移除 currency、增加 invoice_date | 类型转换 + 列增删 |
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
   - 迁移 20200：复合主键 (id, company_id)
   - 迁移 20300：ULID 单主键
   - 当前实体：ULID + 自定义生成器

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

#### 决策 4：主键从 Integer 到 ULID（测试）
```
Version20000-20200: 自增 integer 主键 (+ company_id 复合)
        ↓
Version20300: ULID 单主键 (二进制有序 UUID)
```

**原因**：
- ULID 可在客户端生成，无需数据库 round-trip
- 按时间有序，索引性能好
- 分布式系统友好

---

## 六、关联表外键迁移与 company_id 清理深度解析

本章以多对多关联表（`invoice_contact`、`quote_contact`、`recurringinvoice_contact`）为深度案例，逐行对照代码拆解三个版本的迁移逻辑、数据依赖和风险点。

### 6.1 Version20201：外键回填的依赖链与失效原因

Version20201 对关联表的处理分为**结构变更**和**数据回填**两部分，其中数据回填因依赖链条断裂而失败。

**结构变更（第66-88行）**：

```php
// 先删除旧主键
$this->schema->getTable('invoice_contact')->dropPrimaryKey();

// 添加 company_id 列（ULID 类型，允许 NULL）
$invoiceContact->addColumn('company_id', UlidType::NAME, ['notnull' => false]);

// 添加索引
$invoiceContact->addIndex(['invoice_id', 'company_id']);

// 重新设置主键（还是原来的复合主键）
$invoiceContact->setPrimaryKey(['invoice_id', 'contact_id']);
```

**关键问题**：`company_id` 列被添加了，但**没有填充数据**，全部是 NULL。这为后续外键回填失败埋下了伏笔。

**外键自动发现机制（第248-286行）**：

`getTableForeignKeys()` 通过查询数据库元数据来发现外键：

```php
foreach ($schemaManager->listTables() as $table) {
    $foreignKeys = $schemaManager->listTableForeignKeys($table->getName());
    foreach ($foreignKeys as $foreignKey) {
        if ($foreignKey->getForeignTableName() === $tableName) {
            // 记录外键信息
        }
    }
}
```

**为什么关联表的外键发现不了？** 因为 Version20200 已经删除了引用单 `id` 列的外键约束（主键变成复合的了）。数据库层面已经没有外键约束了，元数据查询自然查不到。

**外键数据回填的依赖链（第355-415行）**：

`addUuidsToTablesWithFK()` 方法尝试回填外键表的 ULID，它依赖一个关键假设：**外键表有 `company_id` 列，并且有值**。

```php
// 第364-366行：尝试 select company_id
if ($linkCompany) {
    $fieldsSelect[] = 'company_id';
}

// 第395行：用 company_id + 外键值 查找 ULID 映射
$uuid = $idToUuidMap[$record['company_id']][$record[$fk['key']]];
```

**双重失效**：
1. **外键发现失效**：关联表没有外键约束，自动发现机制找不到它们，根本不会进入回填循环
2. **即使发现了也填不了**：关联表的 `company_id` 都是 NULL，无法按 company_id 分组查找映射

代码中的 TODO 注释（第373行）只说了一半真相：

```php
// TODO: Table doesn't have company id yet (E.G invoice_contact),
// so we need a different way of updating the data
```

实际上不是「没有 company_id」，而是「有列但没数据」，而且更根本的原因是**外键约束被删了，自动发现找不到这些表**。

### 6.2 Version20300：preUp 删除空 company_id 记录的风险

Version20300 的 `preUp()` 方法中（第61-67行），有一段容易被忽略但风险极高的代码：

```php
$this->connection->delete('invoice_contact', ['company_id' => null]);
$this->connection->delete('quote_contact', ['company_id' => null]);
$this->connection->delete('recurringinvoice_contact', ['company_id' => null]);
```

**这是数据删除操作，不是结构变更**。它直接删除所有 `company_id` 为 NULL 的关联记录。

#### 为什么要删？

因为后面要做两件事：
1. 把关联表的外键列类型设置为 `UlidType`（第144-150行）
2. 给 `company_id` 列添加外键约束（第164-168行）

如果 `company_id` 是 NULL，添加外键约束不会有问题（约束只检查非 NULL 值）。但实际原因更复杂：

**根本原因：关联表的外键列类型可能不是 ULID**。Version20201 没有成功转换这些列，它们可能还是 integer 类型。直接改列类型会因为数据类型不匹配而出错，所以需要先清理脏数据。

但更重要的是——**这些 company_id 为 NULL 的记录是「孤儿数据」**，它们不属于任何 company，在多租户架构下是无效数据。

#### 风险点分析

| 风险 | 说明 | 严重程度 |
|------|------|---------|
| **数据丢失** | 如果存在合法的关联记录但 company_id 没填上，会被直接删除 | 高 |
| **静默失败** | `delete` 操作不报错，删了多少数据也不会在迁移日志中体现 | 中 |
| **不可逆** | 迁移的 `down()` 方法无法恢复被删除的数据 | 高 |
| **级联影响** | 被删除的关联记录可能对应着业务上重要的联系人信息 | 中高 |

**为什么在 preUp 而不是 postUp？** 因为在修改 Schema 之前清理数据，避免结构变更过程中因为数据问题导致迁移失败。这是防御性编程的思路，但代价是可能丢数据。

**实际场景中这些数据是怎么产生的？**
- Version20201 添加了 `company_id` 列但没填充数据 → 所有已有记录都是 NULL
- Version20200 之后如果有新数据插入，理论上应该带 company_id
- 但如果应用代码有 bug 或者迁移顺序有问题，就可能产生孤儿数据

### 6.3 关联表外键修复的实现细节

Version20300 中修复关联表外键的方式非常直接——**手动设置列类型**，而不是走自动发现流程：

```php
// 第144-150行：显式设置每一列的类型
$this->setColumnType($schema, 'recurringinvoice_contact', 'company_id', UlidType::NAME);
$this->setColumnType($schema, 'invoice_contact', 'invoice_id', UlidType::NAME);
$this->setColumnType($schema, 'invoice_contact', 'contact_id', UlidType::NAME);
$this->setColumnType($schema, 'invoice_contact', 'company_id', UlidType::NAME);
$this->setColumnType($schema, 'quote_contact', 'quote_id', UlidType::NAME);
$this->setColumnType($schema, 'quote_contact', 'contact_id', UlidType::NAME);
$this->setColumnType($schema, 'quote_contact', 'company_id', UlidType::NAME);
```

同时添加 `company_id` 的外键约束：

```php
// 第164-168行
$recurringInvoiceContact->addForeignKeyConstraint('companies', ['company_id'], ['id']);
$invoiceContact->addForeignKeyConstraint('companies', ['company_id'], ['id']);
$quoteContact->addForeignKeyConstraint('companies', ['company_id'], ['id']);
```

**对比 Version20201 的「自动发现」，Version20300 是「手动枚举」**。这说明：
1. 自动发现机制有盲区（依赖外键约束）
2. 关联表是特殊情况，需要特殊处理
3. 这是典型的「技术债务偿还」——上一个版本没做对，这个版本补上

### 6.4 Version20305：company_id 清理的深层原因

Version20305 的代码非常简洁（第38-40行）：

```php
$schema->getTable('invoice_contact')->dropColumn('company_id');
$schema->getTable('recurringinvoice_contact')->dropColumn('company_id');
$schema->getTable('quote_contact')->dropColumn('company_id');
```

三行代码，删除三列。但这个决策背后有深层的架构考量。

#### 原因一：Doctrine SQL 过滤器的作用域

CompanyFilter 只作用在**实体**上，关联表不是实体。

```php
// CompanyFilter 的逻辑（伪代码）
public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias)
{
    // 检查的是实体的元数据，不是表
    if (! $this->hasCompanyField($targetEntity)) {
        return '';
    }
    return $targetTableAlias . '.company_id = ...';
}
```

当你查询 Invoice 实体并 join contacts 时：
- Invoice 是实体 → CompanyFilter 生效 → 自动加 `i.company_id = ?`
- Contact 也是实体 → CompanyFilter 生效 → 自动加 `c.company_id = ?`
- `invoice_contact` 关联表 → 不是实体 → **不会被直接过滤**

但没关系，因为**两端的实体都过滤了**，join 出来的关联记录自然就是正确的。关联表的 `company_id` 是多余的。

#### 原因二：数据一致性风险

冗余的 `company_id` 反而可能造成不一致：

```
场景：把一张发票从公司 A 迁移到公司 B

正确做法：改 invoice.company_id，改 contact.company_id
如果关联表也有 company_id：还得改 invoice_contact.company_id
漏改任何一个 → 数据不一致 → 诡异的 bug
```

多一份冗余数据，就多一份维护成本，多一份出错概率。

#### 原因三：关联表由 Doctrine 自动管理

多对多关联表的数据增删改查全由 Doctrine 自动处理，应用代码不会直接操作它。既然应用代码不直接用，那加 `company_id` 也没用——除非你想用它做数据库层面的直接查询，但那又回到了「为什么不走实体查询」的问题。

### 6.5 关联表五步演化全景图

把三个版本串起来看，关联表经历了完整的五步演化：

```
Step 1: Version20000-20100
  ┌──────────────────────────────────────┐
  │  invoice_contact 表                  │
  │  复合主键: (invoice_id, contact_id)  │
  │  外键约束: 有                        │
  │  列类型: integer                     │
  │  company_id: 无                      │
  └──────────────────────────────────────┘
                    ↓ Version20200：删外键约束（因为主键变复合）
Step 2: Version20200
  ┌──────────────────────────────────────┐
  │  invoice_contact 表                  │
  │  复合主键: (invoice_id, contact_id)  │
  │  外键约束: 无                        │
  │  列类型: integer                     │
  │  company_id: 无                      │
  └──────────────────────────────────────┘
                    ↓ Version20201：加 company_id 列（没填数据）+ 外键转换失败
Step 3: Version20201
  ┌──────────────────────────────────────┐
  │  invoice_contact 表                  │
  │  复合主键: (invoice_id, contact_id)  │
  │  外键约束: 无                        │
  │  列类型: integer（没转成 ULID）     │
  │  company_id: 有但全是 NULL           │
  └──────────────────────────────────────┘
                    ↓ Version20300：删 NULL 记录 + 手动修外键类型 + 加外键约束
Step 4: Version20300
  ┌──────────────────────────────────────┐
  │  invoice_contact 表                  │
  │  复合主键: (invoice_id, contact_id)  │
  │  外键约束: 有（company_id也有约束）  │
  │  列类型: UlidType                    │
  │  company_id: 有值                    │
  └──────────────────────────────────────┘
                    ↓ Version20305：删 company_id（冗余，且有一致性风险）
Step 5: Version20305 (当前)
  ┌──────────────────────────────────────┐
  │  invoice_contact 表                  │
  │  复合主键: (invoice_id, contact_id)  │
  │  外键约束: 有                        │
  │  列类型: UlidType                    │
  │  company_id: 无                      │
  └──────────────────────────────────────┘
```

### 6.6 架构教训

从关联表的曲折演化中，可以总结出几个架构教训：

**1. 迁移的「自动发现」机制有盲区**
- 依赖数据库元数据的自动发现，前提是元数据本身是准确的
- 如果前置迁移破坏了元数据（比如删了外键约束），后续的自动发现就会失效
- 关键路径上要有兜底方案

**2. 先加后删不是浪费，是认知迭代**
- Version20201 加 company_id：直觉认为需要
- Version20305 删 company_id：深入理解后发现不需要
- 这不是「做错了」，而是架构认知逐步深化的过程
- 重要的是敢于承认之前的设计不对，及时修正

**3. 数据删除要极度谨慎**
- Version20300 preUp 的 DELETE 操作非常危险
- 迁移脚本应该尽量只做结构变更，少做数据删除
- 如果必须删数据，至少要在迁移日志中记录删除了多少条

**4. 冗余字段的隐性成本**
- 加一个字段很容易，删一个字段要等好几个版本
- 冗余字段带来的一致性风险往往被低估
- 「反正加了也没坏处」的心态容易积累技术债务

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
A: 核心转换在 [Version20201.php](migrations/Version20201.php) 完成，这是整个迁移历史中最复杂的迁移，用「双写过渡 + 原子切换」策略处理了所有表的主键和外键转换。Version20300 主要修复关联表外键遗留问题，以及 Ramsey UUID → Symfony ULID 的兜底转换（标准迁移路径不触发）。

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
