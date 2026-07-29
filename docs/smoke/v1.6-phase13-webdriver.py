#!/usr/bin/env python3
"""ERPV2 v1.6 第 13 章跨瀏覽器 WebDriver 工程 Browser Smoke。"""

from __future__ import annotations

import base64
import os
from typing import Any

from v15_webdriver_support import (
    Browser,
    BrowserConfig,
    Checks,
    wait_until,
)


FRONTEND = os.environ.get("SMOKE_FRONTEND_URL", "http://127.0.0.1:5196")
API = os.environ.get("SMOKE_API_URL", "http://127.0.0.1:8016")
VISIBLE = os.environ.get("SMOKE_VISIBLE") == "1"
BROWSER_NAME = os.environ.get("SMOKE_BROWSER", "firefox")
CONFIG = BrowserConfig.chrome() if BROWSER_NAME == "chrome" else BrowserConfig.firefox()
checks = Checks()


class V16Browser(Browser):
    def __init__(
        self,
        config: BrowserConfig,
        frontend: str,
        api: str,
        width: int = 1440,
        height: int = 1000,
    ) -> None:
        super().__init__(config, frontend, api, width, height)
        self.set_size(width, height)

    def _capabilities(self) -> dict[str, Any]:
        capabilities = super()._capabilities()
        if self.config.browser == "firefox":
            if VISIBLE:
                capabilities["moz:firefoxOptions"]["args"] = []
            capabilities["moz:firefoxOptions"]["prefs"] = {
                "media.prefers-color-scheme": 0,
                "accessibility.force_disabled": 0,
            }
        return capabilities

    def set_size(self, width: int, height: int) -> None:
        if self.config.browser == "chrome":
            self._request(
                "POST",
                f"/session/{self.session}/goog/cdp/execute",
                {
                    "cmd": "Emulation.setDeviceMetricsOverride",
                    "params": {
                        "width": width,
                        "height": height,
                        "deviceScaleFactor": 1,
                        "mobile": width < 768,
                    },
                },
            )
            return

        self._request(
            "POST",
            f"/session/{self.session}/window/rect",
            {"width": width, "height": height},
        )

    def key(self, value: str) -> None:
        self._request(
            "POST",
            f"/session/{self.session}/actions",
            {
                "actions": [{
                    "type": "key",
                    "id": "keyboard",
                    "actions": [
                        {"type": "keyDown", "value": value},
                        {"type": "keyUp", "value": value},
                    ],
                }],
            },
        )
        self._request("DELETE", f"/session/{self.session}/actions")

    def print_pdf(self) -> bytes:
        encoded = self._request(
            "POST",
            f"/session/{self.session}/print",
            {
                "orientation": "portrait",
                "background": True,
                "page": {"width": 21.0, "height": 29.7},
                "margin": {
                    "top": 0.5,
                    "bottom": 0.5,
                    "left": 0.5,
                    "right": 0.5,
                },
            },
        )
        return base64.b64decode(encoded)


def browser(width: int = 1440, height: int = 1000) -> V16Browser:
    return V16Browser(CONFIG, FRONTEND, API, width, height)


def login(target: Browser, identifier: str, password: str) -> None:
    target.get("/login")
    target.find_css("#login")
    target.replace("#login", identifier)
    target.replace("#password", password)
    target.click_text("登入")
    target.wait_url("/dashboard")
    target.wait_text("營運總覽")


def assert_no_document_overflow(target: Browser) -> bool:
    return bool(target.execute(
        """
        return document.documentElement.scrollWidth
          <= document.documentElement.clientWidth + 1
          && document.body.scrollWidth
          <= document.body.clientWidth + 1
        """
    ))


def h1(target: Browser) -> str:
    return str(target.execute(
        "return document.querySelector('h1')?.textContent?.trim() || ''"
    ))


def set_theme(target: Browser, dark: bool) -> None:
    current = bool(target.execute(
        "return document.documentElement.classList.contains('dark')"
    ))
    if current != dark:
        target.click(target.find_css("button[aria-label*='模式']"))
        wait_until(
            lambda: bool(target.execute(
                "return document.documentElement.classList.contains('dark')"
            )) == dark,
            5,
            "theme toggle",
        )


