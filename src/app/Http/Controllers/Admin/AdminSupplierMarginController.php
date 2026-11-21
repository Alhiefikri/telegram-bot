<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Admin\AdminSupplierMargins;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AdminSupplierMarginController extends Controller
{
    public function index(Request $request, AdminSupplierMargins $margins): View
    {
        $this->actor($request);
        $filters = $this->filters($request);
        [$page, $rows] = $margins->page($filters);

        return view('admin.supplier-margins.index', [
            'page' => $page,
            'rows' => $rows,
            'filters' => $filters,
            'suppliers' => Supplier::query()->whereHas('catalogItems.sources')->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'query' => $this->pageQuery($request, $filters),
        ]);
    }

    public function review(Request $request, AdminSupplierMargins $margins): View|Response
    {
        $actor = $this->actor($request);
        $filters = $this->filters($request);
        // Do not expand wildcard validation over an unbounded request array.
        $input = $request->input('edits');
        if (is_array($input) && count($input) > AdminSupplierMargins::PAGE_SIZE) {
            throw ValidationException::withMessages(['edits' => 'Terlalu banyak produk dalam satu pengajuan.']);
        }
        $validated = $request->validate([
            'edits' => ['required', 'list', 'min:1', 'max:'.AdminSupplierMargins::PAGE_SIZE],
            'edits.*' => ['required', 'array:id,price,version'],
            'edits.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'edits.*.price' => ['nullable', 'integer', 'min:0', 'max:'.intdiv(PHP_INT_MAX, 100)],
            'edits.*.version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $edits = array_values(array_filter($validated['edits'], fn (array $edit): bool => isset($edit['price'])));
        if ($edits === []) {
            throw ValidationException::withMessages(['edits' => 'Isi harga baru pada minimal satu produk.']);
        }
        try {
            $changes = $margins->review($edits, $filters['target'], $filters['rounding']);
        } catch (HttpException $exception) {
            return $this->conflict($exception, $this->pageQuery($request, $filters));
        }
        $edits = array_values(array_filter($edits, fn (array $edit): bool => isset($changes[$edit['id']])));
        $token = Crypt::encryptString(json_encode([
            'purpose' => 'supplier-margin-price-review',
            'actor' => $actor->id,
            'expires' => now()->addMinutes(10)->timestamp,
            'edits' => $edits,
            'target' => $filters['target'],
            'rounding' => $filters['rounding'],
        ], JSON_THROW_ON_ERROR));

        return view('admin.supplier-margins.review', [
            'changes' => $changes,
            'token' => $token,
            'query' => $this->pageQuery($request, $filters),
        ]);
    }

    public function save(Request $request, AdminSupplierMargins $margins): RedirectResponse|Response
    {
        $actor = $this->actor($request);
        $filters = $this->filters($request);
        $validated = $request->validate(['token' => ['required', 'string', 'max:12000']]);
        $query = $this->pageQuery($request, $filters);
        try {
            try {
                $review = json_decode(Crypt::decryptString($validated['token']), true, flags: JSON_THROW_ON_ERROR);
            } catch (DecryptException|JsonException) {
                abort(409, 'Konfirmasi tidak valid. Tinjau ulang perubahan harga.');
            }
            abort_unless(is_array($review) && ($review['purpose'] ?? null) === 'supplier-margin-price-review'
                && ($review['actor'] ?? null) === $actor->id && ($review['expires'] ?? 0) > now()->timestamp,
                409, 'Konfirmasi kedaluwarsa atau tidak valid. Tinjau ulang perubahan harga.');
            $count = $margins->save($review['edits'], $review['target'], $review['rounding'], $actor, $request->ip());
        } catch (HttpException $exception) {
            return $this->conflict($exception, $query);
        }

        return redirect()->route('admin.suppliers.margins.index', $query)
            ->with('status', $count.' harga jual IDR berhasil disimpan.');
    }

    private function conflict(HttpException $exception, array $query): Response
    {
        if ($exception->getStatusCode() !== 409) {
            throw $exception;
        }

        return response()->view('admin.supplier-margins.conflict', ['message' => $exception->getMessage(), 'query' => $query], 409);
    }

    private function actor(Request $request): User
    {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User && $actor->role?->canManageStoreOperations() === true, 403);

        return $actor;
    }

    private function filters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'supplier' => ['nullable', 'integer', 'min:1'],
            'target' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'rounding' => ['nullable', 'integer', 'in:1,100,500,1000'],
            'action' => ['nullable', 'boolean'],
            'cursor' => ['nullable', 'string', 'max:256'],
        ]);
        abort_if($validator->fails(), 422, 'Filter margin tidak valid.');
        $filters = $validator->validated();
        if (isset($filters['cursor'])) {
            $cursor = Cursor::fromEncoded($filters['cursor'])?->toArray();
            abort_unless(is_int($cursor['id'] ?? null) && $cursor['id'] > 0
                && is_bool($cursor['_pointsToNextItems'] ?? null), 422, 'Halaman margin tidak valid.');
        }

        return [
            'target' => (int) ($filters['target'] ?? 2000),
            'rounding' => (int) ($filters['rounding'] ?? 500),
            ...isset($filters['supplier']) ? ['supplier' => (int) $filters['supplier']] : [],
            ...! empty($filters['action']) ? ['action' => 1] : [],
        ];
    }

    private function pageQuery(Request $request, array $filters): array
    {
        return $filters + ($request->query('cursor') ? ['cursor' => $request->query('cursor')] : []);
    }
}
