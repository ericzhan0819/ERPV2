# ERPV2

ERPV2 是一套給小型中古車行使用的前後端分離營運管理系統，重點是把日常車輛、客戶、收支、資金帳戶、薪資與權限流程整合在同一個內部後台。

它不是正式會計系統、報稅系統、發票系統、POS，也不是 SaaS 多租戶平台。

## 主要功能

- Dashboard：工作概況、經營概況、30 天趨勢與角色化資訊
- 車輛管理：建檔、整備、上架、保留、成交、退訂重新上架／取消車輛
- 車輛照片：上傳、縮圖、排序、封面照、失敗清理與冪等處理
- 公開車輛 API：只公開 listed / reserved 車輛與安全欄位，供外部官網讀取
- 客戶管理：買方／賣方與車輛關聯
- 收支管理：一般收支、車輛快捷收支、審核流程
- 資金帳戶：現金／銀行等帳戶與正式帳面餘額
- 薪資結算：薪資設定、獎金方案、月份草稿、確認與發薪
- 使用者與角色：admin / manager / sales
- 帳號安全：Email 或 username 登入、首次登入強制改密碼、自助修改個人資料與密碼
- 稽核紀錄：重要新增、修改、刪除與認證事件
- Responsive UI、light / dark mode、Mobile drawer、Safe Area 與 accessibility 基礎

## 車輛流程

```text
preparing
  ↓
listed
  ↓
reserved
  ↓
sold
```

另外保留 `cancelled` 供退訂重新上架／取消車輛流程使用。

## 角色

### admin

- 完整營運權限
- 使用者管理
- 收支審核
- 薪資設定與結算
- 稽核紀錄
- 可查看完整財務資訊

### manager

- 日常營運與車輛管理
- 可查看大部分財務資訊
- 不可執行 admin-only 的審核、使用者與薪資管理功能

### sales

- 日常銷售流程
- 可查看議價與收款需要的銷售價格
- 不可取得收購價、完整成本、毛利、資金帳戶餘額與其他敏感財務資料

角色遮蔽由後端 Resource / Policy / Middleware 執行，不只依賴前端隱藏。

## 技術棧

### Backend

- PHP 8.3+
- Laravel 13
- Laravel Sanctum
- MySQL 8 / MariaDB 10.11+
- Intervention Image / GD
- PHPUnit

### Frontend

- React 19
- TypeScript 6
- Vite 8
- React Router
- Axios
- Tailwind CSS 4
- Vitest
- Oxlint

## 專案結構

```text
ERPV2/
├─ backend/          Laravel API
├─ frontend/         React / Vite SPA
├─ docker-compose.yml
├─ README.md
└─ UI.md             後台 UI / UX design system
```

完整 API 契約見 [backend/API.md](backend/API.md)。

## 快速開始

### 1. 環境需求

- PHP 8.3+
- Composer 2
- Node.js 20+
- npm
- MySQL 8 或 MariaDB 10.11+
- PHP GD extension
- 可選：Docker / Docker Compose

### 2. 啟動開發資料庫

專案附帶 MariaDB 10.11 的 Docker Compose 設定：

```bash
docker compose up -d
```

預設會使用：

```text
host: 127.0.0.1
port: 3307
database: erpv2
username: erpv2
password: erpv2
```

這組帳密只供本機開發使用。

### 3. Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

預設 API：

```text
http://localhost:8000
```

### 4. Frontend

另開一個 Terminal：

```bash
cd frontend
npm ci
cp .env.example .env.local
npm run dev
```

預設前端：

```text
http://localhost:5173
```

## 開發環境預設帳號

Seeder 會建立：

| Login | Password | Role |
|---|---|---|
| admin@example.com | password | admin |

只供本機開發與測試使用，首次登入須改密碼。正式環境使用下方管理員建立指令，不執行 Seeder。

## 環境變數

Backend 主要設定在：

```text
backend/.env
```

常用變數：

```text
APP_URL
FRONTEND_URL
SANCTUM_STATEFUL_DOMAINS
SESSION_DOMAIN
SESSION_SECURE_COOKIE
TRUSTED_HOSTS
TRUSTED_PROXIES
DB_*
```

Frontend：

```text
VITE_API_BASE_URL
```

完整範例見：

- [backend/.env.example](backend/.env.example)
- [frontend/.env.example](frontend/.env.example)

ERPV2 的正式業務日期與月份邊界使用 `Asia/Taipei`。

## 認證

ERPV2 使用 Laravel Sanctum SPA cookie / Session 認證。

瀏覽器流程：

```text
GET /sanctum/csrf-cookie
POST /api/login
→ authenticated API requests
```

