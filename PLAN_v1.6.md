# PLAN_v1.6.md — ERPV2 介面文案精簡與資訊層級優化

本清單對應 `企劃書_v1.6.md`。

v1.6 是小型純前端 UX cleanup 版本：系統性移除重複副標與教學文字，縮短常駐 help text，將只在特定狀態成立的資訊改為條件式提示，同時保留金額口徑、不可逆後果、錯誤與無障礙契約。

正式原則：

```text
正常狀態保持安靜
例外、限制、風險與錯誤才說明
```

本版本不得新增商業功能、Database Schema、Backend API、Workflow、角色、權限、報表、通知、Onboarding 或 Tooltip 平台。

---

## 0. 前置盤點與範圍鎖定

- [x] 閱讀 `企劃書_v1.6.md` 與本 PLAN
- [x] 閱讀 `UI.md`、`README.md`、`docs/current-state.md`
- [x] 閱讀 `企劃書_v1.4.md`、`PLAN_v1.4.md`、`docs/v1.4-handoff.md`
- [x] 閱讀 `企劃書_v1.5.md`、`PLAN_v1.5.md`、`docs/v1.5-handoff.md`
- [x] 閱讀 `AGENTS.md`、`CLAUDE.md`
- [x] 檢查 Git status 並保留使用者既有無關修改
- [x] 記錄 v1.6 起始 commit
- [x] 確認 `v1.5-smoke-passed` 為正式基準
- [x] 確認 v1.6 不修改 backend、migration、API contract、roles、permissions、business logic
- [x] 確認 v1.6 不新增頁面、路由、KPI、圖表、報表、通知或教學導覽
- [x] 確認不使用假資料
- [x] 確認不建立 Tooltip／Popover 平台或新 runtime dependency
- [x] 確認文案刪減不得破壞 visible label、per-field error、heading hierarchy 或 `aria-describedby`

### 0.1 建立盤點基準

- [x] 列出 `frontend/src/pages` 所有正式頁面
- [x] 列出 `frontend/src/components` 所有可能顯示輔助文字的共用元件
- [x] 搜尋所有 `text-fg-muted`、`text-fg-subtle`、description、help、hint、warning、placeholder
- [x] 搜尋所有頁面標題後緊接的 `<p>` 副標
- [x] 搜尋所有 Card description 與 section subtitle
- [x] 搜尋所有 `aria-describedby` 與對應 ID
- [x] 搜尋所有 `title=`，確認是否被當成唯一提示來源
- [x] 搜尋測試內依賴完整文案的 query／assertion
- [x] 建立逐頁盤點表或 handoff，記錄每段文字的分類與處理決策

### 0.2 五分類

每段使用者可見輔助文案必須分類：

- [x] A：直接刪除
- [x] B：縮短後保留
- [x] C：改為條件式顯示
- [x] D：高風險／金額口徑／錯誤，必須保留
- [x] E：視覺移除但保留為無障礙描述

不得使用「看起來太多」作為唯一刪除理由。每一處刪除都要確認資訊已由標題、控制項、狀態、錯誤或條件式提示承接。

---

## 1. 更新 UI Copy Design Contract

### 1.1 `UI.md`

- [x] 新增「UI Copy 與輔助文字」正式章節
- [x] 寫入「正常狀態安靜；例外、限制、風險與錯誤才說明」
- [x] 定義頁面副標保留條件
- [x] 定義 section subtitle 保留條件
- [x] 定義 Card description 保留條件
- [x] 定義 form help text 保留條件
- [x] 定義 warning、error、success、empty state 的責任差異
- [x] 定義 A／B／C／D／E 五分類
- [x] 定義重要資訊不得只放在 hover tooltip
- [x] 定義 placeholder 不可取代 visible label
- [x] 定義刪除 help text 時必須同步檢查 `aria-describedby`
- [x] 定義文案風格：簡潔、直接、繁體中文、不行銷、不聊天、不責怪使用者
- [x] 定義同一概念的正式名詞一致性
- [x] 定義 Mobile 不得靠縮小字體解決資訊過多

