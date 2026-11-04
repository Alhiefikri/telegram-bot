from io import BytesIO
from pathlib import Path
import tempfile
import unittest

from PIL import Image
import zxingcpp

from lhie_candidate.payments.presentation import Placement, QrPresentation, compose, render_payload


def png(image):
    stream = BytesIO()
    image.save(stream, format='PNG')
    return stream.getvalue()


def barcode(contents):
    result = zxingcpp.read_barcode(Image.open(BytesIO(contents)), formats=zxingcpp.BarcodeFormat.QRCode)
    if result is None:
        raise AssertionError('QR is not scannable')
    return result


class QrPresentationContracts(unittest.TestCase):
    def test_template_preserves_payload_and_detected_symbol_matrix(self):
        original = render_payload('Synthetic P13: not a payable code', scale=3)
        placement = Placement(png(Image.new('RGBA', (800, 700), (10, 30, 60, 0))), 120, 100, 560, 520)
        output = compose(original, placement)
        before, after = barcode(original), barcode(output)
        self.assertEqual(after.bytes, before.bytes)
        self.assertEqual(memoryview(after.to_image()).tobytes(), memoryview(before.to_image()).tobytes())
        image = Image.open(BytesIO(output))
        self.assertEqual(image.size, (800, 700))
        self.assertEqual(image.getpixel((0, 0)), (255, 255, 255))
        self.assertEqual(image.getpixel((141, 101)), (255, 255, 255))

    def test_invalid_decoration_never_replaces_the_payment_qr(self):
        qr = render_payload('Synthetic P13 QR')
        for placement in (None, Placement(b'corrupt', 0, 0, 500, 500),
                          Placement(png(Image.new('RGB', (500, 500))), 450, 0, 500, 500),
                          Placement(png(Image.new('RGB', (500, 500))), 0, 0, 20, 20)):
            with self.subTest(placement=placement):
                self.assertEqual(compose(qr, placement), qr)
        invalid = b'not an image'
        self.assertEqual(compose(invalid, Placement(png(Image.new('RGB', (500, 500))), 0, 0, 500, 500)), invalid)

    def test_asset_reads_reject_escape_symlink_and_oversized_images(self):
        with tempfile.TemporaryDirectory() as directory, tempfile.TemporaryDirectory() as outside:
            root = Path(directory)
            foreign = Path(outside) / 'outside.png'
            foreign.write_bytes(render_payload('Synthetic outside'))
            (root / 'escape.png').symlink_to(foreign)
            renderer = QrPresentation(public_storage=root)
            for path in ('../outside.png', str(foreign), 'escape.png'):
                with self.subTest(path=path), self.assertRaises((ValueError, OSError)):
                    renderer.read_file(path)
            (root / 'large.png').write_bytes(png(Image.new('L', (1601, 20))))
            with self.assertRaises(ValueError):
                renderer.read_file('large.png')

    def test_unmapped_manual_image_is_not_a_live_receiver_fallback(self):
        self.assertFalse(QrPresentation().manual_ready())
        with self.assertRaises(ValueError):
            QrPresentation().manual()
