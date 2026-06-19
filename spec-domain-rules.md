# SolidInvoice 发票领域规则规格测试说明

本文档详细说明 SolidInvoice 项目中发票领域的规格测试如何约束业务规则，涵盖测试场景分类、领域行为定义以及测试夹具（Fixture）之间的差异。

---

## 1. 领域模型核心实体

### 1.1 普通发票（Invoice）

**代码位置**：[Invoice.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php#L118-L538)

| 字段 | 类型 | 约束规则 | 说明 |
|------|------|----------|------|
| `id` | Ulid | 主键，自动生成 | 使用 ULID 有序时间唯一标识 |
| `invoiceId` | string | 必填 | 业务可读的发票编号 |
| `uuid` | Uuid v7 | 不可写 | 额外的 UUID 标识（克隆时重新生成） |
| `status` | InvoiceStatus enum | 状态机管理 | 发票生命周期状态，不可直接写入（API 层只读） |
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
| `archived` | ?bool | 软删除标识 | `null`/`false`=未归档，`true`=已归档 |
| `invoiceTaxes` | Collection\<InvoiceTax\> | 级联删除 | 发票级税项 |

**注意**：`Invoice` 同时使用了 `Archivable` trait 和 `InvoiceStatusTrait`，两者都有 `isArchived()` 方法。通过 `Archivable::isArchived insteadof InvoiceStatusTrait` 解决冲突——即 `isArchived()` 返回的是 `archived` 字段的值，而非状态枚举判断。

### 1.2 定期发票（RecurringInvoice）

**代码位置**：[RecurringInvoice.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Entity/RecurringInvoice.php)

与普通发票共享 `BaseInvoice` 基类（金额、折扣、条款、备注等字段），额外包含：
- 调度配置（`RecurringOptions`：频率、结束条件等）
- 生成的普通发票集合（一对多）

### 1.3 状态枚举

#### 普通发票状态

**代码位置**：[InvoiceStatus.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Enum/InvoiceStatus.php#L18-L56)

| 枚举值 | value | 颜色标识 | 含义 |
|--------|-------|----------|------|
| `New` | `new` | gray | 新建（初始瞬态） |
| `Draft` | `draft` | secondary | 草稿（可编辑） |
| `Pending` | `pending` | yellow | 待付款（已发送给客户） |
| `Overdue` | `overdue` | red | 逾期（超过到期日未付款） |
| `Paid` | `paid` | green | 已付款 |
| `Cancelled` | `cancelled` | gray | 已取消 |
| `Archived` | `archived` | purple | 已归档 |
| `Active` | `active` | green | 活跃（预留状态） |

#### 定期发票状态

**代码位置**：[RecurringInvoiceStatus.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Enum/RecurringInvoiceStatus.php#L18-L53)

| 枚举值 | value | 颜色标识 | 含义 |
|--------|-------|----------|------|
| `New` | `new` | gray | 新建 |
| `Draft` | `draft` | secondary | 草稿 |
| `Active` | `active` | green | 活跃调度中 |
| `Paused` | `paused` | dark | 暂停 |
| `Complete` | `complete` | teal | 全部完成 |
| `Cancelled` | `cancelled` | gray | 已取消 |
| `Archived` | `archived` | purple | 已归档 |

---

## 2. 工作流（状态机）配置

**配置文件**：[workflow.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/config/packages/workflow.php#L29-L110)

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
 └──────┬──────┘ OVERDUE └─────────────┘  REOPEN │             │
        │                                         │             │
        │ TRANSITION_                             │             │
        │ CANCEL                                  │             │
        │                                         └──────┬──────┘
        ▼                                         ▲  TRANSITION_
 ┌─────────────┐                                   │  REOPEN
 │   Overdue   │───────────────────────────────────┘
 └──────┬──────┘  TRANSITION_CANCEL
        │
        │ TRANSITION_PAY
        ▼
 ┌─────────────┐
 │    Paid     │───┐
 └─────────────┘   │ TRANSITION_ARCHIVE
                   ▼
            ┌─────────────┐
            │  Archived   │◀── New, Draft, Cancelled, Paid
            └─────────────┘    均可通过 TRANSITION_ARCHIVE 到达
```

### 2.2 转换规则详细定义

| Transition 常量 | 名称 | 允许的源状态 | 目标状态 | 触发场景 |
|-----------------|------|------------|----------|----------|
| `TRANSITION_NEW` | `new` | New | Draft | 发票创建时自动执行 |
| `TRANSITION_ACCEPT` | `accept` | New, Draft | Pending | 确认/发送发票给客户 |
| `TRANSITION_CANCEL` | `cancel` | Draft, Pending, Overdue | Cancelled | 取消发票 |
| `TRANSITION_OVERDUE` | `overdue` | Pending | Overdue | 到期日超过仍未付款，由命令/消息自动触发 |
| `TRANSITION_PAY` | `pay` | Pending, Overdue | Paid | 收到付款 |
| `TRANSITION_REOPEN` | `reopen` | Cancelled | Draft | 重新打开已取消的发票 |
| `TRANSITION_ARCHIVE` | `archive` | New, Draft, Cancelled, Paid | Archived | 归档发票（软删除） |
| `edit` | `edit` | Cancelled, Draft, Pending, Overdue | Draft | 编辑后回到草稿状态 |

### 2.3 状态转换的副作用（WorkFlowSubscriber）

**监听器**：[WorkFlowSubscriber.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Listener/WorkFlowSubscriber.php#L32-L78)

监听 `workflow.invoice.entered` 和 `workflow.recurring_invoice.entered` 事件：

1. **`pay` 转换** → 自动设置 `paidDate = now()`
2. **`archive` 转换** → 调用 `archive()` 设置 `archived = true`（软删除标记）
3. **每次转换后** → 持久化实体（persist + flush）
4. **非 New 状态转换后** → 发送 `InvoiceStatusNotification` 通知

### 2.4 删除、归档与取消的区别

发票领域有三种"删除/关闭"语义，各自对应不同的业务场景和实现方式：

| 操作 | 实现方式 | 数据状态 | 是否可逆 | 触发入口 |
|------|----------|----------|----------|----------|
| **取消（Cancel）** | 状态机 `TRANSITION_CANCEL` | `status = cancelled`，`archived = null` | ✅ 可逆（reopen → Draft） | API 转换端点、UI 操作 |
| **归档（Archive）** | 状态机 `TRANSITION_ARCHIVE` 或 `InvoiceRepository::archiveInvoices()` | `status = archived`，`archived = true` | ✅ 可逆（restoreInvoices） | API 转换端点、DataGrid 批量操作、归档列表恢复 |
| **删除（Delete）** | Doctrine `$em->remove()` 硬删除 | 数据从数据库物理删除 | ❌ 不可逆 | API DELETE 端点、DataGrid 批量删除 |

**ArchivableFilter 过滤规则**：
- **过滤器**：[ArchivableFilter.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/CoreBundle/Doctrine/Filter/ArchivableFilter.php#L23-L53)
- **默认启用**：所有查询自动加上 `(archived IS NULL OR archived = false)` 条件
- **禁用场景**：查询归档列表时需要手动禁用过滤器（`getArchivedGridQuery()`、`deleteInvoices()`、`restoreInvoices()` 内部都会临时禁用）

**三种操作的约束边界**：
- 取消是业务决策（发票作废），数据保留完整，状态可流转
- 归档是数据治理（历史数据下沉），数据仍在但默认不显示，可恢复
- 删除是物理清除，彻底移除数据，不可恢复（DataGrid 中的 Delete 批量操作走硬删除）

---

## 3. 删除操作的实现细节

### 3.1 API 层删除

**API 端点**：`DELETE /api/invoices/{id}`

**实现机制**：API Platform 默认的 Delete 操作，走 Doctrine 的 `remove()` + `flush()`，即**硬删除**。

**测试验证**：
- 测试方法：[InvoiceTest::testDelete()](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Functional/Api/InvoiceTest.php#L157-L163)
- 验证方式：调用 `requestDelete()` 后断言响应状态码为 `204 No Content`，响应体为空
- 测试基类实现：[ApiTestCase::requestDelete()](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/ApiBundle/Test/ApiTestCase.php#L333-L351)

**注意事项**：
- 删除操作没有自定义 Processor，直接使用 API Platform 内置的 Doctrine 删除处理器
- 删除前不会检查状态（任何状态的发票都可以被硬删除）
- 级联关系：`lines` 设为 `orphanRemoval=true`，删除发票时行项目会自动删除
- `payments` 设为 `cascade: ['persist']`，删除发票时付款记录的行为由数据库外键 `onDelete` 决定

### 3.2 DataGrid 批量操作

**代码位置**：[BaseInvoiceGrid.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/DataGrid/BaseInvoiceGrid.php#L119-L127)

三种网格视图的批量操作差异：

| 网格 | 批量操作 | 实现方法 |
|------|----------|----------|
| 活跃发票列表（InvoiceGrid） | Delete + Archive | `deleteInvoices()` 硬删除 + `archiveInvoices()` 软删除 |
| 归档发票列表（ArchivedInvoiceGrid） | Delete + Activate | `deleteInvoices()` 硬删除 + `restoreInvoices()` 恢复 |

**删除方法实现**：
- [InvoiceRepository::deleteInvoices()](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L238-L255)
  - 先禁用 `archivable` 过滤器（确保能找到已归档的发票）
  - 遍历 ID 列表，对每个实体执行 `$em->remove()`
  - 最后 `flush()` 一次性提交
  - 恢复 `archivable` 过滤器

**归档方法实现**：
- [InvoiceRepository::archiveInvoices()](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L293-L310)
  - 遍历 ID，设置 `archived = true`
  - 不改变 `status` 字段（仅设置 archived 标记）

**恢复方法实现**：
- [InvoiceRepository::restoreInvoices()](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php#L315-L336)
  - 先禁用 `archivable` 过滤器（才能查询到已归档记录）
  - 设置 `archived = null`（恢复为未归档状态）
  - 恢复过滤器

### 3.3 状态机归档 vs Repository 归档

两种归档方式的区别：

| 维度 | 状态机 `TRANSITION_ARCHIVE` | `InvoiceRepository::archiveInvoices()` |
|------|---------------------------|---------------------------------------|
| 触发方式 | 通过状态转换 API | 直接调用 Repository 方法 |
| 状态变更 | `status` 变为 `archived` | 仅 `archived = true`，`status` 保持原值 |
| 副作用 | 触发 WorkFlowSubscriber（持久化、发通知） | 仅持久化，无通知 |
| 前置条件 | 源状态必须在允许列表内 | 无状态限制（任何状态都可归档） |
| 可逆性 | 需要通过状态机转换回来（无定义） | 通过 `restoreInvoices()` 恢复 |

---

## 4. 规格测试场景分类与领域行为

### 4.1 单元测试层级

#### 4.1.1 状态转换服务测试

**代码位置**：[InvoiceStatusTransitionServiceTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Service/InvoiceStatusTransitionServiceTest.php#L38-L115)

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testApplyTransition` | Pending → Overdue 转换 | 状态机 `can()` 返回 true 时，`apply()` 被正确调用，实体被持久化到数据库 |
| `testApplyTransitionThrowsExceptionWhenTransitionNotAllowed` | Paid 状态尝试 overdue 转换 | 非法转换抛出 `InvalidTransitionException`，不执行 apply |
| `testCanApplyTransition` | 查询是否可转换 | 服务委托给状态机的 `can()` 方法判断 |
| `testGetAvailableTransitions` | 获取可用转换列表 | 从状态机获取 `getEnabledTransitions()`，返回转换名称数组 |

**夹具策略**：使用 `Mockery` 模拟 `StateMachine`，手动构造 `Invoice` + `ClientFactory::createOne()` 真实实体，配合 `DoctrineTestTrait` 进行持久化验证。

#### 4.1.2 状态转换监听器测试

**代码位置**：[WorkFlowSubscriberTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Listener/WorkFlowSubscriberTest.php#L35-L70)

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testInvoicePaid` | `pay` transition 触发 | 监听器自动设置 `paidDate`，实体被持久化，通知被发送 1 次 |
| `testInvoiceArchive` | `archive` transition 触发 | 监听器调用 `archive()`，`isArchived()` 返回 true，实体被持久化 |

**夹具策略**：`Mockery` 模拟 `NotificationManager`，断言 `sendNotification()` 调用次数。

#### 4.1.3 发票管理器（InvoiceManager）测试

**代码位置**：[InvoiceManagerTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Manager/InvoiceManagerTest.php#L114-L353)

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testCreateFromQuote` | 从报价单生成发票 | 复制金额、折扣、条款、备注、客户、联系人；行项目类型从 Quote Line 转为 Invoice Line；生成新 UUID；状态初始为 null（后续由 create() 方法流转） |
| `testQuoteToInvoiceCopiesLineTaxSnapshotsAsNewRows` | 报价→发票时税快照复制 | LineTax 和 InvoiceTax 作为**新对象**复制（不共享引用），快照字段（name、rate、type、sequence、direction、note）保持一致；转换时不冻结（`freezeSnapshots=false`，`snapshottedAt = null`），因为草稿发票仍可编辑 |
| `testRecurringGenerationFreezesSnapshotsAtGenerationTime` | 定期发票生成时税快照冻结 | 从定期发票生成时 `freezeSnapshots=true`，`snapshottedAt != null`；冻结后调用 `snapshotFrom()` 修改被拒绝（不变性保护） |
| `testCreateFromRecurring` | 从定期发票生成发票 | 除了复制字段外，还对行描述执行占位符替换：`{day}` `{day_name}` `{month}` `{year}` → 替换为生成日期的对应值 |

**夹具策略**：手动 `new` 构造领域对象（Client、Quote、Line、Tax、LineTax 等），设置所有必要字段。`StateMachine` 使用真实实例（最小化定义：new → draft），`ClockInterface` 桩化为固定时间 `2024-01-15 10:30:00` 以测试占位符替换。

#### 4.1.4 发票克隆（Cloner）测试

**代码位置**：[InvoiceClonerTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Cloner/InvoiceClonerTest.php#L46-L214)

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testClone` | 克隆普通发票 | 新发票获得：新 ID（null）、新 UUID、新 invoiceId（通过 `BillingIdGenerator` 重新生成）；金额、折扣、客户、行项目（含税快照）值相等但非同一引用 |
| `testCloneWithRecurring` | 克隆定期发票 | 同时复制定期调度配置（`dateStart`、`dateEnd`、`recurringOptions`）；行类型转换为普通 Invoice Line |

**夹具策略**：手动构造对象 + Mockery 模拟 `InvoiceManager.create()`（不实际持久化）。

#### 4.1.5 定期发票实体行为测试

**代码位置**：[RecurringInvoiceTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Entity/RecurringInvoiceTest.php#L25-L75)

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testHasInvoiceForDayReturnsTrueWhenInvoiceExistsForDate` | 当天已生成发票 | `hasInvoiceForDay()` 按**日期（忽略时间）**匹配，返回 true |
| `testHasInvoiceForDayReturnsFalseWhenNoInvoiceExistsForDate` | 当天未生成发票 | 不同日期返回 false |
| `testHasInvoiceForDayReturnsFalseWhenNoInvoicesExist` | 无任何发票 | 返回 false |
| `testHasInvoiceForDayChecksMultipleInvoices` | 多张发票多日期 | 遍历检查所有发票的日期 |

**夹具策略**：纯内存对象构造，`CarbonImmutable::parse()` 指定日期。

---

### 4.2 功能测试层级

#### 4.2.1 逾期发票完整流程

**代码位置**：[OverdueInvoiceFlowTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Functional/OverdueInvoiceFlowTest.php#L51-L260)

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testCompleteOverdueFlow` | 完整逾期检测→状态变更流程 | Dispatch `MarkInvoiceOverdue` 消息后，Invoice 状态从 Pending 变为 Overdue |
| `testOverdueFlowWithMultipleCompanies` | 多租户隔离 | Company1 和 Company2 的发票各自独立，切换 CompanySelector 后状态正确更新 |
| `testIdempotency` | 幂等性保证 | 同一消息重复 Dispatch 两次，最终状态仍为 Overdue（不会报错或进入异常状态） |
| `testRepositoryGetPendingOverdueInvoices` | 逾期查询规则 | `getPendingOverdueInvoices()` 仅返回：① status=Pending ② due < today ③ due != null 的发票；排除 Paid、已 Overdue、未到期、无到期日的发票 |

**夹具策略**：使用 Foundry Factory（`InvoiceFactory`、`ClientFactory`、`ContactFactory`、`CompanyFactory`），配合 `EnsureApplicationInstalled` trait。显式设置 `due = CarbonImmutable::yesterday()` 构造逾期条件。

#### 4.2.2 API 资源测试

**代码位置**：[InvoiceTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Functional/Api/InvoiceTest.php#L44-L315)

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testGetCollection` | 获取发票列表 | 返回 JSON-LD Collection 格式，包含 @context/@id/@type |
| `testGetInvoicesForClient` | 按客户子资源查询 | `/api/clients/{id}/invoices` 返回该客户的发票 |
| `testCannotAccessInvoiceFromDifferentCompany` | 跨公司数据隔离 | 其他 Company 的发票通过 API 查询返回 404（CompanyFilter 生效） |
| `testCreate` | 创建发票 | POST 后返回 ULID id、UUID v7、状态为 `draft`；折扣计算正确（100 * 90% = balance 90） |
| `testGet` | 获取单张发票 | 所有字段序列化完整，行项目嵌套展示 |
| `testEdit` | PATCH 更新发票 | 支持修改折扣和行项目，金额重新计算 |
| `testDelete` | 删除发票 | DELETE 请求返回 `204 No Content`，实体从数据库物理删除（硬删除） |

**夹具策略**：Foundry Factory 构造前置数据，API 通过 `ApiTestCase` 的 `requestPost/requestGet/requestPatch/requestDelete` 方法发送 HTTP 请求。

#### 4.2.3 API 状态转换测试

**代码位置**：[InvoiceTransitionTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Functional/Api/InvoiceTransitionTest.php#L38-L115)

| 测试方法 | 测试场景 | 领域行为约束 |
|----------|----------|------------|
| `testAcceptInvoice` | Draft → Pending | POST `/api/invoices/{id}/transitions/accept` 返回 `status: pending` |
| `testCancelInvoice` | Draft → Cancelled | POST `/api/invoices/{id}/transitions/cancel` 返回 `status: cancelled` |
| `testInvalidTransition` | Draft → Pay（非法） | 非法转换返回 `422 Unprocessable Entity` |
| `testTransitionOnForeignCompanyInvoice` | 跨公司转换 | 其他 Company 的发票返回 404，无法跨租户操作 |

**夹具策略**：`InvoiceFactory` 指定 `status: InvoiceStatus::Draft` 构造前置状态，`CompanyFactory` 构造多租户场景。

---

## 5. 测试夹具（Fixture）差异对比

### 5.1 三种夹具构造方式

SolidInvoice 发票测试中使用 **三种不同的夹具构造策略**，各有适用场景：

| 方式 | 技术 | 适用测试类型 | 特点 |
|------|------|------------|------|
| **A. 手动 new 构造** | `new Invoice()` + setter | 单元测试（Manager、Cloner、Entity） | 完全控制、无依赖、执行快；需要手动填充所有必填字段 |
| **B. Mockery 模拟** | `Mockery::mock(StateMachine::class)` | 单元测试（Service、Listener） | 隔离外部依赖，断言交互行为（调用次数/参数）；不执行真实业务逻辑 |
| **C. Foundry Factory** | `InvoiceFactory::createOne([...])` | 功能测试、集成测试 | 真实持久化到数据库（测试环境 SQLite），自动生成默认值，支持 Proxy 包装 |

### 5.2 Foundry Factory 详细对比

#### 5.2.1 InvoiceFactory 默认值

**代码位置**：[InvoiceFactory.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Test/Factory/InvoiceFactory.php#L66-L99)

| 字段 | 默认值策略 | 可覆盖 |
|------|----------|--------|
| `client` | `ClientFactory::new()` 延迟创建 | 是 |
| `due` | Faker 随机日期 | 是（逾期测试设为 yesterday） |
| `paidDate` | Faker 随机日期 | 是（未付款设为 null） |
| `status` | 随机 `InvoiceStatus::cases()` | 是（转换测试指定 Draft） |
| `terms` / `notes` | Faker 随机文本 | 是 |
| `balance` / `total` / `baseTotal` / `tax` | Faker 随机 BigInteger | 是 |
| `discount` | 随机类型（百分比/固定金额）+ 随机值 | 是（金额测试指定 type=percentage, value=0） |

#### 5.2.2 RecurringInvoiceFactory 默认值

**代码位置**：[RecurringInvoiceFactory.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Test/Factory/RecurringInvoiceFactory.php#L69-L105)

与 InvoiceFactory 相比的差异：

| 字段 | 差异说明 |
|------|----------|
| `status` | 使用 `RecurringInvoiceStatus::cases()`（Active/Paused/Complete 等） |
| `dateStart` | 调度开始日期（Faker 随机） |
| `recurringOptions` | 默认 `WEEKLY` + `AFTER 1 occurrence` + `days=[1]` |

#### 5.2.3 Factory 场景化覆盖示例

从实际测试中提取的典型 Factory 用法：

| 测试场景 | Factory 覆盖参数 | 目的 |
|----------|----------------|------|
| 逾期流程测试 | `['status' => Pending, 'due' => CarbonImmutable::yesterday()]` | 构造"已过期但未付款"的前置条件 |
| 状态转换测试 | `['status' => Draft]` | 确保转换起点正确 |
| 已付款发票（负例） | `['status' => Paid, 'due' => CarbonImmutable::yesterday()]` | 在逾期查询测试中作为负例（不应被查询到） |
| 金额精确计算 | `['discount' => new Discount()->setType(percentage)->setValue(0)]` | 排除折扣干扰，精确验证行项目金额 |
| 多租户隔离 | `['company' => $otherCompany]` | 构造跨 Company 的数据，验证 CompanyFilter |
| 带联系人的发票 | 先创建 `ContactFactory::createMany(...)`，再通过 `'users' => $contacts` 传入 | 满足 `@Assert\Count(min=1)` 约束，用于提醒/邮件测试 |

### 5.3 夹具构造方式选择矩阵

| 测试目标 | 推荐方式 | 原因 |
|----------|----------|------|
| 验证实体 getter/setter、纯计算逻辑 | A. 手动 new | 无需持久化，快速执行 |
| 验证状态机协作、异常分支 | B. Mock StateMachine | 精确控制 `can()` 返回值，隔离状态机本身 |
| 验证监听器副作用（paidDate、archive） | A + B 混合 | 真实实体 + Mock NotificationManager |
| 验证 Manager 金额复制、快照 | A. 手动 new + 桩化 Clock | 精确控制时间，验证快照冻结逻辑 |
| 验证 API 端点、序列化 | C. Foundry Factory | 需要真实数据库、完整 DI 容器 |
| 验证完整流程（消息→状态变更） | C. Foundry Factory | 需要 Messenger、Doctrine、Workflow 全链路 |
| 验证多租户隔离 | C. Foundry Factory + CompanySelector | 需要数据库级别的 CompanyFilter |
| 验证查询逻辑（Repository） | C. Foundry Factory 批量造数据 | 需要构造多种边界条件的测试数据 |
| 验证删除操作（API 层） | C. Foundry Factory | 需要真实数据库持久化后再删除 |

---

## 6. 领域不变量与测试覆盖映射

下表总结发票领域的核心业务不变量（必须永远为真的规则）及其对应的测试保障：

| 领域不变量 | 代码位置 | 测试保障 |
|----------|----------|----------|
| **状态转换必须合法** | 状态机配置 `workflow.php` | `InvoiceStatusTransitionServiceTest::testApplyTransitionThrowsExceptionWhenTransitionNotAllowed` + `InvoiceTransitionTest::testInvalidTransition` |
| **付款后必须记录付款日期** | `WorkFlowSubscriber::onWorkflowTransitionApplied` | `WorkFlowSubscriberTest::testInvoicePaid` |
| **状态机归档必须设置软删除标记** | 同上 | `WorkFlowSubscriberTest::testInvoiceArchive` |
| **发票至少有 1 个联系人** | `Invoice::$users` `@Assert\Count(min=1)` | Form 类型测试 + API 创建测试 |
| **发票至少有 1 行明细** | `Invoice::$lines` `@Assert\Count(min=1)` | 同上 |
| **跨公司数据不可见（查询）** | Doctrine CompanyFilter | `InvoiceTest::testCannotAccessInvoiceFromDifferentCompany` |
| **跨公司转换不可操作** | Doctrine CompanyFilter | `InvoiceTransitionTest::testTransitionOnForeignCompanyInvoice` |
| **逾期状态幂等** | Handler 内状态机检查 | `OverdueInvoiceFlowTest::testIdempotency` |
| **逾期查询仅包含 Pending 且到期日<今天** | `InvoiceRepository::getPendingOverdueInvoices` | `OverdueInvoiceFlowTest::testRepositoryGetPendingOverdueInvoices` |
| **克隆后 UUID/InvoiceId 必须唯一** | `Invoice::__clone()` + `InvoiceCloner` | `InvoiceClonerTest::testClone` |
| **定期生成发票时描述占位符被替换** | `InvoiceManager::createFromRecurring` | `InvoiceManagerTest::testCreateFromRecurring`（桩化 Clock 为 2024-01-15，验证占位符） |
| **定期生成的税快照不可修改** | `TaxSnapshotCopier::copyLineTax(freezeAt)` | `InvoiceManagerTest::testRecurringGenerationFreezesSnapshotsAtGenerationTime` |
| **报价→发票的税是独立副本** | `InvoiceManager::createFromObject` | `InvoiceManagerTest::testQuoteToInvoiceCopiesLineTaxSnapshotsAsNewRows` |
| **定期发票不重复生成同日发票** | `RecurringInvoice::hasInvoiceForDay()` | `RecurringInvoiceTest` 四个用例 |
| **状态变更必须触发通知（New 除外）** | `WorkFlowSubscriber` | `WorkFlowSubscriberTest` 断言 `sendNotification()` 调用次数 |
| **API 删除返回 204 No Content** | API Platform Delete 操作 | `InvoiceTest::testDelete` |
| **已归档数据默认不显示** | ArchivableFilter SQL 过滤器 | 间接由各列表查询测试保障（InvoiceGrid 只显示未归档） |
| **删除操作需禁用归档过滤器** | `InvoiceRepository::deleteInvoices` | 代码实现保证（无直接单元测试） |

---

## 7. 关键文件索引

### 7.1 实体与枚举

| 文件 | 说明 |
|------|------|
| [Invoice.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Entity/Invoice.php) | 普通发票实体 |
| [BaseInvoice.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Entity/BaseInvoice.php) | 发票基类（金额、折扣等共享字段） |
| [RecurringInvoice.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Entity/RecurringInvoice.php) | 定期发票实体 |
| [InvoiceStatus.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Enum/InvoiceStatus.php) | 普通发票状态枚举 |
| [RecurringInvoiceStatus.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Enum/RecurringInvoiceStatus.php) | 定期发票状态枚举 |
| [Archivable.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/CoreBundle/Traits/Entity/Archivable.php) | 软删除 trait |

### 7.2 状态机与工作流

| 文件 | 说明 |
|------|------|
| [workflow.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/config/packages/workflow.php) | 状态机配置（invoice、recurring_invoice、quote 三个工作流） |
| [Graph.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Model/Graph.php) | 转换名称常量 |
| [WorkFlowSubscriber.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Listener/WorkFlowSubscriber.php) | 状态转换事件监听器（副作用处理） |

### 7.3 核心服务

| 文件 | 说明 |
|------|------|
| [InvoiceManager.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Manager/InvoiceManager.php) | 发票管理器（创建、从报价/定期生成） |
| [InvoiceStatusTransitionService.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Service/InvoiceStatusTransitionService.php) | 状态转换服务 |
| [InvoiceCloner.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Cloner/InvoiceCloner.php) | 发票克隆器 |

### 7.4 仓储与过滤器

| 文件 | 说明 |
|------|------|
| [InvoiceRepository.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Repository/InvoiceRepository.php) | 发票仓储（删除、归档、恢复等方法） |
| [ArchivableFilter.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/CoreBundle/Doctrine/Filter/ArchivableFilter.php) | 软删除 SQL 过滤器 |
| [CompanyFilter.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/CoreBundle/Doctrine/Filter/CompanyFilter.php) | 多租户公司过滤器 |

### 7.5 Foundry 测试工厂

| 文件 | 说明 |
|------|------|
| [InvoiceFactory.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Test/Factory/InvoiceFactory.php) | 普通发票测试工厂 |
| [RecurringInvoiceFactory.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Test/Factory/RecurringInvoiceFactory.php) | 定期发票测试工厂 |

### 7.6 单元测试

| 文件 | 说明 |
|------|------|
| [InvoiceStatusTransitionServiceTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Service/InvoiceStatusTransitionServiceTest.php) | 状态转换服务测试 |
| [WorkFlowSubscriberTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Listener/WorkFlowSubscriberTest.php) | 工作流监听器测试 |
| [InvoiceManagerTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Manager/InvoiceManagerTest.php) | 发票管理器测试 |
| [InvoiceClonerTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Cloner/InvoiceClonerTest.php) | 发票克隆测试 |
| [RecurringInvoiceTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Entity/RecurringInvoiceTest.php) | 定期发票实体测试 |

### 7.7 功能测试

| 文件 | 说明 |
|------|------|
| [OverdueInvoiceFlowTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Functional/OverdueInvoiceFlowTest.php) | 逾期发票完整流程测试 |
| [InvoiceTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Functional/Api/InvoiceTest.php) | 发票 API CRUD 测试 |
| [InvoiceTransitionTest.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/Tests/Functional/Api/InvoiceTransitionTest.php) | 发票 API 状态转换测试 |
| [ApiTestCase.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/ApiBundle/Test/ApiTestCase.php) | API 测试基类（含 requestDelete 等方法） |

### 7.8 DataGrid 视图

| 文件 | 说明 |
|------|------|
| [BaseInvoiceGrid.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/DataGrid/BaseInvoiceGrid.php) | 发票网格基类（含 Delete 批量操作） |
| [InvoiceGrid.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/DataGrid/InvoiceGrid.php) | 活跃发票网格（含 Archive 批量操作） |
| [ArchivedInvoiceGrid.php](file:///d:/fz/0601-2/solo-dogfeeding/code/44-SolidInvoice/src/InvoiceBundle/DataGrid/ArchivedInvoiceGrid.php) | 归档发票网格（含 Activate 恢复操作） |
