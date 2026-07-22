# ERPV2 UX Design System

本文件是 ERPV2 後台的正式 UI／UX 規範。v1.4 沿用既有 Midnight 品牌方向、slate 中性色與語意色彩 token，不新增第二套品牌、平行元件庫或一次性頁面風格。

ERPV2 的資訊架構固定為：

```text
Dashboard = 總覽、KPI、趨勢、快速導流
功能模組 = 搜尋、篩選、分頁、查看與實際操作
```

Dashboard 不承載 Table、車輛列表、明細展開或業務 Workflow；卡片與快捷操作只導向既有模組。前端不得使用假資料或自行拼湊正式金額。

---

## 1. 設計方向

- 現代後台、低噪音、高可讀性，桌機資訊密度適中。
- 使用留白、表面層級與細邊框建立結構，不依賴裝飾性陰影。
- Midnight 藍是唯一品牌互動色；狀態色只表達狀態，不作品牌裝飾。
- light／dark mode 使用同一組語意，不在元件內硬編碼 light-only 顏色。
- Desktop 優先；Mobile 仍須能完成既有流程，不可只縮小桌機版。

---

## 2. Color

### 2.1 品牌與中性色

既有 Midnight 品牌方向維持不變：

| 用途 | Light | Dark |
|---|---|---|
| App 背景 `bg` | `#F8FAFC` | `#0B1120` |
| 主要表面 `surface` | `#FFFFFF` | `#131A2A` |
| 次要表面 `surface-2` | `#F1F5F9` | `#1A2336` |
| 主文字 `fg` | `#0F172A` | `#E8EDF5` |
| 次文字 `fg-muted` | `#475569` | `#94A3B8` |
| 弱化文字 `fg-subtle` | `#94A3B8` | `#64748B` |
| 邊框 `border` | `#E2E8F0` | `#25314A` |
| 強邊框 `border-strong` | `#CBD5E1` | `#33415C` |
| 主要互動 `primary` | `#1E3A8A` | `#3B60C4` |
| 主要互動 hover | `#172E6E` | `#4B72D6` |
| Focus ring `ring` | `#1E3A8A` | `#3B82F6` |
| 固定深色 Sidebar focus `sidebar-ring` | `#3B82F6` | `#3B82F6` |

元件使用 `bg-bg`、`bg-surface`、`bg-surface-2`、`text-fg`、`text-fg-muted`、`border-border`、`border-border-strong`、`bg-primary`、`text-primary-fg` 與 `ring-ring` 等語意 class。禁止直接使用 hex 或以 `slate-*`、`blue-*` 取代已有語意 token。

Sidebar 是不隨 light／dark mode 改變的固定深色表面，內部 focus 必須使用 `ring-sidebar-ring`，不得使用 light mode 對比不足的全域 `ring-ring`。

Dark mode 以 `bg → surface → surface-2` 的亮度差與邊框建立 elevation；不得只靠陰影分層。

### 2.2 狀態色

通用狀態固定為 `success`、`warning`、`error`、`info`。狀態 Badge 必須同時使用底色、文字、邊框、icon 與文字，不得只靠色相傳達。

車輛狀態固定映射：

| 狀態 | 顏色語意 | 顯示文字 | Icon |
|---|---|---|---|
| `preparing` | Amber／進行中 | 整備中 | Wrench |
| `listed` | Blue／上架 | 上架中 | Tag |
| `reserved` | Violet／保留 | 保留中 | Bookmark |
| `sold` | Emerald／完成 | 已售出 | CircleCheck |
| `cancelled` | Slate／停用 | 取消／退車 | XCircle |

Destructive 操作使用 `error` 語意，必須有明確文字；不可只顯示紅色 icon。

---

## 3. Typography 與數字

字型沿用：

```text
font-sans = Inter, "Noto Sans TC", system-ui, -apple-system, sans-serif
font-mono = "JetBrains Mono", ui-monospace, Menlo, monospace
```

| 層級 | 建議 class | 用途 |
|---|---|---|
| 頁面標題 | `text-2xl font-semibold tracking-tight` | 每頁唯一 `h1` |
| 區塊標題 | `text-lg font-semibold` | Dashboard／模組主要區塊 `h2` |
| Card 標題 | `text-sm font-medium` 或 `text-base font-semibold` | 依卡片資訊密度 |
| 內文 | `text-base` | 說明與 Mobile 表單主要文字 |
| 密集資料 | `text-sm` | Table、Filter、次要資訊 |
| 輔助文字 | `text-xs text-fg-muted` | 單位、提示、時間 |
| KPI 數字 | `text-3xl font-bold tabular-nums` | Dashboard 主要數值 |

