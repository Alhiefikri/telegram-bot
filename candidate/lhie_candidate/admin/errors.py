"""Bounded, safe admin failures; payloads and exception text never leave here."""

from dataclasses import dataclass, field

from django.core.exceptions import PermissionDenied


@dataclass
class AdminValidationError(Exception):
    errors: dict = field(default_factory=dict)
    status: int = 422
    message: str = "Periksa kembali isian yang ditandai."

    def __post_init__(self):
        super().__init__(self.message)


class AdminConflict(AdminValidationError):
    def __init__(self, message="Data telah berubah. Muat ulang halaman sebelum menyimpan."):
        super().__init__({}, status=409, message=message)


class AdminForbidden(AdminValidationError, PermissionDenied):
    def __init__(self, message="Akun ini tidak memiliki izin untuk tindakan tersebut."):
        AdminValidationError.__init__(self, {}, status=403, message=message)


class AdminUnavailable(AdminValidationError):
    def __init__(self, message="Perubahan belum dapat disimpan. Coba lagi."):
        super().__init__({}, status=503, message=message)
