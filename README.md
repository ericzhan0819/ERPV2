# 中古車行內部營運系統（1.0 + v1.1 + v1.2 + v1.3 + v1.4 開發中）

小型中古車行內部使用的前後端分離營運管理系統。v1.1 新增角色（`admin`／`manager`／`sales`）、敏感金額遮蔽、一般收支審核、客戶模組與建車入庫欄位補強，並以 `v1.1-smoke-passed` 封版。v1.2 新增車輛照片管理與官網公開唯讀車輛 API，已完成自動測試、瀏覽器 manual smoke，並以 `v1.2-smoke-passed` 封版。v1.3 新增薪資結算與發薪流程，功能與 Smoke 已通過。另完成獨立的 v1.1 Customer Workflow hotfix：買賣表單改為單一姓名搜尋、自動建立／關聯客戶，並以資料庫 unique 與真實 MariaDB 競態測試防止並發重複。完整穩定狀態見 `docs/current-state.md`、`docs/customer-workflow-hotfix.md`、`docs/v1.3-smoke-report.md` 與 `docs/v1.3-handoff.md`。

v1.3 薪資結算的功能實作、自動測試、真實 MariaDB 並發／時區測試、前端 lint／typecheck／production build、RWD／dark mode 驗證與使用者 browser manual smoke 已完成。範圍包含 admin-only 員工薪資設定、版本化獎金方案、正式收／賣車歸屬、approved-only 整月跨級獎金、異常與非阻擋提示、月份草稿／重算／確認，以及具備 transaction、idempotency 與 paid 歷史保護的整批發薪。當月只能建立與重算草稿，必須等月份結束後才能確認，避免中途鎖定後漏掉後續成交。2026-07-18 納入 Customer hotfix 後的完整回歸為 485 passed、14 environment-gated skipped、2293 assertions；所有受保護的 MariaDB 10.11.18 並發／時區測試於專用 schema 共 14 tests／176 assertions 全數通過。完整規格與驗收證據見 `企劃書_v1.3.md`、`PLAN_v1.3.md`、`PLAN_customer_workflow_hotfix.md`、`docs/current-state.md`、`docs/customer-workflow-hotfix.md`、`docs/v1.3-smoke-report.md`、`docs/v1.3-handoff.md`。Git 封板 commit／tag 尚待使用者授權建立。

v1.4 資訊架構與 UI／UX 改版目前完成第 0～5 部分。Dashboard 已切換為 Action Bar、工作概況、經營概況與近 30 天趨勢，依 admin／manager／sales 角色顯示正式 API 資料並透過 URL Filter 導向既有模組；第 4 部分 review 後另完成單月 `sold_month=YYYY-MM` 契約，使本月成交／毛利精確導向同一批台北月份成交車。後台 Vehicle List API 現在只 eager load 已提交的封面縮圖，沒有封面時明確回傳 `null`，不載入完整相簿且維持既有角色遮蔽。完整 Vehicle Card Grid 與第 7 部分其餘 Filter UI 工作尚未開始。階段紀錄見 `docs/v1.4-phase1-handoff.md` 至 `docs/v1.4-phase5-handoff.md`。

### v1.3 薪資公式

```text
單車毛利
→ 公司營運保留 40%
→ 剩餘 60% 為可分配獎金池
   ├─ 收車獎金：分配池 20%
   ├─ 賣車獎金：依賣車人當月成交台數整月跨級
   │  ├─ 1～2 台：20%
   │  ├─ 3～4 台：30%
   │  └─ 5 台以上：50%
   └─ 其餘為公司剩餘分配額
```

v1.3 不做打卡、排班、請假、官方勞健保費率引擎、所得稅扣繳、銀行薪轉檔或完整 HR／正式會計。

