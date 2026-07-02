# PLAN.md — 中古車行內部營運系統 1.0 進度清單

## 0. 專案設定
- [x] Git 初始化（git init，若尚未初始化）
- [x] .gitignore（node_modules, vendor, .env 等）
- [x] docker-compose.yml（MySQL/MariaDB）
- [x] README.md 骨架（環境需求／安裝步驟／啟動方式章節先建立）

## 1. Backend 骨架
- [x] Laravel 專案建立於 backend/
- [x] Laravel Sanctum 安裝與 SPA 設定（CORS、CSRF、session driver）
- [x] .env.example 設定（DB 連線指向 docker-compose）
- [x] 基礎資料夾結構確認（Controllers/Requests/Resources/Services/Models/Policies）

## 2. Frontend 骨架
- [x] Vite + React + TypeScript 專案建立於 frontend/
- [x] Tailwind CSS 設定
- [x] 專案結構建立（api/ components/ layouts/ pages/ routes/ hooks/ types/ utils/）
- [x] API client（src/api/client.ts）與 axios/fetch credentials 設定

## 3. 資料模型與 Migration
- [x] users migration（含 is_admin, is_active）
- [x] vehicles migration
- [x] cash_accounts migration
- [x] money_entries migration
- [x] Model 關聯設定（Vehicle hasMany MoneyEntry 等）
- [x] 第 3B：Codex review fixes completed（audit fields 移除 mass-assignable / direction enum(income,expense) / amount CHECK > 0 / created_by・updated_by 改 restrictOnDelete）

## 4. Seeder
- [x] 預設管理員帳號 seeder
- [x] 預設資金帳戶 seeder（現金／主要銀行／其他）

## 5. Auth Module
- [x] POST /api/login
- [x] POST /api/logout
- [x] GET /api/me
- [x] Middleware：未登入擋後台、停用使用者不可登入

## 6. Dashboard Module
- [x] GET /api/dashboard/summary（Service 計算：帳戶餘額／本月收支／車輛狀態計數／本月成交數）
- [x] 前端 Dashboard 頁（卡片 + 快捷操作，串真實 API）

## 7. Vehicle Module
- [x] Vehicle CRUD API（含 stock_no 自動產生、驗證規則）
- [x] 前端車輛列表頁（搜尋／狀態篩選／分頁）
- [x] 前端新增車輛頁
- [x] 前端車輛詳情頁（基本/採購/銷售資料＋單車收支摘要＋明細）

## 8. Vehicle Workflow Module
- [ ] POST /list（整備完成上架）
- [ ] POST /reserve（收訂金並保留）
- [ ] POST /final-payment（收尾款）
- [ ] POST /close-sale（成交結案，含驗證：已有成交價/買方、至少一筆訂金或尾款）
- [ ] 車輛詳情頁操作按鈕依狀態顯示

## 9. Money Entry Module
- [ ] Money Entry CRUD API
- [ ] 車輛快捷收支 API（purchase-payment/expense/deposit/final-payment/refund）
- [ ] 帳戶餘額即時計算 Service（供 Dashboard/CashAccount/Vehicle 共用）
- [ ] 前端收支列表頁（多重篩選＋分頁）
- [ ] 前端新增收支頁

## 10. Cash Account Module
- [ ] Cash Account CRUD API + /balances
- [ ] 停用帳戶不可新增收支規則
- [ ] 前端資金帳戶頁

## 11. User Module
- [ ] User CRUD + reset-password + disable/enable API
- [ ] 前端使用者管理頁

## 12. Print Module
- [ ] GET /print/intake、/print/closing 資料 API
- [ ] 前端列印頁（intake / closing），含 print.css

## 13. 文件與收尾
- [ ] backend/API.md（所有 endpoint 說明）
- [ ] README.md 完整補齊（環境需求/安裝/啟動/migrate seed/預設帳號/測試方式/常見問題）
- [ ] 系統自我檢查（對照企劃書第 19 章逐項確認）
