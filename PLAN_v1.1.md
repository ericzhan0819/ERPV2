# PLAN_v1.1.md — ERPV2 v1.1 實務工作流補強 進度清單

本清單對應 `企劃書_v1.1.md` 的七大項功能補強，逐步追蹤實作進度。

---

## 0. 前置準備
- [ ] 閱讀 `企劃書_v1.1.md` 全文並對齊範圍
- [ ] 確認角色矩陣（§3.4）與審核流程規則（§8）無疑義
- [ ] 確認實作順序（§14）與本清單對應

---

## 1. Users Role / 員工帳號欄位（對應 `企劃書_v1.1.md` §3.3, §7.2）

### 1.1 is_admin → role 過渡期規則（新增）

**背景：** 目前 `users` 表只有 `is_admin`/`is_active` 布林值，middleware `EnsureUserIsAdmin` 讀 `is_admin`，routes 用 `middleware('admin')`。v1.1 新增 `role` 後，必須維持短期相容。

**過渡期規則（第 1 階段完成前）：**
- `users.role` 與 `users.is_admin` 必須**永遠同步**，不可出現不一致狀態。
- `role = 'admin'` ⟷ `is_admin = true`
- `role = 'manager'` 或 `role = 'sales'` ⟷ `is_admin = false`
- `UserService::setRole()` 改變 `role` 時，**同時更新 `is_admin`** 保持同步。
- 現有 `EnsureUserIsAdmin` middleware 仍讀 `is_admin`（過渡期不改），但由於 `is_admin` 與 `role` 同步，實際上等同檢查 `role=admin`。
- 最後一位 active admin 保護改用「`role='admin' AND is_active=true` 計數」作為正式判斷，不可只靠 `is_admin`。

**為什麼同步：** 若只改 `role` 不改 `is_admin`，第 2 階段遷移角色 middleware 前，舊 admin middleware 仍讀 `is_admin` 造成權限斷層（某人 `role='manager'` 但 `is_admin=true` 會意外通過 admin 檢查）。雙向同步直到 middleware 全部切換完成，避免權限漏洞。

### 1.2 Schema、Model、API

Schema 與 Model：
- [ ] Migration：users 新增 `role`（string, NOT NULL, 預設值為 'manager'，約束 in:'admin','manager','sales'）
- [ ] Migration：回填既有 users 的 role（`is_admin=true`→ `'admin'`，`is_admin=false`→ `'manager'`）
- [ ] Migration：users 新增 `phone`/`job_title`/`hire_date`/`notes`（全部 nullable）
- [ ] User Model：`fillable` 更新，加入 `role`/`phone`/`job_title`/`hire_date`/`notes`
- [ ] User Model：新增輔助方法 `isAdmin()`/`isManager()`/`isSales()`/`hasAnyRole(...roles)`

API 與驗證：
- [ ] StoreUserRequest：新增 `role` required in:admin,manager,sales，`phone`/`job_title`/`hire_date`/`notes` nullable
- [ ] UpdateUserRequest：同上
- [ ] UpdateUserRoleRequest：`role` required in:admin,manager,sales
- [ ] UserResource：`toArray()` 輸出新欄位

Service 與權限：
- [ ] UserService：新增 `setRole($user, $role)` 方法，改變 `role` 後**同時更新 `is_admin` 保持同步**（見過渡期規則）
- [ ] UserService：沿用既有「至少保留一位 active admin」鎖定邏輯，改為檢查 `role='admin' AND is_active=true` 計數
- [ ] UserService：防止自己降級造成系統無 active admin
- [ ] UserController：`updateRole()` 呼叫 `setRole()`

### 1.3 前端

- [ ] 前端：`users` 頁面改名為「員工/帳號管理」（Sidebar nav 與頁面標題）
- [ ] 前端：建立/編輯表單新增 phone/job_title/hire_date/notes 欄位
- [ ] 前端：角色指派為固定下拉選單（`admin`/`manager`/`sales` 三選項，非自訂欄位）
- [ ] 前端：若登入者非 `admin`，使用者管理頁應 403 或路由不可見

### 1.4 測試

- [ ] Feature test：`role='admin'` 建立後 `is_admin=true`
- [ ] Feature test：`role='manager'` 建立後 `is_admin=false`
- [ ] Feature test：`role='sales'` 建立後 `is_admin=false`
- [ ] Feature test：既有 is_admin 回填正確（`is_admin=true` → `role='admin'`，`is_admin=false` → `role='manager'`）
- [ ] Feature test：變更 role 時 is_admin 同步更新
- [ ] Feature test：最後一位 active `role='admin'` 不可被降為 manager/sales
- [ ] Feature test：無法停用最後一位 active admin
- [ ] Feature test：admin 無法把自己降級為 manager/sales（保護系統至少保留一位 active admin）
- [ ] Feature test：既有 admin middleware 在過渡期仍正常運作（因 is_admin 與 role 同步）
- [ ] Feature test：manager/sales 呼叫 `/api/users` 任何路由回 403

