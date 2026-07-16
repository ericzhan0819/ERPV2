# PLAN_v1.3.md — ERPV2 v1.3 薪資結算

本清單對應 `企劃書_v1.3.md`。

v1.1 已以 `v1.1-smoke-passed` 封版，v1.2 已以 `v1.2-smoke-passed` 封版，不回開前述版本範圍。

v1.3 目標：依正式成交、approved-only 單車毛利、收車人／賣車人歸屬與跨級獎金方案，自動算出每位員工每月實發薪資，經 admin 確認後建立不可重複的薪資支出 Money Entry。

---

## 0. 前置準備與範圍確認

- [x] 閱讀 `企劃書.md`
- [x] 閱讀 `企劃書_v1.1.md`
- [x] 閱讀 `企劃書_v1.2.md`
- [x] 閱讀 `企劃書_v1.3.md`
- [x] 閱讀 `PLAN_v1.3.md`
- [x] 閱讀 `docs/current-state.md`
- [x] 閱讀 `docs/v1.2-smoke-report.md`
- [x] 閱讀 `docs/v1.2-handoff.md`
- [x] 閱讀 `backend/API.md`
- [x] 閱讀 `UI.md`
- [x] 檢查 User／Vehicle／MoneyEntry／Dashboard／Audit Log 現有 Model、Service、Policy、Resource、Request、routes 與 tests
- [x] 確認 branch 為 `main`
- [x] 確認工作樹乾淨（開始本階段實作前）
- [x] 確認 `v1.2-smoke-passed` tag 存在
- [x] 確認本階段不實作打卡、排班、請假、法定申報、完整 HR 或正式會計

---

## 1. 資料模型與 Migration

### 1.1 `salary_profiles`

新增員工目前薪資設定表：

- [x] `id`
- [x] `user_id`，unique，FK users，restrict-on-delete
- [x] `base_salary`，unsigned bigint，default 0
- [x] `fixed_allowance`，unsigned bigint，default 0
- [x] `labor_insurance_deduction`，unsigned bigint，default 0
- [x] `health_insurance_deduction`，unsigned bigint，default 0
- [x] `commission_enabled`，boolean，default true
- [x] `is_active`，boolean，default true
- [x] timestamps
- [x] 所有金額使用整數，不使用 decimal/float
- [x] 不在此表保存歷史月份，歷史由 settlement snapshot 保存

### 1.2 `commission_plans`

- [x] `id`
- [x] `name`
- [x] `effective_from`
- [x] `company_reserve_bps`
- [x] `purchase_bonus_bps`
- [x] `is_active`
- [x] `created_by`，FK users，restrict-on-delete
- [x] timestamps
- [x] `company_reserve_bps`、`purchase_bonus_bps` 各自限制在 0～10000；另以最高賣車 tier 驗證 `purchase_bonus_bps + sales_bonus_bps <= 10000`，確保分配池不超配
- [x] 一旦被 salary period 引用，不得直接修改計算欄位

### 1.3 `commission_plan_tiers`

- [x] `id`
- [x] `commission_plan_id`，FK，cascade-on-delete 僅限未使用方案
- [x] `min_sales_count`，unsigned integer
- [x] `sales_bonus_bps`，unsigned integer
- [x] `sort_order`
- [x] timestamps
- [x] unique：`commission_plan_id, min_sales_count`
- [x] 同方案至少一個 tier
- [x] `min_sales_count` 必須遞增且第一級從 1 開始
- [x] 比例不得造成 `purchase_bonus_bps + sales_bonus_bps > 10000`

初始 seeder：

- [x] 建立 `2026 標準薪資方案`
- [x] 公司營運保留 `4000 bps`
- [x] 收車獎金 `2000 bps`（作用於分配池）
- [x] 1 台起 `2000 bps`
- [x] 3 台起 `3000 bps`
- [x] 5 台起 `5000 bps`
- [x] Seeder 可重跑，不重複建立

### 1.4 Vehicle 收車人／賣車人

新增 vehicles 欄位：

- [x] `purchase_agent_id`，nullable FK users，restrict-on-delete
- [x] `sales_agent_id`，nullable FK users，restrict-on-delete
- [x] 建立 index
- [x] Model relationship：`purchaseAgent()`／`salesAgent()`
- [x] Resource 依權限回傳必要資料
- [x] 不使用 `created_by`／`updated_by` 自動 backfill
- [x] migration 不做 heuristic 歷史推定
- [x] 歷史已售車輛缺歸屬時由 admin 人工補齊

### 1.5 `salary_periods`

- [x] `id`
- [x] `period_month`，date，以每月第一天表示，unique
- [x] `commission_plan_id`，FK，restrict-on-delete
- [x] `status`：draft／confirmed／paid
- [x] `created_by`
- [x] `confirmed_by`／`confirmed_at`
- [x] `paid_by`／`paid_at`
- [x] `payment_date`
- [x] `cash_account_id`
- [x] `idempotency_key`，nullable unique
- [x] timestamps
- [x] DB constraint 或 Service 嚴格限制狀態值

### 1.6 `salary_settlements`

