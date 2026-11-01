"""Render a credential-free paid order snapshot to transient PNG bytes.

Amounts are integer IDR units or, for USDT, integer millionths.  The caller
must project the public invoice fields before calling; allocations and account
credentials do not belong in this interface.
"""
from datetime import datetime
from io import BytesIO
from pathlib import Path
import re
from zoneinfo import ZoneInfo

from PIL import Image, ImageDraw, ImageFont


WIDTH = HEIGHT = 720
MAX_BYTES = 300 * 1024
_LOGO = Path(__file__).parent / 'assets' / 'lhie-store-logo.png'
_REGULAR = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
_INK = '#0b1220'
_BLUE = '#005cff'
_MUTED = '#667085'
_LINE = '#a9b4c4'


def _text(value, limit):
    return re.sub(r'[\x00-\x1f\x7f]+', ' ', str(value or '')).strip()[:limit]


def _money(value, currency):
    if value is None:
        return '-'
    if type(value) is not int or value < 0:
        raise ValueError('Invoice amounts must be nonnegative integer units')
    if currency == 'USDT':
        whole, fraction = divmod(value, 1_000_000)
        return f'{whole:,}.{fraction:06d}'.rstrip('0').rstrip('.') + ' USDT'
    if currency == 'IDR':
        return 'Rp ' + f'{value:,}'.replace(',', '.')
    return f'{currency} {value:,}'


def _fit(draw, text, width, size, *, bold=False):
    path = _BOLD if bold else _REGULAR
    for point in range(size, 7, -1):
        font = ImageFont.truetype(path, point)
        if draw.textlength(text, font=font) <= width:
            return text, font
    font = ImageFont.truetype(path, 8)
    while text and draw.textlength(text + '…', font=font) > width:
        text = text[:-1]
    return text.rstrip() + '…', font


def _draw(draw, x, y, text, width, size=15, color=_INK, bold=False, right=False):
    text, font = _fit(draw, text, width, size, bold=bold)
    if right:
        x -= draw.textlength(text, font=font)
    draw.text((x, y), text, font=font, fill=color)


def _wrap(draw, text, width, font):
    lines = []
    line = ''
    for word in text.split():
        candidate = (line + ' ' + word).strip()
        if draw.textlength(candidate, font=font) <= width:
            line = candidate
            continue
        if line:
            lines.append(line)
        line = word
        while line and draw.textlength(line, font=font) > width:
            end = len(line)
            while end > 1 and draw.textlength(line[:end], font=font) > width:
                end -= 1
            lines.append(line[:end])
            line = line[end:]
    if line:
        lines.append(line)
    if len(lines) > 2:
        last = lines[1]
        while last and draw.textlength(last + '…', font=font) > width:
            last = last[:-1]
        lines = [lines[0], last.rstrip() + '…']
    return lines or ['Product']


def _dashed(draw, x1, y, x2):
    for x in range(x1, x2, 16):
        draw.line((x, y, min(x + 8, x2), y), fill=_LINE)


