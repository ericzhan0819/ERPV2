# ERPV2 Frontend

ERPV2 的 React / TypeScript / Vite SPA。

完整專案說明請見上一層 [README.md](../README.md)。

## 安裝

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
