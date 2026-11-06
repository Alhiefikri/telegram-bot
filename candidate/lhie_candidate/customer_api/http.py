"""Bearer-only Customer API boundary and legacy v1 HTTP routes."""
import json
import uuid

from django.http import JsonResponse
from django.views.decorators.csrf import csrf_exempt

from lhie_candidate import runtime
from lhie_candidate.compat.crypto import LaravelEncrypter
from . import reads, usage
from .admission import Admission, ApiFailure, check_availability
from .credentials import authenticate
from .delivery import delivery


def _invalid():
    return ApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.')


def _query(request, allowed, body):
    if body or set(request.GET) - set(allowed) or any(len(request.GET.getlist(key)) != 1 for key in request.GET):
        raise _invalid()
    return {key: request.GET[key] for key in request.GET}


def _dispatch(request, route, principal, body, request_id, supplier_encrypter):
    method = request.method
    if method == 'HEAD':
        method = 'GET'
    parts = route.strip('/').split('/') if route.strip('/') else []
    if parts == ['balance'] and method == 'GET':
        _query(request, (), body)
        return reads.balance(principal), 200
    if parts == ['products'] and method == 'GET':
        return reads.products(principal, _query(request, ('limit', 'cursor', 'quantity', 'category_id', 'family_id', 'available'), body)), 200
    if len(parts) == 2 and parts[0] == 'products' and method == 'GET':
        return reads.product(principal, parts[1], _query(request, ('quantity', 'currency'), body)), 200
    if parts == ['orders'] and method == 'GET':
        return reads.orders(principal, _query(request, ('limit', 'cursor', 'status'), body)), 200
    if len(parts) == 2 and parts[0] == 'orders' and method == 'GET':
        _query(request, (), body)
        return reads.order_detail(principal, parts[1]), 200
    if len(parts) == 3 and parts[0] == 'orders' and parts[2] == 'delivery' and method == 'GET':
        query = _query(request, ('limit', 'cursor'), body)
        limit = query.get('limit', '100')
        if not isinstance(limit, str) or not limit.isascii() or not limit.isdigit() or limit.startswith('0') or len(limit) > 3 or not 1 <= int(limit) <= 100:
            raise _invalid()
        base_url = request.build_absolute_uri('/').rstrip('/')
        return delivery(principal, parts[1], LaravelEncrypter(runtime.application_key()), limit=int(limit), cursor=query.get('cursor'), base_url=base_url), 200
    if parts == ['orders'] and method == 'POST':
        if request.GET:
            raise _invalid()
        from .orders import OrderRequest, create_order
        try:
            data = json.loads(body)
        except (ValueError, UnicodeError):
            raise ApiFailure(422, 'invalid_input', 'The request body must be a JSON object.') from None
        order_request = OrderRequest.parse(request.headers.get('Idempotency-Key'), data)
        result = create_order(principal, order_request, request_id, supplier_encrypter=supplier_encrypter)
        return result, 200 if result['idempotent_replay'] else 201
    if ((parts in (['balance'], ['products'], ['orders']) or
         (len(parts) == 2 and parts[0] in ('products', 'orders')) or
         (len(parts) == 3 and parts[0] == 'orders' and parts[2] == 'delivery'))):
        raise ApiFailure(405, 'method_not_allowed', 'The API request could not be completed.')
    raise ApiFailure(404, 'not_found', 'Resource not found.')


@csrf_exempt
def endpoint(request, route='', *, supplier_encrypter=None):
    request_id = str(uuid.uuid4())
    principal = None
    token = ''
    headers = {}
    admission = None
    try:
        admission = Admission(runtime.application_key())
        ip = request.META.get('REMOTE_ADDR') or 'unknown'
        admission.ip(ip)
        check_availability()
        try:
            length = int(request.META.get('CONTENT_LENGTH') or '0')
        except ValueError:
            raise _invalid() from None
        if length < 0:
            raise _invalid()
        if length > 32768:
            raise ApiFailure(413, 'payload_too_large', 'The maximum request body is 32 KiB.')
        body = request.read(32769)
        if len(body) > 32768:
            raise ApiFailure(413, 'payload_too_large', 'The maximum request body is 32 KiB.')
        authorization = request.META.pop('HTTP_AUTHORIZATION', None)
        principal = authenticate(authorization)
        del authorization
        if principal is None:
            admission.invalid(ip)
            raise ApiFailure(401, 'unauthenticated', 'A valid API key is required.', {'WWW-Authenticate': 'Bearer'})
        if 'HTTP_ORIGIN' in request.META:
            raise ApiFailure(403, 'backend_only', 'Customer API keys are for server-to-server requests only.')
        is_mutation = request.method not in ('GET', 'HEAD', 'OPTIONS')
        is_delivery = len(route.strip('/').split('/')) == 3 and route.strip('/').split('/')[0] == 'orders' and route.strip('/').split('/')[-1] == 'delivery'
        reservation = admission.authenticated(principal, is_mutation, is_delivery)
        token, headers = reservation['token'], reservation['headers']
        usage.record(admission, principal)
        payload, status = _dispatch(request, route, principal, body, request_id, supplier_encrypter)
        response = JsonResponse(payload, status=status, json_dumps_params={'ensure_ascii': True, 'separators': (',', ':')})
        if is_delivery and len(response.content) > 1_048_576:
            raise ApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.')
    except ApiFailure as error:
        payload = {'code': error.code, 'message': str(error), 'request_id': request_id}
        payload.update(getattr(error, 'details', {}) or {})
        headers = error.headers
        response = JsonResponse({'error': payload}, status=error.status)
    except Exception:
        response = JsonResponse({'error': {'code': 'temporarily_unavailable', 'message': 'The API request could not be completed.', 'request_id': request_id}}, status=503)
        headers = {'Retry-After': '5'}
    finally:
        request.META.pop('HTTP_AUTHORIZATION', None)
        if principal is not None and admission is not None:
            admission.release(principal, token)
    for key, value in headers.items():
        response[key] = value
    response['X-Request-ID'] = request_id
    response['Cache-Control'] = 'no-store, private'
    response['Referrer-Policy'] = 'no-referrer'
    return response
