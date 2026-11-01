class CheckoutError(ValueError):
    """Payload-free domain rejection; adapters translate the stable reason code."""

    def __init__(self, code):
        self.code = code
        super().__init__(code)
