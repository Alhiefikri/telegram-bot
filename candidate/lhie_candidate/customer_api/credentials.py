"""Legacy-compatible Customer API credentials; plaintext is returned only once."""
from dataclasses import dataclass
import hashlib
import hmac
import re
import secrets
from datetime import timezone
from zoneinfo import ZoneInfo

from django.db import connection, transaction

from .admission import ApiFailure, check_availability

_TOKEN = re.compile(r"Bearer capi_([a-f0-9]{32})\.([a-f0-9]{64})\Z", re.IGNORECASE | re.ASCII)
_PREFERENCES = frozenset(('idr_only', 'usd_only', 'idr_first', 'usd_first'))


def _digest(value):
    return hashlib.sha256(value.encode('ascii')).hexdigest()


def _failure(code='stale_key_action', message='The API key changed. Open API management again.'):
    return ApiFailure(409, code, message)


@dataclass(frozen=True, slots=True)
class Principal:
    customer_id: int
    credential_id: int
    preference: str

    def revalidate_for_mutation(self):
        if not connection.in_atomic_block:
            raise RuntimeError('API mutation revalidation requires the owning transaction')
        with connection.cursor() as cursor:
            cursor.execute('SELECT balance_payment_preference FROM public.customers WHERE id=%s FOR SHARE', [self.customer_id])
            row = cursor.fetchone()
            if row is None or row[0] not in _PREFERENCES:
                raise ApiFailure(503, 'temporarily_unavailable', 'The service is temporarily unavailable.')
            cursor.execute('SELECT id FROM public.customer_api_credentials WHERE id=%s AND customer_id=%s AND revoked_at IS NULL FOR SHARE', [self.credential_id, self.customer_id])
            if cursor.fetchone() is None:
                raise ApiFailure(401, 'unauthenticated', 'A valid API key is required.')
        return row[0]


class IssuedKey:
    __slots__ = ('masked_identifier', '_secret')

    def __init__(self, selector, secret):
        self.masked_identifier = 'capi_' + selector[:8] + '…'
        self._secret = 'capi_' + selector + '.' + secret

    def take_secret(self):
        if self._secret is None:
            raise RuntimeError('API secret has already been consumed. Rotate to display another.')
        result, self._secret = self._secret, None
        return result

    def __repr__(self):
        return f'IssuedKey(masked_identifier={self.masked_identifier!r})'

    def __reduce__(self):
        raise TypeError('API secret results cannot be persisted or queued')


def authenticate(authorization):
    if not isinstance(authorization, str):
        return None
    match = _TOKEN.fullmatch(authorization)
    if match is None:
        return None
    selector, secret = match.groups()
    with connection.cursor() as cursor:
        cursor.execute('SELECT c.customer_id,c.id,c.secret_digest,u.balance_payment_preference FROM public.customer_api_credentials c JOIN public.customers u ON u.id=c.customer_id WHERE c.selector=%s AND c.revoked_at IS NULL', [selector])
        row = cursor.fetchone()
    if row is None or not hmac.compare_digest(row[2], _digest(secret)):
        return None
    if row[3] not in _PREFERENCES:
        raise ApiFailure(503, 'temporarily_unavailable', 'The service is temporarily unavailable.')
    return Principal(int(row[0]), int(row[1]), row[3])


def _lock_customer(cursor, customer_id):
    check_availability(for_mutation=True)
    cursor.execute('SELECT telegram_id FROM public.customers WHERE id=%s FOR UPDATE', [customer_id])
    row = cursor.fetchone()
    if row is None or row[0] is None:
        raise ApiFailure(403, 'not_authorized', 'A registered Telegram Customer is required.')


def _active(cursor, customer_id):
    cursor.execute('SELECT id,selector,rotation_digest,rotation_message_id,rotation_expires_at FROM public.customer_api_credentials WHERE customer_id=%s AND revoked_at IS NULL', [customer_id])
    return cursor.fetchone()


def _insert(cursor, customer_id):
    selector, secret = secrets.token_hex(16), secrets.token_hex(32)
    cursor.execute("INSERT INTO public.customer_api_credentials (customer_id,selector,secret_digest,created_at) VALUES (%s,%s,%s,timezone('Asia/Jakarta',clock_timestamp()))", [customer_id, selector, _digest(secret)])
    return IssuedKey(selector, secret)


