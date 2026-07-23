# PLAN_v1.5.md — ERPV2 帳號自助管理與系統識別集中化

本清單對應 `企劃書_v1.5.md`。

v1.1～v1.4 均已封板。本版本只補齊 username／Email 雙登入、首次登入強制改密碼、我的帳號與前端系統名稱 config，不回開車輛、收支、薪資、Dashboard 或既有 UI／UX 改版範圍。

正式流程：

```text
Admin 建立員工 + 預設密碼
→ 員工以 Email 首次登入
→ must_change_password=true
→ 強制修改密碼
→ 可設定短 username
→ 後續用 username 或 Email 登入
```

---

## 0. 前置盤點與範圍確認

- [x] 閱讀 `AGENTS.md`、`CLAUDE.md`、`README.md`、`UI.md`、`docs/current-state.md`、`backend/API.md`
- [x] 閱讀 `企劃書_v1.5.md` 與本 PLAN
- [x] 檢查 Git branch、tag 與工作樹，保留無關 untracked／modified files
- [x] 確認 `v1.4-smoke-passed` 封板基準
- [x] 盤點 User migration、Model、Factory、Seeder、Request、Controller、Service、Resource、Policy 與 tests
- [x] 盤點 Auth Login Request、Controller、Service、Rate Limiter、Session 與 Authentication Audit
- [x] 盤點 `/api/me`、`auth:sanctum`、`active` middleware 與 protected routes
- [x] 盤點 Frontend Login、Auth Context、ProtectedRoute、AppLayout、User List、Theme Provider 與 API types
- [x] 搜尋所有「中古車行系統」「中古車行內部營運系統」與 document title 硬編碼
- [x] 記錄實作前 backend／frontend 基準測試結果
- [x] 確認 v1.5 不建立線上 Settings、MFA、Email reset、SSO 或 Website 功能

**Migration：否。**

**驗收：** 所有實際要修改的上下游契約已讀取，不只依企劃文件猜測。

---

## 1. User Schema 與 Model

### 1.1 Migration

- [x] 新增一筆 v1.5 migration
- [x] `users.username`：nullable string
- [x] 建立 username unique index
- [x] `users.must_change_password`：boolean default false
- [x] 欄位順序與現有 schema 清楚
- [x] 不回填 username
- [x] 不從 Email、姓名或其他欄位推測 username
- [x] 不將既有 User 全部設為 `must_change_password=true`
- [x] down migration 先移除 unique index，再移除欄位
- [x] SQLite 與 MySQL／MariaDB migration 行為一致

### 1.2 Model

- [x] `User` fillable 加入 `username`、`must_change_password`
- [x] `must_change_password` cast 為 boolean
- [x] username normalization 集中於 Request／Service 或明確 model boundary，不散落各 Controller
- [x] Password 仍維持 hidden 與 hashed cast
- [x] 不新增與角色或財務權限無關的方法

### 1.3 Factory／Seeder

- [x] Factory 預設建立 `username=null`
- [x] Factory 預設 `must_change_password=false`，避免大量既有測試改變語意
- [x] 增加可讀的 factory state：待首次改密碼
- [x] 增加可讀的 factory state：具有 username
- [x] `AdminUserSeeder` 明確保持 `must_change_password=false`
- [x] Seeder 不替 admin 猜測 username，除非既有開發帳號需求明確指定

**Migration：是。**

**安全邊界：** 多筆 null username 必須合法；非 null username 必須唯一。

**自動測試：**

- Schema 欄位／default／unique index
- 多筆 null username
- 重複非 null username 被拒絕
- migration up／down

**本部分不做：** settings table、username history、password history。

---

## 2. Username 驗證與正規化

### 2.1 正式規則

- [ ] nullable
- [ ] 空字串轉為 null
- [ ] trim
- [ ] 轉為小寫後再驗證／儲存
- [ ] 長度 3～30
- [ ] 只允許 `a-z`、`0-9`、`.`、`_`、`-`
- [ ] 禁止 `@`
- [ ] Database 與 application 層都保證唯一
- [ ] 大小寫視為同一帳號

### 2.2 Validator

- [ ] 建立可重用的 username rule／Request pattern
- [ ] Self profile update 使用同一規則
- [ ] Factory／test fixture 不繞過 normalization 契約
- [ ] Validation message 使用繁體中文
- [ ] Username 未設定不影響 Email 登入

