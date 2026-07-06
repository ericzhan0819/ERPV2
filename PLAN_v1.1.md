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

### 核心原則
- 敏感欄位**不可只靠前端隱藏**，後端 JSON 必須用 `when()`/`unless()` 真正不輸出
- sales 不可見：`purchase_price`, `asking_price`, `floor_price`, `sold_price`、毛利、`amount`、`cash_account_id`、Dashboard 金額欄位
- sales 呼叫金額 endpoint（`/api/cash-accounts/balances`、`/api/money-entries` 等）應 **403**，不是遮蔽版本
- Dashboard 給 sales：只有 `vehicle_counts` 與 `monthly_sold_count`，無金額欄位

### Policy / Middleware
- [x] 新增 VehiclePolicy / MoneyEntryPolicy / CashAccountPolicy / UserPolicy（CustomerPolicy 待第 4 階段 Customer Module 建立後補上）
- [x] 新增 EnsureUserHasRole middleware（取代 EnsureUserIsAdmin）
- [x] routes/api.php 依角色分組：
  - 使用者/現金帳戶寫入：`role:admin` only
  - Customer delete：`role:admin` only（待第 4 階段 Customer Module 建立）
  - Vehicle 新增/編輯/上架：`can:` middleware 綁定 VehiclePolicy（admin,manager）
  - Vehicle 銷售流程（保留/收尾款/成交）：`can:` middleware 綁定 VehiclePolicy（admin,manager,sales）
  - Money Entry CRUD：`role:admin,manager` only（sales 無法操作一般收支，見第 3 階段）
  - Cash Account balances：`role:admin,manager` only

### Resource 遮蔽
- [x] VehicleResource：purchase_price, asking_price, floor_price, sold_price 依角色 `when()`
- [x] MoneyEntryResource：amount, cash_account_id 依角色 `when()`
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

### 邊界定義
- `approval_status` **僅套 manual 收支**（source_type='manual'），不套 vehicle_workflow
- 既有資料一律 `approved`（無論 source_type）
- 車輛流程收支（訂金/尾款/購車付款）永遠 `approved`，不進審核佇列
- approved/rejected 單向不可逆，若要修正需建新 entry

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
- [x] admin 建立 manual → approved，manager/sales 建立 manual → pending
- [x] vehicle_workflow 產生 → 永遠 approved
- [x] pending 不影響 balanceForAccount/balanceForType/Dashboard/Vehicle summary
- [x] approved 後影響，rejected 永不計入
- [x] approved/rejected 後不可編輯/刪除（422），狀態不可逆
- [x] manager/sales 無法呼叫 approve/reject（403）

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
- [ ] Migration：vehicles 新增 displacement/transmission/fuel_type/parking_location（nullable string）
- [ ] Migration：vehicles 新增入庫檢核欄位：has_registration_document/has_spare_key/is_transfer_completed/is_inspection_completed/is_preparation_completed（boolean, default false）
- [ ] Migration：vehicles 新增 lien_note/condition_note（text, nullable）

### Model / API
- [ ] Vehicle Model：fillable + casts（booleans）
- [ ] StoreVehicleRequest/UpdateVehicleRequest：驗證新欄位
- [ ] VehicleResource：輸出新欄位

### 前端
- [ ] VehicleCreate 分區：基本資料 → 買入資料 → 入庫檢核（checkboxes + textareas） → 購車付款（見第 6 階段） → 備註
- [ ] VehicleDetail：顯示檢核狀態，sales 無編輯權限
- [ ] 列印頁：同步新欄位

### 測試
- [ ] 新欄位 CRUD 正常
- [ ] 既有資料相容（backward compatible）
- [ ] sales 無編輯權限

---

## 6. 建車同步購車付款（對應 `企劃書_v1.1.md` §5）

### Idempotency 與並發設計
- `vehicles.idempotency_key` 必須 unique nullable
- `createVehicle()` 改 5 方法模式：normalizeData → createInsideTransaction → replayRacedAfterRollback → replayOrReject → isSame（同 reserveVehicle）
- payment idempotency_key 派生：`"{vehicleKey}:initial-payment"`
- 建車 + payment 在同一 transaction；payment 失敗時整個 vehicle 回滾，無半成品

### Schema / API
- [ ] Migration：vehicles 新增 `idempotency_key`（string, nullable, unique）
- [ ] 確認 vehicles 表已有 `stock_no` unique index
- [ ] StoreVehicleRequest：`idempotency_key` required + `initial_purchase_payment` nested（nullable）
- [ ] 驗證：sales 傳 initial_purchase_payment → 422

### Service / Controller
- [ ] VehicleService::createVehicle()：5 方法模式，race-safe idempotency + stock_no 共存
- [ ] payment 欄位驗證：金額 > 0、cash_account 啟用
- [ ] payment 產生的 MoneyEntry：`source_type='vehicle_workflow'`, `approval_status='approved'`（不進審核）

### 前端
- [ ] VehicleCreate mount 時產生穩定 `idempotencyKey`（不是每次送出重新產生）
- [ ] 新增「購車付款」區塊：checkbox + amount/cash_account_id/payment_date/note
- [ ] 重試時使用同一 idempotency_key

### 測試
- [ ] 純建車無付款 → Vehicle 成功，無 MoneyEntry
- [ ] 建車 + 付款（有效帳戶）→ Vehicle + MoneyEntry 同時建立
- [ ] 付款失敗（帳戶停用） → 整個回滾，Vehicle 不存在
- [ ] 相同 key + 相同 payload → replay，無重複
- [ ] 相同 key + 不同 payload → 422
- [ ] **MySQL concurrency test**（非 SQLite）：並發雙送相同 key → 只有一台 Vehicle 與一筆 payment，兩個請求都安全返回
- [ ] sales 傳 initial_purchase_payment → 422
- [ ] payment entry 應 approved，不進審核

---

## 7. UI/UX 收斂與完整 Smoke（對應 `企劃書_v1.1.md` §10, §12）

### UI 檢查
- [ ] 新增頁面依 UI.md 語意色彩 token、light/dark mode
- [ ] 表單遵守「visible labels + required * + per-field error + on-blur validation」
- [ ] 依 UI.md badge/button/card/sidebar 規範

### Smoke 驗收（22 項，對應 `企劃書_v1.1.md` §12）
- [ ] 1–13：admin 完整入庫流程到成交結案列印
- [ ] 14–17：sales 帳號權限驗證（無 purchase_price/毛利/資金、可銷售流程、無車輛新增/上架/定價、可一般支出但 pending）
- [ ] 18–19：manager 帳號權限驗證（可車輛建檔/上架/客戶、一般支出需核准）
- [ ] 20–22：admin 核准/駁回流程、approved 後正式計入、rejected 不計入

### 回歸測試
- [ ] v1.0 flow（admin）行為一致
- [ ] 既有資料相容
- [ ] 既有列印頁可用

---

## 8. 文件收尾
- [ ] backend/API.md：補充新端點（/api/users/{id}/role, /api/money-entries/{id}/approve, /api/money-entries/{id}/reject, /api/customers/*, /api/vehicles/* 含 idempotency_key）
- [ ] README.md：如需更新（新欄位、角色說明等）
- [ ] 本檔案（PLAN_v1.1.md）：逐項勾選完成狀態
