# SolidInvoice 模板组件与表单协作机制深度解析

本文档深入分析 SolidInvoice 项目中前端模板组件、布局继承与后端表单数据传递的协作机制，帮助开发者理解从后端 Action 到前端渲染的完整数据流。

---

## 目录

1. [整体架构概览](#整体架构概览)
2. [布局继承体系](#布局继承体系)
3. [Twig 组件系统](#twig-组件系统)
4. [表单数据传递的两种模式](#表单数据传递的两种模式)
5. [完整协作流程案例分析](#完整协作流程案例分析)
6. [表单主题定制](#表单主题定制)
7. [关键设计模式总结](#关键设计模式总结)
8. [文件索引](#文件索引)

---

## 整体架构概览

SolidInvoice 采用 **三层架构** 实现从后端数据到前端界面的渲染：

```
┌─────────────────────────────────────────────────────────┐
│  后端 Action 层 (PHP)                                  │
│  - 创建表单对象 (FormType)                              │
│  - 传递变量到模板 (render / #[Template])                │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│  页面模板层 (Twig)                                    │
│  - 继承布局模板                                        │
│  - 调用 Twig 组件                                    │
│  - 传递变量给组件                                     │
└────────────────────────┬────────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────────┐
│  Twig 组件层 (PHP + Twig)                             │
│  - 组件逻辑 (LiveComponent / TwigComponent)            │
│  - 组件模板 (Components/*.html.twig)                      │
│  - 表单渲染 (form_widget / form_row)                 │
└─────────────────────────────────────────────────────────┘
```

---

## 布局继承体系

### 继承链结构

SolidInvoice 使用 **四级布局继承链**，从基础布局到具体页面逐层定制：

```
@Ui/Layout/base.html.twig          (Platform UI 基础布局 — 来自 solidworx/platform 包)
         ↑
@SolidInvoiceCore/Layout/base.html.twig  (项目基础样式扩展)
         ↑
@SolidInvoiceCore/Layout/default.html.twig (默认页面布局)
         ↑
各 Bundle 的页面模板                    (具体业务页面)
```

### 各层职责

#### 第 1 层：Platform UI 基础布局

- **来源**：`solidworx/platform` 包的 `UiBundle`（外部依赖）
- **模板引用名**：`@Ui/Layout/base.html.twig`
- **职责**：提供最基础的 HTML 文档结构、资源引入位置
- **可重写 block**：
  - `title` - 页面标题
  - `stylesheets` - 样式表
  - `javascripts` - JavaScript
  - `body` - 页面主体

> 这是最顶层的布局，由 SolidWorx/Platform/UiBundle 提供，属于外部依赖包。SolidInvoice 通过 `@Ui` 命名空间引用。

#### 第 2 层：项目基础布局

- **位置**：`src/CoreBundle/Resources/views/Layout/base.html.twig`
- **职责**：引入项目自定义的样式和脚本资源（通过 Webpack Encore）

```twig
{% extends '@Ui/Layout/base.html.twig' %}

{% block stylesheets %}
    {{ parent() }}
    {{ encore_entry_link_tags('app') }}
{% endblock stylesheets %}

{% block javascripts %}
    {{ encore_entry_script_tags('app') }}
{% endblock javascripts %}
```

**关键点**：
- 使用 `parent()` 调用父级 block 内容
- 通过 `encore_entry_link_tags` / `encore_entry_script_tags` 引入前端构建资源

#### 第 3 层：默认页面布局

- **位置**：`src/CoreBundle/Resources/views/Layout/default.html.twig`
- **职责**：提供完整的应用页面框架（侧边栏导航、顶部菜单、内容区、页脚）

**主要 block 结构**：

| Block 名称 | 用途 |
|---------|------|
| `header` | 顶部导航栏 |
| `body` | 页面主体（包含侧边栏） |
| `content` | 主要内容区域 |
| `footer` | 页脚 |
| `title` | 页面标题 |
| `pretitle` | 页面副标题 |
| `heading` | 页面头部操作按钮 |
| `sidebar` | 侧边栏额外内容 |
| `body_bottom` | body 底部内容 |

**布局结构示意**：

```html
<body>
    <!-- 侧边栏 (左侧垂直导航) -->
    <aside class="navbar navbar-vertical">...</aside>
    
    <!-- 顶部导航 -->
    {{ block('header') }}
    
    <!-- 页面内容区 -->
    <div class="page-wrapper">
        <!-- 页面标题区 -->
        <div class="page-header">
            <div class="page-pretitle">{{ block('pretitle') }}</div>
            <h2 class="page-title">{{ block('title') }}</h2>
            <div class="col-auto">{{ block('heading') }}</div>
        </div>
        
        <!-- 主要内容 -->
        <div class="page-body">
            <div class="container-xl">
                {% include "@SolidInvoiceCore/flash.html.twig" %}
                {{ block('content') }}
            </div>
        </div>
    </div>
</body>
```

#### 第 4 层：业务页面模板

- **位置**：各 Bundle 的 `Resources/views/Default/` 目录下
- **职责**：实现具体业务页面的内容，通常只填充 `content` block

示例（客户创建页）：

```twig
{% extends "@SolidInvoiceCore/Layout/default.html.twig" %}

{% block content %}
    <twig:ClientForm />
{% endblock %}
```

---

## Twig 组件系统

SolidInvoice 基于 **Symfony UX Twig Component** 和 **Live Component** 构建可复用的 UI 组件。

### 组件类型

#### 1. 普通 Twig 组件（`#[AsTwigComponent]`）

- 纯展示型组件
- 无交互逻辑或简单交互
- 示例：BootstrapModal

**组件类**：`src/CoreBundle/Twig/Components/BootstrapModal.php`

```php
#[AsTwigComponent]
class BootstrapModal
{
    public ?string $id = null;
    public bool $show = false;
}
```

**组件模板**：`src/CoreBundle/Resources/views/Components/BootstrapModal.html.twig`

```twig
<div {{ attributes.defaults({
    class: 'modal fade',
    tabindex: '-1',
    'aria-hidden': 'true',
    id: id ? id : false,
}) }}
    data-controller="bootstrap-modal"
    data-bootstrap-modal-show-value="{{ show ? 'true' : 'false' }}"
>
    <div class="modal-dialog">
        <div class="modal-content">
            {% block modal_full_content %}
                {% if block('modal_header') %}
                    <div class="modal-header">
                        {% block modal_header %}{% endblock %}
                    </div>
                {% endif %}
                <div class="modal-body">
                    {% block modal_body %}{% endblock %}
                </div>
                {% if block('modal_footer') %}
                    <div class="modal-footer justify-content-between">
                        {% block modal_footer %}{% endblock %}
                    </div>
                {% endif %}
            {% endblock %}
        </div>
    </div>
</div>
```

#### 2. 实时组件（`#[AsLiveComponent]`）

- 支持 AJAX 交互
- 表单处理、状态管理
- 示例：ClientForm、CreateInvoice、DataGrid、Settings

**核心 Traits**：

| Trait | 作用 |
|-------|------|
| `DefaultActionTrait` | 提供默认的 `__invoke` 动作，支持组件直接作为页面入口 |
| `ComponentWithFormTrait` | 完整的表单处理能力（表单创建、提交、验证、重渲染） |
| `LiveCollectionTrait` | 集合表单的动态增删处理（内部使用 ComponentWithFormTrait） |
| `ComponentToolsTrait` | 浏览器事件派发等工具方法 |

**核心注解**：

| 注解 | 作用 |
|------|------|
| `#[LiveProp]` | 声明组件属性，支持 URL 持久化、可写性、变更回调 |
| `#[LiveAction]` | 声明可通过 AJAX 调用的动作方法 |
| `#[ExposeInTemplate]` | 将方法/属性暴露给模板访问 |
| `#[PostMount]` | 组件首次挂载后执行的钩子 |
| `#[PreReRender]` | 每次重新渲染前执行的钩子 |
| `#[PreMount]` | 组件挂载前执行的钩子 |

### 组件目录结构

每个 Bundle 的组件遵循以下结构：

```
BundleNameBundle/
├── Twig/
│   └── Components/
│       └── ComponentName.php      # 组件逻辑类
└── Resources/
    └── views/
        └── Components/
            └── ComponentName.html.twig  # 组件模板
```

### 组件调用与属性传递

**在模板中调用组件**：

```twig
{# 无参数调用 #}
<twig:ClientForm />

{# 传递属性：使用 PHP 属性名 #}
<twig:ClientForm :client="client" />

{# 传递多个属性 #}
<twig:CreateInvoice :form="form" :dto="dto" :isEdit="isEdit" :invoice="invoice|default(null)" />
```

**属性传递规则**：
- 使用 `:name="value"` 语法传递属性
- **模板中的属性名对应组件类中的 PHP 属性名**（如 `:client="client"` 对应 `public ?Client $client`）
- `fieldName` 是 Live Component 内部数据存储的字段名，**不**影响模板调用时的属性名
- 非 `LiveProp` 的公共属性也可以直接传递（仅首次挂载时有效）
- 对于 `#[LiveProp(fieldName: 'xxx')]`，模板中仍然使用**PHP 属性名**传递，不是 `fieldName` 的值

### `fieldName` 的作用与表单水合

`fieldName` 是 `#[LiveProp]` 的一个选项，用于指定该属性在 Live Component 内部数据结构中的存储字段名。它主要影响表单数据的序列化和水合（hydration）。

> **重要说明**：`fieldName` 是**内部存储字段名**，**不影响**模板调用时的属性名。模板中始终使用 **PHP 属性名** 传递属性。

#### fieldName 的三种典型用法

| fieldName 值 | 含义 | 作用 | 组件示例 |
|-------------|------|------|---------|
| `'formData'` | 表单数据对象 | 将属性标记为表单直接绑定的数据对象，参与表单水合 | ClientForm、ContactInfo、AddressInfo、CreateRecurringInvoice |
| `'xxxEntity'` | 引用实体（非表单数据） | 用于标识或上下文，不是表单直接绑定的数据 | CreateInvoice (`invoiceEntity`)、CreateQuote (`quoteEntity`) |
| 不设置 | 默认存储名 | 属性名即为内部存储字段名 | Settings (`section`)、DataGrid (`page`、`sort`) |

#### 类型一：fieldName: 'formData'（表单数据对象）

当 `#[LiveProp(fieldName: 'formData')]` 时，这个属性就是表单**直接绑定**的数据对象。表单提交后，数据会同步回这个属性。

**示例：ClientForm**

```php
// 组件类
#[LiveProp(fieldName: 'formData')]
public ?Client $client = null;

// 模板调用——用 PHP 属性名 client，不是 formData
// <twig:ClientForm :client="client" />
```

工作机制：
1. **序列化**：渲染时将 `$client` 序列化为表单数据，存入内部 `formData` 字段
2. **水合**：AJAX 请求时，从 `formData` 反序列化重建 `$client` 对象
3. **表单提交**：`submitForm()` 后，表单数据更新到 `$client`

> `fieldName: 'formData'` 是 `ComponentWithFormTrait` / `LiveCollectionTrait` 的约定方式。当属性本身就是表单绑定的数据实体时，使用此约定。

#### 类型二：fieldName: 'xxxEntity'（引用实体）

当属性只是用于标识或上下文（不是表单直接绑定的数据）时，用自定义的 fieldName 区分内部存储名。

**示例：CreateInvoice 的 invoiceEntity**

```php
// 组件类
#[LiveProp(writable: false, fieldName: 'invoiceEntity')]
public ?Invoice $invoice = null;

// 模板调用——用 PHP 属性名 invoice，不是 invoiceEntity
// <twig:CreateInvoice :invoice="invoice|default(null)" />
```

这种情况下，`$invoice` 只是"编辑模式下的发票实体"，用于标识和上下文，**不是**表单直接绑定的数据（表单绑定的是 `$dto`）。`fieldName: 'invoiceEntity'` 只是给它一个明确的内部存储名，避免与表单数据混淆。

#### 类型三：不设置 fieldName（普通属性）

不设置 `fieldName` 时，属性名直接作为内部存储名。通常用于简单的状态属性。

**示例：Settings 的 section**

```php
// 组件类
#[LiveProp(writable: true, onUpdated: 'onSectionChange', url: true)]
public string $section = '';

// 内部存储名就是 "section"
```

---

## 表单数据传递的两种模式

SolidInvoice 中 Live Component 与表单结合有 **两种不同的使用模式**，适用于不同场景。

### 模式 A：组件自建表单

**特点**：`form` 对象由 Live Component 内部通过 `instantiateForm()` 创建，页面模板**不传递 `form` 变量**。页面模板可以传递数据实体（如 `:client="client"`）作为表单的初始数据，但 form 对象本身由组件创建。

**适用场景**：
- 独立的表单组件
- 表单数据结构固定
- 组件自包含程度高

**数据流**：

```
Action 类
    ↓ (可选：传递少量初始数据)
页面模板
    ↓ 调用组件（不传 form）
Live Component 类
    ↓ instantiateForm()
FormType
    ↓
组件模板
    ↓ form_row / form_widget
表单渲染
    ↓ AJAX 提交
LiveAction 方法
    ↓ submitForm()
表单处理
```

**典型案例**：Settings、ClientForm

**示例：Settings 组件**

Action 层（极简，甚至不创建表单）：

```php
// src/SettingsBundle/Action/Index.php
final class Index
{
    #[Template('@SolidInvoiceSettings/Settings/index.html.twig')]
    public function __invoke(Request $request): array
    {
        return [];  // 不传递 form
    }
}
```

页面模板：

```twig
{# src/SettingsBundle/Resources/views/Settings/index.html.twig #}
{% extends '@SolidInvoiceCore/Layout/default.html.twig' %}

{% block content %}
    <twig:Settings />
{% endblock %}
```

组件类（自己创建表单）：

```php
// src/SettingsBundle/Twig/Components/Settings.php
#[AsLiveComponent]
final class Settings extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;

    #[LiveProp(writable: true, onUpdated: 'onSectionChange', url: true)]
    public string $section = '';

    // 组件自己创建表单
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            SettingsType::class,
            $this->getAppSettings(false)[$this->section],
            [...]
        );
    }

    #[LiveAction]
    public function save(...): RedirectResponse
    {
        $this->submitForm();
        // 保存逻辑...
    }
}
```

### 模式 B：页面传递初始表单数据

**特点**：Action 层创建表单和 DTO，通过页面模板传递给 Live Component 作为初始数据。

**适用场景**：
- 复杂表单需要在 Action 层做初始化
- 表单依赖 URL 参数或路由参数
- 需要兼容传统表单提交（无 JS 降级）
- DTO 需要在 Action 层预先配置

**数据流**：

```
Action 类 (PHP)
    ↓ 创建 DTO + Form
FormType + DTO
    ↓ render() 传递
页面模板 (Twig)
    ↓ :form="form" :dto="dto"
Live Component 类
    ↓ 接收初始数据 + instantiateForm()
组件模板
    ↓ 渲染表单
    ↓ AJAX 交互
LiveAction 方法
    ↓ submitForm()
表单处理
```

**典型案例**：CreateInvoice、CreateQuote

**示例：CreateInvoice 组件**

Action 层（创建 DTO 和表单）：

```php
// src/InvoiceBundle/Action/Create.php
public function __invoke(Request $request, ?Client $client = null): Response
{
    // 1. 创建 DTO 并初始化
    $dto = new InvoiceFormDTO();
    $dto->clientMode = $totalClientsCount > 0 
        ? InvoiceClientMode::Existing 
        : InvoiceClientMode::NewClient;
    $dto->client = $client;
    $dto->invoiceDate = new DateTimeImmutable();
    $dto->lines->add(new Line());

    // 2. 创建表单
    $formOptions = $client instanceof Client ? ['currency' => $client->getCurrency()] : [];
    $form = $this->createForm(InvoiceType::class, $dto, $formOptions);
    $form->handleRequest($request);

    // 3. 处理传统提交（降级方案）
    if ($form->isSubmitted() && $form->isValid()) {
        // 保存逻辑...
        return new RedirectResponse(...);
    }

    // 4. 渲染模板，传递 form 和 dto
    return $this->render('@SolidInvoiceInvoice/Default/create.html.twig', [
        'dto' => $dto,
        'form' => $form,
        'recurring' => false,
    ]);
}
```

页面模板（传递给组件）：

```twig
{# src/InvoiceBundle/Resources/views/Default/create.html.twig #}
{% extends "@SolidInvoiceCore/Layout/default.html.twig" %}

{% block content %}
    <twig:CreateInvoice :form="form" :dto="dto" :isEdit="isEdit" :invoice="invoice|default(null)" />
{% endblock content %}
```

组件类（接收初始数据）：

```php
// src/InvoiceBundle/Twig/Components/CreateInvoice.php
#[AsLiveComponent]
final class CreateInvoice extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    // 公共属性，从模板接收初始值（非 LiveProp，仅首次挂载有效）
    public InvoiceFormDTO $dto;

    // LiveProp，可在组件生命周期中管理
    #[LiveProp(writable: false)]
    public bool $isEdit = false;

    #[LiveProp(writable: false, fieldName: 'invoiceEntity')]
    public ?Invoice $invoice = null;

    // 仍然有 instantiateForm()，用于 AJAX 重渲染时重建表单
    protected function instantiateForm(): FormInterface
    {
        $options = [];
        
        if ($this->dto->client instanceof Client) {
            $options['currency'] = $this->dto->client->getCurrency();
        }
        
        return $this->createForm(InvoiceType::class, $this->dto, $options);
    }

    // ... LiveAction 方法
}
```

### 两种模式对比

| 特性 | 模式 A：组件自建 | 模式 B：页面传参 |
|------|---------------|----------------|
| **form 对象创建位置** | 完全由组件的 `instantiateForm()` 创建 | 首次渲染用传入的 form，AJAX 时用 `instantiateForm()` 重建 |
| **页面向组件传什么** | 不传 form，可传数据实体/DTO | 传 form + 数据实体/DTO |
| 数据初始化位置 | 组件构造函数、`#[PostMount]` 或组件内部逻辑 | Action 中 |
| **无 JS 降级支持** | 不一定——取决于 Action 层是否处理传统提交 | 通常支持（Action 层有 form 用于降级） |
| 代码复杂度 | 较低（组件自包含） | 较高（两层都有表单相关逻辑） |
| 适用场景 | 独立表单组件、简单表单 | 复杂表单、需要 Action 层初始化 |
| AJAX 提交方式 | `submitForm()` + `#[LiveAction]` | `submitForm()` + `#[LiveAction]` |
| 表单水合机制 | 基于 `formData`（fieldName: 'formData' 的属性） | 基于 `formData`（数据属性作为 formData） |

> **重要澄清**：无 JS 降级支持与模式 A/B **没有必然联系**。是否支持降级，取决于 Action 层是否 `handleRequest` 并处理 `isSubmitted() && isValid()`。ClientForm 属于模式 A（自建 form），但它的 Action 层也处理了传统提交，所以也支持降级。

### 重要说明：关于 `instantiateForm()`

无论使用哪种模式，**带表单的 Live Component 始终需要 `instantiateForm()` 方法**。原因：

1. **模式 A（首次渲染 + AJAX）**：所有表单都通过此方法创建
2. **模式 B（首次渲染）**：传入的 form 作为初始状态，但组件需要知道如何建 form
3. **模式 B（AJAX 重渲染）**：每次 AJAX 请求后，组件都需要通过 `instantiateForm()` 重建表单，然后用前端传回的数据填充

### 表单水合（hydration）机制

表单数据在前端和后端之间的传递依赖 Live Component 的水合机制：

1. **序列化（后端 → 前端）**：组件渲染时，将 `#[LiveProp]` 属性序列化为 JSON，存在前端 `data-live-props-value` 属性中
2. **水合（前端 → 后端）**：AJAX 请求时，前端将 props 数据传回，后端反序列化重建对象
3. **`fieldName: 'formData'` 的特殊作用**：标记某个属性是表单的底层数据对象，`ComponentWithFormTrait` / `LiveCollectionTrait` 会用它来关联表单数据

> 对于使用 `LiveCollectionTrait` 的组件，通常有一个属性使用 `fieldName: 'formData'`，这个属性就是表单 `getData()` 返回的数据对象。表单提交时，数据会同步回这个属性。

---

## 完整协作流程案例分析

### 案例一：客户创建页面（ClientForm — 模式 A）

**涉及文件**：
- Action: `src/ClientBundle/Action/Add.php`
- 页面模板: `src/ClientBundle/Resources/views/Default/add.html.twig`
- 组件类: `src/ClientBundle/Twig/Components/ClientForm.php`
- 组件模板: `src/ClientBundle/Resources/views/Components/ClientForm.html.twig`
- 表单类型: `src/ClientBundle/Form/Type/ClientType.php`

**完整流程**：

#### 第 1 步：Action 接收请求

```php
// src/ClientBundle/Action/Add.php
public function __invoke(Request $request): Response
{
    // 1. 功能权限检查
    if (! $this->featureGate->canUse(...)) {
        return $this->render('@SolidInvoiceClient/Default/gated.html.twig');
    }

    // 2. 创建空实体和表单（用于传统表单提交降级）
    $client = new Client();
    $form = $this->formFactory->create(ClientType::class, $client);
    $form->handleRequest($request);

    // 3. 处理传统表单提交（降级处理，非 JS 环境）
    if ($form->isSubmitted() && $form->isValid()) {
        $entityManager = $this->doctrine->getManager();
        $entityManager->persist($client);
        $entityManager->flush();

        $session->getFlashBag()->add('success', 'client.create.success');
        return new RedirectResponse($this->router->generate('_clients_view', ['id' => $client->getId()]));
    }

    // 4. 渲染页面模板，传递 form 变量
    // 注意：虽然传了 form，但页面模板中并没有把它传给 ClientForm 组件
    return $this->render('@SolidInvoiceClient/Default/add.html.twig', [
        'form' => $form->createView(),
    ]);
}
```

> **注意**：Add Action 创建了 form 并传递给了页面模板，但页面模板并没有把 form 传给 `<twig:ClientForm />` 组件。这是因为 ClientForm 组件使用模式 A（自建表单），Action 中的 form 主要是为了兼容无 JS 的传统提交。

#### 第 2 步：页面模板继承布局并调用组件

```twig
{# src/ClientBundle/Resources/views/Default/add.html.twig #}
{% extends "@SolidInvoiceCore/Layout/default.html.twig" %}

{% block content %}
    <twig:ClientForm />
{% endblock %}
```

#### 第 3 步：Live Component 初始化并自建表单

当 Twig 渲染 `<twig:ClientForm />` 时：

1. 组件类实例化
2. 执行 `#[PostMount]` 钩子（如果有）
3. 调用 `instantiateForm()` 创建表单
4. 渲染组件模板

```php
// src/ClientBundle/Twig/Components/ClientForm.php
#[AsLiveComponent]
class ClientForm extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    #[LiveProp(fieldName: 'formData')]
    public ?Client $client = null;

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(
            ClientType::class,
            $this->client ?? (new Client())
                ->addContact(new Contact())
                ->addAddress(new Address()),
            ['validation_groups' => ['Default', 'form']]
        );
    }

    #[LiveAction]
    public function save(EntityManagerInterface $manager): RedirectResponse
    {
        $this->submitForm();

        /** @var Client $client */
        $client = $this->getForm()->getData();

        // 清理空地址
        foreach ($client->getAddresses() as $address) {
            if ($address->isEmpty()) {
                $client->removeAddress($address);
            }
        }

        $manager->persist($client);
        $manager->flush();

        $this->addFlash('success', 'client.create.success');

        return $this->redirectToRoute('_clients_view', [
            'id' => $client->getId(),
        ]);
    }
}
```

#### 第 4 步：组件模板渲染表单

```twig
{# src/ClientBundle/Resources/views/Components/ClientForm.html.twig #}
<div {{ attributes.defaults({class: 'form-page'}) }}>
    {{ form_start(form, {
        attr: {
            'data-action': 'live#action:prevent',
            'data-live-action-param': 'save',
            'class': 'client-form'
        }
    }) }}

    {{ form_errors(form) }}

    {# 业务信息部分 #}
    <section class="form-page-section">
        <div class="section-header">...</div>
        <div class="section-content">
            <div class="form-grid">
                {{ form_row(form.name, {
                    attr: { placeholder: 'Acme Corporation'|trans }
                }) }}
                {{ form_row(form.website) }}
                {{ form_row(form.currencyCode) }}
                {{ form_row(form.vat_number) }}
            </div>
        </div>
    </section>

    {# 联系人集合（动态增删） #}
    <section class="form-page-section">
        {% for contact in form.contacts %}
            {{ form_row(contact) }}
        {% endfor %}
        {{ form_widget(form.contacts.vars.button_add) }}
    </section>

    {# 地址集合（动态增删） #}
    <section class="form-page-section">
        {% for address in form.addresses %}
            {{ form_row(address) }}
        {% endfor %}
        {{ form_widget(form.addresses.vars.button_add) }}
    </section>

    {{ form_rest(form) }}

    {# 底部操作栏 #}
    <div class="form-page-actions">
        <button type="button" class="btn btn-ghost" onclick="history.back()">
            {{ ux_icon('tabler:arrow-left') }} {{ 'client.cancel'|trans }}
        </button>
        <button type="submit" class="btn btn-primary">
            {{ ux_icon('tabler:device-floppy') }} {{ 'client.save'|trans }}
        </button>
    </div>

    {{ form_end(form) }}
</div>
```

#### 第 5 步：表单提交（AJAX 方式）

用户点击保存按钮时：

1. JavaScript 拦截表单提交
2. 通过 AJAX 发送到 Live Component
3. 调用 `save` 方法（`#[LiveAction]`）
4. 提交表单并验证
5. 返回重定向响应或重新渲染表单（含错误信息）

---

### 案例二：发票创建页面（CreateInvoice — 模式 B）

**涉及文件**：
- Action: `src/InvoiceBundle/Action/Create.php`
- 页面模板: `src/InvoiceBundle/Resources/views/Default/create.html.twig`
- 组件类: `src/InvoiceBundle/Twig/Components/CreateInvoice.php`
- 组件模板: `src/InvoiceBundle/Resources/views/Components/CreateInvoice.html.twig`
- DTO: `src/InvoiceBundle/DTO/InvoiceFormDTO.php`
- 表单类型: `src/InvoiceBundle/Form/Type/InvoiceType.php`

**特点**：
- 使用 DTO 作为表单数据载体
- 复杂的实时计算逻辑（总价、税额）
- 多个保存动作（草稿、发布、发送）
- Action 层初始化 DTO 和表单，传递给组件

**关键流程**：

#### 第 1 步：Action 初始化 DTO 和表单

```php
// src/InvoiceBundle/Action/Create.php
public function __invoke(Request $request, ?Client $client = null): Response
{
    // 1. 权限检查
    if (! $this->featureGate->canUse(...)) {
        return $this->render('@SolidInvoiceInvoice/Default/invoice_gated.html.twig');
    }

    // 2. 创建并初始化 DTO
    $dto = new InvoiceFormDTO();
    $dto->clientMode = $totalClientsCount > 0 
        ? InvoiceClientMode::Existing 
        : InvoiceClientMode::NewClient;
    $dto->client = $client;
    $dto->invoiceDate = new DateTimeImmutable();
    $dto->lines->add(new Line());

    // 3. 创建表单
    $formOptions = $client instanceof Client ? ['currency' => $client->getCurrency()] : [];
    $form = $this->createForm(InvoiceType::class, $dto, $formOptions);
    $form->handleRequest($request);

    // 4. 处理传统表单提交（降级）
    if ($form->isSubmitted() && $form->isValid()) {
        $action = $request->request->get('save');
        $invoice = $this->formManager->createInvoiceFromDTO($dto);
        
        // 状态流转...
        // 保存...
        // 发送邮件...
        
        return new RedirectResponse(...);
    }

    // 5. 验证失败时重新计算总价
    if ($form->isSubmitted() && ! $form->isValid()) {
        try {
            $tempInvoice = $this->formManager->createInvoiceFromDTO($dto);
            $this->totalCalculator->calculateTotals($tempInvoice);
            $dto->total = (string) $tempInvoice->getTotal();
            // ...
        } catch (\InvalidArgumentException) {
            // 数据不完整时跳过计算
        }
    }

    // 6. 渲染模板，传递 form 和 dto 给页面
    return $this->render('@SolidInvoiceInvoice/Default/create.html.twig', [
        'dto' => $dto,
        'form' => $form,
        'recurring' => false,
    ]);
}
```

#### 第 2 步：页面模板传递变量给组件

```twig
{# src/InvoiceBundle/Resources/views/Default/create.html.twig #}
{% set isEdit = isEdit|default(false) %}

{% extends "@SolidInvoiceCore/Layout/default.html.twig" %}

{% block content %}
    {% if recurring %}
        <twig:CreateRecurringInvoice :form="form" :invoice="invoice" :isEdit="isEdit" />
    {% else %}
        <twig:CreateInvoice :form="form" :dto="dto" :isEdit="isEdit" :invoice="invoice|default(null)" />
    {% endif %}
{% endblock content %}
```

> **关键点**：页面模板通过 `:form="form"` 和 `:dto="dto"` 将 Action 中创建的表单和 DTO 传递给 Live Component。

#### 第 3 步：组件接收初始数据

```php
// src/InvoiceBundle/Twig/Components/CreateInvoice.php
#[AsLiveComponent]
final class CreateInvoice extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    // 公共属性：从模板接收 DTO（不是 LiveProp，首次挂载时设置）
    public InvoiceFormDTO $dto;

    // LiveProp：编辑模式标志（不可写）
    #[LiveProp(writable: false)]
    public bool $isEdit = false;

    // LiveProp：发票实体（fieldName 用于模板传递时的名称）
    #[LiveProp(writable: false, fieldName: 'invoiceEntity')]
    public ?Invoice $invoice = null;

    // LiveProp：可写，用于跟踪客户切换
    #[LiveProp(writable: true)]
    public ?string $previousClientId = null;

    public function __construct(...)
    {
        $this->dto = new InvoiceFormDTO();  // 默认值，会被模板传入的值覆盖
    }

    // PostMount：挂载后自动选择联系人
    #[PostMount(priority: 10)]
    public function initializeContacts(): void
    {
        $client = $this->dto->client;
        
        if ($client instanceof Client && $this->dto->users->isEmpty()) {
            foreach ($client->getContacts() as $contact) {
                $this->dto->users->add($contact);
            }
            $this->previousClientId = (string) $client->getId();
        }
    }

    // 仍然需要 instantiateForm()，AJAX 重渲染时用
    protected function instantiateForm(): FormInterface
    {
        $options = [];
        
        if ($this->dto->client instanceof Client) {
            $options['currency'] = $this->dto->client->getCurrency();
        } elseif (($this->formValues['client'] ?? '') !== '') {
            $client = $this->clientRepository->find($this->formValues['client']);
            $options['currency'] = $client?->getCurrency();
        }

        return $this->createForm(InvoiceType::class, $this->dto, $options);
    }
}
```

#### 第 4 步：复杂的实时交互逻辑

**客户切换时自动选择联系人**：

```php
#[PreReRender(priority: 10)]
public function autoSelectContactsOnClientChange(): void
{
    $this->maybeAutoSelectContacts();
}
```

**实时计算总价**：

```php
#[PreReRender(priority: -10)]
public function calculateTotals(): void
{
    if (! $this->canCalculateTotals()) {
        $this->dto->total = '0';
        $this->dto->baseTotal = '0';
        $this->dto->tax = '0';
        return;
    }
    
    // 创建临时发票计算总价
    $tempInvoice = $this->formManager->createInvoiceFromDTO($this->dto);
    $this->totalCalculator->calculateTotals($tempInvoice);
    
    // 将计算结果写回 DTO
    $this->dto->total = (string) $tempInvoice->getTotal();
    $this->dto->baseTotal = (string) $tempInvoice->getBaseTotal();
    $this->dto->tax = (string) $tempInvoice->getTax();
}
```

> **优先级说明**：`PreReRender` 的优先级控制执行顺序。priority 越高越先执行。
> - priority 10：先自动选择联系人（在表单提交前处理）
> - priority 0：默认优先级，处理表单提交（submitFormOnRender）
> - priority -10：后计算总价（在表单提交后，基于最新数据计算）

#### 第 5 步：多个保存动作

组件提供多个 `#[LiveAction]` 方法，对应不同的保存策略：

```php
#[LiveAction]
public function saveDraft(): ?Response
{
    return $this->saveInvoice('draft');
}

#[LiveAction]
public function saveUpdate(): ?Response
{
    return $this->saveInvoice('save');
}

#[LiveAction]
public function savePublish(): ?Response
{
    return $this->saveInvoice('publish');
}

#[LiveAction]
public function saveSend(): ?Response
{
    if ($this->emailVerificationGate->isGated()) {
        $this->addFlash('error', 'email_verification.flash.send_invoice');
        return null;
    }
    return $this->saveInvoice('send');
}
```

**核心保存逻辑**：

```php
private function saveInvoice(string $action): ?Response
{
    $this->submitForm();
    
    $form = $this->getForm();
    
    // 验证失败时返回 null，让组件重新渲染（显示错误）
    if (! $form->isValid()) {
        return null;
    }
    
    /** @var InvoiceFormDTO $dto */
    $dto = $form->getData();
    
    // 编辑模式 vs 创建模式
    if ($this->isEdit) {
        assert($this->invoice instanceof Invoice);
        $this->formManager->updateInvoiceFromDTO($this->invoice, $dto);
        
        if ('send' === $action || 'publish' === $action) {
            $this->invoiceStateMachine->apply($this->invoice, Graph::TRANSITION_ACCEPT);
        }
        
        $this->entityManager->flush();
        
        if ('send' === $action) {
            $this->mailer->send(new InvoiceEmail($this->invoice));
        }
        
        $this->addFlash('success', 'invoice.edit.success');
        
        $url = $this->router->generate('_invoices_view', ['id' => $this->invoice->getId()]);
        return $this->redirect($url);
    }
    
    // 创建模式
    $invoice = $this->formManager->createInvoiceFromDTO($dto);
    
    // 状态流转
    if (! $invoice->getId() instanceof Ulid) {
        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_NEW);
    }
    
    if ('send' === $action || 'publish' === $action) {
        $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_ACCEPT);
    }
    
    // 保存
    $this->entityManager->persist($invoice);
    $this->entityManager->flush();
    
    // 发送邮件
    if ('send' === $action) {
        $this->mailer->send(new InvoiceEmail($invoice));
    }
    
    // Flash 消息 + 重定向
    $this->addFlash('success', 'invoice.create.success');
    
    $url = $this->router->generate('_invoices_view', ['id' => $invoice->getId()]);
    return $this->redirect($url);
}
```

> **注意**：`addFlash()` 和 `redirect()` 是两个独立的调用，不是链式调用。`addFlash()` 没有返回值，`redirect()` 返回 Response 对象。

---

### 案例三：数据表格（DataGrid — 模式 A + 通用组件）

**涉及文件**：
- 组件类: `src/DataGridBundle/Twig/Components/DataGrid.php`
- 组件模板: `src/DataGridBundle/Resources/views/Components/DataGrid.html.twig`

**特点**：
- 通用组件，通过 `name` 属性指定具体 grid 定义
- URL 持久化状态（分页、排序、搜索、过滤）
- 批量操作、列显示切换

**使用方式**：

```twig
<twig:DataGrid name="invoice_grid" />
```

**核心属性与状态管理**：

```php
#[AsLiveComponent]
class DataGrid extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    // Grid 名称（必填）
    #[LiveProp(writable: true, url: false)]
    public string $name;

    // 分页状态（URL 持久化）
    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    #[LiveProp(writable: true, url: true)]
    public string $sort = '';

    #[LiveProp(writable: true, url: true)]
    public int $perPage = 10;

    // 搜索（URL 持久化）
    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    // 过滤条件（URL 持久化）
    #[LiveProp(writable: true, url: true)]
    public array $gridFilters = [];

    // 动态创建过滤表单
    protected function instantiateForm(): FormInterface
    {
        $form = $this->createFormBuilder($this->gridFilters);
        
        foreach ($this->getGrid()->filters() as $name => $filter) {
            $form->add($name, $filter->form(), ...);
        }
        
        return $form->getForm();
    }

    // 应用过滤
    #[LiveAction]
    public function applyFilters(): void
    {
        $this->submitForm();
        $this->gridFilters = ...;
        $this->page = 1;
    }
    
    // ... 更多动作
}
```

---

## 表单主题定制

SolidInvoice 基于 **Bootstrap 5** 表单主题，通过自定义扩展实现设计系统样式。

### 表单主题继承链

```
bootstrap_5_layout.html.twig    (Symfony 内置)
         ↑
fields.html.twig           (SolidInvoice 自定义扩展)
```

### 自定义表单主题文件

- **位置**：`src/CoreBundle/Resources/views/Form/fields.html.twig`
- **作用**：扩展 Bootstrap 5 表单主题，添加自定义字段样式和组件

### 自定义的表单字段类型

| 字段 Block 名 | 说明 |
|-------------|------|
| `email_widget` | 带图标的邮箱输入框 |
| `currency_widget` | 带图标的货币选择器 |
| `tax_number_widget` | 带验证按钮的税号输入框 |
| `address_widget` | 地址卡片式布局 |
| `contact_detail_widget` | 联系方式详情 |
| `contact_widget` | 联系人集合 |
| `checkbox_row` | 开关样式的复选框 |
| `money_widget` | 金额输入框 |
| `discount_row` | 折扣行（值 + 类型） |
| `image_upload_widget` | 图片上传预览 |

### 应用表单主题的方式

**方式 1：在模板中指定**

```twig
{% form_theme form '@SolidInvoiceCore/Form/fields.html.twig' %}
```

**方式 2：全局配置**

在 `twig.yaml` 中配置 `form_themes`（项目级全局配置）。

**方式 3：在组件模板中指定**

```twig
{% form_theme form '@SolidInvoiceSettings/Form/fields.html.twig' %}
```

### 自定义字段示例：带图标的输入框

```twig
{% block email_widget %}
    <div class="input-group input-icon-group">
        <span class="input-group-text">
            {{ ux_icon('tabler:mail', {width: 18, height: 18}) }}
        </span>
        <input type="email"
               id="{{ id }}"
               name="{{ full_name }}"
               class="form-control{{ errors|length > 0 ? ' is-invalid' : '' }}"
               {% if value is defined and value is not null %}value="{{ value }}"{% endif %}
               {% if disabled %}disabled="disabled"{% endif %}
               {% if required %}required="required"{% endif %}
               {% for attrname, attrvalue in attr %}{{ attrname }}="{{ attrvalue }}" {% endfor %}
        >
    </div>
{% endblock email_widget %}
```

### 表单渲染函数速查

| Twig 函数 | 作用 |
|----------|------|
| `form_start(form)` | 开始表单标签 |
| `form_end(form)` | 结束表单标签（自动渲染未渲染字段） |
| `form_widget(field)` | 仅渲染字段输入控件 |
| `form_label(field)` | 仅渲染字段标签 |
| `form_errors(field)` | 仅渲染字段错误信息 |
| `form_row(field)` | 渲染整行（标签 + 控件 + 错误 + 帮助文本） |
| `form_rest(form)` | 渲染所有尚未渲染的字段（含 CSRF token） |
| `form_help(field)` | 渲染帮助文本 |

---

## 关键设计模式总结

### 1. Action 模式

每个 HTTP 请求入口是一个独立的 Action 类
- 单一职责原则
- 通过 `__invoke` 方法处理请求
- 位于各 Bundle 的 `Action/` 目录下

### 2. 组件模式

UI 元素封装为 Twig Component
- 可复用性强
- 自包含（逻辑 + 模板）
- 支持嵌套组合
- 支持 AJAX 实时交互（Live Component）

### 3. DTO 模式

复杂表单使用 DTO（Data Transfer Object）作为数据载体
- 解耦表单与实体
- 支持条件验证（validation groups）
- 便于计算派生字段（如总价、税额）
- 更灵活的数据结构（不局限于实体字段）

### 4. 表单类型模式

Symfony Form 组件统一处理表单
- 表单类型（FormType）定义字段结构和验证
- 表单主题（Form Theme）定义渲染外观
- 表单事件处理动态逻辑

### 5. 布局继承模式

模板通过继承逐层定制
- 基础布局提供页面框架
- 子模板填充具体内容
- Block 机制实现定制化
- 四级继承链保证一致性和灵活性

### 6. 渐进增强模式

有降级支持的表单采用渐进增强设计：

- **基础层**：传统 HTML 表单提交（Action 层 `handleRequest` + `isSubmitted() && isValid()`）—— 无 JS 环境也能用
- **增强层**：AJAX 实时交互（Live Component + `#[LiveAction]`）—— 有 JS 时体验更好

> 是否支持降级取决于 Action 层是否处理了传统表单提交，与组件自建表单还是页面传参无关。
>
> - ✅ 支持降级：ClientForm、CreateInvoice、CreateQuote 等
> - ❌ 不支持降级：Settings 等（Action 层极简，不处理提交）

---

## 文件索引

### 布局模板

| 文件 | 说明 |
|------|------|
| `src/CoreBundle/Resources/views/Layout/base.html.twig` | 项目基础布局（扩展 Platform UI） |
| `src/CoreBundle/Resources/views/Layout/default.html.twig` | 默认页面布局（含侧边栏、顶栏） |
| `src/CoreBundle/Resources/views/Layout/login.html.twig` | 登录页布局 |
| `src/CoreBundle/Resources/views/Layout/error.html.twig` | 错误页布局 |
| `src/CoreBundle/Resources/views/Layout/Email/base.html.twig` | 邮件布局基类 |

### 表单主题

| 文件 | 说明 |
|------|------|
| `src/CoreBundle/Resources/views/Form/fields.html.twig` | 核心表单主题 |
| `src/SettingsBundle/Resources/views/Form/fields.html.twig` | 设置页面表单主题 |
| `src/DataGridBundle/Resources/views/Form/fields.html.twig` | 数据表格表单主题 |

### 组件示例

| 组件 | 路径 | 类型 |
|------|------|------|
| ClientForm | `src/ClientBundle/Twig/Components/ClientForm.php` | Live Component（表单） |
| CreateInvoice | `src/InvoiceBundle/Twig/Components/CreateInvoice.php` | Live Component（复杂表单） |
| CreateQuote | `src/QuoteBundle/Twig/Components/CreateQuote.php` | Live Component（复杂表单） |
| DataGrid | `src/DataGridBundle/Twig/Components/DataGrid.php` | Live Component（数据表格） |
| Settings | `src/SettingsBundle/Twig/Components/Settings.php` | Live Component（设置） |
| BootstrapModal | `src/CoreBundle/Twig/Components/BootstrapModal.php` | Twig Component（展示） |
| ContactInfo | `src/ClientBundle/Twig/Components/ContactInfo.php` | Live Component（子组件） |
| AddressInfo | `src/ClientBundle/Twig/Components/AddressInfo.php` | Live Component（子组件） |

### Action 示例

| Action | 路径 | 说明 |
|--------|------|------|
| Add | `src/ClientBundle/Action/Add.php` | 添加客户 |
| Edit | `src/ClientBundle/Action/Edit.php` | 编辑客户 |
| Create | `src/InvoiceBundle/Action/Create.php` | 创建发票 |
| Index | `src/SettingsBundle/Action/Index.php` | 设置页面 |

### 表单类型示例

| 表单类型 | 路径 | 说明 |
|---------|------|------|
| ClientType | `src/ClientBundle/Form/Type/ClientType.php` | 客户表单 |
| InvoiceType | `src/InvoiceBundle/Form/Type/InvoiceType.php` | 发票表单 |
| QuoteType | `src/QuoteBundle/Form/Type/QuoteType.php` | 报价单表单 |
| SettingsType | `src/SettingsBundle/Form/Type/SettingsType.php` | 设置表单 |

### DTO 示例

| DTO | 路径 | 说明 |
|-----|------|------|
| InvoiceFormDTO | `src/InvoiceBundle/DTO/InvoiceFormDTO.php` | 发票表单数据 |
| QuoteFormDTO | `src/QuoteBundle/DTO/QuoteFormDTO.php` | 报价单表单数据 |

### 核心 Traits 与注解

| 名称 | 来源 | 作用 |
|------|------|------|
| `DefaultActionTrait` | Symfony UX Live Component | 默认动作支持 |
| `ComponentWithFormTrait` | Symfony UX Live Component | 表单处理能力 |
| `LiveCollectionTrait` | Symfony UX Live Component | 集合表单动态增删 |
| `ComponentToolsTrait` | Symfony UX Live Component | 工具方法 |
| `#[AsLiveComponent]` | Symfony UX Live Component | 声明实时组件 |
| `#[AsTwigComponent]` | Symfony UX Twig Component | 声明普通组件 |
| `#[LiveProp]` | Symfony UX Live Component | 声明响应式属性 |
| `#[LiveAction]` | Symfony UX Live Component | 声明 AJAX 动作 |
| `#[ExposeInTemplate]` | Symfony UX Twig Component | 暴露给模板访问 |
