# CLAUDE.md

## 專案身份

本專案是「中古車行內部營運系統」。

這是一套給小型中古車行內部使用的前後端分離營運管理系統，不是正式會計系統、不是報稅系統、不是 POS、不是 SaaS 多租戶平台。

目前狀態：

```text
1.0：工程 MVP 已完成，核心 CRUD、車輛流程、收支、資金帳戶、列印與文件已可運作。
v1.1：實務工作流補強已完成 smoke，並以 v1.1-smoke-passed tag 封版。
v1.2：車輛圖片與官網公開車輛資料前置階段已完成 smoke，並以 v1.2-smoke-passed tag 封版。
v1.3：薪資結算已完成企劃與 PLAN，尚未開始實作；目標是依成交、approved-only 毛利、跨級獎金、底薪與勞健保扣款，自動算出每月實發薪資並在發薪後建立正式支出。
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
3. `docs/current-state.md`
4. `docs/v1.1-smoke-report.md`
5. `UI.md`
6. 相關 backend / frontend 既有程式碼

### v1.2 車輛圖片與官網前置文件

v1.2 任務必須閱讀：

1. `企劃書_v1.2.md`
2. `PLAN_v1.2.md`
3. `docs/current-state.md`
4. `docs/v1.1-smoke-report.md`
5. `docs/v1.2-smoke-report.md`
6. `docs/v1.2-handoff.md`
7. `backend/API.md`
8. `UI.md`
9. 相關 backend / frontend 既有程式碼

### v1.3 薪資結算文件

v1.3 任務必須閱讀：

1. `企劃書_v1.3.md`
2. `PLAN_v1.3.md`
3. `docs/current-state.md`
4. `docs/v1.2-smoke-report.md`
5. `docs/v1.2-handoff.md`
6. `backend/API.md`
7. `UI.md`
8. `backend/app/Models/User.php`
9. `backend/app/Models/Vehicle.php`
10. `backend/app/Models/MoneyEntry.php`
11. `backend/app/Services/VehicleService.php`
12. `backend/app/Services/MoneyEntryService.php`
13. 相關 Policy、Request、Resource、routes、tests 與前端 API 型別

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
* sales 敏感金額遮蔽：收購價、購車付款、完整整備成本、單車毛利、資金帳戶餘額、完整收支金額（開價 / 底價 / 成交價為業務議價與收款追蹤依據，sales 可見；訂金 / 尾款 / 退款等銷售收款金額 sales 也可見，但不含資金帳戶）
* 一般收支審核（老闆身兼會計）：只要建立者不是 admin，任何會影響正式資金、成本、毛利的 MoneyEntry（`manual`、`vehicle_shortcut`、`vehicle_workflow`）一律進 pending，只有 admin 建立才直接 approved；只有 admin 可核准 / 駁回
* Customer Module：客戶、賣方 / 買方關聯、車輛關聯摘要
* 車輛入庫建檔欄位補強：排氣量、變速、燃料、停放位置、證件 / 備鑰 / 過戶 / 驗車檢核、貸款或車況備註
* 建車同步購車付款：使用者明確勾選時，Vehicle 與 initial purchase payment 在同一 transaction 建立
* v1.1 smoke 與文件更新

v1.1 不代表重新打開正式會計、稅務、發票或 SaaS 功能。

v1.1 已封版。除非使用者明確要求修補 v1.1 文件或 hotfix，否則新功能不得繼續塞入 v1.1。

---

## v1.2 實作範圍

v1.2 只做 `企劃書_v1.2.md` 與 `PLAN_v1.2.md` 明確列出的車輛圖片與官網前置基礎。

v1.2 允許實作：

* 車輛照片資料模型 `vehicle_photos`
* 車輛照片上傳、縮圖、刪除、排序、封面照
* 車輛詳情頁照片管理 UI
* admin / manager 管理照片，sales 只能查看
* public vehicles read-only API，只公開已上架車輛與公開照片
* storage disk + path 設計，先用 Laravel public storage，預留未來 Cloudflare R2
* API 文件、README、current-state 與 smoke 文件更新

v1.2 不代表直接實作完整官網、CMS、SEO 後台、線上付款、預約試乘或通用附件系統。

v1.2 已完成並封板。除非使用者明確要求 v1.2.x hotfix，否則完整官網或後續功能必須另立企劃書與 PLAN，不得繼續塞入 `PLAN_v1.2.md`。

---

## v1.3 實作範圍

v1.3 只做 `企劃書_v1.3.md` 與 `PLAN_v1.3.md` 明確列出的薪資結算。

v1.3 允許實作：

* 員工薪資設定：底薪、固定津貼、勞保扣款、健保扣款、是否啟用佣金
* 車輛正式收車人 `purchase_agent_id` 與賣車人 `sales_agent_id`
* 可版本化的獎金方案與跨級賣車獎金階梯
* approved-only 單車毛利獎金計算
* 公司營運保留 40%、分配池 60%、收車獎金分配池 20%
* 賣車獎金採整月跨級：1～2 台 20%、3～4 台 30%、5 台以上 50%
* 每月薪資草稿、重算、手動加扣項、確認鎖定與發薪
* 發薪後建立 `薪資 / 佣金` Money Entry，並以專用 `salary_settlement` source type 保護
* admin-only 薪資 API、頁面、Dashboard 摘要與稽核
* v1.3 tests、manual smoke、API 文件與交接文件

v1.3 重要邊界：

* 不得用 `created_by`、`updated_by`、收款建立者或最後操作者推定歷史收車人／賣車人。
* pending／rejected 收支不得進入毛利與獎金。
* 已確認或已發薪的規則、歸屬與薪資快照不得被後續設定回改。
* 所有金額使用整數，比例使用 basis points 或等價整數設計，不得使用 float。
* 跨級後同一賣車人當月所有符合資格車輛套用同一最高級距，不採逐台邊際級距。
* 薪資資料初版只開放 admin；manager／sales 不得讀取任何薪資 API 或畫面。

v1.3 不代表開放完整 HR、打卡、排班、請假、官方勞健保級距計算、所得稅扣繳、銀行薪轉檔或正式會計。

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
* 通用附件上傳（v1.2 僅允許 `vehicle_photos` 車輛照片，不開放一般附件系統）
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
* sales 不可取得資金帳戶餘額、收購價、購車付款、完整整備成本、單車毛利、完整收支金額、他人上報的成本明細。
* sales 可取得開價（asking_price）、底價（floor_price）、成交價（sold_price），因為這是業務跟客人談價錢、追蹤收款的依據；不可因此推論 sales 也能看收購價、完整成本、毛利或資金帳戶餘額。
* sales 可取得訂金收入、尾款收入、退款等銷售收款金額（不論由誰建立），以及自己上報的車輛整備 / 維修 / 美容 / 代辦 / 拍場 / 其他支出申請與其審核狀態；但一律看不到資金帳戶（`cash_account_id` / `cash_account`）。
* manager 可看完整營運金額與毛利，但不可管理使用者，不可寫入 Cash Account，也不可核准 / 駁回收支。
* admin 是老闆兼會計，可看與操作全部 v1.1 範圍內功能，且只有 admin 可核准 / 駁回收支，核准後才正式計入餘額、成本與毛利。

---

## 一般收支審核邊界

老闆身兼會計：只要建立者不是 admin，任何會影響正式資金、成本、毛利的 MoneyEntry 都必須是 pending；只有 admin 建立的 MoneyEntry 才可直接 approved。這個規則套用範圍涵蓋：

* 一般收支 `manual`
* 車輛快捷收支 `vehicle_shortcut`（購車付款、維修 / 美容 / 代辦 / 拍場 / 其他支出、訂金收入、退款）
* 車輛流程收支 `vehicle_workflow`（建車同步購車付款、reserve 產生的訂金、final-payment 產生的尾款）

規則：

* admin 建立的 money entry（不論 source_type）：`approval_status=approved`
* manager / sales 建立的 money entry（不論 source_type）：`approval_status=pending`
* pending / rejected 不得計入正式餘額、正式收入、正式支出、正式毛利或正式列印摘要
* approved 後才計入正式彙總
* approved / rejected 後不可改回 pending；若要修正，需建立新 entry
* 前端不可傳入或偽造 `approval_status`、`approved_by`、`approved_at`
* approve / reject 只有 admin 可呼叫，manager 即使可看完整營運金額也不行；只有 `pending` 狀態可核准 / 駁回，狀態不可逆
* 只有 `source_type=legacy_unknown`（來源未確認的既有資料）不可核准 / 駁回，需人工確認來源後再處理
* 車輛成交結案（`closeSale`）只依 `approved` 收款判斷是否可關帳：`approved` 收款總額需達成交價，pending 收款不算正式已收，不可直接關帳
* update / delete 規則仍保守：非 `manual` 原則上不得透過一般收支 CRUD 修改 / 刪除，不因為可核准而開放改刪流程收支

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
3. 確認任務屬於既有版本 hotfix，或目前明確規劃中的版本。
4. 依任務版本閱讀對應企劃書與 PLAN；v1.3 任務以 `企劃書_v1.3.md` / `PLAN_v1.3.md` 為正式範圍。
5. 如果任務超出範圍，不要實作，先在回覆中說明原因。
6. 優先小步修改，避免一次大範圍重構。
7. 保持模組邊界清楚。
8. 修改後執行可用的驗證指令。
9. 任務完成之後更新對應 PLAN 檔案的完成狀態。

---

## 驗證要求

完成修改後，至少依任務範圍確認：

資料庫重建規則：

* 任何會清空或重建開發資料庫的操作（例如 `php artisan migrate:fresh`、`php artisan db:wipe`）完成後，必須立即重新執行種子資料，優先使用 `php artisan migrate:fresh --seed` 或接續執行 `php artisan db:seed`。
* 必須確認 `DatabaseSeeder` 所要求的基礎資料已補回，目前至少包含管理員帳號與資金帳戶；不得把開發環境留在空資料庫狀態。
* 清空非測試資料庫前必須先取得使用者明確同意，不得把測試用重建流程誤用於正式或開發中的真實資料。

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
