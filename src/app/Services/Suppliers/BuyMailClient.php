<?php

namespace App\Services\Suppliers;

use App\Models\Supplier;
use App\Services\Warranty\RefundProof;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/** Fixed-origin provider boundary. No transport is allowed under financial locks. */
final class BuyMailClient
{
    public const BASE_URL = 'https://buymail.simuru-store.com/api/reseller';

    public const CATALOG_ID = 'gmail';

    private const MAX_BYTES = 2_097_152;

    // A proof upload may carry up to 4 MB; keep it below the claim worker's
    // 60-second timeout with room for the follow-up authoritative GET.
    private const REFUND_UPLOAD_TIMEOUT = 45;

    public function snapshot(Supplier $supplier): SupplierCatalogSnapshot
    {
        $profile = $this->json($supplier, $this->request($supplier, 'GET', '/profile'));
        if (($profile['currency'] ?? null) !== 'IDR') {
            throw new SupplierUnavailable('currency_mismatch');
        }
        $balance = $this->whole($profile['balance'] ?? null, 0, 1_000_000_000);
        $price = $this->whole($profile['price_per_account'] ?? null, 1, 1_000_000_000);
        $stock = $this->whole($profile['stock_available'] ?? null, 0, 1_000_000_000);
        $this->identifier($profile['id'] ?? null);
        $maximum = $this->whole($profile['limits']['max_quantity_per_order'] ?? null, 1, 50);
        $window = $this->whole($profile['refund_window_minutes'] ?? null, 1, 1440);
        if (! is_bool($profile['refunds_enabled'] ?? null)) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return new SupplierCatalogSnapshot(SupplierMoney::micros($balance), 'IDR', [
            new SupplierProductData(self::CATALOG_ID, 'Gmail', null, SupplierMoney::micros($price), $stock, $stock > 0 && $profile['refunds_enabled'], false),
        ], [
            'account_id' => (string) $profile['id'], 'max_quantity_per_order' => $maximum,
            'refund_window_minutes' => $window, 'refunds_enabled' => $profile['refunds_enabled'],
        ]);
    }

