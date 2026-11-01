"""Bounded worker scheduling, isolation and admission contract.

No framework import belongs here: startup, tests and the supervisor can validate
the same numbers before a database or Redis connection exists. PostgreSQL stays
the durable owner of work; a Redis list is only a disposable wakeup.
"""

from dataclasses import dataclass


# Shared timing envelope. Receipt retries are owned by the effect ledger
# (three attempts); the process timeout must stay below the legacy retry_after
# so a killed or timed-out worker is never still sending when Laravel would
# consider the job available again.
JOB_TIMEOUT_SECONDS = 60
RETRY_AFTER_SECONDS = 180
LEASE_SECONDS = RETRY_AFTER_SECONDS
IDLE_POLL_SECONDS = 1.0
SHUTDOWN_GRACE_SECONDS = JOB_TIMEOUT_SECONDS + 15
RESTART_LIMIT = 3
RESTART_WINDOW_SECONDS = 60

# One HTTP process, one supervisor parent and one process per queue. Each
# process uses one thread and at most one candidate_runtime connection at a
# time; the role ceiling is four. The supervisor never opens a connection.
HTTP_PROCESSES = 1
SUPERVISOR_PROCESSES = 1
DB_CONNECTIONS_PER_WORKER = 1
DB_CONNECTIONS_PER_HTTP = 1

RESERVED_FAMILIES = (
    "admin.notice",
    "balance.notice",
    "broadcast",
    "email.credential",
    "payment.proof",
    "sales_proof",
    "stock.notification",
    "supplier.purchase",
    "telegram.outbound",
)


@dataclass(frozen=True, slots=True)
class QueueSpec:
    name: str
    wakeup_queue: str
    kinds: tuple[str, ...]
    batch: int
    sweep_seconds: int
    sweep_limit: int
    max_attempts: int | None
    timeout_seconds: int = JOB_TIMEOUT_SECONDS
    retry_after_seconds: int = RETRY_AFTER_SECONDS

    def __post_init__(self) -> None:
        if not self.name or any(character not in "abcdefghijklmnopqrstuvwxyz-" for character in self.name):
            raise ValueError("Invalid queue name")
        if not self.wakeup_queue.startswith("candidate:") or not self.kinds:
            raise ValueError("Invalid wakeup queue")
        if len(set(self.kinds)) != len(self.kinds) or set(self.kinds) & set(RESERVED_FAMILIES):
            raise ValueError("Queue families must be disjoint and non-reserved")
        if not all(isinstance(kind, str) and kind for kind in self.kinds):
            raise ValueError("Invalid queue family")
        if not 1 <= self.batch <= 100 or not 1 <= self.sweep_limit <= 100:
            raise ValueError("Worker batches must stay bounded")
        if not 1 <= self.sweep_seconds <= 60:
            raise ValueError("Invalid sweep interval")
        if self.max_attempts is not None and not 1 <= self.max_attempts <= 5:
            raise ValueError("Invalid attempt ceiling")
        if not 0 < self.timeout_seconds < self.retry_after_seconds:
            raise ValueError("Worker timeout must stay below retry_after")


TOPOLOGY = {
    "interaction": QueueSpec(
        name="interaction",
        wakeup_queue="candidate:interaction",
        kinds=("navigation.update",),
        batch=25,
        sweep_seconds=2,
        sweep_limit=25,
        # A claimed update that lost its worker is never replayed blindly.
        max_attempts=None,
    ),
    "payment": QueueSpec(
        name="payment",
        wakeup_queue="candidate:payments",
        kinds=("payment.event",),
        batch=10,
        sweep_seconds=5,
        sweep_limit=20,
        max_attempts=3,
    ),
    "receipt": QueueSpec(
        name="receipt",
        wakeup_queue="candidate:telegram-receipts",
        kinds=("effect", "order"),
        batch=25,
        sweep_seconds=5,
        sweep_limit=50,
        max_attempts=3,
    ),
}

QUEUES = tuple(TOPOLOGY)
WAKEUP_QUEUES = {spec.wakeup_queue: spec for spec in TOPOLOGY.values()}
KIND_TO_QUEUE = {kind: spec.wakeup_queue for spec in TOPOLOGY.values() for kind in spec.kinds}
RUNTIME_PROCESSES = SUPERVISOR_PROCESSES + HTTP_PROCESSES + len(QUEUES)
RUNTIME_THREADS = RUNTIME_PROCESSES
RUNTIME_DB_CONNECTIONS = DB_CONNECTIONS_PER_HTTP + DB_CONNECTIONS_PER_WORKER * len(QUEUES)


def queue_spec(name: str) -> QueueSpec:
    try:
        return TOPOLOGY[name]
    except (KeyError, TypeError):
        raise ValueError("Unknown worker queue") from None


def validate_queue_name(name: str) -> str:
    queue_spec(name)
    return name


def worker_connection_budget() -> dict[str, int]:
    """The admitted whole-runtime ceilings, not a measured usage claim."""
    return {
        "processes": RUNTIME_PROCESSES,
        "threads": RUNTIME_THREADS,
        "db_connections": RUNTIME_DB_CONNECTIONS,
    }


def assert_admitted_limits(limits: object) -> None:
    if limits != worker_connection_budget():
        raise ValueError("Runtime worker budget is not admitted")


def restart_budget_exceeded(restarts: list[float], now: float) -> bool:
    recent = [moment for moment in restarts if 0 <= now - moment < RESTART_WINDOW_SECONDS]
    return len(recent) >= RESTART_LIMIT
