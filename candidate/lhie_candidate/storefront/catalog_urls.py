from django.urls import path, re_path

from . import api_docs, catalog

urlpatterns = [
    path('robots.txt', catalog.robots, name='storefront-robots'),
    path('sitemap.xml', catalog.sitemap, name='storefront-sitemap'),
    re_path(r'^api-docs/?$', api_docs.index, name='storefront-api-docs'),
    path('api-docs/openapi.json', api_docs.openapi, name='storefront-api-docs-openapi'),
    path('api-docs/reference.md', api_docs.reference, name='storefront-api-docs-reference'),
    path('llms.txt', catalog.llms, name='storefront-llms'),
    path('aktivitas-pembelian-terverifikasi', catalog.purchase_activity, name='storefront-purchase-activity'),
    path('panduan', catalog.guides, name='storefront-guides'),
    path('gpt/panduan-login-akun', catalog.guides, {'guide': True}, name='storefront-login-guide'),
    path('panduan-login-akun', catalog.legacy_guide),
    path('cara-loginoutlook', catalog.legacy_guide),
    path('', catalog.home, name='storefront-home'),
    path('shop', catalog.shop, name='storefront-shop'),
    path('shop/', catalog.shop),
    path('pilihan/<slug:slug>', catalog.family, name='storefront-family'),
    path('produk-keluarga/<slug:slug>', catalog.legacy_family),
    path('produk/<int:product_id>', catalog.product, name='storefront-product'),
    path('privasi', catalog.policy, {'key': 'privacy'}, name='storefront-privacy'),
    path('syarat-ketentuan', catalog.policy, {'key': 'terms'}, name='storefront-terms'),
    path('refund', catalog.policy, {'key': 'refund'}, name='storefront-refund'),
    path('pemulihan-pesanan', catalog.policy, {'key': 'recovery'}, name='storefront-recovery'),
]
