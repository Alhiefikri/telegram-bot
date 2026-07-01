<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AdminProductVisibilityController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, Product $product, AuditLogger $auditLogger): RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);

        Gate::authorize('update', $product);

        $validator = Validator::make($request->all(), [
            'is_active' => ['required', 'boolean'],
        ]);

        $validated = $validator->validate();
        $oldValues = $product->only(['is_active']);

        $product->forceFill(['is_active' => (bool) $validated['is_active']])->save();

        $auditLogger->log(
            'product.visibility_updated',
            $product,
            $oldValues,
            $product->only(['is_active']),
            $user,
            $request->ip(),
        );

        return redirect()
            ->route('admin.dashboard', ['tab' => 'produk'])
            ->with('status', $product->is_active ? "Produk {$product->name} ditampilkan." : "Produk {$product->name} dipause.");
    }
}
