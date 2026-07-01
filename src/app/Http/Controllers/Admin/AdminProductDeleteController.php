<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminProductDeleteController extends Controller
{
    public function __invoke(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $productName = $product->name;
        $product->delete();

        return redirect()
            ->route('admin.dashboard', ['tab' => 'produk'])
            ->with('status', "Produk {$productName} diarsipkan dari dashboard.");
    }
}
