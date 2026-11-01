"""Memory-only receipt relay client; no retries after an uncertain send."""
import base64
from dataclasses import dataclass
import http.client
import json


MAX_PHOTO_BYTES = 5 * 1024 * 1024
MAX_DOCUMENT_BYTES = 1024 * 1024
MAX_REQUEST_BYTES = 7 * 1024 * 1024 + 65536
MAX_RESPONSE_BYTES = 65536
METHODS = frozenset({'sendMessage', 'sendPhoto', 'sendDocument'})


class ReceiptPreSendError(ValueError):
    pass


@dataclass(frozen=True, slots=True)
class ReceiptResult:
    message_id: int | None
    outcome: str
    error_code: str | None = None
    retry_after_seconds: int | None = None
    emoji_rejected: bool = False


def classify(response):
    if not isinstance(response, dict):
        return ReceiptResult(None, 'ambiguous')
    if response.get('ok') is True:
        result = response.get('result')
        if isinstance(result, dict) and type(result.get('message_id')) is int and result['message_id'] > 0:
            return ReceiptResult(result['message_id'], 'success')
    elif response.get('ok') is False:
        code = response.get('error_code')
        if type(code) is int and 400 <= code < 500 and code != 408:
            retry = response.get('parameters', {}).get('retry_after') if isinstance(response.get('parameters'), dict) else None
            delay = min(300, max(1, retry)) if type(retry) is int else (1 if code == 429 else None)
            return ReceiptResult(None, 'definite', 'telegram_http_' + str(code), delay,
                                 code == 400 and response.get('emoji_rejected') is True)
    return ReceiptResult(None, 'ambiguous')


def _unicode_fallback(payload):
    fallback, changed = dict(payload), False
    for key in ('entities', 'caption_entities'):
        entities = payload.get(key)
        if isinstance(entities, list):
            plain = [entry for entry in entities if not (isinstance(entry, dict) and entry.get('type') == 'custom_emoji')]
            if len(plain) != len(entities):
                fallback[key], changed = plain, True
    markup = payload.get('reply_markup')
    if isinstance(markup, dict) and isinstance(markup.get('inline_keyboard'), list):
        rows = []
        for row in markup['inline_keyboard']:
            buttons = []
            for button in row:
                item = dict(button)
                if item.pop('icon_custom_emoji_id', None) is not None:
                    item['text'] = item.pop('_unicode_fallback', item.get('text', ''))
                    changed = True
                buttons.append(item)
            rows.append(buttons)
        fallback['reply_markup'] = {**markup, 'inline_keyboard': rows}
    return fallback if changed else None


class RelayTransport:
    def execute(self, method, payload):
        if not isinstance(method, str) or method not in METHODS or not isinstance(payload, dict):
            raise ReceiptPreSendError('Invalid receipt request') from None
        try:
            wire = dict(payload)
            field = {'sendPhoto': 'photo', 'sendDocument': 'document'}.get(method)
            if field:
                data = wire.get(field)
                limit = MAX_PHOTO_BYTES if field == 'photo' else MAX_DOCUMENT_BYTES
                if not isinstance(data, bytes) or not 0 < len(data) <= limit:
                    raise ValueError('Invalid receipt attachment')
                wire[field] = base64.b64encode(data).decode('ascii')
            request = json.dumps({'method': method, 'payload': wire}, ensure_ascii=True).encode('ascii')
            if len(request) > MAX_REQUEST_BYTES:
                raise ValueError('Receipt request exceeds limit')
        except (TypeError, ValueError, UnicodeError, OverflowError, RecursionError):
            raise ReceiptPreSendError('Invalid receipt request') from None

        result = self._post(request)
        if result.emoji_rejected:
            fallback = _unicode_fallback(wire)
            if fallback is not None:
                request = json.dumps({'method': method, 'payload': fallback}, ensure_ascii=True).encode('ascii')
                return self._post(request)
        return result

    def _post(self, request):
        client = http.client.HTTPConnection('127.0.0.1', 8025, timeout=8)
        try:
            client.request('POST', '/telegram/receipt', request, {'Content-Type': 'application/json'})
            response = client.getresponse()
            raw = response.read(MAX_RESPONSE_BYTES + 1)
            if response.status != 200 or len(raw) > MAX_RESPONSE_BYTES:
                return ReceiptResult(None, 'ambiguous')
            return classify(json.loads(raw))
        except (OSError, ValueError, http.client.HTTPException, UnicodeError, RecursionError):
            return ReceiptResult(None, 'ambiguous')
        finally:
            client.close()
