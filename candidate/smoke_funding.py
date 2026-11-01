"""Disposable #719 proof: sandbox funding intent, top-up, settlement and race.

Run only in the admitted P01 candidate container namespace:

    P719_FUNDING_SMOKE=disposable-local-only python smoke_funding.py

The provider is a local fake; no network, real money, credential or production
target is used. The script never installs schema or grants.
"""

from datetime import datetime, timedelta
from types import SimpleNamespace
import json
import multiprocessing
import os
from zoneinfo import ZoneInfo

from lhie_candidate.payments.sakurupiah import CreateRequest, InvoiceIdentity, OutcomeState, ProviderOutcome


OPT_IN = 'disposable-local-only'
AMOUNT = 10_000
FEE = 200
QRIS = 'https://sakurupiah.id/qr/synthetic.png'


class FakeProvider:
    def __init__(self, *, reference='trx-synthetic-1', state=OutcomeState.SUCCESS, amount=AMOUNT):
        self.reference = reference
        self.state = state
        self.amount = amount
        self.creates = 0
        self.checks = 0

    def create(self, request):
        self.creates += 1
        return ProviderOutcome(request.identity, OutcomeState.CREATED, self.reference,
                               request.identity.amount + FEE, FEE,
                               (datetime.now(ZoneInfo('Asia/Jakarta')) + timedelta(minutes=10)).isoformat(timespec='seconds'),
                               QRIS)

    def status(self, identity, provider_ref, *, provider_total):
        self.checks += 1
        state = self.state
        return ProviderOutcome(InvoiceIdentity(identity.merchant_ref, self.amount, identity.channel),
                               state, provider_ref, reason=state.value)

    def recover(self, identity, *, provider_total):
        return self.status(identity, self.reference, provider_total=provider_total)


def request_factory(topup):
    return CreateRequest(
        InvoiceIdentity(topup['merchant_ref'], int(topup['amount']), topup['sakurupiah_channel']),
        '08123456789', 'https://sakurupiah.id/callback/synthetic',
        'https://sakurupiah.id/return/synthetic')


def service(provider):
    from lhie_candidate.compat.crypto import LaravelEncrypter
    from lhie_candidate.funding.service import FundingService
    from lhie_candidate.runtime import application_key
    key = application_key()
    return FundingService(provider=provider, request_factory=request_factory,
                          encrypter=LaravelEncrypter(key), callback_key=key)


def root():
    from django.db import connection
    with connection.cursor() as cursor:
        cursor.execute("SELECT bot_asset_scope,customer_id,chat_id,message_id "
                       "FROM django_candidate.navigation_roots WHERE customer_id=1")
        scope, customer_id, chat_id, message_id = cursor.fetchone()
    return SimpleNamespace(bot_asset_scope=scope, customer_id=customer_id,
                           chat_id=chat_id, message_id=message_id)


def customer():
    from django.db import connection
    with connection.cursor() as cursor:
        cursor.execute("SELECT id,telegram_id,locale FROM public.customers WHERE telegram_id=1")
        row = cursor.fetchone()
    if row is None:
        raise AssertionError('synthetic Customer missing')
    return {'id': row[0], 'telegram_id': row[1], 'locale': row[2]}


def counts():
    from django.db import connection
    with connection.cursor() as cursor:
        cursor.execute("""SELECT
            (SELECT COUNT(*) FROM public.store_balance_entries e
             JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
             WHERE a.customer_id=1 AND e.kind='top_up_credit'),
            (SELECT COALESCE(SUM(e.amount),0) FROM public.store_balance_entries e
             JOIN public.customer_balance_accounts a ON a.id=e.customer_balance_account_id
             WHERE a.customer_id=1 AND e.kind='top_up_credit'),
            (SELECT balance FROM public.customer_balance_accounts WHERE customer_id=1),
            (SELECT COUNT(*) FROM public.external_money_receipts
             WHERE target_type='balance_top_up')""")
        return cursor.fetchone()


def _race_child(topup_id, barrier, results):
    try:
        from lhie_candidate.startup import configure_django
        configure_django()
        provider = FakeProvider()
        svc = service(provider)
        row = svc.top_up_by_id(topup_id)
        barrier.wait(timeout=15)
        outcome = svc.check(row)
        results.put(('ok', outcome['status'] if isinstance(outcome, dict) else 'row'))
        from django.db import connections
        connections.close_all()
    except Exception as error:
        results.put(('failed', type(error).__name__))


def race(topup_id):
    context = multiprocessing.get_context('spawn')
    barrier = context.Barrier(3)
    results = context.Queue()
    processes = [context.Process(target=_race_child, args=(topup_id, barrier, results))
                 for _ in range(2)]
    for process in processes:
        process.start()
    barrier.wait(timeout=15)
    observed = [results.get(timeout=20) for _ in processes]
    for process in processes:
        process.join(timeout=10)
        if process.exitcode != 0:
            raise AssertionError('funding race process did not exit cleanly')
    if any(status == 'failed' for status, _ in observed):
        raise AssertionError('funding race failed: ' + repr(observed))
    return observed


