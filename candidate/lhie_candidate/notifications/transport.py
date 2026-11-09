"""Notification relay, with shared outcome classification and definite-only fallback."""
import copy

from ..navigation.transport import RelayTransport as NavigationRelay, fallback, normalize
from ..receipt.transport import ReceiptPreSendError, classify


class NotificationTransport:
    def __init__(self):
        self.relay = NavigationRelay()

    def execute(self, method, payload):
        if method not in ('sendMessage', 'sendPhoto'):
            raise ReceiptPreSendError('Invalid notification method')
        if method == 'sendPhoto' and isinstance(payload.get('photo'), bytes):
            from ..receipt.transport import RelayTransport
            return RelayTransport().execute(method, payload)
        payload = normalize(payload)
        sent = copy.deepcopy(payload)
        for row in sent.get('reply_markup', {}).get('inline_keyboard', []):
            for button in row:
                button.pop('_unicode_fallback', None)
        response = self.relay._request(method, sent)
        description = str(response.get('description', '')).lower() if isinstance(response, dict) else ''
        if (isinstance(response, dict) and response.get('ok') is False
                and response.get('error_code') == 400
                and any(word in description for word in ('custom emoji', 'icon_custom_emoji_id', 'tg-emoji'))):
            response = self.relay._request(method, fallback(payload))
        return classify(response)
