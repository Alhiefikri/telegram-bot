import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch


TOOLS = Path(__file__).resolve().parents[2] / 'tools/migration_benchmark'
sys.path.insert(0, str(TOOLS))
spec = importlib.util.spec_from_file_location('checkpoint_runner', TOOLS / 'runner.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


def point(batch=1, count=25, successes=None, completed=True, warmup=False, duration_ms=20, **dimensions):
    payload = dict(mode='batch', dataset='large', profile='current', scenario='home_catalog',
                   users=1, traffic='distinct', temperature='warm', background='representative',
                   batch=batch, warmup=warmup, samples=count)
    payload.update(dimensions)
    payload['evidence_path'] = '/var/www/html/storage/app/private/p04-evidence/run-' + runner.point_name(payload) + '.jsonl'
    if successes is None:
        successes = count
    rows = []
    for attempt in range(1, count + 1):
        success = attempt <= successes
        rows.append(dict(schema_version=1, implementation='laravel', transport='synthetic',
                         **{key: payload[key] for key in runner.CELL_FIELDS}, attempt=attempt,
                         outcome='success' if success else 'unknown', retries=0,
                         timestamps={'ingress': 1_000_000_000, 'screen_ack': 1_000_000_000 + duration_ms * 1_000_000}
                         if success else {}, db_ms=0.25 if success else None,
                         error_code=None if success else 'interrupted'))
    return dict(cell=payload, samples=rows, completed=completed, started_ns=1, finished_ns=2,
                accounting={'evidence_copied': True, 'probe_cohort_stopped': True})


class BenchmarkCheckpointTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.output = Path(self.temporary.name)
        (self.output / 'checkpoints').mkdir()
        self.source_hash = runner.fingerprint({'runner.py': 'frozen'})

    def save(self, value):
        runner.checkpoint(self.output, value, self.source_hash)

    def load(self):
        return runner.load_checkpoints(self.output, self.source_hash)

    def report(self, points, smoke=False):
        runner.rebuild_journals(self.output, points)
        meta = {'schema_version': 2, 'status': 'complete',
                'batches': [runner.batch_summary(value) for value in points]}
        runner.write_report(self.output, self.output / 'samples.jsonl', meta, smoke)
        return json.loads((self.output / 'report.json').read_text())

    def test_committed_phase_survives_missing_aggregate_append(self):
        original = point(count=3)
        self.save(original)
        loaded = self.load()
        runner.rebuild_journals(self.output, loaded)
        self.assertEqual([json.loads(line) for line in (self.output / 'samples.jsonl').read_text().splitlines()], original['samples'])
        accounting = json.loads((self.output / 'accounting.jsonl').read_text())
        self.assertEqual(accounting['cell'], original['cell'])
        self.assertEqual(accounting['accounting'], original['accounting'])

    def test_duplicate_commit_and_resume_do_not_duplicate_attempts(self):
        original = point(count=3)
        self.save(original)
        self.save(original)
        runner.publish_point(self.output, original)
        runner.publish_point(self.output, original)
        runner.rebuild_journals(self.output, self.load())
        runner.rebuild_journals(self.output, self.load())
        self.assertEqual(len((self.output / 'samples.jsonl').read_text().splitlines()), 3)
        self.assertEqual(len((self.output / 'accounting.jsonl').read_text().splitlines()), 1)
        self.assertEqual(len(list((self.output / 'checkpoints').glob('*.json'))), 1)

    def test_conflicting_commit_never_overwrites_original(self):
        original = point(count=3)
        self.save(original)
        path = self.output / 'checkpoints' / runner.point_name(original['cell'])
        before = path.read_bytes()
        with self.assertRaisesRegex(RuntimeError, 'checkpoint_identity_conflict'):
            self.save(point(count=3, successes=2))
        self.assertEqual(path.read_bytes(), before)

    def test_corrupt_checkpoint_stops_before_rebuilding_host_evidence(self):
        original = point(count=3)
        self.save(original)
        path = self.output / 'checkpoints' / runner.point_name(original['cell'])
        envelope = json.loads(path.read_text())
        envelope['point']['samples'][0]['retries'] = 9
        path.write_text(json.dumps(envelope))
        journal = self.output / 'samples.jsonl'
        journal.write_text('retained evidence\n')
        with self.assertRaisesRegex(RuntimeError, 'checkpoint_checksum_mismatch'):
            self.load()
        self.assertEqual(journal.read_text(), 'retained evidence\n')

    def test_partial_checkpoint_temporary_file_is_preserved_not_committed(self):
        original = point(count=3)
        self.save(original)
        torn = self.output / 'checkpoints' / 'interrupted.json.tmp'
        torn.write_text('{"point":')
        self.assertEqual(self.load(), [original])
        self.assertEqual(torn.read_text(), '{"point":')

    def test_source_and_protocol_mismatch_are_refused(self):
        self.save(point(count=3))
        with self.assertRaisesRegex(RuntimeError, 'checkpoint_source_or_protocol_mismatch'):
            runner.load_checkpoints(self.output, 'different-source')
        meta = {'schema_version': 2, 'protocol': runner.PROTOCOL, 'smoke': False,
                'source_sha256': {'runner.py': 'original'}}
        with self.assertRaisesRegex(RuntimeError, 'resume_source_or_protocol_mismatch'):
            runner.validate_resume(meta, False, {'runner.py': 'changed'})
        old = dict(meta, schema_version=1)
        with self.assertRaisesRegex(RuntimeError, 'resume_source_or_protocol_mismatch'):
            runner.validate_resume(old, False, meta['source_sha256'])

    def test_successful_complete_cell_is_skipped_but_other_cell_is_not(self):
        points = [point(batch=batch) for batch in (1, 2)]
        self.assertIsNone(runner.next_batch(points, points[0]['cell'], False))
        other = dict(points[0]['cell'], scenario='orders')
        self.assertEqual(runner.next_batch(points, other, False), 1)

    def test_partial_batch_is_retained_and_next_identity_is_new(self):
        failed = point(batch=1, count=3, successes=1, completed=False)
        self.save(failed)
        self.assertEqual(runner.next_batch(self.load(), failed['cell'], False), 2)
        replacement = point(batch=2, count=3)
        self.save(replacement)
        runner.rebuild_journals(self.output, self.load())
        rows = [json.loads(line) for line in (self.output / 'samples.jsonl').read_text().splitlines()]
        self.assertEqual(len(rows), 6)
        self.assertEqual([row['outcome'] for row in rows if row['batch'] == 1], ['success', 'unknown', 'unknown'])

    def test_warmup_only_interruption_does_not_continue_on_a_fresh_db(self):
        warmup = point(batch=4, count=10, warmup=True)
        self.assertEqual(runner.next_batch([warmup], warmup['cell'], False), 5)

    def test_failed_or_unstable_observations_do_not_expand_sampling_budget(self):
        for successes, duration in ((0, 20), (25, 40)):
            with self.subTest(successes=successes):
                points = [point(batch=1), point(batch=2, successes=successes, duration_ms=duration)]
                self.assertIsNone(runner.next_batch(points, points[0]['cell'], False))
                report = self.report(points)
                cell = report['cells'][0]
                self.assertTrue(cell['sampling']['completed'])
                self.assertEqual(cell['gate']['status'], 'inconclusive')
                self.assertEqual(cell['counts']['outcomes']['unknown'], 25 - successes)

    def test_partial_batch_cannot_complete_independent_sampling(self):
        points = [point(batch=1), point(batch=2, completed=False)]
        cell = self.report(points)['cells'][0]
        self.assertEqual(cell['completed_batch_count'], 1)
        self.assertFalse(cell['sampling']['completed'])
        self.assertEqual(cell['gate']['status'], 'inconclusive')
        self.assertIn('incomplete_independent_batches', cell['gate']['reasons'])

    def test_other_conditions_cannot_cover_missing_primary_cases(self):
        points = [point(batch=batch, dataset='small') for batch in (1, 2)]
        report = self.report(points)
        cell = report['cells'][0]
        self.assertEqual(cell['gate']['status'], 'not_applicable')
        self.assertEqual(report['coverage']['observed_primary_cells'], 0)
        expected_primary = ['large', 'current', 'home_catalog', 1, 'distinct', 'warm', 'representative']
        self.assertIn(expected_primary, report['coverage']['missing_primary_cells'])

    def test_smoke_cannot_promote_even_complete_primary_shaped_samples(self):
        points = [point(batch=batch) for batch in (1, 2)]
        report = self.report(points, smoke=True)
        self.assertEqual(report['cells'][0]['measurement_role'], 'diagnostic')
        self.assertEqual(report['cells'][0]['gate']['status'], 'not_applicable')
        self.assertEqual(report['coverage']['expected_primary_cells'], 0)
        self.assertEqual(report['coverage']['exploratory_primary_cells'], 0)

    def test_first_phase_stopped_container_spool_is_recovered_before_any_reset(self):
        original = point(count=3)
        spool = json.dumps(original['samples'][0]) + '\n{"attempt":'
        calls = []

        def command(args, **kwargs):
            calls.append(args)
            if args[1] == 'inspect':
                return 'false'
            if args[1] == 'cp':
                Path(args[-1]).write_text(spool)
                return ''
            self.fail('unexpected runtime mutation')

        meta = {'active': {'cell': original['cell'], 'app': 'owned-stopped-app', 'started_ns': 1},
                'batches': [], 'runner_failures': []}
        points = []
        with patch.object(runner, 'command', side_effect=command):
            runner.recover_pending(self.output, meta, points, self.source_hash)
        self.assertIsNone(meta['active'])
        self.assertEqual([row['outcome'] for row in points[0]['samples']], ['success', 'unknown', 'unknown'])
        self.assertFalse(points[0]['completed'])
        self.assertIsNone(points[0]['finished_ns'])
        self.assertEqual(self.load(), points)
        archived = list((self.output / 'recovered-spools').glob('*.jsonl'))
        self.assertEqual(archived[0].read_text(), spool)
        self.assertEqual(runner.next_batch(points, original['cell'], False), 2)
        self.assertTrue(all(args[1] in ('inspect', 'cp') for args in calls))

    def test_committed_active_phase_is_not_recovered_twice(self):
        original = point(count=3)
        self.save(original)
        meta = {'active': {'cell': original['cell'], 'app': 'app', 'started_ns': 1}, 'runner_failures': []}
        with patch.object(runner, 'command') as command:
            runner.recover_pending(self.output, meta, self.load(), self.source_hash)
        command.assert_not_called()
        self.assertIsNone(meta['active'])
        self.assertEqual(len(self.load()), 1)

    def test_non_protocol_dataset_is_refused_before_database_access(self):
        with patch.object(runner, 'command') as command:
            with self.assertRaises(KeyError):
                runner.clone_database({'database': 'owned-db'}, 'production')
        command.assert_not_called()


if __name__ == '__main__':
    unittest.main()
