"""Run only inside the admitted disposable P01 candidate namespace."""
from datetime import datetime
import json
import os
import re
from zoneinfo import ZoneInfo


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
        'UPDATE public.customer_balance_accounts SET balance=balance',
        'UPDATE public.customers SET auth_version=auth_version',
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
    surfaces = {'home': presenter.home(customer), 'catalog': presenter.catalog(customer,state=state),
        'family': presenter.family(customer,reference['family_id'],state=state),
        'detail': presenter.detail(customer,reference['product_id'],state=state)}
    for name,surface in surfaces.items():
        actual = canonical({'caption':surface.caption,'keyboard':surface.keyboard,'options':surface.options})
        expected = canonical(reference['expected'][name])
        if actual != expected:
            with open('/tmp/p06-parity-diff.json','w') as output:
                json.dump({'surface':name,'actual':actual,'expected':expected},output,ensure_ascii=False,indent=2)
            raise AssertionError('PHP presentation mismatch: ' + name)
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
    def click(update,data):
        receive(callback(update,data))
        assert processor.process(update) in ('applied','replaced')
    def session():
        with connection.cursor() as cursor:
            cursor.execute('SELECT state FROM django_candidate.navigation_sessions WHERE customer_id=%s',[customer['id']])
            stored = cursor.fetchone()[0]
        return json.loads(stored) if isinstance(stored,str) else stored
    click(101,'katalog')
    state = session()
    # Use the actual persisted catalog's signed token, not a freshly rendered token.
    origin = state['catalog']['token']
    family = 'family:options:' + presenter._signed(reference['family_id'],1,1) + ':o' + origin
    click(102,family)
    select = 'family:select:' + presenter._signed(reference['family_id'],reference['product_id'],1,1)
    click(103,select)
    selection = session()['selection']
    click(104,f"detail:qty:+:{reference['product_id']}:{selection['quantity_token']}")
    selection = session()['selection']
    assert selection['quantity'] == 2
    click(105,f"detail:back:{reference['product_id']}:{selection['interaction_token']}")
    click(106,'family:back:menu')
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
        'php_surface_parity':list(surfaces),'webhook_to_screen':['home','catalog','family','detail','quantity','back','home'],
        'duplicate_stale_foreign_burst':'passed','webhook_authentication':'passed',
        'registration':'exact_telegram_identity_replay_passed',
        'bounded_consumer_command':'navigate.py --once rendered accepted Home',
        'transport':'P01 fake relay only','live_testing_bot_qa':'not_run_requires_attested_target',**protocol}


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
