<?php

namespace App\Http\Middleware;

use App\Services\CustomerApi\CustomerApiAdmission;
use App\Services\CustomerApi\CustomerApiAvailability;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiUsage;
use Closure;
use Illuminate\Http\Exceptions\MalformedUrlException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CustomerApiBoundary
{
    public function __construct(
        private CustomerApiCredentials $credentials,
        private CustomerApiAdmission $admission,
        private CustomerApiUsage $usage,
        private CustomerApiAvailability $availability,
    ) {}

    public static function matches(Request $request): bool
    {
        $path = $request->decodedPath();

        return $path === 'api/v1' || str_starts_with($path, 'api/v1/');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        $requestId = (string) Str::uuid();
        $principal = null;
        $token = '';
        $headers = [];
        $request->attributes->set('customer_api_request_id', $requestId);
        try {
            $this->admission->ip((string) $request->ip());
            // One uncached singleton read before body/auth/financial work. OFF
            // must not authenticate a key, create a claim, lock a wallet or
            // allocate stock; a failed read stays fail-closed as 503.
            $availability = $this->availability->check();
            if ($availability->unavailable) {
                throw CustomerApiFailure::unavailable();
            }
            if (! $availability->enabled) {
                throw new CustomerApiFailure(
                    503,
                    'api_maintenance',
                    'The Customer API is temporarily disabled for maintenance.',
                    ['Retry-After' => (string) CustomerApiAvailability::MAINTENANCE_RETRY_AFTER],
                );
            }
            // ON keeps the established 400 invalid_path contract before
            // authentication; OFF above already answered maintenance first.
            if (! mb_check_encoding(rawurldecode($request->path()), 'UTF-8')) {
                throw new MalformedUrlException;
            }
            $stream = $request->getContent(true);
            if ((int) $request->server('CONTENT_LENGTH', 0) > 32768 || strlen(stream_get_contents($stream, 32769)) > 32768) {
                throw new CustomerApiFailure(413, 'payload_too_large', 'The maximum request body is 32 KiB.');
            }
            if (is_resource($stream)) {
                rewind($stream);
            }
            $principal = $this->credentials->authenticate($request->header('Authorization'));
            // Do not leave the bearer value attached to a request that downstream code could log.
            $request->headers->remove('Authorization');
            $request->server->remove('HTTP_AUTHORIZATION');
            if ($principal === null) {
                $this->admission->invalid((string) $request->ip());
                throw new CustomerApiFailure(401, 'unauthenticated', 'A valid API key is required.', ['WWW-Authenticate' => 'Bearer']);
            }
            if ($request->headers->has('Origin')) {
                throw new CustomerApiFailure(403, 'backend_only', 'Customer API keys are for server-to-server requests only.');
            }
            $mutation = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
            $delivery = preg_match('~\Aapi/v1/orders/[^/]+/delivery/?\z~D', $request->decodedPath()) === 1;
            $reservation = $this->admission->authenticated($principal, $mutation, $delivery);
            $token = $reservation['token'];
            $headers = $reservation['headers'];
            // A separate principal, never an Admin/web/customer-session guard login.
            $request->attributes->set('customer_api_principal', $principal);
            $request->attributes->set('customer_api_request_id', $requestId);
            $this->usage->record($principal);
            $response = $next($request);
        } catch (Throwable $error) {
            $failure = CustomerApiFailure::fromException($error);
            $headers = $failure->headers;
            $response = $this->error($failure->status, $failure->machineCode, $failure->getMessage(), $requestId, $failure->details);
        } finally {
            $request->headers->remove('Authorization');
            $request->server->remove('HTTP_AUTHORIZATION');
            if ($principal !== null) {
                $this->admission->release($principal, $token);
            }
        }
        $response->headers->add($headers + ['X-Request-ID' => $requestId, 'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);

        return $response;
    }

    private function error(int $status, string $code, string $message, string $requestId, array $details = []): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'request_id' => $requestId] + $details], $status);
    }
}
