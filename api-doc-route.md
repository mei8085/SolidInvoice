# SolidInvoice API 路由、序列化与文档生成协作机制

> 本文档基于 SolidInvoice 3.0.0-dev 代码库逐项源码复核后整理。
> 所有文件路径均为仓库相对路径，所有代码片段均标注精确行号，便于跨环境复核。
>
> **复核状态**：已逐项对照源码验证

---

## 目录

1. [整体架构概览](#整体架构概览)
2. [路由注册机制](#路由注册机制)
3. [序列化器声明体系](#序列化器声明体系)
4. [OpenAPI 文档生成](#openapi-文档生成)
5. [三者协作的完整流程](#三者协作的完整流程)
6. [对齐验证：如何确保一致性](#对齐验证如何确保一致性)
7. [关键文件索引](#关键文件索引)
8. [复核记录](#复核记录)

---

## 整体架构概览

SolidInvoice 基于 **API Platform 4.0+** 构建 REST API，采用"声明式"设计：实体类通过 PHP 属性（Attributes）同时声明路由、序列化规则和文档元数据，API Platform 运行时自动将这些声明转化为实际的路由、序列化行为和 OpenAPI 文档。

```
┌──────────────────────────────────────────────────────────┐
│                     Entity 实体类                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │ #[ApiResource]│  │  #[Groups]   │  │ #[ApiProperty]│  │
│  │  路由声明     │  │  序列化分组   │  │  文档元数据   │  │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  │
└─────────┼─────────────────┼─────────────────┼──────────┘
          │                 │                 │
          ▼                 ▼                 ▼
┌──────────────────────────────────────────────────────────┐
│                    API Platform 引擎                     │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  │
│  │  路由加载器   │  │  序列化器     │  │  OpenAPI 工厂 │  │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘  │
└─────────┼─────────────────┼─────────────────┼──────────┘
          │                 │                 │
          ▼                 ▼                 ▼
    HTTP 路由        请求/响应序列化      /api/docs 文档
```

**核心设计原则：单一事实源（Single Source of Truth）**
- 实体类是唯一的真相来源
- 路由、序列化、文档都从实体的属性声明中派生
- 修改实体属性即可同步更新三者

---

## 路由注册机制

### 1. 路由配置入口（三级加载机制）

API 路由通过 Symfony 路由配置的三级加载机制生效：

| 层级 | 文件路径 | 作用 |
|------|----------|------|
| 1 | `config/routes/api_platform.php` | 全局 API 路由入口，设置 `/api` 前缀，导入 ApiBundle 路由 |
| 2 | `src/ApiBundle/Resources/config/routing.php` | 导入 `api_platform` 路由加载器，自动扫描所有 `#[ApiResource]` 实体 |
| 3 | 各 Bundle 的 Entity 类 | 通过 `#[ApiResource]` 属性声明具体的路由和操作 |

**证据代码 1.1：全局 API 路由入口**
> 文件：`config/routes/api_platform.php`，第 16-19 行
>
> 导入 ApiBundle 的路由配置，并统一加上 `/api` 前缀。

```php
return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator->import('@SolidInvoiceApiBundle/Resources/config/routing.php')
        ->prefix('/api');
};
```

**证据代码 1.2：ApiBundle 路由加载**
> 文件：`src/ApiBundle/Resources/config/routing.php`，第 16-20 行
>
> 关键行：第 19 行 `$routingConfigurator->import('.', 'api_platform');`
>
> `api_platform` 是 API Platform 提供的特殊路由加载器，它会扫描所有标记了 `#[ApiResource]` 的实体类，根据声明的 operations 生成对应路由。

```php
return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator->add('api_login_check', '/login');

    $routingConfigurator->import('.', 'api_platform');
};
```

### 2. ApiResource 路由声明的三种方式

每个 API 资源通过实体类上的 `#[ApiResource]` 属性声明路由。以 `Invoice` 实体（`src/InvoiceBundle/Entity/Invoice.php`）为例，该实体上声明了 **三个** `#[ApiResource]`，分别对应三种路由模式。

#### 方式一：标准 CRUD 资源

**证据代码 2.1：标准 CRUD 资源声明**
> 文件：`src/InvoiceBundle/Entity/Invoice.php`，第 68-78 行
>
> 第一个 `#[ApiResource]`，声明了 5 个标准操作，使用默认 URI 路径（由类名自动转换）。
> 两个上下文（normalizationContext / denormalizationContext）分别控制读写字段。

```php
#[ApiResource(
    operations: [new GetCollection(), new Get(), new Post(), new Patch(), new Delete()],
    normalizationContext: [
        'groups' => ['invoice_api:read'],
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
    denormalizationContext: [
        'groups' => ['invoice_api:write'],
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
)]
```

**生成的路由：**
| HTTP 方法 | 路径 | 对应 Operation |
|-----------|------|----------------|
| GET | `/api/invoices` | `GetCollection` |
| GET | `/api/invoices/{id}` | `Get` |
| POST | `/api/invoices` | `Post` |
| PATCH | `/api/invoices/{id}` | `Patch` |
| DELETE | `/api/invoices/{id}` | `Delete` |

#### 方式二：子资源（嵌套路由）

**证据代码 2.2：子资源嵌套路由**
> 文件：`src/InvoiceBundle/Entity/Invoice.php`，第 79-96 行
>
> 第二个 `#[ApiResource]`，使用 `uriTemplate` 自定义路径，通过 `Link` 建立与 `Client` 的关联。
> 这是一个**子资源**：通过客户 ID 获取该客户的所有发票。

```php
#[ApiResource(
    uriTemplate: '/clients/{clientId}/invoices',
    operations: [new GetCollection()],
    uriVariables: [
        'clientId' => new Link(
            fromProperty: 'invoices',
            fromClass: Client::class,
        ),
    ],
    normalizationContext: [
        'groups' => ['invoice_api:read'],
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
    denormalizationContext: [
        'groups' => ['invoice_api:write'],
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ]
)]
```

**生成的路由：** `GET /api/clients/{clientId}/invoices` — 获取指定客户的所有发票

#### 方式三：自定义操作路由（状态转换）

**证据代码 2.3：自定义状态转换操作**
> 文件：`src/InvoiceBundle/Entity/Invoice.php`，第 97-115 行
>
> 第三个 `#[ApiResource]`，使用 `uriTemplate` 定义状态转换路径。
>
> 关键参数：
> - `provider` / `processor`：自定义数据读取和业务逻辑处理类
> - `input: false`：**不接受请求体输入**（状态转换只需 URL 中的 transition 参数，不需要 POST body）
> - `output: Invoice::class`：输出仍是 Invoice 实体（用 read 组序列化）

```php
#[ApiResource(
    uriTemplate: '/invoices/{id}/transitions/{transition}',
    operations: [
        new Post(
            name: 'invoice_transition',
            provider: InvoiceTransitionProvider::class,
            processor: InvoiceTransitionProcessor::class,
            input: false,
            output: Invoice::class,
        ),
    ],
    uriVariables: [
        'id' => new Link(fromClass: Invoice::class),
    ],
    normalizationContext: [
        'groups' => ['invoice_api:read'],
        AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
    ],
)]
```

**生成的路由：** `POST /api/invoices/{id}/transitions/{transition}` — 应用状态转换

> 💡 **注意**：`input: false` 是一个容易被忽略的重要参数。它表示该操作不接受反序列化输入（即请求体不会被反序列化为实体），状态参数通过 URL 路径 `{transition}` 传递，在 Processor 中从 Request attributes 中读取。

### 3. 路径命名规则

路径生成由 `pathSegmentNameGenerator` 配置项控制。

**证据代码 3.1：路径生成器配置**
> 文件：`config/packages/api_platform.php`，第 24 行
>
> 使用 `dash` 生成器（kebab-case 命名风格），将类名转换为 URL 路径。

```php
$config->pathSegmentNameGenerator('api_platform.metadata.path_segment_name_generator.dash');
```

**命名转换示例：**
| 实体类名 | 生成的路径段 |
|----------|-------------|
| `Invoice` | `invoices` |
| `RecurringInvoice` | `recurring-invoices` |
| `AdditionalContactDetail` | `additional-contact-details` |
| `ApiToken` | `api-tokens` |

### 4. State Provider/Processor 扩展机制

对于非标准 CRUD 操作（如状态转换），API Platform 通过 **State Provider**（读取数据）和 **State Processor**（处理写入/业务逻辑）模式扩展。

**证据代码 4.1：InvoiceTransitionProvider（数据读取）**
> 文件：`src/ApiBundle/State/Provider/InvoiceTransitionProvider.php`，第 22-40 行
>
> Provider 负责根据 URI 变量获取数据。`provide` 方法接收 `$uriVariables`（包含路径参数），返回实体对象。
> 状态转换操作中，Provider 的作用就是根据 ID 查出发票实体。

```php
/** @implements ProviderInterface<Invoice> */
final class InvoiceTransitionProvider implements ProviderInterface
{
    public function __construct(
        private readonly InvoiceRepository $repository
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Invoice
    {
        $invoice = $this->repository->findOneBy(['id' => $uriVariables['id']]);

        if (! $invoice instanceof Invoice) {
            throw new NotFoundHttpException(sprintf('Invoice "%s" not found.', $uriVariables['id']));
        }

        return $invoice;
    }
}
```

**证据代码 4.2：InvoiceTransitionProcessor（业务逻辑）**
> 文件：`src/ApiBundle/State/Processor/InvoiceTransitionProcessor.php`，第 24-49 行
>
> Processor 负责执行业务逻辑。`process` 方法接收 Provider 提供的数据，处理后返回。
>
> 注意：transition 参数从 Request attributes 中读取（因为 `input: false`，不从请求体读取）。

```php
/** @implements ProcessorInterface<Invoice, Invoice> */
final class InvoiceTransitionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly WorkflowInterface $invoiceStateMachine,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Invoice
    {
        assert($data instanceof Invoice);

        $transition = (string) ($context['request']?->attributes->get('transition') ?? '');

        if (! $this->invoiceStateMachine->can($data, $transition)) {
            throw new UnprocessableEntityHttpException(
                sprintf('Transition "%s" cannot be applied to invoice in status "%s".', $transition, $data->getStatus()?->value ?? 'unknown')
            );
        }

        $this->invoiceStateMachine->apply($data, $transition);
        $this->registry->getManager()->flush();

        return $data;
    }
}
```

**证据代码 4.3：自动注册配置**
> 文件：`src/ApiBundle/Resources/config/services/services.php`，第 21-32 行
>
> Provider 和 Processor 通过 `autoconfigure` 和 `autowire` 自动注册到 DI 容器。
> 第 31 行的 `load` 会自动加载 ApiBundle 下所有类，排除 Entity/Resources/Tests 等目录。

```php
$services->defaults()
    ->autowire()
    ->autoconfigure()
    ->private()
    ->bind('$invoiceStateMachine', service('state_machine.invoice'))
    ->bind('$quoteStateMachine', service('state_machine.quote'))
    ->bind('$recurringInvoiceStateMachine', service('state_machine.recurring_invoice'))
;

$services
    ->load(SolidInvoiceApiBundle::NAMESPACE . '\\', dirname(__DIR__, 3))
    ->exclude(dirname(__DIR__, 3) . '/{DependencyInjection,Entity,Resources,Tests}');
```

---

## 序列化器声明体系

### 1. 序列化组（Serialization Groups）

SolidInvoice 使用 **Symfony Serializer** 组件，通过 `#[Groups]` 属性控制字段的序列化/反序列化行为。

> 💡 小提示：不同实体的 Groups 属性导入方式可能不同：
> - Invoice 实体：`use Symfony\Component\Serializer\Attribute\Groups;` → `#[Groups(...)]`
> - Client 实体：`use Symfony\Component\Serializer\Attribute as Serialize;` → `#[Serialize\Groups(...)]`
>
> 本质是同一个东西，只是别名不同。

#### 命名约定

采用 `{资源}_api:{read|write}` 模式：
- `invoice_api:read` — 发票读取时包含的字段（响应输出）
- `invoice_api:write` — 发票写入时接受的字段（请求输入）
- `client_api:read` — 客户读取时包含的字段
- `searchable` — 搜索/索引专用组（不用于 API 输出）

**证据代码 5.1：字段级序列化组声明**
> 文件：`src/InvoiceBundle/Entity/Invoice.php`，第 125-154 行
>
> 不同字段分配不同的序列化组，控制字段在请求和响应中的可见性。
> `#[ApiProperty(writable: false)]` 进一步标记字段为只读（在文档和反序列化中生效）。

```php
// 只读字段：仅出现在 read 组中 + ApiProperty 标记不可写
#[ORM\Column(name: 'status', type: Types::STRING, length: 25, enumType: InvoiceStatus::class)]
#[Groups(['invoice_api:read', 'searchable'])]
#[ApiProperty(writable: false)]
protected ?InvoiceStatus $status = null;

// 只读字段：ID
#[ORM\Column(name: 'id', type: UlidType::NAME)]
#[ORM\Id]
#[ORM\GeneratedValue(strategy: 'CUSTOM')]
#[ORM\CustomIdGenerator(class: UlidGenerator::class)]
#[Groups(['invoice_api:read', 'searchable'])]
private ?Ulid $id = null;

// 读写字段：同时出现在 read 和 write 组中
#[ORM\Column(name: 'invoice_id', type: Types::STRING, length: 255)]
#[Groups(['invoice_api:read', 'invoice_api:write', 'searchable'])]
private string $invoiceId = '';

// 只读字段 + 不可写标记
#[ORM\Column(name: 'uuid', type: Types::STRING, length: 36)]
#[Groups(['invoice_api:read'])]
#[ApiProperty(writable: false)]
private ?string $uuid = null;

// 读写字段：关联客户
#[ApiProperty(
    example: '/api/clients/3fa85f64-5717-4562-b3fc-2c963f66afa6',
    iris: ['https://schema.org/Organization']
)]
#[ORM\ManyToOne(targetEntity: Client::class, cascade: ['persist'], inversedBy: 'invoices')]
#[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
#[Assert\NotBlank]
#[Groups(['invoice_api:read', 'invoice_api:write', 'searchable'])]
private ?Client $client = null;
```

### 2. 序列化上下文配置

每个 `#[ApiResource]` 都声明了独立的序列化上下文（normalizationContext 和 denormalizationContext），定义该操作使用哪些组。

**证据代码 6.1：序列化上下文声明**
> 文件：`src/InvoiceBundle/Entity/Invoice.php`，第 70-77 行
>
> `normalizationContext` 用于响应序列化（实体 → JSON），`denormalizationContext` 用于请求反序列化（JSON → 实体）。
> 两者使用不同的 groups，实现读写字段分离。

```php
normalizationContext: [
    'groups' => ['invoice_api:read'],
    AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
],
denormalizationContext: [
    'groups' => ['invoice_api:write'],
    AbstractObjectNormalizer::SKIP_NULL_VALUES => false,
],
```

| 上下文 | 方向 | 关键选项 |
|--------|------|----------|
| `normalizationContext` | 响应（实体 → JSON） | `groups` — 允许的字段组 |
| `denormalizationContext` | 请求（JSON → 实体） | `groups` — 可写入的字段组 |

**常用上下文选项：**
- `SKIP_NULL_VALUES` — 是否跳过 null 值字段（本项目设为 `false`，即保留 null）
- `SKIP_UNINITIALIZED_VALUES` — 是否跳过未初始化的属性（Client 实体的 read 上下文中设为 `true`）

### 3. 自定义 Normalizer

对于无法通过 `#[Groups]` 处理的复杂类型，项目实现了自定义 Normalizer，通过 `#[AutoconfigureTag('serializer.normalizer')]` 自动注册到序列化器链中。

**所有自定义 Normalizer 位于：** `src/ApiBundle/Serializer/Normalizer/`

| Normalizer 文件 | 处理类型 | 核心特点 |
|-----------------|----------|---------|
| `DiscountNormalizer.php` | `Discount` | 将折扣对象序列化为 `{type, value}` 结构 |
| `CreditNormalizer.php` | `Credit` | 金额分/元转换；**反序列化时累加而非替换** |
| `BigIntegerNormalizer.php` | `BigNumber` | 金额分/元转换（处理所有 BigNumber 子类） |
| `AdditionalContactDetailsNormalizer.php` | `AdditionalContactDetail` | 将联系详情转为 `{type, value}` 结构，type 用名称而非实体 |

**证据代码 7.1：DiscountNormalizer**
> 文件：`src/ApiBundle/Serializer/Normalizer/DiscountNormalizer.php`，第 26-70 行
>
> 实现了 `NormalizerInterface` 和 `DenormalizerInterface`，通过 `#[AutoconfigureTag]` 自动注册。
> `getSupportedTypes()` 方法声明支持的类型（PHP 8.2+ 特性，用于优化序列化器缓存）。

```php
#[AutoconfigureTag('serializer.normalizer')]
final class DiscountNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): Discount
    {
        $discount = new Discount();
        $discount->setType($data['type'] ?? null);
        $discount->setValue($data['value'] ?? null);
        return $discount;
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return Discount::class === $type;
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        return [
            'type' => $object->getType(),
            'value' => $object->getValue(),
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Discount;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Discount::class => true,
        ];
    }
}
```

#### BigIntegerNormalizer：金额的分/元转换

金额字段是最容易出现"文档与实际不一致"的地方，因为它在不同层有不同的表示：

- **数据库层**：以**分**为单位存储（BigNumber，整数）
- **实体层**：`BigNumber` 类型对象
- **API 层**：以**元**为单位展示（浮点数）

**证据代码 7.2：BigIntegerNormalizer 转换逻辑**
> 文件：`src/ApiBundle/Serializer/Normalizer/BigIntegerNormalizer.php`，第 27-73 行
>
> 注意：
> - 文件名叫 BigIntegerNormalizer，但实际支持的是 `BigNumber` 基类（通过 `is_a($type, BigNumber::class, true)` 检查），支持所有 BigNumber 子类
> - 两个关键上下文标记：
>   - `api_denormalize` — API Platform 在反序列化时自动设置，触发"元 → 分"转换
>   - `api_attribute` — API Platform 在序列化时自动设置，触发"分 → 元"转换

```php
#[AutoconfigureTag('serializer.normalizer')]
final class BigIntegerNormalizer implements NormalizerInterface, DenormalizerInterface
{
    // 反序列化（写入）：元 → 分（乘以 100）
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): BigNumber
    {
        $data = is_float($data) ? (string) $data : $data;

        if ($context['api_denormalize'] ?? false) {
            return BigNumber::of($data)->toBigDecimal()->multipliedBy(100);
        }

        return BigNumber::of($data);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_a($type, BigNumber::class, true);
    }

    // 序列化（读取）：分 → 元（除以 100，保留 2 位小数）
    public function normalize(mixed $object, ?string $format = null, array $context = []): float
    {
        if (isset($context['api_attribute'])) {
            return $object->toBigDecimal()->dividedBy(100, 2, RoundingMode::HalfEven)->toFloat();
        }

        return $object->toBigDecimal()->toFloat();
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof BigNumber;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            BigNumber::class => true,
        ];
    }
}
```

#### CreditNormalizer 的特殊累加行为

**证据代码 7.3：CreditNormalizer 的累加逻辑**
> 文件：`src/ApiBundle/Serializer/Normalizer/CreditNormalizer.php`，第 36-48 行
>
> ⚠️ **重要特殊行为**：Credit 反序列化时，如果已存在 Credit 对象（`OBJECT_TO_POPULATE`），则是**累加**（plus）而不是替换。
> 这意味着通过 API 更新客户积分时，传入的值会被加到现有积分上，而不是覆盖。

```php
public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
{
    if ($type === Credit::class) {
        $data = is_float($data) ? (string) $data : $data;
        $delta = BigNumber::of($data)->toBigDecimal()->multipliedBy(100);
        $existing = $context[AbstractObjectNormalizer::OBJECT_TO_POPULATE] ?? null;

        // 如果已有 Credit，则累加，不是替换！
        if ($existing instanceof Credit) {
            return $existing->setValue($existing->getValue()->toBigDecimal()->plus($delta));
        }

        return (new Credit())->setValue($delta);
    }

    return $this->denormalizer->denormalize($data, $type, $format, $context);
}
```

> ⚠️ **重要对齐提示**：由于 BigIntegerNormalizer 改变了字段的实际输出类型（从 BigNumber 对象变为浮点数），必须在 `#[ApiProperty]` 中通过 `openapiContext` 手动指定类型为 `number`，否则 OpenAPI 文档会错误地推断类型。

**Invoice 实体中的正确示范**（`src/InvoiceBundle/Entity/Invoice.php`，第 156-167 行）：
```php
#[ORM\Column(name: 'balance_amount', type: BigIntegerType::NAME)]
#[Groups(['invoice_api:read'])]
#[ApiProperty(
    writable: false,
    openapiContext: [
        'type' => 'number',
    ],
    jsonSchemaContext: [
        'type' => 'number',
    ]
)]
private BigNumber $balance;
```

---

## OpenAPI 文档生成

### 1. 文档生成的信息来源

OpenAPI 文档由 API Platform 自动生成，信息从多个来源聚合：

| 信息来源 | 提供的文档内容 | 对应代码元素 |
|----------|---------------|-------------|
| `#[ApiResource]` | 路径、HTTP 方法、请求/响应类型 | 实体类上的属性 |
| `#[Groups]` | 请求/响应字段列表 | 实体属性上的属性 |
| `#[ApiProperty]` | 字段描述、示例值、是否可写、Schema 自定义 | 实体属性上的属性 |
| PHP 类型声明 | 字段类型、是否可空 | 实体属性的类型声明 |
| `#[ApiFilter]` | 查询过滤参数 | 实体类上的属性 |
| PHP 枚举（Enum） | 字段可选值列表 | 枚举类型字段 |
| 验证约束（Assert） | 字段验证规则（长度、非空等） | `#[Assert\*]` 属性 |

### 2. ApiProperty — 连接代码与文档的关键桥梁

`#[ApiProperty]` 属性是控制 OpenAPI 文档中字段展示的核心机制。

**证据代码 8.1：ApiProperty 常用选项**
> 综合自 `src/InvoiceBundle/Entity/Invoice.php` 和 `src/ClientBundle/Entity/Client.php`
>
> 展示了 `writable`、`example`、`openapiContext`、`iris`、`writableLink` 等常用选项。

```php
// 选项 1：标记为只读（不在 POST/PATCH 请求体 schema 中出现）
#[Groups(['invoice_api:read'])]
#[ApiProperty(writable: false)]
protected ?InvoiceStatus $status = null;

// 选项 2：提供示例值 + 语义化 IRI
#[ApiProperty(
    example: '/api/clients/3fa85f64-5717-4562-b3fc-2c963f66afa6',
    iris: ['https://schema.org/Organization']
)]
private ?Client $client = null;

// 选项 3：自定义 OpenAPI Schema — 金额字段指定为 number 类型
#[ApiProperty(
    openapiContext: ['type' => 'number'],
    jsonSchemaContext: ['type' => 'number'],
)]
private BigNumber $balance;

// 选项 4：自定义 OpenAPI Schema — oneOf 类型（Client.currencyCode）
#[ApiProperty(
    openapiContext: [
        'type' => [
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'null'],
            ],
        ],
    ],
    jsonSchemaContext: [
        'type' => [
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'null'],
            ],
        ],
    ],
)]
private ?string $currencyCode = null;

// 选项 5：允许通过 IRI 写入关联资源
#[ApiProperty(writableLink: true)]
private Collection $users;
```

**各选项对文档的影响：**

| 选项 | 影响的文档部分 | 说明 |
|------|--------------|------|
| `writable: false` | Request Schema | 字段不出现在 POST/PATCH 的请求体中 |
| `example` | Schema example | 文档中显示该示例值 |
| `openapiContext` | Schema 定义 | 覆盖自动推断的类型/格式 |
| `writableLink: true` | Request Schema | 允许通过 IRI 字符串写入关联资源 |
| `iris` | JSON-LD 类型 | 声明语义化类型（JSON-LD/Hydra 格式使用） |

### 3. OpenApiFactory 装饰器 — 文档自定义增强

项目通过装饰器模式（Decorator Pattern）对 API Platform 自动生成的 OpenAPI 文档进行自定义增强。

**证据代码 9.1：OpenApiFactory 装饰器**
> 文件：`src/ApiBundle/OpenApi/OpenApiFactory.php`，第 23-66 行
>
> 通过 `#[AsDecorator]` 装饰 `api_platform.openapi.factory` 服务。
> 优先级设为 -1（低优先级），确保在 LexikJWT 等其他装饰器之后执行。
>
> 主要做两件事：
> 1. 为每个 Tag 添加描述文本（8 个资源的描述）
> 2. 设置 Server URL（根据 `_home` 路由生成绝对 URL）

```php
#[AsDecorator(
    decorates: 'api_platform.openapi.factory',
    priority: -1
)]
final class OpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = $this->decorated->__invoke($context);

        $descriptions = [
            'Invoice' => 'Manage invoices and their lifecycle transitions',
            'Quote' => 'Manage quotes and convert them to invoices',
            'Client' => 'Manage clients, their contacts, and credit',
            'Contact' => 'Manage contacts belonging to a client',
            'Payment' => 'Track and record payments against invoices',
            'Tax' => 'Manage tax rates applied to invoice lines',
            'RecurringInvoice' => 'Manage recurring invoice templates and generate invoices from them',
            'ApiToken' => 'Manage API tokens for authentication',
        ];

        $tags = array_map(
            static fn (Tag $tag) => new Tag($tag->getName(), $descriptions[$tag->getName()] ?? $tag->getDescription()),
            $openApi->getTags(),
        );

        return $openApi
            ->withServers([
                new Server($this->urlGenerator->generate('_home', [], UrlGeneratorInterface::ABSOLUTE_URL)),
            ])
            ->withTags($tags);
    }
}
```

### 4. Swagger UI 配置

**证据代码 10.1：Swagger UI 配置**
> 文件：`config/packages/api_platform.php`，第 73-84 行
>
> 配置 Swagger UI 的行为和 API 认证方式（X-API-TOKEN header）。

```php
$config->swagger()
    ->versions([3])
    ->swaggerUiExtraConfiguration([
        'filter' => true,              // 启用过滤搜索框
        'docExpansion' => 'none',      // 默认折叠所有操作
        'defaultModelsExpandDepth' => 0, // 模型默认不展开
        'persistAuthorization' => true, // 持久化授权信息（刷新页面不丢失）
        'tagsSorter' => 'alpha',       // 标签按字母排序
    ])
    ->apiKeys('bearer')
    ->name('X-API-TOKEN')
    ->type('header');
```

### 5. 全局 API 描述文本

**证据代码 11.1：API 描述配置**
> 文件：`config/packages/api_platform.php`，第 97-198 行
>
> 动态生成 API 文档的描述文本。第 97-110 行是前置准备代码（获取版本和格式列表），第 113 行开始正式调用 `$config->description()`。
>
> 描述内容包括：认证说明、分页说明、错误处理、格式支持、速率限制、版本策略、集成指南等。

```php
// 第 97-110 行：准备动态内容
$array = $config->toArray();
$versions = implode("\n* ", $array['swagger']['versions']);
$formats = $array['formats'];
// ... 构建 $formatDesc 字符串 ...

// 第 113 行开始：设置描述文本
$config->description(
    <<<DESC
SolidInvoice is a simple open source invoicing application aimed to help small businesses and freelancers manage their day-to-day billing.

### Authentication
SolidInvoice uses an API tokens for authentication.
To authenticate, you need to create an API token and set the `X-API-TOKEN` header to your API token.
...
DESC
);
```

#### ⚠️ 文档描述与实际行为的不一致（重要发现）

在描述文本的最后一段（第 195-196 行）有这样的说明：

> "All monetary amounts are represented as **integers in the smallest currency unit** (e.g., cents for USD/EUR). For example, `1000` represents `$10.00`."

但实际上，**BigIntegerNormalizer 会将金额从分转换为元输出**（浮点数），所以 API 返回的金额是 `10.00`（元）而不是 `1000`（分）。

**这是一个文档描述与实际序列化行为不一致的案例，需要注意。** 在实际使用中，应该以实际 API 返回为准（金额以元为单位，浮点数）。

### 6. 文档访问路径

| 格式 | 路径 | 说明 |
|------|------|------|
| HTML (Swagger UI) | `/api/docs` | 交互式文档界面 |
| JSON | `/api/docs.json` | OpenAPI 3.0 JSON 格式 |
| JSON-LD | `/api/docs.jsonld` | JSON-LD 格式 |
| XML | `/api/docs.xml` | XML 格式 |
| JSON OpenAPI | `/api/docs.jsonopenapi` | `application/vnd.openapi+json` 格式 |

---

## 三者协作的完整流程

### 1. 请求生命周期（运行时）

以 `GET /api/invoices/{id}` 为例，展示从路由匹配到响应输出的完整流程：

```
① 路由匹配
   │  Symfony Routing 组件匹配请求 URL
   │  找到对应的 ApiResource Operation
   │
   ▼
② 加载 #[ApiResource] 元数据
   │  ├─ 确定 Operation 类型（Get）
   │  ├─ 确定 Provider 类（默认 Doctrine 提供器）
   │  └─ 读取 normalizationContext（groups: ['invoice_api:read']）
   │
   ▼
③ State Provider 获取数据
   │  调用 Provider 的 provide() 方法
   │  从数据库查询 Invoice 实体
   │
   ▼
④ 序列化响应（Normalization）
   │  ├─ 使用 normalizationContext 中的 groups 过滤字段
   │  ├─ 遍历实体属性，只保留在 invoice_api:read 组中的属性
   │  └─ 调用匹配的 Normalizer 处理复杂类型
   │      ├─ BigIntegerNormalizer：金额字段 分→元
   │      ├─ DiscountNormalizer：折扣对象序列化
   │      └─ ...
   │
   ▼
⑤ 返回 JSON 响应
   带有 Content-Type: application/ld+json（默认格式）
```

### 2. 文档生成流程（编译期/首次访问）

OpenAPI 文档生成是一个**元数据收集**过程，发生在缓存预热或首次访问 `/api/docs` 时：

```
① 扫描所有 #[ApiResource] 实体
   │  API Platform 的 ResourceMetadataCollectionFactory
   │  遍历所有实体类，收集 ApiResource 属性
   │
   ▼
② 为每个 Resource 生成 Path Item
   │  ├─ 从 operations 数组生成 HTTP 方法条目
   │  ├─ 从 uriTemplate（或默认路径）生成 URL 路径
   │  └─ 从 uriVariables 生成路径参数定义
   │
   ▼
③ 生成 Schema（请求/响应模型）
   │  ├─ Response Schema（从 normalizationContext 生成）
   │  │   └─ 遍历实体所有属性
   │  │       ├─ 检查属性是否在 read groups 中
   │  │       ├─ 从 PHP 类型推断 JSON Schema 类型
   │  │       ├─ 从 #[ApiProperty] 读取描述/示例/是否可写
   │  │       ├─ 从枚举类型获取可选值列表
   │  │       └─ 从验证约束补充格式约束
   │  │
   │  └─ Request Schema（从 denormalizationContext 生成）
   │      └─ 遍历实体所有属性
   │          ├─ 检查属性是否在 write groups 中
   │          └─ 检查 ApiProperty 的 writable 属性
   │
   ▼
④ 应用 OpenApiFactory 装饰器链
   │  依次调用所有装饰 api_platform.openapi.factory 的服务
   │  ├─ 添加 Server URL
   │  ├─ 补充 Tag 描述
   │  └─ 其他自定义修改
   │
   ▼
⑤ 输出完整的 OpenAPI 文档
   缓存到缓存目录，后续请求直接使用
```

### 3. 五个关键对齐点

路由、序列化、文档三者通过以下机制确保**天然一致**：

| 对齐点 | 实现机制 | 代码位置 |
|--------|----------|----------|
| **路径一致性** | `uriTemplate` 同时用于路由注册和文档生成 | 实体类 `#[ApiResource]` 的 `uriTemplate` 参数 |
| **字段一致性** | `#[Groups]` 同时控制序列化输出和文档字段列表 | 实体属性上的 `#[Groups]` 属性 |
| **读写一致性** | `normalizationContext` / `denormalizationContext` 分别对应读/写 schema | `#[ApiResource]` 的两个上下文参数 |
| **类型一致性** | PHP 类型 + 自定义 Normalizer + `openapiContext` 三重保障 | 属性类型声明、Normalizer、`#[ApiProperty]` |
| **操作一致性** | `operations` 数组同时定义路由和文档操作 | `#[ApiResource]` 的 `operations` 参数 |

---

## 对齐验证：如何确保一致性

### 1. 常见不一致风险与防范

| 风险场景 | 表现 | 如何避免 | 验证方法 |
|----------|------|----------|----------|
| 修改了 `#[Groups]` 但忘记同步文档描述 | 文档字段列表与实际 API 返回不一致 | `#[ApiProperty]` 与 `#[Groups]` 放在一起修改，改组必改描述 | 对比 `/api/docs.json` 中的 schema 与实际响应 |
| 自定义 Normalizer 改变了输出格式，但 OpenAPI Schema 未更新 | 文档显示类型与实际响应类型不符（如金额字段） | 在 `#[ApiProperty]` 中使用 `openapiContext` 手动指定正确类型 | 检查金额字段的 schema type 是否为 `number` |
| 新增/删除了 `#[ApiResource]` 操作，但忘记实现业务逻辑 | 路由存在但调用失败 | 添加操作时同时实现对应的 Provider/Processor，并添加测试 | `bin/console debug:router` 查看路由，编写 API 测试 |
| 修改了 URI 模板，但客户端代码未同步 | 客户端调用 404 | 使用 API 版本管理策略，破坏性变更走 Sunset 周期 | 对比前后版本的 OpenAPI 文档差异 |
| 枚举新增了值，但文档未更新 | 文档枚举值不全 | 使用 PHP 原生枚举（Enum），API Platform 自动读取 | 检查 OpenAPI 文档中 enum 数组是否完整 |
| 描述文本（description）与实际行为不一致 | 文档误导使用者 | 修改序列化/业务逻辑时，同步检查并更新描述文本 | 实际调用 API 与文档描述对比 |

### 2. 金额类型的特殊对齐（重点关注）

金额字段（BigNumber/Brick\Math）是最容易出现文档与实际不一致的地方，因为涉及三层转换：

```
数据库层（分，整数） → 实体层（BigNumber 对象） → API层（元，浮点数）
                              ↑
                       BigIntegerNormalizer
                       负责中间的转换
```

**正确的对齐方式（以 Invoice.balance 为例）：**
```php
// 1. 实体属性声明为 BigNumber 类型
use Brick\Math\BigNumber;
use SolidInvoice\CoreBundle\Doctrine\Type\BigIntegerType;

#[ORM\Column(name: 'balance_amount', type: BigIntegerType::NAME)]
#[Groups(['invoice_api:read'])]

// 2. 必须手动指定 openapiContext 类型为 number
//    （否则文档会推断为 object 或 integer，与实际返回的 float 不一致）
#[ApiProperty(
    writable: false,
    openapiContext: ['type' => 'number'],
    jsonSchemaContext: ['type' => 'number'],
)]
private BigNumber $balance;
```

### 3. 验证方法清单

可以通过以下方式验证路由、序列化与文档的一致性：

| 验证方式 | 命令/操作 | 验证内容 |
|----------|----------|----------|
| 查看路由列表 | `bin/console debug:router` | 确认所有预期的 API 路由都已注册 |
| 导出现有文档 | `bin/console api:openapi:export` | 导出生成的 OpenAPI 规范，检查字段/类型 |
| 访问 JSON 文档 | 浏览器访问 `/api/docs.json` | 查看实际生成的 OpenAPI JSON |
| 编写 API 功能测试 | PHPUnit + ApiTestCase | 验证实际 API 响应与文档描述一致 |
| 静态分析 | `bin/phpstan analyse` | 确保类型声明正确 |

---

## 关键文件索引

### 配置文件
| 文件路径 | 作用 |
|----------|------|
| `config/packages/api_platform.php` | API Platform 主配置（格式、Swagger、描述等） |
| `config/routes/api_platform.php` | 全局 API 路由入口（/api 前缀） |
| `src/ApiBundle/Resources/config/routing.php` | ApiBundle 路由配置（api_platform 加载器） |
| `src/ApiBundle/Resources/config/services/services.php` | ApiBundle 服务配置（autowire/autoconfigure） |

### 核心实体（带 ApiResource 声明）
| 文件路径 | 资源名称 | 特点 |
|----------|---------|------|
| `src/InvoiceBundle/Entity/Invoice.php` | Invoice | 最完整的示例：3 个 ApiResource、状态转换、金额 openapiContext |
| `src/ClientBundle/Entity/Client.php` | Client | 包含 currencyCode 字段的 oneOf openapiContext、SKIP_UNINITIALIZED_VALUES |
| `src/QuoteBundle/Entity/Quote.php` | Quote | 报价资源 |
| `src/PaymentBundle/Entity/Payment.php` | Payment | 支付记录资源 |
| `src/TaxBundle/Entity/Tax.php` | Tax | 税率资源 |
| `src/UserBundle/Entity/ApiToken.php` | ApiToken | API 令牌资源 |

### 自定义 Normalizer
| 文件路径 | 处理类型 |
|----------|---------|
| `src/ApiBundle/Serializer/Normalizer/DiscountNormalizer.php` | `Discount` |
| `src/ApiBundle/Serializer/Normalizer/CreditNormalizer.php` | `Credit`（反序列化累加） |
| `src/ApiBundle/Serializer/Normalizer/BigIntegerNormalizer.php` | `BigNumber`（分/元转换） |
| `src/ApiBundle/Serializer/Normalizer/AdditionalContactDetailsNormalizer.php` | `AdditionalContactDetail` |

### State Provider / Processor
| 目录 | 说明 |
|------|------|
| `src/ApiBundle/State/Provider/` | 所有 State Provider 类 |
| `src/ApiBundle/State/Processor/` | 所有 State Processor 类 |

### OpenAPI 文档相关
| 文件路径 | 作用 |
|----------|------|
| `src/ApiBundle/OpenApi/OpenApiFactory.php` | OpenAPI 文档装饰器（自定义 Tag 描述、Server URL） |

---

## 复核记录

> 本节记录本次逐项复核的结果，供后续参考。

### 复核范围
- 路由注册机制：3 个配置文件 + Invoice 实体的 3 个 ApiResource
- 序列化器声明：Groups 使用方式、4 个自定义 Normalizer、2 个上下文类型
- OpenAPI 文档生成：ApiProperty 选项、OpenApiFactory 装饰器、Swagger 配置、描述文本

### 复核发现并修正的问题

| 序号 | 问题 | 原描述 | 修正后 |
|------|------|--------|--------|
| 1 | 行号偏差 | 证据 11.1 从第 99 行开始 | 实际从第 113 行开始（第 97-110 行是前置准备代码） |
| 2 | 类型不准确 | BigIntegerNormalizer 处理 `BigInteger` | 实际处理 `BigNumber` 基类（通过 `is_a` 支持所有子类） |
| 3 | 机制遗漏 | 未提到状态转换操作 `input: false` | 补充了 `input: false` 的含义（不接受请求体输入） |
| 4 | 机制遗漏 | 未提到 CreditNormalizer 的累加行为 | 补充了 CreditNormalizer 反序列化时累加而非替换的特殊行为 |
| 5 | 重要不一致 | 未提到文档描述与实际金额格式的矛盾 | 补充了描述文本声称"整数分"但实际是"浮点数元"的不一致案例 |
| 6 | 证据补充 | ApiProperty 例子不够丰富 | 增加了 Client.currencyCode 的 oneOf openapiContext 示例 |
| 7 | 细节补充 | 未提到 Groups 属性的不同导入方式 | 补充了 Invoice 用 `#[Groups]`、Client 用 `#[Serialize\Groups]` 的说明 |

### 复核结论

整体机制描述准确，核心结论正确。主要补充了几个容易被忽略但对理解"文档与代码一致性"很重要的细节：
- `input: false` 对文档和行为的影响
- CreditNormalizer 的累加语义（容易踩坑的地方）
- 全局描述文本与实际序列化行为的不一致（需要注意的文档债务）
