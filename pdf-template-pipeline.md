# PDF 模板生成完整代码链路

本文档梳理 SolidInvoice 中 PDF 模板从选择到最终打印输出的完整代码流程，重点讲清：
- 默认发票/报价模板与可选预设模板的运行路径差异
- 浏览器打印入口与邮件附件入口的不同
- 样式和字体如何进入 mPDF 输出流程

---

## 一、模板选择逻辑：默认模板是唯一运行路径

### 1.1 关键结论

**所有生产代码（Action、Listener）均硬编码引用默认 PDF 模板，不存在运行时动态选择模板的机制。** 8 个可选预设模板（classic、modern、compact 等）目前仅在测试中使用，不进入实际运行路径。

### 1.2 默认模板的引用点（全量搜索结果）

| 入口 | 模板路径 | 代码位置 |
|------|----------|----------|
| 登录用户-发票 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | [InvoiceBundle/Action/View.php#L52](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Action/View.php#L52) |
| 登录用户-报价 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | [QuoteBundle/Action/View.php#L46](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/QuoteBundle/Action/View.php#L46) |
| 外部链接-发票 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | [CoreBundle/Action/ViewBilling.php#L134](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Action/ViewBilling.php#L134) |
| 外部链接-报价 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | [CoreBundle/Action/ViewBilling.php#L69](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Action/ViewBilling.php#L69) |
| 邮件附件-发票 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | [InvoiceBundle/Listener/Mailer/InvoicePdfListener.php#L47](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php#L47) |
| 邮件附件-报价 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | [QuoteBundle/Listener/Mailer/QuotePdfListener.php#L47](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/QuoteBundle/Listener/Mailer/QuotePdfListener.php#L47) |

所有 6 个入口均直接硬编码模板路径，没有通过设置系统或数据库读取模板名的逻辑。

### 1.3 可选预设模板的状态

**目录位置：** [InvoiceBundle/Resources/views/Templates/](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/)

8 个预设模板各包含 3 个通道文件：

```
Templates/
├── _pdf_base.html.twig          ← 基础模板（提供 <style> + 水印 + 页脚）
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

**实际引用点（仅测试文件）：**

| 测试文件 | 引用的模板 |
|----------|-----------|
| [TemplatesRenderingTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php#L73-L74) | `@SolidInvoiceInvoice/Templates/{slug}/{channel}.html.twig` |
| [TemplateEmailSnapshotTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Tests/Functional/Email/TemplateEmailSnapshotTest.php#L82-L84) | `@SolidInvoiceInvoice/Templates/{slug}/email.html.twig` |
| [PdfBaseCustomBrandingGateTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/SaasBundle/Tests/Functional/PdfBaseCustomBrandingGateTest.php#L178-L179) | `@SolidInvoiceInvoice/Templates/classic/pdf.html.twig` |

**结论：可选预设模板是为未来模板选择功能预制的，当前版本尚未接入运行路径。**

---

## 二、两个入口的完整调用链对比

### 2.1 浏览器打印入口

**路由定义：**

```
# 登录用户（InvoiceBundle 路由）
_invoices_view: /view/{id}.{_format}    _format: html|pdf
# 外部链接（CoreBundle 路由）
_view_invoice_external: /view/invoice/{uuid}.{_format}    _format: html|pdf
_view_quote_external: /view/quote/{uuid}.{_format}        _format: html|pdf
```

**路由配置来源：**
- 外部路由：[CoreBundle/Resources/config/routing.php#L38-L47](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Resources/config/routing.php#L38-L47)
- 发票路由：[InvoiceBundle/Resources/config/routing.php#L63-L66](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/config/routing.php#L63-L66)

**触发条件：** URL 后缀为 `.pdf`（如 `/invoices/view/123.pdf`），Symfony 将 `getRequestFormat()` 设为 `'pdf'`。

**调用流程：**

```
1. 用户访问 /invoices/view/{id}.pdf
   │
   ▼ 路由匹配 _invoices_view，_format=pdf
2. InvoiceBundle\Action\View::__invoke(Request, Invoice)
   │  Line 51: 'pdf' === $request->getRequestFormat()  ✅
   │  Line 51: $this->pdfGenerator->canPrintPdf()      ✅ (需 mbstring+gd)
   │
   ▼
3. Twig 渲染 @SolidInvoiceInvoice/Pdf/invoice.html.twig
   │  传入 ['invoice' => $invoice]
   │
   ▼
4. $this->pdfGenerator->generate($html)
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

**外部链接的特殊逻辑**（[ViewBilling.php#L101-L145](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Action/ViewBilling.php#L101-L145)）：

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
        return new RedirectResponse($this->router->generate($options['route'], ['id' => $entity->getId()]));
    }

    // 3. 切换公司上下文（多租户）
    $this->companySelector->switchCompany($entity->getCompany()->getId());

    // 4. PDF 格式处理
    if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
        $html = $this->twig->render($options['pdfTemplate'], [$options['entity'] => $entity]);
        return new PdfResponse($this->pdfGenerator->generate($html), $filename);
    }

    // 5. 非 PDF 格式返回 HTML 视图
    return [...];
}
```

### 2.2 邮件附件入口

**触发机制：** Symfony Mailer 的 `MessageEvent` 事件。

**事件流：**

```
1. 业务代码调用 $mailer->send(new InvoiceEmail($invoice))
   │  InvoiceEmail 构造时设定 htmlTemplate = @SolidInvoiceInvoice/Email/invoice.html.twig
   │  （注意：这是邮件 HTML 模板，不是 PDF 模板）
   │
   ▼
2. Symfony Mailer 分发 MessageEvent 事件
   │
   ▼
3. InvoicePdfListener::__invoke(MessageEvent)
   │  检查 $message instanceof InvoiceEmail  ✅
   │  检查 $this->generator->canPrintPdf()   ✅
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

### 2.3 两个入口的核心差异

| 维度 | 浏览器打印 | 邮件附件 |
|------|-----------|----------|
| **触发方式** | 用户主动请求 `.pdf` 后缀 | 发送邮件时自动触发 |
| **入口代码** | `View::__invoke()` / `ViewBilling::createResponse()` | `InvoicePdfListener::__invoke()` |
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

**文件：** [InvoiceBundle/Resources/views/Pdf/invoice.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig)

该模板是**自包含的完整 HTML 文档**，不继承任何基础模板：

```twig
{# 第 10-11 行：设置变量 #}
{% set currency = invoice.client.currency %}
{% set hasOutstandingBalance = invoice.payments|length > 0 and not invoice.balance.zero %}

<html>
<head>
    <meta charset="UTF-8" />
    <style type="text/css">
        {# 第 17 行：CSS 内联注入（关键机制，详见第四章） #}
        {{ file(asset('static/pdf.css')) }}

        {# 第 19-25 行：mPDF @page 规则 #}
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
    {# 第 33-35 行：状态水印（mPDF 自定义标签） #}
    {% if setting('invoice/watermark') %}
        <watermarktext content="{{ invoice.status.value|upper }}" alpha="0.08"/>
    {% endif %}

    {# 第 40-46 行：页脚定义 #}
    <pagefooter name="footer" content-left="..." content-right="Page {PAGENO} of {nb}" />

    {# 第 51-176 行：公司信息 + 发票元数据头部 #}
    {# 第 183-212 行：客户信息（Bill To） #}
    {# 第 217-262 行：明细行表格 #}
    {# 第 267-271 行：自定义字段组件 #}
    {# 第 277-401 行：总计汇总表 #}
    {# 第 406-438 行：支付链接区块 #}
    {# 第 443-452 行：条款 #}
</body>
</html>
```

### 3.2 报价 PDF 模板

**文件：** [QuoteBundle/Resources/views/Pdf/quote.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/QuoteBundle/Resources/views/Pdf/quote.html.twig)

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

### 3.3 可选预设模板的继承结构

**基础模板：** [_pdf_base.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig)

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

**子模板示例（modern）：** [modern/pdf.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/modern/pdf.html.twig)

```twig
{% extends '@SolidInvoiceInvoice/Templates/_pdf_base.html.twig' %}
{% import '@SolidInvoiceInvoice/Templates/_macros.html.twig' as inv %}

{% block extra_styles %}
    body { font-family: helvetica, Arial, sans-serif; color: #1e293b; }
{% endblock %}

{% block body %}
    {# 使用 inv 宏组装内容 #}
    {{ inv.from_block() }}
    {{ inv.bill_to_block(invoice) }}
    {{ inv.totals_block(invoice, currency, {...}) }}
    {{ inv.terms_block(invoice) }}
    {{ inv.payment_cta(invoice, '#0f172a') }}
{% endblock %}
```

**默认模板 vs 可选模板的结构差异：**

| 维度 | 默认模板 (`Pdf/invoice.html.twig`) | 可选模板 (`Templates/{slug}/pdf.html.twig`) |
|------|--------------------------------------|---------------------------------------------|
| 继承 | 无（自包含） | 继承 `_pdf_base.html.twig` |
| 样式 | 内联写在模板内 | 通过 `extra_styles` 块注入 |
| 内容组织 | 原生 HTML 表格 | 使用 `_macros.html.twig` 宏 |
| 运行状态 | ✅ 生产使用 | ❌ 仅测试使用 |

---

## 四、样式如何进入 mPDF 输出流程

### 4.1 完整链路

```
assets/scss/pdf.scss
       │
       │  ① Webpack Encore 编译
       │     webpack.config.js #L9:
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
       │     FileExtension.php #L34:
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
    .invoice-header-table { width: 100%; ... }
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
       │     注意：mPDF 的 CSS 解析是有限的，不支持所有 CSS 特性
       │
       ▼
PDF 输出
```

### 4.2 `file()` 函数的关键实现

**代码位置：** [CoreBundle/Twig/Extension/FileExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Extension/FileExtension.php#L34)

```php
new TwigFunction('file', fn ($file) => file_get_contents($this->projectDir . '/public/' . ltrim((string) $file, '\\')), ['is_safe' => ['css', 'html']])
```

**关键行为：**

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
body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 10pt; ... }
.invoice-header-table { width: 100%; border-collapse: collapse; ... }
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
<td style="padding: 12px 16px; font-size: 9pt; color: #1e293b; text-align: right;
           font-family: 'Courier New', monospace; font-weight: 600;">
    {{ line.total|formatCurrency(currency) }}
</td>
```

**mPDF 的 CSS 优先级与浏览器一致：** 行内样式 > `<style>` 中的选择器样式。但由于 mPDF CSS 支持有限，开发策略是**对关键布局和颜色使用行内样式**以确保渲染正确，对通用样式使用 `pdf.css` 类选择器。

### 4.4 为什么不使用 `<link>` 外部引用

mPDF 的 `WriteHTML()` 方法在处理 HTML 时：

- ✅ 能解析 `<style>` 标签中的 CSS
- ⚠️ 对 `<link>` 外部 CSS 的支持不完善且依赖文件系统路径
- ✅ 能解析 `style=""` 行内样式

因此 SolidInvoice 选择了**编译时外挂 + 运行时内联**的策略：
- 开发阶段用 SCSS 模块化管理
- Webpack 编译为 `public/static/pdf.css`
- 模板渲染时通过 `file()` 函数内联

---

## 五、字体如何进入 mPDF 输出流程

### 5.1 字体指定的三个位置

字体通过三个独立但协同的路径进入最终 PDF：

**路径 1：mPDF 构造函数的 `default_font` 参数**

```php
// Generator.php #L45
$mpdf = new Mpdf([
    'default_font' => 'helvetica',
]);
```

这是 mPDF 的全局回退字体。当 CSS 和行内样式都没有指定字体时使用此值。mPDF 内置了 `helvetica`、`times`、`courier` 等核心字体的字形数据，无需外部字体文件。

**路径 2：`pdf.css` 中的 `font-family` 声明**

```css
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

4. $mpdf->WriteHTML($html)
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
| CSS `font-family` 回退链不完整 | `pdf.css` 声明 `'Helvetica Neue'` 但 mPDF 无此字体，实际回退到 `Helvetica` |
| 不支持 CJK 字符 | 默认 `helvetica` 字体不包含中文/日文/韩文字形 |
| `default_font` 硬编码 | `Generator.php` 中 `default_font` 为硬编码值，无法通过设置系统修改 |
| 无字体配置接口 | 当前没有管理界面或设置项让用户选择 PDF 字体 |

---

## 六、Twig 函数/过滤器数据注入清单

PDF 模板中使用的所有动态数据函数：

| Twig 函数/过滤器 | 来源 Extension | 作用 | 代码位置 |
|------------------|----------------|------|----------|
| `setting(key)` | [SettingsExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/SettingsBundle/Twig/Extension/SettingsExtension.php#L43) | 读取系统设置值 | 水印开关、公司信息、Logo |
| `address(data)` | [SettingsExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/SettingsBundle/Twig/Extension/SettingsExtension.php#L44) | 格式化地址数组 | 公司地址、客户地址 |
| `tax_identifiers(owner)` | [TaxBreakdownExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/TaxBundle/Twig/Extension/TaxBreakdownExtension.php#L57) | 获取税标识列表 | 公司 VAT、客户 VAT |
| `tax_breakdown(doc)` | [TaxBreakdownExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/TaxBundle/Twig/Extension/TaxBreakdownExtension.php#L58) | 计算税务明细 | 税汇总行 |
| `payable_amount(doc)` | [TaxBreakdownExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/TaxBundle/Twig/Extension/TaxBreakdownExtension.php#L59) | 计算应付金额 | 预扣税后金额 |
| `payments_configured()` | [PaymentExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/PaymentBundle/Twig/PaymentExtension.php#L52) | 检查支付方式数量 | 支付按钮显示 |
| `discount(entity)` | [BillingExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Extension/BillingExtension.php#L40) | 计算折扣金额 | 折扣行 |
| `app_logo(width)` | [GlobalExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php#L120) | 渲染公司 Logo（base64 内嵌） | PDF 头部 Logo |
| `company_name()` | [GlobalExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php#L122) | 获取公司名称 | PDF 头部 |
| `can_print_pdf()` | [GlobalExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php#L132) | 检查 PDF 生成能力 | 前端按钮显示控制 |
| `file(path)` | [FileExtension](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Extension/FileExtension.php#L34) | 读取文件内容 | CSS 内联注入 |
| `|formatCurrency(currency)` | MoneyBundle | 格式化金额+货币符号 | 所有金额显示 |
| `<twig:CustomFieldsListPdf>` | [CustomFieldsListPdf](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Components/CustomFieldsListPdf.php#L27) | 渲染自定义字段 | PDF 自定义字段区块 |

---

## 七、mPDF 渲染核心

**代码位置：** [CoreBundle/Pdf/Generator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Pdf/Generator.php)

### 7.1 前置检查

```php
public function canPrintPdf(): bool
{
    return \extension_loaded('mbstring') && \extension_loaded('gd');
}
```

- `mbstring`：mPDF 内部字符串处理所需
- `gd`：图片处理（Logo 等）所需

### 7.2 mPDF 配置

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

```php
$mpdf->allow_charset_conversion = false;      // HTML 已为 UTF-8，不需转换
$mpdf->showWatermarkText = true;              // 启用水印渲染
$mpdf->SetDisplayMode('fullpage');            // PDF 阅读器默认全页显示
$mpdf->SetProtection(['print']);              // 仅允许打印权限
$mpdf->setLogger($this->logger);              // 日志记录

$mpdf->WriteHTML($html);                      // 核心：解析 HTML+CSS，排版，生成 PDF 内部结构

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

## 八、响应封装

**代码位置：** [CoreBundle/Response/PdfResponse.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Response/PdfResponse.php)

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
              │ View.php (登录)   │    │ InvoicePdfListener    │
              │ ViewBilling.php   │    │ QuotePdfListener      │
              │   (外部链接)       │    │                       │
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
              │  Generator::generate($html)                  │
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

## 十、关键文件索引

| 功能 | 文件路径 |
|------|----------|
| PDF 生成器 | [Generator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Pdf/Generator.php) |
| PDF 响应类 | [PdfResponse.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Response/PdfResponse.php) |
| 文件内联 Twig 函数 | [FileExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Extension/FileExtension.php) |
| 发票 PDF 模板 | [Pdf/invoice.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig) |
| 报价 PDF 模板 | [Pdf/quote.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/QuoteBundle/Resources/views/Pdf/quote.html.twig) |
| PDF 样式源 | [pdf.scss](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/assets/scss/pdf.scss) |
| Webpack 配置 | [webpack.config.js](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/webpack.config.js) |
| 发票查看 Action | [InvoiceBundle/Action/View.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Action/View.php) |
| 外部查看 Action | [CoreBundle/Action/ViewBilling.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Action/ViewBilling.php) |
| 发票路由配置 | [InvoiceBundle routing.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/config/routing.php) |
| 外部路由配置 | [CoreBundle routing.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Resources/config/routing.php) |
| 发票邮件 PDF 监听器 | [InvoicePdfListener.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php) |
| 报价邮件 PDF 监听器 | [QuotePdfListener.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/QuoteBundle/Listener/Mailer/QuotePdfListener.php) |
| 可选模板目录 | [Templates/](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/) |
| 可选模板基础类 | [_pdf_base.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig) |
| 设置 Twig 函数 | [SettingsExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/SettingsBundle/Twig/Extension/SettingsExtension.php) |
| 税务 Twig 函数 | [TaxBreakdownExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/TaxBundle/Twig/Extension/TaxBreakdownExtension.php) |
| 支付 Twig 函数 | [PaymentExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/PaymentBundle/Twig/PaymentExtension.php) |
| 全局 Twig 函数 | [GlobalExtension.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Extension/GlobalExtension.php) |
| 自定义字段 PDF 组件 | [CustomFieldsListPdf.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Twig/Components/CustomFieldsListPdf.php) |
| 自定义字段 PDF 模板 | [_custom-fields-pdf.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Resources/views/Components/_custom-fields-pdf.html.twig) |
| 模板渲染测试 | [TemplatesRenderingTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php) |
| 品牌定制测试 | [PdfBaseCustomBrandingGateTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/SaasBundle/Tests/Functional/PdfBaseCustomBrandingGateTest.php) |
