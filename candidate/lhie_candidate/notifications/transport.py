"""Notification relay, with shared outcome classification and definite-only fallback."""
import copy
import html
import json
import re

from ..navigation.transport import RelayTransport as NavigationRelay, fallback, normalize
from ..receipt.transport import ReceiptPreSendError, classify


_CUSTOM_EMOJI = re.compile(
    r'<tg-emoji\b(?=[^>]*\bdata-omp-icon="1")(?=[^>]*\bemoji-id="([0-9]{1,64})")[^>]*>([^<]*)</tg-emoji>'
    r'|<tg-emoji\b[^>]*>([^<]*)</tg-emoji>',
    re.DOTALL,
)


def _utf16_length(value):
    return len(value.encode('utf-16-le')) // 2


def _text_and_entities(text, entities):
    if isinstance(entities, str):
        entities = json.loads(entities)
    if not isinstance(entities, list):
        entities = []
    parts, added, removals = [], [], []
    cursor = 0
    rendered_length = 0
    for match in _CUSTOM_EMOJI.finditer(text):
        markup_start = _utf16_length(text[:match.start()])
        markup = match.group()
        markup_length = _utf16_length(markup)
        if any(
            isinstance(entity, dict) and entity.get('type') == 'pre'
            and markup_start < int(entity.get('offset', 0)) + int(entity.get('length', 0))
            and markup_start + markup_length > int(entity.get('offset', 0))
            for entity in entities
        ):
            continue
        prefix = text[cursor:match.start()]
        glyph = html.unescape(match.group(2) if match.group(1) else match.group(3))
        parts.extend((prefix, glyph))
        rendered_length += _utf16_length(prefix)
        identity = match.group(1)
        if identity:
            added.append({
                'type': 'custom_emoji',
                'offset': rendered_length,
                'length': _utf16_length(glyph),
                'custom_emoji_id': identity,
            })
        rendered_length += _utf16_length(glyph)
        removals.append((markup_start, markup_start + markup_length,
                         markup_length - _utf16_length(glyph)))
        cursor = match.end()
    parts.append(text[cursor:])

    shifted = []
    for entity in entities:
        if not isinstance(entity, dict) or 'offset' not in entity or 'length' not in entity:
            shifted.append(entity)
            continue
        offset, length, delta = int(entity['offset']), int(entity['length']), 0
        overlap = False
        for start, end, removed in removals:
            if end <= offset:
                delta += removed
            elif start < offset + length:
                overlap = True
                break
        if not overlap and offset - delta >= 0:
            shifted.append({**entity, 'offset': offset - delta})
    return ''.join(parts), sorted(
        [*shifted, *added],
        key=lambda entity: int(entity.get('offset', 0)) if isinstance(entity, dict) else 0,
    )


def _normalize_custom_emoji_text(payload):
    if payload.get('parse_mode') is not None:
        return payload
    payload.pop('parse_mode', None)
    for field, entities_field in (('text', 'entities'), ('caption', 'caption_entities')):
        text = payload.get(field)
        if not isinstance(text, str) or '<tg-emoji' not in text:
            continue
        text, entities = _text_and_entities(text, payload.get(entities_field, []))
        payload[field] = text
        payload[entities_field] = entities
    return payload


class NotificationTransport:
    def __init__(self):
        self.relay = NavigationRelay()

    def execute(self, method, payload):
        if method not in ('sendMessage', 'sendPhoto'):
            raise ReceiptPreSendError('Invalid notification method')
        payload = _normalize_custom_emoji_text(normalize(payload))
        sent = copy.deepcopy(payload)
        for row in sent.get('reply_markup', {}).get('inline_keyboard', []):
            for button in row:
                self._move_button_icon(button)
                button.pop('_unicode_fallback', None)
        response = self.relay._request(method, sent)
        description = str(response.get('description', '')).lower() if isinstance(response, dict) else ''
        if (isinstance(response, dict) and response.get('ok') is False
                and response.get('error_code') == 400
                and any(word in description for word in ('custom emoji', 'icon_custom_emoji_id', 'tg-emoji'))):
            response = self.relay._request(method, fallback(payload))
        return classify(response)

    @staticmethod
    def _move_button_icon(button):
        label = button.get('text')
        if not isinstance(label, str):
            return
        trusted = re.compile(
            r'<tg-emoji\b(?=[^>]*\bdata-omp-icon="1")(?=[^>]*\bemoji-id="([0-9]{1,64})")[^>]*>(.*?)</tg-emoji>',
            re.DOTALL,
        )
        wrapper = re.compile(r'<tg-emoji\b[^>]*>(.*?)</tg-emoji>', re.DOTALL)
        icon_id = button.get('icon_custom_emoji_id')
        fallback_text = wrapper.sub(lambda match: match.group(1), label)

        def strip(match):
            identity, glyph = match.group(1), match.group(2)
            if not icon_id:
                button['icon_custom_emoji_id'] = identity
                if '_unicode_fallback' not in button:
                    button['_unicode_fallback'] = fallback_text
                return ''
            return glyph

        label = trusted.sub(strip, label, count=1)
        button['text'] = wrapper.sub(lambda match: match.group(1), label)
