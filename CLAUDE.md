# CLAUDE.md

## 專案身份

本專案是「中古車行內部營運系統」。

這是一套給小型中古車行內部使用的前後端分離營運管理系統，不是正式會計系統、不是報稅系統、不是 POS、不是 SaaS 多租戶平台。

目前狀態：

```text
1.0：工程 MVP 已完成，核心 CRUD、車輛流程、收支、資金帳戶、列印與文件已可運作。
v1.1：進入實務工作流補強階段，目標是補足內部真實操作需要的角色、遮蔽、審核、客戶與建車付款流程。
```

核心目標不是擴張成完整 ERP，而是讓中古車行日常營運能穩定落地：車輛進來、建檔、整備、上架、保留、收款、成交、列印收支明細與查看營運摘要。

---

## 必讀文件

開始任何實作前，必須先閱讀相關文件並對齊目前任務版本。

### 1.0 基礎文件

1. `企劃書.md`
2. `PLAN.md`
3. `README.md`
4. `UI.md`

### v1.1 補強文件

v1.1 任務必須閱讀：

1. `企劃書_v1.1.md`
2. `PLAN_v1.1.md`
3. `UI.md`
4. 相關 backend / frontend 既有程式碼

目前 repo 只有 `PLAN_v1.1.md`，沒有 `PLAN_v1.2.md`。不要自行建立 v1.2 計畫檔。

不得只看單一檔案就直接大量改碼。實作前必須先檢查既有目錄、路由、Service、Request、Resource、測試與前端 API 型別。

---

## 技術棧

本專案採用前後端分離。

Frontend：

* React
* TypeScript
* Vite
* Tailwind CSS

Backend：

* Laravel API
* Laravel Sanctum
* MySQL 或 MariaDB

前端只能透過 API 與後端溝通，不得直接接觸資料庫。

---

## 目前已完成的 1.0 基礎能力

目前 1.0 已完成並可作為 v1.1 的基礎：

* 登入 / 登出
* Dashboard
* 車輛 CRUD
* 車輛狀態流程：preparing → listed → reserved → sold
* 車輛硬刪除保護
* Money Entry CRUD
* 車輛快捷收支：購車付款、維修 / 美容 / 代辦 / 拍場等支出、訂金、退款、尾款
* Cash Account 查詢與餘額計算
* 使用者管理基礎：is_admin / is_active
* 列印：車輛建檔資料、成交結案收支明細
* API 文件與 README
* UI 語意色彩系統與 light / dark mode

重要既有機制：

* `VehicleService` / `MoneyEntryService` 已有成熟的 idempotency pattern：unique `idempotency_key`、payload 比對、相同 payload replay、不同 payload reject、QueryException race 後 rollback 並開新 transaction 重讀 winner。
* `MoneyEntry.source_type` 已用來區分 `manual`、`vehicle_shortcut`、`vehicle_workflow`、`legacy_unknown`。
* 非 `manual` 或來源未確認的既有收支，不得透過一般收支功能修改 / 刪除。
* 已售出 / 已取消車輛不得再新增或修改綁定收支。
* Dashboard 與 Cash Account 餘額應由後端正式計算，不可由前端自行拼湊。

---

## v1.1 實作範圍

v1.1 只做 `企劃書_v1.1.md` 與 `PLAN_v1.1.md` 明確列出的實務工作流補強。

v1.1 允許實作：

* `users.role` 固定角色：`admin` / `manager` / `sales`
* role-based middleware / policy / resource 遮蔽
* sales 敏感金額遮蔽：收購價、底價、毛利、資金餘額、完整收支金額
* 一般收支審核：`manual` source 的一般收支由 manager / sales 建立時進 pending，admin 核准後才計入正式餘額
* Customer Module：客戶、賣方 / 買方關聯、車輛關聯摘要
* 車輛入庫建檔欄位補強：排氣量、變速、燃料、停放位置、證件 / 備鑰 / 過戶 / 驗車檢核、貸款或車況備註
* 建車同步購車付款：使用者明確勾選時，Vehicle 與 initial purchase payment 在同一 transaction 建立
* v1.1 smoke 與文件更新

v1.1 不代表重新打開正式會計、稅務、發票或 SaaS 功能。

---

## 禁止實作

不得實作以下功能，除非未來文件明確解除限制：

* 正式會計系統
* 借方 / 貸方 UI
* 會計科目管理
* 傳票系統
* 報稅申報
* 稅期管理
* 發票系統
* POS 收銀
* 多公司
* 多分店
* OCR
* 附件上傳
* QR Code
* 自動稅務申報
* 記帳士交接包
* SaaS 多租戶
* 租賃、押金、違約金、長租 / 短租合約
* 完整角色權限勾選 UI
* 任何企劃書與 PLAN 沒有明確要求的進階功能

稅金只當成一般支出處理，不得實作稅務計算、稅期、稅務沖銷或申報流程。

