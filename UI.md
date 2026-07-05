# 中古車行營運系統 — 統一設計語言 v1.0

**風格定位：** Exaggerated-but-controlled Minimalism（韓系選品店感）· 中性 slate 灰階打底 · 單一品牌色 · 大量留白 · 低噪音。同一套 token 同時服務**後台 ERP**（資訊密度稍高）與**官網**（留白更大、標題更大）。

---

## 0. 架構原則：一套中性骨架 + 可抽換品牌色

神經中樞是 **slate 灰階**（Tailwind 原生 slate，已通過 WCAG 檢核）。品牌色只是插進去的一個變數 → 兩套提案共用所有 neutral / status / badge token，**只換 `--brand-*`**，日後想改色改一處即可。

---

## 1. 品牌主色 — 兩套提案

### 提案 A：Midnight（午夜藍）— ⭐ 建議

信賴感 + 專業度最平衡，官網對潛在客戶最有說服力。藍色天然帶「安全 / 可靠」語意，很適合成交金額高的中古車。

| 階 | Hex | 用途 |
|----|-----|------|
| brand-50 | `#EEF2FB` | 淡底、hover 背景 |
| brand-100 | `#D9E2F5` | 選中列底 |
| brand-200 | `#B3C4EA` | 邊框強調 |
| brand-500 | `#2E4EA3` | 次要互動 |
| **brand-600** | **`#1E3A8A`** | **Primary（按鈕/連結）** 白字對比 8.6:1 ✓ |
| brand-700 | `#172E6E` | hover / active |
| brand-800 | `#14213D` | 深色側欄、深色模式表面 |
| brand-900 | `#0B1526` | 深色模式底 |

### 提案 B：Graphite（石墨黑）— 編輯感替代方案

更冷、更「選品店」、更中性。缺點：純黑階缺少互動語意，**連結/焦點需借一個藍色 accent**（`#2563EB`）避免「只靠顏色」的無障礙問題。

| 階 | Hex | 用途 |
|----|-----|------|
| brand-50 | `#F5F5F6` | 淡底 |
| brand-100 | `#E7E8EA` | 選中列底 |
| brand-600 | `#334155` | 次要按鈕 |
| **brand-900** | **`#0F172A`** | **Primary** 白字對比 17:1 ✓ |
| brand-950 | `#020617` | 深色模式底 |
| accent（借用）| `#2563EB` | 連結 / focus ring / active |

> **建議：** 出 **A（Midnight）** 為預設品牌，B 當作可切換的 theme（同一份 token 架構，換 `--brand-*` 即可）。下面所有規範以 A 為示例值，並在深色欄標註。

---

## 2. 語意色彩 Tokens（Light + Dark 全套）

以語意命名，元件內**禁止**寫死 hex（對應 CLAUDE.md 的 `color-semantic`）。

| Token | Light | Dark | 說明 |
|-------|-------|------|------|
| `bg` | `#F8FAFC` | `#0B1120` | App 背景 |
| `surface` | `#FFFFFF` | `#131A2A` | 卡片 / 面板 |
| `surface-2` | `#F1F5F9` | `#1A2336` | 表頭 / 次面板 / hover |
| `fg` | `#0F172A` | `#E8EDF5` | 主文字（暗色不用純白，減眩光）|
| `fg-muted` | `#475569` | `#94A3B8` | 次要文字 對比 7:1 / 6:1 ✓ |
| `fg-subtle` | `#94A3B8` | `#64748B` | placeholder / 禁用文字 |
| `border` | `#E2E8F0` | `#25314A` | 分隔線 / 卡片邊 |
| `border-strong` | `#CBD5E1` | `#33415C` | input 邊框 |
| `primary` | `#1E3A8A` | `#3B60C4` | 主按鈕（暗色調亮才會跳）|
| `primary-hover` | `#172E6E` | `#4B72D6` | |
| `primary-fg` | `#FFFFFF` | `#FFFFFF` | |
| `ring` | `#1E3A8A` | `#3B82F6` | focus 2px |

> **深色模式關鍵：** 用「表面提亮 + 邊框」建立層級，**不要靠陰影**（暗色陰影看不見）。`bg → surface → surface-2` 亮度遞增即是 elevation。

---

## 3. 狀態色（Success / Warning / Error / Info）

Badge 採「柔和填色」：`底 + 文字 + 邊框` 三件組。文字色刻意壓深以達 4.5:1。

| 狀態 | Light 底 / 文字 / 邊 | Dark 底 / 文字 / 邊 | 主色 |
|------|--------------------|--------------------|------|
| Success 成功 | `#ECFDF5` / `#15803D` / `#A7F3D0` | `rgba(16,185,129,.15)` / `#6EE7B7` / `rgba(16,185,129,.3)` | `#16A34A` |
| Warning 警告 | `#FFFBEB` / `#B45309` / `#FDE68A` | `rgba(245,158,11,.15)` / `#FCD34D` / `rgba(245,158,11,.3)` | `#F59E0B` |
| Error 錯誤 | `#FEF2F2` / `#B91C1C` / `#FECACA` | `rgba(239,68,68,.15)` / `#FCA5A5` / `rgba(239,68,68,.3)` | `#DC2626` |
| Info 資訊 | `#EFF6FF` / `#1D4ED8` / `#BFDBFE` | `rgba(59,130,246,.15)` / `#93C5FD` / `rgba(59,130,246,.3)` | `#2563EB` |

