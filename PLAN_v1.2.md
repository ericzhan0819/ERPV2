# PLAN_v1.2.md — ERPV2 v1.2 車輛圖片與官網前置基礎

本清單對應 `企劃書_v1.2.md`。v1.1 已完成 smoke 並封版，不回開 v1.1 範圍。

v1.2 目標：補上車輛圖片管理與公開車輛資料 API，讓後台可以管理車輛照片，並為未來官網 MVP 提供安全資料來源。

---

## 0. 前置準備

- [x] 閱讀 `企劃書.md`
- [x] 閱讀 `企劃書_v1.1.md`
- [x] 閱讀 `docs/current-state.md`
- [x] 閱讀 `docs/v1.1-smoke-report.md`
- [x] 閱讀 `企劃書_v1.2.md`
- [x] 閱讀本檔案 `PLAN_v1.2.md`
- [x] 確認目前 branch 為 `main`
- [x] 確認 `git status --short` 乾淨
- [x] 確認 `v1.1-smoke-passed` tag 存在

---

## 1. 資料模型：Vehicle Photo

### 1.1 Migration

新增資料表：`vehicle_photos`

必要欄位：

- [x] `id`
- [x] `vehicle_id`
- [x] `disk`，預設 `public`
- [x] `path`
- [x] `thumbnail_path`
- [x] `original_filename`
- [x] `mime_type`
- [x] `size`
- [x] `width`
- [x] `height`
- [x] `sort_order`
- [x] `is_cover`
- [x] `uploaded_by`
- [x] `created_at` / `updated_at`

### 1.2 Index / Constraint

- [x] index：`vehicle_id`
- [x] index：`vehicle_id, sort_order`
- [x] index：`vehicle_id, is_cover`
- [x] 同一台車最多只能有一張封面照
- [x] 照片操作必須限制在同一台車內（`VehiclePhotoService::assertBelongsToVehicle()` / `reorder()` 的同車輛比對）

### 1.3 Model / Relationship

- [x] 新增 `VehiclePhoto` model
- [x] `Vehicle::photos()` hasMany
- [x] `VehiclePhoto::vehicle()` belongsTo
- [x] `VehiclePhoto::uploadedBy()` belongsTo User
- [x] fillable / casts 明確設定

---

## 2. Storage / 圖片處理

### 2.1 Storage disk

- [x] v1.2 預設使用 Laravel `public` disk（`config/vehicle_photos.php` 的 `disk`，預設值 `public`）
- [x] 文件說明需執行 `php artisan storage:link`（見 `config/vehicle_photos.php` 註解；README/API.md 完整文件於第 6 節補齊）
- [x] DB 只記 `disk + path`，不記本機絕對路徑（`VehiclePhotoImageProcessor::process()` 回傳相對路徑）
- [x] Resource 只回傳可用 URL（`VehiclePhotoResource` / `PublicVehiclePhotoResource` 皆以 `Storage::disk($this->disk)->url(...)` 產生 `url` / `thumbnail_url`）
- [x] 架構預留未來搬到 Cloudflare R2（`disk` 可由 `VEHICLE_PHOTOS_DISK` env 覆蓋）

### 2.2 檔案命名

- [x] 使用 UUID / ULID 產生 storage filename（`Str::uuid()`）
- [x] 原始檔名只寫入 `original_filename`
- [x] path 格式統一，例如 `vehicles/{vehicle_id}/{uuid}.webp`
- [x] thumbnail path 格式統一，例如 `vehicles/{vehicle_id}/{uuid}_thumb.webp`

### 2.3 檔案驗證

