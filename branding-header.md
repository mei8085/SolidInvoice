# 品牌标识接入发票页眉的代码实现

## 一、存储表和字段

品牌标识（Logo）存储在 `app_config` 数据库表中，对应 Doctrine 实体 `Setting`。

| 数据库列 | ORM 字段 | 类型 | 说明 |
|-----------|----------|------|------|
| `id` | `$id` | `UlidType` | 主键 |
| `setting_key` | `$key` | `string(125)` | 设置键名，Logo 对应 `system/company/logo` |
| `setting_value` | `$value` | `text, nullable` | 设置值，Logo 存为 `{ext}\|{base64}` 格式 |
| `field_type` | `$type` | `string` | 表单类型，Logo 为 `ImageUploadType::class` |
| `company_id` | — | Ulid | 多租户外键（`CompanyAware` trait） |
| `form_options` | `$formOptions` | `json, nullable` | 表单选项 |
| `default_value` | `$defaultValue` | `text, nullable` | 默认值 |

唯一约束：`UNIQUE(setting_key, company_id)`

关键文件：

- 实体定义：`src/SettingsBundle/Entity/Setting.php`（#L33 TABLE_NAME 常量，#L43-L47 key/value 字段）
- 迁移建表：`migrations/Version20000.php`（#L388 建表，#L517 logo 初始行）
- 多租户迁移：`migrations/Version20200.php`（#L142 为 app_config 添加 company_id）
- 扩展列迁移：`migrations/Version30000_3.php`（添加 form_options / default_value 列）

---

## 二、上传保存格式

### 2.1 上传入口

设置页面通过 Live Component 接收文件上传并持久化：

- 设置组件：`src/SettingsBundle/Twig/Components/Settings.php`（#L147-L170 `save()` 方法）
- 表单类型：`src/SettingsBundle/Form/Type/SettingsType.php`（#L81 根据 `Setting.type` 动态添加表单字段）
- 字段注册：`src/CoreBundle/Config/SystemConfigProvider.php`（#L32 注册 `system/company/logo` 使用 `ImageUploadType`）

### 2.2 ImageUploadType 转换逻辑

`ImageUploadType` 继承 `DropzoneType`，内嵌 `DataTransformer` 完成文件→字符串的转换：

文件：`src/CoreBundle/Form\Type/ImageUploadType.php`

**reverseTransform（上传→存储）：**

```
1. 接收 UploadedFile 对象
2. 校验 MIME 类型（仅允许 jpeg/png/gif/webp，拒绝 SVG 防 XSS）
3. 用 getimagesize() 验证是真实图片
4. 转换为格式：{guessExtension}|{base64_encode(file_get_contents(pathname))}
   例如：png|iVBORw0KGgoAAAANSUhEUg...
```

关键代码在 #L106：
```php
return $value->guessExtension() . '|' . base64_encode(file_get_contents($value->getPathname()));
```

**transform（存储→表单展示）：**

从数据库读取 `Setting.value` 字符串，返回空 `File` 对象（展示时不回填文件）。

### 2.3 保存到数据库

`Settings::save()` → `SettingsRepository::store()` 执行 UPDATE：

文件：`src/SettingsBundle/Repository/SettingsRepository.php`（#L44-L81 `store()` 方法）

store() 将嵌套数组 flatten 为 `system/company/logo` => `png|base64data` 形式，然后逐条 UPDATE `app_config` 表的 `setting_value` 列。

### 2.4 允许的图片类型

| MIME 类型 | 扩展名 |
|-----------|--------|
| `image/jpeg` | .jpg |
| `image/png` | .png |
| `image/gif` | .gif |
| `image/webp` | .webp |

SVG 被明确禁止，防止通过 `<script>` 标签注入 XSS（因为最终以 data: URI 内联渲染）。

---

## 三、读取到页眉的路径

### 3.1 完整读取链路

```
1. PDF 模板渲染
   └─ 调用 Twig 函数 setting('system/company/logo') 判断是否非空
   └─ 调用 Twig 函数 app_logo(width) 获取 <img> 标签

2. app_logo() → GlobalExtension::displayAppLogo()
   └─ 判断公司是否已选择（companySelector->getCompany() instanceof Ulid）
   └─ SystemConfig::get('system/company/logo')
       └─ SettingsRepository::getSetting('system/company/logo', company)
           └─ DB: SELECT * FROM app_config WHERE setting_key='system/company/logo' AND company_id=?
       └─ 返回 Setting.value 字符串，如 "png|iVBORw0KGgo..."

3. GlobalExtension 解析格式
   └─ explode('|', $logo) → ['png', 'iVBORw0KGgo...']
   └─ 渲染为 <img src="data:image/png;base64,iVBORw0KGgo..." width="50"/>

4. mPDF 接收完整 HTML（含内联 base64 图片）
   └─ WriteHTML() 解析并嵌入 PDF
```

### 3.2 每步对应文件

