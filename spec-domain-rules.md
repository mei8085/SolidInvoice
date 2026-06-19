# SolidInvoice 发票领域规则规格测试说明

本文档详细说明 SolidInvoice 项目中发票领域的规格测试如何约束业务规则，涵盖普通发票和定期发票两条业务线、测试场景分类、领域行为定义以及测试夹具（Fixture）之间的差异。

---

## 1. 领域模型核心实体

### 1.1 普通发票（Invoice）

**代码位置**：`src/InvoiceBundle/Entity/Invoice.php`

| 字段 | 类型 | 约束规则 | 说明 |
|------|------|----------|------|
| `id` | Ulid | 主键，自动生成 | 使用 ULID 有序时间唯一标识 |
| `invoiceId` | string | 必填 | 业务可读的发票编号 |
| `uuid` | Uuid v7 | 不可写 | 额外的 UUID 标识（克隆时重新生成） |
| `status` | InvoiceStatus enum | 状态机管理 | 发票生命周期状态，API 层只读 |
| `client` | Client | 必填，外键关联 | 所属客户，级联持久化 |
| `users` | Collection\<Contact\> | 至少 1 个 | 发票关联的联系人（收件人） |
| `lines` | Collection\<Line\> | 至少 1 条，级联删除 | 发票明细行（orphanRemoval=true） |
| `invoiceDate` | DateTimeInterface | 必填 | 开票日期 |
| `due` | ?DateTimeInterface | 可选 | 到期日（逾期判断依据） |
| `paidDate` | ?DateTimeInterface | 可选 | 实际付款日期（付款状态转换时自动设置） |
| `balance` | BigNumber | 不可写 | 未结余额（total - 已付款） |
| `total` / `baseTotal` / `tax` | BigNumber | 不可写 | 金额计算字段 |
| `discount` | Discount (embeddable) | 内联对象 | 折扣（百分比或固定金额） |
| `withholdingAmount` / `payableAmount` | BigNumber | 不可写 | 代扣代缴金额、应付金额 |
| `payments` | Collection\<Payment\> | 级联持久化 | 付款记录 |
| `quote` | ?Quote | 可选，一对一 | 来源报价单 |
| `recurringInvoice` | ?RecurringInvoice | 可选 | 来源定期发票 |
| `archived` | ?bool | 软删除字段 | `null`/`false`=未归档，`true`=已归档 |
| `invoiceTaxes` | Collection\<InvoiceTax\> | 级联删除 | 发票级税项 |

> **重要**：`Invoice` 同时使用 `Archivable` trait 和 `InvoiceStatusTrait`，两者都有 `isArchived()` 方法。通过 `Archivable::isArchived insteadof InvoiceStatusTrait` 解决冲突——即 `isArchived()` 返回的是 `archived` 数据库字段的值，**不是**状态枚举判断。

### 1.2 定期发票（RecurringInvoice）

**代码位置**：`src/InvoiceBundle/Entity/RecurringInvoice.php`

与普通发票共享 `BaseInvoice` 基类（金额、折扣、条款、备注等字段），额外包含：

| 字段 | 类型 | 约束规则 | 说明 |
|------|------|----------|------|
| `id` | Ulid | 主键，自动生成 | ULID 唯一标识 |
| `status` | RecurringInvoiceStatus enum | 状态机管理 | 定期发票生命周期状态 |
| `client` | Client | 必填，外键 | 所属客户 |
| `users` | Collection\<Contact\> | 至少 1 个 | 关联联系人 |
| `lines` | Collection\<RecurringInvoiceLine\> | 至少 1 条，级联删除 | 定期发票明细行 |
| `dateStart` | DateTimeInterface | 必填 | 调度开始日期 |
| `dateEnd` | ?DateTimeInterface | 可选 | 调度结束日期 |
| `recurringOptions` | RecurringOptions | 内联一对一 | 调度频率、结束条件等配置 |
| `invoices` | Collection\<Invoice\> | 一对多，反向关联 | 生成的普通发票列表 |
| `archived` | ?bool | 软删除字段 | 同普通发票 |
| `invoiceTaxes` | Collection\<InvoiceTax\> | 级联删除 | 定期发票级税项 |

**关键业务方法**：
- `hasInvoiceForDay(DateTimeInterface $now): bool` — 按日期（忽略时间）判断当日是否已生成过发票，防止重复生成

### 1.3 状态枚举

#### 普通发票状态

**代码位置**：`src/InvoiceBundle/Enum/InvoiceStatus.php`

| 枚举值 | value | 颜色标识 | 含义 |
|--------|-------|----------|------|
| `New` | `new` | gray | 新建（初始瞬态） |
| `Draft` | `draft` | secondary | 草稿（可编辑） |
| `Pending` | `pending` | yellow | 待付款（已发送给客户） |
| `Overdue` | `overdue` | red | 逾期（超过到期日未付款） |
| `Paid` | `paid` | green | 已付款 |
| `Cancelled` | `cancelled` | gray | 已取消 |
| `Archived` | `archived` | purple | 已归档（状态机归档后 status 为此值） |
| `Active` | `active` | green | 活跃（预留状态） |

#### 定期发票状态

**代码位置**：`src/InvoiceBundle/Enum/RecurringInvoiceStatus.php`

| 枚举值 | value | 颜色标识 | 含义 |
|--------|-------|----------|------|
| `New` | `new` | gray | 新建 |
| `Draft` | `draft` | secondary | 草稿 |
| `Active` | `active` | green | 活跃调度中（按配置生成发票） |
| `Paused` | `paused` | dark | 暂停（暂不生成） |
| `Complete` | `complete` | teal | 全部完成（已达到结束条件） |
| `Cancelled` | `cancelled` | gray | 已取消 |
| `Archived` | `archived` | purple | 已归档 |

