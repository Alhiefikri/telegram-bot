"""Run only inside the admitted disposable P01 candidate namespace."""
from datetime import datetime
import json
import os
import re
import time
from zoneinfo import ZoneInfo


def _profile(function):
    """Measure one render through the same request-boundary connection rules."""
    from django.db import close_old_connections, connection
    close_old_connections()
    reused = connection.connection is not None
    queries = 0
    database_ns = 0

    def wrapper(execute, sql, params, many, context):
        nonlocal queries, database_ns
        queries += 1
        started = time.monotonic_ns()
        try:
            return execute(sql, params, many, context)
        finally:
            database_ns += time.monotonic_ns() - started

    started = time.monotonic_ns()
    with connection.execute_wrapper(wrapper):
        result = function()
    elapsed_ns = time.monotonic_ns() - started
    return result, {
        'queries': queries,
        'db_ms': round(database_ns / 1_000_000, 3),
        'elapsed_ms': round(elapsed_ns / 1_000_000, 3),
        'connection_reused': reused,
        'connection_opened': not reused and connection.connection is not None,
    }


def canonical(value):
    if isinstance(value, dict):
        value = {key: canonical(item) for key,item in value.items()
                 if not key.startswith('_')}
        if 'text' in value and any(key in value for key in ('callback_data','url','disabled')):
            value.setdefault('style','primary')
        if value.get('options') == []:
            value['options'] = {}
        return value
    if isinstance(value, list):
        return [canonical(item) for item in value]
    if isinstance(value, str):
        value = re.sub(r':([os])[a-f0-9]{16}', r':\1TOKEN', value)
        value = re.sub(r':[a-f0-9]{16}(?=:|$)', ':TOKEN', value)
        value = re.sub(r'(balance:a:[ond]:)[A-Za-z0-9_-]+', r'\1TOKEN', value)
        return value
    return value


