# PLAN_v1.1.md — ERPV2 v1.1 實務工作流補強 進度清單

本清單對應 `企劃書_v1.1.md` 的七大項功能補強，逐步追蹤實作進度。

---

## 0. 前置準備
- [ ] 閱讀 `企劃書_v1.1.md` 全文並對齊範圍
- [ ] 確認角色矩陣（§3.4）與審核流程規則（§8）無疑義
- [ ] 確認實作順序（§14）與本清單對應

---

## 1. Users Role / 員工帳號欄位（對應 `企劃書_v1.1.md` §3.3, §7.2）

Schema 與 Model：
- [ ] Migration：users 新增 `role`（string, NOT NULL, 預設值為 'manager'）
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
- [ ] UserService：新增 `setRole($user, $role)` 方法，取代既有 `setAdmin()`
- [ ] UserService：沿用既有「至少保留一位 active admin」鎖定邏輯，改為 role=admin
- [ ] UserController：`updateRole()` 呼叫 `setRole()`

前端：
- [ ] 前端：`users` 頁面改名為「員工/帳號管理」（可作 Sidebar nav 與頁面標題更新）
- [ ] 前端：建立/編輯表單新增 phone/job_title/hire_date/notes 欄位
- [ ] 前端：角色指派為固定下拉選單（`admin`/`manager`/`sales` 三選項）
- [ ] 前端：若登入者非 `admin`，entire 使用者管理頁應不可見或 403

測試：
- [ ] Feature test：role 回填正確性（is_admin→role）
- [ ] Feature test：最後一位 active admin 不可被降級為其他角色
- [ ] Feature test：manager/sales 無法呼叫 user 管理 API

---

## 2. Role-based Policy / Middleware / Resource 遮蔽（對應 `企劃書_v1.1.md` §3, §5, §9）

**此階段是全案安全性最關鍵的部分，後端敏感欄位遮蔽必須正確**。

Policy 層：
- [ ] 新增 `backend/app/Policies/VehiclePolicy.php`
- [ ] 新增 `backend/app/Policies/MoneyEntryPolicy.php`
- [ ] 新增 `backend/app/Policies/CashAccountPolicy.php`
- [ ] 新增 `backend/app/Policies/CustomerPolicy.php`
- [ ] 新增 `backend/app/Policies/UserPolicy.php`
- [ ] 在 `bootstrap/providers.php` 或 `AuthServiceProvider` 註冊 Policies

Middleware 層：
- [ ] 新增 `backend/app/Http/Middleware/EnsureUserHasRole.php`（取代 `EnsureUserIsAdmin`）
- [ ] routes/api.php：將既有 `middleware('admin')` 改為 `middleware('role:admin')`
- [ ] routes/api.php：依角色矩陣（§3.4）為各路由分配正確的 `middleware('role:...')`
  - 使用者管理：`role:admin` only
  - 現金帳戶寫入：`role:admin` only
  - Customer 寫入：`role:admin,manager,sales`（但 delete 僅 admin）
  - Vehicle/Money Entry：依矩陣細節分配

Resource 層：
- [ ] VehicleResource：`purchase_price` 依角色條件 `->when($request->user()->hasAnyRole('admin','manager'), fn() => $this->purchase_price)` 或類似
- [ ] VehicleResource：`asking_price`/`floor_price`/`sold_price`/毛利（如有 expose）同樣依角色條件
- [ ] MoneyEntryResource：確認 `amount` 依角色條件（`sales` 不可看）
- [ ] CashAccount Controller：`balances` 端點新增角色判斷，`sales` 無法呼叫
- [ ] 檢查是否有其他 Resource 需依角色遮蔽

前端：
- [ ] AppLayout/Sidebar：依 `user.role` 條件顯示／隱藏功能項目
  - `admin`：全部顯示
  - `manager`：隱藏「員工/帳號管理」，不顯示「資金帳戶」管理操作
  - `sales`：隱藏「資金帳戶」、「員工/帳號管理」，不顯示「新增車輛」按鈕
- [ ] ProtectedRoute：可選加入 `allowedRoles` 參數，於路由層檢查角色
- [ ] VehicleDetail：依 `user.role` 條件顯示/隱藏 purchase_price/毛利 panels
- [ ] Dashboard：依 role 只顯示可見的金額資訊

測試：
- [ ] Feature test：以 3 個角色分別呼叫敏感端點，驗證**原始 JSON 遮蔽是否正確**（非只測 HTTP 狀態碼）
- [ ] Feature test：sales 直接呼叫 `/api/vehicles/{id}`，確認 `purchase_price` 字段不在回應中
- [ ] Feature test：sales 呼叫 `/api/cash-accounts/balances` 應 403 或無法呼叫
- [ ] Feature test：sales 呼叫 `/api/users` 應 403
- [ ] Feature test：manager 呼叫 `/api/users`/`/api/cash-accounts` 寫入應 403

---

## 3. 一般收支審核流程（對應 `企劃書_v1.1.md` §8）

**此階段也是核心風險高的部分，關乎餘額計算正確性**。

Schema：
- [ ] Migration：money_entries 新增 `approval_status`（string, default 'approved'）
- [ ] Migration：money_entries 新增 `approved_by`（foreignId→users, nullable, restrictOnDelete）
- [ ] Migration：money_entries 新增 `approved_at`（timestamp, nullable）
- [ ] Migration：既有資料回填 `approval_status='approved'`

Model：
- [ ] MoneyEntry Model：新增欄位到 casts 與 fillable（`approved_by`/`approved_at` 不應 mass-assign，由 Service 設定）

