"""Lossy minute-bucket counters; drained counts are never financial authority."""
from datetime import datetime, timezone
import time

from django.db import connection, transaction


_RECORD = """
local field = ARGV[1] .. ':' .. ARGV[2]
if redis.call('HEXISTS',KEYS[1],field) == 0 and redis.call('HLEN',KEYS[1]) >= 10000 then return 0 end
redis.call('HINCRBY',KEYS[1],field,1)
redis.call('EXPIRE',KEYS[1],600)
return 1
"""
_DRAIN = """
local pairs = redis.call('HRANDFIELD',KEYS[1],tonumber(ARGV[1]),'WITHVALUES')
for i=1,#pairs,2 do redis.call('HDEL',KEYS[1],pairs[i]) end
return pairs
"""


def _bucket(admission, timestamp):
    return admission.prefix + 'customer-api:usage:' + str(timestamp // 60)


def record(admission, principal, now=None):
    try:
        admission._eval(_RECORD, [_bucket(admission, int(time.time() if now is None else now))], [principal.customer_id, principal.credential_id])
    except Exception:
        pass


def flush(admission, limit=500, now=None):
    remaining = min(1000, max(1, int(limit)))
    timestamp = int(time.time() if now is None else now)
    processed = 0
    for offset in range(9, -1, -1):
        bucket_time = timestamp - offset * 60
        while remaining:
            # The shared RESP client bounds array replies to eight values.
            pairs = admission._eval(_DRAIN, [_bucket(admission, bucket_time)], [min(remaining, 4)])
            if not pairs:
                break
            if len(pairs) % 2:
                raise ValueError('Invalid usage bucket')
            for index in range(0, len(pairs), 2):
                customer_id, credential_id = map(int, pairs[index].split(':'))
                count = int(pairs[index + 1])
                used_at = datetime.fromtimestamp((bucket_time // 60) * 60, timezone.utc)
                with transaction.atomic(), connection.cursor() as cursor:
                    cursor.execute('INSERT INTO public.customer_api_usage (customer_id,request_count) VALUES (%s,0) ON CONFLICT (customer_id) DO NOTHING', [customer_id])
                    cursor.execute('UPDATE public.customer_api_usage SET request_count=request_count+%s,last_used_at=GREATEST(last_used_at,%s) WHERE customer_id=%s', [count, used_at, customer_id])
                    cursor.execute('UPDATE public.customer_api_credentials SET last_used_at=GREATEST(last_used_at,%s) WHERE id=%s AND customer_id=%s', [used_at, credential_id, customer_id])
                processed += 1
                remaining -= 1
    return processed