---

## 2. Role-based Policy / Middleware / Resource 遮蔽（對應 `企劃書_v1.1.md` §3, §5, §9）

**此階段是全案安全性最關鍵的部分，敏感欄位不可只靠前端隱藏，後端 JSON 必須真正不輸出。**

### 2.1 遮蔽規則補強（新增）

**基本原則（見 `企劃書_v1.1.md` §5）：**
- 敏感欄位不可只靠前端 CSS 隱藏或條件渲染，後端 JSON 必須確實不包含該欄位。
- sales 不可透過 API 取得的欄位應使用 Resource 的 `when()`/`unless()` 或等價方式讓欄位完全不存在於 JSON，不可回傳 `0`、空字串或假值偽裝。
- 測試必須使用 `assertJsonMissingPath()` 或等價方法驗證原始 JSON（不是前端斷言）。

**sales 角色不可見的後端資訊：**
- `vehicles.purchase_price`, `asking_price`, `floor_price`, `sold_price`
- 車輛財務摘要（`income_total`、`expense_total`、`gross_profit`）
- `cash_account_id`、`amount`、`direction`、`category` 等金額明細欄位
- Dashboard 金額統計（`cash_balance`, `bank_balance`, `other_balance`, `total_funds`, `monthly_income`, `monthly_expense`, `monthly_net_flow`）
- 使用者/帳號記錄
- Cash Account 管理操作與查詢

**Dashboard 給 sales 的內容（見 `企劃書_v1.1.md` §3.4）：**
- `vehicle_counts`（各狀態車輛數）
- `monthly_sold_count`（本月成交台數）
- 不可包含任何金額欄位

**API 返回策略：**
- sales 直接呼叫金額相關 endpoint（如 `/api/cash-accounts/balances`、`/api/vehicles/{id}` 內的 `purchase_price`、`/api/money-entries` 的 `amount`）應 **403 Forbidden 或完全無法呼叫**，不應返回遮蔽版本。
- 本計畫採保守策略：sales 無法呼叫金額 endpoint，而非返回淨空版本。

### 2.2 Policy 層

- [ ] 新增 `backend/app/Policies/VehiclePolicy.php`
- [ ] 新增 `backend/app/Policies/MoneyEntryPolicy.php`
- [ ] 新增 `backend/app/Policies/CashAccountPolicy.php`
- [ ] 新增 `backend/app/Policies/CustomerPolicy.php`
- [ ] 新增 `backend/app/Policies/UserPolicy.php`
- [ ] 在 `bootstrap/providers.php` 或 `AuthServiceProvider` 註冊 Policies

### 2.3 Middleware 層

- [ ] 新增 `backend/app/Http/Middleware/EnsureUserHasRole.php`（取代 `EnsureUserIsAdmin`）
- [ ] routes/api.php：將既有 `middleware('admin')` 改為 `middleware('role:admin')`，或在完整 role 切換後改為新 middleware
- [ ] routes/api.php：依角色矩陣（`企劃書_v1.1.md` §3.4）為各路由分配正確的角色檢查
  - 使用者管理：`role:admin` only
  - 現金帳戶寫入：`role:admin` only
  - Customer 寫入：`role:admin,manager,sales`（但 delete 僅 admin）
  - Vehicle 新增/編輯：`role:admin,manager` only（sales 不可新增或編輯車輛）
  - Vehicle 上架：`role:admin,manager` only
  - Vehicle 銷售流程（保留/收尾款/成交）：`role:admin,manager,sales` 可
  - Money Entry CRUD 與金額查詢：`role:admin,manager` only（sales 不可新增/修改一般收支，見第 3 階段）
  - Cash Account 查詢 `balances`：`role:admin,manager` only，sales 無法呼叫

### 2.4 Resource 層

**VehicleResource 敏感欄位遮蔽：**
- [ ] `purchase_price`：僅 admin/manager 可見，使用 `when()` 或 `unless()`
- [ ] `asking_price`, `floor_price`, `sold_price`：僅 admin/manager 可見
- [ ] 毛利資訊（如有在詳情頁回傳）：僅 admin/manager 可見

**MoneyEntryResource 敏感欄位遮蔽：**
- [ ] `amount`：僅 admin/manager 可見（sales 查詢金額 endpoint 應 403，無需遮蔽回傳）
- [ ] `cash_account_id`, `cash_account` relation：同上

**CashAccount Controller：**
- [ ] `/api/cash-accounts/balances` 端點加入 role 檢查，sales 無法呼叫（403）

