"""PHP bulk copy with database-owned icons, frozen before delivery."""
import copy
import json
import re
from html import escape

from ..receipt.render import _CATALOGS
from .policy import NotEligible

PRICE_CHANNEL = 'price_change.store_channel'


def locale(value):
    return value if value in ('id', 'en', 'ms') else 'en'


def translate(code, key, icons=(), *, custom=True, **values):
    catalog = _CATALOGS[locale(code)]
    text = catalog.get(key, _CATALOGS['en'].get(key, key))
    by_source = {row['source_emoji']: row for row in icons if row.get('source_emoji')}
    pattern = re.compile('|'.join(re.escape(s) for s in sorted(by_source, key=len, reverse=True))) if by_source else None
    def replace(match):
        row = by_source[match.group()]
        return icon(row.get('fallback_emoji'), row.get('custom_emoji_id'), custom=custom)
    return ''.join(str(values[part[1:]]) if part.startswith(':') and part[1:] in values
                   else pattern.sub(replace, part) if pattern else part
                   for part in re.split(r'(:[A-Za-z_][A-Za-z_0-9]*)', text))


def icon(glyph, identifier=None, *, custom=True):
    glyph = escape(str(glyph or '').strip())
    identifier = str(identifier or '').strip()
    if custom and identifier.isascii() and identifier.isdigit():
        return f'<tg-emoji emoji-id="{identifier}">{glyph}</tg-emoji>'
    return glyph

def button(label, icons=(), **attributes):
    value = {'text': label, **attributes}
    rows = sorted(icons, key=lambda row: len(row.get('source_emoji') or ''), reverse=True)
    for row in rows:
        source = row.get('source_emoji')
        if not source or not label.startswith(source):
            continue
        text = label[len(source):].lstrip()
        fallback = (row.get('fallback_emoji') or source) + (' ' + text if text else '')
        identifier = str(row.get('custom_emoji_id') or '').strip()
        if identifier.isascii() and identifier.isdigit():
            value.update(text=text or label, icon_custom_emoji_id=identifier,
                         _unicode_fallback=fallback)
        else:
            value['text'] = fallback
        return value
    return value


def product_name(product, *, custom=True):
    glyph, identifier = product.get('icon'), product.get('custom_emoji_id')
    if not (glyph or '').strip() and not (identifier or '').strip():
        glyph, identifier = product.get('family_icon'), product.get('family_custom_emoji_id')
    return icon(glyph or '📦', identifier, custom=custom) + ' ' + escape(product['name'])


def icons(db):
    return db.rows('SELECT key,fallback_emoji,source_emoji,custom_emoji_id FROM public.telegram_ui_icons ORDER BY sort_order,label,id')


def freeze(db, effect, rendered):
    """Only the creator writes content; replay never replaces a retained snapshot."""
    db.execute('''UPDATE public.telegram_outbound_effects SET outcome_metadata=
        (COALESCE(outcome_metadata::jsonb,'{}'::jsonb) || jsonb_build_object('bulk_payload',%s::jsonb))::json
        WHERE id=%s AND NOT (COALESCE(outcome_metadata::jsonb,'{}'::jsonb) ? 'bulk_payload')''',
        (json.dumps(rendered, ensure_ascii=False), effect['id']))


def frozen(effect):
    metadata = effect.get('outcome_metadata') or {}
    if isinstance(metadata, str):
        metadata = json.loads(metadata)
    value = metadata.get('bulk_payload')
    if not isinstance(value, dict):
        return None
    if str(value.get('chat_id')) != str(effect['telegram_chat_id']):
        raise NotEligible()
    if ('photo' in value) != (effect['telegram_method'] == 'sendPhoto'):
        raise NotEligible()
    return copy.deepcopy(value)


def channel(db, recipients):
    row = db.one('SELECT telegram_chat_id,last_test_success FROM public.store_channel_settings ORDER BY id LIMIT 1')
    if not row or row['last_test_success'] is not True:
        return None
    chat = str(row.get('telegram_chat_id') or '').strip()
    return chat if recipients.permits(chat) else None


def product_url(product_id, bot_username):
    if not isinstance(bot_username, str) or not re.fullmatch(r'[A-Za-z0-9_]{5,32}', bot_username):
        raise NotEligible()
    return 'https://t.me/' + bot_username + '?start=product_' + str(int(product_id))
