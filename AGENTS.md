# AGENTS.md

## 專案身份

ERPV2 是給小型中古車行內部使用的前後端分離營運管理系統。

它不是正式會計系統、報稅系統、POS、完整 HR、SaaS 多租戶平台或通用 ERP。核心目標是穩定支援中古車行的實際流程：車輛建檔、整備、上架、保留、收款、成交、收支、資金帳戶、列印與營運摘要。

目前版本狀態：

```text
1.0：工程 MVP 已完成。
v1.1：實務工作流補強已完成 smoke 並封版。
v1.2：車輛圖片與官網公開資料前置已完成 smoke 並封版。
v1.3：薪資結算已完成企劃與 PLAN，尚未開始實作。
```

---

## Codex 的正式角色

Codex 是本專案的主要實作工程師。

固定協作分工：

```text
Codex：功能實作、缺陷修正、測試、文件同步與交接。
Claude：對立性審查，或依使用者明確要求執行純 UI 設計、版面與調色。
使用者：產品決策、人工 Smoke Test、最終驗收與是否採納審查意見的決定者。
```

Codex 應完成使用者授權範圍內的實作，不把主要工程工作轉回 Claude。Claude 的 review findings 需由 Codex 重新查證後修正；不得盲目接受，也不得因 reviewer 的推測而擴大範圍。

使用者自行選擇模型與推理強度，本文件不得替使用者指定推理強度。

---

## 開始工作前

先閱讀與任務直接相關的文件、程式碼與測試，再修改。

共同基礎文件：

1. `企劃書.md`
2. `PLAN.md`
3. `README.md`
4. `UI.md`
5. `CLAUDE.md`，用來理解 reviewer 的檢查標準與專案完整邊界

版本文件：

- v1.1：`企劃書_v1.1.md`、`PLAN_v1.1.md`、`docs/v1.1-smoke-report.md`
- v1.2：`企劃書_v1.2.md`、`PLAN_v1.2.md`、`docs/v1.2-smoke-report.md`、`docs/v1.2-handoff.md`
- v1.3：`企劃書_v1.3.md`、`PLAN_v1.3.md`、`docs/current-state.md`、`backend/API.md`

不得只看完成報告或單一檔案就直接大量改碼。先搜尋並確認既有路由、Controller、FormRequest、Service、Model、Policy、Resource、migration、tests、前端 API、types 與相關頁面。

優先使用搜尋定位既有 pattern，避免無目的大量讀檔；但對實際要修改的模組必須讀完足以理解其上下游與契約。

---

## 技術架構

Frontend：

- React
- TypeScript
- Vite
- Tailwind CSS

Backend：

- Laravel API
- Laravel Sanctum
- MySQL 或 MariaDB

前端只能透過 API 與後端溝通，不得直接接觸資料庫。正式餘額、收入、支出、毛利與 Dashboard 統計均由後端計算。

---

## 實作原則

1. 優先沿用既有架構、命名、Service、transaction、idempotency、Resource 與測試 pattern。
2. 採最小且完整的變更，不為單一任務另造重複抽象或平行實作。
3. Controller 保持薄；輸入驗證放 FormRequest，業務邏輯放 Service，權限放 Policy／Middleware，輸出遮蔽放 Resource／DTO。
4. 金流與狀態異動必須使用 database transaction。
5. 金額使用專案既有整數設計，不得使用 float。
6. 不得儲存可由交易紀錄推導的 `current_balance`。
7. 不得只靠前端隱藏敏感資料；後端 JSON 必須真正移除未授權欄位。
8. 不得以假資料、靜態數字或前端拼湊結果假裝功能完成。
9. 不得未經要求重構無關區域、改寫整體架構或新增企劃書外功能。
10. 工作樹可能有使用者變更；保留無關修改，不得使用破壞性 Git 指令覆蓋。
11. 若遇到文件寫的不明確需決策的問題就提早停止任務詢問使用者，嚴禁自行發想猜測下結論。
12. 註解採用簡潔白話的繁體中文。

---

## 既有高風險規則

### Idempotency

`VehicleService` 與 `MoneyEntryService` 已有成熟 pattern：

- unique `idempotency_key`
- payload 比對
- 相同 payload replay
- 不同 payload reject
- duplicate-key race 後 rollback
- 開新 transaction 重讀 winner

新增 workflow 或金流寫入必須沿用此模式，不得改成 silent success，也不得在 MySQL REPEATABLE READ 的舊 snapshot 內假裝能讀到 winner。

### MoneyEntry 與 approval

`MoneyEntry.source_type` 用來區分：

- `manual`
- `vehicle_shortcut`
- `vehicle_workflow`
- `legacy_unknown`
- v1.3 規劃中的 `salary_settlement`

非 `manual` 或來源未確認資料不得透過一般收支 CRUD 任意修改／刪除。

正式餘額、收入、支出、毛利與列印摘要只能計入 `approval_status=approved`。pending／rejected 不得影響正式彙總。

### 權限與敏感資料

正式角色：

```text
admin
manager
sales
```

- `admin`：最高權限，老闆兼會計。
- `manager`：可看完整營運金額與毛利，但不可管理使用者、寫入資金帳戶或核准收支。
- `sales`：不可看收購價、購車付款、完整成本、毛利、資金帳戶與未授權支出明細；可看議價所需價格與銷售收款資訊。

