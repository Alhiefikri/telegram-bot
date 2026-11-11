"""Authenticated preview/confirm HTTP path; no provider I/O in the request."""
from django.http import HttpResponseRedirect
from django.views.decorators.http import require_http_methods, require_POST

from ..admin import access, navigation
from ..admin.views import _flash_set, _page
from .policy import NotEligible
from .publishing import BroadcastPublishing


def _service():
    from .runtime import notification_service
    return notification_service()


def _render(request, service, *, draft=None, error=None, status=200, message='', photo_file_id='', product_id=''):
    rows = service.db.rows('''SELECT id,status,recipient_count,sent_count,failed_count,pending_count,created_at
        FROM public.telegram_broadcasts WHERE source='admin' ORDER BY id DESC LIMIT 25''')
    products = BroadcastPublishing(service).product_options() if draft is None else ()
    for product in products:
        product['selected'] = str(product['id']) == str(product_id)
    return _page(request, 'admin/broadcasts.html', 'Broadcast', navigation.navigation(active='broadcast'),
                 status=status, broadcasts=rows, draft=draft, error=error, message=message,
                 photo_file_id=photo_file_id, product_id=product_id, products=products,
                 enabled=bool(service.recipients.telegram))


@access.guard
@require_http_methods(['GET', 'POST'])
def index(request):
    service = _service()
    draft, error = None, None
    if request.method == 'POST':
        if not service.recipients.telegram:
            error = 'Broadcast dinonaktifkan. Belum ada penerima uji yang diizinkan.'
        else:
            try:
                draft = BroadcastPublishing(service).draft(
                    request.POST.get('message'), request.POST.get('photo_file_id', ''),
                    request.POST.get('product_id', ''))
                request.auth_session.set('admin_broadcast_draft', draft)
            except ValueError as exc:
                error = str(exc)
    return _render(request, service, draft=draft, error=error, status=422 if error else 200,
                   message=request.POST.get('message', ''), photo_file_id=request.POST.get('photo_file_id', ''),
                   product_id=request.POST.get('product_id', ''))


@access.guard
@require_POST
def publish(request):
    service = _service()
    draft = request.auth_session.data.get('admin_broadcast_draft')
    try:
        broadcast_id = BroadcastPublishing(service).publish(draft, request.POST.get('confirmation'))
    except NotEligible:
        return _render(request, service, error='Broadcast dinonaktifkan. Belum ada penerima uji yang diizinkan.', status=409)
    except ValueError as exc:
        return _render(request, service, error=str(exc), status=422)
    # Keep the server-owned key through refresh/replay: unique broadcast_key is the idempotency authority.
    _flash_set(request, f'Broadcast #{broadcast_id} masuk antrean. Progres bisa dipantau di tab Broadcast.')
    return HttpResponseRedirect('/admin/broadcast/')
