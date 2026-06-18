# PDF 模板生成完整代码链路

本文档梳理 SolidInvoice 中 **发票（Invoice）** 和 **报价（Quote）** 两条 PDF 生成链路，覆盖从模板选择、入口触发到最终打印输出的完整代码流程。所有结论均附有代码位置证据，可在仓库中直接验证。

**相对路径说明**：所有路径均相对于项目根目录。

---

## 一、模板选择逻辑：发票与报价各有硬编码的默认模板

### 1.1 关键结论

**所有生产代码（Action、Listener）均硬编码引用各自的默认 PDF 模板，不存在运行时动态选择模板的机制。** 8 个可选预设模板（classic、modern、compact 等）目前仅在测试中使用，不进入实际运行路径。

### 1.2 默认模板的引用点（发票 × 报价 全量对照）

验证命令：
```bash
grep -rn "@SolidInvoiceInvoice/Pdf\|@SolidInvoiceQuote/Pdf" src/ --include="*.php" | grep -v "Tests/"
```

**发票（Invoice）的 3 个入口**：

| 入口类型 | 模板路径 | 代码位置 | 行号 |
|----------|----------|----------|------|
| 登录用户查看 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `src/InvoiceBundle/Action/View.php` | 52 |
| 外部链接查看 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `src/CoreBundle/Action/ViewBilling.php` | 90 |
| 邮件附件 | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php` | 47 |

**报价（Quote）的 3 个入口**：

| 入口类型 | 模板路径 | 代码位置 | 行号 |
|----------|----------|----------|------|
| 登录用户查看 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | `src/QuoteBundle/Action/View.php` | 46 |
| 外部链接查看 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | `src/CoreBundle/Action/ViewBilling.php` | 69 |
| 邮件附件 | `@SolidInvoiceQuote/Pdf/quote.html.twig` | `src/QuoteBundle/Listener/Mailer/QuotePdfListener.php` | 47 |

**验证结果**：没有通过设置系统或数据库读取模板名的逻辑。发票 3 个入口统一指向 `invoice.html.twig`，报价 3 个入口统一指向 `quote.html.twig`。

### 1.3 可选预设模板的状态（测试专用）

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

**结论**：可选预设模板是为未来模板选择功能预制的，当前版本尚未接入运行路径。注意：报价目前没有对应的 `Templates/` 目录，只有发票有。

---

## 二、入口一：浏览器打印（发票 × 报价 并列）

### 2.1 路由配置对照

验证命令：
```bash
grep -n "_invoices_view\|_quotes_view\|_view_invoice_external\|_view_quote_external\|_format" \
  src/InvoiceBundle/Resources/config/routing.php \
  src/QuoteBundle/Resources/config/routing.php \
  src/CoreBundle/Resources/config/routing.php
```

| 维度 | 发票（Invoice） | 报价（Quote） |
|------|-----------------|----------------|
| **路由名** | `_invoices_view` | `_quotes_view` |
| **路由配置文件** | `src/InvoiceBundle/Resources/config/routing.php` | `src/QuoteBundle/Resources/config/routing.php` |
| **路由行号** | 第63-66行 | 第43-46行 |
| **路由路径** | `/view/{id}.{_format}` | `/view/{id}.{_format}` |
| **支持格式** | `html \| pdf` | `html \| pdf` |
| **默认格式** | `html` | `html` |
| **外部路由名** | `_view_invoice_external` | `_view_quote_external` |
| **外部路由路径** | `/view/invoice/{uuid}.{_format}` | `/view/quote/{uuid}.{_format}` |
| **外部路由文件** | `src/CoreBundle/Resources/config/routing.php` | `src/CoreBundle/Resources/config/routing.php` |
| **外部路由行号** | 第44-47行 | 第38-42行 |

**路由配置代码：**

```php
// 发票路由（src/InvoiceBundle/Resources/config/routing.php 第63-66行）
$routingConfigurator
    ->add('_invoices_view', '/view/{id}.{_format}')
    ->controller(\SolidInvoice\InvoiceBundle\Action\View::class)
    ->defaults(['_format' => 'html'])
    ->requirements(['_format' => 'html|pdf']);

// 报价路由（src/QuoteBundle/Resources/config/routing.php 第43-46行）
$routingConfigurator
    ->add('_quotes_view', '/view/{id}.{_format}')
    ->controller(\SolidInvoice\QuoteBundle\Action\View::class)
    ->defaults(['_format' => 'html'])
    ->requirements(['_format' => 'html|pdf']);
