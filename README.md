# 中古車行內部營運系統 1.0

小型中古車行內部使用的前後端分離營運管理系統。

## 環境需求

TODO：待 Backend / Frontend 骨架建立後補齊（PHP / Composer / Node / MySQL 版本需求）。

## 安裝步驟

TODO：待 Backend / Frontend 骨架建立後補齊。

## 啟動方式

TODO：待 Backend / Frontend 骨架建立後補齊。

## Migrate / Seed

TODO：待 Migration / Seeder 完成後補齊。

## 預設帳號

TODO：待 Seeder 完成後補齊。

## 測試方式

TODO：待驗證流程確立後補齊。

## 常見問題

### 開發環境如何設定前端 API 位址（`VITE_API_BASE_URL`）

前端程式碼是在「使用者的瀏覽器」裡執行，不是在開發機上執行。因此 `VITE_API_BASE_URL`
要填的是「瀏覽器所在的那台電腦」能夠連到的位址，而不是開發機自己的區網 IP。

依照使用情境不同，需要不同設定，**沒有單一固定答案**：

1. **在家用同一區網直連**（瀏覽器與後端在同一個 LAN）：

   於 `frontend/.env.local`（或個人的 `frontend/.env`，此檔已被 `.gitignore` 排除，不會進 repo）設定：

   ```
   VITE_API_BASE_URL=http://192.168.0.40:8000
   ```

   （`192.168.0.40` 請替換為實際開發機在區網內的 IP）

2. **人在外面，透過 SSH tunnel 連回家裡開發機**：

   此時瀏覽器在外部電腦（例如 Mac / iPhone）執行，無法連到家裡 LAN 內的
   `192.168.0.40`，必須改用 tunnel 轉發後的 `localhost`。

   建立 tunnel：

   ```
   ssh -L 8000:127.0.0.1:8000 -L 5173:127.0.0.1:5173 z@你的開發機TailscaleIP
   ```

   外部瀏覽器開啟：

   ```
   http://localhost:5173
   ```

   並在外部這台電腦上建立 `frontend/.env.local`（本機私有檔，不會 commit）：

   ```
   VITE_API_BASE_URL=http://localhost:8000
   ```

   因為 SSH port forward 會把「外部電腦自己的 localhost:8000」轉送到「開發機的
   127.0.0.1:8000」，所以在 tunnel 情境下必須用 `localhost`，`192.168.0.40` 在外部
   電腦上是連不到的。

   修改 `.env` / `.env.local` 後，必須重新啟動 `npm run dev`，Vite 才會讀到新的環境變數。

3. `frontend/.env.example` 保留安全預設值 `VITE_API_BASE_URL=http://localhost:8000`，
   實際要用 LAN IP 或 tunnel，請依上面情境自行建立 `.env` / `.env.local`，不要修改
   `.env.example` 或把個人私有設定 commit 進 repo。

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
