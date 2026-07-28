#!/usr/bin/env python3
"""重跑 v1.5 第 15 部分的 15 項 Desktop Chrome 工程預驗證。"""

from __future__ import annotations

import os

from v15_webdriver_support import Browser, BrowserConfig, Checks


FRONTEND = os.environ.get("SMOKE_FRONTEND_URL", "http://127.0.0.1:5196")
API = os.environ.get("SMOKE_API_URL", "http://127.0.0.1:8016")
CONFIG = BrowserConfig.chrome()
checks = Checks()


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


target = Browser(CONFIG, FRONTEND, API)

try:
    target.get("/login")
    target.wait_text("中古車行內部營運系統")
    checks.check(
        "Chrome Login 顯示 config 主標與副標",
        "請登入以繼續" in target.body(),
    )
    checks.check(
        "Chrome Browser title 正確",
        target.title() == "中古車行內部營運系統",
    )

    login(target, "admin@example.com", "password")
    target.wait_text("營運總覽")
    checks.check("Chrome Admin Email 登入與 Dashboard")

    for path, marker in {
        "/vehicles": "車輛",
        "/customers": "客戶",
        "/money-entries": "收支",
        "/cash-accounts": "資金帳戶",
        "/users": "員工/帳號管理",
    }.items():
        target.get(path)
        target.wait_text(marker)
    checks.check("Chrome Admin 主要路由可操作")

    target.get("/users")
    target.click_text("新增員工")
    form = target.find_xpath(
        "//form[.//button[normalize-space()='建立員工']]"
    )
    for selector, value in {
        "input[type='text']": "Chrome 測試業務",
        "input[type='email']": "v15.chrome.sales@example.test",
        "input[type='password']": "ChromeDefault123!",
    }.items():
        target.type(target.find_css(selector, parent=form), value)
    target.click(
        target.find_xpath(
            ".//button[normalize-space()='建立員工']",
            parent=form,
        )
    )
    target.wait_text("員工已建立")
    target.wait_text("v15.chrome.sales@example.test")
    checks.check("Chrome Admin 建立 sales 帳號與提示")
    checks.check(
        "Chrome User list 顯示尚未設定與需修改密碼",
        "尚未設定" in target.body() and "需修改密碼" in target.body(),
    )

    target.click_text("登出")
    target.wait_url("/login")
    login(
        target,
        "v15.chrome.sales@example.test",
        "ChromeDefault123!",
        "/change-password",
    )
    checks.check("Chrome 首次登入導向強制改密碼")

    theme_before = target.execute(
        "return document.documentElement.classList.contains('dark')"
    )
    target.click(target.find_css("button[aria-label*='模式']"))
    theme_after = target.execute(
        "return document.documentElement.classList.contains('dark')"
    )
    checks.check(
        "Chrome 強制頁 Theme Toggle 可用",
        theme_before != theme_after,
    )

    target.get("/dashboard")
    target.wait_url("/change-password")
    checks.check("Chrome 強制狀態無法直接進 Dashboard")

    target.replace("#current-password", "ChromeDefault123!")
    target.replace("#new-password", "ChromeNew123!")
    target.replace("#password-confirmation", "ChromeNew123!")
    target.click_text("修改密碼並繼續")
    target.wait_url("/dashboard")
    target.wait_text("營運總覽")
    checks.check("Chrome 修改預設密碼後進 Dashboard")

    target.click(
        target.find_css("a[aria-label^='我的帳號：']")
    )
    target.wait_url("/account")
    target.replace("#account-name", "Chrome 業務已更新")
    target.replace("#account-username", "chrome.sales")
    target.click_text("儲存個人資料")
    target.wait_text("個人資料已更新")
    checks.check(
        "Chrome 我的帳號更新名稱與 username",
        target.execute(
            """
            return document.querySelector(
              "a[aria-label^='我的帳號：']"
            ).getAttribute('aria-label')
            """
        )
        == "我的帳號：Chrome 業務已更新",
    )

    for path, marker in {
        "/vehicles": "車輛",
        "/customers": "客戶",
        "/money-entries": "收支",
        "/account": "我的帳號",
    }.items():
        target.get(path)
        target.wait_text(marker)
    checks.check("Chrome Sales 主要路由可操作")

    target.click_text("登出")
    target.wait_url("/login")
    login(target, "CHROME.SALES", "ChromeNew123!")
    target.wait_text("營運總覽")
    checks.check("Chrome username 大小寫登入")
    target.click_text("登出")
    target.wait_url("/login")
    checks.check("Chrome 登出可用")
    checks.check(
        "Desktop Chrome 基本操作完成",
        len(checks.names) >= 14,
    )
    print(
        f"RESULT: {len(checks.names)} Chrome checks passed",
        flush=True,
    )
finally:
    target.close()