    public function purchase(Supplier $supplier, int $quantity, string $reference): SupplierOrderResult
    {
        try {
            $this->whole($quantity, 1, 50);
            if (preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $reference) !== 1) {
                throw new SupplierUnavailable('invalid_reference');
            }
            $response = $this->request($supplier, 'POST', '/orders', ['quantity' => $quantity, 'reference' => $reference], ['Idempotency-Key' => $reference], 95);
        } catch (SupplierUnavailable $exception) {
            return new SupplierOrderResult($exception->errorCode === 'transport' ? 'ambiguous' : 'rejected', errorCode: $exception->errorCode);
        }
        if (! $response->successful()) {
            // A status alone, especially from a proxy, never establishes no debit.
            $code = $this->errorCode($response);
            $noDebit = match ($response->status()) {
                400 => ['invalid_json', 'invalid_quantity', 'invalid_reference', 'invalid_request', 'idempotency_key_required'],
                401 => ['unauthorized'],
                402 => ['insufficient_balance'],
                403 => ['ip_not_allowed', 'account_suspended'],
                409 => ['out_of_stock', 'daily_limit_reached'],
                429 => ['rate_limited'],
                500 => ['purchase_failed'],
                502 => ['payment_gateway_error'],
                503 => ['stock_verifying'],
                default => [],
            };

            return new SupplierOrderResult(in_array($code, $noDebit, true) ? 'rejected' : 'ambiguous', errorCode: $code ?? 'http_'.$response->status());
        }
        try {
            return $this->orderResult($this->json($supplier, $response), $reference, null, $quantity);
        } catch (SupplierUnavailable) {
            return new SupplierOrderResult('ambiguous', errorCode: 'invalid_payload');
        }
    }

    public function order(Supplier $supplier, string $orderId, ?string $reference): SupplierOrderResult
    {
        $id = $this->identifier($orderId);

        return $this->orderResult($this->json($supplier, $this->request($supplier, 'GET', '/orders/'.rawurlencode($id))), $reference, $id);
    }

    public function recoverPurchase(Supplier $supplier, int $quantity, string $reference, CarbonInterface $dispatchedAt, bool $allowReplay = true): SupplierOrderResult
    {
        if (preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $reference) !== 1) {
            throw new SupplierUnavailable('invalid_reference');
        }
        $payload = $this->json($supplier, $this->request($supplier, 'GET', '/orders', ['limit' => 20, 'reference' => $reference]));
        $orders = $payload['orders'] ?? null;
        if (! is_array($orders) || ! array_is_list($orders) || count($orders) > 20) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $matches = array_values(array_filter($orders, fn ($order) => is_array($order) && ($order['reference'] ?? null) === $reference));
        if (count($matches) === 1) {
            return $this->orderResult(['order' => $matches[0]], $reference, expectedQuantity: $quantity);
        }
        if (! $allowReplay || $orders !== [] || count($matches) > 1 || ! $supplier->is_active || $dispatchedAt->lt(now()->subDays(7)->addMinutes(5))) {
            return new SupplierOrderResult('ambiguous', errorCode: 'reference_recovery_required');
        }

        // An empty read is not proof of no purchase. The original key/body is
        // the safety contract; leave a margin before the provider's retention expiry.
        return $this->purchase($supplier, $quantity, $reference);
    }

    public function account(Supplier $supplier, string $id, string $orderId): array
    {
        return $this->accountResult($this->json($supplier, $this->request($supplier, 'GET', '/accounts/'.rawurlencode($this->identifier($id)))), $id, $orderId);
    }

    public function requestRefund(Supplier $supplier, string $id, string $orderId, RefundProof $proof, string $note): array
    {
        if (mb_strlen($note) > 500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note)) {
            throw new SupplierUnavailable('invalid_note');
        }

        return $this->accountResult($this->json($supplier, $this->request($supplier, 'POST', '/accounts/'.rawurlencode($this->identifier($id)).'/refund', ['note' => $note], timeout: self::REFUND_UPLOAD_TIMEOUT, proof: $proof)), $id, $orderId);
    }

    public function confirmAccount(Supplier $supplier, string $id, string $orderId): array
    {
        return $this->accountResult($this->json($supplier, $this->request($supplier, 'POST', '/accounts/'.rawurlencode($this->identifier($id)).'/confirm')), $id, $orderId);
    }

    public function createDeposit(Supplier $supplier, int $amount, string $key): array
    {
        $this->whole($amount, 5000, 5000000);
        if (preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key) !== 1) {
            throw new SupplierUnavailable('invalid_reference');
        }

        return $this->depositResult($this->json($supplier, $this->request($supplier, 'POST', '/deposits', ['amount' => $amount], ['Idempotency-Key' => $key], 20)), expectedAmount: $amount);
    }

    public function deposit(Supplier $supplier, string $id): array
    {
        return $this->depositResult($this->json($supplier, $this->request($supplier, 'GET', '/deposits/'.rawurlencode($this->identifier($id)))), expectedId: $id);
    }

    public function deposits(Supplier $supplier): array
    {
        $payload = $this->json($supplier, $this->request($supplier, 'GET', '/deposits', ['limit' => 20]));
        $rows = $payload['deposits'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 20) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return array_map(fn ($row) => is_array($row) ? $this->depositResult(['deposit' => $row]) : throw new SupplierUnavailable('invalid_payload'), $rows);
    }

    public function cancelDeposit(Supplier $supplier, string $id): array
    {
        return $this->depositResult($this->json($supplier, $this->request($supplier, 'POST', '/deposits/'.rawurlencode($this->identifier($id)).'/cancel')), expectedId: $id);
    }

    public function orderHistory(Supplier $supplier): array
    {
        $payload = $this->json($supplier, $this->request($supplier, 'GET', '/orders', ['limit' => 20]));
        $rows = $payload['orders'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 20) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $orders = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $result = $this->orderResult(['order' => $row], null);
            $orders[] = ['id' => $result->orderId, 'requested' => $result->requestedQuantity, 'delivered' => $result->quantity, 'cost_idr' => intdiv($result->costMicros, SupplierMoney::SCALE)];
        }

        return $orders;
    }

    private function depositResult(array $payload, ?string $expectedId = null, ?int $expectedAmount = null): array
    {
        $row = $payload['deposit'] ?? $payload;
        $id = $this->identifier($row['id'] ?? null);
        $amount = $this->whole($row['amount'] ?? null, 5000, 5000000);
        $state = $row['status'] ?? null;
        $url = $row['payment_url'] ?? null;
        $deadline = $this->utcTimestamp($row['expires_at'] ?? null);
        if (($expectedId !== null && $id !== $expectedId) || ($expectedAmount !== null && $amount !== $expectedAmount)
            || ($row['currency'] ?? null) !== 'IDR' || ! in_array($state, ['pending', 'paid', 'expired', 'cancelled'], true)
            || ! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return ['id' => $id, 'amount' => $amount, 'currency' => 'IDR', 'status' => $state, 'payment_url' => $url, 'expires_at' => $deadline];
    }

    private function accountResult(array $payload, string $id, string $orderId): array
    {
        $account = $payload['account'] ?? $payload;
        $refund = $account['refund'] ?? null;
        $state = $account['status'] ?? null;
        $decision = is_array($refund) ? ($refund['status'] ?? null) : null;
        if (($account['id'] ?? null) !== $id || ($account['order_id'] ?? null) !== $orderId
            || ! in_array($state, ['active', 'completed', 'refund_pending', 'refunded'], true)
            || ($refund !== null && (! is_array($refund) || ! in_array($decision, ['pending', 'approved', 'rejected', 'reversed', 'cancelled'], true)))
            || ($state === 'refunded' && $decision !== 'approved')
            || ($state === 'refund_pending' && $decision !== 'pending')) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $price = $this->whole($account['price'] ?? null, 1, 1_000_000_000);
        $deadline = $this->utcTimestamp($account['refund_deadline'] ?? null);
        $chat = $refund['chat_url'] ?? null;
        if ($chat !== null && (! is_string($chat) || strlen($chat) > 2048 || ! filter_var($chat, FILTER_VALIDATE_URL)
            || parse_url($chat, PHP_URL_SCHEME) !== 'https' || parse_url($chat, PHP_URL_USER) !== null || parse_url($chat, PHP_URL_PASS) !== null)) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return ['id' => $id, 'order_id' => $orderId, 'status' => $state, 'price' => $price, 'refund_deadline' => $deadline, 'refund_status' => $decision, 'chat_url' => $chat];
    }

    private function orderResult(array $payload, ?string $reference, ?string $expectedId = null, ?int $expectedQuantity = null): SupplierOrderResult
    {
        $order = $payload['order'] ?? null;
        if (! is_array($order) || ($order['currency'] ?? null) !== 'IDR') {
            throw new SupplierUnavailable('invalid_payload');
        }
        $id = $this->identifier($order['id'] ?? null);
        $requested = $this->whole($order['quantity_requested'] ?? null, 1, 50);
        $delivered = $this->whole($order['quantity_delivered'] ?? null, 0, $requested);
        $price = $this->whole($order['unit_price'] ?? null, 1, 1_000_000_000);
        $charged = $this->whole($order['amount_charged'] ?? null, 0, 50_000_000_000);
        $status = $order['status'] ?? null;
        $accounts = $order['accounts'] ?? null;
        if (($expectedId !== null && $id !== $expectedId)
            || ($reference !== null && ($order['reference'] ?? null) !== $reference)
            || ($expectedQuantity !== null && $requested !== $expectedQuantity)
            || ! in_array($status, ['completed', 'partial'], true)
            || ($status === 'completed' && $delivered !== $requested)
            || ($status === 'partial' && $delivered >= $requested)
            || $charged !== $price * $delivered
            || ! is_array($accounts) || ! array_is_list($accounts) || count($accounts) !== $delivered) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $ids = $emails = $units = $deliveries = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $accountId = $this->identifier($account['id'] ?? null);
            $email = $account['email'] ?? null;
            $password = $account['password'] ?? null;
            $deadline = $this->utcTimestamp($account['refund_deadline'] ?? null);
            if (($account['order_id'] ?? null) !== $id || isset($ids[$accountId])
                || ! is_string($email) || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
                || ! is_string($password) || trim($password) === '' || strlen($password) > 4096
                || preg_match('/[\x00-\x1f\x7f]/', $email.$password)
                || isset($emails[strtolower($email)]) || ($account['price'] ?? null) !== $price
                || ! in_array($account['status'] ?? null, ['active', 'completed', 'refund_pending', 'refunded'], true)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $ids[$accountId] = $emails[strtolower($email)] = true;
            $units[] = ['id' => $accountId, 'email' => $email, 'password' => $password, 'refund_deadline' => $deadline, 'status' => $account['status'], 'price' => $price];
            $deliveries[] = $email.' | '.$password;
        }

        $deliverable = count(array_filter($units, fn ($unit) => in_array($unit['status'], ['active', 'completed'], true))) === count($units);

        return new SupplierOrderResult(
            $deliverable ? ($status === 'partial' ? 'partial' : 'delivered') : 'ambiguous', $id, $delivered,
            SupplierMoney::micros($charged), $deliveries,
            errorCode: $deliverable ? null : 'account_not_deliverable',
            identityTrusted: true, deliveryTrusted: $deliverable, accountUnits: $units, requestedQuantity: $requested,
        );
    }

    private function request(Supplier $supplier, string $method, string $path, array $data = [], array $headers = [], int $timeout = 12, ?RefundProof $proof = null): Response
    {
        if (DB::transactionLevel() !== 0) {
            throw new SupplierUnavailable('financial_lock');
        }
        if ($supplier->provider !== 'buymail' || $supplier->currency !== 'IDR' || $supplier->exchange_rate_idr !== 1) {
            throw new SupplierUnavailable('currency_mismatch');
        }
        try {
            $key = $supplier->api_key;
        } catch (Throwable) {
            // The stored ciphertext cannot be read (for example after an
            // APP_KEY change); this is not a provider rejection.
            throw new SupplierUnavailable('credential_unreadable');
        }
        if (! is_string($key) || preg_match('/\A[\x21-\x7e]{1,512}\z/D', $key) !== 1) {
            throw new SupplierUnavailable('invalid_key');
        }
        $bucket = 'supplier:buymail:'.hash('sha256', $key);
        try {
            Cache::lock($bucket.':lock', 5)->block(2, function () use ($bucket, $method): void {
                if (Cache::has($bucket.':cooldown') || RateLimiter::tooManyAttempts($bucket, 120)
                    || ($method === 'GET' && RateLimiter::tooManyAttempts($bucket.':reads', 90))) {
                    throw new SupplierUnavailable('rate_limited');
                }
                RateLimiter::hit($bucket, 60);
                if ($method === 'GET') {
                    RateLimiter::hit($bucket.':reads', 60);
                }
            });
        } catch (Throwable) {
            throw new SupplierUnavailable('rate_limited');
        }
        try {
            $request = Http::acceptJson()->withToken($key)->withHeaders($headers)
                ->timeout($timeout)->connectTimeout(4)->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => static function ($response): void {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && (! ctype_digit($length) || strlen($length) > 10 || (int) $length > self::MAX_BYTES)) {
                            throw new SupplierUnavailable('invalid_payload');
                        }
                    },
                    'progress' => static function ($total, $downloaded): void {
                        if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            throw new SupplierUnavailable('invalid_payload');
                        }
                    },
                ]);
            if ($proof !== null) {
                $response = $request->attach('proof', $proof->bytes, $proof->filename, ['Content-Type' => $proof->mime])->post(self::BASE_URL.$path, $data);
            } else {
                $response = $request->asJson()->send($method, self::BASE_URL.$path, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
            }
            if ($response->status() === 429) {
                $retry = $response->header('Retry-After');
                $seconds = is_string($retry) && ctype_digit($retry) ? min(3600, max(1, (int) $retry)) : 60;
                Cache::put($bucket.':cooldown', true, $seconds);
            }

            return $response;
        } catch (Throwable) {
            throw new SupplierUnavailable('transport');
        }
    }

    private function json(Supplier $supplier, Response $response): array
    {
        if (! $response->successful()) {
            $code = $this->errorCode($response);
            $recognized = match ($response->status()) {
                400 => ['proof_required', 'invalid_request', 'invalid_amount', 'invalid_json'],
                401 => ['unauthorized'],
                403 => ['ip_not_allowed', 'account_suspended', 'refunds_disabled'],
                404 => ['not_found'],
                409 => ['deposit_pending', 'refund_window_expired', 'refund_already_requested', 'already_refunded', 'invalid_state'],
                413 => ['proof_too_large'],
                415 => ['proof_invalid_type'],
                422 => ['idempotency_key_reused'],
                429 => ['rate_limited'],
                502 => ['payment_gateway_error'],
                503 => ['stock_verifying'],
                default => [],
            };
            if (in_array($code, $recognized, true)) {
                throw new SupplierUnavailable($code);
            }
            throw new SupplierUnavailable('http_'.$response->status());
        }

        return $this->decode($response, $supplier->api_key);
    }

    private function errorCode(Response $response): ?string
    {
        try {
            $code = $this->decode($response)['error']['code'] ?? null;

            return is_string($code) && preg_match('/\A[a-z_]{1,64}\z/D', $code) === 1 ? $code : null;
        } catch (SupplierUnavailable) {
            return null;
        }
    }

    private function decode(Response $response, ?string $key = null): array
    {
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new SupplierUnavailable('invalid_payload');
        }
        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            throw new SupplierUnavailable('invalid_payload');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new SupplierUnavailable('invalid_payload');
        }
        if ($key !== null) {
            // Match the generic supplier boundary: no successful provider echo
            // may turn an operator API credential into delivery/payment data.
            array_walk_recursive($payload, static function ($value) use ($key): void {
                if (is_string($value) && str_contains($value, $key)) {
                    throw new SupplierUnavailable('invalid_payload');
                }
            });
        }

        return $payload;
    }

    private function utcTimestamp(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 40
            || preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|\+00:00)\z/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return $value;
    }

    private function whole(mixed $value, int $minimum, int $maximum): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return $value;
    }

    private function identifier(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || in_array($value, ['.', '..'], true)
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', (string) $value) !== 1) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return (string) $value;
    }
}
