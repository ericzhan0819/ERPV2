# API 文件 — 中古車行內部營運系統（1.0 + v1.1 + v1.2 + v1.3 第 8 部分）

Base URL：`http://localhost:8000`（依 `.env` `APP_URL` 而定）

所有 API 皆為 JSON。認證採用 Laravel Sanctum SPA（cookie-based session），前端需：

1. 先呼叫 `GET /sanctum/csrf-cookie` 取得 CSRF cookie（Sanctum 內建路由）。
2. 之後所有 request 都要帶 `X-XSRF-TOKEN`（axios 預設會自動處理，需搭配 `withCredentials: true`）。
3. 除了 `POST /api/login`、`POST /api/logout`、`GET /api/public/*`（v1.2 官網公開唯讀 API）外，其餘 `/api/*` 都需要登入（`auth:sanctum` + `active` middleware）。
4. 標註「僅限管理員」的路由另外掛 `admin` middleware，非管理員呼叫會回傳 `403`。
5. v1.1 起部分路由改掛 `role:admin,manager` 或 `role:admin,manager,sales` middleware，依 `users.role` 判斷；不符合角色會回傳 `403 {"message": "權限不足"}`。

金額欄位一律為整數（新台幣元，非分），對應資料庫 `decimal`/`integer` 欄位，前端不得自行用 float 計算正式金額。

業務日期與月份邊界一律採 `Asia/Taipei`。API datetime 使用帶 `+08:00` offset 的 ISO 8601；帶其他 offset 的輸入會先轉為台北時間再保存。純日期欄位（例如 `entry_date`）使用 `YYYY-MM-DD`，不做時區換算。

### v1.1 角色與敏感欄位遮蔽

`sales` 角色呼叫下列端點時，回傳 JSON 會直接省略（而非回傳 `0`/`null`/空字串）以下欄位：

- `VehicleResource`：`purchase_price`（收購價）
- `MoneyEntryResource`：`cash_account_id`、`cash_account` 一律省略；`amount` 只在該筆是自己建立的申請，或分類屬於「訂金收入／尾款收入／退款」等銷售收款安全分類時才會出現，其餘（例如他人上報的成本）完全不會出現在 `sales` 的列表中
- `CashAccountResource`：完整版（含 `opening_balance`）僅 `admin`/`manager` 可讀（`GET /api/cash-accounts`、`GET /api/cash-accounts/{id}`、`GET /api/cash-accounts/balances`），`sales` 只能呼叫不含餘額欄位的 `GET /api/cash-accounts/options`

判斷依據為 `User::canViewFinancials()`（`role` 為 `admin` 或 `manager` 時為 `true`）。

`VehicleResource` 的 `asking_price`（開價）、`floor_price`（底價）、`sold_price`（成交價）**不在**上述遮蔽清單內：`sales` 可以看到這三個欄位，因為這是業務跟客人談價錢、追蹤收款的依據。判斷依據為 `User::canViewSalesPricing()`（`role` 為 `admin`、`manager` 或 `sales` 時為 `true`）。`sales` 仍看不到 `purchase_price`（收購價）、購車付款、完整整備成本、單車毛利、資金帳戶餘額、完整收支金額、他人上報的成本明細。

`GET /api/vehicles/{vehicle}` 對 `sales` 回傳的 payload 不含管理用 `summary`（單車收入/支出合計、毛利），改為 `sales_collection_summary`（銷售收款安全摘要，只計入訂金/尾款收入與退款，見下方車輛模組章節），`money_entries` 也只包含銷售收款安全紀錄與自己上報的車輛支出申請，不含購車付款或他人成本明細。未知角色（`role` 不在 `admin`/`manager`/`sales` 內）一律 fail-closed：不回傳 `summary`、`sales_collection_summary`，`money_entries` 為空陣列。

v1.3 Phase 1 起，`source_type=salary_settlement` 的薪資支出只對 `admin` 可見。`manager`／`sales` 不會在一般 Money Entry 列表取得這些紀錄，也不能用 ID 枚舉單筆；Resource 另有防禦性遮蔽，不向非 admin 輸出金額、資金帳戶、員工姓名、說明或審核欄位。

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
| purchase_agent_id | int | 是 | 實際收車人；須為 active user。是否參與獎金於薪資結算階段依 Salary Profile 判斷 |
| asking_price | int | 否 | ≥0 |
| floor_price | int | 否 | ≥0 |
| sales_note | string | 否 | |
| notes | string | 否 | |
| idempotency_key | string | 是（max:100，勾選同步購車付款時為 max:84） | 防止重複送出造成重複建車；勾選同步付款時上限收緊為 84，因為會衍生出 `{idempotency_key}:initial-payment` 寫入 `money_entries.idempotency_key`（欄位長度 100），未同步付款則不受影響，維持 max:100 |
| initial_purchase_payment | object | 否 | 勾選同步建立購車付款時才帶入，見下表 |
| seller_customer_id | int | 否 | 關聯 `customers`（見「9. Customers」），須存在 |
| buyer_customer_id | int | 否 | 關聯 `customers`，須存在 |
| displacement | string | 否 | 排氣量，自由文字，不做單位/數值檢核 |
| transmission | string | 否 | 變速方式，自由文字 |
| fuel_type | string | 否 | 燃料種類，自由文字 |
| parking_location | string | 否 | 停放位置 |
| has_registration_document | bool | 否 | 證件是否齊備，預設 `false` |
| has_spare_key | bool | 否 | 是否有備鑰，預設 `false` |
| is_transfer_completed | bool | 否 | 過戶是否完成，預設 `false` |
| is_inspection_completed | bool | 否 | 驗車是否完成，預設 `false` |
| is_preparation_completed | bool | 否（僅 PATCH） | 整備完成狀態；`list` 端點會自動設為 `true`。車輛已上架（`listed` 以後）時不可透過 `PATCH /api/vehicles/{id}` 回改為 `false`，會回傳 `422` |
| lien_note | string | 否 | 貸款備註（是否有貸款、貸款狀況等以文字描述，非結構化欄位） |
| condition_note | string | 否 | 車況備註 |

