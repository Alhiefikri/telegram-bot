"""PHP-compatible initial catalog surfaces; no Telegram or provider calls."""
import base64
from datetime import datetime
import hmac
from html import escape, unescape
import json
from pathlib import Path
import re
import secrets
import struct
from zoneinfo import ZoneInfo

from django.conf import settings

from lhie_candidate.domain.money import Currency, Money
from .types import Surface


_LOCALES = {code: json.loads(Path(__file__).with_name(code + '.json').read_text()) for code in ('id','en','ms')}
_ICON = re.compile(r'<tg-emoji\b[^>]*>(.*?)</tg-emoji>', re.S)
_TOKEN = r'[a-f0-9]{16}'
_DEFAULT_ICONS = {'message.search': '🔎', 'message.refresh': '🔄', 'menu.back': '⬅️',
                  'menu.products': '🛍️', 'menu.balance': '💰', 'menu.orders': '📦',
                  'menu.language': '🌐', 'menu.support': '💬', 'menu.channel': '📢', 'menu.api': '🔌'}


def value(customer, key, default=None):
    return customer.get(key, default) if isinstance(customer, dict) else getattr(customer, key, default)


def locale(customer):
    code = value(customer, 'locale')
    return code if code in _LOCALES else 'en'


def number(amount):
    return f'{amount:,}'.replace(',', '.')


def usdt(amount):
    return Money(Currency.USDT, int(amount)).format_decimal()


def html_text(text):
    """Escape copy without turning our own custom-emoji wrappers into text."""
    parts = re.split(r'(<tg-emoji\b[^>]*>.*?</tg-emoji>)', str(text), flags=re.S)
    return ''.join(part if part.startswith('<tg-emoji ') else escape(part) for part in parts)


def plain(text):
    return _ICON.sub(lambda match: match[1], text)


