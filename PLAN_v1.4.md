# PLAN_v1.4.md — ERPV2 v1.4 資訊架構與 UI／UX 改版

本清單對應 `企劃書_v1.4.md`。

v1.1、v1.2、v1.3 均已封版，不回開既有版本範圍。

v1.4 目標：以 Presentation Layer 為主，重新整理 Dashboard 與模組工作區的資訊架構，完成車輛 Card Grid、Filter 導流、RWD 與 `UI.md` Design System；不新增商業流程、Database Schema、Workflow、角色或權限。

---

## 0. 前置準備與範圍確認

- [x] 閱讀 `企劃書.md`、`企劃書_v1.1.md`、`企劃書_v1.2.md`、`企劃書_v1.3.md`、`企劃書_v1.4.md`
- [x] 閱讀 `PLAN.md`、歷版 PLAN 與 `PLAN_v1.4.md`
- [x] 閱讀 `README.md`、`UI.md`、`CLAUDE.md`、`docs/current-state.md`、v1.3 smoke／handoff 與 `backend/API.md`
- [x] 檢查 Git branch、tag 與工作樹，保留使用者無關變更
- [x] 搜尋並讀取 Dashboard、Vehicle、MoneyEntry、Customer、AppLayout、照片、權限與 RWD 相關程式及測試
- [x] 盤點既有 URL query、API Request、Service、Resource、Policy 與前端 types 契約
- [x] 確認 v1.4 不建立 migration、不修改 schema、不新增 Workflow、不新增權限
- [x] 確認任何快捷操作均重用既有頁面與流程
- [x] 先記錄實作前基準測試結果

---

## 1. UX Design System 與共用元件盤點

### 1.1 更新 `UI.md`

- [x] 保留既有品牌方向與語意色彩 token，不另提新配色方案
- [x] 整理 Color 規範與 light／dark mode 對應
- [x] 整理 Typography 層級、數字與金額呈現
- [x] 整理 Spacing 與頁面區塊節奏
- [x] 定義 Card 一般用途與互動狀態
- [x] 定義 Button 層級、尺寸、loading、disabled 與 destructive 行為
- [x] 定義 Form visible label、required marker、per-field error 與 Mobile 行為
- [x] 定義 Modal focus、關閉、內容 overflow 與 Mobile 行為
- [x] 定義 Table 適用情境；車輛工作區不得再使用 Table
- [x] 定義 Vehicle Card 欄位、圖片比例、空圖狀態、角色差異與 RWD
- [x] 定義 Dashboard KPI Card 的可點擊、focus、hover、active 與導流行為
- [x] 定義 Filter 的 URL 狀態、清除規則、Desktop 呈現與 Mobile Drawer
- [x] 定義 Responsive Breakpoint 與 Desktop／Tablet／Mobile 驗收寬度
- [x] 確認文件沒有新增本企劃外的元件、功能或品牌方向

### 1.2 共用元件

- [x] 盤點既有 Button、Card、Modal、Badge、Form、Filter 與狀態元件
- [x] 只抽取 Dashboard、Vehicle List 與 Filter 實際共用的最小元件
- [x] 避免把單一頁面改寫成平行 Design System 或巨大通用元件
- [x] 所有互動元件支援鍵盤操作與可見 focus
- [x] 共用元件在 light／dark mode 均使用語意 token

---

## 2. Filter URL 契約

### 2.1 車輛 Filter

- [ ] 保持既有單一 `status=preparing` 等 query 相容
- [ ] 支援工作區預設狀態集合：`preparing`、`listed`、`reserved`
- [ ] 支援使用者明確選擇 `sold`、`cancelled` 或其他狀態組合
- [ ] 支援 `is_preparation_completed=true／false`，不得新增車輛狀態
- [ ] Dashboard query 優先於工作區預設 Filter
- [ ] 搜尋、狀態、整備完成與頁碼皆同步至 URL
- [ ] 重新整理頁面可完整還原 Filter
- [ ] 瀏覽器上一頁／下一頁可還原 Filter 與頁碼
- [ ] 清除 Filter 回到 `preparing`、`listed`、`reserved` 的工作區預設集合
- [ ] Filter 變更後頁碼重設為第 1 頁
- [ ] 前端 API 呼叫集中於 `frontend/src/api/vehicles.ts`