- [x] 允許 `jpg` / `jpeg` / `png` / `webp`
- [x] 禁止 `svg` / `heic` / `pdf` / video / zip（副檔名 + 實際偵測 mime 雙重檢查）
- [x] 單張最大 8MB
- [x] 解碼前依像素數（`max_megapixels`）擋下超大尺寸圖片，避免 8MB 內但像素超大的檔案在 `ImageManager::read()` 全解碼時吃光記憶體（Codex adversarial review 第一輪發現，`getimagesize()` 讀檔頭 + 拒絕，不需完整解碼）。第二輪 review 指出 GD 像素緩衝區是原生記憶體配置、不受 PHP `memory_limit` 限制，原本 40MP 門檻仍會讓單張壓縮圖處理到 500MB+ RSS，門檻曾一度下修到 13MP；第三輪 review 又指出 13MP 連常見手機/相機的正常直出解析度都會擋，等於這個「設計來把大圖縮小存放」的功能反而拒絕大圖，門檻改回 24MP（實測 RSS 335.0MB）並開放 `VEHICLE_PHOTOS_MAX_MEGAPIXELS` env 調整；第四輪 review 指出「內部端點、使用者少所以不會同時上傳」只是假設，不是程式碼裡真的有的保證——多個 admin/manager 剛好同時上傳、各自都在門檻內卻疊加起來一樣可能把 worker 記憶體吃光。已在 `VehiclePhotoImageProcessor::process()` 用 `Cache::lock()` 全域 lock 把「同時間只解碼/編碼一張圖」變成強制保證（依賴 `CACHE_STORE=database` 既有的 atomic lock 支援），等不到 lock 就直接回錯誤而不讓 worker 卡住等待，`processing_lock_ttl_seconds` / `processing_lock_wait_seconds` 可用 env 調整；像素上限從此只需要負責擋「單一請求」的尖峰記憶體，不用再兼顧並發疊加。第五輪 review 又指出固定 TTL 的 lock 本身不可靠：如果主機當下負載高（甚至就是因為記憶體壓力）導致處理變慢、超過 TTL，過期的 lock row 會被別人合法搶走，兩個請求會同時以為自己獨佔、原本要防的並發疊加反而在「lock 機制內部」重新發生。已在 `decodeAndStore()` 的每個重量級步驟之間（decode 後、展示圖編碼後、縮圖編碼後）呼叫 `$lock->refresh()` 續約；只要處理沒有卡住就會持續延長 TTL，一旦 `refresh()` 回傳 false（代表真的過期、被搶走），立刻中止並丟例外，不會假裝仍擁有獨佔權繼續寫檔案。新增 `test_processing_aborts_if_lock_lease_is_lost_mid_flight` 直接驗證這個中止路徑。第六輪 review 指出續約機制有個結構性缺口：續約只能發生在步驟「之間」，單一個 decode / toWebp() 呼叫本身是不可中斷的 GD 原生函式呼叫，如果單一步驟本身就跑得比 TTL 還久，續約救不到。這是 lease-based lock 沒有 fencing token 就無法 100% 杜絕的已知理論限制，改用「TTL 遠大於實測單一步驟最長耗時」把風險壓低：`processing_lock_ttl_seconds` 改成依 `max_megapixels` 等比例換算（每 MP 5 秒、約 100 倍安全係數，並設 120 秒下限），這樣以後調高像素上限時 TTL 安全網會自動跟著放大，不會變成脫鉤的固定數字；並在 code / config 註解誠實記下這個殘餘風險與其成因，不再宣稱續約機制能完全杜絕過期。第七輪 review 又指出兩張圖都編碼成 webp bytes 後，`$source`/`$display`/`$thumbnail` 這幾個 GD 原生解碼緩衝區在進入 `putOrCleanup()` 的 storage I/O（可能是慢的，尤其未來搬到 R2 走網路）期間仍然存活，如果 I/O 卡住超過 lock TTL，一樣會回到「兩個請求同時持有原生 GD buffer」的並發疊加風險，等於前面幾輪加的保護在這個時間窗口失效。已在進入 I/O 前明確 `unset($source, $display, $thumbnail)`，用 `/proc/self/status` 的 VmRSS 實測（`memory_get_usage()` 量不到 GD 原生記憶體）：24MP 圖片 unset 前 RSS 約 335MB、unset 後降到約 138MB，I/O 期間少留著約 197MB。新增 `test_intervention_image_objects_are_actually_freed_when_unset` 用 `WeakReference` 驗證「unset 後 Intervention/Image 物件真的會被釋放」這個優化成立的前提，避免以後升級套件版本這個假設不知不覺失效
- [x] 一次上傳最多 20 張（`VehiclePhotoService::uploadPhotos()` + `StoreVehiclePhotoRequest` 皆有檢查；`VehiclePhotoTest::test_uploading_more_than_max_files_per_request_is_rejected_with_422` 覆蓋）
- [x] 每台車最多 60 張（`VehiclePhotoService::uploadPhotos()` 於 `lockForUpdate()` 下計數檢查；`VehiclePhotoTest::test_uploading_beyond_max_photos_per_vehicle_is_rejected_with_422` 覆蓋）
- [x] 錯誤訊息使用業務可讀中文

