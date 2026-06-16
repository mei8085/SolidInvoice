# SolidInvoice 公司品牌与页眉资源完整脉络

## 一、品牌设置的存取位置

### 1.1 数据库存储

**表名：** `app_config`（由 [Setting](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Entity/Setting.php) 实体定义）

**表结构关键字段：**
| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | ULID | 主键 |
| `setting_key` | VARCHAR(125) | 设置键，如 `system/company/logo` |
| `setting_value` | TEXT | 设置值（存储base64编码的图片） |
| `field_type` | VARCHAR | 表单类型类名 |
| `company_id` | ULID | 多租户公司ID（唯一联合索引） |

**品牌相关设置键：**
- `system/company/logo` - 公司徽标
- `system/company/company_name` - 公司名称
- `system/company/currency` - 公司货币
- `system/company/vat_number` - VAT税号
- `system/company/contact_details/email` - 联系邮箱
- `system/company/contact_details/phone_number` - 联系电话
- `system/company/contact_details/address` - 公司地址（JSON格式）

### 1.2 配置定义层

**SystemConfigProvider** - [SystemConfigProvider.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Config/SystemConfigProvider.php#L23-L36)

```php
new Config('system/company/logo', null, null, ImageUploadType::class),
new Config('system/company/company_name', $data['company_name'] ?? null, null, TextType::class),
// ... 其他配置项
```

### 1.3 数据访问层

**读取流程：**
1. **SystemConfig** - [SystemConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/SystemConfig.php)
   - `get($key)` 方法调用 `SettingsRepository::getSetting()`
   - 内部有静态缓存 `self::$settings` 避免重复查询

2. **SettingsRepository** - [SettingsRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Repository/SettingsRepository.php)
   - `getSetting($key, $company)` 按公司隔离查询
   - 自动应用 `CompanyFilter` 多租户过滤器

**写入流程：**
1. **Settings 组件** - [Settings.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Twig/Components/Settings.php#L131-L154)
   - Live Component 处理表单提交
   - 特别处理文件上传：`$request->files->all()` 提取logo文件
   - 调用 `SettingsRepository::save()` 保存

2. **SettingsRepository::save()** - [SettingsRepository.php#L44-L81](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Repository/SettingsRepository.php#L44-L81)
   - `flatten()` 方法将多维数组扁平化为 `/` 分隔的键
   - 事务内批量UPDATE
   - 特殊处理 `system/company/company_name` 同步更新 `Company` 实体

---

## 二、徽标上传的完整代码路径

### 2.1 后端表单类型

**ImageUploadType** - [ImageUploadType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Form/Type/ImageUploadType.php)

**核心特性：**
- 继承 `DropzoneType`（Symfony UX Dropzone）提供拖拽上传UI
- 内置 `DataTransformer` 处理文件与存储格式转换

**安全限制（防止XSS）：**
```php
public const ALLOWED_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
];
// SVG 被故意排除，因为内联SVG可执行JavaScript
```

### 2.2 后端数据转换流程

**DataTransformer::reverseTransform()** - [ImageUploadType.php#L67-L102](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Form/Type/ImageUploadType.php#L67-L102)

```
上传文件 → 验证MIME类型 → getimagesize()检测有效性 → base64编码 → 存储格式
```

**存储格式：** `{extension}|{base64_content}`
- 示例：`png|iVBORw0KGgoAAAANSUhEUgAAADIAAAAoCAYAAAC8cqlM...`

**DataTransformer::transform()** - 反向转换
- 从 `Setting` 实体读取值，创建虚拟 `File` 对象供表单显示

### 2.3 客户端 Stimulus 控制器与 JS 模块

#### 2.3.1 Stimulus 控制器注册

**`logo_upload` 控制器** - 在 [fields.html.twig#L284](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig#L284) 中通过 `stimulus_controller('logo_upload')` 绑定：

```twig
<{{ element|default('div') }} {{ stimulus_controller('logo_upload') }}>
    {{ app_logo(width=100, showDefault=false) }}
    {{- form_widget(form, widget_attr) -}}
    {{- form_help(form) -}}
</{{ element|default('div') }}>
```

**⚠️ 关键发现：`logo_upload` 控制器当前无实现文件。** 在 `assets/controllers/` 目录和 `controllers.json` 中均未找到对应的 Stimulus 控制器注册。该 `stimulus_controller()` 调用仅会在 DOM 元素上生成 `data-controller="logo-upload"` 属性，但不会触发任何 JavaScript 逻辑。

#### 2.3.2 UX Dropzone 控制器

实际的上传交互由 **`@symfony/ux-dropzone`** 提供，注册于 [controllers.json#L34-L41](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/assets/controllers.json#L34-L41)：

```json
"@symfony/ux-dropzone": {
    "dropzone": {
        "enabled": true,
        "fetch": "eager",
        "autoimport": {
            "@symfony/ux-dropzone/dist/style.min.css": true
        }
    }
}
```

UX Dropzone 的客户端行为：
- 将标准 `<input type="file">` 替换为拖拽区域 UI
- 支持 **拖拽** 和 **点击选择** 两种上传方式
- 上传后通过 Symfony Live Component（`data-action="live#action:prevent"` + `data-live-action-param="prevent|files|save"`）自动提交表单

#### 2.3.3 客户端图片处理分析

**当前状态：无客户端图片处理**

| 处理环节 | 是否实现 | 说明 |
|----------|----------|------|
| 裁剪 (Crop) | ❌ | 无 Cropper.js 或类似库集成 |
| 缩放 (Resize) | ❌ | 无 Canvas API 缩放逻辑 |
| 压缩 (Compress) | ❌ | 无质量调节或格式转换 |
| 尺寸校验 (Size Validation) | ❌ | 仅通过 help 文本提示推荐尺寸 |
| MIME 校验 | ✅ | 后端 `ALLOWED_MIME_TYPES` + `getimagesize()` 双重验证 |
| 伪造文件检测 | ✅ | `getimagesize()` 验证文件实际内容与扩展名一致 |

**推荐尺寸提示** - 在 ImageUploadType 的 help 文本中：
> "Recommended: Square image, at least 200x200px"

此提示仅为文案，无 JavaScript 端强制校验。用户可上传任意尺寸/比例的图片，最终由 `app_logo()` 渲染时通过 HTML `width` 属性 CSS 缩放显示。

### 2.4 上传提交流程（Live Component）

**Settings 组件** - [Settings.html.twig#L41-L46](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Resources/views/Components/Settings.html.twig#L41-L46)

```twig
{{ form_start(form, {
    attr: {
        'data-action': 'live#action:prevent',
        'data-live-action-param': 'prevent|files|save'
    }
}) }}
```

- 表单提交通过 Symfony UX Live Component 的 `save` action 处理
- `prevent|files|save` 参数指示 Live Component 同时处理文件上传
- 后端 [Settings.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Twig/Components/Settings.php#L131-L154) 从 `$request->files->all()` 提取文件

### 2.5 引导流程中的品牌检查

**Dashboard Checklist** - [UploadLogoItem.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/DashboardBundle/Checklist/Items/UploadLogoItem.php)
- 在用户仪表盘显示"上传徽标"待办项
- `isComplete()` 检查 `system/company/logo` 是否有值

**SaaS Onboarding** - [CustomizeLogoStep.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SaasBundle/Onboarding/Step/CustomizeLogoStep.php)
- 引导步骤：如果 `system/company/logo` 为空则发送提醒邮件
- 优先级 60（在设置公司名称之后）

---

## 三、模板渲染时页眉资源的解析

### 3.1 核心Twig函数

**app_logo()** 函数定义于 [GlobalExtension.php#L111](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php#L111)

**函数签名：**
```php
displayAppLogo(
    Environment $env, 
    string $width = 'auto', 
    ?Company $company = null, 
    bool $showDefault = false, 
    bool $showOnlyAppIcon = false
): string
```

### 3.2 解析流程

```
调用 {{ app_logo(50) }}
    ↓
GlobalExtension::displayAppLogo()
    ↓
1. 检查应用是否已安装 ($this->installed)
2. 检查公司选择器是否有当前公司
3. 调用 SystemConfig::get('system/company/logo', $company)
4. 若无值且 $showDefault=true，使用内置 DEFAULT_LOGO
5. 解析存储格式：[$type, $base64] = explode('|', $logo)
6. 渲染内联模板：
   <img src="data:image/{{ type }};base64,{{ logo }}" 
        class="navbar-brand-image m-2" 
        width="{{ width }}"/>
    ↓
返回HTML字符串
```

**代码位置：** [GlobalExtension.php#L140-L159](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php#L140-L159)

### 3.3 使用场景

#### 3.3.1 Web页面页眉（导航栏）
**模板：** [top.html.twig#L17](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Resources/views/Menu/top.html.twig#L17)
```twig
<button class="btn btn-outline-secondary dropdown-toggle">
    {{ app_logo(25) }}
    {{ company_name() }}
</button>
```
- 尺寸：25px 高度
- 位置：顶部导航栏公司切换按钮

#### 3.3.2 设置表单预览
**模板：** [fields.html.twig#L128](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Resources/views/Form/fields.html.twig#L128)
```twig
{% set currentLogo = app_logo(width=80, showDefault=false) %}
```
- 尺寸：80px 高度
- 用于上传前的现有徽标预览

#### 3.3.3 公司名称辅助函数
**company_name()** - [GlobalExtension.php#L113-L119](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php#L113-L119)
- 读取 `system/company/company_name` 设置
- 若无则返回应用默认名称 `SolidInvoiceCoreBundle::APP_NAME`

---

## 四、PDF输出复用同一品牌资产

### 4.1 PDF模板体系

**基础模板：** [_pdf_base.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig)
- 定义mPDF的pagefooter、watermark等通用配置
- 定义 `body` block 供子模板覆盖

**发票PDF模板：**
1. **默认模板** - [invoice.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig#L55-L60)
2. **Classic模板** - [classic/pdf.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/classic/pdf.html.twig#L14-L16)
3. **Friendly模板** - [friendly/pdf.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/friendly/pdf.html.twig#L23)
4. **Studio模板** - [studio/pdf.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/studio/pdf.html.twig#L15)

**报价单PDF模板：**
- [quote.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/QuoteBundle/Resources/views/Pdf/quote.html.twig#L54-L60)

### 4.2 mPDF 三大区块深度解析：水印 / 页脚 / 页眉

SolidInvoice 的 PDF 使用 **mPDF** 引擎渲染，涉及三个与品牌资产相关的区块：

| 区块 | mPDF 标签 | 定义位置 | 品牌资产 | 可否被子模板覆盖 |
|------|-----------|----------|----------|-----------------|
| **水印** | `<watermarktext>` | 基础模板 `_pdf_base.html.twig` | 发票状态文本（非品牌） | ❌ 不能（子模板不覆盖） |
| **页脚** | `<pagefooter>` | 基础模板 `_pdf_base.html.twig` | 应用名 `SolidInvoice` | ❌ 不能（子模板不覆盖） |
| **页眉** | 普通 HTML 表格（在 `body` block 内） | 各子模板各自实现 | 公司徽标 + 公司名 | ✅ 可以（每个子模板自己定义） |

> **⚠️ 重要理解：** mPDF 中没有 `<pageheader>` 标签用于页眉！"页眉"是在 `<body>` 内部用普通 HTML 表格实现的，每个子模板在 `{% block body %}` 开头自己写。而页脚和水印是 mPDF 的特殊标签，在页面布局层面全局生效。

#### 4.2.1 水印 (Watermark)

**定义位置：** [_pdf_base.html.twig#L33-L35](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig#L33-L35)

```twig
{% if setting('invoice/watermark') %}
    <watermarktext content="{{ invoice.status.value|upper }}" alpha="0.08"/>
{% endif %}
```

**技术细节：**
- **mPDF 标签**：`<watermarktext>` —— mPDF 专有元素，在每页背景渲染文字水印
- **控制开关**：`setting('invoice/watermark')`（配置键 `invoice/watermark`，布尔值）
- **内容来源**：`invoice.status.value|upper` —— 发票状态枚举值的大写（如 `DRAFT`、`PAID`、`PENDING`、`OVERDUE`）
- **透明度**：`alpha="0.08"` —— 8% 透明度，极浅不影响阅读
- **品牌属性**：水印内容是**状态文本**，不包含任何公司品牌信息

**两套模板体系的差异：**

| 模板体系 | 水印控制键 | 内容来源 |
|----------|-----------|----------|
| 基础模板系（classic/friendly 等变体） | `invoice/watermark` | `invoice.status.value` |
| 默认发票模板（独立） | `invoice/watermark` | `invoice.status.value` 相同 |
| 报价单模板（独立） | `quote/watermark` | `quote.status.value` |

报价单水印状态示例：`DRAFT`、`PENDING`、`ACCEPTED`、`DECLINED`、`EXPIRED`

**覆盖关系：** 子模板（classic/friendly/studio 等）**都不覆盖**水印块，全部继承基础模板的实现。

#### 4.2.2 页脚 (Page Footer)

**定义位置：** [_pdf_base.html.twig#L39-L47](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig#L39-L47)

```twig
{% set hide_powered_by_value = setting('system/general/hide_powered_by') is same as ('1') %}
{% set hide_powered_by = hide_powered_by_value and feature_enabled('custom_branding') %}
<pagefooter
    name="footer"
    content-left="{{ not hide_powered_by ? 'powered_by'|trans ~ ' ' ~ constant('SolidInvoice\\CoreBundle\\SolidInvoiceCoreBundle::APP_NAME') }}"
    content-right="{{ 'pdf.page'|trans }} {PAGENO} {{ 'pdf.of'|trans }} {nb}"
    line="on"
    footer-style="border-top: 1px solid #e2e8f0; font-size: 8pt; color: #64748b; padding-top: 8px;"
/>
```

**技术细节：**
- **mPDF 标签**：`<pagefooter name="footer">` —— mPDF 专有元素
- **CSS 关联**：`@page { footer: footer; }` —— 通过 `name` 属性与 CSS @page 规则绑定
- **分隔线**：`line="on"` 显示页脚顶部 1px 分割线
- **页码占位符**：`{PAGENO}`（当前页）、`{nb}`（总页数）由 mPDF 渲染时替换

**左侧品牌信息（应用名）：**
- **来源**：`SolidInvoiceCoreBundle::APP_NAME` 类常量，值为 `"SolidInvoice"`
- **定义**：[SolidInvoiceCoreBundle.php#L26](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/SolidInvoiceCoreBundle.php#L26)
- **文案**：`'powered_by'|trans ~ ' ' ~ 'SolidInvoice'` → `"Powered by SolidInvoice"`

**显示控制（两级开关）：**
1. 第一级：`setting('system/general/hide_powered_by')` —— 用户设置是否隐藏
2. 第二级：`feature_enabled('custom_branding')` —— SaaS `custom_branding` 功能是否启用
3. **隐藏条件**：两者同时为真才隐藏（SaaS 付费功能）

**⚠️ 逻辑不一致发现：**

| 模板 | hide_powered_by 判断逻辑 |
|------|--------------------------|
| 基础模板系（`_pdf_base`） | `hide_powered_by_value and feature_enabled('custom_branding')` —— 两级开关 |
| 默认发票模板（`Pdf/invoice.html.twig`） | `setting('system/general/hide_powered_by') is not same as ('1')` —— 仅一级判断 |
| 报价单模板（`Pdf/quote.html.twig`） | 同上，仅一级判断 |

默认发票/报价单模板**没有**检查 `custom_branding` 功能开关，可能导致免费用户也能隐藏 "Powered by" 文字。

**覆盖关系：** 子模板（classic/friendly/studio 等）**都不覆盖**页脚，全部继承基础模板。

#### 4.2.3 页眉 (Header)

**重要理解：** 此处"页眉"**不是** mPDF 的 `<pageheader>` 标签，而是各 PDF 模板在 `<body>` 开头用普通 HTML 表格实现的品牌抬头区域。每页第一页显示，后续页不重复（这是内容的一部分，不是 mPDF 层面的页面页眉）。

**品牌资产引用（三套子模板对比）：**

| 模板 | Logo 尺寸 | Logo 条件判断 | 公司名来源 | 其他品牌信息 | 代码位置 |
|------|-----------|--------------|-----------|-------------|----------|
| **Classic** | 50px | `if setting('system/company/logo') is not empty` | `company_name()` | 无（公司详情在下方 from_block 中） | [classic/pdf.html.twig#L14-L17](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/classic/pdf.html.twig#L14-L17) |
| **Friendly** | 40px | 内联 if（不带 is not empty 判断） | `company_name()` | 无 | [friendly/pdf.html.twig#L23-L24](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/friendly/pdf.html.twig#L23-L24) |
| **Studio** | 40px | `if setting('system/company/logo') is not empty` | 仅 Invoice 编号（公司名在 from_block 中） | 项目名称大标题 | [studio/pdf.html.twig#L15](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/studio/pdf.html.twig#L15) |

**默认发票模板（独立体系）：**
- Logo 尺寸：50px
- 公司名：`company_name()` 18pt 粗体
- 额外信息：VAT 号、邮箱、电话、地址（全部在页眉内联展示，不用 from_block 宏）
- 代码：[invoice.html.twig#L54-L88](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig#L54-L88)

**三套子模板页眉布局差异：**

**Classic：** 左右两列表格布局，左侧 Logo + 公司名，右侧发票编号（带边框 masthead）

**Friendly：** 左右两列表格布局，左侧 Logo + 公司名（margin-top: 8px），右侧发票编号（温暖桃色风格）

**Studio：** 左右两列表格布局，左侧 Logo，右侧发票编号，下方另有独立的项目名称大色块（项目导向设计）

**覆盖关系：**
- 基础模板 `_pdf_base.html.twig` **不定义页眉**（仅定义水印和页脚）
- 每个子模板在 `{% block body %}` 内自行实现页眉
- 三套模板之间**互不继承**，各自独立实现页眉样式

#### 4.2.4 品牌信息的两大来源对比

| 品牌元素 | 来源函数/常量 | 底层数据 | 出现位置 |
|----------|--------------|----------|----------|
| **公司徽标** | `app_logo(N)` | `system/company/logo` 设置 | 页眉（4种模板有）、Web页面、设置预览 |
| **公司名称** | `company_name()` | `system/company/company_name` 设置 | 页眉、from_block 宏、Web页面、Email |
| **应用名** | `APP_NAME` 常量 | 硬编码 `'SolidInvoice'` | 页脚 "Powered by SolidInvoice" |
| **公司联系信息** | `setting()` + `address()` | `vat_number` / `email` / `phone_number` / `address` | from_block 宏、默认模板页眉 |

**公司名的降级逻辑：** `company_name()` 函数优先从 `system/company/company_name` 读取，若为空则回退到 `APP_NAME` 常量（即 "SolidInvoice"）。

### 4.3 三套子模板与基础模板的覆盖关系总览

```
_pdf_base.html.twig (基础层)
  ├── @page { footer: footer; margin-* }   ← CSS 页面布局
  ├── <watermarktext>                      ← 水印（继承，全部子模板都不改）
  ├── <pagefooter name="footer">           ← 页脚（继承，全部子模板都不改）
  ├── {% block extra_styles %}             ← 额外样式块
  │      ├── Friendly: 背景色 #fffaf3
  │      ├── Classic: 不覆盖（默认）
  │      └── Studio: 不覆盖（默认）
  ├── {% block body_attrs %}               ← body 属性块
  │      └── Friendly: style="background-color: #fffaf3;"
  └── {% block body %}                      ← 正文块（每个子模板全覆盖）
         ├── 页眉（各自实现）
         ├── 发件/收件信息（from_block 宏）
         ├── 明细表格
         ├── 总计
         ├── 条款
         └── 支付按钮
```

**继承规则：**
- Classic：仅覆盖 `body` block
- Friendly：覆盖 `body` + `extra_styles` + `body_attrs`（自定义背景色）
- Studio：仅覆盖 `body` block
- 三套模板**都不覆盖**水印和页脚

### 4.4 两套 PDF 模板体系的区别

SolidInvoice 中实际上存在**两套独立的 PDF 模板体系**：

| 维度 | 体系一：默认模板 | 体系二：模板变体 |
|------|----------------|----------------|
| 入口模板 | `InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | `InvoiceBundle/Resources/views/Templates/{variant}/pdf.html.twig` |
| 继承关系 | 独立模板，不继承任何基础模板 | 都继承 `_pdf_base.html.twig` |
| 水印 | 独立实现（逻辑相同） | 继承基础模板 |
| 页脚 | 独立实现（但逻辑不同⚠️） | 继承基础模板 |
| 页眉 | 内联实现，包含完整公司联系信息 | 各自实现，公司详情用 from_block 宏 |
| 数量 | 1 个（发票）+ 1 个（报价单） | 9 种变体 |
| hide_powered_by 检查 | 仅检查设置值 | 检查设置 + custom_branding 功能 |

**品牌资产复用方式总结：**

**所有 PDF 模板统一调用 `app_logo()` 函数：**

```twig
{% if setting('system/company/logo') is not empty %}
    {{ app_logo(50) }}
{% endif %}
```

**关键特性：**
- ✅ **完全复用Web端同一函数**：`app_logo()` 同一套代码
- ✅ **同一数据源**：读取 `system/company/logo` 同一配置
- ✅ **无特殊处理**：data URI 格式直接被 mPDF 识别渲染
- ✅ **多模板一致**：Classic/Friendly/Studio/默认 模板都使用相同方式

### 4.5 PDF生成完整流程

```
HTTP请求 ?format=pdf
    ↓
View Action - [View.php#L48-L50](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Action/View.php#L48-L50)
    ↓
1. $this->twig->render('@SolidInvoiceInvoice/Pdf/invoice.html.twig', ['invoice' => $invoice])
   - 模板内调用 {{ app_logo(50) }}
   - 生成包含 data URI <img> 的完整HTML
    ↓
2. $this->pdfGenerator->generate($html) - [Generator.php#L35-L55](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Pdf/Generator.php#L35-L55)
   - 创建 mPDF 实例
   - $mpdf->WriteHTML($html) 解析HTML
   - mPDF 自动识别 data URI 格式的图片并渲染
    ↓
3. 返回 PdfResponse - [PdfResponse.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Response/PdfResponse.php)
   - Content-Type: application/pdf
   - Content-Disposition: inline; filename="invoice_123.pdf"
```

**外部访问PDF（无需登录）：** [ViewBilling.php#L133-L137](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Action/ViewBilling.php#L133-L137)
- 切换到对应公司上下文：`$companySelector->switchCompany()`
- 保证 `app_logo()` 读取正确公司的品牌设置

---

## 五、完整架构图

```
┌─────────────────────────────────────────────────────────────────────┐
│                         用户上传徽标                                  │
└─────────────────────────────────────┬───────────────────────────────┘
                                      │
                                      ▼
                    ┌──────────────────────────────────┐
                    │  ImageUploadType                 │
                    │  - DropzoneType (拖拽上传)        │
                    │  - DataTransformer 编码为base64   │
                    └─────────────────┬────────────────┘
                                      │
                                      ▼
                    ┌──────────────────────────────────┐
                    │  Settings Component (Live)       │
                    │  - 处理文件上传                   │
                    │  - 调用 SettingsRepository::save │
                    └─────────────────┬────────────────┘
                                      │
                                      ▼
                    ┌──────────────────────────────────┐
                    │  app_config 表                   │
                    │  setting_key: system/company/logo│
                    │  setting_value: png|iVBORw0KG... │
                    │  company_id: ULID (多租户)       │
                    └─────────────────┬────────────────┘
                                      │
          ┌───────────────────────────┼───────────────────────────┐
          ▼                           ▼                           ▼
┌─────────────────────┐   ┌─────────────────────┐   ┌─────────────────────┐
│  Web页眉渲染         │   │  PDF渲染            │   │  表单预览           │
│  top.html.twig      │   │  invoice.html.twig  │   │  fields.html.twig   │
│  {{ app_logo(25) }} │   │  {{ app_logo(50) }} │   │  {{ app_logo(80) }} │
└───────────┬─────────┘   └───────────┬─────────┘   └───────────┬─────────┘
            │                         │                         │
            └─────────────────────────┼─────────────────────────┘
                                      │
                                      ▼
                    ┌──────────────────────────────────┐
                    │  GlobalExtension::displayAppLogo │
                    │  1. SystemConfig::get() 读取    │
                    │  2. 解析 {ext}|{base64} 格式    │
                    │  3. 渲染为 data URI <img>       │
                    └──────────────────────────────────┘
```

---

## 六、关键文件索引

| 模块 | 文件路径 | 主要职责 |
|------|----------|----------|
| 实体 | [Setting.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Entity/Setting.php) | 配置存储实体 |
| 存储 | [SettingsRepository.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Repository/SettingsRepository.php) | 配置读写 |
| 配置 | [SystemConfigProvider.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Config/SystemConfigProvider.php) | 品牌配置定义 |
| 读取 | [SystemConfig.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/SystemConfig.php) | 系统配置门面 |
| 表单 | [ImageUploadType.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Form/Type/ImageUploadType.php) | 图片上传表单类型 |
| 组件 | [Settings.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Twig/Components/Settings.php) | 设置表单Live组件 |
| 渲染 | [GlobalExtension.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php) | app_logo Twig函数 |
| PDF | [Generator.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Pdf/Generator.php) | PDF生成器（mPDF） |
| Action | [View.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Action/View.php) | 发票查看/导出 |
| Action | [ViewBilling.php](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Action/ViewBilling.php) | 外部账单查看 |
| 页眉 | [top.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Resources/views/Menu/top.html.twig) | Web导航栏模板 |
| PDF模板 | [invoice.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig) | 默认发票PDF模板 |
| 表单模板 | [fields.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/SettingsBundle/Resources/views/Form/fields.html.twig) | 图片上传表单模板 |

---

## 七、设计特点与注意事项

### 7.1 设计优点
1. **单一数据源**：Web/PDF/Email 共用同一品牌配置，一致性有保障
2. **多租户隔离**：通过 `CompanyAware` trait 和 `CompanyFilter` 自动隔离
3. **安全编码**：SVG被禁止，防止存储型XSS攻击；`getimagesize()` 防伪造文件
4. **无外部依赖**：base64内联图片不依赖文件系统或CDN
5. **统一接口**：`app_logo()` 函数提供一致的调用方式
6. **模板继承**：`_pdf_base.html.twig` 统一水印和页脚，变体只需关注内容
7. **共享宏**：`_macros.html.twig` 的 `from_block()` 确保所有模板的发件方信息一致

### 7.2 已知问题
1. **`logo_upload` Stimulus 控制器无实现**：[fields.html.twig#L284](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/CoreBundle/Resources/views/Form/fields.html.twig#L284) 引用了 `stimulus_controller('logo_upload')` 但 `assets/controllers/` 目录下无对应控制器文件。这可能是预留的扩展点，或者是从旧代码迁移后的残留。
2. **缺少客户端图片处理**：无裁剪、缩放、压缩、尺寸校验。用户上传大图后原始数据直接存储，渲染时仅 CSS 缩放。
3. **缺少多尺寸生成**：Web端25px和PDF端50px使用同一张原图，浪费带宽和PDF体积。
4. **Base64膨胀**：base64编码增加约33%体积，PDF中大量使用时会增大文件。
5. **缺少缓存**：每次渲染都重新读取和解析，可考虑在SystemConfig层缓存解析结果。
6. **Logo 不一致**：9种PDF模板中仅4种显示Logo，其余5种（modern/compact/editorial/monochrome/photographer）完全不显示Logo，用户上传了Logo但部分模板看不到。
7. **报价单PDF不继承基础层**：[quote.html.twig](file:///d:/fz/0601-1/solo-dogfeeding/code/97-SolidInvoice/src/QuoteBundle/Resources/views/Pdf/quote.html.twig) 独立实现水印和页脚，与 `_pdf_base.html.twig` 逻辑重复。