### 2.2 收支 Filter

- [ ] `/money-entries?approval=pending` 可還原待審核 Filter
- [ ] 既有搜尋、日期、方向、分類、帳戶、車輛、審核與頁碼可同步 URL
- [ ] Dashboard 帶入的 Filter 可再修改或清除
- [ ] 不改變既有 MoneyEntry CRUD、approval 或資料可見範圍

### 2.3 後端最小調整

- [ ] 若現有 Vehicle index 不足，僅擴充既有 Request／Service 以支援多狀態與 `is_preparation_completed`
- [ ] 驗證狀態仍只允許 `preparing`、`listed`、`reserved`、`sold`、`cancelled`
- [ ] 不新增欄位、migration、狀態或 workflow
- [ ] 保留既有單一狀態 API 呼叫行為，避免其他頁面回歸
- [ ] 補 API validation、組合 Filter、分頁與相容性測試

---

## 3. Dashboard API

### 3.1 工作概況

- [ ] 待整備：`status=preparing AND is_preparation_completed=false`
- [ ] 待上架：`status=preparing AND is_preparation_completed=true`
- [ ] 待交車：`status=reserved`
- [ ] 待審核收支：`approval_status=pending`，僅 admin 回傳
- [ ] 所有角色取得三個非財務工作 KPI
- [ ] 不加入待補獎金 KPI

### 3.2 經營概況

- [ ] 在庫數：`status IN (preparing, listed, reserved)`
- [ ] sales 只取得在庫數
- [ ] admin／manager 取得現金餘額、本月收入、本月支出、本月毛利、本月成交與在庫數
- [ ] 現金餘額沿用現有 Cash Account Dashboard 口徑
- [ ] 本月收入／支出只計 approved MoneyEntry
- [ ] 本月毛利沿用既有 approved-only 單車毛利方式
- [ ] 本月成交依 `sold_at` 與 `Asia/Taipei` 月份邊界
- [ ] 所有金額使用整數並由後端 Service 計算

### 3.3 近 30 天趨勢

- [ ] 日期範圍包含今天並固定回傳 30 個連續日資料點
- [ ] 日期依 `Asia/Taipei` 按日分組
- [ ] 近 30 天成交量依 `sold_at` 每日計數
- [ ] 近 30 天毛利沿用 approved-only 單車毛利並依 `sold_at` 歸日
- [ ] 現金變化只計 Cash Account 與 approved MoneyEntry
- [ ] 現金變化回傳每日期末餘額，不重新定義財務公式
- [ ] 無成交或毛利的日期回傳 0
- [ ] 無當日現金異動時延續前一日期末餘額
- [ ] sales 只取得成交量趨勢
- [ ] admin／manager 取得全部三項趨勢

### 3.4 Service、Resource 與權限

- [ ] Dashboard Controller 保持薄
- [ ] 統計集中於既有 Dashboard Service 或其最小拆分服務
- [ ] 維持同一份一致性資料快照，避免各 KPI 讀到不同時間點
- [ ] 角色遮蔽由後端 Resource／DTO 執行，不只靠前端隱藏
- [ ] sales 原始 JSON 不存在現金、收入、支出、毛利或現金趨勢欄位
- [ ] unknown role 採白名單失敗安全，不取得財務資料
- [ ] 不從前端拼湊正式統計

### 3.5 後端測試

- [ ] 三個工作 KPI 定義測試
- [ ] admin 待審核 KPI 與 manager／sales 原始 JSON 缺欄測試
- [ ] 在庫數排除 `sold`、`cancelled` 測試
- [ ] 本月收入／支出／毛利 approved-only 測試
- [ ] pending／rejected 不影響財務 KPI 與趨勢測試
- [ ] 30 天包含今天、共 30 點、無資料補點測試
- [ ] `Asia/Taipei` 月份與日期邊界測試
- [ ] 現金每日餘額與無異動日延續測試
- [ ] sales 財務欄位原始 JSON 遮蔽測試
- [ ] admin／manager 完整資料測試

---

## 4. Dashboard 前端

### 4.1 頁面結構