### 1.2 實作約束

- [x] 不建立巨大 Copy／HelpText 萬用元件
- [x] 只有至少兩個實際使用處具相同語意與行為時才抽共用元件
- [x] 不把企劃書完整規格直接複製到產品 UI
- [x] 不新增 Database-backed copy
- [x] 不新增 i18n 架構
- [x] 不新增 analytics 或 onboarding tracking

### 1.3 文件 review

- [x] 確認新章節不與 v1.4 Design System 衝突
- [x] 確認不改既有品牌、色彩、spacing、RWD 與 Safe Area 契約
- [x] 確認新規則可由實際頁面驗收，不是抽象口號

---

## 2. 全站頁面標題與副標清理

### 2.1 頁面 Header 規則

- [x] 每頁保留唯一 `h1`
- [x] 若副標只重述頁名，直接刪除
- [x] 若副標只說「在這裡可以管理……」，直接刪除
- [x] 若副標包含非直覺範圍、期間或權限，縮短後保留
- [x] 頁面主要動作仍與 `h1` 同層清楚呈現
- [x] 刪除副標後重新檢查 Header spacing
- [x] Mobile Header 不因文字移除產生按鈕擠壓或不平衡留白

### 2.2 正式頁面逐一檢查

- [x] Dashboard
- [x] Login
- [x] Password Change Required
- [x] 我的帳號
- [x] 車輛列表
- [x] 新增車輛
- [x] 車輛詳情
- [x] 收支列表
- [x] 新增收支
- [x] 客戶列表
- [x] 新增客戶
- [x] 客戶詳情
- [x] 資金帳戶
- [x] 員工／帳號管理
- [x] 薪資設定
- [x] 獎金方案
- [x] 薪資月份列表
- [x] 薪資月份詳情
- [x] 待補獎金歸屬
- [x] Audit Log
- [x] 列印頁
- [x] 404／權限阻擋／Session 阻擋等特殊畫面

### 2.3 驗收

- [x] 所有頁面仍可快速辨識目前位置
- [x] 主要動作不依賴被刪除的副標才能理解
- [x] Heading hierarchy 未跳號
- [x] 沒有因刪除 `<p>` 留下不合理 margin

---

## 3. Dashboard 文案精簡

### 3.1 工作概況

- [x] 保留「工作概況」區塊標題
- [x] 移除「點選卡片前往對應工作區處理」
- [x] 移除「待整備」卡片的「整備尚未完成」
- [x] 移除「待上架」卡片的「整備完成，等待上架」
- [x] 移除「待交車」卡片的「已保留，等待完成交車」
- [x] 移除「待審核收支」卡片的「等待核准或駁回」
- [x] 確認 Card 仍有 label、value、unit、icon 與完整 Link
- [x] 確認移除 description 後 Card 高度與 Grid 對齊

### 3.2 經營概況

- [x] 將 approved-only／月份口徑整合為一個區塊級短說明
- [x] 移除與「本月」重複的「完整當月」文字
- [x] 檢查「在庫數」是否需要 description；若 label 已清楚則移除
- [x] 檢查「現金帳面餘額」是否需保留 approved-only 短說明
- [x] 本月收入只保留必要口徑
- [x] 本月支出只保留必要口徑
- [x] 本月毛利保留成交月份與 approved-only 的必要定義，但不重複
- [x] 本月成交不重複「完整當月成交日期」
- [x] 不修改 KPI 欄位、值、角色可見性與 URL Filter

### 3.3 趨勢分析

- [x] 檢查區塊副標「近 30 個連續日，包含今天且截至今日」是否可縮短
- [x] 圖表視覺上保留 title、date range、unit
- [x] 「依車輛成交日期每日統計」視情況移除或轉 `sr-only`
- [x] 毛利 approved-only 定義以單一位置保留
- [x] 現金圖表的「每日期末帳面餘額」屬非直覺定義，縮短後保留
- [x] 不修改 SVG、points、empty state、tooltip、formatValue
- [x] 不修改三角色財務欄位遮蔽

