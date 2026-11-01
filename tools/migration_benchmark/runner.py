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
import re
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
PROTOCOLS = {'p04': TOOLS / 'protocol.json', 'p11': TOOLS / 'protocol_p11.json'}
PROTOCOL = json.loads(PROTOCOLS['p04'].read_text())
IMPLEMENTATIONS = ('laravel', 'django')
CANDIDATE_IMAGE = 'lhie-p05-candidate:1'
CANDIDATE_DATASET = 'synthetic-p11-paired'
P11_STATE = ROOT / '.local/migration-p11/lifecycle'


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


def verify_limits(observed, services=('boundary', 'database', 'redis', 'app')):
    expected = {'boundary': (64, .1), 'database': (640, .5), 'redis': (128, .15), 'app': (1216, 1.25)}
    for service in services:
        mib, cpus = expected[service]
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


# --- P11 paired comparison (candidate lifecycle, PHP seed oracle, Django probe) ---


def django_lifecycle(*args, timeout=240):
    """P01 lifecycle in a separate P11 namespace; never the operator's P01 state."""
    entry = ("import sys; from pathlib import Path; import harness; "
             "harness.STATE=Path(sys.argv[1]); harness.PREFIX='lhie-p11-'; "
             "harness.IMAGE='lhie-p01-boundary:1'; "
             "sys.argv=[harness.__file__,*sys.argv[2:]]; harness.main()")
    result = subprocess.run([sys.executable, '-c', entry, str(P11_STATE), *args],
                            cwd=ROOT / 'tools/migration_harness', capture_output=True,
                            text=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError('p11_lifecycle_failed')
    return result.stdout.strip()


def candidate_directory():
    return P11_STATE / 'candidate'


def prepare_candidate_admission():
    config_path = candidate_directory() / 'config.json'
    if not config_path.exists():
        django_lifecycle('init', 'candidate')
    config = harness.validate(json.loads(config_path.read_text()))
    expected = {**harness.default('candidate'), 'dataset': CANDIDATE_DATASET,
                'safe_chat_ids': list(range(8800000, 8800010))}
    if config != expected:
        # Only a pristine synthetic default in the dedicated P11 namespace may
        # be extended; an operator-attested Testing Bot config is never rewritten.
        if config != harness.default('candidate'):
            raise RuntimeError('p11_candidate_configuration_not_pristine')
        atomic_json(config_path, expected)
        config = expected
    harness.candidate_admission(config, candidate_directory(), workers=True)


def php_storage(output):
    storage = output / 'php-storage'
    for name in ('app/private', 'app/public', 'framework/cache', 'framework/sessions',
                 'framework/views', 'logs'):
        (storage / name).mkdir(parents=True, exist_ok=True)
    os.chmod(storage, 0o700)
    return storage


def php_container(ids, storage, argv, payload=None, timeout=1800):
    """One temporary Laravel container bound to the disposable stack network."""
    directory = candidate_directory()
    command_args = [
        'docker', 'run', '--rm', '-i', '--network', 'container:' + ids['boundary'],
        '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
        '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
        '--user', '1000:1000', '--tmpfs', '/tmp:size=64m',
        '--mount', f'type=bind,src={TOOLS},dst=/tmp/p04,readonly',
        '--mount', f'type=bind,src={storage},dst=/var/www/html/storage',
        '--env', 'APP_ENV=testing', '--env', 'APP_DEBUG=false',
        '--env', 'APP_KEY=base64:' + (directory / 'app-key').read_text().strip(),
        '--env', 'APP_URL=http://127.0.0.1',
        '--env', 'DB_CONNECTION=pgsql', '--env', 'DB_HOST=127.0.0.1',
        '--env', 'DB_PORT=5432', '--env', 'DB_DATABASE=disposable',
        '--env', 'DB_USERNAME=harness', '--env', 'DB_PASSWORD=harness',
        '--env', 'REDIS_HOST=127.0.0.1', '--env', 'REDIS_PORT=6379',
        '--env', 'QUEUE_CONNECTION=redis', '--env', 'CACHE_STORE=redis',
        '--env', 'SESSION_DRIVER=redis', '--env', 'LOG_CHANNEL=null',
        '--env', 'MAIL_MAILER=array', '--env', 'TELEGRAM_BOT_TOKEN=',
        '--env', 'TELEGRAM_ADMIN_CHAT_ID=8999999',
        '--env', 'APP_AUTO_MIGRATE=false', '--env', 'APP_AUTO_SET_TELEGRAM_WEBHOOK=false',
        '--entrypoint', argv[0], 'lhie-p01-laravel:1', *argv[1:],
    ]
    return command(command_args, payload=payload, timeout=timeout)


def php_probe(ids, storage, payload, timeout=1800):
    return json.loads(php_container(ids, storage, ['php', '/tmp/p04/probe.php'],
                                    payload=payload, timeout=timeout))


def disposable_sql(ids, sql):
    return command(['docker', 'exec', ids['database'], 'psql', '-U', 'harness',
                    '-d', 'disposable', '-v', 'ON_ERROR_STOP=1', '-Atc', sql])


def provision_candidate_schema(ids):
    parts = []
    for name in ('schema.sql', 'ingress_schema.sql', 'assets_schema.sql', 'grants.sql'):
        parts.append((ROOT / 'candidate/lhie_candidate/navigation' / name).read_text())
    disposable_sql(ids, '\n'.join(parts))


AUTHORITY_EXCLUDED = {'customers'}


def authority_snapshot(ids):
    """Hash every public row except the admitted narrow Customer profile writes."""
    tables = [row for row in disposable_sql(
        ids, "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='public' ORDER BY tablename"
    ).splitlines() if row and row not in AUTHORITY_EXCLUDED]
    result = {}
    for table in tables:
        identifier = '"' + table.replace('"', '""') + '"'
        result[table] = disposable_sql(
            ids, "SELECT count(*), coalesce(md5(string_agg(t::text, '|' ORDER BY t::text)), '') "
                 f"FROM public.{identifier} AS t")
    return result


def authority_delta(before, after):
    changed = sorted(name for name in set(before) | set(after) if before.get(name) != after.get(name))
    return changed


def evidence_name(run_id, payload):
    return run_id + '-' + point_name(payload).removesuffix('.json') + '.jsonl'


def probe_name(run_id, payload):
    label = re.sub(r'[^a-z0-9]', '', point_name(payload).lower())[:48]
    return 'lhie-p11-probe-' + run_id[:12] + '-' + label


def candidate_probe(ids, payload, output, run_id, session):
    name = probe_name(run_id, payload)
    evidence = output / 'spools' / evidence_name(run_id, payload)
    evidence.parent.mkdir(exist_ok=True)
    os.chmod(evidence.parent, 0o700)
    # An empty host spool is still durable evidence that the cohort wrote
    # nothing before refusing; it keeps cleanup safe without inventing samples.
    evidence.touch(mode=0o600, exist_ok=True)
    payload = {**payload, 'evidence_path': '/evidence/' + evidence.name}
    directory = candidate_directory()
    arguments = [
        'docker', 'run', '--name', name, '-i', '--network', 'container:' + ids['boundary'],
        '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
        '--user', '1000:1000', '--cpus', '1.25', '--memory', '1216m', '--memory-swap', '1216m',
        '--pids-limit', '128', '--tmpfs', '/tmp:size=256m',
        '--mount', f'type=bind,src={ROOT / "candidate"},dst=/opt/candidate,readonly',
        '--mount', f'type=bind,src={TOOLS},dst=/opt/harness,readonly',
        '--mount', f'type=bind,src={directory / "candidate.json"},dst=/run/harness/candidate.json,readonly',
        '--mount', f'type=bind,src={directory / "django-key"},dst=/run/harness/django-key,readonly',
        '--mount', f'type=bind,src={directory / "app-key"},dst=/run/harness/app-key,readonly',
        '--mount', f'type=bind,src={evidence.parent},dst=/evidence',
        '--env', 'P11_BENCHMARK=disposable-local-only',
        '--env', 'PYTHONDONTWRITEBYTECODE=1', '--env', 'PYTHONPATH=/opt/candidate',
        '--entrypoint', 'python', CANDIDATE_IMAGE, '/opt/harness/candidate_probe.py',
    ]
    try:
        command(['docker', 'rm', '-f', name], timeout=30)
    except (RuntimeError, OSError, subprocess.TimeoutExpired):
        pass
    state = {'samples': [], 'resources': []}
    process = subprocess.Popen(arguments, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                               stderr=subprocess.PIPE, text=True)
    try:
        stop = threading.Event()
        thread = threading.Thread(target=sample_probe, args=(name, state['resources'], stop), daemon=True)
        thread.start()
        try:
            stdout, stderr = process.communicate(json.dumps(payload), timeout=1800)
        except subprocess.TimeoutExpired:
            command(['docker', 'rm', '-f', name], timeout=30)
            process.kill()
            process.communicate()
            raise RuntimeError('timeout') from None
        finally:
            stop.set()
            thread.join(timeout=20)
        try:
            state['state'] = json.loads(command([
                'docker', 'inspect', '--format',
                '{"oom_killed":{{.State.OOMKilled}},"restarts":{{.RestartCount}},"exit":{{.State.ExitCode}}}',
                name], timeout=30))
        except (RuntimeError, OSError, subprocess.TimeoutExpired, ValueError):
            state['state'] = None
        if process.returncode:
            diagnostics = output / 'probe-diagnostics'
            diagnostics.mkdir(exist_ok=True)
            os.chmod(diagnostics, 0o700)
            try:
                atomic_text(diagnostics / (name + '.stderr'), (stderr or '')[-65536:])
            except OSError:
                pass
            # The probe prints only a stable error code; never relay child text.
            codes = {'probe_error', 'timeout', 'scenario_error', 'unexpected_response',
                     'boundary_unknown'}
            diagnostic = None
            for line in (stdout or '').splitlines():
                try:
                    value = json.loads(line)
                except (ValueError, TypeError):
                    continue
                if isinstance(value, dict) and value.get('error_code') in codes:
                    diagnostic = value['error_code']
            raise RuntimeError('probe_error:' + (diagnostic or 'unknown'))
        result = json.loads(stdout)
        if not isinstance(result.get('samples'), list):
            raise RuntimeError('probe_failed')
        return result, payload, evidence, state
    finally:
        try:
            command(['docker', 'rm', '-f', name], timeout=30)
        except (RuntimeError, OSError, subprocess.TimeoutExpired):
            pass


def sample_probe(name, records, stop):
    files = ('memory.current', 'memory.peak', 'memory.max', 'memory.swap.current',
             'memory.swap.max', 'pids.current')
    while not stop.wait(3):
        try:
            script = '; '.join(f'echo {value}; cat /sys/fs/cgroup/{value}' for value in files)
            lines = command(['docker', 'exec', name, 'sh', '-c', script], timeout=10).splitlines()
            values, current = {}, None
            for line in lines:
                if line in files:
                    current = line
                    values[current] = []
                elif current is not None:
                    values[current].append(line)
            records.append({'time_ns': time.monotonic_ns(), 'cgroup': values})
        except (RuntimeError, OSError, subprocess.TimeoutExpired):
            records.append({'time_ns': time.monotonic_ns(), 'unknown': 'probe_sensor_failed'})


def recover_candidate_batch(payload, result, code, output, evidence, stopped):
    retained = {}
    rejected = 0
    try:
        for line in evidence.read_text().splitlines():
            try:
                candidate = json.loads(line)
            except (ValueError, TypeError):
                rejected += 1
                continue
            rejected += retain_samples(payload, [candidate], retained)
        evidence_copied = True
    except (OSError, ValueError):
        evidence_copied = False
    if isinstance(result, dict) and isinstance(result.get('samples'), list):
        rejected += retain_samples(payload, result['samples'], retained)
    completed = [retained[key] for key in sorted(retained)]
    records = completed + failure_samples(payload, code, completed)
    records.sort(key=lambda record: record['attempt'])
    return records, {'outcome': 'unknown', 'error_code': code,
                     'completed_attempts_retained': len(completed),
                     'rejected_evidence_records': rejected, 'evidence_copied': evidence_copied,
                     'probe_cohort_stopped': stopped, 'disposable_reset_required': True}


def sources():
    paths = [*TOOLS.glob('*.py'), *TOOLS.glob('*.php'), *TOOLS.glob('*.json'),
             *(ROOT / 'tools/migration_harness').glob('*.py'),
             *(ROOT / 'tools/migration_harness').glob('*.sh'), ROOT / 'supervisord.conf']
    tracked = command(['git', '-C', str(ROOT), 'ls-files', '-z', 'src']).split('\0')
    paths.extend(ROOT / name for name in tracked if name and not Path(name).name.startswith('.env'))
    paths.extend((ROOT / 'src/public/build').rglob('*'))
    if PROTOCOL.get('implementations') and 'django' in PROTOCOL['implementations']:
        # The candidate slice is the code under test for that implementation.
        tracked = command(['git', '-C', str(ROOT), 'ls-files', '-z', 'candidate']).split('\0')
        paths.extend(ROOT / name for name in tracked
                     if name and '/.venv/' not in name and not Path(name).name.startswith('.env'))
    return {str(path.relative_to(ROOT)): digest(path) for path in sorted(set(paths))
            if path.is_file() and not path.is_symlink()}


def implementation_scenarios(implementation):
    scenarios = PROTOCOL['navigation'] + PROTOCOL['other_scenarios']
    if implementation != 'django':
        return scenarios
    unavailable = set(PROTOCOL.get('candidate_unimplemented_scenarios', []))
    unavailable |= set(PROTOCOL.get('candidate_unimplemented_other_scenarios', []))
    return [scenario for scenario in scenarios if scenario not in unavailable]


def measurement_role(dimensions, smoke=False):
    primary = (not smoke
               and dimensions.get('implementation', 'laravel') in PROTOCOL['implementations']
               and dimensions.get('transport', 'synthetic') == 'synthetic'
               and dimensions['profile'] in PROTOCOL['profiles']
               and dimensions['scenario'] in PROTOCOL['navigation']
               and dimensions['users'] in PROTOCOL['users']
               and all(dimensions[key] == value for key, value in PROTOCOL['primary_conditions'].items()))
    return 'primary' if primary else 'diagnostic'


def planned_cells(smoke=False, implementation='laravel'):
    scenarios = implementation_scenarios(implementation)
    if smoke:
        for profile, scenario in itertools.product(PROTOCOL['profiles'], scenarios):
            yield dict(dataset='small', profile=profile, scenario=scenario, users=1,
                       traffic='distinct', temperature='warm', background='idle',
                       implementation=implementation)
        return
    for profile, scenario, users in itertools.product(
            PROTOCOL['profiles'], PROTOCOL['navigation'], PROTOCOL['users']):
        if scenario not in scenarios:
            continue
        yield dict(profile=profile, scenario=scenario, users=users,
                   implementation=implementation, **PROTOCOL['primary_conditions'])


CELL_FIELDS = ('dataset', 'profile', 'scenario', 'users', 'traffic', 'temperature',
               'background', 'batch', 'warmup')
SAMPLE_FIELDS = ('schema_version', 'implementation', 'transport', *CELL_FIELDS,
                 'attempt', 'outcome', 'retries', 'timestamps', 'db_ms', 'error_code')


def implementation_of(payload):
    return payload.get('implementation', 'laravel')


def failure_samples(payload, code, completed=()):
    dimensions = {k: payload[k] for k in CELL_FIELDS}
    seen = {record['attempt'] for record in completed}
    return [dict(schema_version=1, implementation=implementation_of(payload), transport='synthetic',
                 **dimensions, attempt=i + 1, outcome='unknown', retries=0, timestamps={},
                 db_ms=None, error_code=code)
            for i in range(payload['samples']) if i + 1 not in seen]


def retain_samples(payload, candidates, retained):
    """Accept each completed identity independently; never copy arbitrary probe fields."""
    rejected = 0
    expected = implementation_of(payload)
    for candidate in candidates:
        try:
            record = {key: candidate[key] for key in SAMPLE_FIELDS}
            summarize([record])
            if (record['implementation'] != expected or record['transport'] != 'synthetic'
                    or expected not in PROTOCOL['implementations']
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
    implementation = meta.get('implementation', 'laravel')
    report = summarize(records)
    report['run_status'] = meta['status']
    report['smoke'] = smoke
    report['implementation'] = implementation
    report['protocol_file'] = meta.get('protocol_file', 'p04')
    report['protocol_schema_version'] = PROTOCOL['schema_version']
    report['old_full_matrix_acceptance'] = 'not_claimed'
    for cell in report['cells']:
        role = measurement_role(cell['dimensions'], smoke)
        cell['measurement_role'] = role
        completed = {batch['cell']['batch'] for batch in meta.get('batches', [])
                     if batch.get('completed') and not batch['cell']['warmup']
                     and cell_key(batch['cell']) == cell_key(cell['dimensions'])
                     and cell['dimensions']['implementation'] == implementation
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
    expected = {role: {cell_key(cell) for cell in planned_cells(smoke, implementation)
                       if measurement_role(cell, smoke) == role}
                for role in ('primary', 'diagnostic')}
    observed = {role: {cell_key(cell['dimensions']) for cell in report['cells']
                       if cell['measurement_role'] == role
                       and cell['dimensions']['implementation'] == implementation
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
    if implementation == 'django':
        unavailable = set(PROTOCOL.get('candidate_unimplemented_scenarios', []))
        unavailable |= set(PROTOCOL.get('candidate_unimplemented_other_scenarios', []))
        excluded = [{'scenario': scenario, 'users': users, 'reason': 'candidate_feature_absent'}
                    for scenario in sorted(unavailable & set(PROTOCOL['navigation']))
                    for users in PROTOCOL['users']]
        excluded += [{'scenario': scenario, 'users': None, 'reason': 'candidate_feature_absent'}
                     for scenario in sorted(unavailable & set(PROTOCOL['other_scenarios']))]
        report['coverage']['excluded_cells'] = excluded
        report['coverage']['excluded_reason'] = PROTOCOL.get('candidate_unimplemented_reason')
        report['comparison'] = {'status': 'paired_comparison_required',
                                'reason': 'samples_cover_one_implementation'}
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


def validate_resume(meta, smoke, current_sources, implementation='laravel', protocol='p04'):
    if (meta['schema_version'] != 2 or meta['protocol'] != PROTOCOL
            or meta['smoke'] != smoke or meta['source_sha256'] != current_sources
            or meta.get('implementation', 'laravel') != implementation
            or meta.get('protocol_file', 'p04') != protocol):
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


def seed_template(dataset):
    templates = PROTOCOL.get('seed_templates', SEED_DATABASES)
    return templates[dataset]


def clone_database(ids, dataset, create_template=False):
    # Neither identifier comes from a CLI value or arbitrary SQL input.
    template = seed_template(dataset)
    postgres(ids, "ALTER DATABASE disposable WITH ALLOW_CONNECTIONS false")
    postgres(ids, "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='disposable'")
    if create_template:
        postgres(ids, f"CREATE DATABASE {template} WITH TEMPLATE disposable")
    else:
        postgres(ids, "DROP DATABASE disposable WITH (FORCE)")
        postgres(ids, f"CREATE DATABASE disposable WITH TEMPLATE {template}")
    postgres(ids, "ALTER DATABASE disposable WITH ALLOW_CONNECTIONS true")


def prepare_template(ids, dataset, meta, output):
    if meta.get('implementation', 'laravel') == 'django':
        storage = php_storage(output)
        php_container(ids, storage, ['php', 'artisan', 'migrate:fresh', '--force', '--no-interaction'])
        command(['docker', 'exec', ids['redis'], 'redis-cli', 'FLUSHALL'])
        seeded = php_probe(ids, storage, {'mode': 'seed', 'dataset': dataset})
        provision_candidate_schema(ids)
    else:
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


def candidate_stop(name):
    try:
        command(['docker', 'rm', '-f', name], timeout=30)
        return True
    except (RuntimeError, OSError, subprocess.TimeoutExpired):
        return False


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
    if meta.get('implementation', 'laravel') == 'django':
        stopped = candidate_stop(active.get('probe_name') or probe_name(meta['run_id'], payload))
        host_evidence = output / 'spools' / evidence_name(meta['run_id'], payload)
        records, detail = recover_candidate_batch(payload, None, 'interrupted', output,
                                                  host_evidence, stopped)
    else:
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
            records = sorted(completed + failure_samples(payload, 'interrupted', completed),
                             key=lambda row: row['attempt'])
            detail = {'evidence_copied': True, 'probe_cohort_stopped': True,
                      'completed_attempts_retained': len(completed),
                      'rejected_evidence_records': rejected}
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
    parser.add_argument('--protocol', choices=sorted(PROTOCOLS), default='p04',
                        help='p04 baseline identity (default) or p11 paired comparison')
    parser.add_argument('--implementation', choices=IMPLEMENTATIONS, default='laravel',
                        help='implementation under measurement; django requires --protocol p11')
    args = parser.parse_args()
    args.smoke = not args.benchmark
    global PROTOCOL
    process_protocol = json.loads(PROTOCOLS[args.protocol].read_text())
    if args.implementation not in process_protocol['implementations']:
        raise RuntimeError('implementation_not_in_protocol')
    if args.implementation == 'django' and args.protocol != 'p11':
        raise RuntimeError('django_requires_p11_protocol')
    PROTOCOL = process_protocol
    os.umask(0o077)
    output = args.output.resolve()
    if not output.is_relative_to(ROOT / '.local') or (output.exists() != args.resume):
        raise RuntimeError('output_requires_new_local_directory_or_resume')
    current_sources = sources()
    source_hash = fingerprint(current_sources)
    if args.resume:
        meta = json.loads((output / 'run.json').read_text())
        validate_resume(meta, args.smoke, current_sources, args.implementation, args.protocol)
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
                'implementation': args.implementation, 'protocol_file': args.protocol,
                'protocol_sha256': digest(PROTOCOLS[args.protocol]),
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
    if args.implementation == 'django':
        # smoke.container()/harness helpers resolve the P11 namespace in-process.
        harness.STATE = P11_STATE
        harness.PREFIX = 'lhie-p11-'
        harness.IMAGE = 'lhie-p01-boundary:1'
    if args.implementation == 'laravel':
        config = harness.validate(json.loads((harness.STATE / 'laravel/config.json').read_text()))
        if config != harness.default('laravel'):
            raise RuntimeError('only_default_synthetic_configuration_admitted')
    else:
        prepare_candidate_admission()
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
    meta['commands'].append('python3 tools/migration_benchmark/runner.py --protocol ' + args.protocol
                            + (' --smoke ' if args.smoke else ' --benchmark ')
                            + '--implementation ' + args.implementation + ' '
                            + ('--resume ' if args.resume else '')
                            + '--output ' + str(output.relative_to(ROOT)))
    save()
    print(json.dumps({'event': 'baseline_started', 'output': str(output.relative_to(ROOT)),
                      'implementation': args.implementation,
                      'status': meta['status'], 'protocol_version': PROTOCOL['schema_version']}), flush=True)
    safe_to_reset = True
    django = args.implementation == 'django'
    meta['stage'] = 'start_stack'
    try:
        if django:
            django_lifecycle('reset', 'candidate')
            manifest_path = Path(django_lifecycle('start', 'candidate', '--quarantine'))
            ids = {service: container('candidate', service) for service in ('boundary', 'database', 'redis')}
            app = None
            services = ('boundary', 'database', 'redis')
        else:
            cli('reset', 'laravel')
            manifest_path = Path(cli('start', 'laravel'))
            ids = {service: container('laravel', service) for service in ('boundary', 'database', 'redis', 'app')}
            app = ids['app']
            services = ('boundary', 'database', 'redis', 'app')
        meta['harness'] = json.loads(manifest_path.read_text())
        if django:
            meta['candidate_admission'] = json.loads((candidate_directory() / 'candidate.json').read_text())
            meta['candidate_image'] = command(['docker', 'image', 'inspect', CANDIDATE_IMAGE, '--format', '{{.Id}}'])
            meta['comparator_image'] = command(['docker', 'image', 'inspect', 'lhie-p01-laravel:1', '--format', '{{.Id}}'])
        else:
            meta['assets_sha256'] = {str(p.relative_to(ROOT / 'src/public')): digest(p)
                                     for p in sorted((ROOT / 'src/public/build').rglob('*')) if p.is_file()}
            image_id = command(['docker', 'inspect', '--format', '{{.Image}}', app])
            labels = json.loads(command(['docker', 'image', 'inspect', '--format', '{{json .Config.Labels}}', image_id])) or {}
            meta['asset_build_identity'] = {key: value for key, value in labels.items() if key.startswith('lhie.p04.')}
            if labels.get('lhie.p04.assets-tree-sha256') != harness.tree_digest(ROOT / 'src/public/build'):
                raise RuntimeError('comparator_assets_do_not_match_frozen_inputs')
        observed = snapshot(ids, session)
        verify_limits(observed, services)
        atomic_json(output / 'admission-resources.json', observed)
        if not django:
            install_tools(app)
        settings = "SELECT json_object_agg(name,setting) FROM pg_settings WHERE name IN ('max_connections','shared_buffers','synchronous_commit','fsync','full_page_writes','TimeZone','max_worker_processes','checkpoint_timeout')"
        meta.setdefault('postgres_settings', []).append(json.loads(postgres(ids, settings)))
        with Progress(output), Sensors(ids, output / 'resources.jsonl', session):
            cells = list(planned_cells(args.smoke, args.implementation))
            manifests = {}
            for role in ('primary', 'diagnostic'):
                for dimensions in (cell for cell in cells if measurement_role(cell, args.smoke) == role):
                    dataset = dimensions['dataset']
                    while (batch := next_batch(points, dimensions, args.smoke)) is not None:
                        if dataset not in manifests:
                            meta['stage'] = 'seed'
                            save()
                            manifest = prepare_template(ids, dataset, meta, output)
                            meta.setdefault('schema_sha256', []).append(hashlib.sha256(command([
                                'docker', 'exec', ids['database'], 'pg_dump', '-U', 'harness', '-d',
                                'disposable', '--schema-only', '--no-comments']).encode()).hexdigest())
                            manifests[dataset] = manifest
                            save()
                        meta['stage'] = 'clone'
                        save()
                        manifest = manifests[dataset]
                        clone_database(ids, dataset)
                        command(['docker', 'exec', ids['redis'], 'redis-cli', 'FLUSHALL'])
                        if django:
                            admitted = php_probe(ids, php_storage(output), {
                                'mode': 'admit_seed', 'dataset': dataset,
                                'logical_sha256': manifest['logical_sha256']})
                        else:
                            admitted = probe(app, {'mode': 'admit_seed', 'dataset': dataset,
                                                   'logical_sha256': manifest['logical_sha256']})
                        if not admitted.get('admitted') or admitted['manifest'] != manifest:
                            raise RuntimeError('cloned_dataset_admission_failed')
                        meta['stage'] = 'probe'
                        save()
                        count = samples_per_batch(points, dimensions, args.smoke)
                        phases = [(False, count)]
                        if measurement_role(dimensions, args.smoke) == 'primary':
                            phases.insert(0, (True, PROTOCOL['warmup_samples_per_batch']))
                        for index, (warmup, samples) in enumerate(phases):
                            payload = dict(mode='batch', batch=batch, samples=samples, warmup=warmup, **dimensions)
                            # Derived identity fields stay explicit; proposals never
                            # infer an implementation or transport from context.
                            payload['implementation'] = implementation_of(payload)
                            payload['transport'] = 'synthetic'
                            # Warmup and measurement share one pristine clone; only
                            # the first phase may require empty candidate state.
                            payload['fresh_clone'] = index == 0
                            payload['evidence_path'] = (
                                '/evidence/' + evidence_name(meta['run_id'], payload)
                                if django else '/var/www/html/storage/app/private/p04-evidence/'
                                + meta['run_id'] + '-' + point_name(payload) + '.jsonl')
                            before = time.monotonic_ns()
                            probe_id = probe_name(meta['run_id'], payload)
                            authority_before = authority_snapshot(ids) if django else None
                            meta['active'] = {'cell': payload, 'app': app,
                                              'probe_name': probe_id if django else None,
                                              'started_ns': before, 'session': session}
                            save()
                            safe_to_reset = False
                            result = None
                            completed = True
                            probe_state = None
                            host_evidence = output / 'spools' / evidence_name(meta['run_id'], payload)
                            try:
                                if django:
                                    result, payload, host_evidence, probe_state = candidate_probe(
                                        ids, payload, output, meta['run_id'], session)
                                else:
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
                                code = ('timeout'
                                        if isinstance(error, subprocess.TimeoutExpired) or str(error) == 'timeout'
                                        else 'probe_error')
                                if django:
                                    stopped = candidate_stop(probe_id)
                                    records, detail = recover_candidate_batch(
                                        payload, result, code, output, host_evidence, stopped)
                                    if isinstance(error, RuntimeError) and str(error).startswith('probe_error:'):
                                        detail['probe_error_code'] = str(error).split(':', 1)[1]
                                else:
                                    records, detail = recover_batch(app, payload, result, code, output)
                                meta['runner_failures'].append({'cell': payload, 'code': code, 'recovery': detail})
                            changed = []
                            if django:
                                changed = authority_delta(authority_before, authority_snapshot(ids))
                                detail['authority_audit'] = {
                                    'changed_tables': changed, 'violations': len(changed),
                                    'excluded_tables': sorted(AUTHORITY_EXCLUDED)}
                                detail['probe_resources'] = {
                                    'state': (probe_state or {}).get('state'),
                                    'samples': (probe_state or {}).get('resources', [])}
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
                            if changed:
                                raise RuntimeError('authority_violation')
                            if not completed:
                                raise RuntimeError('probe_failed_run_stopped')
            meta['status'] = 'complete_with_unknowns' if meta['runner_failures'] else 'complete'
    except (Exception, KeyboardInterrupt) as error:
        meta['status'] = 'failed'
        meta['error_type'] = type(error).__name__
        meta['runner_failures'].append({'code': 'runner_error'})
    finally:
        if safe_to_reset:
            try:
                if django:
                    django_lifecycle('reset', 'candidate')
                else:
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
        meta['stage'] = 'report'
        attempts = write_report(output, output / 'samples.jsonl', meta, args.smoke)
    print(json.dumps({'output': str(output.relative_to(ROOT)), 'implementation': args.implementation,
                      'status': meta['status'], 'attempts': attempts}))
    return 1 if meta['status'] == 'failed' else 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (RuntimeError, ValueError, KeyError, OSError, subprocess.TimeoutExpired):
        print('benchmark failed safely; inspect sanitized run status; no child payload is printed.', file=sys.stderr)
        raise SystemExit(1)
