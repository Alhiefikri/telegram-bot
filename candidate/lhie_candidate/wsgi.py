from .startup import create_application, suppress_payload_logging


suppress_payload_logging()
try:
    application = create_application()
except Exception:
    raise RuntimeError("candidate startup refused") from None
