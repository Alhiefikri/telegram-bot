from datetime import datetime, timezone
from types import SimpleNamespace
from unittest.mock import patch

from lhie_candidate.storefront import balance_idr


def test_cursor_uses_laravel_jakarta_epoch_and_rank():
    row = {'created_at': datetime(2026, 1, 1, 7, 0, 0, 123456), 'rank': 2, 'id': 9}
    expected = int(datetime(2026, 1, 1, tzinfo=timezone.utc).timestamp()) * 1_000_000 + 123456
    assert balance_idr._cursor(row) == (expected, 2, 9)


def test_topup_credited_only_when_matching_ledger_credit():
    row = {'status': 'paid', 'provider': 'sakurupiah', 'provider_create_state': 'provider_berhasil', 'amount': 100}
    assert balance_idr._topup_state(row, {'amount': 100}) == ('recovered', True)
    assert balance_idr._topup_state(row, {'amount': 99}) == ('review', False)
    assert balance_idr._topup_state(row, None) == ('review', False)


def test_no_account_still_reads_topups_and_validates_anchor():
    class DB:
        def one(self, sql, params):
            return None

        def rows(self, sql, params):
            assert 'store_balance_top_ups' in sql
            return [{'id': 2, 'created_at': datetime(2026, 1, 1, 7), 'amount': 1000,
                     'status': 'awaiting_confirmation', 'provider': 'manual',
                     'provider_create_state': None, 'expires_at': None,
                     'provider_expires_at': None, 'rank': 1}]

    with patch.object(balance_idr, 'Database', DB):
        page = balance_idr.read_page(1)
        assert page['entries'][0]['amount_display'] == 'Nominal Rp 1.000'
        assert page['entries'][0]['state'] == 'Menunggu'
        assert page['older'] is None and page['newer'] is None
        assert balance_idr.read_page(1, direction='older', cursor=(1, 1, 5)) is None
        assert balance_idr.read_page(1, direction='older') is None


def test_klik_payable_requires_valid_local_quote_and_admitted_rail():
    expires = datetime(2099, 1, 1)
    url = 'https://klikqris.com/storage/sandbox/qris_test.png'
    config = SimpleNamespace(environment='sandbox', allowed_hosts=())
    cipher = SimpleNamespace(decrypt_string=lambda value: value)
    owner = SimpleNamespace(lifecycles={'klikqris': SimpleNamespace(adapter=SimpleNamespace(config=config))},
                            encrypter=cipher)
    row = {'status': 'payable', 'provider': 'klikqris', 'provider_create_state': 'created',
           'currency': 'IDR', 'provider_payment_method': 'QRIS', 'amount': 100,
           'merchant_ref': 'quote-1', 'provider_reference': None, 'provider_fee': 5,
           'provider_total_payment': 105, 'provider_qris_url': url,
           'expires_at': expires, 'provider_expires_at': expires,
           'provider_payload': {'environment': 'sandbox', 'data': {
               'order_id': 'quote-1', 'amount': 100, 'total_amount': 105,
               'status': 'PENDING'}}}
    assert balance_idr._topup_state(row, None) == ('review', False)
    assert balance_idr._klik_quote_valid(row, owner)
    row['quote_valid'] = True
    assert balance_idr._topup_state(row, None) == ('pending', False)
    assert not balance_idr._klik_quote_valid(dict(row, provider_qris_url='https://evil.invalid/x.png'), owner)
    assert not balance_idr._klik_quote_valid(dict(row, provider_total_payment=106), owner)
    assert not balance_idr._klik_quote_valid(dict(row, provider_payload={'environment': 'production'}), owner)
    assert not balance_idr._klik_quote_valid(row, SimpleNamespace(lifecycles={}, encrypter=cipher))
    p15 = dict(row, id='synthetic-id', provider_payload={'environment': 'sandbox'})
    assert balance_idr._klik_quote_valid(p15, owner)
    assert not balance_idr._klik_quote_valid(dict(p15, provider_qris_url='https://evil.invalid/x.png'), owner)
    assert not balance_idr._klik_quote_valid(dict(p15, provider_total_payment=106), owner)
