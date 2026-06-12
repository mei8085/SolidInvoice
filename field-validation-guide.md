# SolidInvoice 字段校验规则 — 最终修改指南

> **定位**：一份能直接照着改的操作手册。先读速查表定位问题，再翻对应章节看细节。

---

## 一、一页纸速查（先读这里）

### 1.1 规则定义的六层来源（从高到低）

| 层 | 定义在哪里 | 谁能消费它 | 典型约束 |
|----|-----------|-----------|---------|
| **L1 Entity `#[Assert]`** | Entity 类属性上 | API 校验 + API 文档 + Web 表单（若 FormType 绑定 Entity） | `NotBlank`, `Length`, `Email`, `Url` |
| **L2 DTO `#[Assert]`** | DTO 类属性上 | 仅 Web 表单（若 FormType 绑定 DTO） | 同 L1，带 `groups` 参数 |
| **L3 FormType `'constraints'`** | FormType `buildForm()` 里 `'constraints' => [...]` | 仅该 FormType 渲染的表单 | `NotBlank`, `Callback`, `UserPassword` |
| **L4 validation_groups 闭包** | FormType `configureOptions()` 里 | 决定 L1/L2/L3 中哪些 groups 生效 | `['Default', 'form']`、`['Default', 'smtp']` |
| **L5 Entity `#[Groups]`** | Entity 类属性上（Serializer） | 仅 API：控制字段可见/可写 | `client_api:read`, `invoice_api:write` |
| **L6 步骤级约束** | FormFlow `addStep(..., ['constraints' => [...]])` | 仅该安装步骤 | Callback 检查系统需求 |

### 1.2 核心结论三句话

1. **简单实体（Client/Tax/Contact）**：API 和表单共用 L1（Entity 上的 Assert），改一处两端自动生效。
2. **复杂表单（Invoice/Quote）**：API 走 Invoice Entity，Web 表单走 InvoiceFormDTO + InvoiceFormManager 手动映射，**两边规则独立维护**，改规则必须两边都查。
3. **浏览器原生校验不是主路径**——`form_widget()` 输出的是 `data-required`（只标红星号，不触发浏览器弹窗），真正的校验在服务端 Symfony Validator。

### 1.3 修改决策树（文字版）

```
收到需求：某字段校验规则要改
│
├─ 场景判断：
│   ├─ 简单实体（Client/Tax/Contact） → 改 Entity 的 #[Assert] + #[ORM] + 生成迁移
│   ├─ 复杂表单（Invoice/Quote）       → 改 Entity（API端）+ 改 DTO（表单端）+ 检查 FormType 额外 constraints + 检查 Manager 映射
│   ├─ 仅安装向导                      → 改 Install DTO（groups 匹配步骤名）+ 检查 Step FormType 的 constraints
│   ├─ 仅设置页（Mailer/Notification） → 改 TransportConfigType 的 constraints（groups 匹配 provider 名）
│   ├─ 仅表单（mapped:false 字段）    → 只能改 FormType 的 constraints
│   └─ 仅 API 字段可见性              → 改 Entity 的 #[Groups]
│
└─ 改完必做：
    ├─ 检查 validation_groups 是否匹配
    ├─ 检查 FormType 中有没有额外 constraints 叠加
    └─ bin/ecs + bin/phpstan + bin/phpunit
```

---

## 二、各场景校验链路完整对照表