**DashboardController：**
- [ ] `/api/dashboard/summary` 端點加入 role 檢查，sales 得到淨化版本（僅 `vehicle_counts`/`monthly_sold_count`，無金額欄位）

### 2.5 前端

- [ ] AppLayout/Sidebar：依 `user.role` 條件顯示／隱藏功能項目
  - `admin`：全部顯示
  - `manager`：隱藏「員工/帳號管理」，不顯示「資金帳戶」管理操作
  - `sales`：隱藏「資金帳戶」、「員工/帳號管理」，不顯示「新增車輛」按鈕
- [ ] ProtectedRoute：可選加入 `allowedRoles` 參數，於路由層檢查角色
- [ ] VehicleDetail：依 `user.role` 條件顯示/隱藏敏感欄位（但後端已 JSON 不含，前端隱藏是額外 UX 層）
- [ ] Dashboard：依 role 只顯示可見的內容（sales 版本不含金額卡片）

### 2.6 測試（關鍵，使用 API 層驗證而非前端斷言）

- [ ] Feature test：sales 直接呼叫 `/api/vehicles/{id}`，使用 `assertJsonMissingPath('purchase_price')` 驗證 purchase_price 欄位**不存在**（不是值為 0）
- [ ] Feature test：sales 直接呼叫 `/api/vehicles/{id}`，驗證 `asking_price`/`floor_price`/`sold_price` 不在 JSON
- [ ] Feature test：sales 直接呼叫 `/api/dashboard/summary`，使用 `assertJsonMissingPath('cash_balance')` 驗證不含金額欄位，但包含 `vehicle_counts`/`monthly_sold_count`
- [ ] Feature test：sales 呼叫 `/api/cash-accounts/balances` 應回 403 Forbidden
- [ ] Feature test：sales 呼叫 `/api/users` 任何方法應回 403
- [ ] Feature test：manager 呼叫 `/api/users` 應回 403，呼叫 `/api/cash-accounts` 寫入應回 403
- [ ] Feature test：manager 呼叫 `/api/vehicles/{id}` 可看 purchase_price（資訊完整）
- [ ] Feature test：admin 呼叫所有路由都可見完整資訊

---

## 3. 一般收支審核流程與 Approved-only 金額彙總（對應 `企劃書_v1.1.md` §8）

**此階段直接影響正式餘額與財務統計，是核心風險最高的部分之一。**

### 3.1 審核流程邊界定義（新增）

**重要邊界說明：**
- `approval_status` 字段**僅套用於一般收支 CRUD**，即 `source_type='manual'` 的使用者主動記錄的收支。
- **不套用於既有的車輛流程收支**：訂金（`source_type='vehicle_workflow'`）、尾款、退款、購車付款、車輛快捷支出等，這些永遠 `approval_status='approved'`，不進入審核佇列。
- 原因：車輛狀態流程（整備→上架→保留→成交）不能被 pending 審核卡住，訂金收了就要能保留、尾款收了要能成交。
- 這**不是正式會計、不是傳票簽核**，只是一個簡化的「一般開支申請→admin 核准」流程，避免員工隨意記帳。

**既有資料與 source_type 轉換：**
- 既有所有 `money_entries`（v1.1 上線前已存在的）一律 `approval_status='approved'`，無論 source_type。
- 回填時無需區別 source_type，統一 approved，避免升級後追溯影響正式餘額。

**建立規則：**
- `admin` 透過一般 `/api/money-entries` 建立的 manual 收支：`approval_status='approved'`（直接生效）
- `manager` 透過一般 `/api/money-entries` 建立的 manual 收支：`approval_status='pending'`（需 admin 核准）
- `sales` 透過一般 `/api/money-entries` 建立的 manual 收支：`approval_status='pending'`（需 admin 核准）
- 任何角色透過 `/api/vehicles/{id}/purchase-payment` 等快捷端點建立的流程收支：`approval_status='approved'`，`source_type='vehicle_workflow'`

**核准/駁回規則：**
- 僅 `admin` 可呼叫 `/api/money-entries/{id}/approve` 與 `/reject`
- 核准後 `approval_status='approved'`，`approved_by=$userId`，`approved_at=now()`
- 駁回後 `approval_status='rejected'`，同上
- 一旦 `approval_status` 不為 `pending`，**不可再改變** —— 即 approved/rejected 是單向的、不可逆的。若要修正 rejected 或已 approved 的項目，需建立新的 entry，舊紀錄保留供稽核。
- `pending`/`approved`/`rejected` 三狀態，不可跳轉回 pending。

### 3.2 Schema、Model、Service、API

Schema：
- [ ] Migration：money_entries 新增 `approval_status`（string, default 'approved'，約束 in:'approved','pending','rejected'）
- [ ] Migration：money_entries 新增 `approved_by`（foreignId→users, nullable, restrictOnDelete）
- [ ] Migration：money_entries 新增 `approved_at`（timestamp, nullable）
- [ ] Migration：既有資料回填 `approval_status='approved'`（無論 source_type）

