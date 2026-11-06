"""Browser-facing customer authentication backed by the P19 services."""
from functools import wraps

from django.http import HttpResponse, HttpResponseRedirect
from django.shortcuts import render

from ..auth import http as auth
from ..auth.sessions import customer
from ..auth.types import AuthError
from ..checkout.db import Database
from . import account


def private(response):
    response['Cache-Control'] = 'no-store, private'
    response['Referrer-Policy'] = 'same-origin'
    response['X-Robots-Tag'] = 'noindex, nofollow'
    return response


def redirect(path):
    response = private(HttpResponseRedirect(path))
    response['Referrer-Policy'] = 'no-referrer'
    return response


def render_auth(request, **context):
    return private(render(request, 'storefront/auth.html', context))


def guard(view):
    @wraps(view)
    def wrapped(request, *args, **kwargs):
        try:
            return view(request, *args, **kwargs)
        except AuthError as exc:
            if exc.code == 'authentication_required':
                return redirect('/akun')
            target = '/akun/keamanan' if customer(request, required=False) else '/akun'
            messages = {
                'collision': 'Google belum dihubungkan. Masuk dengan metode akun yang sudah ada, lalu hubungkan Google dari Keamanan akun.',
                'conflict': 'Identitas sudah digunakan. Tidak ada akun atau pesanan yang dipindahkan.',
                'last_method': 'Google tidak dapat dilepas sebelum metode masuk lain yang terverifikasi tersedia.',
                'unavailable': 'Metode ini belum dapat digunakan. Coba lagi nanti atau gunakan metode email.',
            }
            request.auth_session.set('auth_error', messages.get(exc.code, 'Akses tidak dapat dikonfirmasi. Coba masuk atau minta instruksi baru.'))
            return redirect(target)
    return wrapped


def only(request, *methods):
    if request.method not in methods and not (request.method == 'HEAD' and 'GET' in methods):
        response = HttpResponse(status=405)
        response['Allow'] = ', '.join(methods)
        return private(response)
    return None


def readiness(bundle):
    enabled = bundle.core.enabled()
    return {'auth_enabled': enabled,
            'google_ready': enabled and auth.google_service(bundle).ready(),
            'telegram_ready': enabled and auth.telegram_service(bundle).ready()}


def security_context(request, actor):
    context = readiness(auth.services())
    context['google_linked'] = bool(Database().one(
        "SELECT id FROM public.customer_identities WHERE customer_id=%s AND provider='google'", (actor['id'],)))
    context['telegram_linked'] = actor['telegram_id'] is not None
    context['auth_error'] = request.auth_session.get('auth_error')
    context['auth_notice'] = request.auth_session.get('auth_notice')
    request.auth_session.discard('auth_error')
    request.auth_session.discard('auth_notice')
    return context


@guard
def landing(request):
    if response := only(request, 'GET'):
        return response
    actor = customer(request, required=False)
    if actor:
        return account.page(request, 'Akun saya', actor=actor, screen='dashboard')
    context = readiness(auth.services())
    view = request.GET.get('view', 'login')
    if view not in ('login', 'register', 'code', 'reset', 'magic', 'verification'):
        view = 'login'
    if not context['auth_enabled']:
        view = 'magic'
    context.update(view=view, otp_pending=bool(request.auth_session.get('otp')) and not request.GET.get('new'),
                   error=request.auth_session.get('auth_error'), notice=request.auth_session.get('auth_notice'))
    request.auth_session.discard('auth_error')
    request.auth_session.discard('auth_notice')
    return render_auth(request, **context)


