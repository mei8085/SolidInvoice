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

1. 新建 `companies` 表和 `user_company` 关联表
2. 为**所有业务表**增加 `company_id` 列（此时仍为 integer 类型）
3. 主键从单 `id` 改为复合主键 `(id, company_id)`
4. 调整唯一约束以包含 `company_id`（如 `clients.name` → `(name, company_id)`）
5. 新增 `user_invitations` 表
6. `payment.details` 列类型从 `object` 改为 `json`

**数据迁移策略**：
- `preUp()` 关闭外键检查
- `up()` 修改 Schema 结构
- `postUp()` 将现有数据关联到默认公司（从 `app_config` 读取公司名）

> 💡 **关键洞察**：这是一次破坏性变更。迁移后所有查询都必须带上 `company_id` 过滤条件，由运行时的 `CompanyFilter` 自动处理。
>
> **注意**：此时主键仍是 `integer` 类型，只是增加了 `company_id` 形成复合主键。真正的 ID 类型转换发生在 Version20201。

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

#### 🔹 Version20300 — 金额大升级 + ULID 类型统一 + 结构调整
[Version20300.php](migrations/Version20300.php)

**注意**：这个版本的 ID 已经是 ULID 了。它主要做三件事：

**一、金额类型升级为 BigInteger**：
- 所有 `*_amount` 列类型从 `integer` 改为 `BigIntegerType`
- 使用 `Brick\Math\BigInteger` 处理大整数
- 删除所有 `*_currency` 列（如 `total_currency`、`price_currency` 等）
- 货币信息统一由 Client 实体的 `currency` 字段决定

**二、ULID 类型统一**：
- 从 `Ramsey\Uuid\Doctrine\UuidBinaryOrderedTimeType` 迁移到 `Symfony\Bridge\Doctrine\Types\UlidType`
- 遍历所有表，检查列类型，如果是 `UuidBinaryOrderedTimeType` 则统一转换为 `UlidType`
- 这解释了为什么 Version20201 已经转了 ULID，Version20300 还要再转一次 —— 库换了

**三、关联表遗留问题修复**：
- 显式设置 `invoice_contact`、`quote_contact`、`recurringinvoice_contact` 等关联表的外键列类型为 `UlidType`
- 为这些关联表的 `company_id` 添加指向 `companies` 表的外键约束
- （Version20201 中这些关联表的外键转换因为缺少 company_id 被跳过了，见 TODO 注释）

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

#### 🔹 Version20306 — 密码重置重构
[Version20306.php](migrations/Version20306.php)

- 新增 `reset_password_request` 表（Symfony C 标准重置密码表结构）
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

#### 决策 4：主键从 Integer 到 ULID
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

## 六、开发时的心智模型

### 6.1 修改实体时的检查清单

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

### 6.2 常见疑惑解答

**Q: 为什么实体里看不到 `company_id` 字段？**
A: 因为它封装在 `CompanyAware` trait 中，属性名是 `$company`（对象），不是 `$company_id`（整数）。Doctrine 会自动管理外键列。

**Q: 为什么迁移里有 `discount_valueMoney_amount` 这样的长列名？**
A: 因为 `Discount` 是 `#[ORM\Embeddable]`，它的字段会展开到主表中，列名规则是 `{embeddable字段名}_{embeddable内部字段名}`。

**Q: 迁移里的 `deleted` 列为什么实体里没有？**
A: 因为在 Version20100 中已经删除了这些列，改用 `archived` 字段（Archivable trait）。迁移是历史记录，实体是当前状态。

**Q: 为什么查询时自动带上了 `company_id` 条件？**
A: 因为 `CompanyFilter` 是全局启用的 SQL Filter，会自动为所有 CompanyAware 实体的查询添加该条件。

---

## 七、关键文件速查表

| 类别 | 文件 | 作用 |
|------|------|------|
| 迁移配置 | [doctrine_migrations.php](config/packages/doctrine_migrations.php) | 迁移路径、存储表配置 |
| ORM 配置 | [doctrine.php](config/packages/doctrine.php) | 实体映射、过滤器、自定义类型 |
| 核心 Trait | [Archivable.php](src/CoreBundle/Traits/Entity/Archivable.php) | 归档字段 |
| 核心 Trait | [TimeStampable.php](src/CoreBundle/Traits/Entity/TimeStampable.php) | 时间戳 |
| 核心 Trait | [CompanyAware.php](src/CoreBundle/Traits/Entity/CompanyAware.php) | 多租户 |
| 基类 | [BaseInvoice.php](src/InvoiceBundle/Entity/BaseInvoice.php) | 发票基类 |
| 值对象 | [Discount.php](src/CoreBundle/Entity/Discount.php) | 折扣 Embeddable |
| 自定义类型 | [BigIntegerType.php](src/CoreBundle/Doctrine/Type/BigIntegerType.php) | 大整数类型 |
| 过滤器 | [CompanyFilter.php](src/CoreBundle/Doctrine/Filter/CompanyFilter.php) | 多租户过滤 |
| 过滤器 | [ArchivableFilter.php](src/CoreBundle/Doctrine/Filter/ArchivableFilter.php) | 归档过滤 |
| 里程碑迁移 | [Version20000.php](migrations/Version20000.php) | 初始版本 |
| 里程碑迁移 | [Version20200.php](migrations/Version20200.php) | 多租户架构 |
| 里程碑迁移 | [Version20300.php](migrations/Version20300.php) | ULID + BigInteger |
