<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBuyMailWebhook;
use App\Models\Supplier;
use App\Models\SupplierWebhookEvent;
use App\Services\Suppliers\BuyMailWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BuyMailWebhookController extends Controller
{
    private const EVENTS = ['ping', 'deposit.paid', 'refund.approved', 'refund.rejected', 'refund.reversed', 'refund.cancelled'];

    public function __invoke(Request $request, Supplier $supplier): JsonResponse
    {
        $body = $request->getContent();
        if (strlen($body) > 2_097_152) {
            return response()->json(['received' => false], 413);
        }
        try {
            $secret = $supplier->webhook_secret;
        } catch (Throwable) {
            $secret = null;
        }
        if ($supplier->provider !== 'buymail' || ! is_string($secret)
            || ! BuyMailWebhookSignature::verify($secret, $body, (string) $request->header('X-Webhook-Signature'), time())) {
            return response()->json(['received' => false], 401);
        }
        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (Throwable) {
            return response()->json(['received' => false], 422);
        }
        if (! is_array($payload) || array_is_list($payload) || ! is_array($payload['data'] ?? null)) {
            return response()->json(['received' => false], 422);
        }
        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;
        $resource = $type === 'deposit.paid' ? ($payload['data']['deposit']['id'] ?? null) : ($payload['data']['account']['id'] ?? null);
        if (! is_array($payload) || array_is_list($payload) || ! is_string($id) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $id) !== 1
            || ! in_array($type, self::EVENTS, true) || $id !== $request->header('X-Webhook-Id') || $type !== $request->header('X-Webhook-Event')
            || ($type !== 'ping' && (! is_string($resource) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $resource) !== 1))) {
            return response()->json(['received' => false], 422);
        }
        // Reconstruct an allowlisted envelope from validated routing values.
        // Signed provider data is still private and never settlement authority.
        $safePayload = ['id' => $id, 'type' => $type, 'data' => match ($type) {
            'ping' => [],
            'deposit.paid' => ['deposit' => ['id' => $resource]],
            default => ['account' => ['id' => $resource]],
        }];
        try {
            DB::transaction(function () use ($supplier, $id, $type, $resource, $safePayload): void {
                $event = SupplierWebhookEvent::firstOrCreate(['supplier_id' => $supplier->id, 'external_event_id' => $id], [
                    'event_type' => $type, 'resource_id' => $resource, 'payload' => $safePayload,
                    'state' => 'received', 'next_attempt_at' => now(),
                ]);
                if ($event->wasRecentlyCreated) {
                    DB::afterCommit(fn () => ProcessBuyMailWebhook::dispatch($event->id));
                }
            }, 5);
        } catch (Throwable) {
            // Let the provider retry if a durable acceptance cannot be proven.
            return response()->json(['received' => false], 503);
        }

        return response()->json(['received' => true]);
    }
}