`initial_purchase_payment`（勾選同步購車付款時）：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| amount | int | 是（≥1） | 付款金額 |
| cash_account_id | int | 是 | 付款帳戶，須存在且啟用 |
| entry_date | date | 否 | |
| description | string | 否 | |

填寫 `initial_purchase_payment` 時，Vehicle 與這筆購車付款 `MoneyEntry`（`direction=expense`、`category=購車付款`、`source_type=vehicle_workflow`）會在同一個 DB transaction 建立；付款帳戶停用或金額不合法時整個交易回滾，不會留下車輛。老闆身兼會計：只有 `admin` 建車同步付款才直接 `approval_status=approved`，`manager` 建車（`sales` 不可建車，見下方）同步付款一律 `pending`，待 `admin` 核准後才計入正式成本。此 MoneyEntry 的 `idempotency_key` 由 `{idempotency_key}:initial-payment` 衍生，重試沿用同一把 `idempotency_key` 會 replay 既有車輛，不會重複建立。`sales` 角色無法呼叫本端點（見 VehiclePolicy::create），因此也不可能觸發建車同步購車付款。

重試比對的是「建車當下正規化後的欄位快照」（`vehicles.idempotency_payload`），包含正式 `purchase_agent_id`，而不是車輛目前的即時狀態：車輛建立後可能已透過一般編輯、上架等流程被合法修改過，若拿目前狀態比對，同一把 key 的完全相同重試會被誤判成「不同建車內容」而 422。同一把 key 改傳不同收車人會回傳 `422 idempotency_key`。

回傳：`VehicleResource`。

### GET /api/vehicles/{id}

`admin`/`manager`（`canViewFinancials()`）回傳：

```json
{
  "vehicle": { /* VehicleResource */ },
  "summary": { "income_total": 0, "expense_total": 0, "gross_profit": 0 },
  "money_entries": [ /* 該車所有收支，含 amount / cash_account / approval_status */ ]
}
```

`summary` 由後端計算，只計入 `approval_status=approved` 的紀錄：單車收入合計、單車支出合計、單車毛利（收入 - 支出）。

`sales` 回傳（無管理用 `summary`、無購車付款與他人成本明細）：

```json
{
  "vehicle": { /* VehicleResource，含 asking_price/floor_price/sold_price，不含 purchase_price */ },
  "sales_collection_summary": {
    "sold_price": 480000,
    "approved_collection_total": 100000,
    "pending_collection_total": 380000,
    "approved_refund_total": 0,
    "pending_refund_total": 0,
    "net_recorded_collection_total": 480000,
    "remaining_amount": 0
  },
  "money_entries": [ /* 訂金/尾款/退款（不論由誰建立）+ 自己上報的車輛支出申請，不含 cash_account */ ]
}
```

`sales_collection_summary` 只計入訂金收入、尾款收入、退款三種分類；`approved_*`／`pending_*` 分開列出，`rejected` 不計入任何總額。`remaining_amount = sold_price - net_recorded_collection_total`。

未知角色（`role` 不在 `admin`/`manager`/`sales` 內）回傳 `{ "vehicle": { ... }, "money_entries": [] }`，不含 `summary` 或 `sales_collection_summary`。

### GET /api/vehicles/commission-agent-options

供車輛建檔與 admin／manager 代登銷售流程選人。僅 `admin`／`manager` 可讀，`sales` 回傳 `403`。端點只回傳 active user 的 `id`、`name`、`role`，不依 Salary Profile 篩選，也不回傳或洩漏 `commission_enabled` 等薪資設定衍生資訊。

### GET /api/vehicles/commission-attribution-pending — 僅限管理員

回傳所有 `status=sold` 且缺少 `purchase_agent_id` 或 `sales_agent_id` 的歷史車輛，供「待補獎金歸屬」頁面使用。`manager`／`sales` 一律 `403`。

### PATCH /api/vehicles/{id}/commission-attribution — 僅限管理員

人工補登或更正車輛正式獎金歸屬；至少傳入一個欄位：

```json
{
  "purchase_agent_id": 3,
  "sales_agent_id": 5
}
```

