# SolidInvoice 接口文档字段约束与前端表单校验规则 —— 共享层深度分析报告（修正版）

> **修正说明**：本报告基于实际代码深度走查，纠正了首版分析中的三处关键偏差：
> 1. 复杂表单（Invoice/Quote）与 API **并不共享同一层校验** —— 两者走的是「DTO 校验→手动映射→Entity 持久化」与「API Platform 直接校验 Entity」两条独立路径
> 2. 浏览器原生必填校验 **并非主路径** —— 核心属性是 `data-required`（仅数据标记），绝大多数字段不触发浏览器原生弹窗
> 3. 存在大量**仅表单/安装流程专用的校验组**，需逐类说明修改优先级

---

## 一、核心结论（Executive Summary）

| 问题 | 最终结论 |
|------|---------|
| **接口文档 & 表单校验共用哪一层？** | **取决于字段的复杂程度**，分三类：<br>① 简单实体（Client/Tax/PaymentSettings）→ 共用 **Entity 层**<br>② 复杂业务表单（Invoice/Quote 新建/编辑）→ **不共用**，表单走 DTO 层，API 走 Entity 层，两者规则需分别维护<br>③ 安装/注册流程 → 只走**专用 DTO 层**，与 API 完全无关 |
| **浏览器原生必填校验是主路径吗？** | **绝对不是。**<br>主路径是 **服务端 Symfony Validator**（$form→isValid() / API Validation Listener）<br>前端仅有：<br>① `data-required` 数据标记（用于 Label 星号，不触发浏览器校验）<br>② 少数 Live Component / Ajax 异步实时校验<br>③ 两个页面显式加了 `novalidate` 彻底关闭原生校验 |
| **只在表单/安装流程生效的规则？** | 共 4 大类专用校验组：<br>① `form` 组（Client.contacts 集合最小数量）<br>② `existing_client` / `new_client`（Invoice/Quote 条件校验组）<br>③ 安装向导专用组：`database_config*`、`user_account`<br>④ Mailer/Notification 动态 provider 组 |
| **字段规则变更时优先改哪一侧？** | **先判断字段属于上述哪一类，再决定改动点。**<br>总体原则：<br>❶ 找对应类的 `#[Assert]` 注解（真理源）<br>❷ 若两端都需要，改 Entity + 改 DTO（如有）+ 改映射 Manager（如有）<br>❸ API 侧还需检查 `#[Groups]` 序列化组<br>❹ 最后才考虑前端模板 |

---

## 二、系统架构全景图（修正版）

```
┌─────────────────────────────────────────────────────────────────────────────────────┐
│                         API 接口文档（OpenAPI/Swagger）                               │
│                                                                                       │
│    字段来源：                                                                          │
│    ① Entity 上的 #[Assert\*]                                                          │
│    ② Entity 上的 #[ApiProperty(openapiContext)] 手工补充                               │
│    ③ #[Groups(['xxx_api:read', 'xxx_api:write'])] 字段可见性控制                       │
│    ④ validationContext: { groups: ['Default', 'api'] } 校验组                          │
│                                                                                       │
│    校验执行者：API Platform Validation Listener                                       │
│         ↓ 校验 Entity 本身 ↓                                                           │
└────────────────────────────────────┬──────────────────────────────────────────────────┘
                                     │
                                     │
                    ┌────────────────┴────────────────┐
                    │                                 │
                    ▼                                 ▼
        ┌─────────────────────┐            ┌──────────────────────────┐
        │  Entity 层          │            │  DTO 层（表单专用）        │
        │  ┌───────────────┐ │            │  ┌────────────────────┐  │
        │  │ Client        │ │            │  │ InvoiceFormDTO     │  │
        │  │ Contact       │ │            │  │ QuoteFormDTO       │  │
        │  │ Tax           │ │            │  │ Registration       │  │
        │  │ Invoice       │◄┼──API 共用──┤  │ ChangePassword     │  │
        │  │ Quote         │ │            │  │ Installation       │  │
        │  │ BaseInvoice   │ │            │  │ DatabaseConfig     │  │
        │  └───────────────┘ │            │  │ UserAccount        │  │
        │                    │            │  └────────────────────┘  │
        │  真理源一：简单实体  │            │  真理源二：复杂表单专用   │
        └────────────────────┘            └──────────────────────────┘
                    ▲                                    ▲
                    │ data_class 绑定                    │ data_class 绑定
                    │                                    │
        ┌────────────────────────────────────────────────────────────────────┐
        │                        前端表单（Web）                                 │
        │                                                                       │
        │  FormType:                                                             │
        │   ClientType      → data_class: Client::class                         │
        │   TaxType         → data_class: Tax::class                            │
        │   InvoiceType     → data_class: InvoiceFormDTO::class  ← 注意不是Invoice!│
        │   QuoteType       → data_class: QuoteFormDTO::class                   │
        │   RegistrationType→ data_class: Registration::class                   │
        │   InstallationType→ data_class: Installation::class                   │
        │                                                                       │
        │  校验链路：                                                             │
        │   $form→handleRequest()                                                │
        │   $form→isValid()  ←  Symfony Validator 读取 data_class 上的 #[Assert] │
        │       │                                                                 │
        │       ├── 若绑定 Entity → 直接校验 Entity                              │
        │       ├── 若绑定 DTO → 校验 DTO → 手动 → Manager→createXxxFromDTO()   │
        │       │                      ↳映射到 Entity → persist(不二次校验!)     │
        │       │                                                                 │
        │       └── 前端 UI 展示：                                                │
        │           form_widget() 输出 data-required="required"(非原生 required!)│
        │           form_errors() 渲染服务端返回的错误                             │
        │           Live Component / Ajax 异步错误提示                             │
        │           Stimulus 控制器仅做体验增强（VAT校验、密码强度条）              │
        └───────────────────────────────────────────────────────────────────────┘
```

