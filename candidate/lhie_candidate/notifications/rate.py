"""Shared rolling-window broadcast/stock/price budget; Redis failure is closed."""
import secrets

from ..auth.limits import RedisAdmission


_ACQUIRE = """
local t=redis.call('TIME')
local now=tonumber(t[1])*1000+math.floor(tonumber(t[2])/1000)
redis.call('ZREMRANGEBYSCORE',KEYS[1],'-inf',now-1000)
if redis.call('ZCARD',KEYS[1]) >= 25 then return 0 end
redis.call('ZADD',KEYS[1],now,ARGV[1])
redis.call('PEXPIRE',KEYS[1],2000)
return 1
"""


class BroadcastRate:
    def __init__(self, client=None):
        self.client = client if client is not None else RedisAdmission()

    def acquire(self):
        try:
            return self.client.eval(_ACQUIRE, 1, 'candidate:notification:send-rate:v1', secrets.token_hex(16)) == 1
        except Exception:
            return False
