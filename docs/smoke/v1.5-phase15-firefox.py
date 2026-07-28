#!/usr/bin/env python3
"""重跑 v1.5 第 15 部分的 38 項 Firefox 工程預驗證。"""

from __future__ import annotations

import os

from v15_webdriver_support import (
    Browser,
    BrowserConfig,
    Checks,
    wait_until,
)


FRONTEND = os.environ.get("SMOKE_FRONTEND_URL", "http://127.0.0.1:5195")
API = os.environ.get("SMOKE_API_URL", "http://127.0.0.1:8015")
CONFIG = BrowserConfig.firefox()
checks = Checks()


def browser(width: int = 1440, height: int = 1000) -> Browser:
    return Browser(CONFIG, FRONTEND, API, width, height)


def login(
    target: Browser,
    identifier: str,
    password: str,
    expected_path: str = "/dashboard",
) -> None:
    target.get("/login")
    target.find_css("#login")
    target.replace("#login", identifier)
    target.replace("#password", password)
    target.click_text("登入")
    target.wait_url(expected_path)


def logout(target: Browser) -> None:
    target.click_text("登出")
    target.wait_url("/login")


def change_required_password(
    target: Browser,
    current_password: str,
    new_password: str,
) -> None:
    target.replace("#current-password", current_password)
    target.replace("#new-password", new_password)
    target.replace("#password-confirmation", new_password)
    target.click_text("修改密碼並繼續")
    target.wait_url("/dashboard")


def create_user(
    admin: Browser,
    name: str,
    email: str,
    password: str,
    role: str = "sales",
) -> None:
    admin.get("/users")
    admin.wait_text("員工/帳號管理")
    admin.click_text("新增員工")
    form = admin.find_xpath(
        "//form[.//button[normalize-space()='建立員工']]"
    )
    name_input = admin.find_css("input[type='text']", parent=form)
    email_input = admin.find_css("input[type='email']", parent=form)
    password_input = admin.find_css("input[type='password']", parent=form)
    role_select = admin.find_css("select", parent=form)
    admin.type(name_input, name)
    admin.type(email_input, email)
    admin.type(password_input, password)
    if role != "sales":
        admin.click(role_select)
        admin.type(role_select, "經理" if role == "manager" else "管理員")
    admin.click(
        admin.find_xpath(
            ".//button[normalize-space()='建立員工']",
            parent=form,
        )
    )
    admin.wait_text("員工已建立")
    admin.wait_text(email)


def account_profile(
    target: Browser,
    name: str,
    username: str,
) -> None:
    target.get("/account")
    target.wait_text("我的帳號")
    target.replace("#account-name", name)
    target.replace("#account-username", username)
    target.click_text("儲存個人資料")
    target.wait_text("個人資料已更新")


def account_password(
    target: Browser,
    current_password: str,
    password: str,
    confirmation: str | None = None,
) -> None:
    target.replace("#account-current-password", current_password)
    target.replace("#account-new-password", password)
    target.replace(
        "#account-password-confirmation",
        confirmation or password,
    )
    target.click_text("更新密碼")


def row_for(admin: Browser, email: str) -> str:
    return admin.find_xpath(
        f"//tr[.//td[normalize-space()='{email}']]"
    )


admin = None
sales_a = None
sales_a_second = None
sales_b = None
manager = None
mobile = None

