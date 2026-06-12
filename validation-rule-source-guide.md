# SolidInvoice 字段校验规则来源与修改指南

---

## 一、约束定义的六层来源

字段校验规则在代码中可能出现在以下六层，各层的生效范围不同。**修改时必须逐层排查，不能只看 Entity/DTO。**

```
┌──────────────────────────────────────────────────────────────────┐
│  Layer 1: Entity #[Assert]                                       │
│  生效范围：API 校验 + API 文档 + 表单（若 FormType 绑定 Entity）  │
├──────────────────────────────────────────────────────────────────┤
│  Layer 2: DTO #[Assert]                                          │
│  生效范围：仅表单（若 FormType 绑定 DTO）                         │
├──────────────────────────────────────────────────────────────────┤
│  Layer 3: FormType buildForm() 中的 'constraints' => [...]       │
│  生效范围：仅该 FormType 渲染的表单字段                           │
├──────────────────────────────────────────────────────────────────┤
│  Layer 4: FormType configureOptions() 中的 validation_groups 闭包│
│  生效范围：控制 Layer 1/2/3 中哪些 groups 生效                    │
├──────────────────────────────────────────────────────────────────┤
│  Layer 5: Entity #[Groups] (Serializer)                          │
│  生效范围：API 字段是否可见/可写                                  │
├──────────────────────────────────────────────────────────────────┤
│  Layer 6: FormType Step 级 'constraints'（安装向导 FormFlow）     │
│  生效范围：仅该步骤                                               │
└──────────────────────────────────────────────────────────────────┘
```

---

## 二、Layer 3 详查：FormType 中直接定义的约束

FormType 的 `buildForm()` 中 `'constraints' => [...]` 是**仅表单生效的第三层规则**，它不走 Entity/DTO 的 `#[Assert]`，也无法被 API 看到。以下是全量清单。

### 2.1 InvoiceType —— users 字段的 NotBlank

文件：[InvoiceType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L183-L186)

```php
$builder->addDependent('users', 'client', function (DependentField $field, ?Client $client): void {
    // ...
    $field->add(EntityType::class, [
        'class' => Contact::class,
        'constraints' => new NotBlank(),          // ← Layer 3：仅表单
        'expanded' => true,
        'multiple' => true,
    ]);
});
```

**分析**：`users` 是 `InvoiceFormDTO` 上的公共属性，其 `#[Assert\Count(min: 1, groups: ['existing_client'])]` 在 DTO 上已定义。这里又加了 `NotBlank`。两者**叠加生效**：NotBlank 在 Default 组（始终生效），Count(min:1) 在 existing_client 组（条件生效）。

> ⚠️ 如果要修改"用户必须选择联系人"的规则，需要**同时检查** DTO 上的 `Count` 和 FormType 上的 `NotBlank`。

### 2.2 PaymentType —— payment_method 和 amount