- [x] `id`
- [x] `salary_period_id`
- [x] `user_id`
- [x] `eligible_sales_count`
- [x] `sales_bonus_bps_snapshot`
- [x] `base_salary_snapshot`
- [x] `fixed_allowance_snapshot`
- [x] `labor_insurance_deduction_snapshot`
- [x] `health_insurance_deduction_snapshot`
- [x] `purchase_bonus_total`
- [x] `sales_bonus_total`
- [x] `manual_addition_total`
- [x] `manual_deduction_total`
- [x] `gross_pay`
- [x] `deduction_total`
- [x] `net_pay` 使用 signed bigint，草稿可忠實保存負數；confirmed 前再阻擋
- [x] `money_entry_id`，nullable unique，FK money_entries，restrict-on-delete
- [x] timestamps
- [x] unique：`salary_period_id, user_id`

### 1.7 `salary_settlement_items`

- [x] `id`
- [x] `salary_settlement_id`
- [x] `type`
- [x] `vehicle_id`，nullable，FK vehicles，restrict-on-delete
- [x] `amount`
- [x] `description`
- [x] `calculation_snapshot`，JSON nullable
- [x] `created_by`，nullable FK users，restrict-on-delete
- [x] timestamps
- [x] 車輛收車獎金同一 settlement／vehicle／type 不可重複
- [x] 車輛賣車獎金同一 settlement／vehicle／type 不可重複
- [x] 自動項目與手動項目可明確區分

### 1.8 MoneyEntry 薪資來源

- [x] 新增 `MoneyEntry::SOURCE_SALARY_SETTLEMENT = salary_settlement`
- [x] `source_type` migration／validation 支援新值
- [x] salary settlement 來源不得透過一般 Money Entry CRUD 修改或刪除
- [x] salary settlement 來源不走 manager／sales pending 上報流程，只能由 admin 發薪建立為 approved
- [x] `MoneyEntryResource` 不因新 source type 洩漏薪資關聯資料

---

## 2. Model、關聯與狀態常數

- [x] 新增 `SalaryProfile` model
- [x] 新增 `CommissionPlan` model
- [x] 新增 `CommissionPlanTier` model
- [x] 新增 `SalaryPeriod` model
- [x] 新增 `SalarySettlement` model
- [x] 新增 `SalarySettlementItem` model
- [x] 所有 fillable／casts 明確設定
- [x] 金額 casts 為 integer
- [x] 日期／時間 casts 明確設定
- [x] SalaryPeriod 狀態常數集中管理
- [x] SalarySettlementItem type 常數集中管理
- [x] User relationships：salaryProfile、salarySettlements、purchaseAgentVehicles、salesAgentVehicles
- [x] Vehicle relationships：purchaseAgent、salesAgent、salarySettlementItems
- [x] CommissionPlan relationships：tiers、salaryPeriods
- [x] SalaryPeriod relationships：plan、settlements、cashAccount、createdBy／confirmedBy／paidBy
- [x] SalarySettlement relationships：period、user、items、moneyEntry

---

## 3. 薪資設定與獎金方案 Service

### 3.1 SalaryProfileService

- [x] admin 可查詢所有薪資設定
- [x] admin 可新增／更新薪資設定
- [x] manager／sales 一律 403
- [x] 只允許 active user 建立 active salary profile
- [x] `base_salary`／津貼／勞保／健保皆為非負整數
- [x] 不允許前端寫入計算結果或歷史 snapshot
- [x] 修改薪資設定不回改已存在的 confirmed／paid settlement
- [x] 寫入 Audit Log，但不把完整薪資金額 payload 複製進 audit metadata

### 3.2 CommissionPlanService

- [x] admin 可建立方案
- [x] manager／sales 一律 403
- [x] tiers 驗證完整
- [x] 最高 tier 加收車比例不得超過分配池 100%
- [x] 生效日與方案選取規則明確
- [x] 已被 salary period 引用的方案不可修改／刪除
- [x] 規則變更需建立新方案
- [x] 初始方案可由 Seeder 建立

---

## 4. 車輛獎金歸屬

### 4.1 新車流程

- [x] StoreVehicleRequest 加入 `purchase_agent_id`
- [x] 新增車輛 UI 可選收車人
- [x] 收車人選項只列 active 使用者；`commission_enabled` 屬結算資格，不得阻斷車輛事實歸屬或核心營運流程
- [x] admin／manager 建車必須明確指定收車人，或依企劃允許暫存但上架前必填；實作前二選一並在文件鎖定（採建車時必填）
- [x] 建車 idempotency payload 必須納入 `purchase_agent_id`
- [x] 同 key 不同收車人必須 reject，不得 silent replay

### 4.2 銷售流程

- [x] ReserveVehicleRequest 或獨立 attribution action 支援 `sales_agent_id`
- [x] sales 自己執行銷售流程時後端可安全預設為目前登入者
- [x] admin／manager 代登時必須選實際賣車人
- [x] reservation idempotency payload 納入 `sales_agent_id`
- [x] 同 key 不同賣車人必須 reject
- [x] 成交結案前必須已有賣車人

