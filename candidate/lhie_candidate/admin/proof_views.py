"""Explicit admin Fake Sales Proof action; no direct Telegram or inventory writes."""
from uuid import uuid4

from django.views.decorators.http import require_http_methods

from ..checkout.errors import CheckoutError
from ..navigation.repository import _PRODUCT_SELECT
from ..notifications.fake_proof import FakeProofPublication
from ..notifications.policy import NotEligible
from ..notifications.runtime import notification_service
from . import access, navigation
from .operations_views import _fail, _ok
from .views import _page


@require_http_methods(['GET', 'POST'])
@access.guard
def fake_sales_proof(request):
    target = '/admin/store-channel/fake-sales-proof'
    service = notification_service()
    publisher = FakeProofPublication(service)
    if request.method == 'POST':
        token = request.POST.get('request_id')
        if token not in request.session.get('fake_proof_requests', []):
            return _fail(request, CheckoutError('fake_sales_proof_invalid'), target)
        try:
            publisher.manual(request.POST.get('product_id'), request.admin_user['id'], token,
                             request.META.get('REMOTE_ADDR'))
        except CheckoutError as error:
            return _fail(request, error, target)
        return _ok(request, 'Info pembelian fake disiapkan untuk Telegram Channel. Tombol manual bisa dipakai lagi kapan saja.', target)
    token = str(uuid4())
    request.auth_session.set('fake_proof_requests', (request.auth_session.get('fake_proof_requests', []) + [token])[-8:])
    disabled_reason = None
    try:
        publisher._channel()
    except NotEligible:
        disabled_reason = 'Setup channel belum ready. Jalankan Test Channel sampai berhasil dan aktifkan Sales Proof Post dulu.'
    products = [p for p in service.db.rows(_PRODUCT_SELECT + ' ORDER BY p.name,p.id LIMIT 100') if int(p['available_stock']) > 0]
    if not products:
        disabled_reason = disabled_reason or 'Tidak ada produk aktif dengan stok tersedia untuk dipakai.'
    latest = service.db.one('''SELECT sent_at FROM public.telegram_outbound_effects
        WHERE effect_type='sales_proof.fake' AND effect_key LIKE 'fake-sales-proof:manual:%%'
          AND state='sent' ORDER BY sent_at DESC LIMIT 1''')
    return _page(request, 'admin/fake_sales_proof.html', 'Fake Sales Proof',
                 navigation.navigation('pengaturan', 'settings'), products=products,
                 disabled_reason=disabled_reason, request_id=token,
                 manual_last_sent=latest['sent_at'] if latest else None)
