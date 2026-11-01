"""Disposable Redis wakeups carry identities only; PostgreSQL owns recovery."""
import json
import socket
from uuid import UUID

from django.db import transaction


QUEUE = 'candidate:telegram-receipts'
MAX_JOB_BYTES = 160


def encode_job(kind, identity):
    if kind not in ('order', 'effect'):
        raise ValueError('Invalid receipt job')
    try:
        identity = str(UUID(str(identity)))
    except (ValueError, TypeError, AttributeError):
        raise ValueError('Invalid receipt job') from None
    return json.dumps({'version': 1, 'kind': kind, 'id': identity}, separators=(',', ':'))


def decode_job(raw):
    try:
        if not isinstance(raw, (bytes, str)) or not 0 < len(raw) <= MAX_JOB_BYTES:
            raise ValueError()
        def unique(pairs):
            result = {}
            for key, value in pairs:
                if key in result:
                    raise ValueError()
                result[key] = value
            return result
        obj = json.loads(raw, object_pairs_hook=unique)
        if type(obj) is not dict or obj.keys() != {'version', 'kind', 'id'} or type(obj['version']) is not int or obj['version'] != 1:
            raise ValueError()
        normalized = encode_job(obj['kind'], obj['id'])
        if json.loads(normalized)['id'] != obj['id']:
            raise ValueError()
        return obj['kind'], obj['id']
    except (ValueError, TypeError, UnicodeError, KeyError):
        raise ValueError('Invalid receipt job') from None


def enqueue(kind, identity):
    payload = encode_job(kind, identity).encode('ascii')
    parts = (b'LPUSH', QUEUE.encode('ascii'), payload)
    command = b'*3\r\n' + b''.join(b'$%d\r\n' % len(part) + part + b'\r\n' for part in parts)
    try:
        with socket.create_connection(('127.0.0.1', 6379), timeout=1) as connection:
            connection.settimeout(1)
            connection.sendall(command)
            response = connection.recv(128)
            if not response.startswith(b':') or not response.endswith(b'\r\n'):
                return False
            return int(response[1:-2]) > 0
    except (OSError, ValueError):
        return False


def take():
    parts = (b'RPOP', QUEUE.encode('ascii'))
    command = b'*2\r\n' + b''.join(b'$%d\r\n' % len(part) + part + b'\r\n' for part in parts)
    with socket.create_connection(('127.0.0.1', 6379), timeout=1) as connection:
        connection.settimeout(1)
        connection.sendall(command)
        with connection.makefile('rb') as response:
            length = response.readline(32)
            if length == b'$-1\r\n':
                return None
            if not length.startswith(b'$') or not length.endswith(b'\r\n'):
                raise ValueError('Invalid Redis response')
            size = int(length[1:-2])
            if not 0 < size <= MAX_JOB_BYTES:
                raise ValueError('Invalid Redis response')
            body = response.read(size)
            if len(body) != size or response.read(2) != b'\r\n':
                raise ValueError('Invalid Redis response')
            decode_job(body)
            return body


def wake_after_commit(kind, identity, *, using='default'):
    # Validate before commit; failure to wake is repaired from the durable source.
    encode_job(kind, identity)
    transaction.on_commit(lambda: enqueue(kind, identity), using=using)
