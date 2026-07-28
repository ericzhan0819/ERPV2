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

- [x] nullable
- [x] 空字串轉為 null
- [x] trim
- [x] 轉為小寫後再驗證／儲存
- [x] 長度 3～30
- [x] 只允許 `a-z`、`0-9`、`.`、`_`、`-`
- [x] 禁止 `@`
- [x] Database 與 application 層都保證唯一
- [x] 大小寫視為同一帳號

### 2.2 Validator

- [x] 建立可重用的 username rule／Request pattern
- [x] Self profile update 使用同一規則
- [x] Factory／test fixture 不繞過 normalization 契約
- [x] Validation message 使用繁體中文
- [x] Username 未設定不影響 Email 登入

**Self profile Request 契約：** `name` 與 `username` 採完整表單提交；`username` 鍵必須存在，`null` 或空字串表示明確清空。缺少該鍵固定回 422，避免下游誤用 `$data['username'] ?? null` 靜默刪除既有帳號名稱。

### 2.3 競態

- [x] 兩位使用者同時搶同一 username 時，只有一方成功
- [x] unique constraint race 不得變成未處理 500
- [x] QueryException 轉為 username validation error
- [x] 不以先查再寫作為唯一唯一性保護

**Migration：否。**

**驗收：** `Eric`、`ERIC`、`eric` 無法建立成三個不同 username。

---

## 3. Login Request 與雙識別登入

### 3.1 Request 契約

- [x] `LoginRequest` 由 `email` 改為 `login`
- [x] `login` required、string、合理最大長度
- [x] 不再要求 HTML／API Email 格式
- [x] Password 契約保持 required string
- [x] API 文件更新 request example

### 3.2 AuthService

- [x] `login(string $login, string $password)` 命名對齊
- [x] identifier trim
- [x] identifier normalization
- [x] 含 `@` 使用 Email 路徑
- [x] 不含 `@` 使用 username 路徑
- [x] username 登入不分大小寫
- [x] Email 登入維持既有相容性
- [x] 認證失敗維持通用 `帳號或密碼錯誤`
- [x] 不洩漏 username／Email 是否存在
- [x] 停用帳號仍登出並回既有停用訊息
- [x] 成功登入 regenerate session
- [x] 成功登入 Authentication Audit 保留
- [x] Audit failure 時既有 fail-closed logout 行為保留

### 3.3 AuthController

- [x] Controller 改讀 `login`
- [x] 422／429／Retry-After 契約保留
- [x] `UserResource` 回傳 username／must_change_password
- [x] Controller 保持薄

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

- [x] 保留 IP-wide limiter
- [x] identifier + IP limiter：可解析時使用 canonical User identity，否則使用 normalized login hash
- [x] 可解析到 User 時，account limiter 使用 canonical User identity
- [x] username 與 Email 必須命中同一 canonical account limiter
- [x] 不存在 identifier 使用穩定、不互相污染的 account key
- [x] 成功登入清除正確 identifier + IP 與 canonical account limiter
- [x] 不清除其他 User 的 limiter

### 4.2 既有限制保留

- [x] 同 identifier + IP 限制
- [x] 同帳號 rotating IP 限制
- [x] 同 IP rotating identifier 限制
- [x] 成功登入不累積 IP-wide failure
- [x] 被阻擋請求不再執行 credential check
- [x] 429 回 `Retry-After`

### 4.3 Alias bypass tests

- [x] username 失敗數次後改用 Email，仍累計同一 account limiter
- [x] Email 失敗數次後改用 username，仍累計同一 account limiter
- [x] username／Email 不同大小寫不能取得新 limiter 額度
- [x] 同 IP 輪替不存在 username／Email 仍受 IP-wide limiter
- [x] 成功 username login 清除 canonical limiter
- [x] 成功 Email login 清除 canonical limiter

**Migration：否。**

**驗收：** 雙登入不能降低既有 brute-force 防護。

**完成註記（2026-07-24）：** 核心實作已於第 3 部分 adversarial review 因安全阻斷而提前完成；本部分已重新逐項驗收，並補上「不存在 username／Email 混合輪替」與「Email 成功登入清除 alias 共用額度」的直接回歸測試。完整證據見 `docs/v1.5-phase4-handoff.md`。

### Adversarial Review Follow-up（跨部分既有契約修正）

