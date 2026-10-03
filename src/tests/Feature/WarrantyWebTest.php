<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\WarrantyClaim;
use App\Services\Telegram\TelegramReceiptActionPanel;
use App\Services\Warranty\WarrantyOrderAccess;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\BuildsBuyMailWarrantyFixture;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class WarrantyWebTest extends NonTransactionalDatabaseTestCase
{
    use BuildsBuyMailWarrantyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
    }

    public function test_signed_private_telegram_link_opens_english_support_and_claim_upload_without_supplier_details(): void
    {
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001, 'locale' => 'en']);
        $url = app(WarrantyOrderAccess::class)->telegramUrl($unit->order, 'en');
        $this->get($url)->assertOk()->assertSee('Account support and refunds')->assertSee('Google app on your phone')
            ->assertDontSee('synthetic-test-key')->assertDontSee('PR1')->assertDontSee('Private source')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post(route('warranty.claim', $unit->order), [
            'unit_id' => $unit->public_id, 'note' => 'Wrong password',
            'proof' => UploadedFile::fake()->image('phone-proof.png', 400, 600),
        ])->assertRedirect();
        $this->assertSame(1, WarrantyClaim::count());
        $this->get(route('warranty.show', $unit->order))->assertOk()->assertSee('Your claim is under review');
    }

    public function test_other_customers_and_an_unsigned_visitor_cannot_open_or_submit_order_claims(): void
    {
        [, $unit] = $this->fixture();
        $this->get('/pesanan/'.$unit->order_id.'/bantuan-akun')->assertForbidden();
        $this->actingAs(Customer::factory()->create(), 'customer');
        $this->get('/pesanan/'.$unit->order_id.'/bantuan-akun')->assertForbidden();
        $this->post('/pesanan/'.$unit->order_id.'/bantuan-akun/klaim', ['unit_id' => $unit->public_id])->assertForbidden();
        $this->assertSame(0, WarrantyClaim::count());
    }

    public function test_paid_telegram_action_panel_has_localized_private_account_support(): void
    {
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => 790001, 'locale' => 'en']);
        $panel = app(TelegramReceiptActionPanel::class)->render($unit->order, 'en', true);
        $buttons = array_merge(...$panel->replyMarkup()['inline_keyboard']);
        $warranty = array_values(array_filter($buttons, fn ($button) => $button['text'] === 'Account support and refunds'));
        $this->assertCount(1, $warranty);
        $this->assertStringContainsString('bantuan-akun', $warranty[0]['url']);
        $this->assertStringContainsString('signature=', $warranty[0]['url']);
    }

    public function test_private_warranty_access_still_requires_paid_credential_eligibility_to_reveal_replacement_secrets(): void
    {
        [$customer, $unit] = $this->fixture();
        $stock = $unit->stockItem->replicate();
        $stock->supplier_unit_index = 2;
        $stock->save();
        $replacement = $unit->replicate();
        $replacement->public_id = (string) Str::uuid();
        $replacement->external_id = 'PR2';
        $replacement->product_stock_item_id = $stock->id;
        $replacement->replaces_unit_id = $unit->id;
        $replacement->save();
        $unit->order->update(['payment_status' => PaymentStatus::Pending]);
        $this->actingAs($customer, 'customer')->get(route('warranty.show', $unit->order))->assertOk()->assertDontSee('synthetic-password');
    }

    public function test_a_guest_buyer_can_open_warranty_with_the_existing_recovery_key_flow(): void
    {
        [$customer, $unit] = $this->fixture();
        $customer->update(['telegram_id' => null, 'locale' => 'en']);
        $key = app(StorefrontOrderAccess::class)->issue($unit->order);
        $this->get(route('warranty.show', $unit->order))->assertForbidden();
        $this->post(route('storefront.order-access.store'), ['invoice_number' => $unit->order->invoice_number, 'access_key' => $key])->assertRedirect();
        $this->get(route('warranty.show', $unit->order))->assertOk()->assertSee('Account support and refunds')->assertDontSee($key);
    }
}
