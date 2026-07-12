# ERPV2 current-state — v1.2 已封版，v1.3 薪資結算規劃完成

日期：2026-07-12
專案：ERPV2 / 中古車行內部營運系統
目前穩定點：`b1edffa docs: 完成 v1.2 smoke 封版與交接文件`
目前 tag：`v1.1-smoke-passed`、`v1.2-smoke-passed`
狀態：v1.2 已完成並封版。v1.3「薪資結算」已完成 `企劃書_v1.3.md` 與 `PLAN_v1.3.md`，尚未開始程式實作。v1.3 目標是依正式成交、approved-only 單車毛利、收車人／賣車人、整月跨級獎金、底薪與勞健保扣款，自動算出每月實發薪資，確認發薪後建立正式薪資支出。

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

v1.2 封版前最終結果：334 tests、1372 assertions、4 skipped；frontend typecheck 與 production build 均通過。完整紀錄見 `docs/v1.2-smoke-report.md`。v1.2.x hotfix（車輛照片稽核追蹤，2026-07-12，含 partial upload resume/replay 遺漏補記修正）後最新結果：340 tests、1391 assertions、4 skipped。

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

目前實作前已確認的資料缺口：

- Vehicle 尚無正式 `purchase_agent_id`／`sales_agent_id`。
- 不得用 `created_by`／`updated_by` heuristic 推定歷史獎金歸屬。
- MoneyEntry 已有「薪資 / 佣金」分類，但尚無 `salary_settlement` source type、月份快照與防重複發薪機制。

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
