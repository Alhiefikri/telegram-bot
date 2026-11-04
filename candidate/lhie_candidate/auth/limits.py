"""Atomic shared admission; durable challenge attempts remain in PostgreSQL."""
import secrets


from ..compat.identity import application_hmac
from ..workers.wakeup import _command, _connect
from .types import AuthError

_RESERVE = """
local values = {}
for i,key in ipairs(KEYS) do
  local count = tonumber(redis.call('HGET',key,'count') or '0')
  local generation = redis.call('HGET',key,'generation')
  if count == nil or (generation and redis.call('PTTL',key) < 0) then return redis.error_reply('invalid limiter') end
  if count >= tonumber(ARGV[i*2-1]) then return {} end
  values[i] = generation or ARGV[#KEYS*2+1]
end
for i,key in ipairs(KEYS) do
  redis.call('HINCRBY',key,'count',1)
  if not redis.call('HGET',key,'generation') then
    redis.call('HSET',key,'generation',values[i])
    redis.call('EXPIRE',key,ARGV[i*2])
  end
end
return values
"""
_RELEASE = """
for i,key in ipairs(KEYS) do
  if redis.call('HGET',key,'generation') == ARGV[i] then
    local count = tonumber(redis.call('HGET',key,'count') or '0')
    if i == 1 and ARGV[#KEYS+1] == '1' then
      redis.call('DEL',key)
    elseif count > 0 then
      redis.call('HINCRBY',key,'count',-1)
    end
  end
end
return 1
"""


class RedisAdmission:
    """Small bounded RESP2 client on the existing admitted Redis endpoint."""
    def command(self, *parts):
        encoded = tuple(str(part).encode("utf-8") for part in parts)
        with _connect(1) as connection:
            connection.settimeout(1)
            connection.sendall(_command(encoded))
            with connection.makefile("rb") as stream:
                def parse(depth=0):
                    if depth > 1:
                        raise ValueError("Invalid limiter reply")
                    line = stream.readline(128)
                    if not line.endswith(b"\r\n"):
                        raise ValueError("Invalid limiter reply")
                    kind, value = line[:1], line[1:-2]
                    if kind == b":":
                        return int(value)
                    if kind == b"*":
                        length = int(value)
                        if not 0 <= length <= 8:
                            raise ValueError("Invalid limiter reply")
                        return [parse(depth + 1) for _ in range(length)]
                    if kind == b"$":
                        length = int(value)
                        if not 0 <= length <= 64:
                            raise ValueError("Invalid limiter reply")
                        body = stream.read(length)
                        if len(body) != length or stream.read(2) != b"\r\n":
                            raise ValueError("Invalid limiter reply")
                        return body.decode("ascii")
                    raise ValueError("Invalid limiter reply")
                return parse()

    def eval(self, script, number, *args):
        return self.command("EVAL", script, number, *args)


class RateLimits:
    def __init__(self, app_key, client=None):
        self.app_key = app_key
        self.client = client if client is not None else RedisAdmission()

    def reserve(self, limits):
        if not self.app_key or not 1 <= len(limits) <= 8:
            raise AuthError("unavailable", 503)
        keys, args = [], []
        for purpose, identity, maximum, seconds in limits:
            keys.append("candidate:auth:" + purpose + ":" + application_hmac(identity, self.app_key))
            args.extend((maximum, seconds))
        try:
            generations = self.client.eval(_RESERVE, len(keys), *keys, *args, secrets.token_hex(16))
        except Exception:
            raise AuthError("unavailable", 503) from None
        if not generations:
            raise AuthError("rate_limited", 429)
        return tuple(zip(keys, generations))

    def release(self, reservation, *, clear_identity=False):
        try:
            self.client.eval(_RELEASE, len(reservation),
                             *(key for key, _ in reservation), *(generation for _, generation in reservation),
                             "1" if clear_identity else "0")
        except Exception:
            # A failed refund retains the reservation; it never opens admission.
            pass

    def mail(self, email, ip):
        return self.reserve([("mail-email", email, 3, 900), ("mail-ip", ip, 10, 3600),
                             ("mail-cooldown", email, 1, 60)])