- 中文內文避免小於 14px；Mobile 表單輸入維持至少 16px，避免 iOS 自動縮放。
- 金額、數量、里程與會變動的統計使用 `tabular-nums`。
- 金額由後端回傳整數，前端只做千分位與幣別格式化。
- 庫存編號或技術識別碼可使用 `font-mono`，一般車牌不強制使用等寬字。
- 不以字重或全大寫製造多餘層級；同頁層級保持一致。

---

## 4. Spacing、圓角與陰影

以 4px 為基本間距單位：

| 情境 | 間距 |
|---|---|
| 同一控制項內 icon 與文字 | 8px |
| 同一表單欄位 label／control／error | 4–8px |
| 表單欄位間 | 16px |
| Card 內距 | 20–24px；Mobile 可降為 16px |
| 同區塊 Card／Grid 間 | 16px |
| 主要頁面區塊間 | 24–32px |
| 頁面水平內距 | Mobile 16px；Tablet／Desktop 24px |

圓角沿用 `sm=6px`、`md=8px`、`lg=12px`、`xl=16px`。Badge 才使用 full radius。一般 Card 使用 `rounded-xl`；大型面板或 Modal 可使用 `rounded-2xl`。

Light mode 只使用低噪音 `shadow-xs`／`shadow-sm`；浮層與 Modal 可用 `shadow-lg`。Dark mode 主要依靠表面與邊框，不增加重陰影。

---

## 5. Card

### 5.1 一般 Card

- 適用於一組有明確邊界的摘要或工作內容，不拿 Card 包住每一段文字。
- 基本外觀為 `surface + border + rounded-xl`，陰影可省略或只用 `shadow-sm`。
- 一般 Card 預設不可點擊；不可用 hover 假裝可互動。
- 可互動 Card 必須使用語意正確的 `<a>`／`Link` 或 `<button>`，整張卡片為單一點擊目標。
- hover、active、focus-visible 與 disabled 狀態都必須明確；鍵盤 focus 不可被移除。
- Card 內不得巢狀放置彼此競爭的主要點擊區。若有多個動作，改用一般 Card 加明確按鈕。

### 5.2 Dashboard KPI Card

- KPI Card 只顯示標題、正式數值、單位與必要的簡短說明，不展開明細。
- 整張 Card 導向對應模組並帶入正式 URL Filter；不得以 `div onClick` 實作。
- hover：使用 `surface-2` 或邊框強化；active：輕微表面變化；focus-visible：2px `ring-ring` 且有 offset。
- 數值使用 `text-3xl font-bold tabular-nums`，標題使用 `text-sm text-fg-muted`。
- 未授權 KPI 不渲染；不能以 0、空字串或 disabled Card 代替後端欄位遮蔽。
- loading 使用保持卡片尺寸的狀態；API error 需有可讀訊息，不以假數字填充。

### 5.3 Vehicle Card

Vehicle Card 是車輛工作區列表的正式呈現，欄位固定為：

- 封面縮圖或一致的空圖狀態
- 品牌、車型、年份、顏色、車牌
- 車輛狀態 Badge
- 開價
- admin／manager 額外顯示底價

所有角色的列表 Card 都不顯示收購價、成交毛利或資金帳戶；sales 不顯示底價。後端 Resource 仍是敏感資料的正式邊界。

呈現規則：

- 圖片是辨識主體，固定使用 `aspect-[4/3]`、`object-cover`，避免不同原圖使 Grid 跳動。
- 無封面時回傳 `null`，顯示語意一致的車輛 icon、淺表面與「尚無照片」文字；禁止假車照片。
- 標題以「品牌 車型」為主，年份、顏色、車牌為次要資訊；缺值用 `—`，不偽造內容。
- 整張 Card 使用 `Link` 進入既有車輛詳細頁，具完整可點擊區與可見 focus。
- Desktop 依容器寬度使用 Grid；Tablet 固定 2 欄；Mobile 固定 1 欄。
- loading、API error、無資料與無搜尋結果必須占用 Grid 的正常內容寬度，不回退成 Table。

---

## 6. Button 與 Link

層級固定為：

| 層級 | 用途 |
|---|---|
| Primary | 頁面最主要的正向動作；同一視覺區域通常只保留一個 |
| Secondary／Outline | 次要但明確的操作 |
| Ghost | 低優先、局部或工具型操作 |
| Destructive | 刪除、駁回等高風險操作 |
| Text Link | 導覽、查看詳情或低強度輔助動作 |

