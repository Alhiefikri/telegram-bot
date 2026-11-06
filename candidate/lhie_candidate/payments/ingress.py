"""Provider-specific authenticity precedes durable hints; hints never settle money."""
import hashlib
import json

from django.conf import settings

from ..auth.limits import RateLimits
from ..auth.types import AuthError
from ..http import response
from ..workers import wakeup
from .runtime import payment_services


def _body(request):
    if request.method != 'POST':
        raise ValueError('method')
    length = request.headers.get('Content-Length', '')
    if (not length.isascii() or not length.isdigit() or not 0 < int(length) <= 65536
            or request.headers.get('Transfer-Encoding') or request.content_type != 'application/json'):
        raise ValueError('body')
    body = request.body
    if len(body) != int(length):
        raise ValueError('body')
    return body


def _event(db, *, provider, merchant_ref, reference, status, evidence):
    canonical = json.dumps(evidence, sort_keys=True, ensure_ascii=True, separators=(',', ':'))
    digest = hashlib.sha256((provider + '|callback|' + canonical).encode()).hexdigest()
    row = db.one('''INSERT INTO public.payment_events
        (provider,event_type,merchant_ref,provider_reference,provider_status,raw_payload,idempotency_key,
         received_at,last_received_at,received_count)
        VALUES (%s,'callback',%s,%s,%s,%s::jsonb,%s,now(),now(),1)
        ON CONFLICT (provider,event_type,idempotency_key) DO UPDATE SET
        last_received_at=now(),received_count=public.payment_events.received_count+1 RETURNING id''',
        (provider, merchant_ref, reference, status, canonical, digest))
    wakeup.enqueue('payment.event', row['id'])
    return response(200, 'accepted')


def receive_klikqris(request, *, environment='production', payments=None, limits=None, funding_klik=None):
    if request.method != 'POST':
        return response(405, 'method_not_allowed')
    try:
        body = _body(request)
        rails = payments if payments is not None else payment_services()
        lifecycle = rails.lifecycles.get('klikqris')
        if lifecycle is None and funding_klik is None:
            return response(503, 'unavailable')
        if lifecycle is not None and lifecycle.adapter.config.environment != environment:
            return response(503, 'unavailable')
        from .klikqris import callback_identity, authenticate_callback
        ref = callback_identity(body)
        (limits or RateLimits(settings.SECRET_KEY)).reserve([
            ('klikqris-callback', str(request.META.get('REMOTE_ADDR', '')), 120, 60)])
        payment = rails.db.one('''SELECT provider,merchant_ref,amount,provider_total_payment,
            provider_reference,klikqris_transaction_signature FROM public.payments
            WHERE provider='klikqris' AND merchant_ref=%s''', (ref,))
        if payment is None:
            if funding_klik is None:
                return response(404, 'not_found')
            event_id = funding_klik.receive_callback(body, environment=environment)
            if event_id is None:
                return response(404, 'not_found')
            wakeup.enqueue('funding.klikqris', event_id)
            return response(200, 'accepted')
        if lifecycle is None:
            return response(503, 'unavailable')
        payment['klikqris_environment'] = lifecycle.adapter.config.environment
        facts = authenticate_callback(body, environment=environment, payment=payment, encrypter=lifecycle.encrypter)
        return _event(rails.db, provider='klikqris', merchant_ref=ref,
                      reference=facts['provider_reference'] or payment['provider_reference'] or ref,
                      status=facts['status'], evidence=facts)
    except AuthError as error:
        return response(error.status, error.code)
    except (ValueError, TypeError, UnicodeError, RecursionError):
        return response(403, 'forbidden')
    except Exception:
        return response(503, 'unavailable')


def receive_klikqris_sandbox(request):
    return receive_klikqris(request, environment='sandbox')


def receive_pakasir(request, *, payments=None, limits=None):
    if request.method != 'POST':
        return response(405, 'method_not_allowed')
    try:
        body = _body(request)
        rails = payments if payments is not None else payment_services()
        lifecycle = rails.lifecycles.get('pakasir')
        if lifecycle is None:
            return response(503, 'unavailable')
        (limits or RateLimits(settings.SECRET_KEY)).reserve([
            ('pakasir-callback', str(request.META.get('REMOTE_ADDR', '')), 120, 60)])
        from .pakasir import parse_callback
        hint = parse_callback(body, project=lifecycle.adapter.project)
        payment = rails.db.one('''SELECT id,provider_reference FROM public.payments
            WHERE provider='pakasir' AND merchant_ref=%s AND amount=%s AND currency='IDR' ''',
            (hint.merchant_ref, hint.transaction.amount))
        if payment is None:
            return response(404, 'not_found')
        # Pakasir callbacks have no signature contract. Only authenticated detail can confirm them.
        return _event(rails.db, provider='pakasir', merchant_ref=hint.merchant_ref,
                      reference=payment['provider_reference'] or hint.merchant_ref,
                      status=hint.provider_status, evidence=hint.evidence)
    except AuthError as error:
        return response(error.status, error.code)
    except (ValueError, TypeError, UnicodeError, RecursionError):
        return response(400, 'bad_request')
    except Exception:
        return response(503, 'unavailable')


def receive_sakurupiah_production(request, *, payments=None, limits=None):
    if request.method != 'POST':
        return response(405, 'method_not_allowed')
    try:
        body = _body(request)
        rails = payments if payments is not None else payment_services()
        lifecycle = rails.lifecycles.get('sakurupiah')
        if lifecycle is None or getattr(lifecycle.adapter, 'environment', 'sandbox') != 'production':
            return response(503, 'unavailable')
        if (request.headers.get('X-Callback-Event') != 'payment_status'
                or not lifecycle.adapter.authenticate_callback(body, request.headers.get('X-Callback-Signature'))):
            return response(403, 'forbidden')
        (limits or RateLimits(settings.SECRET_KEY)).reserve([
            ('sakurupiah-callback', str(request.META.get('REMOTE_ADDR', '')), 120, 60)])
        from .callback import _callback
        merchant_ref, reference, status, code = _callback(body)
        return _event(rails.db, provider='sakurupiah', merchant_ref=merchant_ref, reference=reference,
                      status=status, evidence={'merchant_ref': merchant_ref, 'trx_id': reference,
                                               'status': status, 'status_kode': code, 'environment': 'production'})
    except AuthError as error:
        return response(error.status, error.code)
    except (ValueError, TypeError, UnicodeError, RecursionError):
        return response(400, 'bad_request')
    except Exception:
        return response(503, 'unavailable')
