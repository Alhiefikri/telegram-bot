"""Values shared by catalog presentation and canonical navigation transport."""
from dataclasses import dataclass, field
from typing import Literal


@dataclass(frozen=True, slots=True)
class Surface:
    caption: str
    keyboard: dict
    screen: str
    locale: str = "id"
    options: dict = field(default_factory=dict)


@dataclass(frozen=True, slots=True)
class TelegramResult:
    message_id: int | None
    outcome: Literal["success", "not_modified", "definite", "ambiguous"]
