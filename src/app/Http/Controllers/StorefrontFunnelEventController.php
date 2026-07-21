<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Storefront\StorefrontFunnelEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class StorefrontFunnelEventController extends Controller
{
    public function __invoke(Request $request, StorefrontFunnelEvents $events): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(StorefrontFunnelEvents::ACTIONS)],
            'order_id' => ['nullable', 'string', 'exists:orders,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'support_channel' => ['nullable', 'string', Rule::in(['whatsapp', 'telegram_admin'])],
            'support_category' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'string', 'max:80'],
            'signed_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $order = isset($validated['order_id']) ? Order::query()->find($validated['order_id']) : null;
        $product = isset($validated['product_id']) ? Product::query()->find($validated['product_id']) : null;
        if ($order !== null) {
            $this->assertSignedOrderContext($order, (string) ($validated['signed_url'] ?? ''));
        }

        $events->logAllowed($validated['action'], $order, $product, [
            'support_channel' => $validated['support_channel'] ?? null,
            'support_category' => $validated['support_category'] ?? null,
            'source' => $validated['source'] ?? 'browser_beacon',
        ]);

        return response()->json(['ok' => true]);
    }

    private function assertSignedOrderContext(Order $order, string $signedUrl): void
    {
        if ($signedUrl === '') {
            throw new InvalidSignatureException;
        }

        $signedRequest = Request::create($signedUrl, 'GET');
        $expectedPath = parse_url(route('storefront.shop.orders.show', $order, false), PHP_URL_PATH);
        if ($expectedPath === null || '/'.ltrim($signedRequest->path(), '/') !== $expectedPath) {
            throw new InvalidSignatureException;
        }

        if (URL::hasValidSignature($signedRequest, false) || URL::hasValidSignature($signedRequest, true)) {
            return;
        }

        throw new InvalidSignatureException;
    }
}
