"""Admission for the disposable P01 network namespace, never host services."""

from dataclasses import dataclass
import json
import os
from pathlib import Path
import re
import stat


RUNTIME_PATH = Path("/run/harness/candidate.json")
KEY_PATH = Path("/run/harness/django-key")
DATASET_PATTERN = re.compile(r"(?:synthetic-[a-z0-9-]{1,60}|sanitized-[a-f0-9]{64})")


class AdmissionError(Exception):
    def __init__(self):
        super().__init__("candidate runtime admission refused")


@dataclass(frozen=True, repr=False)
class Runtime:
    dataset: str
    secret_key: str


def _read_file(path, maximum, *, private=False):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, "rb") as stream:
        info = os.fstat(stream.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_size > maximum
                or (private and stat.S_IMODE(info.st_mode) not in (0o400, 0o600))):
            raise AdmissionError()
        value = stream.read(maximum + 1)
        if len(value) > maximum:
            raise AdmissionError()
        return value


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise AdmissionError()
        result[key] = value
    return result


def _exact(value, expected):
    if type(value) is not type(expected):
        return False
    if type(expected) is dict:
        return value.keys() == expected.keys() and all(
            _exact(value[key], expected[key]) for key in expected
        )
    return value == expected


def validate_document(document):
    expected = {
        "version": 1,
        "identity": "candidate",
        "dataset": "synthetic-empty",
        "quarantine": False,
        "database": {"host": "127.0.0.1", "port": 5432, "name": "disposable", "user": "candidate_runtime"},
        "redis": {"host": "127.0.0.1", "port": 6379, "db": 0},
        "limits": {"processes": 1, "threads": 1, "db_connections": 1},
    }
    if (type(document) is not dict or document.keys() != expected.keys()
            or type(document.get("dataset")) is not str
            or DATASET_PATTERN.fullmatch(document["dataset"]) is None):
        raise AdmissionError()
    expected["dataset"] = document["dataset"]
    if not _exact(document, expected):
        raise AdmissionError()
    return document["dataset"]


def load_runtime():
    try:
        document = json.loads(_read_file(RUNTIME_PATH, 4096), object_pairs_hook=_unique_object)
        dataset = validate_document(document)
        key = _read_file(KEY_PATH, 65, private=True).decode("ascii").removesuffix("\n")
        if re.fullmatch(r"[a-f0-9]{64}", key) is None:
            raise AdmissionError()
        return Runtime(dataset=dataset, secret_key=key)
    except Exception:
        raise AdmissionError() from None