Model：
- [ ] MoneyEntry Model：新增欄位到 casts（`approved_at` 為 datetime）
- [ ] MoneyEntry Model：**不將** `approval_status`/`approved_by`/`approved_at` 加入 fillable（防止前端傳入偽造），由 Service 明確設定

Service 層：
- [ ] MoneyEntryService::createEntry()：根據登入使用者 `role` 決定 `approval_status`
  - `role='admin'`：`approval_status='approved'`
  - `role='manager'` 或 `role='sales'`：`approval_status='pending'`
  - 不可由 FormRequest 傳入 `approval_status`（驗證規則不包含此欄）
- [ ] MoneyEntryService::updateEntry()：只有 `approval_status='pending'` 的 entry 可編輯；approved/rejected 拒絕編輯，返回 422
- [ ] MoneyEntryService::deleteEntry()：同上，rejected/approved 不可刪除
- [ ] MoneyEntryService：新增 `approve(MoneyEntry $entry, User $user)` 方法
  - 驗證 `approval_status='pending'`
  - 設定 `approved_by=$user->id`，`approved_at=now()`，`approval_status='approved'`
  - 保存並返回
- [ ] MoneyEntryService：新增 `reject(MoneyEntry $entry, User $user)` 方法
  - 驗證 `approval_status='pending'`
  - 設定 `approved_by=$user->id`，`approved_at=now()`，`approval_status='rejected'`
  - 保存並返回

### 3.3 Approved-only 金額彙總（新增，第 4 階段內容整合於此）

**核心原則：所有正式財務統計必須只計入 `approval_status='approved'` 的 entry，pending/rejected 不計入。**

**建議改進：**
- 在 `MoneyEntry` Model 增加 scope `scopeApproved()`，方便各處重複使用而不用手寫 `where('approval_status', 'approved')`。
- 或在 `MoneyEntryService` 增加一個 helper 方法 `approvedQuery()` 返回 `MoneyEntry::query()->where('approval_status', 'approved')`。

**必須修改的呼叫點（見實際程式碼位置）：**

1. **`MoneyEntryService::balanceForAccount(CashAccount $account)`**
   - 現狀：直接 sum 所有 income/expense
   - 修改：加上 `where('approval_status', 'approved')`

2. **`MoneyEntryService::balanceForType(string $type)`**
   - 現狀：直接 sum cash_account type 的所有 income/expense
   - 修改：加上 `where('approval_status', 'approved')`

3. **`DashboardService::buildSummary()` 內的 monthly_income/monthly_expense**
   - 現狀：`MoneyEntry::query()->where('direction', 'income')->sum('amount')`
   - 修改：加上 `->where('approval_status', 'approved')`

4. **`VehicleService::financialSummary(Vehicle $vehicle)`**
   - 現狀：直接 sum 該車所有 income/expense
   - 修改：加上 `->where('approval_status', 'approved')`

5. **`VehicleService::buildFinalPaymentWarning(Vehicle $vehicle)`**
   - 現狀：計算 `$incomeTotal` 與 `sold_price` 比對
   - 修改：只計 `approval_status='approved'` 的 income，避免 pending 收款讓檢查誤判

6. **`VehicleService::printClosingData(Vehicle $vehicle)` 裡的 money entries**
   - 現狀：取出該車所有 entries，調用 `financialSummary()` 計算 income/expense/gross_profit
   - 修改：financialSummary 已加過濾後自動生效，但列印頁面的 entries 呈現需要決策：
     - 可選 A：只列印 approved entries（乾淨，但隱藏 pending/rejected 紀錄）
     - 可選 B：列印全部 entries 但用備註或顏色標註審核狀態（透明，展示待審核狀況）
     - 建議採 B，同時 summary 只計 approved，讓審核狀態可見但不影響正式統計

API 層：
- [ ] StoreMoneyEntryRequest：**不包含** `approval_status` 驗證規則（前端不可傳）
- [ ] 新增 `ApproveMoneyEntryRequest` 或在 Controller 驗證 entry 存在且 `status='pending'`
- [ ] 新增 `RejectMoneyEntryRequest` 同上
- [ ] routes/api.php：新增 `PATCH /api/money-entries/{id}/approve` 與 `/reject`，加 `middleware('role:admin')`
- [ ] MoneyEntryResource：輸出 `approval_status`, `approved_by`, `approved_at`（前端需要顯示審核狀態）

### 3.4 前端

- [ ] MoneyEntryList：新增「待審核」篩選器（apex `approval_status='pending'`）
- [ ] MoneyEntryList：admin 檢視時可看待審核清單，每筆 entry 可呼叫核准/駁回操作
- [ ] MoneyEntryList：非 admin 的使用者看不到審核相關 UI（role 判斷隱藏）
- [ ] 核准/駁回後觸發重新載入，確認 Dashboard/餘額/財務摘要立即更新（核准後才計入）

