# ERPV2 current-state — v1.2 已封版，v1.3 第 8 部分完成

日期：2026-07-14
專案：ERPV2 / 中古車行內部營運系統
目前穩定點：`b1edffa docs: 完成 v1.2 smoke 封版與交接文件`
目前 tag：`v1.1-smoke-passed`、`v1.2-smoke-passed`
狀態：v1.2 已完成並封版。v1.3「薪資結算」已完成 `PLAN_v1.3.md` 第 0～11 部分：後端薪資設定、版本化獎金方案、車輛收／賣車人正式歸屬、approved-only 跨級獎金計算、月份草稿／重算／確認、整批發薪、HTTP 安全邊界與 admin-only 管理前端皆已完成；後續完整人工 Smoke 尚未執行。

---

## 1. 產品定位

ERPV2 目前是一套給小型中古車行內部使用的前後端分離營運管理系統。

系統目標不是正式會計、報稅、發票或 POS，而是把車行每天實際操作數位化：

```text
車輛入庫
→ 建檔與檢核
→ 整備中
→ 上架
→ 收訂金並保留
→ 收尾款
→ 老闆核准收款
→ 成交結案
→ 查詢單車收支與列印資料
→ v1.3 預定進入薪資結算與發薪支出
```

v1.1 的重點是補足真實車行操作需要的角色、遮蔽、審核、客戶、建車付款、稽核與 smoke 修正。

---

## 2. 技術現況

### Backend

- Laravel API
- Sanctum session / CSRF
- MariaDB / MySQL 相容設計
- Feature tests 覆蓋核心流程、權限、審核、idempotency、migration repair

### Frontend

- React + TypeScript + Vite
- 前後端分離
- 角色導覽列與頁面入口依權限真實移除，不只靠 CSS 隱藏
- 後端 Resource 仍是正式資料遮蔽邊界

### 目前驗證指令

```bash
cd backend && ./vendor/bin/phpunit
cd frontend && npx tsc -b
cd frontend && ./node_modules/.bin/vite build
```

v1.2 封版前最終結果：334 tests、1372 assertions、4 skipped；frontend typecheck 與 production build 均通過。完整紀錄見 `docs/v1.2-smoke-report.md`。v1.2.x hotfix（車輛照片稽核追蹤，2026-07-12，含 partial upload resume/replay 遺漏補記修正）後為 340 tests、1391 assertions、4 skipped；v1.3 第 10 部分 review 修正後最新完整回歸為 479 tests、467 passed、12 environment-gated skipped、2188 assertions。薪資確認／收支核准跨連線鎖測試已在真實 MariaDB 通過 13 assertions，薪資資格 TIMESTAMP 月界整合測試通過 11 assertions，薪資月份／成交結案鎖序測試通過 14 assertions；Claude review 另在可拋棄 MariaDB 10.11 schema 驗證原始發薪並發案例 5 tests／71 assertions 通過。review 修正後新增的 re-parent 與 migration retry MariaDB 案例已實作，待專用 allowlist 環境重跑。frontend lint（保留 2 個既有 Fast Refresh warnings）／typecheck／production build 沿用第 7 部分已通過結果，本次未修改前端。

---

## 3. 目前完成能力

### 3.1 Auth / 使用者

- 登入 / 登出
- 登出 idempotent retry
- 登入失敗節流
- 使用者管理
- 角色：`admin` / `manager` / `sales`
- `role` 是正式權限來源
- `is_admin` 僅保留相容用途，不可作為唯一權限來源
- 最後一個 active admin 不可被停用、降權或刪除

### 3.2 Dashboard

- admin / manager 可看資金與營運金額
- sales 只看經營狀態型卡片，不看資金金額
- sales Dashboard 快捷入口包含車輛、客戶與收支申請入口
- sales 不顯示新增買入車輛、資金帳戶、員工管理、稽核紀錄

### 3.3 Vehicles

- 車輛 CRUD
- 自動產生庫存編號
- 入庫檢核欄位
- 建車同步購車付款
- 上架
- 收訂金並保留
- 收尾款
- 成交結案
- 單車收支摘要
- 列印資料 API
- 硬刪除保護：非 preparing 或已有 money_entries 不可刪除

### 3.4 Money Entries

