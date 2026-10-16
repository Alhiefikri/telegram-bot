<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerApi\CustomerApiCatalog;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiPrincipal;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerApiReadController extends Controller
{
    public function balance(Request $request, StoreBalanceLedger $idr, CustomerUsdtBalanceLedger $usd): JsonResponse
    {
        $this->allow($request, []);
        $customer = Customer::query()->select(['id', 'balance_payment_preference'])->findOrFail($this->principal($request)->customerId);
        $idrSnapshot = $idr->snapshot($customer);
        $usdSnapshot = $usd->snapshot($customer);
        $usdAvailable = $usdSnapshot->isAvailable() && ! $usdSnapshot->isFrozen();

        return response()->json(['data' => [
            'payment_preference' => $customer->balance_payment_preference->value,
            'wallets' => [
                ['currency' => 'IDR', 'available' => $idrSnapshot->isAvailable(), 'frozen' => false,
                    'amount' => $idrSnapshot->isAvailable() ? (string) $idrSnapshot->balance() : null],
                ['currency' => 'USD', 'available' => $usdAvailable, 'frozen' => $usdSnapshot->isFrozen(),
                    'amount' => $usdAvailable ? UsdtMicroAmount::formatUnits($usdSnapshot->balanceMicro()) : null],
            ],
        ]]);
    }

    public function products(Request $request, CustomerApiCatalog $catalog): JsonResponse
    {
        $this->allow($request, ['limit', 'cursor', 'quantity', 'category_id', 'family_id', 'available']);
        $filters = ['limit' => $this->integer($request->query('limit', '20'), 1, 100),
            'after' => $this->integer($request->query('cursor', '0'), 0, PHP_INT_MAX),
            'quantity' => $this->integer($request->query('quantity', '1'), 1, 100)];
        foreach (['category_id' => 'category_id', 'family_id' => 'product_family_id'] as $input => $field) {
            if ($request->query->has($input)) {
                $filters[$field] = $this->integer($request->query($input), 1, PHP_INT_MAX);
            }
        }
        if ($request->query->has('available')) {
            if (! in_array($request->query('available'), ['true', 'false'], true)) {
                throw $this->invalid();
            }
            $filters['available'] = $request->query('available');
        }

        return response()->json($catalog->page($this->principal($request), $filters));
    }

    public function product(Request $request, string $id, CustomerApiCatalog $catalog): JsonResponse
    {
        $this->allow($request, ['quantity', 'currency']);
        $id = $this->integer($id, 1, PHP_INT_MAX);
        $quantity = $this->integer($request->query('quantity', '1'), 1, 100);
        $currency = $request->query('currency');
        if ($request->query->has('currency') && ! in_array($currency, ['IDR', 'USD'], true)) {
            throw $this->invalid();
        }

        return response()->json($catalog->detail($this->principal($request), $id, $quantity, $currency));
    }

    private function principal(Request $request): CustomerApiPrincipal
    {
        $principal = $request->attributes->get('customer_api_principal');
        if (! $principal instanceof CustomerApiPrincipal) {
            throw new CustomerApiFailure(401, 'unauthenticated', 'A valid API key is required.');
        }

        return $principal;
    }

    private function allow(Request $request, array $fields): void
    {
        if (array_diff(array_keys($request->query()), $fields) !== [] || $request->getContent() !== '') {
            throw $this->invalid();
        }
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (! is_string($value) || strlen($value) > strlen((string) PHP_INT_MAX)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0
            || (int) $value < $min || (int) $value > $max) {
            throw $this->invalid();
        }

        return (int) $value;
    }

    private function invalid(): CustomerApiFailure
    {
        return new CustomerApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.');
    }
}
