"""Competing Order and Store Balance claims in the disposable P15 database."""
import multiprocessing
import os


REFERENCE = 'p15-cross-namespace-race'


def _claim(target, barrier, outcomes):
    try:
        from lhie_candidate.startup import configure_django
        configure_django()
        from django.db import connections, transaction
        from lhie_candidate.checkout.db import Database
        from lhie_candidate.payments.receipts import claim_provider_reference
        barrier.wait(timeout=15)
        with transaction.atomic():
            claim = claim_provider_reference(Database(), provider='sakurupiah', reference=REFERENCE,
                beneficiary=target['beneficiary'], currency='IDR', amount=10000,
                target_type=target['type'], target_id=target['id'], account_namespace=target['namespace'])
        outcomes.put((target['type'], claim.status))
        connections.close_all()
    except Exception as error:
        outcomes.put(('error', type(error).__name__))


def exercise_receipt_race():
    if os.environ.get('P719_FUNDING_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable funding opt-in required')
    from django.db import connections, transaction
    from lhie_candidate.checkout.db import Database
    from lhie_candidate.payments.receipts import claim_provider_reference
    db = Database()
    identity = db.one('SELECT current_database() AS db,current_user AS role,host(inet_server_addr()) AS host')
    if identity != {'db': 'disposable', 'role': 'candidate_runtime', 'host': '127.0.0.1'}:
        raise RuntimeError('Disposable funding identity mismatch')
    targets = [
        {'type': 'order_payment', 'id': 'p15-order', 'namespace': 'default', 'beneficiary': 'order:p15-order'},
        {'type': 'balance_top_up', 'id': 'p15-topup', 'namespace': 'setting:1', 'beneficiary': 'customer:1'},
    ]
    connections.close_all()
    context = multiprocessing.get_context('spawn')
    barrier, outcomes = context.Barrier(3), context.Queue()
    workers = [context.Process(target=_claim, args=(target, barrier, outcomes)) for target in targets]
    for worker in workers:
        worker.start()
    try:
        barrier.wait(timeout=15)
        result = [outcomes.get(timeout=30) for _ in workers]
        for worker in workers:
            worker.join(timeout=10)
            assert worker.exitcode == 0
        assert sorted(status for _, status in result) == ['claimed', 'review'], result
        winner = next(target for target in targets if (target['type'], 'claimed') in result)
        with transaction.atomic():
            replay = claim_provider_reference(db, provider='sakurupiah', reference=REFERENCE,
                beneficiary=winner['beneficiary'], currency='IDR', amount=10000,
                target_type=winner['type'], target_id=winner['id'], account_namespace=winner['namespace'])
        assert replay.status == 'replayed'
        row = db.one("SELECT count(*) AS n FROM public.external_money_receipts WHERE provider='sakurupiah' AND reference=%s", (REFERENCE,))
        assert row['n'] == 1
        return {'race': 'one_claimed_one_review', 'winner_replay': 'replayed', 'claims': row['n']}
    finally:
        for worker in workers:
            if worker.is_alive():
                worker.kill()
                worker.join()
        outcomes.close()
