#!/usr/bin/env python3
"""P07 shared PostgreSQL proof using P01 isolation and the P03 PHP schema oracle."""
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import secrets
import time

import harness
from smoke import container, docker

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT / 'tools/migration_compat'))
from schema_rehearsal import php

def cli(*args):
    # Reuse P01 admission/lifecycle in a separate prefix/state; leave the owner's
    # attested Testing Bot config and retained candidate volumes untouched.
    entry = ("import sys; from pathlib import Path; import harness; "
             "harness.STATE=Path(sys.argv[1]); harness.PREFIX=sys.argv[2]; harness.IMAGE=sys.argv[3]; "
             "sys.argv=[harness.__file__,*sys.argv[4:]]; harness.main()")
    result = subprocess.run([sys.executable,'-c',entry,str(harness.STATE),harness.PREFIX,harness.IMAGE,*args],
        cwd=ROOT / 'tools/migration_harness',capture_output=True,text=True,timeout=180)
    if result.returncode:
        raise RuntimeError('P07 disposable lifecycle command failed: ' + ' '.join(args))
    return result.stdout.strip()


def notification_browser(output):
    """Offline, redacted HTML only; reuse P25's output and single manifest."""
    import importlib.metadata
    from playwright.sync_api import sync_playwright
    observations = []
    # Use an already installed local browser, never download during the proof.
    shells = sorted((Path.home() / '.cache/ms-playwright').glob('chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell'))
    if not shells:
        raise RuntimeError('P25 browser proof requires an installed local Chromium')
    executable = shells[-1]
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=str(executable))
        browser_version = browser.version
        try:
            for width in (390, 1280):
                context = browser.new_context(viewport={'width': width, 'height': 900}, offline=True,
                                              service_workers='block', java_script_enabled=False)
                try:
                    context.route('**/*', lambda route: route.abort())
                    page = context.new_page()
                    images = {}
                    variants = ('magic', 'email_verification', 'email_otp')
                    names = ('milestone', 'php-milestone', 'auth', 'php-auth', 'waitlist', 'credential',
                             'php-credential', 'credential-variant', 'php-credential-variant')
                    names += tuple(prefix + variant for variant in variants for prefix in ('auth-', 'php-auth-'))
                    for name in names:
                        page.set_content((output / ('preview-' + name + '.html')).read_text())
                        # Layout inspection uses the browser engine, not app JS.
                        overflow = page.evaluate('document.documentElement.scrollWidth > window.innerWidth')
                        if overflow:
                            raise AssertionError('P25 mail preview overflows: ' + name + ' at ' + str(width))
                        links = page.locator('a')
                        for index in range(links.count()):
                            link = links.nth(index)
                            link.focus()
                            if not link.evaluate('(element) => document.activeElement === element'):
                                raise AssertionError('P25 mail link cannot receive focus: ' + name)
                            link.blur()
                        page.set_content((output / ('preview-' + name + '.html')).read_text())
                        page.evaluate('window.scrollTo(0, 0)')
                        screenshot = page.screenshot(full_page=True)
                        images[name] = screenshot
                        (output / f'preview-{name}-{width}.png').write_bytes(screenshot)
                        observations.append({'template': name, 'width': width, 'overflow': False, 'links_focusable': True})
                    if images['milestone'] != images['php-milestone']:
                        raise AssertionError('P25 milestone PHP/Django pixels differ at ' + str(width))
                    if images['credential'] != images['php-credential']:
                        raise AssertionError('P25 credential PHP/Django pixels differ at ' + str(width))
                    if images['credential-variant'] != images['php-credential-variant']:
                        raise AssertionError('P25 credential variant PHP/Django pixels differ at ' + str(width))
                    if images['auth'] != images['php-auth']:
                        raise AssertionError('P25 auth PHP/Django pixels differ at ' + str(width))
                    for variant in variants:
                        if images['auth-' + variant] != images['php-auth-' + variant]:
                            raise AssertionError('P25 auth PHP/Django pixels differ for ' + variant + ' at ' + str(width))
                finally:
                    context.close()
        finally:
            browser.close()
    return {'playwright': importlib.metadata.version('playwright'), 'mode': 'offline-redacted-static-html',
            'chromium': browser_version, 'executable_sha256': hashlib.sha256(executable.read_bytes()).hexdigest(),
            'milestone_php_pixels': 'identical', 'credential_php_pixels': 'identical',
            'auth_php_pixels': 'identical_all_four_purposes', 'observations': observations,
            'limits': 'Chromium mail previews only; not SMTP-client rendering, live browser flows or device acceptance'}