### 3.4 Dashboard tests

- [x] 更新受 description 移除影響的測試
- [x] 保留 KPI Link 與 query string 測試
- [x] 保留 admin／manager／sales 可見性測試
- [x] 補測區塊級 approved-only 說明只出現一次
- [x] 確認圖表 accessible description 仍有效

---

## 4. Auth、強制改密碼與我的帳號

### 4.1 Login

- [x] 檢查頁面副標與登入 placeholder 是否重複
- [x] 保留 username／Email 雙登入必要資訊
- [x] 保留通用登入錯誤，不洩漏帳號存在狀態
- [x] 不新增忘記密碼、自助註冊或管理員聯絡說明
- [x] 不修改 login payload、rate limiter 或 Auth Context

### 4.2 強制改密碼

- [x] 縮短「管理員建立或重設的預設密碼」說明
- [x] 明確保留「完成修改後才能進入營運功能」
- [x] 保留目前密碼、新密碼、確認密碼 labels
- [x] 保留密碼格式必要提示或 per-field error
- [x] 保留成功、失敗、Session stale 與重新登入結果
- [x] 不修改後端 fail-closed gate

### 4.3 我的帳號：個人資料

- [x] 縮短頁面或區塊副標
- [x] username 常駐 help 只保留與 Email 登入的關係
- [x] 已設定 username 時，清除後果只在相關狀態顯示
- [x] 未設定 username 時，不重複顯示兩次 Email fallback
- [x] 字數、允許字元與轉小寫規則改由具體錯誤承接，或保留最短必要格式提示
- [x] 保留 `aria-describedby` 的正確關聯
- [x] 移除 help 後不得留下不存在的 ID
- [x] Email、角色 read-only 呈現不變
- [x] 不修改 self profile payload

### 4.4 我的帳號：修改密碼

- [x] 將密碼規則移至最接近新密碼欄的位置
- [x] 避免區塊副標與 per-field error 重複
- [x] 保留至少 8 字元的必要資訊
- [x] 確認「不可與目前密碼相同」在提交錯誤時可理解
- [x] 不修改 self password endpoint、Session invalidation 與 stale response handling

### 4.5 Tests

- [x] 更新 Login tests
- [x] 更新 Password Change Required tests
- [x] 更新 Account tests
- [x] 驗證 username help 在 null／已有 username 狀態的差異
- [x] 驗證 per-field error 與 `aria-describedby`
- [x] 驗證成功訊息仍存在
- [x] 驗證必要安全文案未被刪除

---

## 5. 員工／帳號管理與資金帳戶

### 5.1 員工／帳號管理 Header

- [x] 保留「員工／帳號管理」`h1`
- [x] 移除「建立與管理員工帳號、角色與基本資料」
- [x] 檢查非 admin 畫面的「僅限管理員操作」是否仍有實際可達情境
- [x] 不以 UI 文案取代既有 route／backend 權限保護

### 5.2 建立員工

- [x] 保留姓名、Email、密碼、角色等 visible labels
- [x] 保留 required marker
- [x] 不新增 username 欄位或改變 v1.5 建帳流程
- [x] 檢查預設密碼與首次改密碼的重要結果是否只在成功後清楚顯示

### 5.3 編輯員工

- [x] 移除「角色請使用列表中的角色下拉選單……」長句
- [x] 不改角色下拉、啟用、停用、重設密碼與刪除操作
- [x] 自己帳號的受限操作原因使用最小但 accessible 的方式呈現
- [x] 不使用只有 hover 可見的 `title` 作為唯一原因
- [x] 避免每列常駐「自己的密碼請至我的帳號修改」造成高度增加
- [x] 保留重設密碼成功後 Session 失效與再次強制改密碼的結果
- [x] 保留 `must_change_password` Badge

### 5.4 資金帳戶

- [x] 移除「啟用／停用請使用列表中的按鈕」等描述控制位置的文字
- [x] 保留帳戶狀態與正式餘額
- [x] 保留停用／啟用的高風險結果或確認
- [x] 不改帳戶 API、餘額計算與 admin-only 權限

