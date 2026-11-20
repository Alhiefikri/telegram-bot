<?php

namespace App\Storefront;

use App\Exceptions\InvalidCheckout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StorefrontCheckoutChallenge
{
    public const ACTION = 'checkout';

    public const UNAVAILABLE_MESSAGE = 'Verifikasi keamanan checkout belum tersedia. Coba lagi nanti atau hubungi admin toko untuk mengaktifkan verifikasi checkout.';

    public const FAILED_MESSAGE = 'Verifikasi keamanan belum berhasil atau sudah kedaluwarsa. Muat ulang halaman, selesaikan verifikasi, lalu coba checkout kembali.';

    public function siteKey(): ?string
    {
        $siteKey = config('services.turnstile.site_key');
        $secretKey = config('services.turnstile.secret_key');

        return is_string($siteKey) && trim($siteKey) !== ''
            && is_string($secretKey) && trim($secretKey) !== ''
            && $this->expectedHostname() !== null
                ? trim($siteKey)
                : null;
    }

    public function verify(Request $request): void
    {
        if ($this->siteKey() === null) {
            throw new InvalidCheckout(StorefrontLocale::text('challenge_unavailable'));
        }

        $token = $request->input('cf-turnstile-response');
        if (! is_string($token) || trim($token) === '' || strlen($token) > 2048) {
            throw new InvalidCheckout(StorefrontLocale::text('challenge_failed'));
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(3)
                ->timeout(10)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (ConnectionException) {
            // Do not retain the transport exception: it can contain request secrets.
            throw new InvalidCheckout(StorefrontLocale::text('challenge_failed'));
        }

        $result = $response->json();
        if (! $response->successful()
            || ! is_array($result)
            || ($result['success'] ?? null) !== true
            || ($result['action'] ?? null) !== self::ACTION
            || ($result['hostname'] ?? null) !== $this->expectedHostname()) {
            throw new InvalidCheckout(StorefrontLocale::text('challenge_failed'));
        }
    }

    private function expectedHostname(): ?string
    {
        $url = config('app.url');
        $parts = is_string($url) ? parse_url($url) : false;

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return strtolower($parts['host']);
    }
}
