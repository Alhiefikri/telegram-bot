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
    os.umask(0o077)
    config = harness.validate(json.loads((harness.STATE / 'candidate/config.json').read_text()))
    if config != harness.default('candidate'):
        raise RuntimeError('P06 smoke requires the default synthetic fake candidate')
    if docker('ps', '-q', '--filter', 'label=lhie.p01=disposable'):
        raise RuntimeError('Stop existing disposable stack before P06 smoke')
    output = ROOT / '.local/migration-p06'
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
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c', funding_grants)
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c',
               "INSERT INTO public.manual_payment_settings "
               "(is_active,payment_mode,payment_provider_version,sakurupiah_channel,created_at,updated_at) "
               "VALUES (true,'sakurupiah',1,'QRIS',now(),now())")
        funding_probe = subprocess.run([
            'docker', 'run', '--rm', '--network', 'container:' + boundary, '--volumes-from', app,
            '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
            '--tmpfs', '/tmp:size=64m', '--workdir', '/opt/candidate',
            '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
            '--env', 'P719_FUNDING_SMOKE=disposable-local-only',
            '--entrypoint', 'python', 'lhie-p05-candidate:1', 'smoke_funding.py',
        ], capture_output=True, text=True, timeout=180)
        if funding_probe.returncode:
            (output / 'funding-failure.stdout').write_text(funding_probe.stdout)
            (output / 'funding-failure.stderr').write_text(funding_probe.stderr)
            raise RuntimeError('P11 funding probe failed; see .local/migration-p06/funding-failure.*')
        observed['funding'] = json.loads(funding_probe.stdout)
        assert observed['funding']['status'] == 'passed'
        import hashlib
        sources = [*sorted((ROOT / 'candidate/lhie_candidate/navigation').glob('*')),
                   *sorted((ROOT / 'candidate/lhie_candidate/funding').glob('*')),
                   ROOT / 'candidate/lhie_candidate/payments/receipts.py',
                   ROOT / 'candidate/lhie_candidate/payments/lifecycle.py',
                   ROOT / 'candidate/lhie_candidate/checkout/balance.py',
                   ROOT / 'candidate/lhie_candidate/checkout/privileges.py',
                   ROOT / 'candidate/smoke_funding.py',
                   ROOT / 'candidate/navigate.py', ROOT / 'candidate/smoke_navigation.py',
                   ROOT / 'candidate/lhie_candidate/readiness.py', ROOT / 'candidate/lhie_candidate/http.py',
                   ROOT / 'candidate/lhie_candidate/urls.py', Path(__file__),
                   ROOT / 'tools/migration_harness/boundary.py',
                   ROOT / 'tools/migration_harness/harness.py',
                   ROOT / 'tools/migration_harness/navigation_fixture.php']
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