- Button 使用 `rounded-lg`、`font-medium`，Desktop 視覺高度可為 40px，但觸控目標至少 44×44px。
- icon-only Button 必須有 `aria-label` 與至少 44×44px 點擊區；一般業務動作優先使用 icon + 文字。
- hover 與 active 可使用 150–200ms transition；不得使用會造成版面位移的縮放。
- `focus-visible` 使用 2px `ring-ring` 與適當 offset；不得用 `outline-none` 後不補 focus。Drawer／Modal 開啟後由程式主動承接 focus 的首個控制項可使用 `focus:`，確保滑鼠觸發後同樣看得到焦點位置。
- loading 時保留原按鈕寬度、顯示進度語意並 disabled，避免重複送出。
- disabled 必須同時阻止互動、降低視覺強度並保留可讀文字；不可只改游標。
- Destructive 不得與 Primary 緊貼；需留出空間並在必要時要求確認。
- 導覽使用 `Link`／`<a>`，送出或切換狀態使用 `<button>`，不可混用。

本節是 v1.4 的收斂目標契約。既有頁面仍存在 `focus:ring-ring/30` 等舊寫法，須在第 2、4、6、7 部分修改對應頁面時逐步收斂；第 1 部分不宣稱全站既有控制項已完成一致化，也不為文件一致性一次改寫無關頁面。

---

## 7. Form

- 每個欄位都要有永遠可見且可程式關聯的 label；placeholder 只提供範例，不能取代 label。
- 必填欄位在 label 顯示 `*`，並保留原生 `required` 或等價的 `aria-required`。
- 錯誤訊息顯示在欄位下方，使用 `text-error`，說明原因與修正方式；以 `aria-describedby` 關聯。
- API 422 per-field errors 必須回到對應欄位；非欄位錯誤顯示在表單頂部。
- 控制項使用 `surface + fg + border-strong`；focus 使用 `border-primary + ring-ring`。
- disabled 與 read-only 必須可區分，且不能透過顏色暗示仍可編輯。
- Checkbox／radio 的文字標籤也是可點擊區，觸控目標至少 44px 高。
- Mobile 單欄優先，輸入框寬度填滿容器；並排欄位只有在 320px 仍不擁擠時才保留。
- 表單送出期間避免重複提交；失敗後保留使用者已輸入內容。

---

## 8. Modal 與 Drawer

### 8.1 Modal

- Modal 只處理短而聚焦的現有操作；不得為快捷操作複製一套既有 Workflow。
- 開啟後 focus 移到標題、第一個可操作控制項或錯誤摘要；focus 必須限制在 Modal 內。
- `Escape` 可關閉非破壞性 Modal；明確的關閉按鈕永遠可見且有 accessible name。
- 關閉後 focus 回到觸發元素。送出中或可能遺失輸入時，不可因誤點 backdrop 無提示關閉。
- Overlay 覆蓋 viewport 並鎖住背景捲動；內容區使用 `max-height` 與自身 vertical overflow。
- Desktop 可置中並限制寬度；Mobile 使用接近全寬的底部或置中面板，保留 safe area 與 16px 外距。
- 標題、內容、錯誤與 action 區層級固定；Mobile action 可垂直堆疊，主要按鈕仍清楚。

### 8.2 Mobile Filter Drawer

- Drawer 只是 Desktop Filter 的呈現變體，兩者共用同一份 draft state、URL parse／serialize 與 API 契約。
- 開啟按鈕顯示目前已套用條件數；Drawer 提供「套用」、「清除」與「關閉」。
- 開啟後處理 focus trap、`Escape`、背景捲動與關閉後 focus 歸還。
- 「套用」才把 draft filter 寫入 URL；「清除」回到該模組的正式預設值。
- Drawer 內控制項垂直排列且至少 44px 高；底部 action 區考慮 `env(safe-area-inset-bottom)`。

---

## 9. Table

- Table 只用於需要逐欄比較的結構化資料，例如收支、資金帳戶、使用者與稽核紀錄。
- Dashboard 不使用 Table；v1.4 車輛工作區列表不得再使用 Table，固定改用 Vehicle Card Grid。
- 表頭使用 `surface-2 + text-fg-muted + font-medium`；列高至少 48px。
- 金額右對齊並使用 `tabular-nums`；可排序欄位提供可操作表頭與 `aria-sort`。
- 空狀態顯示原因與適當下一步，不留下空白表身。
- Desktop 可以容器內水平捲動；Mobile 優先改成語意清楚的 Card／stacked layout，不能讓整頁水平 overflow。
- 不能為了避免 overflow 移除關鍵欄位或破壞後端權限語意。

---

## 10. Filter 與 URL 狀態

URL query string 是列表 Filter、分享、reload 與瀏覽器上一頁／下一頁還原的正式來源。

