from datetime import datetime, timedelta
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from django.template import Context, Engine

from lhie_candidate.notifications.mail import DefiniteMailFailure
from lhie_candidate.notifications.waitlist import Waitlist, WaitlistLinks, batch_size, safe_name


class WaitlistTests(unittest.TestCase):
    def setUp(self):
        self.at = datetime(2026, 11, 8, 12)
        self.links = WaitlistLinks('synthetic-test-key')

    def test_links_bind_origin_object_purpose_expiry_and_canonical_query(self):
        url = self.links.create('confirm', 12, self.at + timedelta(hours=1))
        self.assertTrue(self.links.valid(url, 'confirm', 12, self.at))
        for changed in (url.replace('/confirm/', '/unsubscribe/'), url.replace('/12?', '/13?'),
                        url.replace('127.0.0.1', 'localhost'), url + '&expires=9999999999',
                        url + '#fragment', url.replace('expires=', '%65xpires='), url + '&extra=1'):
            self.assertFalse(self.links.valid(changed, 'confirm', 12, self.at))
        self.assertFalse(self.links.valid(url, 'unsubscribe', 12, self.at))
        self.assertFalse(self.links.valid(url, 'confirm', 13, self.at))
        self.assertFalse(self.links.valid(url, 'confirm', 12, self.at + timedelta(hours=2)))

    def test_default_owner_is_disabled_without_database_or_transport_writes(self):
        owner = Waitlist(Mock(), 'synthetic-key')
        owner.db = SimpleNamespace(one=Mock(), execute=Mock())
        owner._outside = Mock()
        self.assertFalse(owner.subscribe(1, 'test@example.invalid'))
        self.assertEqual(owner.resolve(1, 'confirm', 'anything'), 'expired_link')
        self.assertEqual(owner.confirm_mail(1), 'disabled')
        self.assertEqual(owner.deliver(1), 'disabled')
        self.assertEqual(owner.materialize(1), 0)
        self.assertEqual(owner.recover(), {'disabled': True})
        owner.db.one.assert_not_called()
        owner.db.execute.assert_not_called()

    def test_scan_bounds_reject_booleans_and_unbounded_values(self):
        for value in (True, 0, -1, 101, 1.0, '25'):
            with self.assertRaises(ValueError):
                batch_size(value)
        self.assertEqual(batch_size(100), 100)

    def test_mail_outcomes_do_not_treat_transport_exceptions_as_definite_failure(self):
        transport = SimpleNamespace(send=Mock())
        owner = Waitlist(Mock(), 'synthetic-key', transport=transport)
        with patch('lhie_candidate.notifications.waitlist.render_to_string', return_value='<p>test</p>'):
            for error, expected in ((DefiniteMailFailure(), 'failed'), (TimeoutError('private text'), 'unknown')):
                transport.send.side_effect = error
                self.assertEqual(owner._send('test@example.invalid', 'subject', 'text', 'template', {}), expected)
            transport.send.side_effect = None
            self.assertEqual(owner._send('test@example.invalid', 'subject', 'text', 'template', {}), 'sent')
            self.assertEqual(transport.send.call_args.kwargs, {'html': '<p>test</p>'})
        transport.send.reset_mock()
        with patch('lhie_candidate.notifications.waitlist.render_to_string', side_effect=ValueError()):
            self.assertEqual(owner._send('test@example.invalid', 'subject', 'text', 'template', {}), 'failed')
        transport.send.assert_not_called()

    def test_templates_escape_product_and_urls_and_preserve_php_layout(self):
        root = Path(__file__).resolve().parents[1] / 'templates'
        engine = Engine(dirs=[str(root)])
        html = engine.get_template('notifications/waitlist_confirmation.html').render(
            Context({
                'product_name': '<script>private</script>', 'confirmation_url': 'http://127.0.0.1/?a=1&b=2'}))
        self.assertNotIn('<script>', html)
        self.assertIn('&lt;script&gt;', html)
        self.assertIn('?a=1&amp;b=2', html)
        self.assertIn('box-shadow:5px 5px 0 #161616', html)
        self.assertEqual(safe_name('  Product\r\nname  '), 'Product name')


if __name__ == '__main__':
    unittest.main()
