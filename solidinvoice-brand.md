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

### 4.2 品牌资产复用方式

**所有PDF模板统一调用 `app_logo()` 函数：**

```twig
{# 先检查logo是否存在 #}
{% set logo = setting('system/company/logo') %}
{% if logo is not empty %}
    <div class="company-logo" style="margin-bottom: 10px;">
        {{ app_logo(50) }}
    </div>
{% endif %}
```

**关键特性：**
- ✅ **完全复用Web端同一函数**：`app_logo()` 同一套代码
- ✅ **同一数据源**：读取 `system/company/logo` 同一配置
- ✅ **无特殊处理**：data URI 格式直接被 mPDF 识别渲染
- ✅ **多模板一致**：Classic/Friendly/Studio/默认 模板都使用相同方式

### 4.3 PDF生成完整流程

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
1. **单一数据源**：Web和PDF共用同一品牌配置，一致性有保障
2. **多租户隔离**：通过 `CompanyAware` trait 和 `CompanyFilter` 自动隔离
3. **安全编码**：SVG被禁止，防止存储型XSS攻击
4. **无外部依赖**：base64内联图片不依赖文件系统或CDN
5. **统一接口**：`app_logo()` 函数提供一致的调用方式

### 7.2 潜在改进点
1. **缺少图片裁剪**：当前仅依赖用户上传合适尺寸，建议添加服务器端裁剪
2. **缺少多尺寸生成**：Web端25px和PDF端50px使用同一张原图，浪费带宽
3. **Base64膨胀**：base64编码增加约33%体积，PDF中大量使用时会增大文件
4. **缺少缓存**：每次渲染都重新读取和解析，可考虑在SystemConfig层缓存解析结果
