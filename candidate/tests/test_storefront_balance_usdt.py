from datetime import datetime
from unittest.mock import patch

from lhie_candidate.storefront import balance_usdt


class Repository:
    def __init__(self):
        self.targets = []
        self.entries = []
        self.present = True
        self.queries = []

    def _one(self, sql, params):
        self.queries.append((sql, params))
        return {'present': 1} if self.present else None

    def _rows(self, sql, params):
        self.queries.append((sql, params))
        rows = self.targets if 'FROM public.customer_usdt_top_ups t' in sql else self.entries
        if len(params) > 1:
            stamp, rank, item_id = params[1:]
            key = (stamp, rank, item_id)
            newer = ' > ' in sql
            rows = [row for row in rows if ((row['created_at'], row['rank'], row['item_id']) > key) == newer
                    and (row['created_at'], row['rank'], row['item_id']) != key]
        return sorted(rows, key=lambda row: (row['created_at'], row['rank'], row['item_id']),
                      reverse=' DESC' in sql)[:26]


def row(item_id, rank=2, kind='funding_credit', amount=1_500_001):
    return {'created_at': datetime(2026, 2, 1, 10, 30), 'rank': rank, 'item_id': item_id,
            'kind': kind, 'amount_micro': amount, 'reference': f'#{item_id}'}


def test_page_merges_ties_and_walks_both_directions():
    repo = Repository()
    repo.entries = [row(i) for i in range(1, 31)]
    repo.targets = [row(100, 1, 'top_up')]
    with patch.object(balance_usdt, 'CatalogRepository', return_value=repo):
        first = balance_usdt.read_page(7)
        second = balance_usdt.read_page(7, direction='older', cursor=first['older'])
        back = balance_usdt.read_page(7, direction='newer', cursor=second['newer'])
    assert len(first['entries']) == 25
    assert first['entries'][0]['reference'] == '#30'
    assert second['entries'][-1]['kind'] == 'Top-up'
    assert second['entries'][-1]['amount_display'] == 'Nominal 1.500001 USDT'
    assert back['entries'] == first['entries']
    assert back['newer'] is None
    assert first['older'][0] == 1769916600000000
    assert all('manual_review' not in sql for sql, _ in repo.queries)


def test_labels_amounts_and_customer_scoped_invalid_cursor():
    repo = Repository()
    repo.entries = [row(1, kind='purchase_debit', amount=2_000_000),
                    row(2, kind='refund_credit', amount=123_400),
                    row(3, kind='reversal_debit', amount=1)]
    with patch.object(balance_usdt, 'CatalogRepository', return_value=repo):
        page = balance_usdt.read_page(7)
        assert [item['amount_display'] for item in page['entries']] == [
            '−0.000001 USDT', '+0.1234 USDT', '−2 USDT']
        assert [item['state'] for item in page['entries']] == ['Dibalik', 'Dikembalikan', 'Didebit']
        assert page['entries'][0]['occurred_at'] == '01 Feb 2026 10:30'
        assert balance_usdt.read_page(7, direction='older', cursor=(1, 3, 1)) is None
        repo.present = False
        assert balance_usdt.read_page(7, direction='older', cursor=(1769916600000000, 2, 1)) is None
        assert repo.queries[-1][1][0] == 7
