# Customer Workflow Hotfix PLAN

## 身份與版本邊界

本文件是 v1.1 Customer Module 的獨立 hotfix，不屬於 v1.3 薪資結算功能，也不擴張為新的 CRM 版本。

目的：消除買入／保留表單重複輸入客戶資料的問題，並確保自由文字建立客戶時，在一般請求與真實 MySQL／MariaDB 併發下都不會產生可避免的重複客戶。

## 範圍

### 1. 單一姓名搜尋流程

- 買入建車與收訂金表單只顯示一個姓名搜尋欄位及一個電話欄位。
- 輸入姓名時即時搜尋既有客戶。
- 選取既有客戶後帶入電話並鎖定姓名／電話快照來源。
- 未選取既有客戶時，姓名與電話視為新客戶資料。

### 2. 自動建立與關聯

- 建車自由輸入賣方時，自動建立或重用賣方客戶並回填 `seller_customer_id`。
- 保留自由輸入買方時，自動建立或重用買方客戶並回填 `buyer_customer_id`。
- 同一客戶先後扮演買方與賣方時，`customer_type` 升級為 `both`。
- 車輛仍保存交易當下姓名／電話快照，客戶日後編輯不回寫歷史車輛。

### 3. 去重與併發契約

- 重用條件：正規化姓名與正規化電話都相同。
- 姓名會 trim、合併連續空白並轉小寫；電話移除非數字字元。
- 沒有電話時不自動合併同名客戶。
- 資料庫以 `(normalized_name, normalized_phone)` 複合 unique 作最終防線。
- 兩個不同 idempotency key 同時建立同一新客戶時，unique loser 必須 rollback，於新 transaction 重讀 winner 後完成原本的建車／保留流程。

### 4. 一般 Customer CRUD

- 客戶模組直接新增或修改成既有相同姓名＋電話時回 422，不回傳原始資料庫錯誤。
- 正規化欄位只供內部比對，不出現在 Customer API JSON。

## 刻意不做

- 不自動回填或推測既有歷史車輛的 customer_id。
- 不自動合併 migration 前已存在的重複客戶；migration 發現重複時停止並要求人工確認。
- 一般車輛 PATCH 修改自由文字賣方時不自動建立客戶。PATCH 是歷史資料修正，不應因修正錯字而暗中建立新客戶；需要更換關聯時應明確選取既有客戶。
- 不新增客戶標籤、行銷、提醒、群發或完整 CRM 功能。

## 驗收條件

- [x] SQLite 一般流程與 schema tests 通過。
- [x] 真實 MySQL／MariaDB 雙連線測試證明：並發建車共用一位新賣方、並發保留共用一位新買方。
- [x] 前端 typecheck、lint、production build 通過。
- [x] 人工 Smoke：買入與保留表單皆完成既有客戶搜尋帶入、新客戶自由輸入建立，且沒有重複欄位。

驗收日期：2026-07-18。狀態：功能、自動測試、真實 MariaDB 競態測試與使用者 Browser Smoke 全部通過，待建立獨立 hotfix commit。

## Commit 邊界

本 hotfix 使用獨立 commit，不與 v1.3 薪資封板文件混在同一 commit。
