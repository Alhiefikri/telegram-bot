import importlib.util
import json
from pathlib import Path
import tempfile
import unittest


TOOLS = Path(__file__).resolve().parents[2] / 'tools/migration_benchmark'
sys_path = str(TOOLS)
import sys
sys.path.insert(0, sys_path)
spec = importlib.util.spec_from_file_location('candidate_probe', TOOLS / 'candidate_probe.py')
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)
sys.path.insert(0, str(TOOLS))
import report  # noqa: E402


def payload(**changes):
    value = dict(mode='batch', implementation='django', transport='synthetic',
                 dataset='large', profile='current', scenario='home_catalog', users=5,
                 traffic='distinct', temperature='warm', background='idle', batch=1,
                 samples=25, warmup=False, fresh_clone=True,
                 evidence_path='/evidence/run-home_catalog.jsonl')
    value.update(changes)
    return value


class CandidateProbeContractTest(unittest.TestCase):
    def test_paired_cell_identity_is_accepted_and_other_conditions_refused(self):
        self.assertEqual(probe.validate_batch(payload())['scenario'], 'home_catalog')
        for users in (1, 5, 10):
            self.assertEqual(probe.validate_batch(payload(scenario='orders', users=users))['users'],
                             users)
        for changes in ({'background': 'representative'}, {'traffic': 'same_chat'},
                        {'temperature': 'cold'}, {'scenario': 'balance'},
                        {'implementation': 'laravel'}, {'profile': 'normalized'},
                        {'transport': 'sandbox'}, {'dataset': 'production'}):
            with self.subTest(changes=changes):
                with self.assertRaises(probe.Refused):
                    probe.validate_batch(payload(**changes))
        with self.assertRaises(probe.Refused):
            probe.validate_batch(payload(evidence_path='/etc/passwd'))
        with self.assertRaises(probe.Refused):
            probe.validate_batch(payload(evidence_path='/evidence/../../escape.jsonl'))
        with self.assertRaises(probe.Refused):
            probe.validate_batch(payload(samples=0))
        with self.assertRaises(probe.Refused):
            probe.validate_batch(payload(batch=True))
        with self.assertRaises(probe.Refused):
            probe.validate_batch(payload(fresh_clone='yes'))

    def test_event_spool_merges_boundaries_and_ignores_torn_tail(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'events.jsonl'
            lines = [
                {'update_id': 5, 'boundary': 'pickup', 'ns': 100},
                {'update_id': 5, 'boundary': 'db', 'db_ms': 1.5},
                {'update_id': 5, 'boundary': 'screen_ack', 'ns': 180},
                {'update_id': 5, 'boundary': 'callbacks',
                 'callbacks': ['katalog', 'x' * 65, 7]},
                {'update_id': 5, 'boundary': 'completed', 'outcome': 'applied'},
            ]
            path.write_text(''.join(json.dumps(line) + '\n' for line in lines) + '{"update_id":')
            events = probe.Events(str(path))
            events.read()
            record = events.get(5)
            self.assertEqual(record['timestamps']['pickup'], 100)
            self.assertEqual(record['timestamps']['screen_ack'], 180)
            self.assertEqual(record['db_ms'], 1.5)
            self.assertEqual(record['callbacks'], ['katalog'])
            self.assertTrue(record['completed'])
            self.assertEqual(record['outcome'], 'applied')
            self.assertEqual(events.offset, len(''.join(json.dumps(line) + '\n' for line in lines)))
            with path.open('a') as stream:
                # Terminate the torn line first; a crash never appends a valid
                # record immediately after an unterminated fragment.
                stream.write('\n' + json.dumps({'update_id': 6, 'boundary': 'pickup', 'ns': 200}) + '\n')
            events.read()
            self.assertEqual(events.get(6)['timestamps']['pickup'], 200)
            self.assertEqual(events.get(5)['timestamps']['pickup'], 100)

    def test_record_is_a_valid_sanitized_benchmark_sample(self):
        sample = probe.record(payload(warmup=True), 3, 'success', None)
        self.assertEqual(sample['implementation'], 'django')
        self.assertEqual(sample['transport'], 'synthetic')
        self.assertTrue(sample['warmup'])
        self.assertEqual(set(sample['timestamps']), set(probe.BOUNDARIES))
        self.assertEqual(report.summarize([dict(sample, warmup=False)])['cells'][0]
                         ['dimensions']['implementation'], 'django')
        self.assertNotIn('payload', sample)
        self.assertNotIn('customer', json.dumps(sample))


if __name__ == '__main__':
    unittest.main()