### 2.4 縮圖 / 展示圖

- [x] 至少產生縮圖 `thumbnail_path`
- [x] 主要展示圖避免直接使用過大的手機原圖（`display.max_width/max_height` 1920，超過才 `scaleDown`）
- [x] 公開展示圖需重新編碼，避免保留手機拍攝定位資訊（一律重新編碼為 webp，`toWebp(strip: true)`）
- [x] 若引入圖片處理套件，需在完成報告說明原因（見下方說明：新增 `intervention/image` v3，搭配現有 GD extension；`composer.json` 已加 `ext-gd` 為明確 platform requirement，避免環境缺 GD 時 `composer install` 靜默成功但上傳全部在 runtime 才失敗）

### 2.5 檔案一致性

- [x] DB 寫入失敗時要有檔案清理策略（`VehiclePhotoImageProcessor::delete()` 提供給 Service 在 DB insert 失敗時呼叫，屬第 3.1 節整合）
- [x] 檔案寫入失敗時不可留下 DB row（`putOrCleanup()` 寫檔案失敗時立即刪除已寫入的檔案並拋例外，不會有機會建立 DB row）
- [x] 刪除照片時同步處理 storage 檔案（`delete()` 對主圖與縮圖皆處理，且對缺檔 idempotent）
- [x] 檔案處理失敗時不可回傳假成功（驗證失敗拋 `ValidationException`，儲存失敗拋 `RuntimeException`，皆不吞例外）

---

## 3. 後端 Service / Policy / Request / Resource

### 3.1 Service

新增或擴充：

- [x] `VehiclePhotoService::listPhotos(Vehicle $vehicle)`
- [x] `VehiclePhotoService::uploadPhotos(Vehicle $vehicle, User $user, array $files)`
- [x] `VehiclePhotoService::deletePhoto(Vehicle $vehicle, VehiclePhoto $photo)`
- [x] `VehiclePhotoService::setCover(Vehicle $vehicle, VehiclePhoto $photo)`
- [x] `VehiclePhotoService::reorder(Vehicle $vehicle, array $photoIds)`

Service 必須處理：

- [x] 同車輛封面唯一性（`setCover()` 於同一 transaction 內先取消其他封面再設定；DB 層另有 `cover_slot` unique index 兜底）
- [x] 第一張照片自動成為封面（`uploadPhotos()` 依上傳前是否已有封面判斷）
- [x] 刪除封面後自動補封面（`deletePhoto()` 刪除後補下一張 sort_order 最小的照片）
- [x] sort_order 穩定更新（`uploadPhotos()` 依現有最大值遞增；`reorder()` 依陣列順序重寫）
- [x] 跨車輛 photo id 一律拒絕（`assertBelongsToVehicle()` 用於 delete/setCover；`reorder()` 用 `whereIn` 限定同車輛並比對數量）
- [x] storage 與 DB 一致性（DB insert 失敗時清理已寫入的檔案；delete 時 DB 與 storage 皆處理）

