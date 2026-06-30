#!/usr/bin/env python3

import http.client
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import time
from pathlib import Path


class TestFailure(RuntimeError):
    pass


ROOT = Path(__file__).resolve().parents[2]


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


def wait_for_server(port: int, process: subprocess.Popen) -> None:
    deadline = time.time() + 5
    while time.time() < deadline:
        if process.poll() is not None:
            raise TestFailure("PHP test server stopped unexpectedly.")

        try:
            connection = http.client.HTTPConnection("127.0.0.1", port, timeout=0.5)
            connection.request("GET", "/")
            response = connection.getresponse()
            response.read()
            connection.close()
            if response.status > 0:
                return
        except OSError:
            time.sleep(0.05)

    raise TestFailure("PHP test server did not start.")


def start_server(data_dir: str, port: int) -> subprocess.Popen:
    env = os.environ.copy()
    env["OUTLINE_DATA_DIR"] = data_dir
    process = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "router.php"],
        cwd=ROOT,
        env=env,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    wait_for_server(port, process)
    return process


def login(page, base_url: str) -> None:
    page.goto(base_url, wait_until="domcontentloaded")
    login_button = page.get_by_role("button", name="ログイン")
    if login_button.count() > 0:
        login_button.click()
    page.wait_for_selector(".node-text")


def test_japanese_enter_persists(page, base_url: str) -> None:
    login(page, base_url)
    first = page.locator(".node-text").first
    first.click()
    first.evaluate(
        """(el) => {
            el.textContent = "日本語テスト";
            el.dispatchEvent(new InputEvent("input", {
                bubbles: true,
                inputType: "insertText",
                data: "日本語テスト"
            }));
        }"""
    )
    first.press("Enter")
    page.wait_for_timeout(800)
    page.reload(wait_until="domcontentloaded")
    page.wait_for_selector(".node-text")

    texts = page.locator(".node-text").all_inner_texts()
    if "日本語テスト" not in texts:
        raise TestFailure("Japanese text was not saved before Enter created a sibling node.")


def test_composing_enter_does_not_create_node(page, base_url: str) -> None:
    login(page, base_url)
    before = page.locator(".node-text").count()
    first = page.locator(".node-text").first
    first.click()
    first.evaluate(
        """(el) => {
            const event = new KeyboardEvent("keydown", {
                key: "Enter",
                bubbles: true,
                cancelable: true,
                isComposing: true
            });
            el.dispatchEvent(event);
        }"""
    )
    page.wait_for_timeout(500)
    after = page.locator(".node-text").count()
    if before != after:
        raise TestFailure("Composing Enter should not create a new node.")


def run_app_tests() -> None:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:
        raise TestFailure("Playwright is required for app tests.") from exc

    data_dir = tempfile.mkdtemp(prefix="outline-app-test-")
    port = free_port()
    process = start_server(data_dir, port)
    base_url = f"http://127.0.0.1:{port}/"
    channel = os.environ.get("APP_TEST_BROWSER_CHANNEL", "chrome")

    try:
        with sync_playwright() as playwright:
            try:
                browser = playwright.chromium.launch(channel=channel, headless=True)
            except Exception as exc:
                raise TestFailure(
                    f"Cannot launch Chromium channel '{channel}'. "
                    "Set APP_TEST_BROWSER_CHANNEL or install a Playwright browser."
                ) from exc

            try:
                page = browser.new_page()
                test_japanese_enter_persists(page, base_url)
                page = browser.new_page()
                test_composing_enter_does_not_create_node(page, base_url)
            finally:
                browser.close()
    finally:
        process.terminate()
        process.wait(timeout=5)
        shutil.rmtree(data_dir, ignore_errors=True)


if __name__ == "__main__":
    try:
        run_app_tests()
        print("app tests passed")
    except Exception as exc:
        print(f"app tests failed: {exc}", file=sys.stderr)
        sys.exit(1)
