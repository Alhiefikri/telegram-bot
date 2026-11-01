"""Frozen pre-copy receipt layouts for already authorized allocations."""
import html
import re

from .render import MessageUnit, _CATALOGS, _legacy, _len, _tr, _url


_CODE = re.compile(r'(?:email|password|MFA) : ([^\r\n]+)')
_PLACEHOLDER = re.compile(r'(:[A-Za-z_][A-Za-z_0-9]*)')


def _escape(value):
    return html.escape(str(value), quote=True).replace('&#x27;', '&#039;')


def _icon(glyph, identifier, custom):
    if custom and identifier:
        return '\x00<tg-emoji emoji-id="' + _escape(identifier) + '" data-omp-icon="1">' + _escape(glyph) + '</tg-emoji>'
    return str(glyph)


def _product(group, allocation):
    glyph = (group.get('icon') or '').strip() or '🛍️'
    return _icon(glyph, group.get('custom_emoji_id'), allocation.get('custom_emoji')) + ' ' + str(group['product_name'])


def _icons(text, allocation):
    icons = {row['source_emoji']: row for row in allocation.get('ui_icons', ()) if row.get('source_emoji')}
    if not icons:
        return text
    pattern = re.compile('|'.join(re.escape(s) for s in sorted(icons, key=len, reverse=True)))
    def replace(match):
        row = icons[match.group()]
        glyph = str(row.get('fallback_emoji') or '').strip()
        identifier = str(row.get('custom_emoji_id') or '').strip()
        if allocation.get('custom_emoji') and identifier:
            return _icon(glyph, identifier, True)
        return glyph
    return pattern.sub(replace, text)


def _translate(allocation, key, **values):
    locale = allocation.get('locale', 'en')
    catalog = _CATALOGS.get(locale, _CATALOGS['en'])
    template = catalog.get(key, _CATALOGS['en'].get(key, key))
    return ''.join(str(values[part[1:]]) if part.startswith(':') and part[1:] in values
                   else _icons(part, allocation) for part in _PLACEHOLDER.split(template))


def _entities(text, fields=None):
    if fields is None:
        return tuple({'type': 'code', 'offset': _len(text[:m.start(1)]), 'length': _len(m.group(1))}
                     for m in _CODE.finditer(text))
    entities, cursor = [], 0
    for label, value in fields:
        if not isinstance(value, str) or not value:
            continue
        prefix = label + ' : '
        position = text.find(prefix, cursor)
        if position < 0:
            continue
        position += len(prefix)
        if not text.startswith(value, position):
            continue
        entities.append({'type': 'code', 'offset': _len(text[:position]), 'length': _len(value)})
        cursor = position + len(value)
    return tuple(entities)


def _message(text, entities=(), markup=None, secret=False, atomic=False):
    if not text or (atomic and len(text) > 3900) or _len(text) > 4096:
        raise ValueError('Historical receipt unit cannot be rendered safely')
    return MessageUnit(text, tuple(entities), markup if markup is not None else {}, secret)


def _lines(lines, markup=None, secret=False):
    chunks, current = [], ''
    for line in lines:
        if not isinstance(line, str):
            raise ValueError('Invalid historical receipt line')
        parts = [line[i:i + 3900] for i in range(0, len(line), 3900)] or ['']
        for part in parts:
            candidate = current + '\n' + part if current else part
            if current and len(candidate) > 3900:
                chunks.append(current)
                current = part
            else:
                current = candidate
    if current:
        chunks.append(current)
    if not chunks:
        raise ValueError('Empty historical receipt')
    return [_message(chunk, _entities(chunk), markup if i == len(chunks) - 1 else None, secret)
            for i, chunk in enumerate(chunks)]


def _guides(allocation):
    result = []
    for group in allocation.get('groups', ()):
        guide = group.get('guide')
        if guide and guide not in result:
            result.append(guide)
    return result


def _markup(allocation, guides, support_url):
    rows = [[{'text': guide['title'], 'url': guide['url']}] for guide in guides]
    if support_url:
        rows.append([{'text': _translate(allocation, 'receipt.support_button'), 'url': support_url}])
    return {'inline_keyboard': rows}


