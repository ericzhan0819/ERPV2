# ERPV2 current-state — v1.1 smoke passed

日期：2026-07-08
專案：ERPV2 / 中古車行內部營運系統
目前穩定點：`e05cf8d fix: 核准訂金/尾款/退款前檢查車輛是否已結案，防止事後核准打破已關帳不變量`
狀態：`main` 與 `origin/main` 同步，工作樹乾淨

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

最新驗證結果見 `docs/v1.1-smoke-report.md`。

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
- 複雜薪資 / 佣金模組

目前已知技術限制：

- 全站表單仍多為 submit-time validation，尚未統一改成 on-blur per-field validation
- 列印頁已由測試覆蓋資料結構，但仍建議在正式使用前肉眼檢查紙本排版
- MySQL/MariaDB 真並發測試需要專用資料庫環境；一般 SQLite/PHPUnit full suite 會 skip 4 個 MySQL-only tests

---

## 7. 下一階段建議

不要立即開 v1.2 大功能。建議順序：

1. 打 tag：`v1.1-smoke-passed`
2. 用真實車行流程試跑 3–7 天
3. 收集摩擦點
4. 開 v1.1.1 小修

v1.1.1 優先修：

- 文案
- 按鈕位置
- 表單預設值
- 欄位命名
- 列表欄位
- 篩選條件
- 空狀態
- 錯誤提示
- 手機畫面

v1.2 候選功能等試跑後再排：

- 待審核收支工作台
- 手機版操作優化
- 憑證附件 / 收據照片
- 月報 / 營運摘要
- 庫存週轉 / 在庫天數
- 記帳士匯出包

---

## 8. 給下一位 AI / 工程師的注意事項

1. 後端 Resource 遮蔽是正式安全邊界，不能只靠前端隱藏。
2. `canViewFinancials()` 不等於 sales 可看的銷售金額；成交價、開價、底價走 sales pricing 權限。
3. pending 不得計入正式餘額、成本、毛利、成交結案。
4. `vehicle_shortcut` / `vehicle_workflow` 現在也可能 pending，admin 必須可核准 / 駁回。
5. `legacy_unknown` 不可核准 / 駁回。
6. 不要放寬 sales 對成本、毛利、資金帳戶的可見性。
7. 不要破壞現有 idempotency replay-or-reject pattern。
8. 大功能請另開 v1.2 計畫，不要把 v1.1 封版文件改成需求發散清單。
