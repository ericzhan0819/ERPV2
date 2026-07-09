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
- [ ] 照片操作必須限制在同一台車內

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
- [ ] Resource 只回傳可用 URL（`VehiclePhotoResource` 屬於第 3.4 節，尚未建立）
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
- [ ] 一次上傳最多 20 張（需在 Service/Request 層依單次請求檔案數檢查，屬第 3.1 / 3.3 節）
- [ ] 每台車最多 60 張（需查詢該車既有照片數，屬第 3.1 節 Service）
- [x] 錯誤訊息使用業務可讀中文

### 2.4 縮圖 / 展示圖

- [x] 至少產生縮圖 `thumbnail_path`
- [x] 主要展示圖避免直接使用過大的手機原圖（`display.max_width/max_height` 1920，超過才 `scaleDown`）
- [x] 公開展示圖需重新編碼，避免保留手機拍攝定位資訊（一律重新編碼為 webp，`toWebp(strip: true)`）
- [x] 若引入圖片處理套件，需在完成報告說明原因（見下方說明：新增 `intervention/image` v3，搭配現有 GD extension）

### 2.5 檔案一致性

- [x] DB 寫入失敗時要有檔案清理策略（`VehiclePhotoImageProcessor::delete()` 提供給 Service 在 DB insert 失敗時呼叫，屬第 3.1 節整合）
- [x] 檔案寫入失敗時不可留下 DB row（`putOrCleanup()` 寫檔案失敗時立即刪除已寫入的檔案並拋例外，不會有機會建立 DB row）
- [x] 刪除照片時同步處理 storage 檔案（`delete()` 對主圖與縮圖皆處理，且對缺檔 idempotent）
- [x] 檔案處理失敗時不可回傳假成功（驗證失敗拋 `ValidationException`，儲存失敗拋 `RuntimeException`，皆不吞例外）

---

## 3. 後端 Service / Policy / Request / Resource

### 3.1 Service

新增或擴充：

- [ ] `VehiclePhotoService::listPhotos(Vehicle $vehicle)`
- [ ] `VehiclePhotoService::uploadPhotos(Vehicle $vehicle, User $user, array $files)`
- [ ] `VehiclePhotoService::deletePhoto(Vehicle $vehicle, VehiclePhoto $photo)`
- [ ] `VehiclePhotoService::setCover(Vehicle $vehicle, VehiclePhoto $photo)`
- [ ] `VehiclePhotoService::reorder(Vehicle $vehicle, array $photoIds)`

Service 必須處理：

- [ ] 同車輛封面唯一性
- [ ] 第一張照片自動成為封面
- [ ] 刪除封面後自動補封面
- [ ] sort_order 穩定更新
- [ ] 跨車輛 photo id 一律拒絕
- [ ] storage 與 DB 一致性

### 3.2 Policy / Middleware

權限：

- [ ] admin / manager 可上傳
- [ ] admin / manager 可刪除
- [ ] admin / manager 可排序
- [ ] admin / manager 可設封面
- [ ] sales 可讀內部照片
- [ ] 未登入不可讀內部照片 API
- [ ] public API 走獨立公開查詢與 Resource

### 3.3 Form Request

- [ ] `StoreVehiclePhotoRequest`
- [ ] `ReorderVehiclePhotosRequest`
- [ ] 驗證檔案格式 / 大小 / 數量
- [ ] 驗證排序陣列 photo ids
- [ ] 錯誤訊息中文化

### 3.4 Resource

新增：

- [ ] `VehiclePhotoResource`
- [ ] `PublicVehicleResource`
- [ ] `PublicVehiclePhotoResource`

Resource 原則：

- [ ] 回傳 `url` / `thumbnail_url`
- [ ] 不回傳 storage 絕對路徑
- [ ] public Resource 絕不共用內部 VehicleResource

---

## 4. API Routes

### 4.1 Internal routes

新增：

- [ ] `GET /api/vehicles/{vehicle}/photos`
- [ ] `POST /api/vehicles/{vehicle}/photos`
- [ ] `PATCH /api/vehicles/{vehicle}/photos/reorder`
- [ ] `PATCH /api/vehicles/{vehicle}/photos/{photo}/cover`
- [ ] `DELETE /api/vehicles/{vehicle}/photos/{photo}`