文件：[PaymentType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php#L51-L83)

```php
$builder->add('payment_method', EntityType::class, [
    'constraints' => new Assert\NotBlank(),       // ← Layer 3
]);

$builder->add('amount', MoneyType::class, [
    'constraints' => [
        new Assert\NotBlank(),                     // ← Layer 3
        new Assert\Callback(function (BigNumber $value, ExecutionContextInterface $context): void {
            if ($value->isZero() || $value->isNegative()) {
                $context->buildViolation('This value should be greater than {{ compared_value }}.')
                    ->addViolation();
            }
        }),                                        // ← Layer 3：金额必须>0，纯表单逻辑
    ],
]);
```

**分析**：PaymentType **没有 data_class**（看 configureOptions 没有设置），这些约束完全是**FormType 自治**的，不关联任何 Entity/DTO。

### 2.3 ProfileType —— current_password（mapped: false）

文件：[ProfileType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ProfileType.php#L37-L47)

```php
->add('current_password', PasswordType::class, [
    'mapped' => false,                            // ← 不映射到 Entity
    'constraints' => [
        new NotBlank(),                            // ← Layer 3
        new UserPassword(),                        // ← Layer 3：校验当前登录用户密码
    ],
])
```

**分析**：`mapped: false` 意味着这个字段**不存在于 Entity 上**，它的约束只能放在 FormType 里。这是"确认身份"类字段的典型模式——只在表单提交时校验，不持久化。

### 2.4 ChangePasswordFormType —— plainPassword（mapped: false + RepeatedType）

文件：[ChangePasswordFormType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php#L27-L49)

```php
->add('plainPassword', RepeatedType::class, [
    'type' => PasswordType::class,
    'first_options' => [
        'constraints' => [
            new NotBlank(message: 'Please enter a password'),
            new Length(min: 8, max: 4096, minMessage: 'Your password should be at least {{ limit }} characters'),
            new PasswordStrength(minScore: PasswordStrength::STRENGTH_MEDIUM),
        ],
    ],
    'mapped' => false,                            // ← 不映射到 Entity
])
```

**分析**：`mapped: false`，密码明文不存 Entity。约束在 FormType 内部 `first_options` 中定义（注意不是顶层 `constraints`，而是 `RepeatedType` 的 `first_options.constraints`）。

### 2.5 ResetPasswordRequestFormType —— email（无 data_class）

文件：[ResetPasswordRequestFormType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php#L25-L35)

```php
->add('email', EmailType::class, [
    'constraints' => [
        new NotBlank(['message' => 'Please enter your email']),
        new Email(['message' => 'The email {{ value }} is not a valid email.', 'mode' => Email::VALIDATION_MODE_STRICT]),
    ],
])
```

**分析**：这个 FormType 没有 data_class，约束完全是 FormType 自治。

### 2.6 CustomDomainType —— 整个 FormType 级别约束

文件：[CustomDomainType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SaasBundle/Form/Type/CustomDomainType.php#L24-L31)

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        'constraints' => [                         // ← FormType 级别约束（不是字段级别）
            new Hostname(['requireTld' => true]),
            new NotApplicationUrlHost(),           // ← 自定义断言：域名不能是应用自身URL
        ],
    ]);
}
```

**分析**：约束定义在 `configureOptions` 而非 `buildForm`，作用于整个 FormType 数据（因为 `getParent() => TextType::class`，只有一个值字段）。

### 2.7 Payment Method 配置表单 —— Payum 工厂字段

文件列表：

| 文件 | 约束 |
|------|------|
| [PaypalExpressCheckout.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Methods/PaypalExpressCheckout.php#L27-L50) | `username` NotBlank, `password` NotBlank, `signature` NotBlank |
| [PaypalProCheckout.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Methods/PaypalProCheckout.php) | `username` NotBlank, `password` NotBlank, `partner` NotBlank, `vendor` NotBlank |
| [Payex.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Methods/Payex.php#L26-L42) | `account_number` NotBlank, `encryption_key` NotBlank |
| [KlarnaInvoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Methods/KlarnaInvoice.php#L25-L40) | `secret` NotBlank, `eid` NotBlank |

**分析**：这些表单没有 data_class，是 Payum 的工厂配置表单。约束只在这类设置页表单中生效，与 API 无关。

---

## 三、Layer 3 详查：Mailer/Notification 动态 Provider 组

这是项目中最复杂的 validation_groups 模式：**组名来自用户选择的 Provider 值**。

### 3.1 MailTransportType —— 邮件设置

文件：[MailTransportType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php#L131-L132)

```php
'validation_groups' => static fn (FormInterface $form) =>
    ['Default', strtolower(str_replace(' ', '_', $form->get('provider')->getData() ?? ''))],
```

效果：用户选了 SMTP → groups = `['Default', 'smtp']`，选了 SES → groups = `['Default', 'ses']`。

### 3.2 SmtpTransportConfigType —— SMTP 字段约束

文件：[SmtpTransportConfigType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/MailerBundle/Form/Type/TransportConfig/SmtpTransportConfigType.php#L30-L45)

```php
$builder->add('host', null, ['constraints' => new NotBlank(groups: ['smtp'])]);
$builder->add('port', IntegerType::class, ['constraints' => new Type(type: 'integer', groups: ['smtp'])]);
```

**分析**：`host` 的 NotBlank 只在 `smtp` 组生效。如果用户选了 SES provider，这个约束不会触发。

### 3.3 TransportSettingType —— 通知渠道设置

文件：[TransportSettingType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/NotificationBundle/Form/Type/TransportSettingType.php#L62-L64)

```php
'validation_groups' => static function (FormInterface $form) {
    return ['Default', strtolower($form->get('transport')->getData())];
},
```

效果：选了 Slack → groups = `['Default', 'slack']`，选了 Twilio → groups = `['Default', 'twilio']`。

同理，每个 Notification Transport FormType（[SlackType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/NotificationBundle/Form/Type/Transport/SlackType.php)、[TwilioType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/NotificationBundle/Form/Type/Transport/TwilioType.php) 等）中的约束都用 `groups: ['slack']`、`groups: ['twilio']` 等限定。

### 3.4 动态 Provider 组修改指南

| 修改场景 | 需要改的文件 |
|---------|------------|
| SMTP 的 host 加最大长度约束 | SmtpTransportConfigType + 对应约束的 groups 参数 |
| 新增一种邮件 Provider（如 Mailgun） | 新建 MailgunTransportConfigType + 字段约束用 `groups: ['mailgun']` |
| 某个 Provider 的必填字段改为可选 | 对应 TransportConfigType 中删掉 NotBlank 或加 `required: false` |
| 组名规则变化（如空格变连字符） | MailTransportType / TransportSettingType 的闭包 |

---

## 四、Layer 4 详查：安装向导步骤级约束

安装向导使用 Symfony FormFlow，它的 validation_groups 由当前步骤动态决定。

### 4.1 InstallationType 主控逻辑

文件：[InstallationType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L42-L113)

```php
public function buildFormFlow(FormFlowBuilderInterface $builder, array $options): void
{
    $builder->addStep('start', StartStep::class, ['mapped' => false]);
    $builder->addStep('system_requirements', SystemRequirementsStep::class, [
        'mapped' => false,
        'constraints' => [                         // ← Layer 6：步骤级约束
            new Callback(function (array $data, ExecutionContextInterface $context): void {
                if (count($this->appRequirements->getFailedRequirements()) > 0) {
                    $context->buildViolation('Your system does not meet the minimum requirements...')
                        ->atPath('systemRequirements')->addViolation();
                }
            }),
        ],
    ]);
    $builder->addStep('database_config', DatabaseConfigStep::class, [
        'property_path' => 'databaseConfig',       // ← 映射到 Installation→databaseConfig
    ]);
    $builder->addStep('user_account', UserAccountStep::class, [
        'property_path' => 'userAccount',          // ← 映射到 Installation→userAccount
    ]);
    $builder->addStep('review', ReviewStep::class, ['inherit_data' => true]);
    $builder->addStep('install', options: ['inherit_data' => true]);
    $builder->addStep('finish', options: ['mapped' => false]);
}

public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        'data_class' => Installation::class,
        'validation_groups' => static function (FormFlowInterface $form) {
            $groups = ['Default', $form->getCursor()->getCurrentStep()];  // ← 步骤名=组名
            if (...driver...) {
                $groups[] = 'database_config_' . $form->getData()->databaseConfig->driver;
            }
            return $groups;
        },
    ]);
}
```

### 4.2 步骤与校验组的对应关系

| 步骤 | FormType | property_path | 活跃校验组 | 约束来源 |
|------|----------|---------------|-----------|---------|
| `start` | StartStep | mapped:false | `['Default', 'start']` | 无（仅欢迎页） |
| `system_requirements` | SystemRequirementsStep | mapped:false | `['Default', 'system_requirements']` | Layer 6：步骤级 Callback 检查系统需求 |
| `database_config` | DatabaseConfigStep | `databaseConfig` | `['Default', 'database_config', 'database_config_{driver}']` | Layer 2：DatabaseConfig DTO 的 #[Assert] |
| `user_account` | UserAccountStep | `userAccount` | `['Default', 'user_account']` | Layer 2：UserAccount DTO 的 #[Assert] + Layer 3（见下） |
| `review` | ReviewStep | inherit_data | `['Default', 'review']` | 无额外约束 |
| `install` | — | inherit_data | `['Default', 'install']` | 无 |
| `finish` | — | mapped:false | `['Default', 'finish']` | 无 |

### 4.3 UserAccountStep 中额外的 FormType 约束

文件：[UserAccountStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/UserAccountStep.php#L44-L57)

```php
$builder->add('applicationUrl', UrlType::class, [
    'mapped' => false,                              // ← 不映射到 UserAccount DTO
    'required' => true,
    'constraints' => [                              // ← Layer 3：仅此步骤
        new NotBlank(),
        new Url(protocols: ['http', 'https']),
    ],
]);
```

**分析**：`applicationUrl` 字段虽然存在在 `Installation` DTO 上，但 FormType 声明 `mapped: false`，然后通过 `POST_SUBMIT` 事件手动写入 `$root→applicationUrl = ...`。它的约束 **Notblank + Url** 完全在 FormType 中定义，DTO 上没有对 applicationUrl 的 Assert。

### 4.4 DatabaseConfigStep 中额外的 FormType 约束

文件：[DatabaseConfigStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/DatabaseConfigStep.php#L59-L64)

```php
'port' => [
    IntegerType::class,
    [
        'constraints' => new Type('integer'),       // ← Layer 3：仅此步骤
        'required' => false,
    ],
],
```

**分析**：`port` 在 DatabaseConfig DTO 上只有 `#[Type(type: 'integer', groups: ['database_config_mysql', ...])]`（限定 MySQL 等驱动组），而 FormType 中又加了一个无组限定的 `Type('integer')`。两者叠加：在 SQLite 模式下端口字段不显示（DynamicFormBuilder 条件隐藏），在 MySQL/MariaDB/PostgreSQL 模式下 DTO 的 `Type` 和 FormType 的 `Type` 都生效。