```

### 2.2 登录用户 View Action 对照

| 维度 | 发票 | 报价 |
|------|------|------|
| **文件** | `src/InvoiceBundle/Action/View.php` | `src/QuoteBundle/Action/View.php` |
| **类名** | `SolidInvoice\InvoiceBundle\Action\View` | `SolidInvoice\QuoteBundle\Action\View` |
| **实体参数** | `Invoice $invoice` | `Quote $quote` |
| **PDF 判断行号** | 第51行 | 第45行 |
| **判断条件** | `'pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()` | 同左 |
| **渲染模板** | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `@SolidInvoiceQuote/Pdf/quote.html.twig` |
| **模板变量名** | `['invoice' => $invoice]` | `['quote' => $quote]` |
| **文件名格式** | `invoice_{invoiceId}.pdf` | `quote_{quoteId}.pdf` |
| **实体 ID 方法** | `$invoice->getInvoiceId()` | `$quote->getQuoteId()` |
| **HTML 模板** | `@SolidInvoiceInvoice/Default/view.html.twig` | `@SolidInvoiceQuote/Default/view.html.twig` |
| **额外依赖** | `PaymentRepository`（获取付款记录） | 无 |

**发票 View 代码**（第51-52行）：
```php
if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
    return new PdfResponse($this->pdfGenerator->generate(
        $this->twig->render('@SolidInvoiceInvoice/Pdf/invoice.html.twig', ['invoice' => $invoice])
    ), sprintf('invoice_%s.pdf', $invoice->getInvoiceId()));
}
```

**报价 View 代码**（第45-46行）：
```php
if ('pdf' === $request->getRequestFormat() && $this->pdfGenerator->canPrintPdf()) {
    return new PdfResponse($this->pdfGenerator->generate(
        $this->engine->render('@SolidInvoiceQuote/Pdf/quote.html.twig', ['quote' => $quote])
    ), sprintf('quote_%s.pdf', $quote->getQuoteId()));
}
```

### 2.3 外部链接 ViewBilling 入口对照

两个入口共用同一个 Action 类 `src/CoreBundle/Action/ViewBilling.php`，通过不同方法分发：

| 维度 | 发票外部入口 | 报价外部入口 |
|------|-------------|-------------|
| **方法名** | `invoiceAction()` | `quoteAction()` |
| **行号** | 第82-94行 | 第61-73行 |
| **路由名** | `_view_invoice_external` | `_view_quote_external` |
| **HTML 模板** | `@SolidInvoiceCore/View/invoice.html.twig` | `@SolidInvoiceCore/View/quote.html.twig` |
| **Repository** | `Invoice::class` | `Quote::class` |
| **重定向路由** | `_invoices_view` | `_quotes_view` |
| **HTML 视图模板** | `@SolidInvoiceInvoice/external_invoice_view.html.twig` | `@SolidInvoiceQuote/quote_template.html.twig` |
| **PDF 模板** | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `@SolidInvoiceQuote/Pdf/quote.html.twig` |
| **实体变量名** | `'invoice'` | `'quote'` |
| **ID 获取方法** | `$entity->getInvoiceId()` | `$entity->getQuoteId()` |

**代码结构**（`ViewBilling.php`）：

```php
// 报价（第61-73行）
#[Template('@SolidInvoiceCore/View/quote.html.twig')]
public function quoteAction(Request $request, string $uuid): array|Response
{
    $options = [
        'repository' => Quote::class,
        'route' => '_quotes_view',
        'template' => '@SolidInvoiceQuote/quote_template.html.twig',
        'uuid' => $uuid,
        'entity' => 'quote',
        'pdfTemplate' => '@SolidInvoiceQuote/Pdf/quote.html.twig',
    ];
    return $this->createResponse($request, $options);
}

// 发票（第82-94行）
#[Template('@SolidInvoiceCore/View/invoice.html.twig')]
public function invoiceAction(Request $request, string $uuid): array|Response
{
    $options = [
        'repository' => Invoice::class,
        'route' => '_invoices_view',
        'template' => '@SolidInvoiceInvoice/external_invoice_view.html.twig',
        'uuid' => $uuid,
        'entity' => 'invoice',
        'pdfTemplate' => '@SolidInvoiceInvoice/Pdf/invoice.html.twig',
    ];
    return $this->createResponse($request, $options);
}

// 共用处理逻辑（第101-145行）
private function createResponse(Request $request, array $options): array|Response
{
    // 1. 根据 UUID 查询实体
    // 2. 邮件验证门控检查
    // 3. 已登录用户重定向到内部路由
    // 4. 切换公司上下文（多租户）
    // 5. PDF 格式处理：渲染 $options['pdfTemplate']，返回 PdfResponse
    // 6. 非 PDF 格式：返回数组供 HTML 模板渲染
}
```

### 2.4 浏览器入口调用链对比图

