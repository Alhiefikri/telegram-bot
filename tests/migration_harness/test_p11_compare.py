import copy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest


TOOLS = Path(__file__).resolve().parents[2] / 'tools/migration_benchmark'
sys.path.insert(0, str(TOOLS))
spec = importlib.util.spec_from_file_location('benchmark_compare', TOOLS / 'compare.py')
compare = importlib.util.module_from_spec(spec)
spec.loader.exec_module(compare)
PROTOCOL = json.loads((TOOLS / 'protocol_p11.json').read_text())


def cell(implementation, scenario, users, p50, p95, *, gate='exploratory', completed=True):
    return {
        'dimensions': {'implementation': implementation, 'profile': 'current',
                       'transport': 'synthetic', 'dataset': 'large', 'scenario': scenario,
                       'users': users, 'traffic': 'distinct', 'temperature': 'warm',
                       'background': 'idle'},
        'counts': {'measured': 50, 'batches': 2,
                   'outcomes': {'success': 50, 'error': 0, 'timeout': 0, 'unknown': 0}},
        'database': {'successful': {'p50_ms': 1.0, 'p95_ms': 2.0}},
        'boundaries': {'screen_ack': {'successful': {
            'observed': 50, 'p50_ms': p50, 'p95_ms': p95,
            'batch_p95_range_ms': [p50, p95]}}},
        'gate': {'status': gate, 'reasons': [] if gate == 'exploratory' else ['unstable_batch_p95']},
        'sampling': {'completed': completed},
    }


SOURCES = {'src/app.php': 'a', 'tools/migration_benchmark/probe.php': 'b',
           'tools/migration_benchmark/candidate_probe.py': 'c'}


def run(directory, implementation, cells, protocol, *, authority=None, status='complete',
        sources=None):
    directory.mkdir(parents=True, exist_ok=True)
    run_meta = {
        'schema_version': 2, 'protocol_file': 'p11', 'implementation': implementation,
        'protocol_sha256': 'protocol', 'protocol': protocol, 'smoke': False,
        'status': status, 'revision': 'abc', 'host': {'kernel': 'test', 'cpu_count': 4},
        'dataset_contract': {'seed': 686}, 'limits': protocol['excluded_claims'],
        'dataset_logical_sha256': {'large': 'seed-sha'},
        'postgres_settings': [{'max_connections': '30', 'shared_buffers': '128MB',
                               'synchronous_commit': 'on', 'fsync': 'on',
                               'full_page_writes': 'on', 'TimeZone': 'UTC'}],
        'source_sha256': dict(SOURCES if sources is None else sources), 'batches': [],
    }
    (directory / 'run.json').write_text(json.dumps(run_meta))
    (directory / 'report.json').write_text(json.dumps({'cells': cells}))
    records = []
    if authority is not None:
        records.append({'cell': {'batch': 1}, 'accounting': {
            'authority_audit': {'changed_tables': [], 'violations': authority}}})
    (directory / 'accounting.jsonl').write_text(
        ''.join(json.dumps(record) + '\n' for record in records))


def findings(path, values=()):
    path.write_text(json.dumps({'review': ['synthetic review'], 'findings': list(values)}))


