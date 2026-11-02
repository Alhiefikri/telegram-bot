import copy
from collections import Counter
from datetime import datetime, timedelta
import unittest
from zoneinfo import ZoneInfo

from lhie_candidate.navigation.presentation import CatalogPresenter
from lhie_candidate.navigation.repository import (
    BALANCE_ACTIVITY_PAGE_SIZE, CatalogRepository, ORDER_HISTORY_PAGE_SIZE,
    USDT_ACTIVITY_ROWS)


CUSTOMER = {'id': 1, 'telegram_id': 41001, 'first_name': 'Ayu', 'username': 'ayu', 'locale': 'id'}


def product(identity, name, *, family=None, stock=3, price=25000, **values):
    return dict(id=identity, name=name, product_family_id=family, category_id=None,
                family_sort_order=identity, family_option_label=None, available_stock=stock,
                price_amount=price, usdt_price_amount=None, sold_quantity=0, icon=None,
                custom_emoji_id=None, description='Details <plain>', warranty_policy='',
                telegram_content={}, telegram_image_path=None, **values)


def order(identity, invoice, *, created_token, order_status='completed',
          payment_status='paid', receipt_sent=True, customer_id=1):
    return dict(id=f'00000000-0000-4000-8000-{identity:012d}', customer_id=customer_id,
                invoice_number=invoice, order_status=order_status, payment_status=payment_status,
                created_token=created_token, receipt_sent=receipt_sent)


def balance_entry(identity, kind, amount, when, *, source_type='fixture', source_id=None,
                  refund_order_id=None):
    return dict(id=identity, kind=kind, amount=amount,
                occurred_at=when.strftime('%Y-%m-%dT%H:%M:%S.%f'),
                occurred_micros=int(when.replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp() * 1_000_000),
                source_type=source_type, source_id=source_id or str(identity),
                refund_order_id=refund_order_id)


def usdt_entry(identity, kind, amount, when, *, source_type='fixture', source_id=None,
               refund_order_id=None):
    return dict(id=identity, kind=kind, amount_micro=amount,
                occurred_at=when.strftime('%Y-%m-%dT%H:%M:%S.%f'),
                occurred_micros=int(when.replace(tzinfo=ZoneInfo('Asia/Jakarta')).timestamp() * 1_000_000),
                source_type=source_type, source_id=source_id or str(identity),
                refund_order_id=refund_order_id)


class MemoryCatalog(CatalogRepository):
    def __init__(self):
        self.product_rows = [product(1,'Basic',family=10,stock=0), product(2,'Premium',family=10),
                             product(3,'Spotify',family=20,stock=5),product(4,'Standalone',stock=0)]
        self.family_rows = [dict(id=10,name='Netflix',slug='netflix',sort_order=1,icon='🎬',custom_emoji_id='100',description=''),
                            dict(id=20,name='Old Family Label',slug='spotify',sort_order=2,icon='🎵',custom_emoji_id='200',description='')]
        self.category_rows = []
        self.icon_rows = [dict(key='message.search',source_emoji='🔎',fallback_emoji='🔍',custom_emoji_id='300'),
                          dict(key='message.refresh',source_emoji='🔄',fallback_emoji='🔃',custom_emoji_id=None),
                          dict(key='menu.back',source_emoji='⬅️',fallback_emoji='🔙',custom_emoji_id=None)]
        self.tier_rows = {}
        self.assets = {}
        self.order_rows = []
        self.balance_rows = []
        self.usdt_rows = []
        self.idr_snapshot = {'available': True, 'balance': 50000}
        self.usd_snapshot = {'available': True, 'balance_micro': 0, 'frozen': False}
        self.account_id = 1
        self.paused = False
        self.spending = True

    def balance_snapshot(self, customer_id):
        return copy.deepcopy(self.idr_snapshot)

    def usdt_balance_snapshot(self, customer_id):
        return copy.deepcopy(self.usd_snapshot)

    def balance_account_id(self, customer_id):
        return self.account_id if self.balance_rows else None

    def balance_activity(self, account_id, *, direction=None, occurred_micros=None,
                         entry_id=None):
        rows = copy.deepcopy(self.balance_rows)
        if direction is not None:
            compare = (lambda row: (row['occurred_micros'], row['id'])
                       < (occurred_micros, entry_id)) if direction == 'older' else (
                lambda row: (row['occurred_micros'], row['id']) > (occurred_micros, entry_id))
            rows = [row for row in rows if compare(row)]
        rows.sort(key=lambda row: (row['occurred_micros'], row['id']),
                  reverse=direction != 'newer')
        return rows[:BALANCE_ACTIVITY_PAGE_SIZE + 1]

    def balance_entry_exists(self, account_id, entry_id):
        return account_id is not None and any(row['id'] == entry_id for row in self.balance_rows)

    def usdt_activity(self, customer_id, limit=USDT_ACTIVITY_ROWS):
        rows = copy.deepcopy(self.usdt_rows)
        rows.sort(key=lambda row: (row['occurred_micros'], row['id']), reverse=True)
        return rows[:limit]

    def orders_for_balance_entries(self, customer_id, order_ids):
        wanted = {str(order_id) for order_id in order_ids}
        return {row['id']: row['invoice_number'] for row in self.order_rows
                if row['customer_id'] == customer_id and row['id'] in wanted}

    def order_history(self, customer_id, *, direction=None, created_token=None, order_id=None):
        rows = [copy.deepcopy(row) for row in self.order_rows
                if row['customer_id'] == customer_id and row['payment_status'] == 'paid'
                and row['order_status'] in ('processing','completed')]
        if direction is not None:
            compare = (lambda row: (row['created_token'],row['id']) < (created_token,order_id)) if direction == 'older' else (
                lambda row: (row['created_token'],row['id']) > (created_token,order_id))
            rows = [row for row in rows if compare(row)]
        rows.sort(key=lambda row: (row['created_token'],row['id']),reverse=direction != 'newer')
        return rows[:ORDER_HISTORY_PAGE_SIZE + 1]

    def products(self):
        rows = copy.deepcopy(self.product_rows)
        for row in rows:
            row['family'] = next((family for family in self.family_rows if family['id'] == row['product_family_id']),None)
        return rows

    def families(self):
        return copy.deepcopy(self.family_rows)

    def product(self, product_id):
        return next((row for row in self.products() if row['id'] == product_id), None)

    def family(self, family_id):
        family = next((row for row in self.families() if row['id'] == family_id), None)
        if family is None:
            return None
        members = [row for row in self.products() if row['product_family_id'] == family_id]
        return dict(family, members=sorted(members, key=self.member_key)) if members else None

    def category_options(self):
        return {'categories': copy.deepcopy(self.category_rows),
                'has_uncategorized': any(row['category_id'] is None for row in self.product_rows)}

    def categories(self):
        return self.category_options()['categories']

    def category(self, identity):
        return next((row for row in self.category_rows if str(row['id']) == str(identity) or row['slug'] == identity),None)

    def icons(self):
        return copy.deepcopy(self.icon_rows)

    def tiers(self, product_id):
        return copy.deepcopy(self.tier_rows.get(product_id,[]))

    def maintenance(self):
        return {'is_active':self.paused,'public_note':None}

    def detail_flags(self):
        return {'maintenance': self.maintenance(), 'spending_available': self.spending}

    def spending_available(self):
        return self.spending

    def asset(self, source_path, bot_scope='synthetic'):
        return self.assets.get((bot_scope,source_path))

    def home_data(self, customer_id, bot_scope='synthetic'):
        return dict(bot_users=17, successful_orders=9, idr_total=100000, usdt_total=1234567,
                    balance=12500, channel_url='https://t.me/example', api_enabled=False,
                    banner_path='banner.webp', banner_asset=self.assets.get((bot_scope,'banner.webp')),
                    maintenance=self.maintenance())