- [ ] 依序呈現 Action Bar、工作概況、經營概況、趨勢分析
- [ ] 移除舊 Dashboard 混合排列與非 v1.4 指定卡片
- [ ] 不顯示 Table、車輛列表、車輛卡片列表或展開明細
- [ ] loading、error、空資料狀態不破壞四區塊資訊層級

### 4.2 Action Bar

- [ ] 建立收車只對 admin／manager 顯示並導向既有建立車輛頁
- [ ] 回報收支對三角色顯示並導向既有一般收支建立頁
- [ ] 建立客戶對三角色顯示並導向既有建立客戶頁
- [ ] 回報整備支出對三角色顯示並導向 `/vehicles?status=preparing`
- [ ] 不新增選車 Modal、整備支出頁或新的 Workflow
- [ ] 不增加第五個快捷功能

### 4.3 KPI Card

- [ ] 待整備導向車輛模組並帶入 `preparing + is_preparation_completed=false`
- [ ] 待上架導向車輛模組並帶入 `preparing + is_preparation_completed=true`
- [ ] 待交車導向 `/vehicles?status=reserved`
- [ ] admin 的待審核收支導向 `/money-entries?approval=pending`
- [ ] 在庫數導向車輛模組並帶入三個在庫狀態
- [ ] 經營 KPI 導向對應車輛、收支或資金帳戶模組並保留對應 Filter
- [ ] 卡片只能導流，不得展開 Dashboard 明細
- [ ] 使用語意正確的 link／button、可見 focus 與完整可點擊區域

### 4.4 角色呈現

- [ ] sales 顯示三個工作 KPI、在庫數與近 30 天成交量
- [ ] sales 不顯示待審核收支與任何財務 KPI／趨勢
- [ ] manager 顯示三個工作 KPI、完整經營概況與三項趨勢
- [ ] manager 不顯示待審核收支
- [ ] admin 顯示全部 v1.4 指定內容
- [ ] 前端角色判斷與後端白名單一致

### 4.5 趨勢圖

- [ ] 近 30 天成交量使用低噪音趨勢圖
- [ ] 近 30 天毛利使用低噪音趨勢圖
- [ ] 現金變化使用低噪音趨勢圖
- [ ] 不新增其他圖表或報表
- [ ] 圖表具備可讀標題、單位與非純顏色辨識方式
- [ ] Mobile 下不造成整頁水平 overflow
- [ ] light／dark mode 均可讀
- [ ] 若需新增前端圖表 dependency，先確認必要性、維護性與 bundle 影響，不藉此擴張功能

---

## 5. Vehicle List API 與 Resource

- [ ] 列表只 eager load 每台車既有封面照片所需資料，避免 N+1
- [ ] Vehicle list Resource 回傳封面縮圖所需最小欄位
- [ ] 無封面照片時回傳明確 `null`，不使用假圖
- [ ] 不回傳完整照片相簿
- [ ] 不因 Card Grid 新增收購價、毛利、資金帳戶或收支資料
- [ ] 保留 admin／manager／sales 既有後端欄位遮蔽契約
- [ ] 補封面照片、無照片、N+1／查詢行為與敏感欄位測試

---

## 6. Vehicle Card Grid

### 6.1 Card 內容

- [ ] 顯示封面圖片或 Design System 定義的空圖狀態
- [ ] 顯示品牌、車型、年份、顏色、車牌、狀態與開價
- [ ] admin／manager 額外顯示底價
- [ ] sales 列表卡片不顯示底價
- [ ] 所有角色的列表卡片不顯示收購價或毛利
- [ ] 卡片可清楚進入既有車輛詳細頁
- [ ] 保留既有車輛狀態 Badge 語意

### 6.2 Grid 與狀態

- [ ] Desktop 使用 Card Grid
- [ ] Tablet 固定 2 欄
- [ ] Mobile 固定 1 欄
- [ ] 移除車輛列表 Table 與橫向捲動依賴
- [ ] loading、error、無資料、無搜尋結果使用 Card Grid 相容狀態
- [ ] 分頁在 Desktop 與 Mobile 均可操作
- [ ] 不改動車輛詳細頁既有 Workflow

### 6.3 預設與完成狀態 Filter