- 一般收支 CRUD
- 車輛快捷收支
- 車輛流程收支
- `source_type` 分類：
  - `manual`
  - `vehicle_shortcut`
  - `vehicle_workflow`
  - `legacy_unknown`
- `approval_status`：
  - `pending`
  - `approved`
  - `rejected`
- Approved-only 彙總：pending / rejected 不計入正式餘額、成本、毛利、Dashboard
- Admin-only approve / reject
- 狀態不可逆
- 非 manual 原則上不得透過一般 CRUD 修改 / 刪除

### 3.5 Customers

- 客戶新增 / 編輯 / 查詢
- 關聯車輛買方 / 賣方
- 車輛保留時可選擇關聯客戶並同步快照
- 客戶資料更新不會回改既有車輛快照
- 有關聯車輛的客戶不可刪除

### 3.6 Cash Accounts

- 資金帳戶 CRUD 僅 admin
- admin / manager 可看餘額
- sales 只能用 `cash-accounts/options` 取得表單選項，不含餘額

### 3.7 Audit Logs

- 管理員可查詢稽核紀錄
- manager / sales 不可讀取
- 敏感金額不寫入 audit log
- 稽核紀錄 append-only
- 登入 / 登出也有紀錄
- 車輛照片上傳 / 刪除 / 換封面 / 排序也有紀錄（`subject_type=vehicle_photo`）；v1.2.x hotfix（2026-07-12）修復 `VehiclePhoto` 從未被稽核追蹤的問題，見 `PLAN_v1.2.md` 第 9 節 hotfix 說明。排序一次動作只記一筆（`subject_id` 為 null，`after_values` 記完整順序），不逐張照片記錄

---

## 4. 最新權限規則

### 4.1 Admin / 老闆兼會計

admin 是最高權限，也是目前產品假設中的老闆兼會計。

admin 可以：

- 看全部資料
- 新增 / 編輯 / 刪除允許刪除的資料
- 管理使用者
- 管理資金帳戶
- 查稽核紀錄
- 核准 / 駁回所有 pending 收支
- 建立收支時直接 approved

### 4.2 Manager / 營運主管

manager 可以看完整營運資料，但不扮演會計核准者。

manager 可以：

- 看完整車輛金額、成本、毛利
- 看 Dashboard 金額
- 看資金帳戶餘額
- 新增 / 編輯車輛
- 建車同步購車付款
- 上架車輛
- 執行銷售流程
- 建立一般收支或車輛支出申請

manager 不可以：

- 核准 / 駁回收支
- 管理使用者
- 新增 / 停用 / 刪除資金帳戶
- 查看稽核紀錄
- 刪除客戶

### 4.3 Sales / 業務

sales 可以執行銷售與上報資料，但不可看成本與管理財務。

sales 可以看到：

- 車輛基本資料
- 開價 `asking_price`
- 底價 `floor_price`
- 成交價 `sold_price`
- 銷售收款摘要
- 訂金 / 尾款 / 退款
- 已核准收款
- 待老闆核准收款
- 待收差額
- 自己上報的整備 / 維修 / 美容 / 代辦 / 拍場 / 其他支出申請
- 自己上報紀錄的審核狀態

sales 不可以看到：

- 收購價 `purchase_price`
- 購車付款
- 他人上報的完整成本明細
- 完整支出合計
- 完整收入合計
- 單車毛利
- 資金帳戶餘額
- 完整資金帳戶資訊
- 管理用完整單車收支明細
- 稽核紀錄
- 使用者管理

### 4.4 Unknown role

未知角色必須 fail-closed：

- 不可看到任何價格欄位
- 不可看到任何金額摘要
- 不可看到收支明細金額
- 不可看到 Dashboard 金額

---

## 5. 老闆核准流程

最新產品規則：

```text
任何會影響正式資金餘額、正式單車成本、正式毛利的資料，都可以由員工上報，但只有 admin 核准後才正式計入。
```

### 5.1 建立時狀態

| 建立者 | manual | vehicle_shortcut | vehicle_workflow |
|---|---|---|---|
| admin | approved | approved | approved |
| manager | pending | pending | pending |
| sales | pending | pending | pending |

### 5.2 核准 / 駁回

