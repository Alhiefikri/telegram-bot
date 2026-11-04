"""Read P01's verified getMe identity; never accept a separate bot username setting."""
import http.client
import json
import re


class TelegramBotIdentity:
    def username(self):
        client = http.client.HTTPConnection("127.0.0.1", 8025, timeout=10)
        try:
            client.request("GET", "/telegram/identity", headers={"Accept": "application/json"})
            response = client.getresponse()
            raw = response.read(1025)
            if response.status != 200 or len(raw) > 1024:
                return None
            result = json.loads(raw)
            bot = result.get("result") if isinstance(result, dict) else None
            if (result.get("ok") is not True or not isinstance(bot, dict) or bot.get("is_bot") is not True
                    or type(bot.get("id")) is not int or not 0 < bot["id"] < 2**63):
                return None
            username = bot.get("username")
            return username if isinstance(username, str) and re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{4,31}", username) else None
        except Exception:
            return None
        finally:
            client.close()