- 搜尋、狀態、日期、方向、分類、帳戶、車輛、審核狀態、整備完成與頁碼等既有條件依模組同步至 URL。
- 頁面初始化先 parse URL；URL 有明確條件時優先於模組預設值。
- Filter 變更後頁碼重設為 1；只有分頁操作可保留其他 Filter 並改變 page。
- 清除 Filter 回到該模組正式預設值，不一定代表完全沒有 query。
- 車輛工作區的清除結果固定為 `preparing`、`listed`、`reserved`；Dashboard 帶入的單一／組合狀態與 `is_preparation_completed` 可覆蓋預設。
- `/money-entries?approval=pending` 必須能還原待審核條件；前端顯示名稱可與 API key 不同，但 parse／serialize 必須集中轉換。
- Desktop／Tablet 顯示 inline Filter 區；Mobile 使用 Drawer，兩者不得有不同的資料規則。
- 已套用條件使用文字或 removable chip 顯示，不能只靠控制項內目前值讓使用者猜測。
- Filter 結果由分頁 API 取得，不得為篩選而在前端載入全部資料。

---

## 11. Responsive Breakpoint 與驗收寬度

沿用 Tailwind mobile-first breakpoint，並以元件行為而非裝置名稱判斷：

| 範圍 | 主要行為 |
|---|---|
| `< 640px` Mobile | 單欄、Filter Drawer、可堆疊 action、44px 觸控目標 |
| `640–1023px` Tablet | Vehicle Card 固定 2 欄；Filter 保持快速操作但可換行 |
| `>= 1024px` Desktop | Sidebar 常駐、Dashboard／Vehicle 使用較寬 Grid |

正式驗收至少涵蓋：

- 320px
- 375px
- 390px
- 768px（Vehicle Card 必須 2 欄）
- 1440px 或實際桌機寬度

每個寬度都要檢查 light／dark mode、整頁無水平 overflow、觸控目標、鍵盤 focus、Modal／Drawer、長文字與 API error。iOS 須檢查四向 `safe-area-inset-*`，App 背景、Header、Sidebar overlay 與底部不得露出灰邊，橫向瀏海與圓角不得遮住內容。

App Layout 的 Safe Area 契約固定如下：

- viewport 必須啟用 `viewport-fit=cover`；`html`、`body`、React root 與 App Shell 使用同一個 `bg` 語意底色。
- App Shell 使用 `100dvh`，並保留 `100vh` fallback；不得以固定高度截斷長頁面。
- Header 承接頂部與左右 inset；主內容承接左右 inset，保留原有 Mobile／Tablet 水平內距後再額外避開橫向瀏海與圓角。
- Mobile Sidebar 承接頂部、底部與左側 inset；深色表面仍延伸至螢幕邊緣，只有內容內縮，導覽內容不足高度時由 Sidebar 內部捲動。
- 主內容底部至少保留 32px，並再加上 `safe-area-inset-bottom`，避免最後一個控制項貼住 Home Indicator。
- light／dark mode 切換時，App 背景、Header 表面與瀏覽器 `theme-color` 必須同步，不得露出固定淺灰底。
- Safe Area 不是 Footer；不得因此新增 Footer、底部導覽或功能入口。

---

## 12. 可用性與無障礙基線

- 所有互動可由鍵盤操作，Tab 順序符合畫面順序。
- 使用 `:focus-visible` 提供清楚 focus；不可只靠 hover 表達互動。
- Icon 裝飾設為 `aria-hidden`；icon-only 控制需有 accessible name。
- Loading、成功與錯誤依情境使用 `aria-live`，但不搶走使用者 focus。
- 狀態不得只靠顏色；搭配 icon、文字、形狀或位置。
- 圖片有符合用途的替代文字；純裝飾圖片使用空 alt。
- 動畫尊重 `prefers-reduced-motion`；不使用閃爍或不必要的大幅位移。
- 頁面只保留一個主要 `h1`，標題層級不可跳號。

---

## 13. 共用元件策略

- 先確認至少兩個實際使用處具有相同語意、狀態與行為，再抽成共用元件。
- 目前跨頁共用的 Badge、ThemeToggle、CustomerSelect 應維持單一責任與語意 token。
- Dashboard KPI Card、Vehicle Card 與 Filter 可在 v1.4 實際實作時抽取它們真正共用的 focus、surface 或 URL 行為；不得先建立巨大 `Card`、`Form` 或 `Filter` 萬用元件。
- 頁面特有的版面組合留在頁面內；共用元件不接收大量布林 props 來模擬所有變體。
- API URL 集中於 `frontend/src/api`；共用呈現元件不自行發送業務 API 或重算正式統計。
- 權限與敏感欄位仍由後端 Policy／Resource 正式保護；共用元件只負責呈現已授權資料。

---

## 14. v1.4 明確不做

- 不新增品牌提案或可切換品牌皮膚。
- 不建立完整通用元件平台、Storybook 或平行 Design System。
- 不新增通知、通知預留位、網站 Footer 或 Footer 導覽。
- 不以 Modal 複製車輛、收支、客戶或整備既有流程。
- 不增加 Dashboard Table、車輛列表、展開明細、薪資 KPI 或未指定圖表。
- 不因 UI 改版修改 Database Schema、Business Logic、Workflow、角色或權限。