def _header(allocation, detail=False):
    if detail:
        lines = [_translate(allocation, 'receipt.title'), '━━━━━━━━━━━━━━',
                 _translate(allocation, 'receipt.order_id', order=allocation.get('invoice_number') or allocation['order_id']),
                 _translate(allocation, 'receipt.products')]
    else:
        lines = [_translate(allocation, 'receipt.title'), '━━━━━━━━━━━━━━',
                 _translate(allocation, 'payment.invoice', invoice=allocation.get('invoice_number_raw', allocation.get('invoice_number')) or ''),
                 '', _translate(allocation, 'receipt.products')]
    lines.extend('- ' + _product(item, allocation) + ' x ' + str(item['quantity']) for item in allocation['items'])
    if detail:
        lines.extend([_translate(allocation, 'receipt.payment_method', method=allocation['payment_method']),
                      _translate(allocation, 'receipt.paid_at', date=allocation['paid_at']),
                      _translate(allocation, 'receipt.total_quantity', quantity=sum(max(0, int(i['quantity'])) for i in allocation['items'])),
                      _translate(allocation, 'receipt.amount_paid', amount=allocation['amount_paid']),
                      '', _translate(allocation, 'receipt.product_detail')])
    return lines


def _account_units(allocation, group, account, *, grouped, first):
    number = account['number']
    label = _translate(allocation, 'receipt.account_label', number=number) if grouped else 'Data akun #' + str(number)
    product = _product(group, allocation)
    lines = ([product, ''] if not grouped or first else []) + [label]
    fields = []
    kind = account.get('format', 'legacy')
    if kind == 'legacy':
        text, _, _ = _legacy(account.get('secret'))
        lines.append(text)
    elif kind == 'structured_account':
        email, password = account.get('email'), account.get('password')
        if not isinstance(email, str) or not email or not isinstance(password, str) or not password:
            raise ValueError('Invalid historical credential')
        lines.extend(['email : ' + email, 'password : ' + password])
        fields.extend([('email', email), ('password', password)])
    else:
        raise ValueError('Invalid historical credential')
    method, value = account.get('access_method', 'none'), account.get('access_value')
    rows = None
    if method == 'otp_mail_link':
        domain = _url(value)
        if not domain:
            raise ValueError('Invalid historical OTP URL')
        lines.append('OTP Mail: ' + domain)
        rows = {'inline_keyboard': [[{'text': _translate(allocation, 'receipt.otp_button', number=number), 'url': value}]]}
    elif method not in ('none', 'auth_token') or (method == 'none' and value is not None):
        raise ValueError('Invalid historical access method')
    token = value if method == 'auth_token' else None
    if method == 'auth_token' and (not isinstance(token, str) or not token):
        raise ValueError('Invalid historical token')
    token_lines = ['Auth Token Outlook/Hotmail', 'Auth Token : ' + token] if token is not None else []
    tutorial = account.get('tutorial')
    if grouped and tutorial == group.get('default_tutorial'):
        tutorial = None
    note = _translate(allocation, 'receipt.delivery_note')
    with_token = lines + token_lines
    trial = with_token + (['', note, tutorial] if tutorial is not None else [])
    separate_token = token is not None and len('\n'.join(trial if grouped else with_token)) > 3900
    base = lines if separate_token else with_token
    if token is not None and not separate_token:
        fields.append(('Auth Token', token))
    with_tutorial = base + (['', note, tutorial] if tutorial is not None else [])
    separate_tutorial = not grouped and tutorial is not None and len('\n'.join(with_tutorial)) > 3900
    text = '\n'.join(base if separate_tutorial else with_tutorial)
    entities = _entities(text, fields)
    if not entities and kind == 'legacy':
        entities = _entities(text)
    units = [_message(text, entities, rows, True, atomic=True)]
    if separate_token:
        prefix = [product, ''] if not grouped or (number == 1 and first) else []
        token_text = '\n'.join(prefix + [label + ' — Auth Token Outlook/Hotmail'] + token_lines)
        units.append(_message(token_text, _entities(token_text, [('Auth Token', token)]), secret=True, atomic=True))
    if separate_tutorial:
        tutorial_text = '\n'.join([product, '', label, note, tutorial])
        units.append(_message(tutorial_text, atomic=True))
    return units


def _contains_marker(value):
    if isinstance(value, str):
        return '\x00' in value
    if isinstance(value, dict):
        return any(_contains_marker(item) for item in value.values())
    if isinstance(value, (tuple, list)):
        return any(_contains_marker(item) for item in value)
    return False


def _check(allocation):
    if _contains_marker(allocation):
        raise ValueError('Historical receipt contains an unsupported control character')
    groups = allocation.get('groups', ())
    count = sum(len(group.get('accounts', ())) for group in groups)
    if allocation.get('allocated_count', count) != count:
        raise ValueError('Historical receipt allocation is incomplete')
    return groups


