"""Interaction family: durable Telegram updates with per-chat serialization."""

from . import wakeup
from .contract import queue_spec


class InteractionHandler:
    def __init__(self, processor):
        self.processor = processor
        self.spec = queue_spec("interaction")

    def handle(self, raw):
        kind, identity = wakeup.decode(self.spec.wakeup_queue, raw)
        if kind != "navigation.update":
            raise wakeup.InvalidWakeup("Invalid wakeup family")
        return self.processor.process(identity)

    def sweep(self, limit):
        recovered = self.processor.recover(limit)
        outcomes = self.processor.drain(limit)
        return {"recovered": recovered, "processed": len(outcomes)}
