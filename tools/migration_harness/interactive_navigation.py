#!/usr/bin/env python3
"""Explicit, time-bounded Testing Bot QA. Never starts from deployment or scheduler."""
import argparse
import importlib.util
import http.client
import json
from pathlib import Path
import signal
import subprocess
import sys
import time
import urllib.request

import harness
from smoke import container

_candidate_spec = importlib.util.spec_from_file_location(
    '_qa_candidate_interactions', Path(__file__).resolve().parents[2] / 'candidate/interactive_navigation.py')
_candidate_module = importlib.util.module_from_spec(_candidate_spec)
_candidate_spec.loader.exec_module(_candidate_module)
integrated_update_allowed = _candidate_module.integrated_update_allowed


def require_boundary_proof(app, config):
    boundary = container('candidate', 'boundary')
    if (not isinstance(app, str) or not isinstance(boundary, str)
            or not app or not boundary or len(app.splitlines()) != 1 or len(boundary.splitlines()) != 1):
        raise ValueError('Admitted boundary required')
    inspection = subprocess.run(['docker', 'inspect', app, boundary], stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, timeout=8, check=True)
    records = json.loads(inspection.stdout)
    if not isinstance(records, list) or len(records) != 2:
        raise ValueError('Candidate namespace mismatch')
    app_record, boundary_record = records
    project = harness.PREFIX + 'candidate'
    for record, service, identity in ((app_record, 'app', app), (boundary_record, 'boundary', boundary)):
        labels = record.get('Config', {}).get('Labels') or {}
        if (not record.get('State', {}).get('Running') or not record.get('Id', '').startswith(identity)
                or labels.get('com.docker.compose.project') != project
                or labels.get('com.docker.compose.service') != service):
            raise ValueError('Candidate namespace mismatch')
    if app_record.get('HostConfig', {}).get('NetworkMode') != 'container:' + boundary_record['Id']:
        raise ValueError('Candidate namespace mismatch')
    result = subprocess.run(['docker', 'exec', boundary, 'cat', '/tmp/h1-proof.json'],
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=8, check=True)
    if len(result.stdout) > 4096:
        raise ValueError('Running boundary capability mismatch')
    proof = json.loads(result.stdout)
    expected = {'version': 'p01-h1-relay-v1', 'scope_sha256': config['approval']['scope_sha256'],
                'p08_create_limit': 1, 'p08_create_amount': config['p08_create_amount'],
                'p08_receipt_delivery': True}
    if (not isinstance(proof, dict) or proof.keys() != expected.keys()
            or any(type(proof[k]) is not type(v) or proof[k] != v for k, v in expected.items())):
        raise ValueError('Running boundary capability mismatch')


def telegram(config, method, payload):
    if method not in {'getMe','getWebhookInfo','deleteWebhook','getUpdates','setWebhook'}:
        raise ValueError('Unsupported QA management operation')
    request = urllib.request.Request('https://api.telegram.org/bot' + config['bot_token'] + '/' + method,
        json.dumps(payload).encode(), {'Content-Type':'application/json'})
    with urllib.request.urlopen(request, timeout=25) as response:
        body = response.read(262145)
    if len(body) > 262144:
        raise ValueError('Oversized Telegram update response')
    result = json.loads(body)
    if result.get('ok') is not True:
        raise ValueError('Testing Bot management rejected')
    return result['result']


class PollingConnection:
    def __init__(self, config):
        self.path = '/bot' + config['bot_token'] + '/getUpdates'
        self.connection = http.client.HTTPSConnection('api.telegram.org', timeout=25)

    def get_updates(self, payload):
        try:
            self.connection.request('POST', self.path, json.dumps(payload).encode(),
                                    {'Content-Type':'application/json'})
            response = self.connection.getresponse()
            if not 200 <= response.status < 300:
                raise ValueError('Testing Bot management rejected')
            body = response.read(262145)
            if len(body) > 262144:
                raise ValueError('Oversized Telegram update response')
            result = json.loads(body)
            if result.get('ok') is not True:
                raise ValueError('Testing Bot management rejected')
            return result['result']
        except BaseException:
            self.close()
            raise

    def close(self):
        self.connection.close()


