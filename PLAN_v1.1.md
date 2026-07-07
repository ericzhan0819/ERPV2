# PLAN_v1.1.md — ERPV2 v1.1 實務工作流補強 進度清單

本清單對應 `企劃書_v1.1.md` 的七大項功能補強，逐步追蹤實作進度。

---

## 0. 前置準備
- [x] 閱讀 `企劃書_v1.1.md` 全文並對齊範圍
- [x] 確認角色矩陣（§3.4）與審核流程規則（§8）無疑義
- [x] 確認實作順序（§14）與本清單對應

---

## 1. Users Role / 員工帳號欄位（對應 `企劃書_v1.1.md` §3.3, §7.2）

### 過渡期相容說明
- `users.role` 與 `users.is_admin` 必須**雙向同步**直到 middleware 全部切換
- `role='admin'` ⟷ `is_admin=true`；其他角色 ⟷ `is_admin=false`
- 最後一位 active admin 改用「`role='admin' AND is_active=true`」判斷，防止無 active admin 狀態

### Schema
- [x] Migration：users 新增 `role`（string, NOT NULL, in:'admin','manager','sales'）
- [x] Migration：回填既有 users：`is_admin=true` → `role='admin'`，`is_admin=false` → `role='manager'`
- [x] Migration：users 新增 `phone`/`job_title`/`hire_date`/`notes`（nullable）

### Model / Service / API
- [x] User Model：fillable + casts + `isAdmin()`/`isManager()`/`isSales()`/`hasAnyRole()`
- [x] UserService：`setRole($user, $role)` 改變 role **同時更新 is_admin**；防止自己降級造成無 active admin
- [x] StoreUserRequest/UpdateUserRequest/UpdateUserRoleRequest：驗證 role
- [x] UserResource：輸出新欄位
- [x] UserController：`updateRole()` 呼叫 `setRole()`

### 前端
- [x] 頁面改名為「員工/帳號管理」
- [x] 新增 phone/job_title/hire_date/notes 欄位
- [x] 角色為固定 dropdown（admin/manager/sales）
- [x] 非 admin 無法進入用戶管理頁

### 測試
- [x] role 建立後 is_admin 正確同步（`='admin'` → `true`，其他 → `false`）
- [x] 既有 is_admin 回填正確
- [x] 最後一位 active admin 不可被降級或停用
- [x] admin 無法把自己降級
- [x] manager/sales 呼叫 `/api/users` 回 403

---

## 2. Role-based Policy / Middleware / Resource 遮蔽（對應 `企劃書_v1.1.md` §3, §5, §9）

### 核心原則（本節下方「9. 老闆身兼會計」為最新修正，取代與本節衝突的舊敘述）
- 敏感欄位**不可只靠前端隱藏**，後端 JSON 必須用 `when()`/`unless()` 真正不輸出
- sales 不可見：`purchase_price`（收購價）、購車付款、完整整備成本、單車毛利、資金帳戶餘額、完整收支金額
- sales 可見：`asking_price`, `floor_price`, `sold_price`（開價 / 底價 / 成交價，業務跟客人議價與追蹤收款依據）；訂金 / 尾款 / 退款等銷售收款金額（不論由誰建立）；自己上報的車輛支出申請與審核狀態
- sales 呼叫金額 endpoint（`/api/cash-accounts/balances` 等）應 **403**；`/api/money-entries` 則是 200 但依範圍遮蔽（自己的申請 + 銷售收款安全紀錄），不是整份 403
- Dashboard 給 sales：只有 `vehicle_counts` 與 `monthly_sold_count`，無金額欄位

### Policy / Middleware
- [x] 新增 VehiclePolicy / MoneyEntryPolicy / CashAccountPolicy / UserPolicy（CustomerPolicy 待第 4 階段 Customer Module 建立後補上）
- [x] 新增 EnsureUserHasRole middleware（取代 EnsureUserIsAdmin）
- [x] routes/api.php 依角色分組：
  - 使用者/現金帳戶寫入：`role:admin` only
  - Customer delete：`role:admin` only（待第 4 階段 Customer Module 建立）
  - Vehicle 新增/編輯/上架：`can:` middleware 綁定 VehiclePolicy（admin,manager）
  - Vehicle 銷售流程（保留/收尾款/成交/整備支出上報）：`can:` middleware 綁定 VehiclePolicy（admin,manager,sales）
  - Money Entry CRUD：`role:admin,manager,sales`（sales 可建立一般收支申請，但一律 pending，見第 3 階段／第 9 節）
  - Cash Account balances：`role:admin,manager` only

