"""Pure Store Bot command classification.

The router keeps command aliases in one place so the processor can stay a
thin orchestration layer. Nothing in this module reads a database, a session
or a Telegram payload; callers pass the already-minimized message text.
"""
from dataclasses import dataclass
import re


SUPPORT_COMMANDS = frozenset(('/', '/bantuan')) | {'💬 Support', '💬 Sokongan'}
ACCOUNT_COMMANDS = frozenset({'/akun', '/akaun', '/account'}) | {
    '👤 Akun Saya', '👤 My Account', '👤 Akaun Saya'}
ORDER_STATUS_COMMANDS = frozenset({'/status', '/pesanan', '/orders'}) | {
    '📦 Pesanan', '📦 Orders'}
ORDER_HISTORY_COMMANDS = frozenset({'/riwayat', '/sejarah', '/history'})
CATALOG_COMMANDS = frozenset({
    '/produk', '/product', '/products', '/lihat_produk', '/lihat produk',
    '/katalog', '🛍️ Lihat Produk', '🛍️ Browse Products'})
LEGACY_CATALOG_LABELS = frozenset({'🛍️ Lihat Produk', '🛍️ Browse Products'})
LANGUAGE_COMMANDS = frozenset({'/language', '/bahasa'}) | {
    '🌐 Language', '🌐 Bahasa'}
MENU_COMMANDS = frozenset({'/menu'})
ADMIN_DASHBOARD_LABELS = frozenset({'🛠️ Admin Dashboard'})
ADMIN_STATS_LABELS = frozenset({'📊 Statistik', '📊 Statistik Harian'})
ADMIN_STOCK_LABELS = frozenset({'📦 Cek Stok', '/stok'})
BROADCAST_INFO_LABEL = '📣 Broadcast Info'

_SEARCH = re.compile(r'^/(?:cari|carian|search)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$', re.I)
_START = re.compile(r'^/start(?:@[A-Za-z0-9_]+)?(?:\s+(product|family)_([1-9][0-9]*))?$', re.I)
_STOCK = re.compile(r'^/stock(?:@[A-Za-z0-9_]{1,32})?$', re.I)
_ADMIN = re.compile(r'^/admin(?:@[A-Za-z0-9_]+)?$', re.I)
_ORDER = re.compile(r'^/order(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$', re.I)
_CANCEL = re.compile(r'^/cancel(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$', re.I)
_PAYMENT = re.compile(r'^/payment(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$', re.I)
_BALAT = re.compile(r'^/batal(?:@[A-Za-z0-9_]+)?$', re.I)

# A closed set of intents may only run in a one-to-one Store Bot chat. The
# router additionally requires the canonical Customer/chat binding.
_PRIVATE_ONLY = frozenset({
    'admin', 'admin_dashboard', 'admin_stats', 'admin_stock', 'account',
    'cancel', 'payment', 'order_status', 'order_history', 'order_detail',
    'batal', 'deposit',
})


@dataclass(frozen=True, slots=True)
class Intent:
    kind: str
    value: object = None


def _command(text):
    return text.strip().split('@', 1)[0].lower() if text.strip().startswith('/') else None


def message_intent(text):
    """Map one message text to the command that owns it.

    State-driven inputs (custom quantity, payment hints and USDT amount/hash
    prompts) are deliberately not commands; the processor checks those before
    falling back to this classifier.
    """
    raw = (text or '').strip()
    if not raw:
        return Intent('unsupported')
    start = _START.fullmatch(raw)
    if start:
        if start[1] is None:
            return Intent('start')
        return Intent('start', (start[1].lower(), int(start[2])))
    command = _command(raw)
    if command:
        if raw.lower().startswith('/start'):
            return Intent('start')
        if command == '/menu':
            return Intent('menu')
        if command in ('/language', '/bahasa'):
            return Intent('language')
        if command in ('/support', '/bantuan'):
            return Intent('support')
        if command in ('/akun', '/akaun', '/account'):
            return Intent('account')
        if command in ('/status', '/pesanan', '/orders'):
            return Intent('order_status')
        if command in ('/riwayat', '/sejarah', '/history'):
            return Intent('order_history')
        if command in ('/produk', '/product', '/products', '/lihat_produk',
                       '/lihat produk', '/katalog'):
            return Intent('catalog', {'legacy_keyboard': False})
        if _ADMIN.fullmatch(raw):
            return Intent('admin')
        if raw.lower() in ADMIN_STOCK_LABELS or _STOCK.fullmatch(raw):
            return Intent('admin_stock' if raw.lower() in ADMIN_STOCK_LABELS else 'stock')
        search = _SEARCH.fullmatch(raw)
        if search:
            return Intent('search', (search[1] or '').strip())
        cancel = _CANCEL.fullmatch(raw)
        if cancel:
            return Intent('cancel', (cancel[1] or '').strip())
        batal = _BALAT.fullmatch(raw)
        if batal:
            return Intent('batal')
        payment = _PAYMENT.fullmatch(raw)
        if payment:
            return Intent('payment', (payment[1] or '').strip())
        order = _ORDER.fullmatch(raw)
        if order:
            return Intent('order_detail', (order[1] or '').strip())
        if command.startswith('/deposit'):
            return Intent('deposit')
        return Intent('unsupported')
    if raw in LANGUAGE_COMMANDS:
        return Intent('language')
    if raw in SUPPORT_COMMANDS:
        return Intent('support')
    if raw in ACCOUNT_COMMANDS:
        return Intent('account')
    if raw in ORDER_STATUS_COMMANDS:
        return Intent('order_status')
    if raw in ORDER_HISTORY_COMMANDS:
        return Intent('order_history')
    if raw in CATALOG_COMMANDS:
        return Intent('catalog', {'legacy_keyboard': raw in LEGACY_CATALOG_LABELS})
    if raw == '☰ Menu':
        return Intent('menu')
    if raw in ADMIN_DASHBOARD_LABELS:
        return Intent('admin_dashboard')
    if raw in ADMIN_STATS_LABELS:
        return Intent('admin_stats')
    if raw in ADMIN_STOCK_LABELS:
        return Intent('admin_stock')
    if raw == BROADCAST_INFO_LABEL:
        return Intent('broadcast_info')
    return Intent('unsupported')


def private_only(intent):
    return intent.kind in _PRIVATE_ONLY


def is_private_only_text(text):
    """Cheap pre-check used before a Customer has been resolved."""
    return private_only(message_intent(text))
