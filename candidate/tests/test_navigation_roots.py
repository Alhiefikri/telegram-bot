"""Root protocol regressions; exercise_root_protocol runs on a provisioned local DB.

The parent disposable PostgreSQL smoke supplies a real legacy customer and installs
navigation/schema.sql. No SQL is mocked, no fixture DDL is implicit, and no real
Telegram transport is used. Every transport call checks that no transaction is open.
"""

import multiprocessing
from dataclasses import replace
from threading import Lock
import unittest
from unittest.mock import patch
from uuid import uuid4

from django.db import connections, transaction

from lhie_candidate.navigation.roots import NavigationRoots, Root, fits_caption
from lhie_candidate.navigation.types import Surface, TelegramResult


def panel(caption='Home', screen='home', locale='id', **options):
    return Surface(caption=caption, keyboard={'inline_keyboard': []}, screen=screen,
                   locale=locale, options={'parse_mode': 'HTML', **options})


class CaptionTests(unittest.TestCase):
    def test_limit_counts_parsed_text_not_html_or_utf16_entity_offsets(self):
        self.assertTrue(fits_caption(panel('<b>' + '&amp;' * 1024 + '</b>')))
        self.assertFalse(fits_caption(panel('<b>' + '&amp;' * 1025 + '</b>')))
        self.assertTrue(fits_caption(panel('<tg-emoji emoji-id="7">🙂</tg-emoji>' * 1024)))
        self.assertFalse(fits_caption(panel('🙂' * 1025)))

    def test_plain_text_does_not_strip_literal_markup(self):
        self.assertFalse(fits_caption(replace(panel('<b>' * 400), options={})))