### 5.5 Tests

- [x] 更新 UserList tests 或新增必要 contract tests
- [x] 驗證 self 受限操作仍不可執行
- [x] 驗證 reset success message 仍完整
- [x] 驗證 Cash Account 關鍵狀態與操作文字

---

## 6. 薪資模組文案分層

### 6.1 薪資月份列表

- [x] 移除重述頁面用途的副標
- [x] 保留月份狀態、金額與主要動作
- [x] Empty state 說明是否有下一步；避免只寫「目前沒有資料」
- [x] 不改月份建立限制與 API

### 6.2 薪資月份詳情

- [x] 當月未結束 warning 只在條件成立時顯示
- [x] 將 warning 縮成原因＋下一步
- [x] 確認 warning 不與 disabled button reason 重複多次
- [x] 保留 confirmed／paid 鎖定說明
- [x] 保留公司營運保留、公司剩餘分配額、company net 等正式名詞
- [x] 保留異常來源確認的處理方向
- [x] 「獎金設定提示（不阻擋確認）」與阻擋型錯誤視覺／文案分開
- [x] 同一異常不在 summary、table、footer 重複完整長句
- [x] 手動加扣項的「說明」欄位名稱不因 copy cleanup 改動
- [x] 不改計算、重算、確認、發薪或 MoneyEntry

### 6.3 獎金方案

- [x] 「已使用方案永久唯讀；規則變更請建立新版本」縮短但保留
- [x] 只在已使用／唯讀狀態顯示對應規則
- [x] 新方案正常編輯狀態不顯示無關 warning
- [x] 不改版本化方案與生效日期規則

### 6.4 薪資設定

- [x] 檢查「目前沒有可設定的員工」empty state
- [x] 移除一般教學型副標
- [x] 保留佣金啟用、底薪、扣款與異常狀態
- [x] 不改薪資 Profile API 或公式

### 6.5 待補獎金歸屬

- [x] 保留收車人／賣車人缺失原因與下一步
- [x] 縮短重複的「請選擇」與頁面教學
- [x] 保留更新錯誤與成功結果
- [x] 不推測歷史歸屬，不改後端規則

### 6.6 Tests

- [x] 補條件式 warning 顯示／不顯示測試
- [x] 驗證 confirmed／paid 鎖定文案仍存在
- [x] 驗證已使用方案唯讀說明只在適用狀態顯示
- [x] 驗證異常處理指引仍可理解

---

## 7. 車輛模組

### 7.1 車輛列表

- [x] 檢查頁面副標與 Filter 說明
- [x] 保留 active filter chips 與 empty／no result 差異
- [x] 保留「尚無照片」空圖狀態
- [x] 不改 Vehicle Card 欄位、角色遮蔽與 URL Filter

### 7.2 新增車輛

- [x] 移除與 label／required 重複的說明
- [x] 保留同步購車付款的非直覺後果
- [x] 保留付款金額與帳戶缺失錯誤
- [x] 不改 purchase payment transaction 或 payload

### 7.3 車輛詳情與 Workflow Modal

- [x] 檢查上架、收訂金、尾款、整備支出與成交 Modal 的常駐說明
- [x] 保留成交日期影響薪資獎金月份的提示
- [x] 將成交日期提示縮成原因＋結果
- [x] 保留已確認／已發薪月份不能新增成交的阻擋原因
- [x] 保留送出中、錯誤與成功狀態
- [x] 保留說明欄位 label；不因「說明文字太多」誤刪業務資料欄位
- [x] 不改按鈕可見條件、Workflow、idempotency 與 approved-only 檢查

### 7.4 車輛照片

- [x] 保留上傳限制、失敗與重試必要資訊
- [x] 移除只重述按鈕用途的文字
- [x] 保留封面、排序與刪除結果
- [x] 不改 photo API、upload batch、fencing 或 public URL

### 7.5 Tests