### 4.3 歷史車輛人工補資料

- [x] admin-only `PATCH /api/vehicles/{vehicle}/commission-attribution`
- [x] 可補 `purchase_agent_id`／`sales_agent_id`
- [x] manager／sales 一律 403
- [x] confirmed／paid salary period 已引用的車輛不得改歸屬
- [x] 修改寫入 Audit Log
- [x] UI 有「待補獎金歸屬」清單

---

## 5. 純計算器：SalaryCommissionCalculator

計算公式必須集中在純 Service／Calculator，不可散落於 Controller、Model accessor 或前端。

### 5.1 單車基礎

- [x] 只使用 approved MoneyEntry
- [x] `income_total - expense_total = gross_profit`
- [x] gross_profit <= 0 時所有獎金為 0
- [x] 公司營運保留以整數公式計算
- [x] 分配池 = 毛利 - 公司營運保留
- [x] 收車獎金以分配池比例計算
- [x] 賣車獎金以分配池比例計算
- [x] 公司剩餘分配額吸收整數除法餘數
- [x] 驗證總和恆等於 gross_profit
- [x] 禁止使用 float

### 5.2 跨級階梯

- [x] 依同一 `sales_agent_id`、同一結算月份的 eligible sold vehicles 計數
- [x] 0 台為 0%
- [x] 1～2 台整月 20%
- [x] 3～4 台整月 30%
- [x] 5 台以上整月 50%
- [x] 第五台成立後，前四台同時按 50% 重算
- [x] 不採逐台 marginal tier
- [x] tier 由 CommissionPlanTier 查詢，不在程式散落 magic number
- [x] 支援顯示距離下一級還差幾台
- [x] 支援顯示跨級後預估增加金額

### 5.3 四捨五入／餘數

- [x] 比例使用 basis points
- [x] 單一獎金項目向下取整
- [x] 所有餘數歸公司剩餘分配額
- [x] 大額金額不 overflow
- [x] 加總結果可重現且 deterministic

### 5.4 Calculator 單元測試

- [x] 毛利 100,000／40%／20%／50% 得出 40,000／12,000／30,000／18,000
- [x] 公司最終取得 58,000
- [x] 1 台套 20%
- [x] 3 台全部套 30%
- [x] 5 台全部套 50%
- [x] 同人收車又賣車可領兩種獎金
- [x] 收車人與賣車人不同時分配正確
- [x] 零毛利獎金為 0
- [x] 負毛利獎金為 0，不產生負薪資項目
- [x] 無法整除時餘數歸公司剩餘分配額
- [x] 規則不超配
- [x] 混合盈虧批次以 `company_net` 維持總毛利分配恆等式
- [x] `commission_enabled=false` 依收車／賣車角色分別歸零，不吞掉另一位人員獎金
- [x] 結算月份參數與每台 `sold_at` 一致性防禦
- [x] 薪資模組月份契約統一為 `YYYY-MM`，方案查詢明確轉月首日
- [x] 計算器只接受已儲存且 tiers 合法的方案，允許既有 period 沿用綁定版本

---

## 6. 薪資資格與異常檢查

新增 SalaryEligibilityService 或等價集中檢查。

每台納入車輛必須：

- [x] `gross_profit <= 0` 車輛仍列明細，但不計入賣車跨級台數
- [x] 全系統業務日期與薪資月份採 `Asia/Taipei`，包含 Dashboard、預設日期、列印與 API datetime offset

- [x] `status=sold`
- [x] `sold_at` 落在 period month
- [x] 有 `purchase_agent_id`
- [x] 有 `sales_agent_id`
- [x] 不存在 pending MoneyEntry
- [x] approved 訂金／尾款扣退款達成交價（沿用既有 closeSale 不變量，再做 defensive check）
- [x] approved 購車付款總額等於 `purchase_price`
- [x] 無 `legacy_unknown` 綁定收支
- [x] 尚未被其他 confirmed／paid salary period 引用

異常處理：

- [x] 不可靜默略過
- [x] 顯示車號、問題與修正入口
- [x] draft 可存在異常
- [x] confirmed 前所有阻擋異常必須清空
- [x] 不以 category/status heuristic 自動修資料
- [x] 確認入口必須在 transaction 內重新選取完整月份並鎖定候選車，不接受舊草稿集合
- [x] 所有綁車 MoneyEntry 核准共用車輛列鎖，與薪資確認互斥

---

## 7. SalaryPeriodService：草稿、重算、確認

### 7.1 建立草稿

- [x] 每月只能一個 SalaryPeriod
- [x] `period_month` 不得晚於 `Asia/Taipei` 目前月份，避免未來月份被提前確認鎖定
- [x] 自動選取該月份有效 Commission Plan
- [x] 建立草稿時才以 `findEffectiveForMonthForUpdate()` 鎖定並選取當下最新有效方案，並固定寫入 `commission_plan_id`
- [x] 若無有效方案，422 阻擋
- [x] 建立 active salary profiles 對應的 SalarySettlement
- [x] 複製薪資設定 snapshot
- [x] 依 eligible sold vehicles 產生獎金項目
- [x] 產生底薪、固定津貼、勞保、健保項目
- [x] 正確彙總 gross／deduction／net
- [x] 無薪資設定的 active user 不得被靜默納入；採明確排除規則，只有 active user + active salary profile 會建立 settlement

