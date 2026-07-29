<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateProductVisibilityRequest;
use App\Models\Product;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;

class AdminProductVisibilityController extends Controller
{
    public function __invoke(UpdateProductVisibilityRequest $request, Product $product, AuditLogger $auditLogger, AdminDashboardRedirector $redirector): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');

        $validated = $request->validated();
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

        return $redirector->product($product)
            ->with('status', $product->is_active ? "Produk {$product->name} ditampilkan." : "Produk {$product->name} dipause.");
    }
}