class CatalogPresenter:
    def __init__(self, repository, *, signing_key=None, custom_emoji=False, media_mapping=None,
                 bot_scope='synthetic', dollar_rail_selectable=False, clock=None):
        self.repository = repository
        self.signing_key = signing_key
        self.custom_emoji = custom_emoji
        self.media_mapping = dict(media_mapping or {})
        if bot_scope not in ('synthetic','LhieStoreTestBot'):
            raise ValueError('Only explicit test-bot asset scopes are allowed')
        self.bot_scope = bot_scope
        self.dollar_rail_selectable = dollar_rail_selectable
        self.clock = clock or (lambda: datetime.now(ZoneInfo('Asia/Jakarta')))
        self._icon_rows = None
        self.callback_handled = False
        self.callback_notice = None

    def _icons(self):
        if self._icon_rows is None:
            self._icon_rows = self.repository.icons()
        return self._icon_rows

    def _emoji(self, glyph, identifier):
        glyph = (glyph or '').strip() or '🛍️'
        if self.custom_emoji and identifier:
            return f'<tg-emoji emoji-id="{escape(str(identifier), quote=True)}">{escape(glyph)}</tg-emoji>'
        return escape(glyph)

    def _legacy_icons(self, text):
        by_source = {row['source_emoji']: row for row in self._icons() if row.get('source_emoji')}
        if not by_source:
            return text
        pattern = '|'.join(re.escape(source) for source in sorted(by_source, key=len, reverse=True))
        return re.sub(pattern, lambda match: self._emoji(by_source[match[0]]['fallback_emoji'],
                                                        by_source[match[0]].get('custom_emoji_id')), text)

    def t(self, customer, key, **replacements):
        template = _LOCALES[locale(customer)].get(key, _LOCALES['en'].get(key, _LOCALES['en']['translation.missing']))
        template = self._legacy_icons(template)
        return re.sub(r':([A-Za-z_][A-Za-z_0-9]*)',
                      lambda match: str(replacements.get(match[1], match[0])), template)

    def t_html(self, customer, key, **replacements):
        template = html_text(self.t(customer,key))
        return re.sub(r':([A-Za-z_][A-Za-z_0-9]*)',
                      lambda match: escape(str(replacements[match[1]])) if match[1] in replacements else match[0],template)

    def _button(self, customer, key, callback, *, slot=None):
        label = self.t(customer, key)
        result = {'text': plain(label), 'callback_data': callback, 'style': 'primary'}
        if slot:
            row = next((row for row in self._icons() if row['key'] == slot), None)
            glyph = row['fallback_emoji'] if row else _DEFAULT_ICONS.get(slot, '')
            text = plain(label)
            for prefix in (glyph, (row or {}).get('source_emoji'), _DEFAULT_ICONS.get(slot)):
                if prefix and text.startswith(prefix):
                    text = text[len(prefix):].lstrip()
                    break
            if row and self.custom_emoji and row.get('custom_emoji_id'):
                result.update(text=text, icon_custom_emoji_id=row['custom_emoji_id'],
                              _unicode_fallback=(glyph + ' ' + text).strip())
            else:
                result['text'] = (glyph + ' ' + text).strip()
        return result

    def _product_icon(self, product):
        glyph, identifier = product.get('icon'), product.get('custom_emoji_id')
        if not (glyph or '').strip() and not (identifier or '').strip():
            family = product.get('family') or {}
            glyph, identifier = family.get('icon'), family.get('custom_emoji_id')
        return (glyph or '').strip() or '🛍️', identifier

    def _icon_fields(self, glyph, identifier):
        return {'icon_custom_emoji_id': identifier} if self.custom_emoji and identifier else {}

    def _surface(self, customer, caption, rows, *, screen='catalog', html=True, photo_path=None):
        for row in rows:
            for button in row:
                button.setdefault('style', 'primary')
                button['text'] = plain(button['text'])
        options = {'parse_mode': 'HTML'} if html else {}
        if not html:
            caption = unescape(caption)
        # Only a mapping owned by this bot may authorize a legacy catalog path.
        photo = self.media_mapping.get(photo_path)
        asset = None
        if not photo and photo_path:
            asset = self.repository.asset(photo_path,self.bot_scope)
            photo = asset['file_id'] if asset else None
        if photo:
            options['photo_url'] = photo
            options['asset_version'] = asset['version'] if asset else 1
        result = Surface(caption, {'inline_keyboard': rows}, screen, locale(customer), options)
        self._icon_rows = None
        return result

    def _maintenance(self, customer):
        maintenance = self.repository.maintenance()
        if not maintenance.get('is_active'):
            return ''
        text = html_text(self.t(customer, 'menu.maintenance'))
        if maintenance.get('public_note'):
            text += self.t_html(customer, 'maintenance.admin_note', note=maintenance['public_note'])
        return text

    def home(self, customer, private=True):
        data = self.repository.home_data(value(customer, 'id'))
        hour = self.clock().astimezone(ZoneInfo('Asia/Jakarta')).hour
        greeting, glyph = ('morning','🌅') if 5 <= hour < 11 else ('afternoon','☀️') if 11 <= hour < 15 else ('evening','🌇') if 15 <= hour < 18 else ('night','🌙')
        greeting_text = self._legacy_icons(glyph) + ' ' + self.t(customer, 'welcome.greeting.' + greeting)
        activity = self.t(customer, 'welcome.activity', bot_users=data['bot_users'], successful_orders=data['successful_orders'])
        replacements = {'greeting': greeting_text, 'activity': '<blockquote>' + html_text(activity) + '</blockquote>'}
        rows = [[self._button(customer,'menu.products','katalog',slot='menu.products')]]
        if private:
            name = (value(customer,'first_name','') or '').strip()
            if not name or name.lower() == 'telegram customer':
                name = self.t(customer,'welcome.default_name')
            username = (value(customer,'username','') or '').strip().lstrip('@')
            profile = self.t(customer,'welcome.profile', telegram_id=escape(str(value(customer,'telegram_id'))),
                             username=escape('@' + username) if username else self.t(customer,'welcome.missing_value'),
                             transactions='Rp ' + number(int(data['idr_total'])) + ' / ' + usdt(data['usdt_total']) + ' USDT',
                             balance='Rp ' + number(int(data['balance'])) if data['balance'] is not None else self.t(customer,'welcome.balance_unavailable'))
            replacements.update(first_name=escape(name), profile='<blockquote>' + profile + '</blockquote>',
                                channel_invitation='\n\n' + self.t(customer,'welcome.channel_invitation') if data.get('channel_url') else '')
            rows += [[self._button(customer,'menu.balance','balance',slot='menu.balance'), self._button(customer,'menu.orders','menu:orders',slot='menu.orders')]]
            if data.get('api_enabled'):
                rows.append([self._button(customer,'menu.api','api:menu',slot='menu.api')])
            rows.append([self._button(customer,'menu.language','menu:language',slot='menu.language'),self._button(customer,'menu.support','menu:support',slot='menu.support')])
            if data.get('channel_url'):
                button = self._button(customer,'menu.channel','',slot='menu.channel')
                button.pop('callback_data')
                button['url'] = data['channel_url']
                rows.append([button])
        caption = self.t(customer,'welcome.caption' if private else 'welcome.group_caption', **replacements)
        notice = self._maintenance(customer)
        if notice:
            caption += '\n\n' + notice
        return self._surface(customer,caption,rows,screen='home',photo_path=data.get('banner_path'))

    def group(self, customer):
        return self.home(customer, private=False)

    def _signed(self, *values):
        payload = struct.pack('<q', values[0])
        if len(values) == 4:
            payload += struct.pack('<q', values[1])
        payload += struct.pack('>II', *values[-2:])
        key = self.signing_key if self.signing_key is not None else settings.SECRET_KEY
        key = key.encode() if isinstance(key,str) else key
        return 'v' + base64.urlsafe_b64encode(payload + hmac.digest(key,payload,'sha256')[:6]).decode().rstrip('=')

    def _decode(self, token, select=False):
        if not re.fullmatch(r'v[A-Za-z0-9_-]+',token):
            return None
        try:
            raw = base64.b64decode(token[1:] + '=' * (-len(token[1:]) % 4), altchars=b'-_',validate=True)
            size = 24 if select else 16
            if len(raw) != size + 6:
                return None
            payload = raw[:size]
            key = self.signing_key if self.signing_key is not None else settings.SECRET_KEY
            key = key.encode() if isinstance(key,str) else key
            if not hmac.compare_digest(raw[size:],hmac.digest(key,payload,'sha256')[:6]):
                return None
            ids = struct.unpack('<qq' if select else '<q',payload[:-8])
            values = (*ids,*struct.unpack('>II',payload[-8:]))
            return values if all(value > 0 for value in values) else None
        except (ValueError,struct.error):
            return None

    def _footer(self, customer, mode):
        if mode == 'catalog':
            return [[self._button(customer,'catalog.family.filter','catalog:categories',slot='message.search'),
                     self._button(customer,'catalog.family.refresh','catalog:refresh',slot='message.refresh'),
                     self._button(customer,'catalog.family.back_menu','family:back:menu',slot='menu.back')]]
        return [[self._button(customer,'catalog.category.back' if mode == 'category' else 'search.browse',
                              'catalog:categories' if mode == 'category' else 'katalog',slot='menu.back' if mode == 'category' else 'menu.products')]]

    def unavailable(self, customer, key='catalog.family.stale'):
        return self._surface(customer,html_text(self.t(customer,key)),[[self._button(customer,'catalog.family.back_menu','family:back:menu',slot='menu.back')]])

    def catalog(self, customer, page=1, search=None, category=None, origin_token=None, state=None):
        state = state if state is not None else {}
        search = ' '.join((search or '').split())[:100] or None
        mode = 'category' if category is not None else 'search' if search else 'catalog'
        category_row = self.repository.category(category) if category not in (None,'uncategorized') else None
        if category not in (None,'uncategorized') and category_row is None:
            return self.unavailable(customer,'catalog.category.unavailable')
        entries = self.repository.browse(category=category,search=search)
        per_page = 15 if mode == 'catalog' else 10
        pages = max(1,(len(entries) + per_page - 1)//per_page)
        page = max(1,page)
        if entries and page > pages:
            return self.unavailable(customer)
        page = min(page,pages)
        entries = entries[(page-1)*per_page:page*per_page]
        token = origin_token or secrets.token_hex(8)
        origin = {'token': token,'type': mode,'page': page,'category': category,'search': search,
                  'family_ids': [entry['id'] for entry in entries if entry['type'] == 'family']}
        state['catalog'] = origin
        state.pop('selection',None)
        if not entries:
            key = 'catalog.category.empty' if mode == 'category' else 'search.no_results' if mode == 'search' else 'catalog.empty'
            caption = self.t_html(customer,key,keyword=search or '')
            notice = self._maintenance(customer)
            if notice:
                caption += '\n\n' + notice
            return self._surface(customer,caption,self._footer(customer,mode),html=self.custom_emoji)
        title_key = 'catalog.header' if mode == 'catalog' else 'catalog.category.title' if mode == 'category' else 'search.title'
        title = self.t_html(customer,title_key,keyword=search or '')
        if search and ':keyword' not in _LOCALES[locale(customer)].get(title_key,''):
            title += escape(search)
        lines = [title]
        if mode == 'category':
            lines.append(html_text(self.t(customer,'catalog.category.label')) + (escape(category_row['name']) if category_row else html_text(self.t(customer,'catalog.uncategorized'))))
        if pages > 1:
            lines.append(html_text(self.t(customer,'catalog.family.page',page=page,pages=pages)))
        if category_row and category_row.get('description'):
            lines += ['',escape(category_row['description'])]
        notice = self._maintenance(customer)
        if notice:
            lines += ['',notice]
        lines += ['',html_text(self.t(customer,'catalog.family.choose' if mode == 'catalog' else 'catalog.category.choose_product'))]
        rows = []
        for index,entry in enumerate(entries,1):
            family = entry['type'] == 'family'
            single = entry['members'][0] if family and len(entry['members']) == 1 else None
            display = single or entry
            glyph, identifier = self._product_icon(display)
            available = entry['available_stock'] > 0
            if family and single is None:
                suffix = ' ' + self.t(customer,'catalog.family.button_plans',count=len(entry['members'])) if available else ' · ' + self.t(customer,'catalog.family.button_sold_out')
                callback = 'family:options:' + self._signed(entry['id'],1,page) + ':o' + token
            else:
                suffix = ' · ' + self.t(customer,'catalog.family.button_stock' if available else 'catalog.family.button_sold_out',stock=display['available_stock'])
                callback = 'product:' + str(display['id']) + ':o' + token
            label = display['name'] + suffix
            button = {'text': label if self.custom_emoji and identifier else glyph + ' ' + label,
                      **self._icon_fields(glyph,identifier),'callback_data': callback,'style': 'primary' if available else 'danger'}
            if self.custom_emoji and identifier:
                button['_unicode_fallback'] = glyph + ' ' + plain(label)
            if mode != 'catalog':
                button = {'text': self.t(customer,'catalog.family.number_button',number=index), 'callback_data': callback,'style':button['style']}
                if family:
                    lines += ['',f'{index:02d}. {self._emoji(glyph,identifier)} {escape(display["name"])}{html_text(suffix)}']
                else:
                    lines += ['',f'{index}. {self._emoji(glyph,identifier)} {escape(display["name"])}\n' + html_text(self.t(customer,'catalog.family.standalone_summary',price=number(display['price_amount']),stock=display['available_stock']))]
            rows.append([button])
        pagination = []
        for next_page,key in ((page-1,'catalog.family.previous_page'),(page+1,'catalog.family.next_page')):
            if not 1 <= next_page <= pages:
                continue
            callback = f'category:{category}:page:{next_page}' if mode == 'category' else f'family:search:{token}:{next_page}' if mode == 'search' else f'family:list:{next_page}'
            pagination.append(self._button(customer,key,callback,slot='menu.back' if next_page < page else None))
        if pagination:
            rows.append(pagination)
        if mode == 'search' and pages > 1:
            lines += ['',html_text(self.t(customer,'search.more'))]
        rows += self._footer(customer,mode)
        # Catalog copy itself has no markup in PHP; HTML is necessary only when a DB icon is premium.
        return self._surface(customer,'\n'.join(lines),rows,html=mode != 'catalog' or self.custom_emoji)

    def categories(self, customer):
        categories = self.repository.categories()
        products = self.repository.products()
        rows = [[{'text': self._legacy_icons('📂 ') + category['name'],'callback_data': 'category:' + str(category['id'])}] for category in categories]
        uncategorized = any(p['category_id'] is None for p in products)
        if uncategorized:
            rows.append([self._button(customer,'catalog.uncategorized','category:uncategorized')])
        rows.append([self._button(customer,'catalog.family.back_menu','family:back:menu',slot='menu.back')])
        return self._surface(customer,html_text(self.t(customer,'catalog.category_filter' if categories or uncategorized else 'catalog.empty')),rows,html=self.custom_emoji)

    def family(self, customer, family_id, page=1, return_page=1, origin_token=None, state_token=None, state=None):
        state = state if state is not None else {}
        family = self.repository.family(family_id)
        if family is None:
            return self.unavailable(customer,'catalog.family.unavailable')
        members = family['members']
        pages = max(1,(len(members)+9)//10)
        page = min(max(1,page),pages)
        return_page = max(1,return_page)
        state_token = state_token or secrets.token_hex(8)
        origin = state.get('catalog') if state.get('catalog',{}).get('token') == origin_token else None
        state['family'] = {'id': family_id,'page':page,'return_page':return_page,'token':state_token,'origin':origin}
        state.pop('selection',None)
        glyph,identifier = (family.get('icon') or '🛍️'),family.get('custom_emoji_id')
        lines = [self.t(customer,'catalog.family.options_title',icon=self._emoji(glyph,identifier),name=escape(family['name'])).strip(),
                 '━━━━━━━━━━━━━━',html_text(self.t(customer,'catalog.family.sold_total',sold=sum(p['sold_quantity'] for p in members))),'━━━━━━━━━━━━━━']
        if pages > 1:
            lines.append(html_text(self.t(customer,'catalog.family.page',page=page,pages=pages)))
        lines.append('')
        buttons = []
        for index,member in enumerate(members[(page-1)*10:page*10],1):
            glyph,identifier = self._product_icon(member)
            block = [f'{index:02d}. {self._emoji(glyph,identifier)} {escape(member.get("family_option_label") or member["name"])}',
                     html_text(self.t(customer,'catalog.family.option_price',amount=number(member['price_amount'])))]
            if member.get('usdt_price_amount') is not None:
                block.append(html_text(self.t(customer,'catalog.family.option_usdt_price',amount=usdt(member['usdt_price_amount']))))
            available = member['available_stock'] > 0
            block += [html_text(self.t(customer,'catalog.family.option_stock' if available else 'catalog.family.option_sold_out',stock=member['available_stock'])),
                      html_text(self.t(customer,'catalog.family.option_sold',sold=member['sold_quantity']))]
            if index > 1:
                lines.append('')
            lines.append('<blockquote>' + '\n'.join(block) + '</blockquote>')
            button = {'text':str(index),**self._icon_fields(glyph,identifier),'style':'primary' if available else 'danger'}
            if available:
                button['callback_data'] = 'family:select:' + self._signed(family_id,member['id'],page,return_page)
            else:
                button['disabled'] = {}
            buttons.append(button)
        notice = self._maintenance(customer)
        if notice:
            lines += ['',notice]
        rows = [buttons[index:index+2] for index in range(0,len(buttons),2)]
        navigation = []
        for next_page,key in ((page-1,'catalog.family.previous_page'),(page+1,'catalog.family.next_page')):
            if 1 <= next_page <= pages:
                navigation.append(self._button(customer,key,'family:options:' + self._signed(family_id,next_page,return_page) + ':s' + state_token))
        if navigation:
            rows.append(navigation)
        rows.append([self._button(customer,'catalog.family.back_list',f'family:list:{return_page}' + (':o'+origin_token if origin_token else ''),slot='menu.back')])
        return self._surface(customer,'\n'.join(lines),rows)

    def _copy(self, product, field, code):
        column = field + '_' + code if code in ('en','ms') and (product.get(field + '_' + code) or '').strip() else field
        text = product.get(column) or ''
        metadata = product.get('telegram_content') or {}
        if isinstance(metadata,str):
            metadata = json.loads(metadata)
        content = metadata.get(column) or {}
        if not self.custom_emoji or content.get('text') != text:
            return escape(text)
        units = text.encode('utf-16-le')
        pieces, cursor = [], 0
        for entity in sorted(content.get('entities',[]),key=lambda item: item.get('offset',-1)):
            offset,length = entity.get('offset'),entity.get('length')
            identifier = entity.get('custom_emoji_id')
            if (entity.get('type') != 'custom_emoji' or type(offset) is not int or type(length) is not int
                    or offset < cursor or length < 1 or (offset+length)*2 > len(units)
                    or not isinstance(identifier,str) or not re.fullmatch(r'[0-9]+',identifier)):
                continue
            try:
                before = units[cursor*2:offset*2].decode('utf-16-le')
                glyph = units[offset*2:(offset+length)*2].decode('utf-16-le')
            except UnicodeDecodeError:
                continue
            pieces += [escape(before),self._emoji(glyph,identifier)]
            cursor = offset+length
        return ''.join(pieces) + escape(units[cursor*2:].decode('utf-16-le'))

    def _quote(self, product, quantity):
        base = product['price_amount']
        Money(Currency.IDR,base).multiply(quantity)
        tiers = self.repository.tiers(product['id'])
        previous_minimum, previous_price, previous_usdt = 1,base,product.get('usdt_price_amount')
        if len(tiers) > 5:
            return None
        for tier in tiers:
            minimum,price,dollars = tier['minimum_quantity'],tier['price_amount'],tier.get('usdt_price_amount')
            if not (previous_minimum < minimum <= 20 and 0 < price < previous_price):
                return None
            if (previous_usdt is None and dollars is not None) or (previous_usdt is not None and (dollars is None or not 0 < dollars < previous_usdt)):
                return None
            previous_minimum,previous_price,previous_usdt = minimum,price,dollars
        active = next((tier for tier in reversed(tiers) if quantity >= tier['minimum_quantity']),None)
        unit = active['price_amount'] if active else base
        total = Money(Currency.IDR,unit).multiply(quantity).units
        dollar_unit = active.get('usdt_price_amount') if active else product.get('usdt_price_amount')
        dollar_total = Money(Currency.USDT,dollar_unit).multiply(quantity).units if dollar_unit is not None else None
        return {'tiers':tiers,'active':active,'unit':unit,'total':total,'dollar_unit':dollar_unit,'dollar_total':dollar_total,
                'next':next((tier for tier in tiers if quantity < tier['minimum_quantity'] <= product['available_stock']),None)}

    def detail(self, customer, product_id, *, state=None, navigation=None, quantity=1, interaction_token=None, quantity_token=None):
        state = state if state is not None else {}
        product = self.repository.product(product_id)
        if product is None:
            return self.unavailable(customer,'catalog.product_unavailable')
        navigation = dict(navigation or {})
        if not navigation and product.get('product_family_id'):
            family = self.repository.family(product['product_family_id'])
            if family and len(family['members']) > 1:
                index = next((index for index,p in enumerate(family['members']) if p['id'] == product_id),0)
                navigation = {'family_id':family['id'],'page':index//10+1,'return_page':1}
                state['family'] = {'id':family['id'],'page':index//10+1,'return_page':1,
                                   'token':secrets.token_hex(8),'origin':None}
        stock = product['available_stock']
        quantity = min(max(1,quantity),stock) if stock else 1
        try:
            quote = self._quote(product,quantity)
        except ValueError:
            quote = None
        token = interaction_token or secrets.token_hex(8)
        qty_token = quantity_token or token
        selection = {'product_id':product_id,'quantity':quantity,'interaction_token':token,'quantity_token':qty_token,
                     'mode':'browsing','navigation':navigation,'locale':locale(customer)}
        can_select = stock > 0 and quote is not None
        notice = self._maintenance(customer)
        dual = bool(not notice and can_select and quote['dollar_total'] is not None and self.dollar_rail_selectable)
        def price_line(key, amount, dollars=None, **values):
            return html_text(self.t(customer,key + ('_dual' if dual else ''),amount=number(amount),
                                    usdt=usdt(dollars) if dollars is not None else '',**values))
        if can_select:
            state['selection'] = selection
        else:
            state.pop('selection',None)
        glyph,identifier = self._product_icon(product)
        lines = [self._emoji(glyph,identifier) + ' ' + escape(product['name']),'',
                 price_line('catalog.detail.base_price',product['price_amount'],product.get('usdt_price_amount'))]
        if quote:
            for tier in quote['tiers']:
                if tier['minimum_quantity'] <= stock:
                    key = 'catalog.detail.tier_active' if quote['active'] == tier else 'catalog.detail.tier'
                    lines.append(price_line(key,tier['price_amount'],tier.get('usdt_price_amount'),minimum=tier['minimum_quantity']))
        lines.append(html_text(self.t(customer,'catalog.stock') + str(stock) if stock else self.t(customer,'catalog.status.sold_out')))
        if can_select:
            lines += ['',html_text(self.t(customer,'catalog.detail.quantity',quantity=quantity))]
            if quote['active']:
                lines.append(price_line('catalog.detail.effective_unit',quote['unit'],quote['dollar_unit']))
            lines.append(price_line('catalog.detail.subtotal',quote['total'],quote['dollar_total']))
            savings = product['price_amount']*quantity-quote['total']
            dollar_savings = (product['usdt_price_amount']*quantity-quote['dollar_total']) if quote['dollar_total'] is not None else 0
            if savings > 0 and dollar_savings > 0:
                lines.append(html_text(self.t(customer,'catalog.detail.savings_dual',idr=number(savings),usdt=usdt(dollar_savings))))
            elif savings > 0:
                lines.append(html_text(self.t(customer,'catalog.detail.savings',amount=number(savings))))
            elif dollar_savings > 0:
                lines.append(html_text(self.t(customer,'catalog.detail.savings_usdt',amount=usdt(dollar_savings))))
            if quote['next']:
                tier = quote['next']
                try:
                    total = Money(Currency.IDR,tier['price_amount']).multiply(tier['minimum_quantity']).units
                    dollars = Money(Currency.USDT,tier['usdt_price_amount']).multiply(tier['minimum_quantity']).units if tier.get('usdt_price_amount') is not None else None
                except ValueError:
                    pass
                else:
                    lines += ['',html_text(self.t(customer,'catalog.detail.next_tier',quantity=tier['minimum_quantity']-quantity)),
                              price_line('catalog.detail.next_tier_total',total,dollars,quantity=tier['minimum_quantity'])]
        lines += ['',html_text(self.t(customer,'catalog.details'))]
        description = self._copy(product,'description',locale(customer))
        lines.append('<blockquote>' + (description if description.strip() else html_text(self.t(customer,'catalog.description_missing'))) + '</blockquote>')
        warranty = self._copy(product,'warranty_policy',locale(customer))
        if warranty.strip():
            lines += ['','<blockquote>' + html_text(self.t(customer,'catalog.replacement_refund_policy')) + '\n' + warranty + '</blockquote>']
        if notice:
            lines += ['',notice]
            if not stock:
                lines.append(html_text(self.t(customer,'catalog.sold_out_guidance')))
        elif not stock:
            lines += ['',html_text(self.t(customer,'catalog.sold_out_guidance'))]
        else:
            lines += ['','<blockquote>' + html_text(self.t(customer,'catalog.qris_support')) + '</blockquote>']
        rows = []
        if can_select:
            rows.append([self._button(customer,'catalog.detail.decrease',f'detail:qty:-:{product_id}:{qty_token}'),
                         {'text':str(quantity),'callback_data':f'detail:qty:current:{product_id}:{token}'},
                         self._button(customer,'catalog.detail.increase',f'detail:qty:+:{product_id}:{qty_token}')])
            custom = self._button(customer,'catalog.detail.custom_quantity',f'detail:qty:manual:{product_id}:{token}')
            if quote['next']:
                rows.append([{'text':self.t(customer,'catalog.detail.next_tier_button',quantity=quote['next']['minimum_quantity']),
                              'callback_data':f'detail:qty:tier:{product_id}:{token}'},custom])
            else:
                rows.append([custom])
            if notice:
                rows.append([self._button(customer,'catalog.check_status','checkout-maintenance:status')])
            else:
                rows.append([{'text':self.t(customer,'catalog.detail.pay_qris',amount=number(quote['total'])),
                              'callback_data':f'detail:pay:qris:{product_id}:{token}','style':'primary'}])
                if self.repository.spending_available():
                    rows.append([self._button(customer,'catalog.detail.pay_balance',f'detail:pay:balance:{product_id}:{token}',slot='menu.balance')])
                if dual:
                    rows.append([{'text':self.t(customer,'catalog.detail.pay_binance',amount=usdt(quote['dollar_total'])),
                                  'callback_data':f'detail:pay:binance:{product_id}:{token}'}])
            rows.append([self._button(customer,'catalog.detail.back_products',f'detail:back:{product_id}:{token}',slot='menu.back')])
        else:
            if notice:
                rows.append([self._button(customer,'catalog.check_status','checkout-maintenance:status')])
            callback,key = self._back_callback(product,navigation)
            rows.append([self._button(customer,key,callback,slot='menu.back')])
        photo_path = product.get('telegram_image_path') or (product.get('family') or {}).get('telegram_image_path')
        return self._surface(customer,'\n'.join(lines),rows,screen='product',photo_path=photo_path)

    def _back_callback(self, product, navigation):
        if navigation.get('family_id'):
            callback = 'family:options:' + self._signed(navigation['family_id'],navigation.get('page',1),navigation.get('return_page',1))
            if navigation.get('origin_token'):
                callback += ':o' + navigation['origin_token']
            return callback,'catalog.family.back_options'
        if navigation.get('origin_token'):
            return 'family:list:1:o' + navigation['origin_token'],'catalog.family.back_list'
        category = product.get('category_id') or 'uncategorized'
        return f'category:{category}','catalog.back_categories'

    def quantity(self, customer, product_id, quantity, *, state=None, feedback=None):
        state = state if state is not None else {}
        selection = state.get('selection')
        product = self.repository.product(product_id)
        if not selection or not product or selection['product_id'] != product_id:
            return self.unavailable(customer,'catalog.selection.stale')
        if selection['mode'] != 'awaiting_custom_quantity':
            selection['previous_quantity'] = selection['quantity']
        selection['interaction_token'] = secrets.token_hex(8)
        selection['quantity_token'] = secrets.token_hex(8)
        selection['mode'] = 'awaiting_custom_quantity'
        selection['quantity'] = min(quantity,product['available_stock'])
        lines = [html_text(feedback),''] if feedback else []
        glyph,identifier = self._product_icon(product)
        lines += [html_text(self.t(customer,'catalog.quantity.custom_title')),'━━━━━━━━━━━━━━',
                  html_text(self.t(customer,'catalog.quantity.product')) + self._emoji(glyph,identifier) + ' ' + escape(product['name']),
                  html_text(self.t(customer,'catalog.quantity.current',quantity=selection['quantity'])),
                  html_text(self.t(customer,'catalog.quantity.available_stock')) + str(product['available_stock']),'',
                  html_text(self.t(customer,'catalog.quantity.custom_instruction',example=5))]
        rows = [[self._button(customer,'catalog.quantity.cancel',f'detail:qty:cancel:{product_id}:' + selection['interaction_token'])]]
        return self._surface(customer,'\n'.join(lines),rows,screen='product',photo_path=product.get('telegram_image_path') or (product.get('family') or {}).get('telegram_image_path'))

    def quantity_input(self, customer, text, state):
        selection = state.get('selection') or {}
        if selection.get('mode') != 'awaiting_custom_quantity':
            return None
        notice = self._maintenance(customer)
        if notice:
            state.pop('selection',None)
            return self._surface(customer,notice,[[self._button(customer,'catalog.check_status','checkout-maintenance:status')]])
        product = self.repository.product(selection['product_id'])
        if not product or product['available_stock'] < 1:
            state.pop('selection',None)
            return self.unavailable(customer,'catalog.selection.unavailable')
        candidate = text.strip()
        valid = bool(re.fullmatch(r'[1-9][0-9]*',candidate)) and len(candidate) <= 19
        quantity = int(candidate) if valid else 0
        if not valid or quantity > product['available_stock']:
            key = 'catalog.quantity.retry' if not valid else 'catalog.quantity.over_stock'
            feedback = self.t(customer,key,stock=product['available_stock'])
            if not valid and selection['quantity'] > product['available_stock']:
                feedback = self.t(customer,'catalog.quantity.stock_changed') + '\n\n' + feedback
            return self.quantity(customer,product['id'],selection['quantity'],state=state,feedback=feedback)
        return self.detail(customer,product['id'],state=state,quantity=quantity,navigation=selection['navigation'])

    def route(self, customer, data, state):
        self._icon_rows = None
        self.callback_handled = False
        self.callback_notice = None
        if data in ('menu','home','start','/start','/menu','menu:home','family:back:menu'):
            state.clear()
            return self.home(customer)
        if data in ('katalog','catalog','catalog:refresh'):
            state.clear()
            return self.catalog(customer,state=state)
        if data in ('catalog:categories','back:categories'):
            state.pop('selection',None)
            return self.categories(customer)
        match = re.fullmatch(r'category:([^:]+)(?::page:([1-9][0-9]*))?',data)
        if match:
            return self.catalog(customer,category=match[1],page=int(match[2] or 1),state=state)
        match = re.fullmatch(r'family:list:([1-9][0-9]*)(?::o(' + _TOKEN + '))?',data)
        if match:
            origin = state.get('catalog') or {}
            if match[2]:
                if origin.get('token') != match[2]:
                    return None
                return self.catalog(customer,page=origin['page'],category=origin.get('category'),search=origin.get('search'),state=state)
            return self.catalog(customer,page=int(match[1]),state=state)
        match = re.fullmatch(r'family:search:(' + _TOKEN + r'):([1-9][0-9]*)',data)
        if match:
            origin = state.get('catalog') or {}
            if origin.get('token') != match[1] or origin.get('type') != 'search':
                return None
            return self.catalog(customer,page=int(match[2]),search=origin['search'],state=state)
        match = re.fullmatch(r'family:options:(v[A-Za-z0-9_-]+)(?::([os])(' + _TOKEN + '))?',data)
        if match:
            decoded = self._decode(match[1])
            if not decoded:
                return None
            family_id,page,return_page = decoded
            origin = state.get('catalog') or {}
            family_state = state.get('family') or {}
            origin_token = None
            if match[2] == 'o':
                if origin.get('token') != match[3] or family_id not in origin.get('family_ids',[]) or origin.get('page') != return_page:
                    return None
                origin_token = match[3]
            elif match[2] == 's':
                if family_state.get('token') != match[3] or family_state.get('id') != family_id or family_state.get('return_page') != return_page:
                    return None
                origin_token = (family_state.get('origin') or {}).get('token')
            elif family_state.get('id') != family_id:
                return None
            family = self.repository.family(family_id)
            if not family or page > max(1,(len(family['members'])+9)//10):
                return None
            return self.family(customer,family_id,page,return_page,origin_token,state=state)
        match = re.fullmatch(r'family:select:(v[A-Za-z0-9_-]+)',data)
        if match:
            decoded = self._decode(match[1],select=True)
            if not decoded:
                return None
            family_id,product_id,page,return_page = decoded
            family_state = state.get('family') or {}
            if (family_state.get('id'),family_state.get('page'),family_state.get('return_page')) != (family_id,page,return_page):
                return None
            family = self.repository.family(family_id)
            member = next((p for p in (family or {}).get('members',[])[(page-1)*10:page*10] if p['id'] == product_id),None)
            if not member or member['available_stock'] < 1:
                return None
            navigation = {'family_id':family_id,'page':page,'return_page':return_page,
                          'origin_token':(family_state.get('origin') or {}).get('token')}
            selection = state.get('selection') or {}
            if selection.get('mode') == 'browsing' and selection.get('product_id') == product_id and selection.get('navigation') == navigation:
                self.callback_handled = True
                return None
            return self.detail(customer,product_id,state=state,navigation=navigation)
        match = re.fullmatch(r'product:([1-9][0-9]*)(?::o(' + _TOKEN + '))?',data)
        if match:
            if match[2] and (state.get('catalog') or {}).get('token') != match[2]:
                return None
            return self.detail(customer,int(match[1]),state=state,navigation={'origin_token':match[2]} if match[2] else None)
        match = re.fullmatch(r'detail:back:([1-9][0-9]*):(' + _TOKEN + ')',data)
        if match:
            selection = state.get('selection') or {}
            if selection.get('product_id') != int(match[1]) or selection.get('interaction_token') != match[2]:
                return None
            navigation = selection.get('navigation') or {}
            if navigation.get('family_id'):
                return self.family(customer,navigation['family_id'],navigation.get('page',1),navigation.get('return_page',1),navigation.get('origin_token'),state=state)
            origin = state.get('catalog') or {}
            if navigation.get('origin_token') and origin.get('token') == navigation['origin_token']:
                return self.catalog(customer,page=origin['page'],category=origin.get('category'),search=origin.get('search'),state=state)
            product = self.repository.product(int(match[1]))
            return self.catalog(customer,category=str((product or {}).get('category_id') or 'uncategorized'),state=state)
        match = re.fullmatch(r'detail:qty:(-|\+|current|tier|manual|cancel):([1-9][0-9]*):(' + _TOKEN + ')',data)
        if match:
            action,product_id,token = match[1],int(match[2]),match[3]
            selection = state.get('selection') or {}
            expected = selection.get('quantity_token' if action in ('-','+') else 'interaction_token')
            if selection.get('product_id') != product_id or expected != token or selection.get('locale') != locale(customer):
                return None
            if selection.get('mode') != ('awaiting_custom_quantity' if action == 'cancel' else 'browsing'):
                return None
            product = self.repository.product(product_id)
            if not product or product['available_stock'] < 1:
                state.pop('selection',None)
                return self.unavailable(customer,'catalog.selection.unavailable')
            stock = product['available_stock']
            if self._maintenance(customer):
                state.pop('selection',None)
                return self._surface(customer,self._maintenance(customer),[[self._button(customer,'catalog.check_status','checkout-maintenance:status')]])
            if action == 'current':
                if selection['quantity'] > stock:
                    state.pop('selection',None)
                    return self.unavailable(customer,'catalog.selection.unavailable')
                self.callback_handled = True
                return None
            if action == 'manual':
                if selection['quantity'] > stock:
                    self.callback_notice = plain(self.t(customer,'catalog.quantity.stock_changed'))
                return self.quantity(customer,product_id,min(selection['quantity'],stock),state=state)
            quantity = selection['quantity']
            if action == 'cancel':
                quantity = selection.get('previous_quantity',quantity)
            if quantity > stock:
                self.callback_notice = plain(self.t(customer,'catalog.quantity.stock_changed'))
                return self.detail(customer,product_id,state=state,quantity=stock,navigation=selection.get('navigation'),
                                   quantity_token=selection['quantity_token'] if selection['mode'] == 'browsing' else None)
            if action in ('-','+'):
                quantity += 1 if action == '+' else -1
                if quantity < 1 or quantity > stock:
                    self.callback_handled = True
                    self.callback_notice = plain(self.t(customer,'catalog.quantity.minimum' if quantity < 1 else 'catalog.quantity.maximum',stock=stock))
                    return None
            if action == 'tier':
                product = self.repository.product(product_id)
                quote = self._quote(product,quantity) if product else None
                if not quote or not quote['next']:
                    return None
                quantity = quote['next']['minimum_quantity']
            return self.detail(customer,product_id,state=state,quantity=max(1,quantity),navigation=selection.get('navigation'),
                               quantity_token=selection['quantity_token'] if selection['mode'] == 'browsing' else None)
        return None
