import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch


TOOLS = Path(__file__).resolve().parents[2] / 'tools/migration_benchmark'
sys.path.insert(0, str(TOOLS))
spec = importlib.util.spec_from_file_location('benchmark_runner', TOOLS / 'runner.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def payload():
    return dict(mode='batch', dataset='small', profile='current', scenario='home_catalog',
                users=1, traffic='distinct', temperature='warm', background='idle',
                batch=1, warmup=False, samples=3,
                evidence_path='/var/www/html/storage/app/private/p04-evidence/test.jsonl')


def sample(attempt=1, **changes):
    result = dict(schema_version=1, implementation='laravel', transport='synthetic',
                  **{key: payload()[key] for key in runner.CELL_FIELDS}, attempt=attempt,
                  outcome='success', retries=0, timestamps={'ingress': 100, 'screen_ack': 200},
                  db_ms=0.25, error_code=None)
    result.update(changes)
    return result


class BenchmarkRunnerTest(unittest.TestCase):
    def test_recovery_keeps_completed_outcomes_timestamps_and_retries(self):
        completed = [sample(1), sample(3, outcome='timeout', retries=2, error_code='timeout')]
        spool = '\n'.join(json.dumps(record) for record in completed) + '\n{"attempt":'
        with patch.object(runner, 'contain_probe', return_value=True), \
                patch.object(runner, 'command', return_value=spool):
            records, detail = runner.recover_batch('app', payload(), None, 'timeout')
        self.assertEqual(records[0], completed[0])
        self.assertEqual(records[2], completed[1])
        self.assertEqual(records[1]['attempt'], 2)
        self.assertEqual(records[1]['outcome'], 'unknown')
        self.assertEqual(records[1]['timestamps'], {})
        self.assertEqual(detail['completed_attempts_retained'], 2)
        self.assertEqual(detail['rejected_evidence_records'], 1)
        self.assertTrue(detail['evidence_copied'])
        runner.summarize(records)

    def test_invalid_and_foreign_identities_do_not_erase_valid_records(self):
        retained = {}
        valid = sample(2)
        candidates = [sample(1, scenario='orders'), sample(4), valid,
                      sample(2, retries=9), sample(3, timestamps={'screen_ack': 'secret-payload'})]
        self.assertEqual(runner.retain_samples(payload(), candidates, retained), 4)
        self.assertEqual(retained, {2: valid})
        missing = runner.failure_samples(payload(), 'probe_error', retained.values())
        self.assertEqual([record['attempt'] for record in missing], [1, 3])

    def test_arbitrary_probe_fields_never_enter_retained_samples(self):
        retained = {}
        candidate = sample(customer_id='secret-payload', response={'body': 'secret-payload'})
        self.assertEqual(runner.retain_samples(payload(), [candidate], retained), 0)
        self.assertEqual(retained[1], sample())
        self.assertNotIn('secret-payload', json.dumps(retained))

    def test_unavailable_spool_keeps_valid_response_and_requires_reset(self):
        with patch.object(runner, 'contain_probe', return_value=False), \
                patch.object(runner, 'command', side_effect=RuntimeError('secret-payload')):
            records, detail = runner.recover_batch(
                'app', payload(), {'samples': [sample(2)]}, 'probe_error')
        self.assertEqual(records[1], sample(2))
        self.assertEqual([record['outcome'] for record in records], ['unknown', 'success', 'unknown'])
        self.assertFalse(detail['evidence_copied'])
        self.assertFalse(detail['probe_cohort_stopped'])
        self.assertTrue(detail['disposable_reset_required'])
        self.assertNotIn('secret-payload', json.dumps(detail))

    def test_failed_run_report_and_checksums_include_retained_records(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            raw = output / 'samples.jsonl'
            records = [sample(1), *runner.failure_samples(payload(), 'timeout', [sample(1)])]
            raw.write_text(''.join(json.dumps(record) + '\n' for record in records))
            count = runner.write_report(output, raw, {'status': 'failed'}, True)
            report = json.loads((output / 'report.json').read_text())
            checksums = json.loads((output / 'checksums.json').read_text())
            self.assertEqual(count, 3)
            self.assertEqual(report['run_status'], 'failed')
            self.assertEqual(report['cells'][0]['counts']['outcomes']['success'], 1)
            self.assertEqual(checksums['samples.jsonl'], runner.digest(raw))
            self.assertEqual(checksums['report.json'], runner.digest(output / 'report.json'))
            self.assertNotIn('checksums.json', checksums)


if __name__ == '__main__':
    unittest.main()
