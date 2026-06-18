# 公司品牌信息接入发票页眉代码实现分析

## 目录

- [1. 整体架构概述](#1-整体架构概述)
- [2. 品牌标识（Logo）的加载与缓存路径](#2-品牌标识logo的加载与缓存路径)
- [3. 字体资源的加载与缓存路径](#3-字体资源的加载与缓存路径)
- [4. 颜色资源的加载与缓存路径](#4-颜色资源的加载与缓存路径)
- [5. 数据流与调用链](#5-数据流与调用链)

---

## 1. 整体架构概述

SolidInvoice 的公司品牌信息（Logo、公司名称、颜色、字体等）通过 **设置系统（SettingsBundle）+ Twig 扩展 + PDF 生成器（mPDF）** 的组合方式接入发票页眉。

### 核心组件

| 组件 | 职责 | 位置 |
|------|------|------|
| `SettingsBundle` | 品牌配置的持久化存储与读取 | [SettingsBundle](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/SettingsBundle) |
| `GlobalExtension` | 提供 `app_logo()`、`company_name()` Twig 函数 | [GlobalExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php) |
| `SettingsExtension` | 提供 `setting()` Twig 函数 | [SettingsExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/SettingsBundle/Twig/Extension/SettingsExtension.php) |
| `Pdf\Generator` | mPDF 封装，负责 PDF 生成与缓存 | [Generator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Pdf/Generator.php) |
| 模板系统 | 8 套 PDF 模板（classic、modern、friendly、monochrome、photographer、studio、compact、editorial） | [Templates](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates) |

### 品牌信息渲染流程：

```
用户请求 PDF
    ↓
Action 类调用 Generator::generate()
    ↓
Twig 渲染 PDF 模板（继承 _pdf_base.html.twig）
    ↓
模板调用 Twig 函数获取品牌数据
    ↓
  ├─ app_logo()       → SystemConfig → SettingsRepository → DB (app_settings 表)
  ├─ company_name()   → SystemConfig → SettingsRepository → DB
  └─ setting(...)     → SettingsExtension → SystemConfig → DB
    ↓
内联 CSS 样式（pdf.css + 模板内联样式）
    ↓
mPDF 解析 HTML/CSS → 生成 PDF 二进制
```

---

## 2. 品牌标识（Logo）的加载与缓存路径

### 2.1 存储结构

Logo 以 **Base64 编码的图片数据**存储在数据库的 `app_settings` 表中，键名为 `system/company/logo`。

存储格式：
```
{image_type}|{base64_encoded_image_data
```

示例：`png|iVBORw0KGgoAAAANSUhEUgAAADIAAAAoCAYAAAC8cqlMAAAABGdBTUEAALGPC/xhBQAACjppQ0NQUGhvdG9zaG9wIElDQyBwcm9maWxl...`

### 2.2 加载链路

#### 步骤 1：Twig 模板调用

在 PDF 模板中通过 `app_logo(width)` 函数调用：

```twig
{# 例如 classic/pdf.html.twig #}
{% if setting('system/company/logo') is not empty %}
    <div style="margin-bottom: 8px;">{{ app_logo(50) }}</div>
{% endif %}
```

参考：[classic/pdf.html.twig#L14-L16](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/classic/pdf.html.twig#L14-L16)

#### 步骤 2：`GlobalExtension::displayAppLogo()

文件：[GlobalExtension.php#L149-L168](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php#L149-L168)

```php
public function displayAppLogo(
    Environment $env,
    string $width = 'auto',
    ?Company $company = null,
    bool $showDefault = false,
    bool $showOnlyAppIcon = false
): string {
    $logo = $showDefault ? self::DEFAULT_LOGO : null;

    if ($this->installed && ! $showOnlyAppIcon) {
        $logo = $this->companySelector->getCompany() instanceof Ulid
            ? $this->systemConfig->get('system/company/logo', $company)
            : self::DEFAULT_LOGO;

        if (null === $logo) {
            $logo = $showDefault ? self::DEFAULT_LOGO : null;
        }
    }

    if (null === $logo) {
        return '';
    }

    [$type, $logo] = explode('|', $logo);

    return $env->createTemplate(
        '<img src="data:image/{{ type }};base64,{{ logo }}" class="navbar-brand-image m-2" width="' . $width . '"/>'
    )->render(['type' => $type, 'logo' => $logo]);
}
```

关键点：
- 默认 Logo 常量 `self::DEFAULT_LOGO` 内置在类中（Base64 编码的默认图片
- 优先从 `SystemConfig` 读取 `system/company/logo`
- 解析 `type|base64data` 格式
- 输出为 Data URI 格式的 `<img>` 标签

#### 步骤 3：`SystemConfig::get()`

文件：[SystemConfig.php#L40-L47](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/SettingsBundle/SystemConfig.php#L40-L47)

```php
public function get(string $key, ?Company $company = null): ?string
{
    if (null === $this->installed || '' === $this->installed) {
        return null;
    }

    return $this->repository->getSetting($key, $company)?->getValue();
}
```

#### 步骤 4：`SettingsRepository::getSetting()`

文件：[SettingsRepository.php#L108-L124](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/SettingsBundle/Repository/SettingsRepository.php#L108-L124)

```php
public function getSetting(string $key, ?Company $company): ?Setting
{
    $params = ['key' => $key];

    if ($company instanceof Company) {
        $this->companySelector->reset();
        $params['company'] = $company;
    }

    try {
        return $this->findOneBy($params);
    } finally {
        if ($company instanceof Company) {
            $this->companySelector->switchCompany($company->getId());
        }
    }
}
```

### 2.3 Logo 配置入口

Logo 通过 `ImageUploadType` 表单类型上传并保存：

文件：[SystemConfigProvider.php#L32](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Config/SystemConfigProvider.php#L32)

```php
new Config('system/company/logo', null, null, ImageUploadType::class),
```

### 2.4 缓存路径

Logo **无独立缓存层**，每次请求都直接查询数据库。
- `SystemConfig` 有静态属性 `self::$settings` 用于批量设置缓存（通过 `load()` 方法），但 `get()` 方法不使用此缓存，直接走 repository 查询
- 最终输出为 Data URI，直接嵌入 HTML 嵌入，由 mPDF 内联处理，不产生文件缓存

---

## 3. 字体资源的加载与缓存路径

### 3.1 默认字体配置

mPDF 实例化时配置默认字体：

文件：[Generator.php#L35-L46](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Pdf/Generator.php#L35-L46)

```php
public function generate(string $html): string
{
    $mpdf = new Mpdf([
        'tempDir' => $this->cacheDir . '/pdf',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 20,
        'margin_bottom' => 25,
        'margin_header' => 10,
        'margin_footer' => 10,
        'default_font' => 'helvetica',  // 默认字体
    ]);
    // ...
}
```

### 3.2 字体在模板中的定义

字体通过两个层级定义：

#### 层级 1：全局 PDF 样式表

文件：[pdf.scss#L19-L26](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/assets/scss/pdf.scss#L19-L26)

```scss
body {
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
    font-size: 10pt;
    line-height: 1.5;
    color: #1e293b;
    margin: 0;
    padding: 0;
}
```

#### 层级 2：各模板自定义字体（通过 `extra_styles` block）

示例 modern 模板：

文件：[modern/pdf.html.twig#L10-L12](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/modern/pdf.html.twig#L10-L12)

```twig
{% block extra_styles %}
    body { font-family: helvetica, Arial, sans-serif; color: #1e293b; }
{% endblock %}
```

示例 friendly 模板使用衬线字体：

文件：[friendly/pdf.html.twig#L33](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/friendly/pdf.html.twig#L33)

```twig
<div style="font-family: Georgia, serif; font-size: 16pt; color: #1e293b; margin-bottom: 8px;">
```

### 3.3 CSS 加载方式

PDF 模板通过 `file(asset('static/pdf.css'))` 内联加载：

文件：[_pdf_base.html.twig#L17-L19](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig#L17-L19)

```twig
<style type="text/css">
    {{ file(asset('static/pdf.css')) }}
    ...
</style>
```

### 3.4 字体缓存路径

mPDF 的字体和临时文件缓存目录：

```
%kernel.cache_dir%/pdf
```

配置来源：

文件：[services.php#L53](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Resources/config/services/services.php#L53)

```php
->bind('$cacheDir', param('kernel.cache_dir'))
```

即 `var/cache/{env}/pdf`，由 mPDF 自动管理字体子集、字体缓存、临时文件等。

---

## 4. 颜色资源的加载与缓存路径

### 4.1 颜色定义方式

颜色通过三个层级定义：

#### 层级 1：设计系统 Design Tokens

文件：[_tokens.scss](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/assets/scss/design-system/_tokens.scss)

定义了品牌颜色变量（供 Web 网页使用，PDF 不支持 CSS 变量）：

```scss
--swp-primary:      #2e963a;   /* 品牌主色（绿色）
--swp-primary-dark:  #1f6c29;
--swp-primary-light: #e8f5e9;
--swp-success:    #10b981;
--swp-danger:      #ef4444;
```

#### 层级 2：PDF 样式表（硬编码颜色值）

文件：[pdf.scss](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/assets/scss/pdf.scss)

```scss
/* 品牌主色：用于链接、支付按钮、总计背景 */
a { color: #2e963a; }
.total-due-box { background-color: #2e963a; }
.section-label { border-bottom: 2px solid #2e963a; }
```

#### 层级 3：各模板调色板（palette）

模板通过宏参数自定义颜色，示例：

文件：[_macros.html.twig#L82-L95](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_macros.html.twig#L82-L95)

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

各模板可传入自定义 palette 参数覆盖默认颜色：

文件：[modern/pdf.html.twig#L61](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/modern/pdf.html.twig#L61)

```twig
{{ inv.totals_block(invoice, currency, {totalBg: '#ffffff', totalColor: '#0f172a', balanceBg: '#ffffff', balanceColor: '#0f172a'}) }}
```

### 4.2 品牌主色使用场景

| 用途 | 颜色值 | 使用位置 |
|------|--------|----------|
| 品牌主色 | `#2e963a` | 链接、总计金额盒背景、标签下划线、支付按钮 |
| 文字主色 | `#1e293b` | 公司名称、发票编号、标题 |
| 文字次级 | `#475569` | 详细信息、次要文字 |
| 文字辅助 | `#64748b` | 标签、日期标签 |
| 成功/已付 | `#10b981` | 付款记录金额 |
| 警告/逾期 | `#dc2626` / `#ef4444` | 逾期金额、折扣 |
| 余额警告 | `#fef3c7` / `#92400e` | 未付余额背景/文字 |
| 总计成功 | `#f0fdf4` / `#166534` | 已付总额背景/文字 |

### 4.3 颜色缓存路径

颜色定义**无独立缓存**：
- 编译后的 CSS 文件 (`static/pdf.css`) 作为静态资源由 Webpack Encore 构建
- 在模板渲染时通过 `file()` 内联读取，直接嵌入 HTML
- 无运行时动态颜色来自数据库的品牌色配置（当前未实现动态品牌色配置）

---

## 5. 数据流与调用链

### 5.1 完整调用链图

```
InvoicePdfListener / ViewAction
        │
        ▼
Generator::generate($html)
        │
        ├─ 配置 mPDF: tempDir=%kernel.cache_dir%/pdf, default_font=helvetica
        │
        ▼
Twig 渲染模板 (例如 Templates/classic/pdf.html.twig)
        │
        ├─ 继承 _pdf_base.html.twig
        │      └─ 内联加载 static/pdf.css (字体+基础颜色)
        │
        ├─ 调用 app_logo(50)
        │      └─ GlobalExtension::displayAppLogo()
        │              └─ SystemConfig::get('system/company/logo')
        │                      └─ SettingsRepository::getSetting()
        │                              └─ DB: app_settings 表
        │
        ├─ 调用 company_name()
        │      └─ GlobalExtension (匿名函数)
        │              └─ SystemConfig::get('system/company/company_name')
        │
        ├─ 调用 setting('system/company/contact_details/*')
        │      └─ SettingsExtension::getSetting()
        │              └─ SystemConfig::get(...)
        │
        └─ 调用 inv.from_block() / inv.bill_to_block()
               └─ 内联颜色 + 字体样式 (来自 palette 默认值)
        │
        ▼
mPDF::WriteHTML($html)
        │
        ▼
返回 PDF 二进制字符串
```

### 5.2 关键文件索引

| 文件 | 作用 |
|------|------|
| [Generator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Pdf/Generator.php) | PDF 生成器，配置 mPDF 缓存目录和默认字体 |
| [GlobalExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php) | 提供 `app_logo()` 和 `company_name()` |
| [SettingsExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/SettingsBundle/Twig/Extension/SettingsExtension.php) | 提供 `setting()` 函数 |
| [SystemConfig.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/SettingsBundle/SystemConfig.php) | 系统配置读取入口 |
| [SettingsRepository.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/SettingsBundle/Repository/SettingsRepository.php) | 设置数据库查询 |
| [SystemConfigProvider.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Config/SystemConfigProvider.php) | 定义品牌设置字段 |
| [_pdf_base.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig) | PDF 基础模板 |
| [_macros.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_macros.html.twig) | 可复用的模板宏（含调色板系统） |
| [pdf.scss](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/assets/scss/pdf.scss) | PDF 全局样式（字体、颜色） |
| [services.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/CoreBundle/Resources/config/services/services.php) | 服务绑定（$cacheDir 参数绑定） |
| [PdfBaseCustomBrandingGateTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/SaasBundle/Tests/Functional/PdfBaseCustomBrandingGateTest.php) | 自定义品牌功能测试（hide_powered_by 功能门控） |

### 5.3 SaaS 自定义品牌门控

SaaS 模式下，`custom_branding` 特性控制 `hide_powered_by` 设置是否生效：

文件：[_pdf_base.html.twig#L37-L47](file:///d:/fz/0601-2/solo-dogfeeding/code/28-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig#L37-L47)

```twig
{% set hide_powered_by_value = setting('system/general/hide_powered_by') is same as ('1') %}
{% set hide_powered_by = hide_powered_by_value and feature_enabled('custom_branding') %}
```

- 自托管模式：NoopFeatureGate，所有特性默认启用
- SaaS 模式：需订阅计划包含 `custom_branding` 特性才生效
