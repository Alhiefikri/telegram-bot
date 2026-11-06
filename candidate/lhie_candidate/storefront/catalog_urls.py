from django.urls import path

from . import catalog

urlpatterns = [
    path('robots.txt', catalog.robots, name='storefront-robots'),
    path('sitemap.xml', catalog.sitemap, name='storefront-sitemap'),
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
