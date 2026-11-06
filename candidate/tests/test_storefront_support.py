import unittest
from urllib.parse import parse_qs, urlsplit

from lhie_candidate.storefront.support import order_choices


class SupportChoicesTests(unittest.TestCase):
    def test_support_messages_include_invoice_and_issue_only(self):
        choices = order_choices('INV-TEST\nSynthetic', whatsapp='https://wa.me/62000', telegram='https://t.me/admin')
        self.assertEqual(len(choices), 6)
        for choice in choices:
            self.assertEqual(len(choice['options']), 2)
            for option in choice['options']:
                message = parse_qs(urlsplit(option['url']).query)['text'][0]
                self.assertIn('Invoice: INV-TEST Synthetic', message)
                self.assertIn('Kategori masalah: ' + choice['label'], message)
                self.assertNotIn('Recovery Key', message)
                self.assertNotIn('signature', message)

    def test_unsafe_or_missing_contact_does_not_produce_a_link(self):
        choices = order_choices('INV-TEST', whatsapp='javascript:alert(1)', telegram='https://user:pass@example.invalid')
        self.assertTrue(all(not choice['options'] for choice in choices))