### 3.5 測試（關鍵，需驗證三層金額彙總都生效）

- [ ] Feature test：admin 建立 manual entry → `approval_status='approved'`
- [ ] Feature test：manager 建立 manual entry → `approval_status='pending'`
- [ ] Feature test：sales 建立 manual entry → `approval_status='pending'`
- [ ] Feature test：vehicle_workflow/vehicle_shortcut 流程產生的 entry → `approval_status='approved'`，`source_type='vehicle_workflow'`
- [ ] Feature test：pending manual entry 不影響 `MoneyEntryService::balanceForAccount()`
- [ ] Feature test：pending manual entry 不影響 `MoneyEntryService::balanceForType()`
- [ ] Feature test：pending manual entry 不影響 `DashboardService::summary()` 的 `monthly_income`/`monthly_expense`
- [ ] Feature test：pending manual entry 不影響 `VehicleService::financialSummary()` 的 income/expense/gross_profit
- [ ] Feature test：approved 後，上述四個方法都計入該筆金額
- [ ] Feature test：rejected manual entry 永不計入任何金額彙總
- [ ] Feature test：approved/rejected 後不可更新或刪除 entry（回 422）
- [ ] Feature test：approved/rejected 狀態不可逆轉回 pending
- [ ] Feature test：manager 無法呼叫 approve/reject 端點（403）
- [ ] Feature test：sales 無法呼叫 approve/reject 端點（403）
- [ ] Feature test：建車同步購車付款產生的 initial_purchase_payment entry（見第 6 階段）應 approved，不進審核流程

---

## 4. Customer Module（對應 `企劃書_v1.1.md` §6）

Schema：
- [ ] Migration：`customers` 資料表
  - id, name, phone, line_id, customer_type, source, address, notes, created_by, updated_by, created_at, updated_at
  - Indexes on name, phone
- [ ] Migration：vehicles 新增 `seller_customer_id`/`buyer_customer_id`（nullable, foreign keys with `nullOnDelete`）

Model/Service/Controller：
- [ ] Customer Model：fillable, relations
- [ ] CustomerService：CRUD 方法，delete 時檢查是否有關聯 vehicles（若有則拒絕，返回錯誤訊息）
- [ ] CustomerController：全 CRUD，依角色矩陣控制權限
- [ ] StoreCustomerRequest/UpdateCustomerRequest：驗證規則
- [ ] CustomerResource：輸出格式

Routes：
- [ ] routes/api.php：`Route::apiResource('customers', CustomerController::class);` 在 auth 中間件下
- [ ] 依角色限制 delete（僅 admin）

Vehicle 整合：
- [ ] Vehicle Model：新增 `sellerCustomer()` 與 `buyerCustomer()` belongsTo relations
- [ ] StoreVehicleRequest/UpdateVehicleRequest：新增 `seller_customer_id`/`buyer_customer_id`（nullable, exists:customers）
- [ ] VehicleResource：新增欄位輸出
- [ ] VehicleService::createVehicle()：接收可選的 customer_id，保存到 FK
- [ ] VehicleDetail 保留流程：前端可選 buyer_customer_id
- [ ] 保留邏輯同步傳送 buyer_customer_id 到後端

前端：
- [ ] 新增 `/customers` 路由與三個頁面：列表/建立/詳情
- [ ] Sidebar：新增「客戶」導航項目
- [ ] CustomerList：搜尋、篩選、刪除操作（admin only）
- [ ] CustomerDetail：顯示作為買方/賣方的車輛清單、相關成交摘要
- [ ] VehicleCreate：seller_customer_id 選擇欄（建檔時快照 seller_name/phone）
- [ ] VehicleDetail/ReserveModal：buyer_customer_id 選擇欄

測試：
- [ ] Feature test：customer 刪除阻擋（若有關聯 vehicles）
- [ ] Feature test：快照欄位（seller_name/phone）不隨 customer 異動改變
- [ ] Feature test：sales 不可刪除 customer（role check）

---

## 5. 車輛入庫建檔欄位與表單（對應 `企劃書_v1.1.md` §4）

Schema：
- [ ] Migration：vehicles 新增 `displacement` (排氣量，nullable string)
- [ ] Migration：vehicles 新增 `transmission` (變速系統，nullable string)
- [ ] Migration：vehicles 新增 `fuel_type` (燃料，nullable string)
- [ ] Migration：vehicles 新增 `parking_location` (停放位置，nullable string)
- [ ] Migration：vehicles 新增 `has_registration_document` (boolean, default false)
- [ ] Migration：vehicles 新增 `has_spare_key` (boolean, default false)
- [ ] Migration：vehicles 新增 `is_transfer_completed` (boolean, default false)
- [ ] Migration：vehicles 新增 `is_inspection_completed` (boolean, default false)
- [ ] Migration：vehicles 新增 `is_preparation_completed` (boolean, default false)
- [ ] Migration：vehicles 新增 `lien_note` (text, nullable)
- [ ] Migration：vehicles 新增 `condition_note` (text, nullable)

