import json
import unittest
from unittest.mock import patch

from django.test import Client, SimpleTestCase
from django.urls import reverse

from lhie_candidate.customer_api.documentation import ERRORS, OPERATIONS, SCHEMAS


class CustomerApiDocsContractTests(SimpleTestCase):
    def setUp(self):
        self.client = Client(HTTP_HOST='localhost')

    def test_named_routes_serve_public_docs_with_expected_headers_and_links(self):
        paths = {
            'storefront-api-docs': ('/api-docs', 'text/html'),
            'storefront-api-docs-openapi': ('/api-docs/openapi.json', 'application/json'),
            'storefront-api-docs-reference': ('/api-docs/reference.md', 'text/markdown'),
        }
        for name, (expected_path, content_type) in paths.items():
            with self.subTest(name=name):
                self.assertEqual(reverse(name), expected_path)
                response = self.client.get(reverse(name))
                self.assertEqual(response.status_code, 200)
                self.assertTrue(response['Content-Type'].startswith(content_type))
                self.assertEqual(response['Cache-Control'], 'public, max-age=3600')
                if name == 'storefront-api-docs':
                    html = response.content.decode()
                    expected_base = response.wsgi_request.build_absolute_uri('/api/v1')
                    self.assertIn(expected_base, html)
                    self.assertIn('name=\"robots\" content=\"index, follow\"', html)
                    self.assertIn('rel=\"canonical\"', html)
                    self.assertIn('href=\"/api-docs/openapi.json\"', html)
                    self.assertIn('href=\"/api-docs/reference.md\"', html)
                    self.assertIn('href=\"/llms.txt\"', html)
        self.assertEqual(self.client.get('/api-docs/').status_code, 200)

    def test_machine_files_and_html_share_the_canonical_operations(self):
        response = self.client.get(reverse('storefront-api-docs-openapi'))
        document = json.loads(response.content)
        documented = {(method.upper(), '/api/v1' + path) for path, methods in document['paths'].items() for method in methods}
        declared = {(operation['method'], operation['path']) for operation in OPERATIONS}
        self.assertEqual(documented, declared)
        self.assertEqual(document['openapi'], '3.1.0')
        self.assertEqual(document['servers'], [{'url': response.wsgi_request.build_absolute_uri('/api/v1')}])

        def assert_schema_refs(value):
            if isinstance(value, dict):
                reference = value.get('$ref', '')
                if reference.startswith('#/components/schemas/'):
                    self.assertIn(reference.rsplit('/', 1)[1], document['components']['schemas'])
                for child in value.values():
                    assert_schema_refs(child)
            elif isinstance(value, list):
                for child in value:
                    assert_schema_refs(child)

        assert_schema_refs(document)
        page = self.client.get(reverse('storefront-api-docs')).content.decode()
        reference = self.client.get(reverse('storefront-api-docs-reference')).content.decode()
        for operation in OPERATIONS:
            self.assertIn(operation['path'], page)
            self.assertIn(operation['path'], reference)
        self.assertIn('lang=\"id\"', page)
        self.assertIn('lang=\"en\"', page)
        self.assertIn('application/json', page)
        self.assertIn('reference.md', page)
        self.assertIn('openapi.json', page)
        for name in SCHEMAS:
            self.assertIn(f'### `{name}`', reference)

    def test_slash_aliases_do_not_register_separate_named_endpoint_semantics(self):
        from django.urls import resolve

        for path in ('/api-docs', '/api-docs/'):
            match = resolve(path)
            self.assertEqual(match.view_name, 'storefront-api-docs')
        for path in ('/api-docs/openapi.json', '/api-docs/reference.md'):
            self.assertIsNotNone(resolve(path))

    def test_documented_routes_match_python_dispatch_and_no_legacy_operations(self):
        from django.urls import resolve
        from lhie_candidate.customer_api.http import endpoint

        expected = {
            ('GET', '/api/v1/balance'), ('GET', '/api/v1/products'), ('GET', '/api/v1/products/{id}'),
            ('POST', '/api/v1/orders'), ('GET', '/api/v1/orders'), ('GET', '/api/v1/orders/{id}'),
            ('GET', '/api/v1/orders/{id}/delivery'),
        }
        self.assertEqual({(op['method'], op['path']) for op in OPERATIONS}, expected)
        for _, path in expected:
            concrete = path.replace('{id}', '123')
            self.assertIs(resolve(concrete).func, endpoint)
        forbidden = ('credentials', 'webhook', 'refund', 'admin', 'telegram')
        operation_ids = ' '.join(op['operationId'].lower() for op in OPERATIONS)
        for term in forbidden:
            self.assertNotIn(term, operation_ids)

        self.assertTrue({'CreateOrderRequest', 'Product', 'Balance', 'Order', 'OrderCreate', 'Delivery', 'ErrorEnvelope'} <= set(SCHEMAS))
        self.assertEqual(SCHEMAS['CreateOrderRequest']['additionalProperties'], False)
        self.assertEqual(SCHEMAS['CreateOrderRequest']['properties']['quantity']['maximum'], 100)
        self.assertEqual(SCHEMAS['CreateOrderRequest']['properties']['product_id']['type'], 'string')
        self.assertEqual(SCHEMAS['OrderCreateEnvelope']['properties']['data']['$ref'], '#/components/schemas/OrderCreate')
        self.assertEqual(set(ERRORS), {error for operation in OPERATIONS for error in operation['errors']})

    def test_example_order_request_is_accepted_by_the_real_parser(self):
        from lhie_candidate.customer_api.orders import OrderRequest

        parsed = OrderRequest.parse('docs-synthetic-001', {
            'product_id': '123', 'quantity': 1, 'preference': 'idr_first',
            'expected_totals': {'IDR': '25000'},
        })
        self.assertEqual((parsed.product_id, parsed.quantity, parsed.expected_idr), (123, 1, 25000))
    def test_documented_bounds_match_parser_and_pre_database_validation(self):
        from lhie_candidate.customer_api.reads import products
        from lhie_candidate.customer_api.delivery import delivery
        from lhie_candidate.customer_api.admission import ApiFailure
        from lhie_candidate.compat.crypto import LaravelEncrypter
        from lhie_candidate.customer_api.orders import OrderRequest

        request = next(operation for operation in OPERATIONS if operation['operationId'] == 'createOrder')
        self.assertEqual(request['statuses'], (201, 200))
        with self.assertRaises(ApiFailure):
            OrderRequest.parse('docs-synthetic-002', {'product_id': '123', 'quantity': 101})
        with self.assertRaises(ApiFailure):
            products(None, {'limit': '101'})
        with self.assertRaises(ApiFailure):
            delivery(None, '00000000-0000-4000-8000-000000000001', LaravelEncrypter(b'a' * 32), limit=101)


    def test_llms_robots_and_sitemap_expose_consistent_public_metadata(self):
        llms = self.client.get(reverse('storefront-llms'))
        self.assertEqual(llms.status_code, 200)
        self.assertTrue(llms['Content-Type'].startswith('text/plain'))
        self.assertEqual(llms['Cache-Control'], 'public, max-age=3600')
        llms_content = llms.content.decode()
        self.assertIn('/api-docs', llms_content)
        self.assertIn('/api-docs/openapi.json', llms_content)
        self.assertIn('/api-docs/reference.md', llms_content)
        robots = self.client.get(reverse('storefront-robots')).content.decode()
        with patch('lhie_candidate.storefront.catalog.CatalogRepository.products', return_value=[]):
            sitemap = self.client.get(reverse('storefront-sitemap')).content.decode()
        origin = self.client.get('/api-docs').wsgi_request.build_absolute_uri('/api-docs')
        self.assertIn(origin, sitemap)
        self.assertIn('Disallow: /api/', robots)
        self.assertNotIn('Disallow: /api-docs', robots)


if __name__ == '__main__':
    unittest.main()
