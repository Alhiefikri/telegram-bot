"""In-memory Telegram receipt presentation for an already authorized allocation.

The caller owns entitlement checks, decryption, snapshot loading and transport.
Never persist or log the returned objects: they can contain credentials.
"""
from dataclasses import dataclass, field
import json
from pathlib import Path
import re
from urllib.parse import urlsplit


_CATALOGS = {code: json.loads((Path(__file__).parent.parent / 'navigation' / (code + '.json')).read_text())
             for code in ('en', 'id', 'ms')}
_CONTROL = re.compile(r'[\x00-\x1f\x7f]')
_BLOCK_CONTROL = re.compile(r'[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]')
_EMAIL = re.compile(r'[^\s@]+@[^\s@]+\.[^\s@]+\Z')


def _len(text):
    return len(text.encode('utf-16-le')) // 2


def _tr(locale, key, **values):
    catalog = _CATALOGS.get(locale, _CATALOGS['en'])
    text = catalog.get(key, _CATALOGS['en'].get(key, key))
    for name, value in values.items():
        text = text.replace(':' + name, str(value))
    return text

def _translation(locale, key, icons=(), custom=False, **values):
    catalog = _CATALOGS.get(locale, _CATALOGS['en'])
    template = catalog.get(key, _CATALOGS['en'].get(key, key))
    by_source = {row['source_emoji']: row for row in icons if row.get('source_emoji')}
    sources = sorted(by_source, key=len, reverse=True)
    pattern = re.compile('|'.join(re.escape(source) for source in sources)) if sources else None
    text, entities = '', []
    for part in re.split(r'(:[A-Za-z_][A-Za-z_0-9]*)', template):
        if part.startswith(':') and part[1:] in values:
            text += str(values[part[1:]])
            continue
        if pattern is None:
            text += part
            continue
        cursor = 0
        for match in pattern.finditer(part):
            text += part[cursor:match.start()]
            icon = by_source[match.group()]
            glyph = str(icon.get('fallback_emoji') or '').strip()
            identifier = str(icon.get('custom_emoji_id') or '').strip()
            if custom and identifier and glyph:
                entities.append({'type': 'custom_emoji', 'offset': _len(text),
                                 'length': _len(glyph), 'custom_emoji_id': identifier})
            text += glyph
            cursor = match.end()
        text += part[cursor:]
    return _unit(text, entities)


def _leading(unit, prefix):
    return _unit(prefix + unit.text,
                 [{**entity, 'offset': entity['offset'] + _len(prefix)} for entity in unit.entities],
                 unit.reply_markup.get('inline_keyboard', ()), unit.contains_credentials)


class _Ephemeral:
    def __reduce_ex__(self, protocol):
        raise TypeError('Receipt presentation cannot be serialized')


@dataclass(frozen=True, repr=False)
class MessageUnit(_Ephemeral):
    text: str
    entities: tuple = ()
    reply_markup: dict = field(default_factory=dict)
    contains_credentials: bool = False


@dataclass(frozen=True, repr=False)
class Document(_Ephemeral):
    filename: str
    contents: bytes


@dataclass(frozen=True, repr=False)
class ReceiptPlan(_Ephemeral):
    photo_units: tuple
    text_units: tuple
    product_count: int
    account_count: int
    guides: tuple
    document: Document | None = None


def _unit(text, entities=(), rows=(), secret=False):
    if not text or _len(text) > 4096:
        raise ValueError('Receipt unit is outside Telegram limits')
    keyboard = [[dict(button, style=button.get('style', 'primary')) for button in row] for row in rows]
    return MessageUnit(text, tuple(entities), {'inline_keyboard': keyboard} if keyboard else {}, secret)


def _combine(blocks):
    text, entities, rows, secret = '', [], [], False
    for block in blocks:
        prefix = text + '\n' if text else ''
        offset = _len(prefix)
        entities.extend({**entity, 'offset': entity['offset'] + offset} for entity in block.entities)
        text = prefix + block.text
        rows.extend(block.reply_markup.get('inline_keyboard', []))
        secret |= block.contains_credentials
    return _unit(text, entities, rows, secret)


