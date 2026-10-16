<?php

namespace App\Services\CustomerApi;

use App\Enums\BalancePaymentPreference;
use App\Models\CustomerApiOrderClaim;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Illuminate\Http\Request;
use InvalidArgumentException;
use stdClass;

/**
 * Validated, normalized create-order input plus its canonical payload
 * fingerprint. The body carries only fields the canonical checkout actually
 * supports today (no Product buyer-input fields exist), is bounded to 32 KiB by
 * the API boundary, rejects unknown fields, and never trusts a client price:
 * expected totals are consent checks only.
 */
final readonly class CustomerApiOrderRequest
{
    /** Current canonical automatic checkout accepts no buyer-supplied Product input fields. */
    private const SUPPORTED_FIELDS = ['product_id', 'quantity', 'preference', 'expected_totals'];

    private function __construct(
        public string $idempotencyKey,
        public int $productId,
        public int $quantity,
        public ?BalancePaymentPreference $preferenceOverride,
        public ?int $expectedIdrTotal,
        public ?int $expectedUsdTotalMicro,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/\A[\x20-\x7E]{1,128}\z/D', $key) !== 1) {
            throw self::invalid('A printable Idempotency-Key header of at most 128 characters is required.');
        }

        $decoded = json_decode($request->getContent(), false);
        if (! $decoded instanceof stdClass) {
            throw self::invalid('The request body must be a JSON object.');
        }
        $fields = get_object_vars($decoded);
        if (array_diff(array_keys($fields), self::SUPPORTED_FIELDS) !== []) {
            throw self::invalid('The request contains unsupported fields.');
        }

        $productId = is_string($fields['product_id'] ?? null) ? self::positiveInteger($fields['product_id']) : null;
        if ($productId === null) {
            throw self::invalid('product_id must be a canonical positive decimal string.');
        }
        $quantity = $fields['quantity'] ?? null;
        if (! is_int($quantity) || $quantity < 1 || $quantity > 100) {
            throw self::invalid('quantity must be an integer between 1 and 100.');
        }
        $preference = $fields['preference'] ?? null;
        if ($preference !== null && (! is_string($preference) || BalancePaymentPreference::tryFrom($preference) === null)) {
            throw self::invalid('preference must be idr_only, usd_only, idr_first or usd_first.');
        }

        [$idrTotal, $usdTotalMicro] = self::expectedTotals($fields['expected_totals'] ?? null);

        return new self(
            $key,
            $productId,
            $quantity,
            $preference === null ? null : BalancePaymentPreference::from($preference),
            $idrTotal,
            $usdTotalMicro,
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'operation' => CustomerApiOrderClaim::OPERATION_CREATE_ORDER,
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
            'inputs' => new stdClass,
            'preference' => $this->preferenceOverride?->value,
            'expected_idr_total' => $this->expectedIdrTotal,
            'expected_usd_total_micro' => $this->expectedUsdTotalMicro,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private static function expectedTotals(mixed $totals): array
    {
        if ($totals === null) {
            return [null, null];
        }
        if (! $totals instanceof stdClass) {
            throw self::invalid('expected_totals must be a JSON object keyed by IDR and USD.');
        }
        $values = get_object_vars($totals);
        if (array_diff(array_keys($values), ['IDR', 'USD']) !== []) {
            throw self::invalid('expected_totals only accepts IDR and USD.');
        }

        $idr = $values['IDR'] ?? null;
        $usd = $values['USD'] ?? null;
        if ($idr !== null && (! is_string($idr) || self::positiveInteger($idr) === null)) {
            throw self::invalid('expected_totals.IDR must be a canonical positive whole-rupiah string.');
        }
        $usdMicro = null;
        if ($usd !== null) {
            if (! is_string($usd)) {
                throw self::invalid('expected_totals.USD must be an exact decimal string with at most six decimals.');
            }
            try {
                $usdMicro = UsdtMicroAmount::fromDecimalString($usd)->units();
            } catch (InvalidArgumentException) {
                throw self::invalid('expected_totals.USD must be a positive exact decimal string with at most six decimals.');
            }
        }

        return [$idr === null ? null : (int) $idr, $usdMicro];
    }

    private static function positiveInteger(string $value): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1 || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private static function invalid(string $message): CustomerApiFailure
    {
        return new CustomerApiFailure(422, 'invalid_input', $message);
    }
}
