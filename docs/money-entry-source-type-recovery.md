# money_entries.source_type 誤回填恢復指引

## 背景

`2026_07_05_000001_backfill_money_entry_source_type.php` 曾經有一版不安全實作，
用 category、關聯車輛狀態（`status`、`sold_price`、`buyer_name`）等 heuristics，
自動把既有 `money_entries` 的 `source_type` 從 `manual` 改成 `vehicle_shortcut`
或 `vehicle_workflow`。

如果你的環境曾經跑過那個版本的 migration，可能有透過一般
`/api/money-entries` CRUD 建立、但剛好綁車且分類與流程資料重疊的合法
manual 收支，被永久誤判成 `vehicle_shortcut` / `vehicle_workflow`，導致這些
資料之後無法再透過一般 CRUD 修改或刪除。

該 migration 目前已改為 no-op，不會再對新環境造成影響。但**已經跑過舊版本
的環境，資料庫裡的誤判資料不會自動修正**，需要人工恢復。

## 為什麼不能自動恢復

既有資料在 `source_type` 欄位新增前，沒有任何 durable provenance marker：

- `idempotency_key` 只是前端 `crypto.randomUUID()` 或測試用 `Str::uuid()`，
  沒有 endpoint prefix，無法反推是哪個 API 建立的。
- `vehicle_id`、`category`、`vehicle.status`、`buyer_name`、`sold_price`
  都只是巧合特徵，合法 manual 收支也可能符合這些條件。

因此**不能**再寫一個批次 migration，用 category / status / buyer_name /
sold_price 反向自動判斷哪些資料要改回 `manual`——那只是用另一種不可靠的
heuristic 再誤傷一次資料。

正確做法是：人工依單筆收支的實際來源確認後，針對明確確認要恢復的一批
`id`，手動執行更新。

## 步驟

### 1. 查詢候選資料，交給人工比對

```sql
SELECT
    id,
    vehicle_id,
    direction,
    category,
    amount,
    counterparty_name,
    entry_date,
    source_type,
    created_at,
    updated_at
FROM money_entries
WHERE source_type IN ('vehicle_shortcut', 'vehicle_workflow')
ORDER BY vehicle_id, entry_date;
```

把結果交給熟悉當初資料建立情境的人員（例如財務或當初操作人員），逐筆確認
這筆收支實際上是不是透過一般收支 CRUD（而非車輛快捷/流程功能）建立的。

### 2. 只針對人工確認過的 ID 清單執行恢復

確認完成後，把確定要恢復成 `manual` 的 `id` 列成清單，執行：

```sql
UPDATE money_entries
SET source_type = 'manual'
WHERE id IN (101, 205, 309)  -- 替換成人工確認過的實際 ID 清單
  AND source_type IN ('vehicle_shortcut', 'vehicle_workflow');
```

不要省略 `id IN (...)` 條件、不要用 category / status / buyer_name /
sold_price 之類條件取代明確 ID 清單。

## 範圍限制

本文件只處理 `source_type` 誤回填的資料恢復，不涉及、也不得延伸出任何
會計、稅務、傳票、發票、審計事件等本專案禁止實作的功能。