---

## 三、三类字段的共享层深度解析

### 3.1 第一类：简单实体 —— Entity 是真正的共享真理源

**典型代表**：Client、Contact、Tax、Payment Settings

这类字段的 API 文档约束与前端表单校验 **100% 共用 Entity 上的 `#[Assert]` 注解**。

#### 示例 1：Client.name 字段

文件：[Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L93-L98)

```php
#[ORM\Column(name: 'name', type: Types::STRING, length: 125)]
#[Assert\NotBlank]                          // ← 同时用于：API校验 + 表单校验
#[Assert\Length(max: 125)]                  // ← 同时用于：API文档maxLength + 表单maxlength
#[Serialize\Groups(['client_api:read', 'client_api:write', 'searchable'])]
private ?string $name = null;
```

**两端如何消费同一约束：**

| 消费方 | 机制 | 实际效果 |
|-------|------|---------|
| API 文档 | API Platform 解析 Entity 的 Assert → 生成 OpenAPI Schema | `required: ["name"]`, `maxLength: 125` |
| API 校验 | Validation Listener 使用 `validationContext: {groups: ['Default','api']}` | 提交空值或超长时返回 422 错误 |
| 表单校验 | ClientType 配置 `'data_class' => Client::class`，`validation_groups: ['Default', 'form']` | `$form→isValid()` 时执行相同的 NotBlank + Length |
| 表单UI | `form_widget(form.name)` 渲染 widget_attributes block | `<input data-required="required" maxlength="125">` |

#### 示例 2：Client.contacts —— 仅表单专用的组

文件：[Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L146-L159)

```php
#[Assert\Count(
    min: 1,
    minMessage: 'You need to add at least one contact to this client',
    groups: ['form'],          // ← 关键点：只在 form 组生效
)]
#[Assert\Valid(groups: ['form'])]
#[Serialize\Groups(['client_api:read'])]   // ← API 只读，不可写
private Collection $contacts;
```

**效果对照表：**

| 场景 | validation_groups | Count 约束是否生效 | 原因 |
|-----|-------------------|-------------------|------|
| Web 表单创建 Client | `['Default', 'form']` | ✅ 生效，必须至少 1 个联系人 | form 组包含在内 |
| `POST /api/clients` 创建 Client | `['Default', 'api']` | ❌ 不生效，允许空联系人 | RESTful 设计：联系人为独立资源，后续用 `/clients/{id}/contacts` 端点添加 |
| `PATCH /api/clients/{id}` 更新 | `['Default', 'api']` | ❌ 不生效 | 同上 |

---

### 3.2 第二类：复杂业务表单（Invoice/Quote）—— DTO 与 Entity 完全分离

**⚠️ 首版报告的偏差点就在这里！** 复杂表单并不是「共用 Entity 层」，而是 **DTO 做表单校验 → 手动映射 Entity → Entity 不做二次校验**。