def run():
    if os.environ.get('P719_FUNDING_SMOKE') != OPT_IN:
        raise RuntimeError('Explicit disposable funding opt-in required')
    from lhie_candidate.startup import configure_django
    configure_django()
    from django.db import connection
    from lhie_candidate.readiness import verify_dependencies
    verify_dependencies()
    with connection.cursor() as cursor:
        cursor.execute('SELECT current_database(),current_user,host(inet_server_addr())')
        if cursor.fetchone() != ('disposable', 'candidate_runtime', '127.0.0.1'):
            raise AssertionError('non-disposable funding identity')
    if counts()[:1] != (0,):
        raise AssertionError('funding smoke requires a fresh synthetic customer')
    opening_balance = counts()[2]
    customer_row, root_row = customer(), root()
    provider = FakeProvider()
    svc = service(provider)

    opening = svc.open_intent(customer_row, root_row, raw_amount=str(AMOUNT))
    assert opening.state == 'opened', opening.state
    claim = svc.claim(customer_row, root_row, nonce=opening.intent['nonce'], action='confirm')
    assert claim.state == 'confirmed', claim.state
    created = svc.create_top_up(customer_row, root_row, claim.intent['id'])
    assert created.state == 'payable', created.state
    assert created.topup['status'] == 'payable'
    assert created.topup['provider_total_payment'] == AMOUNT + FEE
    view = svc.presentation(created.topup)
    assert view['payable'] and view['qris_url'] == QRIS

    # A provider observation that is still pending cannot change money.
    provider.state = OutcomeState.PENDING
    pending = svc.check(created.topup)
    assert pending['status'] == 'payable', pending['status']
    assert counts() == (0, 0, opening_balance, 0), counts()
    provider.state = OutcomeState.SUCCESS
    paid = svc.check(created.topup)
    assert paid['status'] == 'paid', paid['status']
    assert counts() == (1, AMOUNT, opening_balance + AMOUNT, 1), counts()
    assert svc.check(paid)['status'] == 'paid'
    assert counts() == (1, AMOUNT, opening_balance + AMOUNT, 1), counts()
    assert svc.presentation(paid)['paid']

    # Two independent workers observing the same paid target can never credit
    # twice; the account lock, receipt claim and ledger source claim all agree.
    race(paid['id'])
    assert counts() == (1, AMOUNT, opening_balance + AMOUNT, 1), counts()

    # A second target reusing the first provider reference is held without a
    # second credit because receipt ownership is global.
    provider2 = FakeProvider(reference=paid['provider_reference'])
    svc2 = service(provider2)
    opening2 = svc2.open_intent(customer_row, root_row, raw_amount='25000')
    claim2 = svc2.claim(customer_row, root_row, nonce=opening2.intent['nonce'], action='confirm')
    created2 = svc2.create_top_up(customer_row, root_row, claim2.intent['id'])
    held = svc2.check(created2.topup)
    assert held['status'] == 'invalid_provider_result', held['status']
    assert counts() == (1, AMOUNT, opening_balance + AMOUNT, 1), counts()

    # The active runtime role cannot read provider payloads / QR ciphertext,
    # alter a top-up identity/amount, or write the ledger outside the owner.
    from django.db import DatabaseError
    denied = (
        'SELECT provider_payload FROM public.store_balance_top_ups',
        'UPDATE public.store_balance_top_ups SET amount=1 WHERE id=%s' % paid['id'],
        'UPDATE public.external_money_receipts SET status=status',
        'DELETE FROM public.store_balance_entries WHERE FALSE',
        'UPDATE public.store_balance_entries SET amount=amount',
        'UPDATE public.customer_balance_accounts SET created_at=created_at',
    )
    for sql in denied:
        try:
            with connection.cursor() as cursor:
                cursor.execute(sql)
        except DatabaseError as error:
            assert error.__cause__.sqlstate == '42501', (sql, error.__cause__.sqlstate)
        else:
            raise AssertionError('funding runtime exceeded grants: ' + sql)
    return {
        'status': 'passed', 'topup_id': str(paid['id']),
        'provider_reference': paid['provider_reference'],
        'credited': AMOUNT, 'balance_after': opening_balance + AMOUNT,
        'ledger_entries': 1, 'receipt_claims': 1,
        'race_processes': 2, 'replay_duplicate_credit': 0,
        'global_receipt_conflict': 'invalid_provider_result',
        'provider_creates': provider.creates, 'provider_checks': provider.checks,
        'limits': ['Fake provider only; no network, real money, custody or provider activation',
                   'Disposable synthetic Customer/root/policy; grants applied outside startup'],
    }


if __name__ == '__main__':
    print(json.dumps(run(), indent=2))