- [x] 更新 Vehicle page tests
- [x] 保留 Filter／Card／role presentation tests
- [x] 驗證成交高風險提示仍存在
- [x] 驗證 empty state 與 no result 不混淆

---

## 8. 收支、客戶、Audit 與其他頁面

### 8.1 新增收支

- [x] 縮短「一般營運收支請勿選擇車輛；單車相關收支請務必綁定車輛」
- [x] 確認縮短後仍能避免使用者誤解
- [x] 不改 category、vehicle field、validation 或 payload
- [x] 保留日期、分類、資金帳戶的 per-field error
- [x] 保留 pending／approved 的送出結果

### 8.2 收支列表

- [x] 檢查頁面副標、Filter 說明與 empty state
- [x] 保留 approval status、核准、駁回與錯誤結果
- [x] 不改 URL Filter、分頁、角色可見性與金額遮蔽
- [x] 載入期間不得重新引入過期 pagination meta

### 8.3 客戶

- [x] 移除重述頁面用途的副標
- [x] 保留搜尋、無結果、更新與刪除錯誤
- [x] 檢查 CustomerSelect placeholder 與 help 是否重複
- [x] visible label 不可由 placeholder 取代
- [x] 不改客戶／買方／賣方關聯

### 8.4 Audit Log

- [x] 檢查「追蹤系統登入、資料新增、修改與刪除操作」是否可刪或縮短
- [x] 「紀錄僅供查詢」若為重要邊界則短句保留
- [x] 保留無欄位異動與請求資訊 empty state
- [x] 不改 audit payload、遮蔽或 API

### 8.5 Print

- [x] 不刪正式欄位名稱與收支「說明」資料
- [x] 只處理明顯重複的畫面副標
- [x] 不改 print CSS、資料結構與權限
- [ ] 封板前肉眼檢查紙本／PDF 預覽（承接於 13.2）

---

## 9. 全站狀態文案一致性

### 9.1 Loading

- [x] Loading 文案簡短
- [x] 不以假資料占位
- [x] 不因刪除說明造成 layout shift 過大

### 9.2 Empty state

- [x] 區分「沒有任何資料」與「Filter 沒有結果」
- [x] 只有確實可採取下一步時才顯示 action
- [x] 不用長篇教學填滿空畫面

### 9.3 Error

- [x] Per-field error 留在欄位下方
- [x] General error 留在表單或區塊頂部
- [x] 說明原因與修正方式
- [x] 不洩漏敏感資訊或帳號存在狀態
- [x] 不用「請稍後再試」取代可具體處理的驗證錯誤

### 9.4 Success

- [x] 成功訊息只保留重要結果
- [x] 會造成 Session 失效、重新登入或強制改密碼時明確說明
- [x] 一般 CRUD 成功避免過長

### 9.5 Warning 與 Disabled reason

- [x] Warning 只在條件成立時顯示
- [x] 阻擋型與非阻擋型語意分開
- [x] Disabled control 的原因可由 keyboard／touch 使用者取得
- [x] 不把 `title` 當成唯一原因
- [x] 不重複顯示同一原因三次

---

## 10. Accessibility 與語意回歸

### 10.1 Form

- [ ] 所有 input／select／textarea 保留 visible label
- [ ] required marker 保留
- [ ] `aria-required`／原生 required 契約不變
- [ ] per-field error 具唯一 ID
- [ ] `aria-describedby` 只引用存在的 help／error ID
- [ ] help 移除後同步更新 described-by 陣列
- [ ] placeholder 不成為唯一欄位名稱

### 10.2 Page／Section

- [ ] 每頁只有一個 `h1`
- [ ] section `h2` 不因刪副標被誤刪
- [ ] Card Link／Button 仍有完整 accessible name
- [ ] icon-only control 仍有 `aria-label`

### 10.3 Dynamic state