### 2.3 競態

- [ ] 兩位使用者同時搶同一 username 時，只有一方成功
- [ ] unique constraint race 不得變成未處理 500
- [ ] QueryException 轉為 username validation error
- [ ] 不以先查再寫作為唯一唯一性保護

**Migration：否。**

**驗收：** `Eric`、`ERIC`、`eric` 無法建立成三個不同 username。

---

## 3. Login Request 與雙識別登入

### 3.1 Request 契約

- [ ] `LoginRequest` 由 `email` 改為 `login`
- [ ] `login` required、string、合理最大長度
- [ ] 不再要求 HTML／API Email 格式
- [ ] Password 契約保持 required string
- [ ] API 文件更新 request example

### 3.2 AuthService

- [ ] `login(string $login, string $password)` 命名對齊
- [ ] identifier trim
- [ ] identifier normalization
- [ ] 含 `@` 使用 Email 路徑
- [ ] 不含 `@` 使用 username 路徑
- [ ] username 登入不分大小寫
- [ ] Email 登入維持既有相容性
- [ ] 認證失敗維持通用 `帳號或密碼錯誤`
- [ ] 不洩漏 username／Email 是否存在
- [ ] 停用帳號仍登出並回既有停用訊息
- [ ] 成功登入 regenerate session
- [ ] 成功登入 Authentication Audit 保留
- [ ] Audit failure 時既有 fail-closed logout 行為保留

### 3.3 AuthController

- [ ] Controller 改讀 `login`
- [ ] 422／429／Retry-After 契約保留
- [ ] `UserResource` 回傳 username／must_change_password
- [ ] Controller 保持薄

**Migration：否。**

**安全邊界：** 不把原始密碼、查詢 User 或內部判斷寫入 response。

**Backend tests：**

- Email 登入成功
- username 登入成功
- username 大小寫登入
- 未設定 username 仍可用 Email
- 不存在 Email／username 回相同訊息
- 密碼錯誤回相同訊息
- 停用帳號兩種識別皆不可登入

---

## 4. Login Rate Limiter 雙識別安全

### 4.1 Key 設計

- [ ] 保留 IP-wide limiter
- [ ] identifier + IP limiter 改用 normalized login identifier
- [ ] 可解析到 User 時，account limiter 使用 canonical User identity
- [ ] username 與 Email 必須命中同一 canonical account limiter
- [ ] 不存在 identifier 使用穩定、不互相污染的 account key
- [ ] 成功登入清除正確 identifier + IP 與 canonical account limiter
- [ ] 不清除其他 User 的 limiter

### 4.2 既有限制保留

- [ ] 同 identifier + IP 限制
- [ ] 同帳號 rotating IP 限制
- [ ] 同 IP rotating identifier 限制
- [ ] 成功登入不累積 IP-wide failure
- [ ] 被阻擋請求不再執行 credential check
- [ ] 429 回 `Retry-After`

### 4.3 Alias bypass tests

- [ ] username 失敗數次後改用 Email，仍累計同一 account limiter
- [ ] Email 失敗數次後改用 username，仍累計同一 account limiter
- [ ] username／Email 不同大小寫不能取得新 limiter 額度
- [ ] 同 IP 輪替不存在 username／Email 仍受 IP-wide limiter
- [ ] 成功 username login 清除 canonical limiter
- [ ] 成功 Email login 清除 canonical limiter

**Migration：否。**

**驗收：** 雙登入不能降低既有 brute-force 防護。

---

## 5. 首次登入與重設密碼狀態

### 5.1 Admin 建立 User

- [ ] `UserService::createUser()` 明確設 `username=null`
- [ ] `UserService::createUser()` 明確設 `must_change_password=true`
- [ ] 不接受 Admin create payload 偷帶 `must_change_password=false`
- [ ] Store Request 明確拒絕 username／must_change_password，避免未授權覆寫
- [ ] 新帳號 Resource 回傳 flag

### 5.2 Admin Reset Password

- [ ] `UserService::resetPassword()` 更新 password
- [ ] 同一操作設 `must_change_password=true`
- [ ] 同一個 save／transaction boundary 留下正確 Audit
- [ ] API response 不回傳 password 或 hash
- [ ] 使用者已登入時，下個請求會讀到新 flag
- [ ] User 管理 UI 顯示重設後需再次修改密碼

### 5.3 Existing Users

