<?php

namespace App\Services\Customer;

use App\Enums\CustomerIdentityProvider;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;

final class CustomerTelegramDevices
{
    public const COOKIE = 'customer_telegram_device';

    public const SESSION_KEY = 'customer_telegram_device_hash';

    public function issue(Customer $customer): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addDays(30);
        DB::table('customer_telegram_devices')->insert([
            'customer_id' => $customer->getKey(),
            'token_hash' => hash('sha256', $token),
            'auth_version' => $customer->auth_version,
            'telegram_id' => (string) $customer->telegram_id,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $expiresAt->timestamp];
    }

    public function remember(Request $request, #[\SensitiveParameter] string $token, int $expiresAt): void
    {
        $request->session()->put(self::SESSION_KEY, hash('sha256', $token));
        $this->queueCookie($request, $token, $expiresAt);
    }

    public function restore(Request $request): void
    {
        $hash = $this->cookieHash($request);
        if ($hash === null || ! app(CustomerAuthAvailability::class)->enabled()) {
            return;
        }
        $customer = Customer::query()->whereExists(fn ($query) => $query->selectRaw('1')
            ->from('customer_telegram_devices as device')
            ->where('device.token_hash', $hash)
            ->where('device.expires_at', '>', now())
            ->whereColumn('device.customer_id', 'customers.id')
            ->whereColumn('device.auth_version', 'customers.auth_version'))
            ->first();
        if (! $customer || ! $this->validFor($customer, $hash)
            || ! $customer->identities()->where('provider', CustomerIdentityProvider::Telegram->value)
                ->where('provider_subject', (string) $customer->telegram_id)->exists()) {
            $this->queueCookie($request, '', 1);

            return;
        }

        app(CustomerSessionAuthentication::class)->authenticate($customer, $request, fresh: false);
        $request->session()->put([
            self::SESSION_KEY => $hash,
            'customer_telegram_authenticated' => true,
        ]);
    }

    public function validFor(Customer $customer, string $hash): bool
    {
        return $customer->telegram_id !== null
            && DB::table('customer_telegram_devices')
                ->where('token_hash', $hash)
                ->where('customer_id', $customer->getKey())
                ->where('auth_version', $customer->auth_version)
                ->where('telegram_id', (string) $customer->telegram_id)
                ->where('expires_at', '>', now())
                ->exists();
    }

    public function forget(Request $request): void
    {
        $hashes = array_filter([$this->cookieHash($request), $request->session()->pull(self::SESSION_KEY)], 'is_string');
        if ($hashes !== []) {
            DB::table('customer_telegram_devices')->whereIn('token_hash', $hashes)->delete();
            $this->queueCookie($request, '', 1);
        }
    }

    private function cookieHash(Request $request): ?string
    {
        $token = $request->cookie(self::COOKIE);

        return is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1 ? hash('sha256', $token) : null;
    }

    private function queueCookie(Request $request, #[\SensitiveParameter] string $token, int $expiresAt): void
    {
        Cookie::queue(ResponseCookie::create(self::COOKIE, $token, $expiresAt, '/akun',
            secure: $request->isSecure() || (bool) config('session.secure'), httpOnly: true, sameSite: ResponseCookie::SAMESITE_LAX));
    }
}
