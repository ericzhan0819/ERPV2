# 中古車行內部營運系統 1.0

小型中古車行內部使用的前後端分離營運管理系統。

## 環境需求

- PHP 8.3 以上（含 Composer 2）
- Node.js 20 以上（含 npm）
- MySQL 8 或 MariaDB 10.11 以上（可用專案內 `docker-compose.yml` 啟動）
- （選用）Docker / Docker Compose，用來啟動開發用資料庫

## 安裝步驟

1. 啟動資料庫（若本機沒有現成的 MySQL/MariaDB）：

   ```bash
   docker compose up -d
   ```

2. 後端：

   ```bash
   cd backend
   composer install
   cp .env.example .env
   php artisan key:generate
   ```

   依實際環境調整 `.env` 內的 `DB_*`、`FRONTEND_URL`、`SANCTUM_STATEFUL_DOMAINS`（預設值已對應 `docker-compose.yml`）。

3. 前端：

   ```bash
   cd frontend
   npm install
   cp .env.example .env.local
   ```

   `VITE_API_BASE_URL` 依實際連線情境設定，詳見下方〈常見問題〉。

## 後端啟動方式

```bash
cd backend
php artisan serve
```

預設監聽 `http://localhost:8000`。

## 前端啟動方式

```bash
cd frontend
npm run dev
```

預設監聽 `http://localhost:5173`。

## 資料庫 Migrate / Seed

```bash
cd backend
php artisan migrate
php artisan db:seed
```

`db:seed` 會執行：

- `AdminUserSeeder`：建立預設管理員帳號
- `CashAccountSeeder`：建立預設資金帳戶（現金／主要銀行／其他，期初餘額皆為 0）

若要重建資料庫（開發環境）：

```bash
php artisan migrate:fresh --seed
```

## 預設帳號

| 帳號 | 密碼 | 權限 |
|---|---|---|
| admin@example.com | password | 管理員 |

**僅限開發環境使用**，正式上線前務必修改密碼（可透過使用者管理頁的「重設密碼」功能）。

## 測試方式

後端自動化測試（PHPUnit，涵蓋登入/登出、車輛、車輛流程、收支、資金帳戶、使用者、列印等模組）：

```bash
cd backend
php artisan test
```

手動驗證（依 `PLAN.md` / `企劃書.md` 第 19 章逐項確認）：

1. `php artisan serve` 啟動後端、`npm run dev` 啟動前端。
2. 用預設管理員帳號登入，確認未登入無法進入後台頁面。
3. 停用一個測試帳號後，確認該帳號無法登入。
4. 新增一台車輛 → 整備完成上架 → 收訂金保留 → 收尾款 → 成交結案，確認狀態依序轉換，且不符目前狀態的操作按鈕不會顯示。
5. 新增一筆一般收入/支出，確認不影響任一車輛的單車毛利。
6. 於資金帳戶頁確認現金／銀行／其他帳戶餘額 = 期初餘額 + 收入 - 支出。
7. 開啟車輛建檔資料列印、成交結案收支明細列印頁面，確認資料正確且可列印。

## 常見問題

### 開發環境如何設定前端 API 位址（`VITE_API_BASE_URL`）

前端程式碼是在「使用者的瀏覽器」裡執行，不是在開發機上執行。因此 `VITE_API_BASE_URL`
要填的是「瀏覽器所在的那台電腦」能夠連到的位址，而不是開發機自己的區網 IP。

`frontend/.env.example` 保留安全預設值 `VITE_API_BASE_URL=http://localhost:8000`，適用於
前端與後端在同一台機器上開發的情境。若瀏覽器與後端不在同一台機器（例如透過區網或其他
方式連線），請依實際網路環境自行建立 `frontend/.env.local`（此檔已被 `.gitignore`
排除，不會進 repo）覆蓋此設定，不要修改 `.env.example` 或把個人環境設定 commit 進 repo。

修改 `.env` / `.env.local` 後，必須重新啟動 `npm run dev`，Vite 才會讀到新的環境變數。

### 後端 CORS / Sanctum 設定

`backend/.env.example` 預設：

```
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173
SESSION_DOMAIN=null
```

`backend/config/cors.php` 的 `allowed_origins` 是用
`explode(',', env('FRONTEND_URL', ...))` 產生，若需要同時允許多個前端來源（例如
LAN 與 tunnel 兩種網址），可在 `.env` 的 `FRONTEND_URL` 用逗號分隔多個網址即可，
不需要修改 `config/cors.php` 邏輯。因為 `supports_credentials=true`，
`allowed_origins` 不可設為 `*`。