本專案不提供 Sanctum Personal Access Token / Bearer Token 登入方式。

## 公開車輛 API

公開 API 不需登入，只提供官網展示用途的安全欄位。

主要端點：

```text
GET /api/public/vehicles
GET /api/public/vehicles/{id}
```

公開狀態：

```text
listed   → availability=available
reserved → availability=reserved
```

`preparing`、`sold`、`cancelled` 不會透過公開 API 曝光。

Public Resource 不回傳客戶個資、內部狀態、收購價、底價、成交價、成本、毛利、收支或資金帳戶資訊。

## 測試

Backend：

```bash
cd backend
php artisan test
```

MySQL/MariaDB duplicate-key 真並發測試使用獨立 PHP 行程、REPEATABLE READ 與同步屏障。`MoneyEntryMysqlConcurrencyTest` 覆蓋一般收支／快捷入口的相同內容 replay、不同金額拒絕，以及不同車輛保留訂金撞 key 時的完整 rollback；不代表已涵蓋所有並行情境。

這類測試會重建資料庫，只可使用可清除的專用測試 schema。預設 SQLite 測試會跳過；實際執行前須同時設定 MySQL 測試連線、`RUN_MYSQL_CONCURRENCY_TESTS=1`、`MYSQL_CONCURRENCY_TEST_CONNECTION` 與 `MYSQL_CONCURRENCY_TEST_DATABASE`。使用 `./vendor/bin/phpunit -c phpunit.mysql.xml`，以 `DB_HOST`、`DB_PORT`、`DB_USERNAME`、`DB_PASSWORD` 指向隔離服務。此設定固定使用 `mysql` 連線與 `erpv2_ci_test` schema，並啟用並發及時區測試的開關與 allowlist；一般 `phpunit.xml` 強制 SQLite `:memory:`，拒絕 cached config。連線／資料庫名稱必須與 allowlist 完全一致，schema 名稱須包含 test/testing/phpunit/ci，且不得含 prod/production/live/staging/dev/local；另需 pcntl、posix extension。

Frontend：

```bash
cd frontend
npm test
npm run lint
npm run typecheck
npm run build
```

## Production 注意事項

正式部署至少應確認：

- `APP_ENV=production`
- `APP_DEBUG=false`
- 替換所有預設帳密
- 使用 HTTPS
- `SESSION_SECURE_COOKIE=true`
- 正確設定 `APP_URL`、`FRONTEND_URL`、`SANCTUM_STATEFUL_DOMAINS`
- 正確設定 `TRUSTED_HOSTS` 與 `TRUSTED_PROXIES`
- 使用正式資料庫帳號，不使用 docker-compose 的開發密碼
- Web server 正確提供 `backend/public`
- 執行 `php artisan storage:link`
- 設定 Laravel scheduler

例如每分鐘執行：

```cron
* * * * * cd /path/to/ERPV2/backend && php artisan schedule:run >> /dev/null 2>&1
```

Scheduler 會處理車輛照片 tombstone 與長時間未完成的上傳批次清理。

### 首次安裝與升級

上方「快速開始」的 `migrate --seed` 僅供本機開發。正式環境不要執行 `db:seed` 或 `migrate --seed`；所有 Seeder 僅允許 local/testing，且管理員與資金帳戶 Seeder 不會覆寫既有資料。

首次安裝：完成 `.env` 的資料庫、HTTPS 與 Session 設定後，在 `backend/` 執行：

```bash
composer install --no-dev --prefer-dist --no-interaction
php artisan key:generate
php artisan migrate --force
php artisan app:create-initial-admin
php artisan storage:link
php artisan config:cache
```

管理員指令要求互動輸入姓名、Email、至少 12 字元的密碼與確認密碼；密碼不放命令列、不輸出至 log。只在完全沒有 admin（包含停用帳號）時建立，首次登入必須改密碼。若已存在 admin，請用既有帳號管理流程，不會重設或重新啟用帳號。登入後從後台建立資金帳戶及薪資方案；正式安裝沒有預設帳戶或方案。

升級前先完成下節的完整備份，停止 scheduler／queue／其他寫入端並等待進行中的請求完成。部署程式後：

```bash
php artisan down
composer install --no-dev --prefer-dist --no-interaction
php artisan config:clear
php artisan migrate --force
php artisan config:cache
php artisan up
```

再恢復 scheduler／queue，檢查登入、照片與資金帳戶餘額。升級不得重新產生 `APP_KEY`、執行 Seeder 或以 `migrate:fresh` 重建資料庫；migration 失敗時保持維護狀態並查明原因。

### 前端正式部署