要求：

- [ ] 全部 internal routes 需 auth:sanctum
- [ ] upload / delete / reorder / cover 需 admin / manager
- [ ] list 允許 admin / manager / sales
- [ ] route model binding 不可讓 photo 跨 vehicle 操作成功

### 4.2 Public routes

新增：

- [ ] `GET /api/public/vehicles`
- [ ] `GET /api/public/vehicles/{vehicle}`

要求：

- [ ] 不需登入
- [ ] 只回傳 `status=listed` 車輛
- [ ] 不回傳 preparing / reserved / sold / cancelled
- [ ] 不回傳 purchase_price / floor_price / sold_price
- [ ] 不回傳 buyer / seller / customer / money_entries / cost / gross_profit / cash_account
- [ ] 實作 pagination，建議用 `page` / `per_page` 參數（例如 `?page=1&per_page=20`）
- [ ] 若未來官網與後台不同 domain，需確認 CORS 設定

### 4.3 Public API 安全

- [ ] 公開 API 錯誤回應應只回傳必要訊息，不洩漏內部細節
- [ ] 例如 404 Not Found 時只回傳 `{"message":"Vehicle not found"}`，不回傳敏感的 database / SQL 訊息
- [ ] 禁止在公開 API 回傳任何 internal notes、approval_status、idempotency_key

---

## 5. Frontend：車輛詳情頁照片管理

### 5.1 API client / types

- [ ] 新增 `src/types/vehiclePhoto.ts` 或整合至 `vehicle.ts`
- [ ] 新增 `src/api/vehiclePhotos.ts`
- [ ] 型別包含 `id` / `url` / `thumbnail_url` / `is_cover` / `sort_order` / `width` / `height` / `size`

### 5.2 VehicleDetail UI

新增區塊：`車輛照片`

- [ ] 無照片空狀態
- [ ] 縮圖 grid
- [ ] 封面 badge
- [ ] admin / manager 顯示上傳按鈕
- [ ] admin / manager 顯示刪除 / 設封面 / 排序操作
- [ ] sales 僅可查看，不顯示管理按鈕
- [ ] 上傳中 loading 狀態
- [ ] 上傳失敗錯誤提示
- [ ] 刪除需確認

### 5.3 UI 原則

- [ ] 遵守 `UI.md` 語意色彩 token
- [ ] 不寫死 hex
- [ ] dark mode 可讀
- [ ] mobile layout 可基本使用
- [ ] 圖片 grid 不破版

---

## 6. Public API 文件與官網前置

### 6.1 API 文件

- [ ] `backend/API.md` 補上 Vehicle Photo internal endpoints
- [ ] `backend/API.md` 補上 Public Vehicles endpoints
- [ ] 清楚列出 public API 可回傳欄位
- [ ] 清楚列出 public API 禁止回傳欄位

### 6.2 README / current-state / CLAUDE

- [ ] `README.md` 補充 v1.2 方向
- [ ] `docs/current-state.md` 補充 v1.2 進行中或完成後狀態
- [ ] `CLAUDE.md` 補充 v1.2 必讀文件與範圍

### 6.3 官網不在本階段實作

- [ ] 不建立完整官網前端
- [ ] 不建立 CMS
- [ ] 不建立 SEO 管理
- [ ] 不建立預約試乘 / lead form
- [ ] 不建立線上付款

---

## 7. Backend tests

### 7.1 VehiclePhotoTest

基礎功能與權限測試（單序列執行）：

- [ ] admin 可上傳照片
- [ ] manager 可上傳照片
- [ ] sales 不可上傳照片
- [ ] 未登入不可上傳照片
- [ ] sales 可讀照片列表
- [ ] 第一張照片自動成為封面
- [ ] 第二張、第三張上傳不會成為封面
- [ ] 手動設定封面會取消同車其他封面
- [ ] 刪除封面會自動補封面
- [ ] 可重新排序照片
- [ ] 跨車輛 photo id reorder 被拒絕
- [ ] 刪除照片會刪除 DB row 與 storage file
- [ ] 檔案格式不符會 422
- [ ] 檔案過大會 422
- [ ] 超過每台車照片上限會 422

