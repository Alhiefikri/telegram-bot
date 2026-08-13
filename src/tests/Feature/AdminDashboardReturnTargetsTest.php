<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDashboardReturnTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_product_mutations_redirect_to_the_relevant_product_card_or_product_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $category = Category::factory()->create();
        $product = Product::factory()->create(['name' => 'Return Target Product', 'stock' => 1, 'reserved_stock' => 0]);

        $createResponse = $this->from('/admin?tab=produk')->post(route('admin.products.store'), [
            'name' => 'New Return Target Product',
            'price_amount' => '75000',
            'category_id' => (string) $category->id,
            'description' => 'Produk untuk target redirect.',
        ]);
        $newProduct = Product::query()->where('name', 'New Return Target Product')->firstOrFail();
        $createResponse->assertRedirect('/admin?tab=produk#product-'.$newProduct->id);

        $this->from('/admin?tab=produk')->patch(route('admin.products.update', $product), [
            'name' => 'Updated Return Target Product',
            'price_amount' => '88000',
            'description' => 'Produk update target redirect.',
            'is_active' => '1',
        ])->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->post(route('admin.products.stock.store', $product), [
            'stock_items' => "target-1@example.test|pass\ntarget-2@example.test|pass",
        ])->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->patch(route('admin.products.visibility.update', $product), [
            'is_active' => '0',
        ])->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->delete(route('admin.products.destroy', $product))
            ->assertRedirect('/admin?tab=produk#admin-section-products');
    }

    public function test_section_mutations_redirect_to_their_stable_dashboard_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        ManualPaymentSetting::query()->create(['payment_mode' => 'manual', 'is_active' => true]);
        StoreChannelSetting::query()->create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY, 'channel_username' => '@lhie_store']);
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        Http::fake([
            'https://api.telegram.org/botbot-token/getChat' => Http::response(['ok' => true, 'result' => ['id' => -100123, 'title' => 'LHIE']]),
            'https://api.telegram.org/botbot-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]]),
        ]);

        $this->post(route('admin.categories.store'), [
            'category_name' => 'Return Target Category',
            'category_description' => 'Kategori target redirect.',
        ])->assertRedirect('/admin?tab=produk&category_form=create#admin-category-management');

        $this->post(route('admin.payment-mode.update'), [
            'update_scope' => 'provider_switch',
            'payment_mode' => 'manual',
            'qris_payment_ttl_minutes' => '15',
            'confirm_provider_switch' => '1',
        ])->assertRedirect('/admin?tab=stats#admin-payment-settings');

        $this->post(route('admin.support-settings.update'), [
            'whatsapp_support_number' => '+6281234567890',
        ])->assertRedirect('/admin?tab=stats#admin-support-settings');

        $this->post(route('admin.broadcast.store'), [
            'message' => 'Return target broadcast message.',
        ])->assertRedirect('/admin?tab=broadcast#admin-section-broadcast');

        $this->post(route('admin.store-channel.update'), [
            'channel_username' => '@lhie_store',
        ])->assertRedirect('/admin?tab=telegram-channel#admin-section-store-channel');

        $this->post(route('admin.store-channel.test'))
            ->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-test');

        $this->post(route('admin.store-channel.fake-sales-proof'))
            ->assertRedirect('/admin?tab=telegram-channel#admin-store-channel-fake-sales-proof');
    }

    public function test_sales_proof_retry_redirects_to_the_order_specific_sales_proof_target(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $order = Order::factory()->create([
            'invoice_number' => 'INV-RETURN-TARGET',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        SalesProofPost::query()->create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_SENT,
            'attempts' => 1,
        ]);

        $this->post(route('admin.orders.sales-proof.retry', $order))
            ->assertRedirect('/admin?tab=penjualan#sales-proof-'.$order->id);
    }

    public function test_sales_proof_retry_preserves_sales_list_context_when_available(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $order = Order::factory()->create([
            'invoice_number' => 'INV-RETURN-TARGET-PAGED',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        SalesProofPost::query()->create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_SENT,
            'attempts' => 1,
        ]);

        $this->from('/admin?tab=penjualan&sales_filter=issue&sales_page=2')
            ->post(route('admin.orders.sales-proof.retry', $order))
            ->assertRedirect('/admin?tab=penjualan&sales_filter=issue&sales_page=2#sales-proof-'.$order->id);
    }

    public function test_dashboard_renders_stable_return_targets_for_the_active_tab(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $product = Product::factory()->create(['name' => 'Visible Return Target Product']);
        $order = Order::factory()->create(['invoice_number' => 'INV-TARGET-ROW']);
        SalesProofPost::query()->create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'temporary failure',
        ]);

        $commonMarkup = [
            'data-admin-return-target',
            'admin-return-target-highlight',
            'aria-live="polite"',
        ];
        $tabMarkup = [
            'stats' => ['id="admin-section-stats"', 'id="admin-payment-settings"', 'id="admin-support-settings"'],
            'produk' => ['id="admin-section-products"', 'id="admin-category-management"', 'id="product-'.$product->id.'"'],
            'penjualan' => ['id="admin-section-sales"', 'id="sales-proof-'.$order->id.'"'],
            'broadcast' => ['id="admin-section-broadcast"'],
            'telegram-channel' => ['id="admin-section-store-channel"'],
        ];

        foreach ($tabMarkup as $tab => $expectedMarkup) {
            $content = $this->get('/admin?tab='.$tab)->assertOk()->getContent();

            foreach (array_merge($commonMarkup, $expectedMarkup) as $markup) {
                $this->assertStringContainsString($markup, $content);
            }
        }
    }
}
