"""Public renderers for the Customer API documentation contract."""

import json

from django.http import HttpResponse
from django.shortcuts import render
from django.urls import reverse
from django.views.decorators.http import require_GET

from lhie_candidate.customer_api import documentation


_CACHE = 'public, max-age=3600'


def _origin(request):
    return request.build_absolute_uri('/').rstrip('/')


@require_GET
def index(request):
    origin = _origin(request)
    canonical = request.build_absolute_uri(reverse('storefront-api-docs'))
    context = {
        'title': 'API Supplier Lhie Store',
        'description': 'Referensi API Customer untuk membaca katalog dan saldo serta membuat pesanan server ke server.',
        'api_base_url': origin + documentation.API_PREFIX,
        'canonical': canonical,
        'robots': 'index, follow',
        'og_image': request.build_absolute_uri('/static/storefront/lhie-store-logo.webp'),
        'operations': tuple({
            **operation,
            'parameters': tuple({
                **parameter,
                'location': parameter['in'],
                'schema_text': json.dumps(parameter['schema'], ensure_ascii=False, sort_keys=True),
            } for parameter in operation['parameters']),
            'error_rows': tuple((code, documentation.ERRORS[code][0]) for code in operation['errors']),
        } for operation in documentation.OPERATIONS),
        'schema_rows': tuple((name, json.dumps(schema, ensure_ascii=False, indent=2, sort_keys=True))
                             for name, schema in documentation.SCHEMAS.items()),
        'error_rows': tuple(documentation.ERRORS.items()),
    }
    response = render(request, 'storefront/api_docs.html', context)
    response['Cache-Control'] = _CACHE
    return response


@require_GET
def openapi(request):
    response = HttpResponse(documentation.openapi_json(_origin(request)), content_type='application/json; charset=utf-8')
    response['Cache-Control'] = _CACHE
    return response


@require_GET
def reference(request):
    response = HttpResponse(documentation.reference_markdown(_origin(request)), content_type='text/markdown; charset=utf-8')
    response['Cache-Control'] = _CACHE
    return response