`destructive` 按鈕實心用 `#DC2626`（白字），永遠搭 icon + 文字（不可只靠紅色傳達）。

---

## 4. 車輛狀態 Badge（5 種，刻意選 5 個可區分色相）

流程語意：**整備→上架→保留→售出**，取消為中性。每個都必須 icon + 文字，色盲也可辨。

| 狀態 | 語意色 | Light 底/文/邊 | Dark 底/文/邊 | icon 建議(Lucide) |
|------|--------|---------------|---------------|------|
| `preparing` 整備中 | Amber 進行中 | `#FFFBEB` / `#B45309` / `#FDE68A` | `rgba(245,158,11,.15)` / `#FCD34D` / `.3` | `wrench` |
| `listed` 上架中 | Blue 上市 | `#EFF6FF` / `#1D4ED8` / `#BFDBFE` | `rgba(59,130,246,.15)` / `#93C5FD` / `.3` | `tag` |
| `reserved` 保留中 | Violet 保留 | `#F5F3FF` / `#6D28D9` / `#DDD6FE` | `rgba(139,92,246,.15)` / `#C4B5FD` / `.3` | `bookmark` |
| `sold` 已售出 | Emerald 成交 | `#ECFDF5` / `#047857` / `#A7F3D0` | `rgba(16,185,129,.15)` / `#6EE7B7` / `.3` | `circle-check` |
| `cancelled` 取消/退車 | Slate 停用 | `#F1F5F9` / `#475569` / `#E2E8F0` | `rgba(148,163,184,.15)` / `#94A3B8` / `.25` | `x-circle` |

色相環間距足夠（黃→藍→紫→綠→灰），並列於表格中一眼可分。

---

## 5. 字體與字級

**字型堆疊**（拉丁字 + 數字用 Inter，中文用 Noto Sans TC，兩者字重對齊）：

```
font-sans:  Inter, "Noto Sans TC", system-ui, -apple-system, sans-serif
font-mono:  "JetBrains Mono", ui-monospace, Menlo, monospace   /* stock_no / ID */
```

金額欄位一律加 `font-variant-numeric: tabular-nums`（Inter 內建），數字對齊不跳動。載入用 `font-display: swap`。

**字級 / 行高（16px base）**

| Token | px / rem | line-height | 用途 |
|-------|----------|------|------|
| xs | 12 / .75 | 1.33 | 標籤、badge、表註 |
| sm | 14 / .875 | 1.43 | 表格內容、次要文字 |
| base | 16 / 1 | 1.5 | 內文（手機最小體，防 iOS 縮放）|
| lg | 18 / 1.125 | 1.5 | 卡片標題 |
| xl | 20 / 1.25 | 1.4 | 區塊標題 |
| 2xl | 24 / 1.5 | 1.3 | 頁面標題 |
| 3xl | 30 / 1.875 | 1.25 | Dashboard 大數字 |
| 4xl | 36 / 2.25 | 1.2 | 官網小標 |
| 5xl | 48 / 3 | 1.1 | 官網 Hero（`clamp(2.5rem,6vw,4rem)`）|

**字重：** 400 內文 · 500 標籤/導航/表頭 · 600 標題/按鈕 · 700 大標與 Hero。
**字距：** 標題 `-0.02em`；全大寫微標籤 `+0.04em`；內文預設。

---

## 6. 圓角 / 間距 / 陰影

**圓角**（韓系＝柔但不圓潤）

| Token | 值 | 用途 |
|-------|----|------|
| radius-sm | 6px | input · badge |
| radius-md | 8px | 按鈕 · 下拉 |
| radius-lg | 12px | 卡片 · 面板 |
| radius-xl | 16px | Modal · 大區塊 |
| radius-full | 9999px | pill · 頭像 |

**間距**：4px 基準（Tailwind 原生）。ERP 密度＝標準：卡片 padding `20–24px`、表單欄距 `16px`、區塊間距 `24–32px`；官網區塊間距放大到 `64–96px`。

**陰影**（極淡，靠邊框分層；深色模式改用邊框+提亮，不用陰影）

```
shadow-xs: 0 1px 2px rgba(15,23,42,.06)
shadow-sm: 0 1px 3px rgba(15,23,42,.08), 0 1px 2px rgba(15,23,42,.04)
shadow-md: 0 4px 12px rgba(15,23,42,.08)
shadow-lg: 0 12px 28px rgba(15,23,42,.12)   /* modal / dropdown */
```

---

## 7. 元件風格建議

