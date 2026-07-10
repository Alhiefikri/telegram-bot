<?php

namespace App\Storefront;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class StorefrontOrderAccess
{
    private const SESSION_ISSUED_KEY_PREFIX = 'storefront.order_access.issued_key.';

    private const SESSION_GRANT_PREFIX = 'storefront.order_access.grant.';

    public function issue(Order $order): string
    {
        $key = 'LHIE-'.strtoupper(Str::random(20));

        $order->forceFill([
            'credential_access_key_hash' => Hash::make($key),
        ])->save();

        return $key;
    }

    public function rememberIssuedKey(Request $request, Order $order, string $key): void
    {
        $request->session()->put($this->issuedKeySessionKey($order), Crypt::encryptString($key));
    }

    public function issuedKey(Request $request, Order $order): ?string
    {
        $encryptedKey = $request->session()->get($this->issuedKeySessionKey($order));

        if (! is_string($encryptedKey) || $encryptedKey === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedKey);
        } catch (Throwable) {
            $request->session()->forget($this->issuedKeySessionKey($order));

            return null;
        }
    }

    public function grantFromIssuedKey(Request $request, Order $order): bool
    {
        $key = $this->issuedKey($request, $order);

        if ($key === null || ! $this->matches($order, $key)) {
            return false;
        }

        $this->grant($request, $order);

        return true;
    }

    public function authenticate(Request $request, Order $order, string $key): bool
    {
        if (! $this->matches($order, $key)) {
            return false;
        }

        $this->grant($request, $order);

        return true;
    }

    public function canAccess(Request $request, Order $order): bool
    {
        $expiresAt = $request->session()->get($this->grantSessionKey($order));

        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        try {
            $isValid = Carbon::parse($expiresAt)->isFuture();
        } catch (Throwable) {
            $isValid = false;
        }

        if (! $isValid) {
            $request->session()->forget($this->grantSessionKey($order));
        }

        return $isValid;
    }

    public function hasKey(Order $order): bool
    {
        return filled($order->credential_access_key_hash);
    }

    private function matches(Order $order, string $key): bool
    {
        $hash = $order->credential_access_key_hash;

        return is_string($hash)
            && $hash !== ''
            && Hash::check($this->normalize($key), $hash);
    }

    private function grant(Request $request, Order $order): void
    {
        $minutes = max(5, (int) config('services.storefront.credential_access_session_minutes', 30));

        $request->session()->put($this->grantSessionKey($order), now()->addMinutes($minutes)->toIso8601String());
    }

    private function normalize(string $key): string
    {
        return strtoupper(trim($key));
    }

    private function issuedKeySessionKey(Order $order): string
    {
        return self::SESSION_ISSUED_KEY_PREFIX.$order->getKey();
    }

    private function grantSessionKey(Order $order): string
    {
        return self::SESSION_GRANT_PREFIX.$order->getKey();
    }
}