兩個人員都必須是 active user；獎金資格由後續薪資結算依 Salary Profile 判斷，不影響實際經手人歸屬。若車輛已被 `confirmed`／`paid` salary period 的 settlement item 引用，回傳 `422` 並拒絕修改；成功異動會由 Vehicle Audit Log 記錄。`manager`／`sales` 一律 `403`。

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
  "purchase_agent_id": 2,
  "purchase_agent": { "id": 2, "name": "收車人" },
  "asking_price": 380000,
  "floor_price": 350000,
  "listing_date": "2026-01-05",
  "sales_note": null,
  "reserved_at": null,
  "sold_at": null,
  "sold_price": null,
  "sales_agent_id": 3,
  "sales_agent": { "id": 3, "name": "賣車人" },
  "buyer_name": null,
  "buyer_phone": null,
  "seller_customer_id": null,
  "buyer_customer_id": null,
  "displacement": "1798",
  "transmission": "automatic",
  "fuel_type": "gasoline",
  "parking_location": "A區-03",
  "has_registration_document": false,
  "has_spare_key": false,
  "is_transfer_completed": false,
  "is_inspection_completed": false,
  "is_preparation_completed": false,
  "lien_note": null,
  "condition_note": null,
  "notes": null,
  "created_at": "2026-01-01T08:00:00+08:00",
  "updated_at": "2026-01-05T08:00:00+08:00"
}
```

`purchase_price` 於 `sales` 角色讀取時完全不會出現在 JSON 中；`asking_price`、`floor_price`、`sold_price` 則正常出現（見上方「v1.1 角色與敏感欄位遮蔽」）。

`purchase_agent_id`／`sales_agent_id` 是 v1.3 Phase 1 新增的正式獎金歸屬欄位，歷史資料保持 `null`，不從 `created_by`／`updated_by` 推定。內部 `VehicleResource` 回傳 ID，關聯已載入時另回傳使用者 ID／姓名；公開 Vehicle Resource 不回傳這些內部欄位。

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

`listed → reserved`。同時建立一筆 `訂金收入` money entry（`source_type=vehicle_workflow`）。老闆身兼會計：只有 `admin` 收訂金才直接 `approval_status=approved`，`manager`／`sales` 收的訂金一律 `pending`，待 `admin` 核准後才計入正式餘額與單車收入。Request body（`ReserveVehicleRequest`）：

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
| sales_agent_id | int | admin／manager 必填；sales 禁止傳入 | 實際賣車人；須為 active user。sales 操作時後端固定保存目前登入者，即使尚無 Salary Profile 或 `commission_enabled=false` 仍可完成保留；獎金資格於結算階段判斷 |

回傳：`VehicleResource`。

reservation 的冪等比較包含正式 `sales_agent_id`；同一把 key 改傳不同賣車人會回傳 `422 idempotency_key`，不得 silent replay。

### POST /api/vehicles/{id}/final-payment — 收尾款

建立一筆 `尾款收入` money entry（`source_type=vehicle_workflow`），不改變車輛狀態（結案仍須另外呼叫 close-sale）。老闆身兼會計：只有 `admin` 收尾款才直接 `approval_status=approved`，`manager`／`sales` 收的尾款一律 `pending`，待 `admin` 核准後才計入正式餘額與單車收入。Request body（`FinalPaymentVehicleRequest`）：

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

`reserved → sold`。驗證規則：已有成交價、買方姓名與正式 `sales_agent_id`；且 `approval_status=approved` 的收款總額（訂金 + 尾款）需達成交價，否則回傳 422。`pending` 收款（尚未由 `admin` 核准）不算正式已收，不可用來關帳——sales/manager 收的訂金、尾款必須先由 `admin` 核准入帳，成交結案才可能成功。

Request body（`CloseSaleVehicleRequest`）：

| 欄位 | 型別 | 必填 |
|---|---|---|
| sold_at | date | 否，預設台北現在時間；帶 offset 時先轉為 `Asia/Taipei` |

回傳：`VehicleResource`（`status = sold`）。

---

## 5. Vehicle 快捷收支

以下皆為登入即可呼叫（`purchase-payment` 僅限 `admin`/`manager`，其餘含 `sales`，見 `VehiclePolicy`），並各自建立一筆對應的 `money_entries` 紀錄（`source_type=vehicle_shortcut`），`vehicle_id` 綁定當前車輛。老闆身兼會計：只有 `admin` 建立才直接 `approval_status=approved`，`manager`／`sales` 建立一律 `pending`，待 `admin` 核准後才計入正式餘額與成本：

### POST /api/vehicles/{id}/purchase-payment — 購車付款（支出，僅限 admin/manager）

Request body（`PurchasePaymentVehicleRequest`）：`amount`(必填,int,≥1)、`cash_account_id`(必填)、`entry_date`(選填)、`counterparty_name`(選填)、`description`(選填)、`idempotency_key`(必填,max:100)。

### POST /api/vehicles/{id}/expense — 單車支出（整備支出上報，admin/manager/sales）

Request body（`VehicleExpenseRequest`）：`category`(必填，限 `維修支出`\|`美容支出`\|`代辦支出`\|`拍場支出`\|`其他支出`)、其餘欄位同上。前端建議稱為「上報整備支出」，因為 `manager`/`sales` 送出後為待審核狀態。

### POST /api/vehicles/{id}/deposit — 訂金收入

Request body（`DepositVehicleRequest`）：欄位同 purchase-payment，`category` 固定為 `訂金收入`。

### POST /api/vehicles/{id}/final-payment — 尾款收入

見上方 Vehicle Workflow。

### POST /api/vehicles/{id}/refund — 退款（支出）

Request body（`RefundVehicleRequest`）：欄位同 purchase-payment，`category` 固定為 `退款`。

以上快捷 API 皆回傳 `MoneyEntryResource`。`sales` 建立時可在回應中看到自己這筆的 `amount` 與 `approval_status`（因為訂金/尾款/退款屬於銷售收款安全分類，整備支出則是自己建立的申請），但看不到 `cash_account_id`/`cash_account`。

### GET /api/vehicles/{id}/money-entries

Query 參數同 `IndexMoneyEntryRequest`（見下方），並強制以 `vehicle_id = {id}` 篩選。回傳分頁後的 `MoneyEntryResource` 陣列。

---

## 6. Print（列印）

### GET /api/vehicles/{id}/print/intake — 車輛建檔資料列印

回傳：

```json
{ "printed_at": "2026-07-05T18:00:00+08:00", "vehicle": { /* VehicleResource */ } }
```

### GET /api/vehicles/{id}/print/closing — 成交結案收支明細列印

僅限已售出（`sold`）車輛。回傳：

```json
{
  "printed_at": "2026-07-05T18:00:00+08:00",
  "vehicle": { /* VehicleResource */ },
  "summary": { "income_total": 0, "expense_total": 0, "gross_profit": 0 },
  "money_entries": [ /* MoneyEntryResource[]，只含 approved 紀錄 */ ]
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

回傳：分頁後的 `MoneyEntryResource` 陣列。`admin`/`manager` 看到完整列表；`sales` 只看到自己建立的申請，或訂金收入／尾款收入／退款等銷售收款安全分類（不論由誰建立），不會看到全公司所有成本紀錄的分類、對象、描述。`GET /api/vehicles/{id}/money-entries` 套用同一套範圍限制。

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

`update`/`destroy` 除了上述端點層級的 `role:admin,manager,sales` middleware 外，另外掛 `MoneyEntryPolicy`（`can:update,money_entry` / `can:delete,money_entry`）：`manager`/`sales` 只能異動自己送出、且仍為 `pending` 的一般收支，不可修改或刪除其他人送出的、或已核准/已駁回的收支。

`source_type=salary_settlement` 是 v1.3 專用來源：DB 與應用層共同限制只能由 admin 以 `expense`、`薪資 / 佣金`、`vehicle_id=null`、`approval_status=approved` 建立；不得進入一般 approve/reject，也不得透過一般 CRUD 修改或刪除。正式發薪 API 將於後續 PLAN 階段實作。

### 收支審核（老闆身兼會計）— 核准/駁回僅限管理員

只要建立者不是 `admin`，任何會影響正式資金、成本、毛利的 MoneyEntry 都是 `pending`：涵蓋一般收支 `manual`、車輛快捷收支 `vehicle_shortcut`（購車付款、單車支出、訂金、退款）、車輛流程收支 `vehicle_workflow`（建車同步購車付款、reserve 訂金、final-payment 尾款）。只有 `admin` 建立的 MoneyEntry 才直接 `approved`。

只有 `admin` 可呼叫下列核准/駁回端點，`manager`（即使可看完整營運金額）與 `sales` 呼叫一律 `403`。approve/reject 皆為一次性、不可逆：只有 `pending` 狀態可以被核准或駁回，已核准/已駁回不可再變更；`source_type=legacy_unknown`（來源未確認的既有資料）不可核准/駁回。

### PATCH /api/money-entries/{id}/approve — 僅限管理員

不需 request body。將 `approval_status` 改為 `approved`，並記錄 `approved_by`（核准者 id）與 `approved_at`（核准時間）。

錯誤：`422` — `source_type=legacy_unknown`，或目前狀態不是 `pending`。

回傳：`MoneyEntryResource`。

### PATCH /api/money-entries/{id}/reject — 僅限管理員

不需 request body（無「駁回原因」欄位）。將 `approval_status` 改為 `rejected`，`approved_by`／`approved_at` 同樣會記錄為駁回當下的操作者與時間（欄位沿用同一組，approve 與 reject 共用）。

錯誤：`422` — `source_type=legacy_unknown`，或目前狀態不是 `pending`。

回傳：`MoneyEntryResource`。

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
  "approval_status": "approved",
  "approved_by": 1,
  "approved_at": "2026-07-05T18:05:00+08:00",
  "vehicle": { "id": 1, "stock_no": "V202601001", "brand": "Toyota", "model": "Corolla" },
  "cash_account": { "id": 1, "name": "現金", "type": "cash" },
  "created_at": "2026-07-05T18:00:00+08:00",
  "updated_at": "2026-07-05T18:00:00+08:00"
}
```

`cash_account_id`、`cash_account` 於 `sales` 角色讀取時完全不會出現在 JSON 中。`amount` 對 `sales` 只在該筆是自己建立的申請，或分類屬於「訂金收入／尾款收入／退款」時才出現，其餘一律不出現（而非回傳 `0`）。`approval_status`／`approved_by`／`approved_at` 不遮蔽，所有角色皆可見。`pending`／`rejected` 的收支不計入任何正式金額彙總（帳戶餘額、Dashboard、單車毛利、列印明細）。

---

## 8. Cash Accounts（資金帳戶）

帳戶類型：`cash`（現金）、`bank`（銀行）、`other`（其他）。

帳戶目前餘額不儲存在資料庫，即時計算：`目前餘額 = 期初餘額 + approved 收入總額 - approved 支出總額`。

### GET /api/cash-accounts/options — admin / manager / sales

需登入，不限角色。給表單（收訂金、收尾款、支出登記等）選擇帳戶用，不含金額欄位：

```json
{ "data": [ { "id": 1, "name": "現金", "type": "cash", "is_active": true } ] }
```

### GET /api/cash-accounts — 僅限 admin / manager

回傳所有帳戶的 `CashAccountResource`（不含即時餘額，餘額請用 `/balances`）。`sales` 呼叫會回傳 `403`，請改用 `/options`。

### GET /api/cash-accounts/balances — 僅限 admin / manager

回傳含即時餘額的帳戶清單：

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

### GET /api/cash-accounts/{id} — 僅限 admin / manager

回傳：`CashAccountResource`。

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

## 9. Customers（客戶）

`customer_type` 合法值：`buyer`（買方）、`seller`（賣方）、`both`（買賣皆是）、`other`（其他）。

角色權限：`admin`／`manager`／`sales` 皆可新增、編輯、查詢；僅 `admin` 可刪除。

### GET /api/customers

Query 參數（`IndexCustomerRequest`）：

| 欄位 | 型別 | 說明 |
|---|---|---|
| search | string | 對 `name`、`phone`、`line_id` 模糊搜尋 |
| customer_type | string | `buyer`\|`seller`\|`both`\|`other` |
| per_page | int | 1~100，預設 20 |
| page | int | |

回傳：分頁後的 `CustomerResource` 陣列。

### POST /api/customers

Request body（`StoreCustomerRequest`）：

| 欄位 | 型別 | 必填 | 說明 |
|---|---|---|---|
| name | string | 是 | max:255 |
| phone | string | 否 | |
| line_id | string | 否 | |
| customer_type | string | 是 | `buyer`\|`seller`\|`both`\|`other` |
| source | string | 否 | 客戶來源 |
| address | string | 否 | |
| notes | string | 否 | |

回傳：`CustomerResource`。

### GET /api/customers/{id}

回傳：

```json
{
  "customer": { /* CustomerResource */ },
  "vehicles_as_seller": [ { "id": 1, "stock_no": "V202601001", "status": "sold", "brand": "Toyota", "model": "Corolla", "sold_at": "2026-02-01T08:00:00+08:00", "sold_price": 400000 } ],
  "vehicles_as_buyer": [ /* 同上結構 */ ]
}
```

`sold_price` 於呼叫者 `canViewSalesPricing()`（`admin`／`manager`／`sales`）時會出現在 `vehicles_as_seller`/`vehicles_as_buyer` 陣列中；未知角色呼叫時該欄位完全不存在。

### PATCH /api/customers/{id}

Request body（`UpdateCustomerRequest`）：欄位與 Store 相同。

回傳：`CustomerResource`。

### DELETE /api/customers/{id} — 僅限管理員

若該客戶仍有關聯車輛（`vehicles.seller_customer_id` 或 `buyer_customer_id` 指向此客戶），回傳 `422`：`{"errors": {"customer": ["此客戶已有關聯車輛，不得刪除"]}}`。

回傳成功：`{ "message": "客戶已刪除" }`

### CustomerResource

```json
{
  "id": 1,
  "name": "王小明",
  "phone": "0900000000",
  "line_id": null,
  "customer_type": "buyer",
  "source": null,
  "address": null,
  "notes": null,
  "created_at": "2026-07-05T18:00:00+08:00",
  "updated_at": "2026-07-05T18:00:00+08:00"
}
```

無角色遮蔽（不含金額欄位）。

---

## 10. Users（員工/帳號管理，皆僅限管理員）

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

## 11. Audit Logs（稽核紀錄，僅限管理員）

稽核紀錄為 append-only，API 只提供查詢，不提供新增、修改或刪除端點。系統自動記錄：

- 登入、登出
- 車輛、收支、資金帳戶、客戶、員工帳號的新增／修改／刪除
- 操作者 ID 與姓名／Email／角色快照
- 異動前後值、IP、User-Agent、HTTP method 與 request path

`password`、`remember_token`、`idempotency_key`、`idempotency_payload` 不會寫入稽核內容。

### GET /api/audit-logs

Query 參數：

| 欄位 | 型別 | 說明 |
|---|---|---|
| actor_id | int | 操作者使用者 ID |
| action | string | `created`／`updated`／`deleted`／`login`／`logout` |
| subject_type | string | `user`／`vehicle`／`money_entry`／`cash_account`／`customer`／`salary_profile`／`commission_plan`／`authentication` |
| date_from | date | 起始日期 |
| date_to | date | 結束日期，不得早於 `date_from` |
| search | string | 操作者姓名／Email 或操作對象模糊搜尋 |
| per_page | int | 1~100，預設 30 |
| page | int | 頁碼 |

回傳：分頁後的 `AuditLogResource` 陣列。

### GET /api/audit-logs/{id}

回傳單筆 `AuditLogResource`。`manager`／`sales` 呼叫上述端點皆回傳 403。

### AuditLogResource

```json
{
  "id": 1,
  "actor_id": 2,
  "actor_name": "系統管理員",
  "actor_email": "admin@example.com",
  "actor_role": "admin",
  "action": "updated",
  "subject_type": "vehicle",
  "subject_id": 15,
  "subject_label": "V202607070001 Toyota Camry",
  "before_values": { "status": "preparing" },
  "after_values": { "status": "listed" },
  "ip_address": "127.0.0.1",
  "user_agent": "Mozilla/5.0 ...",
  "request_method": "POST",
  "request_path": "api/vehicles/15/list",
  "created_at": "2026-07-07T18:00:00+08:00"
}
```

若操作者帳號日後被刪除，`actor_id` 會變成 `null`，但姓名、Email 與角色快照仍會保留。

---

## 12. 冪等性（idempotency_key）

`purchase-payment`、`expense`、`deposit`、`final-payment`、`refund`、`reserve`、`POST /vehicles`（勾選同步購車付款時）、`POST /money-entries` 等會建立金流紀錄的端點都要求前端帶入 `idempotency_key`（前端可用 UUID）。同一個 key 重複送出、且內容相同時會回傳原本已建立的紀錄，不會重複入帳；若同一個 key 被用在不同內容的請求上，會回傳 422 錯誤。目的是避免網路重試造成重複收支。

車輛照片上傳（`POST /api/vehicles/{id}/photos`）也需要帶 `idempotency_key`：雖然不是金流端點，但網路逾時、瀏覽器重複送出或 proxy 重試若沒有防護，會重複建立照片並吃掉每台車最多 60 張的上限，且使用者無法確定哪些是第一次嘗試留下的紀錄。一次上傳會建立多張照片，無法讓多筆照片共用同一個 `idempotency_key`，因此改用獨立的批次記錄表（`vehicle_photo_upload_batches`）記錄這次請求的檔案內容快照（依上傳順序排列的 sha256/size/原始檔名）。同一把 key、內容相同的重試會直接回傳第一次建立的照片，不會重複建立；同一把 key 但內容不同會回傳 `422`；同一把 key 在第一次請求仍處理中時提早重試，也會回傳 `422`（請稍後再試），不會回傳不完整或假造的結果。詳見第 13 節。

---

## 13. Vehicle Photos（車輛照片，v1.2）

車輛照片為 v1.2 新增模組，資料表 `vehicle_photos`。上傳的圖片一律重新編碼為 `webp`（移除 EXIF / GPS 等拍攝資訊），並產生縮圖；同一台車最多 60 張、單次上傳最多 20 張、單張檔案最大 8MB，僅接受 `jpg`／`jpeg`／`png`／`webp`。

角色權限：

| 操作 | admin | manager | sales |
|---|---|---|---|
| 讀取（`GET .../photos`） | ✓ | ✓ | ✓ |
| 上傳／刪除／排序／設封面 | ✓ | ✓ | ✗（403） |

### GET /api/vehicles/{id}/photos

回傳該車目前所有照片（依 `sort_order` 排序），`VehiclePhotoResource` 陣列。

### POST /api/vehicles/{id}/photos — 僅限 admin/manager

`multipart/form-data`，欄位：

| 欄位 | 型別 | 說明 |
|---|---|---|
| idempotency_key | string | 必填，前端可用 UUID；同一把 key 帶相同檔案內容重試會回傳第一次的結果，不重複建立 |
| photos[] | file[] | 必填，1~20 個檔案，單檔最大 8MB，僅接受 jpg/jpeg/png/webp |

成功回傳新建立照片的 `VehiclePhotoResource` 陣列（`201`）。第一張上傳的照片自動成為封面。超過每台車 60 張上限、或超過單次 20 張上限時回傳 `422`。

`idempotency_key` 重試規則（見第 12 節冪等性）：

- 同一把 key、檔案內容（依上傳順序比對 sha256/size/原始檔名）與第一次相同：直接回傳第一次建立的照片，不會重複建立、不影響每台車 60 張上限。
- 同一把 key、檔案內容不同：回傳 `422`。
- 同一把 key，前一次請求仍在處理中（處理租約仍在有效期內，例如兩個請求幾乎同時抵達）：回傳 `422`，訊息提示稍後再試，不回傳不完整或假造的結果。
- 同一把 key，前一次請求的處理租約已過期（例如處理程序被中止、伺服器重啟，租約時長預設依單檔處理 TTL × 單次最多檔案數推算，至少 900 秒，可用 `VEHICLE_PHOTOS_UPLOAD_BATCH_PENDING_TTL_SECONDS` 調整）：視為前一次處理程序已放棄，自動續傳認領同一筆紀錄，只接著處理「上次還沒處理完的檔案」，不會重新處理已經真的建立過照片的部分，不需要人工介入資料庫；已放棄的那次處理如果其實還在跑，極端情況下可能對「還沒處理完的那幾個檔案」造成重複建立（詳見 `config/vehicle_photos.php` 的 `upload_batch_pending_ttl_seconds` 註解）。
- 若租約過期後遲遲沒有任何請求回來續傳（例如使用者直接關掉分頁放棄重試），已經真的建立好的部分照片會持續以正常、可見的照片留著。`vehicle-photos:sweep-stale-uploads` 排程指令（預設每日執行一次）會清理超過永久放棄門檻（預設 24 小時，可用 `VEHICLE_PHOTOS_UPLOAD_BATCH_ABANDON_SWEEP_SECONDS` 調整）、仍未完成的批次：把殘留照片標記刪除、移除該筆上傳紀錄，讓車輛照片清單恢復成「這次失敗的上傳完全沒發生過」的一致狀態（詳見 `config/vehicle_photos.php` 的 `upload_batch_abandon_sweep_seconds` 註解）。

### PATCH /api/vehicles/{id}/photos/reorder — 僅限 admin/manager

```json
{ "photo_ids": [12, 9, 7] }
```

`photo_ids` 必須剛好等於此車輛目前所有照片 id（不可缺漏、重複，或包含其他車輛的照片），否則回傳 `422`。成功回傳依新順序排列的 `VehiclePhotoResource` 陣列。

### PATCH /api/vehicles/{id}/photos/{photoId}/cover — 僅限 admin/manager

將指定照片設為封面，其餘照片自動取消封面。回傳更新後的單筆 `VehiclePhotoResource`。指定的 `photoId` 不屬於該車輛時回傳 `422`。

### DELETE /api/vehicles/{id}/photos/{photoId} — 僅限 admin/manager

刪除單張照片（`{"message": "照片已刪除"}`）。若刪除的是封面照，自動改指定 `sort_order` 最小的下一張照片為封面；若刪除後已無任何照片，車輛沒有封面。

### VehiclePhotoResource

```json
{
  "id": 1,
  "vehicle_id": 15,
  "url": "http://localhost:8000/storage/vehicles/15/xxxx.webp",
  "thumbnail_url": "http://localhost:8000/storage/vehicles/15/xxxx_thumb.webp",
  "original_filename": "IMG_1234.jpg",
  "mime_type": "image/jpeg",
  "size": 3456789,
  "width": 4032,
  "height": 3024,
  "sort_order": 0,
  "is_cover": true,
  "uploaded_by": 2,
  "created_at": "2026-07-09T18:00:00+08:00",
  "updated_at": "2026-07-09T18:00:00+08:00"
}
```

---

## 14. Public Vehicles（官網公開唯讀 API，v1.2）

`GET /api/public/*` 不需登入，供未來官網 MVP 讀取已上架車輛資料。整組獨立於 `auth:sanctum` 群組之外，並掛 `throttle:60,1`（每 IP 每分鐘 60 次，超過回 `429`），避免匿名使用者以大 `per_page` 或高頻請求放大 DB 讀取與序列化成本。

只回傳 `status=listed` 的車輛；`preparing`／`reserved`／`sold`／`cancelled` 的車輛一律視為不存在（`GET /api/public/vehicles/{id}` 回傳 `404`，不區分「車輛不存在」與「車輛存在但非上架中」，避免洩漏車輛生命週期狀態）。

**允許回傳欄位**（`PublicVehicleResource` / `PublicVehicleListResource` 白名單）：`id`、`stock_no`、`brand`、`model`、`year`、`mileage_km`、`color`、`fuel_type`、`transmission`、`displacement`、`asking_price`、`cover_photo`、`photos`（僅詳情頁）、`listing_date`、`created_at`。

**禁止回傳欄位**：`purchase_price`（收購價）、`floor_price`（底價）、`sold_price`（成交價）、任何買方／賣方／客戶個資、`money_entries`／成本／毛利、`cash_account`、內部備註（證件／備鑰／過戶／驗車檢核、貸款或車況備註）、`approval_status`、`idempotency_key`、`uploaded_by`、`original_filename`、`mime_type`。公開 API 使用獨立的 `PublicVehicleResource`／`PublicVehicleListResource`／`PublicVehiclePhotoResource`，不共用內部 `VehicleResource`／`VehiclePhotoResource`，避免未來內部欄位新增時意外外洩。

### GET /api/public/vehicles

Query 參數：

| 欄位 | 型別 | 說明 |
|---|---|---|
| per_page | int | 1~100，預設 20 |
| page | int | 頁碼 |

依 `listing_date` 由新到舊排序。列表**只回傳封面照**（`cover_photo`），不回傳每台車完整相簿，避免單一請求換取大量照片序列化。回傳分頁後的 `PublicVehicleListResource` 陣列。

### GET /api/public/vehicles/{id}

回傳單筆 `PublicVehicleResource`，包含完整 `photos` 陣列（依 `sort_order` 排序）。找不到或非上架狀態一律回傳：

```json
{ "message": "Vehicle not found" }
```

### PublicVehicleResource

```json
{
  "id": 15,
  "stock_no": "V202607070001",
  "brand": "Toyota",
  "model": "Camry",
  "year": 2020,
  "mileage_km": 35000,
  "color": "白色",
  "fuel_type": "gasoline",
  "transmission": "automatic",
  "displacement": 2000,
  "asking_price": 680000,
  "cover_photo": { "id": 1, "url": "...", "thumbnail_url": "...", "is_cover": true, "sort_order": 0, "width": 4032, "height": 3024 },
  "photos": [ { "id": 1, "url": "...", "thumbnail_url": "...", "is_cover": true, "sort_order": 0, "width": 4032, "height": 3024 } ],
  "listing_date": "2026-07-09",
  "created_at": "2026-07-09T18:00:00+08:00"
}
```

`PublicVehicleListResource` 欄位相同，但不含 `photos`（只有 `cover_photo`）。

---

## 15. Salary Profiles 與 Commission Plans（v1.3，僅限管理員）

本節端點皆位於 `auth:sanctum` + `active` + `role:admin` 下，並另由 Policy 採 admin 白名單授權。`manager`、`sales` 與未知角色一律回傳 `403`，無法透過列表或 ID 枚舉取得薪資設定與獎金方案。

### GET /api/salary-profiles

回傳所有已建立的員工薪資設定，依 `user_id` 排序。金額皆為非負整數新台幣；回應只使用 `SalaryProfileResource` 白名單，不含任何結算 snapshot、實發薪資或內部計算結果。

### PUT /api/salary-profiles/{user}

新增或完整更新指定使用者目前的薪資設定。成功固定回傳 `200`。停用中的使用者只能保存 `is_active=false` 的非啟用設定，不可建立／啟用 active salary profile。

Request body：

```json
{
  "base_salary": 35000,
  "fixed_allowance": 1200,
  "labor_insurance_deduction": 800,
  "health_insurance_deduction": 600,
  "commission_enabled": true,
  "is_active": true
}
```

所有欄位必填；四個金額欄位必須是大於等於 0 的整數。`user_id`、結算 totals、歷史 snapshot 等系統欄位禁止由前端寫入。修改目前設定不會回改既有 confirmed／paid settlement snapshot。

若兩個首次建立請求同時送達並撞上 `user_id` unique constraint，輸家會先 rollback，再於新 transaction 鎖定讀取已提交的 winner：payload 相同時 replay winner；payload 不同時回傳 `422`，不會將底層 duplicate-key 例外變成 `500`。

異動會寫入 `subject_type=salary_profile` 的 Audit Log；metadata 只記錄對象與異動欄位名稱，不複製底薪、津貼或扣款金額值。

### GET /api/commission-plans

回傳所有獎金方案、tiers、建立者與 `is_used`，依 `effective_from`、`id` 由新到舊排序。已被 salary period 引用的方案 `is_used=true`。

### POST /api/commission-plans

建立新的版本化獎金方案。成功回傳 `201`。方案不提供 update／delete API；規則異動必須建立新名稱、新生效日的方案，避免覆寫歷史。DB trigger 另會阻擋已被 salary period 引用方案的計算欄位或 tiers 異動。

Request body：

```json
{
  "name": "2027 薪資方案",
  "effective_from": "2027-01-01",
  "company_reserve_bps": 4000,
  "purchase_bonus_bps": 2000,
  "is_active": true,
  "tiers": [
    { "min_sales_count": 1, "sales_bonus_bps": 2000 },
    { "min_sales_count": 3, "sales_bonus_bps": 3000 },
    { "min_sales_count": 5, "sales_bonus_bps": 5000 }
  ]
}
```

比例皆使用 0～10000 basis points。tiers 至少一級、第一級必須從 1 台開始，`min_sales_count` 必須依序遞增且不可重複；任一級的 `purchase_bonus_bps + sales_bonus_bps` 不得超過分配池 100%。`sort_order` 由後端依陣列順序產生，禁止前端寫入。

若 request 層 unique 驗證後仍因並發撞上方案名稱 unique constraint，Service 會先 rollback，再於新 transaction 確認已提交的同名 winner，並回傳 `422 name` 驗證錯誤；其他 QueryException 不會被誤轉成重名錯誤。

月份選取規則：只考慮 `is_active=true` 且 `effective_from <= period_month` 的方案，選擇生效日最新者；若同一生效日有多個方案，以較新的 `id` 決定，確保結果 deterministic。

### GET /api/commission-plans/{commissionPlan}

回傳單一 `CommissionPlanResource`，包含依 `sort_order` 排列的 tiers、建立者與 `is_used`。不存在回傳 `404`；非 admin 在進入 Controller 前即回傳 `403`。

v1.3 第 6 部分新增的薪資資格與異常檢查目前是後端集中服務，供下一階段建立草稿、重算與確認時共用，本階段未新增對外 endpoint。它只選取台北月份內的 `sold` 車輛，並對每台候選車檢查收／賣車人、pending 收支、approved 銷售淨收款、approved 購車付款、`legacy_unknown` 與 confirmed／paid 重複引用；異常車不會被靜默略過。

v1.3 第 7 部分已完成 `SalaryPeriodService` 的服務層契約，但對外 Policy／Request／Resource／Routes 仍依 PLAN 第 9、10 部分後續實作，本階段不提前開放 endpoint。服務層行為如下：

- 建立草稿時以 `findEffectiveForMonthForUpdate()` 鎖定並選取方案，再固定 `commission_plan_id`；同月份重複建立或沒有有效方案皆回 `422`。
- 只有「啟用使用者 + 啟用薪資設定」建立 settlement；啟用但沒有薪資設定的使用者採明確排除，不會以零薪資靜默納入。
- 草稿重算沿用已綁定方案，重新建立自動項目並保留手動加扣；已存在 settlement 的薪資設定若後來停用，其自動項目歸零，既有手動項目仍保留供管理員明確處理。
- 草稿允許 `net_pay < 0`，例如純佣金員工淡月只有保險扣款，或停用設定後仍保留既有手動扣款；負值會如實保存在 signed bigint，讓 admin 可在草稿內用手動加給補平或刪除扣款。新增手動扣款若當下會把 net pay 降到負數仍即時回 `422`。
- 確認時在同一 transaction 先鎖定整月候選車，再讀 MoneyEntry、草稿 snapshot 與方案；接著重跑資格與公式，並將重算結果和草稿 snapshot 比對。若資料已變動則回 `422` 要求先重算；若仍有負薪，`net_pay` 錯誤會列出員工姓名與金額。
- confirmed／paid 後拒絕重算及手動加扣；已確認月份的成交回填與車輛獎金歸屬修改也會依台北月份阻擋，即使歷史資料沒有 settlement item reference 亦同。
- 跨模組月份鎖使用 `period_month = YYYY-MM-01` 等值條件，命中 MySQL unique index；`SalaryPeriod` 亦強制以 `Y-m-d` 持久化，避免 SQLite 保存時間部分而破壞同一契約。
- 第 9 部分實作 `SalaryPeriodResource` 時，draft 回應必須即時呼叫 `SalaryEligibilityService::inspectPeriod()`，附上 `anomalies`、`vehicle_results`、`has_blocking_issues` 與既有 correction action。異常是衍生資料、不寫入 salary_periods，但不得只回 settlements／totals 而讓被排除的車輛靜默消失。
- 薪資月份與手動加扣會寫 Audit Log，但不複製薪資金額、加扣金額或說明內容到 audit metadata。

v1.3 第 8 部分已完成同一 Service 的發薪契約；`POST /api/salary-periods/{salaryPeriod}/pay` 仍待 PLAN 第 9、10 部分補上 Request、Policy、Resource 與 route，因此目前不可由外部呼叫。服務層行為如下：

- 只允許啟用中的 admin 對 confirmed 月份操作，輸入啟用中的 `cash_account_id`、`payment_date` 與最長 100 字元的 `idempotency_key`。
- 同一 transaction 依序鎖定 period、資金帳戶及全部 settlement；每位 `net_pay > 0` 員工建立一筆 `expense`、`薪資 / 佣金`、`vehicle_id=null`、`source_type=salary_settlement`、直接 approved 的 MoneyEntry，並回填 `money_entry_id`。零元員工不建立空支出。
- 全部支出成功後才把 period 切為 paid 並寫入帳戶、日期、操作者與時間；任何一筆失敗都會完整 rollback，不留下半套 MoneyEntry 或 settlement linkage。
- 相同 key 與相同帳戶／日期 replay 已完成月份；相同 key 不同 payload、同月份不同 key、或 key 已被其他月份使用皆回 `422`。duplicate-key race 先完整 rollback，再以新 transaction 鎖定讀取已提交 winner，避免 MySQL REPEATABLE READ 舊快照誤判。
- paid period、settlement 與 item 由資料庫 trigger 阻擋後續新增／更新／刪除；薪資 MoneyEntry 亦沿用既有不可一般 CRUD／approval 異動的資料庫與服務層保護。