- [x] Generic Update Request 明確拒絕 username／must_change_password／password，避免專用流程欄位被靜默忽略成假成功
- [x] Generic Update 的 username／must_change_password 錯誤訊息與 API 文件採永久有效的中性契約，不綁版本階段或尚未存在的端點

---

## 5. 首次登入與重設密碼狀態

### 5.1 Admin 建立 User

- [x] `UserService::createUser()` 明確設 `username=null`
- [x] `UserService::createUser()` 明確設 `must_change_password=true`
- [x] 不接受 Admin create payload 偷帶 `must_change_password=false`
- [x] Store Request 明確拒絕 username／must_change_password，避免未授權覆寫
- [x] 新帳號 Resource 回傳 flag
- [x] User 與 Audit 建立在同一個 transaction；Audit 失敗時不留下帳號

### 5.2 Admin Reset Password

- [x] `UserService::resetPassword()` 更新 password
- [x] 同一操作設 `must_change_password=true`
- [x] `ResetUserPasswordRequest` 明確拒絕 payload 覆寫 `must_change_password`
- [x] 同一個 save／transaction boundary 留下正確 Audit
- [x] API response 不回傳 password 或 hash
- [x] 既有 stateful Session 下個請求因密碼 hash 變更回 401 並清空；使用新密碼重新登入後讀到 flag=true
- [x] User 管理 UI 顯示重設後需再次修改密碼
- [x] User 管理 UI 禁止對自己使用 Admin Reset，避免重設成功後立即重新載入撞 401
- [x] 自己的資料列顯示可見說明，不依賴 disabled button 的 `title`

**產品決策（2026-07-25 使用者核准）：** Admin reset 後，每個既有 stateful Session 在各自下一個請求回 401 並清空；使用者以新密碼重新登入後，才依 `must_change_password=true` 進入強制修改密碼頁。

**交付相依（不得單獨部署）：** 第 5 部分停用自我 Admin Reset 的前端限制，必須與第 11 部分「我的帳號」自助改密碼入口一起形成可部署版本；在第 11 部分完成前，目前工作樹只屬工程中間狀態，不得單獨部署到只有單一 admin 的環境。

### 5.3 Existing Users

- [x] migration 後既有 User flag=false
- [x] 不使用 `created_at`、Email 或角色猜測誰要改密碼
- [x] 開發 Seeder admin 不被強制鎖住

**Migration：否（使用第 1 部分欄位）。**

**Backend tests：**

- 新建 User flag=true
- payload 無法關閉 flag
- admin reset 後 flag=true
- reset password hash 正確
- create／reset 的 Audit 失敗皆 rollback
- 真實 stateful login → reset → 下個請求 401 → 新密碼重新登入回 flag=true
- 既有 User flag=false

---

## 6. Password Change Required Middleware

**交付順序相依：** 第 6 部分與第 7 部分必須在同一輪完成、驗證並交付。不得先部署只會阻擋營運 API 的 Middleware，卻尚未提供可將 `must_change_password` 清為 `false` 的 self password endpoint；否則包含最後一位 admin 在內的待改密碼帳號會被鎖住。第 6 部分進行中的暫存 commit 也不得被視為可部署完成狀態。

### 6.1 Middleware

- [x] 新增 `EnsurePasswordHasBeenChanged` 或語意等價 middleware
- [x] 登入者 flag=false 時正常通過
- [x] flag=true 時回 409
- [x] response code 固定 `PASSWORD_CHANGE_REQUIRED`
- [x] response message 固定且可顯示
- [x] 未登入仍由 auth:sanctum 處理
- [x] 停用帳號仍由 active middleware 優先處理

### 6.2 Route 邊界

flag=true 時允許：

- [x] `GET /api/me`
- [x] `POST /api/logout`
- [x] `PATCH /api/me/profile`
- [x] `PATCH /api/me/password`

flag=true 時阻擋：

- [x] Dashboard
- [x] Vehicle APIs
- [x] Customer APIs
- [x] Money Entry APIs
- [x] Cash Account APIs
- [x] Admin User APIs
- [x] Salary APIs
- [x] Audit Log APIs
- [x] 其他 authenticated 營運 API

### 6.3 Middleware 結構