### 7.2 重算草稿

- [x] 只有 draft 可重算
- [x] 重新產生自動項目
- [x] 手動加扣項不得被靜默刪除
- [x] 跨級後所有該業務當月賣車獎金重新套用
- [x] 重算沿用 SalaryPeriod 已綁定的 `commission_plan_id`，不得因後來新增回溯生效方案而換版或阻斷
- [x] 重算結果 deterministic
- [x] 需 DB transaction
- [x] 與 attribution 修改、MoneyEntry approval 競態共用候選車 `lockForUpdate`

### 7.3 手動加扣項

- [x] admin-only
- [x] draft-only
- [x] type 只允許其他加給／其他扣款
- [x] 金額正整數
- [x] description 必填
- [x] 新增／刪除後重算 totals
- [x] 不可直接覆寫自動獎金項目
- [x] 寫入 Audit Log

### 7.4 確認結算

- [x] admin-only
- [x] 只有 draft 可確認
- [x] transaction + lockForUpdate
- [x] confirmed／paid 的驗算、列印與稽核只讀 settlement／item snapshot，不重新選取「目前最新」方案
- [x] transaction 內重跑資格檢查
- [x] transaction 內重算並比對 snapshot，避免 stale preview 被確認
- [x] 所有公式 invariant 通過
- [x] net_pay 不得小於 0（只在確認點守門；草稿允許負數並列出待處理員工）
- [x] 狀態改為 confirmed
- [x] 記錄 confirmed_by／confirmed_at
- [x] confirmed 後任何 calculation/input mutation 一律拒絕
- [x] confirmed 後歸屬欄位不可影響已確認月份

### 7.5 已確認月份的跨模組鎖定

- [x] `closeSale()` 的 `sold_at` 若落在 confirmed／paid SalaryPeriod 月份，422 阻擋回填成交
- [x] 阻擋訊息需明確指出該月份薪資已鎖定，要求聯絡管理員，不可靜默漏算獎金
- [x] 已售車輛歸屬修改除既有 settlement item reference 外，亦需依 `sold_at` 月份防禦 confirmed／paid 歷史缺口
- [x] draft 月份仍允許正常成交與歸屬修正，後續以重算納入
- [x] 補 backdated close-sale、draft 允許、confirmed／paid 阻擋與台北月界測試

---

## 8. 發薪與 MoneyEntry 整合

### 8.1 發薪 API

- [x] admin-only
- [x] 只有 confirmed period 可發薪
- [x] 輸入 `cash_account_id`
- [x] 輸入 `payment_date`
- [x] `payment_date` 不得早於結算月首或晚於今天；允許實際延後至後續月份補發
- [x] 輸入 `idempotency_key`
- [x] 驗證 cash account active
- [x] 為每位 `net_pay > 0` 員工建立一筆 MoneyEntry
- [x] direction=`expense`
- [x] category=`薪資 / 佣金`
- [x] vehicle_id=`null`
- [x] source_type=`salary_settlement`
- [x] approval_status=`approved`
- [x] counterparty_name=員工姓名
- [x] description 含月份但不含不必要敏感明細
- [x] salary_settlement.money_entry_id 回填
- [x] period 狀態改為 paid
- [x] 記錄 cash_account_id／payment_date／paid_by／paid_at

### 8.2 Transaction 與 idempotency

- [x] 整批發薪使用同一 DB transaction
- [x] period row lockForUpdate
- [x] 所有 settlement row 鎖定
- [x] 同 key + 同 payload replay 成功
- [x] 同 key + 不同 payload 422 reject
- [x] 同月份不同 key 的第二次發薪拒絕
- [x] QueryException duplicate race 後 rollback，開新 transaction 讀取 winner
- [x] 不可因部分 MoneyEntry 建立失敗留下半套已發薪
- [x] 只有所有員工 MoneyEntry 都成功，period 才能 paid

### 8.3 已發薪保護

- [x] paid period 不可重算
- [x] paid period 不可刪除
- [x] salary settlement MoneyEntry 不可一般 CRUD update/delete
- [x] paid settlement items 不可修改
- [x] 修正需走後續月份手動調整或一般收支修正，不回改歷史

---

## 9. Policy、Middleware、Request、Resource

### 9.1 Policy

- [x] SalaryProfilePolicy：admin only
- [x] CommissionPlanPolicy：admin only
- [x] SalaryPeriodPolicy：admin only
- [x] SalarySettlementPolicy：admin only
- [x] Vehicle commission attribution：admin only
- [x] 未知角色 fail-closed

### 9.2 Requests

- [x] UpsertSalaryProfileRequest
- [x] StoreCommissionPlanRequest
- [x] StoreSalaryPeriodRequest
- [x] StoreSalaryAdjustmentRequest
- [x] PaySalaryPeriodRequest
- [x] UpdateVehicleCommissionAttributionRequest
- [x] 所有錯誤訊息為業務可讀中文
- [x] 前端不可傳入 totals、snapshot、status、money_entry_id、confirmed_by、paid_by

