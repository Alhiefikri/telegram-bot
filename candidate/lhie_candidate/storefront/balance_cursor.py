"""Laravel-compatible encrypted customer balance activity cursors."""
import json

from ..compat.crypto import CryptoError, LaravelEncrypter
from ..runtime import application_key


def encode(customer_id, currency, cursor):
    occurred_at, rank, identity = cursor
    payload = {'version': 1, 'customer_id': customer_id, 'currency': currency,
               'occurred_at': occurred_at, 'rank': rank, 'id': identity}
    return LaravelEncrypter(application_key()).encrypt_string(json.dumps(payload, separators=(',', ':')))


def decode(token, customer_id, currency):
    if not isinstance(token, str) or not 1 <= len(token) <= 1024:
        return None
    try:
        payload = json.loads(LaravelEncrypter(application_key()).decrypt_string(token))
    except (CryptoError, ValueError, TypeError, RecursionError):
        return None
    if (type(payload) is not dict or type(payload.get('version')) is not int or payload['version'] != 1
            or type(payload.get('customer_id')) is not int or payload['customer_id'] != customer_id
            or payload.get('currency') != currency):
        return None
    occurred_at, rank, identity = (payload.get('occurred_at'), payload.get('rank'), payload.get('id'))
    if (type(occurred_at) is not int or not 1 <= occurred_at < 2**63
            or type(rank) is not int or rank not in (1, 2)
            or type(identity) is not int or not 1 <= identity < 2**63):
        return None
    return occurred_at, rank, identity
