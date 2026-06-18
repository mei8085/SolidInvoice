# 品牌信息接入发票页眉代码实现梳理

## 目录

- [一、整体架构概览](#一整体架构概览)
- [二、品牌标识（Logo）的存储与读取链路](#二品牌标识logo的存储与读取链路)
- [三、字体资源如何进入 PDF](#三字体资源如何进入-pdf)
- [四、颜色资源如何进入 PDF](#四颜色资源如何进入-pdf)
- [五、缓存与非缓存内容总览](#五缓存与非缓存内容总览)
- [六、多公司场景下的读写边界深度分析](#六多公司场景下的读写边界深度分析)
- [七、关键文件索引（仓库相对路径）](#七关键文件索引仓库相对路径)

---

## 一、整体架构概览

SolidInvoice 通过三条独立链路将品牌信息注入发票 PDF 页眉：

```
┌──────────────────────────────────────────────────────────────────┐
│                     发票 PDF 页眉品牌信息                          │
├─────────────┬──────────────────┬────────────────────────────────┤
│   品牌标识   │      字体        │         颜色                    │
├─────────────┼──────────────────┼────────────────────────────────┤
│ app_config  │  mPDF default_font │  pdf.scss (编译为 pdf.css)    │
│ 数据库表    │  pdf.scss 字体族  │  模板内联 style 属性           │
│ 格式:       │  模板 extra_styles │  宏 palette 调色板参数        │
│ {ext}|base64│                  │                                │
└─────────────┴──────────────────┴────────────────────────────────┘
```

三类资源最终都汇入 mPDF 的 `WriteHTML()` 方法，由 mPDF 渲染为 PDF 二进制。

---

## 二、品牌标识（Logo）的存储与读取链路

### 2.1 存储表结构

Logo 以 **字符串** 形式存储在 `app_config` 表中，对应实体 `Setting`。

| 列名 | ORM 字段 | 类型 | 说明 |
|------|----------|------|------|
| `id` | `$id` | `ulid` | 主键 |
| `setting_key` | `$key` | `varchar(125)` | 设置键名，Logo 为 `system/company/logo` |
| `setting_value` | `$value` | `text, nullable` | Logo 值：`{ext}\|{base64data}` |
| `field_type` | `$type` | `varchar(255)` | 表单类型 FQCN，Logo 为 `ImageUploadType::class` |
| `form_options` | `$formOptions` | `json, nullable` | 表单选项 |
| `default_value` | `$defaultValue` | `text, nullable` | 默认值 |
| `company_id` | `$company` (trait) | `ulid` | 多租户外键（`CompanyAware` trait） |

唯一约束：`UNIQUE(setting_key, company_id)`

**关键代码：**
- 实体定义：`src/SettingsBundle/Entity/Setting.php`（#L33 TABLE_NAME 常量，#L35 CompanyAware trait，#L43-L47 key/value 字段）
- 迁移建表：`migrations/Version20000.php`（建表 + logo 初始行）
- 多租户列：`migrations/Version20200.php`（为 app_config 添加 company_id）
- 扩展列：`migrations/Version30000_3.php`（添加 form_options / default_value）

### 2.2 存储格式

```
{文件扩展名}|{base64编码的图片二进制数据}
```

示例：
```
png|iVBORw0KGgoAAAANSUhEUgAAADIAAAAoCAYAAAC8cqlMAAAABGdBTUEAALGPC/xhBQAACjppQ0NQUGhvdG9zaG9wIElDQyBwcm9maWxl...
```

允许的图片类型（MIME → 扩展名）：
- `image/jpeg` → `jpg`
- `image/png` → `png`
- `image/gif` → `gif`
- `image/webp` → `webp`

**SVG 被明确禁止**（见 `src/CoreBundle/Form/Type/ImageUploadType.php` #L39-L44），因为最终以 Data URI 内联渲染，SVG 中的 `<script>` 可能造成 XSS 风险。

### 2.3 上传保存链路

```
用户上传文件
    ↓
Live Component: Settings::save()
  src/SettingsBundle/Twig/Components/Settings.php #L147
    ↓
SettingsType 表单构建
  src/SettingsBundle/Form/Type/SettingsType.php #L81
  └─ 按 Setting.type 动态添加对应字段类型
    ↓
ImageUploadType::reverseTransform()
  src/CoreBundle/Form/Type/ImageUploadType.php #L106
    ├─ 校验 MIME 类型（仅 jpg/png/gif/webp）
    ├─ getimagesize() 验证真实图片
    └─ 拼接格式: {ext}|{base64_encode(file_get_contents)}
    ↓
SettingsRepository::store()
  src/SettingsBundle/Repository/SettingsRepository.php #L44
    ├─ flatten 嵌套数组为 key => value（/ 分隔）
    ├─ 事务中逐条 DQL UPDATE
    └─ finally 中 detach 所有 Setting 实体
```

### 2.4 设置键直接更新的实现

`SettingsRepository::store()` 是配置保存的核心入口，**不通过实体状态追踪更新，而是直接用 DQL 执行 UPDATE**。

**步骤 1：flatten 展平嵌套数组**

`src/SettingsBundle/Repository/SettingsRepository.php` #L93-L106

```php
private function flatten(array $array, string $prefix = ''): array
{
    $result = [];
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $result = [...$result, ...$this->flatten($value, $prefix . $key . '/')];
        } else {
            $result[$prefix . $key] = $value;
        }
    }
    return $result;
}
```

将表单提交的嵌套结构：
```php
['system' => ['company' => ['logo' => 'png|...']]]
```
展平为：
```php
['system/company/logo' => 'png|...']
```

**步骤 2：事务中逐条 UPDATE**

`src/SettingsBundle/Repository/SettingsRepository.php` #L50-L71

```php
$entityManager->wrapInTransaction(function () use ($settings): void {
    foreach ($settings as $key => $value) {
        // 特殊 key 的旁路处理（custom_domain、company_name）
        if ('system/domain/custom_domain' === $key) { ... }
        if ('system/company/company_name' === $key) { ... }

        // 直接执行 DQL UPDATE
        $this->createQueryBuilder('s')
            ->update()
            ->set('s.value', ':val')
            ->where('s.key = :key')
            ->setParameter('key', $key)
            ->setParameter('val', empty($value) ? null : $value)
            ->getQuery()
            ->execute();
    }
});
```

关键特点：
- 使用 `createQueryBuilder()->update()` 直接生成 DQL UPDATE 语句
- 不加载实体到内存，直接批量更新，性能更好
- 空值（empty）统一存储为 `null`
- `custom_domain` 和 `company_name` 有特殊旁路逻辑（同步更新 Company 表）

**步骤 3：finally 中 detach 所有 Setting 实体**

`src/SettingsBundle/Repository/SettingsRepository.php` #L73-L79

```php
$unitOfWork = $entityManager->getUnitOfWork();
$entities = $unitOfWork->getIdentityMap()[Setting::class] ?? [];
foreach ($entities as $entity) {
    $entityManager->detach($entity);
}
```

原因：由于 UPDATE 是直接执行 SQL，内存中的实体对象仍然是旧值。detach 后下次查询会从数据库重新加载，避免读到过期数据。

### 2.5 读取到页眉的链路

```
Twig 模板渲染 PDF
    ↓
{% if setting('system/company/logo') is not empty %}
    {{ app_logo(50) }}
{% endif %}
    ↓
├─ setting() → SettingsExtension::getSetting()
│    src/SettingsBundle/Twig/Extension/SettingsExtension.php #L43
│    → SystemConfig::get('system/company/logo')
│       src/SettingsBundle/SystemConfig.php #L40
│       → SettingsRepository::getSetting($key, $company)
│          src/SettingsBundle/Repository/SettingsRepository.php #L108
│          → findOneBy(['key' => $key])
│             (受 CompanyFilter 自动过滤)
│
└─ app_logo() → GlobalExtension::displayAppLogo()
     src/CoreBundle/Twig/Extension/GlobalExtension.php #L149
     ├─ 判断 installed + 公司是否已选择
     ├─ 读取 SystemConfig::get('system/company/logo')
     ├─ 未设置则走默认 Logo 逻辑
     ├─ explode('|', $logo) → [$type, $base64data]
     └─ 渲染为 <img src="data:image/{type};base64,{data}" width="50"/>
    ↓
mPDF::WriteHTML() 接收含内联 Data URI 的 HTML
  src/CoreBundle/Pdf/Generator.php #L53
```

### 2.6 默认 Logo 的多层 fallback 逻辑

`displayAppLogo()` 中有**三层 fallback 机制**，应对不同场景：

`src/CoreBundle/Twig/Extension/GlobalExtension.php` #L149-L168

```php
public function displayAppLogo(
    Environment $env,
    string $width = 'auto',
    ?Company $company = null,
    bool $showDefault = false,
    bool $showOnlyAppIcon = false
): string {
    // 第一层：$showDefault 强制显示默认 Logo
    $logo = $showDefault ? self::DEFAULT_LOGO : null;

    // 第二层：系统已安装 & 不是仅显示应用图标
    if ($this->installed && ! $showOnlyAppIcon) {
        $logo = $this->companySelector->getCompany() instanceof Ulid
            ? $this->systemConfig->get('system/company/logo', $company)  // 已选公司 → 读数据库
            : self::DEFAULT_LOGO;                                         // 未选公司 → 用默认

        // 第三层：读出来是 null 的话
        if (null === $logo) {
            $logo = $showDefault ? self::DEFAULT_LOGO : null;
        }
    }

    // 最终为 null → 返回空字符串（不显示 Logo）
    if (null === $logo) {
        return '';
    }

    // 解析格式并渲染为 <img>
    [$type, $logo] = explode('|', $logo);
    return $env->createTemplate('<img src="data:image/{{ type }};base64,{{ logo }}" ...')->render(...);
}
```

**各场景对应结果：**

| 场景 | $showDefault | 结果 |
|------|-------------|------|
| 系统未安装 | false | 空字符串（不显示） |
| 系统未安装 | true | 默认 Logo |
| 已安装，未选公司 | - | 默认 Logo（安装向导、CLI 等场景） |
| 已安装，已选公司，已配置 Logo | - | 公司自定义 Logo |
| 已安装，已选公司，未配置 Logo | false | 空字符串（不显示） |
| 已安装，已选公司，未配置 Logo | true | 默认 Logo |
| $showOnlyAppIcon = true | - | 不显示 Logo（仅显示应用图标模式） |

**配套的 company_name() 函数逻辑：**

`src/CoreBundle/Twig/Extension/GlobalExtension.php` #L122-L128

```php
new TwigFunction('company_name', function (): string {
    if ($this->companySelector->getCompany() instanceof Ulid) {
        return $this->systemConfig->get('system/company/company_name') ?? SolidInvoiceCoreBundle::APP_NAME;
    }
    return SolidInvoiceCoreBundle::APP_NAME;
}),
```

- 已选公司且配置了名称 → 自定义公司名
- 其他所有情况 → `APP_NAME`（SolidInvoice）

---

## 三、字体资源如何进入 PDF

字体通过 **三层定义** 进入 PDF，优先级从低到高：

### 3.1 第一层：mPDF 默认字体配置

在 `Generator::generate()` 中实例化 mPDF 时设置 `default_font`：

```php
$mpdf = new Mpdf([
    'tempDir' => $this->cacheDir . '/pdf',
    'default_font' => 'helvetica',  // 全局默认字体
    ...
]);
```

文件：`src/CoreBundle/Pdf/Generator.php` #L35-L46

这是 mPDF 内部的兜底字体，当 CSS 中没有指定字体时使用。

### 3.2 第二层：全局 PDF 样式表（pdf.css）

`assets/scss/pdf.scss` 编译为 `public/static/pdf.css`，由模板内联加载：

```twig
<style type="text/css">
    {{ file(asset('static/pdf.css')) }}
</style>
```

文件：`src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` #L17
（或基础模板 `src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig` #L18）

pdf.css 中的字体定义：
```scss
body {
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
    font-size: 10pt;
    line-height: 1.5;
}
```

文件：`assets/scss/pdf.scss` #L19-L26

**构建方式：** Webpack Encore 构建，入口定义在 `webpack.config.js` #L9：
```js
.addStyleEntry('pdf', './assets/scss/pdf.scss')
```

### 3.3 第三层：各模板自定义字体

每套模板可通过 `extra_styles` block 或内联 style 属性覆盖字体。

**示例 1：modern 模板（extra_styles block）**

```twig
{% block extra_styles %}
    body { font-family: helvetica, Arial, sans-serif; color: #1e293b; }
{% endblock %}
```

文件：`src/InvoiceBundle/Resources/views/Templates/modern/pdf.html.twig` #L10-L12

**示例 2：friendly 模板（内联 style）**

```twig
<div style="font-family: Georgia, serif; font-size: 16pt; ...">
```

文件：`src/InvoiceBundle/Resources/views/Templates/friendly/pdf.html.twig` #L33

**示例 3：宏中的等宽字体（palette 参数）**

```twig
{% macro totals_block(invoice, currency, palette={}) %}
    {% set p = {
        mono: 'Courier New, monospace'
    }|merge(palette) %}
```

文件：`src/InvoiceBundle/Resources/views/Templates/_macros.html.twig` #L82-L95

### 3.4 字体在 PDF 中的最终形态

mPDF 接收到 HTML + CSS 后：
1. 解析 CSS `font-family` 声明
2. 匹配内部字体或使用核心字体（Helvetica 是 mPDF 内置核心字体之一）
3. 将字体子集嵌入 PDF（仅嵌入使用到的字形）

---

## 四、颜色资源如何进入 PDF

颜色通过 **四层定义** 进入 PDF，优先级从低到高：

### 4.1 第一层：设计系统 Design Tokens（源文件）

`assets/scss/design-system/_tokens.scss` 定义了品牌颜色变量：

```scss
--swp-primary:      #2e963a;   /* 品牌主色 */
--swp-primary-dark:  #1f6c29;
--swp-success:    #10b981;
--swp-danger:      #ef4444;
--swp-gray-50 ~ --swp-gray-900
```

> **注意**：mPDF **不支持 CSS 变量（var(--xxx)）**，这些 tokens 仅供网页端使用，PDF 样式直接硬编码颜色值。

文件：`assets/scss/design-system/_tokens.scss`

### 4.2 第二层：全局 PDF 样式表（pdf.css）

`assets/scss/pdf.scss` 中所有颜色都是硬编码值，与 tokens 保持一致：

| 用途 | 颜色值 | 位置 |
|------|--------|------|
| 链接色 | `#2e963a` | #L69 |
| 总计背景 | `#2e963a` | #L210 |
| 标签下划线 | `#2e963a` | #L249 |
| 主文字 | `#1e293b` | #L23 |
| 次级文字 | `#475569` | #L161 |
| 辅助文字 | `#64748b` | #L86 |
| 成功色 | `#10b981` | #L90 |
| 危险/折扣 | `#ef4444` | #L94, #L361 |
| 总计已付背景 | `#f0fdf4` | #L374 |
| 总计已付文字 | `#166534` | #L388 |
| 余额警告背景 | `#fef3c7` | #L393 |
| 余额警告文字 | `#92400e` | #L407 |

### 4.3 第三层：模板内联 style 属性

默认发票模板 `Pdf/invoice.html.twig` 大量使用内联 style：

```twig
<div class="company-name" style="font-size: 18pt; font-weight: bold; color: #1e293b;">
```

文件：`src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` #L63

```twig
<td style="background-color: #2e963a; color: #ffffff; ...">
```

文件：同上 #L156

逾期日期红色警示：
```twig
{% if isOverdue %}color: #dc2626;{% else %}color: #1e293b;{% endif %}
```

文件：同上 #L122

### 4.4 第四层：宏调色板（palette）

`_macros.html.twig` 中的可复用宏通过 `palette` 参数接受颜色覆盖：

```twig
{% macro totals_block(invoice, currency, palette={}) %}
    {% set p = {
        subtotalColor: '#1e293b',
        labelColor: '#475569',
        discountColor: '#ef4444',
        paymentColor: '#10b981',
        totalBg: '#f0fdf4',
        totalColor: '#166534',
        balanceBg: '#fef3c7',
        balanceColor: '#92400e',
        mono: 'Courier New, monospace'
    }|merge(palette) %}
```

文件：`src/InvoiceBundle/Resources/views/Templates/_macros.html.twig` #L82-L95

各模板可传入自定义 palette，例如 modern 模板：
```twig
{{ inv.totals_block(invoice, currency, {
    totalBg: '#ffffff',
    totalColor: '#0f172a',
    balanceBg: '#ffffff',
    balanceColor: '#0f172a'
}) }}
```

文件：`src/InvoiceBundle/Resources/views/Templates/modern/pdf.html.twig` #L61

### 4.5 页眉区域使用的关键颜色

| 元素 | 颜色 | 来源 |
|------|------|------|
| 公司名称文字 | `#1e293b` | pdf.css .company-name + 内联 style |
| 公司详情文字 | `#475569` | pdf.css .company-details |
| 发票标签文字 | `#64748b` | pdf.css .invoice-label |
| 发票编号文字 | `#1e293b` | pdf.css .invoice-number |
| 日期标签文字 | `#64748b` | pdf.css .date-label |
| 日期值文字 | `#1e293b` | pdf.css .date-value |
| 逾期日期文字 | `#dc2626` / `#f59e0b` | 模板内联条件判断 |
| 总计盒背景 | `#2e963a`（品牌主色） | pdf.css .total-due-box + 内联 style |
| 总计盒文字 | `#ffffff` | pdf.css .total-due-box |
| 页脚边框 | `#e2e8f0` | 内联 footer-style |
| 页脚文字 | `#64748b` | 内联 footer-style |

---

## 五、缓存与非缓存内容总览

### 5.1 会被缓存的内容

| 资源 | 缓存位置 | 缓存类型 | 缓存时机 |
|------|----------|----------|----------|
| **mPDF 临时文件** | `var/cache/{env}/pdf/` | 文件系统 | mPDF 运行时自动创建 |
| **字体子集** | `var/cache/{env}/pdf/` | 文件系统 | mPDF 首次使用某字体时生成 |
| **Twig 模板编译** | `var/cache/{env}/twig/` | 文件系统 | 首次访问模板时 |
| **PDF 样式表 (pdf.css)** | `public/static/pdf.css` | 静态文件 | `bun run build` 构建时 |
| **Doctrine 结果缓存** | 取决于配置（默认无） | 可选 | 可配置但默认不开启 |
| **SystemConfig 全量设置** | `SystemConfig::$settings` 静态属性 | 内存（请求内） | 调用 `getAll()` 时通过 `load()` 填充 |

**mPDF 缓存目录配置：**
```php
'tempDir' => $this->cacheDir . '/pdf',
```

文件：`src/CoreBundle/Pdf/Generator.php` #L38
`$cacheDir` 来自服务容器参数绑定：`src/CoreBundle/Resources/config/services/services.php` #L53

### 5.2 不会被缓存的内容

| 资源 | 原因 | 每次请求操作 |
|------|------|--------------|
| **Logo 图片数据** | 无应用层缓存 | 每次查询 `app_config` 表 |
| **公司名称** | 无应用层缓存 | 每次查询 `app_config` 表 |
| **所有设置值（setting()）** | `SystemConfig::get()` 不走静态缓存 | 每次调用 `findOneBy()` 查数据库 |
| **PDF 生成结果** | 每次重新生成 | 每次调用 `Generator::generate()` 重新渲染 |
| **模板渲染输出** | Twig 只缓存编译后的模板类，不缓存渲染结果 | 每次动态渲染 |
| **颜色值** | 硬编码在 CSS/模板中，无运行时动态品牌色 | 每次从 CSS/模板读取 |
| **字体声明** | 硬编码在 CSS/模板中 | 每次从 CSS/模板读取 |

### 5.3 关于 SystemConfig 静态缓存的注意点

`SystemConfig` 有一个静态属性 `self::$settings` 用于批量缓存：

```php
/** @var array<string, string> */
private static array $settings = [];
```

文件：`src/SettingsBundle/SystemConfig.php` #L22

**但 `get()` 方法不使用此缓存**，它直接走 repository 查询。只有 `getAll()` 方法调用 `load()` 时才填充并使用这个静态缓存。

文件：`src/SettingsBundle/SystemConfig.php` #L40-L47（`get()` 方法）

---

## 六、多公司场景下的读写边界深度分析

SolidInvoice 使用 **Doctrine SQL Filter + CompanySelector** 的组合实现多租户数据隔离。这一层机制对品牌配置的读取和保存都有深刻影响。

### 6.1 三层隔离机制

```
┌─────────────────────────────────────────────────────┐
│              多公司数据隔离三层机制                    │
├─────────────────┬───────────────────────────────────┤
│  CompanyAware   │  实体级：为模型添加 company 关联    │
│     trait       │  （数据库列 company_id）            │
├─────────────────┼───────────────────────────────────┤
│ CompanyFilter   │  SQL 级：自动追加 WHERE 条件        │
│ (SQL Filter)    │  对 SELECT/UPDATE/DELETE 都生效    │
├─────────────────┼───────────────────────────────────┤
│ CompanySelector │  应用级：管理当前公司上下文          │
│                 │  启用/禁用 filter，设 companyId    │
└─────────────────┴───────────────────────────────────┘
```

**CompanyAware trait：**
`src/CoreBundle/Traits/Entity/CompanyAware.php`

为实体添加 `$company` ManyToOne 关联，对应数据库 `company_id` 列。
`Setting` 实体使用了此 trait（`src/SettingsBundle/Entity/Setting.php` #L35）。

**CompanyFilter（SQL 级过滤）：**
`src/CoreBundle/Doctrine/Filter/CompanyFilter.php`

Doctrine SQL Filter，在 SQL 生成阶段自动为所有带 `company` 关联的实体追加 `WHERE company_id = ?` 条件。

```php
public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
{
    if (! $targetEntity->hasAssociation('company')) {
        return '';  // 没有 company 关联的实体不过滤
    }

    if ($this->hasParameter('companyId')) {
        return sprintf('%s.company_id = %s', $targetTableAlias, $this->getParameter('companyId'));
    }

    return '';
}
```

关键点：**CompanyFilter 作用于 SQL 层，不只是 SELECT，对 UPDATE 和 DELETE 也会生效**（只要是通过 QueryBuilder 生成的 DML）。

**CompanySelector（公司切换器）：**
`src/CoreBundle/Company/CompanySelector.php`

管理当前请求的公司上下文，核心操作：
- `switchCompany(Ulid $companyId)`：启用 filter + 设置 companyId 参数
- `reset()`：禁用 filter + 清空 companyId
- `getCompany()`：返回当前公司 ID（Ulid 或 null）

### 6.2 公司过滤对读取操作的限制

#### 场景 A：正常读取（不传 company 参数）

```php
public function getSetting(string $key, ?Company $company): ?Setting
{
    $params = ['key' => $key];
    // 不传 company 时，直接 findOneBy
    return $this->findOneBy($params);
}
```

文件：`src/SettingsBundle/Repository/SettingsRepository.php` #L108-L118

**实际执行的 SQL：**
```sql
SELECT * FROM app_config 
WHERE setting_key = 'system/company/logo' 
  AND company_id = 0xABCDEF...  -- CompanyFilter 自动追加
```

结果：只能拿到**当前公司**的配置，数据天然隔离。

#### 场景 B：指定公司读取（传 company 参数）

```php
if ($company instanceof Company) {
    $this->companySelector->reset();   // ① 先关掉全局过滤器
    $params['company'] = $company;      // ② 手动加上 company 条件
}
try {
    return $this->findOneBy($params);   // ③ 执行查询
} finally {
    if ($company instanceof Company) {
        $this->companySelector->switchCompany($company->getId());  // ④ 恢复过滤器
    }
}
```

文件：同上 #L112-L123

**为什么必须先 reset 再手动加条件？**

如果不 reset，直接传 `company => $company`，会发生什么？

```sql
-- 预期（只查指定公司）：
SELECT * FROM app_config WHERE setting_key = ? AND company_id = ?

-- 实际（两个 AND 条件叠加）：
SELECT * FROM app_config 
WHERE setting_key = ? 
  AND company_id = 0x当前公司...     -- CompanyFilter 自动加的
  AND company_id = 0x指定公司...     -- 你手动传的
```

如果 `指定公司 ≠ 当前公司`，两个条件用 AND 连接，**永远查不到结果**。

**正确做法是"替换"而非"叠加"：**
1. `reset()` 关掉全局 filter，移除自动追加的条件
2. 通过 `$params['company']` 手动传入目标公司，Doctrine 会把它转为 WHERE 条件
3. 最终 SQL 只有一个 company_id 条件，指向目标公司
4. `finally` 中恢复 filter，不影响后续查询

**这种模式的使用场景：**
- 管理员后台查看其他公司的配置
- SaaS 系统跨公司数据操作
- 安装/初始化阶段创建新公司的设置

### 6.3 公司过滤对更新操作的限制

`store()` 方法使用 DQL UPDATE：

```php
$this->createQueryBuilder('s')
    ->update()
    ->set('s.value', ':val')
    ->where('s.key = :key')
    ->setParameter('key', $key)
    ->setParameter('val', empty($value) ? null : $value)
    ->getQuery()
    ->execute();
```

文件：`src/SettingsBundle/Repository/SettingsRepository.php` #L58-L65

**实际执行的 SQL：**
```sql
UPDATE app_config 
SET setting_value = ? 
WHERE setting_key = ? 
  AND company_id = 0xABCDEF...  -- CompanyFilter 自动追加
```

由于 **CompanyFilter 对 UPDATE 也生效**，更新操作也是公司隔离的：
- 只能更新当前公司的设置
- 即使 key 写错也不会影响其他公司
- 不需要手动加 company 条件

**对新公司的影响：**

如果一个新公司还没有某个 key 的设置行，`UPDATE ... WHERE setting_key = ? AND company_id = ?` 的影响行数为 0。

`store()` 方法目前**不会自动创建缺失的设置行**，它假设所有 key 都已经有对应行（由迁移或安装程序初始化）。

> 注：早期版本的 `store()` 可能有创建新行的逻辑，需根据实际代码确认。当前代码中只有 UPDATE，没有 INSERT。

### 6.4 未选公司时的默认行为

当 `CompanySelector::getCompany()` 返回 `null` 时（即当前没有选择任何公司），品牌配置的读取会走默认逻辑。

**触发未选公司的场景：**
- 安装向导阶段（还没创建公司）
- CLI 命令（非 web 请求，没有公司上下文）
- 全局管理后台（还没切换到具体公司）
- 系统级别的设置页面

**此时 Logo 的行为：**

`src/CoreBundle/Twig/Extension/GlobalExtension.php` #L154

```php
$logo = $this->companySelector->getCompany() instanceof Ulid
    ? $this->systemConfig->get('system/company/logo', $company)
    : self::DEFAULT_LOGO;  // ← 未选公司时直接用默认 Logo
```

**此时公司名称的行为：**

`src/CoreBundle/Twig/Extension/GlobalExtension.php` #L122-L127

```php
if ($this->companySelector->getCompany() instanceof Ulid) {
    return $this->systemConfig->get('system/company/company_name') ?? SolidInvoiceCoreBundle::APP_NAME;
}
return SolidInvoiceCoreBundle::APP_NAME;  // ← 未选公司时用 APP_NAME
```

**此时设置查询的行为：**

如果 CompanyFilter 没有启用（companyId 参数未设置），`getSetting()` 会怎样？

看 CompanyFilter 的逻辑：
```php
if ($this->hasParameter('companyId')) {
    return sprintf('%s.company_id = %s', ...);
}
return '';  // 没有 companyId 参数就不过滤
```

文件：`src/CoreBundle/Doctrine/Filter/CompanyFilter.php` #L60-L64

未选公司时：
- CompanyFilter 返回空字符串，**不附加任何 company 条件**
- `findOneBy(['key' => $key])` 会返回**所有公司**中匹配的第一条（按主键排序）
- 这可能导致读到任意公司的数据，存在安全风险

但 `GlobalExtension` 中的判断避免了这个问题：**未选公司时根本不查数据库，直接用默认值**。

### 6.5 读写边界总结表

| 操作 | 场景 | 公司过滤是否生效 | 结果范围 |
|------|------|-----------------|----------|
| 读取 | 已选公司，不传 company | 是 | 当前公司 |
| 读取 | 已选公司，传指定 company | 临时关闭再手动加条件 | 指定公司 |
| 读取 | 未选公司 | 否（但代码不查库） | 默认 Logo/名称 |
| 更新 | 已选公司 | 是 | 当前公司的行 |
| 更新 | 未选公司 | 否 | 所有公司同 key 的行都会被更新（⚠️） |
| 删除 | 已选公司 | 是 | 当前公司的行 |
| 新建 | N/A | - | store() 只 UPDATE 不 INSERT |

### 6.6 设计上的关键点

1. **Filter 是 SQL 级别的**：不仅影响 SELECT，也影响通过 QueryBuilder 生成的 UPDATE/DELETE
2. **指定公司查询必须 reset**：否则两个 company_id 条件 AND 起来查不到
3. **reset 后必须恢复**：用 try-finally 模式，确保后续查询仍受过滤保护
4. **未选公司时不查库**：GlobalExtension 中有防御性判断，避免读到任意公司的数据
5. **UPDATE 不自动建行**：store() 假设所有 key 都已存在，新公司需要通过初始化流程创建设置行

---

## 七、关键文件索引（仓库相对路径）

### 7.1 品牌标识（Logo）相关

| 文件 | 作用 |
|------|------|
| `src/SettingsBundle/Entity/Setting.php` | 设置实体，app_config 表映射，CompanyAware trait |
| `src/SettingsBundle/Repository/SettingsRepository.php` | 设置读写，store()/getSetting()，flatten 逻辑 |
| `src/SettingsBundle/SystemConfig.php` | 系统配置读取入口，get()/getAll() |
| `src/SettingsBundle/Twig/Extension/SettingsExtension.php` | Twig setting() 函数 |
| `src/CoreBundle/Twig/Extension/GlobalExtension.php` | Twig app_logo()/company_name() 函数，默认 Logo 常量 |
| `src/CoreBundle/Form/Type/ImageUploadType.php` | Logo 上传表单，文件↔字符串转换，MIME 校验 |
| `src/CoreBundle/Config/SystemConfigProvider.php` | 注册 system/company/logo 等设置字段 |
| `src/SettingsBundle/Twig/Components/Settings.php` | 设置页面 Live Component，保存入口 |
| `src/SettingsBundle/Form/Type/SettingsType.php` | 设置表单构建器，按 type 动态添加字段 |

### 7.2 字体和颜色相关

| 文件 | 作用 |
|------|------|
| `assets/scss/pdf.scss` | PDF 全局样式（字体、颜色硬编码） |
| `assets/scss/design-system/_tokens.scss` | 设计系统颜色 tokens（网页用，PDF 不支持） |
| `webpack.config.js` | Webpack Encore 配置，pdf 样式入口 |
| `src/CoreBundle/Pdf/Generator.php` | mPDF 封装，default_font 配置，tempDir 配置 |
| `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | 默认发票 PDF 模板（内联样式） |
| `src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig` | 模板化 PDF 基础模板 |
| `src/InvoiceBundle/Resources/views/Templates/_macros.html.twig` | 可复用宏（含 palette 调色板） |
| `src/InvoiceBundle/Resources/views/Templates/classic/pdf.html.twig` | Classic 模板 |
| `src/InvoiceBundle/Resources/views/Templates/modern/pdf.html.twig` | Modern 模板 |
| `src/InvoiceBundle/Resources/views/Templates/friendly/pdf.html.twig` | Friendly 模板 |
| `src/InvoiceBundle/Resources/views/Templates/studio/pdf.html.twig` | Studio 模板 |

### 7.3 多租户过滤相关

| 文件 | 作用 |
|------|------|
| `src/CoreBundle/Traits/Entity/CompanyAware.php` | 多租户实体 trait，添加 company 关联 |
| `src/CoreBundle/Doctrine/Filter/CompanyFilter.php` | Doctrine SQL 过滤器，SQL 层 company 隔离 |
| `src/CoreBundle/Company/CompanySelector.php` | 公司切换器，启用/禁用 filter，管理上下文 |
| `src/CoreBundle/Company/CompanySelectorInterface.php` | 公司选择器接口 |

### 7.4 缓存配置相关

| 文件 | 作用 |
|------|------|
| `src/CoreBundle/Resources/config/services/services.php` | Generator 的 $cacheDir 参数绑定 |
| `src/CoreBundle/Pdf/Generator.php` | mPDF tempDir 配置 |

### 7.5 数据库迁移

| 文件 | 作用 |
|------|------|
| `migrations/Version20000.php` | 初始 app_config 表 + Logo 初始行 |
| `migrations/Version20200.php` | 添加 company_id 多租户列 |
| `migrations/Version30000_3.php` | 添加 form_options/default_value 列 |

### 7.6 测试文件

| 文件 | 作用 |
|------|------|
| `src/CoreBundle/Tests/Form/Type/ImageUploadTypeTest.php` | 图片上传类型单元测试 |
| `src/CoreBundle/Tests/Pdf/GeneratorTest.php` | PDF 生成器单元测试 |
| `src/SettingsBundle/Tests/Twig/Components/SettingsTest.php` | 设置组件测试 |
| `src/SettingsBundle/Tests/SystemConfigTest.php` | 系统配置测试 |
| `src/SaasBundle/Tests/Functional/PdfBaseCustomBrandingGateTest.php` | 自定义品牌门控测试 |
