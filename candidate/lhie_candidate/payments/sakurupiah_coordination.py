"""Sakurupiah request fences and cooldown evidence; Redis is disposable cache."""
from contextlib import contextmanager
import hashlib
import secrets
import time

from ..auth.limits import RedisAdmission
from ..checkout.db import Database


_ACQUIRE = """
local cooldown = tonumber(redis.call('GET',KEYS[1]) or '0')
if not cooldown then return -1 end
if cooldown > tonumber(ARGV[1]) then return cooldown end
if ARGV[4] == '1' and redis.call('EXISTS',KEYS[3]) == 1 then return 1 end
if ARGV[4] == '0' then redis.call('SET',KEYS[3],'1','EX',2) end
if redis.call('SET',KEYS[2],ARGV[2],'NX','EX',ARGV[3]) then return 0 end
return 1
"""
_RELEASE = """
if redis.call('GET',KEYS[1]) == ARGV[1] then return redis.call('DEL',KEYS[1]) end
return 0
"""
_COOLDOWN = """
local previous = tonumber(redis.call('GET',KEYS[1]) or '0')
if not previous then return -1 end
local until_at = math.max(previous,tonumber(ARGV[1]))
redis.call('SET',KEYS[1],until_at,'EX',math.max(1,until_at-tonumber(ARGV[2])))
return until_at
"""
_READ_CHANNELS = "return redis.call('LRANGE',KEYS[1],0,4)"
_WRITE_CHANNELS = """
redis.call('DEL',KEYS[1])
for i=2,#ARGV do redis.call('RPUSH',KEYS[1],ARGV[i]) end
redis.call('EXPIRE',KEYS[1],ARGV[1])
return 1
"""


class CoordinationUnavailable(Exception):
    def __init__(self, reason='coordination_unavailable', retry_at=None):
        self.reason = reason
        self.retry_at = retry_at
        super().__init__(reason)


class SakurupiahCoordination:
    CACHE_SECONDS = 60
    COOLDOWN_SECONDS = 60

    def __init__(self, *, client=None, db=None, using='default', clock=time.time):
        self.client = client if client is not None else RedisAdmission()
        self.db = db if db is not None else Database(using)
        self.key = 'candidate:sakurupiah:sandbox'
        self.clock = clock
        self.blocked_until = 0

    @contextmanager
    def request(self, *, background=False):
        now = int(self.clock())
        if self.blocked_until > now:
            raise CoordinationUnavailable('rate_limit_cooldown', self.blocked_until)
        token = secrets.token_hex(16)
        try:
            lock = self.db.one('SELECT pg_try_advisory_lock(hashtextextended(%s,0)) AS acquired',
                               (self.key + ':request',))
        except Exception:
            raise CoordinationUnavailable() from None
        if not lock or not lock['acquired']:
            raise CoordinationUnavailable('request_busy', now + 1)
        try:
            try:
                row = self.db.one('''SELECT max(CASE WHEN raw_payload->>'retry_at' ~ '^[0-9]{1,12}$'
                    THEN (raw_payload->>'retry_at')::bigint END) AS retry_at FROM public.payment_events
                    WHERE provider='sakurupiah' AND event_type='reconciliation' AND merchant_ref=%s
                      AND provider_status='candidate_rate_limited'
                      AND received_at >= CURRENT_TIMESTAMP - INTERVAL '1 day' ''', (self.key,))
                retry_at = row['retry_at'] if row else None
                if retry_at is not None and (type(retry_at) is not int or retry_at < 0):
                    raise ValueError
            except Exception:
                raise CoordinationUnavailable() from None
            if retry_at is not None and retry_at > now:
                raise CoordinationUnavailable('rate_limit_cooldown', retry_at)
            try:
                result = self.client.eval(_ACQUIRE, 3, self.key + ':cooldown', self.key + ':request',
                                          self.key + ':foreground', now, token, 35, int(background))
            except Exception:
                result = 0
            if type(result) is int and result > 0:
                raise CoordinationUnavailable('rate_limit_cooldown' if result > now else 'request_busy',
                                              result if result > now else now + 1)
            yield
        finally:
            try:
                self.client.eval(_RELEASE, 1, self.key + ':request', token)
            except Exception:
                pass
            try:
                self.db.one('SELECT pg_advisory_unlock(hashtextextended(%s,0)) AS released',
                            (self.key + ':request',))
            except Exception:
                # Session death releases the PG fence; a dispatched create remains uncertain.
                pass

    def throttle(self, retry_after=0):
        now = int(self.clock())
        retry_after = retry_after if type(retry_after) is int and retry_after >= 0 else 0
        self.blocked_until = max(self.blocked_until, now + self.COOLDOWN_SECONDS,
                                 now + min(retry_after, 86400))
        digest = hashlib.sha256(f'{self.key}:cooldown:{self.blocked_until}'.encode()).hexdigest()
        try:
            self.db.execute('''INSERT INTO public.payment_events
                (provider,event_type,merchant_ref,provider_status,raw_payload,idempotency_key,
                 received_at,last_received_at,received_count)
                VALUES ('sakurupiah','reconciliation',%s,'candidate_rate_limited',
                        jsonb_build_object('retry_at',%s::bigint),%s,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1)
                ON CONFLICT (provider,event_type,idempotency_key) DO UPDATE SET
                  last_received_at=CURRENT_TIMESTAMP,received_count=public.payment_events.received_count+1''',
                (self.key, self.blocked_until, digest))
        except Exception:
            # Retain the actual response and local cooldown if evidence storage is unavailable.
            pass
        try:
            value = self.client.eval(_COOLDOWN, 1, self.key + ':cooldown', self.blocked_until, now)
            if type(value) is int and value > 0:
                self.blocked_until = max(self.blocked_until, value)
        except Exception:
            pass

    def cached_channels(self):
        try:
            rows = self.client.eval(_READ_CHANNELS, 1, self.key + ':channels')
            if type(rows) is not list or not 0 < len(rows) <= 4:
                return None
            values = []
            for row in rows:
                code, active, minimum, maximum, checked = row.split(':')
                if active not in ('0', '1'):
                    return None
                values.append((code, active == '1', int(minimum), int(maximum), int(checked)))
            now = int(self.clock())
            if any(not 0 <= now - value[4] < self.CACHE_SECONDS for value in values):
                return None
            return values
        except Exception:
            return None

    def cache_channels(self, channels):
        now = int(self.clock())
        values = [f'{c.code}:{int(c.active)}:{c.minimum}:{c.maximum}:{now}' for c in channels]
        if not values or len(values) > 4 or any(len(value) > 64 for value in values):
            return
        try:
            self.client.eval(_WRITE_CHANNELS, 1, self.key + ':channels', self.CACHE_SECONDS, *values)
        except Exception:
            pass
