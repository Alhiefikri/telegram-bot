import json
import pickle
import unittest
from copy import deepcopy
from uuid import uuid4

from lhie_candidate.receipt.historical import normalize_unit, render_legacy, render_product_detail
from lhie_candidate.receipt.jobs import decode_job, encode_job
from lhie_candidate.receipt.render import render_receipt, render_txt


def allocation(accounts, *, locale='en'):
    group = {
        'product_name': 'Product Alpha', 'icon': '🛍️', 'custom_emoji_id': '12345',
        'default_tutorial': None, 'guide': None, 'accounts': accounts,
    }
    return {
        'order_id': str(uuid4()), 'customer_id': 10, 'chat_id': '20',
        'invoice_number': 'ORDER-1', 'locale': locale,
        'items': [{'product_name': group['product_name'], 'icon': group['icon'],
                   'custom_emoji_id': group['custom_emoji_id'], 'quantity': len(accounts)}],
        'groups': [group], 'allocated_count': len(accounts), 'amount_paid': 'Rp 100',
        'payment_method': 'QRIS', 'paid_at': '01 Jan 2025',
        'invoice_url': 'https://example.org/invoice',
    }


def structured():
    return {'format': 'structured_account', 'email': uuid4().hex + '@' + 'example.invalid',
            'password': uuid4().hex + '😀', 'access_method': 'none', 'access_value': None}


def text_of(units):
    return '\n'.join(unit.text for unit in units)


def entity_text(unit, entity):
    encoded = unit.text.encode('utf-16-le')
    start = entity['offset'] * 2
    end = start + entity['length'] * 2
    return encoded[start:end].decode('utf-16-le')