```
               发票                                           报价
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ /invoices/view/{id}.pdf │               │  /quotes/view/{id}.pdf  │
  │ 或                      │               │ 或                      │
  │ /view/invoice/{uuid}.pdf│               │ /view/quote/{uuid}.pdf  │
  └────────────┬────────────┘               └────────────┬────────────┘
               │                                          │
               ▼                                          ▼
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ InvoiceBundle/          │               │ QuoteBundle/            │
  │ Action/View.php         │               │ Action/View.php         │
  │ 或                      │               │ 或                      │
  │ CoreBundle/Action/      │               │ CoreBundle/Action/      │
  │ ViewBilling.php         │               │ ViewBilling.php         │
  │                         │               │                         │
  │ 第51行: _format='pdf'   │               │ 第45行: _format='pdf'   │
  │ && canPrintPdf()        │               │ && canPrintPdf()        │
  └────────────┬────────────┘               └────────────┬────────────┘
               │                                          │
               ▼                                          ▼
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ Twig 渲染               │               │ Twig 渲染               │
  │ @SolidInvoiceInvoice/   │               │ @SolidInvoiceQuote/     │
  │ Pdf/invoice.html.twig   │               │ Pdf/quote.html.twig     │
  │ ['invoice' => $invoice] │               │ ['quote' => $quote]     │
  └────────────┬────────────┘               └────────────┬────────────┘
               │                                          │
               ▼                                          ▼
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ new PdfResponse(        │               │ new PdfResponse(        │
  │   $pdfBinary,           │               │   $pdfBinary,           │
  │   "invoice_{id}.pdf"    │               │   "quote_{id}.pdf"      │
  │ )                       │               │ )                       │
  └─────────────────────────┘               └─────────────────────────┘
```

---

## 三、入口二：邮件附件（发票 × 报价 并列）

### 3.1 邮件监听器对照

验证命令：
```bash
grep -n "MessageEvent\|getSubscribedEvents\|__invoke\|instanceof" \
  src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php \
  src/QuoteBundle/Listener/Mailer/QuotePdfListener.php
```

| 维度 | 发票邮件监听器 | 报价邮件监听器 |
|------|--------------|--------------|
| **文件** | `src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php` | `src/QuoteBundle/Listener/Mailer/QuotePdfListener.php` |
| **类名** | `InvoicePdfListener` | `QuotePdfListener` |
| **事件** | `MessageEvent` | `MessageEvent` |
| **订阅方法行号** | 第57-61行 | 第57-62行 |
| **instanceof 检查** | `$message instanceof InvoiceEmail` | `$message instanceof QuoteEmail` |
| **instanceof 行号** | 第45行 | 第45行 |
| **PDF 模板** | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `@SolidInvoiceQuote/Pdf/quote.html.twig` |
| **模板变量名** | `['invoice' => $message->getInvoice()]` | `['quote' => $message->getQuote()]` |
| **文件名格式** | `invoice_{invoiceId}.pdf` | `quote_{quoteId}.pdf` |
| **MIME 类型** | `application/pdf` | `application/pdf` |

**事件订阅注册**（两者完全相同）：
```php
public static function getSubscribedEvents(): array
{
    return [MessageEvent::class => '__invoke'];
}
```

### 3.2 邮件监听器核心代码对照

**发票监听器**（`InvoicePdfListener.php` 第40-51行）：
```php
public function __invoke(MessageEvent $event): void
{
    /** @var InvoiceEmail $message */
    $message = $event->getMessage();

    if ($message instanceof InvoiceEmail && $this->generator->canPrintPdf()) {
        $content = $this->generator->generate(
            $this->twig->render('@SolidInvoiceInvoice/Pdf/invoice.html.twig', ['invoice' => $message->getInvoice()])
        );
        $message->attach($content, sprintf('invoice_%s.pdf', $message->getInvoice()->getInvoiceId()), 'application/pdf');
    }
}
```

**报价监听器**（`QuotePdfListener.php` 第40-51行）：
```php
public function __invoke(MessageEvent $event): void
{
    /** @var QuoteEmail $message */
    $message = $event->getMessage();

    if ($message instanceof QuoteEmail && $this->generator->canPrintPdf()) {
        $content = $this->generator->generate(
            $this->twig->render('@SolidInvoiceQuote/Pdf/quote.html.twig', ['quote' => $message->getQuote()])
        );
        $message->attach($content, sprintf('quote_%s.pdf', $message->getQuote()->getQuoteId()), 'application/pdf');
    }
}
```

### 3.3 邮件入口调用链对比图

