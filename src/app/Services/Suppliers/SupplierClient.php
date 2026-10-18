<?php

namespace App\Services\Suppliers;

use App\Enums\SupplierProvider;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class SupplierClient
{
    private const MAX_BYTES = 2_097_152;

    private const MAX_PRODUCTS = 500;

    private const MAX_DETAILS = 100;

    private const DETAIL_CONCURRENCY = 5;

    private const MAX_QUANTITY = 10_000;

    public const TOOL_ORAX_ORDER_HISTORY_LIMIT = 100;

    public function snapshot(Supplier $supplier): SupplierCatalogSnapshot
    {
        if ($supplier->provider === 'buymail') {
            if (! $supplier->is_active) {
                throw new SupplierUnavailable('inactive');
            }

            return app(BuyMailClient::class)->snapshot($supplier);
        }
        $provider = $this->ready($supplier);
        $deadline = hrtime(true) + 40_000_000_000;
        $account = $this->read($supplier, in_array($provider, [SupplierProvider::ProdSeller, SupplierProvider::Canboso, SupplierProvider::Nastele], true) ? '/balance' : '/me', $deadline);
        $catalog = $this->read($supplier, '/products', $deadline);
        if ($provider === SupplierProvider::Canboso) {
            return $this->canbosoSnapshot($supplier, $account, $catalog);
        }
        if ($provider === SupplierProvider::Nastele) {
            return $this->nasteleSnapshot($supplier, $account, $catalog);
        }
        $this->currency($supplier, $account);
        $this->currency($supplier, $catalog);
        $balance = $this->money($account['balance'] ?? $account['wallet_balance'] ?? null, roundUp: false);
        $rows = $catalog[match ($provider) {
            SupplierProvider::ProdSeller, SupplierProvider::AiVault, SupplierProvider::ToolOrax => 'products',
            default => 'services',
        }] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_PRODUCTS) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $ids = [];
        $detailCount = 0;
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $idKey = match ($provider) {
                SupplierProvider::ProdSeller, SupplierProvider::ToolOrax => 'id',
                default => 'service_id',
            };
            $id = $this->identifier($row[$idKey] ?? null);
            if (isset($ids[$id])) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $ids[$id] = true;
            if ($provider === SupplierProvider::ProdSeller && ! array_key_exists('stock', $row)) {
                if (++$detailCount > self::MAX_DETAILS) {
                    throw new SupplierUnavailable('catalog_limit');
                }
            }
            $normalized[] = ['id' => $id, 'row' => $row];
        }
        $products = [];
        foreach (array_chunk($normalized, self::DETAIL_CONCURRENCY) as $chunk) {
            $detailIds = [];
            foreach ($chunk as $entry) {
                if ($provider === SupplierProvider::ProdSeller && ! array_key_exists('stock', $entry['row'])) {
                    $detailIds[] = $entry['id'];
                }
            }
            $details = $this->productDetails($supplier, $detailIds, $deadline);
            foreach ($chunk as $entry) {
                $id = $entry['id'];
                $row = isset($details[$id]) ? array_replace($entry['row'], $details[$id]) : $entry['row'];
                $this->currency($supplier, $row);
                $products[] = $this->product($provider, $row, $id);
            }
        }

        if (hrtime(true) >= $deadline) {
            throw new SupplierUnavailable('snapshot_timeout');
        }

        return new SupplierCatalogSnapshot($balance, $supplier->currency, $products);
    }

    public function purchase(Supplier $supplier, SupplierCatalogItem $item, int $quantity, string $reference): SupplierOrderResult
    {
        if ($supplier->provider === 'buymail') {
            if (! $supplier->is_active || $supplier->purchase_paused_until?->isFuture() || $quantity > min(50, max(1, (int) ($supplier->account_metadata['max_quantity_per_order'] ?? 50)))
                || (int) $item->supplier_id !== (int) $supplier->id || $item->external_id !== BuyMailClient::CATALOG_ID || ! $item->orderable || $item->requires_input) {
                return new SupplierOrderResult('rejected', errorCode: 'unsupported_product');
            }

            return app(BuyMailClient::class)->purchase($supplier, $quantity, $reference);
        }
        try {
            $provider = $this->ready($supplier);
            if ((int) $item->supplier_id !== (int) $supplier->id || ! $item->orderable || $item->requires_input || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                throw new SupplierUnavailable('unsupported_product');
            }
            if ($item->currency !== null && $item->currency !== $supplier->currency) {
                throw new SupplierUnavailable('currency_mismatch');
            }
            $id = $this->identifier($item->external_id);
            $this->identifier($reference, 100);
            if ($provider === SupplierProvider::ToolOrax && ! ctype_digit($id)) {
                throw new SupplierUnavailable('unsupported_product');
            }
            if ($provider === SupplierProvider::Nastele) {
                $this->integer(ctype_digit($id) && strlen($id) <= 10 ? (int) $id : null, 1, 2_147_483_647);
            }
            $body = match ($provider) {
                SupplierProvider::ProdSeller => ['productId' => $id, 'quantity' => $quantity],
                SupplierProvider::ToolOrax => ['product_id' => (int) $id, 'quantity' => $quantity],
                SupplierProvider::Nastele => ['productId' => (int) $id, 'qty' => $quantity],
                SupplierProvider::Canboso => ['key' => $supplier->api_key, 'product_id' => $id, 'quantity' => $quantity],
                default => ['service_id' => $id, 'quantity' => $quantity],
            };
            $headers = [];
            if (in_array($provider, [SupplierProvider::ProdSeller, SupplierProvider::NyStore, SupplierProvider::Canboso], true)) {
                $headers['Idempotency-Key'] = $reference;
            }
            if ($provider === SupplierProvider::NyStore) {
                // Documented fastest mode: skip the local checker wait and omit
                // proof files; the admin audit still runs after delivery.
                $body['precheck'] = false;
                $body['include_proofs'] = false;
            } elseif ($provider === SupplierProvider::AiVault) {
                $body['external_order_id'] = $reference;
            }
            $path = match ($provider) {
                SupplierProvider::ProdSeller, SupplierProvider::ToolOrax, SupplierProvider::Nastele => '/orders',
                SupplierProvider::Canboso => '/purchase',
                default => '/order',
            };
            $response = $this->send($supplier, 'POST', $path, $body, $headers);
        } catch (SupplierUnavailable $exception) {
            $ambiguous = $exception->errorCode === 'transport';

            return new SupplierOrderResult($ambiguous ? 'ambiguous' : 'rejected', errorCode: $exception->errorCode);
        }
        if (! $response->successful()) {
            // NyStore can answer a paid, still-verifying order with 503 (or a
            // refunded one with 409) while returning the saved order identity.
            // Keep that evidence so recovery reads the same order instead of
            // dispatching a second purchase.
            if ($provider === SupplierProvider::NyStore) {
                $orderId = null;
                try {
                    $rawOrderId = $this->payload($response, $supplier->api_key)['order_id'] ?? null;
                    $orderId = $rawOrderId === null ? null : $this->identifier($rawOrderId);
                } catch (SupplierUnavailable) {
                    $orderId = null;
                }
                if ($orderId !== null) {
                    return new SupplierOrderResult('ambiguous', $orderId, null, null, [], $this->httpCode($response->status()), identityTrusted: true);
                }
            }

            return new SupplierOrderResult($this->noPurchase($provider, $response) ? 'rejected' : 'ambiguous', errorCode: $this->httpCode($response->status()));
        }
        try {
            if ($provider === SupplierProvider::Canboso) {
                return $this->canbosoResult($supplier, $this->payload($response, $supplier->api_key), $quantity, $id);
            }
            if ($provider === SupplierProvider::Nastele) {
                return $this->nasteleResult($this->payload($response, $supplier->api_key), $quantity, null);
            }
            $result = $this->result($supplier, $this->payload($response, $supplier->api_key), $quantity, null, $id, $reference);
        } catch (SupplierUnavailable) {
            return new SupplierOrderResult('ambiguous', errorCode: 'invalid_payload');
        }
        // ShopBot's documented create envelope omits canonical status, even
        // when it includes delivery. Only an otherwise correlated envelope
        // may hydrate its exact order; never launder a POST payload mismatch.
        if ($provider === SupplierProvider::ToolOrax && $result->orderId !== null && $result->errorCode === 'invalid_order_status') {
            try {
                $detail = $this->order($supplier, $result->orderId, $id, $reference);
                if ($detail->errorCode === 'payload_mismatch'
                    || $detail->quantity !== $quantity
                    || $detail->costMicros === null
                    || ($result->quantity !== null && $result->quantity !== $detail->quantity)
                    || ($result->costMicros !== null && $result->costMicros !== $detail->costMicros)
                    || ($result->deliveries !== [] && (count($result->deliveries) !== count($detail->deliveries)
                        || array_diff($result->deliveries, $detail->deliveries) !== []
                        || array_diff($detail->deliveries, $result->deliveries) !== []))) {
                    // Preserve exact recovery identity, not disputed debit evidence.
                    return new SupplierOrderResult('ambiguous', $result->orderId, errorCode: 'payload_mismatch', identityTrusted: true);
                }
                if ($detail->identityTrusted) {
                    return $detail;
                }
            } catch (SupplierUnavailable) {
                // Retain only the correlated POST identity for exact recovery;
                // unrecognized POST delivery cannot authorize allocation.
            }

            return new SupplierOrderResult('ambiguous', $result->orderId, errorCode: 'delivery_pending', identityTrusted: true);
        }

        return $result;
    }

    public function order(Supplier $supplier, string $orderId, ?string $productId = null, ?string $reference = null): SupplierOrderResult
    {
        if ($supplier->provider === 'buymail') {
            return app(BuyMailClient::class)->order($supplier, $orderId, $reference);
        }
        $provider = $this->ready($supplier, allowInactive: true);
        $orderId = $this->identifier($orderId);
        if ($provider === SupplierProvider::Canboso) {
            $payload = $this->read($supplier, '/orders?orderCode='.rawurlencode($orderId), allowInactive: true);
            $rows = $payload['orders'] ?? null;
            if (($payload['success'] ?? null) !== true || ! is_array($rows) || ! array_is_list($rows)
                || count($rows) !== 1 || ($rows[0]['orderCode'] ?? null) !== $orderId) {
                throw new SupplierUnavailable('invalid_payload');
            }

            // History deliberately omits credentials and cannot authorize delivery.
            return new SupplierOrderResult('ambiguous', $orderId, errorCode: 'delivery_unavailable', identityTrusted: true);
        }
        $path = match ($provider) {
            SupplierProvider::ProdSeller, SupplierProvider::ToolOrax, SupplierProvider::Nastele => '/orders/',
            default => '/order/',
        };
        $payload = $this->read($supplier, $path.rawurlencode($orderId), allowInactive: true);
        if ($provider === SupplierProvider::Nastele) {
            return $this->nasteleResult($payload, null, $orderId);
        }

        return $this->result($supplier, $payload, null, $orderId, $productId, $reference);
    }

    /** Bounded GET-only history; CSxStore pagination/timezone are not qualified. */
    public function orderHistory(Supplier $supplier, string $timezone): SupplierOrderHistorySnapshot
    {
        if ($supplier->provider === SupplierProvider::CSxStore->value) {
            return $this->csxOrderHistory($supplier);
        }
        if ($supplier->provider !== SupplierProvider::ToolOrax->value) {
            throw new SupplierUnavailable('order_history_unsupported');
        }
        try {
            new \DateTimeZone($timezone);
        } catch (Throwable) {
            throw new SupplierUnavailable('invalid_history_timezone');
        }

        $payload = $this->read(
            $supplier,
            '/orders?limit='.self::TOOL_ORAX_ORDER_HISTORY_LIMIT,
            hrtime(true) + 12_000_000_000,
            allowInactive: true,
        );
        if (($payload['ok'] ?? null) !== true
            || ! is_int($payload['count'] ?? null)
            || $payload['count'] < 0
            || ! is_array($payload['orders'] ?? null)
            || ! array_is_list($payload['orders'])
            || count($payload['orders']) > self::TOOL_ORAX_ORDER_HISTORY_LIMIT
            || $payload['count'] < count($payload['orders'])) {
            throw new SupplierUnavailable('invalid_order_history');
        }

        $orders = [];
        $seenOrderIds = [];
        foreach ($payload['orders'] as $row) {
            if (! is_array($row)) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            $productId = $row['product_id'] ?? null;
            if (is_string($productId) && ctype_digit($productId) && strlen($productId) <= 10) {
                $productId = (int) $productId;
            }
            $orderId = $this->identifier($row['order_code'] ?? null);
            if (isset($seenOrderIds[$orderId])) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            $seenOrderIds[$orderId] = true;
            $productId = $this->integer($productId, 1, 2_147_483_647);
            $quantity = $this->integer($row['quantity'] ?? null, 1, self::MAX_QUANTITY);
            $total = $this->money($row['total'] ?? null, roundUp: false);
            $status = $this->text($row['status'] ?? null, 40);
            $createdAtValue = $this->text($row['created_at'] ?? null, 32);
            try {
                $format = strlen($createdAtValue) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
                $createdAt = CarbonImmutable::createFromFormat('!'.$format, $createdAtValue, $timezone);
            } catch (Throwable) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            $errors = CarbonImmutable::getLastErrors();
            if ($createdAt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $createdAt->format($format) !== $createdAtValue) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            $orders[] = [
                'order_id' => $orderId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'total_micros' => $total,
                'status' => $status,
                'created_at' => $createdAt,
                'timestamp_precision' => $format === 'Y-m-d H:i' ? 'minute' : 'second',
            ];
        }

        $digestRows = array_map(static fn (array $row): array => [
            'order_id' => $row['order_id'],
            'product_id' => $row['product_id'],
            'quantity' => $row['quantity'],
            'total_micros' => $row['total_micros'],
            'status' => $row['status'],
            'created_at' => $row['created_at']->format('Y-m-d H:i:s'),
            'timestamp_precision' => $row['timestamp_precision'],
        ], $orders);

        return new SupplierOrderHistorySnapshot(
            self::TOOL_ORAX_ORDER_HISTORY_LIMIT,
            $payload['count'],
            $orders,
            $timezone,
            hash('sha256', json_encode($digestRows, JSON_THROW_ON_ERROR)),
        );
    }

    private function csxOrderHistory(Supplier $supplier): SupplierOrderHistorySnapshot
    {
        // The published contract documents /orders, not pagination parameters.
        $payload = $this->read($supplier, '/orders', hrtime(true) + 12_000_000_000, allowInactive: true);
        $this->currency($supplier, $payload);
        if (($payload['success'] ?? null) !== true
            || ($payload['page'] ?? null) !== 1
            || ! is_int($payload['limit'] ?? null) || $payload['limit'] < 1
            || ! is_int($payload['total_orders'] ?? null) || $payload['total_orders'] < 0 || $payload['total_orders'] > 2_147_483_647
            || ! is_int($payload['total_pages'] ?? null) || $payload['total_pages'] < 0
            || ! is_array($payload['orders'] ?? null) || ! array_is_list($payload['orders'])
            || count($payload['orders']) > self::TOOL_ORAX_ORDER_HISTORY_LIMIT
            || count($payload['orders']) > $payload['limit']
            || count($payload['orders']) > $payload['total_orders']) {
            throw new SupplierUnavailable('invalid_order_history');
        }
        $orders = [];
        $seen = [];
        foreach ($payload['orders'] as $row) {
            if (! is_array($row)) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            $this->currency($supplier, $row);
            $id = $this->identifier($row['order_id'] ?? null, 128);
            if (isset($seen[$id])) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            $seen[$id] = true;
            $rawTime = $this->text($row['created_at'] ?? null, 32);
            // Validate calendar syntax without assigning an instant to offset-free time.
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $rawTime, 'UTC');
            } catch (Throwable) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            $errors = CarbonImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $parsed->format('Y-m-d H:i:s') !== $rawTime) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            try {
                $cost = SupplierMoney::micros($row['total_cost'] ?? '');
            } catch (Throwable) {
                throw new SupplierUnavailable('invalid_order_history');
            }
            $status = $this->text($row['status'] ?? null, 40);
            $orders[] = [
                'order_id' => $id,
                'product_id' => $this->identifier($row['service_id'] ?? null),
                'quantity' => $this->integer($row['quantity'] ?? null, 1, self::MAX_QUANTITY),
                'total_micros' => $cost,
                'currency' => $supplier->currency,
                'status' => in_array($status, ['completed', 'pending', 'paid', 'queued', 'undelivered', 'processing', 'failed', 'cancelled', 'refunded'], true) ? $status : 'unrecognized',
                'created_at' => null,
                'created_at_raw' => $rawTime,
                'timezone_qualification' => 'unknown_offset_free',
            ];
        }
        $truncated = count($orders) >= self::TOOL_ORAX_ORDER_HISTORY_LIMIT
            || $payload['total_orders'] > count($orders) || $payload['total_pages'] > 1;

        return new SupplierOrderHistorySnapshot(
            self::TOOL_ORAX_ORDER_HISTORY_LIMIT, $payload['total_orders'], $orders, 'unknown',
            hash('sha256', json_encode($orders, JSON_THROW_ON_ERROR)),
            $truncated ? 'truncated' : 'first_page',
        );
    }

    private function ready(Supplier $supplier, bool $allowInactive = false): SupplierProvider
    {
        $provider = SupplierProvider::tryFrom($supplier->provider instanceof SupplierProvider ? $supplier->provider->value : (string) $supplier->provider);
        if ($provider === null) {
            throw new SupplierUnavailable('unsupported_provider');
        }
        if (! $allowInactive && ! $supplier->is_active) {
            throw new SupplierUnavailable('inactive');
        }
        $accountCurrencies = $provider === SupplierProvider::Nastele ? ['VND'] : ['USD', 'USDT'];
        if (! in_array($supplier->currency, $accountCurrencies, true)
            || ($provider->requiresCurrencyConfirmation() && ! $supplier->currency_confirmed)
            || (! $provider->requiresCurrencyConfirmation() && $supplier->currency !== $provider->defaultCurrency())) {
            throw new SupplierUnavailable('currency_mismatch');
        }
        try {
            $key = $supplier->api_key;
        } catch (Throwable) {
            // The stored ciphertext cannot be read (for example after an
            // APP_KEY change); this is not a provider rejection.
            throw new SupplierUnavailable('credential_unreadable');
        }
        if (! is_string($key) || strlen($key) < 1 || strlen($key) > 512 || preg_match('/[^\x21-\x7e]/', $key)) {
            throw new SupplierUnavailable('invalid_key');
        }
        if (DB::connection()->transactionLevel() > 0) {
            throw new SupplierUnavailable('financial_lock');
        }

        return $provider;
    }

    private function read(Supplier $supplier, string $path, ?int $deadline = null, bool $allowInactive = false): array
    {
        $response = $this->send($supplier, 'GET', $path, deadline: $deadline, allowInactive: $allowInactive);
        if (! $response->successful()) {
            throw new SupplierUnavailable($this->httpCode($response->status()),
                $response->status() === 429 ? $this->retryAfterSeconds(SupplierProvider::from($supplier->provider), $response) : 0);
        }

        return $this->payload($response, $supplier->api_key);
    }

    /** Read one bounded group; any failed detail rejects the entire snapshot. */
    private function productDetails(Supplier $supplier, array $ids, int $deadline): array
    {
        if ($ids === []) {
            return [];
        }
        $provider = $this->ready($supplier);
        foreach ($ids as $id) {
            $this->budget($supplier, $provider, true);
        }
        $timeout = min(12, ($deadline - hrtime(true)) / 1_000_000_000);
        if ($timeout <= 0) {
            throw new SupplierUnavailable('snapshot_timeout');
        }
        try {
            $responses = Http::pool(function (Pool $pool) use ($supplier, $provider, $ids, $timeout): void {
                foreach ($ids as $id) {
                    $this->request($supplier, $pool->as($id), $timeout)->get($provider->baseUrl().'/products/'.rawurlencode($id));
                }
            }, concurrency: self::DETAIL_CONCURRENCY);
        } catch (Throwable) {
            throw new SupplierUnavailable(hrtime(true) >= $deadline ? 'snapshot_timeout' : 'transport');
        }
        foreach ($responses as $response) {
            if ($response instanceof Response) {
                $this->observeRateLimit($provider, $response);
            }
        }
        $details = [];
        foreach ($ids as $id) {
            $response = $responses[$id] ?? null;
            if (! $response instanceof Response) {
                throw new SupplierUnavailable(hrtime(true) >= $deadline ? 'snapshot_timeout' : 'transport');
            }
            if (! $response->successful()) {
                throw new SupplierUnavailable($this->httpCode($response->status()),
                    $response->status() === 429 ? $this->retryAfterSeconds($provider, $response) : 0);
            }
            $detail = $this->payload($response, $supplier->api_key);
            if ($this->identifier($detail['id'] ?? null) !== $id) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $details[$id] = $detail;
        }

        return $details;
    }

    private function send(Supplier $supplier, string $method, string $path, array $body = [], array $headers = [], ?int $deadline = null, bool $allowInactive = false): Response
    {
        $provider = $this->ready($supplier, $allowInactive);
        $this->budget($supplier, $provider, $method === 'GET');
        $timeout = $deadline === null ? 12 : min(12, ($deadline - hrtime(true)) / 1_000_000_000);
        if ($timeout <= 0) {
            throw new SupplierUnavailable('snapshot_timeout');
        }
        $options = $method === 'POST' ? ['json' => $body] : [];
        if ($provider === SupplierProvider::Canboso && $method === 'GET') {
            parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
            $path = explode('?', $path, 2)[0];
            $options = ['query' => ['key' => $supplier->api_key] + $query];
        }
        try {
            $response = $this->request($supplier, Http::acceptJson(), $timeout, $headers)
                ->send($method, $provider->baseUrl().$path, $options);
        } catch (Throwable) {
            // Even an oversized response can follow a successful purchase.
            throw new SupplierUnavailable($deadline !== null && hrtime(true) >= $deadline ? 'snapshot_timeout' : 'transport');
        }
        try {
            $this->observeRateLimit($provider, $response);
        } catch (SupplierUnavailable $exception) {
            // A cache failure after dispatch is not evidence of no purchase.
            if ($method === 'POST') {
                throw new SupplierUnavailable('transport');
            }
            throw $exception;
        }

        return $response;
    }

    private function request(Supplier $supplier, PendingRequest $request, float $timeout, array $headers = []): PendingRequest
    {
        $authentication = match ($supplier->provider) {
            SupplierProvider::ToolOrax->value => ['X-Shop-API-Key' => $supplier->api_key],
            SupplierProvider::Canboso->value => [],
            default => ['X-API-Key' => $supplier->api_key],
        };

        return $request->acceptJson()->asJson()->timeout($timeout)->connectTimeout(min(4, $timeout))
            ->withHeaders($authentication + $headers)
            ->withOptions([
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
    }

    private function retryAfterSeconds(SupplierProvider $provider, Response $response): int
    {
        $retry = trim((string) $response->header('Retry-After'));
        $seconds = $provider === SupplierProvider::ProdSeller ? 900 : 60;
        $maximum = $provider === SupplierProvider::Canboso ? 21_600 : 3600;
        if ($retry !== '' && ctype_digit($retry)) {
            $seconds = min($maximum, max(1, (int) $retry));
        } elseif ($retry !== '') {
            try {
                $seconds = min($maximum, max(1, CarbonImmutable::parse($retry)->getTimestamp() - now()->getTimestamp()));
            } catch (Throwable) {
                // Malformed headers retain the provider's conservative fallback.
            }
        }

        return $seconds;
    }

    private function observeRateLimit(SupplierProvider $provider, Response $response): void
    {
        if ($response->status() !== 429) {
            return;
        }
        $seconds = $this->retryAfterSeconds($provider, $response);
        $global = 'supplier:budget:'.$provider->value;
        try {
            Cache::lock($global.':lock', 5)->block(2, function () use ($global, $seconds): void {
                $until = max((int) Cache::get($global.':cooldown', 0), now()->getTimestamp() + $seconds);
                Cache::put($global.':cooldown', $until, $until - now()->getTimestamp());
            });
        } catch (Throwable) {
            throw new SupplierUnavailable('rate_limited', $seconds);
        }
    }

    private function budget(Supplier $supplier, SupplierProvider $provider, bool $read): void
    {
        [$maximum, $seconds, $readMaximum] = match ($provider) {
            SupplierProvider::ProdSeller => [300, 900, 240],
            // NyStore documents 3 requests/second per API key.
            SupplierProvider::Arrs, SupplierProvider::Warzone, SupplierProvider::NyStore => [3, 1, 2],
            SupplierProvider::AiVault => [60, 60, 45],
            SupplierProvider::CSxStore => [60, 60, 45],
            // ShopBot publishes no rate-limit contract; stay deliberately small.
            SupplierProvider::ToolOrax => [30, 60, 20],
            SupplierProvider::Canboso => [30, 60, 20],
            SupplierProvider::Nastele => [60, 60, 45],
        };
        // A shared provider bucket is deliberately conservative: all configured
        // accounts on this application may share the same outbound IP.
        $global = 'supplier:budget:'.$provider->value;
        $account = $global.':'.hash('sha256', $supplier->api_key);
        try {
            Cache::lock($global.':lock', 5)->block(2, function () use ($global, $account, $maximum, $seconds, $readMaximum, $read): void {
                $remaining = (int) Cache::get($global.':cooldown', 0) - now()->getTimestamp();
                if ($remaining > 0) {
                    throw new SupplierUnavailable('rate_limited', $remaining);
                }
                $buckets = [$global => $maximum, $account => $maximum];
                if ($read) {
                    $buckets[$global.':reads'] = $readMaximum;
                    $buckets[$account.':reads'] = $readMaximum;
                }
                foreach ($buckets as $key => $limit) {
                    if (RateLimiter::tooManyAttempts($key, $limit)) {
                        throw new SupplierUnavailable('rate_limited', max(1, RateLimiter::availableIn($key)));
                    }
                }
                foreach ($buckets as $key => $limit) {
                    RateLimiter::hit($key, $seconds);
                }
            });
        } catch (SupplierUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SupplierUnavailable('rate_limited');
        }
    }

    private function payload(Response $response, ?string $key = null): array
    {
        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new SupplierUnavailable('invalid_payload');
        }
        try {
            // Preserve JSON decimal tokens rather than rounding them through a
            // binary float. Integers retain their native type for count checks.
            $exact = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', static fn ($match) => strpbrk($match[0], '.eE') !== false ? '"'.$match[0].'"' : $match[0], $body);
            $payload = json_decode($exact, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            throw new SupplierUnavailable('invalid_payload');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new SupplierUnavailable('invalid_payload');
        }

        $values = 0;
        $inspect = static function (array $data) use ($key, &$values, &$inspect): void {
            foreach ($data as $name => $value) {
                if (++$values > 50_000
                    || (is_string($name) && (strlen($name) > 16_000 || ($key !== null && str_contains($name, $key))))
                    || (is_string($value) && (strlen($value) > 16_000 || ($key !== null && str_contains($value, $key))))) {
                    throw new SupplierUnavailable('invalid_payload');
                }
                if (is_array($value)) {
                    $inspect($value);
                }
            }
        };
        $inspect($payload);

        return $payload;
    }

    private function nasteleSnapshot(Supplier $supplier, array $account, array $catalog): SupplierCatalogSnapshot
    {
        if (($account['success'] ?? null) !== true || ($catalog['success'] ?? null) !== true
            || ! is_array($account['data'] ?? null) || ($account['data']['currency'] ?? null) !== 'VND') {
            throw new SupplierUnavailable('currency_mismatch');
        }
        $rows = $catalog['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_PRODUCTS) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $products = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $id = (string) $this->integer($row['id'] ?? null, 1, 2_147_483_647);
            if (isset($seen[$id])) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $seen[$id] = true;
            $this->currency($supplier, $row);
            $tiers = $row['wholesale_tiers'] ?? [];
            if (! is_array($tiers) || ! array_is_list($tiers) || count($tiers) > 100) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $normalized = [];
            foreach ($tiers as $tier) {
                if (! is_array($tier)) {
                    throw new SupplierUnavailable('invalid_payload');
                }
                $normalized[] = [
                    'min' => $this->integer($tier['minQuantity'] ?? null, 1, self::MAX_QUANTITY),
                    'unit_price_micros' => $this->money($tier['unitPrice'] ?? null),
                ];
            }
            usort($normalized, static fn ($a, $b) => $a['min'] <=> $b['min']);
            foreach ($normalized as $index => &$tier) {
                $next = $normalized[$index + 1]['min'] ?? null;
                if ($next !== null && $next <= $tier['min']) {
                    throw new SupplierUnavailable('invalid_payload');
                }
                $tier['max'] = $next === null ? null : $next - 1;
            }
            unset($tier);
            $product = $this->product(SupplierProvider::Nastele, $row, $id);
            $products[] = new SupplierProductData(
                $id, $product->name, $product->description, $product->unitPriceMicros,
                $product->stock, $product->orderable, $product->requiresInput, $normalized,
            );
        }

        return new SupplierCatalogSnapshot($this->money($account['data']['balance'] ?? null, roundUp: false), 'VND', $products);
    }

    private function nasteleResult(array $payload, ?int $expectedQuantity, ?string $expectedOrderId): SupplierOrderResult
    {
        $row = $payload['data'] ?? null;
        if (($payload['success'] ?? null) !== true || ! is_array($row)) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $id = (string) $this->integer($row['orderId'] ?? null, 1, PHP_INT_MAX);
        if ($expectedOrderId !== null && $id !== $expectedOrderId) {
            return new SupplierOrderResult('ambiguous', errorCode: 'payload_mismatch');
        }
        try {
            if (isset($row['currency']) && $row['currency'] !== 'VND') {
                throw new SupplierUnavailable('currency_mismatch');
            }
            $items = $row['items'] ?? null;
            if (! is_array($items) || ! array_is_list($items) || count($items) !== 1 || ! is_array($items[0])) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $item = $items[0];
            $quantity = $this->integer($item['quantity'] ?? null, 1, self::MAX_QUANTITY);
            $cost = $this->money($row['totalAmount'] ?? null);
            $unit = $this->money($item['unitPrice'] ?? null);
            if (($expectedQuantity !== null && $quantity !== $expectedQuantity)
                || SupplierMoney::checkedMultiply($unit, $quantity) !== $cost) {
                throw new SupplierUnavailable('payload_mismatch');
            }
            $accounts = $item['accounts'] ?? [];
            if (! is_array($accounts) || ! array_is_list($accounts) || count($accounts) > $quantity) {
                throw new SupplierUnavailable('invalid_payload');
            }
            foreach ($accounts as $account) {
                $this->text($account, 16_000);
            }
            $status = $row['status'] ?? null;
            // Only the documented successful create omits status; GET must say completed.
            $completed = $status === 'completed' || ($expectedOrderId === null && $status === null);
            if (! $completed) {
                return new SupplierOrderResult('ambiguous', $id, deliveries: $accounts, errorCode: 'invalid_order_status', identityTrusted: true);
            }
            $complete = count($accounts) === $quantity
                && count(array_unique($accounts, SORT_STRING)) === $quantity;

            return new SupplierOrderResult(
                $complete ? 'delivered' : 'ambiguous', $id, $quantity, $cost, $accounts,
                $complete ? null : 'incomplete_delivery', identityTrusted: true, deliveryTrusted: $complete,
            );
        } catch (SupplierUnavailable $exception) {
            return new SupplierOrderResult('ambiguous', $id, errorCode: $exception->errorCode, identityTrusted: true);
        } catch (\InvalidArgumentException) {
            return new SupplierOrderResult('ambiguous', $id, errorCode: 'invalid_payload', identityTrusted: true);
        }
    }

    private function canbosoSnapshot(Supplier $supplier, array $account, array $catalog): SupplierCatalogSnapshot
    {
        foreach ([$account, $catalog] as $payload) {
            if (($payload['success'] ?? null) !== true || ($payload['walletCurrency'] ?? null) !== $supplier->currency) {
                throw new SupplierUnavailable('currency_mismatch');
            }
        }
        $rows = $catalog['products'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_PRODUCTS) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $products = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_array($row['price'] ?? null) || ! is_array($row['availability'] ?? null)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $this->currency($supplier, $row['price']);
            if (($row['price']['currency'] ?? null) !== $supplier->currency) {
                throw new SupplierUnavailable('currency_mismatch');
            }
            $id = $this->identifier($row['productId'] ?? null);
            if (isset($seen[$id])) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $seen[$id] = true;
            $requiresInput = ($row['productType'] ?? null) !== 'account' || ! empty($row['purchaseRequirements']);
            $products[] = $this->product(SupplierProvider::Canboso, [
                'name' => $row['name'] ?? null,
                'description' => $row['description'] ?? null,
                'price' => $row['price']['amount'] ?? null,
                'stock' => $row['availability']['available'] ?? null,
                'requires_input' => $requiresInput,
            ], $id);
        }

        return new SupplierCatalogSnapshot($this->money($account['balance'] ?? null, roundUp: false), $supplier->currency, $products);
    }

    private function canbosoResult(Supplier $supplier, array $payload, int $quantity, string $productId): SupplierOrderResult
    {
        $row = $payload['order'] ?? null;
        $payment = $payload['payment'] ?? null;
        if (($payload['success'] ?? null) !== true || ! is_array($row) || ! is_array($payment)) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $this->currency($supplier, $payment);
        if (($payment['currency'] ?? null) !== $supplier->currency
            || ($row['productId'] ?? null) !== $productId || ($row['quantity'] ?? null) !== $quantity
            || ($row['productType'] ?? null) !== 'account') {
            return new SupplierOrderResult('ambiguous', errorCode: 'payload_mismatch');
        }
        $id = $this->identifier($row['orderCode'] ?? null);
        $cost = $this->money($payment['amount'] ?? null);
        $quantityMatches = ($row['bonusQuantity'] ?? null) === 0 && ($row['finalQuantity'] ?? null) === $quantity;
        $accounts = $payload['delivery']['accounts'] ?? [];
        if (! is_array($accounts) || ! array_is_list($accounts) || count($accounts) > self::MAX_QUANTITY) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $deliveries = [];
        $users = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $user = $this->text($account['user'] ?? null, 1000);
            $users[$user] = true;
            $lines = [$user, $this->text($account['password'] ?? null, 4000)];
            foreach (['verifyEmail', 'expiryText', 'otherInfo'] as $field) {
                if (isset($account[$field]) && $account[$field] !== '') {
                    $lines[] = $field.': '.$this->text($account[$field], 4000);
                }
            }
            $deliveries[] = $this->text(implode("\n", $lines), 16_000);
        }
        $completed = ($row['status'] ?? null) === 'completed';
        $complete = $completed && $quantityMatches && count($deliveries) === $quantity && count($users) === $quantity;

        return new SupplierOrderResult(
            $complete ? 'delivered' : 'ambiguous', $id, $quantity, $cost, $deliveries,
            $complete ? null : ($quantityMatches ? 'incomplete_delivery' : 'unexpected_delivery_quantity'), identityTrusted: true, deliveryTrusted: $complete,
        );
    }

    private function product(SupplierProvider $provider, array $row, string $id): SupplierProductData
    {
        // ShopBot exposes catalog economics as unit_price/stock_count rather
        // than the price/stock field names used by the other services catalogs.
        if ($provider === SupplierProvider::ToolOrax) {
            if (! array_key_exists('price', $row) && array_key_exists('unit_price', $row)) {
                $row['price'] = $row['unit_price'];
            }
            if (! array_key_exists('stock', $row) && array_key_exists('stock_count', $row)) {
                $row['stock'] = $row['stock_count'];
            }
        }
        $name = $this->text($row['name'] ?? null, 500);
        $description = isset($row['description']) && $row['description'] !== '' ? $this->text($row['description'], 16_000) : null;
        $stock = array_key_exists('stock', $row) && $row['stock'] === null ? null : $this->integer($row['stock'] ?? null, 0, 1_000_000_000);
        $requiresInput = $this->boolean($row, 'requiresEmailActivation', false)
            || $this->boolean($row, 'requires_input', false);
        if (isset($row['delivery'])) {
            if (! is_array($row['delivery']) || ! is_string($row['delivery']['type'] ?? null)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $requiresInput = $requiresInput || ! in_array($row['delivery']['type'], ['instant', 'key', 'account', 'link'], true);
        }
        $tiers = $this->tiers($row['price_tiers'] ?? $row['pricing_tiers'] ?? []);
        $discounts = $this->discounts($row['bulk_discounts'] ?? []);
        $price = isset($row['price']) ? $this->money($row['price']) : null;
        if ($price === null) {
            foreach ($tiers as $tier) {
                if ($tier['min'] === 1) {
                    $price = $tier['unit_price_micros'];
                    break;
                }
            }
        }
        $orderable = $this->boolean($row, 'orderable', true)
            && $this->boolean($row, 'in_stock', true)
            && $this->boolean($row, 'inStock', true)
            && ! $requiresInput && $stock !== null && $stock > 0 && $price !== null;
        if (($row['pricing'] ?? null) === 'unavailable') {
            $orderable = false;
        }

        return new SupplierProductData($id, $name, $description, $price, $stock, $orderable, $requiresInput, $tiers, $discounts);
    }

    private function tiers(mixed $rows): array
    {
        if ($rows === null) {
            return [];
        }
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $tiers = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $minimum = $this->integer($row['min'] ?? $row['min_qty'] ?? null, 1, self::MAX_QUANTITY);
            $maximum = $row['max'] ?? $row['max_qty'] ?? null;
            $maximum = $maximum === null ? null : $this->integer($maximum, $minimum, 1_000_000_000);
            $tiers[] = ['min' => $minimum, 'max' => $maximum, 'unit_price_micros' => $this->money($row['price'] ?? $row['unit_price'] ?? null)];
        }
        usort($tiers, static fn ($a, $b) => $a['min'] <=> $b['min']);
        $previous = 0;
        foreach ($tiers as $tier) {
            if ($tier['min'] <= $previous) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $previous = $tier['max'] ?? PHP_INT_MAX;
        }

        return $tiers;
    }

    private function discounts(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $discounts = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $percent = $this->money($row['discount_pct'] ?? null);
            if ($percent > 100_000_000 || $percent % 10_000 !== 0) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $minimum = $this->integer($row['min_qty'] ?? null, 1, self::MAX_QUANTITY);
            if (isset($discounts[$minimum])) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $discounts[$minimum] = ['min' => $minimum, 'percent_basis_points' => intdiv($percent, 10_000)];
        }
        ksort($discounts);

        return array_values($discounts);
    }

    private function result(Supplier $supplier, array $payload, ?int $expectedQuantity, ?string $expectedOrderId, ?string $expectedProductId = null, ?string $reference = null): SupplierOrderResult
    {
        $this->currency($supplier, $payload);
        $row = $payload['order'] ?? $payload;
        if (! is_array($row)) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $this->currency($supplier, $row);
        $rawId = $row['orderId'] ?? $row['order_id'] ?? $row['order_code']
            ?? $payload['orderId'] ?? $payload['order_id'] ?? $payload['order_code'] ?? null;
        $id = $rawId === null ? null : $this->identifier($rawId);
        $quantity = null;
        foreach (['quantity', 'requested_quantity'] as $quantityKey) {
            if (isset($row[$quantityKey])) {
                $quantity = $this->integer($row[$quantityKey], 1, self::MAX_QUANTITY);
                break;
            }
        }
        $costValue = $row['total_cost'] ?? $row['charged_amount'] ?? $row['amount'] ?? $row['total']
            ?? $payload['total_cost'] ?? $payload['charged_amount'] ?? $payload['amount'] ?? $payload['total'] ?? null;
        $cost = $costValue === null ? null : $this->money($costValue);
        $deliveries = $row['deliveredKeys'] ?? $row['delivered_products'] ?? $row['delivered_keys'] ?? $row['products']
            ?? $payload['deliveredKeys'] ?? $payload['delivered_products'] ?? $payload['delivered_keys'] ?? $payload['products']
            ?? (isset($row['deliveredKey']) ? [$row['deliveredKey']] : (isset($payload['deliveredKey']) ? [$payload['deliveredKey']] : []));
        if (! is_array($deliveries) || ! array_is_list($deliveries) || count($deliveries) > self::MAX_QUANTITY) {
            throw new SupplierUnavailable('invalid_payload');
        }
        foreach ($deliveries as $delivery) {
            $this->text($delivery, 16_000);
        }
        $status = $row['status'] ?? $payload['status'] ?? $row['order_status'] ?? $payload['order_status'] ?? null;
        $success = in_array($status, ['delivered', 'success'], true)
            || ($status === null && ($row['success'] ?? $payload['success'] ?? false) === true)
            || ($supplier->provider === SupplierProvider::CSxStore->value && $status === 'completed' && ($row['success'] ?? $payload['success'] ?? false) === true)
            || ($supplier->provider === SupplierProvider::ToolOrax->value && $status === 'fulfilled');
        $productId = $row['service_id'] ?? $row['product_id'] ?? $row['product']['id'] ?? $payload['product_id'] ?? null;
        $mismatch = ($expectedOrderId !== null && $id !== $expectedOrderId)
            || ($expectedProductId !== null && $productId !== null && $this->identifier($productId) !== $expectedProductId)
            || (in_array($supplier->provider, [SupplierProvider::ToolOrax->value, SupplierProvider::CSxStore->value], true) && $expectedOrderId !== null && $expectedProductId !== null && $productId === null)
            || ($supplier->provider === SupplierProvider::ToolOrax->value && $expectedQuantity !== null && $quantity !== null && $quantity !== $expectedQuantity)
            || ($reference !== null && isset($row['external_order_id']) && $row['external_order_id'] !== $reference);
        $count = $quantity ?? $expectedQuantity;
        if ($mismatch || $id === null || ($count !== null && count($deliveries) > $count)) {
            return new SupplierOrderResult('ambiguous', $id, $quantity, $cost, $deliveries, 'payload_mismatch');
        }
        $recognized = $success || in_array($status, ['pending', 'paid', 'queued', 'undelivered', 'processing'], true);
        if (! $recognized) {
            return new SupplierOrderResult('ambiguous', $id, $quantity, $cost, $deliveries, 'invalid_order_status');
        }
        if (count(array_unique($deliveries, SORT_STRING)) !== count($deliveries)) {
            return new SupplierOrderResult('ambiguous', $id, $quantity, $cost, $deliveries, 'duplicate_delivery', identityTrusted: true);
        }
        $complete = $success && $deliveries !== [] && ($count === null || count($deliveries) === $count);
        $metadataMatches = $expectedQuantity === null || ($quantity === $expectedQuantity && $cost !== null);

        return new SupplierOrderResult(
            $complete && $metadataMatches ? 'delivered' : ($success ? 'ambiguous' : 'pending'),
            $id, $quantity, $cost, $deliveries,
            $complete && $metadataMatches ? null : ($success ? 'incomplete_delivery' : null),
            identityTrusted: true, deliveryTrusted: $success,
        );
    }

    private function noPurchase(SupplierProvider $provider, Response $response): bool
    {
        if ($provider === SupplierProvider::Nastele) {
            if (in_array($response->status(), [401, 402, 403, 404, 422, 429], true)) {
                return true;
            }
            if ($response->status() !== 409) {
                return false;
            }
            try {
                $payload = $this->payload($response);
            } catch (SupplierUnavailable) {
                return false;
            }

            return ($payload['success'] ?? null) === false
                && in_array($payload['message'] ?? $payload['error'] ?? null, [
                    'Insufficient stock', 'Insufficient flash sale quantity', 'Product price changed',
                ], true);
        }
        if ($provider === SupplierProvider::Canboso) {
            return in_array($response->status(), [400, 401, 404, 429], true);
        }
        $status = $response->status();
        if ($provider === SupplierProvider::Warzone) {
            return in_array($status, [400, 401, 403, 404, 405, 413, 429, 503], true);
        }
        if ($provider === SupplierProvider::AiVault) {
            if (in_array($status, [400, 401, 402, 404, 429], true)) {
                return true;
            }
            try {
                $error = $this->payload($response)['error'] ?? null;
            } catch (SupplierUnavailable) {
                return false;
            }

            return ($status === 409 && $error === 'Buffer Stock Conflict') || ($status === 500 && $error === 'internal_error');
        }
        if ($provider === SupplierProvider::NyStore) {
            // 409 (idempotency conflict/refunded) and 503 (delivery
            // verification pending) can both refer to an existing order.
            return in_array($status, [400, 401, 403, 404, 415, 429], true);
        }
        if ($provider === SupplierProvider::ToolOrax) {
            if (in_array($status, [400, 401, 402, 403, 404, 429], true)) {
                return true;
            }
            if ($status === 409) {
                try {
                    $error = $this->payload($response)['error'] ?? null;
                } catch (SupplierUnavailable) {
                    return false;
                }

                // Only documented pre-purchase conflicts prove no order exists.
                return is_string($error) && in_array($error, ['Insufficient balance.', 'Quantity is outside the available range.'], true);
            }

            return false;
        }

        // Conflict/duplicate responses never prove that no order exists.
        return match ($provider) {
            SupplierProvider::ProdSeller => in_array($status, [400, 401, 402, 404, 429], true),
            SupplierProvider::CSxStore => in_array($status, [400, 401, 404, 429], true),
            SupplierProvider::Arrs => in_array($status, [400, 429], true),
            default => false,
        };
    }

    private function currency(Supplier $supplier, array $payload): void
    {
        if (array_key_exists('currency', $payload) && $payload['currency'] !== $supplier->currency) {
            throw new SupplierUnavailable('currency_mismatch');
        }
    }

    private function identifier(mixed $value, int $limit = 160): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || strlen($value) > $limit || in_array($value, ['.', '..'], true) || ! preg_match('/\A[A-Za-z0-9_.:-]+\z/D', $value)) {
            throw new SupplierUnavailable('invalid_identifier');
        }

        return $value;
    }

    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > $limit || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return $value;
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return $value;
    }

    private function money(mixed $value, bool $roundUp = true): int
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new SupplierUnavailable('invalid_payload');
        }
        try {
            if (is_string($value) && strlen($value) <= 32 && preg_match('/\A(0|[1-9][0-9]*)\.([0-9]{7,24})\z/D', $value, $parts)) {
                $micros = SupplierMoney::micros($parts[1].'.'.substr($parts[2], 0, 6));
                $residue = substr($parts[2], 6);

                return $roundUp && trim($residue, '0') !== '' ? SupplierMoney::checkedAdd($micros, 1) : $micros;
            }

            return SupplierMoney::micros($value);
        } catch (Throwable) {
            throw new SupplierUnavailable('invalid_payload');
        }
    }

    private function boolean(array $row, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $row)) {
            return $default;
        }
        if (! is_bool($row[$key])) {
            throw new SupplierUnavailable('invalid_payload');
        }

        return $row[$key];
    }

    private function httpCode(int $status): string
    {
        return match (true) {
            $status >= 300 && $status < 400 => 'redirect',
            in_array($status, [401, 403], true) => 'authentication',
            $status === 429 => 'rate_limited',
            $status === 409 => 'conflict',
            $status >= 500 => 'upstream',
            default => 'request_failed',
        };
    }
}