---

## 五、Layer 5 详查：API 读写组字段级生效矩阵

API Platform 通过 `#[Groups]` 注解控制字段在 API 中的可见性。`invoice_api:read` 表示 GET 返回时包含，`invoice_api:write` 表示 POST/PATCH 时接受。

### 5.1 Client Entity

文件：[Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php)

| 字段 | `client_api:read` | `client_api:write` | `searchable` | 效果 |
|------|:-:|:-:|:-:|------|
| id | ✅ | ❌ | ❌ | 只读ID |
| name | ✅ | ✅ | ✅ | 可读可写可搜索 |
| website | ✅ | ✅ | ✅ | 可读可写可搜索 |
| currencyCode | ✅ | ✅ | ✅ | 可读可写可搜索（APIProperty 声明 oneOf string\|null） |
| vatNumber | ✅ | ✅ | ✅ | 可读可写可搜索 |
| contacts | ✅ | ❌ | ❌ | **只读！** API 不能通过 Client 端点写联系人 |
| addresses | ✅ | ❌ | ❌ | **只读！** |
| invoices | ✅ | ❌ | ❌ | 只读 |
| quotes | ✅ | ❌ | ❌ | 只读 |
| payments | ✅ | ❌ | ❌ | 只读 |
| credit | ✅ | ✅ | ❌ | 可读可写 |