敏感欄位不得回傳 `0`、空字串或假值，應直接不存在於 JSON。測試需檢查原始 JSON。

### 車輛流程

```text
preparing → listed → reserved → sold
```

另有 `cancelled`。

不適用目前狀態的操作不得顯示或執行。成交結案只依 approved 收款判斷是否達成交價。

### Database safety

- MySQL／MariaDB concurrency 測試必須是真正跨連線、可觀察 commit 的測試。
- 不得用 `RefreshDatabase` 外層 transaction 製造假的並發覆蓋。
- migration 必須清楚說明 rollback、retry、resume 或 forward-only 邊界。
- 不得根據缺乏 durable provenance 的舊資料欄位做不安全推測式回填。
- 清空非測試資料庫前必須取得使用者明確同意。
- 使用 `migrate:fresh` 或 `db:wipe` 後，必須補回 seed 所需的管理員與資金帳戶等基礎資料。

---

## 版本邊界

只實作目前任務明確指定版本的企劃書與 PLAN 範圍。

v1.1、v1.2 已封版。除非使用者明確要求 hotfix，否則不得繼續塞入新功能。

v1.3 目前只允許薪資結算企劃列明的內容，包括薪資設定、收／賣車歸屬、版本化獎金方案、approved-only 毛利、整月跨級獎金、薪資草稿／鎖定／發薪與專用 MoneyEntry 保護。

禁止擴張為：

- 正式會計、傳票、借貸方 UI、報稅、稅期或發票
- POS
- 多公司、多分店、多租戶 SaaS
- OCR 或通用附件系統
- 完整 HR、打卡、排班、請假、官方勞健保級距或薪轉檔
- 租賃、押金、違約金或長短租合約
- 企劃書與 PLAN 未列出的進階功能

任務超出範圍時，停止實作並指出需要先新增或修訂哪份企劃／PLAN。

---

## 前端與 UI

API 呼叫集中在 `frontend/src/api` 對應模組，不得在元件內散落 URL。

前端遵守 `UI.md`：

- 現代後台
- 低噪音、高可讀性
- 桌機優先，手機可基本操作
- 支援 light／dark mode
- 沿用既有語意色彩 token
- 表單有 visible labels、required marker 與 per-field error
- 不使用假資料

若任務是功能實作，Codex 同時負責必要 UI。若使用者明確把純 UI 設計或調色交給 Claude，Codex 應保留 API、資料流、權限與驗證邊界，避免與 Claude 的視覺修改互相覆蓋。

---

## 標準工作流程

1. 確認任務與版本範圍。
2. 檢查 Git 狀態並保留既有無關變更。
3. 閱讀相關企劃、PLAN、文件、程式碼與測試。
4. 搜尋並確認既有實作 pattern 與所有上下游契約。
5. 先形成最小實作方案，再修改。
6. 完成功能、測試、API 型別與必要文件同步。
7. 執行與風險相稱的驗證並修正失敗。
8. 檢查 diff，確認沒有越界、假資料、敏感資訊洩漏或無關重構。
9. 更新對應 PLAN 的完成狀態；只有實際完成並驗證的項目才能勾選。
10. 一個完整功能或階段完成後，整理交接文件，供使用者開新聊天室延續。
11. 回報結果並提供建議 commit message；除非使用者明確要求，不自動 commit 或 push。

---

## 驗證要求

依任務範圍至少執行可用的相關驗證：

Backend：

- PHPUnit／Pest 對應測試
- migration／schema 驗證
- route、Request、Policy、Resource 與 API 行為
- 必要時 MySQL／MariaDB 真實並發測試

Frontend：

- TypeScript typecheck
- lint
- build
- 對應頁面操作與 API error handling

跨模組：

- 權限與原始 JSON 遮蔽
- pending／rejected 不進正式彙總
- idempotency replay-or-reject
- transaction 一致性
- API 契約與前端 types 同步
- light／dark mode 與基本 responsive

無法執行的驗證必須明確列出原因，不得將「未執行」寫成「通過」。

---

## Claude Review 的處理方式

收到 Claude adversarial review 後：

1. 逐項回到實際程式碼與測試查證。
2. 判斷 finding 是否可重現、是否在任務範圍、是否真有使用者可觀察影響。
3. 有效 finding 以最小修正處理並補回歸測試。
4. 無效或過度延伸的 finding 應以證據說明，不為了消除評論而盲改。
5. 修正後重新執行相關驗證，整理完成報告供下一輪審查。

特別預期 Claude 會檢查：

- 權限是否只靠前端隱藏
- JSON 是否洩漏敏感欄位
- approval filter 是否漏套用
- migration retry／rollback／resume 是否安全
- transaction 是否真的包住檢查與寫入
- idempotency 是否可處理 payload conflict 與真實 race
- MySQL concurrency test 是否為真實兩連線情境
- API contract、型別與既有資料升級是否回歸

---

## 完成回覆格式

每次完成實作或修正後，回覆包含：

1. 本次完成項目
2. 修改檔案
3. 重要實作說明
4. 驗證指令與結果
5. 未執行的驗證與原因
6. 風險、相容性或後續人工 Smoke 重點
7. 建議 commit message，格式為<type>：<Subject>。

建議 commit message 使用簡潔繁體中文。

不得只回覆「完成」。
