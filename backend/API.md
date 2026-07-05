# API 文件 — 中古車行內部營運系統 1.0

Base URL：`http://localhost:8000`（依 `.env` `APP_URL` 而定）

所有 API 皆為 JSON。認證採用 Laravel Sanctum SPA（cookie-based session），前端需：

1. 先呼叫 `GET /sanctum/csrf-cookie` 取得 CSRF cookie（Sanctum 內建路由）。
2. 之後所有 request 都要帶 `X-XSRF-TOKEN`（axios 預設會自動處理，需搭配 `withCredentials: true`）。
3. 除了 `POST /api/login`、`POST /api/logout` 外，其餘 `/api/*` 都需要登入（`auth:sanctum` + `active` middleware）。
4. 標註「僅限管理員」的路由另外掛 `admin` middleware，非管理員呼叫會回傳 `403`。

金額欄位一律為整數（新台幣元，非分），對應資料庫 `decimal`/`integer` 欄位，前端不得自行用 float 計算正式金額。

錯誤格式：

- 驗證錯誤（422）：Laravel 預設格式 `{ "message": "...", "errors": { "field": ["..."] } }`
- 一般錯誤（403/404/429）：`{ "message": "..." }`

---

## 1. Auth

### POST /api/login

不需登入。

Request body：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| email | string | 是 | |
| password | string | 是 | |

回傳：`UserResource`（見下方）。

錯誤：
- `422` 帳密錯誤或帳號不存在
- `403` 帳號已被停用（見下方 `EnsureUserIsActive`）
- `429` 短時間內登入失敗次數過多（`Retry-After` header 秒數）

### POST /api/logout

不需登入（設計為冪等，重複呼叫也回 200，避免 client 重試時因 session 已失效而收到 401）。

回傳：`{ "message": "已登出" }`

### GET /api/me

需登入。回傳目前登入者的 `UserResource`。

### UserResource

```json
{
  "id": 1,
  "name": "系統管理員",
  "email": "admin@example.com",
  "is_admin": true,
  "is_active": true
}
```

---

## 2. Dashboard

### GET /api/dashboard/summary

需登入。由 `DashboardService` 計算，前端不得自行拼湊統計數字。

回傳：

```json
{
  "cash_balance": 100000,
  "bank_balance": 500000,
  "other_balance": 0,
  "total_funds": 600000,
  "monthly_income": 300000,
  "monthly_expense": 120000,
  "monthly_net_flow": 180000,
  "vehicle_counts": {
    "preparing": 3,
    "listed": 5,
    "reserved": 1,
    "sold": 8,
    "cancelled": 0
  },
  "monthly_sold_count": 2
}
```

---

## 3. Vehicles

車輛狀態：`preparing`（整備中）→ `listed`（上架中）→ `reserved`（保留中）→ `sold`（已售出），另有 `cancelled`（取消/退車）。

### GET /api/vehicles

Query 參數（`IndexVehicleRequest`）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| search | string | 車牌/車架號/廠牌/車型模糊搜尋 |
| status | string | `preparing`\|`listed`\|`reserved`\|`sold`\|`cancelled` |
| per_page | int | 1~100，預設由 Service 決定 |
| page | int | |

回傳：分頁後的 `VehicleResource` 陣列（Laravel 標準分頁格式：`data`/`links`/`meta`）。

### POST /api/vehicles

新增車輛後自動產生 `stock_no`，並將 `status` 設為 `preparing`。

Request body（`StoreVehicleRequest`）：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| brand | string | 是 | |
| model | string | 是 | |
| year | int | 否 | 1900~2100 |
| license_plate | string | 二擇一 | 與 `vin` 至少擇一填寫 |
| vin | string | 二擇一 | 與 `license_plate` 至少擇一填寫 |
| mileage_km | int | 否 | ≥0 |
| color | string | 否 | |
| purchase_date | date | 否 | |
| purchase_source_type | string | 否 | |
| seller_name | string | 否 | |
| seller_phone | string | 否 | |
| purchase_price | int | 否 | ≥0 |
| asking_price | int | 否 | ≥0 |
| floor_price | int | 否 | ≥0 |
| sales_note | string | 否 | |
| notes | string | 否 | |

回傳：`VehicleResource`。

### GET /api/vehicles/{id}

回傳：

```json
{
  "vehicle": { /* VehicleResource */ },
  "summary": { "income": 0, "expense": 0, "gross_profit": 0 },
  "money_entries": [ /* 該車所有收支，含 cash_account 資訊 */ ]
}
```