API 校验组：`validationContext: { groups: ['Default', 'api'] }`

### 5.2 Invoice Entity

文件：[Invoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php) + [BaseInvoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php)

| 字段 | `invoice_api:read` | `invoice_api:write` | 效果 |
|------|:-:|:-:|------|
| invoiceId | ✅ | ✅ | 可读可写 |
| client | ✅ | ✅ | 可读可写 |
| users (contacts) | ✅ | ✅ | 可读可写 |
| status | ✅ | ❌ | **只读**（通过 Workflow 状态机变更） |
| invoiceDate | ✅ | ✅ | 可读可写 |
| due | ✅ | ✅ | 可读可写 |
| discount | ✅ | ✅ | 可读可写 |
| lines | ✅ | ✅ | 可读可写 |
| total | ✅ | ❌ | **只读**（ApiProperty writable:false，系统计算） |
| baseTotal | ✅ | ❌ | **只读**（系统计算） |
| tax | ✅ | ❌ | **只读**（系统计算） |
| terms | ✅ | ✅ | 可读可写 |
| notes | ✅ | ✅ | 可读可写 |
| paidDate | ✅ | ❌ | **只读**（支付完成时系统写入） |

### 5.3 Quote Entity

文件：[Quote.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/QuoteBundle/Entity/Quote.php)