def admitted(update, safe_chats):
    if not isinstance(update, dict) or (('callback_query' in update) == ('message' in update)):
        return False
    source = update.get('callback_query', update.get('message'))
    if not isinstance(source, dict):
        return False
    message = source.get('message') if 'callback_query' in update else source
    chat = message.get('chat') if isinstance(message, dict) else None
    actor = source.get('from')
    return (isinstance(chat, dict) and isinstance(actor, dict)
            and chat.get('type') == 'private' and type(chat.get('id')) is int
            and chat['id'] in safe_chats and type(actor.get('id')) is int
            and actor['id'] == chat['id'] and actor.get('is_bot') is not True)

def webhook_restore_payload(old, restore, secret_attested, dns_attested):
    if not isinstance(old, dict) or not isinstance(restore, dict):
        raise ValueError('Webhook restoration context mismatch')
    if old.get('has_custom_certificate') is not False:
        raise ValueError('Webhook certificate cannot be restored')
    url = old.get('url')
    if url == '' and restore.get('url') == '':
        return None
    if (not secret_attested or not dns_attested or not isinstance(url, str) or not url
            or restore.get('url') != url
            or type(old.get('max_connections')) is not int
            or old['max_connections'] != restore.get('max_connections')
            or not isinstance(old.get('allowed_updates'), list)
            or old['allowed_updates'] != restore.get('allowed_updates')
            or any(not isinstance(item, str) for item in old['allowed_updates'])
            or not isinstance(restore.get('secret_token'), str)
            or not restore['secret_token']
            or len(restore['secret_token']) > 256
            or any(c not in 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-'
                   for c in restore['secret_token'])):
        raise ValueError('Webhook restoration context mismatch')
    return {'url': url, 'secret_token': restore['secret_token'],
            'max_connections': old['max_connections'], 'allowed_updates': old['allowed_updates'],
            'drop_pending_updates': False}


def polling_update_filter(previous):
    if not previous:
        return []
    return previous + [kind for kind in ('message', 'callback_query') if kind not in previous]


def webhook_matches(observed, original):
    return (isinstance(observed, dict) and observed.get('has_custom_certificate') is False
            and all(observed.get(key) == original.get(key)
                    for key in ('url', 'max_connections', 'allowed_updates', 'ip_address')))


