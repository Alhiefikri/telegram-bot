from camoufox.sync_api import Camoufox


def prepare_camoufox_options(proxy_config: dict, metadata: dict) -> dict:
    proxy_dict = {
        "server": proxy_config["server"]
    }
    if proxy_config.get("username"):
        proxy_dict["username"] = proxy_config["username"]
        proxy_dict["password"] = proxy_config.get("password", "")

    return {
        "proxy": proxy_dict,
        "timezone": metadata.get("timezone", "Asia/Kolkata"),
        "headless": False
    }


def launch_camoufox_session(proxy_config: dict, metadata: dict, block_media: bool = False, initial_url: str = "https://ipinfo.io"):
    opts = prepare_camoufox_options(proxy_config, metadata)

    def block_media_handler(route):
        if route.request.resource_type in ["image", "media", "font", "stylesheet"]:
            route.abort()
        else:
            route.continue_()

    with Camoufox(**opts) as browser:
        page = browser.new_page()
        if block_media:
            page.route("**/*", block_media_handler)
        page.goto(initial_url)
        # Keep browser alive until closed by user
        page.wait_for_timeout(3600000)
