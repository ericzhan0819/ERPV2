# Customer Workflow Hotfix

## 背景

v1.1 Customer Module 原本要求使用者先選「關聯客戶」，再重複填寫姓名與電話；未選客戶時，車輛只保存快照，不會自動建立 Customer。使用者實際 Smoke 後決定改為單一姓名搜尋流程，並要求新買方／賣方能自動建立與關聯。

本修正是 v1.1 Customer Module 的獨立 hotfix，不屬於 v1.3 薪資功能。

## 最終行為

### 買入建車

- 姓名欄即時搜尋既有客戶。
- 選取既有客戶後自動帶入電話。
- 未選取搜尋結果時，視為自由輸入的新賣方。
- 建車 transaction 內自動建立或重用 Customer，並回填 `seller_customer_id`。

### 收訂金／保留

- 買方姓名欄採相同搜尋流程。
- 未選取既有客戶時，自動建立或重用買方 Customer，並回填 `buyer_customer_id`。

### 去重規則

```text
normalized_name  = trim + 合併連續空白 + lowercase
normalized_phone = 移除非數字字元
```

只有兩者都相同時才視為同一客戶。沒有電話時 `normalized_phone=NULL`，同名資料仍可分開建立，避免誤合併不同本人。

資料庫新增 `customers_normalized_identity_unique` 複合 unique。建車或保留遇到 concurrent duplicate-key 時，整筆交易 rollback，再於新 transaction 重讀已提交的 winner 客戶後完成原本流程。

## Migration 邊界

migration 會：

1. 可重跑地新增正規化欄位。
2. 回填既有客戶。
3. 檢查既有相同正規化姓名＋電話的重複資料。
4. 若有重複，停止 migration，要求人工確認，不自動推測或合併。
5. 無重複才建立 unique index。

## Update 行為

一般車輛 PATCH 不會因修改自由文字賣方而自動建立 Customer。這是刻意的歷史資料保護：一般編輯可能只是修正錯字，不應暗中建立新客戶。需要更換關聯時，應透過明確選取客戶的操作。

## 驗證紀錄

- Customer、Vehicle、Vehicle Workflow、Salary Workflow targeted suite：115 passed、1 environment-gated skipped、791 assertions。
- Customer identity schema：2 passed、7 assertions；欄位、nullability、unique index 與 migration rerun 均通過。
- MariaDB 10.11.18 專用可拋棄 schema：4 passed、55 assertions：
  - 不同建車請求同時輸入同一新賣方，只建立一位 Customer。
  - 不同保留請求同時輸入同一新買方，只建立一位 Customer。
  - 既有建車 idempotency loser replay 與庫存編號並發案例同步通過。
- Backend full suite：485 passed、14 environment-gated skipped、2293 assertions。上述 4 個 MariaDB 案例在專用 schema 已另行實跑，因此一般 SQLite full suite 的 environment-gated skip 不代表未驗證。
- Frontend lint／typecheck／production build：PASS；lint 只有 2 個既有 Fast Refresh warnings。
- Pint：PASS。
- 開發 MySQL migration 已增量套用；既有 2 位客戶無重複並完整保留，未執行 `migrate:fresh`。
- 使用者 Browser Smoke（2026-07-18）：買入與保留表單的既有客戶搜尋帶入、新客戶姓名／電話自由輸入、自動建立與欄位去重均通過。

狀態：功能、自動測試、真實 MariaDB 競態測試與使用者 Browser Smoke 全部通過；待建立獨立 Customer hotfix commit。
