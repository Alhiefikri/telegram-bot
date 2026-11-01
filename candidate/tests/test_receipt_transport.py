import json
import unittest

from lhie_candidate.receipt.transport import ReceiptPreSendError, ReceiptResult, RelayTransport, classify


class ReceiptTransportTests(unittest.TestCase):
    def test_only_acknowledged_message_identity_is_success(self):
        self.assertEqual(classify({'ok': True, 'result': {'message_id': 42}}).outcome, 'success')
        for identity in (None, 0, True, '42'):
            with self.subTest(identity=identity):
                self.assertEqual(classify({'ok': True, 'result': {'message_id': identity}}).outcome, 'ambiguous')

    def test_definite_rejection_and_ambiguous_failures_remain_distinct(self):
        rate_limit = classify({'ok': False, 'error_code': 429, 'parameters': {'retry_after': 12}})
        self.assertEqual((rate_limit.outcome, rate_limit.error_code, rate_limit.retry_after_seconds),
                         ('definite', 'telegram_http_429', 12))
        self.assertEqual(classify({'ok': False, 'error_code': 400}).outcome, 'definite')
        for response in ({'ok': False, 'error_code': 408}, {'ok': False, 'error_code': 503}, {}, None):
            with self.subTest(response=response):
                self.assertEqual(classify(response).outcome, 'ambiguous')

    def test_invalid_attachment_is_rejected_before_any_send(self):
        with self.assertRaises(ReceiptPreSendError):
            RelayTransport().execute('sendDocument', {'chat_id': '1', 'document': b'', 'filename': 'x.txt'})

    def test_unicode_retry_requires_explicit_emoji_rejection(self):
        class Transport(RelayTransport):
            def __init__(self, responses):
                self.responses, self.requests = iter(responses), []

            def _post(self, request):
                self.requests.append(json.loads(request))
                return next(self.responses)

        custom = {'type': 'custom_emoji', 'offset': 0, 'length': 2, 'custom_emoji_id': '123'}
        code = {'type': 'code', 'offset': 3, 'length': 4}
        payload = {'chat_id': '1', 'text': '🟢 copy', 'entities': [custom, code]}
        rejected = ReceiptResult(None, 'definite', 'telegram_http_400', None, True)
        transport = Transport([rejected, ReceiptResult(42, 'success')])
        self.assertEqual(transport.execute('sendMessage', payload).message_id, 42)
        self.assertEqual(transport.requests[1]['payload']['text'], payload['text'])
        self.assertEqual(transport.requests[1]['payload']['entities'], [code])
        self.assertEqual(payload['entities'], [custom, code])
        uncertain = Transport([ReceiptResult(None, 'ambiguous')])
        self.assertEqual(uncertain.execute('sendMessage', payload).outcome, 'ambiguous')
        self.assertEqual(len(uncertain.requests), 1)


if __name__ == '__main__':
    unittest.main()
