import copy
from datetime import datetime
import unittest
from zoneinfo import ZoneInfo

from lhie_candidate.navigation.presentation import CatalogPresenter
from lhie_candidate.navigation.repository import CatalogRepository


CUSTOMER = {'id': 1, 'telegram_id': 41001, 'first_name': 'Ayu', 'username': 'ayu', 'locale': 'id'}


def product(identity, name, *, family=None, stock=3, price=25000, **values):
    return dict(id=identity, name=name, product_family_id=family, category_id=None,
                family_sort_order=identity, family_option_label=None, available_stock=stock,
                price_amount=price, usdt_price_amount=None, sold_quantity=0, icon=None,
                custom_emoji_id=None, description='Details <plain>', warranty_policy='',
                telegram_content={}, telegram_image_path=None, **values)


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
        self.paused = False
        self.spending = True

    def products(self):
        rows = copy.deepcopy(self.product_rows)
        for row in rows:
            row['family'] = next((family for family in self.family_rows if family['id'] == row['product_family_id']),None)
        return rows

    def families(self):
        return copy.deepcopy(self.family_rows)

    def categories(self):
        return copy.deepcopy(self.category_rows)

    def category(self, identity):
        return next((row for row in self.category_rows if str(row['id']) == str(identity) or row['slug'] == identity),None)

    def icons(self):
        return copy.deepcopy(self.icon_rows)

    def tiers(self, product_id):
        return copy.deepcopy(self.tier_rows.get(product_id,[]))

    def maintenance(self):
        return {'is_active':self.paused,'public_note':None}

    def spending_available(self):
        return self.spending

    def asset(self, source_path, bot_scope='synthetic'):
        return self.assets.get((bot_scope,source_path))

    def home_data(self, customer_id):
        return dict(bot_users=17, successful_orders=9, idr_total=100000, usdt_total=1234567,
                    balance=12500, channel_url='https://t.me/example', api_enabled=False, banner_path='banner.webp')


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


if __name__ == '__main__':
    unittest.main()
