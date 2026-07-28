"""v1.5 第 15 部分 browser smoke 共用的零額外 dependency WebDriver helper。"""

from __future__ import annotations

import json
import os
import subprocess
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any, Callable


ELEMENT_KEY = "element-6066-11e4-a52e-4f735466cecf"


class WebDriverError(RuntimeError):
    pass


def wait_until(
    predicate: Callable[[], Any],
    timeout: float,
    description: str,
) -> Any:
    deadline = time.time() + timeout
    last_error: Exception | None = None

    while time.time() < deadline:
        try:
            value = predicate()
            if value:
                return value
        except Exception as error:  # noqa: BLE001 - WebDriver polling must retry.
            last_error = error
        time.sleep(0.15)

    if last_error:
        raise AssertionError(
            f"等待 {description} 逾時：{last_error}"
        ) from last_error
    raise AssertionError(f"等待 {description} 逾時")


@dataclass(frozen=True)
class BrowserConfig:
    browser: str
    driver_binary: str
    browser_binary: str | None = None

    @classmethod
    def firefox(cls) -> BrowserConfig:
        return cls(
            browser="firefox",
            driver_binary=os.environ.get("GECKODRIVER", "geckodriver"),
        )

    @classmethod
    def chrome(cls) -> BrowserConfig:
        browser_binary = os.environ.get("CHROME_BINARY")
        if not browser_binary:
            raise RuntimeError("Chrome smoke 必須設定 CHROME_BINARY。")

        return cls(
            browser="chrome",
            driver_binary=os.environ.get("CHROMEDRIVER", "chromedriver"),
            browser_binary=browser_binary,
        )