### 7.2 PublicVehicleApiTest

- [ ] public vehicles 只列出 listed 車輛
- [ ] public vehicles 不列出 preparing / reserved / sold / cancelled
- [ ] public vehicle detail 回傳照片與封面照
- [ ] public API 不回傳 purchase_price
- [ ] public API 不回傳 floor_price
- [ ] public API 不回傳 sold_price
- [ ] public API 不回傳 customer / buyer / seller
- [ ] public API 不回傳 money_entries
- [ ] public API 不回傳 gross_profit / cost summary / cash_account
- [ ] public API 可未登入讀取

### 7.2.5 MySQL 並發測試（可選，v1.2.x 再補）

**v1.2 簡化版本說明**：

同車輛的 `is_cover=true` 唯一性由 database unique constraint 或 trigger 保護，簡單測試驗證即可。真實並發測試（參考 `VehicleCreateMysqlConcurrencyTest` 的 `pcntl_fork` 模式）可留到後續版本或實務碰到問題時補充。

### 7.3 Regression

- [ ] `RoleAccessTest` 不被破壞
- [ ] `VehicleWorkflowTest` 不被破壞
- [ ] `MoneyEntryApprovalTest` 不被破壞
- [ ] `VehicleMoneyShortcutTest` 不被破壞
- [ ] full backend suite 通過或只有既有 MySQL-only tests skipped

---

## 8. Frontend tests / build

- [ ] TypeScript typecheck 通過：`npx tsc -b`
- [ ] Production build 通過：`./node_modules/.bin/vite build`
- [ ] VehicleDetail 無照片狀態可編譯
- [ ] VehicleDetail 有照片狀態可編譯
- [ ] 權限按鈕依 role 顯示/隱藏
- [ ] API error path 有使用者可讀訊息

---

## 9. Manual smoke

### Admin / Manager

- [ ] 開啟車輛詳情頁可看到照片區塊
- [ ] 可上傳多張照片
- [ ] 第一張自動為封面
- [ ] 可設定另一張為封面
- [ ] 可刪除照片
- [ ] 刪除封面後自動補封面
- [ ] 可調整排序
- [ ] 重新整理後排序與封面保持正確

### Sales

- [ ] 可看到車輛照片
- [ ] 看不到上傳按鈕
- [ ] 看不到刪除按鈕
- [ ] 看不到設封面與排序操作
- [ ] 仍看不到收購價、成本、毛利、資金帳戶

### Public API

- [ ] 未登入可讀 `GET /api/public/vehicles`
- [ ] 只看到已上架車輛
- [ ] 回傳封面照與照片 URL
- [ ] 不回傳底價 / 成交價 / 收購價 / 客戶 / 收支 / 毛利

---

## 10. 不做事項

v1.2 不做：

- [ ] 完整官網前端
- [ ] CMS
- [ ] SEO 管理後台
- [ ] 線上付款
- [ ] 會員系統
- [ ] 預約試乘流程
- [ ] lead form / 名單管理
- [ ] 貸款試算
- [ ] 多語系
- [ ] 圖片 AI 辨識
- [ ] 浮水印系統
- [ ] 複雜裁切器
- [ ] 直接導入 Cloudflare R2 作為必做項
- [ ] 通用附件系統
- [ ] OCR
- [ ] Sales 上傳照片（暫不開放，避免初期資料治理混亂；未來若需要，建議設計為待審核模式再開放）

---

## 11. 完成定義

v1.2 視為完成，必須同時滿足：

- [ ] 後台可以管理車輛照片
- [ ] 每台車可有多張照片
- [ ] 每台車有且只有一張封面照
- [ ] 照片可排序
- [ ] admin / manager 可管理照片
- [ ] sales 可看照片但不可管理
- [ ] public API 可安全輸出已上架車輛與照片
- [ ] public API 不洩漏任何內部敏感資料
- [ ] backend tests 通過
- [ ] frontend typecheck / build 通過
- [ ] 文件更新完成
- [ ] 使用者完成 v1.2 manual smoke

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
