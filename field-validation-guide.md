# SolidInvoice 字段校验规则 — 最终修改指南

> **一份文件解决所有问题**：当需要修改字段的必填性、长度限制、格式校验、API 可见性时，按此手册逐层排查。快速定位改动点 → 按步骤修改 → 用验证清单收尾。
>
> **最后更新**：2026-06-12　|　**代码版本**：SolidInvoice 3.0.0-dev

---

## 第一部分　快速上手（先读这里）

### 一、一页纸速查

#### 1.1 规则定义的六层来源（从高到低）

| 层 | 定义在哪里 | 谁能消费它 | 典型约束 |
|----|-----------|-----------|---------|
| **L1 Entity `#[Assert]`** | Entity 类属性上 | API 校验 + API 文档 + Web 表单（若 FormType 绑定 Entity） | `NotBlank`, `Length`, `Email`, `Url` |
| **L2 DTO `#[Assert]`** | DTO 类属性上 | 仅 Web 表单（若 FormType 绑定 DTO） | 带 `groups` 参数的条件断言 |
| **L3 FormType `'constraints'`** | `buildForm()` 中 `'constraints' => [...]` | 仅该 FormType 渲染的表单字段 | `Callback`, `UserPassword`, 动态字段约束 |
| **L4 validation_groups 闭包** | `configureOptions()` 中 | 决定 L1/L2/L3 中哪些 groups 生效 | `['Default', 'form']`、`['Default', 'smtp']` |
| **L5 Entity `#[Groups]`** | Entity 属性上（Serializer） | 仅 API：控制字段可见/可写 | `client_api:read`, `invoice_api:write` |
| **L6 步骤级约束** | FormFlow `addStep(..., ['constraints'])` | 仅安装/引导的该步骤 | Callback 检查系统需求 |

#### 1.2 核心结论三句话

1. **简单实体（Client/Tax/Contact/Payment）**：API + 表单共用 L1（Entity 上的 Assert），改一处两端自动生效。
2. **复杂表单（Invoice/Quote/Onboarding）**：API 走 Invoice Entity，Web 表单走 InvoiceFormDTO 或 OnboardingData + Manager 手动映射，**两边规则独立维护**，改规则必须两边都查。
3. **浏览器原生校验不是主路径** — `form_widget()` 输出的是 `data-required`（只标红星号，不触发浏览器弹窗），真正的校验在服务端 Symfony Validator。

#### 1.3 修改决策树（文字版）

```
收到需求 → 回答 5 个问题：
  Q1: 字段属于哪类？
    A. 简单实体（Client/Tax/Contact） → 改 Entity 的 #[Assert] + #[ORM] + 生成迁移
    B. 复杂表单（Invoice/Quote）       → 改 Entity（API端）+ 改 DTO（表单端）+ 检查 FormType constraints + 检查 Manager 映射
    C. 安装/Onboarding 引导            → 改 Install DTO / Onboarding DTO（groups 匹配步骤名）+ 检查 Step FormType
    D. 设置页（Mailer/Notification）   → 改 TransportConfigType 的 constraints（groups 匹配 provider 名）
    E. 仅 mapped:false 字段           → 只能改 FormType constraints
    F. 仅 API 可见性                  → 改 Entity 的 #[Groups]

  Q2: 两端都需要吗？（API / Web 表单 / 安装流程）
  Q3: 字段在哪些 #[Groups] 中？
  Q4: FormType 中有没有额外 constraints 叠加？
  Q5: 对应 Manager（如有）的映射正确吗？

改完必做：bin/ecs → bin/phpstan → bin/phpunit → 人工验证 API 文档 + Web 表单
```

---

### 二、24 场景全链路对照表

