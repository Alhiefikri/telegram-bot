"""QR rendering and optional DB-backed decoration; decoration never changes money."""
from dataclasses import dataclass
from io import BytesIO
from pathlib import Path
import stat
import os

from PIL import Image
import zxingcpp

from ..checkout.db import Database


MAX_IMAGE_BYTES = 5 * 1024 * 1024
MAX_IMAGE_SIDE = 1600


def _image(contents):
    if type(contents) is not bytes or not 0 < len(contents) <= MAX_IMAGE_BYTES:
        raise ValueError('Invalid QR image')
    image = Image.open(BytesIO(contents))
    if (image.format not in ('PNG', 'JPEG', 'WEBP') or image.n_frames != 1
            or not all(0 < side <= MAX_IMAGE_SIDE for side in image.size)):
        raise ValueError('Invalid QR image')
    image.load()
    return image


def _png(image):
    output = BytesIO()
    image.save(output, format='PNG')
    return output.getvalue()


def _barcode_image(barcode, scale=1):
    pixels = barcode.to_image(scale=scale, add_quiet_zones=True)
    height, width = pixels.shape
    return Image.frombytes('L', (width, height), memoryview(pixels).tobytes())


def render_payload(payload, scale=8):
    if type(payload) is not str or not 0 < len(payload.encode()) <= 4096:
        raise ValueError('Invalid QR payload')
    barcode = zxingcpp.create_barcode(payload, zxingcpp.BarcodeFormat.QRCode, ec_level='L')
    return _png(_barcode_image(barcode, scale))


@dataclass(frozen=True, slots=True)
class Placement:
    contents: bytes
    x: int
    y: int
    width: int
    height: int

    @property
    def square(self):
        if any(type(value) is not int for value in (self.x, self.y, self.width, self.height)):
            raise ValueError('Invalid QR placement')
        side = min(self.width, self.height)
        return self.x + (self.width - side) // 2, self.y + (self.height - side) // 2, side


def compose(contents, placement):
    if placement is None:
        return contents
    try:
        qr = _image(contents)
        canvas = _image(placement.contents)
        x, y, side = placement.square
        if (qr.width != qr.height or side < qr.width or x < 0 or y < 0
                or x + side > canvas.width or y + side > canvas.height):
            return contents
        decoded = zxingcpp.read_barcodes(qr, formats=zxingcpp.BarcodeFormat.QRCode)
        if len(decoded) != 1 or not decoded[0].valid:
            return contents
        # Re-render the detected symbol, not its text: version/ECC/mask/codewords survive.
        modules = _barcode_image(decoded[0])
        scale = side // modules.width
        if scale < 1:
            return contents
        normalized = modules.resize((modules.width * scale, modules.height * scale), Image.Resampling.NEAREST)
        opaque = Image.new('RGBA', canvas.size, 'white')
        opaque.alpha_composite(canvas.convert('RGBA'))
        opaque = opaque.convert('RGB')
        opaque.paste('white', (x, y, x + side, y + side))
        opaque.paste(normalized, (x + (side - normalized.width) // 2, y + (side - normalized.height) // 2))
        return _png(opaque)
    except (ValueError, TypeError, OSError, RuntimeError, OverflowError):
        return contents


class QrPresentation:
    def __init__(self, *, public_storage=None, image_fetcher=None, using='default'):
        # Only explicitly admitted sanitized files may be mounted here; no production default.
        self.storage = Path(public_storage).resolve() if public_storage is not None else None
        self.db = Database(using)
        self.image_fetcher = image_fetcher

    def read_file(self, path):
        if self.storage is None or type(path) is not str:
            raise ValueError('QR asset unavailable')
        relative = Path(path)
        if relative.is_absolute() or '..' in relative.parts:
            raise ValueError('QR asset unavailable')
        resolved = (self.storage / relative).resolve(strict=True)
        if not resolved.is_relative_to(self.storage):
            raise ValueError('QR asset unavailable')
        with resolved.open('rb') as stream:
            info = os.fstat(stream.fileno())
            if not stat.S_ISREG(info.st_mode) or not 0 < info.st_size <= MAX_IMAGE_BYTES:
                raise ValueError('QR asset unavailable')
            contents = stream.read(MAX_IMAGE_BYTES + 1)
        _image(contents)
        return contents

    def placement(self):
        row = self.db.one('''SELECT enabled,image_path,qr_x,qr_y,qr_width,qr_height,version
            FROM public.payment_qr_template_settings WHERE singleton_key='payment-qr-template' ''')
        if row is None or not row['enabled'] or not row['image_path']:
            return None
        try:
            if not row['image_path'].startswith('payment-qr-templates/'):
                return None
            return Placement(self.read_file(row['image_path']), row['qr_x'], row['qr_y'], row['qr_width'], row['qr_height'])
        except (ValueError, OSError, TypeError):
            return None

    def image(self, contents):
        return compose(contents, self.placement())

    def provider_image(self, url, provider, *, environment='sandbox', allowed_hosts=()):
        from ..checkout.navigation import _qr_image_url
        if self.image_fetcher is None or not _qr_image_url(url, provider, environment, allowed_hosts):
            return None
        contents = self.image_fetcher(url, timeout=5, max_bytes=MAX_IMAGE_BYTES)
        _image(contents)
        return self.image(contents)

    def payload(self, payload):
        placement = self.placement()
        if placement is None:
            return render_payload(payload)
        modules = render_payload(payload, scale=1)
        side = placement.square[2]
        width = _image(modules).width
        if side < width:
            return render_payload(payload)
        return compose(render_payload(payload, scale=side // width), placement)

    def manual(self):
        # The historical Manual QR asset is intentionally not decorated in PHP.
        return self.read_file('qr-lhie.jpg')

    def manual_ready(self):
        try:
            self.manual()
            return True
        except (ValueError, OSError):
            return False
