import httpx
import time

async def detect_ip_metadata(proxy_config: dict) -> dict:
    proxy_url = proxy_config["server"]
    if proxy_config.get("username") and proxy_config.get("password"):
        scheme, rest = proxy_url.split("://", 1)
        proxy_url = f"{scheme}://{proxy_config['username']}:{proxy_config['password']}@{rest}"
    
    start_time = time.time()
    async with httpx.AsyncClient(proxy=proxy_url, timeout=10.0) as client:
        res = await client.get("http://ip-api.com/json")
        latency = int((time.time() - start_time) * 1000)
        data = res.json()
        if data.get("status") != "success":
            raise RuntimeError("Failed to fetch IP geolocation metadata")
        return {
            "ip": data.get("query"),
            "country": data.get("country"),
            "country_code": data.get("countryCode"),
            "city": data.get("city"),
            "isp": data.get("isp"),
            "timezone": data.get("timezone", "UTC"),
            "latency_ms": latency
        }
