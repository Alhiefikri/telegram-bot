import time
from camoufox.sync_api import Camoufox


def check_netflix_trial_offer(page) -> bool:
    content = ""
    text = ""
    try:
        content = page.content().lower()
    except Exception:
        pass
    try:
        text = page.inner_text("body").lower()
    except Exception:
        pass

    indicators = ["₹0", "0 rupees", "free trial", "try 30 days free", "watch free for 30 days"]
    for ind in indicators:
        if ind in content or ind in text:
            return True
    return False


def run_netflix_trial_loop(proxy_config: dict, metadata: dict, max_attempts: int = 20, callback=None) -> bool:
    from src.camouproxy.browser_launcher import prepare_camoufox_options

    opts = prepare_camoufox_options(proxy_config, metadata)

    with Camoufox(**opts) as browser:
        page = browser.new_page()
        attempt = 0
        found = False

        while attempt < max_attempts and not found:
            attempt += 1
            if callback:
                callback({"attempt": attempt, "status": "navigating", "url": "https://www.netflix.com/in/"})

            page.goto("https://www.netflix.com/in/", wait_until="networkidle")
            page.wait_for_timeout(2000)

            if check_netflix_trial_offer(page):
                found = True
                if callback:
                    callback({"attempt": attempt, "status": "success", "message": "₹0 Free Trial Found!"})
                # Pause and keep browser open for user to register
                page.wait_for_timeout(3600000)
                break
            else:
                if callback:
                    callback({"attempt": attempt, "status": "no_offer", "message": "No ₹0 trial. Clearing cookies..."})
                page.goto("https://www.netflix.com/clearcookies")
                page.wait_for_timeout(1500)

        return found