class CheckoutMediaTests(unittest.TestCase):
    QR = 'https://sakurupiah.id/assets/synthetic.png'

    class Transport:
        def __init__(self, outcomes=()):
            self.calls = []
            self.outcomes = iter(outcomes)

        def execute(self, method, payload):
            self.calls.append((method, payload))
            outcome = next(self.outcomes, 'success')
            return TelegramResult(message_id=123 if method.startswith('send') else None, outcome=outcome)

    class Roots(NavigationRoots):
        def _outside_transaction(self):
            pass

        def _claim(self, customer, chat_id, root=None, *, establish=False):
            return root

        def _live(self, root):
            return True

        def _release(self, root):
            return True

        def _invalidate_root(self, root):
            self.cleared = root
            return True

        def _finish(self, root, surface, **kwargs):
            self.finished = kwargs
            return True

    def root(self, screen, media_kind):
        return Root('synthetic', 1, '41001', 7, media_kind, screen, 'id',
                    'banner', 3, 'photo', 1, uuid4(), None, None)

    def roots(self, outcomes=()):
        transport = self.Transport(outcomes)
        return self.Roots(transport, banner_reference='banner', banner_version=3), transport

    def test_checkout_photo_uses_qr_without_overwriting_banner(self):
        roots, transport = self.roots()
        result = roots.render({}, self.root('home', 'photo'), '41001',
                              panel('Scan', 'checkout', photo_url=self.QR))
        self.assertEqual(result, 'replaced')
        self.assertEqual([method for method, _ in transport.calls], ['sendPhoto', 'deleteMessage'])
        self.assertEqual(transport.calls[0][1]['photo'], self.QR)
        self.assertNotIn('photo_url', transport.calls[0][1])
        self.assertEqual(roots.finished['reference'], 'banner')

    def test_pending_photo_is_deleted_before_paid_or_navigation(self):
        for target in (panel('Paid', 'checkout'), panel('Orders', 'orders')):
            with self.subTest(screen=target.screen):
                roots, transport = self.roots()
                result = roots.render({}, self.root('checkout', 'photo'), '41001', target)
                self.assertEqual(result, 'replaced')
                self.assertEqual([method for method, _ in transport.calls],
                                 ['deleteMessage', 'sendMessage' if target.screen == 'checkout' else 'sendPhoto'])

    def test_ambiguous_delete_never_sends_and_ambiguous_send_is_not_retried(self):
        for outcomes, methods in ((['ambiguous'], ['deleteMessage']),
                                  (['success', 'ambiguous'], ['deleteMessage', 'sendMessage'])):
            with self.subTest(outcomes=outcomes):
                roots, transport = self.roots(outcomes)
                result = roots.render({}, self.root('checkout', 'photo'), '41001', panel('Paid', 'checkout'))
                self.assertEqual(result, 'invalidated')
                self.assertEqual([method for method, _ in transport.calls], methods)
                self.assertEqual(roots.cleared.screen, 'checkout')

    def test_invalidated_checkout_feedback_directs_each_locale_to_start(self):
        from lhie_candidate.navigation.presentation import CatalogPresenter
        from lhie_candidate.navigation.processor import NavigationProcessor
        from tests.test_catalog_presentation import CUSTOMER, MemoryCatalog

        callback = {'id': 'callback', 'message': {'chat': {'id': 41001}}}
        for locale in ('id', 'en', 'ms'):
            for outcomes, methods in ((['ambiguous'], ['deleteMessage']),
                                      (['success', 'definite'], ['deleteMessage', 'sendMessage']),
                                      (['success', 'ambiguous'], ['deleteMessage', 'sendMessage'])):
                with self.subTest(locale=locale, outcomes=outcomes):
                    roots, transport = self.roots()
                    transport.execute('answerCallbackQuery', {'callback_query_id': callback['id']})
                    transport.outcomes = iter(outcomes + ['ambiguous'])
                    presenter = CatalogPresenter(MemoryCatalog(), signing_key='synthetic-key')
                    processor = NavigationProcessor(None, presenter, roots, transport)
                    outcome = roots.render({}, self.root('checkout', 'photo'), '41001', panel('Paid', 'checkout'))
                    self.assertEqual(processor._feedback({**CUSTOMER, 'locale': locale}, callback, outcome), 'invalidated')
                    self.assertEqual([method for method, _ in transport.calls],
                                     ['answerCallbackQuery'] + methods + ['sendMessage'])
                    self.assertEqual(transport.calls[-1][1]['chat_id'], 41001)
                    self.assertIn('/start', transport.calls[-1][1]['text'])

    def test_lost_invalidation_fence_reports_superseded(self):
        roots, transport = self.roots(['ambiguous'])
        with patch.object(roots, '_invalidate_root', return_value=False):
            outcome = roots.render({}, self.root('checkout', 'photo'), '41001', panel('Paid', 'checkout'))
        self.assertEqual(outcome, 'superseded')
        self.assertEqual([method for method, _ in transport.calls], ['deleteMessage'])

    def test_new_qr_replaces_old_photo_and_long_caption_uses_text(self):
        for caption, expected in (('Scan', 'sendPhoto'), ('x' * 1025, 'sendMessage')):
            with self.subTest(method=expected):
                roots, transport = self.roots()
                result = roots.render({}, self.root('checkout', 'photo'), '41001',
                                      panel(caption, 'checkout', photo_url=self.QR))
                self.assertEqual(result, 'replaced')
                self.assertEqual([method for method, _ in transport.calls], ['deleteMessage', expected])

    def test_pending_text_upgrades_to_qr_photo(self):
        roots, transport = self.roots()
        result = roots.render({}, self.root('checkout', 'text'), '41001',
                              panel('Scan', 'checkout', photo_url=self.QR))
        self.assertEqual(result, 'replaced')
        self.assertEqual([method for method, _ in transport.calls], ['sendPhoto', 'deleteMessage'])

    def test_checkout_rejects_untrusted_photo_and_ignores_photo_on_other_screens(self):
        roots, transport = self.roots()
        root = self.root('checkout', 'text')
        result = roots.render({}, root, '41001',
                              panel('Pending', 'checkout', photo_url='https://example.com/qr.png'))
        self.assertEqual(result, 'applied')
        self.assertEqual([method for method, _ in transport.calls], ['editMessageText'])
        self.assertNotIn('photo_url', transport.calls[0][1])
        self.assertEqual(roots._media(root, panel('Home', 'home', photo_url=self.QR)),
                         ('photo', 'banner'))

    def test_definite_checkout_photo_failure_sends_one_text_fallback(self):
        roots, transport = self.roots(['definite', 'success', 'success'])
        surface = panel('Scan image above', 'checkout', photo_url=self.QR,
                        text_fallback='Open QRIS below')
        result = roots.render({}, self.root('home', 'photo'), '41001', surface)
        self.assertEqual(result, 'replaced')
        self.assertEqual([method for method, _ in transport.calls],
                         ['sendPhoto', 'sendMessage', 'deleteMessage'])
        photo, text = transport.calls[0][1], transport.calls[1][1]
        self.assertEqual(text['text'], 'Open QRIS below')
        self.assertEqual(text['reply_markup'], photo['reply_markup'])
        self.assertNotIn('photo', text)
        self.assertNotIn('caption', text)
        self.assertNotIn('text_fallback', photo)
        self.assertNotIn('text_fallback', text)
        self.assertEqual(roots.finished['media_kind'], 'text')

    def test_ambiguous_checkout_photo_failure_never_sends_fallback(self):
        roots, transport = self.roots(['ambiguous'])
        surface = panel('Scan image above', 'checkout', photo_url=self.QR,
                        text_fallback='Open QRIS below')
        result = roots.render({}, self.root('home', 'photo'), '41001', surface)
        self.assertEqual(result, 'ambiguous')
        self.assertEqual([method for method, _ in transport.calls], ['sendPhoto'])

    def test_relay_classifier_drives_definite_only_photo_fallback(self):
        from lhie_candidate.navigation.transport import RelayTransport

        responses = (
            ({'ok': False, 'error_code': 400, 'description': 'wrong file identifier'}, True),
            ({'ok': False, 'error_code': 500, 'description': 'server error'}, False),
            ({}, False),
        )
        for response, fallback_expected in responses:
            with self.subTest(response=response):
                roots, _ = self.roots()
                relay = RelayTransport()
                roots.transport = relay
                success = {'ok': True, 'result': {'message_id': 123}}
                surface = panel('Scan above', 'checkout', photo_url=self.QR,
                                text_fallback='Open QRIS below')
                with patch.object(relay, '_request', side_effect=[response, success, {'ok': True, 'result': True}]) as request:
                    outcome = roots.render({}, self.root('home', 'photo'), '41001', surface)
                self.assertEqual(outcome, 'replaced' if fallback_expected else 'ambiguous')
                self.assertEqual([call.args[0] for call in request.call_args_list],
                                 ['sendPhoto', 'sendMessage', 'deleteMessage'] if fallback_expected else ['sendPhoto'])