| 字段 | `quote_api:read` | `quote_api:write` | 效果 |
|------|:-:|:-:|------|
| quoteId | ✅ | ✅ | 可读可写 |
| client | ✅ | ✅ | 可读可写 |
| status | ✅ | ❌ | **只读** |
| discount | ✅ | ✅ | 可读可写 |
| lines | ✅ | ✅ | 可读可写 |
| total/baseTotal/tax | ✅ | ❌ | **只读** |
| terms/notes | ✅ | ✅ | 可读可写 |

### 5.4 关键规则：只读字段 ≠ 必填字段

`invoice_api:read` 中存在但 `invoice_api:write` 中不存在的字段，API **不会接受写入**。但 Entity 上如果该字段有 `#[Assert\NotBlank]`（在 Default 组），API 提交时如果不传这个字段，**仍会触发 NotBlank 校验失败**——除非该字段由系统自动填充（如 status 由 Workflow 设置、total 由 TotalCalculator 计算）。

**实际影响**：API 提交 Invoice 时：
- `status` 不传 → 不触发 NotBlank（因为 API 不反序列化此字段）
- `total` 不传 → 不触发 NotBlank（只读字段，API 忽略）
- `client` 不传 → 触发 NotBlank（可写字段，且 Entity 有 Assert）

---

## 六、各场景校验组完整对照表

| 场景 | FormType | data_class | validation_groups | 约束来源层 |
|------|----------|-----------|-------------------|-----------|
| Web 创建/编辑 Client | ClientType | Client::class | `['Default', 'form']` | Layer 1(Entity) + Layer 4(组) |
| API 创建/更新 Client | — | — | `['Default', 'api']` | Layer 1(Entity) + Layer 5(Groups) |
| Web 创建/编辑 Invoice | InvoiceType | InvoiceFormDTO::class | 闭包: `['Default', 'existing_client'\|'new_client']` | Layer 2(DTO) + Layer 3(users NotBlank) + Layer 4(闭包) |
| API 创建/更新 Invoice | — | — | Entity 的 validationContext | Layer 1(Invoice Entity) + Layer 5(Groups) |
| Web 创建/编辑 Quote | QuoteType | QuoteFormDTO::class | 闭包: `['Default', 'existing_client'\|'new_client']` | Layer 2(DTO) + Layer 4(闭包) |
| API 创建/更新 Quote | — | — | Entity 的 validationContext | Layer 1(Quote Entity) + Layer 5(Groups) |
| Web 创建/编辑 Tax | TaxType | Tax::class | `['Default']` | Layer 1(Entity) |
| Web 记录付款 | PaymentType | 无 | 默认 Default | Layer 3(全量) |
| Web 编辑 Profile | ProfileType | User::class | 默认 Default | Layer 1(Entity firstName/lastName/email) + Layer 3(current_password) |
| Web 修改密码 | ChangePasswordFormType | 无 | 默认 Default | Layer 3(全量，mapped:false) |
| Web 找回密码 | ResetPasswordRequestFormType | 无 | 默认 Default | Layer 3(全量) |
| Web 自定义域名 | CustomDomainType | 无 (parent: TextType) | 默认 Default | Layer 3(全量，FormType 级) |
| 邮件设置 | MailTransportType | 无 | 闭包: `['Default', '{provider}']` | Layer 3(TransportConfig 子表单) + Layer 4(闭包) |
| 通知设置 | TransportSettingType | TransportSetting::class | 闭包: `['Default', '{transport}']` | Layer 3(各 Transport Type) + Layer 4(闭包) |
| 安装-数据库配置 | DatabaseConfigStep | DatabaseConfig::class | `['Default', 'database_config', 'database_config_{driver}']` | Layer 2(DTO) + Layer 3(port Type) + Layer 6(步骤) |
| 安装-管理员账号 | UserAccountStep | UserAccount::class | `['Default', 'user_account']` | Layer 2(DTO) + Layer 3(applicationUrl) + Layer 6(步骤) |
| 安装-系统需求 | SystemRequirementsStep | 无 | `['Default', 'system_requirements']` | Layer 6(步骤级 Callback) |
| 安装-整体 | InstallationType | Installation::class | 闭包: `['Default', '{currentStep}', 'database_config_{driver}']` | Layer 2 + 3 + 4 + 6 |