```
               发票                                           报价
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ $mailer->send(          │               │ $mailer->send(          │
  │   new InvoiceEmail(     │               │   new QuoteEmail(       │
  │     $invoice            │               │     $quote              │
  │   )                     │               │   )                     │
  │ )                       │               │ )                       │
  └────────────┬────────────┘               └────────────┬────────────┘
               │                                          │
               ▼                                          ▼
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ Symfony Mailer 分发      │               │ Symfony Mailer 分发      │
  │ MessageEvent 事件        │               │ MessageEvent 事件        │
  └────────────┬────────────┘               └────────────┬────────────┘
               │                                          │
               ▼                                          ▼
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ InvoicePdfListener/     │               │ QuotePdfListener/       │
  │ __invoke()              │               │ __invoke()              │
  │                         │               │                         │
  │ instanceof InvoiceEmail │               │ instanceof QuoteEmail   │
  │ && canPrintPdf()        │               │ && canPrintPdf()        │
  └────────────┬────────────┘               └────────────┬────────────┘
               │                                          │
               ▼                                          ▼
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ Twig 渲染               │               │ Twig 渲染               │
  │ @SolidInvoiceInvoice/   │               │ @SolidInvoiceQuote/     │
  │ Pdf/invoice.html.twig   │               │ Pdf/quote.html.twig     │
  └────────────┬────────────┘               └────────────┬────────────┘
               │                                          │
               ▼                                          ▼
  ┌─────────────────────────┐               ┌─────────────────────────┐
  │ $message->attach(       │               │ $message->attach(       │
  │   $pdfBinary,           │               │   $pdfBinary,           │
  │   "invoice_{id}.pdf",   │               │   "quote_{id}.pdf",     │
  │   "application/pdf"     │               │   "application/pdf"     │
  │ )                       │               │ )                       │
  └─────────────────────────┘               └─────────────────────────┘
```

---

## 四、两类入口 × 两种单据的完整对照表

| | **浏览器打印-发票** | **浏览器打印-报价** | **邮件附件-发票** | **邮件附件-报价** |
|---|---|---|---|---|
| **触发方式** | URL `.pdf` 后缀 | URL `.pdf` 后缀 | `MessageEvent` 事件 | `MessageEvent` 事件 |
| **触发时机** | 用户主动访问 | 用户主动访问 | 发送邮件时自动 | 发送邮件时自动 |
| **入口类** | `InvoiceBundle/Action/View` 或 `CoreBundle/Action/ViewBilling` | `QuoteBundle/Action/View` 或 `CoreBundle/Action/ViewBilling` | `InvoicePdfListener` | `QuotePdfListener` |
| **入口文件** | `src/InvoiceBundle/Action/View.php` <br> `src/CoreBundle/Action/ViewBilling.php` | `src/QuoteBundle/Action/View.php` <br> `src/CoreBundle/Action/ViewBilling.php` | `src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php` | `src/QuoteBundle/Listener/Mailer/QuotePdfListener.php` |
| **判断条件** | `_format='pdf'` <br> `&& canPrintPdf()` | `_format='pdf'` <br> `&& canPrintPdf()` | `instanceof InvoiceEmail` <br> `&& canPrintPdf()` | `instanceof QuoteEmail` <br> `&& canPrintPdf()` |
| **判断行号** | 第51行（内部） <br> 第133行（外部） | 第45行（内部） <br> 第133行（外部） | 第45行 | 第45行 |
| **PDF 模板** | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `@SolidInvoiceQuote/Pdf/quote.html.twig` | `@SolidInvoiceInvoice/Pdf/invoice.html.twig` | `@SolidInvoiceQuote/Pdf/quote.html.twig` |
| **模板文件** | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | `src/QuoteBundle/Resources/views/Pdf/quote.html.twig` | 同左 | 同左 |
| **模板变量名** | `'invoice'` | `'quote'` | `'invoice'` | `'quote'` |
| **文件名** | `invoice_{id}.pdf` | `quote_{id}.pdf` | `invoice_{id}.pdf` | `quote_{id}.pdf` |
| **ID 方法** | `$invoice->getInvoiceId()` | `$quote->getQuoteId()` | `$invoice->getInvoiceId()` | `$quote->getQuoteId()` |
| **输出方式** | `PdfResponse` | `PdfResponse` | `$message->attach()` | `$message->attach()` |
| **Content-Disposition** | `inline`（浏览器内预览） | `inline` | 无（邮件附件下载） | 无 |
| **MIME 类型** | `application/pdf` | `application/pdf` | `application/pdf` | `application/pdf` |
| **公司上下文** | 外部入口需 `switchCompany()` | 外部入口需 `switchCompany()` | 已在正确上下文 | 已在正确上下文 |
| **额外依赖** | `PaymentRepository`（仅内部入口） | 无 | 无 | 无 |

---

## 五、默认模板结构（发票 × 报价 对照）

### 5.1 发票 PDF 模板

**文件**：`src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig`

该模板是**自包含的完整 HTML 文档**，不继承任何基础模板：