| 场景 | FormType | data_class | validation_groups | 约束来源层 | 备注 |
|------|----------|-----------|-------------------|-----------|------|
| **Web 创建/编辑 Client** | [ClientType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ClientType.php) | `Client::class` | `['Default', 'form']` | L1(Entity) + L4(组) | contacts 集合的 `Count(min:1)` 只在 form 组 |
| **API 创建/更新 Client** | — | — | `['Default', 'api']` | L1(Entity) + L5(Groups) | contacts 只读不写 |
| **Web 创建/编辑 Invoice** | [InvoiceType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php) | `InvoiceFormDTO::class` | 闭包: `['Default', 'existing_client' \| 'new_client']` | L2(DTO) + L3(users `NotBlank`) + L4(闭包) | 校验 DTO → Manager 映射到 Entity → 不二次校验 Entity |
| **API 创建/更新 Invoice** | — | — | Entity 的 validationContext | L1(Invoice Entity) + L5(Groups) | `total` 等计算字段 writable:false |
| **Web 创建/编辑 Quote** | QuoteType | `QuoteFormDTO::class` | 闭包: `['Default', 'existing_client' \| 'new_client']` | L2(DTO) + L4(闭包) | 同 Invoice 模式 |
| **API 创建/更新 Quote** | — | — | Entity 的 validationContext | L1(Quote Entity) + L5(Groups) | |
| **Web 创建/编辑 Tax** | TaxType | `Tax::class` | `['Default']` | L1(Entity) | |
| **Web 记录付款** | [PaymentType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php) | 无 | `['Default']` | L3(全量) | amount 含 `Callback(>0)` 自定义校验 |
| **Web 编辑 Profile** | [ProfileType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ProfileType.php) | `User::class` | `['Default']` | L1(Entity: name/email) + L3(current_password) | current_password 是 `mapped: false` |
| **Web 修改密码** | [ChangePasswordFormType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php) | 无 | `['Default']` | L3(全量) | `plainPassword` 是 `mapped: false`，`RepeatedType` 的 `first_options` 里定义约束 |
| **Web 找回密码申请** | [ResetPasswordRequestFormType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php) | 无 | `['Default']` | L3(全量) | |
| **Web 自定义域名** | [CustomDomainType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SaasBundle/Form/Type/CustomDomainType.php) | 无 (parent: TextType) | `['Default']` | L3(FormType 级) | `configureOptions` 里的 form-level constraints |
| **邮件设置** | [MailTransportType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php) | 无 | 闭包: `['Default', '{provider小写名}']` | L3(TransportConfig 子表单) + L4(闭包) | 组名由用户选择的 provider 动态生成 |
| **通知设置** | [TransportSettingType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/NotificationBundle/Form/Type/TransportSettingType.php) | `TransportSetting::class` | 闭包: `['Default', '{transport小写名}']` | L3(各 Transport Type) + L4(闭包) | |
| **安装-数据库配置** | [DatabaseConfigStep](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/DatabaseConfigStep.php) | `DatabaseConfig::class` | `['Default', 'database_config', 'database_config_{driver}']` | L2(DTO) + L3(port `Type`) + L6(步骤) | |
| **安装-管理员账号** | [UserAccountStep](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/UserAccountStep.php) | `UserAccount::class` | `['Default', 'user_account']` | L2(DTO) + L3(applicationUrl) + L6(步骤) | applicationUrl 是 `mapped:false`，手动写回 |
| **安装-系统需求** | [SystemRequirementsStep](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Step/SystemRequirementsStep.php) | 无 | `['Default', 'system_requirements']` | L6(步骤级 Callback) | 检查 PHP 版本/扩展等 |
| **安装-整体** | [InstallationType](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php) | `Installation::class` | 闭包: `['Default', '{currentStep}', 'database_config_{driver}']` | L2 + L3 + L4 + L6 | 动态决定启用哪些组 |

---

## 三、六层来源深度解析

### 3.1 L1 Entity `#[Assert]` — 最常见的共享真理源

**生效范围**：API 校验 + API 文档 + Web 表单（当 FormType 的 data_class 是 Entity 时）