---

## 2. 工作流（状态机）配置

**配置文件**：`config/packages/workflow.php`

状态机通过 `statusValue` 属性（getter/setter）操作 `status` 枚举字段，使用 method 类型 marking store。

### 2.1 普通发票状态转换图

```
                         ┌─────────────┐
                         │     New     │
                         └──────┬──────┘
                 TRANSITION_NEW │
                                ▼
                         ┌─────────────┐
        ┌───────────────│    Draft    │───────────────┐
        │  TRANSITION_   └──────┬──────┘  TRANSITION_  │
        │  ACCEPT               │               CANCEL│
        │                       │                       │
        ▼                       ▼                       ▼
 ┌─────────────┐         ┌─────────────┐         ┌─────────────┐
 │   Pending   │───────▶ │  Cancelled  │◀────────│             │
 └──────┬──────┘ OVERDUE └──────┬──────┘  REOPEN │             │
        │                        │                  │             │
        │ TRANSITION_            │                  │             │
        │ CANCEL                 │                  │             │
        │                        │                  └──────┬──────┘
        ▼                        ▼                  ▲  TRANSITION_
 ┌─────────────┐         ┌─────────────┐           │  REOPEN
 │   Overdue   │─────────┘             │           │
 └──────┬──────┘  TRANSITION_CANCEL    │           │
        │                              │           │
        │ TRANSITION_PAY               │           │
        ▼                              │           │
 ┌─────────────┐                       │           │
 │    Paid     │───┐                   │           │
 └─────────────┘   │ TRANSITION_       │           │
                   │ ARCHIVE           │           │
                   ▼                   │           │
            ┌─────────────┐            │           │
            │  Archived   │◀───────────┴───────────┘
            └─────────────┘  New / Draft / Cancelled / Paid
                            均可通过 TRANSITION_ARCHIVE 到达
```

### 2.2 定期发票状态转换图

```
                         ┌─────────────┐
                         │     New     │
                         └──────┬──────┘
                 TRANSITION_NEW │
                                ▼
                         ┌─────────────┐
        ┌───────────────│    Draft    │───────────────┐
        │  TRANSITION_   └──────┬──────┘  TRANSITION_  │
        │  ACTIVATE             │               CANCEL│
        │                       │                       │
        ▼                       ▼                       ▼
 ┌─────────────┐         ┌─────────────┐         ┌─────────────┐
 │   Active    │───┐     │             │         │  Cancelled  │
 └──────┬──────┘   │pause│             │         └──────┬──────┘
        │          ▼     │             │                │
        │     ┌──────────┴──┐          │                │
        │     │   Paused    │          │                │
        │     └──────┬──────┘          │                │
        │       resume│                 │                │
        │             │                 │                │
        └───────┐     │                 │                │
        complete│     │                 │                │
                ▼     ▼                 │                │
           ┌─────────────┐              │                │
           │  Complete   │              │                │
           └──────┬──────┘              │                │
                  │                     │                │
                  │  edit               │  edit          │  edit
                  └─────────────────────┴────────────────┘
                                        │
                                        ▼
                                 ┌─────────────┐
                                 │    Draft    │  （编辑后回到草稿）
                                 └─────────────┘

            ┌─────────────┐
            │  Archived   │◀── New / Draft / Cancelled / Active / Paused
            └─────────────┘   均可通过 TRANSITION_ARCHIVE 到达
```

### 2.3 普通发票转换规则

| Transition 常量 | 名称 | 允许的源状态 | 目标状态 | 触发场景 |
|-----------------|------|------------|----------|----------|
| `TRANSITION_NEW` | `new` | New | Draft | 发票创建时自动执行 |
| `TRANSITION_ACCEPT` | `accept` | New, Draft | Pending | 确认/发送发票给客户 |
| `TRANSITION_CANCEL` | `cancel` | Draft, Pending, Overdue | Cancelled | 取消发票 |
| `TRANSITION_OVERDUE` | `overdue` | Pending | Overdue | 到期日超过仍未付款，由命令/消息自动触发 |
| `TRANSITION_PAY` | `pay` | Pending, Overdue | Paid | 收到付款 |
| `TRANSITION_REOPEN` | `reopen` | Cancelled | Draft | 重新打开已取消的发票 |
| `TRANSITION_ARCHIVE` | `archive` | New, Draft, Cancelled, Paid | Archived | 状态机归档（同时设置 status 和 archived 字段） |
| `edit` | `edit` | Cancelled, Draft, Pending, Overdue | Draft | 编辑后回到草稿状态 |

### 2.4 定期发票转换规则

| 名称 | 允许的源状态 | 目标状态 | 触发场景 |
|------|------------|----------|----------|
| `new`（TRANSITION_NEW） | New | Draft | 创建时自动执行 |
| `activate`（TRANSITION_ACTIVATE） | New, Draft | Active | 激活定期调度 |
| `cancel`（TRANSITION_CANCEL） | Draft, Active | Cancelled | 取消定期发票 |
| `complete` | Active | Complete | 达到结束条件后自动标记完成 |
| `pause` | Active | Paused | 暂停调度 |
| `resume` | Paused | Active | 恢复调度 |
| `archive`（TRANSITION_ARCHIVE） | New, Draft, Cancelled, Active, Paused | Archived | 状态机归档 |
| `edit` | Cancelled, Draft, Active, Paused | Draft | 编辑后回到草稿状态 |

### 2.5 状态转换的副作用（WorkFlowSubscriber）

**监听器**：`src/InvoiceBundle/Listener/WorkFlowSubscriber.php`

监听 `workflow.invoice.entered` 和 `workflow.recurring_invoice.entered` 事件，在状态转换后执行副作用：