### 3.2 Policy / Middleware

權限（新增於既有 `VehiclePolicy`：`viewPhotos()` / `managePhotos()`，尚未接上 route middleware，屬第 4 節工作）：

- [x] admin / manager 可上傳（`VehiclePolicy::managePhotos()`）
- [x] admin / manager 可刪除（同上）
- [x] admin / manager 可排序（同上）
- [x] admin / manager 可設封面（同上）
- [x] sales 可讀內部照片（`VehiclePolicy::viewPhotos()` 對所有角色回傳 true）
- [x] 未登入不可讀內部照片 API（第 4 節已接上 `auth:sanctum` + `active` middleware）
- [x] public API 走獨立公開查詢與 Resource（第 4 節 `PublicVehicleController` 已完成查詢邏輯）

### 3.3 Form Request

- [x] `StoreVehiclePhotoRequest`
- [x] `ReorderVehiclePhotosRequest`
- [x] 驗證檔案格式 / 大小 / 數量
- [x] 驗證排序陣列 photo ids
- [x] 錯誤訊息中文化

### 3.4 Resource

新增：

- [x] `VehiclePhotoResource`
- [x] `PublicVehicleResource`
- [x] `PublicVehiclePhotoResource`

Resource 原則：

- [x] 回傳 `url` / `thumbnail_url`
- [x] 不回傳 storage 絕對路徑
- [x] public Resource 絕不共用內部 VehicleResource

---

## 4. API Routes

### 4.1 Internal routes

新增：

- [x] `GET /api/vehicles/{vehicle}/photos`
- [x] `POST /api/vehicles/{vehicle}/photos`
- [x] `PATCH /api/vehicles/{vehicle}/photos/reorder`
- [x] `PATCH /api/vehicles/{vehicle}/photos/{photo}/cover`
- [x] `DELETE /api/vehicles/{vehicle}/photos/{photo}`

要求：

- [x] 全部 internal routes 需 auth:sanctum（`routes/api.php` 全數放在 `auth:sanctum` + `active` 群組內）
- [x] upload / delete / reorder / cover 需 admin / manager（`can:managePhotos,vehicle`）
- [x] list 允許 admin / manager / sales（`can:viewPhotos,vehicle`）
- [x] route model binding 不可讓 photo 跨 vehicle 操作成功（`VehiclePhotoService::assertBelongsToVehicle()` 於 setCover/deletePhoto 內擋下，跨車輛回 422；已補臨時測試驗證後移除）

### 4.2 Public routes

新增：

- [x] `GET /api/public/vehicles`
- [x] `GET /api/public/vehicles/{vehicle}`

要求：

- [x] 不需登入（獨立於 `auth:sanctum` 群組之外，`Route::prefix('public')`）
- [x] 只回傳 `status=listed` 車輛（`Vehicle::query()->where('status', 'listed')`）
- [x] 不回傳 preparing / reserved / sold / cancelled（非 listed 一律回 404，不洩漏存在與否）
- [x] 不回傳 purchase_price / floor_price / sold_price（`PublicVehicleResource` 白名單欄位，第 3.4 節已完成）
- [x] 不回傳 buyer / seller / customer / money_entries / cost / gross_profit / cash_account（同上）
- [x] 實作 pagination，用 `page` / `per_page` 參數（`PublicIndexVehicleRequest` + `paginate()`）
- [x] CORS：`config/cors.php` 的 `paths` 已是 `api/*` 萬用字元，`api/public/*` 已涵蓋，不需額外設定

### 4.3 Public API 安全

