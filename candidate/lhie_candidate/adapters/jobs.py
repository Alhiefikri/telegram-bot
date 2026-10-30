"""Python JSON jobs only. PHP serialization and model snapshots are not inputs."""

from dataclasses import dataclass
import json

from lhie_candidate.domain.money import require_integer
from lhie_candidate.domain.purchase import AssessPurchase, PurchaseAssessment


MAX_JOB_BYTES = 256
_KIND = "purchase.affordability"
_KEYS = {"version", "kind", "request_id"}


class InvalidJob(ValueError):
    pass


def _unique_object(pairs: list[tuple[str, object]]) -> dict[str, object]:
    result = {}
    for key, value in pairs:
        if key in result:
            raise InvalidJob("Invalid Python job envelope")
        result[key] = value
    return result


@dataclass(frozen=True, slots=True)
class AffordabilityJob:
    request_id: int

    def __post_init__(self) -> None:
        try:
            require_integer(self.request_id, minimum=1)
        except ValueError:
            raise InvalidJob("Invalid Python job envelope") from None

    def encode(self) -> str:
        return json.dumps(
            {"version": 1, "kind": _KIND, "request_id": self.request_id},
            separators=(",", ":"),
        )

    @classmethod
    def decode(cls, raw: str | bytes) -> "AffordabilityJob":
        if type(raw) not in (str, bytes) or not 1 <= len(raw) <= MAX_JOB_BYTES:
            raise InvalidJob("Invalid Python job envelope")
        try:
            if type(raw) is bytes:
                raw = raw.decode("utf-8")
            if not raw.isascii():
                raise InvalidJob("Invalid Python job envelope")
            envelope = json.loads(raw, object_pairs_hook=_unique_object)
        except (ValueError, UnicodeError):
            raise InvalidJob("Invalid Python job envelope") from None
        if (
            type(envelope) is not dict
            or envelope.keys() != _KEYS
            or type(envelope["version"]) is not int
            or envelope["version"] != 1
            or envelope["kind"] != _KIND
        ):
            raise InvalidJob("Invalid Python job envelope")
        return cls(envelope["request_id"])


def handle_affordability_job(
    raw: str | bytes, service: AssessPurchase
) -> PurchaseAssessment | None:
    job = AffordabilityJob.decode(raw)
    return service.execute(job.request_id)