- 只有 admin 可核准 / 駁回
- manager / sales 呼叫 approve / reject 一律 403
- 只有 pending 可核准 / 駁回
- approved / rejected 不可逆
- `legacy_unknown` 不可核准 / 駁回，需先人工確認來源

### 5.3 成交結案

成交結案只採計已核准的實際銷售收款：

- 訂金收入
- 尾款收入
- 扣除退款

不採計：

- pending 訂金
- pending 尾款
- pending 退款
- 其他單車收入
- 成本或其他無關 income

成交結案前若仍有 pending 訂金 / 尾款 / 退款，會被阻擋，必須先由 admin 核准或駁回。

已成交或已取消車輛不可再事後核准訂金 / 尾款 / 退款，避免破壞已關帳金額不變量。

---

## 6. 已知限制

目前刻意不做：

- 正式會計傳票
- 借貸方分錄
- 報稅
- 發票
- POS
- OCR
- 記帳士匯出包
- 完整角色權限勾選 UI
- SaaS 多租戶
- 完整 HR／打卡／排班／請假系統
- 官方勞健保費率與級距自動計算／申報
- 所得稅扣繳與薪資申報
- 員工自助薪資單與銀行薪轉檔

目前已知技術限制：

- 全站表單仍多為 submit-time validation，尚未統一改成 on-blur per-field validation。
- 列印頁已由測試覆蓋資料結構，但仍建議在正式使用前肉眼檢查紙本排版。
- MySQL/MariaDB 真並發測試需要專用資料庫環境；一般 PHPUnit full suite 會 skip 4 個既有 MySQL-only tests。
- v1.2 照片排序採左右按鈕，未做拖曳排序。
- 圖片目前使用 Laravel public disk，尚未實作 Cloudflare R2 driver。
- `php artisan storage:link` 是部署必要步驟；缺少 symlink 時圖片 URL 無法公開讀取。
- 真實雙連線 MySQL 封面競態壓力測試保留給 v1.2.x 視需要補強。

---

## 7. v1.2 封版與 v1.3 規劃狀態

v1.2 已完成：

- 車輛照片資料模型（`vehicle_photos`，含 soft delete tombstone、封面唯一性 DB 約束）。
- durable upload batch、idempotency replay-or-reject、processing lease 與 fencing token。
- 後台照片上傳、刪除、排序、設封面與失敗復原。
- 車輛詳情頁照片管理 UI。
- admin／manager 可管理，sales 唯讀。
- public vehicles read-only API。
- public API 僅公開已上架車輛與白名單欄位，列表只載入封面，並加上 IP throttle。
- public storage 與未來 Cloudflare R2 可切換的 disk/path 設計。
- backend tests、frontend typecheck/build 與瀏覽器 manual smoke 全部通過。
- Smoke 過程修復 MySQL database cache lock refresh 誤判問題。

v1.2 封版文件：

- `PLAN_v1.2.md`
- `docs/v1.2-smoke-report.md`
- `docs/v1.2-handoff.md`

v1.2 不做：

- 完整官網前端
- CMS
- SEO 管理後台
- 線上付款
- 預約試乘／lead form
- 通用附件系統
- OCR
- 直接把 Cloudflare R2 作為必做項

v1.3 已另立：

- `企劃書_v1.3.md`
- `PLAN_v1.3.md`

v1.3 已鎖定為「薪資結算」，不是完整 HR。核心規則：

```text
單車毛利
→ 公司營運保留 40%
→ 剩餘 60% 為可分配獎金池
   ├─ 收車獎金：分配池 20%
   ├─ 賣車獎金：整月跨級
   │  ├─ 1～2 台：20%
   │  ├─ 3～4 台：30%
   │  └─ 5 台以上：50%
   └─ 其餘為公司剩餘分配額
```

v1.3 另包含底薪、固定津貼、勞保扣款、健保扣款、手動加扣項、每月草稿／確認／發薪，以及發薪後自動建立 `薪資 / 佣金` Money Entry。

v1.3 第 1～10 部分已補齊：