try:
    admin = browser()
    admin.get("/login")
    admin.wait_text("中古車行內部營運系統")
    checks.check(
        "Login 顯示正式系統名稱與副標",
        "請登入以繼續" in admin.body(),
    )
    checks.check(
        "Browser title 使用 app config",
        admin.title() == "中古車行內部營運系統",
    )
    login(admin, "admin@example.com", "password")
    checks.check(
        "Admin 以既有 Email 登入",
        admin.url().endswith("/dashboard"),
    )
    checks.check(
        "Sidebar 顯示 app config 短名稱",
        "中古車行系統" in admin.body(),
    )

    create_user(
        admin,
        "第十五部分業務甲",
        "v15.sales.a@example.test",
        "DefaultA123!",
    )
    row_text = admin.text(row_for(admin, "v15.sales.a@example.test"))
    checks.check(
        "Admin 建立 sales 帳號並顯示 Email／角色",
        "業務" in row_text,
    )
    checks.check(
        "User list 顯示 username 尚未設定",
        "尚未設定" in row_text,
    )
    checks.check(
        "User list 顯示待修改密碼",
        "需修改密碼" in row_text,
    )
    checks.check(
        "建立提示說明 Email、預設密碼與立即改密碼",
        "首次登入需使用 Email" in admin.body(),
    )

    create_user(
        admin,
        "第十五部分業務乙",
        "v15.sales.b@example.test",
        "DefaultB123!",
    )
    create_user(
        admin,
        "第十五部分經理",
        "v15.manager@example.test",
        "DefaultM123!",
        "manager",
    )

    sales_b = browser()
    login(
        sales_b,
        "v15.sales.b@example.test",
        "DefaultB123!",
        "/change-password",
    )
    logout(sales_b)
    checks.check(
        "強制改密碼頁仍可登出",
        sales_b.url().endswith("/login"),
    )

    sales_a = browser()
    login(
        sales_a,
        "v15.sales.a@example.test",
        "DefaultA123!",
        "/change-password",
    )
    checks.check(
        "Email + 預設密碼登入後立即導向強制改密碼",
        sales_a.url().endswith("/change-password"),
    )
    sales_a.get("/dashboard")
    sales_a.wait_url("/change-password")
    checks.check("直接輸入 /dashboard 仍回強制頁")

    gate = sales_a.fetch("/api/dashboard/summary")
    checks.check(
        "一般 API 由後端回 409 PASSWORD_CHANGE_REQUIRED",
        gate.get("status") == 409
        and gate.get("body", {}).get("code")
        == "PASSWORD_CHANGE_REQUIRED",
    )

    before_theme = sales_a.execute(
        "return document.documentElement.classList.contains('dark')"
    )
    sales_a.click(sales_a.find_css("button[aria-label*='模式']"))
    after_theme = sales_a.execute(
        "return document.documentElement.classList.contains('dark')"
    )
    checks.check(
        "強制改密碼頁 Theme Toggle 可用",
        before_theme != after_theme,
    )

    sales_a.replace("#current-password", "WrongCurrent123!")
    sales_a.replace("#new-password", "FirstNewA123!")
    sales_a.replace("#password-confirmation", "FirstNewA123!")
    sales_a.click_text("修改密碼並繼續")
    sales_a.wait_text("不正確")
    checks.check(
        "錯誤目前密碼不可通過",
        sales_a.url().endswith("/change-password"),
    )
    sales_a.replace("#current-password", "DefaultA123!")
    sales_a.click_text("修改密碼並繼續")
    sales_a.wait_url("/dashboard")
    checks.check("正確修改預設密碼後進 Dashboard")

    sales_a.click(sales_a.find_css("a[aria-label^='我的帳號：']"))
    sales_a.wait_url("/account")
    checks.check("Header 可進我的帳號")
    account_profile(sales_a, "業務甲已更新", "smoke.sales")
    header_label = sales_a.execute(
        """
        return document.querySelector(
          "a[aria-label^='我的帳號：']"
        )?.getAttribute('aria-label')
        """
    )
    checks.check(
        "修改名稱後 Header 即時更新",
        header_label == "我的帳號：業務甲已更新",
    )
    checks.check(
        "設定 username 並以小寫回填",
        sales_a.execute(
            "return document.querySelector('#account-username').value"
        )
        == "smoke.sales",
    )
    checks.check(
        "Email／角色只讀且不可編輯",
        sales_a.execute(
            """
            return !document.querySelector(
              'input[name=email], select[name=role], input[name=role]'
            )
            """
        ),
    )

    account_password(sales_a, "WrongCurrent123!", "SecondNewA123!")
    sales_a.wait_text("不正確")
    checks.check("我的帳號修改密碼必須目前密碼")
    account_password(
        sales_a,
        "FirstNewA123!",
        "SecondNewA123!",
        "MismatchA123!",
    )
    sales_a.wait_text("新密碼與確認密碼不一致")
    checks.check("我的帳號 confirmation 不符顯示錯誤")
    account_password(sales_a, "FirstNewA123!", "SecondNewA123!")
    sales_a.wait_text("密碼已更新")
    checks.check("我的帳號可用目前密碼完成修改")

    login(
        sales_b,
        "v15.sales.b@example.test",
        "DefaultB123!",
        "/change-password",
    )
    change_required_password(
        sales_b,
        "DefaultB123!",
        "FirstNewB123!",
    )
    sales_b.get("/account")
    sales_b.wait_text("我的帳號")
    sales_b.replace("#account-name", "業務乙")
    sales_b.replace("#account-username", "smoke.sales")
    sales_b.click_text("儲存個人資料")
    sales_b.wait_text("已被使用")
    checks.check(
        "重複 username 顯示欄位錯誤",
        sales_b.url().endswith("/account"),
    )

    logout(sales_a)
    login(sales_a, "smoke.sales", "SecondNewA123!")
    checks.check("username + 新密碼登入")
    logout(sales_a)
    login(
        sales_a,
        "v15.sales.a@example.test",
        "SecondNewA123!",
    )
    checks.check("Email + 同一新密碼登入")
    logout(sales_a)
    login(sales_a, "SMOKE.SALES", "SecondNewA123!")
    checks.check("username 大小寫登入符合規格")
    logout(sales_a)

    sales_a.get("/login")
    sales_a.replace("#login", "SMOKE.SALES")
    sales_a.replace("#password", "WrongPassword123!")
    sales_a.click_text("登入")
    sales_a.wait_text("帳號或密碼錯誤")
    username_error = "帳號或密碼錯誤" in sales_a.body()
    sales_a.replace("#login", "does-not-exist@example.test")
    sales_a.replace("#password", "WrongPassword123!")
    sales_a.click_text("登入")
    sales_a.wait_text("帳號或密碼錯誤")
    checks.check(
        "錯誤密碼訊息不洩漏識別方式",
        username_error,
    )

    login(sales_a, "smoke.sales", "SecondNewA123!")
    sales_a_second = browser()
    login(
        sales_a_second,
        "v15.sales.a@example.test",
        "SecondNewA123!",
    )

    admin.get("/users")
    admin.wait_text("v15.sales.a@example.test")
    row = row_for(admin, "v15.sales.a@example.test")
    admin.click(
        admin.find_xpath(
            ".//button[normalize-space()='重設密碼']",
            parent=row,
        )
    )
    reset_form = admin.find_xpath(
        "//form[.//button[normalize-space()='重設密碼']]"
    )
    reset_input = admin.find_css(
        "input[type='password']",
        parent=reset_form,
    )
    admin.type(reset_input, "ResetA123!")
    admin.click(
        admin.find_xpath(
            ".//button[normalize-space()='重設密碼']",
            parent=reset_form,
        )
    )
    admin.wait_text("密碼已重設")
    reset_row_text = admin.text(
        row_for(admin, "v15.sales.a@example.test")
    )
    checks.check(
        "Admin 重設員工密碼並顯示正確提示",
        "需修改密碼" in reset_row_text
        and "既有登入會失效" in admin.body(),
    )

    first_old_session = sales_a.fetch("/api/me")
    sales_a.get("/vehicles")
    sales_a.wait_url("/login")
    second_old_session = sales_a_second.fetch("/api/me")
    sales_a_second.get("/vehicles")
    sales_a_second.wait_url("/login")
    checks.check(
        "員工每個既有 stateful Session 下次操作回 401 並登出",
        first_old_session.get("status") == 401
        and second_old_session.get("status") == 401,
    )
    login(
        sales_a,
        "smoke.sales",
        "ResetA123!",
        "/change-password",
    )
    checks.check("以新預設密碼重新登入後進強制修改密碼頁")
    change_required_password(
        sales_a,
        "ResetA123!",
        "FinalA123!",
    )
    checks.check("使用新預設密碼完成強制修改")

    manager = browser()
    login(
        manager,
        "v15.manager@example.test",
        "DefaultM123!",
        "/change-password",
    )
    change_required_password(
        manager,
        "DefaultM123!",
        "ManagerNew123!",
    )
    checks.check("Manager 首次登入流程可完成")

    for path, marker in {
        "/dashboard": "營運總覽",
        "/vehicles": "車輛",
        "/customers": "客戶",
        "/money-entries": "收支",
        "/cash-accounts": "資金帳戶",
        "/users": "員工/帳號管理",
        "/salary": "薪資",
        "/audit-logs": "稽核",
    }.items():
        admin.get(path)
        admin.wait_text(marker)
    checks.check("Admin 既有主要路由 smoke")

    for path, marker in {
        "/dashboard": "營運總覽",
        "/vehicles": "車輛",
        "/customers": "客戶",
        "/money-entries": "收支",
        "/cash-accounts": "資金帳戶",
    }.items():
        manager.get(path)
        manager.wait_text(marker)
    manager.get("/users")
    manager.wait_url("/dashboard")
    checks.check("Manager 主要流程可用且管理員路由仍被阻擋")

    for path, marker in {
        "/dashboard": "營運總覽",
        "/vehicles": "車輛",
        "/customers": "客戶",
        "/money-entries": "收支",
        "/account": "我的帳號",
    }.items():
        sales_a.get(path)
        sales_a.wait_text(marker)
    sales_a.get("/cash-accounts")
    sales_a.wait_url("/dashboard")
    checks.check("Sales 主要流程可用且敏感路由仍被阻擋")

    mobile = browser(390, 844)
    login(mobile, "smoke.sales", "FinalA123!")
    no_overflow = mobile.execute(
        """
        return document.documentElement.scrollWidth
          <= document.documentElement.clientWidth
        """
    )
    mobile.click(
        mobile.find_css("button[aria-label='開啟導覽選單']")
    )
    close_button = mobile.find_css(
        "button[aria-label='關閉導覽選單']"
    )
    focused_close = mobile.execute(
        """
        return document.activeElement?.getAttribute('aria-label')
          === '關閉導覽選單'
        """
    )
    body_locked = mobile.execute(
        "return document.body.style.overflow === 'hidden'"
    )
    mobile.click(close_button)
    wait_until(
        lambda: mobile.execute(
            "return document.body.style.overflow !== 'hidden'"
        ),
        5,
        "mobile body unlock",
    )
    checks.check(
        "390px Mobile Sidebar、focus、捲動鎖定與無水平 overflow",
        no_overflow and focused_close and body_locked,
    )
    mobile_theme_before = mobile.execute(
        "return document.documentElement.classList.contains('dark')"
    )
    mobile.click(mobile.find_css("button[aria-label*='模式']"))
    mobile_theme_after = mobile.execute(
        "return document.documentElement.classList.contains('dark')"
    )
    mobile.get("/account")
    mobile.wait_text("我的帳號")
    checks.check(
        "Mobile Firefox 基本 Account 與 Theme 操作",
        mobile_theme_before != mobile_theme_after,
    )

    checks.check(
        "Firefox 可拋棄環境完整 smoke 執行完成",
        len(checks.names) >= 37,
    )
    print(
        f"RESULT: {len(checks.names)} Firefox checks passed",
        flush=True,
    )
finally:
    for target in [
        mobile,
        manager,
        sales_b,
        sales_a_second,
        sales_a,
        admin,
    ]:
        if target is not None:
            target.close()