---

## 權限與遮蔽原則

v1.1 起 `users.role` 是正式權限來源。

角色固定為：

```text
admin    管理員 / 老闆 / 最高權限
manager  經理 / 主管 / 營運管理
sales    業務 / 銷售執行
```

過渡期規則：

* `is_admin` 欄位保留作為舊版相容欄位，但不可作為唯一正式權限來源。
* 在所有 middleware / routes 完成切換前，`role` 與 `is_admin` 必須保持同步。
* `role=admin` 時 `is_admin=true`。
* `role=manager` 或 `role=sales` 時 `is_admin=false`。
* 最後一位 active admin 保護必須以 `role=admin AND is_active=true` 作為正式判斷。

敏感資料遮蔽規則：

* 不可只靠前端隱藏，後端 JSON 必須真正遮蔽。
* sales 不可看的欄位應不存在於 JSON，不可回傳 `0`、空字串或假值。
* 測試需驗證原始 JSON 沒有敏感欄位。
* sales 不可取得資金帳戶餘額、收購價、底價、毛利、完整收支金額。
* manager 可看營運金額，但不可管理使用者，也不可寫入 Cash Account。
* admin 可看與操作全部 v1.1 範圍內功能。

---

## 一般收支審核邊界

v1.1 的收支審核只針對一般收支 CRUD，也就是 `source_type=manual` 的一般營運收支。

規則：

* admin 建立的 manual money entry：`approval_status=approved`
* manager / sales 建立的 manual money entry：`approval_status=pending`
* pending / rejected 不得計入正式餘額、正式收入、正式支出、正式毛利或正式列印摘要
* approved 後才計入正式彙總
* approved / rejected 後不可改回 pending；若要修正，需建立新 entry
* 前端不可傳入或偽造 `approval_status`、`approved_by`、`approved_at`

不套用對象：

* `vehicle_shortcut`
* `vehicle_workflow`
* 既有資料回填
* 訂金、尾款、退款、購車付款等已由車輛流程產生的收支

這些流程型收支預設為 approved，避免車輛狀態流程被審核狀態卡住。

---

## 金額彙總規則

帳戶目前餘額不得儲存在資料庫。

基本公式：

```text
目前餘額 = 期初餘額 + approved 收入總額 - approved 支出總額
```

單車收入：

```text
該車所有 approved income money_entries 合計
```

單車支出：

```text
該車所有 approved expense money_entries 合計
```

單車毛利：

```text
approved 單車收入合計 - approved 單車支出合計
```

一般營運收入 / 支出不得影響任何單車毛利。

所有正式金額彙總都必須只計入 `approval_status=approved`。特別要檢查：

* `MoneyEntryService::balanceForAccount()`
* `MoneyEntryService::balanceForType()`
* `DashboardService` 的 monthly income / monthly expense
* `VehicleService::financialSummary()`
* `VehicleService::buildFinalPaymentWarning()`
* `VehicleService::printClosingData()`
* 車輛詳情單車收支摘要
* 成交結案收支明細列印
* 任何直接 `MoneyEntry::query()->sum('amount')` 的正式統計

建議使用 `MoneyEntry::approved()` scope 或集中 helper，避免各處重複手寫條件。

---

## 架構原則

系統必須模組積木化。

後端模組：

* Auth Module
* Dashboard Module
* Vehicle Module
* Vehicle Workflow Module
* Money Entry Module
* Cash Account Module
* Customer Module
* User Module
* Print Module
* Shared Module

前端模組：

* Auth
* Dashboard
* Vehicles
* MoneyEntries
* CashAccounts
* Customers
* Users
* Print
* Shared UI Components
* Layouts
* API Client
* Types
* Utils

模組之間不得硬耦合。
Dashboard 只能透過後端統計服務取得資料，不得在前端自行拼湊正式統計結果。

---

## 後端規則

Laravel 後端必須分層：

* Controller：接收 request，回傳 response
* FormRequest：驗證輸入
* Service：處理業務邏輯
* Model：資料關聯
* Policy / Middleware：權限
* Resource / DTO：API 回傳格式

禁止：

* 把大量業務邏輯寫在 Controller
* 把金流計算寫在前端
* 用前端計算結果當正式資料
* 金額使用 float
* 手動儲存 current_balance
* 實作企劃書沒有列出的功能

金流與狀態異動必須使用 database transaction。

金額欄位必須使用 integer cents 或目前專案既有整數金額設計，不得使用 float。

idempotency 相關新增功能必須沿用既有 pattern，不可另創一套未驗證機制。

---

## 前端規則

API 呼叫必須集中管理：

* `src/api/client.ts`
* `src/api/auth.ts`
* `src/api/vehicles.ts`
* `src/api/moneyEntries.ts`
* `src/api/cashAccounts.ts`
* `src/api/dashboard.ts`
* `src/api/users.ts`
* 如新增 Customer Module，需新增 `src/api/customers.ts`

