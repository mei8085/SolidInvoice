# PDF 模板生成完整代码链路

本文档梳理 SolidInvoice 中 PDF 模板从选择到最终打印输出的完整代码流程。所有结论均附有代码位置证据，可在仓库中直接验证。

**相对路径说明**：所有路径均相对于项目根目录。

---

## 一、模板选择逻辑：硬编码，无动态选择

### 1.1 关键结论

**所有生产代码（Action、Listener）均硬编码引用默认 PDF 模板，不存在运行时动态选择模板的机制。** 8 个可选预设模板（classic、modern、compact 等）目前仅在测试中使用，不进入实际运行路径。

### 1.2 默认模板的引用点（全量搜索结果）

验证命令：
```bash
grep -rn "@SolidInvoiceInvoice/Pdf\|@SolidInvoiceQuote/Pdf" src/ --include="*.php"
```

所有 6 个生产代码入口均直接硬编码模板路径：

| 入口 | 模板路径 | 代码位置 | 行号 |
|------|----------|----------|------|
| 登录用户-发票 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `src/InvoiceBundle/Action/View.php` | 52 |
| 登录用户-报价 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | `src/QuoteBundle/Action/View.php` | 46 |
| 外部链接-发票 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `src/CoreBundle/Action/ViewBilling.php` | 90 |
| 外部链接-报价 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | `src/CoreBundle/Action/ViewBilling.php` | 69 |
| 邮件附件-发票 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php` | 47 |
| 邮件附件-报价 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | `src/QuoteBundle/Listener/Mailer/QuotePdfListener.php` | 47 |

**验证结果**：没有通过设置系统或数据库读取模板名的逻辑。

### 1.3 可选预设模板的状态

验证命令：
```bash
grep -rn "@SolidInvoiceInvoice/Templates" src/ --include="*.php"
```

**结果**：仅在测试文件中引用：

| 测试文件 | 引用的模板 | 行号 |
|----------|-----------|------|
| `src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php` | `@SolidInvoiceInvoice/Templates/{slug}/{channel}.html.twig` | 74 |
| `src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php` | `@SolidInvoiceInvoice/Templates/{slug}/pdf.html.twig` | 120 |
| `src/InvoiceBundle/Tests/Functional/Email/TemplateEmailSnapshotTest.php` | `@SolidInvoiceInvoice/Templates/{slug}/email.html.twig` | 83 |
| `src/SaasBundle/Tests/Functional/PdfBaseCustomBrandingGateTest.php` | `@SolidInvoiceInvoice/Templates/classic/pdf.html.twig` | 179 |

**目录结构**：`src/InvoiceBundle/Resources/views/Templates/`

```
Templates/
├── _pdf_base.html.twig          ← 基础模板骨架
├── _macros.html.twig            ← 共用 Twig 宏
├── classic/{pdf,email,preview}.html.twig
├── modern/{pdf,email,preview}.html.twig
├── compact/{pdf,email,preview}.html.twig
├── editorial/{pdf,email,preview}.html.twig
├── monochrome/{pdf,email,preview}.html.twig
├── photographer/{pdf,email,preview}.html.twig
├── studio/{pdf,email,preview}.html.twig
└── friendly/{pdf,email,preview}.html.twig
```

**结论**：可选预设模板是为未来模板选择功能预制的，当前版本尚未接入运行路径。

---

## 二、两个入口的完整调用链对比

### 2.1 浏览器打印入口

**路由定义**：

验证命令：
```bash
grep -n "_format.*pdf\|_invoices_view\|_view_invoice_external" \
  src/InvoiceBundle/Resources/config/routing.php \
  src/CoreBundle/Resources/config/routing.php
```

```
# 登录用户（InvoiceBundle 路由）
_invoices_view: /view/{id}.{_format}    _format: html|pdf
  → src/InvoiceBundle/Resources/config/routing.php 第63、66行

# 外部链接（CoreBundle 路由）
_view_invoice_external: /view/invoice/{uuid}.{_format}    _format: html|pdf
_view_quote_external: /view/quote/{uuid}.{_format}        _format: html|pdf
  → src/CoreBundle/Resources/config/routing.php 第38、44行
```

**触发条件**：URL 后缀为 `.pdf`（如 `/invoices/view/123.pdf`），Symfony 将 `getRequestFormat()` 设为 `'pdf'`。

**调用流程**：

```
1. 用户访问 /invoices/view/{id}.pdf
   │
   ▼ 路由匹配 _invoices_view，_format=pdf
2. src/InvoiceBundle/Action/View.php::__invoke(Request, Invoice)
   │  第51行: 'pdf' === $request->getRequestFormat()  ✅
   │  第51行: $this->pdfGenerator->canPrintPdf()      ✅ (需 mbstring+gd)
   │
   ▼