def _pack(blocks, photo):
    result, pending = [], []
    size = 0
    for block in blocks:
        length = _len(block.text)
        limit = 1024 if photo and not result else 4096
        if pending and size + 1 + length > limit:
            result.append(_combine(pending))
            pending, size = [], 0
            limit = 4096
        if length > limit:
            raise ValueError('Receipt block exceeds Telegram limit')
        size += length + bool(pending)
        pending.append(block)
    if pending:
        result.append(_combine(pending))
    return tuple(result)


def _chunks(text, limit=4096):
    chunks, current, size = [], '', 0
    for char in text:
        width = 2 if ord(char) > 0xffff else 1
        if size + width > limit:
            chunks.append(current)
            current, size = '', 0
        current += char
        size += width
    if current:
        chunks.append(current)
    return chunks


def _line_chunks(lines, limit=3900):
    chunks, current = [], ''
    for line in lines:
        for part in _chunks(line, limit) or ['']:
            candidate = current + '\n' + part if current else part
            if current and _len(candidate) > limit:
                chunks.append(current)
                current = part
            else:
                current = candidate
    if current:
        chunks.append(current)
    return chunks


def _icon_name(group, custom_emoji):
    glyph = (group.get('icon') or '').strip() or '🛍️'
    name = str(group['product_name'])
    text = glyph + ' ' + name
    emoji_id = group.get('custom_emoji_id')
    entities = ()
    if custom_emoji and emoji_id and str(emoji_id).isdigit():
        entities = ({'type': 'custom_emoji', 'offset': 0, 'length': _len(glyph),
                     'custom_emoji_id': str(emoji_id)},)
    return _unit(text, entities)


def _url(url):
    if not isinstance(url, str) or len(url) > 2048 or _CONTROL.search(url):
        return None
    try:
        p = urlsplit(url)
        if p.scheme.lower() != 'https' or not p.hostname or p.username or p.password or not re.fullmatch(r'[A-Za-z0-9.-]+', p.hostname):
            return None
        return p.hostname
    except ValueError:
        return None


def _legacy(secret):
    if not isinstance(secret, str) or not secret:
        raise ValueError('Invalid credential projection')
    dash = '----' in secret and bool(_EMAIL.fullmatch(secret.split('----', 1)[0].strip()))
    parts = secret.split('----' if dash else '|')
    if (len(parts) not in (2, 3) or (dash and len(parts) != 3)
            or not _EMAIL.fullmatch(parts[0].strip()) or not parts[1].strip()
            or (len(parts) == 3 and not parts[2].strip())
            or any('\n' in part or '\r' in part for part in parts)):
        return secret, None, False
    parts[0] = parts[0].strip()
    text = '\n'.join(label + value for label, value in zip(('email : ', 'password : ', 'MFA : '), parts))
    return text, parts, dash


