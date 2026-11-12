"""Balance operations alert snapshot rendering parity."""
import unittest

class BalanceOperationsAlertSnapshotTests(unittest.TestCase):
    def test_python_supported_alert_keys_retain_reorg_safety(self):
        from lhie_candidate.usdt.operations_controls import _ALERTS

        self.assertIn('reorg_pending', _ALERTS)
        self.assertIn('reorg_loss_unresolved', _ALERTS)
        self.assertIn('scheduler_runtime_failure', _ALERTS)
        self.assertIn('telegram_recovery_runtime_failure', _ALERTS)
    def test_runtime_alert_copy_and_reason_allowlist(self):
        from lhie_candidate.usdt.operations_controls import runtime_alert_summary

        self.assertEqual(runtime_alert_summary('scheduler', False, 'oom_kill'),
                         ('scheduler_runtime_failure',
                          'Scheduler gagal: cgroup mencatat proses scheduler terkena OOM-kill.'))
        self.assertEqual(runtime_alert_summary('outbound-recovery', False, 'recovery_timeout'),
                         ('telegram_recovery_runtime_failure',
                          'Recovery outbound Telegram gagal: proses recovery melewati batas waktu eksekusi.'))
        self.assertEqual(runtime_alert_summary('scheduler', True),
                         ('scheduler_runtime_failure', 'Kondisi runtime pulih.'))
        for component, healthy, reason in (('scheduler', False, 'recovery_timeout'),
                                           ('unknown', False, 'oom_kill'),
                                           ('scheduler', True, 'oom_kill')):
            with self.subTest(component=component, reason=reason), self.assertRaises(ValueError):
                runtime_alert_summary(component, healthy, reason)

    def test_open_and_updated_alert_copy_matches_php(self):
        from lhie_candidate.usdt.operations_controls import _open_message

        summary = 'Akun saldo USDT dibekukan: 2 akun.'
        self.assertEqual(_open_message('critical', summary), '[KRITIS] ' + summary)
        self.assertEqual(_open_message('warning', summary), '[PERINGATAN] ' + summary)
        self.assertEqual(_open_message('critical', 'Treasury coverage changed.'),
                         '[KRITIS] Treasury coverage changed.')

    def test_resolved_snapshot_uses_the_episode_summary(self):
        from lhie_candidate.usdt.operations_controls import _resolved_message

        summary = 'Pengiriman bukti saldo USDT dijeda oleh Owner (scope delivery).'
        self.assertEqual(_resolved_message(summary), '[SELESAI] Kondisi pulih: ' + summary)

    def test_delivery_uses_the_materialized_snapshot(self):
        from lhie_candidate.usdt.alerts_outbound import alert_payload

        class DB:
            def one(self, query, params):
                self.params = params
                return {'id': params[0]}

        db = DB()
        effect = {
            'source_type': 'App\\Models\\BalanceOperationsAlertEpisode',
            'source_id': 'episode-1',
            'target_type': 'admin',
            'template_version': 'balance-operations-alert-v1',
            'telegram_method': 'sendMessage',
            'telegram_chat_id': '1',
            'outcome_metadata': {'message': '[KRITIS] Original snapshot'},
        }
        self.assertEqual(alert_payload(db, effect), {
            'chat_id': '1', 'text': '[KRITIS] Original snapshot'})
        self.assertEqual(db.params, ('episode-1',))


if __name__ == '__main__':
    unittest.main()