### Resource 遮蔽
- [x] VehicleResource：purchase_price 依 `canViewFinancials()` `when()`；asking_price, floor_price, sold_price 依 `canViewSalesPricing()`（admin/manager/sales）`when()`
- [x] MoneyEntryResource：cash_account_id 依 `canViewFinancials()`；amount 依「canViewFinancials() 或（sales 且自己建立或屬銷售收款安全分類）」
- [x] DashboardController：sales 得到淨化版本（無金額欄位）

### 前端
- [x] Sidebar 依 role 顯示：admin 全部、manager 隱藏員工管理、sales 隱藏資金帳戶與員工管理和新增車輛
- [x] ProtectedRoute 可選加入 `allowedRoles` 參數
- [x] VehicleDetail/Dashboard 依 role 條件隱藏欄位（後端已不輸出）

### 測試
- [x] sales 呼叫 `/api/vehicles/{id}` 使用 `assertJsonMissingPath('purchase_price')` 驗證欄位不存在
- [x] sales 呼叫 `/api/dashboard/summary` 驗證無金額欄位但有 vehicle_counts/monthly_sold_count
- [x] sales 呼叫 `/api/cash-accounts/balances` → 403
- [x] sales 呼叫 `/api/users` → 403（既有 UserTest.php 已覆蓋）
- [x] manager 呼叫 `/api/users` 與 cash account 寫入 → 403，但可見 purchase_price

---

## 3. 一般收支審核流程與 Approved-only 金額彙總（對應 `企劃書_v1.1.md` §8）

### 邊界定義（已由第 9 節「老闆身兼會計」修正，此處為最新版本）
- `approval_status` 套用於 `manual`、`vehicle_shortcut`、`vehicle_workflow` 三種 source_type：只要建立者不是 admin 就是 pending，admin 建立才 approved
- 既有回填資料（`legacy_unknown`）維持 `approved`，且不可核准/駁回
- approved/rejected 單向不可逆，若要修正需建新 entry
- 車輛成交結案（closeSale）只依 approved 收款判斷，approved 收款總額需達成交價才可關帳

### Schema
- [x] Migration：money_entries 新增 `approval_status`（default 'approved', in:'approved','pending','rejected'）
- [x] Migration：money_entries 新增 `approved_by` FK（nullable）與 `approved_at` timestamp（nullable）
- [x] 既有資料回填 `approval_status='approved'`

### Model / Service
- [x] MoneyEntry Model：新欄位 casts，**不加 fillable**（防前端偽造）
- [x] MoneyEntryService::createEntry()：依 role 設定 approval_status（admin=approved，manager/sales=pending）
- [x] MoneyEntryService：`updateEntry()`/`deleteEntry()` 只允許 pending（approved/rejected 拒絕）
- [x] MoneyEntryService：新增 `approve($entry, $user)`/`reject($entry, $user)` 方法
- [x] 新增 scope `scopeApproved()` 或 helper 方法，避免各處重複寫 `where('approval_status', 'approved')`

### 金額彙總修改點（關鍵）
- [x] `MoneyEntryService::balanceForAccount()` 加 `where('approval_status', 'approved')`
- [x] `MoneyEntryService::balanceForType()` 加 `where('approval_status', 'approved')`
- [x] `DashboardService::buildSummary()` 的 monthly_income/monthly_expense 加 `where('approval_status', 'approved')`
- [x] `VehicleService::financialSummary()` 加 `where('approval_status', 'approved')`
- [x] `VehicleService::buildFinalPaymentWarning()` 的 income 只計 approved
- [x] `VehicleService::printClosingData()` 的 summary 自動套用（financialSummary 已過濾），entries 選 A（僅 approved）

### API / 前端
- [x] StoreMoneyEntryRequest：**不含** approval_status 驗證規則
- [x] 新增 `PATCH /api/money-entries/{id}/approve` 與 `/reject`（admin only）
- [x] MoneyEntryResource：輸出 approval_status, approved_by, approved_at
- [x] MoneyEntryList：新增「待審核」篩選器，admin 可核准/駁回