**典型示例**：[Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php#L93-L98)

```php
#[ORM\Column(name: 'name', type: Types::STRING, length: 125)]
#[Assert\NotBlank]                          // ← API 和表单都用这个
#[Assert\Length(max: 125)]                  // ← API 文档 maxLength + 表单 maxlength
#[Serialize\Groups(['client_api:read', 'client_api:write'])]
private ?string $name = null;
```

**仅表单生效的组**：`contacts` 字段的 `Count(min:1)` 用 `groups: ['form']`，API 不校验（因为 REST 中联系人是独立资源）。

---

### 3.2 L2 DTO `#[Assert]` — 表单专用的第二真理源

**生效范围**：仅 Web 表单（当 FormType 的 data_class 是 DTO 时）

**典型场景**：Invoice/Quote 创建编辑、用户注册、安装向导。

**InvoiceFormDTO 条件组示例**：[InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php#L33-L86)

```php
// 模式1：选择已有客户
#[Assert\NotBlank(groups: ['existing_client'])]
public ?Client $client = null;

// 模式2：内联新建客户（Entity 里根本没有这些字段！）
#[Assert\NotBlank(groups: ['new_client']), Assert\Length(max: 125, groups: ['new_client'])]
public ?string $newClientName = null;

// 通用字段（两端都有，但 DTO 和 Entity 上各写一份 Assert）
#[Assert\NotBlank]
public string $invoiceId = '';

#[Assert\Count(min: 1), Assert\Valid]
public ArrayCollection $lines;
```

> ⚠️ **关键注意**：Invoice 等复杂表单中，DTO 和 Entity 上的 Assert 是**两套独立代码**，修改规则时必须**两边都改**。

---

### 3.3 L3 FormType `'constraints'` — 仅表单生效的额外规则

**生效范围**：仅该 FormType 渲染的表单字段。

**出现原因**（按频率排序）：

| 原因 | 典型字段 | 示例文件 |
|------|---------|---------|
| `mapped: false`（字段不映射到底层对象） | `current_password`, `applicationUrl`, `plainPassword` | ProfileType, UserAccountStep |
| FormType 没有 data_class | `payment_method`, `amount` | PaymentType |
| FormType 级约束（整个表单一个值） | hostname 校验 | CustomDomainType |
| 条件字段的额外叠加 | `users` 的 `NotBlank`（叠加 DTO 的 `Count`） | InvoiceType |
| 子表单 / 嵌套约束 | RepeatedType 的 `first_options.constraints` | ChangePasswordFormType |

**全量清单（按重要性排序）**：

| FormType | 字段 | 约束 | 备注 |
|----------|------|------|------|
| InvoiceType | `users` | `NotBlank()` | 叠加 DTO 的 `Count(min:1, groups:existing_client)` |
| PaymentType | `payment_method` | `NotBlank()` | 无 data_class |
| PaymentType | `amount` | `NotBlank() + Callback(>0)` | 无 data_class，金额必须>0 |
| ProfileType | `current_password` | `NotBlank + UserPassword` | mapped:false |
| ChangePasswordFormType | `plainPassword` | `NotBlank + Length(8) + PasswordStrength` | mapped:false, RepeatedType first_options |
| ResetPasswordRequestFormType | `email` | `NotBlank + Email(strict)` | 无 data_class |
| CustomDomainType | (form 级) | `Hostname + NotApplicationUrlHost` | configureOptions 级 |
| SmtpTransportConfigType | `host` | `NotBlank(groups: ['smtp'])` | 动态 Provider 组 |
| SmtpTransportConfigType | `port` | `Type(integer, groups: ['smtp'])` | 动态 Provider 组 |
| UserAccountStep | `applicationUrl` | `NotBlank + Url` | mapped:false，POST_SUBMIT 手动写回 |
| DatabaseConfigStep | `port` | `Type('integer')` | 叠加 DTO 的带组 Type |

---

### 3.4 L4 validation_groups 闭包 — 控制哪些规则生效

**生效范围**：决定 L1/L2/L3 中带 `groups` 参数的约束哪些被激活。

**四种典型模式**：

#### 模式 A：简单数组（最常见）
```php
// ClientType
'validation_groups' => ['Default', 'form'],
```

#### 模式 B：闭包动态切换（Invoice/Quote 条件表单）
[InvoiceType.php:210-221](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php#L210-L221)
```php
'validation_groups' => function (FormInterface $form) {
    $data = $form->getData();
    $groups = ['Default'];
    if ($data->clientMode === InvoiceClientMode::NewClient) {
        $groups[] = 'new_client';
    } else {
        $groups[] = 'existing_client';
    }
    return $groups;
},
```

#### 模式 C：闭包动态生成组名（Mailer/Notification Provider）
[MailTransportType.php:132](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php#L132)
```php
'validation_groups' => static fn (FormInterface $form) =>
    ['Default', strtolower(str_replace(' ', '_', $form->get('provider')->getData() ?? ''))],
```
用户选 SMTP → 组 = `['Default', 'smtp']`；选 SES → `['Default', 'ses']`。

#### 模式 D：闭包 + 步骤名（安装向导 FormFlow）
[InstallationType.php:103-111](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L103-L111)
```php
'validation_groups' => static function (FormFlowInterface $form) {
    $groups = ['Default', $form->getCursor()->getCurrentStep()];
    if ($form->getData()?->databaseConfig?->driver) {
        $groups[] = 'database_config_' . $form->getData()->databaseConfig->driver;
    }
    return $groups;
},
```
database_config 步骤 → 组 = `['Default', 'database_config', 'database_config_mysql']`

---

### 3.5 L5 Entity `#[Groups]` — API 字段可见性开关

**生效范围**：仅 API。控制字段在 GET 返回中是否出现、POST/PATCH 是否接受。

**Client Entity 字段矩阵**：

| 字段 | `client_api:read` | `client_api:write` | 含义 |
|------|:-:|:-:|------|
| id | ✅ | ❌ | 只读 ID |
| name | ✅ | ✅ | 可读可写 |
| website | ✅ | ✅ | 可读可写 |
| currencyCode | ✅ | ✅ | 可读可写 |
| vatNumber | ✅ | ✅ | 可读可写 |
| contacts | ✅ | ❌ | **只读**（独立资源，通过 `/clients/{id}/contacts` 管理） |
| addresses | ✅ | ❌ | **只读** |
| invoices / quotes / payments | ✅ | ❌ | 只读关联 |
| credit | ✅ | ✅ | 可读可写 |

**Invoice Entity 关键字段**：

| 字段 | `invoice_api:read` | `invoice_api:write` | 含义 |
|------|:-:|:-:|------|
| invoiceId / client / invoiceDate / due / discount / terms / notes | ✅ | ✅ | 普通字段 |
| status / paidDate | ✅ | ❌ | **只读**（状态机/支付自动写入） |
| total / baseTotal / tax | ✅ | ❌ | **只读**（系统计算） |
| lines | ✅ | ✅ | 可读可写（行项目） |
| users | ✅ | ✅ | 可读可写（联系人） |

> ⚠️ **陷阱**：`write` 组中没有的字段，Entity 上即使有 `NotBlank` 也不会因为"传值为空"报错——因为 API Platform 根本不会反序列化该字段。但如果字段在 write 组里且有 NotBlank，不传就会 422。

---

### 3.6 L6 步骤级约束 — 安装向导 FormFlow 专用

**生效范围**：仅安装向导的某一步骤。

**唯一实例**：[InstallationType.php:56-65](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php#L56-L65)

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

这个约束的触发不依赖任何字段，而是检查系统环境（PHP 版本、扩展等）。

---

## 四、复杂表单深度解析：Invoice/Quote 双层架构

这是最容易出问题的场景，单独拿出来讲。

### 4.1 Web 表单完整链路

```
用户填表单
  ↓
POST /invoices/create
  ↓
InvoiceType → handleRequest() 填充 InvoiceFormDTO
  ↓
$form->isValid()
  ↳ 校验对象：InvoiceFormDTO 实例（不是 Invoice！）
  ↳ 约束来源：DTO 上的 #[Assert] + FormType 'constraints'
  ↳ 组：['Default', 'existing_client'] 或 ['new_client']
  ↓
校验通过？
  ├─ 否 → 重渲染 + form_errors()
  └─ 是 → InvoiceFormManager::createInvoiceFromDTO($dto)
            ↓
          纯手动 set：$invoice->setInvoiceId($dto->invoiceId);
          纯手动 set：$invoice->setDue($dto->due);
          纯手动 set：foreach ($dto->lines as $line) { $invoice->addLine($line); }
            ↓
          EntityManager::persist($invoice) + flush()
            ↓
          ❌ 没有第二次 validate($invoice)！
          ❌ 没有调用 Validator 组件！
            ↓
          完成
```

**关键代码**：[InvoiceFormManager.php:40-78](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php#L40-L78) + [Create.php:100-119](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Action/Create.php#L100-L119)

### 4.2 API 完整链路

```
POST /api/invoices
  ↓
API Platform 反序列化到 Invoice Entity
  ↳ 只反序列化 invoice_api:write 组的字段
  ↓
Validation Listener 校验
  ↳ 约束来源：Invoice Entity 上的 #[Assert]
  ↳ 组：Entity 的 validationContext（一般是 ['Default', 'api']）
  ↓
校验通过？
  ├─ 否 → 422 错误
  └─ 是 → persist + flush
            ↓
          完成
```

### 4.3 两边规则不一致的典型字段

| 字段 | Web 表单规则（DTO） | API 规则（Entity） | 为什么不同 |
|------|-------------------|-------------------|-----------|
| `client` | 条件必填（existing_client 组） | 始终必填 | 表单有"新建客户"模式，API 必须指定已有客户 |
| `newClientName` / `newContactEmail` 等 | DTO 有，new_client 组必填 | ❌ 不存在 | API 走独立的 Client 创建端点 |
| `users`（联系人） | `Count(min: 1, groups: existing_client)` + FormType `NotBlank` | `Count` 规则要看 Entity | 表单至少选一个，API 也有类似约束但独立定义 |
| `lines`（行项目） | `Count(min: 1) + Valid` | `Count` 规则要看 Entity | 两边语义类似但代码独立 |

### 4.4 修改 Invoice/Quote 字段规则的 4 步检查清单

| 序号 | 检查项 | 在哪里看 |
|------|-------|---------|
| 1 | Entity 上的 `#[Assert]` 改了吗？ | `src/InvoiceBundle/Entity/Invoice.php` + `BaseInvoice.php` |
| 2 | DTO 上的 `#[Assert]` 改了吗？ | `src/InvoiceBundle/DTO/InvoiceFormDTO.php` |
| 3 | FormType 中有没有额外 `'constraints'`？ | `src/InvoiceBundle/Form/Type/InvoiceType.php` |
| 4 | Manager 的映射方法里有对应 set 吗？ | `src/InvoiceBundle/Manager/InvoiceFormManager.php` 的 `createInvoiceFromDTO()` |
| 5 | API Groups 对吗？（仅当影响 API 时） | Entity 上的 `#[Groups]` |
| 6 | DB 列变了吗？迁移生成了吗？ | `#[ORM\Column]` + `bin/console doctrine:migrations:diff` |

---

## 五、安装向导深度解析

### 5.1 六个步骤 + 校验组对应

| 步骤名 | FormType | property_path | 活跃校验组 | 约束来源 |
|--------|----------|---------------|-----------|---------|
| `start` | StartStep | mapped:false | `['Default', 'start']` | 无 |
| `system_requirements` | SystemRequirementsStep | mapped:false | `['Default', 'system_requirements']` | L6 步骤级 Callback |
| `database_config` | DatabaseConfigStep | `databaseConfig` | `['Default', 'database_config', 'database_config_{driver}']` | L2 DTO + L3 port Type |
| `user_account` | UserAccountStep | `userAccount` | `['Default', 'user_account']` | L2 DTO + L3 applicationUrl |
| `review` | ReviewStep | inherit_data | `['Default', 'review']` | 无额外 |
| `install` | — | inherit_data | `['Default', 'install']` | 无 |
| `finish` | — | mapped:false | `['Default', 'finish']` | 无 |

### 5.2 DatabaseConfig 条件组

不同数据库驱动需要的字段不同：

| 驱动 | 需要的字段 | 对应校验组 |
|------|-----------|-----------|
| SQLite | 只需要 driver + path | `database_config` + `database_config_sqlite` |
| MySQL / MariaDB / PostgreSQL | driver + host + name + port(可选) + user + password | `database_config` + `database_config_mysql` (等) |

此外 `database_config` 组还有一个 `Callback` 真的尝试连接数据库验证配置是否正确。

---

## 六、前端校验真相

### 6.1 浏览器原生校验不是主路径

**核心证据**：[fields.html.twig:24](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig#L24)

```twig
{% if required %} data-required="required"{% endif %}
{#              ↑↑↑ data-前缀，不是 required="required" #}
```

`data-*` 是 HTML5 自定义数据属性，浏览器**不会**触发原生的"请填写此字段"弹窗。它只用于：
- CSS：label 前加红色 `*` 号
- 自定义 JS 读取做标记

### 6.2 novalidate 使用情况

| 页面 | 是否显式 novalidate |
|------|-------------------|
| 用户注册 | ✅ 是 |
| 安装向导 | ✅ 是（FormFlow 自动） |
| 其他业务表单（Client/Tax/Invoice 等） | ❌ 未设置（但 data-required 也不触发原生校验） |

### 6.3 真正的校验主路径（三层）

```
第一层：Symfony Form Validator（$form->isValid()）
  ↓
第二层：Callback / 自定义断言（UserPassword、数据库连接测试等）
  ↓
第三层：Doctrine / DB 层（NOT NULL、外键、唯一索引）—— 最后一道防线
```

### 6.4 前端增强（体验用，不决定结果）

| 控制器 | 功能 | 是否改变校验结果 |
|--------|------|----------------|
| [vat-validator-controller.ts](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/assets/controllers/vat-validator-controller.ts) | 异步调 API 检查 VAT 号 | 否，只显示图标，提交时服务端重验 |
| [password-strength-controller.ts](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/assets/controllers/password-strength-controller.ts) | 实时密码强度条 | 否，强度算法独立，最终看服务端 `#[PasswordStrength]` |
| Live Components（Invoice/Quote 表单） | 切模式/加行时局部刷新，提前显示错误 | 是**预演**服务端校验，不是独立规则 |

---

## 七、常见修改场景操作手册

### 场景 A：Client.name 最大长度 125 → 150（简单实体，两端共用）

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | `#[ORM\Column(length: 125)]` → 150 | Client.php |
| 2 | `#[Assert\Length(max: 125)]` → 150 | Client.php |
| 3 | 生成迁移：`bin/console doctrine:migrations:diff` | — |
| 4 | 验证：API 文档 maxLength 自动更新、表单 maxlength 自动更新 | — |

**不需要改的**：FormType、Twig 模板、API 配置（name 本来就在 read/write 组）

---

### 场景 B：Invoice 发票号允许为空（复杂表单，双层）

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | 去掉 `#[Assert\NotBlank]`（API 端） | Invoice Entity（或 BaseInvoice） |
| 2 | `#[ORM\Column]` 加 `nullable: true` | Invoice Entity |
| 3 | 去掉 `#[Assert\NotBlank]`（表单端） | InvoiceFormDTO.php |
| 4 | 检查 InvoiceFormManager 映射：只 set 不校验，无需改 | InvoiceFormManager.php |
| 5 | 检查 InvoiceType 中 invoiceId 有无额外 constraints（无） | InvoiceType.php |
| 6 | 生成迁移 | — |
| 7 | 检查 API Groups（invoiceId 在 write 组吗？） | Invoice Entity |

---

### 场景 C：安装向导密码最少 6 → 10 位

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | `#[Length(min: 6, groups: ['user_account'])]` → 10 | UserAccount.php |
| 2 | 检查安装模板中有没有"至少6位"文案，手动改 | Twig 模板 |
| 3 | （可选）如果登录用户改密码也要同步，改 ChangePasswordFormType | ChangePasswordFormType.php first_options |

---

### 场景 D：SMTP host 加最大长度 255

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | constraints 加 `new Length(max: 255, groups: ['smtp'])` | SmtpTransportConfigType.php |
| 2 | 注意 groups 必须是 `['smtp']`，和闭包生成的组名一致 | — |
| 3 | 如果要应用到所有 provider，不加 groups 也行（Default 组） | — |

**不需要改的**：Entity、API 配置（设置页不走 API）

---

### 场景 E：Payment 金额下限从 0 改为 $1

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | 修改 Callback 中 `$value->isZero()` 为 `$value->isLessThan(Money::USD(100))` | PaymentType.php amount constraints |
| 2 | 更新错误消息文字 | PaymentType.php |

**不需要改的**：Entity/DTO（PaymentType 无 data_class）、API（付款走 Payum）

---

### 场景 F：新增字段到 Invoice（最复杂）

| 步骤 | 操作 | 文件 |
|------|------|------|
| 1 | 加属性 + `#[ORM\Column]` + `#[Assert\*]` + `#[Groups]` | Invoice Entity |
| 2 | 加属性 + `#[Assert\*]`（group 注意） | InvoiceFormDTO.php |
| 3 | `buildForm()` 中加字段 | InvoiceType.php |
| 4 | `createInvoiceFromDTO()` 中加 `$invoice->setXxx($dto->xxx)` | InvoiceFormManager.php |
| 5 | Twig 模板中加 `form_row(form.xxx)` 或 Live Component 自动渲染 | 对应模板 |
| 6 | 生成迁移 | — |
| 7 | 两端测试：Web 表单提交 + API POST | — |

> ⚠️ 这是最容易漏改 Manager 映射的场景，**一定要加第四步**。

---

## 八、陷阱与反模式清单

| # | 反模式 | 后果 | 正确做法 |
|---|-------|------|---------|
| 1 | 改了 Entity Assert 忘了改 DTO Assert（Invoice/Quote） | Web 表单和 API 规则不一致 | 先看 FormType 的 data_class，是 DTO 就两边都查 |
| 2 | 改了 Entity/DTO 忘了 FormType 还有额外 constraints | 改了规则还被拦截 | 搜索同字段 `'constraints' =>` |
| 3 | 新增 API 字段只加 read 组忘了 write 组 | POST 时字段被静默忽略，排查困难 | 字段一加上就把 read/write 都考虑好 |
| 4 | DTO 加了 Assert 忘了 Manager 里加 set | 校验过了 DB 还是空 | 改完 DTO 必查 createXxxFromDTO() |
| 5 | Mailer/Notification 约束的 groups 写错 | 约束永远不触发 | 组名必须等于 provider/transport 的小写名 |
| 6 | Twig 模板硬编码 `maxlength="125"` | Entity 改了前端不同步 | 删硬编码，用 `form_widget()` 自动输出 |
| 7 | mapped:false 字段的约束写在 Entity/DTO 上 | 约束永远不触发 | mapped:false 的只能放 FormType |
| 8 | 安装向导 DTO 的 groups 不匹配步骤名 | 约束永远不触发 | 步骤名 === 组名 |
| 9 | 认为浏览器原生 required 是主校验 | 改了 data-required 以为会弹浏览器提示 | 主校验在服务端，data-required 只是 CSS 标记 |
| 10 | Invoice 表单修改后认为 Entity 也会被校验 | DTO 过了 Entity 不校验，脏数据直接进库 | 确保 DTO 规则足够严格，或 Manager 中加校验 |

---

## 九、代码索引附录

### 9.1 Entity / DTO 文件

| 类型 | 文件 | 说明 |
|------|------|------|
| Entity | [Client.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Client.php) | contacts 带 `groups: ['form']` |
| Entity | [Contact.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Entity/Contact.php) | 显式 `groups: ['Default', 'form']` |
| Entity | [Tax.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/TaxBundle/Entity/Tax.php) | 简单实体示例 |
| Entity | [BaseInvoice.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php) | Invoice 基类 |
| DTO | [InvoiceFormDTO.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php) | 复杂表单 DTO，条件组 |
| DTO | [Registration.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/Registration.php) | 注册 DTO |
| DTO | [ChangePassword.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/DTO/ChangePassword.php) | 改密码 DTO |
| DTO | [DatabaseConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/DatabaseConfig.php) | 安装：数据库配置 |
| DTO | [UserAccount.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/DTO/UserAccount.php) | 安装：管理员账号 |

### 9.2 FormType 文件

| 文件 | data_class | 关键特征 |
|------|-----------|---------|
| [ClientType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/ClientBundle/Form/Type/ClientType.php) | `Client::class` | `validation_groups: ['Default', 'form']` |
| [InvoiceType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Form/Type/InvoiceType.php) | `InvoiceFormDTO::class` | 闭包动态组，users 额外 NotBlank |
| [PaymentType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/PaymentBundle/Form/Type/PaymentType.php) | 无 | amount 含 Callback |
| [ProfileType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ProfileType.php) | `User::class` | current_password mapped:false |
| [ChangePasswordFormType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ChangePasswordFormType.php) | 无 | RepeatedType first_options constraints |
| [ResetPasswordRequestFormType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Form/Type/ResetPasswordRequestFormType.php) | 无 | email NotBlank+Email |
| [CustomDomainType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SaasBundle/Form/Type/CustomDomainType.php) | 无 | form-level constraints |
| [MailTransportType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/SettingsBundle/Form/Type/MailTransportType.php) | 无 | 闭包动态 provider 组 |
| [SmtpTransportConfigType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/MailerBundle/Form/Type/TransportConfig/SmtpTransportConfigType.php) | — | `groups: ['smtp']` |
| [TransportSettingType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/NotificationBundle/Form/Type/TransportSettingType.php) | `TransportSetting::class` | 闭包动态 transport 组 |
| [InstallationType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InstallBundle/Form/Type/InstallationType.php) | `Installation::class` | FormFlow 6 步骤，步骤级约束 |

### 9.3 表单提交 / Manager 映射

| 文件 | 关键逻辑 |
|------|---------|
| [InvoiceFormManager.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Manager/InvoiceFormManager.php) | DTO → Entity 纯手动 set，**无二次校验** |
| [Create.php](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/InvoiceBundle/Action/Create.php#L100-L119) | $form->isValid() 只校验 DTO |

### 9.4 前端模板

| 文件 | 关键内容 |
|------|---------|
| [fields.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig#L24) | `data-required` 不是原生 `required` |
| [register.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/21-SolidInvoice/src/UserBundle/Resources/views/Security/register.html.twig#L42) | 显式 novalidate |

---

_报告生成时间：2026-06-12_
_SolidInvoice 版本：3.0.0-dev_