- [ ] Error／success／loading 的 `aria-live` 不回歸
- [ ] 條件式 warning 出現時不搶走 focus，除非現有流程要求
- [ ] Modal／Drawer title、focus trap、Escape、focus return 不變
- [ ] 圖表名稱、期間、數值與單位對 screen reader 仍可理解
- [ ] Account／強制改密碼欄位的 help 先於 error，實際讀屏順序仍可理解
- [ ] UserList self disabled controls 的限制原因可由可見短句與 screen reader 取得

### 10.4 Automated accessibility contract

- [ ] 為刪除 help text 後的 `aria-describedby` 補回歸測試
- [ ] 測試優先使用 role／label，不只依賴精確文字
- [ ] 不使用 snapshot 作為唯一驗證

---

## 11. Frontend Automated Verification

### 11.1 Unit／Contract tests

- [ ] `npm test`
- [ ] 修正因預期文案改動造成的合理失敗
- [ ] 不為了讓測試通過而恢復不必要文案
- [ ] Dashboard presentation tests 通過
- [ ] Account／Auth tests 通過
- [ ] User 管理 tests 通過
- [ ] Salary 條件式 warning tests 通過
- [ ] Vehicle／MoneyEntry／Filter tests 通過
- [ ] App config 與 App Layout tests 通過

### 11.2 Static quality

- [ ] `npm run lint`
- [ ] `npm run typecheck`
- [ ] `npm run build`
- [ ] 記錄既有 warning 與本版本新增 warning 的差異
- [ ] 不新增 runtime dependency
- [ ] 不新增大幅 bundle growth

### 11.3 Static scope audit

- [ ] `git diff -- backend` 為空
- [ ] `git diff -- backend/database` 為空
- [ ] `git diff -- backend/routes` 為空
- [ ] `git diff -- frontend/src/api frontend/src/types` 原則上為空
- [ ] 無 migration
- [ ] 無 package dependency 變更
- [ ] 無新路由
- [ ] 無新 KPI／圖表／報表

若任何項目不為空，停止並確認是否已超出 v1.6。

---

## 12. Backend Regression Baseline

v1.6 不修改 backend，但封板前仍建立完整回歸證據。

- [ ] 執行 `php artisan test`
- [ ] 記錄 passed、skipped、assertions
- [ ] 確認 skipped 仍是既有 environment-gated tests
- [ ] 不將未執行的 MySQL-only tests 寫成通過
- [ ] 確認本版本沒有 backend／schema／time zone／transaction diff，因此不需重跑 MariaDB 專用競態測試
- [ ] 若 backend full suite 發現基準失敗，先判斷是否為既有環境問題，不以 v1.6 文案改動掩蓋

---

## 13. Browser Engineering Smoke

### 13.1 環境

- [ ] 使用真實 Laravel API
- [ ] 使用真實資料或專用 smoke fixtures，不使用假前端資料
- [ ] 準備 admin／manager／sales 三角色
- [ ] 準備 Dashboard 有值、空值、pending 與不同車輛狀態的資料
- [ ] 準備薪資當月、已確認／已發薪或可安全模擬的狀態

### 13.2 Desktop

- [ ] 1440px light mode
- [ ] 1440px dark mode
- [ ] Dashboard 所有區塊
- [ ] Account／User
- [ ] Vehicle／MoneyEntry／Customer
- [ ] Salary／Audit／Cash Account
- [ ] 車輛建檔／成交結案紙本與 PDF 預覽
- [ ] Keyboard-only 主要操作
- [ ] 錯誤、warning、success、empty state

### 13.3 Tablet／Mobile

- [ ] 768px light／dark
- [ ] 390px light／dark
- [ ] 375px light／dark
- [ ] 320px light／dark
- [ ] 頁面 Header 與主要 action 不擁擠
- [ ] 刪除副標後沒有不合理大空白
- [ ] 長 warning 只在需要時出現且不造成整頁 overflow
- [ ] Form label／error 不被截斷
- [ ] 新增收支送出多欄錯誤後，320～390px 仍可感知並修正第一個錯誤
- [ ] 員工／資金帳戶等寬表格可橫向捲動，self 短句換行不破版
- [ ] Modal／Drawer 可操作
- [ ] Safe Area 與底部空間不回歸

