<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransition;
use App\Http\Controllers\Controller;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\SupplierFulfillment;
use App\Models\TelegramOutboundEffect;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\Admin\SupplierOperationConfirmation;
use App\Services\Credentials\UniqueCredentialEmailPolicy;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

class AdminManualFulfillmentReviewController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ManualFulfillmentReview::class);

        $reviews = ManualFulfillmentReview::query()
            ->with(['order:id,invoice_number,order_status,payment_status', 'payment:id,method,provider,provider_reference,paid_at'])
            ->orderByRaw('case when state = ? then 0 else 1 end', [ManualFulfillmentReview::STATE_PENDING])
            ->latest()
            ->paginate(20);

        return view('admin.manual-fulfillment-reviews.index', compact('reviews'));
    }

    public function show(Request $request, ManualFulfillmentReview $review, UniqueCredentialEmailPolicy $emailPolicy, SupplierFulfillmentService $suppliers): View
    {
        Gate::authorize('view', $review);

        $review->load(['order.customer', 'order.orderItems.product', 'payment', 'actor']);
        $activePaid = $review->state === ManualFulfillmentReview::STATE_PENDING
            && $review->order->order_status === OrderStatus::Processing
            && $review->order->payment_status === PaymentStatus::Paid
            && $review->payment->status === PaymentStatus::Paid;
        $handoffFulfillments = in_array($review->reason, ManualFulfillmentReview::REASONS_COVERED_BY_PAID_NOTICE, true)
            ? SupplierFulfillment::query()->where('order_id', $review->order_id)
                ->with(['manualHandoffs.actor', 'purchases' => fn ($query) => $query->whereNull('warranty_claim_id')
                    ->select(['id', 'supplier_fulfillment_id', 'state', 'committed_at', 'supplier_order_id', 'actual_cost_micros', 'claim_token', 'lease_expires_at'])])
                ->orderBy('id')->get()
            : collect();
        $retryItems = $activePaid && $review->reason === ManualFulfillmentReview::REASON_MANUAL_UNLIMITED
            && $request->attributes->get('adminUser')?->role === UserRole::Owner
            ? $suppliers->unassignedUnlimitedItems($review->order)->filter(fn ($item) => $item->product?->supplierSources()
                ->where('is_active', true)->whereHas('catalogItem.supplier', fn ($supplier) => $supplier->where('is_active', true))->exists())
            : collect();
        $localCredentialItems = $review->acceptsLocalCredentials()
            ? $review->order->orderItems->map(function ($item): array {
                $allocated = $item->stockItems()->whereNull('supplier_purchase_id')->whereNull('retired_at')->count();

                return [
                    'item' => $item,
                    'missing' => max(0, $item->localQuantity() - $allocated),
                ];
            })->filter(fn (array $row): bool => $row['item']->localQuantity() > 0)
            : collect();
        $credentialEmailHistory = $review->order->orderItems
            ->filter(fn ($item): bool => (bool) ($item->unique_credential_email || $item->product?->unique_credential_email))
            ->map(function ($item) use ($emailPolicy): array {
                try {
                    return ['item' => $item, 'history' => $emailPolicy->history($item), 'error' => null];
                } catch (ValidationException $exception) {
                    return ['item' => $item, 'history' => [], 'error' => collect($exception->errors())->flatten()->first()];
                }
            });

        $receiptEffect = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', (string) $review->order_id)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
            ->orderByDesc('sequence')->first();

        return view('admin.manual-fulfillment-reviews.show', [
            'review' => $review,
            'amount' => $this->amount($review->amount, $review->currency),
            'handoffFulfillments' => $handoffFulfillments,
            'supplierRetryIds' => $activePaid && $request->attributes->get('adminUser')?->role === UserRole::Owner
                ? $handoffFulfillments->filter($suppliers->canOfferInvoiceRetry(...))->pluck('id')->all()
                : [],
            'retryItems' => $retryItems,
            'activePaid' => $activePaid,
            'handoffKey' => (string) Str::uuid(),
            'localCredentialItems' => $localCredentialItems,
            'credentialEmailHistory' => $credentialEmailHistory,
            'receiptEffect' => $receiptEffect,
        ]);
    }

    public function retryUnlimitedSupplier(Request $request, ManualFulfillmentReview $review, SupplierFulfillmentService $suppliers): RedirectResponse
    {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor?->role === UserRole::Owner, 403);
        $url = route('admin.manual-fulfillment-reviews.show', $review);
        $confirmed = (int) $request->session()->get('auth.password_confirmed_at', 0);
        if ($confirmed <= 0 || $confirmed > time() || time() - $confirmed > 900) {
            $request->session()->put('url.intended', $url);

            return redirect()->route('password.confirm');
        }
        $data = $request->validate(['item_id' => ['required', 'integer', 'min:1']]);
        $result = $suppliers->retryUnlimitedReview($review, (int) $data['item_id'], $actor);

        return $result === 'queued'
            ? redirect($url)->with('status', 'Pembelian supplier dijadwalkan. Akun akan dikirim otomatis setelah semua unit siap.')
            : redirect($url)->withErrors(['supplier' => $result === 'unavailable'
                ? 'Supplier belum bisa memenuhi pesanan dalam batas harga, stok dan saldo yang tersedia. Anda masih bisa mengisi akun manual.'
                : 'Status invoice berubah atau pembelian belum aman. Muat ulang sebelum melanjutkan.']);
    }

    public function resolve(Request $request, ManualFulfillmentReview $review, ManualFulfillmentResolutionService $resolutions): RedirectResponse
    {
        Gate::authorize('resolve', $review);
        $data = $request->validate([
            'action' => ['required', 'in:fulfill,hand_off,refund,reject'],
            'reason' => ['required', 'string', 'max:2000'],
            'fulfillment_id' => ['nullable', 'required_if:action,hand_off', 'integer', 'exists:supplier_fulfillments,id'],
            'handoff_quantity' => ['nullable', 'required_if:action,hand_off', 'integer', 'min:1'],
            'handoff_key' => ['nullable', 'required_if:action,hand_off', 'uuid'],
            'refund_provider_reference' => ['nullable', 'string', 'max:255'],
            'refund_provider_result' => ['nullable', 'in:success,failure,uncertain'],
        ]);
        if (in_array($data['action'], ['fulfill', 'hand_off'], true) && $review->reason === 'supplier_fulfillment') {
            app(SupplierOperationConfirmation::class)->requireRecent($request);
        }

        try {
            $resolved = $data['action'] === 'hand_off'
                ? $resolutions->recordExternalHandoff(
                    $review,
                    $request->attributes->get('adminUser'),
                    (int) $data['fulfillment_id'],
                    (int) $data['handoff_quantity'],
                    $data['reason'],
                    $request->ip(),
                    $data['handoff_key'],
                )
                : $resolutions->resolve(
                    $review,
                    $request->attributes->get('adminUser'),
                    $data['action'],
                    $data['reason'],
                    $data['refund_provider_reference'] ?? null,
                    $data['refund_provider_result'] ?? null,
                    $request->ip(),
                );
        } catch (\Throwable $exception) {
            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        return redirect()->route('admin.manual-fulfillment-reviews.show', $resolved)->with('status', 'Review resolved as '.$resolved->state.'.');
    }

    public function provideCredentials(Request $request, ManualFulfillmentReview $review, ManualFulfillmentResolutionService $resolutions): RedirectResponse
    {
        Gate::authorize('provideCredentials', $review);

        try {
            $data = Validator::make($request->all(), [
                'items' => ['required', 'array'],
                'items.*' => ['required', 'array'],
                'items.*.format' => ['required', 'in:legacy,structured_account'],
                'items.*.credentials' => ['required', 'string', 'max:1048576'],
            ])->validate();
            $credentials = [];
            foreach ($data['items'] as $itemId => $input) {
                if ($input['format'] === 'structured_account') {
                    $accounts = json_decode($input['credentials'], true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($accounts) || ! array_is_list($accounts)
                        || count(array_filter($accounts, 'is_array')) !== count($accounts)) {
                        throw ValidationException::withMessages(['credentials' => 'Input Akun harus berupa daftar JSON akun.']);
                    }
                    $credentials[$itemId] = $accounts;
                } else {
                    $credentials[$itemId] = array_values(array_filter(
                        array_map('trim', preg_split('/\r\n|\r|\n/', $input['credentials'])),
                        fn (string $line): bool => $line !== '',
                    ));
                }
            }
            $resolutions->provideLocalCredentials(
                $review,
                $request->attributes->get('adminUser'),
                $credentials,
                null,
                $request->ip(),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        } catch (JsonException) {
            return back()->withErrors(['credentials' => 'JSON akun tidak valid. Periksa format lalu kirim ulang.']);
        } catch (InvalidOrderTransition $exception) {
            return back()->withErrors(['credentials' => $exception->getMessage()]);
        }

        return redirect()->route('admin.manual-fulfillment-reviews.show', $review)->with('status', 'Akun lokal disimpan. Pengiriman dilanjutkan setelah seluruh unit pesanan siap.');
    }

    private function amount(int $amount, string $currency): string
    {
        return strtoupper($currency) === 'USDT' ? Money::usdt($amount) : Money::idr($amount);
    }
}
