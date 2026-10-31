#!/usr/bin/env python3
"""Fault-inject only a disposable P04 runner and verify real stopped-container recovery."""
import argparse
import json
import os
from pathlib import Path
import signal
import subprocess
import time

import runner


def await_state(output, condition, deadline=600):
    end = time.monotonic() + deadline
    while time.monotonic() < end:
        try:
            state = json.loads((output / 'run.json').read_text())
        except (FileNotFoundError, json.JSONDecodeError):
            time.sleep(.05)
            continue
        if state.get('status') == 'failed':
            raise RuntimeError('runner_failed_before_fault_barrier')
        if condition(state):
            return state
        time.sleep(.05)
    raise RuntimeError('fault_barrier_timeout')


def spool_ready(active):
    script = '$p=$argv[1];clearstatcache(true,$p);echo is_file($p)&&filesize($p)>0?"ready":"waiting";'
    return runner.command(['docker', 'exec', active['app'], 'php', '-r', script,
                           active['cell']['evidence_path']], timeout=15) == 'ready'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    output = args.output.resolve()
    if not output.is_relative_to(runner.ROOT / '.local') or output.exists():
        raise RuntimeError('requires_new_local_output')
    runner.harness.docker_admission()
    if runner.command(['docker', 'ps', '-q', '--filter', 'label=lhie.p01=disposable']):
        raise RuntimeError('stop_existing_disposable_stack_first')
    print(json.dumps({'event': 'resume_smoke_started', 'output': str(output.relative_to(runner.ROOT))}), flush=True)
    log_dir = output.parent / (output.name + '-diagnostic')
    log_dir.mkdir(parents=True)
    os.chmod(log_dir, 0o700)
    command = ['python3', str(runner.TOOLS / 'runner.py'), '--output', str(output)]
    first_log = (log_dir / 'initial.log').open('w')
    first = subprocess.Popen(command, stdout=first_log, stderr=subprocess.STDOUT, cwd=runner.ROOT)
    resumed = None
    try:
        state = await_state(output, lambda s: s.get('active') is not None and spool_ready(s['active']))
        active = state['active']
        initial_identity = runner.point_name(active['cell'])
        # Freeze the real probe cohort so the durable-record barrier stays partial.
        marker = active['cell']['evidence_path'] + '.pid'
        freeze = '$p=(int)trim(file_get_contents($argv[1]));if($p<2||posix_getpgid($p)!==$p||!posix_kill(-$p,SIGSTOP)){exit(1);}echo "frozen";'
        runner.command(['docker', 'exec', active['app'], 'php', '-r', freeze, marker])
        first.kill()
        first.wait(timeout=30)
        first_log.close()
        before = runner.command(['docker', 'exec', active['app'], 'cat', active['cell']['evidence_path']])
        original_rows = [json.loads(line) for line in before.splitlines()]
        if not 0 < len(original_rows) < active['cell']['samples']:
            raise RuntimeError('fault_did_not_interrupt_partial_phase')
        ids = [runner.container('laravel', service) for service in ('app', 'database', 'redis', 'boundary')]
        runner.command(['docker', 'stop', '--time', '1', *ids], timeout=30)
        print(json.dumps({'event': 'stopped_container_fault_injected', 'retained_before_restart': len(original_rows)}), flush=True)
        resumed_log = (log_dir / 'resumed.log').open('w')
        resumed = subprocess.Popen(command + ['--resume'], stdout=resumed_log,
                                   stderr=subprocess.STDOUT, cwd=runner.ROOT)
        state = await_state(output, lambda s: s.get('active') is not None
                            and s['active']['cell']['batch'] > active['cell']['batch']
                            and spool_ready(s['active']))
        resumed.send_signal(signal.SIGINT)
        resumed.wait(timeout=120)
        resumed_log.close()
        final = json.loads((output / 'run.json').read_text())
        checkpoints = runner.load_checkpoints(output, runner.fingerprint(runner.sources()))
        recovered = next(point for point in checkpoints if runner.point_name(point['cell']) == initial_identity)
        if recovered['completed']:
            raise RuntimeError('partial_phase_misclassified_complete')
        by_attempt = {row['attempt']: row for row in recovered['samples']}
        if any(by_attempt[row['attempt']] != row for row in original_rows):
            raise RuntimeError('completed_attempt_changed_during_recovery')
        rows = [json.loads(line) for line in (output / 'samples.jsonl').read_text().splitlines()]
        runner.summarize(rows)
        if final['disposable_cleanup'] != 'reset':
            raise RuntimeError('diagnostic_cleanup_unconfirmed')
        proof = {'status': 'passed', 'scope': 'fault_diagnostic_not_baseline',
                 'completed_attempts_preserved': len(original_rows),
                 'interrupted_batch': active['cell']['batch'],
                 'replacement_batch': state['active']['cell']['batch'],
                 'original_phase_remains_incomplete': True, 'duplicate_attempts': 0,
                 'disposable_cleanup': final['disposable_cleanup']}
        runner.atomic_json(log_dir / 'proof.json', proof)
        print(json.dumps({'event': 'resume_smoke_completed', **proof}), flush=True)
    finally:
        if first.poll() is None:
            first.kill()
            first.wait(timeout=30)
        if resumed is not None and resumed.poll() is None:
            resumed.send_signal(signal.SIGINT)
            resumed.wait(timeout=120)


if __name__ == '__main__':
    main()