禁止：

* 在元件裡散落 fetch URL
* 在前端硬寫重要業務邏輯
* 用前端結果取代後端計算
* 使用假資料假裝功能完成
* 把頁面寫成單一巨大元件
* 只靠前端隱藏敏感欄位

前端可以做顯示用格式化，但正式餘額、收入、支出、毛利、Dashboard 統計都必須以後端 API 回傳為準。

---

## UI 原則

UI 風格遵守 `UI.md`：

* 現代後台
* 清楚
* 低噪音
* 高可讀性
* 不花俏
* 不過度設計
* 不使用假資料
* 支援 light / dark mode
* 使用既有語意色彩 token

必要元素：

* 左側 Sidebar
* 上方 Header
* 卡片式 Dashboard
* 圓角面板
* 狀態 Badge
* 表格
* 搜尋列
* 篩選器
* 明確操作按鈕
* visible labels
* required `*` marker
* per-field error message
* 成功提示

桌機優先，手機需可基本操作。

---

## 車輛流程規則

車輛狀態：

* preparing：整備中
* listed：上架中
* reserved：保留中
* sold：已售出
* cancelled：取消 / 退車

新增車輛後：

```text
建立 vehicles
→ 自動產生 stock_no
→ status = preparing
```

車輛流程必須支援：

```text
整備中
→ 上架中
→ 保留中
→ 已售出
```

不適用目前狀態的操作按鈕不得顯示。

建車同步購車付款規則：

* 不可因填寫 `purchase_price` 就自動扣款。
* 必須由使用者明確選擇是否同步建立購車付款。
* Vehicle 與 initial purchase payment 必須在同一個 DB transaction。
* initial purchase payment 失敗時，Vehicle 不得殘留。
* `sales` 不可建車，也不可觸發建車同步購車付款。
* `vehicles.idempotency_key` 與 `stock_no` 並發風險必須以測試覆蓋；MySQL/MariaDB 並發測試不得只靠 SQLite 假測。

---

## 開發流程

1. 閱讀相關文件。
2. 檢查目前目錄與既有程式碼。
3. 確認任務屬於 1.0 維護或 v1.1 補強。
4. 1.0 任務依 `企劃書.md` / `PLAN.md`；v1.1 任務依 `企劃書_v1.1.md` / `PLAN_v1.1.md`。
5. 如果任務超出範圍，不要實作，先在回覆中說明原因。
6. 優先小步修改，避免一次大範圍重構。
7. 保持模組邊界清楚。
8. 修改後執行可用的驗證指令。
9. 任務完成之後更新對應 PLAN 檔案的完成狀態。

---

## 驗證要求

完成修改後，至少依任務範圍確認：

* backend 可以 migrate
* backend 可以 seed
* backend API 可以啟動
* frontend 可以啟動
* 登入 / 登出可用
* Dashboard 使用真實 API
* 車輛 CRUD 可用
* 收支 CRUD 可用
* 資金帳戶餘額正確
* 單車收入 / 支出 / 毛利正確
* 車輛流程可從整備中走到已售出
* 列印頁可開啟
* 權限與 Resource 遮蔽由後端 JSON 驗證
* pending / rejected 收支不影響正式彙總
* 沒有假資料
* 沒有額外實作未列功能

如果某些驗證無法執行，必須在回覆中明確列出原因。

高風險項目必須補測試：

* role / is_admin 過渡期
* 最後一位 active admin 保護
* sales JSON 敏感欄位遮蔽
* approval_status 對所有正式金額彙總的影響
* idempotency replay-or-reject
* MySQL/MariaDB 並發 race，不可只用 SQLite 假測

---

## 回覆格式

完成任務後，回覆必須包含：

1. 本次完成項目
2. 修改檔案
3. 重要實作說明
4. 驗證指令與結果
5. 未執行的驗證與原因
6. 給出建議 commit message，而非自動commit

不得只回覆「完成」。

---

## Commit message 規則

使用簡潔的繁體中文 commit message。

---

## 最重要限制

只依照目前任務對應版本的企劃書與 PLAN 實作。

不要額外過度實作。
不要把正式會計、稅務、發票、傳票、OCR、附件、多公司、多分店、POS、租賃或 SaaS 功能帶進本專案。

---

## CODE REVIEW

所有程式碼皆會交由 Codex 進行 Code Review。

實作時要預設會被 adversarial review 檢查：

* 權限是否只靠前端隱藏
* JSON 是否洩漏敏感欄位
* 金額彙總是否漏掉 pending / rejected 過濾
* migration 是否可重跑、可 rollback 或 forward-only 說明清楚
* transaction 是否真的包住狀態與金流
* idempotency 是否 replay-or-reject，而不是 silent success
* MySQL/MariaDB concurrency test 是否真的跨連線、不是 RefreshDatabase 外層 transaction 下的假測試