```twig
{% set currency = invoice.client.currency %}
{% set hasOutstandingBalance = invoice.payments|length > 0 and not invoice.balance.zero %}

<html>
<head>
    <style type="text/css">
        {{ file(asset('static/pdf.css')) }}          {# ← CSS 内联注入 #}
        @page {
            margin-top: 20mm; margin-bottom: 25mm;
            margin-left: 15mm; margin-right: 15mm;
            footer: footer;
        }
    </style>
</head>
<body>
    {% if setting('invoice/watermark') %}
        <watermarktext content="{{ invoice.status.value|upper }}" alpha="0.08"/>
    {% endif %}
    <pagefooter name="footer" content-right="Page {PAGENO} of {nb}" ... />

    {# 公司信息 + 发票元数据头部 #}
    {# 客户信息（Bill To） #}
    {# 明细行表格 #}
    {# 自定义字段组件 #}
    {# 总计汇总表（含余额、付款信息） #}
    {# 支付链接区块 #}
    {# 条款 #}
</body>
</html>
```

### 5.2 报价 PDF 模板

**文件**：`src/QuoteBundle/Resources/views/Pdf/quote.html.twig`

结构与发票模板完全平行，关键差异：

| 差异点 | 发票模板 | 报价模板 |
|--------|----------|----------|
| **变量** | `invoice` | `quote` |
| **标题 key** | `'invoice.pdf.title'` → "INVOICE" | `'quote.pdf.title'` → "QUOTE" |
| **客户标签** | "Invoice To" | `'quote.pdf.prepared_for'` → "Prepared For" |
| **水印设置 key** | `setting('invoice/watermark')` | `setting('quote/watermark')` |
| **主色调** | 绿色 `#2e963a` | 蓝色 `#3b82f6` |
| **总计背景色** | `#f0fdf4`（浅绿） | `#eff6ff`（浅蓝） |
| **总计文字色** | `#166534`（深绿） | `#1d4ed8`（深蓝） |
| **标签下划线色** | `#2e963a`（绿） | `#3b82f6`（蓝） |
| **日期字段** | 开票日期 + 到期日 | 报价日期 + 有效期（`valid_until`） |
| **有效期标识** | 无 | `isExpired` / `expires_today` / `valid_for_days` |
| **余额行** | 有（支持部分付款） | 无 |
| **付款记录** | 有（已付金额、未付余额） | 无 |
| **支付 CTA 区块** | 有 | 无 |
| **ID 字段** | `#{{ invoice.invoiceId }}` | `#{{ quote.quoteId }}` |
| **ID 方法** | `invoice.getInvoiceId()` | `quote.getQuoteId()` |
| **自定义字段 Target** | `CustomFieldTarget::INVOICE` | `CustomFieldTarget::QUOTE` |

### 5.3 两者的共同结构

发票和报价模板共享相同的基础骨架：

| 共同部分 | 说明 |
|----------|------|
| `file(asset('static/pdf.css'))` | CSS 内联注入（样式机制完全相同） |
| `@page` 边距设置 | 20mm 上、25mm 下、15mm 左右，页脚名 `footer` |
| `<watermarktext>` | 状态水印，alpha=0.08 |
| `<pagefooter>` | 页脚含 "Powered by SolidInvoice" + 页码 `{PAGENO} of {nb}` |
| 公司信息区 | Logo + 公司名 + 税标识 + 联系方式 + 地址 |
| 客户信息区 | 客户名 + 税标识 + 地址 + 主要联系人邮箱 |
| 明细表格 | 描述 / 单价 / 数量 / 税额 / 合计 五列（税额列为条件渲染） |
| 自定义字段 | `<twig:CustomFieldsListPdf>` 组件渲染 |
| 总计汇总 | 小计 + 税务明细 + 折扣 + 合计（行内等宽字体） |
| 条款 | `terms` 字段（条件渲染） |
| 字体 | `'Courier New', monospace` 用于金额列（行内样式） |

---

## 六、样式如何进入 mPDF（发票报价共用）

### 6.1 完整链路（可验证）

发票和报价模板使用完全相同的样式注入机制。

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
       │  ② Twig asset() 生成 public 路径
       │     asset('static/pdf.css') → '/static/pdf.css'
       │
       │  ③ Twig file() 读取文件内容
       │     src/CoreBundle/Twig/Extension/FileExtension.php 第34行:
       │     file_get_contents($this->projectDir . '/public/' . ltrim($file, '/'))
       │
       ▼
{{ file(asset('static/pdf.css')) }}
       │
       │  ④ 嵌入 <style> 标签（两个模板的第16/17行）
       │
       ▼
<style type="text/css">
    /* pdf.css 全部内容原样嵌入 */
</style>
       │
       │  ⑤ 传入 Generator::generate($html)
       │
       ▼
$mpdf->WriteHTML($html)
       │
       │  ⑥ mPDF 解析 <style> 中的 CSS 规则
       │
       ▼