### 測試
- [x] admin 建立 manual/vehicle_shortcut/vehicle_workflow → approved，manager/sales 建立 → pending
- [x] pending 不影響 balanceForAccount/balanceForType/Dashboard/Vehicle summary
- [x] approved 後影響，rejected 永不計入
- [x] approved/rejected 後不可編輯/刪除（422），狀態不可逆
- [x] manager/sales 無法呼叫 approve/reject（403），legacy_unknown 不可核准/駁回
- [x] sales 收訂金/尾款後、admin 核准前，closeSale 回 422；admin 核准足額後才可成交結案

---

## 4. Customer Module（對應 `企劃書_v1.1.md` §6）

### Schema / Model
- [x] Migration：customers 表（id, name, phone, line_id, customer_type, source, address, notes, created_by, updated_by, timestamps）
- [x] Migration：vehicles 新增 `seller_customer_id`/`buyer_customer_id`（nullable, nullOnDelete）
- [x] Customer Model + CustomerService（delete 檢查關聯 vehicles，有則拒絕）

### API
- [x] CustomerController（full CRUD）
- [x] StoreCustomerRequest/UpdateCustomerRequest
- [x] CustomerResource
- [x] routes/api.php：`apiResource('customers', ...)`，delete 限 admin

### Vehicle 整合
- [x] Vehicle Model：`sellerCustomer()`/`buyerCustomer()` relations
- [x] StoreVehicleRequest/UpdateVehicleRequest：seller_customer_id/buyer_customer_id（nullable, exists:customers）
- [x] VehicleResource：新增欄位
- [x] VehicleService::createVehicle()：接收可選 customer_id
- [x] ReserveModal：可選 buyer_customer_id

### 前端
- [x] 新增 /customers（list/create/detail）
- [x] Sidebar 加「客戶」
- [x] VehicleCreate：seller_customer_id 選擇欄（快照 seller_name/phone）
- [x] VehicleDetail/ReserveModal：buyer_customer_id 選擇欄

### 測試
- [x] 刪除有關聯 vehicles 的 customer 被阻擋
- [x] 快照欄位不隨 customer 異動改變
- [x] sales 無法刪除 customer（role check）

---

## 5. 車輛入庫建檔欄位與表單（對應 `企劃書_v1.1.md` §4）

### Schema
- [x] Migration：vehicles 新增 displacement/transmission/fuel_type/parking_location（nullable string）
- [x] Migration：vehicles 新增入庫檢核欄位：has_registration_document/has_spare_key/is_transfer_completed/is_inspection_completed/is_preparation_completed（boolean, default false）
- [x] Migration：vehicles 新增 lien_note/condition_note（text, nullable）

### Model / API
- [x] Vehicle Model：fillable + casts（booleans）
- [x] StoreVehicleRequest/UpdateVehicleRequest：驗證新欄位
- [x] VehicleResource：輸出新欄位

### 前端
- [x] VehicleCreate 分區：基本資料 → 買入資料 → 入庫檢核（checkboxes + textareas） → 備註（購車付款區塊留待第 6 階段一併實作，本階段未加空區塊）
- [x] VehicleDetail：顯示檢核狀態，sales 無編輯權限
- [x] 列印頁：同步新欄位

### 測試
- [x] 新欄位 CRUD 正常
- [x] 既有資料相容（backward compatible）
- [x] sales 無編輯權限

---

## 6. 建車同步購車付款（對應 `企劃書_v1.1.md` §5）

### Idempotency 與並發設計
- `vehicles.idempotency_key` 必須 unique nullable
- `createVehicle()` 改 5 方法模式：normalizeData → createInsideTransaction → replayRacedAfterRollback → replayOrReject → isSame（同 reserveVehicle）
- payment idempotency_key 派生：`"{vehicleKey}:initial-payment"`
- 建車 + payment 在同一 transaction；payment 失敗時整個 vehicle 回滾，無半成品

### Schema / API
- [x] Migration：vehicles 新增 `idempotency_key`（string, nullable, unique）
- [x] 確認 vehicles 表已有 `stock_no` unique index
- [x] StoreVehicleRequest：`idempotency_key` required + `initial_purchase_payment` nested（nullable）
- [x] 驗證：sales 無法觸發 initial_purchase_payment（VehiclePolicy::create 已擋在建車功能之外，回 403，而非放行到建車流程再回 422）