### 9.3 Resources

- [x] SalaryProfileResource
- [x] CommissionPlanResource
- [x] SalaryPeriodListResource
- [x] SalaryPeriodResource
- [x] SalarySettlementResource
- [x] SalarySettlementItemResource
- [x] `SalaryPeriodResource` 回傳 draft 時必須呼叫 `SalaryEligibilityService::inspectPeriod()`，附上 `anomalies`、`vehicle_results`、`has_blocking_issues` 與修正 action；不得只回 settlements／totals 而靜默漏掉異常車
- [x] 白名單輸出
- [x] manager／sales 無法透過 API 取得任何薪資資料
- [x] 不回傳內部 idempotency payload／敏感 audit metadata

---

## 10. API Routes

所有薪資 API 放在 `auth:sanctum` + `active` + admin authorization 下。

- [x] `GET /api/salary-profiles`
- [x] `PUT /api/salary-profiles/{user}`
- [x] `GET /api/commission-plans`
- [x] `POST /api/commission-plans`
- [x] `GET /api/commission-plans/{commissionPlan}`
- [x] `GET /api/salary-periods`
- [x] `POST /api/salary-periods`
- [x] `GET /api/salary-periods/{salaryPeriod}`
- [x] `POST /api/salary-periods/{salaryPeriod}/recalculate`
- [x] `POST /api/salary-periods/{salaryPeriod}/adjustments`
- [x] `DELETE /api/salary-periods/{salaryPeriod}/adjustments/{item}`
- [x] `POST /api/salary-periods/{salaryPeriod}/confirm`
- [x] `POST /api/salary-periods/{salaryPeriod}/pay`
- [x] `PATCH /api/vehicles/{vehicle}/commission-attribution`
- [x] route model binding 不可跨 period 操作 settlement item
- [x] 刪除手動加扣項時，先驗證 item 屬於 route 的 period，再以 `authorize('deleteAdjustment', $item->settlement)` 授權；不可把 `SalarySettlementItem` 直接傳給 `SalarySettlementPolicy`
- [x] 薪資月份 index 必須使用 `SalaryPeriodListResource`，不可誤用會執行 `inspectPeriod()` 的 `SalaryPeriodResource::collection()` 造成逐月份重複掃描
- [x] manager／sales 全部回 403，不可只靠前端藏入口

---

## 11. Frontend：薪資結算

### 11.1 API client／types

- [x] `src/api/salaryProfiles.ts`
- [x] `src/api/commissionPlans.ts`
- [x] `src/api/salaryPeriods.ts`
- [x] `src/types/salary.ts`
- [x] API 呼叫集中，不在 component 散落 URL
- [x] totals 只顯示後端結果，前端不得自行當正式計算器

### 11.2 導覽與路由

- [x] admin Sidebar 新增「薪資結算」
- [x] manager／sales 不顯示入口
- [x] 直接輸入路由仍由前端 guard + 後端 403 雙重保護
- [x] admin Dashboard 可顯示「本月預估薪資」卡片
- [x] manager／sales Dashboard 不回傳、不顯示薪資數字

### 11.3 薪資月份列表

- [x] 月份
- [x] 狀態 badge：草稿／已確認／已發薪
- [x] 預估／實發總額
- [x] 員工人數
- [x] 建立月份草稿
- [x] 空狀態
- [x] loading／error state

### 11.4 薪資月份詳情

- [x] 全公司實發合計
- [x] 各員工薪資卡／表格
- [x] 底薪
- [x] 固定津貼
- [x] 收車獎金
- [x] 賣車獎金
- [x] 其他加給
- [x] 勞保扣款
- [x] 健保扣款
- [x] 其他扣款
- [x] 實發薪資
- [x] 當月成交台數
- [x] 適用級距
- [x] 距離下一級
- [x] 展開查看車輛獎金明細
- [x] 公司營運保留／公司剩餘分配額摘要
- [x] 阻擋異常清單與修正入口
- [x] 未啟用薪資設定／獎金的歸屬人顯示非阻擋提示，並說明獎金歸公司剩餘
- [x] 草稿可重算
- [x] 草稿可新增／刪除手動加扣項
- [x] 已確認／已發薪只讀
- [x] 確認結算需二次確認
- [x] 發薪需選帳戶與日期並二次確認

### 11.5 員工薪資設定

- [x] admin 可編輯底薪
- [x] 固定津貼
- [x] 勞保扣款
- [x] 健保扣款
- [x] 是否啟用獎金
- [x] 必填標示與 per-field error
- [x] 明確提示「只影響未確認月份」

### 11.6 獎金方案

- [x] 顯示 40% 公司保留
- [x] 顯示分配池
- [x] 顯示收車 20%
- [x] 顯示跨級 20%／30%／50%
- [x] 已使用方案唯讀
- [x] 新規則建立新方案，不直接覆寫歷史

### 11.7 車輛歸屬 UI

