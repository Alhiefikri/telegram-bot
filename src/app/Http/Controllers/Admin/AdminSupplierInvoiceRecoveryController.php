<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Http\Controllers\Controller;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierFulfillment;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\AuditLogger;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AdminSupplierInvoiceRecoveryController extends Controller
{
    public function __invoke(Request $request, SupplierFulfillment $fulfillment, SupplierFulfillmentService $service)
    {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor?->role?->canManageStoreOperations() === true, 403);
        $url = route('admin.suppliers.purchases', ['invoice' => $fulfillment->order->invoice_number]);
        $confirmed = (int) $request->session()->get('auth.password_confirmed_at', 0);
        if ($confirmed <= 0 || $confirmed > time() || time() - $confirmed > 900) {
            $request->session()->put('url.intended', $url);

            return redirect()->route('password.confirm');
        }
        $validator = Validator::make($request->only(['action', 'purchase_id', 'credentials', 'confirmed']), [
            'action' => ['required', 'in:send_accounts,handed_off,not_debited,sync'],
            'purchase_id' => ['required', 'integer', 'min:0'],
            'credentials' => ['required_if:action,send_accounts', 'nullable', 'string', 'max:64000'],
            'confirmed' => ['required_unless:action,sync', 'accepted'],
        ]);
        if ($validator->fails()) {
            return redirect($url)->withErrors(['recovery' => 'Periksa akun dan centang konfirmasi sebelum melanjutkan. Isian akun tidak disimpan; masukkan kembali bila diperlukan.']);
        }
        if ($request->input('action') === 'not_debited') {
            abort_unless($actor->role === UserRole::Owner, 403);
        }
        try {
            $supplierId = DB::transaction(function () use ($request, $fulfillment, $service, $actor): ?int {
                $order = Order::query()->lockForUpdate()->findOrFail($fulfillment->order_id);
                Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                $service->lockForReview($order);
                $f = SupplierFulfillment::query()->whereKey($fulfillment->id)->lockForUpdate()->firstOrFail();
                if ((int) $f->current_purchase_id !== $request->integer('purchase_id')) {
                    throw new InvalidOrderTransition('Purchase changed.');
                }
                $supplierId = $f->reserved_supplier_id ?? $f->currentPurchase?->supplier_id;
                switch ($request->input('action')) {
                    case 'send_accounts':
                        if ($order->customer?->telegram_id === null) {
                            throw new InvalidOrderTransition('This customer has no Telegram destination.');
                        }
                        $credentials = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $request->string('credentials')->toString())), fn (string $line): bool => $line !== ''));
                        $service->provideManualCredentials($f->id, $credentials);
                        app(AuditLogger::class)->log('supplier_fulfillment.manual_accounts_submitted', $f, null, ['quantity' => count($credentials)], $actor, $request->ip());
                        break;
                    case 'handed_off':
                        $review = ManualFulfillmentReview::query()->where('order_id', $order->id)->where('reason', 'supplier_fulfillment')->where('state', 'pending')->first();
                        if ($review === null) {
                            throw new InvalidOrderTransition('Manual review is no longer open.');
                        }
                        $quantity = max(0, $f->quantity - (int) $f->refunded_quantity - (int) $f->manualHandoffs()->sum('quantity') - $order->orderItems()->findOrFail($f->order_item_id)->stockItems()->whereHas('supplierPurchase', fn ($q) => $q->where('supplier_fulfillment_id', $f->id)->whereNull('warranty_claim_id'))->count());
                        app(ManualFulfillmentResolutionService::class)->recordExternalHandoff($review, $actor, $f->id, $quantity, 'Admin confirms all remaining accounts were handed to the customer outside the bot.', $request->ip());
                        break;
                    case 'not_debited':
                        $service->confirmOwnerNoDebit($f->id, $actor, $request->integer('purchase_id'));
                        break;
                }

                return $supplierId;
            }, 5);
        } catch (InvalidOrderTransition|InsufficientStock|\DomainException|\InvalidArgumentException|ValidationException) {
            return redirect($url)->withErrors(['recovery' => 'Status invoice berubah atau tindakan belum aman. Muat ulang. Pastikan jumlah akun sesuai; bukti debit atau purchase yang masih berjalan harus diselesaikan melalui pemeriksaan lanjutan.']);
        }
        $action = $request->input('action');
        $message = match ($action) {
            'send_accounts' => 'Akun disimpan dan pengiriman bot dijadwalkan setelah semua item terpenuhi. Hold supplier tetap terpisah. Periksa status pengiriman di bawah.',
            'handed_off' => 'Penyerahan di luar bot dicatat. Tidak ada akun yang dikirim ulang. Hold supplier tidak dilepas.',
            'not_debited' => 'Konfirmasi Owner dicatat dan hold invoice dilepas. Tidak ada pembelian ulang atau refund.',
            default => '',
        };
        $syncWarning = null;
        if (in_array($action, ['not_debited', 'sync'], true)) {
            $supplier = $supplierId === null ? null : Supplier::query()->find($supplierId);
            $applied = $supplier !== null && app(SupplierCatalogSync::class)->sync($supplier);
            $balanceUpdated = $applied && $supplier->refresh()->balance_observed_at?->equalTo($supplier->synced_at);
            if (! $applied) {
                $syncWarning = 'Sinkronisasi belum berhasil; saldo terbaru belum terverifikasi. Coba sinkronkan lagi.';
            } elseif (! $balanceUpdated) {
                $syncWarning = 'Katalog disinkronkan. Saldo masih menunggu penyelesaian purchase supplier lain.';
            } else {
                $message .= ' Saldo dan katalog terbaru berhasil disinkronkan.';
            }
        }

        return redirect($url)->with('status', trim($message))->with('supplier_sync_warning', $syncWarning);
    }
}
