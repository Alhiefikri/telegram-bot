#!/usr/bin/env python3
"""Exercise P04 clone admission and real Laravel paths; never qualifies sample coverage."""
import argparse
import json
import os
from pathlib import Path
import time
import uuid

import runner


def verify_queue_sensor(ids):
    prefix = 'p04-sensor-' + uuid.uuid4().hex + ':queues:probe'
    keys = [prefix, prefix + ':reserved', prefix + ':delayed', prefix + ':notify']
    create = ("redis.call('RPUSH',KEYS[1],'synthetic','synthetic');"
              "redis.call('ZADD',KEYS[2],1,'reserved');"
              "redis.call('ZADD',KEYS[3],1,'delayed1',2,'delayed2',3,'delayed3');"
              "for i=1,9 do redis.call('RPUSH',KEYS[4],'1') end;return 1")
    runner.command(['docker', 'exec', ids['redis'], 'redis-cli', 'EVAL', create, '4', *keys])
    try:
        observed = runner.snapshot(ids)['redis']
        expected = dict(ready=2, reserved=1, delayed=3, wakeup_tokens=9, scan_complete=1)
        if observed != expected:
            raise RuntimeError('queue_sensor_confuses_wakeup_tokens_with_jobs')
        return observed
    finally:
        runner.command(['docker', 'exec', ids['redis'], 'redis-cli', 'DEL', *keys])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    output = args.output.resolve()
    if not output.is_relative_to(runner.ROOT / '.local') or output.exists():
        raise RuntimeError('requires_new_local_artifact_directory')
    runner.harness.docker_admission()
    config = runner.harness.validate(json.loads((runner.harness.STATE / 'laravel/config.json').read_text()))
    if config != runner.harness.default('laravel'):
        raise RuntimeError('only_default_synthetic_configuration_admitted')
    if runner.command(['docker', 'ps', '-q', '--filter', 'label=lhie.p01=disposable']):
        raise RuntimeError('stop_existing_disposable_stack_first')
    output.mkdir(parents=True)
    state = {'status': 'running', 'qualification': 'smoke_only', 'source_sha256': runner.sources(),
             'checks': [], 'datasets': [], 'started_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}
    def save():
        runner.atomic_json(output / 'smoke.json', state)
    save()
    print(json.dumps({'event': 'smoke_started', 'output': str(output.relative_to(runner.ROOT))}), flush=True)
    safe_to_reset = True
    active = None
    try:
        runner.cli('reset', 'laravel')
        state['harness'] = json.loads(Path(runner.cli('start', 'laravel')).read_text())
        ids = {s: runner.container('laravel', s) for s in ('app', 'database', 'redis', 'boundary')}
        runner.install_tools(ids['app'])
        observed = runner.snapshot(ids)
        runner.verify_limits(observed)
        runner.atomic_json(output / 'admission-resources.json', observed)
        state['queue_sensor_proof'] = verify_queue_sensor(ids)
        save()
        meta = {'dataset_contract': json.loads((runner.TOOLS / 'datasets.json').read_text()), 'datasets': []}
        manifests = {}
        with runner.Sensors(ids, output / 'resources.jsonl'):
            for dataset in ('small', 'large'):
                manifests[dataset] = runner.prepare_template(ids, dataset, meta)
                first_checksum = manifests[dataset]['logical_sha256']
                runner.postgres(ids, 'DROP DATABASE ' + runner.SEED_DATABASES[dataset] + ' WITH (FORCE)')
                manifests[dataset] = runner.prepare_template(ids, dataset, meta)
                if manifests[dataset]['logical_sha256'] != first_checksum:
                    raise RuntimeError('seed_replay_changed_logical_history')
                state.setdefault('seed_replays', []).append({
                    'dataset': dataset, 'independent_seeds': 2, 'logical_sha256': first_checksum})
                runner.clone_database(ids, dataset)
                runner.command(['docker', 'exec', ids['redis'], 'redis-cli', 'FLUSHALL'])
                admitted = runner.probe(ids['app'], {'mode': 'admit_seed', 'dataset': dataset,
                                                   'logical_sha256': manifests[dataset]['logical_sha256']})
                if admitted.get('admitted') is not True:
                    raise RuntimeError('pristine_clone_not_admitted')
                state['datasets'].append({'dataset': dataset, 'manifest': admitted['manifest']})
                save()
                print(json.dumps({'event': 'seed_admitted', 'dataset': dataset}), flush=True)
            scenarios = runner.PROTOCOL['navigation'] + runner.PROTOCOL['other_scenarios']
            cases = []
            for index, scenario in enumerate(scenarios):
                cases.append(dict(dataset='large' if index % 2 else 'small', scenario=scenario,
                                  users=(1, 5, 10)[index % 3], traffic='distinct',
                                  profile='current' if index % 2 else 'normalized',
                                  temperature='cold' if index % 2 else 'warm',
                                  background='representative' if index % 2 else 'idle'))
            cases += [dict(dataset='small', scenario=scenario, users=10, traffic='same_chat',
                           profile='current', temperature='warm', background='representative')
                      for scenario in ('checkout', 'paid_delivery')]
            for index, dimensions in enumerate(cases, 1):
                dataset = dimensions['dataset']
                runner.clone_database(ids, dataset)
                runner.command(['docker', 'exec', ids['redis'], 'redis-cli', 'FLUSHALL'])
                runner.probe(ids['app'], {'mode': 'admit_seed', 'dataset': dataset,
                                         'logical_sha256': manifests[dataset]['logical_sha256']})
                active = dict(mode='batch', batch=1, samples=max(dimensions['users'], 2), warmup=False,
                              evidence_path='/var/www/html/storage/app/private/p04-evidence/smoke-' + uuid.uuid4().hex + '.jsonl',
                              **dimensions)
                safe_to_reset = False
                result = runner.probe(ids['app'], active)
                retained = {}
                if runner.retain_samples(active, result['samples'], retained) or len(retained) != active['samples']:
                    raise RuntimeError('invalid_smoke_sample_contract')
                runner.atomic_json(output / f'{index:02d}-result.json', result)
                safe_to_reset = True
                rows = result['samples']
                successes = sum(row['outcome'] == 'success' for row in rows)
                if dimensions['traffic'] == 'distinct' and successes != len(rows):
                    raise RuntimeError('distinct_path_did_not_complete')
                if dimensions['traffic'] == 'same_chat' and successes != 1:
                    raise RuntimeError('coalesced_checkout_borrowed_ack')
                if dimensions['scenario'] == 'paid_delivery' and sum(row['timestamps']['provider_paid'] is not None for row in rows) != successes:
                    raise RuntimeError('coalesced_paid_observation_not_unique')
                if dimensions['background'] == 'representative' and result['accounting']['background_jobs_submitted'] != result['accounting']['background_jobs_expected']:
                    raise RuntimeError('background_accounting_mismatch')
                dirty = {'mode': 'admit_seed', 'dataset': dataset, 'logical_sha256': manifests[dataset]['logical_sha256']}
                try:
                    runner.probe(ids['app'], dirty)
                except RuntimeError:
                    pass
                else:
                    raise RuntimeError('mutated_clone_was_admitted')
                check = {'case': dimensions, 'attempts': len(rows), 'successes': successes,
                         'outcomes': {s: sum(r['outcome'] == s for r in rows) for s in ('success', 'error', 'timeout', 'unknown')},
                         'mutated_clone_rejected': True, 'harness_phase_ms': result['accounting']['harness_phase_ms']}
                state['checks'].append(check)
                save()
                print(json.dumps({'event': 'smoke_case_completed', **check}), flush=True)
            state['status'] = 'passed'
    except BaseException:
        state['status'] = 'failed'
        if active is not None and not safe_to_reset:
            rows, recovery = runner.recover_batch(ids['app'], active, None, 'probe_error', output)
            runner.atomic_json(output / 'failed-attempts.json', rows)
            state['recovery'] = recovery
            safe_to_reset = recovery['evidence_copied'] and recovery['probe_cohort_stopped']
        raise
    finally:
        state['finished_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
        if safe_to_reset:
            runner.cli('reset', 'laravel')
            state['disposable_cleanup'] = 'reset'
        else:
            state['disposable_cleanup'] = 'evidence_recovery_required'
        save()
    print(json.dumps({'event': 'smoke_completed', 'status': state['status'], 'cases': len(state['checks'])}), flush=True)


if __name__ == '__main__':
    main()