class RecordingTransport:
    def __init__(self, using):
        self.using = using
        self.calls = []
        self.outcome = 'success'
        self.hook = None
        self.next_id = 9000
        self.lock = Lock()

    def execute(self, method, payload):
        connection = connections[self.using]
        if connection.in_atomic_block or not connection.get_autocommit():
            raise AssertionError('Transport called inside a database transaction')
        with self.lock:
            self.calls.append((method, payload))
            self.next_id += 1
            message_id = self.next_id if method.startswith('send') else None
        if self.hook:
            return self.hook(method, payload, message_id)
        return TelegramResult(message_id=message_id, outcome=self.outcome)


def _render_worker(customer, snapshot, scope, using, barrier, results):
    try:
        from lhie_candidate.startup import configure_django
        configure_django()
        transport = RecordingTransport(using)
        roots = NavigationRoots(transport, using=using, banner_reference='test-bot-banner',
                                banner_version=3, bot_asset_scope=scope)
        barrier.wait(timeout=15)
        outcome = roots.render(customer, snapshot, str(customer['telegram_id']), panel('Winner', 'catalog'))
        results.put((outcome, len(transport.calls)))
    except Exception as error:
        results.put((type(error).__name__, 0))
    finally:
        connections[using].close()


def exercise_root_protocol(customer, *, using='default'):
    """Run real PostgreSQL behavioral assertions in the admitted disposable smoke.

    Only this helper's randomly scoped root rows are deleted. The supplied customer
    is read-only and must already belong to public.customers in the disposable DB.
    """
    check = unittest.TestCase()
    connection = connections[using]
    if connection.vendor != 'postgresql' or connection.in_atomic_block or not connection.get_autocommit():
        raise RuntimeError('Root regressions require PostgreSQL autocommit')
    with connection.cursor() as cursor:
        cursor.execute('SELECT current_database(), host(inet_server_addr())')
        if cursor.fetchone() != ('disposable', '127.0.0.1'):
            raise RuntimeError('Refusing root regressions outside the disposable local database')
    scope = 'p06-root-regression-' + uuid4().hex
    transport = RecordingTransport(using)
    roots = NavigationRoots(transport, using=using, banner_reference='test-bot-banner',
                            banner_version=3, bot_asset_scope=scope)
    chat = str(customer['telegram_id'])
    passed = []
    scopes = [scope]

    def current():
        return roots.current_for_chat(customer, chat)

    def expire():
        with connection.cursor() as cursor:
            cursor.execute('UPDATE django_candidate.navigation_roots '
                           "SET lease_expires_at = clock_timestamp() - interval '1 second' "
                           'WHERE bot_asset_scope = %s AND customer_id = %s', [scope, customer['id']])

    try:
        check.assertEqual(roots.establish(customer, chat, panel()), 'established')
        initial = current()
        check.assertEqual((initial.message_id, initial.generation, initial.screen, initial.media_kind),
                          (9001, 1, 'home', 'photo'))
        check.assertIsNone(initial.render_token)
        check.assertEqual(transport.calls[0][0], 'sendPhoto')
        passed.append('establish_ack')

        before = len(transport.calls)
        foreign = dict(customer, telegram_id=customer['telegram_id'] + 1)
        check.assertIsNone(roots.match(customer, chat, initial.message_id + 1))
        check.assertIsNone(roots.current_for_chat(customer, '-123'))
        check.assertIsNone(roots.current_for_chat(foreign, str(foreign['telegram_id'])))
        check.assertEqual(roots.render(foreign, initial, str(foreign['telegram_id']), panel()), 'superseded')
        check.assertEqual(roots.render(customer, replace(initial, bot_asset_scope='foreign-bot'), chat, panel()), 'superseded')
        check.assertEqual(len(transport.calls), before)
        passed.append('private_customer_chat_asset_ownership')

        for outcome, expected in [('not_modified', 'unchanged'), ('ambiguous', 'ambiguous')]:
            before_root = current()
            before = len(transport.calls)
            transport.outcome = outcome
            check.assertEqual(roots.render(customer, before_root, chat, panel('Catalog', 'catalog', 'en')), expected)
            after = current()
            check.assertEqual((after.message_id, after.screen, after.locale, after.rendered_at),
                              (before_root.message_id, before_root.screen, before_root.locale, before_root.rendered_at))
            check.assertEqual(len(transport.calls), before + 1)
            check.assertIsNone(after.render_token)
        passed.append('not_modified_ambiguous_never_advance_or_resend')

        before_root = current()
        transport.outcome = 'ambiguous'
        check.assertEqual(roots.establish(customer, chat, panel('Unproven')), 'ambiguous')
        after = current()
        check.assertEqual((after.message_id, after.screen, after.rendered_at),
                          (before_root.message_id, before_root.screen, before_root.rendered_at))
        passed.append('failed_establish_retains_root')

        def definite_edit(method, payload, message_id):
            return TelegramResult(message_id=message_id, outcome='definite' if method.startswith('edit') else 'success')

        transport.hook = definite_edit
        before = len(transport.calls)
        before_root = current()
        check.assertEqual(roots.render(customer, before_root, chat, panel('Catalog', 'catalog')), 'replaced')
        check.assertEqual([method for method, _ in transport.calls[before:]], ['editMessageCaption', 'sendPhoto'])
        check.assertEqual(transport.calls[-1][1]['photo'], 'test-bot-banner')
        check.assertEqual(current().generation, before_root.generation + 2)
        check.assertNotEqual(current().message_id, before_root.message_id)
        passed.append('definite_edit_exactly_one_replacement')

        transport.hook = None
        transport.outcome = 'success'
        before = len(transport.calls)
        check.assertEqual(roots.render(customer, current(), chat, panel('x' * 1025, 'catalog')), 'replaced')
        check.assertEqual([method for method, _ in transport.calls[before:]], ['sendMessage'])
        check.assertEqual((current().media_kind, current().banner_reference), ('text', 'test-bot-banner'))
        check.assertEqual(roots.establish(customer, chat, panel()), 'established')
        check.assertEqual(transport.calls[-1][0], 'sendPhoto')
        passed.append('long_caption_downgrade_retains_banner')

        before_root = current()

        def expire_edit(method, payload, message_id):
            expire()
            return TelegramResult(message_id=message_id, outcome='success')

        transport.hook = expire_edit
        check.assertEqual(roots.render(customer, before_root, chat, panel('Expired', 'catalog')), 'superseded')
        after = current()
        check.assertEqual((after.message_id, after.screen, after.rendered_at),
                          (before_root.message_id, before_root.screen, before_root.rendered_at))
        passed.append('expired_lease_cannot_commit')

        transport.hook = None
        check.assertEqual(roots.establish(customer, chat, panel()), 'established')
        before_root = current()

        def competing_root(method, payload, message_id):
            transport.hook = None
            expire()
            check.assertEqual(roots.establish(customer, chat, panel('New Home')), 'established')
            return TelegramResult(message_id=message_id, outcome='success')

        transport.hook = competing_root
        check.assertEqual(roots.render(customer, before_root, chat, panel('Old Catalog', 'catalog')), 'superseded')
        check.assertNotEqual(current().message_id, before_root.message_id)
        check.assertEqual(current().screen, 'home')
        check.assertIsNone(current().render_token)
        passed.append('competing_root_fences_old_ack')

        snapshot = current()
        context = multiprocessing.get_context('spawn')
        barrier = context.Barrier(2)
        results = context.Queue()
        workers = [context.Process(target=_render_worker,
                   args=(customer, snapshot, scope, using, barrier, results)) for _ in range(2)]
        try:
            for worker in workers:
                worker.start()
            observed = [results.get(timeout=25) for _ in workers]
            for worker in workers:
                worker.join(timeout=5)
                check.assertEqual(worker.exitcode, 0)
            check.assertEqual(sorted(outcome for outcome, _ in observed), ['applied', 'superseded'])
            check.assertEqual(sum(count for _, count in observed), 1)
        finally:
            for worker in workers:
                if worker.is_alive():
                    worker.terminate()
                worker.join(timeout=5)
            results.close()
            results.join_thread()
        check.assertEqual(current().generation, snapshot.generation + 1)
        check.assertIsNone(current().render_token)
        passed.append('concurrent_generation_single_render_owner')

        snapshot = current()
        before = len(transport.calls)
        transport.outcome = 'ambiguous'
        check.assertEqual(roots.render(customer, snapshot, chat, panel(), refresh=True), 'ambiguous')
        check.assertEqual([method for method, _ in transport.calls[before:]], ['deleteMessage'])
        check.assertEqual(current().message_id, snapshot.message_id)
        transport.outcome = 'success'

        def ambiguous_send(method, payload, message_id):
            return TelegramResult(message_id=message_id, outcome='ambiguous' if method.startswith('send') else 'success')

        transport.hook = ambiguous_send
        before = len(transport.calls)
        check.assertEqual(roots.render(customer, current(), chat, panel(), refresh=True), 'ambiguous')
        check.assertEqual([method for method, _ in transport.calls[before:]], ['deleteMessage', 'sendPhoto'])
        check.assertEqual(current().message_id, snapshot.message_id)
        passed.append('refresh_delete_before_send_without_ambiguous_retry')

        transport.hook = None
        with transaction.atomic(using=using):
            with check.assertRaises(RuntimeError):
                roots.establish(customer, chat, panel())
        passed.append('rejects_outer_transaction')

        transport.outcome = 'definite'
        before = len(transport.calls)
        check.assertEqual(roots.render(customer, current(), chat, panel(), refresh=True), 'definite')
        check.assertEqual([method for method, _ in transport.calls[before:]], ['deleteMessage'])
        passed.append('definite_refresh_delete_does_not_send')

        other_scope = scope + '-other'
        scopes.append(other_scope)
        other_transport = RecordingTransport(using)
        other = NavigationRoots(other_transport, using=using, bot_asset_scope=other_scope,
                                banner_reference='other-bot-animation', banner_version=1, banner_kind='animation')
        check.assertIsNone(other.current_for_chat(customer, chat))
        other_transport.outcome = 'ambiguous'
        check.assertEqual(other.establish(customer, chat, panel()), 'ambiguous')
        check.assertIsNone(other.current_for_chat(customer, chat))
        other_transport.outcome = 'success'
        check.assertEqual(other.establish(customer, chat, panel()), 'established')
        check.assertEqual(other_transport.calls[-1][0], 'sendAnimation')
        check.assertEqual(other_transport.calls[-1][1]['animation'], 'other-bot-animation')
        other_root = other.current_for_chat(customer, chat)
        check.assertEqual(other.render(customer, other_root, chat, panel('Animated catalog', 'catalog')), 'applied')
        check.assertEqual(other_transport.calls[-1][0], 'editMessageCaption')
        check.assertEqual(current().banner_reference, 'test-bot-banner')
        check.assertEqual(other.current_for_chat(customer, chat).banner_reference, 'other-bot-animation')
        passed.append('unproven_first_send_and_animation_bot_scope_isolation')

        checkout_scope = scope + '-checkout'
        scopes.append(checkout_scope)
        checkout_transport = RecordingTransport(using)
        checkout_roots = NavigationRoots(checkout_transport, using=using, bot_asset_scope=checkout_scope)
        for failure in ('definite', 'ambiguous'):
            checkout_transport.hook = None
            check.assertEqual(checkout_roots.establish(
                customer, chat, panel('QR', 'checkout', photo_url=CheckoutMediaTests.QR)), 'established')
            old = checkout_roots.current_for_chat(customer, chat)

            def failed_replacement(method, payload, message_id):
                return TelegramResult(message_id=message_id,
                                      outcome=failure if method.startswith('send') else 'success')

            checkout_transport.hook = failed_replacement
            check.assertEqual(checkout_roots.render(customer, old, chat, panel('Paid', 'checkout')), 'invalidated')
            check.assertIsNone(checkout_roots.current_for_chat(customer, chat))
            check.assertFalse(checkout_roots._invalidate_root(old))
            checkout_transport.hook = None
            check.assertEqual(checkout_roots.establish(customer, chat, panel('Home')), 'established')
        checkout_transport.hook = None
        check.assertEqual(checkout_roots.establish(
            customer, chat, panel('QR', 'checkout', photo_url=CheckoutMediaTests.QR)), 'established')
        old = checkout_roots.current_for_chat(customer, chat)
        checkout_transport.hook = lambda method, payload, message_id: TelegramResult(
            message_id=message_id, outcome='ambiguous')
        before = len(checkout_transport.calls)
        check.assertEqual(checkout_roots.render(customer, old, chat, panel('Paid', 'checkout')), 'invalidated')
        check.assertEqual([method for method, _ in checkout_transport.calls[before:]], ['deleteMessage'])
        check.assertIsNone(checkout_roots.current_for_chat(customer, chat))
        checkout_transport.hook = None
        check.assertEqual(checkout_roots.establish(customer, chat, panel('Home')), 'established')
        passed.append('deleted_checkout_root_is_fenced_clear_on_failed_replacement')
        return {'root_protocol_checks': passed}
    finally:
        with connection.cursor() as cursor:
            cursor.execute('DELETE FROM django_candidate.navigation_roots WHERE bot_asset_scope = ANY(%s)', [scopes])