PDF 输出
```

### 6.2 样式叠加的三个层次

| 层次 | 发票模板 | 报价模板 | 是否相同 |
|------|----------|----------|----------|
| **1. `pdf.css` 全局样式** | `{{ file(asset('static/pdf.css')) }}`（第17行） | 同（第16行） | ✅ 完全相同 |
| **2. `@page` 规则** | `margin-top: 20mm; margin-bottom: 25mm; margin-left: 15mm; margin-right: 15mm; footer: footer;` | 同 | ✅ 完全相同 |
| **3. 行内 `style=""`** | 大量颜色、字体、间距声明 | 类似但颜色值不同（绿 vs 蓝） | ⚠️ 结构相同，颜色不同 |

**行内样式的颜色差异**：

| 用途 | 发票（绿） | 报价（蓝） |
|------|-----------|-----------|
| 主色 | `#2e963a` | `#3b82f6` |
| 总计背景 | `#f0fdf4` | `#eff6ff` |
| 总计文字 | `#166534` | `#1d4ed8` |
| 标签下划线 | `#2e963a` | `#3b82f6` |

### 6.3 代码证据汇总

| 环节 | 验证命令 | 代码位置 | 行号 |
|------|---------|----------|------|
| Webpack 编译入口 | `grep -n "addStyleEntry.*pdf" webpack.config.js` | `webpack.config.js` | 9 |
| 发票模板内联 CSS | `grep -n "file(asset.*pdf" src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` | 17 |
| 报价模板内联 CSS | `grep -n "file(asset.*pdf" src/QuoteBundle/Resources/views/Pdf/quote.html.twig` | `src/QuoteBundle/Resources/views/Pdf/quote.html.twig` | 16 |
| file() 函数实现 | `grep -n "file_get_contents\|is_safe" src/CoreBundle/Twig/Extension/FileExtension.php` | `src/CoreBundle/Twig/Extension/FileExtension.php` | 34 |

---

## 七、字体如何进入 mPDF（发票报价共用）

### 7.1 字体指定的三个位置

发票和报价使用完全相同的字体机制。

**路径 1：mPDF `default_font` 参数**（`src/CoreBundle/Pdf/Generator.php` 第45行）：
```php
$mpdf = new Mpdf([
    'default_font' => 'helvetica',  // 全局回退字体
]);
```

**路径 2：`pdf.scss` CSS `font-family`**（`assets/scss/pdf.scss` 第20行）：
```scss
body {
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
}
```

**路径 3：行内样式覆盖**（两个模板的金额列）：
```twig
<td style="font-family: 'Courier New', monospace;">
    {{ line.total|formatCurrency(currency) }}
</td>
```

或通过 CSS 类（`pdf.scss` 第312行）：
```scss
.totals-table .value-cell {
    font-family: 'Courier New', monospace;
}
```

### 7.2 字体路径对照

| 位置 | 发票 | 报价 | 是否相同 |
|------|------|------|----------|
| default_font | `helvetica` | `helvetica` | ✅ |
| body font-family | `'Helvetica Neue', Helvetica, Arial, sans-serif` | 同 | ✅ |
| 金额列 font-family | `'Courier New', monospace` | 同 | ✅ |

### 7.3 代码证据汇总

| 环节 | 验证命令 | 代码位置 | 行号 |
|------|---------|----------|------|
| default_font 配置 | `grep -n "default_font" src/CoreBundle/Pdf/Generator.php` | `src/CoreBundle/Pdf/Generator.php` | 45 |
| CSS body 字体 | `grep -n "font-family" assets/scss/pdf.scss \| head -1` | `assets/scss/pdf.scss` | 20 |
| 金额等宽字体 | `grep -n "font-family.*Courier" assets/scss/pdf.scss \| head -1` | `assets/scss/pdf.scss` | 312 |
| WriteHTML 调用 | `grep -n "WriteHTML" src/CoreBundle/Pdf/Generator.php` | `src/CoreBundle/Pdf/Generator.php` | 53 |

---

## 八、mPDF 渲染核心（发票报价共用）

**文件**：`src/CoreBundle/Pdf/Generator.php`

两个单据共用同一个 Generator 类，配置完全相同：

```php
public function canPrintPdf(): bool
{
    return \extension_loaded('mbstring') && \extension_loaded('gd');
}

$mpdf = new Mpdf([
    'tempDir' => $this->cacheDir . '/pdf',
    'margin_left' => 15,
    'margin_right' => 15,
    'margin_top' => 20,
    'margin_bottom' => 25,
    'margin_header' => 10,
    'margin_footer' => 10,
    'default_font' => 'helvetica',
]);

$mpdf->allow_charset_conversion = false;
$mpdf->showWatermarkText = true;
$mpdf->SetDisplayMode('fullpage');
$mpdf->SetProtection(['print']);
$mpdf->setLogger($this->logger);
$mpdf->WriteHTML($html);
return $mpdf->Output(null, Destination::STRING_RETURN);
```

