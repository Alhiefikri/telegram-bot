<?php

namespace App\Services\Customer;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class GoogleOAuthProvider
{
    private const MAX_RESPONSE_BYTES = 16_384;

    public function ready(): bool
    {
        return $this->configurationFingerprint() !== null;
    }

    public function configurationFingerprint(): ?string
    {
        $client = config('services.google.client_id');
        $secret = config('services.google.client_secret');
        $key = config('app.key');
        $callback = $this->callbackUrl();
        if (! is_string($client) || trim($client) === '' || ! is_string($secret) || trim($secret) === ''
            || ! is_string($key) || $key === '' || $callback === null) {
            return null;
        }

        return hash_hmac('sha256', strlen($client).':'.$client.strlen($secret).':'.$secret.$callback, $key);
    }

    public function callbackUrl(): ?string
    {
        $url = config('app.url');
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return rtrim($url, '/').'/auth/google/callback';
    }

    public function authorizationUrl(#[\SensitiveParameter] string $state): string
    {
        if (! $this->ready()) {
            throw new RuntimeException('Google authentication unavailable.');
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->callbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function user(#[\SensitiveParameter] string $code): GoogleOAuthIdentity
    {
        if (! $this->ready()) {
            throw new RuntimeException('Google authentication unavailable.');
        }
        try {
            $tokens = $this->payload($this->http()->asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $this->callbackUrl(),
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]));
            $accessToken = $tokens['access_token'] ?? null;
            if (! is_string($accessToken) || preg_match('/\A[\x21-\x7e]{1,4096}\z/', $accessToken) !== 1
                || ! is_string($tokens['token_type'] ?? null) || strcasecmp($tokens['token_type'], 'Bearer') !== 0) {
                throw new UnexpectedValueException('Invalid Google identity evidence.');
            }
            // ID and refresh tokens are deliberately unused. Only userinfo evidence is accepted.
            unset($tokens);
            $profile = $this->payload($this->http()->withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo'));
            unset($accessToken);
            if (($profile['email_verified'] ?? null) !== true || ! is_string($profile['sub'] ?? null)
                || ! is_string($profile['email'] ?? null)) {
                throw new UnexpectedValueException('Invalid Google identity evidence.');
            }

            return new GoogleOAuthIdentity($profile['sub'], $profile['email']);
        } catch (UnexpectedValueException) {
            throw new UnexpectedValueException('Invalid Google identity evidence.');
        } catch (Throwable) {
            throw new RuntimeException('Google authentication unavailable.');
        }
    }

    private function http(): PendingRequest
    {
        $memory = Utils::streamFor(Utils::tryFopen('php://memory', 'w+'));
        $sink = FnStream::decorate($memory, [
            'write' => static function (string $bytes) use ($memory): int {
                if ($memory->getSize() + strlen($bytes) > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Google response exceeds limit.');
                }

                return $memory->write($bytes);
            },
        ]);

        return Http::acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()->withOptions([
            'verify' => true,
            'debug' => false,
            'on_headers' => static function (ResponseInterface $response): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && (! ctype_digit($length) || (float) $length > self::MAX_RESPONSE_BYTES)) {
                    throw new RuntimeException('Google response exceeds limit.');
                }
            },
            'progress' => static function ($downloadTotal, $downloaded): void {
                if ($downloadTotal > self::MAX_RESPONSE_BYTES || $downloaded > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Google response exceeds limit.');
                }
            },
            'sink' => $sink,
            'read_timeout' => 8,
        ]);
    }

    private function payload(Response $response): array
    {
        try {
            if ($response->status() !== 200) {
                throw new RuntimeException('Google authentication unavailable.');
            }
            $stream = $response->toPsrResponse()->getBody();
            $stream->rewind();
            $body = '';
            while (! $stream->eof() && strlen($body) <= self::MAX_RESPONSE_BYTES) {
                $body .= $stream->read(self::MAX_RESPONSE_BYTES + 1 - strlen($body));
            }
            if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('Google response exceeds limit.');
            }
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload)) {
                throw new UnexpectedValueException('Invalid Google identity evidence.');
            }

            return $payload;
        } finally {
            $response->close();
        }
    }
}
