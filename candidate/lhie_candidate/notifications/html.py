"""Bounded Telegram text with intact custom-emoji tags and escaped values."""
from html import escape
from html.parser import HTMLParser


class _BoundedText(HTMLParser):
    def __init__(self, limit):
        super().__init__(convert_charrefs=True)
        self.remaining, self.parts, self.opened, self.truncated = limit, [], False, False

    def handle_starttag(self, tag, attrs):
        identifier = dict(attrs).get('emoji-id', '')
        if tag != 'tg-emoji' or self.opened or not identifier.isascii() or not identifier.isdigit():
            raise ValueError('Unsupported notification markup')
        if self.remaining:
            self.parts.append('<tg-emoji emoji-id="' + identifier + '">')
            self.opened = True

    def handle_endtag(self, tag):
        if tag != 'tg-emoji':
            raise ValueError('Unsupported notification markup')
        if self.opened:
            self.parts.append('</tg-emoji>')
            self.opened = False

    def handle_data(self, data):
        encoded = data.encode('utf-16-le')
        if len(encoded) // 2 > self.remaining:
            data = encoded[:self.remaining * 2].decode('utf-16-le', errors='ignore')
            self.truncated = True
        self.parts.append(escape(data))
        self.remaining -= len(data.encode('utf-16-le')) // 2


def bounded_html(value, limit=3900):
    parser = _BoundedText(limit - 3)
    parser.feed(value)
    parser.close()
    if parser.opened:
        parser.parts.append('</tg-emoji>')
    return ''.join(parser.parts) + ('...' if parser.truncated else '')


class _Entities(HTMLParser):
    """Strict retained Telegram HTML to upload-relay entity contract."""
    TYPES = {'b': 'bold', 'strong': 'bold', 'i': 'italic', 'em': 'italic', 'u': 'underline',
             's': 'strikethrough', 'code': 'code', 'pre': 'pre', 'tg-emoji': 'custom_emoji'}

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.text, self.entities, self.stack = '', [], []

    def handle_starttag(self, tag, attrs):
        if tag not in self.TYPES:
            raise ValueError('Unsupported notification markup')
        item = {'type': self.TYPES[tag], 'offset': len(self.text.encode('utf-16-le')) // 2}
        if tag == 'tg-emoji':
            identity = dict(attrs).get('emoji-id', '')
            if not identity.isascii() or not identity.isdigit():
                raise ValueError('Invalid custom emoji')
            item['custom_emoji_id'] = identity
        self.stack.append((tag, item))

    def handle_endtag(self, tag):
        if not self.stack or self.stack[-1][0] != tag:
            raise ValueError('Unbalanced notification markup')
        _, item = self.stack.pop()
        item['length'] = len(self.text.encode('utf-16-le')) // 2 - item['offset']
        if item['length']:
            self.entities.append(item)

    def handle_data(self, data):
        self.text += data


def telegram_entities(value):
    parser = _Entities()
    parser.feed(value)
    parser.close()
    if parser.stack:
        raise ValueError('Unbalanced notification markup')
    return parser.text, sorted(parser.entities, key=lambda item: item['offset'])


def localized_html(locale, key, *, icons=(), custom=False, **values):
    from ..receipt.render import _translation
    unit = _translation(locale, key, icons=icons, custom=custom, **values)
    data, cursor, parts = unit.text.encode('utf-16-le'), 0, []
    for entity in unit.entities:
        start, end = entity['offset'] * 2, (entity['offset'] + entity['length']) * 2
        parts.extend((escape(data[cursor:start].decode('utf-16-le')),
            '<tg-emoji emoji-id="' + escape(entity['custom_emoji_id']) + '">' + escape(data[start:end].decode('utf-16-le')) + '</tg-emoji>'))
        cursor = end
    parts.append(escape(data[cursor:].decode('utf-16-le')))
    return ''.join(parts)
