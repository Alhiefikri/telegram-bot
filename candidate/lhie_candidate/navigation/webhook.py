import hashlib
import hmac
import json

from django.conf import settings
from django.http import JsonResponse

from .ingress import InvalidUpdate, UpdateInbox


def webhook_secret():
    return hmac.new(settings.SECRET_KEY.encode(), b"candidate-navigation-webhook-v1", hashlib.sha256).hexdigest()


def receive(request):
    def reply(status, value):
        result = JsonResponse(value, status=status)
        result["Cache-Control"] = "no-store"
        return result
    if request.method != "POST":
        return reply(405, {"status": "method_not_allowed"})
    if not hmac.compare_digest(request.headers.get("X-Telegram-Bot-Api-Secret-Token", ""), webhook_secret()):
        return reply(403, {"status": "forbidden"})
    if request.content_type != "application/json" or request.headers.get("Transfer-Encoding"):
        return reply(400, {"status": "bad_request"})
    try:
        if len(request.body) > 16384:
            raise InvalidUpdate()
        payload = json.loads(request.body)
        admitted = UpdateInbox().receive(payload)
    except (InvalidUpdate, ValueError, UnicodeError):
        return reply(422, {"status": "unsupported_update"})
    return reply(200, admitted.response)