Model/Request/Resource：
- [ ] Vehicle Model：新欄位加入 fillable、casts（booleans）
- [ ] StoreVehicleRequest/UpdateVehicleRequest：新欄位驗證規則
- [ ] VehicleResource：新欄位輸出

前端：
- [ ] VehicleCreate：表單分區調整
  - 基本車輛資料區塊：brand/model/year/license_plate/vin/mileage_km/color/displacement/transmission/fuel_type/parking_location
  - 買入資料區塊：purchase_date/purchase_source_type/seller_customer_id/seller_name/seller_phone/purchase_price
  - 入庫檢核區塊：checkboxes for has_registration_document/has_spare_key/is_transfer_completed/is_inspection_completed/is_preparation_completed + lien_note/condition_note textareas
  - 購車付款區塊（見第 6 階段）
  - 備註區塊：notes
- [ ] VehicleDetail：新增面板顯示入庫檢核狀態，sales 無法編輯任何欄位
- [ ] 列印頁：同步顯示新欄位

測試：
- [ ] Feature test：新欄位建立、更新、回傳
- [ ] Feature test：既有資料與新欄位相容（migration backward compatible）
- [ ] Feature test：sales 角色無編輯權限

---

## 6. 建車同步購車付款（對應 `企劃書_v1.1.md` §5）

**此階段涉及交易一致性、idempotency、stock_no 並發，是第二高風險的項目。**

### 6.1 Idempotency 與 stock_no 並發設計（新增）

**背景與設計約束：**
- 建車與初始購車付款必須在同一個 DB transaction 內，全成全敗。
- 若付款帳戶停用或驗證失敗，**整個交易回滾，車輛也不存在**（不可殘留半成品）。
- 前端網路不穩定時，可能重送相同請求（相同 `idempotency_key`）；系統應 replay 原紀錄，不可重複建車。
- 同時存在兩個風險：
  1. 相同 `idempotency_key` 並發送出（已建車完成，前端再送一遍） → 應 replay，不重複
  2. 不同 `idempotency_key` 並發送出（不同分頁/設備）→ 應各建一台車，stock_no 也各不同（existing `generateStockNo()` 已用 lockForUpdate 處理）

**Idempotency 與 stock_no 共存：**
- `vehicles.idempotency_key` 必須 **unique nullable**。
- `vehicles.stock_no` 已有 unique index（請確認 migration 中是否有；若沒有，本階段需補）。
- `createVehicle()` 改寫為 5 方法模式（見下），與 `reserveVehicle()` 結構同構：
  1. 正規化資料
  2. 在 transaction 內檢查 idempotency_key，若存在則 replay；若不存在則 create
  3. stock_no 產生發生在 transaction 內，用 `lockForUpdate()` 競爭，race 後 rollback 會觸發 catch
  4. catch QueryException 時，re-enter 新 transaction，用 `lockForUpdate()` 重讀 winner
  5. 確保兩個並發中只有一個成功 insert，另一個被 lock 並拿到 duplicate key exception，然後安全 replay

**Payment idempotency key 派生：**
- `initial_purchase_payment` 產生的 MoneyEntry 的 idempotency_key 應**由 vehicle key 派生**，例如 `"{vehicleIdempotencyKey}:initial-payment"`
- 好處：不需前端額外生成第二把 key，自動保證該次建車的 payment entry 在 vehicle 內唯一且可重放
- 風險：若 vehicle 建立失敗（payment 驗證失敗），payment 的 idempotency_key 也不會被記錄，重試時會重新生成相同派生 key，replay 邏輯自動處理

### 6.2 Schema、API、Service

Schema：
- [ ] Migration：vehicles 新增 `idempotency_key`（string, nullable, unique index）
- [ ] 檢查 vehicles 表是否已有 `stock_no` unique index；若沒有，補 migration（應已有）

API 層：
- [ ] StoreVehicleRequest：新增 `idempotency_key`（required, string, max:100）
- [ ] StoreVehicleRequest：新增巢狀 `initial_purchase_payment`（nullable array）
  - `initial_purchase_payment.amount`（required_with:initial_purchase_payment, integer, min:0）
  - `initial_purchase_payment.cash_account_id`（required_with:initial_purchase_payment, exists:cash_accounts,id）
  - `initial_purchase_payment.payment_date`（nullable, date）
  - `initial_purchase_payment.note`（nullable, string）
