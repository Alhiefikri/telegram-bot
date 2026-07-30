import re

def parse_proxy_string(proxy_str: str) -> dict:
    proxy_str = proxy_str.strip()
    if not proxy_str:
        raise ValueError("Proxy string cannot be empty")
    
    # Handle user:pass@host:port or scheme://user:pass@host:port or scheme://host:port
    match_url = re.match(r'^(?:(https?|socks5|socks4)://)?(?:([^:@]+):([^@]+)@)?([^:]+):(\d+)$', proxy_str)
    if match_url and (match_url.group(1) or match_url.group(2)):
        scheme = match_url.group(1) or "http"
        user = match_url.group(2) or ""
        password = match_url.group(3) or ""
        host = match_url.group(4)
        port = match_url.group(5)
        return {
            "server": f"{scheme}://{host}:{port}",
            "username": user,
            "password": password,
            "raw": proxy_str
        }
    
    # Handle host:port:user:pass or host:port
    parts = proxy_str.split(':')
    if len(parts) == 4:
        host, port, user, password = parts
        if not port.isdigit():
            raise ValueError(f"Invalid port in proxy string: {proxy_str}")
        return {
            "server": f"http://{host}:{port}",
            "username": user,
            "password": password,
            "raw": proxy_str
        }
    elif len(parts) == 2:
        host, port = parts
        if not port.isdigit():
            raise ValueError(f"Invalid port in proxy string: {proxy_str}")
        return {
            "server": f"http://{host}:{port}",
            "username": "",
            "password": "",
            "raw": proxy_str
        }
    
    raise ValueError(f"Unrecognized proxy format: {proxy_str}")
