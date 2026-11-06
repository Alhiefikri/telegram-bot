import json
from functools import cmp_to_key
from datetime import datetime
from pathlib import Path
from urllib.parse import quote, urlencode, urlsplit
from xml.sax.saxutils import escape
from zoneinfo import ZoneInfo

from django.core.paginator import Paginator
from django.http import Http404, HttpResponse, HttpResponseRedirect, JsonResponse
from django.shortcuts import render
from django.views.decorators.http import require_GET

from lhie_candidate.auth.limits import RateLimits
from lhie_candidate.auth.types import AuthError
from lhie_candidate.compat.identity import application_hmac
from lhie_candidate.runtime import application_key
from lhie_candidate.checkout.errors import CheckoutError
from lhie_candidate.checkout.pricing import PricingSnapshot
from lhie_candidate.navigation.repository import CatalogRepository
from . import public_media
from . import guide_content
from .support import general_choices, product_choices


def _render(request, template, context):
    context.setdefault('description', 'Lhie Store menyediakan produk digital dan akun premium dengan stok transparan, harga jelas, checkout QRIS, serta akses pesanan mandiri.')
    context.setdefault('canonical', request.build_absolute_uri(request.path))
    context.setdefault('og_image', request.build_absolute_uri('/static/storefront/lhie-store-logo.webp'))
    context.setdefault('robots', 'noindex, follow' if request.GET.get('q') else 'index, follow')
    return render(request, template, context)




def _image(path):
    if not path or not isinstance(path, str) or path.startswith('//') or '\\' in path:
        return None
    path = path.split('?', 1)[0].split('#', 1)[0]
    if any(part in ('.', '..') for part in path.split('/')):
        return None
    if path.startswith('/'):
        return path
    if ':' in path:
        return None
    tracked = Path(__file__).resolve().parents[2] / 'static' / 'storefront' / 'products' / Path(path).name
    if tracked.is_file():
        return '/static/storefront/products/' + quote(tracked.name)
    return '/storage/' + quote(path, safe='/')


def _banners(repository):
    row = repository._one("SELECT homepage_banners FROM public.storefront_settings WHERE singleton_key='storefront'") or {}
    banners = row.get('homepage_banners') or []
    if isinstance(banners, str):
        banners = json.loads(banners)
    result = []
    for banner in banners if isinstance(banners, list) else []:
        if not isinstance(banner, dict):
            continue
        image = _image(banner.get('path'))
        if not image:
            continue
        link = banner.get('link')
        if (not isinstance(link, str) or len(link) > 2048 or not link.startswith('/')
                or link.startswith('//') or '\\' in link or any(ord(char) <= 32 for char in link)):
            link = None
        width = height = None
        try:
            stream, _, (width, height) = public_media.open_image(banner['path'])
            stream.close()
        except Http404:
            pass
        result.append({'image': image, 'title': banner.get('title') or 'Banner toko', 'link': link,
                       'width': width, 'height': height})
    return result


