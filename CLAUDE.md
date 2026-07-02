# CLAUDE.md

## 專案身份

本專案是「中古車行內部營運系統 1.0」。

這是一套給小型中古車行內部使用的前後端分離營運管理系統，不是正式會計系統、不是報稅系統、不是 POS、不是 SaaS 多租戶平台。

核心目標是完成可執行、可測試、結構清楚、方便未來維護與擴充的 1.0 版本。

---

## 必讀文件

開始任何實作前，必須先閱讀，避免偏離方向：

1. `PLAN.md`
2. `企劃書.md`
2. `README.md`，如果已存在
3. 目前 repo 既有程式碼與目錄結構，不得只看單一檔案就直接開始大量改碼。

---

## 技術棧

本專案必須採用前後端分離。

Frontend：

* React
* TypeScript
* Vite
* Tailwind CSS

Backend：

* Laravel API
* Laravel Sanctum
* MySQL 或 MariaDB

前端只能透過 API 與後端溝通，不得直接接觸資料庫。

---

## 實作範圍

只實作 `企劃書.md` 以及 `PLAN.md` 明確列出的功能。

必做功能包含：

* 登入 / 登出
* Dashboard
* 車輛管理
* 車輛狀態流程
* 收支紀錄
* 現金 / 銀行 / 其他資金帳戶
* 即時資金餘額
* 單車收入 / 支出 / 毛利
* 一般收入 / 一般支出
* 使用者管理
* 車輛建檔資料列印
* 成交結案收支明細列印
* migration
* seeder
* 預設管理員帳號
* API 文件
* README

---

## 禁止實作

不得實作以下功能：

* 正式會計
* 借方 / 貸方
* 會計科目
* 傳票
* 報稅申報
* 稅期管理
* 發票系統
* POS 收銀
* 多公司
* 多分店
* OCR
* 附件上傳
* QR Code
* 自動稅務申報
* 記帳士交接包
* 完整角色權限勾選 UI
* 任何企劃書沒有明確要求的進階功能

稅金只當成一般支出處理，不得實作稅務計算、稅期、稅務沖銷或申報流程。

---

## 架構原則

系統必須模組積木化。

後端模組：

* Auth Module
* Dashboard Module
* Vehicle Module
* Vehicle Workflow Module
* Money Entry Module
* Cash Account Module
* User Module
* Print Module
* Shared Module

前端模組：

* Auth
* Dashboard
* Vehicles
* MoneyEntries
* CashAccounts
* Users
* Print
* Shared UI Components
* Layouts
* API Client
* Types
* Utils

模組之間不得硬耦合。
Dashboard 只能透過後端統計服務取得資料，不得在前端自行拼湊正式統計結果。

---

## 後端規則

Laravel 後端必須分層：

* Controller：接收 request，回傳 response
* FormRequest：驗證輸入
* Service：處理業務邏輯
* Model：資料關聯
* Policy / Middleware：權限
* Resource / DTO：API 回傳格式

禁止：

* 把大量業務邏輯寫在 Controller
* 把金流計算寫在前端
* 用前端計算結果當正式資料
* 金額使用 float
* 手動儲存 current_balance
* 實作企劃書沒有列出的功能

金流與狀態異動必須使用 database transaction。

金額欄位必須使用適合金額的 decimal / integer cents 設計，不得使用 float。

---

## 前端規則

API 呼叫必須集中管理：

* `src/api/client.ts`
* `src/api/auth.ts`
* `src/api/vehicles.ts`
* `src/api/moneyEntries.ts`
* `src/api/cashAccounts.ts`
* `src/api/dashboard.ts`
* `src/api/users.ts`

禁止：

* 在元件裡散落 fetch URL
* 在前端硬寫重要業務邏輯
* 用前端結果取代後端計算
* 使用假資料假裝功能完成
* 把頁面寫成單一巨大元件

前端可以做顯示用格式化，但正式餘額、收入、支出、毛利、Dashboard 統計都必須以後端 API 回傳為準。

---

## UI 原則

UI 風格：

* 現代後台
* 清楚
* 低噪音
* 高可讀性
* 不花俏
* 不過度設計
* 不使用假資料

必要元素：

* 左側 Sidebar
* 上方 Header
* 卡片式 Dashboard
* 圓角面板
* 狀態 Badge
* 表格
* 搜尋列
* 篩選器
* 明確操作按鈕
* 表單錯誤提示
* 成功提示

桌機優先，手機需可基本操作。

---

## 資料與計算規則

帳戶目前餘額不得儲存在資料庫。

帳戶目前餘額計算：

```
目前餘額 = 期初餘額 + 收入總額 - 支出總額
```

單車收入：

```
該車所有 income money_entries 合計
```

單車支出：

```
該車所有 expense money_entries 合計
```

單車毛利：

```
單車收入合計 - 單車支出合計
```

一般營運收入 / 支出不得影響任何單車毛利。

---

## 車輛流程規則

車輛狀態：

* preparing：整備中
* listed：上架中
* reserved：保留中
* sold：已售出
* cancelled：取消 / 退車

新增車輛後：

```
建立 vehicles
→ 自動產生 stock_no
→ status = preparing
```

車輛流程必須支援：

```
整備中
→ 上架中
→ 保留中
→ 已售出
```

不適用目前狀態的操作按鈕不得顯示。

---

## 開發流程

1. 閱讀相關文件。
2. 檢查目前目錄與既有程式碼。
3. 確認任務是否在 `企劃書.md` 範圍內。
4. 如果任務超出範圍，不要實作，先在回覆中說明原因。
5. 優先小步修改，避免一次大範圍重構。
6. 保持模組邊界清楚。
7. 修改後執行可用的驗證指令。
8. 任務完成之後在 `PLAN.md` 中標記已完成。

---

## 驗證要求

完成修改後，至少確認：

* backend 可以 migrate
* backend 可以 seed
* backend API 可以啟動
* frontend 可以啟動
* 登入 / 登出可用
* Dashboard 使用真實 API
* 車輛 CRUD 可用
* 收支 CRUD 可用
* 資金帳戶餘額正確
* 單車收入 / 支出 / 毛利正確
* 車輛流程可從整備中走到已售出
* 列印頁可開啟
* 沒有假資料
* 沒有額外實作未列功能

如果某些驗證無法執行，必須在回覆中明確列出原因。

---

## 回覆格式

完成任務後，回覆必須包含：

1. 本次完成項目
2. 修改檔案
3. 重要實作說明
4. 驗證指令與結果
5. 未執行的驗證與原因
6. 建議 commit message

不得只回覆「完成」。

---

## Commit message 規則

使用簡潔的繁體中文 commit message。


---

## 最重要限制

請只依照 `企劃書.md` 與 `PLAN.md` 實作。

不要額外過度實作。
不要把正式會計、稅務、發票、傳票、OCR、附件、多公司、多分店、POS 或 SaaS 功能帶進本專案。

---

## CODE REVIEW

所有程式碼皆會交由Codex進行Code Review