- **Button**：primary 實心 `radius-md`、`h-10 (40px)` / 觸控區 ≥44px、`font-medium`、hover 150ms 過渡、loading 時 disable + spinner。層級：一頁一個 primary，其餘 outline（`border-strong` + `surface`）或 ghost。destructive 用紅，且與 primary 空間隔開。
- **Card**：`surface` + `border` + `radius-lg` + `shadow-sm`；標題 `lg/600`，內容 `sm`。Dashboard 數字卡用 `3xl/700` + tabular-nums + 小趨勢 badge。
- **Table**：表頭 `surface-2` + `sm/500` + `fg-muted`；列高 48px；hover 整列 `surface-2`；金額右對齊 + tabular-nums；可排序欄標 `aria-sort`；空狀態給「尚無資料 + 動作」而非空白。
- **Form**：label 永遠可見（不用 placeholder 當 label）、必填標 `*`、錯誤訊息在欄位下方且說明「原因+修正」、on-blur 驗證、input `h-10` `border-strong` focus 換 `ring`。
- **Badge**：pill `radius-full` `xs/500`，用上面三件組色，一律含 icon。
- **Sidebar**：深色 `brand-800`（Midnight）或 `brand-950`（Graphite）；active 項目 `primary` 底 + 左側 3px 指示條 + `500` 字重（狀態不只靠顏色）。
- **Toast**：`aria-live="polite"`、3–5 秒自動消失、成功綠/錯誤紅配 icon、不搶焦點。

---

## 8. Tailwind v4 設定（CSS-first `@theme`）

Tailwind v4 用 CSS 變數驅動。放 `src/index.css`：

```css
@import "tailwindcss";

@theme {
  /* ---- 字型 ---- */
  --font-sans: "Inter", "Noto Sans TC", system-ui, -apple-system, sans-serif;
  --font-mono: "JetBrains Mono", ui-monospace, Menlo, monospace;

  /* ---- 圓角 ---- */
  --radius-sm: 6px;  --radius-md: 8px;
  --radius-lg: 12px; --radius-xl: 16px;

  /* ---- 陰影 ---- */
  --shadow-xs: 0 1px 2px rgb(15 23 42 / .06);
  --shadow-sm: 0 1px 3px rgb(15 23 42 / .08), 0 1px 2px rgb(15 23 42 / .04);
  --shadow-md: 0 4px 12px rgb(15 23 42 / .08);
  --shadow-lg: 0 12px 28px rgb(15 23 42 / .12);

  /* ---- 語意色（預設 = Light / Midnight）---- */
  --color-bg: #F8FAFC;         --color-surface: #FFFFFF;
  --color-surface-2: #F1F5F9;
  --color-fg: #0F172A;         --color-fg-muted: #475569;
  --color-fg-subtle: #94A3B8;
  --color-border: #E2E8F0;     --color-border-strong: #CBD5E1;
  --color-primary: #1E3A8A;    --color-primary-hover: #172E6E;
  --color-primary-fg: #FFFFFF; --color-ring: #1E3A8A;

  /* ---- 狀態 ---- */
  --color-success: #16A34A; --color-warning: #F59E0B;
  --color-error:   #DC2626; --color-info:    #2563EB;

  /* ---- 車輛狀態（主色，badge 底/文另用 utility）---- */
  --color-status-preparing: #F59E0B;
  --color-status-listed:    #2563EB;
  --color-status-reserved:  #7C3AED;
  --color-status-sold:      #10B981;
  --color-status-cancelled: #64748B;
}

/* ---- Dark 覆寫（切 .dark class 或 data-theme）---- */
.dark {
  --color-bg: #0B1120;         --color-surface: #131A2A;
  --color-surface-2: #1A2336;
  --color-fg: #E8EDF5;         --color-fg-muted: #94A3B8;
  --color-fg-subtle: #64748B;
  --color-border: #25314A;     --color-border-strong: #33415C;
  --color-primary: #3B60C4;    --color-primary-hover: #4B72D6;
  --color-ring: #3B82F6;
}

/* ---- 切 Graphite 品牌：只覆寫 brand ---- */
.theme-graphite {
  --color-primary: #0F172A; --color-primary-hover: #1E293B; --color-ring: #2563EB;
}
.theme-graphite.dark { --color-bg:#0B0B0C; --color-surface:#141416; --color-primary:#E2E8F0; }
```

之後元件直接用語意 class：`bg-surface text-fg border-border`、`bg-primary text-primary-fg`、`text-fg-muted`、`shadow-sm rounded-lg`，切換深色只要在 `<html>` 加 `.dark`。

**Token 名稱對應表（給後端/Blade 列印頁共用）**

| 語意 | Tailwind class | CSS var |
|------|----------------|---------|
| 背景 | `bg-bg` | `--color-bg` |
| 卡片 | `bg-surface` | `--color-surface` |
| 主文字 | `text-fg` | `--color-fg` |
| 次文字 | `text-fg-muted` | `--color-fg-muted` |
| 主按鈕 | `bg-primary text-primary-fg` | `--color-primary` |
| 邊框 | `border-border` | `--color-border` |
| 車輛狀態 | `text-status-sold` 等 | `--color-status-*` |

---

## 9. 決策摘要

- **建議採用提案 A（Midnight）為預設**，把 Graphite 保留為 `.theme-graphite` 可切換皮膚——架構已支援，零額外成本。
- 官網與 ERP 共用此 `@theme`，官網只是加大字級（用到 4xl/5xl）與留白（64–96px），品牌一致。
