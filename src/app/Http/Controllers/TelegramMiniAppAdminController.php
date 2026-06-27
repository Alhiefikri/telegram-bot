<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\TelegramMiniAppAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramMiniAppAdminController extends Controller
{
    public function bootstrap(Request $request, TelegramMiniAppAuthenticator $auth): JsonResponse
    {
        $user = $auth->authenticateAdmin($request->string('initData')->toString());

        return response()->json($this->dashboardPayload($user));
    }

    public function storeCategory(Request $request, TelegramMiniAppAuthenticator $auth, AuditLogger $auditLogger): JsonResponse
    {
        $user = $auth->authenticateAdmin($request->string('initData')->toString());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $category = Category::create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug(Category::class, $validated['name']),
            'description' => $validated['description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => false,
        ]);

        $auditLogger->log('category.created_from_telegram_mini_app', $category, null, $category->only(['name', 'slug', 'description', 'sort_order', 'is_active']) + ['telegram_user_id' => $user['id']], null, $request->ip());

        return response()->json(['category' => $category, 'dashboard' => $this->dashboardPayload()['dashboard']], 201);
    }

    public function storeProduct(Request $request, TelegramMiniAppAuthenticator $auth, AuditLogger $auditLogger): JsonResponse
    {
        $user = $auth->authenticateAdmin($request->string('initData')->toString());
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price_amount' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'in:IDR'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $product = Product::create($validated + ['is_active' => false]);
        $product->forceFill(['stock' => 0, 'reserved_stock' => 0])->save();

        $auditLogger->log('product.created_from_telegram_mini_app', $product, null, $product->only(['category_id', 'name', 'description', 'price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']) + ['telegram_user_id' => $user['id']], null, $request->ip());

        return response()->json(['product' => $this->productPayload($product->refresh()), 'dashboard' => $this->dashboardPayload()['dashboard']], 201);
    }

    public function adjustStock(Request $request, Product $product, TelegramMiniAppAuthenticator $auth, StockAdjustmentService $stockAdjustments): JsonResponse
    {
        $user = $auth->authenticateAdmin($request->string('initData')->toString());
        $validated = $request->validate([
            'delta' => ['required', 'integer', 'between:-1000,1000', 'not_in:0'],
            'notes' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $key = 'telegram-mini-app:stock:'.sha1($user['id'].'|'.$product->id.'|'.$validated['idempotency_key']);

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $product, $validated, $stockAdjustments, $user) {
            if ($cached = Cache::get($key)) {
                return response()->json($cached + ['idempotent' => true]);
            }

            try {
                $notes = '[Telegram admin '.$user['id'].'] '.$validated['notes'];
                $adjusted = $stockAdjustments->adjust($product, (int) $validated['delta'], $notes, null, request()->ip());
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            $payload = ['product' => $this->productPayload($adjusted), 'dashboard' => $this->dashboardPayload()['dashboard']];
            Cache::put($key, $payload, now()->addMinutes(30));

            return response()->json($payload);
        });
    }

    private function dashboardPayload(?array $user = null): array
    {
        $products = Product::query()->with('category:id,name')->orderBy('name')->limit(100)->get()->map(fn (Product $product) => $this->productPayload($product))->values();

        return [
            'user' => $user,
            'dashboard' => [
                'stats' => [
                    'balance' => (int) Payment::query()->where('status', PaymentStatus::Paid)->sum('amount'),
                    'escrow' => (int) Order::query()->where('payment_status', PaymentStatus::Pending)->sum('total_amount'),
                    'gmv' => (int) Order::query()->sum('total_amount'),
                    'product_count' => Product::query()->count(),
                    'order_count' => Order::query()->count(),
                    'pending_withdraw_count' => 0,
                ],
                'categories' => Category::query()->select(['id', 'name'])->orderBy('name')->get(),
                'products' => $products,
            ],
        ];
    }

    private function productPayload(Product $product): array
    {
        return ['id' => $product->id, 'name' => $product->name, 'category' => $product->category?->name, 'price' => $product->price_amount, 'currency' => $product->currency, 'stock' => $product->stock, 'reserved' => $product->reserved_stock, 'available' => max(0, $product->stock - $product->reserved_stock), 'is_active' => (bool) $product->is_active];
    }

    private function uniqueSlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;
        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
