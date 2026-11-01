"""Receipt-family wakeups over the shared ID-only transport.

PostgreSQL owns receipt/effect recovery; these calls only shorten the wait.
"""

from ..workers import wakeup
from ..workers.contract import TOPOLOGY


QUEUE = TOPOLOGY["receipt"].wakeup_queue
MAX_JOB_BYTES = wakeup.MAX_JOB_BYTES


def encode_job(kind, identity):
    return wakeup.encode(kind, identity)


def decode_job(raw):
    return wakeup.decode(QUEUE, raw)


def enqueue(kind, identity):
    return wakeup.enqueue(kind, identity)


def take():
    return wakeup.take(QUEUE)


def wake_after_commit(kind, identity, *, using='default'):
    wakeup.wake_after_commit(kind, identity, using=using)