- [x] 公開 API 錯誤回應應只回傳必要訊息，不洩漏內部細節
- [x] 例如 404 Not Found 時只回傳 `{"message":"Vehicle not found"}`，不回傳敏感的 database / SQL 訊息（`PublicVehicleController::show()` 刻意不用 implicit route model binding，自行查詢並丟出固定訊息的 `NotFoundHttpException`，避免預設 `ModelNotFoundException` 洩漏 model 類別名稱）
- [x] 禁止在公開 API 回傳任何 internal notes、approval_status、idempotency_key（`PublicVehicleResource` / `PublicVehiclePhotoResource` 皆為獨立白名單 Resource）
- [x] 匿名放大攻擊防護（Codex adversarial review 指出）：`GET /api/public/vehicles` 原本 eager load 每台車完整 photos（單車最多 60 張），`per_page=100` 時單一未登入請求可換取約 6000 筆照片序列化與對應 DB 讀取。已改用獨立的 `PublicVehicleListResource`，列表只回傳 `cover_photo`（eager load 時以 `where('is_cover', true)` 限定只撈封面），完整 `photos` 陣列只保留給 `show()` 詳情頁的 `PublicVehicleResource`；並在 `Route::prefix('public')` 群組加上 `throttle:60,1`（每 IP 每分鐘 60 次），超過回 429。

---

## 5. Frontend：車輛詳情頁照片管理

### 5.1 API client / types

- [x] 新增 `VehiclePhoto` 型別，整合至 `src/types/vehicle.ts`
- [x] 新增 `src/api/vehiclePhotos.ts`
- [x] 型別包含 `id` / `url` / `thumbnail_url` / `is_cover` / `sort_order` / `width` / `height` / `size`

### 5.2 VehicleDetail UI

新增區塊：`車輛照片`

- [x] 無照片空狀態
- [x] 縮圖 grid
- [x] 封面 badge
- [x] admin / manager 顯示上傳按鈕
- [x] admin / manager 顯示刪除 / 設封面 / 排序操作（排序以 ← / → 按鈕調整，未做拖曳排序，屬本階段最小可用實作）
- [x] sales 僅可查看，不顯示管理按鈕
- [x] 上傳中 loading 狀態
- [x] 上傳失敗錯誤提示
- [x] 刪除需確認

### 5.3 UI 原則

- [x] 遵守 `UI.md` 語意色彩 token
- [x] 不寫死 hex
- [x] dark mode 可讀（沿用既有語意 token，未寫死顏色）
- [x] mobile layout 可基本使用（grid 於窄螢幕降為 2 欄）
- [x] 圖片 grid 不破版

---

## 6. Public API 文件與官網前置

### 6.1 API 文件

- [x] `backend/API.md` 補上 Vehicle Photo internal endpoints（第 13 節）
- [x] `backend/API.md` 補上 Public Vehicles endpoints（第 14 節）
- [x] 清楚列出 public API 可回傳欄位
- [x] 清楚列出 public API 禁止回傳欄位

### 6.2 README / current-state / CLAUDE

- [x] `README.md` 補充 v1.2 方向（含 `storage:link` 步驟與 v1.2 manual smoke 項目 12/13）
- [x] `docs/current-state.md` 補充 v1.2 進行中或完成後狀態
- [x] `CLAUDE.md` 補充 v1.2 必讀文件與範圍（先前任務已完成）

### 6.3 官網不在本階段實作

- [x] 不建立完整官網前端
- [x] 不建立 CMS
- [x] 不建立 SEO 管理
- [x] 不建立預約試乘 / lead form
- [x] 不建立線上付款

---

## 7. Backend tests

### 7.1 VehiclePhotoTest

基礎功能與權限測試（單序列執行）：

- [x] admin 可上傳照片
- [x] manager 可上傳照片
- [x] sales 不可上傳照片
- [x] 未登入不可上傳照片
- [x] sales 可讀照片列表
- [x] 第一張照片自動成為封面
- [x] 第二張、第三張上傳不會成為封面
- [x] 手動設定封面會取消同車其他封面
- [x] 刪除封面會自動補封面
- [x] 可重新排序照片
- [x] 跨車輛 photo id reorder 被拒絕
- [x] 刪除照片會刪除 DB row 與 storage file
- [x] 檔案格式不符會 422
- [x] 檔案過大會 422
- [x] 超過每台車照片上限會 422