def _v1_account_block(group, account, number, first, locale, custom, icons):
    label = _tr(locale, 'receipt.account_label', number=number)
    product = _icon_name(group, custom)
    prefix = (product.text + '\n\n' if first else '') + label + '\n'
    entities = list(product.entities) if first else []
    rows, token = [], None
    if account.get('format', 'legacy') == 'legacy':
        credential, parts, _ = _legacy(account.get('secret'))
        if parts is not None:
            pos = _len(prefix)
            for field, value in zip(('email : ', 'password : ', 'MFA : '), parts):
                pos += _len(field)
                entities.append({'type': 'code', 'offset': pos, 'length': _len(value)})
                pos += _len(value) + 1
    elif account.get('format') == 'structured_account':
        email, password = account.get('email'), account.get('password')
        if not isinstance(email, str) or not _EMAIL.fullmatch(email) or not isinstance(password, str) or not password or _CONTROL.search(email):
            raise ValueError('Invalid credential projection')
        credential = 'email : ' + email + '\npassword : ' + password
        entities.extend([
            {'type': 'code', 'offset': _len(prefix + 'email : '), 'length': _len(email)},
            {'type': 'code', 'offset': _len(prefix + 'email : ' + email + '\npassword : '), 'length': _len(password)},
        ])
        method, value = account.get('access_method', 'none'), account.get('access_value')
        if method == 'otp_mail_link':
            domain = _url(value)
            if not domain:
                raise ValueError('Invalid credential projection')
            credential += '\n' + _tr(locale, 'receipt.access_otp', domain=domain)
            rows = [[{'text': _tr(locale, 'receipt.otp_button', number=number), 'url': value}]]
        elif method == 'auth_token':
            if not isinstance(value, str) or not value:
                raise ValueError('Invalid credential projection')
            token = value
        elif method != 'none' or value is not None:
            raise ValueError('Invalid credential projection')
    else:
        raise ValueError('Invalid credential projection')
    text = prefix + credential
    token_lines = '\n' + _tr(locale, 'receipt.access_auth_token') + '\nAuth Token : ' + token if token is not None else ''
    separate_token = bool(token_lines and _len(text + token_lines) > 3900)
    if token_lines and not separate_token:
        entities.append({'type': 'code', 'offset': _len(text + token_lines) - _len(token), 'length': _len(token)})
        text += token_lines
    tutorial = account.get('tutorial')
    tutorial_unit = None
    if tutorial and tutorial != group.get('default_tutorial'):
        note = _translation(locale, 'receipt.delivery_note', icons, custom)
        suffix = '\n\n' + note.text + '\n' + tutorial
        if _len(text + suffix) <= 3900:
            entities.extend({**entity, 'offset': entity['offset'] + _len(text + '\n\n')} for entity in note.entities)
            text += suffix
        else:
            tutorial_unit = (note, tutorial)
    if _len(text) > 3900:
        raise ValueError('Receipt account exceeds configured limit')
    blocks = [_unit(text, entities, rows, True)]
    if separate_token:
        token_text = label + token_lines
        if _len(token_text) > 3900:
            raise ValueError('Receipt token exceeds configured limit')
        blocks.append(_unit(token_text, [{'type': 'code', 'offset': _len(token_text) - _len(token),
                                         'length': _len(token)}], secret=True))
    if tutorial_unit:
        note, tutorial = tutorial_unit
        chunks = _line_chunks([label, note.text, tutorial])
        blocks.extend(_unit(chunk, [{**entity, 'offset': entity['offset'] + _len(label + '\n')}
                                    for entity in note.entities] if index == 0 else ())
                      for index, chunk in enumerate(chunks))
    return blocks


def _account_block(group, account, number, first, locale, custom_emoji, icons):
    label = _tr(locale, 'receipt.account_label', number=number)
    product = _icon_name(group, custom_emoji)
    prefix = (product.text + '\n\n' if first else '') + label + '\n'
    icon_entities = list(product.entities) if first else []
    method = account.get('access_method', 'none')
    value = account.get('access_value')
    rows, note, separate_token = [], '', None
    if account.get('format', 'legacy') == 'legacy':
        text, parts, individual = _legacy(account.get('secret'))
        credential = text if individual or parts is None else ' | '.join(parts)
    elif account.get('format') == 'structured_account':
        email, password = account.get('email'), account.get('password')
        if not isinstance(email, str) or not _EMAIL.fullmatch(email) or not isinstance(password, str) or not password or _CONTROL.search(email):
            raise ValueError('Invalid credential projection')
        individual, parts = False, None
        credential = email + ' | ' + password
        if method == 'auth_token':
            if not isinstance(value, str) or not value:
                raise ValueError('Invalid credential projection')
            note = '\n' + _tr(locale, 'receipt.access_auth_token')
            if _len(prefix + credential + ' | ' + value + note) > 3900:
                separate_token = value
            else:
                credential += ' | ' + value
        elif method == 'otp_mail_link':
            domain = _url(value)
            if not domain:
                raise ValueError('Invalid credential projection')
            note = '\n' + _tr(locale, 'receipt.access_otp', domain=domain)
            rows = [[{'text': _tr(locale, 'receipt.otp_button', number=number), 'url': value}]]
        elif method != 'none' or value is not None:
            raise ValueError('Invalid credential projection')
    else:
        raise ValueError('Invalid credential projection')
    entities = icon_entities
    if individual:
        position = _len(prefix)
        for label_text, part in zip(('email : ', 'password : ', 'MFA : '), parts):
            position += _len(label_text)
            entities.append({'type': 'code', 'offset': position, 'length': _len(part)})
            position += _len(part) + 1
    else:
        entities.append({'type': 'pre', 'offset': _len(prefix), 'length': _len(credential), 'language': 'copy'})
    text = prefix + credential + note
    if _len(text) > 3900:
        raise ValueError('Receipt account exceeds configured limit')
    tutorial = account.get('tutorial')
    extra = []
    if separate_token is not None:
        token_prefix = product.text + '\n\n' + label + '\nAuth Token Outlook/Hotmail\n'
        if _len(token_prefix + separate_token) > 3900:
            raise ValueError('Receipt token exceeds configured limit')
        extra.append(_unit(token_prefix + separate_token, [
            *product.entities,
            {'type': 'pre', 'offset': _len(token_prefix), 'length': _len(separate_token), 'language': 'copy'},
        ], secret=True))
    if tutorial and tutorial != group.get('default_tutorial'):
        note_unit = _translation(locale, 'receipt.delivery_note', icons, custom_emoji)
        suffix = '\n\n' + note_unit.text + '\n' + tutorial
        if _len(text + suffix) <= 3900:
            entities.extend({**entity, 'offset': entity['offset'] + _len(text + '\n\n')} for entity in note_unit.entities)
            text += suffix
        else:
            prefix = product.text + '\n\n' + label + '\n'
            chunks = _line_chunks([product.text, '', label, note_unit.text, tutorial])
            note_entities = [{**entity, 'offset': entity['offset'] + _len(prefix)} for entity in note_unit.entities]
            extra.extend(_unit(chunk, [*product.entities, *note_entities] if index == 0 else ()) for index, chunk in enumerate(chunks))
    return [_unit(text, entities, rows, True), *extra]