| 场景 | FormType | data_class | validation_groups | 约束来源层 | 备注 |
|------|----------|-----------|-------------------|-----------|------|
| **Web 创建/编辑 Client** | [ClientType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ClientType.php) | `Client::class` | `['Default', 'form']` | L1(Entity) + L4(组) | contacts 的 `Count(min:1)` 仅 form 组 |
| **API 创建/更新 Client** | — | — | `['Default', 'api']` | L1(Entity) + L5(Groups) | contacts 只读不写 |
| **Web 创建/编辑 Invoice** | [InvoiceType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php) | `InvoiceFormDTO::class` | 闭包: `['Default', 'existing_client' \| 'new_client']` | L2(DTO) + L3(users `NotBlank`) + L4(闭包) | 校验 DTO → Manager 映射 Entity，不二次校验 |
| **API 创建/更新 Invoice** | — | — | Entity 的 validationContext | L1(Invoice Entity) + L5(Groups) | `total` 等 writable:false |
| **Web 创建/编辑 Quote** | QuoteType | `QuoteFormDTO::class` | 闭包: `['Default', 'existing_client' \| 'new_client']` | L2(DTO) + L4(闭包) | 同 Invoice 模式 |
| **API 创建/更新 Quote** | — | — | Entity 的 validationContext | L1(Quote Entity) + L5(Groups) | |
| **Web 创建/编辑 Tax** | TaxType | `Tax::class` | `['Default']` | L1(Entity) | |
| **Web 记录付款** | [PaymentType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php) | 无 | `['Default']` | L3(全量) | amount 含 `Callback(>0)` |
| **Web 客户积分充值** | [CreditType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/CreditType.php#L43) | 无（嵌入式） | `['Default']` | L3(amount `NotBlank`) | Modal 弹窗表单 |
| **Web 联系人详情（邮箱/电话等）** | [ContactDetailType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ContactDetailType.php) | `AdditionalContactDetail::class` | 闭包：按类型动态切换 `not_blank` / `email` | L1(Entity) + L3(type/value) + L4(闭包) | 注解 @TODO: constraints should not be hard-coded |
| **Web 编辑 Profile** | [ProfileType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ProfileType.php) | `User::class` | `['Default']` | L1(name/email) + L3(current_password) | current_password 是 `mapped: false` |
| **Web 修改密码** | [ChangePasswordFormType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php) | 无 | `['Default']` | L3(全量) | `plainPassword` 是 `mapped: false`，RepeatedType |
| **Web 找回密码申请** | [ResetPasswordRequestFormType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php) | 无 | `['Default']` | L3(全量) | |
| **Web 自定义域名** | [CustomDomainType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SaasBundle/Form/Type/CustomDomainType.php) | 无 (parent: TextType) | `['Default']` | L3(FormType 级) | `configureOptions` 中 form-level constraints |
| **邮件设置** | [MailTransportType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php) | 无 | 闭包: `['Default', '{provider小写名}']` | L3(TransportConfig 子表单) + L4(闭包) | 组名 = 用户选择的 provider |
| **通知设置** | [TransportSettingType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/NotificationBundle/Form/Type/TransportSettingType.php) | `TransportSetting::class` | 闭包: `['Default', '{transport小写名}']` | L3(各 Transport Type) + L4(闭包) | |
| **Onboarding 引导流程** | [OnboardingType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/Type/OnboardingType.php) | `OnboardingData::class` | **当前步骤名**（company / client / invoice） | L2(OnboardingData) + L4(闭包) | Session 存储分步数据 |
| **安装-数据库配置** | [DatabaseConfigStep](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/DatabaseConfigStep.php) | `DatabaseConfig::class` | `['Default', 'database_config', 'database_config_{driver}']` | L2(DTO) + L3(port `Type`) + L6(步骤) | |
| **安装-管理员账号** | [UserAccountStep](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/UserAccountStep.php) | `UserAccount::class` | `['Default', 'user_account']` | L2(DTO) + L3(applicationUrl) + L6(步骤) | applicationUrl 是 mapped:false，手动写回 |
| **安装-系统需求** | [SystemRequirementsStep](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/SystemRequirementsStep.php) | 无 | `['Default', 'system_requirements']` | L6(步骤级 Callback) | 检查 PHP 版本/扩展等 |
| **安装-整体** | [InstallationType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php) | `Installation::class` | 闭包: `['Default', '{currentStep}', 'database_config_{driver}']` | L2 + L3 + L4 + L6 | FormFlow 6 步骤 |

---

## 第二部分　六层来源深度解析

### 三、L1 Entity `#[Assert]` — 最常见的共享真理源

**生效范围**：API 校验 + API 文档 + Web 表单（当 FormType 的 data_class 是 Entity 时）

#### 代码位置：[Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L93-L98)

```php
#[ORM\Column(name: 'name', type: Types::STRING, length: 125)]
#[Assert\NotBlank]                          // ← API 和表单都用
#[Assert\Length(max: 125)]                  // ← API 文档 maxLength + 表单 maxlength
#[Serialize\Groups(['client_api:read', 'client_api:write', 'searchable'])]
private ?string $name = null;
```

#### 仅表单生效的组

Client.contacts 用 `groups: ['form']`，API 不校验（REST 中联系人是独立资源）：

[Client.php:146-159](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L146-L159)

```php
#[Assert\Count(min: 1, minMessage: '...', groups: ['form'])]
#[Assert\Valid(groups: ['form'])]
#[Serialize\Groups(['client_api:read'])]
private Collection $contacts;
```

---

### 四、L2 DTO `#[Assert]` — 表单专用的第二真理源

**生效范围**：仅 Web 表单（当 FormType 的 data_class 是 DTO 时）。

#### 4.1 InvoiceFormDTO 条件组（复杂表单）

[InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php#L32-L96)

```php
// 模式1：选择已有客户
#[Assert\NotBlank(groups: ['existing_client'])]
public ?Client $client = null;

// 模式2：内联新建客户（Entity 里根本没有这些字段！）
#[Assert\NotBlank(groups: ['new_client']), Assert\Length(max: 125, groups: ['new_client'])]
public ?string $newClientName = null;

// 通用字段（两端都有，但 Assert 各写一份）
#[Assert\NotBlank]
public string $invoiceId = '';

#[Assert\Count(min: 1), Assert\Valid]
public ArrayCollection $lines;
```

> ⚠️ **关键注意**：DTO 和 Entity 上的 Assert 是**两套独立代码**。修改规则必须两边都查！

#### 4.2 Entity 侧对应规则（API 校验用）

[Invoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php)（`lines`、`users` 等字段处）

```php
#[ORM\OneToMany(...)]
#[Assert\Valid]
#[Assert\Count(min: 1, minMessage: 'You need to add at least 1 line to the Invoice')]
#[Groups(['invoice_api:read', 'invoice_api:write'])]
private Collection $lines;

#[ORM\ManyToMany(...)]
#[Assert\Count(min: 1, minMessage: 'You need to select at least 1 user to attach to the Invoice')]
#[Groups(['invoice_api:read', 'invoice_api:write'])]
private Collection $users;
```

#### 4.3 Onboarding 引导流程 DTO（分步校验）

[OnboardingData.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/DTO/OnboardingData.php#L21-L48)

```php
// Step 1: Company
#[Assert\NotBlank(groups: ['company'])]
public ?string $companyName = null;
#[Assert\NotBlank(groups: ['company'])]
#[Assert\Currency(groups: ['company'])]
public ?string $companyCurrency = 'USD';

// Step 2: Client
#[Assert\NotBlank(groups: ['client'])]
public ?string $clientName = null;

// Step 3: Invoice
#[Assert\NotBlank(groups: ['invoice'])]
#[Assert\Positive(groups: ['invoice'])]
public ?string $invoiceAmount = null;
```

每个步骤的组名**等于步骤名**（company / client / invoice），FormFlow 自动激活。

#### 4.4 安装向导 DTO

[DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php#L24-L43)

```php
#[Callback(callback: 'validate', groups: ['database_config'])]   // 类级回调：真连库测试
final class DatabaseConfig {
    public function __construct(
        #[NotBlank(groups: ['database_config'])]
        public ?string $driver = null,

        // MySQL/MariaDB/PostgreSQL 才需要
        #[NotBlank(groups: ['database_config_mysql', 'database_config_mariadb', 'database_config_pgsql'])]
        public ?string $host = null,
    ) { }
}
```

---

### 五、L3 FormType `'constraints'` — 仅表单生效的额外规则

**⚠️ 重要**：这一层的约束**不会**自动传播到 API 文档或其他表单，只在当前 FormType 生效。

#### 出现原因 + 典型实例（全量清单）

| 原因 | 约束位置 | 字段 | 约束 |
|------|---------|------|------|
| `mapped: false`，Entity 无此字段 | [ProfileType:40-43](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ProfileType.php#L40-L43) | `current_password` | NotBlank + UserPassword |
| `mapped: false` + RepeatedType | [ChangePasswordFormType:35-38](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php#L35-L38) | 新密码 `plainPassword` | NotBlank + Length(8) + PasswordStrength |
| **无 data_class** | [PaymentType:59](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php#L59) | `payment_method` | NotBlank |
| **无 data_class** + 业务规则 | [PaymentType:71-78](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php#L71-L78) | `amount` | NotBlank + Callback(金额>0) |
| **无 data_class** | [ResetPasswordRequestFormType:27-35](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php#L27-L35) | `email` | NotBlank + Email(strict) |
| 弹窗表单专属 | [CreditType:43](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/CreditType.php#L43) | `amount` | NotBlank |
| **DependentField** 动态字段 | [InvoiceType:186](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L186) | `users` | NotBlank（叠加 DTO 的 Count min:1） |
| **ContactDetail** 硬编码（@TODO） | [ContactDetailType:42-44](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ContactDetailType.php#L42-L44) | `type` | NotBlank(groups:`not_blank_type`) |
| **ContactDetail** 硬编码（@TODO） | [ContactDetailType:53-56](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ContactDetailType.php#L53-L56) | `value` | NotBlank(groups:`not_blank`) + Email(groups:`email`) |
| **FormType 级**约束 | [CustomDomainType:27-30](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SaasBundle/Form/Type/CustomDomainType.php#L27-L30) | (整个值字段) | Hostname(+TLD) + NotApplicationUrlHost |
| **动态 Provider 组** | [SmtpTransportConfigType:34](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/MailerBundle/Form/Type/TransportConfig/SmtpTransportConfigType.php#L34) | `host` | NotBlank(groups:`smtp`) |
| **动态 Provider 组** | [SmtpTransportConfigType:42](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/MailerBundle/Form/Type/TransportConfig/SmtpTransportConfigType.php#L42) | `port` | Type(integer, groups:`smtp`) |
| **mapped: false** + 手动写回 | [UserAccountStep:53-56](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/UserAccountStep.php#L53-L56) | `applicationUrl` | NotBlank + Url(http/https) |
| 动态字段（DB 端口） | [DatabaseConfigStep:62](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/DatabaseConfigStep.php#L62) | `port` | Type('integer') |

> **修改建议**：如果规则是**业务规则**（如「付款金额必须 > 0」），应**迁移到 DTO 或 Entity**；FormType 只适合放 UI 专属校验。ContactDetailType 已有 @TODO 标记硬编码问题。

---

### 六、L4 validation_groups 闭包 — 决定哪些规则生效

**四种典型模式**：

#### 模式 A：简单数组（最常见）
```php
// ClientType
'validation_groups' => ['Default', 'form'],
```

#### 模式 B：闭包按条件切换（Invoice/Quote）
[InvoiceType:210-221](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L210-L221)
```php
'validation_groups' => function (FormInterface $form) {
    $data = $form->getData();
    $groups = ['Default'];
    $groups[] = match ($data?->clientMode) {
        InvoiceClientMode::NewClient => 'new_client',
        default => 'existing_client',
    };
    return $groups;
},
```

#### 模式 C：闭包动态生成组名（Mailer/Notification）
[MailTransportType:132](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php#L132)
```php
'validation_groups' => static fn (FormInterface $form) =>
    ['Default', strtolower(str_replace(' ', '_', $form->get('provider')->getData() ?? ''))],
```
选 SMTP → `['Default', 'smtp']`；选 SES → `['Default', 'ses']`。

#### 模式 D：闭包 + 步骤名 + Driver（安装向导 FormFlow）
[InstallationType:103-111](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L103-L111)
```php
'validation_groups' => static function (FormFlowInterface $form) {
    $groups = ['Default', $form->getCursor()->getCurrentStep()];
    if ($form->getCursor()->getCurrentStep() === 'database_config') {
        $groups[] = 'database_config_' . $form->getData()?->databaseConfig?->driver;
    }
    return $groups;
},
```

#### 模式 E：ContactDetailType — 按字段值动态切换（更复杂的闭包）
[ContactDetailType:68-89](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ContactDetailType.php#L68-L89)
```php
'validation_groups' => function (FormInterface $form) {
    // 选了类型但没填值 → not_blank
    // 选的类型是 email → email 组（额外 Email 格式校验）
    // 默认 → not_blank
};
```

#### 模式 F：Skip 按钮完全跳过校验
[OnboardingNavigatorType:60](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/FormFlow/OnboardingNavigatorType.php#L60)
```php
$builder->add('skip', null, [
    'validation_groups' => false,    // ← 点击跳过，完全不校验
]);
```

---

### 七、L5 Entity `#[Groups]` — API 字段可见性开关

**三层 Groups 配置位置**：

#### A. Entity 字段上的 `#[Groups]`

[Client.php:97](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L97)
```php
#[Serialize\Groups(['client_api:read', 'client_api:write', 'searchable'])]
private ?string $name = null;
```

| Group | 含义 |
|-------|------|
| `xxx_api:read` | GET 操作时返回此字段 |
| `xxx_api:write` | POST/PATCH 操作时接受此字段 |
| `searchable` | 搜索 API 中返回 |
| `none` | 永不返回（ContactType 某些字段） |

#### B. ApiResource 级 normalization/denormalization Context

[Invoice.php:70-78](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L70-L78)
```php
#[ApiResource(
    normalizationContext: ['groups' => ['invoice_api:read']],    // GET 返回
    denormalizationContext: ['groups' => ['invoice_api:write']],  // POST/PATCH 接受
)]
```

#### C. 操作级 Context（可覆盖全局）

[ApiToken.php:47-50](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Entity/ApiToken.php#L47-L50)
```php
new Post(
    normalizationContext: ['groups' => ['api_token:read', 'api_token:create_read']],
    // POST 创建成功后返回时多返回 token（只在首次创建时显示）
),
```

#### Client / Invoice / Quote 字段组矩阵

| 字段 | `read` 组 | `write` 组 | 说明 |
|------|:--------:|:---------:|------|
| **Client.id** | ✅ | ❌ | 主键只读 |
| **Client.name** | ✅ | ✅ | 普通字段，可搜索 |
| **Client.contacts** | ✅ | ❌ | 联系人是独立资源，走 `/clients/{id}/contacts` |
| **Client.addresses** | ✅ | ❌ | 同上 |
| **Invoice.status** | ✅ | ❌ | 状态机管理，writable: false |
| **Invoice.total/baseTotal/tax** | ✅ | ❌ | 系统计算，writable: false |
| **Invoice.paidDate** | ✅ | ❌ | 支付完成时自动写入 |
| **Invoice.invoiceId/client/lines** | ✅ | ✅ | 可读写普通字段 |
| **Quote.status/计算字段** | ✅ | ❌ | 同 Invoice |

> ⚠️ **陷阱**：在 `write` 组中的字段 + Entity 有 `NotBlank` → API 不传会 422。不在 `write` 组中的字段，Entity 即使有 NotBlank 也不会报错——因为 API Platform 根本不反序列化它（除了系统自动填充的字段如 status/total）。

### 八、L6 步骤级约束 — FormFlow 专用

**唯一实例**：[InstallationType:56-65](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L56-L65)

```php
$builder->addStep('system_requirements', SystemRequirementsStep::class, [
    'mapped' => false,
    'constraints' => [
        new Callback(function (array $data, ExecutionContextInterface $context): void {
            if (count($this->appRequirements->getFailedRequirements()) > 0) {
                $context->buildViolation('Your system does not meet the minimum requirements...')
                    ->atPath('systemRequirements')->addViolation();
            }
        }),
    ],
]);
```

约束的触发不依赖任何字段，而是检查系统环境。

---

## 第三部分　关键机制深度解析

### 九、复杂表单双层架构：Invoice / Quote

这是最容易出问题的场景，单独一章。

#### Web 表单完整链路

```
用户填表单 → POST → InvoiceType 填充 InvoiceFormDTO
  ↓
$form->isValid()
  ↳ 校验对象：InvoiceFormDTO（不是 Invoice！）
  ↳ 规则来源：DTO 上的 #[Assert] + FormType 'constraints'
  ↳ 组：['Default', 'existing_client'] 或 ['new_client']
  ↓
通过？→ InvoiceFormManager::createInvoiceFromDTO($dto)
         ↓ 纯手动 set，零二次校验
       EntityManager::persist + flush
         ↓
       ❌ 没有第二次 Validator::validate($invoice)！
         ↓
       完成
```

**关键代码**：[InvoiceFormManager](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L40-L78) + [Create.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Action/Create.php#L100-L119)

#### API 完整链路

```
POST /api/invoices → API Platform 反序列化到 Invoice Entity
  ↳ 只反序列化 invoice_api:write 组字段
  ↓
Validation Listener 校验
  ↳ 规则来源：Invoice Entity 上的 #[Assert]
  ↳ 组：validationContext（无显式配置=默认 Default）
  ↓
通过？→ persist + flush → 完成
```

#### 两边规则不一致的典型字段

| 字段 | Web 表单（DTO） | API（Entity） | 原因 |
|------|----------------|---------------|------|
| `client` | 条件必填（existing_client 组） | 始终必填 | 表单有"新建客户"模式 |
| `newClientName` 等新建字段 | DTO 有，new_client 组必填 | ❌ 不存在 | API 必须先创建 Client |
| `users`（联系人） | `Count(min:1, existing_client)` + FormType `NotBlank` | `Count(min:1)` | 语义相同但代码独立 |
| `lines`（行项目） | `Count(min:1) + Valid` | `Count(min:1) + Valid` | 语义相同但代码独立 |

#### 修改 Invoice/Quote 规则的 6 步清单

| 序号 | 检查项 | 在哪里 |
|------|-------|--------|
| 1 | Entity 上的 `#[Assert]` 改了吗？ | Invoice.php + BaseInvoice.php |
| 2 | DTO 上的 `#[Assert]` 改了吗？ | InvoiceFormDTO.php |
| 3 | FormType 中 `'constraints'` 叠加了吗？ | InvoiceType.php（尤其是 users） |
| 4 | Manager 里有对应 `setXxx()` 映射吗？ | InvoiceFormManager::createInvoiceFromDTO() |
| 5 | `#[Groups]` 对吗？（仅影响 API） | Entity 上的 write/read 组 |
| 6 | 迁移生成了吗？ | `#[ORM\Column]` 变更 → `bin/console doctrine:migrations:diff` |

---

### 十、安装 / Onboarding 多步表单机制

#### 10.1 安装向导 6 步骤

| 步骤 | FormType | property_path | 活跃校验组 | 约束来源 |
|------|----------|---------------|-----------|---------|
| `start` | StartStep | mapped:false | `['Default', 'start']` | 无 |
| `system_requirements` | SystemRequirementsStep | mapped:false | `['Default', 'system_requirements']` | L6 步骤级 Callback（PHP 版本/扩展） |
| `database_config` | DatabaseConfigStep | `databaseConfig` | `['Default', 'database_config', 'database_config_{driver}']` | L2(DTO) + L3(port Type) + L6 |
| `user_account` | UserAccountStep | `userAccount` | `['Default', 'user_account']` | L2(DTO) + L3(applicationUrl) |
| `review` | ReviewStep | inherit_data | `['Default', 'review']` | 无额外 |
| `install` | — | inherit_data | `['Default', 'install']` | 无 |
| `finish` | — | mapped:false | `['Default', 'finish']` | 无 |

> 步骤名 == 组名，这是 FormFlow 的核心约定。**改了步骤名必须同步改 DTO 的 groups 参数。**

#### 10.2 Onboarding 引导流程（新用户 3 步）

| 步骤 | FormType | 校验组 | DTO 字段断言 |
|------|----------|--------|-------------|
| `company` | CompanySetupStep | `['Default', 'company']` | companyName NotBlank + companyCurrency NotBlank+Currency |
| `client` | ClientSetupStep | `['Default', 'client']` | clientName NotBlank + clientEmail NotBlank+Email |
| `invoice` | InvoiceSetupStep | `['Default', 'invoice']` | invoiceDescription NotBlank + invoiceAmount NotBlank+Positive |

**特殊能力**：[OnboardingNavigatorType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/FormFlow/OnboardingNavigatorType.php#L60) 的「Skip」按钮配置 `validation_groups: false` → 跳过时完全不校验。

---

### 十一、API 不同操作下的生效规则

#### GET/POST/PATCH/DELETE 差异（以 Invoice 为例）

| 操作 | 读 Groups | 写 Groups | validationContext | 自动校验 |
|------|----------|----------|-------------------|---------|
| `GET /api/invoices` | `invoice_api:read` | N/A | N/A | ❌ |
| `GET /api/invoices/{id}` | `invoice_api:read` | N/A | N/A | ❌ |
| `GET /api/clients/{id}/invoices` | `invoice_api:read` | N/A | N/A | ❌ |
| `POST /api/invoices` | `invoice_api:read` | `invoice_api:write` | `['Default']`（默认） | ✅ |
| `PATCH /api/invoices/{id}` | `invoice_api:read` | `invoice_api:write` | `['Default']` | ✅ |
| `DELETE /api/invoices/{id}` | N/A | N/A | N/A | ❌ |
| 状态迁移 `POST /transitions/{transition}` | `invoice_api:read` | `input: false` | N/A | ❌（无输入） |

#### 子资源路由（Client ↔ Contact）

[Contact.php:102-140](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php#L102-L140) 定义了两组 ApiResource：
- `/clients/{clientId}/contacts`（集合级：获取列表、新建联系人）
- `/clients/{clientId}/contact/{id}`（项级：获取详情、删除、修改）

#### Processor / Provider 模式 — 可能绕过断言

当操作配置了 `processor`，**必须检查处理器中是否有手写校验**：

- [Contact.php:104](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php#L104): `new Post(processor: ContactPersistProcessor::class)`
- [ApiToken.php:47](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Entity/ApiToken.php#L47): `new Post(processor: ApiTokenCreateProcessor::class)`

可能存在「Entity Assert + Processor 逻辑」双重校验，修改时必须都看。

#### 只读字段：`writable: false`

[BaseInvoice.php:36](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php#L36)

```php
#[ApiProperty(
    writable: false,     // ← 即使在 write 组中也强制不可写
    openapiContext: ['type' => 'number'],
)]
protected BigNumber $total;
```

---

### 十二、前端校验真相

#### `data-required` ≠ 原生 required

[fields.html.twig:24](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig#L24)
```twig
{% if required %} data-required="required"{% endif %}
```
`data-*` 前缀是 HTML5 自定义属性，浏览器**不会**触发原生弹窗。只用于 CSS（标红星号）和自定义 JS。

#### novalidate 使用情况

| 页面 | 显式 novalidate |
|------|---------------|
| 用户注册 | ✅ 是 |
| 安装向导 | ✅ 是（FormFlow 自动） |
| 其他业务表单 | ❌ 否（但 data-required 同样不触发原生校验） |

#### 三层真实校验路径（主路径在服务端）

```
L1：Symfony Form Validator（$form->isValid()）
  ↓
L2：Callback / UserPassword / PasswordStrength（自定义断言）
  ↓
L3：Doctrine/DB 层（NOT NULL、外键、唯一索引）—— 最后防线
```

#### 前端增强（纯体验，不决定结果）

| 控制器 | 功能 | 影响结果？ |
|--------|------|-----------|
| [vat-validator-controller.ts](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/assets/controllers/vat-validator-controller.ts) | 异步调 API 检查 VAT | 否，只显示图标，提交时服务端重验 |
| [password-strength-controller.ts](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/assets/controllers/password-strength-controller.ts) | 实时密码强度条 | 否，算法独立，最终看 `#[PasswordStrength]` |
| Live Components（Invoice/Quote） | 切模式/加行时局部刷新，提前显示错误 | 是**预演**服务端校验，不是独立规则 |

---

## 第四部分　操作手册（直接照做）

### 十三、6 个常见场景，每个场景有步骤清单

#### 场景 A：简单实体长度变更（Client.name 125 → 150，非必填）

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | `#[ORM\Column(length: 125)]` → 150, `nullable: true` | Client.php |
| 2 | 删除 `#[Assert\NotBlank]`，改 `Length(max: 150)` | Client.php |
| 3 | 检查 `#[Groups]`（已在 read/write → 无需改） | Client.php |
| 4 | 检查 FormType 中 name 字段有无额外 constraints（无→✓） | ClientType.php |
| 5 | 生成迁移：`bin/console doctrine:migrations:diff` | — |
| 6 | 验证：API 文档 required/maxLength 自动更新 + 表单 maxlength 自动输出 | — |

**改文件数**：1 个（Entity）+ 1 个自动生成迁移 = 2

---

#### 场景 B：Invoice 发票号从必填改可选，长度 255 → 100

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | 删除 `#[Assert\NotBlank]`，加 `Length(max: 100)`（API 端） | Invoice Entity |
| 2 | `#[ORM\Column(length: 255)]` → 100, `nullable: true` | Invoice Entity |
| 3 | 删除 `#[Assert\NotBlank]`，加 `Length(max: 100)`（表单端） | **InvoiceFormDTO.php** |
| 4 | 检查 InvoiceType 的 invoiceId 字段有无额外 constraints（无→✓） | InvoiceType.php |
| 5 | 检查 `createInvoiceFromDTO()` 映射（只做 set → ✓） | InvoiceFormManager.php |
| 6 | 检查 Groups（invoiceId 在 write 组→✓） | Invoice Entity |
| 7 | 生成迁移 | — |

**改文件数**：2（Entity + DTO）+ 1 迁移 = 3

---

#### 场景 C：安装向导密码最少 6 → 10 位

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | `#[Length(min: 6, groups: ['user_account'])]` → min: 10 | UserAccount.php |
| 2 | 检查模板中文案（硬编码的"至少6位"需手动改） | 对应 Twig / 翻译文件 |
| 3 | （可选）同步改其他密码场景 | Registration.php / ChangePasswordFormType.php |

**不需要改**：User Entity、迁移（存 hash，列长固定）、API 配置

---

#### 场景 D：SMTP host 加最大长度 255

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | `'constraints' => [new NotBlank(groups: ['smtp']), new Length(max: 255, groups: ['smtp'])]` | SmtpTransportConfigType.php |
| 2 | 确保 groups 参数匹配 provider 名（smtp） | — |

**不需要改**：Entity（无 data_class）、API 配置

---

#### 场景 E：Payment 金额下限从 0 → 1

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | 修改 Callback：`$value->isZero()` → `$value->isLessThan(Money::USD(100))` | PaymentType.php amount constraints |
| 2 | 同步检查 Payment Entity 上是否有 GreaterThan(0)（若业务规则全局化→考虑加 Entity Assert） | — |

---

#### 场景 F：Invoice 新增字段（最复杂）

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | 加属性 + `#[ORM\Column]` + `#[Assert]` + `#[Groups]` | Invoice Entity |
| 2 | 加属性 + `#[Assert]`（注意 groups） | **InvoiceFormDTO.php** |
| 3 | `buildForm()` 中 `$builder->add('xxx')` | InvoiceType.php |
| 4 | `createInvoiceFromDTO()` 中加 `$invoice->setXxx($dto->xxx)` | **InvoiceFormManager.php** |
| 5 | Twig 中加字段（或 LiveComponent 自动） | 对应模板 |
| 6 | 生成迁移 | — |

> ⚠️ 第 4 步最容易遗漏，不加会出现「表单校验过了 DB 还是空」的诡异 Bug。

---

### 十四、字段来源速查表（按实体）

#### 14.1 Client 字段

| 字段 | Entity Assert | Entity Groups | FormType 额外约束？ |
|-----|--------------|--------------|---------------------|
| `name` | NotBlank + Length(125) | read + write + searchable | 否 |
| `website` | Url + Length(125) | read + write + searchable | 否 |
| `currencyCode` | Length(min:3, max:3) | read + write + searchable | 否 |
| `vatNumber` | — | read + write + searchable | 否 |
| `contacts` | Count(min:1, groups:`form`) + Valid(groups:`form`) | read 仅 | 否（嵌套 CollectionType） |
| `archived` | — | read + write | 否 |

#### 14.2 Invoice 字段（两套独立规则）

| 字段 | InvoiceFormDTO（表单） | Invoice Entity（API） |
|------|----------------------|----------------------|
| `invoiceId` | NotBlank + Length | -（无 NotBlank，但 ORM nullable:false） |
| `client` | NotBlank(groups:`existing_client`) | NotBlank |
| `newClientName` / `newContactEmail` | NotBlank + Length(groups:`new_client`) | ❌ 不存在 |
| `lines` | Count(min:1) + Valid | Count(min:1) + Valid |
| `users` | Count(min:1, groups:`existing_client`) + FormType `NotBlank` | Count(min:1) |
| `discount` / `terms` / `notes` | -（继承 DTO 基类或无显式） | Groups: read + write |

#### 14.3 Onboarding 字段（只在引导流程）

| 字段 | DTO 断言 | 生效组 |
|-----|---------|-------|
| `companyName` | NotBlank | `['company']` |
| `companyCurrency` | NotBlank + Currency | `['company']` |
| `clientName` / `clientEmail` | NotBlank + Email | `['client']` |
| `invoiceAmount` | NotBlank + Positive | `['invoice']` |

---

### 十五、修改优先级（从高到低）

| 优先级 | 层级 | 改动频率 | 影响面 | 注意事项 |
|-------|-----|---------|--------|---------|
| 1️⃣ **最高** | Entity `#[ORM]` + `#[Assert]` | 高 | 两端自动同步 | ORM 和 Assert 必须一起改 |
| 2️⃣ | Entity `validationContext` | 极低 | 整个 Entity 的 API 校验组 | 不要轻易改，影响所有 API 操作 |
| 3️⃣ | Entity `#[Groups]`（Serializer） | 中 | API 字段可见性 | 忘加 write 组会静默丢弃字段 |
| 4️⃣ | 表单专用 DTO `#[Assert]` | 中 | 仅 Web 表单 | Invoice/Quote/Onboarding 必须同步查 Entity |
| 5️⃣ | FormType `constraints` 选项 | 中 | 仅当前表单 | 优先考虑迁移到 DTO/Entity，UI 专属才放这 |
| 6️⃣ | FormFlow 步骤配置 | 低 | 仅该多步流程 | 步骤改名后 DTO 的 groups 必须同步 |
| 7️⃣ | validation_groups 闭包 | 极低 | 仅当前 FormType | 改动后必须测试所有分支路径 |
| 8️⃣ | Twig 模板 | 低 | 仅 UI | **不要硬编码** maxlength/required，用 form_widget |
| 9️⃣ | 前端 Stimulus 控制器 | 极低 | 仅体验 | **永远不要在 JS 写业务校验规则** |

---

### 十六、反模式与陷阱清单

| # | 错误做法 | 后果 | 正确做法 |
|---|---------|------|---------|
| 1 | 改 Entity 忘改 DTO（Invoice 场景） | 表单和 API 规则不一致 | 每次改 Invoice 必 grep `InvoiceFormDTO` |
| 2 | 改 Length 忘改 ORM Column length | DB 层截断，数据丢失 | 两个注解放一起，改的时候同时看到 |
| 3 | 新增字段只加 read 组不加 write 组 | API 不报错，但字段值被静默丢弃 | 加字段顺手就把两个组考虑好 |
| 4 | Twig 硬编码 `maxlength="125"` | Entity 改了模板不同步 | 删掉硬编码，用 `form_widget()` 自动输出 |
| 5 | FormType 重复写 `'constraints' => [new NotBlank()]` | 和 Entity 规则重复，改一处忘另一处 | 删除重复，依赖 data_class 自动继承 |
| 6 | 用 `required: false` 覆盖 Entity 的 NotBlank | DB 层可能仍是 NOT NULL，flush 失败 | 要改变业务规则就改 Entity，别在 FormType 改 |
| 7 | 改了 validation_groups 闭包逻辑 | 某些分支路径校验意外跳过 | 改完必须测试所有条件分支 |
| 8 | DTO 加了 Assert 忘在 Manager 里加 set | 表单校验过了，DB 还是空 | 改完 DTO 必查映射方法 |
| 9 | Mailer/Notification 约束 groups 不匹配 provider 名 | 约束永远不触发 | 组名必须等于 provider/transport 的小写名 |
| 10 | mapped:false 字段的约束写在 Entity/DTO | 约束永远不触发 | mapped:false 的只能放 FormType |
| 11 | 安装/Onboarding DTO groups 不匹配步骤名 | 约束永远不触发 | 步骤名 === 组名，这个是约定 |
| 12 | 在前端 JS 中写业务规则（邮箱格式、长度） | 改规则要改两处，易不一致 | 只做视觉反馈，核心规则全靠服务端 |
| 13 | 改 Processor 操作的字段只改 Assert | Processor 里的手写校验可能仍然拦截 | 改完 Entity 必查对应 Processor 类 |

---

### 十七、修改后验证清单（Checklist）

每次修改完字段规则后，逐一打勾：

- [ ] **L1** 所有相关 Entity/DTO 的 `#[Assert]` 已同步修改
- [ ] **L1** `#[ORM\Column]` 的 length/nullable 已同步（如果有 DB 变更）
- [ ] **L3** 对应的 FormType 没有显式 constraints 覆盖（或已同步）
- [ ] **L4** validation_groups 闭包逻辑和新规则兼容（如果涉及）
- [ ] **L5** `#[Groups]` 配置正确（read/write/searchable）
- [ ] Manager 映射逻辑已更新（仅 Invoice/Quote 等 DTO→Entity 场景）
- [ ] Twig 模板无硬编码规则（删掉硬编码的 maxlength/required）
- [ ] 数据库迁移已生成：`bin/console doctrine:migrations:diff`
- [ ] 清缓存：`bin/console cache:clear`
- [ ] **Web 表单测试**：合法值 / 超限 / 空值 三种边界用例
- [ ] **API 测试**：POST / PATCH / GET 三个操作
- [ ] **API 文档测试**：`/api/docs.json` 中 schema required/maxLength 正确
- [ ] 代码规范：`bin/ecs check --fix` 通过
- [ ] 静态分析：`bin/phpstan analyse` 通过
- [ ] 单元测试：`bin/phpunit` 通过

---

## 附录　代码索引

### A. Entity 真理源

| 文件 | 关键内容 |
|------|---------|
| [Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php) | Default + form + api 三组共存的典型 |
| [Contact.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php) | 显式双组 `['Default', 'form']` |
| [Invoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php) | 多 ApiResource + 操作级 Groups |
| [BaseInvoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php) | 共用字段 + 多处 `writable: false` |
| [Tax.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/TaxBundle/Entity/Tax.php) | 简单实体，无 groups 区分 |
| [ApiToken.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Entity/ApiToken.php) | 操作级 normalizationContext 示例 |

### B. 表单专用 DTO

| 文件 | 关键内容 |
|------|---------|
| [InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php) | existing_client / new_client 条件组 |
| [QuoteFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/QuoteBundle/DTO/QuoteFormDTO.php) | 同 Invoice |
| [OnboardingData.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/DTO/OnboardingData.php) | company / client / invoice 步骤组 |
| [Installation.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/Installation.php) | Valid 嵌套子对象 |
| [DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php) | Callback 真连库测试 |
| [UserAccount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/UserAccount.php) | 安装管理员账号组 |
| [Registration.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/Registration.php) | 注册专用 |
| [ChangePassword.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/ChangePassword.php) | 改密码专用 |

### C. FormType 约束层

| 文件 | 关键内容 |
|------|---------|
| [InvoiceType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php) | DependentField 动态字段 + 闭包切换 |
| [PaymentType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php) | 金额 > 0 Callback |
| [CreditType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/CreditType.php) | Modal 弹窗专用 |
| [ContactDetailType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ContactDetailType.php) | 动态 not_blank/email 组（@TODO 硬编码标记） |
| [ProfileType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ProfileType.php) | current_password mapped:false |
| [ChangePasswordFormType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php) | RepeatedType first_options |
| [ResetPasswordRequestFormType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php) | 非 data_class |
| [CustomDomainType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SaasBundle/Form/Type/CustomDomainType.php) | FormType 级 constraints |
| [MailTransportType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php) | 动态 provider 组名 |
| [SmtpTransportConfigType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/MailerBundle/Form/Type/TransportConfig/SmtpTransportConfigType.php) | SMTP 专用组 |
| [InstallationType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php) | FormFlow 6 步骤 + 动态 validation_groups 闭包 |
| [OnboardingType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/Type/OnboardingType.php) | 3 步骤引导 |
| [OnboardingNavigatorType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/FormFlow/OnboardingNavigatorType.php) | Skip 按钮 `validation_groups: false` |

### D. 映射层（最容易漏改）

| 文件 | 关键内容 |
|------|---------|
| [InvoiceFormManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L40-L78) | DTO→Entity 纯手动 set，零二次校验 |

---

_本手册基于代码库实际走查生成，每次改动核心校验机制后请同步更新。_
