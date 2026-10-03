<?php

namespace App\Services\Warranty;

use App\Models\Order;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/** Scoped private claim access, separate from a public invoice number. */
final class WarrantyOrderAccess
{
    public function telegramUrl(Order $order, string $locale): string
    {
        if ($order->customer?->telegram_id === null || ! in_array($locale, ['id', 'en', 'ms'], true)) {
            throw new InvalidArgumentException('Private warranty access is unavailable.');
        }

        return URL::temporarySignedRoute('warranty.show', now()->addDay(), ['order' => $order->id, 'channel' => 'telegram', 'locale' => $locale]);
    }

    public function authorize(Request $request, Order $order, bool $allowSignedGrant = false): void
    {
        $owned = (string) $request->user('customer')?->getKey() === (string) $order->customer_id;
        $key = 'warranty.order_grant.'.$order->id;
        $grant = $request->session()->get($key, 0);
        $existingGrant = is_int($grant) && $grant > time();
        $recoveryAccess = app(StorefrontOrderAccess::class)->canAccess($request, $order);
        $privateLink = $allowSignedGrant && $request->query('channel') === 'telegram' && $request->has('expires')
            && $order->customer?->telegram_id !== null && ($request->hasValidSignatureWhileIgnoring([], true) || $request->hasValidSignatureWhileIgnoring([], false));
        abort_unless($owned || $existingGrant || $recoveryAccess || $privateLink, 403);
        if ($owned || $recoveryAccess || $privateLink) {
            $request->session()->put($key, now()->addMinutes(30)->timestamp);
        }
        $locale = $request->query('locale');
        if (in_array($locale, ['id', 'en', 'ms'], true)) {
            $request->session()->put('warranty.locale.'.$order->id, $locale);
        }
    }

    public function locale(Request $request, Order $order): string
    {
        $locale = $request->session()->get('warranty.locale.'.$order->id, $order->customer?->locale ?? 'id');

        return in_array($locale, ['id', 'en', 'ms'], true) ? $locale : 'en';
    }
}