class Browser:
    next_port = int(os.environ.get("WEBDRIVER_START_PORT", "4460"))

    def __init__(
        self,
        config: BrowserConfig,
        frontend: str,
        api: str,
        width: int = 1440,
        height: int = 1000,
    ) -> None:
        self.config = config
        self.frontend = frontend.rstrip("/")
        self.api = api.rstrip("/")
        self.session: str | None = None

        port = Browser.next_port
        Browser.next_port += 1
        self.driver_url = f"http://127.0.0.1:{port}"
        self.process = subprocess.Popen(
            self._driver_command(port),
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )

        try:
            wait_until(
                lambda: self._request("GET", "/status"),
                10,
                f"{config.browser} driver {port}",
            )
            self.session = self._request(
                "POST",
                "/session",
                {"capabilities": {"alwaysMatch": self._capabilities()}},
            )["sessionId"]
            self._request(
                "POST",
                f"/session/{self.session}/window/rect",
                {"width": width, "height": height},
            )
        except Exception:
            self._stop_process()
            raise

    def _driver_command(self, port: int) -> list[str]:
        if self.config.browser == "firefox":
            return [
                self.config.driver_binary,
                "--host",
                "127.0.0.1",
                "--port",
                str(port),
            ]

        return [self.config.driver_binary, f"--port={port}"]

    def _capabilities(self) -> dict[str, Any]:
        if self.config.browser == "firefox":
            return {
                "browserName": "firefox",
                "acceptInsecureCerts": True,
                "moz:firefoxOptions": {"args": ["-headless"]},
            }

        return {
            "browserName": "chrome",
            "goog:chromeOptions": {
                "binary": self.config.browser_binary,
                "args": [
                    "--headless=new",
                    "--no-sandbox",
                    "--disable-dev-shm-usage",
                ],
            },
        }

    def _request(
        self,
        method: str,
        path: str,
        payload: dict[str, Any] | None = None,
    ) -> Any:
        data = None if payload is None else json.dumps(payload).encode()
        request = urllib.request.Request(
            self.driver_url + path,
            data=data,
            method=method,
            headers={"Content-Type": "application/json"},
        )

        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                body = response.read()
        except urllib.error.HTTPError as error:
            body = error.read().decode()
            raise WebDriverError(
                f"{method} {path}: HTTP {error.code} {body}"
            ) from error

        decoded = json.loads(body.decode()) if body else {"value": None}
        value = decoded.get("value")
        if isinstance(value, dict) and value.get("error"):
            raise WebDriverError(f"{method} {path}: {value}")
        return value

    def close(self) -> None:
        if self.session:
            try:
                self._request("DELETE", f"/session/{self.session}")
            finally:
                self.session = None
        self._stop_process()

    def _stop_process(self) -> None:
        if self.process.poll() is not None:
            return

        self.process.terminate()
        try:
            self.process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            self.process.kill()
            self.process.wait(timeout=5)

    def get(self, path: str) -> None:
        url = path if path.startswith("http") else self.frontend + path
        self._request("POST", f"/session/{self.session}/url", {"url": url})

    def url(self) -> str:
        return self._request("GET", f"/session/{self.session}/url")

    def title(self) -> str:
        return self._request("GET", f"/session/{self.session}/title")

    def body(self) -> str:
        return self.execute(
            "return document.body ? document.body.innerText : ''"
        )

    def execute(
        self,
        script: str,
        args: list[Any] | None = None,
    ) -> Any:
        return self._request(
            "POST",
            f"/session/{self.session}/execute/sync",
            {"script": script, "args": args or []},
        )

    def execute_async(
        self,
        script: str,
        args: list[Any] | None = None,
    ) -> Any:
        return self._request(
            "POST",
            f"/session/{self.session}/execute/async",
            {"script": script, "args": args or []},
        )

    def find(
        self,
        using: str,
        value: str,
        timeout: float = 10,
        parent: str | None = None,
    ) -> str:
        def locate() -> str:
            path = f"/session/{self.session}/element"
            if parent:
                path = (
                    f"/session/{self.session}/element/{parent}/element"
                )
            return self._request(
                "POST",
                path,
                {"using": using, "value": value},
            )[ELEMENT_KEY]

        return wait_until(locate, timeout, f"element {using}={value}")

    def find_css(
        self,
        value: str,
        timeout: float = 10,
        parent: str | None = None,
    ) -> str:
        return self.find("css selector", value, timeout, parent)

    def find_xpath(
        self,
        value: str,
        timeout: float = 10,
        parent: str | None = None,
    ) -> str:
        return self.find("xpath", value, timeout, parent)

    def click(self, element: str) -> None:
        self._request(
            "POST",
            f"/session/{self.session}/element/{element}/click",
            {},
        )

    def clear(self, element: str) -> None:
        self._request(
            "POST",
            f"/session/{self.session}/element/{element}/clear",
            {},
        )

    def type(self, element: str, value: str) -> None:
        self._request(
            "POST",
            f"/session/{self.session}/element/{element}/value",
            {"text": value, "value": list(value)},
        )

    def replace(self, selector: str, value: str) -> None:
        element = self.find_css(selector)
        self.clear(element)
        self.type(element, value)

    def text(self, element: str) -> str:
        return self._request(
            "GET",
            f"/session/{self.session}/element/{element}/text",
        )

    def wait_url(self, suffix: str, timeout: float = 12) -> None:
        wait_until(
            lambda: self.url().endswith(suffix),
            timeout,
            f"URL ending {suffix}",
        )

    def wait_text(self, text: str, timeout: float = 12) -> None:
        wait_until(
            lambda: text in self.body(),
            timeout,
            f"text {text}",
        )

    def click_text(self, text: str) -> None:
        xpath = (
            "//button[normalize-space()="
            f"{json.dumps(text, ensure_ascii=False)}]"
        )
        self.click(self.find_xpath(xpath))

    def fetch(self, path: str) -> dict[str, Any]:
        return self.execute_async(
            """
            const done = arguments[arguments.length - 1];
            fetch(arguments[0], {credentials: 'include'})
              .then(async (response) => {
                let body = null;
                try { body = await response.json(); } catch (_) {}
                done({status: response.status, body});
              })
              .catch((error) => done({error: String(error)}));
            """,
            [self.api + path],
        )


class Checks:
    def __init__(self) -> None:
        self.names: list[str] = []

    def check(self, name: str, condition: bool = True) -> None:
        if not condition:
            raise AssertionError(name)
        self.names.append(name)
        print(f"PASS {len(self.names):02d}: {name}", flush=True)
