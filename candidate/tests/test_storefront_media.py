import os
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from lhie_candidate.runtime import Runtime


class PublicImageTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        import django
        os.environ['DJANGO_SETTINGS_MODULE'] = 'lhie_candidate.settings'
        with patch('lhie_candidate.runtime.load_runtime', return_value=Runtime('synthetic-empty', 'a' * 64)):
            django.setup()

    def test_public_image_is_served_and_private_or_non_image_files_are_rejected(self):
        from django.http import Http404
        from django.test import RequestFactory
        from PIL import Image
        from lhie_candidate.storefront import public_media

        with TemporaryDirectory() as directory:
            root = Path(directory) / 'public'
            root.mkdir()
            (root / 'products').mkdir()
            Image.new('RGB', (2, 2), 'white').save(root / 'products' / 'cover.png')
            (root / 'products' / 'not-image.png').write_text('private synthetic content')
            outside = Path(directory) / 'outside.png'
            Image.new('RGB', (1, 1), 'red').save(outside)
            (root / 'products' / 'escape.png').symlink_to(outside)
            request = RequestFactory().get('/storage/products/cover.png')
            with patch.object(public_media, 'PUBLIC_ROOT', root):
                response = public_media.image(request, 'products/cover.png')
                self.assertEqual(response.status_code, 200)
                self.assertEqual(response['Content-Type'], 'image/png')
                self.assertTrue(b''.join(response.streaming_content).startswith(b'\x89PNG'))
                response.close()
                for path in ('../outside.png', 'products/escape.png', 'products/not-image.png'):
                    with self.subTest(path=path), self.assertRaises(Http404):
                        public_media.image(request, path)

    def test_banner_links_cannot_be_normalized_to_external_navigation(self):
        from types import SimpleNamespace
        from lhie_candidate.storefront.catalog import _banners

        links = ['/pilihan/example', '//outside.example', '/\n/outside.example', '/\\outside.example']
        settings = {'homepage_banners': [{'path': 'banners/image.png', 'link': link} for link in links]}
        banners = _banners(SimpleNamespace(_one=lambda *_: settings))
        self.assertEqual([item['link'] for item in banners], ['/pilihan/example', None, None, None])

    def test_banner_aspect_uses_admitted_image_dimensions_with_source_clamps(self):
        from types import SimpleNamespace
        from PIL import Image
        from lhie_candidate.storefront import public_media
        from lhie_candidate.storefront.catalog import _banner_aspect, _banners

        with TemporaryDirectory() as directory:
            root = Path(directory)
            Image.new('RGB', (200, 200), 'white').save(root / 'square.png')
            Image.new('RGB', (500, 100), 'white').save(root / 'wide.png')
            with patch.object(public_media, 'PUBLIC_ROOT', root):
                def banner(path):
                    repo = SimpleNamespace(_one=lambda *_: {'homepage_banners': [{'path': path}]})
                    return _banners(repo)
                self.assertEqual(_banner_aspect(banner('square.png')), '200 / 113')
                self.assertEqual(_banner_aspect(banner('wide.png')), '500 / 125')
                self.assertEqual(_banner_aspect(banner('missing.png')), '4 / 1')