Service 層：
- [ ] MoneyEntryService::createEntry()：新增邏輯依角色決定 approval_status
  - `role=admin`：`approval_status='approved'`
  - `role=manager`/`role=sales`：`approval_status='pending'`
  - 不可由前端傳入 `approval_status`
- [ ] MoneyEntryService::updateEntry()：pending 狀態可編輯，approved/rejected 不可編輯
- [ ] MoneyEntryService：新增 `approve($entry, $user)` 方法（寫入 `approved_by`/`approved_at`，狀態鎖定）
- [ ] MoneyEntryService：新增 `reject($entry, $user)` 方法（同上，但狀態為 rejected）
- [ ] **核心**：MoneyEntryService::balanceForAccount() 與 balanceForType() 加上 `where('approval_status', 'approved')` 條件
- [ ] 確認 Dashboard、CashAccount、Vehicle 單車收支摘要 皆呼叫上述方法，無繞過

API：
- [ ] StoreMoneyEntryRequest：移除 `approval_status`（前端不可傳）
- [ ] 新增 `ApproveMoneyEntryRequest`（驗證 entry 存在且 status=pending）
- [ ] 新增 `RejectMoneyEntryRequest`（同上）
- [ ] 新增 Controller 或 routes 處理 `PATCH /api/money-entries/{id}/approve` 與 `/reject`（admin only）
- [ ] MoneyEntryResource：輸出 `approval_status`/`approved_by`/`approved_at`

前端：
- [ ] MoneyEntryList：新增「待審核」篩選器（approval_status=pending）
- [ ] MoneyEntryList：admin 檢視時可看待審核清單，可呼叫核准/駁回操作
- [ ] 核准/駁回後觸發重新載入，確認餘額影響（核准後才計入）

測試：
- [ ] Feature test：pending 狀態不影響餘額計算
- [ ] Feature test：approved 後影響餘額
- [ ] Feature test：rejected 不影響餘額且狀態不可逆
- [ ] Feature test：balanceForAccount()/balanceForType() 的過濾邏輯正確
- [ ] Feature test：Dashboard/CashAccount/Vehicle 三處呼叫端都回歸測試
- [ ] Feature test：admin 可核准/駁回，manager/sales 無法呼叫核准端點

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

**此階段涉及交易一致性與冪等設計，是第二高風險的項目。**

Schema：
- [ ] Migration：vehicles 新增 `idempotency_key`（string, nullable, unique index）

API 層：
- [ ] StoreVehicleRequest：新增 `idempotency_key`（required, string, max:255）
- [ ] StoreVehicleRequest：新增巢狀 `initial_purchase_payment`（nullable array）
  - `initial_purchase_payment.amount`（required_with:initial_purchase_payment, integer, min:0）
  - `initial_purchase_payment.cash_account_id`（required_with:initial_purchase_payment, exists:cash_accounts）
  - `initial_purchase_payment.payment_date`（nullable, date）
  - `initial_purchase_payment.note`（nullable, string）

Service 層（最關鍵）：
- [ ] VehicleService::createVehicle()：改寫為五方法模式，比照 `reserveVehicle()`：
  1. `normalizeCreateVehicleData($data)` — 正規化資料
  2. `createVehicleInsideTransaction($data, $userId)` — 單一 transaction 內：
     - stock_no lockForUpdate + insert vehicle
     - 若有 initial_purchase_payment，建立 MoneyEntry（direction=expense, category=購車付款, source_type=vehicle_workflow）
     - 巢狀 MoneyEntry 的 idempotency_key = `"{vehicleIdempotencyKey}:initial-payment"`
  3. `replayRacedVehicleCreateAfterRollback($key, $request)` — race 後在新交易中 lockForUpdate 重讀
  4. `replayOrRejectVehicleCreate($existing, $request)` — payload 比對 replay-or-reject
  5. `isSameVehicleCreateRequest($vehicle, $request)` — 欄位比對邏輯
- [ ] 沿用既有 MoneyEntryService 的 `isSame...Request` 與 `replayRaced...AfterRollback` pattern

權限：
- [ ] StoreVehicleRequest 或 Controller：sales 不可傳 initial_purchase_payment（或傳了也被忽略+回 422）
- [ ] 僅 admin/manager/sales 的特定銷售操作可含 initial_purchase_payment

前端：
- [ ] VehicleCreate：於建車時在 mount 時產生 `idempotencyKey` 並保持穩定（沿用 `generateIdempotencyKey()`）
- [ ] VehicleCreate：新增「購車付款」區塊（toggle + amount/cash_account_id/payment_date/note 表單）
- [ ] 表單送出時拼組 payload，含 `idempotency_key` 與可選的 `initial_purchase_payment`

測試（必須包含，風險最高）：
- [ ] Feature test：純建車無付款（payload 不含 initial_purchase_payment）— 成功，無 MoneyEntry
- [ ] Feature test：建車+付款（payload 含 initial_purchase_payment）— 成功，生成 Vehicle + MoneyEntry
- [ ] Feature test：相同 idempotency_key + 相同 payload → replay 原紀錄，無重複 vehicle/payment
- [ ] Feature test：相同 idempotency_key + **不同** payload → 422 ValidationException
- [ ] Feature test：付款驗證失敗（如 cash_account 停用）→ **整個交易回滾，vehicle 也不存在**（最關鍵驗證）
- [ ] Feature test (MySQL concurrency)：兩個並發請求相同 idempotency_key，最終只有一個 vehicle+payment
- [ ] Feature test：idempotency 與 stock_no race-safe 邏輯共存無衝突
- [ ] Feature test：sales 不可送 initial_purchase_payment 或被拒

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
