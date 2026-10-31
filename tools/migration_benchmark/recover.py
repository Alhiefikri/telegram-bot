#!/usr/bin/env python3
"""Recover sanitized P04 attempts from stopped, owned disposable storage, read-only."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/migration_harness'))
import harness
from report import DIMENSIONS, summarize


def checked(args):
    result = subprocess.run(args, capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError('recovery_command_failed')
    return result.stdout


def identity(row):
    return tuple(row[key] for key in (*DIMENSIONS, 'warmup', 'batch', 'attempt'))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    output = args.output.resolve()
    if not output.is_relative_to(ROOT / '.local'):
        raise RuntimeError('only_local_evidence_admitted')
    meta = json.loads((output / 'run.json').read_text())
    if meta['status'] != 'running' or not meta.get('batches'):
        raise RuntimeError('requires_interrupted_run_with_recorded_cell')
    harness.docker_admission()
    if checked(['docker', 'ps', '-q', '--filter', 'label=lhie.p01=disposable']).strip():
        raise RuntimeError('stop_disposable_services_before_offline_recovery')
    harness.owned_volumes('laravel')
    volume = 'lhie-p01-laravel_storage'
    inspected = json.loads(checked(['docker', 'volume', 'inspect', volume]))[0]
    if inspected.get('Labels', {}).get('lhie.p01') != 'disposable' or inspected['Labels'].get('lhie.p01.stack') != 'laravel':
        raise RuntimeError('unowned_storage_refused')
    run_id = Path(meta['batches'][0]['cell']['evidence_path']).name.split('-', 1)[0]
    if not re.fullmatch('[a-f0-9]{32}', run_id):
        raise RuntimeError('invalid_evidence_identity')
    recovery = output / 'offline-recovery'
    recovery.mkdir(exist_ok=False)
    reader = r'''
import json,pathlib,sys
root=pathlib.Path('/evidence/app/private/p04-evidence')
for path in sorted(root.glob(sys.argv[1]+'-*.jsonl')):
    if path.is_symlink() or not path.is_file() or path.stat().st_size>1024**3:
        raise RuntimeError('unsafe evidence file')
    print(json.dumps({'name':path.name,'contents':path.read_text()}),flush=True)
'''
    process = subprocess.Popen(['docker', 'run', '--rm', '--network', 'none', '--read-only',
        '--cpus', '0.1', '--memory', '64m', '--memory-swap', '64m', '--pids-limit', '16',
        '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', '--user', '1000:1000',
        '--mount', 'type=volume,src=' + volume + ',dst=/evidence,readonly',
        '--entrypoint', 'python', 'lhie-p01-boundary:1', '-c', reader, run_id],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    rows = {}
    raw = output / 'samples.jsonl'
    for line in raw.read_text().splitlines():
        row = json.loads(line)
        summarize([row])
        rows[identity(row)] = row
    files = []
    rejected_lines = 0
    for envelope in process.stdout:
        file = json.loads(envelope)
        name = file['name']
        if not re.fullmatch(run_id + r'-[a-zA-Z0-9_.-]+\.jsonl', name):
            raise RuntimeError('unsafe_spool_name')
        contents = file['contents']
        (recovery / name).write_text(contents)
        valid = 0
        for line in contents.splitlines():
            try:
                row = json.loads(line)
                summarize([row])
            except (ValueError, TypeError):
                rejected_lines += 1
                continue
            key = identity(row)
            if key in rows and rows[key] != row:
                raise RuntimeError('conflicting_attempt_evidence')
            rows.setdefault(key, row)
            valid += 1
        files.append({'name': name, 'valid_records': valid,
                      'sha256': hashlib.sha256(contents.encode()).hexdigest()})
    if process.wait(timeout=30):
        raise RuntimeError('offline_storage_read_failed')
    before = len(raw.read_text().splitlines())
    pending = raw.with_suffix('.jsonl.pending')
    with pending.open('w') as stream:
        for key in sorted(rows):
            stream.write(json.dumps(rows[key], separators=(',', ':')) + '\n')
        stream.flush()
        os.fsync(stream.fileno())
    pending.replace(raw)
    summary = {'status': 'interrupted_recovered', 'previous_host_records': before,
               'retained_records': len(rows), 'newly_recovered_records': len(rows) - before,
               'rejected_spool_lines': rejected_lines, 'files': files,
               'limits': ['Partial cells are not qualified; no missing HTTP attempt is invented.',
                          'Volatile Redis state was lost; do not resume this sequential-state protocol as if uninterrupted.']}
    (recovery / 'summary.json').write_text(json.dumps(summary, indent=2) + '\n')
    meta['status'] = 'interrupted_recovered'
    meta['offline_recovery'] = summary
    meta['recovered_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    (output / 'run.json').write_text(json.dumps(meta, indent=2) + '\n')
    report = summarize(rows.values())
    report['run_status'] = meta['status']
    (output / 'report.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps({key: value for key, value in summary.items() if key not in ('files', 'limits')}))


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError, RuntimeError, subprocess.SubprocessError):
        print('Offline recovery refused safely; original storage is unchanged.', file=sys.stderr)
        raise SystemExit(1)
