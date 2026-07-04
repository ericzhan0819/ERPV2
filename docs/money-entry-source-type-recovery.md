# money_entries.source_type 保護與恢復指引

## 背景

`2026_07_05_000001_backfill_money_entry_source_type.php` 曾經有一版不安全實作，
用 category、關聯車輛狀態（`status`、`sold_price`、`buyer_name`）等 heuristics，
自動把既有 `money_entries` 的 `source_type` 從 `manual` 改成 `vehicle_shortcut`
或 `vehicle_workflow`。該版本已移除，現在的 `2026_07_05_000001...` 是 no-op。

既有資料在 `source_type` 欄位新增前，沒有任何 durable provenance marker：

- `idempotency_key` 只是前端 `crypto.randomUUID()` 或測試用 `Str::uuid()`，
  沒有 endpoint prefix，無法反推是哪個 API 建立的。
- `vehicle_id`、`category`、`vehicle.status`、`buyer_name`、`sold_price`
  都只是巧合特徵，合法 manual 收支也可能符合這些條件。

因此**不能**用 category / status / buyer_name / sold_price 之類條件批次判斷
既有資料的真實來源——那只是用另一種不可靠的 heuristic 再誤傷一次資料。

## 現行保護機制

`2026_07_05_000002_quarantine_legacy_unknown_money_entry_source_type.php` 是
forward-only migration，執行 `php artisan migrate` 時會：

1. 建立 `money_entry_source_type_reviews` 表，記錄後續每一次人工確認變更
   （僅供本次資料修復使用，不是產品審計模組）。
2. 把 migration 執行當下所有 `source_type = manual` 且 `vehicle_id IS NOT NULL`
   的既有收支，保守 quarantine 成 `MoneyEntry::SOURCE_LEGACY_UNKNOWN`
   （`legacy_unknown`）。這只是「無法證明來源」的保護狀態，**不代表**
   workflow，也**不代表** manual。
   - `vehicle_id IS NULL` 的一般營運 manual 收支不受影響，仍是 manual。
   - 既有 `vehicle_shortcut` / `vehicle_workflow` 不受影響。

`legacy_unknown` 的收支無法透過一般 `/api/money-entries` CRUD 修改或刪除，
即使綁定的車輛不是 sold/cancelled，行為與 `vehicle_shortcut` /
`vehicle_workflow` 一樣受保護，直到人工確認完成。

如果你的環境曾經跑過舊版不安全 backfill migration，資料庫裡可能已經有
合法 manual 收支被誤判成 `vehicle_shortcut` / `vehicle_workflow`。這批資料
**不會**被 `2026_07_05_000002...` quarantine（因為它們已經不是 manual），
也**不可**用另一種批次 heuristic 反向覆蓋改回 manual，只能人工確認 ID 後
用下面的指令個別處理。

## Review candidate 快照（`2026_07_05_000003...`）

只看 `legacy_unknown` 不足以擋住上面這種情境：如果某個環境曾經跑過舊版
不安全 backfill，被誤標成 `vehicle_shortcut` / `vehicle_workflow` 的既有
manual 收支，不會被 `2026_07_05_000002...` quarantine 成 `legacy_unknown`，
gate 若只檢查 `legacy_unknown` 會誤判「沒有問題」而放行部署。

`2026_07_05_000003_capture_money_entry_source_type_review_candidates.php`
是 forward-only migration，會建立 `money_entry_source_type_review_candidates`
表，並在**這支 migration 執行的當下**，把以下既有收支拍照存進 candidate 表：

- 執行當下所有 `source_type = legacy_unknown` 的收支（理由標記為
  `legacy_vehicle_bound_manual_quarantine`）。
- 執行當下所有 `source_type IN ('vehicle_shortcut', 'vehicle_workflow')`
  的收支（理由標記為 `preexisting_protected_source_type_needs_review`），
  因為這批可能是舊版不安全 backfill 誤標的結果，也可能本來就正確，兩者都
  必須人工逐筆確認才能排除嫌疑。

candidate 只是**執行當下的快照**：migration 跑完之後，由
`MoneyEntryService::recordVehicleShortcut()`、
`VehicleService::reserveVehicle()` / `recordFinalPayment()` 正常建立的新
`vehicle_shortcut` / `vehicle_workflow` 收支，不會被追加進 candidate 表，
也就不會被 gate 檔住 —— gate 不會把「未來正常建立的流程收支」誤認為待
review 對象。

candidate 表不加 FK（`money_entry_id` 只是 `unsignedBigInteger` + unique
索引），避免日後合法刪除 money_entry 卡住操作。每筆 candidate 記錄
`captured_source_type`、`candidate_reason`、`money_entry_snapshot`，以及
`resolved_at` / `resolved_by` / `resolution_review_id`（解決時對應寫入的
`money_entry_source_type_reviews` id）。

## 恢復流程

### 1. 查詢候選資料，交給人工比對

```sql
-- 待確認的 legacy_unknown（本次 quarantine 造成的保護狀態）
SELECT id, vehicle_id, direction, category, amount, counterparty_name,
       entry_date, source_type, created_at, updated_at
FROM money_entries
WHERE source_type = 'legacy_unknown'
ORDER BY vehicle_id, entry_date;

-- 若環境曾跑過舊版不安全 migration，需人工複查是否有合法 manual 被誤判
SELECT id, vehicle_id, direction, category, amount, counterparty_name,
       entry_date, source_type, created_at, updated_at
FROM money_entries
WHERE source_type IN ('vehicle_shortcut', 'vehicle_workflow')
ORDER BY vehicle_id, entry_date;
```