def render_invoice(snapshot):
    """Return a 720x720 PNG of a paid, credential-free snapshot.

    Required keys: invoice_number, buyer_name, chat_id, paid_at (aware
    datetime), payment_method, currency, items, provider_fee, total_paid.
    Items contain product_name, quantity, unit_price and subtotal. Optional
    payment_subtotal overrides the sum of all item subtotals; item_currency
    defaults to currency. USDT amounts use micro-units.
    """
    paid_at = snapshot['paid_at']
    if not isinstance(paid_at, datetime) or paid_at.utcoffset() is None:
        raise ValueError('Paid invoice timestamp must be timezone-aware')
    items = snapshot['items']
    if not items:
        raise ValueError('Paid invoice requires order items')
    currency = _text(snapshot['currency'], 12).upper() or 'IDR'
    item_currency = _text(snapshot.get('item_currency') or currency, 12).upper()
    subtotal = snapshot.get('payment_subtotal')
    if subtotal is None:
        subtotals = [item['subtotal'] for item in items]
        if any(type(value) is not int or value < 0 for value in subtotals):
            raise ValueError('Invoice amounts must be nonnegative integer units')
        subtotal = sum(subtotals)
    name = _text(snapshot['buyer_name'], 96).lstrip('@')
    if name.lower() == 'telegram customer':
        name = ''
    buyer = name[:3] + '***' if name else 'Buyer'
    method = _text(snapshot['payment_method'], 48)
    method = {'api_qris': 'QRIS', 'qris': 'QRIS', 'binance_pay_id': 'Binance Pay',
              'usdt_balance': 'USDT Balance', 'hosted': 'Pakasir',
              'qr_manual': 'QR Manual'}.get(method.lower(), method or 'Unknown')

    image = Image.new('RGB', (WIDTH, HEIGHT), '#eef4ff')
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle((8, 8, 718, 718), radius=18, fill='#b29dff')
    draw.rounded_rectangle((2, 2, 714, 714), radius=18, fill='#ccd5e6')
    draw.rounded_rectangle((5, 5, 711, 711), radius=16, fill='#fffdf8')
    _dashed(draw, 20, 20, 696)
    _dashed(draw, 20, 712, 696)
    for y in range(20, 712, 16):
        draw.line((20, y, 20, min(y + 8, 712)), fill=_INK)
        draw.line((696, y, 696, min(y + 8, 712)), fill=_INK)
    draw.polygon(((18, 42), (96, 28), (102, 54), (24, 68)), fill='#ffe45c')
    draw.polygon(((622, 28), (700, 42), (694, 68), (616, 54)), fill='#ff8fc7')
    with Image.open(_LOGO) as source:
        logo = source.convert('RGBA')
        logo.thumbnail((190, 132), Image.Resampling.LANCZOS)
        image.paste(logo, (48, 14), logo)
    _draw(draw, 286, 42, 'INVOICE', 240, 29, _BLUE, True)
    _draw(draw, 289, 78, 'DIGITAL PAYMENT RECEIPT', 240, 11, _MUTED, True)
    draw.rounded_rectangle((552, 55, 674, 99), 4, fill=_INK)
    draw.rounded_rectangle((547, 50, 669, 94), 4, fill='#2fcb84')
    _draw(draw, 578, 61, 'PAID', 75, 20, _INK, True)
    draw.rectangle((48, 146, 672, 150), fill=_BLUE)
    _draw(draw, 48, 159, 'INVOICE NO.', 105, 11, _MUTED, True)
    _draw(draw, 158, 156, _text(snapshot['invoice_number'], 96) or 'ORDER', 510, 17, _INK, True)
    _draw(draw, 48, 190, 'BUYER', 270, 11, _MUTED, True)
    _draw(draw, 48, 211, buyer, 270, 16, _INK, True)
    _draw(draw, 370, 190, 'PAID AT', 300, 11, _MUTED, True)
    _draw(draw, 370, 211, paid_at.astimezone(ZoneInfo('Asia/Jakarta')).strftime('%d %B %Y, %H:%M WIB'), 302, 15, _INK, True)
    _draw(draw, 48, 250, 'PAYMENT METHOD', 270, 11, _MUTED, True)
    _draw(draw, 48, 270, method, 270, 15, _INK, True)
    _dashed(draw, 48, 298, 672)
    _draw(draw, 48, 305, 'ORDER DETAILS', 300, 15, _INK, True)
    for x, label in ((48, 'PRODUCT'), (348, 'QTY'), (414, 'UNIT PRICE'), (577, 'LINE TOTAL')):
        _draw(draw, x, 331, label, 95, 11, _BLUE, True)
    draw.rectangle((48, 350, 672, 353), fill=_BLUE)
    product_font = ImageFont.truetype(_BOLD, 14)
    for index, item in enumerate(items[:3]):
        top = 362 + index * 50
        for line_index, line in enumerate(_wrap(draw, _text(item['product_name'], 128), 270, product_font)):
            draw.text((48, top + line_index * 17), line, font=product_font, fill=_INK)
        _draw(draw, 390, top, str(max(0, int(item['quantity']))), 52, 14, _INK, True, True)
        _draw(draw, 535, top, _money(item['unit_price'], item_currency), 130, 13, _INK, False, True)
        _draw(draw, 672, top, _money(item['subtotal'], item_currency), 130, 13, _INK, True, True)
        _dashed(draw, 48, top + 44, 672)
    summary_top = 522
    if len(items) > 3:
        _draw(draw, 48, 513, f'+{len(items) - 3} other products', 250, 12, _BLUE, True)
        summary_top = 540
    _draw(draw, 238, summary_top, 'SUBTOTAL', 180, 12, _MUTED)
    _draw(draw, 520, summary_top, _money(subtotal, currency), 135, 14, _INK, True, True)
    _draw(draw, 238, summary_top + 24, 'PROVIDER / ADMIN FEE', 180, 12, _MUTED)
    _draw(draw, 520, summary_top + 24, _money(snapshot['provider_fee'], currency), 135, 14, _INK, True, True)
    draw.rectangle((188, summary_top + 47, 520, summary_top + 50), fill=_INK)
    draw.rectangle((188, summary_top + 61, 318, summary_top + 88), fill='#ffe45c')
    _draw(draw, 196, summary_top + 66, 'TOTAL PAID', 120, 14, _INK, True)
    _draw(draw, 520, summary_top + 63, _money(snapshot['total_paid'], currency), 195, 21, _BLUE, True, True)
    draw.ellipse((551, summary_top + 1, 651, summary_top + 101), outline=_BLUE, width=3)
    _draw(draw, 571, summary_top + 28, 'PAID', 70, 21, _BLUE, True)
    _draw(draw, 570, summary_top + 55, 'VERIFIED', 70, 10, _BLUE, True)
    _dashed(draw, 48, 662, 520)
    _draw(draw, 230, 672, 'Thank you for choosing Lhie Store.', 300, 13)
    _draw(draw, 245, 692, 'Keep this invoice for your records.', 300, 11, _MUTED)
    buffer = BytesIO()
    image.save(buffer, format='PNG', optimize=True)
    png = buffer.getvalue()
    if len(png) > MAX_BYTES or not png.startswith(b'\x89PNG\r\n\x1a\n'):
        raise ValueError('Paid invoice PNG exceeds its size budget or is invalid')
    return png