class ReceiptRenderTests(unittest.TestCase):
    def test_inline_structured_entities_and_owner_independence(self):
        account = structured()
        data = allocation([account])
        plan = render_receipt(data)
        self.assertIsNone(plan.document)
        self.assertEqual((plan.product_count, plan.account_count), (1, 1))
        for units in (plan.photo_units, plan.text_units):
            self.assertTrue(any(account['email'] in u.text and account['password'] in u.text for u in units),
                            'inline account missing')
            copies = [entity_text(u, e) for u in units for e in u.entities if e['type'] == 'pre']
            self.assertTrue(any(account['email'] in value and account['password'] in value for value in copies),
                            'copy entity does not cover the account')
            self.assertTrue(any(u.contains_credentials for u in units))
            for index, unit in enumerate(units):
                limit = 1024 if units is plan.photo_units and index == 0 else 4096
                self.assertLessEqual(len(unit.text.encode('utf-16-le')) // 2, limit)
        other = deepcopy(data)
        other.update(customer_id=99, chat_id='999', buyer_name='Different buyer')
        self.assertTrue(text_of(plan.text_units) == text_of(render_receipt(other).text_units),
                        'owner metadata changed receipt content')

    def test_automatic_document_preserves_allocation_order_and_is_in_memory(self):
        first, second = structured(), structured()
        data = allocation([first, second])
        plan = render_receipt(data)
        self.assertIsNotNone(plan.document)
        self.assertIsInstance(plan.document.contents, bytes)
        self.assertTrue(plan.document.filename.endswith('.txt'))
        contents = plan.document.contents.decode('utf-8')
        self.assertTrue(contents.index(first['email']) < contents.index(second['email']),
                        'allocated account order changed')
        self.assertTrue(all(a['password'] in contents for a in (first, second)),
                        'document omitted account content')
        self.assertTrue(all(a['password'] not in text_of(plan.text_units) for a in (first, second)),
                        'document mode exposed account in summary')
        self.assertTrue(render_txt(data).contents == plan.document.contents, 'TXT projection changed')
        forced = render_receipt(data, document_delivery=False)
        combined = text_of(forced.text_units)
        self.assertTrue(combined.index(first['email']) < combined.index(second['email']),
                        'inline allocation order changed')
        long_account = structured()
        long_account['password'] += 'x' * 1030
        self.assertIsNotNone(render_receipt(allocation([long_account])).document)

    def test_utf16_offsets_localized_icon_substitution_and_fallback(self):
        account = structured()
        data = allocation([account], locale='id')
        data['custom_emoji'] = True
        data['ui_icons'] = [{'source_emoji': '✅', 'fallback_emoji': '🟢', 'custom_emoji_id': '67890'}]
        plan = render_receipt(data, document_delivery=False)
        text = text_of(plan.text_units)
        self.assertIn('Pembayaran diterima', text)
        found = [(entity_text(unit, entity), entity['custom_emoji_id'])
                 for unit in plan.text_units for entity in unit.entities if entity['type'] == 'custom_emoji']
        self.assertIn(('🟢', '67890'), found)
        self.assertIn(('🛍️', '12345'), found)
        for unit in plan.text_units:
            for entity in unit.entities:
                selected = entity_text(unit, entity)
                self.assertGreater(len(selected), 0)
        data['custom_emoji'] = False
        plain = render_receipt(data, document_delivery=False)
        self.assertIn('🟢 Pembayaran diterima', text_of(plain.text_units))
        self.assertFalse(any(e['type'] == 'custom_emoji' for u in plain.text_units for e in u.entities))

    def test_structured_token_otp_and_legacy_fields(self):
        account = structured()
        token = uuid4().hex
        account.update(access_method='auth_token', access_value=token)
        plan = render_receipt(allocation([account]), document_delivery=False, copyable_credentials=False)
        copies = [entity_text(u, e) for u in plan.text_units for e in u.entities if e['type'] == 'code']
        self.assertTrue(all(value in copies for value in (account['email'], account['password'], token)),
                        'individual copy fields were not preserved')
        otp = structured()
        otp.update(access_method='otp_mail_link', access_value='https://example.org/otp')
        plan = render_receipt(allocation([otp]), document_delivery=False)
        buttons = [b for u in plan.text_units for row in u.reply_markup.get('inline_keyboard', []) for b in row]
        self.assertTrue(any(b.get('url') == otp['access_value'] for b in buttons))
        legacy = {'format': 'legacy', 'secret': account['email'] + '----' + account['password'] + '----' + token}
        plan = render_receipt(allocation([legacy]), document_delivery=False)
        copies = [entity_text(u, e) for u in plan.text_units for e in u.entities if e['type'] == 'code']
        self.assertTrue(all(value in copies for value in (account['email'], account['password'], token)),
                        'legacy fields were not individually copyable')
        legacy['secret'] = account['email'] + '|' + account['password']
        plan = render_receipt(allocation([legacy]), document_delivery=False)
        self.assertTrue(any(account['email'] in entity_text(u, e) and account['password'] in entity_text(u, e)
                            for u in plan.text_units for e in u.entities if e['type'] == 'pre'),
                        'legacy pipe account is not copyable')

    def test_incomplete_or_unrenderable_projection_fails_closed(self):
        data = allocation([structured()])
        data['allocated_count'] = 2
        with self.assertRaises(ValueError):
            render_receipt(data)
        data = allocation([structured()])
        data['groups'][0]['accounts'][0]['access_method'] = 'otp_mail_link'
        data['groups'][0]['accounts'][0]['access_value'] = 'javascript:alert(1)'
        with self.assertRaises(ValueError):
            render_receipt(data, document_delivery=False)
        with self.assertRaises(ValueError):
            render_txt(data)

    def test_presentation_cannot_be_serialized_or_repr_credentials(self):
        account = structured()
        data = allocation([account])
        inline = render_receipt(data, document_delivery=False)
        document = render_txt(data)
        secret_unit = next(unit for unit in inline.text_units if unit.contains_credentials)
        for obj in (inline, secret_unit, document):
            with self.subTest(kind=type(obj).__name__):
                self.assertTrue(account['password'] not in repr(obj), 'representation exposed credentials')
                with self.assertRaises(TypeError):
                    pickle.dumps(obj)
                with self.assertRaises(TypeError):
                    json.dumps(obj)

    def test_job_wire_accepts_only_version_kind_and_canonical_id(self):
        identity = 'abcdef01-2345-4678-9abc-def012345678'
        for kind in ('order', 'effect'):
            wire = encode_job(kind, identity)
            self.assertEqual(json.loads(wire), {'version': 1, 'kind': kind, 'id': identity})
            self.assertEqual(decode_job(wire.encode()), (kind, identity))
            with self.assertRaises(ValueError):
                decode_job(json.dumps({'version': 1, 'kind': kind, 'id': identity,
                                       'payload': {'value': uuid4().hex}}))
        for wire in ('{}', '[]', '{', b'\xff',
                     '{"version":1,"version":1,"kind":"order","id":"' + identity + '"}',
                     json.dumps({'version': True, 'kind': 'order', 'id': identity}),
                     json.dumps({'version': 1, 'kind': 'order', 'id': identity.upper()})):
            with self.assertRaises(ValueError):
                decode_job(wire)


class HistoricalReceiptTests(unittest.TestCase):
    def test_product_detail_keeps_php_unit_boundaries_and_order(self):
        first, second = structured(), structured()
        first['number'], second['number'] = 1, 2
        data = allocation([first, second])
        units = render_product_detail(data)
        self.assertEqual(len(units), 4)
        self.assertEqual(units[0].text, '\n'.join([
            '✅ Payment received', '━━━━━━━━━━━━━━', 'Order ID: ORDER-1',
            'Your ordered products:', '- 🛍️ Product Alpha x 2',
            'Payment method: QRIS', 'Paid at: 01 Jan 2025', 'Total quantity: 2',
            'Amount paid: Rp 100', '', '🔐 Product Detail',
        ]))
        self.assertTrue(units[1].text == '\n'.join([
            '🛍️ Product Alpha', '', 'Account #1',
            'email : ' + first['email'], 'password : ' + first['password'],
        ]), 'first account unit changed')
        self.assertTrue(units[2].text == '\n'.join([
            'Account #2', 'email : ' + second['email'], 'password : ' + second['password'],
        ]), 'second account unit changed')
        self.assertEqual(units[3].text, '\n\n'.join([
            '⚠️ Save these details securely and do not share them with anyone.',
            '💡 Tap an account value to copy it. Do not share these details.',
            'Thank you for shopping at LHIE STORE.',
        ]))
        for unit, account in zip(units[1:3], (first, second)):
            fields = [entity_text(unit, e) for e in unit.entities if e['type'] == 'code']
            self.assertTrue(fields == [account['email'], account['password']],
                            'historical copy entities changed')

    def test_historical_emoji_conversion_preserves_credential_offsets(self):
        account = structured()
        account['password'] = '<tg-emoji emoji-id="12345" data-omp-icon="1">🛍️</tg-emoji>'
        account['number'] = 1
        data = allocation([account])
        data['custom_emoji'] = True
        units = [normalize_unit(unit) for unit in render_product_detail(data)]
        self.assertTrue(all('\x00' not in unit.text for unit in units))
        icons = [(entity_text(unit, entity), entity['custom_emoji_id']) for unit in units
                 for entity in unit.entities if entity['type'] == 'custom_emoji']
        self.assertIn(('🛍️', '12345'), icons)
        fields = [entity_text(unit, entity) for unit in units
                  for entity in unit.entities if entity['type'] == 'code']
        self.assertEqual(fields, [account['email'], account['password']])

    def test_legacy_text_is_one_php_unit_with_original_section_order(self):
        account = structured()
        legacy = {'format': 'legacy', 'number': 1,
                  'secret': account['email'] + '|' + account['password']}
        units = render_legacy(allocation([legacy]))
        self.assertEqual(len(units), 1)
        expected = '\n'.join([
            '✅ Payment received', '━━━━━━━━━━━━━━', '🧾 Invoice: ORDER-1', '',
            'Your ordered products:', '- 🛍️ Product Alpha x 1', '',
            '🔐 Account details', '━━━━━━━━━━━━━━', '🛍️ Product Alpha',
            '1. email : ' + account['email'] + '\npassword : ' + account['password'],
            '', '⚠️ Save these details securely and do not share them with anyone.',
            '', 'If you have a login problem, contact /support and include this invoice.',
            'Thank you for shopping at LHIE STORE.',
        ])
        self.assertTrue(units[0].text == expected, 'historical legacy receipt changed')


if __name__ == '__main__':
    unittest.main()
