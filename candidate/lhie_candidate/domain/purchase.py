"""Read-only affordability example. An affordable result authorizes no purchase."""

from dataclasses import dataclass
from enum import Enum
from typing import Protocol

from .money import Money, require_integer


@dataclass(frozen=True, slots=True)
class PurchaseInputs:
    available: Money
    unit_price: Money
    quantity: int

    def __post_init__(self) -> None:
        if type(self.available) is not Money or type(self.unit_price) is not Money:
            raise ValueError("Expected exact money values")
        if self.available.currency is not self.unit_price.currency:
            raise ValueError("Currency conversion is not supported")
        if self.unit_price.units == 0:
            raise ValueError("Purchase price must be positive")
        require_integer(self.quantity, minimum=1)


class Affordability(Enum):
    AFFORDABLE = "affordable"
    INSUFFICIENT_FUNDS = "insufficient_funds"


@dataclass(frozen=True, slots=True)
class PurchaseAssessment:
    outcome: Affordability
    total: Money
    shortfall: Money


def assess_affordability(inputs: PurchaseInputs) -> PurchaseAssessment:
    """No writes, stock decisions, implicit FX, IO, or transaction ownership."""
    total = inputs.unit_price.multiply(inputs.quantity)
    shortfall = max(0, total.units - inputs.available.units)
    return PurchaseAssessment(
        outcome=(Affordability.INSUFFICIENT_FUNDS if shortfall else Affordability.AFFORDABLE),
        total=total,
        shortfall=Money(total.currency, shortfall),
    )


class PurchaseInputReader(Protocol):
    def load_affordability(self, request_id: int) -> PurchaseInputs | None:
        """Resolve a trusted local request ID; never fetch upstream inside locks."""
        ...


class AssessPurchase:
    def __init__(self, reader: PurchaseInputReader) -> None:
        self._reader = reader

    def execute(self, request_id: int) -> PurchaseAssessment | None:
        require_integer(request_id, minimum=1)
        inputs = self._reader.load_affordability(request_id)
        return None if inputs is None else assess_affordability(inputs)
