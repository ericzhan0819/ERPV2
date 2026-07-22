# ERPV2 Website MVP 規劃交接

日期：2026-07-23
狀態：只進入產品與工程規劃，尚未建立 Website 企劃書、PLAN、前端專案、資料庫欄位或 runtime 實作。

## 1. 交接目的

ERPV2 內部後台已完成 v1.1～v1.4，使用者決定停止預先擴充一般後台功能，下一條產品主線改為中古車行公開官網 Website MVP。

後台後續原則：

- 維持功能凍結。
- 實際營運遇到阻斷問題時才開 hotfix。
- 不為了讓後台「看起來更完整」而提前加入正式會計、完整 HR、POS、CMS 或其他通用 ERP 功能。
- Website 必須另立企劃書與 PLAN，不得塞回既有 `PLAN_v1.2.md`、`PLAN_v1.3.md` 或 `PLAN_v1.4.md`。

## 2. 正式基準

- Repository：`~/ERPV2`
- Branch：`main`
- v1.4 封板 commit：`d4ea978 docs：完成 v1.4 最終封板交接校正`
- v1.4 runtime／follow-up 基準：`a10dd0c`
- 正式封板 tag：`v1.4-smoke-passed`
- Tag 類型：annotated tag
- Tag 已推送至 `origin`，解引用後指向 `d4ea978`

v1.4 已完成完整自動回歸、三角色 Browser Manual Smoke、真實 iPhone Safari Safe Area／Sidebar／light-dark 複驗及文件同步。Website 規劃不需要重新執行整套 v1.4 Manual Smoke；Website 完成後另做官網 Smoke，正式部署後另做部署 Smoke。

## 3. 現有系統架構

```text
backend/   Laravel API + Sanctum + MySQL／MariaDB
frontend/  React 19 + TypeScript + Vite 8 + Tailwind CSS 4
```

現有 `frontend/` 是登入後的 ERP 後台，不應直接把公開官網頁面混入既有 App Shell、權限路由、Sidebar 或後台資訊架構。Website 規劃必須先決定要新增獨立前端應用、採何種渲染策略，以及正式網域部署方式。

## 4. 已存在的官網公開 API

公開路由已在 v1.2 完成，不需要登入：

```text
GET /api/public/vehicles
GET /api/public/vehicles/{id}
```

路由位於：

```text
backend/routes/api.php
backend/app/Http/Controllers/PublicVehicleController.php
```

正式契約：

- 只公開 `status=listed` 車輛。
- `preparing`、`reserved`、`sold`、`cancelled` 與不存在的 ID 在公開詳情統一回傳 404。
- 匿名 API 掛載 `throttle:60,1`。
- 列表依 `listing_date`、`id` 由新到舊排序。
- 列表只回傳封面照，不載入完整相簿。
- 詳情才回傳完整公開照片陣列。
- 公開 API 使用獨立 Resource 白名單，不共用內部 `VehicleResource`。

目前公開欄位：

```text
id
stock_no
brand
model
year
mileage_km
color
fuel_type
transmission
displacement
asking_price
cover_photo
photos（僅詳情）
listing_date
created_at
```

禁止公開：

- `purchase_price`
- `floor_price`
- `sold_price`
- VIN、車牌
- 買方、賣方、客戶個資
- MoneyEntry、成本、毛利、資金帳戶
- 收購來源、停放位置與內部車況／業務備註
- approval、idempotency、照片上傳者與原始檔名等內部欄位

關鍵檔案：

```text
backend/app/Http/Resources/PublicVehicleListResource.php
backend/app/Http/Resources/PublicVehicleResource.php
backend/app/Http/Resources/PublicVehiclePhotoResource.php
backend/app/Http/Requests/PublicIndexVehicleRequest.php
backend/tests/Feature/PublicVehicleApiTest.php
backend/API.md（Public Vehicles 章節）
```

## 5. 現有照片能力

v1.2 已完成：

- 多張照片上傳
- WebP 重新編碼
- 展示圖與縮圖
- EXIF／GPS 公開風險移除
- 封面、排序與刪除
- 失敗批次清理與 soft-delete storage retry 排程
- 公開列表封面與公開詳情相簿