1. **`pay` 转换** → 自动设置 `paidDate = now()`（仅普通发票）
2. **`archive` 转换** → 调用 `$invoice->archive()` 设置 `archived = true`（软删除字段）
3. **每次转换后** → 持久化实体（persist + flush）
4. **非 New 状态转换后** → 发送 `InvoiceStatusNotification` 通知

### 2.6 取消、归档、删除、恢复操作总览

发票领域有五种"关闭/移除/恢复"语义，各自对应不同的业务场景和实现方式。**普通发票和定期发票在仓储归档和网格入口上存在关键差异**。

#### 2.6.1 操作语义对比

| 操作 | 实现方式 | status 字段 | archived 字段 | 是否可逆 | 适用实体 |
|------|----------|------------|--------------|----------|----------|
| **取消（Cancel）** | 状态机 `TRANSITION_CANCEL` | `cancelled` | 不变 | ✅ 可逆（reopen → Draft） | 普通发票 + 定期发票 |
| **状态机归档** | 状态机 `TRANSITION_ARCHIVE` | `archived` | `true` | ⚠️ 状态机无回退转换 | 普通发票 + 定期发票 |
| **仓储归档** | `Repository::archiveInvoices()` | **不变** | `true` | ✅ 可逆（`restoreInvoices`） | **仅普通发票** |
| **恢复（Activate）** | `Repository::restoreInvoices()` | **不变** | `null` | N/A | 普通发票 + 定期发票 |
| **重新激活（Reactivate）** | 直接 `$invoice->setStatus(Active)` | `active` | 不变 | — | **仅定期发票**（已完成→活跃） |
| **删除（Delete）** | Doctrine `$em->remove()` 硬删除 | 数据物理删除 | ❌ 不可逆 | 普通发票 + 定期发票 |

#### 2.6.2 状态机归档 vs 仓储归档

| 维度 | 状态机归档（TRANSITION_ARCHIVE） | 仓储归档（InvoiceRepository::archiveInvoices） |
|------|--------------------------------|-----------------------------------------------|
| status 变化 | 变为 `archived` | **保持原值不变** |
| archived 变化 | 变为 `true`（由 WorkFlowSubscriber 设置） | 变为 `true`（直接 setArchived） |
| 是否触发通知 | 是（WorkFlowSubscriber 发送） | 否 |
| 前置状态限制 | 有（仅允许特定源状态） | 无（任何状态都可归档） |
| 适用实体 | 普通发票 + 定期发票 | **仅普通发票**（RecurringInvoiceRepository 无此方法） |
| 可逆方式 | 状态机中无定义回退转换 | `restoreInvoices()` 恢复 archived 字段 |

#### 2.6.3 普通发票 vs 定期发票的归档路径差异

| 归档路径 | 普通发票（Invoice） | 定期发票（RecurringInvoice） |
|----------|-------------------|---------------------------|
| 状态机归档（API 转换端点） | ✅ status→archived, archived→true | ✅ status→archived, archived→true |
| 仓储归档（DataGrid 批量 Archive） | ✅ `InvoiceRepository::archiveInvoices()` | ❌ **无此方法和 UI 入口** |
| 进入归档列表的其他途径 | 仓储归档后也出现在归档列表 | 仅状态机归档后出现在归档列表 |
| 从归档列表恢复 | ✅ `InvoiceRepository::restoreInvoices()` → archived=null | ✅ `RecurringInvoiceRepository::restoreInvoices()` → archived=null |

> **关键区别**：定期发票没有批量归档 UI 入口，归档只能通过 API 转换端点（状态机归档）实现。这意味着定期发票归档后 status 一定是 `archived`，不会出现"仓储归档那种 status 保持原值但 archived=true"的情况。

#### 2.6.4 定期发票独有的"重新激活"操作

**代码位置**：`src/InvoiceBundle/DataGrid/CompletedRecurringInvoiceGrid.php`

已完成（Complete）状态的定期发票有一个独特的 **Reactivate** 批量操作，直接将 status 设为 `Active`，**绕过状态机**（不通过 `resume` 转换，因为 Complete → Active 在状态机中没有定义）。

| 维度 | 说明 |
|------|------|
| 网格入口 | CompletedRecurringInvoiceGrid 的 Reactivate 按钮 |
| 实现方式 | `$invoice->setStatus(RecurringInvoiceStatus::Active)` + `$em->flush()` |
| 是否走状态机 | ❌ 直接修改 status，不走工作流 |
| 源状态 | Complete |
| 目标状态 | Active |

#### 2.6.5 ArchivableFilter 过滤规则

- **过滤器代码**：`src/CoreBundle/Doctrine/Filter/ArchivableFilter.php`
- **SQL 条件**：`(archived IS NULL OR archived = 0)`
- **默认启用**：所有查询自动过滤掉已归档记录
- **禁用场景**：查询归档列表、删除归档记录、恢复归档记录时需手动禁用过滤器

> **易错点**：归档网格（Archived Grid）显示的是 `archived IS NOT NULL` 的所有记录，不管 status 值是什么。对普通发票来说，状态机归档的记录（status=archived, archived=true）和仓储归档的记录（status=原值, archived=true）都会出现在归档列表中。对定期发票来说，因无仓储归档入口，归档列表中只会有状态机归档的记录。

---

## 3. 删除操作的实现细节

### 3.1 API 层删除

**端点**：`DELETE /api/invoices/{id}` 和 `DELETE /api/recurring-invoices/{id}`

**实现机制**：使用 API Platform 默认的 Delete 操作，走 Doctrine 的 `remove()` + `flush()`，即**硬删除**。

**测试验证**：
- 普通发票：`src/InvoiceBundle/Tests/Functional/Api/InvoiceTest.php` → `testDelete()`
- 定期发票：`src/InvoiceBundle/Tests/Functional/Api/RecurringInvoiceTest.php` → `testDelete()`
- 验证方式：调用 `requestDelete()` 后断言响应状态码为 `204 No Content`

