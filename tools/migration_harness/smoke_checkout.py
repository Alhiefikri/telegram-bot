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
             "harness.STATE=Path(sys.argv[1]); harness.PREFIX='lhie-p07-'; "
             "sys.argv=[harness.__file__,*sys.argv[2:]]; harness.main()")
    result = subprocess.run([sys.executable,'-c',entry,str(harness.STATE),*args],
        cwd=ROOT / 'tools/migration_harness',capture_output=True,text=True,timeout=180)
    if result.returncode:
        raise RuntimeError('P07 disposable lifecycle command failed: ' + ' '.join(args))
    return result.stdout.strip()


def main():
    os.umask(0o077)
    harness.STATE = ROOT / '.local/migration-p07/lifecycle'
    harness.PREFIX = 'lhie-p07-'
    if not (harness.STATE / 'candidate/config.json').exists():
        cli('init','candidate')
    config = harness.validate(json.loads((harness.STATE / 'candidate/config.json').read_text()))
    if config != harness.default('candidate'):
        raise RuntimeError('P07 requires default synthetic fake configuration')
    if docker('ps','-q','--filter','label=lhie.p01=disposable'):
        raise RuntimeError('Stop existing disposable stack before P07')
    output = ROOT / '.local/migration-p07'
    output.mkdir(parents=True,exist_ok=True)
    cli('reset','candidate')
    harness.candidate_admission(config,harness.STATE / 'candidate')
    try:
        manifest = cli('start','candidate','--quarantine')
        boundary = container('candidate','boundary')
        database = container('candidate','database')
        php(boundary,'php artisan migrate --force --no-interaction')
        fixture = (ROOT / 'tools/migration_harness/checkout_fixture.php').read_text().removeprefix('<?php')
        fixtures = json.loads(php(boundary,'php -r ' + shlex.quote(fixture)))
        schema = '\n'.join((ROOT / 'candidate/lhie_candidate/navigation' / name).read_text()
            for name in ('schema.sql','ingress_schema.sql','assets_schema.sql','grants.sql'))
        schema += '\n' + (ROOT / 'candidate/lhie_candidate/checkout/grants.sql').read_text()
        docker('exec',database,'psql','-v','ON_ERROR_STOP=1','-U','harness','-d','disposable','-c',schema)
        directory = harness.STATE / 'candidate'
        command = ['docker','run','--rm','-i','--network','container:'+boundary,
            '--read-only','--cap-drop','ALL','--security-opt','no-new-privileges:true',
            '--user','1000:1000','--cpus','1.25','--memory','1216m','--memory-swap','1216m',
            '--tmpfs','/tmp:size=64m',
            '--mount',f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
            '--mount',f'type=bind,src={directory / "candidate.json"},dst=/run/harness/candidate.json,readonly',
            '--mount',f'type=bind,src={directory / "django-key"},dst=/run/harness/django-key,readonly',
            '--mount',f'type=bind,src={output},dst=/evidence',
            '--env','P07_CHECKOUT_SMOKE=disposable-local-only','--entrypoint','python',
            'lhie-p05-candidate:1','smoke_checkout.py']
        probe = subprocess.run(command,input=json.dumps(fixtures),capture_output=True,text=True,timeout=180)
        if probe.returncode:
            (output / 'probe-failure.stderr').write_text(probe.stderr)
            raise RuntimeError('P07 probe failed; private diagnostics in .local/migration-p07')
        observed = json.loads(probe.stdout)
        assert observed['status'] == 'passed'
        sources = [*sorted((ROOT / 'candidate/lhie_candidate/checkout').glob('*')),
            ROOT / 'candidate/lhie_candidate/readiness.py', ROOT / 'candidate/smoke_checkout.py',
            ROOT / 'candidate/tests/test_checkout_postgres.py',ROOT / 'candidate/tests/test_checkout_pricing.py',
            ROOT / 'candidate/requirements.lock',ROOT / 'tools/migration_harness/checkout_fixture.php',Path(__file__)]
        observed['source_sha256'] = {str(path.relative_to(ROOT)):hashlib.sha256(path.read_bytes()).hexdigest()
                                      for path in sources if path.is_file()}
        observed['base_revision'] = subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
        observed['dependency_image'] = docker('image','inspect','lhie-p05-candidate:1','--format','{{.Id}}')
        migration_digest = hashlib.sha256()
        for path in sorted((ROOT / 'src/database/migrations').glob('*.php')):
            migration_digest.update(str(path.relative_to(ROOT)).encode() + b'\0' + path.read_bytes() + b'\0')
        observed['migration_sources_sha256'] = migration_digest.hexdigest()
        observed['legacy_owner_sha256'] = {name:hashlib.sha256((ROOT / name).read_bytes()).hexdigest()
            for name in ('src/app/Services/OrderCheckoutService.php','src/app/Services/StoreBalance/StoreBalanceLedger.php',
                'src/app/Services/EloquentInventoryLedger.php','src/app/Services/ProductCredentialAllocator.php',
                'src/app/Services/Pricing/ProductQuantityPricing.php','src/app/Services/OrderStateMachine.php')}
        observed['postgres_version'] = docker('exec',database,'postgres','--version')
        observed['harness_manifest'] = str(Path(manifest).relative_to(ROOT))
        observed['limits'] = ['Synthetic local data/test keys only; no production schema/history qualification',
            'Runtime uses explicit checkout column grants; grants are never applied at startup',
            'Shared service seam only; Telegram checkout consumer/delivery and QRIS sandbox remain P08/P09',
            'Original-order refund posting prerequisite only; full lifecycle/warranty/supplier workflows remain P12/P18',
            'Source bind-mounted read-only over the existing locked dependency image; no performance claim']
        (output / 'verification.json').write_text(json.dumps(observed,indent=2)+'\n')
        (output / 'probe-failure.txt').unlink(missing_ok=True)
        (output / 'probe-failure.stderr').unlink(missing_ok=True)
        print(json.dumps(observed,indent=2))
    finally:
        cli('reset','candidate')


if __name__ == '__main__':
    main()