---

## 七、字段规则变更修改指南

### 7.1 决策树

```
收到需求：某字段的校验规则要改
        │
        ▼
Q1: 这个字段在哪些场景出现？（API / Web表单 / 安装向导 / 设置页）
        │
        ├── 仅 API → 只改 Entity #[Assert] + 检查 #[Groups]
        ├── 仅 Web 表单 → 进入 Q2
        ├── API + Web 表单 → 进入 Q3
        ├── 仅安装向导 → 进入 Q4
        └── 仅设置页 → 进入 Q5

Q2: 仅 Web 表单
    ├── FormType 有 data_class 吗？
    │   ├── 有 Entity → 改 Entity #[Assert]
    │   ├── 有 DTO → 改 DTO #[Assert]
    │   └── 无 → 改 FormType 'constraints'
    └── FormType 中有 'constraints' 覆盖吗？
        └── 有 → 也要同步改

Q3: API + Web 表单
    ├── FormType 的 data_class 是 Entity 吗？
    │   ├── 是 → 改 Entity #[Assert]，两端自动生效
    │   └── 否（是 DTO）→ 改 Entity #[Assert]（API端）+ 改 DTO #[Assert]（表单端）
    │                     + 检查 Manager 映射 + 检查 FormType 中有无额外 constraints
    └── Entity 和 DTO 的 groups 一致吗？
        └── 不一致 → 分别处理，确保两端语义一致

Q4: 仅安装向导
    ├── 改 DTO #[Assert]（groups 参数要对应当前步骤名）
    └── 检查 FormType Step 中有无 'constraints' / 'mapped: false' 字段

Q5: 仅设置页（Mailer/Notification）
    ├── 改 TransportConfigType 中的 'constraints'（注意 groups 参数）
    └── 检查 MailTransportType / TransportSettingType 的 validation_groups 闭包
```

### 7.2 常见修改场景速查

#### 场景A：Client.name 最大长度 125→150（简单实体，两端共用 Entity）

| 改动项 | 是否需要 |
|-------|---------|
| Entity `#[ORM\Column(length: 150)]` | ✅ 必须 |
| Entity `#[Assert\Length(max: 150)]` | ✅ 必须 |
| 生成数据库迁移 | ✅ 必须 |
| DTO | ❌ 无 DTO |
| FormType constraints | ❌ ClientType 无额外约束 |
| API Groups | ❌ name 已在 read+write |
| Twig 模板 | ❌ maxlength 自动输出 |

#### 场景B：Invoice 发票号允许为空（复杂表单，DTO+Entity 双层）

