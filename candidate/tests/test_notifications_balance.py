"""IDR notice eligibility and immutable presentation boundaries."""
from datetime import datetime
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

from lhie_candidate.notifications import balance
from lhie_candidate.notifications.policy import TestRecipients as Recipients
from lhie_candidate.notifications.snapshot import record_snapshot
from lhie_candidate.receipt.protocol import RECEIPT_TYPES


class BalanceNoticeTests(unittest.TestCase):
    def row(self, **changes):
        row = {'id': 7, 'customer_id': 3, 'chat_id': '1', 'telegram_id': 1,
               'locale': 'en', 'customer_locale': 'id', 'first_name': 'Synthetic Buyer',
               'username': 'synthetic', 'provider': 'manual', 'merchant_ref': 'TOPUP-SYNTHETIC',
               'currency': 'IDR', 'amount': 10000, 'provider_fee': 500,
               'provider_total_payment': 10500, 'provider_payment_method': 'QR_MANUAL',
               'provider_reference': 'REF<&', 'status': 'paid', 'settled_at': datetime(2026, 11, 11, 9, 15),
               'paid_at': datetime(2026, 11, 11, 9, 14), 'provider_create_last_error': None,
               'expires_at': datetime(2026, 11, 11, 10), 'provider_expires_at': datetime(2026, 11, 11, 9, 45),
               'payment_proof_file_id': 'synthetic-photo', 'payment_proof_submitted_at': datetime(2026, 11, 11, 9),
               'payment_proof_admin_chat_id': '1', 'payment_proof_forwarded_at': None, 'compensated': False,
               'compensation_id': 11, 'compensation_customer_id': 3, 'compensation_amount': 10000,
               'entry_customer_id': 3, 'entry_amount': 10000, 'entry_kind': 'owner_compensation_credit',
               'entry_source_type': 'owner_compensation', 'entry_source_id': '11',
               'resolved_at': datetime(2026, 11, 11, 9, 20), 'compensation_created_at': datetime(2026, 11, 11, 9, 20)}
        row.update(changes)
        return row

    def adapter(self):
        db = SimpleNamespace(one=Mock(), rows=Mock(return_value=[]), execute=Mock(return_value=1),
                             require_transaction=Mock(), using='default')
        service = SimpleNamespace(db=db, recipients=Recipients(frozenset({'1'})),
                                  protocol=SimpleNamespace(effect_types=tuple(balance.MODELS)), record=Mock(),
                                  bot_username='LhieStoreTestBot')
        return balance.BalanceNotices(service)

    def effect(self, kind=balance.RECEIPT, *, payload=None, **changes):
        effect = {'id': 'effect-synthetic', 'effect_key': 'key-synthetic', 'effect_type': kind,
                  'source_type': 'App\\Models\\' + balance.MODELS[kind], 'source_id': '7',
                  'telegram_chat_id': '1', 'target_type': 'customer', 'locale': 'en', 'sequence': 1,
                  'template_version': 'balance-notification-v1', 'telegram_method': 'sendMessage',
                  'state': 'pending', 'outcome_metadata': {'notification_payload': payload or {'chat_id': '1', 'text': 'Frozen text'}}}
        effect.update(changes)
        return effect

    def test_family_selection_does_not_duplicate_usdt_receipt_consumers(self):
        self.assertFalse(set(balance.LIMITS) & set(RECEIPT_TYPES))
        self.assertEqual(set(balance.MODELS), set(balance.LIMITS))
        self.assertEqual(set(balance.LIMITS.values()), {3})

    def test_receipt_button_custom_icon_capability_and_definite_fallback(self):
        from lhie_candidate.notifications.transport import NotificationTransport

        icons = [{'key': 'menu.balance', 'source_emoji': '💰', 'fallback_emoji': '💰',
                  'custom_emoji_id': '456'}]
        rendered = balance.render_receipt(self.row(), '1', 'en', icons)
        button = rendered['reply_markup']['inline_keyboard'][0][0]
        self.assertEqual(button['_unicode_fallback'], '💰 ' + button['text'])
        self.assertNotIn('<', button['text'])

        accepted = NotificationTransport()
        accepted.relay = SimpleNamespace(_request=Mock(return_value={
            'ok': True, 'result': {'message_id': 7}}))
        self.assertEqual(accepted.execute('sendMessage', rendered).message_id, 7)
        sent_button = accepted.relay._request.call_args.args[1]['reply_markup']['inline_keyboard'][0][0]
        self.assertEqual(sent_button['icon_custom_emoji_id'], '456')
        self.assertNotIn('_unicode_fallback', sent_button)
        self.assertEqual(accepted.relay._request.call_count, 1)

        rejected = NotificationTransport()
        rejected.relay = SimpleNamespace(_request=Mock(side_effect=[
            {'ok': False, 'error_code': 400, 'description': 'custom emoji is unavailable'},
            {'ok': True, 'result': {'message_id': 8}}]))
        self.assertEqual(rejected.execute('sendMessage', rendered).message_id, 8)
        fallback = rejected.relay._request.call_args.args[1]['reply_markup']['inline_keyboard'][0][0]
        self.assertEqual(fallback['text'], button['_unicode_fallback'])
        self.assertNotIn('icon_custom_emoji_id', fallback)
        self.assertEqual(rejected.relay._request.call_count, 2)

    def test_uncertain_custom_icon_outcome_never_falls_back_or_retries(self):
        from lhie_candidate.notifications.transport import NotificationTransport

        icons = [{'key': 'menu.balance', 'source_emoji': '💰', 'fallback_emoji': '💰',
                  'custom_emoji_id': '456'}]
        rendered = balance.render_receipt(self.row(), '1', 'en', icons)
        for response in ({}, {'ok': False, 'error_code': 500, 'description': 'custom emoji'}):
            with self.subTest(response=response):
                transport = NotificationTransport()
                transport.relay = SimpleNamespace(_request=Mock(return_value=response))
                self.assertEqual(transport.execute('sendMessage', rendered).outcome, 'ambiguous')
                self.assertEqual(transport.relay._request.call_count, 1)
                sent_button = transport.relay._request.call_args.args[1]['reply_markup']['inline_keyboard'][0][0]
                self.assertEqual(sent_button['icon_custom_emoji_id'], '456')

    def test_receipt_uses_frozen_database_icons_and_button_fallback(self):
        icons = [{'key': 'message.success', 'source_emoji': '✅', 'fallback_emoji': '☑️', 'custom_emoji_id': '123'},
                 {'key': 'menu.balance', 'source_emoji': '💰', 'fallback_emoji': '💰', 'custom_emoji_id': '456'}]
        result = balance.render_receipt(self.row(), '1', 'en', icons)
        self.assertTrue(result['text'].startswith('<tg-emoji emoji-id="123">☑️</tg-emoji>'))
        button = result['reply_markup']['inline_keyboard'][0][0]
        self.assertEqual(button['icon_custom_emoji_id'], '456')
        self.assertTrue(button['_unicode_fallback'].startswith('💰 '))
        self.assertTrue(button['_unicode_fallback'].endswith(button['text']))

    def test_owner_attention_excludes_plain_expiry_and_all_resolved_money(self):
        for status, proof, compensated, expected in (
                ('recovery_pending', None, False, True), ('invalid_provider_result', None, False, True),
                ('expired', 'photo', False, True), ('expired', None, False, False),
                ('paid', 'photo', False, False), ('compensated', 'photo', False, False),
                ('recovery_pending', 'photo', True, False), ('failed', 'photo', False, False)):
            with self.subTest(status=status, proof=proof, compensated=compensated):
                self.assertEqual(balance.owner_eligible(self.row(status=status, payment_proof_file_id=proof,
                                                                 compensated=compensated)), expected)

    def test_owner_alert_redacts_provider_details_without_exposing_secrets(self):
        secret = 'capi_' + 'a' * 32 + '.' + 'b' * 64
        row = self.row(status='invalid_provider_result', provider_create_last_error=
                       'https://api.telegram.org/bot123:private/sendMessage ' + secret)
        text = balance.render_owner(row, '1')['text']
        self.assertIn('[redacted-telegram-bot-api-url]', text)
        self.assertIn('[redacted-customer-api-key]', text)
        self.assertNotIn('123:private', text)
        self.assertNotIn(secret, text)

    def test_manual_proof_eligibility_rejects_ineligible_and_forwarded_source_rows(self):
        row = self.row(status='awaiting_confirmation')
        self.assertTrue(balance.proof_eligible(row))
        for changes in (
                {'status': 'paid'}, {'provider': 'klikqris'}, {'payment_proof_file_id': None},
                {'payment_proof_submitted_at': None}, {'payment_proof_forwarded_at': datetime(2026, 11, 11)}):
            with self.subTest(changes=changes):
                self.assertFalse(balance.proof_eligible({**row, **changes}))

    def test_compensation_requires_exact_linked_ledger_and_customer(self):
        row = self.row(status='compensated')
        self.assertTrue(balance.compensation_eligible(row))
        for field, value in (('entry_kind', 'top_up_credit'), ('entry_source_id', '12'),
                             ('entry_amount', 9999), ('entry_customer_id', 4), ('telegram_id', 2),
                             ('compensation_customer_id', 4), ('compensation_amount', 20000)):
            with self.subTest(field=field):
                self.assertFalse(balance.compensation_eligible({**row, field: value}))

    def test_public_channel_requires_supported_settled_idr_payment(self):
        self.assertTrue(balance.channel_eligible(self.row()))
        for change in ({'status': 'expired'}, {'settled_at': None}, {'provider_total_payment': None},
                       {'amount': 0}, {'currency': 'USDT'}, {'provider_payment_method': 'BINANCE_PAY_ID'}):
            with self.subTest(change=change):
                self.assertFalse(balance.channel_eligible(self.row(**change)))
        source = balance.public_topup(self.row(telegram_id=123456789))
        self.assertEqual(source['chat_id'], '123*****89')
        self.assertEqual(source['buyer_name'], 'Syn***')
        self.assertNotIn('123456789', str(source))
        self.assertEqual(source['items'][0]['product_name'], 'Store Balance Top-Up')
        self.assertEqual(source['payment_method'], 'QR Manual')

    def test_instruction_requires_matching_customer_and_live_deadlines(self):
        row = self.row(status='awaiting_confirmation')
        now = datetime(2026, 11, 11, 9, 30)
        self.assertTrue(balance.instruction_eligible(row, now))
        self.assertFalse(balance.instruction_eligible(row, row['provider_expires_at']))
        self.assertFalse(balance.instruction_eligible({**row, 'provider_fee': None}, now))
        self.assertFalse(balance.instruction_eligible({**row, 'telegram_id': 2}, now))

    def test_empty_admitted_targets_are_noop_before_database_or_media(self):
        service = SimpleNamespace(db=Mock(), recipients=Recipients())
        self.assertEqual(balance.BalanceNotices(service).materialize(25), 0)
        service.db.assert_not_called()
        self.assertEqual(service.db.method_calls, [])

    def test_existing_snapshot_never_rerenders_after_source_copy_changes(self):
        adapter = self.adapter()
        adapter._topup = Mock(return_value=self.row(amount=20000, first_name='Changed customer'))
        effect = self.effect(payload={'chat_id': '1', 'text': 'Original receipt', 'reply_markup': {'inline_keyboard': []}})
        result = adapter.payload(effect)
        self.assertEqual(result['text'], 'Original receipt')
        result['reply_markup']['inline_keyboard'].append([])
        self.assertEqual(effect['outcome_metadata']['notification_payload']['reply_markup']['inline_keyboard'], [])
        adapter.db.execute.assert_not_called()

    def test_adjustment_retains_exact_plain_text_without_regenerating_money(self):
        adapter = self.adapter()
        effect = self.effect(balance.ADJUSTMENT, target_type='admin', outcome_metadata={
            'delivery_context': 'balance_adjustment', 'message': 'Original <case> & amount\nRp 10.000'})
        result = adapter.payload(effect)
        self.assertIsNone(result['parse_mode'])
        self.assertEqual(result['text'], 'Original <case> & amount\nRp 10.000')
        adapter.db.one.assert_not_called()
        adapter.db.execute.assert_not_called()

    def test_unknown_history_never_gets_a_new_pending_obligation_or_snapshot(self):
        adapter = self.adapter()
        effect = self.effect(state='unknown', outcome_metadata={})
        adapter.service.record.return_value = effect
        returned = record_snapshot(adapter.service, key=effect['effect_key'], kind=balance.RECEIPT,
            model=balance.MODELS[balance.RECEIPT], source='7', chat='1', target='customer', locale='en',
            template=effect['template_version'], payload={'chat_id': '1', 'text': 'New text'})
        self.assertIs(returned, effect)
        adapter.db.execute.assert_not_called()

    def test_old_sent_proof_cannot_overwrite_latest_sent_projection(self):
        adapter = self.adapter()
        effect = self.effect(balance.PAYMENT_PROOF, state='sent', sent_at=datetime(2026, 11, 11),
                             telegram_message_id=9, telegram_method='sendPhoto', target_type='admin')
        adapter.db.one.return_value = {'id': 'newer-effect'}
        self.assertEqual(adapter.project(effect), 0)
        adapter.db.execute.assert_not_called()
        adapter.db.one.return_value = {'id': effect['id']}
        self.assertEqual(adapter.project(effect), 1)
        self.assertIn('payment_proof_forwarded_at', adapter.db.execute.call_args.args[0])

    def test_unknown_projection_never_sets_forwarded_marker(self):
        adapter = self.adapter()
        self.assertEqual(adapter.project(self.effect(balance.PAYMENT_PROOF, state='unknown')), 0)
        adapter.db.execute.assert_not_called()


if __name__ == '__main__':
    unittest.main()