- [x] 不把 Public Vehicle API 納入
- [x] 不阻擋 Login／Logout 所需 CSRF 流程
- [x] Route group 結構可讀，不為四個例外散落大量 `withoutMiddleware`
- [x] 未知未來 authenticated route 預設被 gate 保護

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

- [x] 新增 CurrentUser／Account Controller 或沿用 AuthController 的最小清楚拆分
- [x] Controller 保持薄
- [x] Self profile 邏輯集中 Service
- [x] Self password 邏輯集中 Service
- [x] 只操作 `$request->user()`，不接受 target user id

### 7.2 `PATCH /api/me/profile`

允許：

- [x] name
- [x] username nullable

禁止：

- [x] email
- [x] role
- [x] is_admin
- [x] is_active
- [x] phone
- [x] job_title
- [x] hire_date
- [x] notes
- [x] must_change_password
- [x] password

- [x] 禁止欄位使用 `missing` 或等價 fail-closed validation
- [x] 更新後回完整 Self UserResource
- [x] username unique race 轉 422
- [x] 更新 name 後 Auth Context 可使用新值

### 7.3 `PATCH /api/me/password`

- [x] current_password required
- [x] current_password 非字串輸入以 `422` 拒絕，不進入 Hash check
- [x] 使用 Laravel current password rule 或等價 Hash check
- [x] password required、string、min 8、confirmed
- [x] 新密碼不可與目前密碼相同
- [x] 新密碼 hash 儲存
- [x] 成功後 `must_change_password=false`
- [x] 成功後 regenerate session
- [x] 成功後回更新後 UserResource 或明確 success payload
- [x] current password 錯誤回 per-field 422
- [x] 其他帳號流程欄位使用 `missing` fail-closed，不靜默忽略
- [x] 不在 error／log／audit 暴露密碼

### 7.4 Audit

- [x] Self profile update 產生 User updated audit
- [x] Password update 產生 audit 但不含 password values
- [x] Flag true→false 可追溯
- [x] Username before／after 可追溯

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

- [x] `UserResource` 新增 `username`
- [x] `UserResource` 新增 `must_change_password`
- [x] Password／remember token 仍不存在
- [x] `/api/me` 與 admin users list 契約一致

### 8.2 Admin User List

- [x] User types 新增 username／must_change_password
- [x] 列表顯示 username
- [x] null 顯示「尚未設定」
- [x] 顯示待修改密碼狀態
- [x] 建立表單仍不要求 username
- [x] 建立成功提示首次登入需改密碼
- [x] Reset password 成功提示下次操作需改密碼
- [x] Admin edit form 不新增 username 編輯欄位
- [x] 既有 name、Email、role、active、員工資料功能不回歸

### 8.3 API types

- [x] `frontend/src/types/auth.ts` 更新
- [x] `frontend/src/types/user.ts` 更新
- [x] 避免兩份 User type 契約失步，採最小可維護整理
- [x] `frontend/src/api/auth.ts` login payload 改為 `login`
- [x] 新增 self profile／password API client
- [x] API URL 仍集中於 `frontend/src/api`

**Migration：否。**

**驗收：** Admin 可清楚知道員工是否已設定 username、是否仍待改密碼。

---

## 9. Frontend Auth Context 與 Route Gate

### 9.1 Auth Context

- [x] `login(login, password)` 命名更新
- [x] Login 回傳 username／flag
- [x] `/api/me` 回傳 username／flag
- [x] 新增安全的 `updateCurrentUser()` 或等價 context 更新能力
- [x] Self profile／password 成功後更新 Context
- [x] Header 名稱不需 reload 即更新
- [x] 既有多分頁 logout state 保護不回歸

### 9.2 Protected Route

- [x] 未登入導 `/login`
- [x] `must_change_password=true` 導向強制頁
- [x] 強制頁本身不產生 redirect loop
- [x] flag=false 才檢查一般 role route
- [x] backend 409 `PASSWORD_CHANGE_REQUIRED` 可讓前端同步導流
- [x] logout pending／blocked 狀態優先順序保持正確

### 9.3 API Error Handling

- [x] Axios interceptor 或集中 error handling 辨識 machine code
- [x] 不在每個頁面重複寫 409 判斷
- [x] 不能把一般 409 誤判成 password required
- [x] 導流時不遺失 logout 安全狀態

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

