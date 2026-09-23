# ERPV2 Backend

ERPV2 的 Laravel API。

主要內容：

- Sanctum SPA Session 認證
- 車輛、客戶、收支、資金帳戶與薪資 API
- admin / manager / sales 權限與敏感資料遮蔽
- 車輛照片處理與公開車輛 API
- 稽核紀錄
- 排程清理機制

完整專案說明請見上一層 [README.md](../README.md)。

API 契約請見 [API.md](API.md)。

## 安裝

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```

## 開發伺服器

```bash
php artisan serve
```

## 測試

```bash
php artisan test
```

## Production

正式環境至少需要：

- `APP_ENV=production`
- `APP_DEBUG=false`
- HTTPS
- 正確的 Sanctum / Session / Trusted Host / Trusted Proxy 設定
- 正式 DB credentials
- Web server 指向 `public/`
- Laravel scheduler
