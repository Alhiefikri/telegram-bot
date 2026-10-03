<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InvalidOrderTransition;
use App\Http\Controllers\Controller;
use App\Models\OrderRefund;
use App\Models\SupplierWebhookEvent;
use App\Models\WarrantyClaim;
use App\Services\Admin\SupplierOperationConfirmation;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyReplacementService;
use App\Services\Warranty\WarrantySettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class AdminWarrantyController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOperator($request);
        $claims = WarrantyClaim::query()->with(['order', 'unit.orderItem', 'replacementPurchase'])->orderByDesc('id')->paginate(25);
        $refunds = OrderRefund::query()->whereIn('warranty_claim_id', $claims->pluck('id'))->get()->keyBy('warranty_claim_id');
        $missingRefunds = OrderRefund::whereNull('warranty_claim_id')->orderByDesc('created_at')->paginate(25, ['*'], 'missing_page');
        $webhookEvents = SupplierWebhookEvent::query()
            ->select(['id', 'supplier_id', 'event_type', 'state', 'last_error', 'created_at', 'next_attempt_at'])
            ->where('state', '!=', 'processed')->orderBy('next_attempt_at')->orderBy('id')->paginate(25, ['*'], 'webhook_page');

        return view('admin.suppliers.warranty', compact('claims', 'refunds', 'missingRefunds', 'webhookEvents'));
    }

    public function proof(Request $request, WarrantyClaim $claim)
    {
        $this->authorizeOperator($request);
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        $bytes = Crypt::decryptString(Storage::disk('local')->get($claim->proof_path));
        abort_unless(hash_equals($claim->proof_digest, hash('sha256', $bytes)), 409);
        $proof = RefundProof::fromBytes($bytes);

        return response($proof->bytes)->header('Content-Type', $proof->mime)->header('Content-Disposition', 'attachment; filename="'.$proof->filename.'"')
            ->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff')->header('Referrer-Policy', 'no-referrer');
    }

    public function refresh(Request $request, WarrantyClaim $claim, WarrantyClaimService $claims)
    {
        $this->authorizeOperator($request);
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        $claims->refreshNow($claim->id);

        return redirect()->route('admin.suppliers.warranty')->with('status', 'Status klaim dibaca ulang dari akun supplier yang sama.');
    }

    public function refund(Request $request, OrderRefund $refund, WarrantySettlementService $settlements)
    {
        $this->authorizeOperator($request);
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        $input = $request->only(['amount', 'currency', 'reference', 'result']);
        $validator = Validator::make($input, [
            'amount' => ['required', 'integer', 'min:1'], 'currency' => ['required', 'in:IDR,USDT'],
            'reference' => ['required', 'string', 'max:200', 'not_regex:/[\x00-\x1f\x7f]/'],
            'result' => ['required', 'in:success,failure,uncertain'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Bukti refund tidak valid.'], 422);
        }
        try {
            $settlements->confirmExternalRefund($request->attributes->get('adminUser'), $refund->id, (int) $input['amount'], $input['currency'], $input['reference'], $input['result']);
        } catch (InvalidOrderTransition|InvalidArgumentException) {
            return response()->json(['message' => 'Bukti transfer tidak cocok atau sudah digunakan. Periksa status sebelum mengulang.'], 409);
        }

        return redirect()->route('admin.suppliers.warranty')->with('status', 'Hasil transfer refund dicatat. Form ini tidak mengirim uang.');
    }

    public function recoverReplacement(Request $request, WarrantyClaim $claim, WarrantyReplacementService $replacements)
    {
        $this->authorizeOperator($request);
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        $validator = Validator::make($request->only('supplier_order_id'), ['supplier_order_id' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/D']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Identitas order pengganti tidak valid.'], 422);
        }
        try {
            $replacements->recoverOrder($request->attributes->get('adminUser'), $claim->id, $request->input('supplier_order_id'));
        } catch (InvalidOrderTransition|InvalidArgumentException) {
            return response()->json(['message' => 'Order pengganti tidak cocok atau masih diproses. Tidak ada pembelian baru.'], 409);
        }

        return redirect()->route('admin.suppliers.warranty')->with('status', 'Order pengganti dibaca ulang dengan reference original. Periksa hasil pemulihan.');
    }

    private function authorizeOperator(Request $request): void
    {
        abort_unless($request->attributes->get('adminUser')?->role?->canManageStoreOperations() === true, 403);
    }

    public function approveAcquired(Request $request, WarrantyClaim $claim, WarrantyReplacementService $replacements)
    {
        $this->authorizeOperator($request);
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        if (Validator::make($request->only('actual_cost_idr'), ['actual_cost_idr' => ['required', 'integer', 'min:1', 'max:1000000000']])->fails()) {
            return response()->json(['message' => 'Konfirmasi biaya aktual diperlukan.'], 422);
        }
        try {
            $replacements->approveAcquired($request->attributes->get('adminUser'), $claim->id, (int) $request->input('actual_cost_idr'));
        } catch (InvalidOrderTransition|InvalidArgumentException) {
            return response()->json(['message' => 'Bukti pembelian tidak lengkap, identity konflik, atau biaya tidak cocok. Tidak ada pembelian baru.'], 409);
        }

        return redirect()->route('admin.suppliers.warranty')->with('status', 'Akun yang sudah diperoleh disetujui untuk delivery. Tidak ada debit atau pembelian tambahan.');
    }
}