- [x] state `email` 改為 `login`
- [x] Label 改為「帳號名稱或 Email」
- [x] input type 改為 text
- [x] `autocomplete="username"`
- [x] password 使用 `autocomplete="current-password"`
- [x] Email 與 username 都可送出
- [x] 登入成功依 flag 導向
- [x] 通用錯誤與 429 呈現保留
- [x] Login title／subtitle 改讀 app config

### 10.2 強制修改密碼頁

- [x] 建立獨立 route
- [x] 不使用一般 AppLayout／Sidebar
- [x] 顯示目前密碼
- [x] 顯示新密碼
- [x] 顯示確認新密碼
- [x] visible labels
- [x] password autocomplete 語意正確
- [x] per-field errors
- [x] loading／disabled
- [x] Theme Toggle
- [x] 登出
- [x] 成功更新 Auth Context
- [x] 成功導 Dashboard
- [x] Mobile／safe area

### 10.3 強制流程錯誤

- [x] 目前密碼錯誤不清除 flag
- [x] validation error 保留欄位
- [x] API 失敗不讓使用者進營運頁
- [x] Session 過期回 Login
- [x] Admin 重設密碼造成的 stateful 401 回 Login；使用新密碼登入後依 flag 進強制頁

**Migration：否。**

**驗收：** 首次登入無法略過密碼修改。

---

## 11. 我的帳號頁

### 11.1 Route 與入口

- [x] 建立 `/account` 或企劃指定等價 route
- [x] 三角色皆可進入
- [x] Header 使用者名稱區提供入口
- [x] Admin 可由此入口修改自己的密碼，解除第 5 部分自我 Admin Reset 限制的交付相依
- [x] 不新增 Sidebar 主模組項目
- [x] Theme Toggle 留在 Header

### 11.2 個人資料區塊

- [x] 顯示名稱可編輯
- [x] username 可新增／修改／清空
- [x] Email 唯讀
- [x] 角色唯讀
- [x] null username 顯示說明
- [x] username 規則提示
- [x] per-field errors
- [x] success feedback
- [x] 更新後 Header 名稱即時改變

### 11.3 密碼區塊

- [x] 目前密碼
- [x] 新密碼
- [x] 確認新密碼
- [x] 與個人資料分開提交
- [x] success 後清空密碼欄位
- [x] current password error
- [x] confirmation error
- [x] loading／double submit protection

### 11.4 Accessibility／RWD

- [x] 320／375／390／768／1440px
- [x] keyboard
- [x] visible focus
- [x] label／description／error association
- [x] light／dark mode
- [x] 無水平 overflow

**Migration：否。**

**本部分不做：** Email 編輯、Avatar、通知、Theme 偏好頁、Session 管理。

---

## 12. ERPV2 App Config 集中化

### 12.1 Config

- [x] 新增 `frontend/src/config/app.ts`
- [x] `companyName`
- [x] `systemName`
- [x] `systemShortName`
- [x] `browserTitle`
- [x] `loginSubtitle`
- [x] export 型別穩定且不需要 runtime API

### 12.2 套用位置

- [x] Login h1
- [x] Login subtitle
- [x] Sidebar brand
- [x] document.title
- [x] 搜尋到的其他相同硬編碼名稱
- [x] `frontend/index.html` 保留合理無 JS fallback，但不成為第二個正式設定來源

### 12.3 邊界

- [x] 不建立 backend settings API
- [x] 不建立 DB settings table
- [x] 不建立 Settings 頁
- [x] 不修改 Theme token 架構
- [x] 不新增 Logo upload
- [x] 不修改 `~/website` repo

### 12.4 驗證

- [x] 修改 config 後所有位置一致更新
- [x] `rg` 確認不再散落正式硬編碼字串
- [x] Browser title 在首次 render 即正確
- [x] typecheck／build 通過

**Migration：否。**

---

## 13. Backend Automated Tests

### 13.1 Schema／User

- [x] Migration schema tests
- [x] Existing user defaults
- [x] Username nullable／unique／normalization
- [x] Username race handling
- [x] Create user force flag
- [x] Reset password force flag
- [x] Resource fields

### 13.2 Auth

- [x] Email login
- [x] Username login
- [x] Case normalization
- [x] Generic errors
- [x] Disabled account
- [x] Session regenerate
- [x] Authentication audit
- [x] Alias-safe Rate Limiter
- [x] IP-wide limiter regression
- [x] Retry-After regression