`summary` 由後端計算：單車收入合計、單車支出合計、單車毛利（收入 - 支出）。

### PATCH /api/vehicles/{id}

Request body（`UpdateVehicleRequest`）：與 `StoreVehicleRequest` 欄位相同，皆為修改基本/採購/銷售資料，不影響 `status`。

回傳：`VehicleResource`。

### DELETE /api/vehicles/{id}

回傳：`{ "message": "車輛已刪除" }`

### VehicleResource

```json
{
  "id": 1,
  "stock_no": "V202601001",
  "status": "listed",
  "brand": "Toyota",
  "model": "Corolla",
  "year": 2020,
  "license_plate": "ABC-1234",
  "vin": null,
  "mileage_km": 30000,
  "color": "白色",
  "purchase_date": "2026-01-01",
  "purchase_source_type": "拍場",
  "seller_name": "王小明",
  "seller_phone": "0900000000",
  "purchase_price": 300000,
  "asking_price": 380000,
  "floor_price": 350000,
  "listing_date": "2026-01-05",
  "sales_note": null,
  "reserved_at": null,
  "sold_at": null,
  "sold_price": null,
  "buyer_name": null,
  "buyer_phone": null,
  "notes": null,
  "created_at": "2026-01-01T00:00:00.000000Z",
  "updated_at": "2026-01-05T00:00:00.000000Z"
}
```

---

## 4. Vehicle Workflow

### POST /api/vehicles/{id}/list — 整備完成上架

`preparing → listed`。Request body（`ListVehicleRequest`）：

| 欄位 | 型別 | 必填 |
|---|---|---|
| asking_price | int | 是（≥0） |
| floor_price | int | 否（≥0） |
| listing_date | date | 否 |
| sales_note | string | 否 |

回傳：`VehicleResource`。

### POST /api/vehicles/{id}/reserve — 收訂金並保留

`listed → reserved`。同時建立一筆 `訂金收入` money entry。Request body（`ReserveVehicleRequest`）：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| buyer_name | string | 是 | |
| buyer_phone | string | 否 | |
| sold_price | int | 是（≥1） | 成交價 |
| deposit_amount | int | 是（≥1） | 訂金金額 |
| cash_account_id | int | 是 | 收款帳戶，須存在 |
| entry_date | date | 否 | |
| description | string | 否 | |
| idempotency_key | string | 是（max:100） | 防止重複送出造成重複收訂金 |

回傳：`VehicleResource`。

### POST /api/vehicles/{id}/final-payment — 收尾款

建立一筆 `尾款收入` money entry，不改變車輛狀態（結案仍須另外呼叫 close-sale）。Request body（`FinalPaymentVehicleRequest`）：

| 欄位 | 型別 | 必填 |
|---|---|---|
| amount | int | 是（≥1） |
| cash_account_id | int | 是 |
| idempotency_key | string | 是（max:100） |
| entry_date | date | 否 |
| description | string | 否 |

回傳：

```json
{ "vehicle": { /* VehicleResource */ }, "warning": null }
```

`warning` 於尾款加訂金總額與成交價不符時回傳提示文字，不阻擋操作。

### POST /api/vehicles/{id}/close-sale — 成交結案

`reserved → sold`。驗證規則：已有成交價與買方姓名、至少一筆訂金或尾款收入紀錄，否則回傳 422。

Request body（`CloseSaleVehicleRequest`）：

| 欄位 | 型別 | 必填 |
|---|---|---|
| sold_at | date | 否，預設現在時間 |

回傳：`VehicleResource`（`status = sold`）。

---

## 5. Vehicle 快捷收支

以下皆為登入即可呼叫，並各自建立一筆對應的 `money_entries` 紀錄，`vehicle_id` 綁定當前車輛：

### POST /api/vehicles/{id}/purchase-payment — 購車付款（支出）

Request body（`PurchasePaymentVehicleRequest`）：`amount`(必填,int,≥1)、`cash_account_id`(必填)、`entry_date`(選填)、`counterparty_name`(選填)、`description`(選填)、`idempotency_key`(必填,max:100)。

### POST /api/vehicles/{id}/expense — 單車支出

Request body（`VehicleExpenseRequest`）：`category`(必填，限 `維修支出`\|`美容支出`\|`代辦支出`\|`拍場支出`\|`其他支出`)、其餘欄位同上。

### POST /api/vehicles/{id}/deposit — 訂金收入

Request body（`DepositVehicleRequest`）：欄位同 purchase-payment，`category` 固定為 `訂金收入`。