class PairedComparisonTest(unittest.TestCase):
    def build(self, php_cells, django_cells, *, protocol=None, authority=0, security=(),
              php_changes=None, php_sources=None):
        protocol = protocol or copy.deepcopy(PROTOCOL)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            php = root / 'php'
            django = root / 'django'
            run(php, 'laravel', php_cells, protocol, status='complete', sources=php_sources)
            run(django, 'django', django_cells, protocol, authority=authority)
            if php_changes:
                meta = json.loads((php / 'run.json').read_text())
                meta.update(php_changes)
                (php / 'run.json').write_text(json.dumps(meta))
            review = root / 'findings.json'
            findings(review, security)
            return compare.build(php, django, review)

    def all_cells(self, implementation, table):
        return [cell(implementation, scenario, users, *table[scenario])
                for scenario in PROTOCOL['navigation'] for users in PROTOCOL['users']]

    def test_every_case_is_judged_independently_at_seventy_percent(self):
        table = {scenario: (10.0, 20.0) for scenario in PROTOCOL['navigation']}
        table['orders'] = (10.0, 20.0)
        table['balance'] = (10.0, 20.0)
        django_table = {scenario: (6.0, 14.0) for scenario in PROTOCOL['navigation']}
        protocol = copy.deepcopy(PROTOCOL)
        protocol['candidate_unimplemented_scenarios'] = []
        report = self.build(self.all_cells('laravel', table),
                            self.all_cells('django', django_table), protocol=protocol)
        self.assertEqual(report['summary']['paired_cases'], 24)
        self.assertEqual(report['summary']['pass'], 24)
        self.assertEqual(report['verdict']['decision'], 'continue')
        for case in report['cases']:
            self.assertEqual(case['ratio']['p50'], 0.6)
            self.assertEqual(case['ratio']['p95'], 0.7)
            self.assertEqual(case['verdict'], 'pass')
        # A failed balance-like pair cannot hide behind faster cases.
        django_table['balance'] = (9.0, 14.0)
        report = self.build(self.all_cells('laravel', table),
                            self.all_cells('django', django_table), protocol=protocol)
        self.assertEqual(report['summary']['fail'], 3)
        self.assertEqual(report['summary']['pass'], 21)
        self.assertEqual(report['verdict']['decision'], 'fix')

    def test_declared_candidate_gaps_are_not_implemented_not_fabricated(self):
        table = {scenario: (10.0, 20.0) for scenario in PROTOCOL['navigation']}
        cells = [cell('laravel', scenario, users, *table[scenario])
                 for scenario in PROTOCOL['navigation'] for users in PROTOCOL['users']]
        implemented = [dict(item, dimensions={**item['dimensions'], 'implementation': 'django'})
                       for item in cells if item['dimensions']['scenario']
                       not in PROTOCOL['candidate_unimplemented_scenarios']]
        report = self.build(cells, implemented)
        self.assertEqual(report['summary']['not_implemented'], 3)
        self.assertEqual(report['summary']['paired_cases'], 21)
        self.assertEqual(report['summary']['candidate_coverage_fraction'], 1.0)
        gaps = [case for case in report['cases'] if case['verdict'] == 'not_implemented']
        self.assertEqual({case['scenario'] for case in gaps}, {'balance'})
        self.assertEqual(report['verdict']['decision'], 'fix')
        self.assertIn('candidate_feature_absent', report['verdict']['reasons'])

    def test_inconclusive_evidence_never_counts_as_a_pass(self):
        table = {scenario: (10.0, 20.0) for scenario in PROTOCOL['navigation']}
        protocol = copy.deepcopy(PROTOCOL)
        protocol['candidate_unimplemented_scenarios'] = []
        cells = self.all_cells('laravel', table)
        django = self.all_cells('django', {scenario: (5.0, 10.0)
                                           for scenario in PROTOCOL['navigation']})
        django[0]['gate'] = {'status': 'inconclusive', 'reasons': ['unstable_batch_p95']}
        report = self.build(cells, django, protocol=protocol)
        self.assertEqual(report['summary']['inconclusive'], 1)
        self.assertEqual(report['summary']['pass'], 23)
        self.assertEqual(report['verdict']['decision'], 'fix')
        self.assertIn('inconclusive_evidence', report['verdict']['reasons'])

    def test_pairing_refuses_mismatched_dataset_or_protocol_identity(self):
        table = {scenario: (10.0, 20.0) for scenario in PROTOCOL['navigation']}
        cells = self.all_cells('laravel', table)
        with self.assertRaises(compare.Mismatch):
            self.build(cells, cells, php_changes={'dataset_logical_sha256': {'large': 'other'}})
        with self.assertRaises(compare.Mismatch):
            self.build(cells, cells, php_changes={'implementation': 'django'})
        with self.assertRaises(compare.Mismatch):
            self.build(cells, cells, php_changes={'smoke': True})

    def test_authority_violation_or_critical_finding_stops(self):
        table = {scenario: (10.0, 20.0) for scenario in PROTOCOL['navigation']}
        protocol = copy.deepcopy(PROTOCOL)
        protocol['candidate_unimplemented_scenarios'] = []
        report = self.build(self.all_cells('laravel', table),
                            self.all_cells('django', {s: (5.0, 10.0)
                                                      for s in PROTOCOL['navigation']}),
                            protocol=protocol, authority=1)
        self.assertEqual(report['verdict']['decision'], 'stop')
        self.assertIn('authority_violation', report['verdict']['reasons'])
        report = self.build(self.all_cells('laravel', table),
                            self.all_cells('django', {s: (5.0, 10.0)
                                                      for s in PROTOCOL['navigation']}),
                            protocol=protocol,
                            security=[{'severity': 'high', 'status': 'open'}])
        self.assertEqual(report['verdict']['decision'], 'stop')

    def test_reused_php_baseline_allows_only_non_measurement_tooling_delta(self):
        table = {scenario: (10.0, 20.0) for scenario in PROTOCOL['navigation']}
        protocol = copy.deepcopy(PROTOCOL)
        protocol['candidate_unimplemented_scenarios'] = []
        report = self.build(self.all_cells('laravel', table),
                            self.all_cells('django', {s: (5.0, 10.0)
                                                      for s in PROTOCOL['navigation']}),
                            protocol=protocol,
                            php_sources={**SOURCES,
                                         'tools/migration_benchmark/runner.py': 'older'})
        self.assertFalse(report['identity']['source_sha256_equal'])
        self.assertEqual(report['identity']['source_delta'],
                         ['tools/migration_benchmark/runner.py'])
        self.assertEqual(report['identity']['php_measurement_path_delta'], [])
        self.assertTrue(report['baseline_reuse']['php_baseline_reused'])
        self.assertEqual(report['verdict']['decision'], 'continue')
        with self.assertRaises(compare.Mismatch):
            self.build(self.all_cells('laravel', table),
                       self.all_cells('django', {s: (5.0, 10.0)
                                                 for s in PROTOCOL['navigation']}),
                       protocol=protocol,
                       php_sources={**SOURCES,
                                    'tools/migration_benchmark/probe.php': 'changed'})

    def test_missing_authority_audit_blocks_a_continue(self):
        table = {scenario: (10.0, 20.0) for scenario in PROTOCOL['navigation']}
        protocol = copy.deepcopy(PROTOCOL)
        protocol['candidate_unimplemented_scenarios'] = []
        report = self.build(self.all_cells('laravel', table),
                            self.all_cells('django', {s: (5.0, 10.0)
                                                      for s in PROTOCOL['navigation']}),
                            protocol=protocol, authority=None)
        self.assertEqual(report['verdict']['decision'], 'fix')
        self.assertIn('authority_audit_missing', report['verdict']['reasons'])

    def test_payment_stage_proposal_is_php_only_and_unratified(self):
        table = {scenario: (10.0, 20.0) for scenario in PROTOCOL['navigation']}
        cells = self.all_cells('laravel', table)
        report = self.build(cells, [])
        stages = report['payment_stages']
        self.assertEqual(stages['status'], 'php_only_diagnostic')
        self.assertEqual(stages['candidate_counterpart'], 'not_implemented')
        self.assertEqual(stages['budget_proposal']['status'], 'proposed_unratified')
        self.assertTrue(stages['budget_proposal']['anchors'])