### 13.4 三角色

Admin：

- [ ] Dashboard 財務與待審核 KPI
- [ ] 員工管理
- [ ] 資金帳戶
- [ ] 薪資與 Audit
- [ ] 高風險文案仍完整

Manager：

- [ ] Dashboard 財務 KPI
- [ ] 車輛、收支、客戶流程
- [ ] 不出現 admin-only 教學或入口

Sales：

- [ ] Dashboard 不出現財務 KPI
- [ ] 車輛、客戶、銷售收款流程可理解
- [ ] 不因文案刪減誤導權限或敏感金額

### 13.5 工程 smoke 紀錄

- [ ] 建立 `docs/v1.6-phase-smoke-report.md` 或直接累積於正式 smoke report
- [ ] 記錄瀏覽器版本、寬度、角色、測試資料與結果
- [ ] 截圖只作輔助證據，不取代操作驗證

---

## 14. Adversarial Review

### 14.1 Review 目標

- [ ] 審查完整 v1.6 diff
- [ ] 確認沒有刪除關鍵業務規則
- [ ] 確認 approved-only、成交月份、期末餘額等定義仍可理解
- [ ] 確認薪資鎖定、發薪、重設密碼與 Session 失效後果仍清楚
- [ ] 確認沒有 broken `aria-describedby`
- [ ] 確認 disabled reason 不只存在於 hover `title`
- [ ] 確認 Mobile 沒有因文字條件式出現造成 overflow
- [ ] 確認沒有新增功能、API、schema、dependency 或平行元件系統

### 14.2 Reviewer 邊界

- [ ] Reviewer 不得要求把所有被刪文字改放 tooltip
- [ ] Reviewer 不得以個人文案偏好提出無可觀察影響的 finding
- [ ] Reviewer 不得把 v1.6 擴張成整站視覺改版
- [ ] Reviewer 不得要求 backend／schema 配合純 copy cleanup
- [ ] 有效 finding 必須指出具體頁面、缺失資訊與失敗模式

### 14.3 Follow-up

- [ ] Codex 逐項回到程式碼查證 finding
- [ ] 有效 finding 以最小變更修正
- [ ] 補對應 regression test
- [ ] 重新執行相關 frontend tests／lint／typecheck／build
- [ ] 若 finding 涉及範圍擴張，停止並交由使用者決策

---

## 15. 使用者 Browser Manual Smoke

工程 smoke 通過後，由使用者以實際日常操作方式驗收。

### 15.1 Dashboard

- [ ] 工作概況不再重複解釋卡片名稱
- [ ] 經營概況仍能理解本月與 approved-only 口徑
- [ ] 趨勢圖名稱、單位與期間清楚
- [ ] Desktop 與手機版明顯更簡潔

### 15.2 常用流程

- [ ] 新增車輛
- [ ] 車輛整備／上架／保留／尾款／成交
- [ ] 新增一般收支
- [ ] 上報單車支出
- [ ] 客戶建立與搜尋
- [ ] 員工建立與重設密碼
- [ ] 我的帳號與改密碼
- [ ] 薪資月份預覽、重算與限制提示

### 15.3 判斷標準

- [ ] 沒有因刪字不知道按鈕用途
- [ ] 沒有因刪字不知道不可操作原因
- [ ] 重要警告比一般文字更容易辨識
- [ ] 日常畫面沒有操作說明書感
- [ ] 手機畫面文字高度明顯下降
- [ ] 沒有希望恢復的關鍵資訊

使用者提出的純偏好微調可在 v1.6 收尾處理；新增功能或新互動流程必須另立版本／hotfix，不得混入。

---

## 16. 文件同步與交接

### 16.1 文件

- [ ] 更新 `UI.md`
- [ ] 更新 `README.md` v1.6 狀態
- [ ] 更新 `docs/current-state.md`
- [ ] 建立 `docs/v1.6-smoke-report.md`
- [ ] 建立 `docs/v1.6-handoff.md`
- [ ] 更新 `AGENTS.md`：v1.6 由規劃中改為完成／封板狀態
- [ ] 更新 `CLAUDE.md`：v1.6 由規劃中改為完成／review 邊界
- [ ] 確認 `backend/API.md` 無需修改

