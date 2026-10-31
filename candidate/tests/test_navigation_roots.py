"""Root protocol regressions; exercise_root_protocol runs on a provisioned local DB.

The parent disposable PostgreSQL smoke supplies a real legacy customer and installs
navigation/schema.sql. No SQL is mocked, no fixture DDL is implicit, and no real
Telegram transport is used. Every transport call checks that no transaction is open.
"""

import multiprocessing
from dataclasses import replace
from threading import Lock
import unittest
from uuid import uuid4

from django.db import connections, transaction

from lhie_candidate.navigation.roots import NavigationRoots, fits_caption
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
        return {'root_protocol_checks': passed}
    finally:
        with connection.cursor() as cursor:
            cursor.execute('DELETE FROM django_candidate.navigation_roots WHERE bot_asset_scope = ANY(%s)', [scopes])
