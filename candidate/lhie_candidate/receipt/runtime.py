"""Local admitted receipt dependencies; the synthetic key never enters a job."""
import re
from pathlib import Path

from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.runtime import _read_file
from .invoice import render_invoice
from .service import ReceiptService
from .source import ReceiptSource
from .transport import RelayTransport


def receipt_service():
    encoded = _read_file(Path('/run/harness/app-key'), 64, private=True).decode('ascii').strip()
    if re.fullmatch(r'[A-Za-z0-9+/]{43}=', encoded) is None:
        raise ValueError('Invalid local application key')
    key = 'base64:' + encoded
    source = ReceiptSource(LaravelEncrypter(key), signing_key=key,
                           invoice_base_url='http://127.0.0.1:18801', custom_emoji=True)
    return ReceiptService(source, RelayTransport(), render_invoice)