def local_storefront_proxy():
    """Forward the signed loopback origin to the quarantined candidate port."""
    import contextlib
    import http.client
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
    import threading

    class Proxy(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def forward(self):
            connection = http.client.HTTPConnection('127.0.0.1', 18802, timeout=10)
            try:
                length = int(self.headers.get('Content-Length', '0'))
                if length < 0 or length > 131072:
                    self.send_error(413)
                    return
                body = self.rfile.read(length) if length else None
                headers = {key: value for key, value in self.headers.items()
                           if key.lower() not in ('connection', 'transfer-encoding')}
                connection.request(self.command, self.path, body=body, headers=headers)
                response = connection.getresponse()
                data = response.read()
                self.send_response(response.status)
                for key, value in response.getheaders():
                    if key.lower() not in ('connection', 'transfer-encoding', 'content-length'):
                        self.send_header(key, value)
                size = response.getheader('Content-Length') if self.command == 'HEAD' else str(len(data))
                self.send_header('Content-Length', size or '0')
                self.end_headers()
                if self.command != 'HEAD':
                    self.wfile.write(data)
            finally:
                connection.close()

        do_GET = forward
        do_POST = forward
        do_HEAD = forward

    class QuietServer(ThreadingHTTPServer):
        def handle_error(self, request, client_address):
            pass

    @contextlib.contextmanager
    def run():
        server = QuietServer(('127.0.0.1', 18801), Proxy)
        thread = threading.Thread(target=server.serve_forever, kwargs={'poll_interval': 0.1}, daemon=True)
        thread.start()
        try:
            yield
        finally:
            server.shutdown()
            thread.join(timeout=5)
            server.server_close()
    return run()



def waitlist_browser(output, control_key, admin_password):
    """Drive real storefront routes through the candidate's disposable HTTP port."""
    from playwright.sync_api import sync_playwright
    fixture = json.loads((output / 'waitlist-browser-ready.json').read_text())
    def stage(name):
        (output / 'waitlist-browser-stage.txt').write_text(name)
    shells = sorted((Path.home() / '.cache/ms-playwright').glob('chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell'))
    if not shells:
        raise RuntimeError('P25 browser proof requires an installed local Chromium')
    with local_storefront_proxy(), sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=str(shells[-1]))
        try:
            context = browser.new_context(service_workers='block')
            try:
                def route_request(route):
                    original = route.request.url
                    if not original.startswith('http://127.0.0.1:18801/'):
                        route.abort()
                        return
                    route.continue_()
                context.route('**/*', route_request)
                page = context.new_page()
                from urllib.parse import urlsplit
                responses = []
                def remember(response):
                    path = urlsplit(response.url).path
                    if path.startswith(('/produk/', '/waitlist/', '/admin')):
                        responses.append({'path': path, 'status': response.status, 'method': response.request.method})
                        (output / 'waitlist-browser-http.json').write_text(json.dumps(responses))
                page.on('response', remember)
                url = 'http://127.0.0.1:18801/produk/' + str(fixture['product_id'])
                stage('product')
                page.goto(url, wait_until='domcontentloaded')
                status = page.evaluate("""async url => (await fetch(url + '/waitlist', {
                    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'email=browser%40example.invalid'})).status""", url)
                if status != 403:
                    raise AssertionError('Waitlist accepted a request without CSRF')
                stage('submit')
                page.locator('#waitlist-email').fill('browser@example.invalid')
                page.locator('#waitlist button[type=submit]').click()
                stage('submit-result')
                page.locator('#waitlist [role=status]').wait_for()
                def operation(name):
                    return page.evaluate("""async ([name, key]) => {
                        const response = await fetch('/__p25_browser/' + name, {
                            method: 'POST', headers: {'X-P25-Control': key}});
                        if (!response.ok) throw new Error('browser operation rejected');
                        return (await response.json()).url;
                    }""", [name, control_key])
                stage('confirmation-mail')
                confirmation = operation('confirmation')
                stage('confirmation-page')
                page.goto(confirmation, wait_until='domcontentloaded')
                page.get_by_role('heading', name='Permintaan dikonfirmasi').wait_for()
                stage('restock-mail')
                unsubscribe = operation('restock')
                stage('unsubscribe-page')
                page.goto(unsubscribe, wait_until='domcontentloaded')
                page.get_by_role('heading', name='Notifikasi dihentikan').wait_for()
                stage('admin-authorization')
                retry_url = 'http://127.0.0.1:18801/admin/orders/' + fixture['retry_order'] + '/sales-proof/retry'
                page.goto('http://127.0.0.1:18801/admin/login', wait_until='domcontentloaded')
                unauthorized = page.evaluate("""async url => {
                    const csrf = document.querySelector('[name=csrfmiddlewaretoken]').value;
                    const r = await fetch(url, {method:'POST', headers: {'Accept':'text/html',
                        'Content-Type':'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({csrfmiddlewaretoken:csrf})});
                    return new URL(r.url).pathname;
                }""", retry_url)
                if unauthorized != '/admin/login':
                    raise AssertionError('Unauthenticated Sales Proof retry accepted')
                stage('admin-login')
                page.goto('http://127.0.0.1:18801/admin/login?next=/admin/orders/' + fixture['retry_order'])
                page.locator('#id_email').fill('p25-operator@example.invalid')
                page.locator('#id_password').fill(admin_password)
                page.locator('form[action="/admin/login"] button[type=submit]').click()
                stage('admin-retry')
                page.get_by_role('button', name='Retry Sales Proof').wait_for()
                without_csrf = page.evaluate("""async url => (await fetch(url, {method:'POST',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:''})).status""", retry_url)
                if without_csrf != 403:
                    raise AssertionError('Sales Proof retry accepted without CSRF')
                page.get_by_role('button', name='Retry Sales Proof').click()
                page.get_by_text('Retry Sales Proof Post dicatat. Unknown delivery tetap memerlukan review.').wait_for()
                stage('admin-unknown')
                page.goto('http://127.0.0.1:18801/admin/orders/' + fixture['unknown_order'])
                page.get_by_role('button', name='Retry Sales Proof').click()
                page.get_by_text('Tindakan tidak dapat diproses pada status saat ini.').wait_for()
            finally:
                context.close()
        finally:
            browser.close()


