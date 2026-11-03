import importlib.util
import io
import json
import tempfile
from contextlib import redirect_stdout
from types import SimpleNamespace
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

TOOLS = Path(__file__).resolve().parents[2] / 'tools/migration_harness'
sys.path.insert(0,str(TOOLS))
spec = importlib.util.spec_from_file_location('p06_interactive_runner',TOOLS / 'interactive_navigation.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class InteractiveNavigationTests(unittest.TestCase):
    def test_only_exact_allowlisted_private_actor_can_reach_python(self):
        message = {'message_id':1,'chat':{'id':11,'type':'private'},'from':{'id':11},'text':'/start'}
        self.assertTrue(runner.admitted({'message':message},[11]))
        self.assertTrue(runner.admitted({'callback_query':{'from':{'id':11},'message':message}},[11]))
        for update in (
            {'message':message},
            {'message':{**message,'from':{'id':12}}},
            {'message':{**message,'chat':{'id':11,'type':'group'}}},
            {'callback_query':{'from':{'id':12},'message':message}},
            {'message':{}},
        ):
            allowed = [12] if update == {'message':message} else [11]
            with self.subTest(update=update):
                self.assertFalse(runner.admitted(update,allowed))

    def test_management_adapter_cannot_dispatch_messages_or_payment_operations(self):
        with patch.object(runner.urllib.request,'urlopen') as request:
            for method in ('sendMessage','sendPhoto','pinChatMessage','transferBusinessAccountStars'):
                with self.subTest(method=method),self.assertRaises(ValueError):
                    runner.telegram({'bot_token':'synthetic'},method,{})
        request.assert_not_called()

    def test_integrated_requires_exact_gate_and_managed_workers_before_network(self):
        for gate, managed in (('P06-approved-navigation-targets-only', True),
                              ('P08-approved-dual-targets-only', False)):
            with self.subTest(gate=gate, managed=managed), tempfile.TemporaryDirectory() as directory:
                state = Path(directory)
                (state / 'candidate').mkdir()
                config = {'mode': 'p08-sandbox-testing-bot', 'approval': {'gate': gate}}
                (state / 'candidate/config.json').write_text(json.dumps(config))
                with patch.object(runner.harness, 'STATE', state), \
                     patch.object(runner.harness, 'validate', side_effect=lambda x: x), \
                     patch.object(runner, 'telegram') as api, self.assertRaises(ValueError):
                    runner.run(1, state / 'restore.json', True, managed)
                api.assert_not_called()

    def test_configured_webhook_is_never_removed(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            (state / 'candidate').mkdir()
            config = {'mode': 'p08-sandbox-testing-bot',
                      'approval': {'gate': 'P08-approved-dual-targets-only'}, 'bot_id': 1,
                      'p08_create_limit': 1, 'p08_create_amount': 500, 'p08_receipt_delivery': True}
            (state / 'candidate/config.json').write_text(json.dumps(config))
            restore = state / 'restore.json'
            restore.write_text(json.dumps({'url': 'https://example.invalid/secret'}))
            with patch.object(runner.harness, 'STATE', state), \
                 patch.object(runner.harness, 'validate', side_effect=lambda x: x), \
                 patch.object(runner, 'container', return_value='isolated'), \
                 patch.object(runner, 'require_boundary_proof'), \
                 patch.object(runner, 'telegram', side_effect=[
                     {'id': 1}, {'url': 'https://example.invalid/secret', 'has_custom_certificate': False}
                 ]) as api, patch.object(runner.subprocess, 'Popen') as popen, self.assertRaises(ValueError):
                runner.run(1, restore, True, True)
            self.assertEqual([call.args[1] for call in api.call_args_list], ['getMe', 'getWebhookInfo'])
            popen.assert_not_called()

    def test_unhandled_update_stops_before_any_next_offset(self):
        valid = {'update_id': 1, 'message': {'message_id': 1, 'chat': {'id': 11, 'type': 'private'},
                                             'from': {'id': 11}, 'text': '/start'}}
        foreign = {'update_id': 2, 'message': {'message_id': 2, 'chat': {'id': 12, 'type': 'private'},
                                               'from': {'id': 12}, 'text': '/start'}}
        oversized = {**valid, 'update_id': 2, 'message': {**valid['message'], 'entities': 'x' * 17000}}
        malformed = {'update_id': 2, 'message': {'chat': []}}
        forbidden = {**valid, 'update_id': 2, 'message': {**valid['message'], 'text': '/deposit 1'}}
        pinned = {**valid, 'update_id': 2, 'message': {**valid['message'], 'pinned_message': {}}}
        for rejected in (foreign, oversized, malformed, forbidden, pinned):
            with self.subTest(rejected_type=type(rejected)), tempfile.TemporaryDirectory() as directory:
                state = Path(directory)
                (state / 'candidate').mkdir()
                config = {'mode': 'p08-sandbox-testing-bot',
                          'approval': {'gate': 'P08-approved-dual-targets-only'},
                          'bot_id': 1, 'safe_chat_ids': [11], 'p08_create_limit': 1,
                          'p08_create_amount': 500, 'p08_receipt_delivery': True}
                (state / 'candidate/config.json').write_text(json.dumps(config))
                restore = state / 'restore.json'
                restore.write_text('{"url": ""}')
                requests = []
                class Poll:
                    def __init__(self, config):
                        pass
                    def get_updates(self, payload):
                        requests.append(payload)
                        return [valid, rejected]
                    def close(self):
                        pass
                process = SimpleNamespace(stdin=io.StringIO(),
                                          stdout=io.StringIO('{"status":"ready"}\n{"status":"admitted"}\n'),
                                          wait=lambda timeout: None)
                with patch.object(runner.harness, 'STATE', state), \
                     patch.object(runner.harness, 'validate', side_effect=lambda x: x), \
                     patch.object(runner, 'require_boundary_proof'), \
                     patch.object(runner, 'webhook_restore_payload', return_value={'url': 'synthetic', 'allowed_updates': ['message']}), \
                     patch.object(runner, 'webhook_matches', return_value=True), \
                     patch.object(runner, 'telegram', side_effect=[
                         {'id': 1}, {'url': 'synthetic'}, True, {'url': ''}, True, {}
                     ]) as api, patch.object(runner, 'container', return_value='isolated'), \
                     patch.object(runner.subprocess, 'Popen', return_value=process) as popen, \
                     patch.object(runner, 'PollingConnection', Poll), redirect_stdout(io.StringIO()), \
                     self.assertRaises(ValueError):
                    runner.run(1, restore, True, True)
                self.assertEqual(requests, [{'offset': 0, 'timeout': 10, 'limit': 25,
                                             'allowed_updates': ['message', 'callback_query']}])
                self.assertEqual([call.args[1] for call in api.call_args_list],
                                 ['getMe', 'getWebhookInfo', 'deleteWebhook', 'getWebhookInfo',
                                  'setWebhook', 'getWebhookInfo'])
                self.assertIn('--testing-bot-workers-stdin', popen.call_args.args[0])

    def test_acknowledges_only_durable_admission_without_logging_payload(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            (state / 'candidate').mkdir()
            config = {'mode': 'p08-sandbox-testing-bot',
                      'approval': {'gate': 'P08-approved-dual-targets-only'},
                      'bot_id': 1, 'safe_chat_ids': [11], 'p08_create_limit': 1,
                      'p08_create_amount': 500, 'p08_receipt_delivery': True}
            (state / 'candidate/config.json').write_text(json.dumps(config))
            restore = state / 'restore.json'
            restore.write_text('{"url": ""}')
            requests = []
            update = {'update_id': 123, 'message': {'message_id': 1,
                      'chat': {'id': 11, 'type': 'private'}, 'from': {'id': 11},
                      'text': '/start'}}
            class Poll:
                def __init__(self, config):
                    pass
                def get_updates(self, payload):
                    requests.append(payload)
                    if len(requests) == 1:
                        return [update]
                    raise RuntimeError('stop')
                def close(self):
                    pass
            process = SimpleNamespace(stdin=io.StringIO(),
                                      stdout=io.StringIO('{"status":"ready"}\n{"status":"admitted","secret":"private-payload"}\n'),
                                      wait=lambda timeout: None)
            output = io.StringIO()
            with patch.object(runner.harness, 'STATE', state), \
                 patch.object(runner.harness, 'validate', side_effect=lambda x: x), \
                 patch.object(runner, 'require_boundary_proof'), \
                 patch.object(runner, 'webhook_restore_payload', return_value={'url': 'synthetic', 'allowed_updates': ['message']}), \
                 patch.object(runner, 'webhook_matches', return_value=True), \
                 patch.object(runner, 'telegram', side_effect=[
                     {'id': 1}, {'url': 'synthetic'}, True, {'url': ''}, True, {}
                 ]), patch.object(runner, 'container', return_value='isolated'), \
                 patch.object(runner.subprocess, 'Popen', return_value=process), \
                 patch.object(runner, 'PollingConnection', Poll), redirect_stdout(output), \
                 self.assertRaises(RuntimeError):
                runner.run(1, restore, True, True)
            self.assertEqual([request['offset'] for request in requests], [0, 124])
            self.assertNotIn('private-payload', output.getvalue())
            self.assertNotIn('123', output.getvalue())

    def test_one_create_cap_is_required_before_network(self):
        for cap in (None, False, True, 0, 2, '1'):
            with self.subTest(cap=cap), tempfile.TemporaryDirectory() as directory:
                state = Path(directory)
                (state / 'candidate').mkdir()
                config = {'mode': 'p08-sandbox-testing-bot',
                          'approval': {'gate': 'P08-approved-dual-targets-only'},
                          'p08_create_limit': cap, 'p08_create_amount': 500, 'p08_receipt_delivery': True}
                (state / 'candidate/config.json').write_text(json.dumps(config))
                with patch.object(runner.harness, 'STATE', state), \
                     patch.object(runner.harness, 'validate', side_effect=lambda x: x), \
                     patch.object(runner, 'telegram') as api, self.assertRaises(ValueError):
                    runner.run(1, state / 'restore.json', True, True)
                api.assert_not_called()

    def test_attested_amount_is_required_before_network(self):
        for amount in (None, False, True, 0, -1, '500'):
            with self.subTest(amount=amount), tempfile.TemporaryDirectory() as directory:
                state = Path(directory)
                (state / 'candidate').mkdir()
                config = {'mode': 'p08-sandbox-testing-bot',
                          'approval': {'gate': 'P08-approved-dual-targets-only'},
                          'p08_create_limit': 1, 'p08_create_amount': amount,
                          'p08_receipt_delivery': True}
                (state / 'candidate/config.json').write_text(json.dumps(config))
                with patch.object(runner.harness, 'STATE', state), \
                     patch.object(runner.harness, 'validate', side_effect=lambda x: x), \
                     patch.object(runner, 'telegram') as api, self.assertRaises(ValueError):
                    runner.run(1, state / 'restore.json', True, True)
                api.assert_not_called()

    def test_webhook_context_requires_secret_attestation_and_observable_match(self):
        old = {'url': 'https://example.invalid/path', 'has_custom_certificate': False,
               'max_connections': 40, 'allowed_updates': ['message'], 'ip_address': '192.0.2.1'}
        saved = {'url': old['url'], 'max_connections': 40,
                 'allowed_updates': ['message'], 'secret_token': 'saved_secret'}
        for secret_attested, dns_attested in ((False, True), (True, False)):
            with self.assertRaises(ValueError):
                runner.webhook_restore_payload(old, saved, secret_attested, dns_attested)
        for key, value in (('url', 'https://example.invalid/other'), ('max_connections', 5),
                           ('allowed_updates', []), ('secret_token', 'invalid secret')):
            with self.subTest(key=key), self.assertRaises(ValueError):
                runner.webhook_restore_payload(old, {**saved, key: value}, True, True)
        payload = runner.webhook_restore_payload(old, saved, True, True)
        self.assertEqual(payload['secret_token'], 'saved_secret')
        self.assertFalse(payload['drop_pending_updates'])
        self.assertTrue(runner.webhook_matches(old, old))
        for key, value in (('url', ''), ('max_connections', 5), ('allowed_updates', []),
                           ('ip_address', '192.0.2.2'), ('has_custom_certificate', True)):
            with self.subTest(key=key):
                self.assertFalse(runner.webhook_matches({**old, key: value}, old))

    def test_polling_filter_preserves_existing_subscriptions(self):
        self.assertEqual(runner.polling_update_filter([]), [])
        self.assertEqual(runner.polling_update_filter(['message']), ['message', 'callback_query'])
        self.assertEqual(runner.polling_update_filter(['chat_member', 'callback_query']),
                         ['chat_member', 'callback_query', 'message'])
        self.assertEqual(runner.polling_update_filter(['message', 'callback_query', 'chat_member']),
                         ['message', 'callback_query', 'chat_member'])

    def test_webhook_is_restored_after_unhandled_update(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            (state / 'candidate').mkdir()
            config = {'mode': 'p08-sandbox-testing-bot', 'p08_create_limit': 1, 'p08_create_amount': 500,
                      'approval': {'gate': 'P08-approved-dual-targets-only'},
                      'bot_id': 1, 'safe_chat_ids': [11], 'p08_receipt_delivery': True}
            (state / 'candidate/config.json').write_text(json.dumps(config))
            restore = state / 'restore.json'
            old = {'url': 'https://example.invalid/private', 'has_custom_certificate': False,
                   'max_connections': 40, 'allowed_updates': ['message'], 'ip_address': '192.0.2.1'}
            restore.write_text(json.dumps({**old, 'secret_token': 'saved_secret'}))
            requests = []
            class Poll:
                def __init__(self, config):
                    pass
                def get_updates(self, payload):
                    requests.append(payload)
                    return [{'update_id': 1, 'message': {'chat': {'id': 12, 'type': 'private'},
                                                        'from': {'id': 12}}}]
                def close(self):
                    pass
            process = SimpleNamespace(stdin=io.StringIO(), stdout=io.StringIO('{"status":"ready"}\n'),
                                      wait=lambda timeout: None)
            output = io.StringIO()
            with patch.object(runner.harness, 'STATE', state), \
                 patch.object(runner.harness, 'validate', side_effect=lambda x: x), \
                 patch.object(runner, 'require_boundary_proof'), \
                 patch.object(runner, 'telegram', side_effect=[
                     {'id': 1}, old, True, {'url': ''}, True, old
                 ]) as api, patch.object(runner, 'container', return_value='isolated'), \
                 patch.object(runner.subprocess, 'Popen', return_value=process), \
                 patch.object(runner, 'PollingConnection', Poll), redirect_stdout(output), \
                 self.assertRaises(ValueError):
                runner.run(1, restore, True, True, True, True)
            self.assertEqual([call.args[1] for call in api.call_args_list],
                             ['getMe', 'getWebhookInfo', 'deleteWebhook', 'getWebhookInfo',
                              'setWebhook', 'getWebhookInfo'])
            self.assertFalse(api.call_args_list[2].args[2]['drop_pending_updates'])
            self.assertFalse(api.call_args_list[4].args[2]['drop_pending_updates'])
            self.assertEqual(requests[0]['allowed_updates'], ['message', 'callback_query'])
            self.assertEqual(api.call_args_list[4].args[2]['allowed_updates'], ['message'])
            self.assertIn('TESTING_BOT_ROUTING_OBSERVABLY_RESTORED', output.getvalue())
            self.assertNotIn('saved_secret', output.getvalue())
            self.assertNotIn(old['url'], output.getvalue())

    def test_boundary_proof_must_match_running_bound_container(self):
        project = runner.harness.PREFIX + 'candidate'
        app_id, boundary_id = 'a' * 64, 'b' * 64
        records = [
            {'Id': app_id, 'State': {'Running': True},
             'Config': {'Labels': {'com.docker.compose.project': project, 'com.docker.compose.service': 'app'}},
             'HostConfig': {'NetworkMode': 'container:' + boundary_id}},
            {'Id': boundary_id, 'State': {'Running': True},
             'Config': {'Labels': {'com.docker.compose.project': project, 'com.docker.compose.service': 'boundary'}}},
        ]
        config = {'approval': {'scope_sha256': 'digest'}, 'p08_create_amount': 500}
        proof = {'version': 'p01-h1-relay-v1', 'scope_sha256': 'digest',
                 'p08_create_limit': 1, 'p08_create_amount': 500, 'p08_receipt_delivery': True}
        with patch.object(runner, 'container', return_value=boundary_id[:12]), \
             patch.object(runner.subprocess, 'run', side_effect=[
                 SimpleNamespace(stdout=json.dumps(records).encode()),
                 SimpleNamespace(stdout=json.dumps(proof).encode())
             ]) as execute:
            runner.require_boundary_proof(app_id[:12], config)
        self.assertEqual(execute.call_args_list[1].args[0],
                         ['docker', 'exec', boundary_id[:12], 'cat', '/tmp/h1-proof.json'])
        for altered in ({**proof, 'scope_sha256': 'old'}, {**proof, 'p08_create_limit': True},
                        {**proof, 'p08_create_amount': 501}, {**proof, 'p08_create_amount': True},
                        {key: value for key, value in proof.items() if key != 'p08_create_amount'},
                        {**proof, 'p08_receipt_delivery': False}, {}):
            with self.subTest(altered=altered), \
                 patch.object(runner, 'container', return_value=boundary_id[:12]), \
                 patch.object(runner.subprocess, 'run', side_effect=[
                     SimpleNamespace(stdout=json.dumps(records).encode()),
                     SimpleNamespace(stdout=json.dumps(altered).encode())
                 ]), self.assertRaises(ValueError):
                runner.require_boundary_proof(app_id[:12], config)
        records[0]['HostConfig']['NetworkMode'] = 'container:' + 'c' * 64
        with patch.object(runner, 'container', return_value=boundary_id[:12]), \
             patch.object(runner.subprocess, 'run', return_value=SimpleNamespace(stdout=json.dumps(records).encode())) as execute, \
             self.assertRaises(ValueError):
            runner.require_boundary_proof(app_id[:12], config)
        self.assertEqual(execute.call_count, 1)


