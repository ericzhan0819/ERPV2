# 中古車行內部營運系統（1.0 + v1.1 + v1.2 planning）

小型中古車行內部使用的前後端分離營運管理系統。v1.1 新增角色（`admin`／`manager`／`sales`）、敏感金額遮蔽、一般收支審核、客戶模組與建車入庫欄位補強，另依使用者需求追加管理員稽核紀錄。v1.1 已完成 smoke，封版狀態詳見 `docs/current-state.md`、`docs/v1.1-smoke-report.md`。v1.2 已新增車輛圖片與官網前置規劃，詳見 `企劃書_v1.2.md`、`PLAN_v1.2.md`。API 與既有計畫細節見 `backend/API.md`、`PLAN_v1.1.md`。

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

| 帳號 | 密碼 | 角色 |
|---|---|---|
| admin@example.com | password | admin |

**僅限開發環境使用**，正式上線前務必修改密碼（可透過使用者管理頁的「重設密碼」功能）。

`AdminUserSeeder` 只建立 `admin` 帳號；若要手動驗證 `manager`／`sales` 的權限遮蔽與收支審核流程，請以此管理員帳號登入後，於使用者管理頁另外新增 `manager`／`sales` 測試帳號。

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
6. 於資金帳戶頁確認現金／銀行／其他帳戶餘額 = 期初餘額 + approved 收入 - approved 支出。
7. 開啟車輛建檔資料列印、成交結案收支明細列印頁面，確認資料正確且可列印。
8. （v1.1）以 `sales` 帳號登入，確認收購價、購車付款、完整整備成本、單車毛利、資金帳戶餘額、完整收支金額等敏感欄位在畫面與後端 JSON 皆不可見；但開價（`asking_price`）、底價（`floor_price`）、成交價（`sold_price`）為業務議價與收款追蹤依據，`sales` 應可正常看到，車輛詳情頁應顯示「銷售收款摘要」而非管理用單車收支摘要。
9. （v1.1）以 `manager`／`sales` 帳號新增一般收支或車輛快捷收支（含訂金、尾款、整備支出），確認一律進入待審核（`pending`），不計入正式餘額；再以 `admin` 核准/駁回，確認核准後才計入正式彙總，且核准/駁回後不可改回待審核；`manager` 即使可看完整營運金額也不可呼叫核准/駁回。
9a. （v1.1）以 `sales` 帳號收訂金/尾款後，在 admin 核准前嘗試成交結案應回 422；admin 核准足額收款後才可成交結案。
10. （v1.1）新增客戶並與車輛建立買方/賣方關聯，確認客戶詳情可看到關聯車輛；刪除仍有關聯車輛的客戶應被拒絕。
11. 以 admin 開啟「稽核紀錄」，確認登入、資料新增／修改／刪除皆有紀錄；manager／sales 不可進入該頁或呼叫稽核 API。

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
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1,127.0.0.1:5173
SESSION_DOMAIN=null
```

`backend/config/cors.php` 的 `allowed_origins` 是用
`explode(',', env('FRONTEND_URL', ...))` 產生，若需要同時允許多個前端來源（例如
LAN 與 tunnel 兩種網址），可在 `.env` 的 `FRONTEND_URL` 用逗號分隔多個網址即可，
不需要修改 `config/cors.php` 邏輯。因為 `supports_credentials=true`，
`allowed_origins` 不可設為 `*`。

### 正式上線環境變數：前後端分 subdomain

正式部署採前後端分 subdomain 架構（例如前端 `https://erp.example.com`、
後端 API `https://api.erp.example.com`）時，`local` / `LAN` / `Tailscale` /
`production` 四種情境的設定不可混用同一組，請依實際環境分別建立 `.env`。

後端 `.env` 必填：

- `APP_URL`：後端自身網址，例如 `https://api.erp.example.com`
- `FRONTEND_URL`：前端網址，例如 `https://erp.example.com`
- `SANCTUM_STATEFUL_DOMAINS`：前端網域（不含協定），例如 `erp.example.com`
- `SESSION_DOMAIN`：cookie 共用網域，例如 `.erp.example.com`
- `SESSION_SECURE_COOKIE`：正式環境需為 `true`（僅限 HTTPS 傳送 cookie）
- `SESSION_SAME_SITE`：例如 `lax`

前端 `.env` 必填：

- `VITE_API_BASE_URL`：後端 API 網址，例如 `https://api.erp.example.com`

完整範例請參考 `backend/.env.example`、`frontend/.env.example` 內的正式上線註解區塊。
修改 `.env` 後需執行：

```bash
php artisan config:clear
```
