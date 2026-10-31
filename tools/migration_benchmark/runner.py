#!/usr/bin/env python3
"""P04 local synthetic baseline. No live targets or production configuration accepted."""
import argparse
import hashlib
import io
import itertools
import json
import os
from pathlib import Path
import platform
import resource
import subprocess
import sys
import tarfile
import threading
import tempfile
import uuid
import time

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools/migration_harness'))
import harness
from smoke import cli, container
from report import summarize

TOOLS = Path(__file__).resolve().parent
PROTOCOL = json.loads((TOOLS / 'protocol.json').read_text())


def command(args, *, payload=None, timeout=600):
    result = subprocess.run(args, input=None if payload is None else json.dumps(payload),
                            capture_output=True, text=True, timeout=timeout,
                            env={'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'HOME': os.environ['HOME']})
    if result.returncode:
        # Child errors can contain row values, HTTP bodies or configuration. Never publish them.
        raise RuntimeError('subprocess_failed_' + Path(args[0]).name)
    return result.stdout.strip()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def snapshot(ids, session=None):
    result = {'time_ns': time.monotonic_ns(), 'session': session, 'services': {}, 'unknowns': []}
    files = ('memory.current', 'memory.peak', 'memory.max', 'memory.swap.current',
             'memory.swap.max', 'memory.events', 'cpu.stat', 'cpu.max', 'pids.current')
    for service, cid in ids.items():
        try:
            script = '; '.join(f'echo {name}; cat /sys/fs/cgroup/{name}' for name in files)
            lines = command(['docker', 'exec', cid, 'sh', '-c', script], timeout=15).splitlines()
            values = {}
            current = None
            for line in lines:
                if line in files:
                    current = line
                    values[current] = []
                else:
                    values[current].append(line)
            state = json.loads(command(['docker', 'inspect', '--format',
                '{"oom_killed":{{.State.OOMKilled}},"restarts":{{.RestartCount}},"running":{{.State.Running}}}', cid]))
            result['services'][service] = {'cgroup': values, **state}
        except (RuntimeError, subprocess.TimeoutExpired, ValueError, KeyError):
            result['unknowns'].append(service + '_sensor_failed')
    try:
        query = "SELECT json_build_object('connections',count(*),'active',count(*) FILTER (WHERE state='active'),'waiting',count(*) FILTER (WHERE wait_event_type='Lock')) FROM pg_stat_activity WHERE datname='disposable'"
        result['postgres'] = json.loads(command(['docker', 'exec', ids['database'], 'psql', '-U', 'harness', '-d', 'postgres', '-Atc', query], timeout=15))
    except (RuntimeError, subprocess.TimeoutExpired, ValueError):
        result['unknowns'].append('postgres_sensor_failed')
    try:
        # Laravel's :notify lists contain wakeup tokens, not runnable jobs.
        script = "local c='0'; local n=0; local r=0; local d=0; local s=0; local w=0; repeat local x=redis.call('SCAN',c,'MATCH','*queues:*','COUNT',100); c=x[1]; for _,k in ipairs(x[2]) do local t=redis.call('TYPE',k).ok; if t=='list' then local v=redis.call('LLEN',k); if string.sub(k,-7)==':notify' then w=w+v else r=r+v end elseif t=='zset' then local v=redis.call('ZCARD',k); if string.sub(k,-9)==':reserved' then s=s+v elseif string.sub(k,-8)==':delayed' then d=d+v end end; n=n+1 end until c=='0' or n>=10000; return {r,s,d,w,c=='0' and 1 or 0}"
        values = json.loads(command(['docker', 'exec', ids['redis'], 'redis-cli', '--json', 'EVAL', script, '0'], timeout=15))
        result['redis'] = dict(zip(('ready', 'reserved', 'delayed', 'wakeup_tokens', 'scan_complete'), values))
    except (RuntimeError, subprocess.TimeoutExpired, ValueError):
        result['unknowns'].append('redis_sensor_failed')
    result['host'] = {'load_average': list(os.getloadavg()),
                      'memory': {line.split(':')[0]: line.split(':')[1].strip()
                                 for line in Path('/proc/meminfo').read_text().splitlines()
                                 if line.split(':')[0] in ('MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree')}}
    return result


class Sensors:
    def __init__(self, ids, path, session=None):
        self.ids, self.path, self.session = ids, path, session
        self.stop_event = threading.Event()
        self.thread = threading.Thread(target=self.run, daemon=True)

    def run(self):
        with self.path.open('a') as output:
            while not self.stop_event.is_set():
                output.write(json.dumps(snapshot(self.ids, self.session), separators=(',', ':')) + '\n')
                output.flush()
                self.stop_event.wait(PROTOCOL['resource_sample_interval_seconds'])

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *_):
        self.stop_event.set()
        self.thread.join(timeout=120)
        if self.thread.is_alive():
            raise RuntimeError('resource_sensor_did_not_stop')


def verify_limits(observed):
    expected = {'boundary': (64, .1), 'database': (640, .5), 'redis': (128, .15), 'app': (1216, 1.25)}
    for service, (mib, cpus) in expected.items():
        record = observed['services'].get(service)
        if record is None:
            raise RuntimeError('missing_cgroup_sensor')
        group = record['cgroup']
        quota, period = map(int, group['cpu.max'][0].split())
        if (int(group['memory.max'][0]) != mib * 1024 ** 2
                or int(group['memory.swap.max'][0]) != 0 or quota / period != cpus):
            raise RuntimeError('unequal_resource_envelope')


def probe(app, payload):
    return json.loads(command(['docker', 'exec', '-i', app, 'php', '/tmp/p04/probe.php'], payload=payload, timeout=1800))


def install_tools(app):
    # docker cp can write beneath a tmpfs mount instead of its live filesystem.
    command(['docker', 'exec', app, 'mkdir', '-p', '/tmp/p04'])
    archive = io.BytesIO()
    with tarfile.open(fileobj=archive, mode='w') as package:
        for path in sorted(TOOLS.iterdir()):
            if path.is_file() and path.suffix in ('.py', '.php', '.json') and not path.is_symlink():
                package.add(path, arcname=path.name)
    result = subprocess.run(['docker', 'exec', '-i', app, 'tar', '-xf', '-', '-C', '/tmp/p04'],
                            input=archive.getvalue(), capture_output=True, timeout=30)
    if result.returncode:
        raise RuntimeError('probe_installation_failed')


def sources():
    paths = [*TOOLS.glob('*.py'), *TOOLS.glob('*.php'), *TOOLS.glob('*.json'),
             *(ROOT / 'tools/migration_harness').glob('*.py'),
             *(ROOT / 'tools/migration_harness').glob('*.sh'), ROOT / 'supervisord.conf']
    tracked = command(['git', '-C', str(ROOT), 'ls-files', '-z', 'src']).split('\0')
    paths.extend(ROOT / name for name in tracked if name and not Path(name).name.startswith('.env'))
    paths.extend((ROOT / 'src/public/build').rglob('*'))
    return {str(path.relative_to(ROOT)): digest(path) for path in sorted(set(paths))
            if path.is_file() and not path.is_symlink()}


def measurement_role(dimensions, smoke=False):
    primary = (not smoke
               and dimensions.get('implementation', 'laravel') == 'laravel'
               and dimensions.get('transport', 'synthetic') == 'synthetic'
               and dimensions['profile'] in PROTOCOL['profiles']
               and dimensions['scenario'] in PROTOCOL['navigation']
               and dimensions['users'] in PROTOCOL['users']
               and all(dimensions[key] == value for key, value in PROTOCOL['primary_conditions'].items()))
    return 'primary' if primary else 'diagnostic'


def planned_cells(smoke=False):
    scenarios = PROTOCOL['navigation'] + PROTOCOL['other_scenarios']
    if smoke:
        for profile, scenario in itertools.product(PROTOCOL['profiles'], scenarios):
            yield dict(dataset='small', profile=profile, scenario=scenario, users=1,
                       traffic='distinct', temperature='warm', background='idle')
        return
    for profile, scenario, users in itertools.product(
            PROTOCOL['profiles'], PROTOCOL['navigation'], PROTOCOL['users']):
        yield dict(profile=profile, scenario=scenario, users=users, **PROTOCOL['primary_conditions'])


CELL_FIELDS = ('dataset', 'profile', 'scenario', 'users', 'traffic', 'temperature',
               'background', 'batch', 'warmup')
SAMPLE_FIELDS = ('schema_version', 'implementation', 'transport', *CELL_FIELDS,
                 'attempt', 'outcome', 'retries', 'timestamps', 'db_ms', 'error_code')


def failure_samples(payload, code, completed=()):
    dimensions = {k: payload[k] for k in CELL_FIELDS}
    seen = {record['attempt'] for record in completed}
    return [dict(schema_version=1, implementation='laravel', transport='synthetic', **dimensions,
                 attempt=i + 1, outcome='unknown', retries=0, timestamps={}, db_ms=None, error_code=code)
            for i in range(payload['samples']) if i + 1 not in seen]


def retain_samples(payload, candidates, retained):
    """Accept each completed identity independently; never copy arbitrary probe fields."""
    rejected = 0
    for candidate in candidates:
        try:
            record = {key: candidate[key] for key in SAMPLE_FIELDS}
            summarize([record])
            if (record['implementation'] != 'laravel' or record['transport'] != 'synthetic'
                    or any(record[key] != payload[key] for key in CELL_FIELDS)
                    or not 1 <= record['attempt'] <= payload['samples']):
                raise ValueError('probe_cell_identity_mismatch')
            previous = retained.get(record['attempt'])
            if previous is not None and previous != record:
                raise ValueError('conflicting_attempt_evidence')
            retained.setdefault(record['attempt'], record)
        except (ValueError, KeyError, TypeError):
            rejected += 1
    return rejected


def contain_probe(app, evidence_path):
    # The probe creates its own session before booting or forking. Killing the
    # docker CLI alone does not terminate the PHP cohort inside the container.
    script = (
        '$p=$argv[1].".pid";'
        'if(!is_file($p)){echo "unaccounted";exit;}'
        '$s=trim(file_get_contents($p));'
        'if(!ctype_digit($s)||(int)$s<2){echo "unaccounted";exit;}'
        '$pid=(int)$s;$g=posix_getpgid($pid);'
        'if($g!==false&&$g!==$pid){echo "unaccounted";exit;}'
        'if(!posix_kill(-$pid,0)){echo "stopped";exit;}'
        'if(!posix_kill(-$pid,SIGKILL)){echo "unaccounted";exit;}'
        '$active=function()use($pid){'
        '$files=glob("/proc/[0-9]*/stat");if($files===false){return true;}'
        'foreach($files as $f){$s=@file_get_contents($f);'
        'if($s===false){if(file_exists($f)){return true;}continue;}'
        '$a=explode(" ",substr($s,strrpos($s,")")+2));'
        'if((int)($a[2]??0)===$pid&&!in_array($a[0],["Z","X"],true)){return true;}}'
        'return false;};'
        'for($i=0;$i<100&&$active();$i++){usleep(10000);}'
        'echo $active()?"unaccounted":"stopped";'
    )
    try:
        return command(['docker', 'exec', app, 'php', '-r', script, evidence_path], timeout=15) == 'stopped'
    except (RuntimeError, OSError, subprocess.TimeoutExpired):
        return False


def recover_batch(app, payload, result, code, output=None):
    retained = {}
    stopped = contain_probe(app, payload['evidence_path'])
    copied = False
    rejected = 0
    try:
        evidence = command(['docker', 'exec', app, 'cat', payload['evidence_path']], timeout=30)
        # An interrupted final write must not discard earlier complete records.
        for line in evidence.splitlines():
            try:
                candidate = json.loads(line)
            except (ValueError, TypeError):
                rejected += 1
                continue
            rejected += retain_samples(payload, [candidate], retained)
        if output is not None:
            with tempfile.TemporaryDirectory(prefix='p04-spool-') as temporary:
                target = Path(temporary) / 'spool.jsonl'
                command(['docker', 'cp', app + ':' + payload['evidence_path'], str(target)], timeout=30)
                recovered = output / 'recovered-spools'
                recovered.mkdir(exist_ok=True)
                atomic_text(recovered / (point_name(payload) + '-' + uuid.uuid4().hex + '.jsonl'),
                            target.read_text())
        copied = True
    except (RuntimeError, OSError, subprocess.TimeoutExpired):
        pass
    if isinstance(result, dict) and isinstance(result.get('samples'), list):
        rejected += retain_samples(payload, result['samples'], retained)
    completed = [retained[key] for key in sorted(retained)]
    records = completed + failure_samples(payload, code, completed)
    records.sort(key=lambda record: record['attempt'])
    return records, {'outcome': 'unknown', 'error_code': code,
                     'completed_attempts_retained': len(completed),
                     'rejected_evidence_records': rejected, 'evidence_copied': copied,
                     'probe_cohort_stopped': stopped, 'disposable_reset_required': True}


def write_report(output, raw_path, meta, smoke):
    records = [json.loads(line) for line in raw_path.read_text().splitlines()]
    report = summarize(records)
    report['run_status'] = meta['status']
    report['smoke'] = smoke
    report['protocol_schema_version'] = PROTOCOL['schema_version']
    report['old_full_matrix_acceptance'] = 'not_claimed'
    for cell in report['cells']:
        role = measurement_role(cell['dimensions'], smoke)
        cell['measurement_role'] = role
        completed = {batch['cell']['batch'] for batch in meta.get('batches', [])
                     if batch.get('completed') and not batch['cell']['warmup']
                     and cell_key(batch['cell']) == cell_key(cell['dimensions'])
                     and cell['dimensions']['implementation'] == 'laravel'
                     and cell['dimensions']['transport'] == 'synthetic'}
        cell['completed_batch_count'] = len(completed)
        cell['sampling'] = {
            'planned_batches': 1 if smoke else PROTOCOL['measured_batches'],
            'attempts_per_batch': 2 if smoke else PROTOCOL['primary_samples_per_batch'],
            'completed': len(completed) >= (1 if smoke else PROTOCOL['measured_batches']),
        }
        cell['stability'] = {
            'batch_p95_spread_fraction': cell['gate']['batch_p95_spread_fraction'],
            'unstable': 'unstable_batch_p95' in cell['gate']['reasons'],
        }
        if role == 'diagnostic':
            cell['gate'].update(status='not_applicable', reasons=['diagnostic_only'])
        elif not cell['sampling']['completed']:
            cell['gate']['status'] = 'inconclusive'
            cell['gate']['reasons'].append('incomplete_independent_batches')
    expected = {role: {cell_key(cell) for cell in planned_cells(smoke)
                       if measurement_role(cell, smoke) == role}
                for role in ('primary', 'diagnostic')}
    observed = {role: {cell_key(cell['dimensions']) for cell in report['cells']
                       if cell['measurement_role'] == role
                       and cell['dimensions']['implementation'] == 'laravel'
                       and cell['dimensions']['transport'] == 'synthetic'}
                for role in expected}
    report['coverage'] = {'expected_cells': sum(map(len, expected.values())),
                          'observed_cells': sum(len(expected[role] & observed[role]) for role in expected)}
    for role in expected:
        report['coverage'].update({
            f'expected_{role}_cells': len(expected[role]),
            f'observed_{role}_cells': len(expected[role] & observed[role]),
            f'missing_{role}_cells': [list(key) for key in sorted(expected[role] - observed[role])],
        })
    primary = [cell for cell in report['cells'] if cell['measurement_role'] == 'primary']
    report['coverage'].update({
        'completed_primary_cells': sum(cell['sampling']['completed'] for cell in primary),
        'exploratory_primary_cells': sum(cell['gate']['status'] == 'exploratory' for cell in primary),
        'inconclusive_primary_cells': sum(cell['gate']['status'] == 'inconclusive' for cell in primary),
    })
    atomic_json(output / 'report.json', report)
    artifacts = {str(p.relative_to(output)): digest(p) for p in output.rglob('*')
                 if p.is_file() and p.name != 'checksums.json'}
    atomic_json(output / 'checksums.json', artifacts)
    return len(records)


def atomic_text(path, text):
    temporary = path.with_name(path.name + '.' + uuid.uuid4().hex + '.tmp')
    with temporary.open('x') as stream:
        stream.write(text)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temporary, path)
    descriptor = os.open(path.parent, os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def atomic_json(path, value):
    atomic_text(path, json.dumps(value, indent=2) + '\n')


def fingerprint(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def cell_key(payload):
    return tuple(payload[key] for key in CELL_FIELDS if key not in ('batch', 'warmup'))


def point_name(payload):
    return '-'.join(str(payload[key]).lower() for key in CELL_FIELDS) + '.json'


def checkpoint(output, point, source_hash):
    envelope = {'protocol_sha256': fingerprint(PROTOCOL), 'source_sha256': source_hash, 'point': point}
    envelope['sha256'] = fingerprint(envelope)
    path = output / 'checkpoints' / point_name(point['cell'])
    if path.exists():
        if json.loads(path.read_text()) != envelope:
            raise RuntimeError('checkpoint_identity_conflict')
        return
    atomic_json(path, envelope)


def load_checkpoints(output, source_hash):
    points = []
    identities = set()
    for path in sorted((output / 'checkpoints').glob('*.json')):
        envelope = json.loads(path.read_text())
        checksum = envelope.pop('sha256')
        if checksum != fingerprint(envelope):
            raise RuntimeError('checkpoint_checksum_mismatch')
        if envelope['source_sha256'] != source_hash or envelope['protocol_sha256'] != fingerprint(PROTOCOL):
            raise RuntimeError('checkpoint_source_or_protocol_mismatch')
        point = envelope['point']
        payload = point['cell']
        identity = point_name(payload)
        retained = {}
        if (identity != path.name or identity in identities
                or type(point['completed']) is not bool
                or len(point['samples']) != payload['samples']
                or retain_samples(payload, point['samples'], retained)
                or len(retained) != payload['samples']):
            raise RuntimeError('checkpoint_sample_contract_failure')
        identities.add(identity)
        points.append(point)
    return points


def validate_resume(meta, smoke, current_sources):
    if (meta['schema_version'] != 2 or meta['protocol'] != PROTOCOL
            or meta['smoke'] != smoke or meta['source_sha256'] != current_sources):
        raise RuntimeError('resume_source_or_protocol_mismatch')


def batch_summary(point):
    return {'cell': point['cell'], 'completed': point['completed'], 'attempts': len(point['samples']),
            'outcomes': {state: sum(row['outcome'] == state for row in point['samples'])
                         for state in ('success', 'error', 'timeout', 'unknown')}}


def publish_point(output, point):
    for name, rows in (('samples.jsonl', point['samples']),
                       ('accounting.jsonl', [{key: value for key, value in point.items() if key != 'samples'}])):
        with (output / name).open('a') as stream:
            for row in rows:
                stream.write(json.dumps(row, separators=(',', ':')) + '\n')
            stream.flush()
            os.fsync(stream.fileno())


def rebuild_journals(output, points):
    # A checkpoint commits before its aggregate append. Rebuild once on resume,
    # making a crash between those writes harmless without duplicate attempts.
    for name, rows in (
            ('samples.jsonl', (row for point in points for row in point['samples'])),
            ('accounting.jsonl', ({k: v for k, v in point.items() if k != 'samples'} for point in points))):
        atomic_text(output / name, ''.join(json.dumps(row, separators=(',', ':')) + '\n' for row in rows))


def next_batch(points, dimensions, smoke):
    selected = [point for point in points if cell_key(point['cell']) == cell_key(dimensions)]
    measured = [point for point in selected if not point['cell']['warmup'] and point['completed']]
    minimum = 1 if smoke else PROTOCOL['measured_batches']
    if len(measured) >= minimum:
        return None
    return max((point['cell']['batch'] for point in selected), default=0) + 1


def samples_per_batch(points, dimensions, smoke):
    if smoke:
        return max(dimensions['users'], 2)
    return PROTOCOL['primary_samples_per_batch']


def postgres(ids, sql):
    return command(['docker', 'exec', ids['database'], 'psql', '-U', 'harness',
                    '-d', 'postgres', '-v', 'ON_ERROR_STOP=1', '-Atc', sql])


SEED_DATABASES = {'small': 'p04_seed_small', 'large': 'p04_seed_large'}


def clone_database(ids, dataset, create_template=False):
    # Neither identifier comes from a CLI value or arbitrary SQL input.
    template = SEED_DATABASES[dataset]
    postgres(ids, "ALTER DATABASE disposable WITH ALLOW_CONNECTIONS false")
    postgres(ids, "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='disposable'")
    if create_template:
        postgres(ids, f"CREATE DATABASE {template} WITH TEMPLATE disposable")
    else:
        postgres(ids, "DROP DATABASE disposable WITH (FORCE)")
        postgres(ids, f"CREATE DATABASE disposable WITH TEMPLATE {template}")
    postgres(ids, "ALTER DATABASE disposable WITH ALLOW_CONNECTIONS true")


def prepare_template(ids, dataset, meta):
    command(['docker', 'exec', ids['app'], 'php', 'artisan', 'migrate:fresh', '--force', '--no-interaction'])
    command(['docker', 'exec', ids['redis'], 'redis-cli', 'FLUSHALL'])
    seeded = probe(ids['app'], {'mode': 'seed', 'dataset': dataset})
    manifest = seeded['manifest']
    for key, expected in meta['dataset_contract']['profiles'][dataset].items():
        if manifest['cardinalities']['orders' if key == 'historical_orders' else key] != expected:
            raise RuntimeError('dataset_cardinality_drift')
    if manifest['seed'] != meta['dataset_contract']['seed']:
        raise RuntimeError('dataset_seed_drift')
    previous = meta.setdefault('dataset_logical_sha256', {}).get(dataset)
    if previous is not None and previous != manifest['logical_sha256']:
        raise RuntimeError('nondeterministic_dataset')
    meta['dataset_logical_sha256'][dataset] = manifest['logical_sha256']
    meta['datasets'].append({'dataset': dataset, **seeded})
    clone_database(ids, dataset, create_template=True)
    return manifest


class Progress:
    def __init__(self, output):
        self.output = output
        self.stop = threading.Event()
        self.thread = threading.Thread(target=self.run, daemon=True)

    def run(self):
        from progress import progress
        while not self.stop.wait(30):
            try:
                record = progress(self.output)
            except (OSError, ValueError, KeyError, subprocess.SubprocessError):
                record = {'event': 'baseline_progress', 'status': 'observation_unavailable'}
            print(json.dumps(record, separators=(',', ':')), flush=True)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *_):
        self.stop.set()
        self.thread.join(timeout=30)


def recover_pending(output, meta, points, source_hash):
    active = meta.get('active')
    if active is None:
        return
    payload = active['cell']
    existing = next((point for point in points if point_name(point['cell']) == point_name(payload)), None)
    if existing is not None and (existing['completed'] or
                                (existing['accounting'].get('evidence_copied')
                                 and existing['accounting'].get('probe_cohort_stopped'))):
        meta['active'] = None
        return
    # docker cp reads stopped containers as well. Never reset their volume
    # before copying the durable per-attempt spool to a host checkpoint.
    app = active['app']
    running = command(['docker', 'inspect', '--format', '{{.State.Running}}', app]) == 'true'
    if running:
        records, detail = recover_batch(app, payload, None, 'interrupted', output)
    else:
        retained = {}
        rejected = 0
        with tempfile.TemporaryDirectory(prefix='p04-recovery-') as temporary:
            target = Path(temporary) / 'spool.jsonl'
            command(['docker', 'cp', app + ':' + payload['evidence_path'], str(target)], timeout=30)
            # Preserve all bytes, including a torn last record, on the host.
            recovered = output / 'recovered-spools'
            recovered.mkdir(exist_ok=True)
            atomic_text(recovered / (point_name(payload) + '.jsonl'), target.read_text())
            for line in target.read_text().splitlines():
                try:
                    rejected += retain_samples(payload, [json.loads(line)], retained)
                except (ValueError, TypeError):
                    rejected += 1
        completed = list(retained.values())
        records = sorted(completed + failure_samples(payload, 'interrupted', completed), key=lambda row: row['attempt'])
        detail = {'evidence_copied': True, 'probe_cohort_stopped': True,
                  'completed_attempts_retained': len(completed), 'rejected_evidence_records': rejected}
    point = {'cell': payload, 'session': active.get('session'), 'started_ns': active['started_ns'],
             'finished_ns': None, 'recovered_at': {'session': meta.get('sessions', [None])[-1],
                                                 'time_ns': time.monotonic_ns()},
             'completed': False, 'samples': records, 'accounting': detail}
    if existing is None:
        checkpoint(output, point, source_hash)
        points.append(point)
    meta['runner_failures'].append({'cell': payload, 'code': 'interrupted', 'recovery': detail})
    if not detail['evidence_copied'] or not detail['probe_cohort_stopped']:
        raise RuntimeError('resume_evidence_recovery_required')
    meta['active'] = None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--smoke', action='store_true', help='short diagnostic run (default)')
    mode.add_argument('--benchmark', action='store_true', help='explicit comparison workload for P11')
    parser.add_argument('--output', type=Path, required=True, help='artifact directory below repository .local')
    parser.add_argument('--resume', action='store_true', help='resume this exact version/source output')
    args = parser.parse_args()
    args.smoke = not args.benchmark
    os.umask(0o077)
    output = args.output.resolve()
    if not output.is_relative_to(ROOT / '.local') or (output.exists() != args.resume):
        raise RuntimeError('output_requires_new_local_directory_or_resume')
    current_sources = sources()
    source_hash = fingerprint(current_sources)
    if args.resume:
        meta = json.loads((output / 'run.json').read_text())
        validate_resume(meta, args.smoke, current_sources)
        points = load_checkpoints(output, source_hash)
        for point in points:
            if not point['completed'] and not any(
                    failure.get('cell') and point_name(failure['cell']) == point_name(point['cell'])
                    for failure in meta['runner_failures']):
                meta['runner_failures'].append({'cell': point['cell'], 'code': 'interrupted_checkpoint',
                                                'recovery': point['accounting']})
    else:
        output.mkdir(parents=True)
        (output / 'checkpoints').mkdir()
        points = []
        meta = {'schema_version': 2, 'run_id': uuid.uuid4().hex, 'status': 'running', 'smoke': args.smoke,
                'protocol': PROTOCOL, 'source_sha256': current_sources,
                'started_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                'revision': command(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']),
                'host': {'kernel': platform.release(), 'architecture': platform.machine(), 'cpu_count': os.cpu_count()},
                'clock': {'implementation': time.get_clock_info('monotonic').implementation,
                          'resolution_seconds': time.get_clock_info('monotonic').resolution},
                'dataset_contract': json.loads((TOOLS / 'datasets.json').read_text()),
                'commands': [], 'limits': PROTOCOL['excluded_claims'], 'datasets': [], 'batches': [],
                'runner_failures': [], 'run_history': [], 'active': None}
    def save():
        atomic_json(output / 'run.json', meta)
    session = {'session_uuid': uuid.uuid4().hex,
               'host_boot_id': Path('/proc/sys/kernel/random/boot_id').read_text().strip(),
               'started_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}
    meta.setdefault('sessions', []).append(session)
    harness.docker_admission()
    config = harness.validate(json.loads((harness.STATE / 'laravel/config.json').read_text()))
    if config != harness.default('laravel'):
        raise RuntimeError('only_default_synthetic_configuration_admitted')
    if args.resume:
        recover_pending(output, meta, points, source_hash)
        meta['run_history'].append({'status': 'interrupted' if meta['status'] == 'running' else meta['status'],
                                    'finished_utc': meta.get('finished_utc'),
                                    'failures': list(meta['runner_failures'])})
    elif command(['docker', 'ps', '-q', '--filter', 'label=lhie.p01=disposable']):
        raise RuntimeError('stop_existing_disposable_stack_first')
    rebuild_journals(output, points)
    meta['batches'] = [batch_summary(point) for point in points]
    meta['status'] = 'running'
    meta['commands'].append('python3 tools/migration_benchmark/runner.py '
                            + ('--smoke ' if args.smoke else '--benchmark ') + ('--resume ' if args.resume else '')
                            + '--output ' + str(output.relative_to(ROOT)))
    save()
    print(json.dumps({'event': 'baseline_started', 'output': str(output.relative_to(ROOT)),
                      'status': meta['status'], 'protocol_version': PROTOCOL['schema_version']}), flush=True)
    safe_to_reset = True
    try:
        cli('reset', 'laravel')
        manifest_path = Path(cli('start', 'laravel'))
        meta['harness'] = json.loads(manifest_path.read_text())
        meta['assets_sha256'] = {str(p.relative_to(ROOT / 'src/public')): digest(p)
                                for p in sorted((ROOT / 'src/public/build').rglob('*')) if p.is_file()}
        ids = {service: container('laravel', service) for service in ('boundary', 'database', 'redis', 'app')}
        app = ids['app']
        image_id = command(['docker', 'inspect', '--format', '{{.Image}}', app])
        labels = json.loads(command(['docker', 'image', 'inspect', '--format', '{{json .Config.Labels}}', image_id])) or {}
        meta['asset_build_identity'] = {key: value for key, value in labels.items() if key.startswith('lhie.p04.')}
        if labels.get('lhie.p04.assets-tree-sha256') != harness.tree_digest(ROOT / 'src/public/build'):
            raise RuntimeError('comparator_assets_do_not_match_frozen_inputs')
        observed = snapshot(ids, session)
        verify_limits(observed)
        atomic_json(output / 'admission-resources.json', observed)
        install_tools(app)
        settings = "SELECT json_object_agg(name,setting) FROM pg_settings WHERE name IN ('max_connections','shared_buffers','synchronous_commit','fsync','full_page_writes','TimeZone','max_worker_processes','checkpoint_timeout')"
        meta.setdefault('postgres_settings', []).append(json.loads(postgres(ids, settings)))
        with Progress(output), Sensors(ids, output / 'resources.jsonl', session):
            cells = list(planned_cells(args.smoke))
            manifests = {}
            for role in ('primary', 'diagnostic'):
                for dimensions in (cell for cell in cells if measurement_role(cell, args.smoke) == role):
                    dataset = dimensions['dataset']
                    while (batch := next_batch(points, dimensions, args.smoke)) is not None:
                        if dataset not in manifests:
                            manifest = prepare_template(ids, dataset, meta)
                            meta.setdefault('schema_sha256', []).append(hashlib.sha256(command([
                                'docker', 'exec', ids['database'], 'pg_dump', '-U', 'harness', '-d',
                                'disposable', '--schema-only', '--no-comments']).encode()).hexdigest())
                            manifests[dataset] = manifest
                            save()
                        manifest = manifests[dataset]
                        clone_database(ids, dataset)
                        command(['docker', 'exec', ids['redis'], 'redis-cli', 'FLUSHALL'])
                        admitted = probe(app, {'mode': 'admit_seed', 'dataset': dataset,
                                               'logical_sha256': manifest['logical_sha256']})
                        if not admitted.get('admitted') or admitted['manifest'] != manifest:
                            raise RuntimeError('cloned_dataset_admission_failed')
                        count = samples_per_batch(points, dimensions, args.smoke)
                        phases = [(False, count)]
                        if measurement_role(dimensions, args.smoke) == 'primary':
                            phases.insert(0, (True, PROTOCOL['warmup_samples_per_batch']))
                        for warmup, samples in phases:
                            payload = dict(mode='batch', batch=batch, samples=samples, warmup=warmup, **dimensions)
                            payload['evidence_path'] = ('/var/www/html/storage/app/private/p04-evidence/'
                                                        + meta['run_id'] + '-' + point_name(payload) + '.jsonl')
                            before = time.monotonic_ns()
                            meta['active'] = {'cell': payload, 'app': app, 'started_ns': before, 'session': session}
                            save()
                            safe_to_reset = False
                            result = None
                            completed = True
                            try:
                                result = probe(app, payload)
                                retained = {}
                                if (not isinstance(result['samples'], list) or len(result['samples']) != samples
                                        or retain_samples(payload, result['samples'], retained)
                                        or len(retained) != samples):
                                    raise RuntimeError('probe_sample_contract_failure')
                                records = [retained[key] for key in sorted(retained)]
                                detail = result.get('accounting', {})
                            except (RuntimeError, OSError, subprocess.TimeoutExpired, ValueError,
                                    KeyError, TypeError, KeyboardInterrupt) as error:
                                completed = False
                                code = 'timeout' if isinstance(error, subprocess.TimeoutExpired) else 'probe_error'
                                records, detail = recover_batch(app, payload, result, code, output)
                                meta['runner_failures'].append({'cell': payload, 'code': code, 'recovery': detail})
                            point = {'cell': payload, 'session': session, 'started_ns': before,
                                     'finished_ns': time.monotonic_ns(), 'completed': completed,
                                     'samples': records, 'accounting': detail}
                            checkpoint(output, point, source_hash)
                            points.append(point)
                            publish_point(output, point)
                            safe_to_reset = completed or (detail['evidence_copied'] and detail['probe_cohort_stopped'])
                            if safe_to_reset:
                                meta['active'] = None
                            meta['batches'].append(batch_summary(point))
                            save()
                            print(json.dumps(meta['batches'][-1]), flush=True)
                            if not completed:
                                raise RuntimeError('probe_failed_run_stopped')
            meta['status'] = 'complete_with_unknowns' if meta['runner_failures'] else 'complete'
    except (Exception, KeyboardInterrupt):
        meta['status'] = 'failed'
        meta['runner_failures'].append({'code': 'runner_error'})
    finally:
        if safe_to_reset:
            try:
                cli('reset', 'laravel')
                meta['disposable_cleanup'] = 'reset'
            except (Exception, KeyboardInterrupt):
                meta['status'] = 'failed'
                meta['runner_failures'].append({'code': 'disposable_reset_failed'})
                meta['disposable_cleanup'] = 'reset_required'
        else:
            meta['disposable_cleanup'] = 'reset_required_after_evidence_recovery'
        meta['finished_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
        meta['driver_max_rss_kib'] = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        save()
        attempts = write_report(output, output / 'samples.jsonl', meta, args.smoke)
    print(json.dumps({'output': str(output.relative_to(ROOT)), 'status': meta['status'], 'attempts': attempts}))
    return 1 if meta['status'] == 'failed' else 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (RuntimeError, ValueError, KeyError, OSError, subprocess.TimeoutExpired):
        print('P04 failed safely; inspect sanitized run status; no child payload is printed.', file=sys.stderr)
        raise SystemExit(1)