#### 完整链路走查：Invoice 创建流程

```
用户在浏览器填写发票表单
        ↓
POST 表单数据到 /invoices/create
        ↓
InvoiceType→handleRequest() 填充 InvoiceFormDTO
        ↓
$form→isValid()
  ↳ 校验对象是 InvoiceFormDTO 实例（不是 Invoice Entity!）
  ↳ 使用的约束完全来自 InvoiceFormDTO 上的 #[Assert]
  ↳ validation_groups 动态选 existing_client 或 new_client
        ↓
  校验通过？ ──否──→ 重新渲染表单+错误信息
        │是
        ▼
InvoiceFormManager→createInvoiceFromDTO($dto)
  ↳ 纯手动映射：$invoice→setInvoiceId($dto→invoiceId)
  ↳ 纯手动映射：$invoice→setDue($dto→due)
  ↳ 纯手动映射：foreach ($dto→lines as $line) { $invoice→addLine($line); }
  ↳ 此处 **不调用 Validator→validate($invoice)**！！
        ↓
EntityPersister→persist($invoice) → EntityPersister→flush()
  ↳ Doctrine 只做 ORM 级检查（外键、非空列、唯一索引）
  ↳ **不执行 Entity 上的 #[Assert] 约束**
        ↓
完成
```

#### 关键代码证据 1：InvoiceFormManager 没有二次校验

文件：[InvoiceFormManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L40-L78)

```php
public function createInvoiceFromDTO(InvoiceFormDTO $dto): Invoice
{
    $invoice = new Invoice();
    $client = $this->resolveClient($dto);
    $invoice->setClient($client);
    $invoice->setInvoiceId($dto->invoiceId);          // ← 纯 set，无校验
    $invoice->setInvoiceDate($dto->invoiceDate ?? ...);
    $invoice->setDue($dto->due);
    $invoice->setDiscount($dto->discount);
    $invoice->setTerms($dto->terms);
    // ... 逐字段手动映射 ...
    foreach ($dto->lines as $line) {
        $invoice->addLine($line);                      // ← 集合直接赋值
    }
    return $invoice;                                    // ← 直接返回，没有 validate()
}
```

#### 关键代码证据 2：Action 层 persist 前无校验

