#!/usr/bin/env python3
"""P06 uses the existing P01 namespace and real repository migration oracle."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys

import harness
from smoke import cli, container, docker

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/migration_compat'))
from schema_rehearsal import php


def main():
    global cli
    os.umask(0o077)
    p15 = sys.argv[1:] == ['--p15']
    if sys.argv[1:] not in ([], ['--p15']):
        raise ValueError('Usage: smoke_navigation.py [--p15]')
    if p15:
        from smoke_checkout import cli as isolated_cli
        cli = isolated_cli
        harness.STATE = ROOT / '.local/migration-p15/lifecycle'
        harness.PREFIX = 'lhie-p15-'
        if not (harness.STATE / 'candidate/config.json').exists():
            cli('init', 'candidate')
    config = harness.validate(json.loads((harness.STATE / 'candidate/config.json').read_text()))
    if config != harness.default('candidate'):
        raise RuntimeError('P06 smoke requires the default synthetic fake candidate')
    if docker('ps', '-q', '--filter', 'label=lhie.p01=disposable'):
        raise RuntimeError('Stop existing disposable stack before P06 smoke')
    output = ROOT / ('.local/migration-p15' if p15 else '.local/migration-p06')
    output.mkdir(parents=True, exist_ok=True)
    cli('reset', 'candidate')
    try:
        manifest = cli('start', 'candidate')
        boundary = container('candidate', 'boundary')
        database = container('candidate', 'database')
        php(boundary, 'php artisan migrate --force --no-interaction')
        fixture = (ROOT / 'tools/migration_harness/navigation_fixture.php').read_text().removeprefix('<?php')
        expected = json.loads(php(boundary, 'php -r ' + shlex.quote(fixture)))
        (output / 'php-reference.json').write_text(json.dumps(expected, ensure_ascii=False, indent=2) + '\n')
        schema = '\n'.join((ROOT / 'candidate/lhie_candidate/navigation' / name).read_text()
                           for name in ('schema.sql', 'ingress_schema.sql', 'assets_schema.sql'))
        grants = (ROOT / 'candidate/lhie_candidate/navigation/grants.sql').read_text()
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c', schema + grants)
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c',
               'GRANT DELETE ON django_candidate.navigation_roots TO candidate_runtime')
        app = container('candidate', 'app')
        probe = subprocess.run(['docker', 'exec', '-i', '-e', 'P06_NAVIGATION_SMOKE=disposable-local-only',
                                app, 'python', 'smoke_navigation.py'], input=json.dumps(expected),
                               capture_output=True, text=True, timeout=120)
        if probe.returncode:
            (output / 'probe-failure.json').write_text(probe.stdout)
            (output / 'probe-failure.stderr').write_text(probe.stderr)
            raise RuntimeError('P06 navigation probe failed; see .local/migration-p06/probe-failure.json')
        observed = json.loads(probe.stdout)
        assert observed['status'] == 'passed'
        # The funding follow-up applies its own write grants only after the
        # read-only Balance proof, so #718's navigation authority stays intact.
        funding_grants = (ROOT / 'candidate/lhie_candidate/funding/grants.sql').read_text()
        if p15:
            funding_grants += '\n' + (ROOT / 'candidate/lhie_candidate/funding/policy_admin_grants.sql').read_text()
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c', funding_grants)
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c',
               "INSERT INTO public.manual_payment_settings "
               "(is_active,payment_mode,payment_provider_version,sakurupiah_channel,created_at,updated_at) "
               "VALUES (true,'sakurupiah',1,'QRIS',now(),now())")
        funding_probe = subprocess.run([
            'docker', 'run', '--rm', '--network', 'container:' + boundary,
            '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
            '--tmpfs', '/tmp:size=64m', '--workdir', '/opt/candidate',
            '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
            '--mount', f'type=bind,src={harness.STATE / "candidate" / "candidate.json"},dst=/run/harness/candidate.json,readonly',
            '--mount', f'type=bind,src={harness.STATE / "candidate" / "django-key"},dst=/run/harness/django-key,readonly',
            '--mount', f'type=bind,src={harness.STATE / "candidate" / "app-key"},dst=/run/harness/app-key,readonly',
            '--env', 'P719_FUNDING_SMOKE=disposable-local-only',
            '--entrypoint', 'python', 'lhie-p05-candidate:1', 'smoke_funding.py',
        ], capture_output=True, text=True, timeout=180)
        if funding_probe.returncode:
            (output / 'funding-failure.stdout').write_text(funding_probe.stdout)
            (output / 'funding-failure.stderr').write_text(funding_probe.stderr)
            raise RuntimeError('P11 funding probe failed; see .local/migration-p06/funding-failure.*')
        observed['funding'] = json.loads(funding_probe.stdout)
        assert observed['funding']['status'] == 'passed'
        if p15:
            check = subprocess.run([
                'docker', 'run', '--rm', '--network', 'container:' + boundary,
                '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
                '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
                '--tmpfs', '/tmp:size=64m', '--workdir', '/opt/candidate',
                '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
                '--mount', f'type=bind,src={harness.STATE / "candidate" / "candidate.json"},dst=/run/harness/candidate.json,readonly',
                '--mount', f'type=bind,src={harness.STATE / "candidate" / "django-key"},dst=/run/harness/django-key,readonly',
                '--mount', f'type=bind,src={harness.STATE / "candidate" / "app-key"},dst=/run/harness/app-key,readonly',
                '--env', 'P719_FUNDING_SMOKE=disposable-local-only', '--entrypoint', 'python',
                'lhie-p05-candidate:1', '-c',
                'import json,sys; from lhie_candidate.startup import configure_django; configure_django(); '
                'sys.excepthook=sys.__excepthook__; sys.tracebacklimit=50; '
                'from tests.test_funding_policy_postgres import exercise_policy_and_reconciliation; '
                'from tests.test_funding_receipt_race_postgres import exercise_receipt_race; '
                'print(json.dumps({"policy":exercise_policy_and_reconciliation(int(sys.argv[1]),1),'
                '"receipt_race":exercise_receipt_race()}))',
                observed['funding']['topup_id'],
            ], capture_output=True, text=True, timeout=120)
            if check.returncode:
                (output / 'policy-failure.stderr').write_text(check.stderr)
                raise RuntimeError('P15 policy/reconciliation probe failed; see .local/migration-p15/policy-failure.stderr')
            observed['funding_policy_reconciliation'] = json.loads(check.stdout)
        for name in ('funding-failure.stdout', 'funding-failure.stderr'):
            (output / name).unlink(missing_ok=True)
        # The #720 navigation-checkout proof adds the minimum checkout/P08/P09
        # grants only after the read-only and funding proofs above.
        checkout_fixture = (ROOT / 'tools/migration_harness/checkout_fixture.php').read_text().removeprefix('<?php')
        checkout = json.loads(php(boundary, 'php -r ' + shlex.quote(checkout_fixture)))
        checkout_grants = (ROOT / 'candidate/lhie_candidate/checkout/grants.sql').read_text()
        checkout_grants += '\n' + (ROOT / 'candidate/lhie_candidate/payments/grants.sql').read_text()
        checkout_grants += '\n' + (ROOT / 'candidate/lhie_candidate/receipt/grants.sql').read_text()
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c', checkout_grants)
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c',
               "UPDATE public.manual_payment_settings SET is_active=false; "
               "INSERT INTO public.manual_payment_settings "
               "(is_active,payment_mode,payment_provider_version,sakurupiah_channel,created_at,updated_at) "
               "VALUES (true,'sakurupiah',1,'QRIS',now(),now())")
        oracle_key = harness.STATE / 'candidate' / 'p720-oracle-key'
        oracle_key.write_text('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n')
        oracle_key.chmod(0o600)
        checkout_probe = subprocess.run([
            'docker', 'run', '--rm', '-i', '--network', 'container:' + boundary,
            '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
            '--tmpfs', '/tmp:size=64m', '--workdir', '/opt/candidate',
            '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
            '--mount', f'type=bind,src={harness.STATE / "candidate" / "candidate.json"},dst=/run/harness/candidate.json,readonly',
            '--mount', f'type=bind,src={harness.STATE / "candidate" / "django-key"},dst=/run/harness/django-key,readonly',
            '--mount', f'type=bind,src={oracle_key},dst=/run/harness/app-key,readonly',
            '--env', 'P720_CHECKOUT_SMOKE=disposable-local-only',
            '--entrypoint', 'python', 'lhie-p13-candidate:local' if p15 else 'lhie-p09-candidate:local', 'smoke_checkout_navigation.py',
        ], input=json.dumps(checkout), capture_output=True, text=True, timeout=240)
        if checkout_probe.returncode:
            (output / 'checkout-failure.stdout').write_text(checkout_probe.stdout)
            (output / 'checkout-failure.stderr').write_text(checkout_probe.stderr)
            raise RuntimeError('P11 checkout-navigation probe failed; see .local/migration-p06/checkout-failure.*')
        observed['checkout_navigation'] = json.loads(checkout_probe.stdout)
        assert observed['checkout_navigation']['status'] == 'passed'
        if p15:
            auth_schema = '\n'.join((ROOT / 'candidate/lhie_candidate/auth' / name).read_text()
                                    for name in ('schema.sql', 'grants.sql'))
            auth_schema += '\n' + (ROOT / 'candidate/lhie_candidate/adjustments/grants.sql').read_text()
            docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c', auth_schema)
            adjustment_probe = subprocess.run([
                'docker', 'run', '--rm', '--network', 'container:' + boundary,
                '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
                '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
                '--tmpfs', '/tmp:size=64m', '--workdir', '/opt/candidate',
                '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
                '--mount', f'type=bind,src={harness.STATE / "candidate" / "candidate.json"},dst=/run/harness/candidate.json,readonly',
                '--mount', f'type=bind,src={harness.STATE / "candidate" / "django-key"},dst=/run/harness/django-key,readonly',
                '--mount', f'type=bind,src={oracle_key},dst=/run/harness/app-key,readonly',
                '--env', 'P719_FUNDING_SMOKE=disposable-local-only', '--entrypoint', 'python',
                'lhie-p13-candidate:local', '-c',
                'import json,sys,psycopg; from lhie_candidate.startup import configure_django; configure_django(); '
                'sys.excepthook=sys.__excepthook__; sys.tracebacklimit=50; '
                'from lhie_candidate.readiness import verify_dependencies; verify_dependencies(); '
                'from tests.test_adjustments_postgres import exercise_adjustments; '
                'a=psycopg.connect(host="127.0.0.1",hostaddr="127.0.0.1",port=5432,'
                'dbname="disposable",user="harness",password="harness",autocommit=True); '
                'print(json.dumps(exercise_adjustments(a))); a.close()',
            ], capture_output=True, text=True, timeout=180)
            if adjustment_probe.returncode:
                (output / 'adjustment-failure.stderr').write_text(adjustment_probe.stderr)
                raise RuntimeError('P15 adjustment probe failed; see .local/migration-p15/adjustment-failure.stderr')
            observed['adjustments'] = json.loads(adjustment_probe.stdout)
            docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable',
                   '-c', (ROOT / 'candidate/lhie_candidate/funding/klik_grants.sql').read_text())
            rail_probe = subprocess.run([
                'docker', 'run', '--rm', '--network', 'container:' + boundary,
                '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
                '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
                '--tmpfs', '/tmp:size=64m', '--workdir', '/opt/candidate',
                '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
                '--mount', f'type=bind,src={harness.STATE / "candidate" / "candidate.json"},dst=/run/harness/candidate.json,readonly',
                '--mount', f'type=bind,src={harness.STATE / "candidate" / "django-key"},dst=/run/harness/django-key,readonly',
                '--mount', f'type=bind,src={oracle_key},dst=/run/harness/app-key,readonly',
                '--env', 'P719_FUNDING_SMOKE=disposable-local-only', '--entrypoint', 'python',
                'lhie-p13-candidate:local', '-c',
                'import json; from smoke_funding_rails import run; print(json.dumps(run()))',
            ], capture_output=True, text=True, timeout=180)
            if rail_probe.returncode:
                (output / 'rails-failure.stderr').write_text(rail_probe.stderr)
                raise RuntimeError('P15 funding rails probe failed; see .local/migration-p15/rails-failure.stderr')
            observed['funding_rails'] = json.loads(rail_probe.stdout)
        for name in ('checkout-failure.stdout', 'checkout-failure.stderr',
                     'probe-failure.json', 'probe-failure.stderr'):
            (output / name).unlink(missing_ok=True)
        import hashlib
        sources = [*sorted((ROOT / 'candidate/lhie_candidate/navigation').glob('*')),
                   *sorted((ROOT / 'candidate/lhie_candidate/funding').glob('*')),
                   *sorted((ROOT / 'candidate/lhie_candidate/payments').glob('*')),
                   *sorted((ROOT / 'candidate/lhie_candidate/receipt').glob('*')),
                   *sorted((ROOT / 'candidate/lhie_candidate/checkout').glob('*')),
                   ROOT / 'candidate/lhie_candidate/workers/services.py',
                   ROOT / 'candidate/smoke_funding.py',
                   ROOT / 'candidate/smoke_checkout_navigation.py',
                   ROOT / 'candidate/navigate.py', ROOT / 'candidate/smoke_navigation.py',
                   ROOT / 'candidate/lhie_candidate/readiness.py', ROOT / 'candidate/lhie_candidate/http.py',
                   ROOT / 'candidate/lhie_candidate/urls.py', Path(__file__),
                   ROOT / 'tools/migration_harness/boundary.py',
                   ROOT / 'tools/migration_harness/harness.py',
                   ROOT / 'tools/migration_harness/navigation_fixture.php',
                   ROOT / 'tools/migration_harness/checkout_fixture.php',
                   ROOT / 'candidate/tests/test_navigation_checkout.py']
        if p15:
            sources.extend((*sorted((ROOT / 'candidate/lhie_candidate/adjustments').glob('*')),
                            ROOT / 'candidate/lhie_candidate/workers/payment.py',
                            ROOT / 'candidate/lhie_candidate/workers/contract.py',
                            ROOT / 'candidate/lhie_candidate/workers/wakeup.py',
                            ROOT / 'candidate/smoke_funding_rails.py',
                            ROOT / 'candidate/tests/test_adjustments_postgres.py',
                            ROOT / 'candidate/tests/test_funding_policy_postgres.py',
                            ROOT / 'candidate/tests/test_funding_receipt_race_postgres.py',
                            ROOT / 'candidate/check_store_balance.py'))
        observed['source_sha256'] = {str(path.relative_to(ROOT)):hashlib.sha256(path.read_bytes()).hexdigest()
                                     for path in sources if path.is_file()}
        observed['harness_manifest'] = str(Path(manifest).relative_to(ROOT))
        observed['postgres_version'] = docker('exec',database,'postgres','--version')
        (output / 'verification.json').write_text(json.dumps(observed, indent=2) + '\n')
        print(json.dumps(observed, indent=2))
    finally:
        cli('reset', 'candidate')


if __name__ == '__main__':
    main()