- [x] 新增車輛表單加入收車人
- [x] 銷售流程加入賣車人
- [x] admin 待補歸屬清單
- [x] 已結算車輛顯示鎖定原因

---

## 12. Backend Tests

### 12.1 SalaryProfileTest

- [x] admin 可讀寫
- [x] manager／sales 403
- [x] 非負整數驗證
- [x] 修改 profile 不回改已確認 settlement snapshot
- [x] audit log 不洩漏完整薪資 payload

### 12.2 CommissionPlanTest

- [x] 初始方案正確
- [x] tier 驗證
- [x] 超配比例拒絕
- [x] 已使用方案不可修改／刪除
- [x] 生效日選取正確

### 12.3 VehicleCommissionAttributionTest

- [x] 新車保存 purchase_agent
- [x] reserve／sale 保存 sales_agent
- [x] sales 自己操作安全預設本人
- [x] admin／manager 代登可指定實際人員
- [x] 不從 created_by heuristic backfill
- [x] confirmed／paid period 引用後不可改歸屬
- [x] idempotency payload 含 agent ids

### 12.4 SalaryCalculationTest

- [x] 公式完整案例
- [x] 1／3／5 台跨級案例
- [x] 第五台讓前四台全部改 50%
- [x] approved-only
- [x] pending／rejected 不計入
- [x] 虧損不產生獎金
- [x] 同人同時收車與賣車
- [x] 整數餘數歸公司
- [x] 大額邊界
- [x] 混合盈虧批次公司淨得與總毛利恆等
- [x] 佣金停用依收車／賣車角色分別歸零
- [x] 跨月份輸入拒絕
- [x] 月首生效方案可由 `YYYY-MM` 正確選取
- [x] 回溯生效新方案不阻斷既有 period 使用原綁定方案
- [x] 零毛利／虧損車不推升賣車級距
- [x] 只有零毛利／虧損車的啟用佣金業務仍回傳 0 台／0% summary
- [x] `Asia/Taipei` 月首邊界與 API offset

### 12.5 SalaryEligibilityTest

- [x] 非 sold 不納入
- [x] sold_at 非該月不納入
- [x] 缺 purchase_agent 阻擋
- [x] 缺 sales_agent 阻擋
- [x] pending money entry 阻擋
- [x] purchase payment 與 purchase_price 不一致阻擋
- [x] legacy_unknown 阻擋
- [x] 同車不可進兩個 confirmed periods
- [x] 台北月首／月底上下界選取
- [x] 真實 MariaDB TIMESTAMP 月界整合測試
- [x] 真實 MariaDB 薪資確認／綁車收支核准跨連線鎖測試
- [x] 真實 MariaDB salary period／closeSale 跨連線鎖序測試（fork/socket，1 test／14 assertions）

### 12.6 SalaryPeriodWorkflowTest

- [x] 建立 draft
- [x] 重算跨級
- [x] 手動加扣項保留
- [x] confirmed 前 transaction 內重驗
- [x] confirmed 後不可重算／修改
- [x] paid 後不可變更
- [x] manager／sales 403
- [x] unknown role fail-closed

### 12.7 SalaryPaymentTest

- [x] 一位員工建立一筆 salary MoneyEntry
- [x] 金額等於 net_pay
- [x] category／direction／source_type 正確
- [x] 直接 approved
- [x] cash account balance 正確下降
- [x] Dashboard monthly expense 正確增加
- [x] 相同 key 同 payload replay
- [x] 相同 key 不同 payload reject
- [x] 不同 key 重複發薪 reject
- [x] 中途失敗整批 rollback
- [x] salary MoneyEntry 不可一般 CRUD 修改／刪除
- [x] 真實 MySQL concurrency 測試，不只 SQLite 模擬

### 12.8 Regression

- [x] full backend suite 通過（第 11 部分與 review 收尾後：482 tests、470 passed、12 environment-gated skipped、2217 assertions）
- [x] VehicleWorkflowTest 不被破壞
- [x] MoneyEntryApprovalTest 不被破壞
- [x] RoleAccessTest 不被破壞
- [x] UserTest 最後 active admin 保護不被破壞
- [x] Vehicle create／reserve idempotency regression 通過
- [x] v1.2 VehiclePhoto／Public API tests 不被破壞

### 12.9 Salary Period HTTP 邊界測試（第 10 部分完成後）

- [x] manager／sales 對每一個薪資月份 endpoint 都回 403
- [x] 直接檢查原始 JSON，不得回傳任何薪資欄位、內部 idempotency payload、`money_entry_id` 或未列入白名單的 snapshot 欄位
- [x] index 使用 `SalaryPeriodListResource`，不會對每一列月份呼叫 `SalaryEligibilityService::inspectPeriod()`
- [x] 刪除手動加扣項只能授權該 item 所屬的 settlement，且不可跨 period 刪除

---

## 13. Frontend 驗證