**注意事项**：
- 删除操作没有自定义 Processor，直接使用 API Platform 内置的 Doctrine 删除处理器
- 删除前不会检查状态（任何状态的发票都可以被硬删除）
- 级联关系：`lines` 设为 `orphanRemoval=true`，删除时行项目自动删除
- `payments` 级联 `persist`，删除行为由数据库外键 `onDelete` 约束决定

### 3.2 DataGrid 批量操作

#### 3.2.1 普通发票网格

**基类**：`src/InvoiceBundle/DataGrid/BaseInvoiceGrid.php` — 定义 Delete 批量操作

| 网格 | 代码位置 | Delete | Archive | Activate/恢复 | 查询条件 |
|------|----------|--------|---------|--------------|----------|
| InvoiceGrid | `src/InvoiceBundle/DataGrid/InvoiceGrid.php` | ✅（继承自基类） | ✅ 调用 `InvoiceRepository::archiveInvoices()` | — | 全部未归档发票，可按 client_id 过滤 |
| ArchivedInvoiceGrid | `src/InvoiceBundle/DataGrid/ArchivedInvoiceGrid.php` | ✅（继承自基类） | — | ✅ 调用 `InvoiceRepository::restoreInvoices()` → archived=null | 禁用 ArchivableFilter + `archived IS NOT NULL` |

**仓储方法对应关系**：

| Repository 方法 | 被调用的网格 | 逻辑 |
|----------------|------------|------|
| `InvoiceRepository::deleteInvoices(array $ids)` | 两个网格都有 | 禁用 archivable 过滤器 → 逐个 remove → flush → 恢复过滤器 |
| `InvoiceRepository::archiveInvoices(array $ids)` | InvoiceGrid Archive 按钮 | 逐个 `setArchived(true)` → persist → flush（**不改变 status**） |
| `InvoiceRepository::restoreInvoices(array $ids)` | ArchivedInvoiceGrid Activate 按钮 | 禁用 archivable 过滤器 → 逐个 `setArchived(null)` → persist → flush → 恢复过滤器 |

#### 3.2.2 定期发票网格

**基类**：`src/InvoiceBundle/DataGrid/BaseRecurringInvoiceGrid.php` — 定义 Delete 批量操作

| 网格 | 代码位置 | Delete | Reactivate | Activate/恢复 | 查询条件 |
|------|----------|--------|-----------|--------------|----------|
| RecurringInvoiceGrid | `src/InvoiceBundle/DataGrid/RecurringInvoiceGrid.php` | ✅（继承自基类） | — | — | `status != Complete`（排除已完成的） |
| CompletedRecurringInvoiceGrid | `src/InvoiceBundle/DataGrid/CompletedRecurringInvoiceGrid.php` | ✅（继承自基类） | ✅ 直接 `setStatus(Active)` + flush（**绕过状态机**） | — | `status = Complete` |
| ArchivedRecurringInvoiceGrid | `src/InvoiceBundle/DataGrid/ArchivedRecurringInvoiceGrid.php` | ✅（继承自基类） | — | ✅ 调用 `RecurringInvoiceRepository::restoreInvoices()` → archived=null | 禁用 ArchivableFilter + `archived IS NOT NULL` |

**仓储方法对应关系**：

| Repository 方法 | 被调用的网格 | 逻辑 |
|----------------|------------|------|
| `RecurringInvoiceRepository::deleteInvoices(array $ids)` | 三个网格都有 | 禁用 archivable 过滤器 → 逐个 remove → flush → 恢复过滤器 |
| **无 `archiveInvoices` 方法** | ❌ 无入口 | 定期发票**不支持**批量仓储归档，归档只能走状态机 API 转换 |
| `RecurringInvoiceRepository::restoreInvoices(array $ids)` | ArchivedRecurringInvoiceGrid Activate 按钮 | 禁用 archivable 过滤器 → 逐个 `setArchived(null)` → persist → flush → 恢复过滤器 |

> **核心差异**：普通发票有两个归档入口（状态机 + 仓储批量），定期发票只有一个归档入口（状态机）。这导致普通发票归档列表中可能出现"status 保持原值但 archived=true"的记录（仓储归档产生），而定期发票归档列表中的记录 status 一定是 `archived`。

### 3.3 定期发票生成 API

**端点**：`POST /api/recurring-invoices/{id}/generate`

**处理器**：`src/ApiBundle/State/Processor/GenerateInvoiceFromRecurringProcessor.php`

生成逻辑：
1. 检查当日是否已生成过发票（`hasInvoiceForDay()`），如果已生成则抛出 `UnprocessableEntityHttpException`（422）
2. 调用 `InvoiceManager::createFromRecurring()` 从定期发票创建普通发票（含描述占位符替换 + 税快照冻结）
3. 调用 `InvoiceManager::create()` 保存并触发状态流转（New → Draft）
4. 返回生成的普通发票实体

**测试**：`RecurringInvoiceTransitionTest::testGenerateInvoice()` — 断言返回类型为 Invoice 且包含 id

---

## 4. 规格测试场景分类与领域行为

### 4.1 单元测试层级

#### 4.1.1 状态转换服务测试

**代码位置**：`src/InvoiceBundle/Tests/Service/InvoiceStatusTransitionServiceTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testApplyTransition` | Pending → Overdue 转换 | 状态机 `can()` 返回 true 时，`apply()` 被正确调用，实体被持久化 |
| `testApplyTransitionThrowsExceptionWhenTransitionNotAllowed` | Paid 状态尝试 overdue 转换 | 非法转换抛出 `InvalidTransitionException` |
| `testCanApplyTransition` | 查询是否可转换 | 服务委托给状态机 `can()` 方法 |
| `testGetAvailableTransitions` | 获取可用转换列表 | 返回 `getEnabledTransitions()` 的转换名称数组 |

