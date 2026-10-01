# ERPV2 Frontend

ERPV2 的 React / TypeScript / Vite SPA。

完整專案說明請見上一層 [README.md](../README.md)。

## 本機開發安裝

```bash
npm ci
cp .env.example .env.local
```

## 開發

```bash
npm run dev
```

預設前端位址：

```text
http://localhost:5173
```

API 位址由：

```text
VITE_API_BASE_URL
```

控制。

## 驗證

```bash
npm test
npm run lint
npm run typecheck
npm run build
```

## 技術

- React
- TypeScript
- Vite
- React Router
- Axios
- Tailwind CSS
- Vitest
- Oxlint

## 正式部署

使用乾淨建置目錄，勿帶入開發用 `.env.local`。以 `VITE_API_BASE_URL=https://api.example.com npm run build` 建置，將 `dist/` 交由 HTTPS 靜態伺服器提供，並設定 SPA fallback 至 `index.html`。API 位址在建置時固定；前後端需使用同一註冊網域。完整 Nginx、Session 與驗證步驟見 [正式部署](../README.md#前端正式部署)。
