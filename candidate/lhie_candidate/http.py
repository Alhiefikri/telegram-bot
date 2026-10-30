from django.http import JsonResponse
from django.utils.deprecation import MiddlewareMixin

from .readiness import verify_dependencies


def response(status, code):
    result = JsonResponse({"status": code}, status=status)
    result["Cache-Control"] = "no-store"
    return result


class PayloadSafeMiddleware(MiddlewareMixin):
    def process_request(self, request):
        try:
            request.get_host()
        except Exception:
            return response(400, "bad_request")
        if request.method not in ("GET", "HEAD"):
            result = response(405, "method_not_allowed")
            result["Allow"] = "GET, HEAD"
            return result

    def process_exception(self, request, exception):
        return response(500, "error")

    def process_response(self, request, result):
        if request.method == "HEAD" and not result.streaming:
            result["Content-Length"] = str(len(result.content))
            result.content = b""
        return result


def health(request):
    return response(200, "ok")


def ready(request):
    try:
        verify_dependencies()
    except Exception:
        return response(503, "not_ready")
    return response(200, "ready")


def bad_request(request, exception):
    return response(400, "bad_request")


def forbidden(request, exception):
    return response(403, "forbidden")


def not_found(request, exception):
    return response(404, "not_found")


def server_error(request):
    return response(500, "error")