**夹具策略**：Mockery 模拟 StateMachine + 手动构造 Invoice + ClientFactory::createOne() 真实实体，配合 DoctrineTestTrait。

#### 4.1.2 状态转换监听器测试

**代码位置**：`src/InvoiceBundle/Tests/Listener/WorkFlowSubscriberTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testInvoicePaid` | `pay` transition 触发 | 自动设置 `paidDate`，实体持久化，通知发送 1 次 |
| `testInvoiceArchive` | `archive` transition 触发 | 调用 `archive()` 设置软删除标记，实体持久化 |

**夹具策略**：Mockery 模拟 NotificationManager，断言 `sendNotification()` 调用次数。

#### 4.1.3 发票管理器（InvoiceManager）测试

**代码位置**：`src/InvoiceBundle/Tests/Manager/InvoiceManagerTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testCreateFromQuote` | 从报价单生成发票 | 复制金额、折扣、条款、备注、客户、联系人；行类型转换；生成新 UUID |
| `testQuoteToInvoiceCopiesLineTaxSnapshotsAsNewRows` | 报价→发票税快照复制 | LineTax/InvoiceTax 作为新对象复制（不共享引用）；草稿状态**不冻结**快照（snapshottedAt = null） |
| `testRecurringGenerationFreezesSnapshotsAtGenerationTime` | 定期生成税快照冻结 | 定期生成时 `freezeSnapshots=true`，`snapshottedAt != null`；冻结后修改被拒绝（不变性保护） |
| `testCreateFromRecurring` | 从定期发票生成发票 | 行描述占位符替换：`{day}` `{day_name}` `{month}` `{year}` → 生成日期对应值 |

**夹具策略**：手动 new 构造领域对象，StateMachine 用真实最小定义（new → draft），`ClockInterface` 桩化为 `2024-01-15 10:30:00`。

#### 4.1.4 发票克隆（Cloner）测试

**代码位置**：`src/InvoiceBundle/Tests/Cloner/InvoiceClonerTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testClone` | 克隆普通发票 | 新 ID（null）、新 UUID、新 invoiceId；金额/折扣/客户/行项目值相等但非同一引用 |
| `testCloneWithRecurring` | 克隆定期发票 | 同时复制定期调度配置；行类型转换为普通 Invoice Line |

**夹具策略**：手动构造对象 + Mockery 模拟 InvoiceManager.create()。

#### 4.1.5 定期发票实体行为测试

**代码位置**：`src/InvoiceBundle/Tests/Entity/RecurringInvoiceTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testHasInvoiceForDayReturnsTrueWhenInvoiceExistsForDate` | 当天已生成发票 | 按**日期（忽略时间）**匹配，返回 true |
| `testHasInvoiceForDayReturnsFalseWhenNoInvoiceExistsForDate` | 当天未生成发票 | 不同日期返回 false |
| `testHasInvoiceForDayReturnsFalseWhenNoInvoicesExist` | 无任何发票 | 返回 false |
| `testHasInvoiceForDayChecksMultipleInvoices` | 多张发票多日期 | 遍历检查所有发票日期 |

**夹具策略**：纯内存对象构造，`CarbonImmutable::parse()` 指定日期。

---

### 4.2 功能测试层级

#### 4.2.1 逾期发票完整流程

**代码位置**：`src/InvoiceBundle/Tests/Functional/OverdueInvoiceFlowTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testCompleteOverdueFlow` | 完整逾期检测→状态变更流程 | Dispatch `MarkInvoiceOverdue` 消息后，状态从 Pending 变为 Overdue |
| `testOverdueFlowWithMultipleCompanies` | 多租户隔离 | Company1 和 Company2 的发票各自独立 |
| `testIdempotency` | 幂等性保证 | 同一消息重复 Dispatch 两次，最终状态仍为 Overdue |
| `testRepositoryGetPendingOverdueInvoices` | 逾期查询规则 | 仅返回：① status=Pending ② due < today ③ due != null |

**夹具策略**：Foundry Factory（Invoice/Client/Contact/Company），显式设置 `due = CarbonImmutable::yesterday()`。

#### 4.2.2 普通发票 API 测试

**代码位置**：`src/InvoiceBundle/Tests/Functional/Api/InvoiceTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testGetCollection` | 获取列表 | 返回 JSON-LD Collection 格式 |
| `testGetInvoicesForClient` | 按客户子资源查询 | `/api/clients/{id}/invoices` 返回客户发票 |
| `testCannotAccessInvoiceFromDifferentCompany` | 跨公司数据隔离 | 其他 Company 的发票返回 404 |
| `testCreate` | 创建发票 | POST 后返回 ULID、UUID v7、状态 draft；折扣计算正确 |
| `testGet` | 获取单张 | 所有字段序列化完整，行项目嵌套 |
| `testEdit` | PATCH 更新 | 支持修改折扣和行项目，金额重算 |
| `testDelete` | 删除发票 | DELETE 返回 204 No Content，物理删除 |

**夹具策略**：Foundry Factory 构造前置数据，API 通过 ApiTestCase 的 request* 方法发送请求。

#### 4.2.3 普通发票 API 状态转换测试

**代码位置**：`src/InvoiceBundle/Tests/Functional/Api/InvoiceTransitionTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testAcceptInvoice` | Draft → Pending | POST transitions/accept 返回 status: pending |
| `testCancelInvoice` | Draft → Cancelled | POST transitions/cancel 返回 status: cancelled |
| `testInvalidTransition` | Draft → Pay（非法） | 返回 422 Unprocessable Entity |
| `testTransitionOnForeignCompanyInvoice` | 跨公司转换 | 其他 Company 的发票返回 404 |