def generate(customer_id):
    with transaction.atomic(), connection.cursor() as cursor:
        _lock_customer(cursor, customer_id)
        if _active(cursor, customer_id):
            raise _failure('key_exists', 'An active key already exists. Confirm rotation to replace it.')
        return _insert(cursor, customer_id)


def _prepare(customer_id, message_id, disable):
    with transaction.atomic(), connection.cursor() as cursor:
        _lock_customer(cursor, customer_id)
        row = _active(cursor, customer_id)
        if row is None:
            raise _failure()
        nonce = secrets.token_hex(16)
        cursor.execute("UPDATE public.customer_api_credentials SET rotation_digest=%s,rotation_message_id=%s,rotation_expires_at=timezone('Asia/Jakarta',clock_timestamp()) + interval '5 minutes' WHERE id=%s", [_digest(('disable:' if disable else '') + nonce), message_id, row[0]])
        return nonce


def prepare_rotation(customer_id, message_id):
    return _prepare(customer_id, message_id, False)


def prepare_disable(customer_id, message_id):
    return _prepare(customer_id, message_id, True)


def _revoke(cursor, credential_id):
    cursor.execute("UPDATE public.customer_api_credentials SET revoked_at=timezone('Asia/Jakarta',clock_timestamp()),rotation_digest=NULL,rotation_message_id=NULL,rotation_expires_at=NULL WHERE id=%s", [credential_id])


def _confirmed(customer_id, message_id, confirmation, disable):
    with transaction.atomic(), connection.cursor() as cursor:
        _lock_customer(cursor, customer_id)
        row = _active(cursor, customer_id)
        if not isinstance(confirmation, str) or len(confirmation) != 32 or not confirmation.isascii() or row is None:
            raise _failure()
        cursor.execute("SELECT %s > timezone('Asia/Jakarta',clock_timestamp())", [row[4]])
        valid_time = cursor.fetchone()[0]
        digest = _digest(('disable:' if disable else '') + confirmation)
        if not valid_time or row[3] != message_id or not hmac.compare_digest(row[2] or '', digest):
            raise _failure()
        _revoke(cursor, row[0])
        if not disable:
            return _insert(cursor, customer_id)


def rotate(customer_id, message_id, confirmation):
    return _confirmed(customer_id, message_id, confirmation, False)


def disable_confirmed(customer_id, message_id, confirmation):
    return _confirmed(customer_id, message_id, confirmation, True)


def disable(customer_id):
    with transaction.atomic(), connection.cursor() as cursor:
        _lock_customer(cursor, customer_id)
        row = _active(cursor, customer_id)
        if row:
            _revoke(cursor, row[0])


def set_preference(customer_id, preference):
    if preference not in _PREFERENCES:
        raise ApiFailure(422, 'validation_error', 'Invalid payment preference.')
    with transaction.atomic(), connection.cursor() as cursor:
        _lock_customer(cursor, customer_id)
        cursor.execute('UPDATE public.customers SET balance_payment_preference=%s WHERE id=%s', [preference, customer_id])


def summary(customer_id):
    check_availability()
    with connection.cursor() as cursor:
        cursor.execute('SELECT selector,created_at FROM public.customer_api_credentials WHERE customer_id=%s AND revoked_at IS NULL', [customer_id])
        active = cursor.fetchone()
        cursor.execute('SELECT request_count,last_used_at FROM public.customer_api_usage WHERE customer_id=%s', [customer_id])
        usage = cursor.fetchone()
    created = active[1] if active else None
    used = usage[1] if usage else None
    if created is not None and created.tzinfo is None:
        created = created.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
    if used is not None and used.tzinfo is None:
        used = used.replace(tzinfo=timezone.utc)
    return {'active': active is not None, 'masked_identifier': 'capi_' + active[0][:8] + '…' if active else None,
            'created_at': created.isoformat() if created else None, 'last_used_at': used.isoformat() if used else None,
            'account_request_count': str(usage[0] if usage else 0), 'usage_consistency': 'eventual_lower_bound'}
