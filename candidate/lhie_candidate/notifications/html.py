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