- [x] `npm run lint`
- [x] `npx tsc -b`
- [x] production build
- [x] admin salary pages 可編譯
- [x] manager／sales 無入口
- [x] direct route guard 正確
- [x] 手機可基本查看與操作
- [x] dark mode 可讀
- [x] 金額格式化正確
- [x] loading／empty／error／disabled state 完整
- [x] 確認與發薪按鈕不會重複送出
- [x] idempotency key 同一次重試穩定沿用

### 13.1 RWD、Dark Mode 與操作語意收尾

本節屬於 v1.3 驗收缺口修正，不另立 v1.4。只調整前端版面、呈現語意與操作狀態，不變更後端薪資公式、API 契約、資料庫結構、權限模型或既有歷史快照規則。

#### 13.1.1 全域響應式框架

- [x] `AppLayout` 在手機尺寸改為 drawer／overlay Sidebar，不可永久占用固定 `w-56`
- [x] 桌機維持固定 Sidebar；手機可開啟、關閉，切換路由後自動收合
- [x] 主內容容器補 `min-w-0`，避免 flex child 撐破 viewport
- [x] 手機縮小頁面 padding，頁首標題、說明與操作按鈕可換行／堆疊
- [x] 320px、375px、390px、768px 寬度不可出現整頁水平捲動
- [x] 導覽、按鈕、輸入框與選單維持至少 44px 的基本觸控操作高度

#### 13.1.2 薪資頁面手機版

- [x] 薪資月份列表在手機改為卡片或等價可讀版面，不只依賴桌面表格水平捲動
- [x] 薪資月份詳情在手機可清楚查看員工摘要、獎金明細、公司分配摘要與異常清單
- [x] 員工薪資設定欄位在手機垂直排列，錯誤訊息與必填標示不被截斷
- [x] 獎金方案級距欄位取消固定寬度組合造成的 overflow，手機改為堆疊版面
- [x] 待補獎金歸屬清單在手機改為卡片或堆疊欄位，兩個歸屬選單可完整操作
- [x] modal、確認區與主要操作按鈕在手機不超出 viewport

#### 13.1.3 Dark Mode 可讀性

- [x] v1.3 新增的 input、select、textarea 明確使用既有 surface／foreground／border token
- [x] 原生 select 在 light／dark mode 均可辨識目前值、選項與 disabled 狀態
- [x] table、card、modal、badge、warning、empty／loading／error state 在 dark mode 對比足夠
- [x] 不以新增硬編碼色彩破壞 `UI.md` 的語意色彩系統

#### 13.1.4 操作語意與狀態修正

- [x] `eligible_sales_count` 顯示為「獎金級距計入台數」或等價精確文案，不得誤稱所有實際成交台數
- [x] 員工未啟用獎金時，不顯示「再 X 台升級」；改為清楚提示目前未啟用獎金
- [x] `legacy_unknown`／`money_entry_source_review` 異常不可連到無法修正問題的車輛詳情頁
- [x] 尚未提供後台來源確認 UI 前，畫面應顯示正確的系統管理處理說明，不得提供假修正入口
- [x] 待補歸屬頁補完整 loading state，API 尚未回應時不可短暫顯示錯誤 empty state
- [x] loading／empty／error／disabled 文案在桌機與手機均不互相跳動或遮擋

#### 13.1.5 驗證與範圍護欄

- [x] `npm run lint`
- [x] `npx tsc -b`
- [x] `npm run build`
- [x] 以瀏覽器實測 320px、375px、390px、768px 與桌機尺寸
- [x] 上述尺寸均各自檢查 light／dark mode
- [x] admin 完成薪資月份列表、詳情、設定、獎金方案與待補歸屬基本操作
- [x] manager／sales 導覽與 direct route guard 不回歸
- [x] 確認本節沒有修改後端薪資計算、API、migration、Policy 或 Resource 契約

驗證紀錄（2026-07-16）：使用 Firefox 152 headless + WebDriver BiDi 設定真實 viewport，逐頁檢查 320／375／390／768／1440px 的 light／dark mode；所有頁面 `scrollWidth` 均未超出 viewport，可見導覽、按鈕、輸入框與 select 均至少 44px。另實測手機 drawer 開關與換頁收合、薪資明細展開、加扣項 modal、薪資設定編輯、獎金方案表單及待補歸屬 loading／empty 狀態。完整 v1.3 業務資料、發薪與角色驗收仍依第 14 節 manual smoke 執行。

Review 修正（2026-07-16）：移除會波及全站 compact controls 的裸 `button`／`input`／`select`／`textarea` CSS，改由 13.1 表單以 `.form-control-touch` 明確 opt-in；重新執行 50 組 viewport／theme／頁面檢查全數通過。草稿員工獎金啟用狀態改由既有 admin-only Salary Profiles API 的 `commission_enabled` 判斷，修正停用且 0 台時 warning 清單不足以判定的顯示缺口，未變更後端契約。

---

## 14. Manual Smoke

### 14.1 前置資料

- [ ] 建立至少兩位員工薪資設定
- [ ] 建立／確認 2026 標準薪資方案
- [ ] 建立至少五台同一賣車人、同月份 sold 車輛
- [ ] 其中至少一台收車人與賣車人不同
- [ ] 其中至少一台同人收車又賣車
- [ ] 每台 approved 購車付款與 purchase_price 一致
- [ ] 每台 approved 銷售收款達 sold_price

