<?php

namespace App\Storefront;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StorefrontCheckoutChallenge
{
    public const ACTION = 'checkout';

    public const UNAVAILABLE_MESSAGE = 'Verifikasi keamanan checkout belum tersedia. Coba lagi nanti atau hubungi admin toko untuk mengaktifkan verifikasi checkout.';

    public const FAILED_MESSAGE = 'Verifikasi keamanan belum berhasil atau sudah kedaluwarsa. Muat ulang halaman, selesaikan verifikasi, lalu coba checkout kembali.';

    private const TEST_SITE_KEY = '1x00000000000000000000AA';

    private const TEST_SECRET_KEY = '1x0000000000000000000000000000000AA';

    public function siteKey(): ?string
    {
        $siteKey = config('services.turnstile.site_key');
        $secretKey = config('services.turnstile.secret_key');

        if (self::TEST_SITE_KEY === $siteKey || self::TEST_SECRET_KEY === $secretKey) {
            return $this->usesLocalTestKeys() ? self::TEST_SITE_KEY : null;
        }

        return is_string($siteKey) && trim($siteKey) !== ''
            && is_string($secretKey) && trim($secretKey) !== ''
            && $this->expectedHostname() !== null
                ? trim($siteKey)
                : null;
    }

    public function verify(Request $request): void
    {
        if ($this->siteKey() === null) {
            throw new StorefrontCheckoutError('challenge_unavailable');
        }

        $token = $request->input('cf-turnstile-response');
        if (! is_string($token) || trim($token) === '' || strlen($token) > 2048) {
            throw new StorefrontCheckoutError('challenge_failed');
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
            throw new StorefrontCheckoutError('challenge_failed');
        }

        $result = $response->json();
        if (! $response->successful()
            || ! is_array($result)
            || ($result['success'] ?? null) !== true
            || ! $this->matchesChallenge($result)) {
            throw new StorefrontCheckoutError('challenge_failed');
        }
    }

    private function matchesChallenge(array $result): bool
    {
        if ($this->usesLocalTestKeys()) {
            // Cloudflare Siteverify returns a synthetic hostname and no action for dummy tokens.
            return ($result['hostname'] ?? null) === 'example.com' && ! array_key_exists('action', $result);
        }

        return ($result['action'] ?? null) === self::ACTION
            && ($result['hostname'] ?? null) === $this->expectedHostname();
    }

    private function usesLocalTestKeys(): bool
    {
        return app()->environment('local')
            && config('services.turnstile.site_key') === self::TEST_SITE_KEY
            && config('services.turnstile.secret_key') === self::TEST_SECRET_KEY
            && $this->expectedHostname() !== null;
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
