#!/usr/bin/env python3
"""P08 synthetic QRIS proof using the P01 quarantine and P07 PHP fixtures."""
import json
import hashlib
import os
from pathlib import Path
import shlex
import subprocess
import sys
from uuid import uuid4

import harness
from smoke import container, docker
from smoke_checkout import ROOT, php


def cli(*args):
    entry = ("import sys; from pathlib import Path; import harness; "
             "harness.STATE=Path(sys.argv[1]); harness.PREFIX=sys.argv[2]; "
             "sys.argv=[harness.__file__,*sys.argv[3:]]; harness.main()")
    result = subprocess.run([sys.executable, '-c', entry, str(harness.STATE), harness.PREFIX, *args],
        cwd=ROOT / 'tools/migration_harness', capture_output=True, text=True, timeout=180)
    if result.returncode:
        raise RuntimeError('P08 disposable lifecycle command failed: ' + ' '.join(args))
    return result.stdout.strip()


def main():
    rails = sys.argv[1:] == ['--rails']
    integrated = sys.argv[1:] == ['--integrated']
    external = sys.argv[1:] == ['--external']
    if sys.argv[1:] not in ([], ['--integrated'], ['--rails'], ['--external']):
        raise ValueError('Usage: smoke_qris.py [--integrated|--rails|--external]')
    os.umask(0o077)
    namespace = 'migration-p14' if external else 'migration-p13' if rails else 'migration-726-' + uuid4().hex if integrated else 'migration-p08'
    harness.STATE = ROOT / '.local' / namespace / 'lifecycle'
    harness.PREFIX = 'lhie-p14-' if external else 'lhie-p13-' if rails else 'lhie-726-' + namespace[-12:] + '-' if integrated else 'lhie-p08-'
    directory = harness.STATE / 'candidate'
    if not (directory / 'config.json').exists():
        cli('init', 'candidate')
    config = harness.validate(json.loads((directory / 'config.json').read_text()))
    if config != harness.default('candidate'):
        raise RuntimeError('P08 requires default synthetic fake configuration')
    if docker('ps', '-q', '--filter', 'label=lhie.p01=disposable'):
        raise RuntimeError('Stop existing disposable stack before P08')
    output = ROOT / '.local' / namespace
    output.mkdir(parents=True, exist_ok=True)
    if not integrated:
        cli('reset', 'candidate')
    harness.candidate_admission(config, directory)
    try:
        manifest = cli('start', 'candidate', '--quarantine')
        boundary = container('candidate', 'boundary')
        database = container('candidate', 'database')
        php(boundary, 'php artisan migrate --force --no-interaction')
        fixture = (ROOT / 'tools/migration_harness/checkout_fixture.php').read_text().removeprefix('<?php')
        fixtures = json.loads(php(boundary, ('P14_EXTERNAL_SMOKE=1 ' if external else '') + 'php -r ' + shlex.quote(fixture)))
        schema = '\n'.join((ROOT / 'candidate/lhie_candidate/navigation' / name).read_text()
            for name in ('schema.sql', 'ingress_schema.sql', 'assets_schema.sql', 'grants.sql'))
        schema += '\n' + (ROOT / 'candidate/lhie_candidate/checkout/grants.sql').read_text()
        schema += '\n' + (ROOT / 'candidate/lhie_candidate/payments/grants.sql').read_text()
        if rails or external:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/payments/settings_grants.sql').read_text()
        if external:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/payments/receiving_grants.sql').read_text()
        if integrated or rails or external:
            schema += '\n' + (ROOT / 'candidate/lhie_candidate/receipt/grants.sql').read_text()
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c', schema)
        oracle_key = directory / 'receipt-oracle-key'
        if integrated or rails or external:
            docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c',
                   "UPDATE public.orders SET telegram_receipt_sent_at=now() "
                   "WHERE customer_id IN (SELECT id FROM public.customers WHERE telegram_id=700001) "
                   "AND payment_status='paid'")
            oracle_key.write_text('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n')
            oracle_key.chmod(0o600)
        command = ['docker', 'run', '--rm', '-i', '--network', 'container:' + boundary,
            '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
            '--tmpfs', '/tmp:size=64m',
            '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
            '--mount', f'type=bind,src={ROOT / "tools/migration_harness"},dst=/opt/harness,readonly',
            '--mount', f'type=bind,src={directory / "candidate.json"},dst=/run/harness/candidate.json,readonly',
            '--mount', f'type=bind,src={directory / "django-key"},dst=/run/harness/django-key,readonly',
            '--mount', f'type=bind,src={output},dst=/evidence',
            *(['--mount', f'type=bind,src={oracle_key},dst=/run/harness/app-key,readonly'] if integrated or rails or external else []),
            '--env', 'P07_CHECKOUT_SMOKE=disposable-local-only',
            '--env', 'P08_QRIS_SMOKE=disposable-local-only',
            '--env', f'P13_QRIS_RAILS={"1" if rails else "0"}',
            '--env', f'P14_EXTERNAL_SMOKE={"1" if external else "0"}',
            '--env', f'P08_INTEGRATED_SMOKE={"1" if integrated else "0"}', '--entrypoint', 'python',
            'lhie-p13-candidate:local', 'smoke_qris.py']
        probe = subprocess.run(command, input=json.dumps(fixtures), capture_output=True, text=True, timeout=180)
        if probe.returncode:
            (output / 'probe-failure.stderr').write_text(probe.stderr)
            raise RuntimeError(f'P08 probe failed; private diagnostics in {output.relative_to(ROOT)}')
        observed = json.loads(probe.stdout)
        assert observed['status'] == 'passed'
        observed['harness_manifest'] = str(Path(manifest).relative_to(ROOT))
        if rails or external:
            sources = [*sorted((ROOT / 'candidate/lhie_candidate/payments').glob('*')),
                       *sorted((ROOT / 'candidate/lhie_candidate/checkout').glob('*')),
                       *sorted((ROOT / 'candidate/lhie_candidate/workers').glob('*.py')),
                       ROOT / 'candidate/tests/test_qris_postgres.py', ROOT / 'candidate/requirements.lock',
                       ROOT / 'candidate/lhie_candidate/navigation/processor.py',
                       ROOT / 'candidate/lhie_candidate/navigation/presentation.py',
                       ROOT / 'candidate/lhie_candidate/navigation/roots.py',
                       ROOT / 'candidate/lhie_candidate/navigation/transport.py',
                       ROOT / 'candidate/lhie_candidate/navigation/ingress.py',
                       ROOT / 'candidate/lhie_candidate/runtime.py',
                       ROOT / 'candidate/lhie_candidate/readiness.py',
                       ROOT / 'candidate/tests/test_public_receiving.py',
                       ROOT / 'tools/migration_compat/schema_rehearsal.py',
                       ROOT / 'candidate/smoke_qris.py',
                       ROOT / 'tools/migration_harness/checkout_fixture.php',
                       ROOT / 'candidate/tests/test_external_usdt_postgres.py',
                       ROOT / 'candidate/tests/test_binance.py', ROOT / 'candidate/tests/test_bsc.py',
                       ROOT / 'src/database/migrations/2026_11_05_000001_add_public_binance_checkout_receiver.php',
                       Path(__file__)]
            observed['source_sha256'] = {str(path.relative_to(ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
                                         for path in sources if path.is_file()}
            observed['base_revision'] = subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
            observed['dependency_image'] = docker('image','inspect','lhie-p13-candidate:local','--format','{{.Id}}')
        (output / 'verification.json').write_text(json.dumps(observed, indent=2) + '\n')
        (output / 'probe-failure.stderr').unlink(missing_ok=True)
        (output / 'probe-failure.txt').unlink(missing_ok=True)
        print(json.dumps(observed, indent=2))
    finally:
        cli('reset', 'candidate')


if __name__ == '__main__':
    main()
