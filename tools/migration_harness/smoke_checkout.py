#!/usr/bin/env python3
"""P07 shared PostgreSQL proof using P01 isolation and the P03 PHP schema oracle."""
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys

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


def main():
    receipt = sys.argv[1:] == ['--receipt']
    lifecycle = sys.argv[1:] == ['--lifecycle']
    auth = sys.argv[1:] == ['--auth']
    supplier = sys.argv[1:] == ['--supplier']
    p16 = sys.argv[1:] == ['--p16']
    p17 = sys.argv[1:] == ['--p17']
    api = sys.argv[1:] == ['--api']
    if sys.argv[1:] not in ([], ['--receipt'], ['--lifecycle'], ['--auth'], ['--supplier'], ['--p16'], ['--p17'], ['--api']):
        raise ValueError('Usage: smoke_checkout.py [--receipt|--lifecycle|--auth|--supplier|--p16|--p17|--api]')
    os.umask(0o077)
    scope = 'migration-p20' if api else 'migration-p17' if p17 else 'migration-p16' if p16 else 'migration-p18' if supplier else 'migration-p19' if auth else 'migration-p12' if lifecycle else 'migration-p09' if receipt else 'migration-p07'
    harness.STATE = ROOT / '.local' / scope / 'lifecycle'
    harness.PREFIX = 'lhie-p20-' if api else 'lhie-p17-' if p17 else 'lhie-p16-' if p16 else 'lhie-p18-' if supplier else 'lhie-p19-' if auth else 'lhie-p12-' if lifecycle else 'lhie-p09-' if receipt else 'lhie-p07-'
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
    cli('reset','candidate')
    harness.candidate_admission(config,harness.STATE / 'candidate')
    try:
        manifest = cli('start','candidate','--quarantine')
        boundary = container('candidate','boundary')
        database = container('candidate','database')
        php(boundary,'php artisan migrate --force --no-interaction')
        fixture = (ROOT / 'tools/migration_harness/checkout_fixture.php').read_text().removeprefix('<?php')
        fixtures = json.loads(php(boundary, ('P20_API_SMOKE=1 P16_USDT_SMOKE=1 ' if api else 'P17_USDT_SMOKE=1 ' if p17 else 'P19_AUTH_SMOKE=1 ' if auth else 'P16_USDT_SMOKE=1 ' if p16 else '') + 'php -r ' + shlex.quote(fixture)))
        if receipt:
            customer_id = int(fixtures['python']['customer_id'])
            docker('exec',database,'psql','-v','ON_ERROR_STOP=1','-U','harness','-d','disposable',
                '-c',f"UPDATE public.customers SET telegram_id=1 WHERE id={customer_id}")
        schema = '\n'.join((ROOT / 'candidate/lhie_candidate/navigation' / name).read_text()
            for name in ('schema.sql','ingress_schema.sql','assets_schema.sql','grants.sql'))
        schema += '\n' + (ROOT / 'candidate/lhie_candidate/checkout/grants.sql').read_text()
        if lifecycle or supplier or p16 or p17 or api:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/payments/grants.sql').read_text()
        if receipt or lifecycle or supplier or p16 or p17 or api:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/receipt/grants.sql').read_text()
        if p16 or p17:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/usdt/grants.sql').read_text()
        if p17:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/usdt/funding_grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/usdt/operations_grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/adjustments/grants.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/auth/schema.sql').read_text()
        if supplier or api:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/suppliers/grants.sql').read_text()
        if supplier:
            docker('exec',database,'psql','-v','ON_ERROR_STOP=1','-U','harness','-d','disposable',
                '-c',"CREATE ROLE candidate_supplier_operator LOGIN PASSWORD 'candidate-local-only' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS")
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/suppliers/operator_grants.sql').read_text()
        if auth:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/auth/schema.sql').read_text()
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/auth/grants.sql').read_text()
        if api:
            schema += '\n' + '\n'.join((ROOT / 'candidate/lhie_candidate/customer_api' / name).read_text()
                                       for name in ('credential_grants.sql','orders_grants.sql','reads_grants.sql'))
        docker('exec',database,'psql','-v','ON_ERROR_STOP=1','-U','harness','-d','disposable','-c',schema)
        directory = harness.STATE / 'candidate'
        oracle_key = directory / 'p09-oracle-key'
        if receipt or lifecycle or auth or supplier or p16 or p17 or api:
            oracle_key.write_text('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n')
            oracle_key.chmod(0o600)
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
            '--env',f'P12_LIFECYCLE_SMOKE={"1" if lifecycle else "0"}',
            '--env',f'P19_AUTH_SMOKE={"1" if auth else "0"}',
            '--env',f'P16_USDT_SMOKE={"1" if p16 else "0"}',
            '--env',f'P17_USDT_SMOKE={"1" if p17 else "0"}',
            '--env',f'P20_API_SMOKE={"1" if api else "0"}',
            '--env',f'P18_SUPPLIER_SMOKE={"1" if supplier else "0"}','--entrypoint','python',
            'lhie-p13-candidate:local' if supplier or p17 or api else 'lhie-p09-candidate:local' if receipt or lifecycle or auth or p16 else 'lhie-p05-candidate:1','smoke_checkout.py']
        probe = subprocess.run(command,input=json.dumps(fixtures),capture_output=True,text=True,timeout=240)
        if probe.returncode:
            if receipt:
                diagnostic = [json.loads(line) for line in probe.stderr.splitlines() if line.startswith('{')]
                raise RuntimeError('P09 probe failed: ' + json.dumps(diagnostic))
            (output / 'probe-failure.stderr').write_text(probe.stderr)
            raise RuntimeError(f'{scope} probe failed; private diagnostics in .local/{scope}')
        observed = json.loads(probe.stdout)
        assert observed['status'] == 'passed'
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
        observed['dependency_image'] = docker('image','inspect','lhie-p13-candidate:local' if supplier or p17 or api else 'lhie-p09-candidate:local' if receipt or lifecycle or auth or p16 else 'lhie-p05-candidate:1','--format','{{.Id}}')
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