def run(minutes, restore_path, integrated=False, workers_managed=False,
        secret_attested=False, dns_attested=False):
    config = harness.validate(json.loads((harness.STATE / 'candidate/config.json').read_text()))
    expected = (('p08-sandbox-testing-bot', 'P08-approved-dual-targets-only') if integrated
                else ('testing-bot', 'P06-approved-navigation-targets-only'))
    if (config['mode'], config['approval']['gate']) != expected or (integrated and not workers_managed):
        raise ValueError('Explicit Testing Bot approval and worker supervision required')
    if integrated and (type(config.get('p08_create_limit')) is not int
                       or config['p08_create_limit'] != 1
                       or type(config.get('p08_create_amount')) is not int
                       or config['p08_create_amount'] <= 0
                       or config.get('p08_receipt_delivery') is not True):
        raise ValueError('Sandbox capability approval required')
    app = container('candidate','app')
    if not app:
        raise ValueError('Admitted candidate stack required')
    if integrated:
        require_boundary_proof(app, config)
    restore = json.loads(restore_path.read_text())
    if telegram(config,'getMe',{}).get('id') != config['bot_id']:
        raise ValueError('Testing Bot identity mismatch')
    old = telegram(config,'getWebhookInfo',{})
    restore_payload = webhook_restore_payload(old, restore, secret_attested, dns_attested)
    if integrated and restore_payload is None:
        raise ValueError('Restorable webhook required for update filter')
    worker = subprocess.Popen(['docker','exec','-i',app,'python','-u','interactive_navigation.py',
        '--testing-bot-workers-stdin' if integrated else '--testing-bot-stdin'],
        stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True)
    switched = False
    polling = None
    try:
        polling = PollingConnection(config)
        if json.loads(worker.stdout.readline()).get('status') != 'ready':
            raise ValueError('Interactive candidate refused startup')
        if restore_payload is not None:
            switched = True
            telegram(config, 'deleteWebhook', {'drop_pending_updates': False})
            current = telegram(config, 'getWebhookInfo', {})
            if not isinstance(current, dict) or current.get('url') != '':
                raise ValueError('Polling switch not confirmed')
        print('PYTHON_NAVIGATION_READY', flush=True)
        offset = 0
        deadline = time.monotonic() + minutes * 60
        while time.monotonic() < deadline:
            request = {'offset':offset,'timeout':10,'limit':25}
            if integrated:
                request['allowed_updates'] = polling_update_filter(restore_payload['allowed_updates'])
            updates = polling.get_updates(request)
            if not isinstance(updates, list):
                raise ValueError('Invalid update response')
            batch_received = time.monotonic_ns()
            for update in updates:
                if not isinstance(update, dict):
                    raise ValueError('Invalid update')
                identity = update.get('update_id')
                if type(identity) is not int or identity < 1 or identity < offset:
                    raise ValueError('Invalid update identity')
                if not admitted(update,config['safe_chat_ids']):
                    raise ValueError('Update outside approved private chat')
                if integrated and not integrated_update_allowed(update):
                    raise ValueError('Update outside integrated scope')
                encoded = json.dumps(update)
                if len(encoded.encode()) >= 16384:
                    raise ValueError('Oversized update')
                dispatched = time.monotonic_ns()
                worker.stdin.write(encoded + '\n'); worker.stdin.flush()
                outcome = json.loads(worker.stdout.readline())
                completed = time.monotonic_ns()
                if (not isinstance(outcome, dict)
                        or outcome.get('status') != ('admitted' if integrated else 'processed')
                        or (not integrated and outcome.get('outcome') in ('busy', 'waiting'))):
                    raise ValueError('Update not handled')
                if integrated:
                    print(json.dumps({'status': 'admitted'}), flush=True)
                else:
                    print(json.dumps({'status': 'processed',
                                      'driver_queue_ms': (dispatched - batch_received) / 1e6,
                                      'driver_roundtrip_ms': (completed - dispatched) / 1e6}), flush=True)
                offset = identity + 1
    finally:
        try:
            if polling is not None:
                polling.close()
        finally:
            try:
                if worker.stdin:
                    try:
                        worker.stdin.close()
                    except OSError:
                        pass
                try:
                    worker.wait(timeout=20)
                except subprocess.TimeoutExpired:
                    worker.terminate(); worker.wait(timeout=5)
                trailing = worker.stdout.read(32768) if worker.stdout else ''
                if isinstance(trailing, str):
                    for line in trailing.splitlines():
                        try:
                            summary = json.loads(line)
                        except (ValueError, UnicodeError):
                            continue
                        if (isinstance(summary, dict) and summary.get('status') == 'ack_summary'
                                and type(summary.get('drained')) is bool
                                and isinstance(summary.get('completed'), dict)):
                            counts = summary['completed']
                            if all(type(counts.get(key)) is int and counts[key] >= 0
                                   for key in ('success', 'unconfirmed')):
                                print(json.dumps({'status': 'ack_summary', 'drained': summary['drained'],
                                                  'completed': {key: counts[key] for key in
                                                                ('success', 'unconfirmed')}}), flush=True)
            finally:
                if switched:
                    telegram(config, 'setWebhook', restore_payload)
                    if not webhook_matches(telegram(config, 'getWebhookInfo', {}), old):
                        raise ValueError('Webhook restoration not confirmed')
                    print('TESTING_BOT_ROUTING_OBSERVABLY_RESTORED', flush=True)
                else:
                    print('TESTING_BOT_ROUTING_UNCHANGED',flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--approved-testing-bot-polling',action='store_true',required=True)
    parser.add_argument('--minutes',type=int,default=30)
    parser.add_argument('--restore-context',type=Path,required=True)
    parser.add_argument('--integrated-dual-targets', action='store_true')
    parser.add_argument('--caller-managed-worker-supervisor', action='store_true')
    parser.add_argument('--attest-saved-webhook-secret-current', action='store_true',
                        help='operator attests the saved secret is the current webhook secret')
    parser.add_argument('--attest-webhook-unpinned-dns', action='store_true',
                        help='operator attests the existing webhook has no pinned IP override')
    args = parser.parse_args()
    if (not 1 <= args.minutes <= 60 or not args.restore_context.resolve().is_relative_to(harness.STATE.resolve())
            or args.caller_managed_worker_supervisor != args.integrated_dual_targets):
        raise ValueError('Private bounded QA context required')
    signal.signal(signal.SIGTERM,lambda *_: (_ for _ in ()).throw(KeyboardInterrupt()))
    run(args.minutes, args.restore_context, args.integrated_dual_targets,
        args.caller_managed_worker_supervisor, args.attest_saved_webhook_secret_current,
        args.attest_webhook_unpinned_dns)


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        pass
    except Exception:
        print('Interactive Testing Bot QA stopped; inspect routing restoration status.',flush=True)
        raise SystemExit(1) from None