def run():
    if os.environ.get('P06_NAVIGATION_SMOKE') != 'disposable-local-only':
        raise RuntimeError('Explicit disposable opt-in required')
    from lhie_candidate.startup import configure_django
    configure_django()
    from django.db import connection
    import urllib.request
    import urllib.error
    from lhie_candidate.readiness import verify_dependencies
    from lhie_candidate.navigation.webhook import webhook_secret
    from lhie_candidate.navigation.repository import CatalogRepository
    from lhie_candidate.navigation.presentation import CatalogPresenter
    from lhie_candidate.navigation.processor import NavigationProcessor
    from lhie_candidate.navigation.roots import NavigationRoots
    from lhie_candidate.navigation.transport import RelayTransport
    from tests.test_navigation_roots import exercise_root_protocol
    from tests.test_navigation_ingress import message
    verify_dependencies()
    with connection.cursor() as cursor:
        cursor.execute('SELECT current_database(),current_user,host(inet_server_addr())')
        assert cursor.fetchone() == ('disposable','candidate_runtime','127.0.0.1')
    from django.db.utils import ProgrammingError
    for denied in (
        'SELECT password FROM public.customers',
        'SELECT * FROM public.product_stock_items',
        'SELECT total_amount FROM public.orders',
        'SELECT idempotency_key FROM public.store_balance_entries',
        'UPDATE public.customer_balance_accounts SET balance=balance',
        'UPDATE public.customer_usdt_balance_accounts SET balance_micro=balance_micro',
        'UPDATE public.customer_usdt_balance_entries SET amount_micro=amount_micro',
        "INSERT INTO public.store_balance_entries (customer_balance_account_id,kind,amount,balance_after,source_type,source_id,idempotency_key,created_at) VALUES (1,'purchase_debit',1,1,'fixture','denied','denied',now())",
        'DELETE FROM public.customer_usdt_balance_entries WHERE FALSE',
        'SELECT provider_payload FROM public.store_balance_top_ups',
        'UPDATE public.customers SET auth_version=auth_version',
        'UPDATE public.orders SET order_status=order_status',
        "INSERT INTO public.orders (id,customer_id,invoice_number,order_status,payment_status,total_amount,currency,created_at,updated_at) VALUES (gen_random_uuid(),1,'DENIED','processing','paid',0,'IDR',now(),now())",
        'DELETE FROM public.orders WHERE FALSE',
        'UPDATE public.payments SET status=status',
        'DELETE FROM public.telegram_outbound_effects WHERE FALSE',
        'UPDATE public.store_balance_entries SET balance_after=balance_after',
        'CREATE TABLE public.p06_forbidden (id integer)',
    ):
        try:
            with connection.cursor() as cursor:
                cursor.execute(denied)
        except ProgrammingError as error:
            assert error.__cause__.sqlstate == '42501'
        else:
            raise AssertionError('Navigation runtime exceeded column grants')
    import sys
    reference = json.load(sys.stdin)
    repository = CatalogRepository()
    presenter = CatalogPresenter(repository, signing_key='a'*64,
        clock=lambda: datetime(2026,11,1,10,0,tzinfo=ZoneInfo('Asia/Jakarta')))
    customer = repository.customer(1)
    state = {}
    surfaces, profile = {}, {}
    screens = {'home': lambda: presenter.home(customer),
        'catalog': lambda: presenter.catalog(customer,state=state),
        'family': lambda: presenter.family(customer,reference['family_id'],state=state),
        'detail': lambda: presenter.detail(customer,reference['product_id'],state=state),
        'orders': lambda: presenter.orders(customer),
        'balance': lambda: presenter.balance(customer),
        'balance_activity': lambda: presenter.balance_activity(customer)}
    for name, render in screens.items():
        surfaces[name], profile[name] = _profile(render)
    for name,surface in surfaces.items():
        actual = canonical({'caption':surface.caption,'keyboard':surface.keyboard,'options':surface.options})
        expected = canonical(reference['expected'][name])
        if actual != expected:
            with open('/tmp/p06-parity-diff.json','w') as output:
                json.dump({'surface':name,'actual':actual,'expected':expected},output,ensure_ascii=False,indent=2)
            raise AssertionError('PHP presentation mismatch: ' + name)
    next_orders = next(button['callback_data']
        for row in reference['expected']['orders']['keyboard']['inline_keyboard'] for button in row
        if button.get('callback_data','').startswith('orders:older:'))
    orders_next = presenter.route(customer,next_orders,{})
    actual = canonical({'caption':orders_next.caption,'keyboard':orders_next.keyboard,'options':orders_next.options})
    expected = canonical(reference['expected']['orders_next'])
    if actual != expected:
        with open('/tmp/p06-parity-diff.json','w') as output:
            json.dump({'surface':'orders_next','actual':actual,'expected':expected},output,ensure_ascii=False,indent=2)
        raise AssertionError('PHP presentation mismatch: orders_next')
    history = repository.order_history(customer['id'])
    if [row['invoice_number'] for row in history[:10]] != [f'INV-P06-{index:03d}' for index in range(1,11)]:
        raise AssertionError('Orders keyset order is not newest-first')
    older = repository.order_history(customer['id'],direction='older',
        created_token=history[9]['created_token'],order_id=history[9]['id'])
    if [row['invoice_number'] for row in older] != ['INV-P06-011','INV-P06-012']:
        raise AssertionError('Orders keyset older page is incorrect')
    next_balance = next(button['callback_data']
        for row in surfaces['balance_activity'].keyboard['inline_keyboard'] for button in row
        if button.get('callback_data','').startswith('balance:a:o:'))
    balance_next = presenter.route(customer,next_balance,{})
    actual = canonical({'caption':balance_next.caption,'keyboard':balance_next.keyboard,'options':balance_next.options})
    expected = canonical(reference['expected']['balance_activity_next'])
    if actual != expected:
        with open('/tmp/p06-parity-diff.json','w') as output:
            json.dump({'surface':'balance_activity_next','actual':actual,'expected':expected},output,ensure_ascii=False,indent=2)
        raise AssertionError('PHP presentation mismatch: balance_activity_next')
    account_id = repository.balance_account_id(customer['id'])
    ledger = repository.balance_activity(account_id)
    if len(ledger) != 11 or [row['occurred_micros'] for row in ledger] != sorted(
            (row['occurred_micros'] for row in ledger),reverse=True):
        raise AssertionError('Balance ledger keyset order is not newest-first')
    ledger_older = repository.balance_activity(account_id,direction='older',
        occurred_micros=ledger[9]['occurred_micros'],entry_id=ledger[9]['id'])
    if len(ledger_older) != 4 or {row['id'] for row in ledger_older} & {row['id'] for row in ledger[:10]}:
        raise AssertionError('Balance ledger older page is incorrect')
    usdt_ledger = repository.usdt_activity(customer['id'])
    if [(row['kind'],row['amount_micro']) for row in usdt_ledger] != [
            ('funding_credit',3750000),('purchase_debit',1250000)]:
        raise AssertionError('USDT ledger projection is incorrect')
    funding = presenter.route(customer,'balance:add',{})
    if funding is not None or not presenter.callback_handled or 'Saldo tidak berubah' not in presenter.callback_notice:
        raise AssertionError('Unimplemented funding action did not give explicit feedback')
    detail = next(button['callback_data']
        for row in surfaces['balance_activity'].keyboard['inline_keyboard'] for button in row
        if button.get('callback_data','').startswith('balance:a:d:'))
    if presenter.route(customer,detail,{}) is not None or not presenter.callback_handled:
        raise AssertionError('Unimplemented Balance detail did not give explicit feedback')
    transport = RelayTransport()
    roots = NavigationRoots(transport,bot_asset_scope='synthetic')
    processor = NavigationProcessor(repository,presenter,roots,transport)
    def receive(payload, authenticated=True):
        headers = {'Content-Type':'application/json'}
        if authenticated:
            headers['X-Telegram-Bot-Api-Secret-Token'] = webhook_secret()
        request = urllib.request.Request('http://127.0.0.1:8080/webhook/telegram',
            json.dumps(payload).encode(),headers,method='POST')
        try:
            result = urllib.request.urlopen(request,timeout=5)
        except urllib.error.HTTPError as error:
            result = error
        with result:
            status = result.status
            response = json.loads(result.read(4096))
        assert status == (200 if authenticated else 403)
        return response
    receive(message(100))
    receive(message(100))
    assert processor.process(100) == 'established'
    assert processor.process(100) == 'duplicate'
    verify_dependencies()
    root = roots.current_for_chat(customer,'1')
    assert root.screen == 'home'
    def callback(update,data,*,message_id=None,actor=1):
        return {'update_id':update,'callback_query':{'id':str(update),'from':{'id':actor},
            'data':data,'message':{'message_id':message_id or roots.current_for_chat(customer,'1').message_id,
            'chat':{'id':1,'type':'private'}}}}
    def click(update,data,*,profile_name=None):
        receive(callback(update,data))
        outcome, measured = _profile(lambda: processor.process(update))
        assert outcome in ('applied','replaced')
        if profile_name:
            profile[profile_name] = measured
    def session():
        with connection.cursor() as cursor:
            cursor.execute('SELECT state FROM django_candidate.navigation_sessions WHERE customer_id=%s',[customer['id']])
            stored = cursor.fetchone()[0]
        return json.loads(stored) if isinstance(stored,str) else stored
    click(101,'katalog',profile_name='route_catalog')
    state = session()
    # Use the actual persisted catalog's signed token, not a freshly rendered token.
    origin = state['catalog']['token']
    family = 'family:options:' + presenter._signed(reference['family_id'],1,1) + ':o' + origin
    click(102,family,profile_name='route_family')
    select = 'family:select:' + presenter._signed(reference['family_id'],reference['product_id'],1,1)
    click(103,select,profile_name='route_detail')
    selection = session()['selection']
    click(104,f"detail:qty:+:{reference['product_id']}:{selection['quantity_token']}",profile_name='route_quantity')
    selection = session()['selection']
    assert selection['quantity'] == 2
    click(105,f"detail:back:{reference['product_id']}:{selection['interaction_token']}",profile_name='route_back')
    click(106,'family:back:menu',profile_name='route_home')
    receive(callback(107,'katalog',message_id=999999))
    assert processor.process(107) == 'stale'
    receive(callback(108,'katalog',actor=2))
    assert processor.process(108) == 'foreign'
    # Same-chat accepted bursts cannot overtake their earlier pending update.
    receive(callback(109,'katalog'))
    receive(callback(110,'family:back:menu'))
    assert processor.process(110) == 'waiting'
    assert processor.process(109) in ('applied','replaced')
    assert processor.process(110) in ('applied','replaced')
    assert roots.current_for_chat(customer,'1').screen == 'home'
    receive(message(111),authenticated=False)
    receive(message(99))
    assert processor.process(99) == 'superseded'
    previous_home = roots.current_for_chat(customer,'1')
    receive(message(112))
    assert processor.process(112) == 'established'
    assert roots.current_for_chat(customer,'1').message_id != previous_home.message_id
    receive(callback(113,'katalog',message_id=previous_home.message_id))
    assert processor.process(113) == 'stale'
    click(114,'menu:orders')
    assert roots.current_for_chat(customer,'1').screen == 'orders'
    receive(callback(115,'menu:orders',message_id=999999))
    assert processor.process(115) == 'stale'
    click(116,'menu:back')
    assert roots.current_for_chat(customer,'1').screen == 'home'
    click(117,'balance')
    assert roots.current_for_chat(customer,'1').screen == 'balance'
    receive(callback(118,'balance',message_id=999999))
    assert processor.process(118) == 'stale'
    receive(callback(119,'balance',actor=2))
    assert processor.process(119) == 'foreign'
    receive(callback(120,'balance:add'))
    assert processor.process(120) == 'acknowledged'
    assert roots.current_for_chat(customer,'1').screen == 'balance'
    click(121,'menu:back')
    assert roots.current_for_chat(customer,'1').screen == 'home'
    protocol = exercise_root_protocol(customer)
    from lhie_candidate.navigation.customers import resolve_customer
    second = resolve_customer(repository,{'id':2,'first_name':'Synthetic Second'})
    replay = resolve_customer(repository,{'id':2,'first_name':'Synthetic Renamed'})
    assert second['id'] == replay['id'] != customer['id']
    assert replay['first_name'] == 'Synthetic Renamed'
    assert repository.customer(1)['id'] == customer['id']
    verify_dependencies()
    import subprocess
    receive(message(200))
    command = subprocess.run([sys.executable,'navigate.py','--once'],capture_output=True,text=True,timeout=20)
    assert command.returncode == 0
    assert json.loads(command.stdout) == {'status':'processed','outcomes':['established']}
    assert NavigationRoots(transport,bot_asset_scope='LhieStoreTestBot').current_for_chat(customer,'1').screen == 'home'
    return {'status':'passed','dataset':'p06-synthetic-repository-schema-v1',
        'php_surface_parity':list(surfaces),'orders_parity':['first_page','older_page','stale','back'],
        'balance_parity':['screen','recent_activity','ledger_first_page','ledger_older_page',
                          'usdt_ledger','funding_rejected','detail_rejected','stale','foreign'],
        'webhook_to_screen':['home','catalog','family','detail','quantity','back','home','orders','balance','home'],
        'duplicate_stale_foreign_burst':'passed','webhook_authentication':'passed',
        'registration':'exact_telegram_identity_replay_passed',
        'bounded_consumer_command':'navigate.py --once rendered accepted Home',
        'transport':'P01 fake relay only','live_testing_bot_qa':'not_run_requires_attested_target',
        'profile':profile,**protocol}


if __name__ == '__main__':
    try:
        print(json.dumps(run(),indent=2))
    except Exception as error:
        import traceback
        frame = traceback.extract_tb(error.__traceback__,limit=100)[-1]
        diagnostic = {'status':'failed','error_type':type(error).__name__,
                      'file':os.path.basename(frame.filename),'line':frame.lineno}
        diagnostic['frames'] = [{'file':os.path.basename(item.filename),'line':item.lineno}
                                for item in traceback.extract_tb(error.__traceback__,limit=100)]
        diagnostic['sqlstate'] = getattr(error.__cause__,'sqlstate',None)
        if diagnostic['sqlstate'] in ('42501','42703','42P01','42601'):
            diagnostic['database_error'] = error.__cause__.diag.message_primary
        if os.path.isfile('/tmp/p06-parity-diff.json'):
            diagnostic['parity'] = json.load(open('/tmp/p06-parity-diff.json'))
        print(json.dumps(diagnostic,ensure_ascii=False))
        raise SystemExit(1) from None