def _safe_line(value):
    if not isinstance(value, str) or not value or _CONTROL.search(value):
        raise ValueError('Invalid TXT projection')
    return value


def _block(value):
    if not isinstance(value, str) or not value.strip() or _BLOCK_CONTROL.search(value):
        raise ValueError('Invalid TXT projection')
    return value.replace('\r\n', '\n').replace('\r', '\n')


def _display(value):
    if not isinstance(value, str):
        raise ValueError('Invalid TXT projection')
    result = _CONTROL.sub(' ', value).strip()
    if not result:
        raise ValueError('Invalid TXT projection')
    return result


def render_txt(allocation):
    locale = allocation.get('locale', 'en')
    reference = str(allocation.get('invoice_number') or allocation['order_id'])
    groups = allocation.get('groups', ())
    if not any(g.get('accounts') for g in groups):
        return None
    invoice_url = _safe_line(allocation['invoice_url'])
    header = ['LHIE STORE', 'Order : ' + _display(reference), 'Items : ' + str(sum(max(0, int(i['quantity'])) for i in allocation['items'])),
              'Invoice : ' + invoice_url, _tr(locale, 'receipt.save_details')]
    sections, emitted = [], set()
    for group in groups:
        lines = ['Product : ' + _display(group['product_name'])]
        for index, account in enumerate(group.get('accounts', ()), 1):
            number = int(account.get('number', index))
            if number < 1:
                raise ValueError('Invalid TXT projection')
            lines += ['', 'Account #' + str(number)]
            if account.get('format', 'legacy') == 'legacy':
                text, _, _ = _legacy(account.get('secret'))
                lines.append(_block(text))
            elif account.get('format') == 'structured_account':
                email, password = _safe_line(account.get('email')), _safe_line(account.get('password'))
                if not _EMAIL.fullmatch(email):
                    raise ValueError('Invalid TXT projection')
                lines += ['email : ' + email, 'password : ' + password]
                method, value = account.get('access_method', 'none'), account.get('access_value')
                reader = account.get('reader_mail_link')
                if reader is not None:
                    if not _url(reader) or len(_safe_line(value)) > 3500:
                        raise ValueError('Invalid TXT projection')
                    lines += ['akses token : ' + value, 'link reader mail : ' + reader]
                elif method == 'otp_mail_link' and _url(value):
                    lines += ['access: OTP Mail Link', 'OTP Mail Link : ' + value]
                elif method == 'auth_token' and len(_safe_line(value)) <= 3500:
                    lines += ['access: Auth Token', 'Auth Token : ' + value]
                elif method != 'none' or value is not None:
                    raise ValueError('Invalid TXT projection')
            else:
                raise ValueError('Invalid TXT projection')
            tutorial = account.get('tutorial')
            if tutorial is not None and tutorial != group.get('default_tutorial'):
                lines += ['', 'Delivery note :', _block(tutorial)]
        if group.get('default_tutorial') is not None:
            lines += ['', 'Delivery note :', _block(group['default_tutorial'])]
        guide = group.get('guide')
        if guide:
            key = (_safe_line(guide['title']), _safe_line(guide['url']))
            if key not in emitted:
                lines += ['', 'Usage guide : ' + key[0], 'URL : ' + key[1]]
                emitted.add(key)
        sections.append('\n'.join(lines))
    contents = '\n\n'.join(['\n'.join(header), *sections]).encode('utf-8')
    if len(contents) > 1_048_576:
        raise ValueError('Invalid TXT projection')
    safe = re.sub(r'[^A-Za-z0-9._-]+', '-', reference)
    safe = re.sub(r'-{2,}', '-', re.sub(r'\.{2,}', '.', safe)).strip('.-')[:96].strip('.-')
    return Document('lhie-store-' + (safe or 'order') + '.txt', contents)