目前預設仍使用 Laravel 本機 `public` disk，尚未搬到 Cloudflare R2。R2 不應自動成為 Website MVP 的前置阻擋；規劃時需依正式主機容量、流量、備份與 CDN 需求決定是在首發前搬遷，或先用本機 disk 上線後再做獨立 migration。

## 6. 已知的公開內容缺口

目前 Public Vehicle Resource 足以製作基本車輛展示，但沒有專門供官網使用的銷售文案欄位。

現有 `sales_note` 屬既有內部工作流欄位，不得直接假設可以公開。規劃時優先決策：

```text
是否新增 public_description（或同義的明確公開欄位）
```

建議第一版只考慮一個清楚的公開長文欄位，不要直接擴張成：

- 複雜配備資料表
- 任意標籤 CMS
- 多語系內容模型
- 可組頁式 CMS
- AI 自動文案

若確認需要新增公開欄位，必須同步規劃：

- migration／Model／Request／Service
- admin／manager 編輯入口
- sales 是否可編輯
- Public Resource 白名單
- API 測試與敏感資料回歸
- 空值時官網的 fallback 顯示

## 7. 建議 Website MVP 範圍

### 必做

1. 首頁
   - 品牌主視覺
   - 最新或精選在庫車輛
   - 車行核心特色
   - 購車／賞車流程
   - 明確聯絡 CTA

2. 在庫車輛列表
   - 封面照片
   - 廠牌、車型、年份、里程、開價
   - 分頁
   - Loading、空狀態、API 錯誤與圖片失敗狀態
   - Mobile-first RWD

3. 車輛詳情
   - 圖片相簿
   - 基本規格
   - 開價
   - 公開銷售文案（若決策新增）
   - LINE／電話／預約賞車 CTA

4. 關於我們／聯絡資訊
   - 車行介紹
   - 地址
   - 營業時間
   - 電話
   - LINE
   - Google Maps 導流

5. Website 基礎品質
   - SEO title／description
   - Open Graph／社群分享預覽
   - sitemap
   - robots.txt
   - canonical URL
   - 404 頁
   - 圖片 lazy loading、尺寸預留與 layout shift 控制
   - 基本 accessibility、鍵盤操作與可讀對比

6. 後台整合驗證
   - 後台上架車輛後官網可見。
   - 車輛轉為 reserved／sold／cancelled 後官網消失。
   - 價格與照片更新後官網能取得新資料。

### 第一版明確不做

- CMS 後台
- 會員登入／註冊
- 收藏與車輛比較
- 線上付款或線上訂金
- 車貸正式試算或申請
- Lead CRM／預約管理模組
- AI 聊天機器人
- 多語系
- 社群平台自動發文
- 複雜搜尋、任意排序與多條件比較器
- 與 ERP 之外的第二套車輛資料同步

第一版聯絡建議先採電話、LINE 與地圖導流。LINE CTA 可帶入車輛名稱與庫存編號；不要在未確認實務需要前先建立表單、垃圾訊息防護、通知信及 Lead 後台。

## 8. 規劃階段必須先做的產品決策

### A. Website 技術與渲染方式

需比較後再決策，不得直接開工：

- 獨立 Vite React SPA
- 支援 SSR／SSG 的獨立前端架構
- 其他能兼顧動態車輛詳情、SEO、部署複雜度與維護成本的方案

SEO 對公開車輛詳情頁有實際價值，因此不能只因現有後台使用 Vite React，就自動決定官網也必須是純 CSR SPA。

### B. Repository 目錄

需決定是否新增：

```text
website/
```

原則上 Website 應與 `frontend/` 後台分離，但仍可維持同一 repository 與共用 Laravel API。不得讓公開網站依賴後台登入狀態、Sanctum App Shell 或後台路由。

### C. 正式網域

建議規劃模型：

```text
www.example.com 或 example.com   公開官網
erp.example.com                  內部後台
api.example.com                  Laravel API
```

需確認正式品牌網域後，再完成 CORS、Sanctum、Cookie 與 Website public API 的 production 契約。

### D. 品牌與內容

規劃前需向使用者確認或整理：