Vite 在 **build 時**將 `VITE_API_BASE_URL` 寫入 JavaScript，修改伺服器環境後必須重新 build。正式建置使用乾淨 checkout，不要帶入開發用 `.env.local`／`.env.production.local`。以下以同一註冊網域的 `erp.example.com` 與 `api.example.com` 為例：

```bash
cd frontend
npm ci
VITE_API_BASE_URL=https://api.example.com npm run build
```

依 [Vite 環境變數規則](https://main.vite.dev/guide/env-and-mode)，命令列環境值優先於 env 檔案；不可省略，也不可設為 localhost 或帶上 `/api`。將整個 `frontend/dist/` 部署到例如 `/srv/erp-frontend/dist/`。Nginx 的 HTTPS server 區塊範例（憑證另行設定）：

```nginx
server {
    listen 443 ssl;
    server_name erp.example.com;
    root /srv/erp-frontend/dist;
    index index.html;
    location /assets/ {
        try_files $uri =404;
    }
    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

`BrowserRouter` 需要上述 SPA fallback，否則直接開 `/vehicles/1` 或重新整理會 404。前後端必須使用相同 scheme 與同一註冊網域，讓 Sanctum SPA Session cookie 正常運作。後端設定：

```dotenv
APP_URL=https://api.example.com
FRONTEND_URL=https://erp.example.com
SANCTUM_STATEFUL_DOMAINS=erp.example.com
SESSION_DOMAIN=.example.com
SESSION_SECURE_COOKIE=true
APP_DEBUG=false
```

依實際網域同步 Trusted Hosts／CORS 設定。驗證 `dist/assets/` 內 API base 為正式網址（可用 `rg -n 'https://api.example.com|http://localhost:8000' dist/assets`），並以瀏覽器 Network 確認請求目標、登入與 CSRF cookie。`curl -I https://erp.example.com/vehicles/1` 應回 200，登入後直接進入該網址及重新整理都須正常。

### 照片上傳容量與逾時

[PHP 上傳限制](https://www.php.net/manual/en/features.file-upload.common-pitfalls.php) 必須與應用設定配合。應用層限制為每張 8 MiB、每批 20 張、每張最多 24MP。multipart overhead 也占 request body，不能只給剛好 160 MiB。PHP-FPM 的起始設定建議：

```ini
upload_max_filesize = 8M
post_max_size = 192M
max_file_uploads = 20
memory_limit = 512M
max_execution_time = 300
max_input_time = 300
```

API Nginx server 區塊至少設定：

```nginx
client_max_body_size 192m;
client_body_timeout 300s;
# 放在既有轉送 PHP-FPM 的 location 內：
fastcgi_read_timeout 300s;
```

PHP-FPM pool 可設定 `request_terminate_timeout = 360s`。若另有反向代理／CDN，同步其 body size 與 timeout；平台硬上限可能要求縮小批次。變更後 reload Nginx 與 PHP-FPM，確認 **FPM 使用的 ini**（CLI 的 `php -i` 不代表 FPM）。確保 upload temp 與 storage 磁碟有足夠空間。

512M 是起始預算，不是 24MP 解碼成功的保證；GD 原生配置可能不受 PHP memory_limit 完整限制。既有 24MP 處理量測約 335MB RSS，仍須依實際圖片、worker 數與容器記憶體限制實測。不要只提高 PHP 上限而忽略主機 OOM。於 staging 一次上傳 20 張約 7MB、各不超過 24MP 的有效圖片，確認全部出現、縮圖／封面可讀、無 413／timeout／OOM；記錄 RSS、耗時並據此調整。

### 資料庫、照片與審查證據備份

備份必須包含同一時間點的資料庫、`storage/app/public`、`storage/app/private/money-entry-source-type-backups`，以及另外加密保存的 `.env`／`APP_KEY`。若改用外部照片 disk，需備份該 disk 的物件與版本。備份存到 web root 外、限制權限並另存異機；不要提交 repository。

以下依 [mariadb-dump 文件](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump) 的 MariaDB 範例在 `backend/` 執行；先在 web／proxy 層阻擋所有寫入、停止 scheduler、queue 與外部寫入程序，等待進行中的照片請求完成，記錄各資金帳戶正式餘額（期初餘額加所有 approved 收入減 approved 支出，包含未來日期）、程式與 DB 版本，再進入維護模式。`down` 本身不會停止排程與已開始的請求。整個 DB dump 與檔案複製期間保持停止寫入，避免 DB 與照片錯位。

憑證放在權限 0600 的 `/secure/erp-backup.cnf`，內容為 `[client]` 下的 host、user、password；帳號須能讀完整 schema、trigger、routine、event。不要把密碼放命令列。將下列 `erpv2` 換成實際資料庫名稱：

```bash
set -euo pipefail
umask 077
backup_dir="/secure/backups/erp-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$backup_dir"
php artisan down
mariadb --defaults-extra-file=/secure/erp-backup.cnf erpv2 \
  -e 'SHOW TRIGGERS' > "$backup_dir/triggers.tsv"
mariadb-dump --defaults-extra-file=/secure/erp-backup.cnf \
  --single-transaction --quick --triggers --routines --events \
  erpv2 > "$backup_dir/database.sql"
tar -czf "$backup_dir/storage.tar.gz" -C storage/app public private
sha256sum "$backup_dir/database.sql" "$backup_dir/storage.tar.gz" > "$backup_dir/SHA256SUMS"
php artisan up
```

打包整個 private 目錄可包含審查證據，即使證據子目錄尚未產生也不會漏掉後續證據。若任何命令失敗，**不要**自動恢復營運或把部分檔案當成有效備份。成功後記錄備份時間與 trigger 名稱／數量，連同停寫期間記錄的版本與餘額保存，再恢復 scheduler／queue。MySQL 8 使用對應版本的 `mysql`／`mysqldump`；可另加 `--no-tablespaces --set-gtid-purged=OFF`，不要將 MySQL 專用參數傳給 MariaDB。

### 還原演練

先在隔離主機建立**空白專用還原資料庫**，不要覆寫現行營運資料庫。使用相容的 DB 版本與備份時的程式版本，停用 scheduler／queue／對外通知及公開流量。

1. 執行 `sha256sum -c /secure/backups/<backup>/SHA256SUMS` 確認備份完整。
2. 檢查 SQL 中的 `DEFINER`。trigger 有資料保護作用，不得刪除 trigger 來繞過匯入錯誤。由 DB 管理員在隔離環境建立對應、不可互動登入且具有必要權限的 definer 帳號，或在 **SQL 副本**將 definer 明確映射至還原用帳號並審核差異。匯入帳號需有建立 trigger／routine／event 及指定 definer 的權限；不同 DB 版本權限名稱不同。勿直接對原始備份做全域文字刪除。
3. 設定還原連線的 `/secure/erp-restore.cnf`，確認 host 與資料庫為隔離目標，再匯入：

   ```bash
   mariadb --defaults-extra-file=/secure/erp-restore.cnf erpv2_restore < /secure/backups/<backup>/database.sql
   tar -xzf /secure/backups/<backup>/storage.tar.gz -C storage/app
   ```

4. 設定 `.env` 指向還原資料庫與隔離網域，還原原 `APP_KEY`，設定 storage 權限；執行 `php artisan config:clear`、`php artisan storage:link`。不得 seed。先用相同版本驗證還原，再依升級流程執行新 migration。
5. 用 `SHOW TRIGGERS` 比對備份清單的名稱、數量、table、timing、event、body 與預期 definer；僅數量相同仍不足以驗證完整性。確認 restore log 沒有 SQL 錯誤。
6. 用管理員登入，比對各帳戶期初餘額／正式餘額、車輛／收支／薪資資料與備份紀錄。抽查照片及縮圖 URL 回 200，檢查 private 審查證據檔案及其 DB 關聯，並確認 private 檔案不經 Web server 公開。
7. 記錄實際還原時間與結果，通過演練後才規劃正式切換；未演練的備份不視為已驗證可還原。

目前營運資料無需以 APP_KEY 解密的欄位，但該 key 用於 cookie／Session 等 Laravel 加密。遺失或更換會使既有登入失效，仍應與備份安全保存；不可把「可重建登入」當成忽略金鑰備份的理由。

## 系統邊界

ERPV2 刻意不處理：

- 正式會計總帳
- 稅務申報
- 電子發票
- POS
- 打卡／排班／請假
- 官方勞健保費率計算
- 銀行薪轉檔
- SaaS 多租戶
- 通用 CMS

如果要加入上述能力，建議視為新的獨立模組，而不是直接塞進既有營運流程。

## UI / UX

後台設計規範見 [UI.md](UI.md)。

核心方向：

- 低噪音、高可讀性
- Desktop 與 Mobile 都能完成主要流程
- light / dark mode 使用同一組語意 token
- 金額與正式狀態以後端為權威來源
- 不以顏色作為唯一狀態提示

## Security

如果你準備把 ERPV2 部署到公開網路，請先完整檢查：

- 預設帳號
- Session / Cookie 設定
- Trusted Hosts / Proxies
- CORS / Sanctum stateful domains
- 資料庫權限
- 檔案 storage 權限
- Web server HTTPS 與反向代理設定

正式的安全通報方式會記錄在 `SECURITY.md`。

## License

ERPV2 採用 [MIT License](LICENSE)。