- [ ] migration 後既有 User flag=false
- [ ] 不使用 `created_at`、Email 或角色猜測誰要改密碼
- [ ] 開發 Seeder admin 不被強制鎖住

**Migration：否（使用第 1 部分欄位）。**

**Backend tests：**

- 新建 User flag=true
- payload 無法關閉 flag
- admin reset 後 flag=true
- reset password hash 正確
- 既有 User flag=false

---

## 6. Password Change Required Middleware

### 6.1 Middleware

- [ ] 新增 `EnsurePasswordHasBeenChanged` 或語意等價 middleware
- [ ] 登入者 flag=false 時正常通過
- [ ] flag=true 時回 409
- [ ] response code 固定 `PASSWORD_CHANGE_REQUIRED`
- [ ] response message 固定且可顯示
- [ ] 未登入仍由 auth:sanctum 處理
- [ ] 停用帳號仍由 active middleware 優先處理

### 6.2 Route 邊界

flag=true 時允許：

- [ ] `GET /api/me`
- [ ] `POST /api/logout`
- [ ] `PATCH /api/me/profile`
- [ ] `PATCH /api/me/password`

flag=true 時阻擋：

- [ ] Dashboard
- [ ] Vehicle APIs
- [ ] Customer APIs
- [ ] Money Entry APIs
- [ ] Cash Account APIs
- [ ] Admin User APIs
- [ ] Salary APIs
- [ ] Audit Log APIs
- [ ] 其他 authenticated 營運 API

### 6.3 Middleware 結構

- [ ] 不把 Public Vehicle API 納入
- [ ] 不阻擋 Login／Logout 所需 CSRF 流程
- [ ] Route group 結構可讀，不為四個例外散落大量 `withoutMiddleware`
- [ ] 未知未來 authenticated route 預設被 gate 保護

**Migration：否。**

**安全邊界：** 前端繞過 redirect 仍不能呼叫營運 API。

**Backend tests：**

- flag=true 可讀 me
- flag=true 可 logout
- flag=true 可改 profile
- flag=true 可改 password
- flag=true dashboard／vehicle／money 等回 409 + code
- flag=false 不受影響

---

## 7. Self Account Backend API

### 7.1 Controller／Service

- [ ] 新增 CurrentUser／Account Controller 或沿用 AuthController 的最小清楚拆分
- [ ] Controller 保持薄
- [ ] Self profile 邏輯集中 Service
- [ ] Self password 邏輯集中 Service
- [ ] 只操作 `$request->user()`，不接受 target user id

### 7.2 `PATCH /api/me/profile`

允許：

- [ ] name
- [ ] username nullable

禁止：

- [ ] email
- [ ] role
- [ ] is_admin
- [ ] is_active
- [ ] phone
- [ ] job_title
- [ ] hire_date
- [ ] notes
- [ ] must_change_password
- [ ] password

- [ ] 禁止欄位使用 `missing` 或等價 fail-closed validation
- [ ] 更新後回完整 Self UserResource
- [ ] username unique race 轉 422
- [ ] 更新 name 後 Auth Context 可使用新值

### 7.3 `PATCH /api/me/password`

- [ ] current_password required
- [ ] 使用 Laravel current password rule 或等價 Hash check
- [ ] password required、string、min 8、confirmed
- [ ] 新密碼 hash 儲存
- [ ] 成功後 `must_change_password=false`
- [ ] 成功後 regenerate session
- [ ] 成功後回更新後 UserResource 或明確 success payload
- [ ] current password 錯誤回 per-field 422
- [ ] 不在 error／log／audit 暴露密碼

### 7.4 Audit

- [ ] Self profile update 產生 User updated audit
- [ ] Password update 產生 audit 但不含 password values
- [ ] Flag true→false 可追溯
- [ ] Username before／after 可追溯

**Migration：否。**

**Backend tests：**

- 三角色皆可修改自己
- 無法修改別人
- Email／role／active 等 present payload 被拒絕
- Username validation／normalization／unique
- current password 錯誤
- confirmation 錯誤
- 成功後 password 正確、flag=false
- audit 無密碼

---

## 8. UserResource、Types 與 Admin 使用者管理

### 8.1 Resource

- [ ] `UserResource` 新增 `username`
- [ ] `UserResource` 新增 `must_change_password`
- [ ] Password／remember token 仍不存在
- [ ] `/api/me` 與 admin users list 契約一致