| 步骤 | 文件 | 行号 | 说明 |
|------|------|------|------|
| 模板判断+调用 | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | #L55-L59 | `{% set logo = setting('system/company/logo') %}` → `{{ app_logo(50) }}` |
| 模板判断+调用 | `src/InvoiceBundle/Resources/views/Templates/classic/pdf.html.twig` | #L14-L16 | `{% if setting(...) %}` → `{{ app_logo(50) }}` |
| 模板判断+调用 | `src/InvoiceBundle/Resources/views/Templates/friendly/pdf.html.twig` | #L23 | `{{ app_logo(40) }}` |
| 模板判断+调用 | `src/InvoiceBundle/Resources/views/Templates/studio/pdf.html.twig` | #L15 | `{{ app_logo(40) }}` |
| Twig 函数注册 | `src/CoreBundle/Twig/Extension/GlobalExtension.php` | #L120 | `new TwigFunction('app_logo', ...)` |
| Logo 读取逻辑 | `src/CoreBundle/Twig/Extension/GlobalExtension.php` | #L149-L168 | `displayAppLogo()` 解析 `type\|base64` → Data URI |
| setting() 函数 | `src/SettingsBundle/Twig/Extension/SettingsExtension.php` | #L43 | `new TwigFunction('setting', ...)` |
| 配置读取 | `src/SettingsBundle/SystemConfig.php` | #L40-L47 | `get('system/company/logo')` → repository |
| DB 查询 | `src/SettingsBundle/Repository/SettingsRepository.php` | #L108-L124 | `findOneBy(['key'=>$key, 'company'=>$company])` |
| 实体映射 | `src/SettingsBundle/Entity/Setting.php` | #L33,43-47 | TABLE_NAME='app_config', key/value 字段 |

### 3.3 默认 Logo

当未设置自定义 Logo 时，`GlobalExtension` 使用内置常量 `DEFAULT_LOGO`：

文件：`src/CoreBundle/Twig/Extension/GlobalExtension.php`（#L44）

常量值为 `png|<base64编码的默认Logo图片>`，与上传格式完全一致。

### 3.4 页眉渲染效果

Logo 以 Data URI 形式嵌入 `<img>` 标签，直接作为 mPDF 的 HTML 输入：

```html
<img src="data:image/png;base64,iVBORw0KGgo..." class="navbar-brand-image m-2" width="50"/>
```

mPDF 原生支持 Data URI 格式的图片，无需文件系统缓存或外部 URL 访问。

### 3.5 缓存说明

- **Logo 数据**：无应用层缓存，每次通过 `SettingsRepository::findOneBy()` 直查数据库
- **mPDF 临时目录**：`{kernel.cache_dir}/pdf`（`src/CoreBundle/Pdf/Generator.php` #L38），用于 mPDF 内部字体子集化等临时文件，不缓存 Logo 数据
- **SystemConfig 静态缓存**：`SystemConfig::$settings` 静态数组仅在 `getAll()` 时通过 `load()` 填充，`get()` 方法不使用此缓存（`src/SettingsBundle/SystemConfig.php` #L40-L47）

---

## 四、所有引用 Logo 的 PDF 模板

| 模板 | 文件 | 宽度 |
|------|------|------|
| 默认发票 PDF | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | 50 |
| Classic | `src/InvoiceBundle/Resources/views/Templates/classic/pdf.html.twig` | 50 |
| Friendly | `src/InvoiceBundle/Resources/views/Templates/friendly/pdf.html.twig` | 40 |
| Studio | `src/InvoiceBundle/Resources/views/Templates/studio/pdf.html.twig` | 40 |
| Modern | `src/InvoiceBundle/Resources/views/Templates/modern/pdf.html.twig` | 无（不显示 Logo） |
| Monochrome | `src/InvoiceBundle/Resources/views/Templates/monochrome/pdf.html.twig` | 无（不显示 Logo） |
| Photographer | `src/InvoiceBundle/Resources/views/Templates/photographer/pdf.html.twig` | 无（不显示 Logo） |
| Compact | `src/InvoiceBundle/Resources/views/Templates/compact/pdf.html.twig` | 无（不显示 Logo） |
| Editorial | `src/InvoiceBundle/Resources/views/Templates/editorial/pdf.html.twig` | 无（不显示 Logo） |

共享宏中的 `from_block()` 也调用 `company_name()` 但不渲染 Logo：

文件：`src/InvoiceBundle/Resources/views/Templates/_macros.html.twig`（#L38-L67 `from_block` 宏）

---

## 五、相关测试

| 测试 | 文件 |
|------|------|
| ImageUploadType 单元测试 | `src/CoreBundle/Tests/Form/Type/ImageUploadTypeTest.php` |
| Settings Live Component 测试 | `src/SettingsBundle/Tests/Twig/Components/SettingsTest.php` |
| PDF 品牌门控测试 | `src/SaasBundle/Tests/Functional/PdfBaseCustomBrandingGateTest.php` |
| SystemConfig 单元测试 | `src/SettingsBundle/Tests/SystemConfigTest.php` |
| Generator 单元测试 | `src/CoreBundle/Tests/Pdf/GeneratorTest.php` |
