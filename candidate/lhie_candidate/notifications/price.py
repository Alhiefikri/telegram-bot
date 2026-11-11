"""Price notices render immutable event economics, not current Product prices."""
from html import escape

from .bulk_content import PRICE_CHANNEL, button, frozen, translate
from .policy import NotEligible


def usdt(amount):
    if type(amount) is not int or amount < 0:
        raise NotEligible()
    whole, fraction = divmod(amount, 1_000_000)
    return (str(whole) + f'.{fraction:06d}').rstrip('0').rstrip('.') + ' USDT'


def payload(event, effect):
    retained = frozen(effect) if 'telegram_method' in effect else None
    if retained is not None:
        return retained
    locale = effect['locale'] if effect['locale'] in ('id', 'en', 'ms') else 'en'
    def tr(key, **values):
        return translate(locale, 'notification.price.' + key, event.get('ui_icons', ()), **values)
    def idr(amount):
        if type(amount) is not int or amount < 0:
            raise NotEligible()
        return 'Rp' + f'{amount:,}'.replace(',', ',' if locale == 'en' else '.')
    old, new = event['old_price_amount'], event['new_price_amount']
    before, after = event['old_usdt_price_amount'], event['new_usdt_price_amount']
    changes = []
    if old != new:
        changes.append(new < old)
    if before is not None and after is not None and before != after:
        changes.append(after < before)
    if (before is None) != (after is None):
        changes.append(False)
    decrease = bool(changes) and all(changes)
    glyph = event.get('product_icon') or '📦'
    name = escape(event['product_name'])
    identifier = event.get('product_custom_emoji_id')
    icon = escape(glyph)
    if identifier and str(identifier).isascii() and str(identifier).isdigit():
        icon = f'<tg-emoji emoji-id="{identifier}">{icon}</tg-emoji>'
    lines = [tr('title.drop' if decrease else 'title.update'), '━━━━━━━━━━━━━━',
             tr('lead.drop' if decrease else 'lead.update'), icon + ' ' + name, '',
             tr('qris.label'), idr(old) + ' → ' + idr(new)]
    if before != after:
        line = (tr('usdt.now_available', amount=usdt(after)) if before is None else
                tr('usdt.unavailable') if after is None else usdt(before) + ' → ' + usdt(after))
        lines.extend(('', tr('binance.label'), line))
    savings = []
    if new < old:
        savings.append(idr(old - new))
    if before is not None and after is not None and after < before:
        savings.append(usdt(before - after))
    lines.append('')
    if decrease and savings:
        lines.extend((tr('savings', amount=' + '.join(savings)), tr('new_price_ready')))
    else:
        lines.extend((tr('future_checkout'), tr('existing_orders')))
    lines.extend(('', tr('help')))
    keyboard = button(translate(locale, 'notification.price.cta'), event.get('ui_icons', ()),
                      style='primary', callback_data='product:' + str(event['product_id']))
    if effect.get('effect_type') == PRICE_CHANNEL:
        keyboard = button(translate('en', 'notification.store_channel.cta'),
                          event.get('ui_icons', ()), style='primary', url=event['product_url'])
    return {'chat_id': effect['telegram_chat_id'], 'text': '\n'.join(lines), 'parse_mode': 'HTML',
            'reply_markup': {'inline_keyboard': [[keyboard]]}}
