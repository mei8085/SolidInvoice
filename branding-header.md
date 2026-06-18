# 品牌信息接入发票页眉代码实现梳理

## 目录

- [一、整体架构概览](#一整体架构概览)
- [二、品牌标识（Logo）的存储与读取链路](#二品牌标识logo的存储与读取链路)
- [三、字体资源如何进入 PDF](#三字体资源如何进入-pdf)
- [四、颜色资源如何进入 PDF](#四颜色资源如何进入-pdf)
- [五、缓存与非缓存内容总览](#五缓存与非缓存内容总览)
- [六、多公司配置过滤对读取和保存的影响](#六多公司配置过滤对读取和保存的影响)
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
- 实体定义：`src/SettingsBundle/Entity/Setting.php`（#L33 TABLE_NAME 常量，#L43-L47 key/value 字段）
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
SettingsType 表单
  src/SettingsBundle/Form/Type/SettingsType.php #L81
    ↓
ImageUploadType::reverseTransform()
  src/CoreBundle/Form/Type/ImageUploadType.php #L106
    ├─ 校验 MIME 类型（仅 jpg/png/gif/webp）
    ├─ getimagesize() 验证真实图片
    └─ 拼接格式: {ext}|{base64_encode(file_get_contents)}
    ↓
SettingsRepository::store()
  src/SettingsBundle/Repository/SettingsRepository.php #L44
    ├─ flatten 嵌套数组为 key => value
    └─ 逐条 UPDATE app_config.setting_value
```

### 2.4 读取到页眉的链路

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
│          → findOneBy(['key' => $key, 'company' => $company])
│             (受 CompanyFilter 自动过滤)
│
└─ app_logo() → GlobalExtension::displayAppLogo()
     src/CoreBundle/Twig/Extension/GlobalExtension.php #L149
     ├─ 读取 SystemConfig::get('system/company/logo')
     ├─ 未设置则使用 DEFAULT_LOGO 常量
     ├─ explode('|', $logo) → [$type, $base64data]
     └─ 渲染为 <img src="data:image/{type};base64,{data}" width="50"/>
    ↓
mPDF::WriteHTML() 接收含内联 Data URI 的 HTML
  src/CoreBundle/Pdf/Generator.php #L53
```

### 2.5 默认 Logo

当数据库中未设置自定义 Logo 时，使用内置常量：

`src/CoreBundle/Twig/Extension/GlobalExtension.php` #L44 中的 `DEFAULT_LOGO`

格式与存储格式完全一致：`png|<base64编码的默认图片>`

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

## 六、多公司配置过滤对读取和保存的影响

SolidInvoice 使用 **Doctrine SQL Filter + CompanySelector** 实现多租户数据隔离。

### 6.1 核心机制

**CompanyAware trait：**

`src/CoreBundle/Traits/Entity/CompanyAware.php`

为实体添加 `$company` 关联字段（对应数据库 `company_id` 列）。

`Setting` 实体使用了此 trait（因为 app_config 表有 company_id 列）。

**CompanyFilter（SQL 级过滤）：**

`src/CoreBundle/Doctrine/Filter/CompanyFilter.php`

Doctrine SQL Filter，在 SQL 层面自动为所有带 `company` 关联的实体追加 `WHERE company_id = ?` 条件。

当 `companyId` 参数被设置后，所有查询都会自动加上公司过滤，开发者无需手动处理。

**CompanySelector（公司切换器）：**

`src/CoreBundle/Company/CompanySelector.php`

管理当前请求的公司上下文，负责启用/禁用 CompanyFilter 并设置 companyId 参数。

### 6.2 对读取的影响

**正常读取（自动过滤）：**

```php
// SettingsRepository::getSetting()
public function getSetting(string $key, ?Company $company): ?Setting
{
    // 默认情况下，CompanyFilter 已启用
    // findOneBy(['key' => $key]) 会自动追加 AND company_id = 当前公司
    return $this->findOneBy(['key' => $key]);
}
```

文件：`src/SettingsBundle/Repository/SettingsRepository.php` #L108-L124

因为 `company` 过滤总是启用的，查询 Logo 时只能拿到当前公司的数据，不会串数据。

**指定公司读取（需要切换上下文）：**

当 `getSetting()` 显式传入 `$company` 参数时，需要临时切换公司：

```php
if ($company instanceof Company) {
    $this->companySelector->reset();   // 禁用过滤器
    $params['company'] = $company;      // 手动加上 company 条件
}
try {
    return $this->findOneBy($params);
} finally {
    if ($company instanceof Company) {
        $this->companySelector->switchCompany($company->getId());  // 恢复过滤器
    }
}
```

文件：同上 #L111-L123

这是一种"先关掉全局过滤器，再手动加条件"的模式，确保查询指定公司的数据而不影响全局状态。

### 6.3 对保存的影响

**SettingsRepository::store() 保存时：**

`src/SettingsBundle/Repository/SettingsRepository.php` #L44-L81

由于 CompanyFilter 自动生效，`findOneBy(['key' => $key])` 只会找到当前公司的设置行，UPDATE 操作也只会影响当前公司的数据。

**新公司首次保存时：**

如果某个设置 key 在当前公司还没有行，`store()` 方法会创建新的 Setting 实体并设置 company：

```php
$setting = $this->findOneBy(['key' => $key]);
if (null === $setting) {
    $setting = new Setting();
    $setting->setKey($key);
    // ... 由 CompanyAware 自动设置当前 company
}
```

（具体逻辑在 store() 方法内部的循环中）

### 6.4 GlobalExtension 中的特殊处理

`displayAppLogo()` 方法有一个判断：

```php
$logo = $this->companySelector->getCompany() instanceof Ulid
    ? $this->systemConfig->get('system/company/logo', $company)
    : self::DEFAULT_LOGO;
```

文件：`src/CoreBundle/Twig/Extension/GlobalExtension.php` #L156-L159

含义：
- 如果当前已选择了公司（companySelector 返回 Ulid），则从数据库读取 Logo
- 如果还没选择公司（如安装阶段、CLI 命令等），直接使用默认 Logo

---

## 七、关键文件索引（仓库相对路径）

### 7.1 品牌标识（Logo）相关

| 文件 | 作用 |
|------|------|
| `src/SettingsBundle/Entity/Setting.php` | 设置实体，app_config 表映射 |
| `src/SettingsBundle/Repository/SettingsRepository.php` | 设置读写，store()/getSetting() |
| `src/SettingsBundle/SystemConfig.php` | 系统配置读取入口 |
| `src/SettingsBundle/Twig/Extension/SettingsExtension.php` | Twig setting() 函数 |
| `src/CoreBundle/Twig/Extension/GlobalExtension.php` | Twig app_logo()/company_name() 函数 |
| `src/CoreBundle/Form/Type/ImageUploadType.php` | Logo 上传表单，文件↔字符串转换 |
| `src/CoreBundle/Config/SystemConfigProvider.php` | 注册 system/company/logo 等设置字段 |
| `src/SettingsBundle/Twig/Components/Settings.php` | 设置页面 Live Component，保存入口 |
| `src/SettingsBundle/Form/Type/SettingsType.php` | 设置表单构建器 |

### 7.2 字体和颜色相关

| 文件 | 作用 |
|------|------|
| `assets/scss/pdf.scss` | PDF 全局样式（字体、颜色硬编码） |
| `assets/scss/design-system/_tokens.scss` | 设计系统颜色 tokens（网页用） |
| `webpack.config.js` | Webpack Encore 配置，pdf 样式入口 |
| `src/CoreBundle/Pdf/Generator.php` | mPDF 封装，default_font 配置 |
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
| `src/CoreBundle/Traits/Entity/CompanyAware.php` | 多租户实体 trait |
| `src/CoreBundle/Doctrine/Filter/CompanyFilter.php` | Doctrine SQL 过滤器 |
| `src/CoreBundle/Company/CompanySelector.php` | 公司切换器 |
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
