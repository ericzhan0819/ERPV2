#!/usr/bin/env python3
"""驗證暫改 app config 後 Login／Sidebar／Browser title 的同步。"""

from __future__ import annotations

import os

from v15_webdriver_support import Browser, BrowserConfig, Checks


FRONTEND = os.environ.get("SMOKE_FRONTEND_URL", "http://127.0.0.1:5195")
API = os.environ.get("SMOKE_API_URL", "http://127.0.0.1:8015")
EXPECTED_SYSTEM = os.environ.get(
    "SMOKE_EXPECTED_SYSTEM_NAME",
    "第十五部分測試營運系統",
)
EXPECTED_SHORT = os.environ.get(
    "SMOKE_EXPECTED_SYSTEM_SHORT_NAME",
    "第十五部分測試系統",
)
EXPECTED_TITLE = os.environ.get(
    "SMOKE_EXPECTED_BROWSER_TITLE",
    "第十五部分測試瀏覽器標題",
)
EXPECTED_SUBTITLE = os.environ.get(
    "SMOKE_EXPECTED_LOGIN_SUBTITLE",
    "第十五部分測試登入副標",
)
checks = Checks()
target = Browser(BrowserConfig.firefox(), FRONTEND, API)

try:
    target.get("/login")
    target.wait_text(EXPECTED_SYSTEM)
    checks.check(
        "修改 config 後 Login 主標、副標與 Browser title 同步",
        EXPECTED_SUBTITLE in target.body()
        and target.title() == EXPECTED_TITLE,
    )

    target.replace("#login", "admin@example.com")
    target.replace("#password", "password")
    target.click_text("登入")
    target.wait_url("/dashboard")
    checks.check(
        "修改 config 後 Sidebar 與登入後 Browser title 同步",
        EXPECTED_SHORT in target.body()
        and target.title() == EXPECTED_TITLE,
    )
    print("RESULT: 2 config checks passed", flush=True)
finally:
    target.close()