class ProtocolP11ContractTest(unittest.TestCase):
    def test_declared_scope_matches_the_paired_plan(self):
        navigation = set(PROTOCOL['navigation'])
        other = set(PROTOCOL['other_scenarios'])
        gaps = set(PROTOCOL['candidate_unimplemented_scenarios'])
        self.assertEqual(set(PROTOCOL['implementations']), {'laravel', 'django'})
        self.assertEqual(PROTOCOL['schema_version'], 5)
        self.assertTrue(gaps <= navigation)
        self.assertTrue(set(PROTOCOL['candidate_unimplemented_other_scenarios']) <= other)
        self.assertEqual(PROTOCOL['primary_conditions'],
                         {'dataset': 'large', 'temperature': 'warm',
                          'traffic': 'distinct', 'background': 'idle'})
        self.assertEqual(PROTOCOL['planned_cases']['per_implemented_navigation_scope'],
                         len(navigation) * len(PROTOCOL['users']))
        self.assertEqual(PROTOCOL['planned_cases']['candidate_implementable'],
                         (len(navigation) - len(gaps)) * len(PROTOCOL['users']))
        self.assertEqual(PROTOCOL['planned_cases']['candidate_unimplementable'],
                         len(gaps) * len(PROTOCOL['users']))
        self.assertIn('representative', PROTOCOL['background_policy'])
        self.assertIn('resource_sample_interval_seconds', PROTOCOL)

    def test_runner_plans_only_the_declared_candidate_scope(self):
        spec = importlib.util.spec_from_file_location(
            'benchmark_runner_p11', TOOLS / 'runner.py')
        runner = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(runner)
        runner.PROTOCOL = PROTOCOL
        cells = list(runner.planned_cells(False, 'django'))
        self.assertEqual(len(cells), 21)
        self.assertEqual({cell['scenario'] for cell in cells},
                         set(PROTOCOL['navigation']) - set(PROTOCOL['candidate_unimplemented_scenarios']))
        self.assertEqual({cell['users'] for cell in cells if cell['scenario'] == 'orders'},
                         {1, 5, 10})
        self.assertTrue(all(cell['background'] == 'idle' for cell in cells))
        self.assertTrue(all(runner.measurement_role(cell) == 'primary' for cell in cells))
        php = list(runner.planned_cells(False, 'laravel'))
        self.assertEqual(len(php), 24)
        self.assertEqual({cell['implementation'] for cell in php}, {'laravel'})


if __name__ == '__main__':
    unittest.main()