| 改动项 | 是否需要 | 说明 |
|-------|---------|------|
| InvoiceFormDTO `#[Assert\NotBlank]` → 删掉 | ✅ 必须 | 否则 Web 表单仍拦截空值 |
| Invoice Entity 去掉 `#[Assert\NotBlank]`（如有） | ✅ 必须 | 否则 API 仍拦截空值 |
| Invoice Entity `#[ORM\Column(nullable: true)]` | ✅ 必须 | DB 允许 NULL |
| 生成迁移 | ✅ 必须 | |
| InvoiceFormManager | ❌ 只做 set，无需改 | |
| InvoiceType constraints | ❌ 无额外约束 | |
| API Groups | ⚠️ 检查 | 确保 invoiceId 在 write 组中 |

#### 场景C：安装向导密码最少 6→10 位

| 改动项 | 是否需要 | 说明 |
|-------|---------|------|
| UserAccount DTO `#[Length(min: 10, groups: ['user_account'])]` | ✅ 必须 | |
| 安装模板中文字"至少6位" | ✅ 手动 | 文案硬编码在 Twig 中 |
| User Entity / ChangePassword DTO | ⚠️ 按需 | 两个独立场景，看是否同步改 |

#### 场景D：SMTP 设置的 host 加最大长度

| 改动项 | 是否需要 | 说明 |
|-------|---------|------|
| SmtpTransportConfigType `'constraints' => [new NotBlank(groups: ['smtp']), new Length(max: 255, groups: ['smtp'])]` | ✅ 必须 | |
| Entity / DTO | ❌ 无 | 无 data_class |
| API | ❌ 无 | 设置页不走 API |

#### 场景E：PaymentType 金额校验加下限 $1

| 改动项 | 是否需要 | 说明 |
|-------|---------|------|
| PaymentType `'constraints'` Callback 中 `$value→isZero()` → 改为 `$value→isLessThan(100)` (cents) | ✅ 必须 | |
| Entity / DTO | ❌ 无 | PaymentType 无 data_class |
| API | ❌ 无 | 付款走 Payum 流程不走 REST API |

#### 场景F：Web 表单 Invoice 的 users 字段校验规则调整

| 改动项 | 是否需要 | 说明 |
|-------|---------|------|
| InvoiceFormDTO `#[Assert\Count(min: 1, groups: ['existing_client'])]` | ✅ 必须 | DTO 层 |
| InvoiceType `buildForm()` 中 `'constraints' => new NotBlank()` | ✅ 必须 | FormType 层 |
| Invoice Entity | ❌ 不需要 | Web 表单不走 Entity 校验 |

---

## 八、反模式清单

| # | 反模式 | 后果 | 正确做法 |
|---|-------|------|---------|
| 1 | 改了 Entity Assert 忘了改 DTO Assert（复杂表单） | Web 表单规则和 API 规则不一致 | 先判断 FormType 的 data_class，是 DTO 就两边都改 |
| 2 | 改了 Entity Assert 忘了改 FormType constraints | FormType 中的额外约束仍拦截 | 搜索 FormType 中同字段的 `'constraints'` |
| 3 | 新增字段只加了 `#[Groups(['xxx_api:read'])]` 忘了 write | API 创建时字段被静默忽略 | 需要可写时加 `xxx_api:write` |
| 4 | 在 DTO 上加了 Assert 忘了在 Manager 映射中加 set | 表单校验过了，DB 值为空 | Manager 的 createXxxFromDTO() 中必须有对应 set |
| 5 | 改了 Mailer DTO 上的 Assert 忘了改 groups 参数 | 约束永远不生效（因为 validation_groups 闭包只传 provider 名） | constraints 的 groups 必须匹配动态组名 |
| 6 | 在 Twig 模板硬编码 `maxlength="125"` | Entity 改了但前端不更新 | 删掉硬编码，让 `form_widget()` 自动输出 |
| 7 | mapped: false 字段的约束写在 Entity/DTO 上 | 约束永远不触发（字段不映射） | mapped:false 字段的约束只能放 FormType |
| 8 | 安装向导的 DTO 约束 groups 不匹配步骤名 | 约束永远不触发（步骤名=组名） | DTO 的 groups 必须等于 InstallationType 中的步骤名 |

---

## 九、代码证据索引

### 9.1 FormType 中的 Layer 3 约束（仅表单生效）