- [ ] StoreVehicleRequest：驗證 sales 不可傳 `initial_purchase_payment`（或傳了則驗證失敗 422）

Service 層（最關鍵）：
- [ ] `VehicleService::createVehicle()` 改寫為 5 方法模式，沿用既有 `reserveVehicle()` pattern：
  1. `normalizeCreateVehicleData($data, $userId)` — 正規化與驗證資料，確保金額非負、帳戶存在等
  2. `createVehicleInsideTransaction($data, $userId)` — 核心建車邏輯，單一 `DB::transaction` 內：
     - 檢查 `idempotency_key` 是否已存在；若存在，調用 replay 邏輯
     - 否則產生 `stock_no`（用既有 `lockForUpdate()` 邏輯）
     - 建立 Vehicle row，保存 `idempotency_key`
     - 若 `initial_purchase_payment` 存在：
       - 驗證 `cash_account` 啟用（assertCashAccountActive）
       - 建立 MoneyEntry：`category='購車付款'`, `direction='expense'`, `vehicle_id=$vehicle->id`, `source_type='vehicle_workflow'`, `idempotency_key="{vehicleKey}:initial-payment"`, `approval_status='approved'`（購車付款不進審核，直接生效）
     - 若建立失敗（例如 cash_account 被停用），throwable 會讓 transaction rollback
  3. `replayRacedVehicleCreateAfterRollback($queryException, $idempotencyKey, $data, $userId)` — duplicate key race 時呼叫：
     - 在新 transaction 內用 `lockForUpdate()` 重讀 `Vehicle::where('idempotency_key', $key)`
     - 若找到，調用 replay-or-reject 邏輯
  4. `replayOrRejectVehicleCreate($existingVehicle, $data)` — payload 比對：
     - 逐欄位比對 vehicle attributes 與 input（見下方細節）
     - 若一致，返回既有 vehicle（200 但內容是既有的）
     - 若不一致，throw ValidationException（422）
  5. `isSameVehicleCreateRequest($existingVehicle, $normalizedData)` — 欄位比對邏輯：
     - 比對：brand, model, year, license_plate, vin, mileage_km, color, purchase_price, purchase_date, purchase_source_type, seller_name, seller_phone, notes，以及 initial_purchase_payment 內容
     - 不比對：stock_no, status, created_at 等系統欄位

Controller 層：
- [ ] VehicleController::store() 改寫以調用上述方法，正確 catch 並處理 QueryException

權限：
- [ ] sales 角色若傳 `initial_purchase_payment` 欄位，應拒絕（422 或 403，建議 422 驗證失敗）
- [ ] sales 不可建車（見第 2 階段，vehicles create 應 `middleware('role:admin,manager')`）

### 6.3 前端

- [ ] VehicleCreate：於 component mount 時產生 `idempotencyKey`，保存在 state（不是每次送出時重新產生，確保同一邏輯操作中 key 穩定）
- [ ] VehicleCreate：沿用既有 `generateIdempotencyKey()` 工具
- [ ] VehicleCreate：新增「購車付款」區塊：
  - checkbox：「同步建立購車付款」
  - 若勾選，顯示：
    - amount（required）
    - cash_account_id select（required）
    - payment_date（optional，預設今日）
    - note（optional）
- [ ] 表單送出時，拼組 payload：`{ idempotency_key, ...vehicle_fields, initial_purchase_payment: {...} }`
- [ ] 提交後，若失敗且前端自動重試，使用**同一個 idempotency_key**（不重新產生）

### 6.4 測試（必須包含，風險最高，需使用 MySQL/MariaDB，不只 SQLite）

**基本功能測試：**
- [ ] Feature test：純建車無付款 → Vehicle 建立成功，無 MoneyEntry
- [ ] Feature test：建車+付款（有效帳戶）→ Vehicle + MoneyEntry 同時建立
- [ ] Feature test：建車+付款（停用帳戶）→ 整個交易回滾，Vehicle 不存在，MoneyEntry 也不存在

**Idempotency 測試：**
- [ ] Feature test：相同 idempotency_key + 相同 payload → 第二次返回 200，內容完全相同（stock_no, created_at 都一樣），無重複 Vehicle
- [ ] Feature test：相同 idempotency_key + 不同 payload（例如 brand 不同）→ 第二次返回 422，訊息指出 idempotency_key 已被不同內容使用
- [ ] Feature test：不同 idempotency_key → 兩次各建一台車，stock_no 不同

**Concurrent Race 測試（僅 MySQL，不用 SQLite）：**
- [ ] Feature test (MySQL concurrency)：兩個並發請求，**相同 idempotency_key** + 相同 payload，持續 lockForUpdate 爭搶 stock_no：
  - 預期：只有一台 Vehicle 被建立，stock_no 唯一
  - 預期：只有一筆 initial_purchase_payment MoneyEntry
  - 兩個請求都應返回 200，內容相同（或一個 200 owner，一個 replay）
  - 資料庫最終一致性正確