- 車行正式名稱
- Logo／字標
- 品牌色與視覺風格
- 地址、營業時間、電話、LINE
- 車行核心賣點
- 首頁主標與信任文案
- 是否有保固、鑑定、貸款、舊車換購或到府服務
- 首頁精選車輛規則

不得自行捏造服務承諾、保固、認證、利率或車況保證。

### E. 公開車輛資料

需決定：

- 是否新增 `public_description`
- 是否需要第一版品牌／價格／年份篩選
- `reserved` 車輛要完全消失，或未來另設公開的「已收訂」展示狀態
- 無封面照片的 listed 車輛能否公開
- 是否要求上架前至少有一張公開照片與公開文案

目前正式 API 契約是只有 `listed` 可見，其他狀態全部消失；任何改變都必須另立規格與測試，不得在 Website 前端自行猜測內部狀態。

### F. 圖片與部署

需決定：

- Website MVP 是否先沿用本機 public disk
- 是否在正式首發前搬到 R2／CDN
- 圖片、DB 與環境設定的備份方式
- 官網、API、ERP 是否部署在同一主機
- Laravel scheduler、HTTPS、反向代理、log rotation 與監控方式

正式部署準備應另立 `docs/production-deployment-runbook.md`，不混入 Website 頁面功能 PLAN。

## 9. 規劃文件的預期產物

新聊天室先完成規劃，不直接寫程式。預期產出：

```text
企劃書_Website_MVP.md
PLAN_Website_MVP.md
```

企劃書至少包含：

- 產品目標與成功標準
- 使用者與主要轉換目標
- Sitemap／頁面職責
- Website 視覺方向
- 車輛資料與 CTA 契約
- SEO／分享／效能／accessibility
- 錯誤與空狀態
- 安全、隱私與禁止公開資料
- 非目標與後續版本

PLAN 至少包含：

- 前置盤點與技術選型
- 目錄與部署架構
- API 契約變更（若有）
- Website scaffold
- 首頁
- 車輛列表
- 車輛詳情
- 關於／聯絡頁
- SEO／metadata／sitemap
- RWD／accessibility／效能
- 自動測試
- Browser Manual Smoke
- 正式部署與整合 Smoke
- 文件、handoff 與封板條件

每一階段必須界定：範圍、驗收條件、測試、角色／安全邊界、是否涉及 migration，以及不納入項目。

## 10. 新聊天室必讀檔案

依序閱讀：

```text
AGENTS.md
CLAUDE.md
README.md
UI.md
docs/current-state.md
docs/v1.4-handoff.md
docs/v1.4-smoke-report.md
docs/v1.2-handoff.md
企劃書_v1.2.md
PLAN_v1.2.md
backend/API.md
backend/routes/api.php
backend/app/Http/Controllers/PublicVehicleController.php
backend/app/Http/Resources/PublicVehicleListResource.php
backend/app/Http/Resources/PublicVehicleResource.php
backend/tests/Feature/PublicVehicleApiTest.php
```

規劃時仍需實際搜尋 repo，不可只依本交接文件推測現況。

## 11. 新聊天室開場指令

```text
@MCP 請先完整閱讀 docs/website-mvp-planning-handoff.md，並依其中的必讀清單查證目前 repo、公開 API、圖片系統、v1.4 封板狀態與既有限制。

這一輪只討論並產出 Website MVP 的完整企劃與可執行 PLAN，不寫程式。請先協助我依序釐清技術／渲染架構、網站 Sitemap、品牌內容、公開車輛欄位、聯絡 CTA、圖片儲存與部署方式。不要把 CMS、會員、線上付款、Lead CRM、多語系或其他非 MVP 功能自行塞入範圍，也不要修改既有 v1.1～v1.4 PLAN。
```

## 12. 交接邊界

- 本文件不代表 Website 技術方案已選定。
- 本文件不授權新增 dependency、migration、API 欄位或 `website/` 專案。
- 新聊天室應先完成產品決策與企劃／PLAN，再進入實作。
- v1.4 已正式封板，Website 工作不得回開其 Dashboard、Filter、RWD 或既有後台資訊架構範圍。
