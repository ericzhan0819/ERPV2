# ERPV2

ERPV2 是一套給小型中古車行使用的前後端分離營運管理系統，重點是把日常車輛、客戶、收支、資金帳戶、薪資與權限流程整合在同一個內部後台。

它不是正式會計系統、報稅系統、發票系統、POS，也不是 SaaS 多租戶平台。

## 主要功能

- Dashboard：工作概況、經營概況、30 天趨勢與角色化資訊
- 車輛管理：建檔、整備、上架、保留、成交、取消／退車
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

另外保留 `cancelled` 供取消／退車流程使用。

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

只供本機開發與測試使用。正式環境不得保留預設密碼。

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

這類測試會重建資料庫，只可使用可清除的專用測試 schema。預設 SQLite 測試會跳過；實際執行前須同時設定 MySQL 測試連線、`RUN_MYSQL_CONCURRENCY_TESTS=1`、`MYSQL_CONCURRENCY_TEST_CONNECTION` 與 `MYSQL_CONCURRENCY_TEST_DATABASE`。連線／資料庫名稱必須與 allowlist 完全一致，schema 名稱須包含 test/testing/phpunit/ci，且不得含 prod/production/live/staging/dev/local；另需 pcntl、posix extension。

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
