<?php

namespace App\Services\Suppliers;

use App\Enums\SupplierProvider;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
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

    private const MAX_QUANTITY = 10_000;

    public function snapshot(Supplier $supplier): SupplierCatalogSnapshot
    {
        $provider = $this->ready($supplier);
        $deadline = hrtime(true) + 40_000_000_000;
        $account = $this->read($supplier, $provider === SupplierProvider::ProdSeller ? '/balance' : '/me', $deadline);
        $catalog = $this->read($supplier, '/products', $deadline);
        $this->currency($supplier, $account);
        $this->currency($supplier, $catalog);
        $balance = $this->money($account['balance'] ?? $account['wallet_balance'] ?? null, roundUp: false);
        $rows = $catalog[$provider === SupplierProvider::ProdSeller || $provider === SupplierProvider::AiVault ? 'products' : 'services'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_PRODUCTS) {
            throw new SupplierUnavailable('invalid_payload');
        }
        $details = 0;
        $products = [];
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $id = $this->identifier($row[$provider === SupplierProvider::ProdSeller ? 'id' : 'service_id'] ?? null);
            if (isset($ids[$id])) {
                throw new SupplierUnavailable('invalid_payload');
            }
            $ids[$id] = true;
            if ($provider === SupplierProvider::ProdSeller && ! array_key_exists('stock', $row)) {
                if (++$details > self::MAX_DETAILS) {
                    throw new SupplierUnavailable('catalog_limit');
                }
                $detail = $this->read($supplier, '/products/'.rawurlencode($id), $deadline);
                if ($this->identifier($detail['id'] ?? null) !== $id) {
                    throw new SupplierUnavailable('invalid_payload');
                }
                $row = array_replace($row, $detail);
            }
            $this->currency($supplier, $row);
            $products[] = $this->product($provider, $row, $id);
        }

        if (hrtime(true) >= $deadline) {
            throw new SupplierUnavailable('snapshot_timeout');
        }

        return new SupplierCatalogSnapshot($balance, $supplier->currency, $products);
    }

    public function purchase(Supplier $supplier, SupplierCatalogItem $item, int $quantity, string $reference): SupplierOrderResult
    {
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
            $body = [$provider === SupplierProvider::ProdSeller ? 'productId' : 'service_id' => $id, 'quantity' => $quantity];
            $headers = [];
            if ($provider === SupplierProvider::ProdSeller) {
                $headers['Idempotency-Key'] = $reference;
            } elseif ($provider === SupplierProvider::AiVault) {
                $body['external_order_id'] = $reference;
            }
            $response = $this->send($supplier, 'POST', $provider === SupplierProvider::ProdSeller ? '/orders' : '/order', $body, $headers);
        } catch (SupplierUnavailable $exception) {
            $ambiguous = $exception->errorCode === 'transport';

            return new SupplierOrderResult($ambiguous ? 'ambiguous' : 'rejected', errorCode: $exception->errorCode);
        }
        if (! $response->successful()) {
            return new SupplierOrderResult($this->noPurchase($provider, $response) ? 'rejected' : 'ambiguous', errorCode: $this->httpCode($response->status()));
        }
        try {
            return $this->result($supplier, $this->payload($response, $supplier->api_key), $quantity, null, $id, $reference);
        } catch (SupplierUnavailable) {
            return new SupplierOrderResult('ambiguous', errorCode: 'invalid_payload');
        }
    }

    public function order(Supplier $supplier, string $orderId, ?string $productId = null, ?string $reference = null): SupplierOrderResult
    {
        $provider = $this->ready($supplier, allowInactive: true);
        $orderId = $this->identifier($orderId);
        $payload = $this->read($supplier, ($provider === SupplierProvider::ProdSeller ? '/orders/' : '/order/').rawurlencode($orderId), allowInactive: true);

        return $this->result($supplier, $payload, null, $orderId, $productId, $reference);
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
        if (! in_array($supplier->currency, ['USD', 'USDT'], true)
            || ($provider->requiresCurrencyConfirmation() && ! $supplier->currency_confirmed)
            || (! $provider->requiresCurrencyConfirmation() && $supplier->currency !== $provider->defaultCurrency())) {
            throw new SupplierUnavailable('currency_mismatch');
        }
        try {
            $key = $supplier->api_key;
        } catch (Throwable) {
            throw new SupplierUnavailable('invalid_key');
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
            throw new SupplierUnavailable($this->httpCode($response->status()));
        }

        return $this->payload($response, $supplier->api_key);
    }

    private function send(Supplier $supplier, string $method, string $path, array $body = [], array $headers = [], ?int $deadline = null, bool $allowInactive = false): Response
    {
        $provider = $this->ready($supplier, $allowInactive);
        $this->budget($supplier, $provider, $method === 'GET');
        $timeout = $deadline === null ? 12 : min(12, ($deadline - hrtime(true)) / 1_000_000_000);
        if ($timeout <= 0) {
            throw new SupplierUnavailable('snapshot_timeout');
        }
        try {
            return Http::acceptJson()->asJson()->timeout($timeout)->connectTimeout(min(4, $timeout))
                ->withHeaders(['X-API-Key' => $supplier->api_key] + $headers)
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
                ])->send($method, $provider->baseUrl().$path, $method === 'POST' ? ['json' => $body] : []);
        } catch (Throwable) {
            // Even an oversized response can follow a successful purchase.
            throw new SupplierUnavailable('transport');
        }
    }

    private function budget(Supplier $supplier, SupplierProvider $provider, bool $read): void
    {
        [$maximum, $seconds, $readMaximum] = match ($provider) {
            SupplierProvider::ProdSeller => [300, 900, 240],
            SupplierProvider::Arrs, SupplierProvider::Warzone => [3, 1, 2],
            SupplierProvider::AiVault => [60, 60, 45],
            SupplierProvider::CSxStore => [60, 60, 45],
        };
        // A shared provider bucket is deliberately conservative: all configured
        // accounts on this application may share the same outbound IP.
        $global = 'supplier:budget:'.$provider->value;
        $account = $global.':'.hash('sha256', $supplier->api_key);
        try {
            Cache::lock($global.':lock', 5)->block(2, function () use ($global, $account, $maximum, $seconds, $readMaximum, $read): void {
                $buckets = [$global => $maximum, $account => $maximum];
                if ($read) {
                    $buckets[$global.':reads'] = $readMaximum;
                    $buckets[$account.':reads'] = $readMaximum;
                }
                foreach ($buckets as $key => $limit) {
                    if (RateLimiter::tooManyAttempts($key, $limit)) {
                        throw new SupplierUnavailable('rate_limited');
                    }
                }
                foreach ($buckets as $key => $limit) {
                    RateLimiter::hit($key, $seconds);
                }
            });
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
        array_walk_recursive($payload, static function ($value) use ($key, &$values): void {
            if (++$values > 50_000 || (is_string($value) && (strlen($value) > 16_000 || ($key !== null && str_contains($value, $key))))) {
                throw new SupplierUnavailable('invalid_payload');
            }
        });

        return $payload;
    }

    private function product(SupplierProvider $provider, array $row, string $id): SupplierProductData
    {
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
        $rawId = $row['orderId'] ?? $row['order_id'] ?? $payload['orderId'] ?? $payload['order_id'] ?? null;
        $id = $rawId === null ? null : $this->identifier($rawId);
        $quantity = isset($row['quantity']) ? $this->integer($row['quantity'], 1, self::MAX_QUANTITY) : null;
        $cost = isset($row['total_cost']) || isset($row['amount']) ? $this->money($row['total_cost'] ?? $row['amount']) : null;
        $deliveries = $row['deliveredKeys'] ?? $row['delivered_products'] ?? $row['products']
            ?? $payload['deliveredKeys'] ?? $payload['delivered_products'] ?? $payload['products']
            ?? (isset($row['deliveredKey']) ? [$row['deliveredKey']] : (isset($payload['deliveredKey']) ? [$payload['deliveredKey']] : []));
        if (! is_array($deliveries) || ! array_is_list($deliveries) || count($deliveries) > self::MAX_QUANTITY) {
            throw new SupplierUnavailable('invalid_payload');
        }
        foreach ($deliveries as $delivery) {
            $this->text($delivery, 16_000);
        }
        $status = $row['status'] ?? $payload['status'] ?? null;
        $success = in_array($status, ['delivered', 'success'], true) || ($status === null && ($row['success'] ?? $payload['success'] ?? false) === true);
        $productId = $row['service_id'] ?? $row['product']['id'] ?? null;
        $mismatch = ($expectedOrderId !== null && $id !== $expectedOrderId)
            || ($expectedProductId !== null && $productId !== null && $this->identifier($productId) !== $expectedProductId)
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
