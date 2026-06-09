# SolidInvoice 模板组件与表单协作机制深度解析

本文档深入分析 SolidInvoice 项目中前端模板组件、布局继承与后端表单数据传递的协作机制，帮助开发者理解从后端 Action 到前端渲染的完整数据流。

---

## 目录

1. [整体架构概览](#整体架构概览)
2. [布局继承体系](#布局继承体系)
3. [Twig 组件系统](#twig-组件系统)
4. [表单数据传递机制](#表单数据传递机制)
5. [完整协作流程案例分析](#完整协作流程案例分析)
6. [表单主题定制](#表单主题定制)
7. [关键设计模式总结](#关键设计模式总结)

---

## 整体架构概览

SolidInvoice 采用 **三层架构** 实现从后端数据到前端界面的渲染：

```
┌─────────────────────────────────────────────────────────┐
│  后端 Action 层 (PHP)                                  │
│  - 创建表单对象 (FormType)                            │
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
│  - 组件逻辑 (LiveComponent / TwigComponent)                │
│  - 组件模板 (Components/*.html.twig)                      │
│  - 表单渲染 (form_widget / form_row)                 │
└─────────────────────────────────────────────────────────┘
```

---

## 布局继承体系

### 继承链结构

SolidInvoice 使用 **四级布局继承链**，从基础布局到具体页面逐层定制：

```
@Ui/Layout/base.html.twig          (Platform UI 基础布局)
         ↑
@SolidInvoiceCore/Layout/base.html.twig  (项目基础样式扩展)
         ↑
@SolidInvoiceCore/Layout/default.html.twig (默认页面布局)
         ↑
各 Bundle 的页面模板                    (具体业务页面)
```

### 各层职责

#### 第 1 层：Platform UI 基础布局
- **位置**：`vendor/solidworx/platform/src/Bundle/Ui/templates/Layout/base.html.twig`
- **职责**：提供最基础的 HTML 结构、资源引入
- **可重写位置**：
  - `title` - 页面标题
  - `stylesheets` - 样式表
  - `javascripts` - JavaScript
  - `body` - 页面主体

#### 第 2 层：项目基础布局
- **位置**：[base.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/CoreBundle/Resources/views/Layout/base.html.twig)
- **职责**：引入项目自定义的样式和脚本资源

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

**关键点：
- 使用 `parent()` 调用父级 block 内容
- 通过 Webpack Encore 引入前端资源

#### 第 3 层：默认页面布局
- **位置**：[default.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/CoreBundle/Resources/views/Layout/default.html.twig)
- **职责**：提供完整的应用页面框架（侧边栏、顶部导航、内容区）

**主要 block 结构：

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

**布局结构示意：**

```html
<body>
    <!-- 侧边栏 -->
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
- **职责**：实现具体业务页面的内容

示例：[add.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Resources/views/Default/add.html.twig)

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

#### 1. 普通 Twig 组件 (`#[AsTwigComponent]
- 纯展示型组件
- 无交互逻辑或简单交互
- 示例：BootstrapModal

**组件类**：[BootstrapModal.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/CoreBundle/Twig/Components/BootstrapModal.php)

```php
#[AsTwigComponent]
class BootstrapModal
{
    public ?string $id = null;
    public bool $show = false;
}
```

**组件模板**：[BootstrapModal.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/CoreBundle/Resources/views/Components/BootstrapModal.html.twig)

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

#### 2. 实时组件 (`#[AsLiveComponent]`)
- 支持 AJAX 交互
- 表单处理、状态管理
- 示例：ClientForm、CreateInvoice、DataGrid、Settings

**核心 Traits：**

| Trait | 作用 |
|-------|------|
| `DefaultActionTrait` | 提供默认的 `__invoke` 动作 |
| `ComponentWithFormTrait` | 表单处理能力 |
| `LiveCollectionTrait` | 集合表单动态处理 |
| `ComponentToolsTrait` | 浏览器事件派发等工具 |

**核心注解：**

| 注解 | 作用 |
|------|------|
| `#[LiveProp]` | 声明组件属性，支持 URL 持久化、可写性 |
| `#[LiveAction]` | 声明可通过 AJAX 调用的动作方法 |
| `#[ExposeInTemplate]` | 将方法/属性暴露给模板 |
| `#[PostMount]` | 组件挂载后执行 |
| `#[PreReRender]` | 重新渲染前执行 |

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

### 组件调用方式

**在模板中调用组件：

```twig
{# 无参数调用 #}
<twig:ClientForm />

{# 传递属性 #}
<twig:ClientForm :client="client" />

{# 传递多个属性 #}
<twig:CreateInvoice :form="form" :dto="dto" :isEdit="isEdit" />
```

**属性传递规则：**
- 使用 `:name="value"` 传递普通值
- 使用 `:name="变量名"` 传递变量（变量名与属性名相同）
- 组件类中必须有对应属性（`#[LiveProp]` 或公共属性）

---

## 表单数据传递机制

SolidInvoice 有 **两种表单数据传递模式**，适用于不同场景。

### 模式一：传统 Action 驱动模式

适用于简单表单、传统页面。

**数据流：**

```
Action 类 (PHP)
    ↓ 创建表单
FormType (PHP)
    ↓ createView()
FormView 对象
    ↓ render() 传递
页面模板 (Twig)
    ↓ 作为变量
form 变量
    ↓ form_widget/form_row
表单渲染
```

**示例：** [Add.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Action/Add.php)

```php
final class Add extends AbstractController
{
    public function __invoke(Request $request): Response
    {
        $client = new Client();
        $form = $this->formFactory->create(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 保存逻辑...
            return new RedirectResponse(...);
        }

        return $this->render('@SolidInvoiceClient/Default/add.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
```

**也可以使用 `#[Template]` 注解简化：**

```php
final class Edit
{
    #[Template('@SolidInvoiceClient/Default/edit.html.twig')]
    public function __invoke(Request $request, Client $client): array | Response
    {
        $form = $this->formFactory->create(ClientType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // 保存逻辑...
            return new RedirectResponse(...);
        }

        return [
            'form' => $form->createView(),
            'client' => $client,
        ];
    }
}
```

### 模式二：Live Component 驱动模式

适用于复杂表单、需要实时交互的场景。

**数据流：**

```
Action 类
    ↓ 传递初始数据
页面模板
    ↓ 调用 Live Component
Live Component 类 (PHP)
    ↓ instantiateForm()
FormType
    ↓
组件模板 (Twig)
    ↓ form_start/form_row
表单渲染
    ↓ AJAX 交互
LiveAction 方法
    ↓ submitForm()
表单处理
```

**示例：** [ClientForm.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Twig/Components/ClientForm.php)

```php
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

        $manager->persist($client);
        $manager->flush();

        $this->addFlash('success', 'client.create.success');

        return $this->redirectToRoute('_clients_view', [
            'id' => $client->getId(),
        ]);
    }
}
```

**组件模板中使用表单：**

```twig
<div {{ attributes.defaults({class: 'form-page'}) }}>
    {{ form_start(form, {
        attr: {
            'data-action': 'live#action:prevent',
            'data-live-action-param': 'save',
            'class': 'client-form'
        }
    }) }}

    {{ form_errors(form) }}

    {# 表单字段 #}
    {{ form_row(form.name) }}
    {{ form_row(form.website) }}
    {# ... 更多字段 ... #}

    {{ form_rest(form) }}

    {# 提交按钮 #}
    <button type="submit" class="btn btn-primary">
        {{ ux_icon('tabler:device-floppy') }} {{ 'client.save'|trans }}
    </button>

    {{ form_end(form) }}
</div>
```

### 两种模式对比

| 特性 | 传统模式 | Live Component 模式 |
|------|---------|-------------------|
| 表单创建位置 | Action 类 | 组件类 |
| 提交方式 | 整页刷新 | AJAX 提交 |
| 交互体验 | 较差 | 好（无刷新） |
| 适用场景 | 简单表单 | 复杂表单、实时交互 |
| 代码复杂度 | 较低 | 较高 |
| 表单验证 | 提交后验证 | 实时验证 |

### DTO 模式

对于复杂表单，使用 **DTO (Data Transfer Object)** 作为表单数据载体。

**示例：** [InvoiceFormDTO.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php)

```php
final class InvoiceFormDTO
{
    public InvoiceClientMode $clientMode;
    
    #[Assert\NotBlank(groups: ['existing_client'])]
    public ?Client $client = null;
    
    #[Assert\NotBlank(groups: ['new_client'])]
    public ?string $newClientName = null;
    
    #[Assert\NotBlank]
    public string $invoiceId = '';
    
    #[Assert\NotBlank]
    #[Assert\Type(DateTimeInterface::class)]
    public ?DateTimeInterface $invoiceDate = null;
    
    /** @var ArrayCollection<int, Line> */
    #[Assert\Valid]
    #[Assert\Count(min: 1)]
    public ArrayCollection $lines;
    
    // ... 更多字段
}
```

**DTO 的优势：**
1. 解耦表单与实体
2. 支持条件验证（validation groups）
3. 便于计算派生字段（如总价）
4. 更灵活的数据结构

---

## 完整协作流程案例分析

### 案例一：客户创建页面（ClientForm）

**涉及文件：**
- Action: [Add.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Action/Add.php)
- 页面模板: [add.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Resources/views/Default/add.html.twig)
- 组件类: [ClientForm.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Twig/Components/ClientForm.php)
- 组件模板: [ClientForm.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Resources/views/Components/ClientForm.html.twig)
- 表单类型: [ClientType.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Form/Type/ClientType.php)

**完整流程：**

#### 第 1 步：Action 接收请求

```php
// src/ClientBundle/Action/Add.php
public function __invoke(Request $request): Response
{
    // 1. 功能权限检查
    if (! $this->featureGate->canUse(...)) {
        return $this->render('@SolidInvoiceClient/Default/gated.html.twig');
    }

    // 2. 创建空实体和表单
    $client = new Client();
    $form = $this->formFactory->create(ClientType::class, $client);
    $form->handleRequest($request);

    // 3. 处理传统表单提交（降级处理）
    if ($form->isSubmitted() && $form->isValid()) {
        // 保存实体
        $entityManager = $this->doctrine->getManager();
        $entityManager->persist($client);
        $entityManager->flush();

        // Flash 消息
        $session->getFlashBag()->add('success', 'client.create.success');

        // 重定向
        return new RedirectResponse($this->router->generate('_clients_view', ['id' => $client->getId()]));
    }

    // 4. 渲染页面模板，传递 form 变量
    return $this->render('@SolidInvoiceClient/Default/add.html.twig', [
        'form' => $form->createView(),
    ]);
}
```

#### 第 2 步：页面模板继承布局并调用组件

```twig
{# src/ClientBundle/Resources/views/Default/add.html.twig #}
{% extends "@SolidInvoiceCore/Layout/default.html.twig" %}

{% block content %}
    <twig:ClientForm />
{% endblock %}
```

**注意：这里没有显式传递 `form` 变量给组件，因为 Live Component 会自己创建表单。

#### 第 3 步：Live Component 初始化

当 Twig 渲染 `<twig:ClientForm />` 时：

1. **组件类实例化
2. 执行 `#[PostMount]` 钩子（如果有）
3. 调用 `instantiateForm()` 创建表单
4. 渲染组件模板

```php
// src/ClientBundle/Twig/Components/ClientForm.php
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
                <div class="form-field form-field-wide">
                    {{ form_row(form.name, {
                        attr: {
                            placeholder: 'Acme Corporation'|trans
                        }
                    }) }}
                </div>
                {{ form_row(form.website) }}
                {{ form_row(form.currencyCode) }}
                {{ form_row(form.vat_number) }}
            </div>
        </div>
    </section>

    {# 联系人集合 #}
    <section class="form-page-section">
        {% for contact in form.contacts %}
            {{ form_row(contact) }}
        {% endfor %}
        {{ form_widget(form.contacts.vars.button_add) }}
    </section>

    {# 地址集合 #}
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
5. 返回重定向响应或重新渲染表单

```php
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
```

### 案例二：发票创建页面（CreateInvoice）

**涉及文件：**
- Action: [Create.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/Action/Create.php)
- 页面模板: [create.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/Resources/views/Default/create.html.twig)
- 组件类: [CreateInvoice.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/Twig/Components/CreateInvoice.php)
- 组件模板: [CreateInvoice.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/Resources/views/Components/CreateInvoice.html.twig)
- DTO: [InvoiceFormDTO.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php)

**特点：
- 使用 DTO 作为表单数据
- 复杂的实时计算逻辑
- 多个保存动作（草稿、发布、发送）

**关键流程：**

#### 第 1 步：Action 初始化 DTO

```php
// src/InvoiceBundle/Action/Create.php
public function __invoke(Request $request, ?Client $client = null): Response
{
    // 创建 DTO
    $dto = new InvoiceFormDTO();
    
    // 设置客户模式
    $dto->clientMode = $totalClientsCount > 0 
        ? InvoiceClientMode::Existing 
        : InvoiceClientMode::NewClient;
    
    // 设置客户
    $dto->client = $client;
    
    // 设置默认日期
    $dto->invoiceDate = new DateTimeImmutable();
    
    // 添加空行项目
    $dto->lines->add(new Line());
    
    // 创建表单
    $form = $this->createForm(InvoiceType::class, $dto, $formOptions);
    $form->handleRequest($request);
    
    // 处理传统提交...
    
    // 渲染模板
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
{% extends "@SolidInvoiceCore/Layout/default.html.twig" %}

{% block content %}
    {% if recurring %}
        <twig:CreateRecurringInvoice :form="form" :invoice="invoice" :isEdit="isEdit" />
    {% else %}
        <twig:CreateInvoice :form="form" :dto="dto" :isEdit="isEdit" :invoice="invoice|default(null)" />
    {% endif %}
{% endblock content %}
```

#### 第 3 步：Live Component 复杂交互

**多个保存动作：**

```php
// src/InvoiceBundle/Twig/Components/CreateInvoice.php
#[LiveAction]
public function saveDraft(): ?Response
{
    return $this->saveInvoice('draft');
}

#[LiveAction]
public function savePublish(): ?Response
{
    return $this->saveInvoice('publish');
}

#[LiveAction]
public function saveSend(): ?Response
{
    return $this->saveInvoice('send');
}

private function saveInvoice(string $action): ?Response
{
    $this->submitForm();
    
    $form = $this->getForm();
    
    if (! $form->isValid()) {
        return null;
    }
    
    /** @var InvoiceFormDTO $dto */
    $dto = $form->getData();
    
    // 转换 DTO 为实体
    $invoice = $this->formManager->createInvoiceFromDTO($dto);
    
    // 状态流转
    $this->invoiceStateMachine->apply($invoice, Graph::TRANSITION_NEW);
    
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
    
    // 重定向
    $url = $this->router->generate('_invoices_view', ['id' => $invoice->getId()]);
    return $this->addFlash('success', 'invoice.create.success');
    return $this->redirect($url);
}
```

**实时计算总价：**

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

**客户切换时自动选择联系人：**

```php
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
```

### 案例三：数据表格（DataGrid）

**涉及文件：**
- 组件类: [DataGrid.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/DataGridBundle/Twig/Components/DataGrid.php)
- 组件模板: [DataGrid.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/DataGridBundle/Resources/views/Components/DataGrid.html.twig)

**特点：**
- 通用组件，通过 name 属性指定具体 grid
- URL 持久化状态（分页、排序、搜索）
- 批量操作

**使用方式：**

```twig
<twig:DataGrid name="invoice_grid" />
```

**核心属性传递与状态管理：**

```php
#[AsLiveComponent]
class DataGrid extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    #[LiveProp(writable: true, url: false)]
    public string $name;

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;

    #[LiveProp(writable: true, url: true)]
    public string $sort = '';

    #[LiveProp(writable: true, url: true)]
    public int $perPage = 10;

    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    #[LiveProp(writable: true, url: true)]
    public array $gridFilters = [];
    
    // ...
}
```

---

## 表单主题定制

SolidInvoice 基于 **Bootstrap 5** 表单主题，通过自定义扩展实现设计系统样式。

### 表单主题继承链

```
bootstrap_5_layout.html.twig    (Symfony 内置)
         ↑
fields.html.twig           (SolidInvoice 自定义)
```

### 自定义表单主题文件：[fields.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig)

### 自定义的表单字段类型

| 字段类型 | 说明 |
|---------|------|
| `email_widget` | 带图标的邮箱输入框 |
| `currency_widget` | 带图标的货币选择器 |
| `tax_number_widget` | 带验证按钮的税号输入框 |
| `address_widget` | 地址卡片式布局 |
| `contact_detail_widget` | 联系方式详情 |
| `contact_widget` | 联系人集合 |
| `checkbox_row` | 开关样式的复选框 |
| `money_widget` | 金额输入框 |
| `discount_row` | 折扣行 |
| `image_upload_widget` | 图片上传 |

### 使用表单主题

**全局配置方式：**

1. 在 `twig.yaml` 中全局配置

2. 在模板中指定：

```twig
{% form_theme form '@SolidInvoiceCore/Form/fields.html.twig' %}
```

3. 在组件中使用：

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

### 表单字段渲染函数

Twig 提供以下函数渲染表单字段：

| 函数 | 作用 |
|------|------|
| `form_start(form)` | 开始表单 |
| `form_end(form)` | 结束表单 |
| `form_widget(field)` | 渲染字段控件 |
| `form_label(field)` | 渲染字段标签 |
| `form_errors(field)` | 渲染字段错误 |
| `form_row(field)` | 渲染整行（标签+控件+错误） |
| `form_rest(form)` | 渲染剩余未渲染字段 |
| `form_errors(form)` | 渲染表单全局错误 |

---

## 关键设计模式总结

### 1. Action 模式
每个 HTTP 请求入口是一个 Action 类
- 单一职责
- 通过 `__invoke` 方法处理请求
- 位于 `Action/` 目录

### 2. 组件模式
UI 元素封装为 Twig Component
- 可复用
- 自包含（逻辑 + 模板
- 支持嵌套组合

### 3. DTO 模式
复杂表单使用 DTO 作为数据载体
- 解耦表单与实体
- 灵活的验证组
- 便于计算

### 4. 表单类型模式
Symfony Form 组件处理表单
- 表单类型定义字段
- 表单主题定义渲染
- 表单事件处理动态逻辑

### 5. 布局继承模式
模板通过继承逐层定制
- 基础布局提供框架
- 子模板填充内容
- Block 机制定制化

---

## 快速参考文件索引

### 布局模板
- [base.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/CoreBundle/Resources/views/Layout/base.html.twig) - 项目基础布局
- [default.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/CoreBundle/Resources/views/Layout/default.html.twig) - 默认页面布局
- [fields.html.twig](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig) - 表单主题

### 组件示例
- [ClientForm.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Twig/Components/ClientForm.php) - 客户表单组件
- [CreateInvoice.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/Twig/Components/CreateInvoice.php) - 创建发票组件
- [DataGrid.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/DataGridBundle/Twig/Components/DataGrid.php) - 数据网格组件
- [Settings.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/SettingsBundle/Twig/Components/Settings.php) - 设置组件

### Action 示例
- [Add.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Action/Add.php) - 添加客户
- [Edit.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Action/Edit.php) - 编辑客户
- [Create.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/Action/Create.php) - 创建发票

### 表单类型示例
- [ClientType.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/ClientBundle/Form/Type/ClientType.php) - 客户表单类型

### DTO 示例
- [InvoiceFormDTO.php](file:///d:/fz/0508-2/solo-dogfeeding/code/118-SolidInvoice/src/InvoiceBundle/DTO/InvoiceFormDTO.php) - 发票表单 DTO