### 8.2 Admin User List

- [ ] User types 新增 username／must_change_password
- [ ] 列表顯示 username
- [ ] null 顯示「尚未設定」
- [ ] 顯示待修改密碼狀態
- [ ] 建立表單仍不要求 username
- [ ] 建立成功提示首次登入需改密碼
- [ ] Reset password 成功提示下次操作需改密碼
- [ ] Admin edit form 不新增 username 編輯欄位
- [ ] 既有 name、Email、role、active、員工資料功能不回歸

### 8.3 API types

- [ ] `frontend/src/types/auth.ts` 更新
- [ ] `frontend/src/types/user.ts` 更新
- [ ] 避免兩份 User type 契約失步，採最小可維護整理
- [ ] `frontend/src/api/auth.ts` login payload 改為 `login`
- [ ] 新增 self profile／password API client
- [ ] API URL 仍集中於 `frontend/src/api`

**Migration：否。**

**驗收：** Admin 可清楚知道員工是否已設定 username、是否仍待改密碼。

---

## 9. Frontend Auth Context 與 Route Gate

### 9.1 Auth Context

- [ ] `login(login, password)` 命名更新
- [ ] Login 回傳 username／flag
- [ ] `/api/me` 回傳 username／flag
- [ ] 新增安全的 `updateCurrentUser()` 或等價 context 更新能力
- [ ] Self profile／password 成功後更新 Context
- [ ] Header 名稱不需 reload 即更新
- [ ] 既有多分頁 logout state 保護不回歸

### 9.2 Protected Route

- [ ] 未登入導 `/login`
- [ ] `must_change_password=true` 導向強制頁
- [ ] 強制頁本身不產生 redirect loop
- [ ] flag=false 才檢查一般 role route
- [ ] backend 409 `PASSWORD_CHANGE_REQUIRED` 可讓前端同步導流
- [ ] logout pending／blocked 狀態優先順序保持正確

### 9.3 API Error Handling

- [ ] Axios interceptor 或集中 error handling 辨識 machine code
- [ ] 不在每個頁面重複寫 409 判斷
- [ ] 不能把一般 409 誤判成 password required
- [ ] 導流時不遺失 logout 安全狀態

**Migration：否。**

**Frontend tests：**

- login flag redirect
- me flag redirect
- protected operational route redirect
- backend 409 code redirect
- no redirect loop
- context update
- logout regression

---

## 10. Login 與強制修改密碼 UI

### 10.1 Login Page

- [ ] state `email` 改為 `login`
- [ ] Label 改為「帳號名稱或 Email」
- [ ] input type 改為 text
- [ ] `autocomplete="username"`
- [ ] password 使用 `autocomplete="current-password"`
- [ ] Email 與 username 都可送出
- [ ] 登入成功依 flag 導向
- [ ] 通用錯誤與 429 呈現保留
- [ ] Login title／subtitle 改讀 app config

### 10.2 強制修改密碼頁

- [ ] 建立獨立 route
- [ ] 不使用一般 AppLayout／Sidebar
- [ ] 顯示目前密碼
- [ ] 顯示新密碼
- [ ] 顯示確認新密碼
- [ ] visible labels
- [ ] password autocomplete 語意正確
- [ ] per-field errors
- [ ] loading／disabled
- [ ] Theme Toggle
- [ ] 登出
- [ ] 成功更新 Auth Context
- [ ] 成功導 Dashboard
- [ ] Mobile／safe area

### 10.3 強制流程錯誤

- [ ] 目前密碼錯誤不清除 flag
- [ ] validation error 保留欄位
- [ ] API 失敗不讓使用者進營運頁
- [ ] Session 過期回 Login

**Migration：否。**

**驗收：** 首次登入無法略過密碼修改。

---

## 11. 我的帳號頁

### 11.1 Route 與入口

- [ ] 建立 `/account` 或企劃指定等價 route
- [ ] 三角色皆可進入
- [ ] Header 使用者名稱區提供入口
- [ ] 不新增 Sidebar 主模組項目
- [ ] Theme Toggle 留在 Header

### 11.2 個人資料區塊

- [ ] 顯示名稱可編輯
- [ ] username 可新增／修改／清空
- [ ] Email 唯讀
- [ ] 角色唯讀
- [ ] null username 顯示說明
- [ ] username 規則提示
- [ ] per-field errors
- [ ] success feedback
- [ ] 更新後 Header 名稱即時改變

