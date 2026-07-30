import pytest
from src.camouproxy.browser_launcher import prepare_camoufox_options, launch_camoufox_session


def test_prepare_camoufox_options_with_auth():
    proxy_config = {"server": "http://1.2.3.4:8080", "username": "u", "password": "p"}
    metadata = {"timezone": "Asia/Kolkata"}
    opts = prepare_camoufox_options(proxy_config, metadata)
    assert opts["proxy"]["server"] == "http://1.2.3.4:8080"
    assert opts["proxy"]["username"] == "u"
    assert opts["proxy"]["password"] == "p"
    assert opts["timezone"] == "Asia/Kolkata"
    assert opts["headless"] is False


def test_prepare_camoufox_options_without_auth():
    proxy_config = {"server": "http://5.6.7.8:3128", "username": "", "password": ""}
    metadata = {}
    opts = prepare_camoufox_options(proxy_config, metadata)
    assert opts["proxy"]["server"] == "http://5.6.7.8:3128"
    assert "username" not in opts["proxy"]
    assert opts["timezone"] == "Asia/Kolkata"
    assert opts["headless"] is False