**夹具策略**：InvoiceFactory 指定 status=Draft 构造前置状态，CompanyFactory 构造多租户。

#### 4.2.4 定期发票 API 测试

**代码位置**：`src/InvoiceBundle/Tests/Functional/Api/RecurringInvoiceTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testGetCollection` | 获取列表 | 返回 JSON-LD Collection 格式 |
| `testCannotAccessRecurringInvoiceFromDifferentCompany` | 跨公司数据隔离 | 其他 Company 的定期发票返回 404 |
| `testCreate` | 创建定期发票 | 含 recurringOptions（频率、结束条件、日期）、折扣、行项目；返回 status: draft |
| `testGet` | 获取单张 | 所有字段序列化，含 recurringOptions 嵌套对象 |
| `testEdit` | PATCH 更新 | 支持修改 dateStart、折扣、行项目 |
| `testDelete` | 删除定期发票 | DELETE 返回 204 No Content |

**夹具策略**：RecurringInvoiceFactory + ClientFactory + ContactFactory，创建时手动传入 users 和 lines 以满足断言要求。

#### 4.2.5 定期发票 API 状态转换与生成测试

**代码位置**：`src/InvoiceBundle/Tests/Functional/Api/RecurringInvoiceTransitionTest.php`

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testActivateRecurringInvoice` | Draft → Active | POST transitions/activate 返回 status: active |
| `testCancelRecurringInvoice` | Draft → Cancelled | POST transitions/cancel 返回 status: cancelled |
| `testInvalidTransition` | Draft → Complete（非法） | 返回 422 Unprocessable Entity |
| `testGenerateInvoice` | 从定期发票生成普通发票 | POST /generate 返回 Invoice 类型实体，含 id |
| `testTransitionOnForeignCompanyRecurringInvoice` | 跨公司转换 | 其他 Company 的定期发票转换返回 404 |
| `testGenerateForeignCompanyRecurringInvoice` | 跨公司生成 | 其他 Company 的定期发票生成返回 404 |

**夹具策略**：
- 激活测试：status=Draft + users 联系人
- 生成测试：status=Active + users 联系人
- 跨公司测试：CompanyFactory 创建另一个 Company，切换 CompanySelector 造数据，再切回来

---

## 5. 测试夹具（Fixture）差异对比

### 5.1 三种夹具构造方式

| 方式 | 技术 | 适用测试类型 | 特点 |
|------|------|------------|------|
| **A. 手动 new 构造** | `new Invoice()` + setter | 单元测试（Manager、Cloner、Entity） | 完全控制、无依赖、执行快；需手动填充所有必填字段 |
| **B. Mockery 模拟** | `Mockery::mock(...)` | 单元测试（Service、Listener） | 隔离外部依赖，断言交互行为；不执行真实业务逻辑 |
| **C. Foundry Factory** | `*Factory::createOne([...])` | 功能测试、集成测试 | 真实持久化到数据库，自动生成默认值，支持 Proxy 包装 |

### 5.2 Foundry Factory 默认值对比

#### 普通发票 vs 定期发票默认值

| 字段 | InvoiceFactory | RecurringInvoiceFactory |
|------|---------------|------------------------|
| `client` | ClientFactory::new() | ClientFactory::new() |
| `status` | 随机 InvoiceStatus | 随机 RecurringInvoiceStatus |
| `terms` / `notes` | Faker 随机文本 | Faker 随机文本 |
| `archived` | null | null |
| `total` / `baseTotal` / `tax` | Faker 随机 BigInteger | Faker 随机 BigInteger |
| `discount` | 随机类型 + 随机值 | 随机类型 + 随机值 |
| `due` | Faker 随机日期 | —（定期发票无 due 字段） |
| `paidDate` | Faker 随机日期 | — |
| `balance` | Faker 随机 BigInteger | —（定期发票无 balance） |
| `dateStart` | — | Faker 随机日期 |
| `recurringOptions` | — | WEEKLY + AFTER 1 occurrence + days=[1] |

#### 定期发票工厂独有字段

**代码位置**：`src/InvoiceBundle/Test/Factory/RecurringInvoiceFactory.php`

`recurringOptions` 默认配置：
- `type`: `ScheduleRecurringType::WEEKLY`（每周）
- `endType`: `ScheduleEndType::AFTER`（按次数结束）
- `days`: `[1]`（周一）
- `endOccurrence`: `1`（只生成 1 次）

### 5.3 Factory 场景化覆盖示例

| 测试场景 | Factory 覆盖参数 | 目的 |
|----------|----------------|------|
| 逾期流程测试 | `['status' => Pending, 'due' => CarbonImmutable::yesterday()]` | 构造"已过期但未付款"条件 |
| 状态转换测试 | `['status' => Draft]` | 确保转换起点正确 |
| 已付款发票（负例） | `['status' => Paid, 'due' => CarbonImmutable::yesterday()]` | 逾期查询中作为负例 |
| 金额精确计算 | `['discount' => new Discount()->setType(percentage)->setValue(0)]` | 排除折扣干扰 |
| 多租户隔离 | `['company' => $otherCompany]` | 构造跨 Company 数据 |
| 带联系人测试 | `['users' => $contacts]` | 满足 @Assert\Count(min=1) 约束 |
| 定期发票激活测试 | `['status' => Draft, 'users' => $contacts]` | 激活转换前置条件 |
| 定期发票生成测试 | `['status' => Active, 'users' => $contacts]` | 生成操作前置条件 |
| 定期发票自定义调度 | `['recurringOptions' => new RecurringOptions()->setType(WEEKLY)->setDays([4,5])]` | 自定义调度频率 |

### 5.4 夹具构造方式选择矩阵

| 测试目标 | 推荐方式 | 原因 |
|----------|----------|------|
| 验证实体 getter/setter、纯计算逻辑 | A. 手动 new | 无需持久化，快速执行 |
| 验证状态机协作、异常分支 | B. Mock StateMachine | 精确控制 can() 返回值 |
| 验证监听器副作用（paidDate、archive） | A + B 混合 | 真实实体 + Mock 通知组件 |
| 验证 Manager 金额复制、快照 | A. 手动 new + 桩化 Clock | 精确控制时间 |
| 验证 API 端点、序列化 | C. Foundry Factory | 需要真实数据库、完整 DI 容器 |
| 验证完整流程（消息→状态变更） | C. Foundry Factory | 需要 Messenger、Doctrine、Workflow 全链路 |
| 验证多租户隔离 | C. Foundry Factory + CompanySelector | 需要数据库级 CompanyFilter |
| 验证查询逻辑（Repository） | C. Foundry Factory 批量造数据 | 需要多种边界条件测试数据 |
| 验证删除操作（API 层） | C. Foundry Factory | 需要真实持久化后再删除 |
| 验证定期发票同日去重 | A. 手动 new | 纯内存日期比较逻辑 |

---

## 6. 领域不变量与测试覆盖映射

下表总结发票领域的核心业务不变量及其对应的测试保障：

| 领域不变量 | 代码位置 | 测试保障 |
|----------|----------|----------|
| **普通发票状态转换必须合法** | workflow.php invoice 工作流 | `InvoiceStatusTransitionServiceTest` + `InvoiceTransitionTest::testInvalidTransition` |
| **定期发票状态转换必须合法** | workflow.php recurring_invoice 工作流 | `RecurringInvoiceTransitionTest::testInvalidTransition` |
| **付款后必须记录付款日期** | WorkFlowSubscriber | `WorkFlowSubscriberTest::testInvoicePaid` |
| **状态机归档必须设置软删除标记** | WorkFlowSubscriber | `WorkFlowSubscriberTest::testInvoiceArchive` |
| **发票至少有 1 个联系人** | Invoice::$users `@Assert\Count(min=1)` | API 创建测试（400 校验） |
| **发票至少有 1 行明细** | Invoice::$lines `@Assert\Count(min=1)` | 同上 |
| **定期发票至少有 1 个联系人** | RecurringInvoice::$users 约束 | RecurringInvoice API 创建测试 |
| **定期发票至少有 1 行明细** | RecurringInvoice::$lines 约束 | 同上 |
| **跨公司发票不可见（查询）** | CompanyFilter | `InvoiceTest::testCannotAccessInvoiceFromDifferentCompany` + `RecurringInvoiceTest::testCannotAccessRecurringInvoiceFromDifferentCompany` |
| **跨公司发票不可操作（转换）** | CompanyFilter | `InvoiceTransitionTest::testTransitionOnForeignCompanyInvoice` + `RecurringInvoiceTransitionTest::testTransitionOnForeignCompanyRecurringInvoice` |
| **跨公司定期发票不可生成** | CompanyFilter | `RecurringInvoiceTransitionTest::testGenerateForeignCompanyRecurringInvoice` |
| **逾期状态幂等** | Handler 内状态机检查 | `OverdueInvoiceFlowTest::testIdempotency` |
| **逾期查询仅含 Pending 且到期日<今天** | InvoiceRepository::getPendingOverdueInvoices | `OverdueInvoiceFlowTest::testRepositoryGetPendingOverdueInvoices` |
| **克隆后 UUID/InvoiceId 必须唯一** | Invoice::__clone() + InvoiceCloner | `InvoiceClonerTest::testClone` |
| **定期生成描述占位符被替换** | InvoiceManager::createFromRecurring | `InvoiceManagerTest::testCreateFromRecurring` |
| **定期生成的税快照不可修改** | TaxSnapshotCopier::copyLineTax(freezeAt) | `InvoiceManagerTest::testRecurringGenerationFreezesSnapshotsAtGenerationTime` |
| **报价→发票税是独立副本** | InvoiceManager::createFromObject | `InvoiceManagerTest::testQuoteToInvoiceCopiesLineTaxSnapshotsAsNewRows` |
| **定期发票不重复生成同日发票** | RecurringInvoice::hasInvoiceForDay() | `RecurringInvoiceTest` 四个用例 + GenerateInvoiceFromRecurringProcessor 同日校验 |
| **状态变更必须触发通知（New 除外）** | WorkFlowSubscriber | `WorkFlowSubscriberTest` 断言 sendNotification 调用次数 |
| **API 删除返回 204 No Content** | API Platform Delete 操作 | `InvoiceTest::testDelete` + `RecurringInvoiceTest::testDelete` |
| **已归档数据默认不显示** | ArchivableFilter | 各列表查询测试间接保障（仅返回未归档） |
| **删除操作需禁用归档过滤器** | Repository::deleteInvoices | 代码实现保证（无直接单元测试） |
| **同日不可重复生成定期发票** | GenerateInvoiceFromRecurringProcessor | 代码实现保证 + 实体层 hasInvoiceForDay 测试 |
| **仓储归档仅适用于普通发票** | RecurringInvoiceRepository 无 archiveInvoices | 代码实现保证（定期发票无批量归档入口） |
| **定期发票 Reactivate 绕过状态机** | CompletedRecurringInvoiceGrid 直接 setStatus | 代码实现保证（Complete→Active 无状态机路径） |

---

## 7. 关键文件索引

### 7.1 实体与枚举

| 文件 | 说明 |
|------|------|
| `src/InvoiceBundle/Entity/Invoice.php` | 普通发票实体 |
| `src/InvoiceBundle/Entity/BaseInvoice.php` | 发票基类（金额、折扣等共享字段） |
| `src/InvoiceBundle/Entity/RecurringInvoice.php` | 定期发票实体 |
| `src/InvoiceBundle/Entity/RecurringInvoiceLine.php` | 定期发票行项目 |
| `src/InvoiceBundle/Entity/RecurringOptions.php` | 定期调度配置内联对象 |
| `src/InvoiceBundle/Enum/InvoiceStatus.php` | 普通发票状态枚举（8 种状态） |
| `src/InvoiceBundle/Enum/RecurringInvoiceStatus.php` | 定期发票状态枚举（7 种状态） |
| `src/CoreBundle/Traits/Entity/Archivable.php` | 软删除 trait（archived 字段 + archive/restore 方法） |

### 7.2 状态机与工作流

| 文件 | 说明 |
|------|------|
| `config/packages/workflow.php` | 状态机配置（invoice、recurring_invoice、quote 三个工作流） |
| `src/InvoiceBundle/Model/Graph.php` | 转换名称常量（TRANSITION_ACCEPT、TRANSITION_ARCHIVE 等） |
| `src/InvoiceBundle/Listener/WorkFlowSubscriber.php` | 状态转换事件监听器（paidDate、archive、通知等副作用） |

### 7.3 核心服务

| 文件 | 说明 |
|------|------|
| `src/InvoiceBundle/Manager/InvoiceManager.php` | 发票管理器（创建、从报价/定期生成） |
| `src/InvoiceBundle/Service/InvoiceStatusTransitionService.php` | 状态转换服务（封装工作流调用） |
| `src/InvoiceBundle/Cloner/InvoiceCloner.php` | 发票克隆器 |
| `src/ApiBundle/State/Processor/GenerateInvoiceFromRecurringProcessor.php` | 定期发票生成普通发票的 API Processor |
| `src/ApiBundle/State/Processor/RecurringInvoiceTransitionProcessor.php` | 定期发票状态转换的 API Processor |
| `src/ApiBundle/State/Provider/RecurringInvoiceItemProvider.php` | 定期发票单项数据 Provider |

### 7.4 仓储与过滤器

| 文件 | 说明 |
|------|------|
| `src/InvoiceBundle/Repository/InvoiceRepository.php` | 普通发票仓储（删除、归档、恢复、逾期查询等） |
| `src/InvoiceBundle/Repository/RecurringInvoiceRepository.php` | 定期发票仓储（删除、恢复、活跃查询、MRR 计算等） |
| `src/CoreBundle/Doctrine/Filter/ArchivableFilter.php` | 软删除 SQL 过滤器 |
| `src/CoreBundle/Doctrine/Filter/CompanyFilter.php` | 多租户公司过滤器 |

### 7.5 Foundry 测试工厂

| 文件 | 说明 |
|------|------|
| `src/InvoiceBundle/Test/Factory/InvoiceFactory.php` | 普通发票测试工厂 |
| `src/InvoiceBundle/Test/Factory/RecurringInvoiceFactory.php` | 定期发票测试工厂 |

### 7.6 单元测试

| 文件 | 说明 |
|------|------|
| `src/InvoiceBundle/Tests/Service/InvoiceStatusTransitionServiceTest.php` | 状态转换服务测试 |
| `src/InvoiceBundle/Tests/Listener/WorkFlowSubscriberTest.php` | 工作流监听器测试（paid/archive 副作用） |
| `src/InvoiceBundle/Tests/Manager/InvoiceManagerTest.php` | 发票管理器测试（Quote→Invoice、Recurring→Invoice） |
| `src/InvoiceBundle/Tests/Cloner/InvoiceClonerTest.php` | 发票克隆测试 |
| `src/InvoiceBundle/Tests/Entity/RecurringInvoiceTest.php` | 定期发票实体测试（hasInvoiceForDay 方法） |

### 7.7 功能测试

| 文件 | 说明 |
|------|------|
| `src/InvoiceBundle/Tests/Functional/OverdueInvoiceFlowTest.php` | 逾期发票完整流程测试（消息→状态变更） |
| `src/InvoiceBundle/Tests/Functional/Api/InvoiceTest.php` | 普通发票 API CRUD 测试 |
| `src/InvoiceBundle/Tests/Functional/Api/InvoiceTransitionTest.php` | 普通发票 API 状态转换测试 |
| `src/InvoiceBundle/Tests/Functional/Api/RecurringInvoiceTest.php` | 定期发票 API CRUD 测试 |
| `src/InvoiceBundle/Tests/Functional/Api/RecurringInvoiceTransitionTest.php` | 定期发票 API 状态转换+生成测试 |
| `src/ApiBundle/Test/ApiTestCase.php` | API 测试基类（含 requestDelete/requestPost 等方法） |

### 7.8 DataGrid 视图

| 文件 | 说明 |
|------|------|
| `src/InvoiceBundle/DataGrid/BaseInvoiceGrid.php` | 普通发票网格基类（列定义、Delete 批量操作） |
| `src/InvoiceBundle/DataGrid/InvoiceGrid.php` | 活跃普通发票网格（含 Archive 批量操作） |
| `src/InvoiceBundle/DataGrid/ArchivedInvoiceGrid.php` | 归档普通发票网格（含 Activate 恢复操作） |
| `src/InvoiceBundle/DataGrid/BaseRecurringInvoiceGrid.php` | 定期发票网格基类（列定义、Delete 批量操作） |
| `src/InvoiceBundle/DataGrid/RecurringInvoiceGrid.php` | 活跃定期发票网格 |
| `src/InvoiceBundle/DataGrid/CompletedRecurringInvoiceGrid.php` | 已完成定期发票网格 |
| `src/InvoiceBundle/DataGrid/ArchivedRecurringInvoiceGrid.php` | 归档定期发票网格（含恢复操作） |