- [ ] Feature test (MySQL concurrency)：兩個並發請求，**不同 idempotency_key**：
  - 預期：建立兩台 Vehicle，各有不同 stock_no
  - 預期：各有一筆 initial_purchase_payment MoneyEntry
  - stock_no 生成邏輯（`V{Ymd}{seq}` 遞增）應不受影響

**Idempotency + stock_no 共存測試：**
- [ ] Feature test：相同 idempotency_key 三次並發送出，第一次成功 insert，第二、三次 race 競爭失敗，然後 rollback + lockForUpdate 重讀：
  - 預期：最終 Vehicle count = 1，stock_no 也只有 1 個
  - 三個請求都應安全返回（不是 500）

**Payment 驗證測試：**
- [ ] Feature test：cash_account_id 不存在 → 建車驗證失敗（422），Vehicle 不建立
- [ ] Feature test：amount 為 0 或負數 → 驗證失敗，Vehicle 不建立
- [ ] Feature test：payment_date 在未來 → 如無特殊限制則允許（或依業務規則）

**Permission 測試：**
- [ ] Feature test：sales 傳 `initial_purchase_payment` → 422 或 403，Vehicle 不建立
- [ ] Feature test：sales 不可呼叫 POST /vehicles（見第 2 階段 middleware 限制）

**與第 3 階段審核流程的交互：**
- [ ] Feature test：建車同步產生的 initial_purchase_payment MoneyEntry 應 `approval_status='approved'`，不進待審核佇列
- [ ] Feature test：該 payment 直接計入單車支出、毛利、Dashboard
- [ ] Feature test：若後來有 manager 或 sales 添加的一般支出 pending，不影響該車的成交結案檢查（因為購車付款已 approved）

---

## 7. UI/UX 收斂與完整 Smoke（對應 `企劃書_v1.1.md` §10, §12）

UI 檢查：
- [ ] 新增頁面（Customer/Employee/Money Entry 審核）依 UI.md 語意色彩 token 檢查
- [ ] 所有新增表單遵守「visible labels + required `*` marker + per-field error message + on-blur validation」
- [ ] 依 UI.md 定義使用 badge、button、card、sidebar 規範
- [ ] light/dark mode 兩種主題皆測試

Smoke 驗收（22 項，對應 `企劃書_v1.1.md` §12）：
- [ ] 1. admin 帳號登入
- [ ] 2. 建立完整入庫車輛（含所有新欄位）
- [ ] 3. 建車時同步建立購車付款（核驗付款金額、帳戶、日期）
- [ ] 4. 車輛詳情看到購車付款（MoneyEntry 顯示）
- [ ] 5. 單車支出/毛利摘要正確（購車付款納入計算）
- [ ] 6. 新增整備支出
- [ ] 7. 整備完成上架（填寫 asking_price/floor_price）
- [ ] 8. 建立客戶（seller/buyer 客戶記錄）
- [ ] 9. 保留車輛時選買方客戶（buyer_customer_id）
- [ ] 10. 收訂金
- [ ] 11. 收尾款
- [ ] 12. 成交結案
- [ ] 13. 列印成交結案收支明細
- [ ] 14. sales 帳號登入 → 確認看不到 purchase_price/毛利/資金餘額
- [ ] 15. sales 帳號 → 保留/收尾款/成交結案可操作
- [ ] 16. sales 帳號 → 新增車輛/上架/定價不可用
- [ ] 17. sales 帳號 → 可送出一般支出申請，不影響餘額
- [ ] 18. manager 帳號登入 → 車輛建檔/上架/客戶管理可用
- [ ] 19. manager 帳號 → 一般支出需 admin 核准
- [ ] 20. admin 帳號 → 核准/駁回一般支出
- [ ] 21. 支出 approved 後 → Dashboard/CashAccount/Vehicle 摘要正確更新
- [ ] 22. 支出 rejected → 不影響餘額，紀錄保留

回歸測試：
- [ ] 既有 v1.0 flow（admin 帳號操作）應行為一致
- [ ] 既有資料相容（migration backward compatible）
- [ ] 既有列印頁可使用

---

## 8. 文件收尾

- [ ] backend/API.md：補充 v1.1 新端點文件
  - /api/users/{id}/role (PATCH)
  - /api/money-entries/{id}/approve (PATCH)
  - /api/money-entries/{id}/reject (PATCH)
  - /api/customers/* (full CRUD)
  - /api/vehicles/* (新增 idempotency_key/initial_purchase_payment)
- [ ] README.md：如需更新（新欄位、新角色說明等）則更新
- [ ] 本檔案（PLAN_v1.1.md）：逐項勾選完成狀態