def main():
    notifications = sys.argv[1:] == ['--notifications']
    receipt = sys.argv[1:] == ['--receipt'] or notifications
    lifecycle = sys.argv[1:] == ['--lifecycle']
    browser = sys.argv[1:] == ['--storefront-browser']
    storefront = sys.argv[1:] in (['--storefront'], ['--storefront-browser'])
    admin = sys.argv[1:] == ['--admin']
    auth = sys.argv[1:] == ['--auth'] or storefront or admin
    supplier = sys.argv[1:] == ['--supplier']
    p16 = sys.argv[1:] == ['--p16']
    p17 = sys.argv[1:] == ['--p17']
    api = sys.argv[1:] == ['--api']
    if sys.argv[1:] not in ([], ['--notifications'], ['--receipt'], ['--lifecycle'], ['--auth'], ['--storefront'], ['--storefront-browser'], ['--supplier'], ['--p16'], ['--p17'], ['--api'], ['--admin']):
        raise ValueError('Usage: smoke_checkout.py [--notifications|--receipt|--lifecycle|--auth|--storefront|--storefront-browser|--supplier|--p16|--p17|--api|--admin]')
    os.umask(0o077)
    scope = 'migration-p25' if notifications else 'migration-p22' if admin else 'migration-p21' if storefront else 'migration-p20' if api else 'migration-p17' if p17 else 'migration-p16' if p16 else 'migration-p18' if supplier else 'migration-p19' if auth else 'migration-p12' if lifecycle else 'migration-p09' if receipt else 'migration-p07'
    harness.STATE = ROOT / '.local' / scope / 'lifecycle'
    harness.PREFIX = 'lhie-p25-' if notifications else 'lhie-p22-' if admin else 'lhie-p21-' if storefront else 'lhie-p20-' if api else 'lhie-p17-' if p17 else 'lhie-p16-' if p16 else 'lhie-p18-' if supplier else 'lhie-p19-' if auth else 'lhie-p12-' if lifecycle else 'lhie-p09-' if receipt else 'lhie-p07-'
    harness.IMAGE = 'lhie-p19-boundary:local' if auth or api else 'lhie-p09-boundary:local' if receipt or lifecycle or supplier or p16 or p17 else 'lhie-p01-boundary:1'
    if not (harness.STATE / 'candidate/config.json').exists():
        cli('init','candidate')
    config = harness.validate(json.loads((harness.STATE / 'candidate/config.json').read_text()))
    if config != harness.default('candidate'):
        raise RuntimeError('P07 requires default synthetic fake configuration')
    if docker('ps','-q','--filter','label=lhie.p01=disposable'):
        raise RuntimeError('Stop existing disposable stack before P07')
    output = ROOT / '.local' / scope
    output.mkdir(parents=True,exist_ok=True)
    if browser:
        (output / 'browser-finished').unlink(missing_ok=True)
        (output / 'browser-fixture.json').unlink(missing_ok=True)
    if notifications:
        (output / 'waitlist-browser-ready.json').unlink(missing_ok=True)
        (output / 'waitlist-browser-finished').unlink(missing_ok=True)
        (output / 'waitlist-browser-failure.json').unlink(missing_ok=True)
    cli('reset','candidate')
    harness.candidate_admission(config,harness.STATE / 'candidate', workers=notifications, notifications=notifications)
    if notifications:
        image_boundary = docker('run', '--rm', '--network', 'none', '--entrypoint', 'python',
            harness.IMAGE, '-c', "import hashlib; from pathlib import Path; print(hashlib.sha256(Path('/opt/harness/boundary.py').read_bytes()).hexdigest())")
        if image_boundary != hashlib.sha256((ROOT / 'tools/migration_harness/boundary.py').read_bytes()).hexdigest():
            raise RuntimeError('P25 boundary image does not match the local relay source')
    try:
        manifest = cli('start','candidate','--quarantine')
        boundary = container('candidate','boundary')
        database = container('candidate','database')
        php(boundary,'php artisan migrate --force --no-interaction')
        fixture = (ROOT / 'tools/migration_harness/checkout_fixture.php').read_text().removeprefix('<?php')
        fixtures = json.loads(php(boundary, ('P25_NOTIFICATION_SMOKE=1 ' if notifications else 'P20_API_SMOKE=1 P16_USDT_SMOKE=1 ' if api else 'P17_USDT_SMOKE=1 ' if p17 else 'P21_STOREFRONT_SMOKE=1 P19_AUTH_SMOKE=1 ' if storefront else 'P19_AUTH_SMOKE=1 ' if auth else 'P16_USDT_SMOKE=1 ' if p16 else '') + 'php -r ' + shlex.quote(fixture)))
        if notifications:
            fixtures['p25_browser_key'] = secrets.token_hex(32)
            fixtures['p25_admin_password'] = secrets.token_hex(24)
            fixtures['p25_admin_db_password'] = secrets.token_hex(24)
            fixtures['p25_supplier_db_password'] = secrets.token_hex(24)
            fixtures['p25_funding_db_password'] = secrets.token_hex(24)
        if receipt:
            customer_id = int(fixtures['python']['customer_id'])
            docker('exec',database,'psql','-v','ON_ERROR_STOP=1','-U','harness','-d','disposable',
                '-c',f"UPDATE public.customers SET telegram_id=1 WHERE id={customer_id}")
        schema = '\n'.join((ROOT / 'candidate/lhie_candidate/navigation' / name).read_text()
            for name in ('schema.sql','ingress_schema.sql','assets_schema.sql','grants.sql'))
        schema += '\n' + (ROOT / 'candidate/lhie_candidate/checkout/grants.sql').read_text()
        if notifications or lifecycle or supplier or p16 or p17 or api or storefront:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/payments/grants.sql').read_text()
        if receipt or lifecycle or supplier or p16 or p17 or api or storefront:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/receipt/grants.sql').read_text()
        if notifications:
            schema += '\nALTER ROLE candidate_runtime CONNECTION LIMIT 7;\n'
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/notifications/grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/notifications/waitlist_grants.sql').read_text()
            schema += "\nGRANT candidate_runtime TO candidate_p25_admin;\n"
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/admin/grants.sql').read_text().replace(
                'TO candidate_runtime', 'TO candidate_p25_admin')
            schema += "\nGRANT candidate_runtime TO candidate_p25_supplier;\n"
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/suppliers/grants.sql').read_text().replace(
                'TO candidate_runtime', 'TO candidate_p25_supplier')
            schema += "\nGRANT candidate_runtime TO candidate_p25_funding;\n"
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/funding/grants.sql').read_text().replace(
                'TO candidate_runtime', 'TO candidate_p25_funding')
        if p16 or p17:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/usdt/grants.sql').read_text()
        if p17:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/usdt/funding_grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/usdt/operations_grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/adjustments/grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/auth/schema.sql').read_text()
        if supplier or api or storefront:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/suppliers/grants.sql').read_text()
        if supplier:
            docker('exec',database,'psql','-v','ON_ERROR_STOP=1','-U','harness','-d','disposable',
                '-c',"CREATE ROLE candidate_supplier_operator LOGIN PASSWORD 'candidate-local-only' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS")
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/suppliers/operator_grants.sql').read_text()
        if auth or notifications:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/auth/schema.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/auth/grants.sql').read_text()
        if notifications:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/storefront/catalog_grants.sql').read_text()
        if storefront:
            schema += '\n' + '\n'.join((ROOT / 'candidate/lhie_candidate/storefront' / name).read_text()
                                       for name in ('catalog_grants.sql', 'grants.sql', 'account_grants.sql',
                                                    'balance_idr_grants.sql', 'balance_usdt_grants.sql'))
        if admin:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/admin/grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/funding/policy_admin_grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/adjustments/grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/suppliers/grants.sql').read_text()
        if api:
            schema += '\n' + '\n'.join((ROOT / 'candidate/lhie_candidate/customer_api' / name).read_text()
                                       for name in ('credential_grants.sql','orders_grants.sql','reads_grants.sql'))
        if notifications:
            role_sql = ("CREATE ROLE candidate_p25_admin LOGIN PASSWORD '" + fixtures['p25_admin_db_password']
                        + "' CONNECTION LIMIT 1; CREATE ROLE candidate_p25_supplier LOGIN PASSWORD '"
                        + fixtures['p25_supplier_db_password'] + "' CONNECTION LIMIT 1; "
                        + "CREATE ROLE candidate_p25_funding LOGIN PASSWORD '"
                        + fixtures['p25_funding_db_password'] + "' CONNECTION LIMIT 1;")
            subprocess.run(['docker', 'exec', '-i', database, 'psql', '-v', 'ON_ERROR_STOP=1',
                            '-U', 'harness', '-d', 'disposable'],
                           input=role_sql, capture_output=True, text=True, check=True, timeout=30)
        docker('exec',database,'psql','-v','ON_ERROR_STOP=1','-U','harness','-d','disposable','-c',schema)
        directory = harness.STATE / 'candidate'
        oracle_key = directory / 'p09-oracle-key'
        if receipt or lifecycle or auth or supplier or p16 or p17 or api:
            oracle_key.write_text('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n')
            oracle_key.chmod(0o600)
        candidate_image = ('lhie-p13-candidate:local' if notifications or admin or supplier or p17 or api or storefront
                           else 'lhie-p09-candidate:local' if receipt or lifecycle or auth or p16
                           else 'lhie-p05-candidate:1')
        command = ['docker','run','--rm','-i','--network','container:'+boundary,
            '--read-only','--cap-drop','ALL','--security-opt','no-new-privileges:true',
            '--user','1000:1000','--cpus','1.25','--memory','1216m','--memory-swap','1216m',
            '--tmpfs','/tmp:size=64m',
            '--mount',f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
            '--mount',f'type=bind,src={directory / "candidate.json"},dst=/run/harness/candidate.json,readonly',
            '--mount',f'type=bind,src={directory / "django-key"},dst=/run/harness/django-key,readonly',
            '--mount',f'type=bind,src={oracle_key if receipt or lifecycle or auth or supplier or p16 or p17 or api else directory / "app-key"},dst=/run/harness/app-key,readonly',
            '--mount',f'type=bind,src={output},dst=/evidence',
            '--env','P07_CHECKOUT_SMOKE=disposable-local-only',
            '--env',f'P07_RECEIPT_SMOKE={"1" if receipt else "0"}',
            '--env',f'P25_NOTIFICATION_SMOKE={"1" if notifications else "0"}',
            '--env',f'P12_LIFECYCLE_SMOKE={"1" if lifecycle else "0"}',
            '--env',f'P19_AUTH_SMOKE={"1" if auth else "0"}',
            '--env',f'P21_STOREFRONT_SMOKE={"1" if storefront else "0"}',
            '--env',f'P22_ADMIN_SMOKE={"1" if admin else "0"}',
            '--env',f'P21_BROWSER_SMOKE={"1" if browser else "0"}',
            '--env',f'P16_USDT_SMOKE={"1" if p16 else "0"}',
            '--env',f'P17_USDT_SMOKE={"1" if p17 else "0"}',
            '--env',f'P20_API_SMOKE={"1" if api else "0"}',
            '--env',f'P18_SUPPLIER_SMOKE={"1" if supplier else "0"}','--entrypoint','python',
            candidate_image,'smoke_checkout.py']
        if notifications:
            process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            process.stdin.write(json.dumps(fixtures))
            process.stdin.close()
            process.stdin = None
            finished = output / 'waitlist-browser-finished'
            stage = 'waiting-for-storefront'
            try:
                ready = output / 'waitlist-browser-ready.json'
                deadline = time.monotonic() + 180
                while not ready.exists():
                    if process.poll() is not None or time.monotonic() >= deadline:
                        raise RuntimeError('Disposable storefront did not become ready')
                    time.sleep(0.1)
                stage = 'php-compensation'
                compensation = fixtures['p25_compensation']
                owner_call = ("require '/var/www/html/vendor/autoload.php'; "
                    "$app=require '/var/www/html/bootstrap/app.php'; "
                    "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
                    "if (!app()->environment('testing') || Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName()!=='disposable') throw new RuntimeException('refused'); "
                    "Illuminate\\Support\\Facades\\Http::preventStrayRequests(); Illuminate\\Support\\Facades\\Queue::fake(); "
                    f"$result=app(App\\Services\\StoreBalance\\StoreBalanceOwnerCompensationService::class)->release({int(compensation['top_up_id'])},"
                    f"App\\Models\\User::findOrFail({int(compensation['owner_id'])}),"
                    "'P25-SYNTHETIC-REFERENCE','Synthetic compensation qualification'); "
                    "Illuminate\\Support\\Facades\\Event::fake([App\\Events\\StoreBalanceOwnerCompensated::class]); "
                    f"$missing=app(App\\Services\\StoreBalance\\StoreBalanceOwnerCompensationService::class)->release({int(compensation['missing_top_up_id'])},"
                    f"App\\Models\\User::findOrFail({int(compensation['owner_id'])}),"
                    "'P25-MISSING-REFERENCE','Synthetic missing handoff'); "
                    "echo json_encode(['applied'=>$result->applied,'missing_applied'=>$missing->applied]);")
                if json.loads(php(boundary, 'php -r ' + shlex.quote(owner_call))) != {'applied': True, 'missing_applied': True}:
                    raise AssertionError('Synthetic PHP compensation did not apply')
                stage = 'browser-flow'
                waitlist_browser(output, fixtures['p25_browser_key'], fixtures['p25_admin_password'])
                finished.write_text('PASS')
                stage = 'finalizing-probe'
                stdout, stderr = process.communicate(timeout=240)
                probe = subprocess.CompletedProcess(command, process.returncode, stdout, stderr)
            except Exception as error:
                (output / 'waitlist-browser-failure.json').write_text(json.dumps(
                    {'stage': stage, 'error_type': type(error).__name__}))
                if process.poll() is None:
                    finished.write_text('FAIL')
                    try:
                        process.communicate(timeout=20)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.communicate()
                raise RuntimeError('P25 disposable browser verification failed') from None
        else:
            probe = subprocess.run(command,input=json.dumps(fixtures),capture_output=True,text=True,timeout=1200 if browser else 240)
        if probe.returncode:
            if receipt and not notifications:
                diagnostic = [json.loads(line) for line in probe.stderr.splitlines() if line.startswith('{')]
                raise RuntimeError('P09 probe failed: ' + json.dumps(diagnostic))
            (output / 'probe-failure.stderr').write_text(probe.stderr)
            raise RuntimeError(f'{scope} probe failed; private diagnostics in .local/{scope}')
        observed = json.loads(probe.stdout)
        assert observed['status'] == 'passed'
        if notifications:
            for name in ('waitlist-browser-stage.txt', 'waitlist-browser-http.json',
                         'probe-failure.txt', 'probe-failure.stderr', 'p25-bulk-failure.json'):
                (output / name).unlink(missing_ok=True)
        if notifications:
            observed['notification_browser'] = notification_browser(output)
            observed['waitlist_browser_boundary'] = {
                'mode': 'live-loopback-storefront',
                'restock': 'disposable fixture through existing waitlist owner',
                'delivery': 'local discard-only SMTP',
                'limits': 'No production, real email, Admin stock UI or mail-client rendering',
            }
            from uuid import UUID
            supplier_case = json.loads((output / 'p25-supplier-outcome.json').read_text())
            supplier_order = str(UUID(supplier_case['order_id']))
            supplier_oracle = ("require '/var/www/html/vendor/autoload.php'; "
                "$app=require '/var/www/html/bootstrap/app.php'; "
                "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
                "if (!app()->environment('testing') || Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName()!=='disposable') throw new RuntimeException('refused'); "
                f"$order=App\\Models\\Order::with(['customer','orderItems','payments'])->findOrFail('{supplier_order}'); "
                "echo json_encode(app(App\\Services\\Telegram\\AdminPaidOrderOutcome::class)->read($order));")
            legacy_outcome = json.loads(php(boundary, 'php -r ' + shlex.quote(supplier_oracle)))
            if (legacy_outcome['resolved'] is not False or supplier_case['outcome']['resolved'] is not True
                    or not any('hasil pembelian belum dapat dipastikan' in line for line in legacy_outcome['lines'])
                    or not any('konfirmasi Owner' in line for line in supplier_case['outcome']['lines'])):
                raise AssertionError('Supplier full-refund differential changed unexpectedly')
            observed['supplier_full_refund_php_differential'] = (
                'PHP reports unresolved after owner-attested no-debit and full refund; '
                'candidate resolves from immutable owner evidence')
            handoff = json.loads((output / 'counter-handoff.json').read_text())
            floor = handoff['rollback_floor']
            if type(floor) is not int or not handoff['php_floor'] < floor < 2**53 - 1:
                raise AssertionError('Invalid local Sales Proof counter handoff')
            rollback = ("require '/var/www/html/vendor/autoload.php'; "
                "$app=require '/var/www/html/bootstrap/app.php'; "
                "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
                "if (!app()->environment('testing') || Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName()!=='disposable') throw new RuntimeException('refused'); "
                "config(['cache.default'=>'array']); "
                f"Illuminate\\Support\\Facades\\Cache::forever('sales-proof-posts:published-count',{floor}); "
                "echo json_encode(['next'=>app(App\\Services\\Telegram\\SalesProofPostNumbering::class)->reserveNextNumber()]);")
            next_number = json.loads(php(boundary, 'php -r ' + shlex.quote(rollback)))['next']
            if next_number != floor + 1:
                raise AssertionError('PHP rollback Sales Proof numbering regressed')
            observed['sales_proof_counter_handoff'] = {'php_floor': handoff['php_floor'],
                'candidate_rollback_floor': floor, 'php_next': next_number,
                'limits': 'Drained synthetic publishers and explicit integer transfer; not a production Redis/cache migration'}
        sources = [*sorted((ROOT / 'candidate/lhie_candidate/checkout').glob('*')),
            ROOT / 'candidate/lhie_candidate/readiness.py', ROOT / 'candidate/smoke_checkout.py',
            ROOT / 'candidate/tests/test_checkout_postgres.py',ROOT / 'candidate/tests/test_checkout_pricing.py',
            ROOT / 'candidate/requirements.lock',ROOT / 'tools/migration_harness/checkout_fixture.php',Path(__file__)]
        if receipt:
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/receipt').glob('*')))
            sources.extend(ROOT / name for name in (
                'candidate/tests/test_receipt_postgres.py', 'candidate/tests/test_receipt_render.py',
                'candidate/tests/test_receipt_transport.py', 'candidate/lhie_candidate/receipt/assets/lhie-store-logo.png',
                'candidate/lhie_candidate/navigation/processor.py', 'candidate/lhie_candidate/payments/lifecycle.py',
                'candidate/receipt.py', 'tests/migration_harness/test_p09_relay.py',
                 'tools/migration_harness/boundary.py', 'tools/migration_harness/harness.py'))
        if notifications:
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/notifications').glob('*.py')))
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/workers').glob('*.py')))
            sources.extend(ROOT / name for name in (
                'candidate/lhie_candidate/notifications/grants.sql', 'candidate/tests/test_notifications.py',
                 'candidate/tests/test_notifications_postgres.py', 'candidate/tests/test_supplier_notice_postgres.py',
                 'candidate/notifications.py'))
            sources.extend(ROOT / name for name in (
                'candidate/lhie_candidate/funding/manual.py', 'candidate/lhie_candidate/funding/service.py',
                'candidate/lhie_candidate/funding/outbound.py', 'candidate/lhie_candidate/funding/grants.sql',
                'candidate/lhie_candidate/payments/presentation.py', 'candidate/lhie_candidate/payments/sakurupiah_urls.py',
                'candidate/lhie_candidate/payments/klikqris.py'))
            sources.extend(ROOT / name for name in (
                'candidate/lhie_candidate/settings.py', 'candidate/lhie_candidate/navigation/transport.py',
                'candidate/lhie_candidate/navigation/presentation.py',
                'candidate/lhie_candidate/navigation/id.json', 'candidate/lhie_candidate/navigation/en.json',
                'candidate/lhie_candidate/navigation/ms.json'))
            sources.extend(ROOT / name for name in (
                'candidate/tests/test_notification_completion.py', 'candidate/tests/test_runtime.py',
                'candidate/lhie_candidate/runtime.py', 'candidate/workers.py', 'candidate/stack.py'))
            sources.extend((ROOT / 'candidate/templates/notifications').glob('*.html'))
            sources.extend((ROOT / 'candidate/templates/notifications').glob('*.txt'))
            sources.extend(ROOT / name for name in (
                'candidate/lhie_candidate/notifications/waitlist_grants.sql',
                'candidate/tests/test_waitlist.py', 'candidate/tests/test_waitlist_postgres.py',
                'candidate/serve.py', 'candidate/lhie_candidate/startup.py',
                'candidate/lhie_candidate/urls.py', 'candidate/lhie_candidate/http.py',
                'candidate/templates/storefront/catalog_base.html',
                'candidate/static/storefront/laravel-storefront.css', 'candidate/static/storefront/catalog.css',
                'candidate/static/storefront/catalog.js', 'candidate/static/storefront/carousels-init.js',
                'candidate/static/storefront/carousels.js', 'candidate/static/storefront/activity.js',
                'candidate/static/storefront/favicon.png', 'candidate/static/storefront/product-placeholder.svg',
                'candidate/lhie_candidate/storefront/waitlist.py',
                'candidate/lhie_candidate/storefront/catalog.py',
                'candidate/lhie_candidate/storefront/catalog_urls.py',
                'candidate/lhie_candidate/storefront/catalog_grants.sql',
                'candidate/lhie_candidate/auth/schema.sql',
                'candidate/lhie_candidate/auth/grants.sql',
                'candidate/lhie_candidate/auth/mail.py',
                'candidate/lhie_candidate/admin/catalog.py', 'candidate/lhie_candidate/admin/views.py',
                'candidate/lhie_candidate/admin/operations_views.py', 'candidate/templates/admin/order_detail.html',
                'candidate/lhie_candidate/admin/grants.sql', 'candidate/lhie_candidate/admin/access.py',
                'candidate/lhie_candidate/admin/orders.py', 'candidate/lhie_candidate/admin/urls.py',
                'candidate/lhie_candidate/auth/admin.py', 'candidate/templates/admin/base.html',
                'candidate/templates/admin/login.html', 'candidate/static/admin/admin.css', 'candidate/static/admin/admin.js',
                'candidate/lhie_candidate/storefront/checkout.py',
                'candidate/lhie_candidate/suppliers/manual.py', 'candidate/lhie_candidate/suppliers/acquisition.py',
                'candidate/lhie_candidate/suppliers/capacity.py', 'candidate/lhie_candidate/suppliers/transport.py',
                'candidate/lhie_candidate/suppliers/warranty.py', 'candidate/lhie_candidate/suppliers/grants.sql',
                'candidate/lhie_candidate/auth/sessions.py',
                'candidate/lhie_candidate/compat/identity.py',
                'candidate/lhie_candidate/navigation/repository.py',
                'src/app/Models/ProductWaitlistNotification.php',
                'src/app/Services/GuestOrderNotificationService.php',
                'src/app/Services/Customer/CustomerAuthMailDeliveryService.php',
                'src/tests/Feature/CustomerAuthMailDeliveryTest.php', 'src/tests/Feature/GuestOrderNotificationTest.php',
                'src/tests/Feature/ProductWaitlistTest.php',
                'candidate/lhie_candidate/checkout/privileges.py'))
            sources.extend((ROOT / 'candidate/templates/storefront').glob('waitlist*.html'))
            sources.append(ROOT / 'candidate/templates/storefront/catalog_product.html')
        if lifecycle:
            sources.extend(ROOT / name for name in (
                'candidate/tests/test_order_lifecycle_postgres.py', 'candidate/tests/test_order_refunds.py',
                'candidate/tests/test_order_projection.py', 'candidate/lhie_candidate/payments/lifecycle.py',
                'candidate/lhie_candidate/payments/receipts.py', 'candidate/lhie_candidate/workers/payment.py',
                'candidate/lhie_candidate/receipt/source.py', 'candidate/lhie_candidate/receipt/service.py',
                'candidate/lhie_candidate/receipt/protocol.py', 'candidate/lhie_candidate/payments/claimed_order.py',
                'candidate/lhie_candidate/navigation/presentation.py'))
        if supplier:
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/suppliers').glob('*')))
            sources.extend(ROOT / name for name in (
                'candidate/tests/test_supplier_postgres.py', 'candidate/lhie_candidate/receipt/source.py',
                'candidate/lhie_candidate/receipt/grants.sql', 'candidate/lhie_candidate/payments/grants.sql',
                'candidate/lhie_candidate/payments/routing.py', 'candidate/lhie_candidate/payments/manual.py',
                'candidate/lhie_candidate/payments/runtime.py', 'candidate/lhie_candidate/workers/consumer.py',
                'candidate/lhie_candidate/workers/contract.py', 'candidate/lhie_candidate/workers/metrics.py'))
        if auth:
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/auth').glob('*')))
            sources.extend(ROOT / name for name in (
                'candidate/tests/test_auth_postgres.py', 'candidate/lhie_candidate/settings.py',
                'candidate/lhie_candidate/urls.py', 'candidate/lhie_candidate/http.py',
                'candidate/lhie_candidate/navigation/ingress.py', 'candidate/lhie_candidate/navigation/processor.py',
                'candidate/auth_mail.py', 'tools/migration_harness/boundary.py',
                'candidate/tests/test_auth_core.py', 'candidate/tests/test_auth_http.py',
                'candidate/tests/test_auth_providers.py', 'tests/migration_harness/test_p09_relay.py'))
        if storefront:
            sources.extend(path for directory in (
                ROOT / 'candidate/lhie_candidate/storefront', ROOT / 'candidate/templates/storefront',
                ROOT / 'candidate/static/storefront') for path in sorted(directory.rglob('*'))
                if path.is_file() and '__pycache__' not in path.parts and path.suffix != '.pyc')
            sources.append(ROOT / 'candidate/tests/test_storefront_postgres.py')
        if admin:
            sources.extend(path for directory in (
                ROOT / 'candidate/lhie_candidate/admin', ROOT / 'candidate/templates/admin',
                ROOT / 'candidate/static/admin') for path in sorted(directory.rglob('*'))
                if path.is_file() and '__pycache__' not in path.parts and path.suffix != '.pyc')
            sources.extend(ROOT / name for name in (
                'candidate/tests/test_admin_postgres.py', 'candidate/tests/test_admin_operations_postgres.py',
                'candidate/tests/test_admin_pricing.py', 'candidate/tests/test_admin_operations.py',
                'candidate/lhie_candidate/settings.py',
                'candidate/lhie_candidate/urls.py', 'candidate/lhie_candidate/auth/admin.py',
                'candidate/lhie_candidate/auth/http.py', 'candidate/lhie_candidate/compat/crypto.py',
                'candidate/lhie_candidate/compat/identity.py', 'candidate/lhie_candidate/checkout/db.py',
                'candidate/lhie_candidate/checkout/privileges.py'))
        if p16:
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/usdt').glob('*.py')))
            sources.append(ROOT / 'candidate/lhie_candidate/usdt/grants.sql')
            sources.append(ROOT / 'candidate/tests/test_usdt_receipt_receiver_registry.py')
            sources.extend((ROOT / 'candidate/lhie_candidate/domain/money.py', ROOT / 'candidate/tests/test_money.py'))
            sources.extend(ROOT / name for name in (
                'candidate/tests/test_p16_postgres.py', 'candidate/tests/test_wallet_selection.py',
                'candidate/lhie_candidate/payments/receipts.py',
                'candidate/lhie_candidate/payments/receiver_ownership.py',
                'candidate/lhie_candidate/payments/grants.sql',
                'candidate/lhie_candidate/receipt/grants.sql'))
        if p17:
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/usdt').glob('*')))
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/receipt').glob('*.py')))
            sources.extend(ROOT / name for name in (
                'candidate/tests/test_p17_postgres.py', 'candidate/tests/test_binance.py', 'candidate/tests/test_bsc.py',
                'candidate/tests/test_catalog_presentation.py', 'candidate/lhie_candidate/adjustments/password.py',
                'candidate/lhie_candidate/adjustments/grants.sql', 'candidate/lhie_candidate/workers/services.py',
                'candidate/lhie_candidate/navigation/presentation.py', 'candidate/lhie_candidate/navigation/repository.py',
                'candidate/lhie_candidate/navigation/processor.py', 'candidate/lhie_candidate/payments/binance.py',
                'candidate/lhie_candidate/payments/bsc.py', 'candidate/lhie_candidate/payments/receipts.py',
                'candidate/lhie_candidate/workers/payment.py'))
        if api:
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/customer_api').glob('*')))
            sources.extend(sorted((ROOT / 'candidate/lhie_candidate/suppliers').glob('*.py')))
            sources.append(ROOT / 'candidate/lhie_candidate/suppliers/grants.sql')
            sources.extend(ROOT / name for name in ('candidate/tests/test_customer_api_postgres.py',
                'candidate/tests/test_customer_api_contract.py', 'candidate/customer_api_usage.py',
                'candidate/lhie_candidate/receipt/source.py', 'candidate/lhie_candidate/urls.py',
                'candidate/lhie_candidate/http.py', 'candidate/lhie_candidate/navigation/repository.py',
                'candidate/lhie_candidate/usdt/ledger.py', 'candidate/lhie_candidate/receipt/render.py'))
        observed['source_sha256'] = {str(path.relative_to(ROOT)):hashlib.sha256(path.read_bytes()).hexdigest()
                                      for path in sources if path.is_file()}
        observed['base_revision'] = subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
        observed['dependency_image'] = docker('image','inspect',candidate_image,'--format','{{.Id}}')
        if receipt or auth:
            observed['boundary_image'] = docker('image','inspect',harness.IMAGE,'--format','{{.Id}}')
        migration_digest = hashlib.sha256()
        for path in sorted((ROOT / 'src/database/migrations').glob('*.php')):
            migration_digest.update(str(path.relative_to(ROOT)).encode() + b'\0' + path.read_bytes() + b'\0')
        observed['migration_sources_sha256'] = migration_digest.hexdigest()
        observed['legacy_owner_sha256'] = {name:hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
            for name in ('src/app/Services/OrderCheckoutService.php','src/app/Services/StoreBalance/StoreBalanceLedger.php',
                'src/app/Services/EloquentInventoryLedger.php','src/app/Services/ProductCredentialAllocator.php',
                'src/app/Services/Pricing/ProductQuantityPricing.php','src/app/Services/OrderStateMachine.php')}
        if lifecycle:
            observed['legacy_owner_sha256'].update({name:hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                for name in ('src/app/Services/OrderLifecycleProjection.php',
                    'src/app/Services/Admin/ManualFulfillmentResolutionService.php',
                    'src/app/Services/Suppliers/SupplierFulfillmentService.php',
                    'src/app/Services/Warranty/RemainderRefundService.php',
                    'src/app/Services/UsdtBalance/UsdtOrderRefundService.php',
                    'src/app/Services/MoneyReceipts/ExternalMoneyReceiptWriter.php')})
        if receipt:
            observed['legacy_owner_sha256'].update({name:hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                for name in ('src/app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php',
                    'src/app/Services/Telegram/TelegramOutboundEffectService.php',
                    'src/app/Services/Telegram/TelegramReceiptNotifier.php',
                    'src/app/Services/Telegram/ReceiptDeliveryPipeline.php')})
        if notifications:
            observed['legacy_owner_sha256'].update({name:hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                for name in ('src/app/Services/ProductWaitlistSubscriptionService.php',
                    'src/app/Services/Telegram/AdminPaidOrderOutcome.php',
                    'src/app/Services/Telegram/AdminOrderNotificationDeliveryPipeline.php',
                    'src/app/Services/Telegram/TelegramAdminOrderNotifier.php',
                    'src/app/Services/Telegram/TelegramAdminPaidOrderNotifier.php',
                    'src/app/Services/Telegram/SalesProofDeliveryPipeline.php',
                    'src/app/Services/Telegram/SalesProofPostFormatter.php',
                    'src/app/Services/Telegram/SalesProofPostNumbering.php',
                    'src/app/Services/PaidInvoice/PaidInvoiceOrderPresentationAdapter.php',
                    'src/app/Services/PaidInvoice/PaidInvoicePresentation.php',
                    'src/app/Services/PaidInvoice/PaidInvoiceBuyerNameMasker.php',
                    'src/app/Services/Telegram/TelegramProductNameRenderer.php',
                    'src/app/Services/OrderCredentialMailService.php',
                    'src/resources/views/emails/order-credential.blade.php',
                    'src/resources/views/emails/order-credential-text.blade.php',
                    'src/app/Services/ProductWaitlistNotificationService.php',
                    'src/app/Services/ProductWaitlistEmailHasher.php',
                    'src/resources/views/emails/product-waitlist-confirmation.blade.php',
                    'src/resources/views/emails/product-waitlist-restocked.blade.php',
                    'src/resources/views/emails/product-waitlist-confirmation-text.blade.php',
                    'src/resources/views/emails/product-waitlist-restocked-text.blade.php')})
        if auth:
            observed['legacy_owner_sha256'].update({name:hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                for name in ('src/app/Services/Customer/CustomerPasswordAuthenticationService.php',
                    'src/app/Services/Customer/CustomerEmailOtpService.php',
                    'src/app/Services/Customer/CustomerAuthenticationService.php',
                    'src/app/Services/Customer/CustomerGoogleAuthenticationService.php',
                    'src/app/Services/Customer/CustomerTelegramAuthenticationService.php',
                    'src/app/Services/Customer/CustomerAuthMailDeliveryService.php',
                    'src/app/Services/Customer/CustomerOrderClaimService.php',
                    'src/app/Services/Telegram/TelegramBotIdentity.php',
                    'src/app/Storefront/StorefrontOrderAccess.php',
                    'src/app/Http/Controllers/Admin/AdminLoginController.php')})
        if admin:
            observed['legacy_owner_sha256'].update({name:hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                for name in ('src/app/Services/ProductStockIntake.php',
                    'src/app/Services/ProductStockItemManager.php',
                    'src/app/Services/Admin/ProductFamilyManagementService.php',
                    'src/app/Services/Admin/AdminProductQuantityPricing.php',
                    'src/app/Services/Admin/ProductFamilyCategoryCoherence.php',
                    'src/app/Services/Admin/AdminProductWorkspaceData.php',
                    'src/app/Services/Admin/CategoryManagementService.php',
                    'src/app/Http/Controllers/Admin/AdminProductCreateController.php',
                    'src/app/Http/Controllers/Admin/AdminProductUpdateController.php',
                    'src/app/Http/Controllers/Admin/AdminProductStockController.php',
                    'src/app/Http/Controllers/Admin/AdminProductAccountStockController.php',
                    'src/app/Storefront/StorefrontBanners.php')})
        observed['postgres_version'] = docker('exec',database,'postgres','--version')
        observed['harness_manifest'] = str(Path(manifest).relative_to(ROOT))
        observed['limits'] = ['Synthetic local data/test keys only; no production schema/history qualification',
            'Runtime uses explicit checkout column grants; grants are never applied at startup',
            'Shared service seam only; Telegram checkout consumer/delivery and QRIS sandbox remain P08/P09',
            'Original-order refund posting prerequisite only; full lifecycle/warranty/supplier workflows remain P12/P18',
            'Source bind-mounted read-only over the existing locked dependency image; no performance claim']
        if receipt:
            observed['limits'] = ['Synthetic disposable PostgreSQL and Redis; fake relay only, no Telegram',
                'Injected lost ACK and crash boundaries; no production delivery or history qualification',
                'Receipt and checkout grants provisioned explicitly, never at startup']
        if notifications:
            observed['legacy_owner_sha256'].update({name:hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
                for name in ('src/app/Services/Telegram/TelegramProductNotificationService.php',
                    'src/app/Services/Telegram/StockNotificationDeliveryPipeline.php',
                    'src/app/Services/Telegram/ProductPriceChangeNotificationService.php',
                    'src/app/Services/Telegram/TelegramBroadcastIntents.php',
                    'src/app/Services/Telegram/TelegramBroadcastService.php',
                    'src/app/Services/Telegram/BalanceTopUpReceiptDeliveryPipeline.php',
                    'src/app/Services/Telegram/BalanceTopUpInstructionDeliveryPipeline.php',
                    'src/app/Services/Telegram/TelegramPaymentInstructionRenderer.php',
                    'src/app/Services/Telegram/StoreBalanceTopUpOutbound.php',
                    'src/app/Services/Telegram/BalanceTopUpChannelProofDeliveryPipeline.php',
                    'src/app/Services/PaidInvoice/PaidInvoiceTopUpPresentationAdapter.php',
                    'src/app/Services/Telegram/BalanceTopUpOwnerAlertDeliveryPipeline.php',
                    'src/app/Services/Telegram/TelegramBotApiRedactor.php',
                    'src/app/Logging/RedactCustomerApiSecrets.php',
                    'src/app/Services/Telegram/TelegramUiIcons.php',
                    'src/app/Services/Telegram/CustomEmojiCapability.php',
                    'src/app/Services/Telegram/BalanceTopUpPaymentProofDeliveryPipeline.php',
                    'src/app/Services/StoreBalance/StoreBalanceOwnerCompensationService.php',
                    'src/app/Services/Telegram/OwnerCompensationNotificationDeliveryPipeline.php',
                    'src/app/Listeners/MaterializeStoreBalanceOwnerCompensationNotification.php',
                    'src/app/Models/StoreBalanceCompensation.php',
                    'src/app/Services/StoreBalance/TopUp/ManualStoreBalanceTopUpLifecycle.php',
                    'src/app/Services/StoreBalance/TopUp/StoreBalanceTopUpLifecycleResolver.php',
                    'src/app/Services/Suppliers/SupplierHealthDeliveryPipeline.php',
                    'src/app/Services/Suppliers/SupplierHealthService.php',
                    'src/app/Services/Suppliers/SupplierMoney.php',
                    'src/app/Services/Suppliers/SupplierCatalogSync.php',
                    'src/app/Models/SupplierFulfillment.php',
                    'src/app/Models/StoreBalanceTopUp.php',
                    'src/app/Models/SupplierHealthEvent.php',
                    'src/app/Services/Telegram/TelegramLocale.php',
                    'src/lang/id.json', 'src/lang/en.json', 'src/lang/ms.json',
                    'src/app/Services/Telegram/TelegramManualPaymentIntents.php',
                    'src/app/Mail/GuestOrderMilestoneMail.php',
                    'src/app/Notifications/CustomerAuthMailNotification.php',
                    'src/app/Notifications/CustomerLoginLinkNotification.php',
                    'src/composer.lock',
                    'src/resources/views/emails/guest-order-milestone.blade.php')})
            resources_path = output / 'notification-resources.json'
            observed['notification_resources'] = json.loads(resources_path.read_text())
            resources_path.unlink()
            observed['limits'] = ['Synthetic disposable PostgreSQL/Redis, fake Telegram relay and loopback SMTP sink only',
                'Live waitlist browser uses loopback and a disposable restock fixture; mail previews do not qualify mail-client rendering',
                'Waitlist is disabled by default and admits only reserved .invalid mail targets when explicitly provisioned',
                'Optional eight-process profile: seven runtime connections; cgroup sampling includes probe/harness overhead, not production capacity',
                'No full P25 parity, P26 acceptance, live delivery, production or CI/CD operation']
        if lifecycle:
            observed['limits'] = ['Synthetic disposable PostgreSQL; no production data or historical key qualification',
                'Provider observations use the existing Unix-socket fixture, not live provider qualification',
                'P18 acquisition/network recovery and P19/P23/P24 authentication/UI adapters retain their ticket ownership',
                'No production, Telegram sends, provider activation, deployment or CI/CD operations']
        if auth:
            observed['limits'] = ['Synthetic disposable PostgreSQL/Redis, PHP-generated hashes/Recovery Key/signed link, local SMTP sink',
                'No real Google/Telegram identities, mail recipients, historical key qualification or production activation',
                'Explicit additive browser session metadata; old PHP cookies require relogin at H2, never deserialized',
                'No production, deployment, provider activation or CI/CD operations']
        if storefront:
            observed['limits'] = ['Synthetic disposable PostgreSQL/Redis and local fixture transports only',
                'Source mounted over cached dependency image; explicit grants, no production activation',
                'No live providers, real customers, production data, deployment or CI/CD operations']
        if admin:
            observed['limits'] = ['Synthetic disposable PostgreSQL/Redis only; no production data, uploads or live services',
                'Admin routes use explicit disposable grants and a synthetic credential identity key injected by the proof',
                'P23 order/payment/balance/supplier/reporting surfaces are local-only; P24 bot/admin presentation, asset uploads and P27 browser parity remain downstream',
                'No production, deployment or CI/CD operations']
        if supplier:
            observed['limits'] = ['Synthetic disposable PostgreSQL and Redis, injected supplier/Telegram fixtures only',
                'No live supplier targets, actual historical data or production provider qualification',
                'Explicit supplier grants; no production, deployment or CI/CD operations']
        if p16:
            observed['limits'] = ['Synthetic disposable PostgreSQL and existing PHP fixture ledger only',
                'No production history, live providers, custody or funding activation',
                'Explicit disposable grants; no production, deployment or CI/CD operations']
        if p17:
            observed['limits'] = ['Synthetic disposable PostgreSQL and injected fake provider/Telegram only',
                'Unqualified live funding and custody remain disabled; no production or CI/CD operations',
                'Explicit disposable grants are never applied at startup']
        if api:
            observed['limits'] = ['Synthetic disposable PostgreSQL/Redis only; no production or external providers',
                'Source mounted over cached dependency image; explicit grants, no production activation',
                'No claim of live provider qualification or historical production key coverage']
        (output / 'verification.json').write_text(json.dumps(observed,indent=2)+'\n')
        (output / 'probe-failure.txt').unlink(missing_ok=True)
        (output / 'probe-failure.stderr').unlink(missing_ok=True)
        print(json.dumps(observed,indent=2))
    finally:
        cli('reset','candidate')


if __name__ == '__main__':
    main()