### Service / Controller
- [x] VehicleService::createVehicle()：5 方法模式，race-safe idempotency + stock_no 共存
- [x] payment 欄位驗證：金額 > 0、cash_account 啟用
- [x] payment 產生的 MoneyEntry：`source_type='vehicle_workflow'`, `approval_status='approved'`（不進審核）

### 前端
- [x] VehicleCreate mount 時產生穩定 `idempotencyKey`（不是每次送出重新產生）
- [x] 新增「購車付款」區塊：checkbox + amount/cash_account_id/payment_date/note
- [x] 重試時使用同一 idempotency_key

### 測試
- [x] 純建車無付款 → Vehicle 成功，無 MoneyEntry
- [x] 建車 + 付款（有效帳戶）→ Vehicle + MoneyEntry 同時建立
- [x] 付款失敗（帳戶停用） → 整個回滾，Vehicle 不存在
- [x] 相同 key + 相同 payload → replay，無重複
- [x] 相同 key + 不同 payload → 422
- [x] **MySQL concurrency test**（非 SQLite）：並發雙送相同 key → 只有一台 Vehicle 與一筆 payment，兩個請求都安全返回
- [x] Code review 修正：以每日序號列鎖定 `stock_no`，避免當日首筆不同 key 並發產生重號；補上遞增、既有資料銜接、交易回滾及不同 key 真 MySQL 並行測試
- [x] Code review 修正：關聯買方客戶後，即使客戶資料後續異動，相同保留 payload 仍可安全 replay
- [x] sales 呼叫建車端點（含 initial_purchase_payment）→ 403（policy 已完全擋下，見上）
- [x] payment entry 應 approved，不進審核

---

## 7. UI/UX 收斂與完整 Smoke（對應 `企劃書_v1.1.md` §10, §12）

### UI 檢查
- [x] 新增頁面依 UI.md 語意色彩 token、light/dark mode（審查 Customers/Users/MoneyEntries 審核 UI/Vehicle 入庫表單/Badge 元件，未發現寫死 hex，皆使用語意 token）
- [ ] 表單遵守「visible labels + required * + per-field error + on-blur validation」— label 可見與必填 `*` 已補齊（VehicleCreate/VehicleDetail 原本漏標，已修正），但 per-field 錯誤訊息 + on-blur 驗證屬全站既有模式（含 1.0 頁面），目前仍是單一錯誤橫幅、submit 時才驗證。此為跨頁面架構調整，未在本次一併重構，避免大範圍改動，列為後續項目
- [x] 依 UI.md badge/button/card/sidebar 規範（ApprovalStatusBadge/ActiveStatusBadge/VehicleStatusBadge/MoneyDirectionBadge 皆 icon+文字、三件組色彩；Sidebar 依角色以 `.filter()` 真實移除節點，非 CSS 隱藏；各頁單一 primary 按鈕、刪除等破壞性操作以 `text-error` 區隔）
- [x] Code review 修正：sales 收支列表同步移除金額／資金帳戶欄位，避免後端遮蔽後前端顯示 `NaN`

### Smoke 驗收（22 項，對應 `企劃書_v1.1.md` §12）
- [ ] 1–13：admin 完整入庫流程到成交結案列印 — 未執行，原因見任務回覆（無瀏覽器自動化工具可實際點選 UI）
- [ ] 14–17：sales 帳號權限驗證 — 同上，UI 點選未執行；後端 JSON 遮蔽與權限已由既有自動化測試覆蓋並全數通過
- [ ] 18–19：manager 帳號權限驗證 — 同上
- [ ] 20–22：admin 核准/駁回流程 — 同上，approval_status 對彙總的影響已由自動化測試覆蓋並通過

### 回歸測試
- [x] v1.0 flow（admin）行為一致（`php artisan test` 238 passed / 3 skipped，涵蓋車輛流程、收支、列印資料組裝等既有 Feature 測試全數通過）
- [x] 既有資料相容（既有回填 migration 測試通過）
- [ ] 既有列印頁可用 — 僅確認 frontend/backend 可正常啟動且路由存在，未實際開啟列印頁面以肉眼檢查排版

---

