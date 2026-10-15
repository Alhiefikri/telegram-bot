<?php

namespace Tests\Feature\Telegram;

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
use App\Models\TelegramUiIcon;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\TelegramDocumentPayload;
use App\Services\Telegram\TelegramPaidOrderTxtExporter;
use App\Services\Telegram\TelegramTranslationCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\TelegramBotTestSupport;

final class TelegramPaidOrderTxtExporterTest extends TelegramBotTestSupport
{
    public function test_it_exports_all_credential_kinds_in_stable_product_and_account_order(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 421001, 'locale' => 'en']);
        $beta = Product::factory()->create(['name' => 'Product Beta']);
        $alpha = Product::factory()->create(['name' => 'Product Alpha']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-421-EXPORT',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $betaItem = OrderItem::factory()->for($order)->create([
            'product_id' => $beta->id,
            'product_name' => $beta->name,
            'delivery_note' => 'Beta snapshot note',
            'delivery_note_snapshotted' => true,
            'usage_guide_title' => 'Shared setup guide',
            'usage_guide_url' => 'https://guide.example.test/setup',
            'usage_guide_snapshotted' => true,
            'quantity' => 4,
        ]);
        $alphaItem = OrderItem::factory()->for($order)->create([
            'product_id' => $alpha->id,
            'product_name' => $alpha->name,
            'delivery_note' => 'Alpha snapshot note',
            'delivery_note_snapshotted' => true,
            'usage_guide_title' => 'Shared setup guide',
            'usage_guide_url' => 'https://guide.example.test/setup',
            'usage_guide_snapshotted' => true,
            'quantity' => 1,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $legacySecret = 'legacy@example.test|Legacy-password|Legacy-mfa';
        ProductStockItem::create([
            'product_id' => $beta->id,
            'order_item_id' => $betaItem->id,
            'secret' => $legacySecret,
        ]);
        ProductStockItem::create([
            'product_id' => $beta->id,
            'order_item_id' => $betaItem->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => $this->structured(
                'none@example.test',
                'None-password',
                CredentialAccessMethod::None,
            ),
        ]);
        $otpUrl = 'https://mail.example.test/otp?opaque=full-otp-value';
        ProductStockItem::create([
            'product_id' => $beta->id,
            'order_item_id' => $betaItem->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => $this->structured(
                'otp@example.test',
                'Otp-password',
                CredentialAccessMethod::OtpMailLink,
                $otpUrl,
                'OTP-specific note',
            ),
        ]);
        $authToken = 'auth-token-value';
        ProductStockItem::create([
            'product_id' => $beta->id,
            'order_item_id' => $betaItem->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => $this->structured(
                'auth@example.test',
                'Auth-password',
                CredentialAccessMethod::AuthToken,
                $authToken,
            ),
        ]);
        ProductStockItem::create([
            'product_id' => $alpha->id,
            'order_item_id' => $alphaItem->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => $this->structured(
                'alpha@example.test',
                'Alpha-password',
                CredentialAccessMethod::None,
            ),
        ]);

        $payload = app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);

        $this->assertInstanceOf(TelegramDocumentPayload::class, $payload);
        $contents = $payload->documentContents();
        $this->assertSame('lhie-store-INV-421-EXPORT.txt', $payload->filename());
        $this->assertSame(strlen($contents), $payload->byteLength());
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $contents);
        $this->assertStringNotContainsString("\r", $contents);
        $this->assertStringContainsString("LHIE STORE\nOrder : INV-421-EXPORT\nItems : 5", $contents);

        $betaPosition = strpos($contents, 'Product : Product Beta');
        $alphaPosition = strpos($contents, 'Product : Product Alpha');
        $this->assertNotFalse($betaPosition);
        $this->assertNotFalse($alphaPosition);
        $this->assertLessThan($alphaPosition, $betaPosition);

        $this->assertStringContainsString("email : legacy@example.test\npassword : Legacy-password\nMFA : Legacy-mfa", $contents);
        $this->assertStringNotContainsString($legacySecret, $contents);
        $this->assertStringContainsString("access: OTP Mail Link\nOTP Mail Link : {$otpUrl}", $contents);
        $this->assertSame(1, substr_count($contents, $otpUrl));
        $this->assertStringContainsString("access: Auth Token\nAuth Token : {$authToken}", $contents);
        $this->assertSame(1, substr_count($contents, $authToken));
        $this->assertSame(2, substr_count($contents, 'Account #1'));
        $this->assertSame(1, substr_count($contents, 'Account #2'));
        $this->assertSame(1, substr_count($contents, 'Account #3'));
        $this->assertSame(1, substr_count($contents, 'Account #4'));
        $this->assertSame(1, substr_count($contents, 'Beta snapshot note'));
        $this->assertSame(1, substr_count($contents, 'OTP-specific note'));
        $this->assertSame(1, substr_count($contents, 'Alpha snapshot note'));
        $this->assertSame(1, substr_count($contents, 'Usage guide : Shared setup guide'));
        $this->assertSame(1, substr_count($contents, 'URL : https://guide.example.test/setup'));

        $noneAccountStart = strpos($contents, 'Account #2');
        $otpAccountStart = strpos($contents, 'Account #3');
        $this->assertNotFalse($noneAccountStart);
        $this->assertNotFalse($otpAccountStart);
        $this->assertStringNotContainsString('access:', substr($contents, $noneAccountStart, $otpAccountStart - $noneAccountStart));
    }

    public function test_txt_warning_is_plain_unicode_without_changing_literal_credential_markup(): void
    {
        TelegramUiIcon::query()->where('key', 'message.warning')->sole()->update([
            'custom_emoji_id' => '5420323339723881652',
        ]);
        app(CustomEmojiCapability::class)->markAvailable();
        $secret = '<tg-emoji emoji-id="123456">⚠️</tg-emoji> <b>literal</b> &amp; <>&';
        [$order, $customer] = $this->paidOrderWithLegacy($secret);
        $customer->update(['locale' => 'id']);

        $payload = app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);

        $this->assertInstanceOf(TelegramDocumentPayload::class, $payload);
        [$header] = explode("\n\n", $payload->documentContents(), 2);
        $this->assertStringContainsString(
            app(TelegramTranslationCatalog::class)->get('receipt.save_details', 'id'),
            $header,
        );
        $this->assertStringContainsString('⚠️', $header);
        $this->assertStringNotContainsString('<', $header);
        $this->assertStringNotContainsString('>', $header);
        $this->assertStringEndsWith("Account #1\n".$secret, $payload->documentContents());
    }

    public function test_it_returns_no_payload_when_the_paid_order_has_no_allocated_credentials(): void
    {
        [$order, $customer] = $this->paidOrderWithoutStock();

        $this->assertNull(app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer));
    }

    public function test_it_rejects_foreign_and_ineligible_orders_without_secret_details(): void
    {
        [$order, $customer] = $this->paidOrderWithLegacy('foreign@example.test|foreign-password');
        $foreignCustomer = Customer::factory()->create(['telegram_id' => 421002]);

        try {
            app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $foreignCustomer);
            $this->fail('A foreign Customer must not receive a credential export.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('TXT credential export is unavailable.', $exception->getMessage());
            $this->assertStringNotContainsString('foreign-password', $exception->getMessage());
        }

        $order->update([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);

        try {
            app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);
            $this->fail('An ineligible Order must not receive a credential export.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('TXT credential export is unavailable.', $exception->getMessage());
            $this->assertStringNotContainsString('foreign-password', $exception->getMessage());
        }
    }

    public function test_malformed_structured_data_and_otp_urls_fail_closed(): void
    {
        [$order, $customer, $item] = $this->paidOrderWithItem();
        $secret = 'malformed-password';
        ProductStockItem::withoutEvents(static fn () => ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => [
                'version' => 1,
                'email' => 'malformed@example.test',
                'password' => $secret,
                'access_method' => CredentialAccessMethod::OtpMailLink->value,
                'access_value' => 'http://not-https.example.test/otp',
                'tutorial_override' => null,
            ],
        ]));

        try {
            app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);
            $this->fail('Malformed structured credentials must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('TXT credential export is unavailable.', $exception->getMessage());
            $this->assertStringNotContainsString($secret, $exception->getMessage());
            $this->assertStringNotContainsString('http://not-https', $exception->getMessage());
        }
    }

    public function test_oversized_output_is_rejected_before_a_payload_is_created(): void
    {
        [$order, $customer] = $this->paidOrderWithLegacy(
            'large@example.test|'.str_repeat('X', TelegramDocumentPayload::MAX_BYTES),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('TXT credential export is unavailable.');
        app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);
    }

    public function test_filename_uses_a_conservative_order_reference_and_falls_back_to_order_key(): void
    {
        [$order, $customer] = $this->paidOrderWithLegacy('filename@example.test|filename-password');
        $order->update(['invoice_number' => "../bad\\name\r\n..??"]);

        $payload = app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);

        $this->assertInstanceOf(TelegramDocumentPayload::class, $payload);
        $this->assertMatchesRegularExpression('/\Alhie-store-[A-Za-z0-9][A-Za-z0-9._-]*\.txt\z/', $payload->filename());
        $this->assertStringNotContainsString('/', $payload->filename());
        $this->assertStringNotContainsString('\\', $payload->filename());
        $this->assertStringNotContainsString("\r", $payload->filename());
        $this->assertStringNotContainsString("\n", $payload->filename());

        $fallbackOrder = $order->fresh();
        $fallbackOrder->setAttribute('invoice_number', null);
        $fallback = app(TelegramPaidOrderTxtExporter::class)->export($fallbackOrder, $customer);
        $this->assertSame('lhie-store-'.(string) $order->id.'.txt', $fallback?->filename());
    }

    public function test_export_eager_loads_stock_items_once_for_many_accounts(): void
    {
        [$order, $customer, $item] = $this->paidOrderWithItem(quantity: 20);
        for ($index = 1; $index <= 20; $index++) {
            ProductStockItem::create([
                'product_id' => $item->product_id,
                'order_item_id' => $item->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => $this->structured(
                    "account-{$index}@example.test",
                    "password-{$index}",
                    CredentialAccessMethod::None,
                ),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $stockQueries = array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower((string) ($query['query'] ?? '')), 'product_stock_items'),
        );
        $this->assertCount(1, $stockQueries, json_encode($queries, JSON_THROW_ON_ERROR));
    }

    public function test_it_exports_reader_mail_link_bulk_credentials_with_token_and_link_labels(): void
    {
        [$order, $customer, $item] = $this->paidOrderWithItem();
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => $this->structured(
                'reader@example.test',
                'Reader-password',
                CredentialAccessMethod::AuthToken,
                'READER-TOKEN',
                null,
                'https://mail.example.test/reader/abc',
            ),
        ]);

        $payload = app(TelegramPaidOrderTxtExporter::class)->export($order->fresh(), $customer);

        $this->assertStringContainsString(
            "email : reader@example.test\npassword : Reader-password\nakses token : READER-TOKEN\nlink reader mail : https://mail.example.test/reader/abc",
            $payload?->documentContents() ?? '',
        );
    }

    /** @return array<string, mixed> */
    private function structured(
        string $email,
        string $password,
        CredentialAccessMethod $accessMethod,
        ?string $accessValue = null,
        ?string $tutorial = null,
        ?string $readerMailLink = null,
    ): array {
        return [
            'version' => 1,
            'email' => $email,
            'password' => $password,
            'access_method' => $accessMethod->value,
            'access_value' => $accessValue,
            'tutorial_override' => $tutorial,
            'reader_mail_link' => $readerMailLink,
        ];
    }

    /** @return array{0: Order, 1: Customer} */
    private function paidOrderWithoutStock(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 421003]);
        $product = Product::factory()->create(['name' => 'No Credential Product']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return [$order, $customer];
    }

    /** @return array{0: Order, 1: Customer} */
    private function paidOrderWithLegacy(string $secret): array
    {
        [$order, $customer] = $this->paidOrderWithItem();
        $item = $order->orderItems()->sole();
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'secret' => $secret,
        ]);

        return [$order, $customer];
    }

    /** @return array{0: Order, 1: Customer, 2: OrderItem} */
    private function paidOrderWithItem(int $quantity = 1): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 421004]);
        $product = Product::factory()->create(['name' => 'TXT Export Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-421-TEST',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return [$order, $customer, $item];
    }
}
