# SolidInvoice 发票编号生成策略与并发防重号机制 源码分析报告

> 分析版本：SolidInvoice 3.0.0-dev
> 分析日期：2026-06-13
> 分析范围：Invoice / Quote 业务编号（invoice_id / quote_id 列）的生成、传递、落盘全链路

---

## 目录

- [一、核心概念澄清](#一核心概念澄清)
- [二、编号生成器架构](#二编号生成器架构)
- [三、五种序列策略详解](#三五序列策略详解)
- [四、编号生成与落盘的完整链路](#四编号生成与落盘的完整链路)
- [五、触发场景全景](#五触发场景全景)
- [六、并发与重试场景下的防重号机制分析](#六并发与重试场景下的防重号机制分析)
- [七、关键源码索引](#七关键源码索引)

---

## 一、核心概念澄清

在深入分析之前，必须先明确两个容易混淆的字段：

| 字段 | 列名 | 类型 | 作用 | 唯一约束 |
|------|------|------|------|---------|
| 主键 ID | `id` | ULID | 数据库主键、内部关联 | 有（主键） |
| **业务编号** | `invoice_id` / `quote_id` | VARCHAR(255) | 对外展示的发票号/报价号 | **无** |

本报告讨论的是**业务编号**（`invoice_id` / `quote_id`），而非主键 `id`。

> 历史沿革：在 2.2 版本之前（[Version20201.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/migrations/Version20201.php#L100-L104)），发票编号直接使用自增主键 `id`。2.2 版本引入独立的 `invoice_id` 列以支持自定义编号格式（前缀、后缀、不同生成策略）。

---

## 二、编号生成器架构

### 2.1 策略模式

编号生成采用**策略模式**，核心入口为 [BillingIdGenerator](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator.php)（门面类），通过 Symfony `ServiceLocator` 根据策略名称分发到具体实现。

```
BillingIdGenerator (门面/调度器)
    ├── AutoIncrementIdGenerator  → auto_increment  (默认策略)
    ├── RandomNumberGenerator     → random_number
    ├── TimestampGenerator        → timestamp
    ├── UlidGenerator             → ulid
    └── UuidGenerator             → uuid
```

### 2.2 核心入口逻辑

[BillingIdGenerator::generate()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator.php#L42-L67) 流程：

1. 根据实体类名（`Invoice::class` 或 `Quote::class`）从系统配置中读取：
   - 策略名称（`invoice/id_generation/strategy`）
   - 前缀（`invoice/id_generation/prefix`）
   - 后缀（`invoice/id_generation/suffix`）
2. 从 ServiceLocator 中获取对应的生成器实例
3. 调用生成器的 `generate()` 方法，传入 Repository、字段名、前缀、后缀等选项
4. 最终返回值 = `前缀 + 生成值 + 后缀`

---

## 三、五种序列策略详解

### 3.1 AutoIncrementIdGenerator — 自增策略（默认）

**源码位置**：[AutoIncrementIdGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/AutoIncrementIdGenerator.php)

**核心算法**：

```php
// 第 66-74 行
$lastId = $repository
    ->createQueryBuilder('e')
    ->select(sprintf('MAX(ABS(TO_NUMBER(%s)))', $field))
    ->getQuery()
    ->getSingleScalarResult();

return (string) ($lastId + 1);
```

**关键细节**：
- 执行查询前**临时禁用 `archivable` 过滤器**（软删除过滤器），确保已归档的发票也被计入最大值（第 58-60 行）
- 若配置了前缀/后缀，使用 `SUBSTRING()` SQL 函数截取中间的纯数字部分再做 MAX 计算（第 76-79 行）
- 捕获 `NonUniqueResultException|NoResultException`，异常时降级为 0
- **无任何锁机制**，纯 SELECT MAX + 自增

### 3.2 RandomNumberGenerator — 随机数策略

**源码位置**：[RandomNumberGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/RandomNumberGenerator.php)

```php
return (string) random_int(
    $options['min'] ?? self::MIN_VALUE,  // 默认 100000
    $options['max'] ?? self::MAX_VALUE   // 默认 999999
);
```

默认生成 **6 位随机整数**（100000~999999），共约 90 万种可能。

### 3.3 TimestampGenerator — 时间戳策略

**源码位置**：[TimestampGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/TimestampGenerator.php)

```php
return date($options['format'] ?? 'YmdHis');
```

默认格式 `YmdHis`（年月日时分秒），例如 `20260613153045`。

### 3.4 UlidGenerator — ULID 策略

**源码位置**：[UlidGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/UlidGenerator.php)

```php
return (new Ulid())->toBase32();
```

基于 Symfony UID 组件，输出 26 字符的 Base32 编码 ULID。
- 48 位毫秒时间戳 + 80 位随机数
- 按时间有序
- 同毫秒内碰撞概率极低

### 3.5 UuidGenerator — UUID v7 策略

**源码位置**：[UuidGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/UuidGenerator.php)

```php
return UuidV7::generate();
```

生成标准 UUID v7，例如 `018f9ad5-8b24-7c9e-a9b3-7d5f3a2e1c0d`。
- 时间有序 + 高随机性
- 抗碰撞性极强

---

## 四、编号生成与落盘的完整链路

### 4.1 表单创建流程（Web 端）

以下以发票创建为例，报价单流程完全一致。

#### 第 1 步：Action 层初始化 DTO

[Create::__invoke()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Action/Create.php#L64-L98)

```php
$dto = new InvoiceFormDTO();          // 第 79 行
$dto->invoiceDate = new DateTimeImmutable();
$dto->lines->add(new Line());

$form = $this->createForm(InvoiceType::class, $dto, $formOptions);
$form->handleRequest($request);
```

#### 第 2 步：表单构建时生成编号

[InvoiceType::buildForm()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L155-L172)

```php
// 第 162-166 行
$data = $options['data'] ?? null;
if (! $data instanceof InvoiceFormDTO || '' === $data->invoiceId) {
    $invoiceId = $this->billingIdGenerator->generate(Invoice::class);
    $builder->add('invoiceId', null, ['data' => $invoiceId]);
}
```

**关键点**：
- 编号在**表单构建阶段**就生成了（GET 请求时）
- 只有当 DTO 的 `invoiceId` 为空时才生成（编辑模式下不会重新生成）
- 生成的编号通过表单字段的 `data` 选项作为默认值传入

#### 第 3 步：模板渲染

[CreateInvoice.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Resources/views/Components/CreateInvoice.html.twig#L92-L118)

编号字段在模板中有**两种展示形态**，通过 Stimulus 控制器 `billing-id` 切换：

- **展示模式**（默认）：显示编号文本 + 编辑按钮
  ```twig
  <span class="billing-id-value">{{ form.invoiceId.vars.data }}</span>
  <button type="button" class="billing-id-edit" {{ stimulus_action('billing-id', 'edit') }}>
  ```

- **编辑模式**：输入框 + 保存/取消按钮
  ```twig
  {{ form_widget(form.invoiceId, {attr: {class: 'form-control'}}) }}
  ```

> 重要事实：**invoiceId 不是隐藏字段，用户可以主动编辑修改编号。**

#### 第 4 步：提交后 DTO → 实体映射

[InvoiceFormManager::createInvoiceFromDTO()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L40-L78)

```php
// 第 49 行 —— 直接赋值，无任何校验或重新生成
$invoice->setInvoiceId($dto->invoiceId);
```

#### 第 5 步：持久化落盘

[Create::__invoke()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Action/Create.php#L100-L119)

```php
$invoice = $this->formManager->createInvoiceFromDTO($dto);  // 第 106 行
$this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_NEW);  // 第 109 行

$entityManager = $this->doctrine->getManager();
$entityManager->persist($invoice);  // 第 118 行
$entityManager->flush();            // 第 119 行
```

**关键观察**：
- 整个落盘过程中，**没有任何 prePersist 事件或监听器**介入重新生成或校验编号
- Invoice 实体上的 `#[ORM\PrePersist]` 方法只有 [updateLines()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L351-L357)，用于更新行项目总额，与编号无关
- [InvoiceSaveListener](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Listener/Doctrine/InvoiceSaveListener.php) 也只处理状态变更，不涉及编号

### 4.2 数据库层面

#### 列定义

[invoice 实体定义](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L137-L139)

```php
#[ORM\Column(name: 'invoice_id', type: Types::STRING, length: 255)]
#[Groups(['invoice_api:read', 'invoice_api:write', 'searchable'])]
private string $invoiceId = '';
```

#### 唯一约束核查

经核查所有迁移文件，**`invoices.invoice_id` 列上没有任何唯一约束**：

- [Version20201.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/migrations/Version20201.php#L98-L104)：添加 `invoice_id` 列，无唯一约束
- [Version20300.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/migrations/Version20300.php#L162)：只给 `quote_id` 加了唯一索引（因为一个报价单只能转成一张发票）
- 所有 3.0 版本迁移中，均未对 `invoice_id` 加唯一约束

> ⚠️ 注意：很多迁移文件中出现的 `invoice_id` 是**外键列**（关联到 invoices 表的主键 id），不是业务编号列。业务编号列是 `invoices.invoice_id`。

---

## 五、触发场景全景

编号生成在以下 6 个场景中被触发：

| # | 场景 | 触发位置 | 生成时机 |
|---|------|---------|---------|
| 1 | Web 端新建发票表单渲染 | [InvoiceType::buildForm()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L164) | GET 请求，表单构建时 |
| 2 | Web 端新建报价单表单渲染 | [QuoteType::buildForm()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/QuoteBundle/Form/Type/QuoteType.php#L167) | GET 请求，表单构建时 |
| 3 | 克隆发票 | [InvoiceCloner::clone()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Cloner/InvoiceCloner.php#L90) | 克隆时，flush 之前 |
| 4 | 克隆报价单 | [QuoteCloner::clone()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/QuoteBundle/Cloner/QuoteCloner.php#L57) | 克隆时，flush 之前 |
| 5 | Quote 转 Invoice | [InvoiceManager::createFromObject()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php#L122) | 转换时，flush 之前 |
| 6 | 定期账单生成 | [GenerateInvoiceFromRecurringProcessor](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/ApiBundle/State/Processor/GenerateInvoiceFromRecurringProcessor.php) | 定期任务执行时 |

### 关于 API 直接创建

**特别注意**：通过 `POST /api/invoices` 直接创建发票时，**系统不会自动生成编号**。

- `invoiceId` 在 `invoice_api:write` 序列化组中（[Invoice.php#L138](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L138)），客户端可以传值
- 如果客户端不传，`invoiceId` 将保持默认空字符串 `''`
- 没有任何 StateProcessor 会在 API 创建时自动调用 BillingIdGenerator

---

## 六、并发与重试场景下的防重号机制分析

### 6.1 总体结论

**当前实现几乎没有任何可靠的防重号保护，存在明显的并发重号风险。**

具体表现为：
- ❌ 数据库无唯一约束
- ❌ 自增策略无锁（TOCTOU 竞态）
- ❌ 无应用层重试机制
- ❌ 表单预生成模式加剧了重号风险

---

### 6.2 数据库层面：无唯一约束

如第 4.2 节所述，`invoices.invoice_id` 列上**没有任何唯一约束**。

这意味着：
- 即使生成了重复编号，数据库也不会报错
- 重号数据会**静默写入**，只有在业务层面发现时才会暴露
- 多租户场景下（不同 company）可以有相同编号，但同一 company 内也没有联合唯一约束

> 补充：`invoices.quote_id` 有唯一约束（一个报价单只能转一张发票），但这是外键约束，不是业务编号约束。

---

### 6.3 AutoIncrement 策略：纯 SELECT MAX，无任何锁

**最严重的风险在默认策略 `auto_increment` 上。**

[AutoIncrementIdGenerator::generate()](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/AutoIncrementIdGenerator.php#L43-L83) 存在经典的 **TOCTOU（Time-of-check to time-of-use）竞态条件**：

```
时序示意（两个并发请求同时创建发票）：

时间轴 →
  │
  ├─ 请求A: SELECT MAX(invoice_id) FROM invoices → 得到 '100'
  │
  ├─ 请求B: SELECT MAX(invoice_id) FROM invoices → 得到 '100'
  │
  ├─ 请求A: 生成 '101'，写入数据库 ✓
  │
  └─ 请求B: 生成 '101'，写入数据库 ✓  ← 重号！无唯一约束，写入成功
```

代码中完全没有使用：
- ❌ `SELECT ... FOR UPDATE`（悲观锁 / 行锁）
- ❌ 数据库 `SEQUENCE` 序列对象
- ❌ 独立的序号维护表
- ❌ 乐观锁（版本号字段）
- ❌ 应用层分布式锁（Redis/APCu/文件锁）

**单元测试佐证**：[AutoIncrementIdGeneratorTest.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Tests/Generator/BillingIdGenerator/AutoIncrementIdGeneratorTest.php#L31-L40) 中，在不写入数据库的情况下，连续两次调用 `generate()` 会得到**完全相同的值 `'1'`**——这本身就说明了问题。

---

### 6.4 各策略抗碰撞能力对比

| 策略 | 并发重号概率 | 重试重号概率 | 说明 |
|------|:-----------:|:-----------:|------|
| `auto_increment` | 🔴 高 | 🔴 高 | 完全依赖 MAX 查询，无锁保护，两个并发请求必撞 |
| `timestamp` | 🔴 极高 | 🔴 极高 | 同一秒内并发必撞；重试若在同一秒内也必撞 |
| `random_number` | 🟡 中 | 🟡 中 | 6 位数 ~90 万空间，生日悖论下约 1000 次生成后碰撞概率 ~40% |
| `ulid` | 🟢 极低 | 🟢 极低 | 48 位毫秒时间戳 + 80 位随机，同毫秒内理论碰撞概率可忽略 |
| `uuid` | 🟢 极低 | 🟢 极低 | UUID v7 带 74 位随机，碰撞概率可忽略 |

---

### 6.5 表单预生成模式加剧风险

Web 表单场景下，编号在 **GET 请求（打开表单时）** 就生成了，而不是在 **POST 提交时**生成。这带来了额外的风险：

1. **长时间打开的表单**：用户打开表单后过了很久才提交，期间可能已有大量新发票生成，编号早已过期
2. **多个标签页**：用户同时打开多个创建页面，每个页面都生成了一个编号，最终只有一个会被使用，造成"跳号"
3. **放弃的表单**：用户打开表单后直接关闭，编号被浪费
4. **重复提交**：用户双击提交按钮或网络超时重试，带着同一个编号提交两次

---

### 6.6 重试场景分析

重试场景通常来自：
- 用户提交表单后网络超时，浏览器重复提交
- API 调用失败后客户端自动重试
- 消息队列消费失败后的重试

对不同策略的影响：

| 策略 | 重试是否会重号 | 原因 |
|------|:-------------:|------|
| `auto_increment` | 🔴 是 | 编号在表单构建时就确定了，重试带着同一个号提交 |
| `timestamp` | 🔴 是 | 同一秒内重试结果相同 |
| `random_number` | 🟡 可能 | 随机生成，但因为是表单预生成，重试带着同一个号 |
| `ulid` | 🟢 否（表单场景同左） | 算法保证唯一性，但表单预生成模式下重试也会重号 |
| `uuid` | 🟢 否（表单场景同左） | 同上 |

> 关键洞察：**即使使用 ULID/UUID 这种天然唯一的策略，在 Web 表单场景下也可能因为"预生成 + 重试"而导致重号**——因为编号在表单渲染时就固定了，重试时不会重新生成。

---

### 6.7 多租户下的情况

Invoice 和 Quote 都使用了 [CompanyAware](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Traits/Entity/CompanyAware.php) trait，全局启用了 `CompanyFilter`。

在 AutoIncrementIdGenerator 中，查询通过普通 Repository 的 `createQueryBuilder` 发起，会受 `CompanyFilter` 过滤——也就是说**自增是按 company 独立计数的**。

这符合业务预期，但也意味着：
- 不同公司之间可以有相同的 invoice_id
- 唯一约束如果加上，必须是 **(company_id, invoice_id)** 联合唯一
- 目前这个联合唯一约束也不存在

---

## 七、关键源码索引

### 7.1 生成器核心

| 模块 | 文件路径 |
|------|---------|
| 生成器门面 | [BillingIdGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator.php) |
| 生成器接口 | [IdGeneratorInterface.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/IdGeneratorInterface.php) |
| 自增策略 | [AutoIncrementIdGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/AutoIncrementIdGenerator.php) |
| 随机数策略 | [RandomNumberGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/RandomNumberGenerator.php) |
| 时间戳策略 | [TimestampGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/TimestampGenerator.php) |
| ULID 策略 | [UlidGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/UlidGenerator.php) |
| UUID 策略 | [UuidGenerator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Generator/BillingIdGenerator/UuidGenerator.php) |

### 7.2 表单与 DTO 链路

| 模块 | 文件路径 |
|------|---------|
| 发票创建 Action | [Create.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Action/Create.php) |
| 发票表单类型 | [InvoiceType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php) |
| 发票表单 DTO | [InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php) |
| 发票表单管理器 | [InvoiceFormManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php) |
| 发票创建模板 | [CreateInvoice.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Resources/views/Components/CreateInvoice.html.twig) |
| 报价单对应表单类型 | [QuoteType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/QuoteBundle/Form/Type/QuoteType.php) |
| 报价单表单管理器 | [QuoteFormManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/QuoteBundle/Manager/QuoteFormManager.php) |

### 7.3 实体与数据库

| 模块 | 文件路径 |
|------|---------|
| Invoice 实体 | [Invoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php) |
| Quote 实体 | [Quote.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/QuoteBundle/Entity/Quote.php) |
| invoice_id 列添加迁移 | [Version20201.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/migrations/Version20201.php) |
| 3.0 外键级联迁移 | [Version30000_5.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/migrations/Version30000_5.php) |

### 7.4 其他生成场景

| 场景 | 文件路径 |
|------|---------|
| 发票克隆 | [InvoiceCloner.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Cloner/InvoiceCloner.php) |
| 报价单克隆 | [QuoteCloner.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/QuoteBundle/Cloner/QuoteCloner.php) |
| Quote 转 Invoice | [InvoiceManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php) |
| API Quote 转 Invoice 处理器 | [QuoteToInvoiceProcessor.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/ApiBundle/State/Processor/QuoteToInvoiceProcessor.php) |

### 7.5 测试相关

| 测试文件 | 文件路径 |
|---------|---------|
| 自增策略单元测试 | [AutoIncrementIdGeneratorTest.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/CoreBundle/Tests/Generator/BillingIdGenerator/AutoIncrementIdGeneratorTest.php) |
| 发票表单类型测试 | [InvoiceTypeTest.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Tests/Form/Type/InvoiceTypeTest.php) |
| 发票管理器测试 | [InvoiceManagerTest.php](file:///d:/fz/0601-1/solo-dogfeeding/code/47-SolidInvoice/src/InvoiceBundle/Tests/Manager/InvoiceManagerTest.php) |

---

## 附录：改进建议（基于代码审计）

> 以下为审计发现的改进方向，供参考：

1. **添加数据库唯一约束**（高优先级）：在 `invoices` 和 `quotes` 表上添加 `(company_id, invoice_id)` 联合唯一索引，作为最后一道防线
2. **改生成时机为提交时**：将编号生成从"表单构建时"推迟到"提交落盘前"，减少预生成带来的问题
3. **为自增策略加锁**：使用 `SELECT ... FOR UPDATE` 或独立序号表 + 行锁，保证自增的原子性
4. **API 创建自动生成**：API 直接创建发票时也应自动生成编号，而不是依赖客户端传入
5. **高并发场景推荐 ULID/UUID**：如果业务允许，切换到 ULID 或 UUID 策略，从算法层面避免碰撞，无需数据库协调