def _banner_aspect(banners):
    for banner in banners:
        width, height = banner['width'], banner['height']
        if not width or not height:
            continue
        if width * 9 < height * 16:
            height = max(1, (width * 9 + 8) // 16)
        elif width > height * 4:
            height = max(1, (width + 2) // 4)
        return f'{width} / {height}'
    return '4 / 1'

def _support(repository):
    setting = repository._one("""SELECT whatsapp_support_url,whatsapp_support_number
        FROM public.manual_payment_settings WHERE is_active=TRUE ORDER BY id LIMIT 1""") or {}
    url = (setting.get('whatsapp_support_url') or '').strip()
    parsed = urlsplit(url)
    if parsed.scheme != 'https' or parsed.hostname not in ('wa.me', 'api.whatsapp.com') or parsed.username or parsed.password:
        number = ''.join(c for c in (setting.get('whatsapp_support_number') or '') if c.isascii() and c.isdigit())
        if number.startswith('0'):
            number = '62' + number[1:]
        url = 'https://wa.me/' + number if number else ''
    return url

def _support_channels(repository):
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.receipt.source import ReceiptSource
    return {'whatsapp': _support(repository),
            'telegram': ReceiptSource(LaravelEncrypter(application_key())).support_url()}


def _activity(repository):
    rows = repository._rows("""
        SELECT o.id AS order_id,
               COALESCE((SELECT MIN(m.created_at) FROM public.inventory_movements m
                         WHERE m.order_id=o.id AND m.type='sale'),o.created_at) AS occurred_at,
               CASE WHEN COALESCE(TRIM(c.first_name),'')='' THEN 'Pembeli terverifikasi'
                    ELSE LEFT(TRIM(c.first_name),1) || '***' END AS buyer,
               (SELECT i.product_name FROM public.order_items i WHERE i.order_id=o.id ORDER BY i.id LIMIT 1) AS product
        FROM public.orders o LEFT JOIN public.customers c ON c.id=o.customer_id
        WHERE o.payment_status='paid' AND o.order_status IN ('processing','completed')
          AND EXISTS (SELECT 1 FROM public.order_items i WHERE i.order_id=o.id)
          AND NOT EXISTS (SELECT 1 FROM public.supplier_fulfillments sf
                          WHERE sf.order_id=o.id AND sf.state != 'delivered')
          AND NOT EXISTS (SELECT 1 FROM public.order_items i WHERE i.order_id=o.id
                          AND (SELECT m.quantity FROM public.inventory_movements m
                               WHERE m.order_id=o.id AND m.product_id=i.product_id
                                 AND m.type='sale' ORDER BY m.id LIMIT 1) IS DISTINCT FROM -i.quantity)
        ORDER BY o.updated_at DESC LIMIT 8
    """)
    now = datetime.now(ZoneInfo('Asia/Jakarta'))
    key = application_key()
    result = []
    for row in rows:
        occurred = row['occurred_at']
        if occurred.tzinfo is None:
            occurred = occurred.replace(tzinfo=ZoneInfo('Asia/Jakarta'))
        seconds = max(0, int((now - occurred).total_seconds()))
        if seconds < 60:
            relative = 'beberapa detik yang lalu'
        elif seconds < 3600:
            relative = f'{seconds // 60} menit yang lalu'
        elif seconds < 86400:
            relative = f'{seconds // 3600} jam yang lalu'
        else:
            relative = f'{seconds // 86400} hari yang lalu'
        result.append({'id': application_hmac(str(row['order_id']), key),
                       'buyer': row['buyer'], 'product': row['product'],
                       'occurred_at': occurred.isoformat(timespec='seconds'), 'relative_time': relative})
    return result


@require_GET
def purchase_activity(request):
    try:
        RateLimits(application_key()).reserve([
            ('storefront-activity-ip', request.META.get('REMOTE_ADDR') or 'unknown', 12, 60)])
    except AuthError as error:
        return JsonResponse({'status': error.code}, status=error.status)
    response = JsonResponse({'items': _activity(CatalogRepository())})
    response['Cache-Control'] = 'public, max-age=45'
    return response


def _presentation(repository, products):
    # Images are storefront presentation fields, not stock or pricing authorities.
    images = {row['id']: row['image_path'] for row in repository._rows(
        'SELECT id,image_path FROM public.products WHERE is_active=TRUE AND deleted_at IS NULL')}
    families = {row['id']: row for row in repository._rows(
        'SELECT id,image_path,created_at FROM public.product_families WHERE deleted_at IS NULL')}
    for product in products:
        family = families.get(product.get('product_family_id')) or {}
        product['image'] = _image(images.get(product['id']) or family.get('image_path'))
        if product.get('family'):
            product['family']['created_at'] = family.get('created_at')
        product['price_label'] = 'Rp' + format(product['price_amount'], ',').replace(',', '.')
    return products


def _entries(products):
    entries, groups = [], {}
    for product in products:
        family = product.get('family')
        if family and family.get('slug'):
            key = family['id']
            if key not in groups:
                entry = {'id': key, 'name': family['name'], 'family_name': family['name'], 'slug': family['slug'],
                         'sort_order': family.get('sort_order') or 0, 'description': family['description'],
                         'url': '/pilihan/' + quote(family['slug'], safe=''), 'members': [],
                         'created': family.get('created_at'),
                         'image': product['image'], 'icon': family.get('icon'), 'family': True}
                groups[key] = entry
                entries.append(entry)
            groups[key]['members'].append(product)
        else:
            entries.append({'id': product['id'], 'name': product['name'], 'description': product['description'],
                            'url': '/produk/' + str(product['id']), 'members': [product],
                            'image': product['image'], 'icon': product.get('icon')})
    for entry in entries:
        members = entry['members']
        if entry.get('family') and len(members) == 1:
            entry['name'] = members[0]['name']
        entry['price'] = min(p['price_amount'] for p in members)
        entry['price_label'] = 'Rp' + format(entry['price'], ',').replace(',', '.')
        entry['stock'] = sum(p['available_stock'] for p in members)
        entry['sold'] = sum(p['sold_quantity'] for p in members)
        entry['created'] = entry.get('created') if entry.get('family') else members[0]['created_at']
    return entries


def _query_url(request, **changes):
    params = request.GET.copy()
    for key, value in changes.items():
        if value is None:
            params.pop(key, None)
        else:
            params[key] = value
    return '?' + params.urlencode()


def _catalog(request, shop=False):
    search = ' '.join(request.GET.get('q', '').split())
    if len(search) > 80:
        return HttpResponse('Search query may not be greater than 80 characters.', status=422, content_type='text/plain')
    repository = CatalogRepository()
    products = _presentation(repository, repository.products())
    category = request.GET.get('category', '')[:255]
    sort = request.GET.get('sort', 'recommended')
    if sort not in ('recommended', 'newest', 'price_asc', 'price_desc'):
        sort = 'recommended'
    categories = repository.categories()
    if category == 'uncategorized':
        products = [p for p in products if p.get('category_id') is None]
    elif category and any(category in (str(c['id']), c['slug']) for c in categories):
        products = [p for p in products if p.get('category_slug') == category or str(p.get('category_id')) == category]
    else:
        category = ''
    entries = _entries(products)
    if search:
        needle = search.casefold()
        fields = ('name', 'description', 'description_ms', 'description_en', 'family_option_label',
                  'category_name', 'category_slug', 'product_type', 'access_duration', 'region',
                  'account_type', 'device_limit', 'included_items', 'limitations', 'fulfillment_expectation',
                  'warranty_policy', 'warranty_policy_ms', 'warranty_policy_en', 'support_policy')
        def matches(values):
            return any(needle in str(value or '').casefold() for value in values)
        entries = [e for e in entries if matches((e['name'], e.get('family_name'), e.get('slug'), e.get('description')))
                   or any(matches(p.get(field) for field in fields) for p in e['members'])]
    if sort == 'price_asc':
        entries.sort(key=lambda e: (e['price'], e['name'].casefold()))
    elif sort == 'price_desc':
        entries.sort(key=lambda e: (-e['price'], e['name'].casefold()))
    elif sort == 'newest':
        entries.sort(key=lambda e: (e['created'] is not None, e['created']), reverse=True)
    else:
        def compare(a, b):
            if a.get('family') and b.get('family') and a['sold'] != b['sold']:
                return -1 if a['sold'] > b['sold'] else 1
            def key(entry):
                return (entry['stock'] <= 0, not entry.get('family', False),
                        entry.get('sort_order', 2**63), entry['name'].casefold(), entry['id'])
            return (key(a) > key(b)) - (key(a) < key(b))
        entries.sort(key=cmp_to_key(compare))
    page = Paginator(entries, 24 if shop else max(1, len(entries))).get_page(request.GET.get('page', 1) if shop else 1)
    popular = sorted(
        (entry['members'][0] for entry in entries
         if not entry.get('family') and entry['stock'] > 0 and entry['sold'] > 0),
        key=lambda product: (-product['sold_quantity'], product['name'], product['id']),
    )[:3] if not shop else []
    for item in categories:
        params = {'category': item['slug']}
        if search:
            params['q'] = search
        item['url'] = _query_url(request, category=item['slug'], page=None) if shop else '/shop?' + urlencode(params)
    all_category_url = _query_url(request, category=None, page=None)
    canonical_params = {}
    if shop:
        if search:
            canonical_params['q'] = search
        if category:
            canonical_params['category'] = category
        if sort != 'recommended':
            canonical_params['sort'] = sort
        if page.number > 1:
            canonical_params['page'] = page.number
    canonical = request.build_absolute_uri('/shop' if shop else '/')
    if canonical_params:
        canonical += '?' + urlencode(canonical_params)
    banners = _banners(repository)
    return _render(request, 'storefront/catalog_list.html', {
        'title': 'Katalog produk digital siap pakai' if shop else 'Produk digital siap pakai, checkout tanpa ribet.',
        'canonical': canonical,
        'shop': shop, 'search': search, 'category': category, 'sort': sort,
        'categories': categories, 'page': page, 'banners': banners, 'banner_aspect': _banner_aspect(banners),
        'popular': popular, 'all_category_url': all_category_url,
        'support_options': general_choices(**_support_channels(repository)),
        'previous_url': _query_url(request, page=page.previous_page_number()) if page.has_previous() else None,
        'next_url': _query_url(request, page=page.next_page_number()) if page.has_next() else None,
    })


@require_GET
def home(request):
    return _catalog(request)


@require_GET
def shop(request):
    return _catalog(request, shop=True)


@require_GET
def family(request, slug):
    repository = CatalogRepository()
    matching = next((f for f in repository.families() if f['slug'] == slug), None)
    if matching is None:
        raise Http404
    family_data = repository.family(matching['id'])
    if family_data is None:
        raise Http404
    family_data['members'] = _presentation(repository, family_data['members'])
    maintenance = repository._one("SELECT is_active,public_note FROM public.checkout_maintenance_settings WHERE singleton_key='checkout_maintenance'") or {'is_active': True}
    description = family_data['description'] or f"{family_data['name']} menawarkan {len(family_data['members'])} pilihan produk di Lhie Store."
    response = _render(request, 'storefront/catalog_family.html', {'family': family_data, 'title': family_data['name'] + ' — Pilihan Produk', 'description': description, 'maintenance': maintenance})
    response['Referrer-Policy'] = 'same-origin'
    return response


@require_GET
def legacy_family(request, slug):
    repository = CatalogRepository()
    slug = slug.strip().lower()
    owner = repository._one('SELECT id,slug,deleted_at FROM public.product_families WHERE slug=%s', (slug,))
    if owner is None:
        owner = repository._one('SELECT f.id,f.slug,f.deleted_at FROM public.product_family_slug_redirects r JOIN public.product_families f ON f.id=r.product_family_id WHERE r.old_slug=%s', (slug,))
    if owner is None or owner['deleted_at'] is not None or repository.family(owner['id']) is None:
        raise Http404
    return HttpResponseRedirect('/pilihan/' + quote(owner['slug'], safe=''), status=301)


@require_GET
def product(request, product_id):
    repository = CatalogRepository()
    item = repository.product(product_id)
    if item is None:
        raise Http404
    item = _presentation(repository, [item])[0]
    tiers = repository.tiers(product_id)
    reachable_tiers = []
    try:
        for tier in tiers:
            if tier['minimum_quantity'] <= item['available_stock']:
                quote = PricingSnapshot.build(
                    [{'product_id': product_id, 'quantity': tier['minimum_quantity']}],
                    {product_id: item}, {product_id: tiers}).quotes[0]
                reachable_tiers.append({'minimum': tier['minimum_quantity'], 'price': 'Rp' + format(quote.unit_price, ',').replace(',', '.')})
    except (CheckoutError, ValueError):
        reachable_tiers = []
    maintenance = repository._one("SELECT is_active,public_note FROM public.checkout_maintenance_settings WHERE singleton_key='checkout_maintenance'") or {'is_active': True}
    image = request.build_absolute_uri(item['image']) if item['image'] else request.build_absolute_uri('/static/storefront/lhie-store-logo.webp')
    canonical = request.build_absolute_uri(request.path)
    schema = {'@context': 'https://schema.org', '@type': 'Product', 'name': item['name'],
              'description': item['description'] or '', 'image': [image], 'url': canonical,
              'offers': {'@type': 'Offer', 'url': canonical, 'priceCurrency': 'IDR',
                         'price': max(0, item['price_amount']), 'availability': 'https://schema.org/InStock' if item['available_stock'] else 'https://schema.org/OutOfStock'}}
    schema_json = json.dumps(schema, ensure_ascii=True).replace('<', '\\u003c').replace('>', '\\u003e').replace('&', '\\u0026')
    response = _render(request, 'storefront/catalog_product.html', {'product': item, 'title': item['name'], 'description': item['description'] or '', 'maintenance': maintenance, 'tiers': reachable_tiers, 'og_image': image, 'og_type': 'product', 'schema_json': schema_json, 'support_options': product_choices(item['name'], canonical, **_support_channels(repository))})
    response['Referrer-Policy'] = 'same-origin'
    return response


@require_GET
def policy(request, key):
    if key not in ('privacy', 'terms', 'refund', 'recovery'):
        raise Http404
    titles = {'privacy': 'Kebijakan Privasi', 'terms': 'Syarat Penggunaan', 'refund': 'Kebijakan Refund', 'recovery': 'Pemulihan Pesanan'}
    return _render(request, 'storefront/catalog_policy.html', {'policy': key, 'title': titles[key]})


@require_GET
def guides(request, guide=False):
    return_url, invoice = None, None
    if guide:
        candidate = request.GET.get('return', '')
        if (len(candidate) <= 2048 and candidate.startswith('/shop/orders/')
                and not candidate.startswith('//') and '\\' not in candidate
                and not any(ord(char) < 32 for char in candidate)):
            return_url = candidate
        invoice = request.GET.get('invoice', '').replace('\r', ' ').replace('\n', ' ').strip()[:255] or None
    response = _render(request, 'storefront/login_guide.html' if guide else 'storefront/catalog_guides.html', {
        'return_url': return_url, 'invoice_number': invoice,
        'quick_flow': guide_content.QUICK_FLOW, 'preparation': guide_content.PREPARATION,
        'steps': guide_content.STEPS, 'troubleshooting': guide_content.TROUBLESHOOTING,
        'checklist': guide_content.CHECKLIST,
        'robots': 'noindex, nofollow' if return_url or invoice else 'index, follow',
        'title': 'Panduan Login ChatGPT' if guide else 'Pusat Panduan',
        'description': ('Panduan masuk dan mengamankan akun ChatGPT dari Lhie Store memakai Outlook, iCloud, dan Google Authenticator.' if guide else 'Pilih panduan penggunaan produk Lhie Store, termasuk login ChatGPT melalui Outlook, iCloud, dan MFA.'),
    })
    if return_url or invoice:
        response['Cache-Control'] = 'no-store, private'
        response['Referrer-Policy'] = 'no-referrer'
    return response


@require_GET
def legacy_guide(request):
    return HttpResponseRedirect('/gpt/panduan-login-akun', status=302)


@require_GET
def robots(request):
    lines = ['User-agent: *'] + ['Disallow: ' + path for path in (
        '/shop/cart', '/shop/checkout', '/shop/orders/', '/akses-pesanan',
        '/akun', '/akun/', '/admin', '/admin/', '/telegram-mini-app',
        '/api/', '/api/webhook/', '/storefront/events', '/*?q=', '/*?*q=',
        '/*?checkout=', '/*?*checkout=', '/*?from=', '/*?*from=')]
    lines.append('Sitemap: ' + request.build_absolute_uri('/sitemap.xml'))
    response = HttpResponse('\n'.join(lines) + '\n', content_type='text/plain; charset=utf-8')
    response['Cache-Control'] = 'public, max-age=3600'
    return response


@require_GET
def sitemap(request):
    products = CatalogRepository().products()
    paths = ['/', '/shop', '/panduan', '/gpt/panduan-login-akun',
             '/privasi', '/syarat-ketentuan', '/refund', '/pemulihan-pesanan']
    families = {p['family']['slug'] for p in products if p.get('family') and p['family'].get('slug')}
    paths.extend('/pilihan/' + quote(slug, safe='') for slug in sorted(families))
    paths.extend('/produk/' + str(p['id']) for p in products)
    body = '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
    body += ''.join('<url><loc>' + escape(request.build_absolute_uri(path)) + '</loc></url>\n' for path in paths[:1000])
    response = HttpResponse(body + '</urlset>\n', content_type='application/xml; charset=utf-8')
    response['Cache-Control'] = 'public, max-age=3600'
    return response


@require_GET
def llms(request):
    origin = request.build_absolute_uri('/').rstrip('/')
    body = (
        '# Lhie Store\n\n'
        '> Lhie Store is a digital-goods storefront. The Supplier API lets the backend of a registered Customer read the public catalog and its own balances and create balance-funded orders on this origin. API keys are issued from the private Store Bot.\n\n'
        '## API documentation\n\n'
        f'- [Supplier API reference (Markdown)]({origin}/api-docs/reference.md)\n'
        f'- [OpenAPI 3.1 description]({origin}/api-docs/openapi.json)\n'
        f'- [Bilingual documentation site]({origin}/api-docs)\n\n'
        '## Store\n\n'
        f'- [Storefront]({origin}/)\n'
        f'- [Order access]({origin}/akses-pesanan)\n'
        f'- [Guides]({origin}/panduan)\n\n'
        '## Policy\n\n'
        f'- [Refund policy]({origin}/refund)\n'
        f'- [Terms and conditions]({origin}/syarat-ketentuan)\n'
        f'- [Privacy policy]({origin}/privasi)\n'
    )
    response = HttpResponse(body, content_type='text/plain; charset=utf-8')
    response['Cache-Control'] = 'public, max-age=3600'
    return response