### 13.3 Password Gate

- [x] Allowed routes
- [x] Blocked operational routes
- [x] 409 + machine code
- [x] active middleware interaction
- [x] unknown role fail-closed

### 13.4 Self Account

- [x] Self profile role matrix
- [x] Prohibited fields
- [x] Username validation／unique
- [x] Current password
- [x] Confirmed password
- [x] Flag clear
- [x] Audit password redaction

### 13.5 Full Regression

- [x] `php artisan test`
- [x] 既有 UserTest
- [x] 既有 LoginThrottleTest
- [x] AuditLogTest
- [x] RoleAccessTest
- [x] Salary user references
- [x] 其他受 UserResource／Factory 影響 tests

**Migration：否。**

### Adversarial Review Follow-up

- [x] 非 stateful login 在 credential lookup／limiter 異動前固定回 419，不形成密碼 oracle
- [x] 非 stateful logout 維持冪等 200，不讀取不存在的 Session store
- [x] Login 與 self password 的 Session regenerate 使用 request lifecycle 內取樣及負向對照
- [x] MariaDB username 競態改走 production `updateCurrentProfile()` transaction 路徑
- [x] Password Gate priority 以 sales／unknown role 呼叫 admin-only route 驗證
- [x] 認證正式限縮為 SPA Session；Bearer token 呼叫受保護 API 固定回 401
- [x] 非 stateful logout 保留冪等 200，但明確標示沒有可登出的工作階段
- [x] 保留既有 personal access token table，不新增破壞性 migration 或 token API
- [x] 無 Session 測試明確清除 Origin／Referer；inactive 使用者仍固定回 403，直接保護 active middleware 守衛

---

## 14. Frontend Automated Tests

### 14.1 Auth

- [x] Login label／payload
- [x] Email login input
- [x] Username login input
- [x] flag redirect
- [x] 409 redirect
- [x] logout regression

### 14.2 Account

- [x] Load current user
- [x] Name update
- [x] Username add／update／clear
- [x] Email／role readonly
- [x] Password success／errors
- [x] Context／Header update

### 14.3 Config

- [x] Login title from config
- [x] Sidebar title from config
- [x] document title from config

### 14.4 Quality

- [x] `npm test`
- [x] `npm run lint`
- [x] `npm run typecheck`
- [x] `npm run build`
- [x] 無新增未使用 dependency

**Migration：否。**

### Adversarial Review Follow-up

- [x] 登出兩次失敗固定進入 blocked 狀態並保留 failed marker
- [x] Login flag 與 API 409 導向通過真實 passwordChangeOnly gate
- [x] 首次 logout 失敗但 CSRF 重試成功時正常完成登出
- [x] API client 測試採保留原模組的 partial mock
- [x] 第 14 部分 review 補強同步至 PLAN 與 handoff

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
- [ ] 員工每個既有 stateful Session 在各自下次操作回 401 並登出
- [ ] 員工以新預設密碼重新登入後進強制修改密碼頁
- [ ] 使用新預設密碼完成強制修改

### 15.6 Config／Regression

- [ ] 修改 app config 後 Login／Sidebar／Browser title 同步
- [ ] Theme Toggle 無回歸
- [ ] Admin／Manager／Sales 既有主要流程 smoke
- [ ] Mobile Safari 基本操作
- [ ] Desktop Chrome 基本操作

**Migration：否。**

### 工程端 Browser 預驗證（2026-07-28）

- [x] Firefox 152 headless 可拋棄環境完成 38 項帳號工作流、三角色、雙 Session、Config、Theme 與 390px RWD 檢查
- [x] 官方 Chrome for Testing 151.0.7922.47 完成 15 項 Desktop Chrome 基本操作檢查
- [x] 獨立 SQLite、Laravel／Vite ports 與瀏覽器 profiles 均已清理，未接觸既有開發資料

工程端預驗證結果記錄於 `docs/v1.5-phase15-smoke-report.md`。上述真實瀏覽器檢查可證明
正式 API、Router、Session 與畫面整合，但不取代使用者人工操作；因此 15.1～15.6 的正式
Browser Manual Smoke 項目仍維持未勾選，並作為本部分唯一完成閘門，待使用者逐項確認後
再完成。

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