3. Twig 渲染 @SolidInvoiceInvoice/Pdf/invoice.html.twig
   │  传入 ['invoice' => $invoice]
   │
   ▼
4. src/CoreBundle/Pdf/Generator.php::generate($html)
   │  返回 PDF 二进制字符串
   │
   ▼
5. new PdfResponse($pdfContent, 'invoice_{invoiceId}.pdf')
   │  Content-Type: application/pdf
   │  Content-Disposition: inline; filename="invoice_XXX.pdf"
   │
   ▼
6. 浏览器接收 PDF 响应（内联预览）
```

**外部链接的特殊逻辑**（`src/CoreBundle/Action/ViewBilling.php` 第101-145行）：

```php
private function createResponse(Request $request, array $options): array|Response
{
    $entity = $repository->findOneBy(['uuid' => $options['uuid']]);

    // 1. 邮件验证门控检查
    if ($this->emailVerificationGate->isCompanyGated($entity->getCompany())) {
        throw new NotFoundHttpException(...);
    }

    // 2. 已登录用户重定向到内部路由
    if ($this->authorizationChecker->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
        return new RedirectResponse($this->router->generate($options['route'], ...));
    }

    // 3. 切换公司上下文（多租户）
    $this->companySelector->switchCompany($entity->getCompany()->getId());

    // 4. PDF 格式处理
    if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
        $html = $this->twig->render($options['pdfTemplate'], ...);
        return new PdfResponse($this->pdfGenerator->generate($html), $filename);
    }
}
```

### 2.2 邮件附件入口

**触发机制**：Symfony Mailer 的 `MessageEvent` 事件。

验证命令：
```bash
grep -n "MessageEvent\|getSubscribedEvents\|__invoke" \
  src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php
```

**事件流**：

```
1. 业务代码调用 $mailer->send(new InvoiceEmail($invoice))
   │  InvoiceEmail 构造时设定 htmlTemplate = @SolidInvoiceInvoice/Email/invoice.html.twig
   │  （注意：这是邮件 HTML 模板，不是 PDF 模板）
   │
   ▼
2. Symfony Mailer 分发 MessageEvent 事件
   │
   ▼
3. src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php::__invoke(MessageEvent)
   │  第44行: 检查 $message instanceof InvoiceEmail  ✅
   │  第45行: 检查 $this->generator->canPrintPdf()   ✅
   │
   ▼
4. Twig 渲染 @SolidInvoiceInvoice/Pdf/invoice.html.twig
   │  传入 ['invoice' => $message->getInvoice()]
   │  （与浏览器入口使用完全相同的模板和数据）
   │
   ▼
5. $this->generator->generate($html)
   │  返回 PDF 二进制字符串
   │
   ▼
6. $message->attach($content, 'invoice_{invoiceId}.pdf', 'application/pdf')
   │  PDF 作为附件添加到邮件
   │
   ▼