### POST /api/vehicles/{id}/final-payment — 尾款收入

見上方 Vehicle Workflow。

### POST /api/vehicles/{id}/refund — 退款（支出）

Request body（`RefundVehicleRequest`）：欄位同 purchase-payment，`category` 固定為 `退款`。

以上快捷 API 皆回傳 `MoneyEntryResource`。

### GET /api/vehicles/{id}/money-entries

Query 參數同 `IndexMoneyEntryRequest`（見下方），並強制以 `vehicle_id = {id}` 篩選。回傳分頁後的 `MoneyEntryResource` 陣列。

---

## 6. Print（列印）

### GET /api/vehicles/{id}/print/intake — 車輛建檔資料列印

回傳：

```json
{ "printed_at": "2026-07-05T10:00:00.000000Z", "vehicle": { /* VehicleResource */ } }
```

### GET /api/vehicles/{id}/print/closing — 成交結案收支明細列印

僅限已售出（`sold`）車輛。回傳：

```json
{
  "printed_at": "2026-07-05T10:00:00.000000Z",
  "vehicle": { /* VehicleResource */ },
  "summary": { "income": 0, "expense": 0, "gross_profit": 0 },
  "money_entries": [ /* MoneyEntryResource[] */ ]
}
```

---

## 7. Money Entries

`category` 合法值（依方向分類，`direction` 與 `category` 不一致會被拒絕）：

- 收入：`訂金收入`、`尾款收入`、`其他單車收入`、`一般收入`
- 支出：`購車付款`、`維修支出`、`美容支出`、`代辦支出`、`拍場支出`、`退款`、`租金`、`水電`、`廣告`、`平台費`、`薪資 / 佣金`、`稅金支出`、`其他支出`

其中與車輛相關的分類（`訂金收入`、`尾款收入`、`其他單車收入`、`購車付款`、`維修支出`、`美容支出`、`代辦支出`、`拍場支出`、`退款`）必須帶 `vehicle_id`；一般營運分類（`一般收入`、`租金`、`水電`、`廣告`、`平台費`、`薪資 / 佣金`、`稅金支出`）不得帶 `vehicle_id`，以確保一般收支不影響單車毛利。稅金支出僅作為一般支出的其中一個分類，不做任何稅務計算。

已售出（`sold`）或已取消（`cancelled`）的車輛不得再新增/修改綁定其上的收支。

### GET /api/money-entries

Query 參數（`IndexMoneyEntryRequest`）：`vehicle_id`、`cash_account_id`、`direction`(`income`\|`expense`)、`category`、`date_from`、`date_to`、`search`、`per_page`(1~100)、`page`，皆為選填。

回傳：分頁後的 `MoneyEntryResource` 陣列。

### POST /api/money-entries

Request body（`StoreMoneyEntryRequest`）：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| entry_date | date | 是 | |
| direction | string | 是 | `income`\|`expense` |
| category | string | 是 | 見上方合法值 |
| amount | int | 是（≥1） | |
| cash_account_id | int | 是 | |
| vehicle_id | int | 否 | 依 category 規則決定是否可填 |
| counterparty_name | string | 否 | |
| description | string | 否 | |
| idempotency_key | string | 是（max:100） | |

回傳：`MoneyEntryResource`。

### GET /api/money-entries/{id}

回傳：`MoneyEntryResource`。

### PATCH /api/money-entries/{id}

Request body（`UpdateMoneyEntryRequest`）：同 Store，但不含 `idempotency_key`。

回傳：`MoneyEntryResource`。

### DELETE /api/money-entries/{id}

回傳：`{ "message": "收支紀錄已刪除" }`

### MoneyEntryResource

```json
{
  "id": 1,
  "entry_date": "2026-07-05",
  "direction": "income",
  "category": "訂金收入",
  "amount": 50000,
  "vehicle_id": 1,
  "cash_account_id": 1,
  "counterparty_name": "王小明",
  "description": null,
  "vehicle": { "id": 1, "stock_no": "V202601001", "brand": "Toyota", "model": "Corolla" },
  "cash_account": { "id": 1, "name": "現金", "type": "cash" },
  "created_at": "2026-07-05T10:00:00.000000Z",
  "updated_at": "2026-07-05T10:00:00.000000Z"
}
```

---

## 8. Cash Accounts（資金帳戶）

帳戶類型：`cash`（現金）、`bank`（銀行）、`other`（其他）。

