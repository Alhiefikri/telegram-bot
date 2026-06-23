<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectOversizedTripayWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('services.tripay.webhook_max_body_bytes', 65536);
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

        if ($contentLength > $maxBytes) {
            return response()->json(['message' => 'Payload Too Large'], 413);
        }

        if ($contentLength === 0 && strlen($request->getContent()) > $maxBytes) {
            return response()->json(['message' => 'Payload Too Large'], 413);
        }

        return $next($request);
    }
}
