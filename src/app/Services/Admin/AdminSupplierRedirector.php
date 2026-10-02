<?php

namespace App\Services\Admin;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Contextual redirects for the private supplier surface smoke the operator
 * back to the page (and filters) they were working on instead of always
 * dropping them on the supplier dashboard tab.
 */
final class AdminSupplierRedirector
{
    public const CONTEXTS = ['suppliers', 'catalog', 'product', 'purchases'];

    public const CATALOG_STATUSES = ['semua', 'belum-dijual', 'sudah-dijual', 'instan', 'perlu-input'];

    /**
     * Only these request keys may be flashed back to the form. Secrets and
     * attacker-controlled enum values (provider, action) stay out.
     */
    private const FLASHABLE_INPUTS = [
        'name', 'description', 'price_amount', 'currency', 'currency_confirmed',
        'exchange_rate_idr', 'is_active', 'low_balance_threshold_micros',
        'priority', 'equivalent_confirmed', 'version', 'supplier_version',
        'mode', 'markup_basis_points', 'profit_idr', 'minimum_profit_idr',
        'buffer_idr', 'rounding_idr', 'catalog_item_id', 'product_id',
        'context', 'context_id', 'return_q', 'return_status', 'supplier_order_id',
    ];

    public function tab(?string $message = null): RedirectResponse
    {
        $redirect = redirect()
            ->route('admin.dashboard', ['tab' => 'suppliers'])
            ->withFragment('admin-section-suppliers');

        return $message === null ? $redirect : $redirect->with('status', $message);
    }

    /**
     * @param  array<string, string>  $query
     */
    public function catalog(Supplier $supplier, array $query = [], ?string $message = null): RedirectResponse
    {
        $redirect = redirect()->route('admin.suppliers.catalog', ['supplier' => $supplier->getKey()] + $query);

        return $message === null ? $redirect : $redirect->with('status', $message);
    }

    public function product(Product $product, ?string $message = null): RedirectResponse
    {
        $redirect = redirect()->route('admin.products.suppliers', $product);

        return $message === null ? $redirect : $redirect->with('status', $message);
    }

    public function purchases(?string $message = null): RedirectResponse
    {
        $redirect = redirect()->route('admin.suppliers.purchases');

        return $message === null ? $redirect : $redirect->with('status', $message);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public function fromRequest(Request $request, ?string $message = null, array $errors = []): RedirectResponse
    {
        $redirect = match ($this->context($request)) {
            'catalog' => $this->contextCatalog($request),
            'product' => $this->contextProduct($request),
            'purchases' => $this->purchases(),
            default => $this->tab(),
        };

        if ($errors !== []) {
            $redirect->withErrors($errors)->withInput(self::safeInput($request));
        }

        return $message === null ? $redirect : $redirect->with('status', $message);
    }

    public function contextUrl(Request $request): string
    {
        return match ($this->context($request)) {
            'catalog' => $this->contextCatalog($request)->getTargetUrl(),
            'product' => $this->contextProduct($request)->getTargetUrl(),
            'purchases' => route('admin.suppliers.purchases'),
            default => route('admin.dashboard', ['tab' => 'suppliers']),
        };
    }

    public function context(Request $request): string
    {
        $context = (string) $request->input('context', 'suppliers');

        return in_array($context, self::CONTEXTS, true) ? $context : 'suppliers';
    }

    /**
     * Catalog filters submitted with a POST so an error redirect can restore them.
     *
     * @return array<string, string>
     */
    public function catalogQueryFromRequest(Request $request): array
    {
        $query = [];
        $search = trim((string) $request->input('return_q', ''));
        if ($search !== '') {
            $query['q'] = mb_substr($search, 0, 100);
        }
        $status = (string) $request->input('return_status', '');
        if (in_array($status, self::CATALOG_STATUSES, true) && $status !== 'semua') {
            $query['status'] = $status;
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public static function safeInput(Request $request): array
    {
        return $request->only(self::FLASHABLE_INPUTS);
    }

    private function contextCatalog(Request $request): RedirectResponse
    {
        $supplier = Supplier::query()->find((int) $request->input('context_id', 0));

        return $supplier instanceof Supplier
            ? $this->catalog($supplier, $this->catalogQueryFromRequest($request))
            : $this->tab();
    }

    private function contextProduct(Request $request): RedirectResponse
    {
        $product = Product::query()->find((int) $request->input('context_id', 0));

        return $product instanceof Product ? $this->product($product) : $this->tab();
    }
}