---

## 九、完整调用链总览（发票 + 报价双链路）

```
                        ┌─────────────────────────────────────────┐
                        │            两种 PDF 触发入口              │
                        └────────────────┬──────────────┬─────────┘
                                         │              │
                     ┌───────────────────▼──┐     ┌─────▼───────────────────┐
                     │     浏览器打印入口     │     │      邮件附件入口        │
                     └──────┬──────────┬────┘     └──┬──────────────────┬────┘
                            │          │              │                  │
                ┌───────────▼──┐  ┌────▼─────────┐  ┌▼─────────────┐  ┌▼──────────────┐
                │ 发票 .pdf    │  │ 报价 .pdf    │  │ InvoiceEmail │  │ QuoteEmail    │
                │ URL 访问     │  │ URL 访问     │  │ 邮件发送      │  │ 邮件发送       │
                └──────┬───────┘  └────┬─────────┘  └──┬───────────┘  └──┬────────────┘
                       │                │                │                  │
                       ▼                ▼                ▼                  ▼
          ┌──────────────────────┐  ┌──────────────────────┐  ┌──────────────────────┐  ┌──────────────────────┐
          │ InvoiceBundle/       │  │ QuoteBundle/         │  │ InvoicePdfListener   │  │ QuotePdfListener     │
          │ Action/View.php      │  │ Action/View.php      │  │ ::__invoke()         │  │ ::__invoke()         │
          │ 或                   │  │ 或                   │  │                      │  │                      │
          │ CoreBundle/Action/   │  │ CoreBundle/Action/   │  │ instanceof           │  │ instanceof           │
          │ ViewBilling.php      │  │ ViewBilling.php      │  │ InvoiceEmail         │  │ QuoteEmail           │
          └──────────┬───────────┘  └──────────┬───────────┘  └──────────┬───────────┘  └──────────┬───────────┘
                     │                         │                         │                         │
                     ▼                         ▼                         ▼                         ▼
          ┌──────────────────────┐  ┌──────────────────────┐                                 ┌──────────────────────┐
          │ @SolidInvoiceInvoice │  │ @SolidInvoiceQuote   │                                 │ 共用 Twig 渲染         │
          │ /Pdf/invoice.html.twig│  │ /Pdf/quote.html.twig │                                 │ (模板/变量名不同)      │
          │ ['invoice' => ...]   │  │ ['quote' => ...]     │                                 └──────────┬───────────┘
          └──────────┬───────────┘  └──────────┬───────────┘                                            │
                     │                         │                                                        │
                     └──────────────┬──────────┘                                                        │
                                    │                                                                   │
                                    ▼                                                                   ▼
                    ┌───────────────────────────────────────────────────────────────────────────────────┐
                    │                    共用 PDF 生成管线（样式 + 字体 + mPDF）                          │
                    │                                                                                   │
                    │  1. {{ file(asset('static/pdf.css')) }} → CSS 全文内联到 <style>                  │
                    │  2. @page 规则 + 行内 style="" 叠加                                                │
                    │  3. font-family 解析: Helvetica(正文) / Courier(金额)                              │
                    │  4. mPDF new Mpdf(['default_font' => 'helvetica'])                                │
                    │  5. $mpdf->WriteHTML($html) → CSS解析 + 字体渲染 + 排版 + PDF生成                  │
                    │  6. $mpdf->Output() → PDF 二进制字符串                                             │
                    └──────────────────────────────┬────────────────────────────────────────────────────┘
                                                   │
                                    ┌──────────────▼───────────────┐
                                    │        PDF 二进制字符串         │
                                    └──────┬──────────────────┬─────┘
                                           │                  │
                               ┌───────────▼────────┐  ┌──────▼────────────────────┐
                               │ 浏览器入口          │  │ 邮件入口                   │
                               │                    │  │                            │
                               │ new PdfResponse()  │  │ $message->attach()         │
                               │ Content-Type:      │  │ 文件名: invoice_xxx.pdf   │
                               │ application/pdf    │  │          quote_xxx.pdf     │
                               │ Disposition: inline│  │ MIME: application/pdf     │
                               └────────────────────┘  └───────────────────────────┘
```

---

## 十、关键文件索引（相对路径）

### 10.1 发票链路

| 功能 | 文件路径 |
|------|----------|
| 登录用户 View | `src/InvoiceBundle/Action/View.php` |
| 路由配置 | `src/InvoiceBundle/Resources/config/routing.php` |
| 邮件 PDF 监听器 | `src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php` |
| 默认 PDF 模板 | `src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig` |

### 10.2 报价链路

