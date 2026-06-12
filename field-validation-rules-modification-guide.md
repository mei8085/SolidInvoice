# SolidInvoice 字段校验规则修改指导手册

> **用途**：当需要修改某个字段的校验规则、长度限制、必填性或 API 可见性时，按此手册逐层排查，确保所有相关位置都同步修改。
> **最后更新**：2026-06-12
> **代码版本**：SolidInvoice 3.0.0-dev

---

## 一、字段校验规则的六层来源体系

SolidInvoice 的字段规则不是单一来源，而是 **六层叠加体系**。修改时需从第 1 层向下排查，确认是否需要同步修改。

```
第 6 层：API 操作级 validationContext（校验组过滤）
       ↓ 决定哪些校验组在什么操作下生效
第 5 层：API 读写组 Groups（字段可见性过滤）
       ↓ 决定字段在 GET/POST/PATCH 时是否出现
第 4 层：FormFlow 步骤约束（多步表单专属）
       ↓ 安装向导/Onboarding 的步骤级附加约束
第 3 层：FormType 直接定义 constraints
       ↓ 非 mapped 字段、动态字段的约束
第 2 层：DTO 层 Assert（表单专属数据对象）
       ↓ 复杂业务表单、安装流程专用
第 1 层：Entity 层 Assert（领域模型真理源）
       ↓ 简单实体 API + 表单共用
```

---

## 二、各层详细说明与代码实例

### 第 1 层：Entity 层 Assert（领域模型真理源）

**适用场景**：Client、Tax、Contact、Payment 等简单实体，API 和 Web 表单共享同一套规则。

#### 代码位置：[Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L93-L98)

```php
#[ORM\Column(name: 'name', type: Types::STRING, length: 125)]
#[Assert\NotBlank]                          // ← 两端共用：必填
#[Assert\Length(max: 125)]                  // ← 两端共用：最大长度
#[Serialize\Groups(['client_api:read', 'client_api:write', 'searchable'])]
private ?string $name = null;
```

#### 另一处关键：按组区分的规则（仅表单生效）

[Client.php:146-159](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L146-L159)

```php
#[Assert\Count(
    min: 1,
    minMessage: 'You need to add at least one contact to this client',
    groups: ['form'],          // ← 仅 form 组生效，API 不走这组
)]
#[Assert\Valid(groups: ['form'])]
#[Serialize\Groups(['client_api:read'])]   // ← API 只读不可写
private Collection $contacts;
```

#### 修改时必须同步改动的位置：
| 修改内容 | 需改位置 |
|---------|---------|
| 必填/非必填 | `#[Assert\NotBlank]` 去掉或加上 |
| 最大长度 | `#[Assert\Length(max: N)]` + `#[ORM\Column(length: N)]` 两处都要改，然后生成迁移 |
| 格式校验（邮箱/URL等） | 增加或修改 `#[Assert\Email]` / `#[Assert\Url]` |
| 数字范围 | `#[Assert\Range(min: 0, max: 100)]` |

---

### 第 2 层：DTO 层 Assert（表单专属数据对象）

**适用场景**：Invoice/Quote 新建/编辑表单、安装向导、注册/找回密码、Onboarding 流程。这些表单比 Entity 更复杂，有条件字段、模式切换。

#### 典型代表 1：InvoiceFormDTO（发票表单）

[InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php#L32-L86)

```php
// 模式1：选择已有客户
#[Assert\NotBlank(groups: ['existing_client'])]
public ?Client $client = null;

// 模式2：内联新建客户（Entity 里根本没有这些字段！）
#[Assert\NotBlank(groups: ['new_client'])]
#[Assert\Length(max: 125, groups: ['new_client'])]
public ?string $newClientName = null;

// 通用字段（与 Entity 字段名相同，但规则独立定义）
#[Assert\NotBlank]
public string $invoiceId = '';

#[Assert\Count(min: 1)]
#[Assert\Valid]
public ArrayCollection $lines;

#[Assert\Count(min: 1, groups: ['existing_client'])]
public ArrayCollection $users;
```

**⚠️ 关键注意**：DTO 上的规则和 Invoice Entity 上的规则是**两套独立代码**，修改时必须两边同步！

#### Invoice Entity 侧对应规则 [Invoice.php:205-222](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L205-L222)

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

#### 典型代表 2：OnboardingData（引导流程）

[OnboardingData.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/DTO/OnboardingData.php#L21-L48)

```php
// Step 1: Company
#[Assert\NotBlank(groups: ['company'])]
public ?string $companyName = null;

#[Assert\NotBlank(groups: ['company'])]
#[Assert\Currency(groups: ['company'])]
public ?string $companyCurrency = 'USD';

// Step 2: Client (optional)
#[Assert\NotBlank(groups: ['client'])]
public ?string $clientName = null;

// Step 3: Invoice (optional)
#[Assert\NotBlank(groups: ['invoice'])]
#[Assert\Positive(groups: ['invoice'])]
public ?string $invoiceAmount = null;
```

每个步骤的校验组名**等于步骤名**（company、client、invoice），由 FormFlow 自动按当前步骤激活。

#### 典型代表 3：DatabaseConfig（安装向导数据库配置）

[DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php#L24-L43)

```php
#[Callback(callback: 'validate', groups: ['database_config'])]   // ← 整个类级别的回调
final class DatabaseConfig
{
    public function __construct(
        #[NotBlank(groups: ['database_config'])]
        public ?string $driver = null,

        // MySQL/MariaDB/PostgreSQL 才需要
        #[NotBlank(groups: ['database_config_mysql', 'database_config_mariadb', 'database_config_pgsql'])]
        public ?string $host = null,

        #[NotBlank(groups: ['database_config_mysql', 'database_config_mariadb', 'database_config_pgsql'])]
        public ?string $name = 'solidinvoice',
    ) { }

    // 回调方法：尝试真正连接数据库
    public static function validate(self $data, ExecutionContextInterface $context): void { ... }
}
```

---

### 第 3 层：FormType 直接定义 constraints

**⚠️ 重要：这一层的约束**不会**自动传播到 API 文档或其他表单！**只在当前 FormType 生效。**

**适用场景**：
1. `'mapped' => false` 的字段（不映射到 data_class）
2. 动态字段（DependentField）
3. 表单级别的特殊校验逻辑

#### 典型实例清单：

| 约束位置 | 字段 | 约束内容 | 为何不放到 Entity/DTO |
|---------|------|---------|---------------------|
| [ResetPasswordRequestFormType.php:27-35](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php#L27-L35) | `email` | NotBlank + Email | DTO 是 User 实体，但找回密码不需要改 User 的断言 |
| [ProfileType.php:40-43](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ProfileType.php#L40-L43) | `current_password` | NotBlank + UserPassword | 非 mapped 字段，Entity 里没有 |
| [ChangePasswordFormType.php:35-38](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php#L35-L38) | 新密码 | NotBlank + Length(min:8) + PasswordStrength | 非 mapped 字段 |
| [PaymentType.php:59](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php#L59) | `paymentMethod` | NotBlank | 动态可选列表，放到 Entity 不合适 |
| [PaymentType.php:71-78](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php#L71-L78) | `amount` | NotBlank + Callback(正数) | 金额 > 0，放到 Entity 不合适（退款场景可能为负） |
| [InvoiceType.php:186](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L186) | `users`（DependentField） | NotBlank | 动态字段，依赖 client 选择，直接写在 FormType 里 |
| [InstallationType.php:56-65](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L56-L65) | system_requirements 步骤 | Callback 检查系统要求 | 纯表单级检查，无对应字段 |
| [CreditType.php:43](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/CreditType.php#L43) | `amount` | NotBlank | 弹窗表单专属 |
| [ContactDetailType.php:42-44](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ContactDetailType.php#L42-L44) | `type` | NotBlank(groups: 'not_blank_type') | 动态组名 |
| [ContactDetailType.php:53-56](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ContactDetailType.php#L53-L56) | `value` | NotBlank(groups: 'not_blank') + Email(groups: 'email') | 不同类型用不同校验组 |
| [CustomDomainType.php:27-30](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SaasBundle/Form/Type/CustomDomainType.php#L27-L30) | 整个类 | Hostname + NotApplicationUrlHost | 类级别约束 |
| [DatabaseConfigStep.php:62](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/DatabaseConfigStep.php#L62) | `port` | Type('integer') | 动态字段（选择 MySQL 后才出现） |

#### 代码示例：PaymentType 中正则约束

[PaymentType.php:71-78](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php#L71-L78)

```php
'constraints' => [
    new Assert\NotBlank(),
    new Assert\Callback(function (BigNumber $value, ExecutionContextInterface $context): void {
        if ($value->isZero() || $value->isNegative()) {
            $context->buildViolation('This value should be greater than {{ compared_value }}.')
                ->setParameter('{{ compared_value }}', '0')
                ->addViolation();
        }
    }),
],
```

#### 修改提醒：
如果规则是**业务规则**（如「付款金额必须 > 0」），应该**迁移到 DTO 或 Entity 上**，而不是永远埋在 FormType 里。FormType 只适合放**UI 专属**的校验。

---

### 第 4 层：FormFlow 多步表单的步骤约束

**适用场景**：安装向导（InstallationType）、新用户 Onboarding（OnboardingType）。

这是一套比普通表单更复杂的机制：数据分步存储在 Session 中，校验组按当前步骤名动态生成。

#### 4.1 安装向导步骤机制 [InstallationType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php)

```php
final class InstallationType extends AbstractFlowType
{
    public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
    {
        $builder->addStep(name: 'start',           type: StartStep::class);
        $builder->addStep(name: 'system_requirements', type: SystemRequirementsStep::class, options: [
            'mapped' => false,
            'constraints' => [
                new Callback(function (array $data, ExecutionContextInterface $context): void {
                    // 检查系统要求是否满足
                }),
            ],
        ]);
        $builder->addStep(name: 'database_config', type: DatabaseConfigStep::class, options: [
            'property_path' => 'databaseConfig',   // ← 映射到 DTO 子对象
        ]);
        $builder->addStep(name: 'user_account',    type: UserAccountStep::class, options: [
            'property_path' => 'userAccount',      // ← 映射到 DTO 子对象
        ]);
        $builder->addStep(name: 'review', options: ['inherit_data' => true]);
        $builder->addStep(name: 'install', options: ['inherit_data' => true]);
        $builder->addStep(name: 'finish', options: ['mapped' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Installation::class,
            'data_storage' => new SessionDataStorage('installation_flow', $this->requestStack),
            'step_property_path' => 'currentStep',

            // ↓↓↓ 核心：动态校验组 ↓↓↓
            'validation_groups' => static function (FormFlowInterface $form) {
                // 规则：Default + 当前步骤名 + 数据库driver名（如果是数据库步骤）
                $groups = ['Default', $form->getCursor()->getCurrentStep()];

                if ($form->getCursor()->getCurrentStep() === 'database_config') {
                    $groups[] = 'database_config_' . $form->getData()->databaseConfig->driver;
                }

                return $groups;
            },
        ]);
    }
}
```

**按步骤激活的校验组：**

| 步骤 | 激活的校验组 | 校验内容 |
|-----|-------------|---------|
| `system_requirements` | `['Default', 'system_requirements']` | Callback 检查 PHP 版本/扩展 |
| `database_config` (选 MySQL) | `['Default', 'database_config', 'database_config_mysql']` | driver 必填 + host/name 必填 + 真连库测试 |
| `database_config` (选 SQLite) | `['Default', 'database_config']` | 只需 driver 必填（host/name 不校验） |
| `user_account` | `['Default', 'user_account']` | 姓名/邮箱/密码 必填 |
| `review` | `['Default', 'review']` | 继承数据，展示摘要 |

#### 4.2 Onboarding 引导流程机制 [OnboardingType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/Type/OnboardingType.php)

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        'data_class' => OnboardingData::class,
        'data_storage' => new SessionDataStorage('user_onboarding', $this->requestStack),
        'step_property_path' => 'currentStep',
        // 没有显式配置 validation_groups，默认就是当前步骤名
    ]);
}
```

配合 [OnboardingData.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/DTO/OnboardingData.php) 中的 `groups: ['company']` / `groups: ['client']` / `groups: ['invoice']` 实现分步校验。

#### 4.3 特殊按钮级的 validation_groups

[OnboardingNavigatorType.php:60](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/FormFlow/OnboardingNavigatorType.php#L60) 中的「Skip」按钮：

```php
$builder->add('skip', null, [
    'validation_groups' => false,   // ← 点击跳过按钮时，完全不校验
    'handler' => function (mixed $data, ButtonFlow $button, FormFlow $flow): void {
        // 直接跳到最后一步
    },
]);
```

---

### 第 5 层：API 读写组 Groups（字段可见性过滤）

`#[Groups]` 决定字段在 API 中的可见性和可写性，**不影响校验规则本身**，但决定哪些字段会被校验。

#### 三层 Groups 配置位置：

##### A. Entity 字段上的 `#[Groups]`

[Client.php:97](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L97)

```php
#[Serialize\Groups(['client_api:read', 'client_api:write', 'searchable'])]
private ?string $name = null;
```

| Group | 含义 |
|-------|------|
| `client_api:read` | GET 操作时返回此字段 |
| `client_api:write` | POST/PATCH 操作时接受此字段 |
| `searchable` | 搜索 API 中返回 |
| `none` | 永远不返回（ContactType 中 `type` 字段用了这个） |

##### B. ApiResource 级 normalization/denormalization Context

[Invoice.php:70-77](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L70-L77)

```php
#[ApiResource(
    operations: [new GetCollection(), new Get(), new Post(), new Patch(), new Delete()],
    normalizationContext: [
        'groups' => ['invoice_api:read'],        // ← GET 返回的字段组
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
    denormalizationContext: [
        'groups' => ['invoice_api:write'],       // ← POST/PATCH 接受的字段组
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
)]
```

##### C. 操作级 normalization/denormalization Context（可覆盖全局）

[ApiToken.php:47-50](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Entity/ApiToken.php#L47-L50)

```php
new Post(
    processor: ApiTokenCreateProcessor::class,
    normalizationContext: ['groups' => ['api_token:read', 'api_token:create_read']],
    // ↓↓↓ POST 操作返回时多返回 token 值（首次创建才显示）
),
```

#### 常见字段组模式对比表：

| 字段 | Groups 配置 | GET | POST | PATCH | 说明 |
|-----|------------|-----|------|-------|------|
| `id` | `['invoice_api:read', 'searchable']` | ✅ 可见 | ❌ 忽略 | ❌ 忽略 | 主键只读 |
| `name` | `['client_api:read', 'client_api:write']` | ✅ 可见 | ✅ 可写 | ✅ 可改 | 普通字段 |
| `status` | `['invoice_api:read']` + `writable: false` | ✅ 可见 | ❌ 忽略 | ❌ 忽略 | 状态由状态机管理 |
| `currencyCode` | `['client_api:read', 'client_api:write']` | ✅ 可见 | ✅ 可写 | ✅ 可改 | 可读写 |
| `contacts` | `['client_api:read']` | ✅ 可见（嵌套对象） | ❌ 忽略 | ❌ 忽略 | 联系人是独立资源 |
| `total` | `['invoice_api:read']` + `writable: false` | ✅ 可见 | ❌ 忽略 | ❌ 忽略 | 计算字段，自动汇总 |
| `vatNumber` | `['client_api:read', 'client_api:write', 'searchable']` | ✅ 可见 | ✅ 可写 | ✅ 可改 | 可搜索字段 |

#### 修改提醒：
如果新增字段但忘记加 `xxx_api:write` 组，**API 会静默忽略该字段**，可能出现「表单提交正常但 API 提交提示字段缺失」或「API 提交后字段为空」的诡异问题。

---

### 第 6 层：API validationContext（校验组过滤）

决定 API 调用时哪些校验组会被执行，实现「API 和表单规则差异化」。

#### 代码实例：[Client.php:70-72](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L70-L72)

```php
#[ApiResource(
    // ...
    validationContext: [
        'groups' => ['Default', 'api'],     // ← API 校验只走这两个组
    ],
)]
```

而 FormType 对应的校验组是：
[ClientType.php:117](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ClientType.php#L117)

```php
'validation_groups' => ['Default', 'form'],   // ← 表单校验走这两个组
```

#### 差异化效果对比：

| 断言 | 组配置 | API 校验（`['Default','api']`） | Web 表单校验（`['Default','form']`） |
|-----|---------|-------------------------------|------------------------------------|
| `#[Assert\NotBlank]` | 无 groups（=Default） | ✅ 执行 | ✅ 执行 |
| `#[Assert\NotBlank(groups: ['Default','form'])]` | 显式双组 | ✅ 执行 | ✅ 执行 |
| `#[Assert\Count(min:1, groups: ['form'])]` | 仅 form 组 | ❌ 不执行 | ✅ 执行 |
| `#[Assert\Length(max:125, groups: ['api'])]` | 仅 api 组 | ✅ 执行 | ❌ 不执行 |

#### 注意：Invoice/Quote 没有显式配置 validationContext

[Invoice.php:68-78](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L68-L78) 中没有 `validationContext`，所以 API 校验用默认的 `['Default']` 组。

---

## 三、API 不同操作（GET/POST/PATCH/DELETE）下的生效规则

### 3.1 操作级差异总览（以 Invoice 为例）

[Invoice.php:68-115](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L68-L115)

| 操作 | 读 Groups | 写 Groups | validationContext 校验组 | 自动校验 |
|-----|----------|----------|-------------------------|---------|
| `GET /api/invoices` | `invoice_api:read` | N/A | N/A | ❌ 不校验 |
| `GET /api/invoices/{id}` | `invoice_api:read` | N/A | N/A | ❌ 不校验 |
| `GET /api/clients/{id}/invoices` | `invoice_api:read` | N/A | N/A | ❌ 不校验 |
| `POST /api/invoices` | `invoice_api:read` | `invoice_api:write` | `['Default']`（无显式配置） | ✅ 校验写组中的字段 |
| `PATCH /api/invoices/{id}` | `invoice_api:read` | `invoice_api:write` | `['Default']` | ✅ 校验写组中的字段 |
| `DELETE /api/invoices/{id}` | N/A | N/A | N/A | ❌ 不校验 |
| `POST /api/invoices/{id}/transitions/{transition}` | `invoice_api:read` | `input: false`（无输入） | N/A | ❌ 输入为 false，跳过校验 |

### 3.2 特殊操作：子资源路由（URI 模板）

[Contact.php:102-140](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php#L102-L140)

```php
#[ApiResource(
    uriTemplate: '/clients/{clientId}/contacts',
    operations: [ new GetCollection(), new Post(processor: ContactPersistProcessor::class) ],
    normalizationContext: [ 'groups' => ['contact_api:read'] ],
    denormalizationContext: [ 'groups' => ['contact_api:write'] ],
)]
#[ApiResource(
    uriTemplate: '/clients/{clientId}/contact/{id}',
    operations: [new Get(), new Delete(), new Patch()],
    normalizationContext: [ 'groups' => ['contact_api:read'] ],
    denormalizationContext: [ 'groups' => ['contact_api:write'] ],
)]
```

### 3.3 特殊操作：处理器（Processor/Provider）模式

当操作配置了 `processor` 或 `provider`，校验规则可能被绕过或增强：

[Contact.php:104](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php#L104)
```php
new Post(processor: ContactPersistProcessor::class)
```

[ApiToken.php:47-48](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Entity/ApiToken.php#L47-L48)
```php
new Post(processor: ApiTokenCreateProcessor::class)
```

修改这类操作的字段规则时，**必须检查 Processor 中是否有手写的校验/处理逻辑**，可能存在「断言 + 处理器逻辑」双重校验。

### 3.4 只读字段：writable: false

[BaseInvoice.php:36](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php#L36)

```php
#[ApiProperty(
    writable: false,     // ← 即使在写组中，也强制不可写
    openapiContext: ['type' => 'number'],
)]
protected BigNumber $total;
```

这类字段即使在 `xxx_api:write` 组中，API Platform 也会拒绝写入。

---

## 四、字段规则修改决策流程（按场景）

### 4.0 修改前必问的 5 个问题

```
1. 这个字段属于 A（简单实体）、B（复杂表单）、C（专用流程）哪一类？
2. 规则需要在 API 生效、Web 表单生效，还是两端都生效？
3. 字段在哪些 #[Groups] 中？
4. FormType 中有没有独立定义 constraints 覆盖了 data_class 的规则？
5. 对应的 DTO（如有）上有没有独立定义的规则？
```

---

### 4.1 场景 A：简单实体字段（Client/Tax/Contact）

**示例需求**：`Client.name` 最大长度从 125 改为 150，且允许为空（非必填）。

```
Step 1：修改 Entity（真理源）
    [Client.php]
    ① #[ORM\Column(length: 125)] → 150
    ② 删除 #[Assert\NotBlank]（允许为空）
    ③ #[Assert\Length(max: 125)] → 150
    ④ 检查 #[Groups]：name 在 client_api:read/write 中，无需改

Step 2：检查 FormType 中是否有覆盖
    [ClientType.php]
    → 搜索 $builder->add('name', ...)
    → 如果 options 中有 'constraints' 或 'required' 显式设置，需同步修改
    → ClientType 中 name 字段没有显式 constraints，✓ 自动继承

Step 3：检查 Twig 模板是否有硬编码
    [ClientForm.html.twig]
    → 搜索 maxlength="125" 或 required="required"
    → 如果有，删掉硬编码，让 form_widget() 自动输出

Step 4：生成数据库迁移
    bin/console doctrine:migrations:diff
    bin/console doctrine:migrations:migrate

Step 5：检查 API 文档
    → 访问 /api/docs.json，确认 name 字段：
      - required 数组中已不包含 name
      - maxLength 变为 150

Step 6：运行测试
    bin/phpunit src/ClientBundle/Tests
    bin/phpstan analyse
    bin/ecs check --fix
```

**需要同步修改的文件数**：1（Entity）+ 1（迁移自动生成）= 2 处

---

### 4.2 场景 B：复杂表单字段（Invoice/Quote）

**示例需求**：`invoiceId`（发票号）从必填改为可选，最大长度从 255 改为 100。

**⚠️ 这是最容易漏改的场景！** Invoice 表单用的是 InvoiceFormDTO，API 用的是 Invoice Entity，两套规则独立。

```
Step 1：修改 InvoiceFormDTO（表单真理源）
    [InvoiceFormDTO.php]
    ① 删除或注释掉 #[Assert\NotBlank]
    ② 增加 #[Assert\Length(max: 100)]（或修改已有）

Step 2：修改 Invoice Entity（API 真理源 + DB）
    [Invoice.php]
    ① #[ORM\Column(length: 255)] → 100, nullable: true
    ② 删除对应 #[Assert\NotBlank]（如果有）
    ③ 增加 #[Assert\Length(max: 100)]（如果有）
    ④ 检查 #[Groups]：invoiceId 在 invoice_api:read/write 中，✓

Step 3：检查 FormType 中是否有覆盖
    [InvoiceType.php]
    → 搜索 $builder->add('invoiceId', ...)
    → 第 166 行：$builder->add('invoiceId', null, ['data' => $data]);
    → 没有显式 constraints，✓ 自动继承 DTO 规则

Step 4：检查 InvoiceFormManager 映射
    [InvoiceFormManager.php]
    → 第 49 行：$invoice->setInvoiceId($dto->invoiceId);
    → 映射存在，✓ 无需改

Step 5：检查 DTO→Entity 的空值处理
    → 如果 invoiceId 为空时 setInvoiceId('') 可能仍存空串，需确认逻辑是否符合需求

Step 6：生成迁移
    bin/console doctrine:migrations:diff

Step 7：检查两端
    Web 表单：空值是否能提交成功？长度超限是否报错？
    API：POST /api/invoices 不带 invoiceId 是否能成功？超长是否 422？
```

**需要同步修改的文件数**：2（DTO + Entity）+ 1（迁移自动生成）= 3 处

---

### 4.3 场景 C：安装/注册/找回密码等专用流程

**示例需求**：安装流程中管理员密码最少 6 位改为最少 10 位。

```
Step 1：确认字段属于哪套 DTO
    安装流程 → [UserAccount.php]
    用户改密码 → [ChangePassword.php]
    用户注册 → [Registration.php]

Step 2：修改专用 DTO
    [UserAccount.php:33-35]
    - #[Length(min: 6, groups: ['user_account'])]
                         ↑
                 改为 min: 10

Step 3：检查对应 FormType/Step
    [UserAccountStep.php]
    → 没有直接定义 constraints，✓ 自动继承

Step 4：检查前端提示文案
    [step_user_account.html.twig] 或翻译文件
    → 如果有 "密码至少 6 位" 的提示文字，需同步修改（这是文案，不是规则）

Step 5：检查其他相关 DTO 是否也要改
    → Registration 中的密码规则是 min: 8，用的是 PasswordStrength
    → ChangePassword 中的密码规则是 min: 8
    → 根据产品需求决定是否同步修改其他场景

Step 6：不需要做的事
    ❌ 不用改 User Entity（除非登录用户改密码规则也要同步）
    ❌ 不用生成迁移（password 存的是 hash，DB 字段长度固定）
    ❌ 不用改 API 配置（安装流程没有 API）
```

**需要同步修改的文件数**：1（DTO）+ 1（前端文案可选）= 1~2 处

---

### 4.4 场景 D：FormType 中直接定义的约束

**示例需求**：付款金额必须大于 0 改为必须大于等于 0（允许 0 元付款）。

```
Step 1：定位 FormType 中的约束
    [PaymentType.php:71-78]
    - new Assert\Callback(function (BigNumber $value, ...) {
        if ($value->isZero() || $value->isNegative()) { ... }
                           ↑
                  去掉 isZero() 判断
      });

Step 2：考虑是否应迁移到 DTO/Entity
    → 如果这个规则是业务规则（所有付款场景都必须 ≥ 0），应移到 Payment Entity 上
    → 如果只是这个表单的 UI 规则，保留在 FormType

Step 3：检查 API 侧对应规则
    → POST /api/payments 的金额校验在 Payment Entity 上
    → 同步检查 Payment Entity 上是否有对应的 Assert\GreaterThan(0)

Step 4：修改 Entity 侧（如果判定为业务规则）
    [Payment Entity]
    加上 #[Assert\GreaterThanOrEqual(0)]
```

---

### 4.5 场景 E：API 可见性修改（新增/隐藏字段）

**示例需求**：新增 `Client.vatNumber` 字段，允许 API 读写，Web 表单上也可编辑。

```
Step 1：修改 Entity
    [Client.php]
    ① 加字段定义 + #[ORM\Column]
    ② 加 #[Assert\Length(max: 30)] 等校验
    ③ 加 #[Groups(['client_api:read', 'client_api:write', 'searchable'])]
                    ↑           ↑
            GET 时返回   POST/PATCH 时接受

Step 2：修改 FormType
    [ClientType.php] buildForm() 中添加：
    $builder->add('vatNumber', null, ['required' => false]);

Step 3：修改 Twig 模板
    [ClientForm.html.twig] 添加对应字段的 form_row()

Step 4：检查 validationContext
    → vatNumber 的 Assert 没有指定 groups → Default 组
    → API 校验用 ['Default', 'api'] → ✓ 生效
    → 表单校验用 ['Default', 'form'] → ✓ 生效

Step 5：生成迁移
```

---

## 五、字段规则来源速查表（按字段）

### 5.1 Client 字段

| 字段 | Entity Assert | Entity Groups | FormType 有额外约束？ |
|-----|--------------|--------------|----------------------|
| `name` | NotBlank + Length(max:125) | read + write + searchable | 否 |
| `website` | Url + Length(max:125) | read + write + searchable | 否 |
| `status` | - | read + searchable | 否 |
| `currencyCode` | Length(min:3, max:3) | read + write + searchable | 否 |
| `vatNumber` | - | read + write + searchable | 否 |
| `contacts` | Count(min:1, groups:['form']) + Valid(groups:['form']) | read（只读到列表） | 否（用 CollectionType 嵌套） |

### 5.2 Invoice 字段（两套独立规则）

| 字段 | InvoiceFormDTO（表单） | Invoice Entity（API） |
|-----|----------------------|----------------------|
| `invoiceId` | NotBlank | -（无 NotBlank，但 ORM nullable: false） |
| `client` | NotBlank(groups:['existing_client']) | NotBlank |
| `newClientName` | NotBlank + Length(125) (groups:['new_client']) | ❌ 不存在此字段 |
| `newContactEmail` | NotBlank + Email (groups:['new_client']) | ❌ 不存在此字段 |
| `lines` | Count(min:1) + Valid | Count(min:1) + Valid |
| `users` | Count(min:1, groups:['existing_client']) | Count(min:1) |
| `discount` | -（继承 BaseInvoice） | -（继承 BaseInvoice） |
| `terms` | -（继承 BaseInvoice） | Groups: read + write |
| `notes` | -（继承 BaseInvoice） | Groups: read + write |

### 5.3 Onboarding 字段（只在引导流程生效）

| 字段 | DTO 断言 | 生效组 |
|-----|---------|-------|
| `companyName` | NotBlank | `['company']` |
| `companyCurrency` | NotBlank + Currency | `['company']` |
| `clientName` | NotBlank | `['client']` |
| `clientEmail` | NotBlank + Email | `['client']` |
| `invoiceDescription` | NotBlank | `['invoice']` |
| `invoiceAmount` | NotBlank + Positive | `['invoice']` |

---

## 六、修改优先级总结（从高到低）

| 优先级 | 层级 | 改动频率 | 改动后影响面 | 注意事项 |
|-------|-----|---------|------------|---------|
| 1️⃣ **最高** | Entity `#[ORM]` + `#[Assert]` | 高 | 两端自动同步 | 必须同时改 ORM 和 Assert |
| 2️⃣ | Entity `#[Groups]` | 中 | API 字段可见性 | 忘加 write 组 API 会静默忽略 |
| 3️⃣ | Entity `validationContext` | 低 | API 校验组过滤 | 不要轻易改动，会影响整个 API |
| 4️⃣ | 表单专用 DTO `#[Assert]` | 中 | 仅 Web 表单 | Invoice/Quote 必须和 Entity 同步改 |
| 5️⃣ | FormType `constraints` 选项 | 中 | 仅当前表单 | 优先考虑移到 DTO/Entity，除非是 UI 专属 |
| 6️⃣ | FormFlow 步骤配置 | 低 | 仅当前多步流程 | 步骤改名后对应 DTO 的 groups 也要改 |
| 7️⃣ | FormFlow `validation_groups` 闭包 | 极低 | 仅当前多步流程 | 一般不用改 |
| 8️⃣ | Twig 模板 | 低 | 仅 UI 表现 | 不要硬编码 maxlength/required，用 form_widget |
| 9️⃣ | 前端 Stimulus 控制器 | 极低 | 仅体验增强 | 永远不要在 JS 中写业务校验规则 |

---

## 七、常见错误与反模式

| 错误做法 | 后果 | 正确做法 |
|---------|------|---------|
| 改了 Entity 忘了改 DTO（Invoice 场景） | 表单和 API 规则不一致 | 每次改 Invoice 字段都 grep `InvoiceFormDTO` |
| 改了 Length 忘改 ORM Column length | DB 层会截断，导致数据丢失 | 两个注解放一起，改的时候同时看到 |
| 新增字段忘了加 `xxx_api:write` 组 | API 不返回错误，但字段值被静默丢弃 | 加完字段顺手检查 Groups |
| 在 Twig 硬编码 `maxlength="125"` | Entity 改了模板不同步 | 删除硬编码，让 form_widget() 自动输出 |
| 在 FormType 重复写 `'constraints' => [new NotBlank()]` | 和 Entity 规则重复，改一处忘另一处 | 删除重复，依赖 data_class 自动继承 |
| 用 `#[Groups(['none'])]` 隐藏字段却没检查其他影响 | 某些场景下字段意外缺失 | 优先用 `writable: false` 或干脆去掉 Groups |
| 修改 `validation_groups` 闭包逻辑 | 导致某些场景下校验意外跳过 | 改动后测试所有分支路径 |
| 在前端 JS 中写自己的邮箱格式校验 | 改规则要改两处，易不一致 | 只做视觉反馈，核心规则全靠服务端 |
| 用 `required: false` 在 FormType 中覆盖 Entity 的 `NotBlank` | 数据库层可能仍 NOT NULL，导致 flush 失败 | 不要在 FormType 中用 required 改变业务规则，改 Entity |

---

## 八、修改后验证清单

每次修改完字段规则后，逐一核对：

- [ ] 所有 PHP 类上的 `#[Assert]` 已同步修改
- [ ] `#[ORM\Column]` 长度/nullable 已同步修改
- [ ] `#[Groups]` 配置正确
- [ ] 对应的 FormType 没有显式 constraints 覆盖
- [ ] Twig 模板没有硬编码规则
- [ ] 数据库迁移已生成并执行
- [ ] 运行 `bin/console cache:clear`
- [ ] Web 表单测试：空值/合法值/超限值三种用例
- [ ] API 测试：POST / PATCH / GET 三个操作
- [ ] API 文档测试：/api/docs.json 中 schema 正确
- [ ] `bin/phpunit` 通过
- [ ] `bin/phpstan analyse` 通过
- [ ] `bin/ecs check --fix` 通过

---

## 九、核心代码索引

### 9.1 Entity 真理源

| 文件 | 关键内容 |
|------|---------|
| [Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php) | 三组共存的典型：Default + form + api |
| [Contact.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php) | 显式双组 `['Default', 'form']` |
| [Invoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php) | API 校验组默认 Default，多 ApiResource |
| [BaseInvoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php) | 共用字段，多处 writable: false |
| [Tax.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/TaxBundle/Entity/Tax.php) | 简单实体，无 groups 区分 |

### 9.2 表单专用 DTO

| 文件 | 关键内容 |
|------|---------|
| [InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php) | 条件组 existing_client / new_client |
| [QuoteFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/QuoteBundle/DTO/QuoteFormDTO.php) | 同 Invoice |
| [OnboardingData.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/DTO/OnboardingData.php) | 步骤组 company / client / invoice |
| [Installation.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/Installation.php) | 安装主 DTO，Valid 嵌套子对象 |
| [DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php) | Callback 真连库测试 |
| [UserAccount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/UserAccount.php) | 安装管理员账号组 |
| [Registration.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/Registration.php) | 注册专用 |
| [ChangePassword.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/ChangePassword.php) | 改密码专用 |

### 9.3 FormType 约束层

| 文件 | 关键内容 |
|------|---------|
| [InvoiceType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php) | DependentField 动态字段 NotBlank |
| [PaymentType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php) | 金额 > 0 Callback |
| [ResetPasswordRequestFormType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php) | 非 mapped 字段约束 |
| [ChangePasswordFormType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php) | 密码约束 |
| [InstallationType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php) | 动态 validation_groups 闭包 |
| [OnboardingType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/Type/OnboardingType.php) | Onboarding FormFlow |
| [OnboardingNavigatorType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Onboarding/Form/FormFlow/OnboardingNavigatorType.php) | Skip 按钮 validation_groups: false |

### 9.4 映射层（容易漏改）

| 文件 | 关键内容 |
|------|---------|
| [InvoiceFormManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L40-L78) | DTO→Entity 纯手动映射，无二次校验 |

---

_本手册基于代码库实际走查生成，每次改动核心校验机制后请同步更新本手册。_
