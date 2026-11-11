"""Stock recipient snapshots retain PHP customer/channel layouts verbatim."""
from .broadcast import markup
from .bulk_content import button, frozen, product_name, product_url, translate
from .policy import NotEligible
from .price import usdt


def notifiable(movement):
    kind, quantity = movement['type'], movement['quantity']
    sold_out = quantity < 0 and movement.get('became_unavailable') is True
    if kind == 'availability':
        return (quantity > 0 and movement.get('available_before') == 0
                and (movement.get('available_after') or 0) > 0) or sold_out
    return kind == 'adjustment' and (quantity > 0 or sold_out) or kind == 'sale' and sold_out


def render(product, movement, event, code, *, store_channel=False, ui_icons=(), bot_username=None):
    code = 'en' if store_channel else code
    def tr(key, **values):
        return translate(code, 'notification.' + key, ui_icons, **values)
    name = product_name(product)
    stock = movement.get('available_after')
    if stock is None:
        stock = max(0, product['stock'] - product['reserved_stock'])
    sold_out = movement['quantity'] < 0
    tier = event.get('promoted_tier_minimum')
    idr = lambda value: f'{value:,}'.replace(',', '.')
    usd = lambda value: usdt(value).removesuffix(' USDT')
    if store_channel:
        if sold_out:
            lines = [name, tr('store_channel.sold_out.label'), '━━━━━━━━━━━━━━', '',
                     tr('store_channel.sold_out.confirmation'), tr('store_channel.sold_out.thanks'), '',
                     tr('store_channel.sold_out.unavailable'), tr('store_channel.sold_out.follow_up')]
        else:
            unit = tr('store_channel.restock.price', amount=idr(product['price_amount']))
            if product['usdt_price_amount'] is not None:
                unit += ' / ' + tr('store_channel.restock.binance', amount=usd(product['usdt_price_amount']))
            lines = [tr('store_channel.restock.title', product=name),
                     tr('store_channel.restock.added', quantity=movement['quantity']),
                     tr('store_channel.restock.available', stock=stock), unit]
            if tier is not None:
                line = tr('store_channel.restock.tier', minimum=tier, amount=idr(event['promoted_tier_price_amount']))
                if event.get('promoted_tier_usdt_price_amount') is not None:
                    line += ' / ' + usd(event['promoted_tier_usdt_price_amount']) + ' USDT each'
                lines.append(line)
        keyboard = {} if sold_out else {'inline_keyboard': [[button(
            translate('en', 'notification.store_channel.cta'), ui_icons,
            style='primary', url=product_url(product['id'], bot_username))]]}
    elif sold_out:
        lines = [tr('product.sold_out.title'), '━━━━━━━━━━━━━━', name,
                 tr('product.sold_out.message'), '', tr('product.sold_out.catalog')]
        keyboard = {}
    else:
        unit = tr('product.price' if product['usdt_price_amount'] is None else 'product.price_dual',
                  amount=idr(product['price_amount']), usd='' if product['usdt_price_amount'] is None else usd(product['usdt_price_amount']))
        lines = [tr('product.restock.title'), '━━━━━━━━━━━━━━', tr('product.restock.lead'), name,
                 unit, tr('product.stock', stock=stock)]
        if tier is not None:
            lines.append(tr('product.tier' if event.get('promoted_tier_usdt_price_amount') is None else 'product.tier_dual',
                            minimum=tier, amount=idr(event['promoted_tier_price_amount']),
                            usd='' if event.get('promoted_tier_usdt_price_amount') is None else usd(event['promoted_tier_usdt_price_amount'])))
        description = product.get('description' if code == 'id' else 'description_' + code) or ''
        if not description.strip():
            description = product.get('description') or ''
        if not description.strip():
            description = tr('product.description_missing')
        lines.extend(('', tr('product.description'), description, '', tr('broadcast.help')))
        keyboard = {'inline_keyboard': [[button(
            translate(code, 'notification.product.cta'), ui_icons,
            style='primary', callback_data='product:' + str(product['id']))]]}
    return '\n'.join(lines), keyboard


def payload(recipient, effect, *, asset=None):
    retained = frozen(effect)
    if retained is not None:
        return retained
    if str(recipient['telegram_id']) != str(effect['telegram_chat_id']):
        raise NotEligible()
    result = {'chat_id': effect['telegram_chat_id'], 'parse_mode': 'HTML',
              'reply_markup': markup(recipient.get('reply_markup'), effect['locale'])}
    if recipient.get('photo_path') and len(recipient['text']) <= 1024:
        if not asset or not isinstance(asset.get('file_id'), str) or not asset['file_id']:
            raise NotEligible()
        result.update(photo=asset['file_id'], caption=recipient['text'])
    else:
        result['text'] = recipient['text']
    return result
