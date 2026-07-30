import pytest
from src.camouproxy.proxy_parser import parse_proxy_string

def test_parse_standard_user_pass():
    parsed = parse_proxy_string("http://user123:pass456@gate.cliproxy.com:8080")
    assert parsed["server"] == "http://gate.cliproxy.com:8080"
    assert parsed["username"] == "user123"
    assert parsed["password"] == "pass456"
    assert parsed["raw"] == "http://user123:pass456@gate.cliproxy.com:8080"

def test_parse_ip_port_user_pass():
    parsed = parse_proxy_string("103.1.2.3:8080:user123:pass456")
    assert parsed["server"] == "http://103.1.2.3:8080"
    assert parsed["username"] == "user123"
    assert parsed["password"] == "pass456"
    assert parsed["raw"] == "103.1.2.3:8080:user123:pass456"

def test_parse_ip_port_only():
    parsed = parse_proxy_string("103.1.2.3:8080")
    assert parsed["server"] == "http://103.1.2.3:8080"
    assert parsed["username"] == ""
    assert parsed["password"] == ""
    assert parsed["raw"] == "103.1.2.3:8080"

def test_parse_socks5_url():
    parsed = parse_proxy_string("socks5://user:pass@1.2.3.4:1080")
    assert parsed["server"] == "socks5://1.2.3.4:1080"
    assert parsed["username"] == "user"
    assert parsed["password"] == "pass"

def test_parse_invalid_format():
    with pytest.raises(ValueError):
        parse_proxy_string("invalid_proxy_format_string")

def test_parse_empty_string():
    with pytest.raises(ValueError):
        parse_proxy_string("   ")
