"""Receipt family: bounded delivery plus PostgreSQL recovery sweeps."""

from . import wakeup
from .contract import queue_spec


class ReceiptHandler:
    def __init__(self, service):
        self.service = service
        self.spec = queue_spec("receipt")

    def handle(self, raw):
        wakeup.decode(self.spec.wakeup_queue, raw)
        return self.service.handle(raw)

    def sweep(self, limit):
        summary = self.service.recover(limit)
        delivered = 0
        # Redis is only a wakeup. Continue ordered successors from PostgreSQL
        # in one bounded sweep so a lost Redis list cannot strand a group.
        while delivered < limit:
            due = self.service.protocol.ready_effects(limit - delivered)
            if not due:
                break
            progressed = 0
            for effect in due:
                if self.service.deliver(effect["id"]) in ("sent", "unknown", "failed"):
                    progressed += 1
            delivered += progressed
            if progressed == 0:
                break
        summary["delivered"] = delivered
        return summary