（`tests/Feature/VehiclePhotoTest.php`，16 tests / 50 assertions 通過）

### 7.2 PublicVehicleApiTest

- [x] public vehicles 只列出 listed 車輛
- [x] public vehicles 不列出 preparing / reserved / sold / cancelled
- [x] public vehicle detail 回傳照片與封面照
- [x] public API 不回傳 purchase_price
- [x] public API 不回傳 floor_price
- [x] public API 不回傳 sold_price
- [x] public API 不回傳 customer / buyer / seller
- [x] public API 不回傳 money_entries
- [x] public API 不回傳 gross_profit / cost summary / cash_account
- [x] public API 可未登入讀取

（`tests/Feature/PublicVehicleApiTest.php`，14 tests / 49 assertions 通過，另補 vin / license_plate / seller_name / seller_phone / buyer_name / buyer_phone / notes / sales_note / condition_note / lien_note 等 PII 與內部欄位遮蔽測試）

### 7.2.5 MySQL 並發測試（可選，v1.2.x 再補）

**v1.2 簡化版本說明**：

同車輛的 `is_cover=true` 唯一性由 database unique constraint 或 trigger 保護，簡單測試驗證即可。真實並發測試（參考 `VehicleCreateMysqlConcurrencyTest` 的 `pcntl_fork` 模式）可留到後續版本或實務碰到問題時補充。

### 7.3 Regression

- [x] `RoleAccessTest` 不被破壞
- [x] `VehicleWorkflowTest` 不被破壞
- [x] `MoneyEntryApprovalTest` 不被破壞
- [x] `VehicleMoneyShortcutTest` 不被破壞
- [x] full backend suite 通過或只有既有 MySQL-only tests skipped（封版前最終驗證：334 tests、1372 assertions、4 skipped，皆為既有 MySQL-only tests）

---

## 8. Frontend tests / build

- [x] TypeScript typecheck 通過：`npx tsc -b`
- [x] Production build 通過：`./node_modules/.bin/vite build`
- [x] VehicleDetail 無照片狀態可編譯
- [x] VehicleDetail 有照片狀態可編譯
- [x] 權限按鈕依 role 顯示/隱藏
- [x] API error path 有使用者可讀訊息

---

## 9. Manual smoke

以下項目已於本機 dev 環境（既有 `php artisan serve` + MySQL）完成 API 與瀏覽器 manual smoke。API 層級使用 curl + Sanctum token 驗證 admin／manager／sales 權限與 public API；瀏覽器層級由使用者實際操作照片上傳、封面、排序、刪除、重新整理持久化與 sales 唯讀畫面。完整紀錄見 `docs/v1.2-smoke-report.md`。

### Admin / Manager

- [x] 開啟車輛詳情頁可看到照片區塊（使用者於瀏覽器確認縮圖、封面 badge 與管理操作正常）
- [x] 可上傳多張照片（admin、manager 皆以 API 驗證成功）
- [x] 第一張自動為封面
- [x] 可設定另一張為封面
- [x] 可刪除照片
- [x] 刪除封面後自動補封面（依 sort_order 最小者遞補）
- [x] 可調整排序
- [x] 重新整理後排序與封面保持正確（重新 GET list 驗證持久化）

### Sales

- [x] 可看到車輛照片（GET list 回 200）
- [x] 看不到上傳按鈕（API 層級：上傳回 403）
- [x] 看不到刪除按鈕（API 層級：刪除回 403）
- [x] 看不到設封面與排序操作（API 層級：設封面回 403）
- [x] 仍看不到收購價、成本、毛利、資金帳戶（本項由 v1.1 `RoleAccessTest` 與本次 334-test regression suite 驗證；v1.2 瀏覽器 smoke 聚焦照片管理按鈕的 sales 唯讀狀態）