7. 邮件发送（含 HTML 正文 + PDF 附件）
```

**事件订阅注册**（第57-61行）：

```php
public static function getSubscribedEvents(): array
{
    return [MessageEvent::class => '__invoke'];
}
```

### 2.3 两个入口的核心差异

| 维度 | 浏览器打印 | 邮件附件 |
|------|-----------|----------|
| **触发方式** | 用户主动请求 `.pdf` 后缀 | 发送邮件时自动触发 |
| **入口代码** | `src/InvoiceBundle/Action/View.php` / `src/CoreBundle/Action/ViewBilling.php` | `src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php` |
| **PDF 模板** | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | 同左（完全相同） |
| **数据来源** | 路由参数解析的 Invoice 实体 | `InvoiceEmail` 对象中获取的 Invoice 实体 |
| **输出方式** | `PdfResponse`（HTTP 响应） | `$message->attach()`（邮件附件） |
| **文件名** | `invoice_{invoiceId}.pdf` | `invoice_{invoiceId}.pdf` |
| **Content-Disposition** | `inline`（浏览器内预览） | 无（作为邮件附件下载） |
| **前置检查** | 路由格式判断 + `canPrintPdf()` | `instanceof InvoiceEmail` + `canPrintPdf()` |
| **公司上下文** | 外部入口需 `CompanySelector::switchCompany()` | 邮件发送时已在正确的公司上下文 |

---

## 三、默认模板内部结构

### 3.1 发票 PDF 模板

**文件**：`src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig`

该模板是**自包含的完整 HTML 文档**，不继承任何基础模板：

```twig
{# 第10-11行：设置变量 #}
{% set currency = invoice.client.currency %}
{% set hasOutstandingBalance = invoice.payments|length > 0 and not invoice.balance.zero %}

<html>
<head>
    <meta charset="UTF-8" />
    <style type="text/css">
        {# 第17行：CSS 内联注入（关键机制，详见第四章） #}
        {{ file(asset('static/pdf.css')) }}

        {# 第19-25行：mPDF @page 规则 #}
        @page {
            margin-top: 20mm;
            margin-bottom: 25mm;
            margin-left: 15mm;
            margin-right: 15mm;
            footer: footer;
        }
    </style>
</head>
<body>
    {# 第33-35行：状态水印（mPDF 自定义标签） #}
    {% if setting('invoice/watermark') %}
        <watermarktext content="{{ invoice.status.value|upper }}" alpha="0.08"/>
    {% endif %}

    {# 第40-46行：页脚定义 #}
    <pagefooter name="footer" content-left="..." content-right="Page {PAGENO} of {nb}" />

    {# 第51-176行：公司信息 + 发票元数据头部 #}
    {# 第183-212行：客户信息（Bill To） #}
    {# 第217-262行：明细行表格 #}
    {# 第267-271行：自定义字段组件 #}
    {# 第277-401行：总计汇总表 #}
    {# 第406-438行：支付链接区块 #}
    {# 第443-452行：条款 #}
</body>
</html>
```

### 3.2 报价 PDF 模板

**文件**：`src/QuoteBundle/Resources/views/Pdf/quote.html.twig`

结构与发票模板完全平行，差异点：

| 差异 | 发票模板 | 报价模板 |
|------|----------|----------|
| 标题 | `invoice.pdf.title` | `quote.pdf.title` |
| 标签 | "Invoice To" | "Prepared For" |
| 水印设置键 | `setting('invoice/watermark')` | `setting('quote/watermark')` |
| 主色调 | 绿色 `#2e963a` | 蓝色 `#3b82f6` |
| 总计背景色 | `#f0fdf4` (绿) | `#eff6ff` (蓝) |
| 余额行 | 有（支持部分付款） | 无 |
| 支付区块 | 有 | 无 |

### 3.3 可选预设模板的继承结构（测试用）

**基础模板**：`src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig`

```twig
{# _pdf_base.html.twig 提供的骨架 #}
<html>
<head>
    <style type="text/css">
        {{ file(asset('static/pdf.css')) }}
        @page { ... footer: footer; }
        {% block extra_styles %}{% endblock %}     {# ← 子模板可注入额外样式 #}
    </style>
</head>
<body{% block body_attrs %}{% endblock %}>

    {% if setting('invoice/watermark') %}
        <watermarktext content="{{ invoice.status.value|upper }}" alpha="0.08"/>
    {% endif %}

    <pagefooter name="footer" ... />

    {% block body %}{% endblock %}                  {# ← 子模板填充内容 #}
</body>
</html>
```

**子模板示例（modern）**：`src/InvoiceBundle/Resources/views/Templates/modern/pdf.html.twig`

```twig
{% extends '@SolidInvoiceInvoice/Templates/_pdf_base.html.twig' %}
{% import '@SolidInvoiceInvoice/Templates/_macros.html.twig' as inv %}

{% block extra_styles %}
    body { font-family: helvetica, Arial, sans-serif; color: #1e293b; }
{% endblock %}

{% block body %}
    {{ inv.from_block() }}
    {{ inv.bill_to_block(invoice) }}
    ...
{% endblock %}
```

**默认模板 vs 可选模板的结构差异**：

| 维度 | 默认模板 (`Pdf/invoice.html.twig`) | 可选模板 (`Templates/{slug}/pdf.html.twig`) |
|------|--------------------------------------|---------------------------------------------|
| 继承 | 无（自包含） | 继承 `_pdf_base.html.twig` |
| 样式 | 内联写在模板内 | 通过 `extra_styles` 块注入 |
| 内容组织 | 原生 HTML 表格 | 使用 `_macros.html.twig` 宏 |
| 运行状态 | ✅ 生产使用 | ❌ 仅测试使用 |

---

## 四、样式如何进入 mPDF 输出流程

### 4.1 完整链路（可验证）

```
assets/scss/pdf.scss
       │
       │  ① Webpack Encore 编译
       │     webpack.config.js 第9行:
       │     .addStyleEntry('pdf', './assets/scss/pdf.scss')
       │     编译命令: bun run build
       │
       ▼
public/static/pdf.css
       │
       │  ② Twig asset() 函数生成 public 路径
       │     asset('static/pdf.css') → '/static/pdf.css'
       │
       │  ③ Twig file() 函数读取文件内容
       │     src/CoreBundle/Twig/Extension/FileExtension.php 第34行:
       │     file_get_contents($this->projectDir . '/public/' . ltrim($file, '/'))
       │     → 返回 CSS 全文字符串
       │
       ▼
{{ file(asset('static/pdf.css')) }}
       │
       │  ④ 模板输出时嵌入 <style> 标签
       │
       ▼
<style type="text/css">
    /* pdf.css 全部内容 */
    body { font-family: 'Helvetica Neue', Helvetica, ... }
    ...
</style>
       │
       │  ⑤ 完整 HTML 传入 Generator::generate($html)
       │
       ▼
$mpdf->WriteHTML($html)
       │
       │  ⑥ mPDF 解析 <style> 中的 CSS 规则
       │     mPDF 内部 CSS 解析器处理选择器和属性
       │
       ▼
PDF 输出
```

### 4.2 `file()` 函数的关键实现（可验证）

验证命令：
```bash
grep -n "file_get_contents\|is_safe" src/CoreBundle/Twig/Extension/FileExtension.php
```

**代码**（`src/CoreBundle/Twig/Extension/FileExtension.php` 第34行）：

```php
new TwigFunction(
    'file',
    fn ($file) => file_get_contents($this->projectDir . '/public/' . ltrim((string) $file, '\\')),
    ['is_safe' => ['css', 'html']]
)
```

**关键行为**：
1. 接收 `asset()` 返回的相对路径（如 `/static/pdf.css`）
2. 拼接项目根目录 + `/public/` 前缀得到绝对路径
3. `file_get_contents()` 读取完整文件内容
4. `is_safe` 标记告诉 Twig 不对输出转义（原始 CSS 内容直接输出）
5. **结果：整个 CSS 文件内容被原样嵌入到 `<style>` 标签中**

**这意味着 CSS 不是通过 `<link rel="stylesheet">` 外部引用，而是在模板渲染阶段就被内联到 HTML 中。** 这对 mPDF 很重要，因为 mPDF 需要在 `WriteHTML()` 时就能访问所有样式规则。

### 4.3 样式叠加的三个层次

在最终传入 `Generator::generate()` 的 HTML 中，样式通过三个层次叠加：

**层次 1：`<style>` 中的 `pdf.css` 全局样式**

```css
body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; ... }
.invoice-header-table { width: 100%; ... }
.totals-table .value-cell { font-family: 'Courier New', monospace; ... }
```

**层次 2：`<style>` 中的 `@page` 规则和可选的 `extra_styles` 块**

```css
@page { margin-top: 20mm; margin-bottom: 25mm; ... footer: footer; }
/* 可选模板可追加 */
body { font-family: helvetica, Arial, sans-serif; color: #1e293b; }
```

**层次 3：HTML 元素上的 `style=""` 行内样式**

```twig
<td style="padding: 12px 16px; font-size: 9pt; color: #1e293b;
           font-family: 'Courier New', monospace; font-weight: 600;">
    {{ line.total|formatCurrency(currency) }}
</td>
```

**mPDF 的 CSS 优先级与浏览器一致**：行内样式 > `<style>` 中的选择器样式。但由于 mPDF CSS 支持有限，开发策略是**对关键布局和颜色使用行内样式**以确保渲染正确，对通用样式使用 `pdf.css` 类选择器。

### 4.4 为什么不使用 `<link>` 外部引用

mPDF 的 `WriteHTML()` 方法在处理 HTML 时：
- ✅ 能解析 `<style>` 标签中的 CSS
- ⚠️ 对 `<link>` 外部 CSS 的支持不完善且依赖文件系统路径
- ✅ 能解析 `style=""` 行内样式

因此 SolidInvoice 选择了**编译时外挂 + 运行时内联**的策略。

### 4.5 代码证据汇总

| 环节 | 验证命令 | 代码位置 | 行号 |
|------|---------|----------|------|
| Webpack 编译入口 | `grep -n "addStyleEntry.*pdf" webpack.config.js` | `webpack.config.js` | 9 |
| 模板内联 CSS | `grep -rn "file(asset" src/InvoiceBundle/Resources/views/Pdf/` | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | 17 |
| file() 函数实现 | `grep -n "file_get_contents" src/CoreBundle/Twig/Extension/FileExtension.php` | `src/CoreBundle/Twig/Extension/FileExtension.php` | 34 |

---

## 五、字体如何进入 mPDF 输出流程

### 5.1 字体指定的三个位置（可验证）

字体通过三个独立但协同的路径进入最终 PDF。

**路径 1：mPDF 构造函数的 `default_font` 参数**

验证命令：
```bash
grep -n "default_font" src/CoreBundle/Pdf/Generator.php
```

**代码**（`src/CoreBundle/Pdf/Generator.php` 第45行）：

```php
$mpdf = new Mpdf([
    'default_font' => 'helvetica',
    // ...
]);
```

这是 mPDF 的全局回退字体。当 CSS 和行内样式都没有指定字体时使用此值。mPDF 内置了 `helvetica`、`times`、`courier` 等核心字体的字形数据，无需外部字体文件。

**路径 2：`pdf.scss` 中的 `font-family` 声明**

验证命令：
```bash
grep -n "font-family" assets/scss/pdf.scss | head -5
```

**代码**（`assets/scss/pdf.scss` 第20行）：

```scss
body {
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
}
```

mPDF 的 CSS 解析器会解析 `font-family` 列表，按优先级查找可用字体：
1. `'Helvetica Neue'` — mPDF 内置无此字体，跳过
2. `Helvetica` — mPDF 内置，匹配使用
3. `Arial` — 备选
4. `sans-serif` — 通用回退

**路径 3：行内 `style` 中的 `font-family` 覆盖**

验证命令：
```bash
grep -n "font-family" assets/scss/pdf.scss | tail -5
```

**代码**（`assets/scss/pdf.scss` 第312、351行）：

```scss
.totals-table .value-cell {
    font-family: 'Courier New', monospace;
}
```

**或模板中行内样式**：

```twig
{# 金额列使用等宽字体 #}
<td style="font-family: 'Courier New', monospace;">
    {{ line.total|formatCurrency(currency) }}
</td>
```

这在特定元素上覆盖 CSS 类样式，确保数字对齐。

### 5.2 字体从 CSS 到 PDF 像素的完整路径

```
1. Twig 模板渲染
   ├─ <style> 中的 font-family: 'Helvetica Neue', Helvetica, ...
   └─ style="" 中的 font-family: 'Courier New', monospace

2. 生成完整 HTML 字符串传入 Generator::generate()

3. mPDF 构造时设置 default_font = 'helvetica'
   → src/CoreBundle/Pdf/Generator.php 第45行

4. $mpdf->WriteHTML($html)  （第53行）
   │
   ├─ mPDF 解析 <style> 中的 CSS 规则
   │  → 建立 CSSOM 样式映射
   │
   ├─ mPDF 逐元素匹配样式
   │  → 元素无 font-family → 使用 default_font: helvetica
   │  → 元素有 font-family: Helvetica → 匹配内置 Helvetica 字体
   │  → 元素有 font-family: 'Courier New' → 匹配内置 Courier 字体
   │
   ├─ mPDF 使用对应字体的字形数据渲染文本
   │  → 将字符映射为字体中的 glyph ID
   │  → 计算 glyph 宽度进行排版
   │  → 将 glyph 路径嵌入 PDF 流
   │
   └─ $mpdf->Output() 输出包含嵌入字体的 PDF
```

### 5.3 mPDF 内置字体子集

mPDF 7.x+ 内置以下字体的子集（无需外部 TTF/OTF 文件）：

| 字体名 | 类型 | mPDF 中的 Key | 使用场景 |
|--------|------|---------------|----------|
| Helvetica | 无衬线 | `helvetica` | 默认正文字体 |
| Times | 衬线 | `times` | 未使用 |
| Courier | 等宽 | `courier` | 金额数字列 |
| DejaVu Sans | 无衬线 | `dejavusans` | Unicode 扩展字符 |

如需使用非内置字体（如中文字体），需要：
1. 将 TTF/OTF 字体文件放入 mPDF 字体目录
2. 在 `fontdata` 配置中注册字体
3. 在 CSS 或 `default_font` 中引用注册的字体名

### 5.4 当前字体配置的局限

| 局限 | 说明 |
|------|------|
| CSS `font-family` 回退链不完整 | `pdf.scss` 声明 `'Helvetica Neue'` 但 mPDF 无此字体，实际回退到 `Helvetica` |
| 不支持 CJK 字符 | 默认 `helvetica` 字体不包含中文/日文/韩文字形 |
| `default_font` 硬编码 | `Generator.php` 中 `default_font` 为硬编码值，无法通过设置系统修改 |
| 无字体配置接口 | 当前没有管理界面或设置项让用户选择 PDF 字体 |

### 5.5 代码证据汇总

| 环节 | 验证命令 | 代码位置 | 行号 |
|------|---------|----------|------|
| default_font 配置 | `grep -n "default_font" src/CoreBundle/Pdf/Generator.php` | `src/CoreBundle/Pdf/Generator.php` | 45 |
| CSS body 字体 | `grep -n "font-family" assets/scss/pdf.scss | head -1` | `assets/scss/pdf.scss` | 20 |
| 金额等宽字体 | `grep -n "font-family.*Courier" assets/scss/pdf.scss | head -1` | `assets/scss/pdf.scss` | 312 |
| WriteHTML 调用 | `grep -n "WriteHTML" src/CoreBundle/Pdf/Generator.php` | `src/CoreBundle/Pdf/Generator.php` | 53 |

---

## 六、Twig 函数/过滤器数据注入清单（可验证）

PDF 模板中使用的所有动态数据函数：

| Twig 函数/过滤器 | 来源 Extension | 作用 | 代码位置 |
|------------------|----------------|------|----------|
| `setting(key)` | `SettingsExtension` | 读取系统设置值 | `src/SettingsBundle/Twig/Extension/SettingsExtension.php` |
| `address(data)` | `SettingsExtension` | 格式化地址数组 | 同上 |
| `tax_identifiers(owner)` | `TaxBreakdownExtension` | 获取税标识列表 | `src/TaxBundle/Twig/Extension/TaxBreakdownExtension.php` |
| `tax_breakdown(doc)` | `TaxBreakdownExtension` | 计算税务明细 | 同上 |
| `payable_amount(doc)` | `TaxBreakdownExtension` | 计算应付金额 | 同上 |
| `payments_configured()` | `PaymentExtension` | 检查支付方式数量 | `src/PaymentBundle/Twig/PaymentExtension.php` |
| `discount(entity)` | `BillingExtension` | 计算折扣金额 | `src/CoreBundle/Twig/Extension/BillingExtension.php` |
| `app_logo(width)` | `GlobalExtension` | 渲染公司 Logo（base64） | `src/CoreBundle/Twig/Extension/GlobalExtension.php` |
| `company_name()` | `GlobalExtension` | 获取公司名称 | 同上 |
| `can_print_pdf()` | `GlobalExtension` | 检查 PDF 生成能力 | 同上 |
| `file(path)` | `FileExtension` | 读取文件内容 | `src/CoreBundle/Twig/Extension/FileExtension.php` |
| `|formatCurrency(currency)` | MoneyBundle | 格式化金额 | - |
| `<twig:CustomFieldsListPdf>` | `CustomFieldsListPdf` | 渲染自定义字段 | `src/CoreBundle/Twig/Components/CustomFieldsListPdf.php` |

**验证命令**：
```bash
grep -rn "TwigFunction.*'setting'\|TwigFunction.*'file'" src/ --include="*.php"
```

---

## 七、mPDF 渲染核心（可验证）

**文件**：`src/CoreBundle/Pdf/Generator.php`

### 7.1 前置检查

验证命令：
```bash
grep -n "canPrintPdf" src/CoreBundle/Pdf/Generator.php
```

```php
public function canPrintPdf(): bool
{
    return \extension_loaded('mbstring') && \extension_loaded('gd');
}
```

- `mbstring`：mPDF 内部字符串处理所需
- `gd`：图片处理（Logo 等）所需

### 7.2 mPDF 配置

验证命令：
```bash
grep -n "new Mpdf\|margin_\|default_font" src/CoreBundle/Pdf/Generator.php
```

```php
$mpdf = new Mpdf([
    'tempDir' => $this->cacheDir . '/pdf',       // 临时目录（ttfontdata 等）
    'margin_left' => 15,                          // mm
    'margin_right' => 15,
    'margin_top' => 20,
    'margin_bottom' => 25,
    'margin_header' => 10,
    'margin_footer' => 10,
    'default_font' => 'helvetica',                // 全局默认字体
]);
```

### 7.3 渲染步骤

验证命令：
```bash
grep -n "WriteHTML\|allow_charset_conversion\|showWatermarkText\|SetProtection\|SetDisplayMode" src/CoreBundle/Pdf/Generator.php
```

```php
$mpdf->allow_charset_conversion = false;      // HTML 已为 UTF-8，不需转换
$mpdf->showWatermarkText = true;              // 启用水印渲染
$mpdf->SetDisplayMode('fullpage');            // PDF 阅读器默认全页显示
$mpdf->SetProtection(['print']);              // 仅允许打印权限
$mpdf->setLogger($this->logger);              // 日志记录

$mpdf->WriteHTML($html);                      // 核心：解析 HTML+CSS，排版，生成 PDF

return $mpdf->Output(null, Destination::STRING_RETURN);  // 输出为二进制字符串
```

### 7.4 mPDF 自定义标签

模板中使用的 mPDF 专有 HTML 标签，由 `WriteHTML()` 特殊处理：

| 标签 | 处理方式 | 模板用法 |
|------|----------|----------|
| `<watermarktext>` | 在每页绘制半透明文字 | `<watermarktext content="PENDING" alpha="0.08"/>` |
| `<pagefooter>` | 注册命名页脚模板 | `<pagefooter name="footer" content-right="Page {PAGENO} of {nb}"/>` |
| `{PAGENO}` | 渲染时替换为当前页码 | 页脚 content 属性中 |
| `{nb}` | 渲染时替换为总页数 | 页脚 content 属性中 |

### 7.5 CSS 解析的局限

mPDF 的 CSS 解析器与浏览器引擎差异：

| 特性 | 浏览器 | mPDF | SolidInvoice 的应对 |
|------|--------|------|---------------------|
| CSS 变量 `var(--xxx)` | ✅ | ❌ | pdf.scss 中硬编码颜色值 |
| Flexbox/Grid | ✅ | ❌ | 使用 `<table>` 布局 |
| 嵌套选择器 | ✅ | ⚠️ 有限 | 使用简单类选择器 + 行内样式 |
| `border-radius` | ✅ | ⚠️ 有限 | 部分使用，简单场景 |
| `background-color` 继承 | ✅ | ⚠️ 不完整 | 在每个 `<td>` 上重复声明 |
| `@media` 查询 | ✅ | ❌ | 不使用 |

---

## 八、响应封装（可验证）

**文件**：`src/CoreBundle/Response/PdfResponse.php`

验证命令：
```bash
grep -n "Content-Type\|Content-Disposition\|DISPOSITION_INLINE" src/CoreBundle/Response/PdfResponse.php
```

```php
public function __construct(
    string $content,                                    // PDF 二进制
    string $fileName,                                   // 如 invoice_INV-001.pdf
    string $contentDisposition = ResponseHeaderBag::DISPOSITION_INLINE,  // 默认内联预览
    int $status = Response::HTTP_OK,
    array $headers = []
)
```

浏览器入口默认 `DISPOSITION_INLINE`（浏览器内预览），邮件附件入口不经过 `PdfResponse`，而是直接将二进制字符串通过 `$message->attach()` 附加。

---

## 九、完整调用链对比图

```
                    ┌─────────────────────────────────────┐
                    │         两种 PDF 触发入口             │
                    └──────────┬──────────────┬────────────┘
                               │              │
              ┌────────────────▼──┐    ┌──────▼───────────────┐
              │   浏览器打印入口    │    │    邮件附件入口        │
              │                   │    │                       │
              │ URL 后缀 .pdf     │    │ MessageEvent 事件     │
              │ getRequestFormat  │    │ instanceof 检查       │
              │ === 'pdf'         │    │ InvoiceEmail/QuoteEmail│
              │                   │    │                       │
              │ src/InvoiceBundle/Action/View.php  │ InvoicePdfListener.php │
              │ src/CoreBundle/Action/ViewBilling.php │ QuotePdfListener.php │
              └────────┬──────────┘    └──────────┬────────────┘
                       │                          │
                       │   ┌──────────────────────┘
                       │   │
              ┌────────▼───▼──────────────────────────────────┐
              │  Twig 渲染 @SolidInvoiceInvoice/Pdf/          │
              │           invoice.html.twig                    │
              │           (或 Quote/Pdf/quote.html.twig)       │
              │                                                │
              │  ┌─ file(asset('static/pdf.css')) ──────────┐  │
              │  │  FileExtension::file_get_contents()      │  │
              │  │  → pdf.css 全文内联到 <style> 标签       │  │
              │  └──────────────────────────────────────────┘  │
              │                                                │
              │  ┌─ Twig 函数注入动态数据 ───────────────────┐  │
              │  │  setting() / tax_identifiers() /          │  │
              │  │  tax_breakdown() / discount() /           │  │
              │  │  payments_configured() / app_logo() /     │  │
              │  │  company_name() / formatCurrency() /      │  │
              │  │  <twig:CustomFieldsListPdf>               │  │
              │  └──────────────────────────────────────────┘  │
              │                                                │
              │  ┌─ @page + 行内样式 ────────────────────────┐  │
              │  │  @page { margin: 20mm 15mm 25mm 15mm; }  │  │
              │  │  style="font-family: 'Courier New'; ..."  │  │
              │  └──────────────────────────────────────────┘  │
              └──────────────────┬─────────────────────────────┘
                                 │
                                 ▼
              ┌──────────────────────────────────────────────┐
              │  src/CoreBundle/Pdf/Generator.php::generate() │
              │                                              │
              │  new Mpdf(['default_font' => 'helvetica'])   │
              │  $mpdf->showWatermarkText = true             │
              │  $mpdf->SetProtection(['print'])             │
              │  $mpdf->WriteHTML($html)                     │
              │    → 解析 <style> 中的 CSS                    │
              │    → 匹配 font-family 到内置字体              │
              │    → 处理 <watermarktext> <pagefooter>       │
              │    → 排版、字形嵌入、生成 PDF 内部结构         │
              │  $mpdf->Output(null, STRING_RETURN)          │
              └──────────────┬───────────────────────────────┘
                             │
                    ┌────────▼────────┐
                    │  PDF 二进制字符串  │
                    └───┬─────────┬───┘
                        │         │
           ┌────────────▼──┐  ┌──▼──────────────────┐
           │ 浏览器入口      │  │ 邮件入口             │
           │                │  │                     │
           │ new PdfResponse│  │ $message->attach()  │
           │ Content-Type:  │  │ MIME: application/  │
           │ application/pdf│  │ pdf                 │
           │ Disposition:   │  │                     │
           │ inline         │  │ 作为邮件附件发送      │
           └────────────────┘  └─────────────────────┘
```

---

## 十、关键文件索引（相对路径）

| 功能 | 文件路径 |
|------|----------|
| PDF 生成器 | `src/CoreBundle/Pdf/Generator.php` |
| PDF 响应类 | `src/CoreBundle/Response/PdfResponse.php` |
| 文件内联 Twig 函数 | `src/CoreBundle/Twig/Extension/FileExtension.php` |
| 发票 PDF 模板 | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` |
| 报价 PDF 模板 | `src/QuoteBundle/Resources/views/Pdf/quote.html.twig` |
| PDF 样式源 | `assets/scss/pdf.scss` |
| Webpack 配置 | `webpack.config.js` |
| 发票查看 Action | `src/InvoiceBundle/Action/View.php` |
| 外部查看 Action | `src/CoreBundle/Action/ViewBilling.php` |
| 发票路由配置 | `src/InvoiceBundle/Resources/config/routing.php` |
| 外部路由配置 | `src/CoreBundle/Resources/config/routing.php` |
| 发票邮件 PDF 监听器 | `src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php` |
| 报价邮件 PDF 监听器 | `src/QuoteBundle/Listener/Mailer/QuotePdfListener.php` |
| 可选模板目录 | `src/InvoiceBundle/Resources/views/Templates/` |
| 可选模板基础类 | `src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig` |
| 设置 Twig 函数 | `src/SettingsBundle/Twig/Extension/SettingsExtension.php` |
| 税务 Twig 函数 | `src/TaxBundle/Twig/Extension/TaxBreakdownExtension.php` |
| 支付 Twig 函数 | `src/PaymentBundle/Twig/PaymentExtension.php` |
| 全局 Twig 函数 | `src/CoreBundle/Twig/Extension/GlobalExtension.php` |
| 自定义字段 PDF 组件 | `src/CoreBundle/Twig/Components/CustomFieldsListPdf.php` |
| 自定义字段 PDF 模板 | `src/CoreBundle/Resources/views/Components/_custom-fields-pdf.html.twig` |
| 模板渲染测试 | `src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php` |
| 品牌定制测试 | `src/SaasBundle/Tests/Functional/PdfBaseCustomBrandingGateTest.php` |

---

## 十一、验证命令汇总

本章节的所有结论均可通过以下命令在本地仓库验证：

### 11.1 模板选择验证

```bash
# 生产代码中默认模板的引用（应得到 6 个结果，不含测试文件）
grep -rn "@SolidInvoiceInvoice/Pdf\|@SolidInvoiceQuote/Pdf" src/ --include="*.php" | grep -v "Tests/"

# 可选模板的引用（应仅在测试文件中）
grep -rn "@SolidInvoiceInvoice/Templates" src/ --include="*.php"
```

### 11.2 入口差异验证

```bash
# 路由配置
grep -n "_invoices_view\|_view_invoice_external\|_format" \
  src/InvoiceBundle/Resources/config/routing.php \
  src/CoreBundle/Resources/config/routing.php

# 邮件监听器事件订阅
grep -n "MessageEvent\|getSubscribedEvents" \
  src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php \
  src/QuoteBundle/Listener/Mailer/QuotePdfListener.php
```

### 11.3 样式流程验证

```bash
# Webpack 配置
grep -n "addStyleEntry.*pdf" webpack.config.js

# 模板内联 CSS
grep -rn "file(asset.*pdf" src/ --include="*.twig"

# file() 函数实现
grep -n "file_get_contents\|is_safe" src/CoreBundle/Twig/Extension/FileExtension.php
```

### 11.4 字体流程验证

```bash
# mPDF 默认字体
grep -n "default_font\|WriteHTML" src/CoreBundle/Pdf/Generator.php

# CSS 字体声明
grep -n "font-family" assets/scss/pdf.scss | head -5

# 行内字体覆盖
grep -n "font-family.*Courier" assets/scss/pdf.scss
```

---

## 结论总结

经过代码证据逐一验证，以下结论准确无误：

1. **模板选择**：6 个生产代码入口全部硬编码引用默认模板，不存在动态选择机制。8 个可选预设模板仅在测试中使用，尚未接入运行路径。

2. **入口差异**：浏览器入口通过 URL 后缀 `.pdf` 触发，走 `View` / `ViewBilling` Action，返回 `PdfResponse`（内联预览）；邮件入口通过 `MessageEvent` 自动触发，走 `InvoicePdfListener`，作为邮件附件发送。两者使用完全相同的 PDF 模板。

3. **样式进入 mPDF**：通过 `file()` 函数（`file_get_contents`）将编译好的 `public/static/pdf.css` 全文内联到 `<style>` 标签，而非外部引用。三个层次叠加：`pdf.css` 全局 → `@page` 规则 → 行内 `style=""`。

4. **字体进入 mPDF**：三个路径协同：① `Generator.php` 构造时设置 `default_font => 'helvetica'` 作为回退；② `pdf.scss` 中的 CSS `font-family` 声明匹配到内置 `Helvetica`；③ 行内样式中 `'Courier New'` 覆盖用于金额列。mPDF 在 `WriteHTML()` 时解析 CSS 中的字体声明，使用内置字形数据渲染并嵌入 PDF 流。
