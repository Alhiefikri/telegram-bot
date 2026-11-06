"""Shared Redis admission; financial correctness never depends on a permit."""
import hashlib
import hmac
import secrets

from lhie_candidate.runtime import application_key
from django.db import connection

from lhie_candidate.auth.limits import RedisAdmission


class ApiFailure(Exception):
    def __init__(self, status, code, message, headers=None, details=None):
        self.status = status
        self.code = code
        self.message = message
        self.headers = {'Retry-After': '5', **(headers or {})} if status == 503 else (headers or {})
        self.details = details or {}
        super().__init__(message)


def unavailable():
    return ApiFailure(503, 'temporarily_unavailable', 'API admission is temporarily unavailable.')


def check_availability(for_mutation=False):
    if for_mutation and not connection.in_atomic_block:
        raise RuntimeError('Availability mutation fence requires the owning transaction')
    try:
        with connection.cursor() as cursor:
            sql = "SELECT enabled FROM public.customer_api_settings WHERE singleton_key='customer_api'"
            cursor.execute(sql + (' FOR SHARE' if for_mutation else ''))
            row = cursor.fetchone()
    except Exception as exc:
        raise unavailable() from exc
    if row is None:
        raise unavailable()
    if not row[0]:
        raise ApiFailure(503, 'api_maintenance', 'The Customer API is temporarily disabled for maintenance.', {'Retry-After': '60'})


_RESERVE = """
local n = tonumber(ARGV[1])
local now = tonumber(redis.call('TIME')[1])
local counts, resets = {}, {}
local selected, lowest = 1, 2
for i=1,n do
  local maximum, seconds = tonumber(ARGV[2*i+2]), tonumber(ARGV[2*i+3])
  local count = tonumber(redis.call('GET', KEYS[i]) or '0')
  local ttl = redis.call('PTTL', KEYS[i])
  if not count or (ttl == -1) then return redis.error_reply('invalid limiter') end
  if ttl == -2 then count = 0; ttl = seconds*1000 end
  ttl = math.max(1, math.ceil(ttl/1000))
  if count >= maximum then return {0,maximum,0,now+ttl,ttl} end
  counts[i], resets[i] = count, now+ttl
  local ratio = (maximum-count-1)/maximum
  if ratio < lowest then lowest = ratio; selected = i end
end
for i=1,n do
  redis.call('INCR',KEYS[i])
  if counts[i] == 0 then redis.call('EXPIRE',KEYS[i],tonumber(ARGV[2*i+3])) end
end
if ARGV[2] ~= '' then
  local key = KEYS[n+1]
  redis.call('ZREMRANGEBYSCORE',key,'-inf',now)
  if redis.call('ZCARD',key) >= 2 then
    local earliest = redis.call('ZRANGE',key,0,0,'WITHSCORES')
    local retry = math.max(1,tonumber(earliest[2])-now)
    return {0,2,0,now+retry,retry}
  end
  redis.call('ZADD',key,now+tonumber(ARGV[3]),ARGV[2])
  redis.call('EXPIRE',key,tonumber(ARGV[3]))
end
local maximum = tonumber(ARGV[2*selected+2])
return {1,maximum,maximum-counts[selected]-1,resets[selected],0}
"""


class Admission:
    PERMIT_SECONDS = 90

    def __init__(self, app_key=None, client=None, prefix=''):
        self.app_key = app_key if app_key is not None else application_key()
        self.prefix = prefix
        self.client = client if client is not None else RedisAdmission()

    def _key(self, purpose, identity):
        if not self.app_key:
            raise unavailable()
        digest = hmac.new(self.app_key.encode(), str(identity).encode(), hashlib.sha256).hexdigest()
        return self.prefix + 'customer-api:' + purpose + ':' + digest

    def _eval(self, script, keys, args):
        try:
            return self.client.eval(script, len(keys), *keys, *args)
        except Exception as exc:
            raise unavailable() from exc

    def _reserve(self, limits, permit=None, token=''):
        keys = [self._key(purpose, identity) for purpose, identity, _, _ in limits]
        args = [len(limits), token, self.PERMIT_SECONDS]
        for _, _, maximum, seconds in limits:
            args.extend((maximum, seconds))
        if permit is not None:
            keys.append(permit)
        result = self._eval(_RESERVE, keys, args)
        if not isinstance(result, (tuple, list)) or len(result) != 5 or any(type(x) is not int for x in result):
            raise unavailable()
        headers = {'X-RateLimit-Limit': str(result[1]), 'X-RateLimit-Remaining': str(result[2]), 'X-RateLimit-Reset': str(result[3])}
        if result[0] != 1:
            headers['Retry-After'] = str(result[4])
            raise ApiFailure(429, 'rate_limited', 'API admission limit reached.', headers)
        return headers

    def ip(self, ip):
        return self._reserve([('ip', ip, 600, 60)])

    def invalid(self, ip):
        return self._reserve([('invalid', ip, 30, 60)])

    def authenticated(self, principal, mutation=False, delivery=False):
        identity = str(principal.customer_id)
        limits = [('orders', identity, 10, 60), ('global-orders', 'all', 20, 1)] if mutation else [('reads', identity, 120, 60), ('global-reads', 'all', 300, 1)]
        if delivery:
            limits.append(('delivery', identity, 10, 60))
        token = secrets.token_hex(16) if mutation else ''
        headers = self._reserve(limits, self._key('inflight', identity) if mutation else None, token)
        return {'headers': headers, 'token': token}

    def key_operation(self, customer_id, disable=False, confirmation=False):
        purpose = 'key-disable' if disable else ('key-confirmation' if confirmation else 'key-create')
        return self._reserve([(purpose, str(customer_id), 30 if disable or confirmation else 3, 600)])

    def release(self, principal, token):
        if not token:
            return
        try:
            self._eval("return redis.call('ZREM',KEYS[1],ARGV[1])", [self._key('inflight', str(principal.customer_id))], [token])
        except Exception:
            pass