class CountingCatalog(MemoryCatalog):
    def __init__(self):
        super().__init__()
        self.calls = Counter()

    def product(self, product_id):
        self.calls['product'] += 1
        return super().product(product_id)

    def family(self, family_id):
        self.calls['family'] += 1
        return super().family(family_id)

    def products(self):
        self.calls['products'] += 1
        return super().products()

    def category(self, identity):
        self.calls['category'] += 1
        return super().category(identity)

    def category_options(self):
        self.calls['category_options'] += 1
        return super().category_options()

    def detail_flags(self):
        self.calls['detail_flags'] += 1
        return super().detail_flags()

    def home_data(self, customer_id, bot_scope='synthetic'):
        self.calls['home_data'] += 1
        return super().home_data(customer_id, bot_scope)


def buttons(surface):
    return [button for row in surface.keyboard['inline_keyboard'] for button in row]


class CatalogPresentationTests(unittest.TestCase):
    def setUp(self):
        self.repository = MemoryCatalog()
        self.presenter = CatalogPresenter(self.repository,signing_key='synthetic-key',
                                          clock=lambda: datetime(2026,11,1,8,tzinfo=ZoneInfo('Asia/Jakarta')))
        self.customer = dict(CUSTOMER)
        self.state = {}

    def test_home_uses_source_profile_stats_button_order_and_group_privacy(self):
        home = self.presenter.home(self.customer)
        self.assertIn('Selamat pagi, Ayu',home.caption)
        self.assertIn('<blockquote>',home.caption)
        self.assertIn('Rp 12.500',home.caption)
        self.assertIn('Rp 100.000 / 1.234567 USDT',home.caption)
        self.assertEqual([[button.get('callback_data',button.get('url')) for button in row]
                          for row in home.keyboard['inline_keyboard']],
                         [['katalog'],['balance','menu:orders'],['menu:language','menu:support'],['https://t.me/example']])
        self.assertTrue(all(button['style'] == 'primary' for button in buttons(home)))
        group = self.presenter.home(self.customer,private=False)
        self.assertEqual([button['callback_data'] for button in buttons(group)],['katalog'])
        for sensitive in ('Ayu','41001','12.500','100.000','@ayu'):
            self.assertNotIn(sensitive,group.caption)

    def test_catalog_single_plan_shortcut_stock_and_footer_parity(self):
        catalog = self.presenter.route(self.customer,'katalog',self.state)
        self.assertEqual(catalog.caption,'📦 DAFTAR PRODUK DIGITAL\n\nPilih keluarga atau produk untuk melihat plan dan stok.')
        rows = catalog.keyboard['inline_keyboard']
        self.assertEqual(rows[0][0]['text'],'🎬 Netflix ▸ 2 Plan »')
        self.assertEqual(rows[1][0]['text'],'🎵 Spotify · Stok 5')
        self.assertTrue(rows[1][0]['callback_data'].startswith('product:3:o'))
        self.assertEqual(rows[2][0]['style'],'danger')
        self.assertEqual([button['callback_data'] for button in rows[-1]],['catalog:categories','catalog:refresh','family:back:menu'])
        self.assertEqual([button['text'] for button in rows[-1]],['🔍 Filter','🔃 Refresh','🔙 Kembali ke menu'])
        detail = self.presenter.route(self.customer,rows[1][0]['callback_data'],self.state)
        self.assertEqual(detail.screen,'product')
        self.assertIn('Spotify',detail.caption)
        self.assertNotIn('Pilih Plan',detail.caption)
        back = next(button['callback_data'] for button in buttons(detail) if button.get('callback_data','').startswith('detail:back:'))
        restored = self.presenter.route(self.customer,back,self.state)
        self.assertEqual(restored.caption,catalog.caption)

    def test_family_plans_are_ordered_disabled_red_and_member_icons_override(self):
        self.repository.product_rows[1].update(icon='👑',custom_emoji_id=None)
        catalog = self.presenter.catalog(self.customer,state=self.state)
        family = self.presenter.route(self.customer,buttons(catalog)[0]['callback_data'],self.state)
        self.assertIn('🎬 Pilih Plan Netflix',family.caption)
        self.assertIn('01. 🎬 Basic',family.caption)
        self.assertIn('02. 👑 Premium',family.caption)
        first,second = family.keyboard['inline_keyboard'][0]
        self.assertEqual(first,{'text':'1','style':'danger','disabled':{}})
        self.assertEqual(second['style'],'primary')
        self.assertTrue(second['callback_data'].startswith('family:select:v'))
        self.assertLessEqual(len(second['callback_data'].encode()),64)
        detail = self.presenter.route(self.customer,second['callback_data'],self.state)
        self.assertTrue(detail.caption.startswith('👑 Premium'))
        self.assertIsNone(self.presenter.route(self.customer,second['callback_data'],self.state))

    def test_family_pagination_uses_ten_members_and_restores_original_list(self):
        self.repository.product_rows += [product(index,f'Extra {index}',family=10) for index in range(5,16)]
        catalog = self.presenter.catalog(self.customer,state=self.state)
        family = self.presenter.route(self.customer,buttons(catalog)[0]['callback_data'],self.state)
        self.assertEqual(len([button for button in buttons(family) if button.get('callback_data','').startswith('family:select:')]),9)
        next_callback = next(button['callback_data'] for button in buttons(family)
                             if button.get('callback_data','').startswith('family:options:') and ':s' in button['callback_data'])
        next_page = self.presenter.route(self.customer,next_callback,self.state)
        self.assertIn('2',next_page.caption)
        self.assertIn('01. ',next_page.caption)
        back = buttons(next_page)[-1]['callback_data']
        self.assertEqual(self.presenter.route(self.customer,back,self.state).caption,catalog.caption)

    def test_sales_ranking_precedes_catalog_pagination(self):
        self.repository.product_rows[2]['sold_quantity'] = 50
        catalog = self.presenter.catalog(self.customer,state=self.state)
        self.assertIn('Spotify',buttons(catalog)[0]['text'])

    def test_signed_callback_tampering_wrong_origin_and_wrong_family_state_are_rejected(self):
        catalog = self.presenter.catalog(self.customer,state=self.state)
        callback = buttons(catalog)[0]['callback_data']
        self.assertIsNone(self.presenter.route(self.customer,callback,{}))
        self.assertIsNone(self.presenter.route(self.customer,callback.replace(':v',':vA',1),self.state))
        family = self.presenter.route(self.customer,callback,self.state)
        selected = family.keyboard['inline_keyboard'][0][1]['callback_data']
        self.state['family']['page'] = 2
        self.assertIsNone(self.presenter.route(self.customer,selected,self.state))

    def test_navigation_reuses_loaded_rows_instead_of_rescanning(self):
        repository = self.repository = CountingCatalog()
        self.presenter.repository = repository
        self.repository.category_rows = [dict(id=7,name='Music & Audio',slug='music',description='')]
        self.repository.product_rows[2]['category_id'] = 7
        catalog = self.presenter.route(self.customer,'katalog',self.state)
        family_callback = buttons(catalog)[0]['callback_data']
        family = self.presenter.route(self.customer,family_callback,self.state)
        select_callback = next(button['callback_data'] for button in buttons(family)
                              if button.get('callback_data','').startswith('family:select:'))

        repository.calls.clear()
        detail = self.presenter.route(self.customer,select_callback,self.state)
        self.assertEqual(repository.calls['family'], 1)
        self.assertEqual(repository.calls['product'], 0)
        self.assertIn('Premium', detail.caption)

        increase = next(button['callback_data'] for button in buttons(detail) if ':qty:+:' in button.get('callback_data',''))
        repository.calls.clear()
        self.presenter.route(self.customer,increase,self.state)
        self.assertEqual(repository.calls['product'], 1)
        self.assertEqual(repository.calls['detail_flags'], 1)

        repository.calls.clear()
        self.presenter.route(self.customer,'catalog:categories',self.state)
        self.assertEqual(repository.calls['category_options'], 1)
        self.assertEqual(repository.calls['products'], 0)

        repository.calls.clear()
        self.presenter.home(self.customer)
        self.assertEqual(repository.calls['home_data'], 1)
        self.assertEqual(repository.calls['products'], 0)

    def test_quantity_uses_exact_tiers_rotates_payment_token_and_keeps_burst_token(self):
        self.repository.product_rows[2]['available_stock'] = 10
        self.repository.tier_rows[3] = [dict(minimum_quantity=3,price_amount=20000,usdt_price_amount=None)]
        detail = self.presenter.detail(self.customer,3,state=self.state)
        increase = detail.keyboard['inline_keyboard'][0][2]['callback_data']
        previous_payment = self.state['selection']['interaction_token']
        self.presenter.route(self.customer,increase,self.state)
        self.assertNotEqual(self.state['selection']['interaction_token'],previous_payment)
        detail = self.presenter.route(self.customer,increase,self.state)
        self.assertEqual(self.state['selection']['quantity'],3)
        self.assertIn('Rp60.000',detail.caption)
        self.assertIn('Rp15.000',detail.caption)
        self.assertIn('Rp20.000',detail.caption)
        self.assertIsNone(self.presenter.route(self.customer,f'detail:back:3:{previous_payment}',self.state))

    def test_custom_quantity_input_retry_and_cancel_preserve_quantity(self):
        detail = self.presenter.detail(self.customer,3,state=self.state,quantity=2)
        manual = next(button['callback_data'] for button in buttons(detail) if ':manual:' in button.get('callback_data',''))
        prompt = self.presenter.route(self.customer,manual,self.state)
        self.assertEqual(self.state['selection']['mode'],'awaiting_custom_quantity')
        for invalid in ('1.5','-1','0','01','6','9'*30):
            prompt = self.presenter.quantity_input(self.customer,invalid,self.state)
            self.assertEqual(self.state['selection']['quantity'],2)
        detail = self.presenter.route(self.customer,buttons(prompt)[0]['callback_data'],self.state)
        self.assertEqual(self.state['selection']['quantity'],2)
        manual = next(button['callback_data'] for button in buttons(detail) if ':manual:' in button.get('callback_data',''))
        self.presenter.route(self.customer,manual,self.state)
        chosen = self.presenter.quantity_input(self.customer,'5',self.state)
        self.assertEqual(self.state['selection']['quantity'],5)
        self.assertIn('Rp125.000',chosen.caption)

    def test_localized_copy_falls_back_to_base_and_escapes_untrusted_html(self):
        self.repository.product_rows[2].update(description_en='English details & support',description_ms='',warranty_policy='Base policy')
        for code,expected in [('id','Details &lt;plain&gt;'),('en','English details &amp; support'),('ms','Details &lt;plain&gt;')]:
            with self.subTest(locale=code):
                self.customer['locale'] = code
                detail = self.presenter.detail(self.customer,3,state=self.state)
                self.assertIn(expected,detail.caption)
                self.assertIn('Base policy',detail.caption)
        self.customer['first_name'] = '<tg-emoji emoji-id="1"><b>bad</b></tg-emoji>'
        self.assertNotIn('<b>bad</b>',self.presenter.home(self.customer).caption)
        self.repository.product_rows[2]['name'] = '<tg-emoji emoji-id="1">BAD</tg-emoji>'
        self.assertNotIn('<tg-emoji',self.presenter.detail(self.customer,3,state=self.state).caption)
        malicious = '<tg-emoji emoji-id="1"><b>bad</b></tg-emoji>'
        search = self.presenter.catalog(self.customer,search=malicious,state=self.state)
        self.assertNotIn('parse_mode',search.options)
        self.assertIn(malicious,search.caption)
        self.presenter.custom_emoji = True
        search = self.presenter.catalog(self.customer,search=malicious,state=self.state)
        self.assertEqual(search.options['parse_mode'],'HTML')
        self.assertNotIn('<b>bad</b>',search.caption)

    def test_utf16_custom_emoji_metadata_is_bound_to_exact_localized_copy(self):
        text = 'A😀Z'
        self.repository.product_rows[2].update(description_en=text,telegram_content={'description_en':{'text':text,'entities':[
            {'type':'custom_emoji','offset':1,'length':2,'custom_emoji_id':'123'}]}})
        self.customer['locale'] = 'en'
        self.presenter.custom_emoji = True
        detail = self.presenter.detail(self.customer,3,state=self.state)
        self.assertIn('A<tg-emoji emoji-id="123">😀</tg-emoji>Z',detail.caption)
        self.repository.product_rows[2]['description_en'] = 'Changed😀'
        self.assertNotIn('emoji-id="123"',self.presenter.detail(self.customer,3,state=self.state).caption)
        self.presenter.custom_emoji = False
        self.repository.product_rows[2]['description_en'] = text
        self.assertNotIn('<tg-emoji',self.presenter.detail(self.customer,3,state=self.state).caption)

    def test_db_icons_include_unicode_fallback_when_premium_is_supported(self):
        self.presenter.custom_emoji = True
        catalog = self.presenter.catalog(self.customer,state=self.state)
        family = buttons(catalog)[0]
        self.assertEqual(family['icon_custom_emoji_id'],'100')
        self.assertFalse(family['text'].startswith('🎬'))
        self.assertTrue(family['_unicode_fallback'].startswith('🎬'))
        footer = catalog.keyboard['inline_keyboard'][-1][0]
        self.assertEqual(footer['icon_custom_emoji_id'],'300')
        self.assertTrue(footer['_unicode_fallback'].startswith('🔍'))

    def test_media_requires_bot_scoped_mapping_not_production_file_id(self):
        row = self.repository.product_rows[2]
        row.update(telegram_image_path='product.webp',telegram_file_id='PRODUCTION_FILE_ID')
        self.assertNotIn('photo_url',self.presenter.detail(self.customer,3,state=self.state).options)
        self.repository.assets[('synthetic','product.webp')] = {'file_id':'SYNTHETIC_FILE_ID','version':2}
        self.assertEqual(self.presenter.detail(self.customer,3,state=self.state).options['photo_url'],'SYNTHETIC_FILE_ID')
        self.repository.assets[('LhieStoreTestBot','banner.webp')] = {'file_id':'TEST_BOT_BANNER','version':1}
        self.assertNotIn('photo_url',self.presenter.home(self.customer).options)
        testing = CatalogPresenter(self.repository,signing_key='synthetic-key',bot_scope='LhieStoreTestBot')
        self.assertEqual(testing.home(self.customer).options['photo_url'],'TEST_BOT_BANNER')

    def test_detail_spending_pause_and_maintenance_hide_payment_actions(self):
        self.repository.spending = False
        detail = self.presenter.detail(self.customer,3,state=self.state)
        self.assertFalse(any(':pay:balance:' in button.get('callback_data','') for button in buttons(detail)))
        self.repository.paused = True
        detail = self.presenter.detail(self.customer,3,state=self.state)
        self.assertFalse(any(':pay:' in button.get('callback_data','') for button in buttons(detail)))
        self.assertTrue(any(button.get('callback_data') == 'checkout-maintenance:status' for button in buttons(detail)))

    def test_dual_prices_are_exact_micro_units_when_local_rail_is_selectable(self):
        self.customer['locale'] = 'en'
        self.repository.product_rows[2]['usdt_price_amount'] = 1234567
        self.presenter.dollar_rail_selectable = True
        detail = self.presenter.detail(self.customer,3,state=self.state,quantity=3)
        self.assertIn('3.703701 USDT',detail.caption)
        self.assertTrue(any(':pay:binance:' in button.get('callback_data','') for button in buttons(detail)))

    def test_empty_catalog_keeps_relevant_primary_footer(self):
        self.repository.product_rows = []
        empty = self.presenter.catalog(self.customer,state=self.state)
        self.assertIn('Produk masih kosong',empty.caption)
        self.assertEqual([button['callback_data'] for button in buttons(empty)],['catalog:categories','catalog:refresh','family:back:menu'])
        self.assertTrue(all(button['style'] == 'primary' for button in buttons(empty)))

    def test_category_picker_filter_and_single_plan_back_restore_category(self):
        self.repository.category_rows = [dict(id=7,name='Music & Audio',slug='music',description='Choose <music>')]
        self.repository.product_rows[2]['category_id'] = 7
        picker = self.presenter.route(self.customer,'catalog:categories',self.state)
        self.assertEqual([button['callback_data'] for button in buttons(picker)],
                         ['category:7','category:uncategorized','family:back:menu'])
        category = self.presenter.route(self.customer,'category:7',self.state)
        self.assertIn('Music &amp; Audio',category.caption)
        self.assertIn('Choose &lt;music&gt;',category.caption)
        selected = buttons(category)[0]['callback_data']
        self.assertTrue(selected.startswith('product:3:o'))
        detail = self.presenter.route(self.customer,selected,self.state)
        back = next(button['callback_data'] for button in buttons(detail) if button.get('callback_data','').startswith('detail:back:'))
        self.assertEqual(self.presenter.route(self.customer,back,self.state).caption,category.caption)

    def test_quantity_boundaries_ack_without_render_or_token_rotation(self):
        detail = self.presenter.detail(self.customer,3,state=self.state)
        before = copy.deepcopy(self.state)
        decrease = detail.keyboard['inline_keyboard'][0][0]['callback_data']
        self.assertIsNone(self.presenter.route(self.customer,decrease,self.state))
        self.assertTrue(self.presenter.callback_handled)
        self.assertEqual(self.state,before)
        self.assertIn('1',self.presenter.callback_notice)
        current = detail.keyboard['inline_keyboard'][0][1]['callback_data']
        self.assertIsNone(self.presenter.route(self.customer,current,self.state))
        self.assertTrue(self.presenter.callback_handled)
        self.assertIsNone(self.presenter.callback_notice)

    def test_catalog_paginates_fifteen_entries_and_refresh_resets_filters(self):
        self.repository.family_rows = []
        self.repository.product_rows = [product(index,f'Item {index:02d}') for index in range(1,17)]
        first = self.presenter.catalog(self.customer,state=self.state)
        self.assertEqual(len(first.keyboard['inline_keyboard']),17)
        callback = first.keyboard['inline_keyboard'][-2][0]['callback_data']
        self.assertEqual(callback,'family:list:2')
        second = self.presenter.route(self.customer,callback,self.state)
        self.assertIn('Item 16',buttons(second)[0]['text'])
        self.presenter.catalog(self.customer,search='Item 16',state=self.state)
        refreshed = self.presenter.route(self.customer,'catalog:refresh',self.state)
        self.assertEqual(self.state['catalog']['type'],'catalog')
        self.assertEqual(self.state['catalog']['page'],1)
        self.assertEqual(refreshed.caption,first.caption)

    def _order_rows(self):
        rows = [order(index,f'INV-{index:03d}',created_token=f'2026110112{23-index:02d}00',
                      order_status='processing' if index in (3,4) else 'completed',
                      receipt_sent=index not in (2,4)) for index in range(1,13)]
        rows += [order(101,'INV-PENDING',created_token='20261101130000',payment_status='pending'),
                 order(102,'INV-CANCELLED',created_token='20261101130100',order_status='cancelled')]
        return rows

    def test_orders_matches_php_status_labels_and_keyset_pagination(self):
        self.repository.order_rows = self._order_rows()
        self.state['selection'] = {'product_id': 3}
        first = self.presenter.route(self.customer,'menu:orders',self.state)
        self.assertEqual(self.state,{})
        self.assertEqual((first.screen,first.locale,first.caption),('orders','id','📦 Pesanan Berhasil'))
        order_buttons = [button for button in buttons(first) if button.get('callback_data','').startswith('order:')]
        self.assertEqual([button['callback_data'] for button in order_buttons],
                         [f'order:INV-{index:03d}' for index in range(1,11)])
        self.assertEqual([button['text'] for button in order_buttons],
                         ['📦 INV-001 · Terkirim','📦 INV-002 · Terkirim','📦 INV-003 · Terkirim',
                          '📦 INV-004 · Diproses','📦 INV-005 · Terkirim','📦 INV-006 · Terkirim',
                          '📦 INV-007 · Terkirim','📦 INV-008 · Terkirim','📦 INV-009 · Terkirim',
                          '📦 INV-010 · Terkirim'])
        next_button = next(button for button in buttons(first) if button.get('text','').startswith('Berikutnya'))
        self.assertTrue(next_button['callback_data'].startswith('orders:older:20261101121300:'))
        self.assertEqual([button['callback_data'] for button in first.keyboard['inline_keyboard'][-1]],
                         ['menu:back','menu:home'])
        second = self.presenter.route(self.customer,next_button['callback_data'],self.state)
        self.assertEqual([button['callback_data'] for button in buttons(second)
                          if button.get('callback_data','').startswith('order:')],
                         ['order:INV-011','order:INV-012'])
        previous = next(button for button in buttons(second)
                        if button.get('callback_data','').startswith('orders:newer:'))
        restored = self.presenter.route(self.customer,previous['callback_data'],self.state)
        self.assertEqual(restored.caption,first.caption)
        self.assertEqual(restored.keyboard,first.keyboard)

    def test_orders_empty_localized_and_unimplemented_callbacks_do_not_fake_a_screen(self):
        self.customer['locale'] = 'en'
        empty = self.presenter.route(self.customer,'menu:orders',self.state)
        self.assertEqual(empty.caption,'📦 No successful orders yet.')
        self.assertEqual([button['callback_data'] for button in buttons(empty)],
                         ['katalog','menu:back','menu:home'])
        self.customer['locale'] = 'ms'
        self.assertEqual(self.presenter.route(self.customer,'menu:orders',self.state).caption,
                         '📦 Tiada pesanan berjaya lagi.')
        self.customer['locale'] = 'id'
        for data in ('orders:older:not-a-cursor','orders:newer:20261101120000:bad','order:INV-001'):
            with self.subTest(data=data):
                self.assertIsNone(self.presenter.route(self.customer,data,self.state))
        self.repository.order_rows = self._order_rows()
        self.state['selection'] = {'product_id': 3}
        self.presenter.route(self.customer,'menu:orders',self.state)
        self.assertEqual(self.presenter.route(
            self.customer,'orders:older:20261301120000:'
            '00000000-0000-4000-8000-000000000010',self.state).caption,
            '📦 Pesanan Berhasil')
        self.repository.order_rows = []
        page = self.presenter.route(self.customer,'orders:older:20261101121300:'
                                    '00000000-0000-4000-8000-000000000010',self.state)
        self.assertEqual(page.caption,'📦 Belum ada pesanan berhasil.')
        self.assertEqual([button['callback_data'] for button in buttons(page)],
                         ['katalog','orders:open','menu:back','menu:home'])
        self.assertEqual(self.presenter.route(self.customer,'orders:open',self.state).caption,
                         '📦 Belum ada pesanan berhasil.')

    def test_orders_uses_db_icon_for_copy_and_leading_button_icon(self):
        self.presenter.custom_emoji = True
        self.repository.order_rows = self._order_rows()[:1]
        self.repository.icon_rows.append(dict(key='message.orders',source_emoji='📦',
                                              fallback_emoji='📦',custom_emoji_id='400'))
        screen = self.presenter.route(self.customer,'menu:orders',self.state)
        self.assertIn('<tg-emoji emoji-id="400">📦</tg-emoji> Pesanan Berhasil',screen.caption)
        order_button = next(button for button in buttons(screen)
                            if button.get('callback_data','').startswith('order:'))
        self.assertEqual(order_button['text'],'INV-001 · Terkirim')
        self.assertEqual(order_button['icon_custom_emoji_id'],'400')
        self.assertEqual(order_button['_unicode_fallback'],'📦 INV-001 · Terkirim')
        back,home = screen.keyboard['inline_keyboard'][-1]
        self.assertEqual((back['text'],home['text']),('🔙 Kembali','🏠 Beranda'))


    def _balance_rows(self):
        orders = self._order_rows()
        self.repository.order_rows = orders
        rows = [balance_entry(identity,
                              'purchase_debit' if identity % 2 == 0 else 'admin_adjustment_credit',
                              1000 + identity,
                              datetime(2026,11,1,8,0) + timedelta(minutes=identity))
                for identity in range(1,12)]
        rows.append(balance_entry(12,'refund_credit',1500,datetime(2026,11,1,8,12),
                                  source_type='order_refund',source_id=orders[1]['id'],
                                  refund_order_id=orders[1]['id']))
        rows.append(balance_entry(13,'purchase_debit',2000,datetime(2026,11,1,8,13),
                                  source_type='order',source_id=orders[0]['id']))
        self.repository.balance_rows = rows
        self.repository.usdt_rows = [
            usdt_entry(1,'funding_credit',3_500_000,datetime(2026,11,1,8,20)),
            usdt_entry(2,'purchase_debit',1_250_000,datetime(2026,11,1,8,11,30)),
        ]

    def test_balance_matches_php_header_recent_activity_and_funding_identity(self):
        self._balance_rows()
        self.state['selection'] = {'product_id': 3}
        screen = self.presenter.route(self.customer,'balance',self.state)
        self.assertEqual(self.state,{})
        self.assertEqual((screen.screen,screen.locale),('balance','id'))
        self.assertEqual(screen.caption,
            '💰 Saldo Saya\n━━━━━━━━━━━━━━\n🇮🇩 Saldo Rupiah: Rp 50.000\n\n'
            '💎 Saldo USDT: 0 USDT\n\n4 aktivitas terakhir\n'
            '1. Kredit top-up · +3.5 USDT · Dikreditkan · 01 Nov 2026\n'
            '2. Pembelian · -Rp 2.000 · Didebit · 01 Nov 2026\n'
            '3. Pengembalian dana · +Rp 1.500 · Dikembalikan · 01 Nov 2026\n'
            '4. Pembelian · -1.25 USDT · Didebit · 01 Nov 2026')
        self.assertEqual([button['callback_data'] for button in screen.keyboard['inline_keyboard'][0]],
                         ['balance:add'])
        self.assertEqual([button['callback_data'] for button in screen.keyboard['inline_keyboard'][1]],
                         ['balance:activity'])
        self.assertEqual([button['callback_data'] for button in screen.keyboard['inline_keyboard'][-1]],
                         ['menu:back','menu:home'])
        self.assertEqual((screen.keyboard['inline_keyboard'][0][0]['text'],
                          screen.keyboard['inline_keyboard'][1][0]['text']),
                         ('➕ Tambah Saldo','📜 Riwayat Transaksi'))

    def test_balance_unavailable_and_frozen_states_never_fabricate_a_zero(self):
        self.repository.idr_snapshot = {'available': False, 'balance': 0}
        self.repository.usd_snapshot = {'available': False, 'balance_micro': 0, 'frozen': False}
        screen = self.presenter.route(self.customer,'balance',self.state)
        self.assertEqual(screen.caption,
            '💰 Saldo Saya\n━━━━━━━━━━━━━━\n🇮🇩 Saldo Rupiah: Saldo sementara tidak tersedia\n\n'
            'Aktivitas disembunyikan sementara saat saldo sedang diverifikasi.\n'
            'Kalau terus begini, buka Support. Dana tidak ditambahkan selama verifikasi belum selesai.\n\n'
            '💎 Saldo USDT belum bisa diverifikasi. Hubungi Support; nominal tidak ditampilkan sampai cocok dengan ledger.')
        self.assertEqual([button['callback_data'] for button in buttons(screen)],
                         ['menu:back','menu:home'])
        self.assertEqual(self.presenter.route(self.customer,'balance:activity',self.state).caption,
                         screen.caption)
        self.repository.idr_snapshot = {'available': True, 'balance': 50000}
        self.repository.usd_snapshot = {'available': True, 'balance_micro': 0, 'frozen': True}
        frozen = self.presenter.route(self.customer,'balance',self.state)
        self.assertIn('💎 Saldo USDT dibekukan sementara selama pemeriksaan insiden.',frozen.caption)
        self.assertNotIn('💎 Saldo USDT: 0 USDT',frozen.caption)
        self.assertEqual([button['callback_data'] for button in buttons(frozen)],
                         ['balance:add','balance:activity','menu:back','menu:home'])

    def test_balance_history_paginates_ledger_and_rejects_money_actions(self):
        self._balance_rows()
        first = self.presenter.route(self.customer,'balance:activity',self.state)
        self.assertIn('Riwayat Transaksi Rupiah',first.caption)
        self.assertNotIn('Saldo USDT',first.caption)
        self.assertIn('1. Pembelian — -Rp 2.000 — Didebit',first.caption)
        self.assertIn('Referensi: #INV-001',first.caption)
        details = [button for button in buttons(first)
                   if button.get('callback_data','').startswith('balance:a:d:')]
        self.assertEqual(len(details),10)
        self.assertTrue(all(len(button['callback_data'].encode()) <= 64 for button in buttons(first)
                            if button.get('callback_data')))
        self.assertIn('Referensi: #INV-002',first.caption)
        older = next(button for button in buttons(first)
                     if button.get('callback_data','').startswith('balance:a:o:'))
        self.assertEqual(older['text'],'Berikutnya ➡️')
        second = self.presenter.route(self.customer,older['callback_data'],self.state)
        self.assertEqual(len([button for button in buttons(second)
                              if button.get('callback_data','').startswith('balance:a:d:')]),3)
        self.assertIn('3. Penyesuaian saldo — +Rp 1.001 — Dikreditkan',second.caption)
        newer = next(button for button in buttons(second)
                     if button.get('callback_data','').startswith('balance:a:n:'))
        restored = self.presenter.route(self.customer,newer['callback_data'],self.state)
        self.assertEqual(restored.caption,first.caption)
        self.assertEqual(restored.keyboard,first.keyboard)
        self.assertIsNone(self.presenter.route(self.customer,details[0]['callback_data'],self.state))
        self.assertTrue(self.presenter.callback_handled)
        self.assertEqual(self.presenter.callback_notice,
                         'Aksi Saldo ini belum tersedia di build ini. Saldo tidak berubah.')
        for data in ('balance:add','balance:currency:idr','balance:topup:7','balance:usd:bsc'):
            with self.subTest(data=data):
                self.assertIsNone(self.presenter.route(self.customer,data,self.state))
                self.assertTrue(self.presenter.callback_handled)
                self.assertIn('Saldo tidak berubah',self.presenter.callback_notice)

    def test_balance_history_rejects_foreign_tampered_and_malformed_cursors(self):
        self._balance_rows()
        entry = self.repository.balance_rows[-2]
        foreign = self.presenter._activity_callback('older',2,entry['occurred_micros'],entry['id'])
        self.assertIsNone(self.presenter.route(self.customer,foreign,self.state))
        self.assertFalse(self.presenter.callback_handled)
        for data in ('balance:a:o:' + 'A' * 51,'balance:a:o:short','balance:a:d:' + 'A' * 40):
            with self.subTest(data=data):
                self.assertIsNone(self.presenter.route(self.customer,data,self.state))
                self.assertFalse(self.presenter.callback_handled)

    def test_balance_history_keeps_same_second_rows_reachable(self):
        when = datetime(2026,11,1,8,0)
        self.repository.balance_rows = [
            balance_entry(identity,'admin_adjustment_credit',1000 + identity,when)
            for identity in range(1,12)]
        first = self.presenter.route(self.customer,'balance:activity',self.state)
        def detail_ids(surface):
            details = [button for button in buttons(surface)
                       if button.get('callback_data','').startswith('balance:a:d:')]
            return [self.presenter._decode_detail_token(
                button['callback_data'][len('balance:a:d:'):])['entry_id'] for button in details]
        self.assertEqual(detail_ids(first),list(range(11,1,-1)))
        older = next(button for button in buttons(first)
                     if button.get('callback_data','').startswith('balance:a:o:'))
        second = self.presenter.route(self.customer,older['callback_data'],self.state)
        self.assertEqual(detail_ids(second),[1])
        newer = next(button for button in buttons(second)
                     if button.get('callback_data','').startswith('balance:a:n:'))
        self.assertEqual(detail_ids(self.presenter.route(
            self.customer,newer['callback_data'],self.state)),list(range(11,1,-1)))

    def test_balance_localized_copy_and_db_icons(self):
        self.presenter.custom_emoji = True
        self.repository.icon_rows += [
            dict(key='message.balance',source_emoji='💰',fallback_emoji='💰',custom_emoji_id='600'),
            dict(key='message.add',source_emoji='➕',fallback_emoji='➕',custom_emoji_id='601')]
        screen = self.presenter.route(self.customer,'balance',self.state)
        self.assertIn('<tg-emoji emoji-id="600">💰</tg-emoji> Saldo Saya',screen.caption)
        add = next(button for button in buttons(screen) if button['callback_data'] == 'balance:add')
        self.assertEqual(add['text'],'Tambah Saldo')
        self.assertEqual(add['icon_custom_emoji_id'],'601')
        self.assertEqual(add['_unicode_fallback'],'➕ Tambah Saldo')
        self.customer['locale'] = 'en'
        self.assertEqual(self.presenter.route(self.customer,'balance',self.state).caption.splitlines()[0],
                         '<tg-emoji emoji-id="600">💰</tg-emoji> My Balance')
        self.customer['locale'] = 'ms'
        self.assertIn('💎 Baki USDT: 0 USDT',
                      self.presenter.route(self.customer,'balance',self.state).caption)


if __name__ == '__main__':
    unittest.main()