def route_check(target: Browser, path: str, heading: str) -> None:
    target.get(path)
    wait_until(lambda: h1(target) == heading, 12, f"{path} heading")


def api_data(target: Browser, path: str) -> Any:
    response = target.fetch(path)
    if response.get("status") != 200:
        raise AssertionError(f"{path} API status: {response}")
    return response.get("body", {}).get("data")


admin = None
manager = None
sales = None
mobile = None

try:
    admin = browser()
    login(admin, "admin@example.com", "password")
    admin.wait_text("每日期末帳面餘額")

    dashboard_text = admin.body()
    checks.check(
        "Admin Dashboard 所有區塊與完整金額口徑",
        all(text in dashboard_text for text in [
            "工作概況",
            "經營概況",
            "趨勢分析",
            "本月涵蓋完整月份",
            "金額僅計已核准收支",
            "現金為正式帳面餘額",
            "近 30 天成交量",
            "近 30 天毛利",
            "現金變化",
            "每日期末帳面餘額",
        ]),
    )
    checks.check(
        "Admin Dashboard 有值、pending 與不同車態 fixture",
        all(text in dashboard_text for text in [
            "待整備",
            "待上架",
            "待交車",
            "待審核收支",
            "本月成交",
        ]),
    )

    for dark in [False, True]:
        set_theme(admin, dark)
        checks.check(
            f"1440px {'dark' if dark else 'light'} mode 無水平 overflow",
            assert_no_document_overflow(admin),
        )

    for path, heading in {
        "/account": "我的帳號",
        "/users": "員工/帳號管理",
        "/vehicles": "車輛管理",
        "/money-entries": "收支管理",
        "/customers": "客戶管理",
        "/salary": "薪資結算",
        "/audit-logs": "稽核紀錄",
        "/cash-accounts": "資金帳戶",
    }.items():
        route_check(admin, path, heading)
    checks.check("Admin Account／User／營運／薪資／Audit／資金帳戶主要路由")

    admin.get("/dashboard")
    admin.wait_text("營運總覽")
    admin.execute(
        """
        document.body.setAttribute('tabindex', '-1');
        document.body.focus();
        """
    )
    account_reached = False
    for _ in range(40):
        admin.key("\ue004")
        account_reached = bool(admin.execute(
            """
            const active = document.activeElement;
            return active?.matches("a[aria-label^='我的帳號：']");
            """
        ))
        if account_reached:
            break
    if account_reached:
        admin.key("\ue007")
        admin.wait_url("/account")
        wait_until(
            lambda: h1(admin) == "我的帳號",
            5,
            "keyboard account heading",
        )
    checks.check(
        "Keyboard-only Tab 與 Enter 可進入我的帳號",
        account_reached and h1(admin) == "我的帳號",
    )
    admin.replace("#account-name", "系統管理員")
    admin.click_text("儲存個人資料")
    admin.wait_text("個人資料已更新")
    checks.check("Account 成功狀態可感知")

    vehicles = api_data(admin, "/api/vehicles?per_page=100")
    sold_id = next(
        item["id"] for item in vehicles
        if item["stock_no"] == "V16-SOLD"
    )
    reserved_id = next(
        item["id"] for item in vehicles
        if item["stock_no"] == "V16-RESERVED"
    )
    route_check(admin, f"/vehicles/{sold_id}", "V16-SOLD")
    sold_text = admin.body()
    checks.check(
        "成交車保留 approved-only 收支與敏感金額資訊",
        all(text in sold_text for text in [
            "單車收支摘要",
            "單車毛利",
            "成交價",
            "已核准",
        ]),
    )

    admin.get(f"/vehicles/{sold_id}/print/intake")
    admin.wait_text("車輛建檔資料")
    intake_pdf = admin.print_pdf()
    checks.check(
        "車輛建檔紙本與 PDF 預覽可產生",
        intake_pdf.startswith(b"%PDF") and len(intake_pdf) > 10_000,
    )
    admin.get(f"/vehicles/{sold_id}/print/closing")
    admin.wait_text("成交結案收支明細")
    admin.wait_text("收入合計")
    closing_pdf = admin.print_pdf()
    checks.check(
        "成交結案紙本與 PDF 預覽可產生且保留正式摘要",
        closing_pdf.startswith(b"%PDF")
        and len(closing_pdf) > 10_000
        and "單車毛利" in admin.body(),
    )

    route_check(admin, f"/vehicles/{reserved_id}", "V16-RESERVED")
    admin.click_text("成交結案")
    admin.wait_text("成交日期決定薪資獎金月份")
    checks.check(
        "成交結案 Modal 保留月份鎖定與收款日期後果",
        all(text in admin.body() for text in [
            "已確認或已發薪月份不能新增成交",
            "收款日期不影響成交月份",
        ]),
    )
    admin.key("\ue00c")
    wait_until(
        lambda: "成交日期決定薪資獎金月份" not in admin.body(),
        5,
        "close-sale modal escape",
    )
    checks.check("Desktop Modal 可用 Escape 關閉")

    periods = api_data(admin, "/api/salary-periods")
    by_status = {period["status"]: period["id"] for period in periods}
    route_check(
        admin,
        f"/salary/periods/{by_status['confirmed']}",
        next(
            period["period_month"] for period in periods
            if period["status"] == "confirmed"
        ) + " 薪資",
    )
    checks.check(
        "已確認薪資保留鎖定與發薪操作後果",
        "本月已確認，薪資快照與車輛歸屬已鎖定。" in admin.body()
        and "發薪" in admin.body(),
    )
    route_check(
        admin,
        f"/salary/periods/{by_status['paid']}",
        next(
            period["period_month"] for period in periods
            if period["status"] == "paid"
        ) + " 薪資",
    )
    checks.check(
        "已發薪月份保留日期、帳戶、鎖定與唯讀後果",
        all(text in admin.body() for text in [
            "已於",
            "由 現金 發薪",
            "薪資快照與車輛歸屬已鎖定",
            "本月份唯讀",
        ]),
    )
    route_check(
        admin,
        f"/salary/periods/{by_status['draft']}",
        next(
            period["period_month"] for period in periods
            if period["status"] == "draft"
        ) + " 薪資",
    )
    admin.wait_text("尚未結束")
    checks.check(
        "當月薪資 warning 只在 draft 顯示且說明延後確認",
        "請於下個月確認結算，以免漏掉後續成交" in admin.body(),
    )

    admin.get("/vehicles?status=sold&sold_month=1999-01")
    admin.wait_text("尚無符合條件的車輛")
    checks.check("Vehicle Filter 空狀態可理解")

    route_check(admin, "/money-entries/create", "新增收支")
    admin.click_text("建立收支")
    admin.wait_text("請選擇分類")
    wait_until(
        lambda: admin.execute(
            "return document.activeElement?.matches('[aria-invalid=\"true\"]')"
        ),
        5,
        "desktop first invalid focus",
    )
    first_invalid = admin.execute(
        """
        const field = document.querySelector('[aria-invalid="true"]');
        const describedBy = field?.getAttribute('aria-describedby') || '';
        return {
          id: field?.id || '',
          focused: document.activeElement === field,
          describedBy,
          errorText: document.getElementById(describedBy)?.textContent || '',
        }
        """
    )
    checks.check(
        "Desktop 表單錯誤、首錯 focus 與 described-by 可感知",
        first_invalid.get("focused")
        and first_invalid.get("describedBy")
        and "請選擇分類" in first_invalid.get("errorText", ""),
    )

    manager = browser()
    login(manager, "manager.v16@example.test", "ManagerV16!")
    manager.wait_text("本月收入")
    manager_text = manager.body()
    checks.check(
        "Manager Dashboard 顯示財務 KPI",
        "本月收入" in manager_text and "本月毛利" in manager_text,
    )
    for path, heading in {
        "/vehicles": "車輛管理",
        "/money-entries": "收支管理",
        "/customers": "客戶管理",
    }.items():
        route_check(manager, path, heading)
    manager.get("/users")
    manager.wait_url("/dashboard")
    checks.check(
        "Manager 不出現 admin-only 入口或教學",
        "員工管理" not in manager.body()
        and manager.fetch("/api/users").get("status") == 403,
    )

    sales = browser()
    login(sales, "sales.v16@example.test", "SalesV16!")
    sales.wait_text("工作概況")
    sales_text = sales.body()
    checks.check(
        "Sales Dashboard 不出現財務 KPI 或 approved-only 財務口徑",
        all(text not in sales_text for text in [
            "本月收入",
            "本月支出",
            "本月毛利",
            "現金帳面餘額",
            "金額僅計已核准收支",
        ]),
    )
    for path, heading in {
        "/vehicles": "車輛管理",
        "/customers": "客戶管理",
        "/money-entries": "收支管理",
    }.items():
        route_check(sales, path, heading)
    route_check(sales, f"/vehicles/{sold_id}", "V16-SOLD")
    sales_vehicle_text = sales.body()
    checks.check(
        "Sales 銷售收款可理解且不洩漏成本毛利",
        "銷售收款摘要" in sales_vehicle_text
        and "成交價" in sales_vehicle_text
        and "收購價" not in sales_vehicle_text
        and "單車毛利" not in sales_vehicle_text,
    )

    mobile = browser(390, 844)
    login(mobile, "admin@example.com", "password")
    responsive_routes = {
        "/dashboard": "營運總覽",
        "/vehicles": "車輛管理",
        "/money-entries": "收支管理",
        "/customers": "客戶管理",
        "/users": "員工/帳號管理",
        "/cash-accounts": "資金帳戶",
        f"/salary/periods/{by_status['draft']}": next(
            period["period_month"] for period in periods
            if period["status"] == "draft"
        ) + " 薪資",
    }
    for width, height in [
        (768, 1024),
        (390, 844),
        (375, 667),
        (320, 568),
    ]:
        mobile.set_size(width, height)
        for dark in [False, True]:
            set_theme(mobile, dark)
            for path, heading in responsive_routes.items():
                route_check(mobile, path, heading)
                actual_width = int(mobile.execute("return window.innerWidth"))
                if actual_width != width:
                    raise AssertionError(
                        f"viewport width expected {width}, got {actual_width}"
                    )
                if not assert_no_document_overflow(mobile):
                    raise AssertionError(
                        f"{width}px {'dark' if dark else 'light'} overflow at {path}"
                    )
        checks.check(f"{width}px light／dark 主要頁面無水平 overflow")

    mobile.set_size(320, 568)
    route_check(mobile, "/money-entries/create", "新增收支")
    mobile.click_text("建立收支")
    mobile.wait_text("請選擇分類")
    wait_until(
        lambda: mobile.execute(
            "return document.activeElement?.matches('[aria-invalid=\"true\"]')"
        ),
        5,
        "mobile first invalid focus",
    )
    mobile_error = mobile.execute(
        """
        const field = document.querySelector('[aria-invalid="true"]');
        const rect = field?.getBoundingClientRect();
        const visibleText = Array.from(
          document.querySelectorAll('label, [id$="-error"]')
        ).filter((node) => {
          const style = getComputedStyle(node);
          return style.display !== 'none' && style.visibility !== 'hidden';
        });
        return Boolean(field && document.activeElement === field
          && rect && rect.top < window.innerHeight && rect.bottom > 0
          && visibleText.every((node) => {
            const box = node.getBoundingClientRect();
            return box.left >= -1 && box.right <= window.innerWidth + 1;
          })
          && document.documentElement.scrollWidth <= window.innerWidth + 1)
        """
    )
    checks.check(
        "320px Form label／error 不截斷且首錯可見可聚焦",
        bool(mobile_error),
    )

    route_check(mobile, "/vehicles", "車輛管理")
    mobile.click_text("篩選")
    drawer_focus = mobile.execute(
        """
        return document.querySelector('[role="dialog"]') !== null
          && document.activeElement?.getAttribute('aria-label') === '關閉篩選'
        """
    )
    mobile.key("\ue00c")
    wait_until(
        lambda: mobile.execute(
            "return document.querySelector('[role=\"dialog\"]') === null"
        ),
        5,
        "filter drawer close",
    )
    checks.check("320px Filter Drawer focus 與 Escape 可操作", bool(drawer_focus))

    route_check(mobile, f"/vehicles/{reserved_id}", "V16-RESERVED")
    mobile.click_text("成交結案")
    mobile.wait_text("成交日期決定薪資獎金月份")
    mobile_modal = mobile.execute(
        """
        const dialog = document.querySelector('[role="dialog"]');
        if (!dialog) return false;
        const rect = dialog.getBoundingClientRect();
        const overflowY = getComputedStyle(dialog).overflowY;
        const contentReachable = dialog.scrollHeight <= dialog.clientHeight
          || ['auto', 'scroll'].includes(overflowY);
        return rect.left >= -1 && rect.right <= window.innerWidth + 1
          && contentReachable
          && document.activeElement?.textContent?.trim() === '關閉'
          && document.documentElement.scrollWidth <= window.innerWidth + 1;
        """
    )
    mobile.key("\ue00c")
    wait_until(
        lambda: mobile.execute(
            "return document.querySelector('[role=\"dialog\"]') === null"
        ),
        5,
        "mobile modal close",
    )
    checks.check("320px Modal 可操作且不造成頁面 overflow", bool(mobile_modal))

    route_check(mobile, "/users", "員工/帳號管理")
    table_metrics = mobile.execute(
        """
        const table = document.querySelector('table');
        const scroller = table?.parentElement;
        return {
          tableWide: Boolean(table && scroller
            && table.scrollWidth > scroller.clientWidth),
          scrollable: Boolean(scroller
            && ['auto','scroll'].includes(getComputedStyle(scroller).overflowX)),
          noDocumentOverflow:
            document.documentElement.scrollWidth <= window.innerWidth + 1,
          selfReasonVisible: document.body.innerText.includes(
            '不可變更自己的角色、停用或刪除自己的帳號'
          ),
        }
        """
    )
    checks.check(
        "320px 員工表格可橫向捲動且 self 短句不破版",
        table_metrics.get("tableWide")
        and table_metrics.get("scrollable")
        and table_metrics.get("noDocumentOverflow")
        and table_metrics.get("selfReasonVisible"),
    )

    route_check(mobile, "/cash-accounts", "資金帳戶")
    cash_metrics = mobile.execute(
        """
        const table = document.querySelector('table');
        const scroller = table?.parentElement;
        return Boolean(table && scroller
          && table.scrollWidth > scroller.clientWidth
          && ['auto','scroll'].includes(getComputedStyle(scroller).overflowX)
          && document.documentElement.scrollWidth <= window.innerWidth + 1)
        """
    )
    checks.check("320px 資金帳戶表格可橫向捲動", bool(cash_metrics))

    safe_area = mobile.execute(
        """
        const root = document.querySelector('#root');
        const shell = root?.firstElementChild;
        const style = shell ? getComputedStyle(shell) : null;
        return {
          minHeight: style?.minHeight || '',
          viewportHeight: window.innerHeight,
          bodyHeight: document.body.getBoundingClientRect().height,
        }
        """
    )
    checks.check(
        "Mobile Safe Area 與底部 viewport 空間不回歸",
        "100" in safe_area.get("minHeight", "")
        or safe_area.get("bodyHeight", 0) >= safe_area.get("viewportHeight", 0),
    )

    checks.check(
        f"{CONFIG.browser} 真實 Laravel API 與專用 fixture 工程 smoke 完成",
        len(checks.names) >= 31,
    )
    print(
        f"RESULT: {len(checks.names)} {CONFIG.browser} checks passed",
        flush=True,
    )
finally:
    for target in [mobile, sales, manager, admin]:
        if target is not None:
            target.close()