### Public API

- [x] 未登入可讀 `GET /api/public/vehicles`
- [x] 只看到已上架車輛（另建一筆 preparing 車輛驗證 detail 回 404、list 不列出，測試後已清除）
- [x] 回傳封面照與照片 URL
- [x] 不回傳底價 / 成交價 / 收購價 / 客戶 / 收支 / 毛利（逐欄位比對 JSON key，確認無洩漏）
- [x] 額外驗證：`throttle:60,1` 限流生效（第 61 次請求起回 429）

**重大發現與修復**：manual smoke 過程中發現車輛照片上傳在真實 MySQL 環境下 100% 失敗（`照片處理逾時，請重新上傳`），即使沒有任何並發。根因：Laravel `Cache::lock()` 搭配 `CACHE_STORE=database` 時，`DatabaseLock::refresh()` 用 UPDATE 影響列數 `>= 1` 判斷續約是否成功；MySQL PDO 預設「影響列數」只計算「值真的改變」的列，若 `refresh()` 剛好在 `acquire()` 的同一秒內呼叫（本案的 decode/encode 只需數十毫秒，幾乎必然落在同一秒），新舊 `expiration` 相同，MySQL 回報 0 rows affected，讓程式誤判鎖已遺失並中止上傳。測試環境用 `CACHE_STORE=array`（不受此限制）所以先前 `VehiclePhotoTest` 全數通過但沒踩到這個問題，直到這次對著真實 MySQL 走 manual smoke 才暴露。修復方式：在 `backend/config/database.php` 的 `mysql` / `mariadb` 連線 `options` 加上 `Mysql::ATTR_FOUND_ROWS => true`，讓 PDO 回報「WHERE 條件有 match 到的列數」而非「值有改變的列數」，這是這個 MySQL 特有行為的標準修法。修復後重新以 API 完整走過上傳／封面／刪除／排序流程皆正確；封版前最終 `php artisan test` 為 334 tests、1372 assertions、4 skipped（皆為既有 MySQL-only tests）。

---

## 10. 不做事項

v1.2 不做：

- [x] 完整官網前端
- [x] CMS
- [x] SEO 管理後台
- [x] 線上付款
- [x] 會員系統
- [x] 預約試乘流程
- [x] lead form / 名單管理
- [x] 貸款試算
- [x] 多語系
- [x] 圖片 AI 辨識
- [x] 浮水印系統
- [x] 複雜裁切器
- [x] 直接導入 Cloudflare R2 作為必做項（`disk` 保留 env 可調整，但未實作 R2 driver）
- [x] 通用附件系統
- [x] OCR
- [x] Sales 上傳照片（`VehiclePolicy::managePhotos()` 僅限 admin / manager，sales 上傳一律 403）

---

## 11. 完成定義

v1.2 視為完成，必須同時滿足：

- [x] 後台可以管理車輛照片
- [x] 每台車可有多張照片
- [x] 每台車有且只有一張封面照
- [x] 照片可排序
- [x] admin / manager 可管理照片
- [x] sales 可看照片但不可管理
- [x] public API 可安全輸出已上架車輛與照片
- [x] public API 不洩漏任何內部敏感資料
- [x] backend tests 通過
- [x] frontend typecheck / build 通過
- [x] 文件更新完成
- [x] 使用者完成 v1.2 manual smoke（照片上傳、封面、排序、刪除、刪除封面自動補封面、重新整理持久化、sales 唯讀與公開 API 均通過）

---

## 12. 建議 commit 拆分

建議拆成：

```text
feat: 新增車輛照片資料模型與後端管理 API
feat: 新增車輛詳情頁照片管理 UI
feat: 新增公開車輛資料 API
docs: 補齊 v1.2 車輛圖片與公開 API 文件
```

若實作需要新增圖片處理套件，建議獨立 commit：

```text
chore: 加入圖片縮圖處理套件
```