def render_receipt(allocation, *, document_delivery=None, compact=True, copyable_credentials=True,
                   icons=None, support_url=''):
    locale = allocation.get('locale', 'en')
    icons = tuple(icons if icons is not None else allocation.get('ui_icons', ()))
    custom = bool(allocation.get('custom_emoji', False))
    groups = allocation.get('groups', ())
    accounts = [a for g in groups for a in g.get('accounts', ())]
    expected = allocation.get('allocated_count', len(accounts))
    if expected != len(accounts):
        raise ValueError('Receipt credential projection is incomplete')
    merged = []
    for group in groups:
        if not group.get('accounts'):
            continue
        key = tuple(group.get(k) for k in ('product_name', 'icon', 'custom_emoji_id', 'default_tutorial', 'guide'))
        match = next((entry for entry in merged if entry[0] == key), None)
        if match is None:
            merged.append((key, group, list(group['accounts'])))
        else:
            match[2].extend(group['accounts'])
    guides = []
    for group in groups:
        guide = group.get('guide')
        if guide and guide not in guides:
            guides.append(guide)
    if document_delivery is None:
        length = 0
        for group in groups:
            length += len(group.get('default_tutorial') or '')
            for account in group.get('accounts', ()):
                if account.get('format', 'legacy') == 'legacy':
                    text, _, _ = _legacy(account.get('secret'))
                    length += len(text)
                else:
                    length += sum(len(account.get(k) or '') for k in ('email', 'password', 'access_value', 'reader_mail_link'))
                tutorial = account.get('tutorial')
                if tutorial != group.get('default_tutorial'):
                    length += len(tutorial or '')
        document_delivery = len(accounts) > 1 or length > 1024
    reference = allocation.get('invoice_number') or allocation['order_id']
    header = _combine([_translation(locale, 'receipt.title', icons, custom), _unit('━━━━━━━━━━━━━━'),
                       _translation(locale, 'receipt.order_id', icons, custom, order=reference)])
    quantity = sum(max(0, int(item['quantity'])) for item in allocation['items'])
    quantity_unit = _translation(locale, 'receipt.total_quantity', icons, custom, quantity=quantity)
    amount_unit = _translation(locale, 'receipt.amount_paid', icons, custom, amount=allocation['amount_paid'])
    if document_delivery:
        document = render_txt(allocation)
        if document is None:
            raise ValueError('Receipt document is unavailable')
        summary = _combine([header, quantity_unit, amount_unit])
        caption_copy = _combine([_translation(locale, 'receipt.txt.automatic', icons, custom),
                                 _leading(_translation(locale, 'receipt.save_details', icons, custom), '\n')])
        caption = _unit(caption_copy.text, caption_copy.entities, secret=True)
        if _len(summary.text) > 1024:
            raise ValueError('Receipt header exceeds Telegram limit')
        return ReceiptPlan((summary, caption), (summary, caption), len(merged), len(accounts), tuple(guides), document)
    product_lines = [_leading(_translation(locale, 'receipt.products', icons, custom), '\n')]
    product_blocks = []
    product_size = _len(product_lines[0].text)
    for item in allocation['items']:
        icon = _icon_name(item, custom)
        line = _unit('- ' + icon.text + ' x ' + str(item['quantity']),
                     [{**e, 'offset': e['offset'] + 2} for e in icon.entities])
        if product_size + 1 + _len(line.text) > 4096:
            product_blocks.append(_combine(product_lines))
            product_lines, product_size = [], 0
        product_size += _len(line.text) + bool(product_lines)
        product_lines.append(line)
    product_blocks.append(_combine(product_lines))
    payment = _combine([_translation(locale, 'receipt.payment_method', icons, custom, method=allocation['payment_method']),
                        _leading(_translation(locale, 'receipt.paid_at', icons, custom, date=allocation['paid_at']), '\n'),
                        _leading(quantity_unit, '\n'), amount_unit])
    blocks = [header, *product_blocks, _leading(payment, '\n'),
              _leading(_translation(locale, 'receipt.product_detail', icons, custom), '\n')]
    if not accounts:
        blocks.append(_leading(_translation(locale, 'receipt.no_credentials', icons, custom), '\n'))
    for _, group, group_accounts in merged:
        for index, account in enumerate(group_accounts, 1):
            renderer = _account_block if copyable_credentials else _v1_account_block
            blocks.extend(renderer(group, account, index, index == 1, locale, custom, icons))
        if group.get('default_tutorial'):
            name = _icon_name(group, custom)
            note = _translation(locale, 'receipt.delivery_note', icons, custom)
            prefix = name.text + '\n\n'
            chunks = _line_chunks([name.text, '', note.text, group['default_tutorial']])
            entities = [*name.entities, *({**e, 'offset': e['offset'] + _len(prefix)} for e in note.entities)]
            for index, chunk in enumerate(chunks):
                blocks.append(_unit(chunk, entities if index == 0 else ()))
        guide = group.get('guide')
        if guide:
            glyph = _translation(locale, '📘 ', icons, custom)
            blocks.append(_unit(glyph.text + guide['title'], glyph.entities,
                                rows=[[{'text': guide['title'], 'url': guide['url']}]]))
    if not compact:
        lines = [_translation(locale, 'receipt.save_details', icons, custom)]
        if accounts:
            key = 'receipt.copy_block_guidance' if copyable_credentials else 'receipt.copy_guidance'
            lines.append(_leading(_translation(locale, key, icons, custom), '\n'))
        lines.append(_leading(_translation(locale, 'receipt.thanks', icons, custom), '\n'))
        footer = _leading(_combine(lines), '\n')
        rows = [[{'text': _tr(locale, 'receipt.support_button'), 'url': support_url.strip()}]] if support_url.strip() else []
        blocks.append(_unit(footer.text, footer.entities, rows))
    return ReceiptPlan(_pack(blocks, True), _pack(blocks, False), len(merged), len(accounts), tuple(guides))


