# v1.5 第 15 部分工程預驗證重跑方式

這些 driver 只重現工程端 Firefox／Chrome／Config 預驗證，不取代使用者依
`PLAN_v1.5.md` 15.1～15.6 執行的 Browser Manual Smoke。

所有指令都必須使用明確的 `/tmp` SQLite，不可把 `migrate:fresh` 指向既有開發或正式資料庫。

## 1. Firefox 38 項

先建立可拋棄資料庫：

```bash
touch /tmp/erpv2-v15-smoke.sqlite
cd backend
APP_ENV=testing \
APP_DEBUG=false \
DB_CONNECTION=sqlite \
DB_DATABASE=/tmp/erpv2-v15-smoke.sqlite \
CACHE_STORE=array \
SESSION_DRIVER=file \
QUEUE_CONNECTION=sync \
php artisan migrate:fresh --seed --force
```

終端 A 啟動 Laravel：

```bash
cd backend
APP_ENV=testing \
APP_DEBUG=false \
APP_URL=http://127.0.0.1:8015 \
FRONTEND_URL=http://127.0.0.1:5195 \
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5195,127.0.0.1 \
SESSION_DOMAIN=null \
SESSION_SECURE_COOKIE=false \
DB_CONNECTION=sqlite \
DB_DATABASE=/tmp/erpv2-v15-smoke.sqlite \
CACHE_STORE=array \
SESSION_DRIVER=file \
QUEUE_CONNECTION=sync \
php artisan serve --host=127.0.0.1 --port=8015
```

終端 B 啟動 Vite：

```bash
cd frontend
VITE_API_BASE_URL=http://127.0.0.1:8015 \
npm run dev -- --host 127.0.0.1 --port 5195 --strictPort
```

終端 C 執行：

```bash
python3 docs/smoke/v1.5-phase15-firefox.py
```

需求：Firefox 與 `geckodriver` 位於 PATH。Script 會為每個 Session 啟動獨立 driver／profile，
完成後自行停止。

## 2. Desktop Chrome 15 項

原始驗證使用官方 Chrome for Testing 151.0.7922.47：

```bash
curl -fL -o /tmp/erpv2-chrome-linux64.zip \
  https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.47/linux64/chrome-linux64.zip
curl -fL -o /tmp/erpv2-chromedriver-linux64.zip \
  https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.47/linux64/chromedriver-linux64.zip
unzip -q /tmp/erpv2-chrome-linux64.zip -d /tmp/erpv2-chrome
unzip -q /tmp/erpv2-chromedriver-linux64.zip -d /tmp/erpv2-chrome
```

依 Firefox 步驟另建 `/tmp/erpv2-v15-chrome.sqlite`，並將 Laravel／Vite ports 改為
`8016`／`5196`。執行：

```bash
CHROME_BINARY=/tmp/erpv2-chrome/chrome-linux64/chrome \
CHROMEDRIVER=/tmp/erpv2-chrome/chromedriver-linux64/chromedriver \
WEBDRIVER_START_PORT=9516 \
python3 docs/smoke/v1.5-phase15-chrome.py
```

## 3. App Config 2 項

在 Firefox 環境仍運作且工作樹沒有其他 `frontend/src/config/app.ts` 修改時：

```bash
git apply docs/smoke/v1.5-phase15-config.patch
python3 docs/smoke/v1.5-phase15-config.py
git apply -R docs/smoke/v1.5-phase15-config.patch
git diff -- frontend/src/config/app.ts
```

最後一個指令必須沒有輸出。若 config 原本已有使用者修改，不得套用 patch，應另開乾淨
worktree 執行。

## 4. MariaDB username gated integration

此測試會對指定 schema 執行 `migrate:fresh`。只能使用明確可拋棄且名稱符合 test guard 的
schema，絕不可把 `DB_DATABASE` 或 allowlist 指向既有 `erpv2`。

先確認專用 schema 不存在；下列查詢必須輸出 `0`，否則停止並查明來源：

```bash
docker exec erpv2-db mariadb -N -uroot -proot \
  -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = 'erpv2_v15_test';"
```

建立並只授權專用 schema：

```bash
docker exec erpv2-db mariadb -uroot -proot \
  -e "CREATE DATABASE erpv2_v15_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON erpv2_v15_test.* TO 'erpv2'@'%'; FLUSH PRIVILEGES;"
```

執行 v1.5 專用的兩項 MySQL／MariaDB integration：

```bash
cd backend
APP_ENV=testing \
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3307 \
DB_DATABASE=erpv2_v15_test \
DB_USERNAME=erpv2 \
DB_PASSWORD=erpv2 \
MYSQL_CONCURRENCY_TEST_CONNECTION=mysql \
MYSQL_CONCURRENCY_TEST_DATABASE=erpv2_v15_test \
RUN_MYSQL_CONCURRENCY_TESTS=1 \
./vendor/bin/phpunit tests/Feature/UserAccountMysqlIntegrationTest.php
```

無論測試通過或失敗，都要明確刪除這個專用 schema：

```bash
docker exec erpv2-db mariadb -uroot -proot \
  -e "DROP DATABASE erpv2_v15_test;"
docker exec erpv2-db mariadb -N -uroot -proot \
  -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = 'erpv2_v15_test';"
```

最後一個查詢必須輸出 `0`。

## 5. 清理

停止 Laravel／Vite 後，刪除本輪明確建立的 `/tmp` SQLite、下載檔與 Chrome 解壓目錄。
不得使用未解析變數、glob 或 broad recursive path 作為清理目標。