def render_legacy(allocation, *, support_url=''):
    groups = _check(allocation)
    guides = _guides(allocation)
    markup = _markup(allocation, guides, support_url)
    lines = _header(allocation)
    structured = any(account.get('format', 'legacy') == 'structured_account'
                     for group in groups for account in group.get('accounts', ()))
    if structured:
        lines.extend(['', _translate(allocation, 'receipt.account_details'), '━━━━━━━━━━━━━━'])
        units = _lines(lines)
        for group in groups:
            for account in group.get('accounts', ()):
                units.extend(_account_units(allocation, group, account, grouped=False, first=True))
        footer = [_translate(allocation, 'receipt.save_details')]
        for guide in guides:
            footer.extend(['', _icons('📘 ', allocation) + guide['title'], guide['url']])
        footer.extend(['', _translate(allocation, 'receipt.login_help'), _translate(allocation, 'receipt.thanks')])
        units.extend(_lines(footer, markup))
        return tuple(units)
    account_lines = []
    for group in groups:
        account_lines.append(_product(group, allocation))
        for account in group.get('accounts', ()):
            text, _, _ = _legacy(account.get('secret'))
            account_lines.append(str(account['number']) + '. ' + text)
        if group.get('default_tutorial') is not None:
            account_lines.extend(['', _translate(allocation, 'receipt.delivery_note'), group['default_tutorial']])
        account_lines.append('')
    while account_lines and account_lines[-1] == '':
        account_lines.pop()
    if account_lines:
        lines.extend(['', _translate(allocation, 'receipt.account_details'), '━━━━━━━━━━━━━━', *account_lines,
                      '', _translate(allocation, 'receipt.save_details')])
        for guide in guides:
            lines.extend(['', _icons('📘 ', allocation) + guide['title'], guide['url']])
        lines.extend(['', _translate(allocation, 'receipt.login_help'), _translate(allocation, 'receipt.thanks')])
    else:
        lines.extend(['', _translate(allocation, 'receipt.recorded'), '',
                      _translate(allocation, 'receipt.support_help'), _translate(allocation, 'receipt.thanks')])
    return tuple(_lines(lines, markup, bool(account_lines)))


def render_product_detail(allocation, *, support_url=''):
    groups = _check(allocation)
    guides = _guides(allocation)
    markup = _markup(allocation, guides, support_url)
    lines = _header(allocation, detail=True)
    if not any(group.get('accounts') for group in groups):
        lines.extend([_translate(allocation, 'receipt.no_credentials'), '',
                      _translate(allocation, 'receipt.save_details'), _translate(allocation, 'receipt.thanks')])
        return tuple(_lines(lines, markup))
    units = _lines(lines)
    emitted = []
    for group in groups:
        for index, account in enumerate(group.get('accounts', ())):
            units.extend(_account_units(allocation, group, account, grouped=True, first=index == 0))
        if group.get('default_tutorial') is not None:
            text = '\n'.join([_product(group, allocation), '', _translate(allocation, 'receipt.delivery_note'), group['default_tutorial']])
            units.append(_message(text, atomic=True))
        guide = group.get('guide')
        if guide and guide not in emitted:
            units.append(_message(_icons('📘 ', allocation) + guide['title'] + '\n' + guide['url'], atomic=True))
            emitted.append(guide)
    footer = '\n\n'.join(_translate(allocation, key) for key in
                         ('receipt.save_details', 'receipt.copy_guidance', 'receipt.thanks'))
    units.append(_message(footer, markup=markup))
    return tuple(units)


def normalize_unit(unit):
    """Convert the frozen HTML emoji wrappers without exposing markup to Telegram."""
    pattern = re.compile(r'\x00<tg-emoji emoji-id="([^"]+)" data-omp-icon="1">(.*?)</tg-emoji>', re.S)
    text, cursor, shifts, icons = '', 0, [], []
    for match in pattern.finditer(unit.text):
        before = unit.text[cursor:match.start()]
        text += before
        start, end = _len(unit.text[:match.start()]), _len(unit.text[:match.end()])
        for entity in unit.entities:
            left = entity['offset']
            right = left + entity['length']
            if left < end and right > start:
                raise ValueError('Historical credential overlaps an emoji wrapper')
        glyph = html.unescape(match.group(2))
        identifier = html.unescape(match.group(1))
        if identifier.isascii() and identifier.isdecimal() and glyph:
            icons.append({'type': 'custom_emoji', 'offset': _len(text),
                          'length': _len(glyph), 'custom_emoji_id': identifier})
        text += glyph
        shifts.append((end, _len(glyph) - (end - start)))
        cursor = match.end()
    text += unit.text[cursor:]
    entities = [{**entity, 'offset': entity['offset'] + sum(delta for end, delta in shifts if end <= entity['offset'])}
                for entity in unit.entities]
    markup = {'inline_keyboard': [[dict(button, text=pattern.sub(lambda match: html.unescape(match.group(2)),
                                                                 button.get('text', '')),
                                       style=button.get('style', 'primary')) for button in row]
                                 for row in unit.reply_markup.get('inline_keyboard', [])]}
    return _message(text, [*entities, *icons], markup, unit.contains_credentials)
