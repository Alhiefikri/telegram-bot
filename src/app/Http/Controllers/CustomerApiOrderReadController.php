<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\CustomerApiOrders;
use App\Services\CustomerApi\CustomerApiPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class CustomerApiOrderReadController extends Controller
{
    public function index(Request $request, CustomerApiOrders $orders): JsonResponse
    {
        $this->allow($request, ['limit', 'cursor', 'status']);
        $limit = $request->query('limit', '20');
        $cursor = $request->query('cursor');
        $status = $request->query('status');
        if (! is_string($limit) || preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/D', $limit) !== 1
            || $request->query->has('cursor') && (! is_string($cursor) || ! Str::isUuid($cursor))
            || $request->query->has('status') && (! is_string($status) || OrderStatus::tryFrom($status) === null)) {
            throw $this->invalid();
        }

        return response()->json($orders->page($this->principal($request), (int) $limit, $cursor, $status));
    }

    public function show(Request $request, string $id, CustomerApiOrders $orders): JsonResponse
    {
        $this->allow($request, []);
        $this->id($id);

        return response()->json($orders->detail($this->principal($request), $id));
    }

    public function delivery(Request $request, string $id, CustomerApiOrders $orders): JsonResponse
    {
        $this->allow($request, []);
        $this->id($id);

        $response = response()->json($orders->delivery($this->principal($request), $id));
        if (strlen($response->getContent()) > 1_048_576) {
            throw new CustomerApiFailure(503, 'temporarily_unavailable', 'Delivery is temporarily unavailable.');
        }

        return $response;
    }

    private function principal(Request $request): CustomerApiPrincipal
    {
        $principal = $request->attributes->get('customer_api_principal');
        if (! $principal instanceof CustomerApiPrincipal) {
            throw new CustomerApiFailure(401, 'unauthenticated', 'A valid API key is required.');
        }

        return $principal;
    }

    private function id(string $id): void
    {
        if (! Str::isUuid($id)) {
            throw new CustomerApiFailure(404, 'not_found', 'Resource not found.');
        }
    }

    private function allow(Request $request, array $fields): void
    {
        if (array_diff(array_keys($request->query()), $fields) !== [] || $request->getContent() !== '') {
            throw $this->invalid();
        }
    }

    private function invalid(): CustomerApiFailure
    {
        return new CustomerApiFailure(422, 'invalid_input', 'The request contains unsupported or malformed inputs.');
    }
}