### 11.3 密碼區塊

- [ ] 目前密碼
- [ ] 新密碼
- [ ] 確認新密碼
- [ ] 與個人資料分開提交
- [ ] success 後清空密碼欄位
- [ ] current password error
- [ ] confirmation error
- [ ] loading／double submit protection

### 11.4 Accessibility／RWD

- [ ] 320／375／390／768／1440px
- [ ] keyboard
- [ ] visible focus
- [ ] label／description／error association
- [ ] light／dark mode
- [ ] 無水平 overflow

**Migration：否。**

**本部分不做：** Email 編輯、Avatar、通知、Theme 偏好頁、Session 管理。

---

## 12. ERPV2 App Config 集中化

### 12.1 Config

- [ ] 新增 `frontend/src/config/app.ts`
- [ ] `companyName`
- [ ] `systemName`
- [ ] `systemShortName`
- [ ] `browserTitle`
- [ ] `loginSubtitle`
- [ ] export 型別穩定且不需要 runtime API

### 12.2 套用位置

- [ ] Login h1
- [ ] Login subtitle
- [ ] Sidebar brand
- [ ] document.title
- [ ] 搜尋到的其他相同硬編碼名稱
- [ ] `frontend/index.html` 保留合理無 JS fallback，但不成為第二個正式設定來源

### 12.3 邊界

- [ ] 不建立 backend settings API
- [ ] 不建立 DB settings table
- [ ] 不建立 Settings 頁
- [ ] 不修改 Theme token 架構
- [ ] 不新增 Logo upload
- [ ] 不修改 `~/website` repo

### 12.4 驗證

- [ ] 修改 config 後所有位置一致更新
- [ ] `rg` 確認不再散落正式硬編碼字串
- [ ] Browser title 在首次 render 即正確
- [ ] typecheck／build 通過

**Migration：否。**

---

## 13. Backend Automated Tests

### 13.1 Schema／User

- [ ] Migration schema tests
- [ ] Existing user defaults
- [ ] Username nullable／unique／normalization
- [ ] Username race handling
- [ ] Create user force flag
- [ ] Reset password force flag
- [ ] Resource fields

### 13.2 Auth

- [ ] Email login
- [ ] Username login
- [ ] Case normalization
- [ ] Generic errors
- [ ] Disabled account
- [ ] Session regenerate
- [ ] Authentication audit
- [ ] Alias-safe Rate Limiter
- [ ] IP-wide limiter regression
- [ ] Retry-After regression

### 13.3 Password Gate

- [ ] Allowed routes
- [ ] Blocked operational routes
- [ ] 409 + machine code
- [ ] active middleware interaction
- [ ] unknown role fail-closed

### 13.4 Self Account

- [ ] Self profile role matrix
- [ ] Prohibited fields
- [ ] Username validation／unique
- [ ] Current password
- [ ] Confirmed password
- [ ] Flag clear
- [ ] Audit password redaction

### 13.5 Full Regression

- [ ] `php artisan test`
- [ ] 既有 UserTest
- [ ] 既有 LoginThrottleTest
- [ ] AuditLogTest
- [ ] RoleAccessTest
- [ ] Salary user references
- [ ] 其他受 UserResource／Factory 影響 tests

**Migration：否。**

---

## 14. Frontend Automated Tests

### 14.1 Auth

- [ ] Login label／payload
- [ ] Email login input
- [ ] Username login input
- [ ] flag redirect
- [ ] 409 redirect
- [ ] logout regression

### 14.2 Account

- [ ] Load current user
- [ ] Name update
- [ ] Username add／update／clear
- [ ] Email／role readonly
- [ ] Password success／errors
- [ ] Context／Header update

### 14.3 Config

- [ ] Login title from config
- [ ] Sidebar title from config
- [ ] document title from config

### 14.4 Quality

- [ ] `npm test`
- [ ] `npm run lint`
- [ ] `npm run typecheck`
- [ ] `npm run build`
- [ ] 無新增未使用 dependency

**Migration：否。**

---

## 15. Browser Manual Smoke

### 15.1 Admin 建立帳號

- [ ] Admin 建立 sales 帳號：名稱、Email、預設密碼、角色
- [ ] User list 顯示 username 尚未設定
- [ ] User list 顯示待修改密碼
- [ ] 建立提示正確

### 15.2 首次登入