### 16.2 Handoff 必須記錄

- [ ] v1.6 runtime 基準 commit
- [ ] 修改頁面清單
- [ ] 主要刪除／縮短／條件式提示決策
- [ ] 保留的高風險文案
- [ ] Frontend test／lint／typecheck／build 結果
- [ ] Backend full suite 結果
- [ ] 未重跑 MariaDB 專用測試的原因
- [ ] Browser smoke 的瀏覽器、寬度與角色
- [ ] 使用者 manual smoke 結果
- [ ] 已知限制與未做項目
- [ ] 部署注意事項：原則上只有 frontend build 變更，無 migration／API contract

### 16.3 Diff audit

- [ ] `git diff --check`
- [ ] `git status --short`
- [ ] 確認只有 v1.6 範圍檔案
- [ ] 確認沒有 backend runtime diff
- [ ] 確認沒有 package lock 非預期變更
- [ ] 確認沒有 debug log、暫存截圖、測試帳密或敏感資料

---

## 17. Git 與封板

### 17.1 建議 commit 切分

```text
docs：建立 v1.6 UI 文案精簡規格
ui：精簡 Dashboard 與共用頁面文案
ui：精簡帳號與管理頁面文案
ui：精簡薪資與營運模組文案
test：補齊 v1.6 UI copy 回歸
docs：完成 v1.6 smoke 與交接
```

實際 commit 應維持小步、可 review、可回滾。

### 17.2 Git 規則

- [ ] 除非使用者明確要求，不自動 commit
- [ ] 除非使用者明確要求，不自動 push
- [ ] 不 amend 使用者既有 commit
- [ ] 不使用 reset、clean、checkout 等破壞性指令覆蓋工作樹
- [ ] 不在工程與 smoke 尚未完成時建立正式 tag

### 17.3 封板

- [ ] 完成 automated verification
- [ ] 完成 engineering browser smoke
- [ ] 完成 adversarial review
- [ ] 完成使用者 manual smoke
- [ ] 文件與 handoff 完成
- [ ] 使用者明確授權封板
- [ ] 建立 annotated tag：`v1.6-smoke-passed`
- [ ] Tag message：`v1.6 Smoke passed`
- [ ] 只有使用者要求時才 push main 與 tag

---

## 18. v1.6 完成定義

v1.6 視為完成，必須同時滿足：

- [ ] 所有正式前端頁面完成文案盤點
- [ ] 所有盤點文字完成 A／B／C／D／E 分類
- [ ] Dashboard 重複副標與 Card description 明顯減少
- [ ] 頁面標題不再普遍搭配重述用途的副標
- [ ] Username、User 管理與一般表單 help text 已縮短
- [ ] 薪資、成交、發薪、重設密碼與金額口徑等高風險資訊仍完整
- [ ] 正常狀態不常駐顯示大量預防性說明
- [ ] Error、success、warning、disabled、empty state 層級清楚
- [ ] visible label、required、per-field error、heading 與 `aria-describedby` 無回歸
- [ ] Backend、Schema、API、roles、permissions、business logic 無 diff
- [ ] 不新增 dependency、頁面、路由、KPI、報表、通知、onboarding 或 tooltip 平台
- [ ] `npm test` 通過
- [ ] `npm run lint` 通過或只有已記錄既有 warning
- [ ] `npm run typecheck` 通過
- [ ] `npm run build` 通過
- [ ] Backend full suite 通過
- [ ] 320／375／390／768／1440px 通過
- [ ] light／dark mode 通過
- [ ] admin／manager／sales 三角色通過
- [ ] 使用者真實 Desktop／Mobile manual smoke 通過
- [ ] `UI.md`、README、current-state、smoke report、handoff、AGENTS、CLAUDE 同步
- [ ] 使用者明確授權後才建立 `v1.6-smoke-passed` annotated tag