- [ ] 初次進入只顯示 `preparing`、`listed`、`reserved`
- [ ] `sold`、`cancelled` 預設隱藏
- [ ] 使用者可透過 Filter 顯示 `sold`、`cancelled`
- [ ] Dashboard query 可覆蓋預設狀態集合
- [ ] 待整備與待上架可依 `is_preparation_completed` 正確分流

---

## 7. Filter UI 與 Mobile Drawer

- [ ] Desktop／Tablet 顯示可快速操作的搜尋與 Filter 區
- [ ] Mobile 使用 Filter Drawer
- [ ] Drawer 與 Desktop Filter 共用同一份狀態與 URL 契約
- [ ] Drawer 有明確開啟、套用、清除與關閉操作
- [ ] Drawer 開啟時處理 focus、背景捲動與鍵盤關閉
- [ ] Filter 顯示目前已套用條件
- [ ] 清除後回到工作區預設在庫狀態
- [ ] 搜尋、篩選與分頁支援大量資料，不在前端載入全部車輛

---

## 8. App Layout、Header 與 Safe Area

- [ ] 全域 Header 保持現有結構與職責
- [ ] Header 不加入建立收車、回報收支、建立客戶或回報整備支出
- [ ] Header 不加入通知功能或通知預留版位
- [ ] 不新增網站 Footer 或 Footer 導覽
- [ ] 修正 iOS／行動裝置 Safe Area 底色
- [ ] 修正手機底部灰邊與多餘留白
- [ ] 內容底部保留足夠觸控與捲動空間
- [ ] Header、Sidebar、主內容與底部區域在 light／dark mode 視覺銜接一致
- [ ] Sidebar 在 Mobile 的開關、overlay、focus 與 route change 行為維持可用

---

## 9. Frontend 品質與契約測試

- [ ] Dashboard 與 Vehicle types 對齊 API 契約
- [ ] API URL 仍集中於 `frontend/src/api`
- [ ] 不在元件內散落 endpoint URL
- [ ] Filter parse／serialize 純函式補單元測試（若現有測試架構可用）
- [ ] Dashboard 角色顯示、KPI 連結與 Action Bar 行為補前端測試（若現有測試架構可用）
- [ ] Vehicle Card 欄位與角色差異補前端測試（若現有測試架構可用）
- [ ] loading、error、空資料與 API validation error 有明確處理
- [ ] 不使用假 KPI、假趨勢或假車輛圖片

若專案仍未配置前端測試 runner，不為 v1.4 擴張建立大型測試架構；以 typecheck、lint、build、後端契約測試與 browser manual smoke 補足，並在交接文件明確記錄。

---

## 10. 自動驗證

### 10.1 Backend

- [ ] 執行 Dashboard 相關 PHPUnit 測試
- [ ] 執行 Vehicle list／Resource／Filter 相關 PHPUnit 測試
- [ ] 執行 MoneyEntry approval 與角色遮蔽相關 PHPUnit 測試
- [ ] 執行 Timezone boundary 相關 PHPUnit 測試
- [ ] 執行 backend 完整 PHPUnit regression
- [ ] 確認沒有 migration／schema 變更

### 10.2 Frontend

- [ ] `npm run lint`
- [ ] `npx tsc -b --noEmit` 或專案可用 typecheck
- [ ] `npm run build`
- [ ] 檢查 production bundle 與新增圖表 dependency 影響（若有）

### 10.3 靜態檢查

- [ ] 搜尋元件內散落 API URL
- [ ] 搜尋硬編碼色彩並確認使用語意 token
- [ ] 搜尋 Dashboard Table、車輛列表或展開明細殘留
- [ ] 搜尋前端自行計算正式金額或趨勢
- [ ] 檢查原始 JSON 未授權財務欄位遮蔽

---

## 11. Browser Manual Smoke

### 11.1 角色

- [ ] admin Dashboard 顯示四個 Action、四個工作 KPI、完整經營概況與三項趨勢
- [ ] manager Dashboard 不顯示待審核 KPI，顯示其餘指定內容
- [ ] sales Dashboard 不顯示建立收車、待審核或任何財務資料
- [ ] sales 車輛卡片不顯示底價、收購價或毛利
- [ ] admin／manager 車輛卡片顯示底價但不顯示收購價或毛利

### 11.2 導流與 Filter