def render_action_panel(order_id, locale, txt_available, support_url='', privacy=False, *,
                        icons=(), custom_emoji=False):
    order_id = str(order_id).strip()
    if not re.fullmatch(r'[0-9a-fA-F-]{36}', order_id):
        raise ValueError('Invalid receipt action order identifier')
    rows = []
    if txt_available:
        rows.append([{'text': _translation(locale, 'receipt.actions.txt_button', icons).text, 'callback_data': 'txt:request:' + order_id}])
    if support_url.strip():
        rows.append([{'text': _translation(locale, 'receipt.actions.support_button', icons).text, 'url': support_url.strip()}])
    rows += [[{'text': _translation(locale, 'receipt.actions.buy_again_button', icons).text, 'callback_data': 'receipt:buy_again:' + order_id}],
             [{'text': _translation(locale, 'receipt.actions.main_menu_button', icons).text, 'callback_data': 'receipt:main_menu:' + order_id}]]
    lines = []
    if privacy:
        lines.append(_translation(locale, 'receipt.save_details', icons, custom_emoji))
        if txt_available:
            lines.append(_translation(locale, 'receipt.copy_block_guidance', icons, custom_emoji))
        lines.append(_translation(locale, 'receipt.thanks', icons, custom_emoji))
    lines.append(_translation(locale, 'receipt.actions.message', icons, custom_emoji))
    body = _combine([line if index == 0 else _leading(line, '\n') for index, line in enumerate(lines)])
    return _unit(body.text, body.entities, rows=rows)
