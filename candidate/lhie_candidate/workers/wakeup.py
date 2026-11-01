"""Disposable, ID-only Redis wakeups; PostgreSQL owns every durable obligation.

The envelope is Python JSON with a fixed version, family and stable identity.
No credentials, amounts, customer payloads, ORM snapshots or serialized PHP
jobs are accepted. A failed enqueue only loses a hint; each queue's durable
sweep re-discovers the same work.
"""

import json
import socket
import uuid

from .contract import IDLE_POLL_SECONDS, KIND_TO_QUEUE, WAKEUP_QUEUES


MAX_JOB_BYTES = 160
SOCKET_TIMEOUT = 1.0
_INTEGER_KINDS = frozenset({"navigation.update", "payment.event"})
_UUID_KINDS = frozenset({"effect", "order"})


class InvalidWakeup(ValueError):
    pass


def _identity_value(kind, identity):
    if kind in _INTEGER_KINDS:
        if type(identity) is int and 1 <= identity < 2**63:
            return identity
        raise InvalidWakeup("Invalid wakeup identity")
    if kind in _UUID_KINDS:
        try:
            return str(uuid.UUID(str(identity)))
        except (ValueError, TypeError, AttributeError):
            raise InvalidWakeup("Invalid wakeup identity") from None
    raise InvalidWakeup("Invalid wakeup family")


def encode(kind, identity):
    value = _identity_value(kind, identity)
    return '{"version":1,"kind":"' + kind + '","id":' + (
        str(value) if type(value) is int else '"' + value + '"') + "}"


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InvalidWakeup("Invalid wakeup envelope")
        result[key] = value
    return result


def decode(queue, raw):
    spec = WAKEUP_QUEUES.get(queue) if isinstance(queue, str) else None
    if spec is None:
        raise InvalidWakeup("Invalid wakeup queue")
    try:
        if type(raw) not in (str, bytes) or not 1 <= len(raw) <= MAX_JOB_BYTES:
            raise InvalidWakeup("Invalid wakeup envelope")
        if type(raw) is bytes:
            raw = raw.decode("utf-8")
        if not raw.isascii():
            raise InvalidWakeup("Invalid wakeup envelope")
        envelope = json.loads(raw, object_pairs_hook=_unique_object)
    except (ValueError, UnicodeError):
        raise InvalidWakeup("Invalid wakeup envelope") from None
    if (type(envelope) is not dict
            or set(envelope) != {"version", "kind", "id"}
            or type(envelope["version"]) is not int
            or envelope["version"] != 1
            or type(envelope["kind"]) is not str
            or envelope["kind"] not in spec.kinds):
        raise InvalidWakeup("Invalid wakeup envelope")
    identity = _identity_value(envelope["kind"], envelope["id"])
    if envelope["id"] != identity or encode(envelope["kind"], identity) != raw:
        raise InvalidWakeup("Invalid wakeup envelope")
    return envelope["kind"], identity


def _command(parts):
    return b"*" + str(len(parts)).encode("ascii") + b"\r\n" + b"".join(
        b"$" + str(len(part)).encode("ascii") + b"\r\n" + part + b"\r\n" for part in parts)


def _connect(timeout):
    return socket.create_connection(("127.0.0.1", 6379), timeout=timeout)


def enqueue(kind, identity, *, timeout=SOCKET_TIMEOUT):
    payload = encode(kind, identity).encode("ascii")
    queue = KIND_TO_QUEUE[kind].encode("ascii")
    try:
        with _connect(timeout) as connection:
            connection.settimeout(timeout)
            connection.sendall(_command((b"LPUSH", queue, payload)))
            response = connection.recv(128)
            if not response.startswith(b":") or not response.endswith(b"\r\n"):
                return False
            return int(response[1:-2]) > 0
    except (OSError, ValueError):
        return False


def take(queue, *, timeout=int(IDLE_POLL_SECONDS)):
    """Block for at most ``timeout`` seconds, returning raw bytes or None."""
    if queue not in WAKEUP_QUEUES:
        raise InvalidWakeup("Invalid wakeup queue")
    if type(timeout) is not int or not 1 <= timeout <= 5:
        raise ValueError("Invalid wakeup timeout")
    with _connect(timeout + 1) as connection:
        connection.settimeout(timeout + 1)
        connection.sendall(_command((b"BRPOP", queue.encode("ascii"), str(timeout).encode("ascii"))))
        with connection.makefile("rb") as response:
            length = response.readline(32)
            if length == b"*-1\r\n":
                return None
            if length != b"*2\r\n":
                raise ValueError("Invalid Redis response")
            queue_length = response.readline(32)
            if queue_length != b"$" + str(len(queue)).encode("ascii") + b"\r\n":
                raise ValueError("Invalid Redis response")
            if response.read(len(queue)) != queue.encode("ascii") or response.read(2) != b"\r\n":
                raise ValueError("Invalid Redis response")
            size_line = response.readline(32)
            if not size_line.startswith(b"$") or not size_line.endswith(b"\r\n"):
                raise ValueError("Invalid Redis response")
            size = int(size_line[1:-2])
            if not 0 < size <= MAX_JOB_BYTES:
                raise ValueError("Invalid Redis response")
            body = response.read(size)
            if len(body) != size or response.read(2) != b"\r\n":
                raise ValueError("Invalid Redis response")
            decode(queue, body)
            return body


def wake_after_commit(kind, identity, *, using="default"):
    """Validate before commit; a failed wakeup is repaired from PostgreSQL."""
    encode(kind, identity)
    from django.db import transaction
    transaction.on_commit(lambda: enqueue(kind, identity), using=using)