@guard
def action(request, action):
    if response := only(request, 'POST'):
        return response
    if action in ('password', 'email', 'logout'):
        customer(request)
    result = auth.auth_action(request, action=action)
    if result.status_code >= 400:
        request.auth_session.set('auth_error', 'Akses tidak dapat dikonfirmasi. Coba masuk atau minta instruksi baru.')
        return redirect('/akun/keamanan' if action in ('password', 'email') else '/akun')
    if action in ('login', 'magic-consume', 'otp-consume', 'verify'):
        return redirect('/akun/pesanan')
    if action == 'logout':
        return redirect('/akun')
    if action == 'password':
        request.auth_session.set('auth_notice', 'Password akun telah disimpan.')
        return redirect('/akun/keamanan')
    if action == 'email':
        request.auth_session.set('auth_notice', 'Jika email dapat digunakan, instruksi telah dikirim. Periksa inbox Anda.')
        return redirect('/akun/keamanan')
    request.auth_session.set('auth_notice', 'Jika email dapat digunakan, instruksi telah dikirim. Periksa inbox Anda.')
    if action == 'reset':
        request.auth_session.set('auth_notice', 'Password diperbarui. Silakan masuk kembali.')
        return redirect('/akun')
    return redirect('/akun?view=' + {'register': 'verification', 'otp-request': 'code',
                                    'reset-request': 'reset', 'magic-request': 'magic'}.get(action, 'login'))


def token(request, action, token=None):
    if request.method in ('GET', 'HEAD'):
        if token is not None:
            return redirect('/akun/password-reset/confirm')
        return private(auth.token_form(request, action=action))
    return action_view(request, 'magic-request' if action == 'magic-consume' else action)


def reset_request(request):
    if request.method in ('GET', 'HEAD'):
        return redirect('/akun?view=reset')
    return action(request, 'reset-request')


def action_view(request, name):
    return action(request, name)


@guard
def google(request, operation):
    allowed = ('POST',) if operation in ('link', 'unlink') else ('GET',)
    if response := only(request, *allowed):
        return response
    if operation in ('link', 'unlink'):
        customer(request)
    session, bundle = request.auth_session, auth.services()
    service, browser = auth.google_service(bundle), session.browser(request)
    if operation == 'callback':
        state = session.get('google') or {}
        session.discard('google')
        state_value, code, error = request.GET.get('state', ''), request.GET.get('code', ''), request.GET.get('error')
        request.GET = request.GET.copy()
        request.GET.clear()
        request.META['QUERY_STRING'] = ''
        request.META['REQUEST_URI'] = '/auth/google/callback'
        result = service.callback(browser, state_value, code, state.get('nonce', ''), error=error)
        session.authenticate(request, result)
        return redirect('/akun/keamanan' if state.get('operation') == 'link' else '/akun/pesanan')
    if operation == 'unlink':
        session.authenticate(request, service.unlink(browser))
        request.auth_session.set('auth_notice', 'Akun Google telah dilepaskan.')
        return redirect('/akun/keamanan')
    kind = 'link' if operation == 'link' else request.GET.get('operation', 'login')
    if kind not in ('login', 'register') and operation != 'link':
        raise AuthError()
    result = service.start(browser, kind)
    session.set('google', {'nonce': result['nonce'], 'operation': kind})
    return redirect(result['authorization_url'])


@guard
def telegram(request, operation):
    if response := only(request, 'GET' if operation == 'status' else 'POST'):
        return response
    if operation == 'link':
        customer(request)
    session, bundle = request.auth_session, auth.services()
    service, browser = auth.telegram_service(bundle), session.browser(request)
    if operation in ('login', 'link'):
        result = service.start(browser, link=operation == 'link')
        session.set('telegram', {'claim_id': result['claim_id'], 'nonce': result['nonce'], 'operation': operation})
        response = render_auth(request, view='telegram', deep_link=result['deep_link'], telegram_status='pending')
        response['Referrer-Policy'] = 'no-referrer'
        return response
    state = session.get('telegram') or {}
    if operation == 'status':
        status = service.status(browser, state.get('claim_id', ''), state.get('nonce', ''))
        return render_auth(request, view='telegram', telegram_status=status)
    if operation == 'consume':
        result = service.consume(browser, state.get('claim_id', ''), state.get('nonce', ''))
        session.authenticate(request, result)
        return redirect('/akun/keamanan' if state.get('operation') == 'link' else '/akun/pesanan')
    raise AuthError('not_found', 404)