### 14.2 跨級與公式

- [ ] 1～2 台顯示 20%
- [ ] 第 3 台後整月改 30%
- [ ] 第 5 台後整月五台全部改 50%
- [ ] 100,000 毛利範例算出 40,000／12,000／30,000／18,000
- [ ] 公司保留 + 所有獎金 + 公司額外 = 毛利
- [ ] 同一人收車又賣車同時取得兩項獎金

### 14.3 薪資

- [ ] 底薪正確
- [ ] 固定津貼正確
- [ ] 勞保扣款正確
- [ ] 健保扣款正確
- [ ] 手動加給正確
- [ ] 手動扣款正確
- [ ] 實發薪資正確
- [ ] 全公司應準備薪資總額正確

### 14.4 異常

- [ ] 缺收車人時不可確認
- [ ] 缺賣車人時不可確認
- [ ] pending 收支時不可確認
- [ ] 購車付款不一致時不可確認
- [ ] 虧損車不產生負獎金

### 14.5 確認與發薪

- [ ] 確認後資料唯讀
- [ ] 發薪建立每位員工一筆 Money Entry
- [ ] 資金帳戶餘額下降正確
- [ ] 重新整理後 paid 狀態保持
- [ ] 重複點擊／重試不重複建立支出
- [ ] 已發薪無法一般收支修改／刪除

### 14.6 權限

- [ ] admin 可完整操作
- [ ] manager 看不到 Sidebar／頁面／API
- [ ] sales 看不到 Sidebar／頁面／API
- [ ] API 原始 JSON 不對 manager／sales 洩漏任何薪資欄位

---

## 15. 文件

- [x] `backend/API.md` 補薪資結算 endpoints
- [x] `README.md` 補 v1.3 功能與 smoke
- [x] `CLAUDE.md` 對齊 v1.3 範圍與限制
- [x] `docs/current-state.md` 更新實作進度
- [x] 新增 `docs/v1.3-smoke-report.md`
- [x] 新增 `docs/v1.3-handoff.md`
- [x] 新增 `docs/v1.3-rwd-handoff.md`，記錄本次響應式、dark mode、操作語意修正與人工驗證結果
- [x] 依目前 HEAD 的實際測試結果更新 `docs/v1.3-smoke-report.md`、`docs/v1.3-handoff.md` 與 PLAN 測試數字
- [x] PLAN 每階段完成後同步勾選

---

## 16. v1.3 不做

- [x] 打卡
- [x] 排班
- [x] 請假
- [x] 加班時數自動計算
- [x] 特休管理
- [x] 官方勞健保級距與費率引擎
- [x] 勞健保／勞退線上申報
- [x] 所得稅扣繳與申報
- [x] 年終獎金引擎
- [x] 薪資銀行批次轉帳檔
- [x] 員工自助薪資單
- [x] PDF／Email／LINE 薪資單
- [x] 多公司薪資
- [x] 正式會計分錄
- [x] 官網前端
- [x] 庫存績效大改

勾選代表確認「刻意不做」，不是已實作。

---

## 17. 完成定義

v1.3 視為完成，必須同時滿足：

- [x] 收車人／賣車人有正式欄位，不使用 heuristic 推定
- [x] 獎金方案可版本化且歷史不可回改
- [x] 40% 公司保留與 60% 分配池公式正確
- [x] 收車獎金固定為分配池 20%
- [x] 賣車跨級 20%／30%／50% 正確追溯整月
- [x] approved-only 毛利
- [x] 底薪、津貼、勞保、健保、手動加扣項正確
- [x] 每位員工實發薪資與全公司總額正確
- [x] 確認後鎖定
- [x] 發薪後建立正式 salary settlement MoneyEntry
- [x] idempotency／並發下不重複發薪
- [x] admin-only 薪資隱私邊界成立
- [x] backend tests 通過
- [x] frontend lint／typecheck／build 通過
- [x] 320px、375px、390px、768px 與桌機尺寸均可基本查看與操作，且無整頁水平 overflow
- [x] v1.3 新增頁面在 light／dark mode 均可讀
- [x] 獎金級距台數、未啟用獎金與異常修正入口的操作語意正確
- [ ] manual smoke 通過
- [x] RWD 收尾與實際驗證結果已同步至文件

---

## 18. 建議 Commit 拆分

```text
feat: 新增薪資設定與獎金方案資料模型
feat: 新增車輛收車人與賣車人歸屬
feat: 實作跨級佣金與薪資計算服務
feat: 實作薪資月份草稿與確認流程
feat: 實作發薪與薪資支出整合
feat: 新增薪資結算管理介面
fix: 補強薪資結算響應式版面與操作語意
test: 補齊薪資計算、權限與並發測試
docs: 完成 v1.3 薪資結算文件
docs: 補充 v1.3 RWD 收尾交接與驗證結果
```

實際 commit 應小步、可 review、可回滾，不要一次把 migration、計算、付款與 UI 混成單一巨大 commit。
