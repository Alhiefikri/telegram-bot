"""Customer support links contain public context, never access keys or credentials."""
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit


_ORDER_CATEGORIES = (
    'QRIS / pembayaran belum terdeteksi',
    'Pembayaran gagal, expired, atau dibatalkan',
    'Data akun belum muncul',
    'Kredensial tidak valid',
    'MFA / Authenticator bermasalah',
    'Login gagal',
)


def _with_message(base, message):
    try:
        parts = urlsplit(base)
        if parts.scheme != 'https' or not parts.hostname or parts.username or parts.password:
            return None
        query = [(key, value) for key, value in parse_qsl(parts.query, keep_blank_values=True) if key != 'text']
        query.append(('text', message))
        return urlunsplit((parts.scheme, parts.netloc, parts.path, urlencode(query), ''))
    except (ValueError, TypeError):
        return None


def contact_options(message, *, whatsapp='', telegram=''):
    return [{'label': label, 'url': url} for label, base in
            (('WhatsApp Support', whatsapp), ('Telegram Admin', telegram))
            if (url := _with_message(base, message))]


def general_choices(*, whatsapp='', telegram=''):
    return contact_options('Halo Admin Lhie Store, saya butuh bantuan di storefront.',
                           whatsapp=whatsapp, telegram=telegram)


def product_choices(name, product_url, *, whatsapp='', telegram=''):
    name = ' '.join(str(name or '').split())
    message = f'Halo Admin Lhie Store, saya mau tanya sebelum membeli.\nProduk: {name}\nURL produk: {product_url}'
    return contact_options(message, whatsapp=whatsapp, telegram=telegram)


def order_choices(invoice, *, whatsapp='', telegram=''):
    invoice = ' '.join(str(invoice or '').split())
    result = []
    for category in _ORDER_CATEGORIES:
        message = ('Halo Admin Lhie Store, saya butuh bantuan untuk pesanan website.\n'
                   f'Invoice: {invoice}\nKategori masalah: {category}')
        result.append({'label': category, 'options': contact_options(message, whatsapp=whatsapp, telegram=telegram)})
    return result