## 8. 文件收尾
- [x] backend/API.md：補充新端點（/api/users/{id}/role、/api/money-entries/{id}/approve、/api/money-entries/{id}/reject、/api/customers/*、/api/cash-accounts/options、/api/vehicles/* 新增欄位與 idempotency_key），並補上 v1.1 角色敏感欄位遮蔽總覽、VehicleResource/MoneyEntryResource 最新 JSON 範例
- [x] README.md：補充 v1.1 說明（角色、審核流程、客戶模組手動驗證步驟；預設 seed 僅有 admin 帳號，manager/sales 需另外建立）
- [x] 本檔案（PLAN_v1.1.md）：逐項勾選完成狀態

---

## 9. 使用者追加：稽核紀錄模組
- [x] `audit_logs` append-only schema、Model 與 admin-only Policy/API
- [x] 自動記錄 User／Vehicle／MoneyEntry／CashAccount／Customer 新增、修改、刪除
- [x] 記錄登入／登出、操作者快照、IP、User-Agent 與 request path
- [x] 排除 password／remember_token／idempotency_key／idempotency_payload 敏感值
- [x] 前端 admin-only 稽核列表、篩選、分頁與異動前後值展開檢視
- [x] 權限、敏感值、登入登出與 append-only 自動化測試
- [x] API.md／README.md 文件更新

---

## 10. 使用者追加：老闆身兼會計 — 收斂核准流程與 sales 銷售收款可視範圍

產品決策：老闆身兼會計，所有會影響正式資金餘額、正式成本、正式毛利的資料都可由員工上報，但只有 `admin` 核准後才正式計入。`manager` 可看完整營運與毛利，但不能核准/駁回收支。`sales` 可以執行銷售、記錄收款、上報整備支出，但不能看收購價、完整成本、毛利、資金帳戶餘額。

- [x] `User::canViewSalesPricing()` 納入 `sold_price`；新增 `canViewSalesCollectionAmounts()` 語意方法
- [x] `VehicleResource`：`sold_price` 改依 `canViewSalesPricing()`（原為 `canViewFinancials()`）
- [x] `CustomerController@show`：`sold_price` 改依 `canViewSalesPricing()`
- [x] `MoneyEntryService`：`recordVehicleShortcut()`（購車付款/單車支出/訂金/退款）與 `VehicleService` 的建車同步付款/reserve 訂金/final-payment 尾款，approval_status 一律改為「建立者是否為 admin」判斷，取代原本「快捷/流程收支永遠 approved」
- [x] `MoneyEntryService::approve()`/`reject()`：允許 `manual`/`vehicle_shortcut`/`vehicle_workflow` 三種 source_type（原僅 `manual`），僅 `legacy_unknown` 不可核准/駁回；仍只有 admin 可呼叫
- [x] `VehicleService::closeSale()`：改依 approved 收款總額是否達成交價判斷，取代原本「至少一筆 income」的寬鬆檢查
- [x] `VehicleController@show`：依角色回傳不同 payload — admin/manager 維持完整 `summary`+完整 `money_entries`；sales 回傳 `sales_collection_summary`（訂金/尾款/退款安全摘要）+ sales-safe `money_entries`（銷售收款紀錄 + 自己上報的支出申請，不含資金帳戶）；未知角色 fail-closed
- [x] `MoneyEntryResource`：`cash_account_id`/`cash_account` 一律只給 `canViewFinancials()`；`amount` 對 sales 開放「自己建立」或「銷售收款安全分類」
- [x] `MoneyEntryService::listEntries()`：新增依角色範圍限制，sales 只看自己建立的申請與銷售收款安全紀錄
- [x] 前端：`permissions.ts` 新增 `canViewSalesCollectionAmounts`/`canApproveMoneyEntries`；`VehicleDetail.tsx` 新增「銷售收款摘要」區塊與「上報整備支出」入口；`VehicleList.tsx`/`CustomerDetail.tsx` 的成交價欄位改依銷售定價權限；`MoneyEntryList.tsx` 金額欄位對 sales 依範圍開放
- [x] 測試：`RoleAccessTest`／`MoneyEntryApprovalTest`／`VehicleWorkflowTest`／`VehicleMoneyShortcutTest` 補齊新規則覆蓋（見各檔案內對應測試方法）
- [x] `CLAUDE.md`／`backend/API.md`／`README.md` 同步更新，移除「vehicle_shortcut/vehicle_workflow 不進審核」「快捷收支直接 approved」等舊描述