- [ ] Email + 預設密碼登入成功
- [ ] 立即導向強制改密碼
- [ ] 直接輸入 `/dashboard` 仍回強制頁
- [ ] 一般 API 被後端阻擋
- [ ] Theme Toggle 可用
- [ ] Logout 可用
- [ ] 錯誤目前密碼不可通過
- [ ] 正確修改後進 Dashboard

### 15.3 我的帳號

- [ ] Header 可進我的帳號
- [ ] 修改名稱後 Header 即時更新
- [ ] 設定 username
- [ ] 重複 username 顯示錯誤
- [ ] Email／角色不可編輯
- [ ] 修改密碼必須目前密碼
- [ ] confirmation 不符顯示錯誤

### 15.4 雙登入

- [ ] username + 新密碼登入
- [ ] Email + 同一新密碼登入
- [ ] username 大小寫登入行為符合規格
- [ ] 錯誤密碼訊息不洩漏識別方式

### 15.5 Admin Reset

- [ ] Admin 重設員工密碼
- [ ] User list 顯示待修改密碼
- [ ] 員工既有 Session 下次操作被 gate
- [ ] 使用新預設密碼完成強制修改

### 15.6 Config／Regression

- [ ] 修改 app config 後 Login／Sidebar／Browser title 同步
- [ ] Theme Toggle 無回歸
- [ ] Admin／Manager／Sales 既有主要流程 smoke
- [ ] Mobile Safari 基本操作
- [ ] Desktop Chrome 基本操作

**Migration：否。**

---

## 16. 文件同步與交接

### 16.1 文件

- [ ] `README.md` 更新登入方式與首次登入流程
- [ ] `backend/API.md` 更新 Auth、Self Account 與 UserResource
- [ ] `docs/current-state.md` 更新 v1.5 現況
- [ ] `AGENTS.md` 更新版本狀態與 v1.5 邊界
- [ ] `CLAUDE.md` 更新 reviewer 必讀與 v1.5 邊界
- [ ] 補 v1.5 smoke report
- [ ] 補 v1.5 handoff
- [ ] 不修改 v1.1～v1.4 PLAN 完成內容

### 16.2 Review

- [ ] 檢查所有密碼欄位未進 Audit／Resource／log
- [ ] 檢查 username／Email alias limiter
- [ ] 檢查 middleware fail-closed
- [ ] 檢查 self endpoint 不可改 Email／role／active
- [ ] 檢查 App config 沒有擴張成 Settings 模組
- [ ] 檢查無關 schema／dependency／UI 重構

### 16.3 Git

- [ ] 每個完整階段有可驗證 commit
- [ ] 不自動 push
- [ ] 使用者完成 smoke 後再準備 tag
- [ ] 只有使用者明確授權才建立 annotated tag

**建議規劃文件 Commit Message：**

```text
docs：新增 v1.5 帳號自助管理企劃與執行計畫
```

**未來實作 Commit 建議：**

```text
feat：新增 username 與首次改密碼狀態
feat：支援帳號名稱或 Email 登入
feat：新增首次登入強制改密碼保護
feat：新增我的帳號自助管理
refactor：集中 ERPV2 系統識別設定
test：補齊 v1.5 帳號與登入回歸
docs：完成 v1.5 smoke 與交接
```

---

## 17. v1.5 完成定義

只有以下全部成立，才能將本 PLAN 標記完成：

- [ ] `username`／`must_change_password` migration 安全完成
- [ ] 既有 User 未被錯誤強制改密碼
- [ ] Admin 新建與重設密碼均設 force flag
- [ ] Email／username 雙登入完成
- [ ] Alias-safe Rate Limiter 完成
- [ ] 後端 Password Change Required Gate 完成
- [ ] 首次登入無法略過密碼修改
- [ ] 三角色皆可修改自己的 name／username／password
- [ ] Self API 無法修改 Email／role／active 等欄位
- [ ] Password 不存在於 API、Audit 或 log
- [ ] Admin User List 顯示 username 與待改密碼狀態
- [ ] Header 我的帳號入口完成
- [ ] App config 取代系統名稱硬編碼
- [ ] Theme Toggle 無回歸
- [ ] Backend full suite 通過
- [ ] Frontend test／lint／typecheck／build 通過
- [ ] Browser manual smoke 通過
- [ ] 文件與 handoff 完成
- [ ] 使用者明確授權後完成 annotated tag