| 文件 | 字段 | 约束 | 特殊说明 |
|------|------|------|---------|
| [InvoiceType.php:186](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L183-L186) | users | NotBlank() | 叠加 DTO 的 Count(min:1) |
| [PaymentType.php:59](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php#L59) | payment_method | NotBlank() | 无 data_class |
| [PaymentType.php:71-80](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php#L71-L80) | amount | NotBlank() + Callback(>0) | 无 data_class |
| [ProfileType.php:40-42](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ProfileType.php#L40-L42) | current_password | NotBlank + UserPassword | mapped:false |
| [ChangePasswordFormType.php:35-38](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php#L35-L38) | plainPassword | NotBlank + Length + PasswordStrength | mapped:false, RepeatedType first_options |
| [ResetPasswordRequestFormType.php:27-34](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php#L27-L34) | email | NotBlank + Email(strict) | 无 data_class |
| [CustomDomainType.php:27-29](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SaasBundle/Form/Type/CustomDomainType.php#L27-L29) | (整个表单) | Hostname + NotApplicationUrlHost | configureOptions 级 |
| [SmtpTransportConfigType.php:34](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/MailerBundle/Form/Type/TransportConfig/SmtpTransportConfigType.php#L34) | host | NotBlank(groups: ['smtp']) | 动态组 |
| [SmtpTransportConfigType.php:42](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/MailerBundle/Form/Type/TransportConfig/SmtpTransportConfigType.php#L42) | port | Type(integer, groups: ['smtp']) | 动态组 |
| [UserAccountStep.php:53-56](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/UserAccountStep.php#L53-L56) | applicationUrl | NotBlank + Url | mapped:false, 手动写回 |

### 9.2 Layer 6 步骤级约束（安装向导）

| 文件 | 约束 | 生效步骤 |
|------|------|---------|
| [InstallationType.php:56-65](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L56-L65) | Callback(检查系统需求) | system_requirements |

### 9.3 Layer 4 动态 validation_groups 闭包

| 文件 | 闭包逻辑 |
|------|---------|
| [InvoiceType.php:210-221](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L210-L221) | Default + existing_client / new_client |
| [InstallationType.php:103-111](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L103-L111) | Default + 步骤名 + database_config_{driver} |
| [MailTransportType.php:132](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php#L132) | Default + {provider名} |
| [TransportSettingType.php:62-64](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/NotificationBundle/Form/Type/TransportSettingType.php#L62-L64) | Default + {transport名} |

### 9.4 Entity/DTO 层（Layer 1/2）

| 文件 | 关键约束 |
|------|---------|
| [Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php) | NotBlank(name) + Length(125,name) + Url(website) + Length(3,currencyCode) + Count(min:1,contacts,groups:form) |
| [Contact.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php) | NotBlank(firstName,email,groups:[Default,form]) + Email(strict,groups:[Default,form]) + Length(125,groups:[Default,form]) |
| [Tax.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/TaxBundle/Entity/Tax.php) | NotBlank(name,rate,type) + Type(float,rate) |
| [InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php) | NotBlank(client,groups:existing_client) + NotBlank(newClient*,groups:new_client) + Count(min:1,users,groups:existing_client) + Count(min:1,lines) + NotBlank(invoiceId) |
| [QuoteFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/QuoteBundle/DTO/QuoteFormDTO.php) | 同 InvoiceFormDTO 结构 |
| [Registration.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/Registration.php) | NotBlank + Email(strict) + Length(8,plainPassword) + PasswordStrength |
| [DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php) | NotBlank(driver,groups:database_config) + NotBlank(host,name,groups:database_config_mysql/mariadb/pgsql) + Callback(validate,groups:database_config) |
| [UserAccount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/UserAccount.php) | NotBlank(locale,firstName,lastName,emailAddress,password,groups:user_account) + Email(strict,groups:user_account) + Length(min:6,password,groups:user_account) |

---

_报告生成时间：2026-06-12_
_分析代码版本：SolidInvoice 3.0.0-dev_
