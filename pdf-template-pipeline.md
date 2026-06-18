# PDF 模板生成完整代码链路

本文档梳理 SolidInvoice 中 PDF 模板从选择到最终打印输出的完整代码流程，包括模板资源定位、字体配置、样式叠加、PDF 渲染等关键环节。

---

## 一、整体架构概览

```
用户请求 PDF
      │
      ▼
  Action 入口
      │
      ├─► 检查 PDF 生成能力 (mbstring + gd 扩展)
      │
      ▼
  Twig 模板渲染
      │
      ├─► 选择模板 (主模板 / 可选手册模板)
      ├─► 加载 pdf.css (file_get_contents 内联)
      ├─► 注入动态数据 (invoice/quote)
      │
      ▼
  生成完整 HTML
      │
      ▼
  mPDF 渲染引擎
      │
      ├─► 字体配置 (helvetica 默认)
      ├─► 页面边距设置
      ├─► 水印处理
      ├─► CSS 解析与布局
      │
      ▼
  输出 PDF 二进制
      │
      ▼
  PdfResponse 封装
      │
      └─► Content-Type: application/pdf
```

---

## 二、入口点：PDF 生成的触发方式

### 2.1 浏览器直接下载/预览

**代码位置：**

- 登录用户查看：[InvoiceBundle/Action/View.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Action/View.php#L48-L53)
- 外部公开链接：[CoreBundle/Action/ViewBilling.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Action/ViewBilling.php#L132-L138)

**核心逻辑：**

```php
// 关键判断条件
if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
    $html = $this->twig->render('@SolidInvoiceInvoice/Pdf/invoice.html.twig', ['invoice' => $invoice]);
    return new PdfResponse($this->pdfGenerator->generate($html), sprintf('invoice_%s.pdf', $invoice->getInvoiceId()));
}
```

### 2.2 邮件附件自动生成

**代码位置：**

- 发票邮件：[InvoiceBundle/Listener/Mailer/InvoicePdfListener.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php#L40-L51)
- 报价邮件：[QuoteBundle/Listener/Mailer/QuotePdfListener.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/QuoteBundle/Listener/Mailer/QuotePdfListener.php#L40-L51)

**事件驱动机制：**

```php
// 监听 MessageEvent 事件，在邮件发送前自动附加 PDF
public static function getSubscribedEvents(): array
{
    return [MessageEvent::class => '__invoke'];
}

public function __invoke(MessageEvent $event): void
{
    $message = $event->getMessage();
    if ($message instanceof InvoiceEmail && $this->generator->canPrintPdf()) {
        $content = $this->generator->generate(
            $this->twig->render('@SolidInvoiceInvoice/Pdf/invoice.html.twig', ['invoice' => $message->getInvoice()])
        );
        $message->attach($content, sprintf('invoice_%s.pdf', $message->getInvoice()->getInvoiceId()), 'application/pdf');
    }
}
```

---

## 三、模板系统：两层结构

### 3.1 主 PDF 模板（默认使用）

**发票模板：** [InvoiceBundle/Resources/views/Pdf/invoice.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig)

**报价模板：** [QuoteBundle/Resources/views/Pdf/quote.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/QuoteBundle/Resources/views/Pdf/quote.html.twig)

**模板结构：**

```twig
<html>
<head>
    <meta charset="UTF-8" />
    <style type="text/css">
        {{ file(asset('static/pdf.css')) }}  {# 样式内联 #}
        @page {
            margin-top: 20mm;
            margin-bottom: 25mm;
            footer: footer;
        }
    </style>
</head>
<body>
    {# 水印 #}
    {% if setting('invoice/watermark') %}
        <watermarktext content="{{ invoice.status.value|upper }}" alpha="0.08"/>
    {% endif %}

    {# 页脚 (mPDF 自定义标签) #}
    <pagefooter
        name="footer"
        content-left="Powered by SolidInvoice"
        content-right="Page {PAGENO} of {nb}"
        line="on"
    />

    {# 主体内容：公司信息、账单信息、明细表格、总计、支付链接、条款 #}
</body>
</html>
```

### 3.2 可选模板系统（8 种预设风格）

**代码位置：** [InvoiceBundle/Resources/views/Templates/](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/)

**可用模板：**
- `classic` - 经典风格
- `modern` - 现代简约
- `compact` - 紧凑布局
- `editorial` - 编辑风格
- `monochrome` - 单色风格
- `photographer` - 摄影师风格
- `studio` - 工作室风格
- `friendly` - 友好风格

**基础模板：** [_pdf_base.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig)

**继承关系（以 modern 为例）：**

```twig
{# modern/pdf.html.twig #}
{% extends '@SolidInvoiceInvoice/Templates/_pdf_base.html.twig' %}

{% block extra_styles %}
    body { font-family: helvetica, Arial, sans-serif; color: #1e293b; }
{% endblock %}

{% block body %}
    {# 自定义布局内容 #}
{% endblock %}
```

**模板测试验证：** [TemplatesRenderingTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php#L65-L127)

---

## 四、样式系统：SCSS → CSS → 内联

### 4.1 样式编译链路

```
assets/scss/pdf.scss (源文件)
       │
       ▼  Webpack Encore 编译
       │  (webpack.config.js #L9)
       ▼
public/static/pdf.css (编译输出)
       │
       ▼  Twig file() 函数读取内联
       │  (invoice.html.twig #L17)
       ▼
<style type="text/css">
    /* pdf.css 全部内容直接嵌入 */
</style>
```

**Webpack 配置：** [webpack.config.js](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/webpack.config.js#L9)

```javascript
.addStyleEntry('pdf', './assets/scss/pdf.scss')
```

### 4.2 PDF 样式源文件

**代码位置：** [assets/scss/pdf.scss](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/assets/scss/pdf.scss)

**mPDF CSS 限制（重要！）：**

```scss
/**
 * IMPORTANT: mPDF has limited CSS support:
 * - No CSS custom properties (var(--xxx))
 * - No flexbox or grid
 * - Limited support for nested selectors
 * - Best results with simple class selectors and inline styles
 */
```

**样式组成部分：**

| 部分 | 说明 |
|------|------|
| 基础样式 | body、h1-h4、p、a 等标签默认样式 |
| 工具类 | .text-right、.text-muted、.text-bold 等 |
| 头部表格 | .invoice-header-table、.header-left/right |
| 明细表格 | .items-table、thead、tbody 行样式 |
| 总计表格 | .totals-table、.total-row、.balance-row |
| 条款区域 | .terms-section、.terms-title |

**颜色值镜像设计系统：**

由于 mPDF 不支持 CSS 变量，`pdf.scss` 中的颜色值是从设计系统 `_tokens.scss` 镜像复制的硬编码值：

| 用途 | 颜色值 | 对应 Token |
|------|--------|------------|
| 主色 | `#2e963a` | `--swp-primary` |
| 成功色 | `#10b981` | `--swp-success` |
| 危险色 | `#ef4444` | `--swp-danger` |
| 警告色 | `#f59e0b` | `--swp-warning` |
| 主文本 | `#1e293b` | `--swp-gray-800` |
| 次要文本 | `#64748b` | `--swp-gray-500` |

### 4.3 样式叠加顺序

1. **基础层：** `pdf.css` 中的标签默认样式
2. **类选择器层：** `.invoice-header-table`、`.totals-table` 等类样式
3. **行内样式层：** Twig 模板中的 `style=""` 属性（优先级最高）

**示例：**

```twig
{# 类样式 + 行内样式叠加 #}
<td class="header-left" style="width: 50%; vertical-align: top;">
```

---

## 五、字体配置

### 5.1 字体栈

**CSS 字体声明（pdf.scss #L20）：**

```scss
body {
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
}
```

**mPDF 默认字体配置（Generator.php #L45）：**

```php
$mpdf = new Mpdf([
    'default_font' => 'helvetica',
    // ...
]);
```

### 5.2 mPDF 内置字体

mPDF 自带以下核心字体，无需额外安装：

- `helvetica` / `Helvetica` - 无衬线字体（默认使用）
- `times` / `Times` - 衬线字体
- `courier` / `Courier` - 等宽字体
- `dejavusans` / `DejaVu Sans` - 支持更多 Unicode 字符

**特殊字体使用：** 金额列使用等宽字体保证对齐

```twig
<td style="font-family: 'Courier New', monospace;">
    {{ line.total|formatCurrency(currency) }}
</td>
```

---

## 六、PDF 生成核心：Generator 类

**代码位置：** [CoreBundle/Pdf/Generator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Pdf/Generator.php)

### 6.1 依赖要求

```php
public function canPrintPdf(): bool
{
    return \extension_loaded('mbstring') && \extension_loaded('gd');
}
```

### 6.2 mPDF 配置参数

```php
$mpdf = new Mpdf([
    'tempDir' => $this->cacheDir . '/pdf',       // 临时目录
    'margin_left' => 15,                          // 左边距 (mm)
    'margin_right' => 15,                         // 右边距 (mm)
    'margin_top' => 20,                           // 上边距 (mm)
    'margin_bottom' => 25,                        // 下边距 (mm)
    'margin_header' => 10,                        // 页眉距 (mm)
    'margin_footer' => 10,                        // 页脚距 (mm)
    'default_font' => 'helvetica',                // 默认字体
]);
```

### 6.3 渲染流程

```php
// 1. 配置 mPDF 实例
$mpdf->allow_charset_conversion = false;      // 禁用字符集转换
$mpdf->showWatermarkText = true;              // 显示文本水印
$mpdf->SetDisplayMode('fullpage');            // 全屏显示模式
$mpdf->SetProtection(['print']);              // 仅允许打印
$mpdf->setLogger($this->logger);              // 设置日志

// 2. 写入 HTML（触发 CSS 解析、布局计算、字体渲染）
$mpdf->WriteHTML($html);

// 3. 输出 PDF 二进制字符串
return $mpdf->Output(null, Destination::STRING_RETURN);
```

### 6.4 mPDF 自定义标签支持

模板中使用的 mPDF 专有标签：

| 标签 | 用途 | 示例 |
|------|------|------|
| `<watermarktext>` | 文本水印 | `<watermarktext content="PENDING" alpha="0.08"/>` |
| `<pagefooter>` | 页面页脚 | `<pagefooter name="footer" content-right="Page {PAGENO}"/>` |
| `{PAGENO}` | 当前页码 | 页脚中使用 |
| `{nb}` | 总页数 | 页脚中使用 |

---

## 七、响应封装：PdfResponse 类

**代码位置：** [CoreBundle/Response/PdfResponse.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Response/PdfResponse.php)

```php
public function __construct(
    string $content,          // PDF 二进制内容
    string $fileName,         // 文件名（如 invoice_INV-001.pdf）
    string $contentDisposition = ResponseHeaderBag::DISPOSITION_INLINE,
    int $status = Response::HTTP_OK,
    array $headers = []
) {
    parent::__construct($content, $status, $headers);
    $this->headers->add(['Content-Type' => 'application/pdf']);
    $this->headers->add(['Content-Disposition' => $this->headers->makeDisposition($contentDisposition, $fileName)]);
}
```

**Content-Disposition 两种模式：**

- `DISPOSITION_INLINE` - 浏览器内联预览（默认）
- `DISPOSITION_ATTACHMENT` - 强制下载

---

## 八、关键数据注入

### 8.1 动态数据来源

| 数据类型 | 来源 | 模板变量 |
|----------|------|----------|
| 发票/报价数据 | Doctrine 实体 | `invoice` / `quote` |
| 公司信息 | 设置系统 | `setting('system/company/*')` |
| 税标识 | Twig 函数 | `tax_identifiers()` |
| 货币格式化 | Twig 过滤器 | `|formatCurrency(currency)` |
| 自定义字段 | Twig 组件 | `<twig:CustomFieldsListPdf>` |

### 8.2 条件渲染

- **水印显示：** `setting('invoice/watermark')` 控制
- **支付按钮：** `payments_configured(false) > 0` 且发票未付款
- **Powered by 页脚：** `feature_enabled('custom_branding')` 控制
- **余额/总计高亮：** 根据付款状态动态切换

---

## 九、完整调用链（以发票 PDF 为例）

```
1. 用户访问 /invoices/{id}.pdf
   │
   ▼ 路由匹配
2. InvoiceBundle\Action\View::__invoke()
   ├─► 检查请求格式：'pdf' === $request->getRequestFormat()
   ├─► 检查 PDF 能力：$this->pdfGenerator->canPrintPdf()
   │
   ▼
3. Twig 渲染：@SolidInvoiceInvoice/Pdf/invoice.html.twig
   ├─► 读取 static/pdf.css 内联到 <style>
   ├─► 注入 invoice 实体数据
   ├─► 调用 Twig 函数/过滤器格式化数据
   │
   ▼
4. 生成完整 HTML 字符串
   │
   ▼
5. Generator::generate($html)
   ├─► 实例化 mPDF
   ├─► 配置页面参数、字体、权限
   ├─► WriteHTML() 解析 HTML+CSS
   ├─► 输出 PDF 二进制
   │
   ▼
6. new PdfResponse($pdfContent, 'invoice_INV-001.pdf')
   ├─► Content-Type: application/pdf
   ├─► Content-Disposition: inline; filename="..."
   │
   ▼
7. 返回 HTTP 响应给浏览器
```

---

## 十、调试与测试

### 10.1 单元测试

**Generator 测试：** [CoreBundle/Tests/Pdf/GeneratorTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Tests/Pdf/GeneratorTest.php)

**PdfResponse 测试：** [CoreBundle/Tests/Response/PdfResponseTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Tests/Response/PdfResponseTest.php)

### 10.2 功能测试

**模板渲染测试：** [TemplatesRenderingTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php)

```php
// 测试所有 8 个模板 × 3 个通道（pdf/email/preview）
public function testTemplateRenders(string $slug, string $channel): void
{
    $output = $twig->render(
        sprintf('@SolidInvoiceInvoice/Templates/%s/%s.html.twig', $slug, $channel),
        ['invoice' => $invoice]
    );
    // 断言输出包含必要内容
}

// 测试 PDF 实际生成
public function testPdfTemplateGenerates(string $slug): void
{
    $html = $twig->render(sprintf('@SolidInvoiceInvoice/Templates/%s/pdf.html.twig', $slug), ['invoice' => $invoice]);
    $pdf = $generator->generate($html);
    self::assertStringStartsWith('%PDF-', $pdf);
}
```

---

## 十一、常见问题与注意事项

### 11.1 mPDF CSS 限制

- ❌ 不支持 CSS 自定义属性 `var(--xxx)`
- ❌ 不支持 Flexbox 和 Grid 布局
- ❌ 不支持复杂的 CSS 选择器嵌套
- ✅ 支持简单类选择器、标签选择器
- ✅ 支持 `style=""` 行内样式（优先级最高）

### 11.2 中文/多语言支持

当前默认字体 `helvetica` 对中文支持有限。如需支持中文：

1. 安装中文字体文件到 mPDF 字体目录
2. 修改 `Generator.php` 中的 `default_font` 配置
3. 或在模板 CSS 中指定支持中文的字体

### 11.3 缓存与性能

- mPDF 临时文件目录：`%kernel.cache_dir%/pdf`
- 建议定期清理临时目录
- 大文件或批量生成时考虑队列异步处理

### 11.4 安全性

```php
// PDF 设置了仅允许打印权限
$mpdf->SetProtection(['print']);
```

用户可以查看和打印 PDF，但不能编辑、复制内容或修改注释。

---

## 十二、关键文件索引

| 功能 | 文件路径 |
|------|----------|
| PDF 生成器 | [src/CoreBundle/Pdf/Generator.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Pdf/Generator.php) |
| PDF 响应类 | [src/CoreBundle/Response/PdfResponse.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Response/PdfResponse.php) |
| 发票 PDF 模板 | [src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig) |
| 报价 PDF 模板 | [src/QuoteBundle/Resources/views/Pdf/quote.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/QuoteBundle/Resources/views/Pdf/quote.html.twig) |
| PDF 样式源 | [assets/scss/pdf.scss](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/assets/scss/pdf.scss) |
| Webpack 配置 | [webpack.config.js](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/webpack.config.js) |
| 发票查看 Action | [src/InvoiceBundle/Action/View.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Action/View.php) |
| 外部查看 Action | [src/CoreBundle/Action/ViewBilling.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/CoreBundle/Action/ViewBilling.php) |
| 邮件 PDF 监听器 | [src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php) |
| 可选模板目录 | [src/InvoiceBundle/Resources/views/Templates/](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/) |
| 模板基础类 | [src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Resources/views/Templates/_pdf_base.html.twig) |
| 模板渲染测试 | [src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/29-SolidInvoice/src/InvoiceBundle/Tests/Functional/Templates/TemplatesRenderingTest.php) |
