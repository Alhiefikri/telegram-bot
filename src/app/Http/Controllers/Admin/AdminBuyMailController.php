<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InvalidOrderTransition;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSupplierDeposit;
use App\Models\Supplier;
use App\Models\SupplierDeposit;
use App\Services\Admin\SupplierOperationConfirmation;
use App\Services\Suppliers\BuyMailClient;
use App\Services\Suppliers\SupplierDepositService;
use App\Services\Suppliers\SupplierUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AdminBuyMailController extends Controller
{
    public function index(Request $request, Supplier $supplier)
    {
        $this->authorizeOperator($request, $supplier);

        return $this->page($supplier);
    }

    public function createDeposit(Request $request, Supplier $supplier, SupplierDepositService $deposits)
    {
        $this->authorizeOperator($request, $supplier);
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        $input = $request->only(['amount', 'idempotency_key']);
        $validator = Validator::make($input, ['amount' => ['required', 'integer', 'min:5000', 'max:5000000'], 'idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/D']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Instruksi deposit tidak valid.'], 422);
        }
        try {
            $deposits->create($supplier, (int) $input['amount'], $input['idempotency_key'], $request->attributes->get('adminUser'));
        } catch (InvalidOrderTransition) {
            return response()->json(['message' => 'Deposit belum selesai atau identitas nominal berubah. Pulihkan deposit yang ada dahulu.'], 409);
        }

        return redirect()->route('admin.suppliers.buymail', $supplier)->with('status', 'Instruksi top-up supplier dijadwalkan. Saldo buyer tidak berubah.');
    }

    public function cancelDeposit(Request $request, SupplierDeposit $deposit, SupplierDepositService $deposits)
    {
        $supplier = Supplier::findOrFail($deposit->supplier_id);
        $this->authorizeOperator($request, $supplier);
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        try {
            $deposits->cancel($deposit, $request->attributes->get('adminUser'));
        } catch (InvalidOrderTransition) {
            return response()->json(['message' => 'Deposit tidak dapat dibatalkan sebelum status pembayaran dipastikan.'], 409);
        }

        return redirect()->route('admin.suppliers.buymail', $supplier)->with('status', 'Pembatalan diminta. GET supplier menentukan hasil akhir bila pembayaran datang bersamaan.');
    }

    public function refreshDeposit(Request $request, SupplierDeposit $deposit, SupplierDepositService $deposits)
    {
        $supplier = Supplier::findOrFail($deposit->supplier_id);
        $this->authorizeOperator($request, $supplier);
        if ($deposit->external_id === null) {
            // Queue recovery preserves the original intent/key; this action
            // never creates a replacement deposit with a new key.
            ProcessSupplierDeposit::dispatch((string) $deposit->id);
        } else {
            try {
                $deposits->reconcileKnownDeposit($supplier, $deposit->external_id);
            } catch (SupplierUnavailable) {
                return response()->json(['message' => 'Status deposit belum dapat dibaca. Tidak ada kredit yang diasumsikan.'], 503);
            }
        }

        return redirect()->route('admin.suppliers.buymail', $supplier);
    }

    public function history(Request $request, Supplier $supplier, BuyMailClient $client, SupplierDepositService $deposits)
    {
        $this->authorizeOperator($request, $supplier);
        try {
            $orders = $client->orderHistory($supplier);
            $remoteDeposits = $client->deposits($supplier);
        } catch (SupplierUnavailable) {
            return response()->json(['message' => 'Histori supplier belum dapat dibaca. Tidak ada data atau uang yang diganti.'], 503);
        }

        return $this->page($supplier, $orders, $remoteDeposits);
    }

    private function page(Supplier $supplier, array $orders = [], array $remoteDeposits = [])
    {
        $deposits = SupplierDeposit::query()->where('supplier_id', $supplier->id)->orderByDesc('created_at')->paginate(25);

        return view('admin.suppliers.buymail', [
            'supplier' => $supplier->only(['id', 'name', 'is_active', 'external_account_id']),
            'deposits' => $deposits, 'orders' => $orders, 'remoteDeposits' => $remoteDeposits,
        ]);
    }

    private function authorizeOperator(Request $request, Supplier $supplier): void
    {
        abort_unless($request->attributes->get('adminUser')?->role?->canManageStoreOperations() === true, 403);
        abort_unless($supplier->provider === 'buymail', 404);
    }
}
