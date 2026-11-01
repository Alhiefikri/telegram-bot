"""Local admitted receipt dependencies; the synthetic key never enters a job."""
from lhie_candidate.compat.crypto import LaravelEncrypter
from lhie_candidate.runtime import application_key
from .invoice import render_invoice
from .service import ReceiptService
from .source import ReceiptSource
from .transport import RelayTransport


def receipt_service(lease_seconds=600):
    key = application_key()
    source = ReceiptSource(LaravelEncrypter(key), signing_key=key,
                           invoice_base_url='http://127.0.0.1:18801', custom_emoji=True)
    return ReceiptService(source, RelayTransport(), render_invoice, lease_seconds=lease_seconds)