文件：[Create.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Action/Create.php#L100-L119)

```php
if ($form->isSubmitted() && $form->isValid()) {   // ← 只校验 DTO
    $invoice = $this->formManager->createInvoiceFromDTO($dto);
    // ... 状态机 apply ...
    $entityManager = $this->doctrine->getManager();
    $entityManager->persist($invoice);             // ← 直接持久化，无第二次 validate()
    $entityManager->flush();
    // ...
}
```

#### 关键代码证据 3：InvoiceFormDTO 与 Invoice Entity 上的规则是**两套独立代码**

**InvoiceFormDTO 侧（仅表单用）**：[InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php#L32-L86)

```php
// 模式1：选择已有客户
#[Assert\NotBlank(groups: ['existing_client'])]
public ?Client $client = null;

// 模式2：内联新建客户字段（Entity里根本没有这些字段！）
#[Assert\NotBlank(groups: ['new_client'])]
#[Assert\Length(max: 125, groups: ['new_client'])]
public ?string $newClientName = null;              // ← Entity 没有！

#[Assert\NotBlank(groups: ['new_client'])]
#[Assert\Email(groups: ['new_client'])]
public ?string $newContactEmail = null;             // ← Entity 没有！

// 通用字段（与 Entity 字段名相同，但 Assert 是独立写的）
#[Assert\NotBlank]
public string $invoiceId = '';

#[Assert\Count(min: 1)]
#[Assert\Valid]
public ArrayCollection $lines;

// 模式1下至少选1个联系人（条件组）
#[Assert\Count(min: 1, groups: ['existing_client'])]
public ArrayCollection $users;
```

**Invoice Entity 侧（仅 API 用）**：[BaseInvoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php)  + Invoice.php

```php
// Entity 的校验是独立定义的（主要用于 API 校验）
// 注意：Entity 完全不知道 newClientName / newContactEmail 这些 DTO 专用字段
```

**对照表：Invoice/Quote 两端规则来源完全不同**

| 字段 | Web 表单规则来源 | API 规则来源 | 是否需要同步维护 |
|-----|----------------|-------------|----------------|
| `invoiceId` 必填 | `InvoiceFormDTO→invoiceId` 上的 `#[Assert\NotBlank]` | Invoice Entity 上对应字段的 Assert | **✅ 需要** |
| `client` 必填（模式1） | `InvoiceFormDTO→client` 上的 `groups: ['existing_client']` | Invoice Entity→client 上的 `#[Assert\NotBlank]` | **✅ 需要**（规则不同：表单按条件，API始终要） |
| `lines` 至少 1 条 | `InvoiceFormDTO→lines` 上的 `#[Assert\Count(min: 1)]` | Invoice Entity→lines 上的 Assert | **✅ 需要** |
| `newClientName` 必填（模式2） | `InvoiceFormDTO→newClientName` 上的 `groups: ['new_client']` | ❌ 不存在（API走独立的 Client 创建端点） | 不需要同步 |
| `users` 至少 1 个（模式1） | `InvoiceFormDTO→users` 上的 `groups: ['existing_client']` | Invoice Entity→users 上的规则 | **✅ 需要** |

---

### 3.3 第三类：安装/注册/找回密码流程 —— 专用 DTO，与 API 完全无关

这些流程没有对应的 REST API，字段和规则只存在于前端表单→专用 DTO 的链路中。

#### 安装向导：Installation DTO 体系

文件：[Installation.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/Installation.php#L16-L27)

```php
final class Installation
{
    public function __construct(
        #[Valid(groups: ['database_config', 'database_config_mysql', ...])]
        public DatabaseConfig $databaseConfig = new DatabaseConfig(),
        #[Valid(groups: ['user_account'])]
        public UserAccount $userAccount = new UserAccount(),
        public string $currentStep = 'start',
    ) { }
}
```

子 DTO：[DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php#L24-L43)

```php
#[Callback(callback: 'validate', groups: ['database_config'])]   // ← 实际尝试连接数据库
final class DatabaseConfig
{
    public function __construct(
        #[NotBlank(groups: ['database_config'])]
        public ?string $driver = null,

        // MySQL/MariaDB/PostgreSQL 才需要 host 和 name
        #[NotBlank(groups: ['database_config_mysql', 'database_config_mariadb', 'database_config_pgsql'])]
        public ?string $host = null,

        #[NotBlank(groups: ['database_config_mysql', 'database_config_mariadb', 'database_config_pgsql'])]
        public ?string $name = SolidInvoiceCoreBundle::APP_NAME,
        // SQLite 不需要 host/port/user/password/name...
    ) { }
}
```

子 DTO：[UserAccount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/UserAccount.php#L20-L36)

```php
public function __construct(
    #[NotBlank(groups: ['user_account'])]
    public ?string $firstName = null,

    #[NotBlank(groups: ['user_account']), Email(groups: ['user_account'])]
    public ?string $emailAddress = null,

    #[NotBlank(groups: ['user_account']), Length(min: 6, groups: ['user_account'])]
    public ?string $password = null,
) { }
```

**专用组清单（安装流程）：**

| 校验组 | 触发条件 | 校验内容 |
|-------|---------|---------|
| `database_config` | 步骤：数据库配置页 | driver 必填 + Callback 实际连库测试 |
| `database_config_mysql` | 选择 MySQL | host/name 必填 |
| `database_config_mariadb` | 选择 MariaDB | host/name 必填 |
| `database_config_pgsql` | 选择 PostgreSQL | host/name 必填 + port 整数 |
| `user_account` | 步骤：管理员账号页 | 姓名/邮箱/密码必填，邮箱格式，密码≥6位 |

这些组在整个 API 体系中**完全不会被调用到**。

---

## 四、浏览器原生校验——彻底澄清：它不是主路径

### 4.1 最关键证据：字段模板用的是 `data-required`，不是原生 `required`

文件：[fields.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig#L23-L26)

```twig
{% block widget_attributes -%}
    id="{{ id }}" name="{{ full_name }}"
    {% if disabled %} disabled="disabled"{% endif %}
    {% if required %} data-required="required"{% endif %}
    {#                ↑↑↑↑↑↑↑↑↑↑↑↑↑                    #}
    {#     这里是 data-required，不是 required="required" #}
    {#     data-* 前缀只是自定义数据属性，浏览器会忽略它    #}
    {#     不会触发浏览器原生的"请填写此字段"弹窗         #}
    {% for attrname, attrvalue in attr %} ... {% endfor %}
{%- endblock widget_attributes %}
```

### 4.2 `data-required` 实际用途

它只用于：
- 触发 CSS：`[data-required] + label::before` 在 label 前加红色 `*` 号
- 少数自定义 JS 读取这个属性做标记

**不会触发浏览器的 HTML5 Form Validation API。**

### 4.3 novalidate 使用情况

| 模板 | 是否显式 novalidate |
|------|-------------------|
| [register.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Resources/views/Security/register.html.twig#L42) | ✅ `novalidate: 'novalidate'` |
| 安装向导 `SystemInstallation.html.twig` | ✅（通过 FormFlow 自动配置） |
| Client 创建/编辑 `ClientForm.html.twig` | ❌ 未设置（但因为 data-required，依然不触发原生校验） |
| Tax 创建/编辑 `form.html.twig` | ❌ 未设置 |
| Invoice 创建/编辑 `CreateInvoice.html.twig` | ❌ 未设置 |
| 其他数十个业务表单 | ❌ 绝大多数未设置 |

### 4.4 真正的校验主路径——三层服务端校验

```
第 1 层：Symfony Form Validator（所有表单通用）
    $form→isValid() 读取 data_class 上的 #[Assert]
    ↓ 通过 → 继续
    ↓ 失败 → form_errors() / form_row() 中渲染字段级错误信息

第 2 层：Callback / 自定义断言（复杂业务校验）
    - DatabaseConfig→validate() 真正尝试连接数据库
    - UserPassword 断言检查当前密码是否正确
    - UniqueEntity 断言检查唯一性

第 3 层：Doctrine / DB 层检查（最后一道防线）
    - 非空列 NOT NULL 约束
    - 外键约束
    - 唯一索引
    - 失败抛异常 → 转为 500 或 4xx
```

### 4.5 前端异步体验增强（非主校验路径）

| 控制器 | 用途 | 是否改变校验结果 |
|-------|------|----------------|
| [vat-validator-controller.ts](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/assets/controllers/vat-validator-controller.ts) | 点击 Validate 按钮异步调 Tax Validate 接口检查 VAT | 否，只显示对/错图标，提交时服务端会再次校验 |
| [password-strength-controller.ts](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/assets/controllers/password-strength-controller.ts) | 实时显示密码强度条和文字描述 | 否，强度算法与服务端的 `#[PasswordStrength]` 独立，最终以服务端结果为准 |
| Live Components（Invoice/Quote 表单） | 切换 Client 模式、新增行等操作时局部重新渲染，提前把当前输入的错误显示出来 | 是**预演**服务端校验，不是独立规则 |

---

## 五、仅表单/安装流程生效的规则完整索引

### 5.1 `form` 组：Client + Contact 专用

**Entity：Client** —— [Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L146-L159)

| 字段 | 约束 | form 组效果 | API 效果 |
|-----|------|------------|---------|
| `contacts` 集合 | `Count(min: 1)` | 至少添加 1 个联系人 | ❌ 不生效 |
| `contacts` 嵌套 | `Valid` | 校验每个 Contact 子对象 | ❌ 不生效 |

**Entity：Contact** —— [Contact.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php#L160-L188)

| 字段 | 约束 | 组 | 说明 |
|-----|------|----|------|
| `firstName` | `NotBlank` + `Length(max:125)` | `['Default', 'form']` | 显式双组，两端都校验 |
| `lastName` | `Length(max:125)` | `['Default', 'form']` | 显式双组 |
| `email` | `NotBlank` + `Email` | `['Default', 'form']` | 显式双组 |

> **设计意图推测**：Contact 写上双组而非省略 groups（默认 Default），是为了如果将来在「批量导入 CSV」这类新场景中用独立的 import 组校验，这些规则就不会被误触发，体现了防御式设计。

### 5.2 `existing_client` / `new_client`：Invoice/Quote 条件组

**DTO：InvoiceFormDTO / QuoteFormDTO** —— [InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php#L33-L86)

| 组 | 触发条件 | 生效字段 |
|----|---------|---------|
| `existing_client` | 用户选择「选择已有客户」radio | `client` 必填，`users` 至少 1 个联系人 |
| `new_client` | 用户选择「新建客户」radio | `newClientName` 必填+≤125，`newContactFirstName` 必填+≤125，`newContactEmail` 必填+邮箱格式 |

动态切换逻辑在 [InvoiceType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L210-L221) 的闭包中：

```php
'validation_groups' => function (FormInterface $form) {
    $data = $form->getData();
    $groups = ['Default'];
    if ($data instanceof InvoiceFormDTO) {
        if ($data->clientMode === InvoiceClientMode::NewClient) {
            $groups[] = 'new_client';
        } else {
            $groups[] = 'existing_client';
        }
    }
    return $groups;
},
```

### 5.3 安装向导专用组

文件：[Installation.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/) + 子 DTO

| 组 | 所属类 | 内容摘要 |
|----|-------|---------|
| `database_config` | DatabaseConfig | driver 必填 + Callback 试连数据库 |
| `database_config_mysql` | DatabaseConfig | host/name 必填 |
| `database_config_mariadb` | DatabaseConfig | host/name 必填 |
| `database_config_pgsql` | DatabaseConfig | host/name 必填 + port 是整数 |
| `user_account` | UserAccount | firstName/emailAddress/password 必填 |

### 5.4 Mailer / Notification 动态 Provider 组

在 MailerBundle / NotificationBundle 的 FormType 中，根据用户选择的 SMTP / Postmark / Slack 等 provider，动态生成并追加对应名称的 validation_groups。

---

## 六、字段规则变更时的修改决策流程

### 6.1 总体原则：先「归类」再「动手」

```
收到需求：某字段规则要改（例如：Client.name 从 125 改为 150 字符）
        │
        ▼
┌─判断：这个字段属于哪一类？───────────────────────────────┐
│                                                          │
│  A. 简单实体（Client/Tax/Contact/普通设置项）              │
│      → Entity 有 #[Assert]，FormType 的 data_class 是它     │
│                                                          │
│  B. 复杂业务表单字段（Invoice/Quote）                      │
│      → 字段在 InvoiceFormDTO / QuoteFormDTO 中？          │
│      → 字段也在 Invoice / Quote Entity 中？                 │
│                                                          │
│  C. 安装/注册/找回密码流程                                │
│      → 字段在 InstallBundle / UserBundle 的专用 DTO 中    │
└──────────────────────────────────────────────────────────┘
        │
        ▼
分别按 A / B / C 路径修改（见下方）
        │
        ▼
改完后执行：bin/ecs check --fix → bin/phpstan analyse → bin/phpunit
```

### 6.2 路径 A：简单实体字段修改

**示例：Client.name 最大长度 125 → 150**

```
Step 1（真理源，必须改）：修改 Entity 上的两处注解
    [Client.php]
    - #[ORM\Column(length: 125)]  →  length: 150
    - #[Assert\Length(max: 125)]  →  max: 150

Step 2（DB 变更需要）：生成迁移
    bin/console doctrine:migrations:diff
    → 生成 ALTER TABLE clients MODIFY name VARCHAR(150)

Step 3（API 侧检查）：Groups 是否需要调整
    → 本例无需，name 本来就在 client_api:read / write 中

Step 4（表单侧检查）：ClientType / ClientForm 模板
    → 无需改！ maxlength="150" 会自动输出

Step 5（自动生效的，不用改）：
    ✅ API 文档 OpenAPI maxLength 自动更新
    ✅ API 请求校验 Length(max:150) 自动生效
    ✅ Web 表单 maxlength 属性自动更新
    ✅ Web 表单服务端校验自动生效
```

### 6.3 路径 B：复杂业务表单（Invoice/Quote）字段修改

**⚠️ 最容易出错的场景！必须同时检查 DTO 和 Entity 两边。**

**示例：invoiceId（发票号）最大长度从 255 改为 100，并且允许为空**

```
Step 1：列清单——这个字段在哪几层都有？
    ① InvoiceFormDTO→invoiceId（表单校验）
    ② Invoice Entity→invoiceId（API 校验 + DB 列）
    ③ InvoiceFormManager（字段映射，本例可能不需要改）

Step 2：修改 InvoiceFormDTO（表单真理源）
    [InvoiceFormDTO.php]
    - #[Assert\NotBlank]  →  删掉（允许为空）
    - 新增 #[Assert\Length(max: 100)]

Step 3：修改 Invoice Entity（API 真理源 + DB）
    [Invoice.php 或 BaseInvoice.php 对应字段处]
    - #[ORM\Column(length: 255)] →  length: 100, nullable: true
    - 去掉对应 #[Assert\NotBlank]
    - 加上 #[Assert\Length(max: 100)]
    - 检查 Groups：确保 invoice_api:write 组包含该字段

Step 4：生成迁移
    bin/console doctrine:migrations:diff

Step 5：检查 InvoiceType / CreateInvoice 模板
    → 一般无需改，除非前端有特殊逻辑

Step 6：检查 InvoiceFormManager→createInvoiceFromDTO()
    → 本例只是 setInvoiceId($dto→invoiceId)，无需改

Step 7：可选的人工检查
    ① Web 表单：空值能否成功保存？
    ② Web 表单：输入 101 个字符是否报错？
    ③ POST /api/invoices 空值能否成功？
    ④ POST /api/invoices 超长是否 422？
    ⑤ OpenAPI /api/docs 中 invoiceId 是否 required=false / maxLength=100
```

### 6.4 路径 C：安装/注册流程专用字段修改

**示例：安装流程的管理员密码从最少 6 位改为最少 10 位**

```
Step 1（唯一真理源）：修改专用 DTO
    [UserAccount.php]
    - #[Length(min: 6, groups: ['user_account'])]
                      ↑
               改为 min: 10

Step 2：检查对应 FormType
    → 一般不需要，data_class 绑定 DTO

Step 3：检查前端模板
    → 如密码提示框里写了"至少 6 位"，需要手动改文字（这是文案，不是校验规则）

Step 4：不需要做的事（这些完全不相关）
    ❌ 不用改 User Entity（除非登录用户改密码的规则也要同步改，那是另一个 DTO ChangePassword）
    ❌ 不用生成迁移（password 字段在 DB 中总是固定长度 hash，minLength 只在 DTO 层校验）
    ❌ 不用改 API 配置（安装流程没有 API）
```

### 6.5 修改优先级速查表

按**「真理源从高到低」**排列，优先改上面的，再看下层是否需要同步：

| 层级 | 改动频率 | 改动后同步面 | 典型场景 |
|------|---------|------------|---------|
| 1️⃣ Entity `#[Assert]` + `#[ORM]` | 高 | API文档 + API校验 + 表单校验 + DB 全部自动同步 | 简单实体的字段规则变化 |
| 2️⃣ Entity `#[Groups]` | 中 | API 字段可见性 | 让字段在 API 中新增/隐藏 |
| 3️⃣ 表单专用 DTO `#[Assert]` | 中 | 仅 Web 表单 | Invoice/Quote 条件校验、安装流程字段 |
| 4️⃣ FormType 的 validation_groups 闭包 | 低 | 仅 Web 表单的组切换 | 新增一种表单模式 |
| 5️⃣ FormType 的 buildForm 字段配置 | 中 | 仅 UI 表现 | 调整字段顺序、控件类型、帮助文案 |
| 6️⃣ Twig 模板 | 低 | 仅 UI | 新增字段、改变排版 |
| 7️⃣ 前端 Stimulus 控制器 | 极低 | 仅体验 | 新增视觉增强指示器 |
| 8️⃣ API 文档手工 openapiContext | 极低 | 仅文档补充 | 补充枚举值说明、复杂类型 |

---

## 七、代码证据索引

### 7.1 Entity 层（真理源一）

| 文件 | 关键内容 |
|------|---------|
| [Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php) | ApiResource + validationContext `['Default','api']`；name/website/currencyCode 上的 Assert；contacts 的 `groups: ['form']` |
| [Contact.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php) | firstName/email 上的 `groups: ['Default', 'form']` |
| [Tax.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/TaxBundle/Entity/Tax.php) | name/rate/type 上的 Assert 规则 |
| [BaseInvoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php) | Invoice Entity 的字段、Groups、ApiProperty |

### 7.2 表单 DTO 层（真理源二）

| 文件 | 关键内容 |
|------|---------|
| [InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php) | `existing_client` / `new_client` 条件组、lines/users 集合约束 |
| [QuoteFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/QuoteBundle/DTO/QuoteFormDTO.php) | 同 InvoiceFormDTO，用于报价单 |
| [Registration.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/Registration.php) | 用户注册 DTO，Email / PasswordStrength 约束 |
| [ChangePassword.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/ChangePassword.php) | 修改密码 DTO，UserPassword + Length |
| [Installation.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/Installation.php) | 安装向导主 DTO，Valid + groups 配置 |
| [DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php) | 数据库配置 DTO，Callback 真连库校验 |
| [UserAccount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/UserAccount.php) | 安装向导管理员账号 DTO |

### 7.3 FormType 绑定层

| 文件 | data_class 绑定 | validation_groups |
|------|----------------|-------------------|
| [ClientType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ClientType.php) | `Client::class` | `['Default', 'form']` |
| [TaxType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/TaxBundle/Form/Type/TaxType.php) | `Tax::class` | `['Default']` |
| [InvoiceType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php) | `InvoiceFormDTO::class` | **闭包动态切换** existing_client / new_client |

### 7.4 表单提交 + DTO→Entity 映射层

| 文件 | 关键逻辑 |
|------|---------|
| [Create.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Action/Create.php#L100-L119) | Invoice 表单的 isValid() 只校验 DTO，不二次校验 Entity |
| [InvoiceFormManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L40-L78) | createInvoiceFromDTO() 纯手动 set，**无二次 validate()** |

### 7.5 前端模板与校验属性层

| 文件 | 关键内容 |
|------|---------|
| [fields.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig#L23-L26) | widget_attributes block：输出 **`data-required`** 而非原生 `required` |
| [CreateInvoice.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Resources/views/Components/CreateInvoice.html.twig#L13) | 复杂表单使用 Live Component，无 novalidate 但也无原生 required |
| [register.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Resources/views/Security/register.html.twig#L42) | 注册页显式 `novalidate`，完全关闭原生校验 |
| [vat-validator-controller.ts](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/assets/controllers/vat-validator-controller.ts) | VAT 校验：只做体验增强，不改变结果 |
| [password-strength-controller.ts](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/assets/controllers/password-strength-controller.ts) | 密码强度条：只做体验增强，不改变结果 |

### 7.6 API Platform 配置层

| 文件 | 关键内容 |
|------|---------|
| [api_platform.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/config/packages/api_platform.php) | 全局 API 格式、分页、OpenAPI 配置 |

---

## 八、常见陷阱与反模式

| 陷阱/反模式 | 实际后果 | 正确做法 |
|------------|---------|---------|
| **「改了 Entity 为什么 Invoice 表单没生效？」** | Invoice 表单用的是 InvoiceFormDTO，不是 Invoice Entity | 复杂表单要同步改 Entity + DTO |
| **「DTO 改了为啥 API 文档还是旧值？」** | API Platform 读的是 Entity，不读 DTO | 复杂表单两端都要改 |
| **在 Twig 模板硬编码 `maxlength="125"`** | Entity 改了模板没同步，前后端规则不一致 | 删掉硬编码，让 `form_widget()` 自动输出 maxlength |
| **在 FormType 中重复写 `constraints: [new Assert\NotBlank()]`** | 和 Entity 上的规则重复，修改时容易忘改其中一处 | 删掉，依赖 data_class 自动继承 |
| **在前端 JS 里写自己的必填校验逻辑** | 改规则要改两处，容易和服务端脱节 | 只用 JS 做视觉增强，核心规则全靠服务端 |
| **新建字段忘了加 `#[Groups(['xxx_api:write'])]`** | API 提交时字段被静默忽略，报 422 说字段缺失但根本不知道原因 | 字段规则改完后，一定顺便检查 Groups |
| **InvoiceFormDTO 新增字段忘了在 InvoiceFormManager→createInvoiceFromDTO() 里加映射** | 表单填了值，DB 里是空的，且没有任何错误提示 | 改完 DTO 字段，**必须**打开 Manager 检查映射逻辑 |

---

## 九、最终结论

### 9.1 共享层总结（一句话版）

> **简单实体共用 Entity 层，复杂表单 DTO/Entity 两套独立维护，安装流程完全走专用 DTO。**

### 9.2 修改优先级总结（一句话版）

> **先判断字段属于 A/B/C 哪一类，真理源永远是带 `#[Assert]` 的 PHP 类（Entity 或 DTO），前端模板和 FormType 永远排在最后改。复杂表单（Invoice/Quote）是最容易漏改的场景，必须同时检查 DTO 断言 + Entity 断言 + Manager 映射三处代码。**

---

_报告生成时间：2026-06-12_  
_分析代码版本：SolidInvoice 3.0.0-dev_