全系統業務日期與月份邊界使用 `Asia/Taipei`；MySQL／MariaDB connection session 使用 `+08:00`。API datetime 會回傳帶 `+08:00` offset 的 ISO 8601，純日期欄位仍使用 `YYYY-MM-DD`。薪資跨級台數只計入正毛利成交車，零毛利／虧損車仍列明細但不推升級距。

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

   v1.2 車輛照片上傳需要 public storage 對外可讀，執行一次：

   ```bash
   php artisan storage:link
   ```

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
- `CommissionPlanSeeder`：建立可重跑且不重複的 `2026 標準薪資方案`（4000 bps 公司保留、2000 bps 收車獎金、1／3／5 台賣車級距）

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

後端自動化測試（PHPUnit，涵蓋登入／登出、車輛、車輛流程、收支、資金帳戶、使用者、列印、車輛照片與公開 API 等模組）：

```bash
cd backend
php artisan test
```

前端驗證：

```bash
cd frontend
npm run lint
npx tsc -b
npm run build
```

手動驗證（v1.2 已完成一次完整 browser smoke，結果見 `docs/v1.2-smoke-report.md`；重新部署或重大修改後依下列項目複查）：

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
12. （v1.2）於車輛詳情頁以 `admin`／`manager` 帳號上傳／刪除／排序／設定封面照片，確認縮圖與封面正確顯示；以 `sales` 帳號檢視同一頁，確認只能看照片、不會顯示上傳／刪除／排序等管理按鈕，且直接呼叫對應 API 會回傳 `403`。
13. （v1.2）呼叫 `GET /api/public/vehicles`、`GET /api/public/vehicles/{id}`（不帶登入 cookie），確認只回傳 `status=listed` 的車輛，且回應 JSON 不含收購價、底價、成交價、客戶個資、收支、毛利、資金帳戶等欄位；查詢非上架中或不存在的車輛 id 應回傳 `404`。
14. （v1.3）依 `docs/v1.3-smoke-report.md` 建立至少兩位員工與五台同月份成交車，確認 1／3／5 台整月級距為 20%／30%／50%，同人收賣車可同時取得兩項獎金。
15. （v1.3）確認公司保留、分配池、收車／賣車獎金、公司剩餘與毛利恆等；歸屬人未啟用薪資或獎金時顯示非阻擋提示，對應獎金明確歸入公司剩餘。
16. （v1.3）確認底薪、津貼、勞健保、手動加扣與全公司實發總額；草稿變更後未重算不得直接確認。
17. （v1.3）逐一製造缺收車人、缺賣車人、pending 收支及購車付款不一致，確認皆阻擋；虧損車不得產生負獎金。
18. （v1.3）確認後頁面只讀；發薪後每位正數實發員工各有一筆 approved `salary_settlement` MoneyEntry，帳戶餘額正確下降，重試不重複建立支出。
19. （v1.3）以 manager／sales 檢查 Sidebar、直接路由與 API 均無法取得薪資資料，並於 light／dark mode、桌機／手機寬度完成基本操作。

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
- `APP_TIMEZONE`：固定 `Asia/Taipei`
- `DB_TIMEZONE`：固定 `+08:00`

既有環境由 UTC 切換至上述台北時區設定前，必須先在舊版資料庫連線確認實際時區：

```sql
SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone, NOW(), UTC_TIMESTAMP();
```

只有 global／session 明確為 UTC，或為 `SYSTEM` 且 system timezone 為 UTC、`NOW()` 等於
`UTC_TIMESTAMP()` 時，既有 MySQL／MariaDB `TIMESTAMP` 才可不搬移直接切換。若舊環境不是
UTC，應停止部署並人工評估既有資料；不得直接套用固定八小時平移。

前端 `.env` 必填：

- `VITE_API_BASE_URL`：後端 API 網址，例如 `https://api.erp.example.com`

完整範例請參考 `backend/.env.example`、`frontend/.env.example` 內的正式上線註解區塊。
修改 `.env` 後需執行：

```bash
php artisan config:clear
```