- [ ] 四個 Action Bar 快捷功能導向正確既有流程
- [ ] 回報整備支出導向 preparing 車輛工作區，未新增 Modal／頁面
- [ ] 待整備、待上架、待交車、待審核 KPI 導流與預載 Filter 正確
- [ ] Filter 可再修改、清除並支援搜尋與分頁
- [ ] reload、上一頁與下一頁可保留 Filter
- [ ] `sold`、`cancelled` 預設隱藏但可手動顯示

### 11.3 RWD 與主題

- [ ] 320px
- [ ] 375px
- [ ] 390px
- [ ] 768px，Vehicle Card 為 2 欄
- [ ] Desktop，Vehicle Card Grid 正常
- [ ] 所有指定寬度無整頁水平 overflow
- [ ] Mobile Filter Drawer 可完整操作
- [ ] iOS Safe Area／底部區域無灰邊
- [ ] light mode 可讀
- [ ] dark mode 可讀
- [ ] 鍵盤 focus、Modal／Drawer 關閉與觸控區可用

---

## 12. 文件與交接

- [ ] 更新 `UI.md`
- [ ] 更新 `backend/API.md` 的 Dashboard 與 Vehicle Filter／封面縮圖契約
- [ ] 更新 `README.md` 的 v1.4 狀態與驗證方式
- [ ] 更新 `CLAUDE.md` 的 v1.4 審查邊界
- [ ] 更新 `docs/current-state.md`
- [ ] 新增 `docs/v1.4-smoke-report.md`
- [ ] 新增 `docs/v1.4-handoff.md`
- [ ] 記錄自動測試指令、通過數、skip 數與未執行原因
- [ ] 記錄 RWD、light／dark mode 與角色 manual smoke 結果
- [ ] PLAN 只勾選實際完成且已驗證項目

---

## 13. v1.4 不做

以下勾選代表確認「刻意不做」，不是已實作：

- [ ] Database Schema 或 migration
- [ ] 新的 Business Logic 或財務公式
- [ ] 新 Workflow、狀態、角色或權限
- [ ] 新整備支出 Modal 或頁面
- [ ] Dashboard Table、車輛列表或展開明細
- [ ] 待補獎金 KPI 或薪資流程調整
- [ ] 通知功能或通知預留版位
- [ ] 新網站 Footer 或 Footer 導覽
- [ ] 大型圖表或三項以外的趨勢報表
- [ ] 多據點、多業務的新資料模型或功能
- [ ] 企劃書未列出的進階功能

---

## 14. 完成定義

v1.4 視為完成，必須同時滿足：

- [ ] Dashboard 與模組工作區職責分離
- [ ] Dashboard 四區塊與角色顯示符合企劃
- [ ] 所有 KPI 只導流且 Filter 可由 URL 還原
- [ ] 回報整備支出完全重用既有流程
- [ ] 三項 30 天趨勢口徑、資料點與權限正確
- [ ] sales 原始 JSON 不含財務資料
- [ ] Vehicle Card Grid、欄位、封面圖與角色差異正確
- [ ] 車輛預設隱藏 `sold`、`cancelled` 且仍可篩選顯示
- [ ] Mobile Filter Drawer 與 Desktop Filter 行為一致
- [ ] Header 無快捷功能、通知或通知預留版位
- [ ] Safe Area、手機底部灰邊與底部留白完成修正
- [ ] `UI.md` 完成指定 Design System 規範
- [ ] 無 schema、Business Logic、Workflow 或權限擴張
- [ ] backend 相關與完整 regression 通過
- [ ] frontend lint、typecheck、production build 通過
- [ ] 指定寬度、light／dark mode 與三角色 browser manual smoke 通過
- [ ] smoke report、handoff、current state 與 API 文件同步

---

## 15. 建議 Commit 拆分

```text
docs: 建立 v1.4 UI UX 改版規格與設計系統
feat: 擴充 Dashboard 工作概況與趨勢摘要
feat: 支援模組 Filter URL 導流與還原
feat: 將車輛列表改為響應式卡片工作區
fix: 改善行動版 Filter 與 Safe Area 版面
test: 補齊 Dashboard 權限與車輛列表契約測試
docs: 完成 v1.4 smoke 與交接文件
```

實際 commit 應維持小步、可 review、可回滾。除非使用者明確要求，不自動 commit 或 push。