帳戶目前餘額不儲存在資料庫，即時計算：`目前餘額 = 期初餘額 + 收入總額 - 支出總額`。

### GET /api/cash-accounts

需登入。回傳所有帳戶的 `CashAccountResource`（不含即時餘額，餘額請用 `/balances`）。

### GET /api/cash-accounts/balances

需登入。回傳含即時餘額的帳戶清單：

```json
{
  "data": [
    {
      "id": 1,
      "name": "現金",
      "type": "cash",
      "opening_balance": 0,
      "is_active": true,
      "current_balance": 100000
    }
  ]
}
```

### GET /api/cash-accounts/{id}

需登入。回傳：`CashAccountResource`。

### POST /api/cash-accounts — 僅限管理員

Request body（`StoreCashAccountRequest`）：`name`(必填)、`type`(必填，`cash`\|`bank`\|`other`)、`opening_balance`(必填,int,≥0)、`is_active`(選填,bool)。

回傳：`CashAccountResource`。

### PATCH /api/cash-accounts/{id} — 僅限管理員

Request body（`UpdateCashAccountRequest`）：`name`(必填)、`type`(必填)、`opening_balance`(必填,≥0)。**不可**透過此端點修改 `is_active`（帶入該欄位會回傳 422，需改用下方 status 端點），避免舊版前端誤用造成靜默失敗。

### PATCH /api/cash-accounts/{id}/status — 僅限管理員

Request body（`UpdateCashAccountStatusRequest`）：`is_active`(必填,bool)。停用後的帳戶不可再被選為新增收支的 `cash_account_id`。

### DELETE /api/cash-accounts/{id} — 僅限管理員

回傳：`{ "message": "資金帳戶已刪除" }`

### CashAccountResource

```json
{ "id": 1, "name": "現金", "type": "cash", "opening_balance": 0, "is_active": true }
```

---

## 9. Users（員工/帳號管理，皆僅限管理員）

v1.1 起 `users.role` 為正式權限來源，固定三種角色：`admin`／`manager`／`sales`。`is_admin` 欄位在過渡期間持續與 `role` 同步（`role=admin` ⟷ `is_admin=true`，其餘 ⟷ `is_admin=false`），但不再作為權限判斷依據；是否仍有啟用中管理員一律以 `role=admin AND is_active=true` 判斷。

### GET /api/users

回傳所有使用者的 `UserResource`（含 `role`、`phone`、`job_title`、`hire_date`、`notes`）。

### POST /api/users

Request body（`StoreUserRequest`）：`name`(必填)、`email`(必填,唯一)、`password`(必填,min:8)、`role`(必填,in:admin/manager/sales)、`is_active`(選填,bool)、`phone`(選填)、`job_title`(選填)、`hire_date`(選填,date)、`notes`(選填)。

回傳：`UserResource`。

### GET /api/users/{id}

回傳：`UserResource`。

### PATCH /api/users/{id}

Request body（`UpdateUserRequest`）：`name`(必填)、`email`(必填,唯一，忽略自身)、`phone`／`job_title`／`hire_date`／`notes`(皆選填)。**不可**在此修改 `is_active`／`is_admin`／`role`（帶入會回傳 422），須改用下方 status／role 端點。

### PATCH /api/users/{id}/status

Request body（`UpdateUserStatusRequest`）：`is_active`(必填,bool)。停用後的帳號無法登入（見 `EnsureUserIsActive`，登入中的 session 也會被強制登出）。最後一位啟用中管理員（`role=admin AND is_active=true`）不可被停用。

### PATCH /api/users/{id}/role

Request body（`UpdateUserRoleRequest`）：`role`(必填,in:admin/manager/sales)。同步更新 `is_admin`。管理員不可將自己的角色改為非 `admin`；最後一位啟用中管理員不可被降級。

### POST /api/users/{id}/reset-password

Request body（`ResetUserPasswordRequest`）：`password`(必填,min:8)。

回傳：`{ "message": "密碼已重設" }`

### DELETE /api/users/{id}

回傳：`{ "message": "使用者已刪除" }`

---

## 10. 冪等性（idempotency_key）

`purchase-payment`、`expense`、`deposit`、`final-payment`、`refund`、`reserve`、`POST /money-entries` 等會建立金流紀錄的端點都要求前端帶入 `idempotency_key`（前端可用 UUID）。同一個 key 重複送出、且內容相同時會回傳原本已建立的紀錄，不會重複入帳；若同一個 key 被用在不同內容的請求上，會回傳 422 錯誤。目的是避免網路重試造成重複收支。
