<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\Credentials\StructuredCredentialPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialDeliveryPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ineligible_orders_and_foreign_customers_receive_no_credential_presentation(): void
    {
        $presentation = app(CredentialDeliveryPresentation::class);
        $statuses = [
            [OrderStatus::PendingPayment, PaymentStatus::Pending],
            [OrderStatus::Cancelled, PaymentStatus::Failed],
            [OrderStatus::Expired, PaymentStatus::Expired],
            [OrderStatus::Processing, PaymentStatus::Pending],
            [OrderStatus::Completed, PaymentStatus::Refunded],
        ];

        foreach ($statuses as [$orderStatus, $paymentStatus]) {
            [$order, $item] = $this->orderWithItem($orderStatus, $paymentStatus);
            ProductStockItem::create([
                'product_id' => $item->product_id,
                'order_item_id' => $item->id,
                'secret' => 'ineligible@example.test|never-deliver|never-mfa',
            ]);

            $delivery = $presentation->forOrder($order->fresh());

            $this->assertTrue($delivery->isEmpty());
            $this->assertSame([], $delivery->groups());
            $this->assertSame([], $delivery->usageGuides());
        }

        [$order, $item] = $this->paidOrderWithItem();
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'secret' => 'foreign@example.test|never-deliver|never-mfa',
        ]);

        $foreignCustomer = Customer::factory()->create();

        $this->assertTrue($presentation->forCustomer($foreignCustomer, $order->fresh())->isEmpty());
        $this->assertFalse($presentation->forCustomer($order->customer, $order->fresh())->isEmpty());
    }

    public function test_single_account_projection_requires_paid_allocation_and_excludes_retired_credentials(): void
    {
        [$order, $item] = $this->paidOrderWithItem();
        $stock = ProductStockItem::create([
            'product_id' => $item->product_id, 'order_item_id' => $item->id,
            'secret' => 'allocated@example.test|allocated-password',
        ]);
        $presentation = app(CredentialDeliveryPresentation::class);
        $this->assertSame('allocated-password', $presentation->forStockItem($order, $stock)?->password());

        [$otherOrder] = $this->paidOrderWithItem();
        $this->assertNull($presentation->forStockItem($otherOrder, $stock));
        $order->update(['payment_status' => PaymentStatus::Pending]);
        $this->assertNull($presentation->forStockItem($order, $stock));
        $order->update(['payment_status' => PaymentStatus::Paid]);
        $stock->update(['retired_at' => now()]);
        $this->assertNull($presentation->forStockItem($order, $stock));
    }

    public function test_paid_orders_preserve_item_and_stock_item_order_and_legacy_formatting(): void
    {
        $customer = Customer::factory()->create();
        $firstProduct = Product::factory()->create(['name' => 'First Product', 'delivery_note' => 'First tutorial']);
        $secondProduct = Product::factory()->create(['name' => 'Second Product', 'delivery_note' => 'Second tutorial']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $secondItem = OrderItem::factory()->for($order)->create([
            'product_id' => $secondProduct->id,
            'product_name' => $secondProduct->name,
        ]);
        $firstItem = OrderItem::factory()->for($order)->create([
            'product_id' => $firstProduct->id,
            'product_name' => $firstProduct->name,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        ProductStockItem::create([
            'product_id' => $firstProduct->id,
            'order_item_id' => $firstItem->id,
            'secret' => 'first@example.test|first-password|first-mfa',
        ]);
        ProductStockItem::create([
            'product_id' => $secondProduct->id,
            'order_item_id' => $secondItem->id,
            'secret' => 'second-a@example.test|second-password-a|second-mfa-a',
        ]);
        ProductStockItem::create([
            'product_id' => $secondProduct->id,
            'order_item_id' => $secondItem->id,
            'secret' => 'second-b@example.test|second-password-b|second-mfa-b',
        ]);

        $groups = app(CredentialDeliveryPresentation::class)->forOrder($order->fresh())->groups();

        $this->assertSame(['Second Product', 'First Product'], array_map(
            static fn ($group): string => $group->productName(),
            $groups,
        ));
        $this->assertSame(['Data akun 1', 'Data akun 2'], array_map(
            static fn ($account): string => $account->label(),
            $groups[0]->accounts(),
        ));
        $this->assertSame(
            "email : second-a@example.test\npassword : second-password-a\nMFA : second-mfa-a",
            $groups[0]->accounts()[0]->text(),
        );
        $this->assertSame([
            'email' => 'second-a@example.test',
            'password' => 'second-password-a',
            'mfa' => 'second-mfa-a',
        ], $groups[0]->accounts()[0]->legacyParts());
        $this->assertSame('Second tutorial', $groups[0]->defaultTutorial());
    }

    public function test_paid_two_part_legacy_credentials_are_presented_without_an_mfa_field(): void
    {
        [$order, $item] = $this->paidOrderWithItem();
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'secret' => 'two-part@example.test|@Prime12345',
        ]);

        $account = app(CredentialDeliveryPresentation::class)
            ->forOrder($order->fresh())
            ->groups()[0]
            ->accounts()[0];

        $this->assertSame(
            "email : two-part@example.test\npassword : @Prime12345",
            $account->text(),
        );
        $this->assertSame([
            'email' => 'two-part@example.test',
            'password' => '@Prime12345',
            'mfa' => null,
        ], $account->legacyParts());
    }

    public function test_structured_and_legacy_items_share_ordered_groups_and_effective_tutorials(): void
    {
        [$order, $item] = $this->paidOrderWithItem([
            'delivery_note' => null,
            'usage_guide_title' => 'Provider guide',
            'usage_guide_url' => '/provider/guide',
        ]);
        $item->update([
            'delivery_note_snapshotted' => true,
            'delivery_note' => 'Snapshotted tutorial',
        ]);
        $item->load('product');
        $item->product->update(['delivery_note' => 'Current product tutorial']);

        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'secret' => 'legacy@example.test|legacy-password|legacy-mfa',
        ]);
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                '  Structured@Example.test ',
                'structured-password',
                CredentialAccessMethod::OtpMailLink,
                'https://mail.example.test/otp?opaque=fake-otp-value',
                'Use the item-specific tutorial.',
            )->toArray(),
        ]);
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'token@example.test',
                'token-password',
                CredentialAccessMethod::AuthToken,
                'fake-auth-token-value',
            )->toArray(),
        ]);

        $group = app(CredentialDeliveryPresentation::class)->forOrder($order->fresh())->groups()[0];
        $accounts = $group->accounts();

        $this->assertCount(3, $accounts);
        $this->assertTrue($accounts[0]->isLegacy());
        $this->assertFalse($accounts[1]->isLegacy());
        $this->assertSame('structured@example.test', $accounts[1]->email());
        $this->assertSame('structured-password', $accounts[1]->password());
        $this->assertSame(CredentialAccessMethod::OtpMailLink, $accounts[1]->accessMethod());
        $this->assertSame('https://mail.example.test/otp?opaque=fake-otp-value', $accounts[1]->accessValue());
        $this->assertSame('Use the item-specific tutorial.', $accounts[1]->tutorial());
        $this->assertSame(CredentialAccessMethod::AuthToken, $accounts[2]->accessMethod());
        $this->assertSame('Snapshotted tutorial', $accounts[2]->tutorial());
        $this->assertSame('Snapshotted tutorial', $group->defaultTutorial());
        $this->assertSame([
            'title' => 'Provider guide',
            'url' => url('/provider/guide'),
        ], $group->usageGuide());
    }

    public function test_malformed_structured_payload_fails_closed_without_partial_presentation(): void
    {
        [$order, $item] = $this->paidOrderWithItem();
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'secret' => 'valid@example.test|valid-password|valid-mfa',
        ]);
        ProductStockItem::withoutEvents(static fn () => ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => ['version' => 99],
        ]));

        $delivery = app(CredentialDeliveryPresentation::class)->forOrder($order->fresh());

        $this->assertTrue($delivery->isEmpty());
        $this->assertSame([], $delivery->groups());
    }

    public function test_secret_bearing_presentation_values_are_not_json_or_debug_serializable(): void
    {
        [$order, $item] = $this->paidOrderWithItem();
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'private@example.test',
                'private-password',
                CredentialAccessMethod::AuthToken,
                'private-auth-token',
            )->toArray(),
        ]);

        $account = app(CredentialDeliveryPresentation::class)->forOrder($order->fresh())->groups()[0]->accounts()[0];
        $json = json_encode($account, JSON_THROW_ON_ERROR);
        ob_start();
        var_dump($account);
        $debug = (string) ob_get_clean();

        $this->assertSame('{}', $json);
        $this->assertStringNotContainsString('private@example.test', $debug);
        $this->assertStringNotContainsString('private-password', $debug);
        $this->assertStringNotContainsString('private-auth-token', $debug);

        $this->expectException(\LogicException::class);
        serialize($account);
    }

    /**
     * @return array{0: Order, 1: OrderItem}
     */
    private function paidOrderWithItem(array $productOverrides = []): array
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create($productOverrides);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return [$order, $item];
    }

    /**
     * @return array{0: Order, 1: OrderItem}
     */
    private function orderWithItem(OrderStatus $orderStatus, PaymentStatus $paymentStatus): array
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $order = Order::factory()->for($customer)->create([
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
        Payment::factory()->for($order)->create([
            'status' => $paymentStatus,
            'paid_at' => $paymentStatus === PaymentStatus::Paid ? now() : null,
        ]);

        return [$order, $item];
    }
}
