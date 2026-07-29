#!/usr/bin/env python3
"""以真實 Orca + 無障礙瀏覽器抽查 v1.6 error 與欄位名稱朗讀。"""

from __future__ import annotations

import os
import time
from typing import Any

from v15_webdriver_support import Browser, BrowserConfig, wait_until


FRONTEND = os.environ.get("SMOKE_FRONTEND_URL", "http://127.0.0.1:5196")
API = os.environ.get("SMOKE_API_URL", "http://127.0.0.1:8016")


class AccessibleBrowser(Browser):
    def _capabilities(self) -> dict[str, Any]:
        if self.config.browser == "chrome":
            return {
                "browserName": "chrome",
                "goog:chromeOptions": {
                    "binary": self.config.browser_binary,
                    "args": [
                        "--no-sandbox",
                        "--disable-dev-shm-usage",
                        "--force-renderer-accessibility",
                        "--window-size=1440,900",
                    ],
                },
            }

        return {
            "browserName": "firefox",
            "acceptInsecureCerts": True,
            "moz:firefoxOptions": {
                "args": [],
                "prefs": {
                    "accessibility.force_disabled": 0,
                    "accessibility.browsewithcaret": True,
                },
            },
        }


browser_config = (
    BrowserConfig.chrome()
    if os.environ.get("SMOKE_BROWSER") == "chrome"
    else BrowserConfig.firefox()
)
target = AccessibleBrowser(
    browser_config,
    FRONTEND,
    API,
    1440,
    900,
)

try:
    target.get("/login")
    target.find_css("#login")
    target.replace("#login", "not-found.v16@example.test")
    target.replace("#password", "WrongV16!")
    target.click_text("登入")
    target.wait_text("帳號或密碼錯誤")
    login_alert = target.execute(
        """
        const alert = document.querySelector('[role="alert"]');
        return {
          focused: document.activeElement === alert,
          text: alert?.textContent || '',
          live: alert?.getAttribute('aria-live') || '',
        }
        """
    )
    print(f"SR_MARKER login_alert {login_alert}", flush=True)
    time.sleep(2)

    target.replace("#login", "admin@example.com")
    target.replace("#password", "password")
    target.click_text("登入")
    target.wait_url("/dashboard")
    target.wait_text("營運總覽")

    target.get("/money-entries/create")
    target.wait_text("新增收支")
    target.click_text("建立收支")
    target.wait_text("請選擇分類")
    wait_until(
        lambda: target.execute(
            "return document.activeElement?.matches('[aria-invalid=\"true\"]')"
        ),
        5,
        "screen reader first invalid focus",
    )
    field_error = target.execute(
        """
        const field = document.querySelector('[aria-invalid="true"]');
        const describedBy = field?.getAttribute('aria-describedby') || '';
        return {
          focused: document.activeElement === field,
          name: field?.labels?.[0]?.textContent?.trim() || '',
          errorText: document.getElementById(describedBy)?.textContent || '',
        }
        """
    )
    print(f"SR_MARKER field_error {field_error}", flush=True)
    time.sleep(2)

    target.get("/cash-accounts")
    target.wait_text("資金帳戶")
    target.click_text("新增帳戶")
    account_name = target.find_xpath(
        "//label[normalize-space()='帳戶名稱']/following-sibling::input"
    )
    target.execute("arguments[0].focus()", [{
        "element-6066-11e4-a52e-4f735466cecf": account_name,
    }])
    account_label = target.execute(
        """
        const field = document.activeElement;
        return {
          tag: field?.tagName || '',
          labels: Array.from(field?.labels || [])
            .map((label) => label.textContent?.trim() || ''),
          ariaLabel: field?.getAttribute('aria-label') || '',
        }
        """
    )
    print(f"SR_MARKER sibling_label {account_label}", flush=True)
    time.sleep(3)
finally:
    target.close()