| 功能 | 文件路径 |
|------|----------|
| 登录用户 View | `src/QuoteBundle/Action/View.php` |
| 路由配置 | `src/QuoteBundle/Resources/config/routing.php` |
| 邮件 PDF 监听器 | `src/QuoteBundle/Listener/Mailer/QuotePdfListener.php` |
| 默认 PDF 模板 | `src/QuoteBundle/Resources/views/Pdf/quote.html.twig` |

### 10.3 共用基础（发票 + 报价）

| 功能 | 文件路径 |
|------|----------|
| 外部链接查看 Action | `src/CoreBundle/Action/ViewBilling.php` |
| 外部路由配置 | `src/CoreBundle/Resources/config/routing.php` |
| PDF 生成器 | `src/CoreBundle/Pdf/Generator.php` |
| PDF 响应类 | `src/CoreBundle/Response/PdfResponse.php` |
| 文件内联 Twig 函数 | `src/CoreBundle/Twig/Extension/FileExtension.php` |
| PDF 样式源 | `assets/scss/pdf.scss` |
| Webpack 配置 | `webpack.config.js` |
| 可选模板目录（仅发票有，测试用） | `src/InvoiceBundle/Resources/views/Templates/` |

---

## 十一、验证命令汇总

### 11.1 模板引用验证

```bash
# 生产代码中默认模板的引用（发票+报价合计6个结果，不含测试文件）
grep -rn "@SolidInvoiceInvoice/Pdf\|@SolidInvoiceQuote/Pdf" src/ --include="*.php" | grep -v "Tests/"

# 可选模板的引用（应仅在测试文件中，且只有发票有）
grep -rn "@SolidInvoiceInvoice/Templates" src/ --include="*.php"
grep -rn "@SolidInvoiceQuote/Templates" src/ --include="*.php"
```

### 11.2 路由与入口验证

```bash
# 发票路由
grep -n "_invoices_view\|_format" src/InvoiceBundle/Resources/config/routing.php

# 报价路由
grep -n "_quotes_view\|_format" src/QuoteBundle/Resources/config/routing.php

# 外部路由
grep -n "_view_invoice_external\|_view_quote_external" src/CoreBundle/Resources/config/routing.php

# 邮件监听器事件订阅
grep -n "MessageEvent\|getSubscribedEvents" \
  src/InvoiceBundle/Listener/Mailer/InvoicePdfListener.php \
  src/QuoteBundle/Listener/Mailer/QuotePdfListener.php
```

### 11.3 样式与字体验证

```bash
# 两个模板中 CSS 内联注入
grep -n "file(asset.*pdf" \
  src/InvoiceBundle/Resources/views/Pdf/invoice.html.twig \
  src/QuoteBundle/Resources/views/Pdf/quote.html.twig

# file() 函数实现
grep -n "file_get_contents" src/CoreBundle/Twig/Extension/FileExtension.php

# 字体配置
grep -n "default_font\|WriteHTML" src/CoreBundle/Pdf/Generator.php
grep -n "font-family" assets/scss/pdf.scss | head -5
```

---

## 结论总结

经过代码证据逐一验证，以下结论准确无误：

### 模板选择
- **发票 3 个入口**（登录用户 View、外部 ViewBilling、邮件 InvoicePdfListener）统一硬编码引用 `@SolidInvoiceInvoice/Pdf/invoice.html.twig`
- **报价 3 个入口**（登录用户 View、外部 ViewBilling、邮件 QuotePdfListener）统一硬编码引用 `@SolidInvoiceQuote/Pdf/quote.html.twig`
- 不存在运行时动态选择模板的机制
- 8 个可选预设模板（classic、modern 等）仅在测试文件中引用，尚未接入运行路径（且仅发票有，报价无对应 Templates 目录）

### 两类入口 × 两种单据
- **浏览器打印**：通过 URL 后缀 `.pdf` 触发，走各自的 `Action/View.php` 或共用的 `ViewBilling.php`，返回 `PdfResponse`（`inline` 内联预览）
- **邮件附件**：通过 `MessageEvent` 自动触发，走各自的 `PdfListener`，通过 `$message->attach()` 附加为邮件附件
- 发票和报价的入口逻辑结构完全对称，差异仅在模板路径、实体类型、ID 方法、颜色

### 样式与字体
- 发票和报价使用完全相同的样式/字体机制：`file(asset('static/pdf.css'))` 将编译好的 CSS 全文内联到 `<style>`，叠加 `@page` 规则和行内 `style=""`
- 字体通过三个路径进入 mPDF：① `default_font => 'helvetica'`（回退）② CSS `font-family`（正文 Helvetica）③ 行内 `'Courier New'`（金额列）
- 发票和报价的样式差异仅限于行内颜色值（绿色 `#2e963a` vs 蓝色 `#3b82f6`）