- `salary_profiles`、`commission_plans`／`commission_plan_tiers`、`salary_periods`、`salary_settlements`、`salary_settlement_items`。
- Vehicle 正式 `purchase_agent_id`／`sales_agent_id`，歷史資料保持空值，不做 heuristic backfill。
- 使用者刪除前置檢查涵蓋 Vehicle 的收車／賣車歸屬，已有歸屬時回傳 422 並要求改為停用。
- `2026 標準薪資方案` Seeder，可重跑且已使用方案不會被覆寫。
- `salary_settlement` MoneyEntry source type、一般 CRUD／approval 保護與 manager／sales 查詢遮蔽。
- `SalaryProfile`、`CommissionPlan`／Tier、`SalaryPeriod`、`SalarySettlement`／Item 完整 fillable、casts、狀態／type 常數與上下游關聯。
- admin-only `GET/PUT salary-profiles` 與 `GET/POST commission-plans` API；manager、sales、未知角色皆 fail-closed 403。
- Salary Profile 只接受非負整數金額；停用使用者不能啟用薪資設定，且既有 confirmed／paid snapshot 不受目前設定修改影響。
- Commission Plan tiers 由集中規則驗證，方案不提供修改／刪除 API；月份採「最新有效生效日、同日較新 id」的 deterministic 選取規則。
- 薪資設定稽核只記對象與異動欄位名稱，不複製底薪、津貼、保險扣款金額值。
- Salary Profile 首次建立與 Commission Plan 重名建立的 duplicate-key race 皆會在 rollback 後開新 transaction 讀取 winner；相同 profile payload 可 replay，不同內容／重名方案回 422，不再外洩成 500。
- `SalarySettingsMysqlConcurrencyTest` 使用 `pcntl_fork`、socket barrier 與真正獨立 MariaDB connections 驅動兩個 Service 公開方法；只有在測試環境、connection／database allowlist、明確可拋棄資料庫名稱與 `RUN_MYSQL_CONCURRENCY_TESTS=1` 同時成立時才允許 `migrate:fresh`。
- 建車時 admin／manager 必須指定 active 收車人；建車冪等快照包含 `purchase_agent_id`。事實歸屬不依賴 Salary Profile，fresh seed 或 v1.2 升級後不會因尚未設定薪資而鎖死建車。
- 保留銷售流程由 sales 安全歸屬本人，admin／manager 代登必須指定 active 實際賣車人；reservation 冪等比對包含 `sales_agent_id`，成交結案前不得缺少賣車人。未設定薪資或 `commission_enabled=false` 不阻斷銷售，獎金資格留待結算判斷。
- 獎金人員選項只開放 admin／manager，sales 不需選人且無法藉此取得薪資設定衍生名單。
- admin 可由待補清單人工補登歷史已售車輛歸屬；manager／sales 403，confirmed／paid 月份引用後鎖定，異動寫入 Audit Log。
- `SalaryCommissionCalculator` 只讀取傳入 eligible 車輛的 approved MoneyEntry，集中計算毛利、公司保留、分配池、收車／賣車獎金、公司剩餘與公司最終取得。
- 賣車級距依同一批月份 eligible 車輛按 `sales_agent_id` 分組，從 `CommissionPlanTier` 選取 0／1／3／5 台級距並整批追溯套用，不採逐台 marginal tier。
- 計算結果包含距下一級台數與既有車輛跨級後預估增加額；所有比例使用 basis points 並向下取整，餘數歸公司。
- 大額比例以商數／餘數拆算，避免 `amount × bps` 中間乘法 overflow；超出 PHP 安全整數範圍的彙總會明確拒絕，不會靜默截斷。
- 混合盈虧批次另回傳 `loss_total` 與 `company_net`；`company_total` 表示獲利車分配給公司的正額合計，正式公司淨得應使用 `company_net`，並維持 `company_net + purchase_bonus + sales_bonus = gross_profit`。
- 計算器要求明確傳入 `YYYY-MM` 結算月份與啟用佣金的 agent IDs；月份不符會拒絕，未啟用佣金的人員只將自己對應的收車／賣車 bps 歸零，不會犧牲另一位人員的合法獎金。
- `CommissionPlanService` 的月份查詢與計算器共用 `SalaryPeriodMonth` 的 `YYYY-MM` 契約，查詢會明確轉成月首日；建立草稿使用 `findEffectiveForMonthForUpdate()` 鎖定並選取最新有效方案。之後重算沿用 SalaryPeriod 綁定的 `commission_plan_id`；計算器只驗證方案已儲存且 tiers 合法，避免回溯生效的新方案把既有草稿或歷史 snapshot 卡死。
- `SalaryProfile.user_id` 明確 cast 為 integer，後續傳入佣金資格集合時不依賴 PDO／driver 回傳型別。
- 使用者已決策：`gross_profit <= 0` 車輛仍列入月份明細與公司淨損益，但不增加賣車跨級台數；只有正毛利車計入 `eligible_sales_count`。
- 啟用佣金但整月只有零毛利／虧損車的賣車人仍會出現在 `sales_agents`，明確回傳 0 台、0 bps、0 元，不會消失或造成 undefined key。
- 全系統業務日期與月份邊界統一為 `Asia/Taipei`。Laravel app timezone、MySQL／MariaDB session timezone、Dashboard 月統計、預設收支日期、成交時間正規化、列印／稽核前端顯示及 API datetime offset 均採台北時間。
- 既有 MySQL／MariaDB `TIMESTAMP` 由資料庫依 `+08:00` session timezone 顯示，不做推測式資料搬移；正式環境須保留 `APP_TIMEZONE=Asia/Taipei` 與 `DB_TIMEZONE=+08:00`。持久化 SQLite 不是正式部署目標，既有 UTC-naive 開發資料需重建，不做不安全回填。
- 時區切換前提是舊環境實際以 UTC 運作；部署前必須查 `@@global.time_zone`、`@@session.time_zone`、`@@system_time_zone` 並比較 `NOW()`／`UTC_TIMESTAMP()`。若舊環境不是 UTC，停止部署並人工評估既有 TIMESTAMP，不得直接假設無需轉換。
- `SalaryEligibilityService` 以台北月份選出 `status=sold` 候選車，並集中檢查歸屬、任意 pending 收支、approved 銷售淨收款、approved 購車付款、`legacy_unknown` 與其他 confirmed／paid 月份重複引用。
- 本月候選車不因異常被靜默丟棄；結果保留車號、問題碼、業務可讀訊息與前端可映射的修正動作代碼，不在後端 Service 硬寫 SPA 路徑。草稿可顯示異常，確認流程以 422 fail-closed。
- `assertPeriodEligible()` 是月份確認唯一入口：只能在 transaction 內執行，會重新從資料庫選取完整月份並 `lockForUpdate()` 候選車，不接受舊草稿傳入的任意集合。
- 所有綁車 MoneyEntry 核准都會鎖定同一車輛列，確保維修等非銷售類 pending 支出核准與薪資確認互斥；銷售收款分類統一由 `VehicleMoneyCategories` 提供，避免關帳、可見範圍與薪資資格規則漂移。
- 薪資資格服務的任意集合檢查已改為 private；一般預覽使用 `inspectPeriod()`，第 7 部分的草稿建立／重算使用 transaction-only `inspectPeriodForUpdate()` 鎖定完整月份，確認則使用 `assertPeriodEligible()` 重新鎖定並阻擋異常。
- 零毛利／虧損車只要其他資格完整仍列為 eligible，並回傳 approved-only 毛利；後續交給計算器保留明細與公司淨損益，但不推升賣車跨級台數。
- `SalaryPeriodService` 已完成月份草稿、重算、手動加扣與確認鎖定。建立草稿才選取最新有效方案，後續重算固定沿用 `commission_plan_id`，不受新建回溯方案影響。
- 薪資草稿月份不得晚於 `Asia/Taipei` 目前月份；當月可用於即時預估，未來月份由 Request 與 Service 雙層拒絕，避免誤確認後提前鎖住該月份成交回填。
- 只有 active user + active salary profile 建立 settlement；沒有啟用薪資設定的使用者採明確排除。草稿重算會更新薪資 snapshot 與所有自動項目，但保留既有手動加扣；設定後來停用的既有 settlement 自動項目歸零，不會暗刪手動資料。
- `salary_settlements.net_pay` 為 signed bigint。草稿允許負薪並保持可建立、可重算、可手動補平；確認點才列出負薪員工並以 422 阻擋。新增手動扣款若立即造成負薪仍會 rollback。
- 草稿建立／重算會先鎖定整月 sold 候選車，再讀 MoneyEntry、settlement snapshot 與方案，避免 MySQL REPEATABLE READ 在車輛鎖前建立舊 read view；與獎金歸屬修改及綁車 MoneyEntry 核准共用車輛列鎖。確認時同一 transaction 重新選取、鎖定、驗資格、重算並比對 snapshot；stale preview 會回 422 要求先重算。
- confirmed／paid 月份查詢以 `period_month = YYYY-MM-01` 命中 unique index，不使用會使 MySQL 全表掃描並擴大 `FOR UPDATE` 鎖範圍的 `whereDate()`。SalaryPeriod 寫入則固定正規化為 `Y-m-d`，確保 SQLite／MySQL 等值契約一致。
- draft 不持久化資格異常；`SalaryPeriodResource` 已在輸出草稿時呼叫 `SalaryEligibilityService::inspectPeriod()`，把 `anomalies`、`vehicle_results`、`has_blocking_issues` 與修正 action 一併回傳，避免被排除的異常車在草稿畫面靜默消失。confirmed／paid 回應只讀鎖定快照，不重新套用目前資格資料。
- `SalaryPeriodService::loadPeriod()` 與薪資 Resource 的 `whenLoaded()` 契約已對齊：方案建立人、月份建立／確認／發薪人、發薪帳戶，以及 item 的車輛摘要／手動項目建立人都由真實 Service 回傳預載，不依賴 Controller 額外補載。
- 手動加扣 Request 會把 canonical 整數字串金額正規化為 int，再交給 Service 的嚴格整數契約；SalarySettlementPolicy 同時提供新增與刪除手動加扣的 admin-only ability，已由第 10 部分 routes 掛載。
- `SalaryPeriodController` 已開放月份列表、建立、詳情、重算、手動加扣、確認與發薪端點；列表只使用 `SalaryPeriodListResource` 與 DB aggregate，不會逐月份掃描車輛資格，詳情才使用完整 `SalaryPeriodResource`。
- 建立／重算會把 transaction 內既有 eligibility 結果帶給 `SalaryPeriodResource`，避免 commit 後再掃描一次造成成本與短暫顯示不一致；獨立 GET 詳情仍保留即時掃描。手動加扣 POST 同步預載 nullable vehicle relation，確保 item 回應固定包含 `vehicle: null`。
- 手動加扣項刪除會先驗證 item 所屬 settlement 的 `salary_period_id` 與 URL 月份一致，再以該 settlement 執行 Policy 授權；跨月份 ID 組合固定 `404`，不會把 item 錯傳給 `SalarySettlementPolicy`。
- `role` middleware 已排在 route model binding 前執行，薪資月份與 admin-only 車輛歸屬端點對 manager／sales 不論資源 ID 是否存在皆固定 `403`，避免用 `403`／`404` 差異枚舉敏感資源。
- `SalarySettingsMysqlConcurrencyTest` 已新增並在可拋棄 MariaDB schema 實跑 salary period／closeSale fork/socket 鎖序案例：parent 持有 period 鎖時 child 必須等待，parent 仍可取得 vehicle 鎖；提交 confirmed 後 child 醒來回 `sold_at` 422，避免未來重構恢復 vehicle → period 反向鎖。
- confirmed／paid 月份拒絕重算與手動項目異動；`closeSale()` 回填至已鎖月份會回 422 並要求聯絡管理員，已售車歸屬也會依 `sold_at` 月份防禦沒有 settlement item reference 的歷史缺口。draft 月份仍可成交或修正歸屬，之後重算納入。
- 薪資月份建立、重算、確認與手動加扣新增／刪除皆有 Audit Log；metadata 不保存薪資金額、加扣金額或說明內容。
- `SalaryPeriodService::pay()` 只允許啟用中的 admin 對 confirmed 月份發薪，會鎖定 period、啟用中的資金帳戶及所有 settlement；每位 `net_pay > 0` 員工各建立一筆直接 approved 的 `salary_settlement` MoneyEntry，零元員工不建立空支出。
- 整批發薪與 period 切換 paid 使用同一 transaction；任何一筆 MoneyEntry 失敗會完整 rollback。批次 key 以 `salary_periods.idempotency_key` 保護，相同 key／payload 可 replay，相同 key／不同 payload與同月份不同 key皆拒絕。
- duplicate-key race 會先離開並回滾原 transaction，再以新 transaction `lockForUpdate()` 讀取已提交 winner；真實 MariaDB 測試使用 fork/socket 讓兩個月份競爭同一 key，確認輸家不殘留 MoneyEntry 或 settlement linkage。
- 發薪完成後回填 `cash_account_id`、`payment_date`、`paid_by`、`paid_at` 與 settlement 的 `money_entry_id`；正式帳戶餘額及 Dashboard monthly expense 會因 approved 薪資支出立即更新。
- 資料庫 trigger 阻擋 paid period、settlement 與 settlement item 的後續新增／更新／刪除；既有 salary MoneyEntry 不可變規則持續保護正式薪資支出，歷史修正只能走後續月份手動調整或另建一般收支修正。
- 第 8 部分 adversarial review 修正 paid 歷史 re-parent 漏洞：settlement／item UPDATE trigger 同時檢查 `OLD` 與 `NEW` 所屬月份，不能從草稿搬入 paid 歷史，也不能從 paid 搬出；migration 每次 `up()` 先移除全部同名 trigger，可從 MySQL 部分 DDL 失敗狀態安全重跑。
- 發薪服務接受正整數或 canonical 整數字串形式的 `cash_account_id`，並集中正規化為 int；`PaySalaryPeriodRequest` 同步接受表單常見的整數字串、拒絕停用帳戶，非法型別回傳業務可讀中文訊息。
- `payment_date` 定義為款項實際支付日，必須介於結算月份第一天與今天之間；可延後至隔月或更後月份補發，但不可提前到結算月之前，也不可用尚未實際發生的未來日期建立 approved 支出。
- 第 11 部分 review 修正公司摘要來源：`company_reserve_total`／`company_remaining_total` 由 calculator 的整月 eligible vehicle 批次 totals 保存到 salary period，不再從「有員工 settlement 才建立」的 item 反推，因此收／賣車人都沒有 active salary profile 時仍不會漏算公司分配。
- 新增公司 totals migration 對升級前草稿保留 nullable，重算後寫入；若部署前已有 confirmed／paid 月份則在任何 DDL 前停止，要求人工評估，避免缺乏 durable provenance 的歷史數字被靜默回填為 0。此 migration 實質 forward-only：`down()` 只移除欄位；若 rollback 後又產生 confirmed／paid 月份，不能直接重新 `up()`，必須先人工處理歷史資料。
- 公司 totals 已納入 confirm 的草稿 snapshot signature；即使車輛歸屬人都沒有 active salary profile、完全不產生 bonus item，草稿後 approved 收支造成的公司分配漂移仍會以 422 要求先重算，不會確認 admin 尚未看過的數字。
- Dashboard 薪資卡依月份狀態顯示預估／已確認／實發，並區分尚未建立與載入失敗；薪資前端已拆分為可獨立審查的卡片、表單、明細、異常、發薪及 modal 元件。

後續仍待實作／驗收：

- 完整 manual smoke。

Website MVP 延後到 v1.3 完成或進入正式部署準備時再獨立規劃，不得混入薪資結算。

---

## 8. 給下一位 AI / 工程師的注意事項

1. 後端 Resource 遮蔽是正式安全邊界，不能只靠前端隱藏。
2. `canViewFinancials()` 不等於 sales 可看的銷售金額；成交價、開價、底價走 sales pricing 權限。
3. pending 不得計入正式餘額、成本、毛利、成交結案。
4. `vehicle_shortcut` / `vehicle_workflow` 現在也可能 pending，admin 必須可核准 / 駁回。
5. `legacy_unknown` 不可核准 / 駁回。
6. 不要放寬 sales 對成本、毛利、資金帳戶的可見性。
7. 不要破壞現有 idempotency replay-or-reject pattern。
8. v1.3 只依 `企劃書_v1.3.md`／`PLAN_v1.3.md` 實作，不得把完整 HR、官網或正式會計混入。
9. 薪資計算必須由後端純計算服務產生，前端只顯示結果，不可把正式公式放在 React。
10. 薪資資料初版只開放 admin，manager／sales 必須在 API、路由與 UI 全部 fail-closed。