把結果交給熟悉當初資料建立情境的人員（例如財務或當初操作人員），逐筆確認
這筆收支實際上的來源。

### 2. Dry-run 預覽

```bash
php artisan money-entries:source-type-review --ids=101,205,309 --to=manual --dry-run
```

Dry-run 只列出會被處理的 rows 與 before/after，不寫入資料庫、不產生
backup、不寫入 `money_entry_source_type_reviews`。

### 3. 人工確認後正式執行

```bash
php artisan money-entries:source-type-review \
  --ids=101,205,309 \
  --to=manual \
  --approver="王小美" \
  --reason="人工核對交易紀錄，確認為一般收支 CRUD 建立"
```

也可以用 `--file=storage/app/review-ids.txt` 提供 ID 清單（一行一個或以逗號
分隔），取代 `--ids`。

- `--to` 僅接受 `manual` / `vehicle_shortcut` / `vehicle_workflow` /
  `legacy_unknown`。
- 非 dry-run 時 `--approver`、`--reason` 必填，缺少會直接失敗（非 0 exit
  code），不會有任何資料異動。
- 任一 ID 不存在會整批失敗，不會部分更新。
- 執行前會先把即將變更的 rows 完整備份成 JSON，存於
  `storage/app/money-entry-source-type-backups/`。
- 每一筆實際變更都會寫入 `money_entry_source_type_reviews`，記錄
  `previous_source_type`、`new_source_type`、approver、reason、backup 路徑與
  該筆資料變更前的完整快照。
- 指令是 idempotent：同一批 ID 重跑到同一個 `--to`，已經是目標狀態、且沒有
  待解決 candidate 的 row 會標示 `skipped_noop`，不會報錯、也不會產生重複的
  backup/review 紀錄。
- 若某筆 ID 對應到一個尚未解決的 candidate、但目前 `source_type` 剛好已經
  等於 `--to`（例如人工核對後認定「目前的值就是對的」），指令仍會寫入一筆
  `previous_source_type === new_source_type` 的 review 紀錄，標記
  `action = confirmed_candidate_no_change`，並把該筆 candidate 標記為
  resolved（`resolved_at`/`resolved_by`/`resolution_review_id`）。這種情況
  不會產生 backup（因為沒有任何資料被修改）。重跑同一批到同一個 `--to`
  時，該 candidate 已 resolved，不會重複寫入 review 紀錄。
- 任一 ID 不存在會整批失敗，不會部分更新。
- 執行前，只針對「實際會被修改 `source_type` 的 rows」把即將變更的資料
  完整備份成 JSON，存於 `storage/app/money-entry-source-type-backups/`。
  備份寫入後會立即驗證 `Storage::disk('local')->put()` 回傳成功、檔案
  `exists()`、且可以 `get()` 讀回與寫入內容一致；任何一步驟失敗都會直接
  丟出例外，指令以非 0 exit code 失敗，**不會**修改 `money_entries`、
  不會寫入 review 紀錄，也不會留下指向不存在檔案的紀錄。
- 每一筆實際變更（或 candidate 確認）都會寫入
  `money_entry_source_type_reviews`，記錄 `previous_source_type`、
  `new_source_type`、approver、reason、backup 路徑（confirm-only 時為
  `null`）與該筆資料變更前的完整快照。

### 4. 部署 gate

```bash
php artisan money-entries:source-type-gate
```

gate 通過條件是**同時滿足**：

- 沒有任何 `source_type = legacy_unknown` 的收支。
- 沒有任何尚未 resolved 的 `money_entry_source_type_review_candidates`
  （包含 000003 執行當下拍下的 `legacy_unknown` 與
  `vehicle_shortcut` / `vehicle_workflow` candidate）。

只要其中一項不滿足，gate 就以非 0 exit code 失敗，列出待處理數量與樣本
（每筆樣本包含 `id`/`category`/`amount`/`vehicle_id`/`current_source_type`/
`captured_source_type`/`candidate_reason`），提示必須先用
`money-entries:source-type-review` 人工確認分類。建議放進部署流程，卡住
交付直到 gate 通過。

## Rollback 不刪除證據

`2026_07_05_000002...` 與 `2026_07_05_000003...` 的 `down()` 都刻意保持
no-op，不會 `dropIfExists` `money_entry_source_type_reviews` 或
`money_entry_source_type_review_candidates`。理由：這兩張表記錄的是人工
approver/reason/前後狀態/資料快照/解決紀錄，一旦 rollback 就會連帶銷毀已經
完成的人工審核證據，且無法復原。schema rollback（例如
`php artisan migrate:rollback`）不應該連帶刪除已產生的人工審核紀錄。

## Backup 失敗即中止

`money-entries:source-type-review` 的備份行為必須「寫入成功且可讀回」才
繼續：

1. 只有實際會被修改 `source_type` 的 rows 才需要備份。
2. 備份必須先寫入本地磁碟，再修改 `money_entries` 與寫入
   `money_entry_source_type_reviews`。
3. `Storage::disk('local')->put()` 回傳值不是 `true`、或寫入後
   `exists()` 為否、或 `get()` 讀回內容與寫入內容不一致，都會直接丟出
   例外，整個指令以非 0 exit code 失敗，資料庫完全不會被異動（因為備份
   在任何 DB 寫入之前完成，失敗時 transaction 內尚未發生任何 mutation）。

## 範圍限制

本文件與相關指令只處理 `source_type` 資料保護與恢復，不涉及、也不得延伸
出任何會計、稅務、傳票、發票、審計事件等本專案禁止實作的功能。
`money_entry_source_type_reviews` 僅是本次資料修復的安全記錄，不是正式
審計模組。
