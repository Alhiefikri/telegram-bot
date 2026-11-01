#!/usr/bin/env python3
"""P08 synthetic QRIS proof using the P01 quarantine and P07 PHP fixtures."""
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys

import harness
from smoke import container, docker
from smoke_checkout import ROOT, php


def cli(*args):
    entry = ("import sys; from pathlib import Path; import harness; "
             "harness.STATE=Path(sys.argv[1]); harness.PREFIX='lhie-p08-'; "
             "sys.argv=[harness.__file__,*sys.argv[2:]]; harness.main()")
    result = subprocess.run([sys.executable, '-c', entry, str(harness.STATE), *args],
        cwd=ROOT / 'tools/migration_harness', capture_output=True, text=True, timeout=180)
    if result.returncode:
        raise RuntimeError('P08 disposable lifecycle command failed: ' + ' '.join(args))
    return result.stdout.strip()


def main():
    os.umask(0o077)
    harness.STATE = ROOT / '.local/migration-p08/lifecycle'
    harness.PREFIX = 'lhie-p08-'
    directory = harness.STATE / 'candidate'
    if not (directory / 'config.json').exists():
        cli('init', 'candidate')
    config = harness.validate(json.loads((directory / 'config.json').read_text()))
    if config != harness.default('candidate'):
        raise RuntimeError('P08 requires default synthetic fake configuration')
    if docker('ps', '-q', '--filter', 'label=lhie.p01=disposable'):
        raise RuntimeError('Stop existing disposable stack before P08')
    output = ROOT / '.local/migration-p08'
    output.mkdir(parents=True, exist_ok=True)
    cli('reset', 'candidate')
    harness.candidate_admission(config, directory)
    try:
        manifest = cli('start', 'candidate', '--quarantine')
        boundary = container('candidate', 'boundary')
        database = container('candidate', 'database')
        php(boundary, 'php artisan migrate --force --no-interaction')
        fixture = (ROOT / 'tools/migration_harness/checkout_fixture.php').read_text().removeprefix('<?php')
        fixtures = json.loads(php(boundary, 'php -r ' + shlex.quote(fixture)))
        schema = '\n'.join((ROOT / 'candidate/lhie_candidate/navigation' / name).read_text()
            for name in ('schema.sql', 'ingress_schema.sql', 'assets_schema.sql', 'grants.sql'))
        schema += '\n' + (ROOT / 'candidate/lhie_candidate/checkout/grants.sql').read_text()
        schema += '\n' + (ROOT / 'candidate/lhie_candidate/payments/grants.sql').read_text()
        docker('exec', database, 'psql', '-v', 'ON_ERROR_STOP=1', '-U', 'harness', '-d', 'disposable', '-c', schema)
        command = ['docker', 'run', '--rm', '-i', '--network', 'container:' + boundary,
            '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
            '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
            '--tmpfs', '/tmp:size=64m',
            '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
            '--mount', f'type=bind,src={ROOT / "tools/migration_harness"},dst=/opt/harness,readonly',
            '--mount', f'type=bind,src={directory / "candidate.json"},dst=/run/harness/candidate.json,readonly',
            '--mount', f'type=bind,src={directory / "django-key"},dst=/run/harness/django-key,readonly',
            '--mount', f'type=bind,src={output},dst=/evidence',
            '--env', 'P07_CHECKOUT_SMOKE=disposable-local-only',
            '--env', 'P08_QRIS_SMOKE=disposable-local-only', '--entrypoint', 'python',
            'lhie-p05-candidate:1', 'smoke_qris.py']
        probe = subprocess.run(command, input=json.dumps(fixtures), capture_output=True, text=True, timeout=180)
        if probe.returncode:
            (output / 'probe-failure.stderr').write_text(probe.stderr)
            raise RuntimeError('P08 probe failed; private diagnostics in .local/migration-p08')
        observed = json.loads(probe.stdout)
        assert observed['status'] == 'passed'
        observed['harness_manifest'] = str(Path(manifest).relative_to(ROOT))
        (output / 'verification.json').write_text(json.dumps(observed, indent=2) + '\n')
        (output / 'probe-failure.stderr').unlink(missing_ok=True)
        (output / 'probe-failure.txt').unlink(missing_ok=True)
        print(json.dumps(observed, indent=2))
    finally:
        cli('reset', 'candidate')


if __name__ == '__main__':
    main()
