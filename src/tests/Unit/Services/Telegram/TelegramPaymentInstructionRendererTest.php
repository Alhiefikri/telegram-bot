<?php

namespace Tests\Unit\Services\Telegram;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisPayableNativeQris;
use App\Services\Telegram\TelegramPaymentInstruction;
use App\Services\Telegram\TelegramPaymentInstructionRenderer;
use App\Services\Telegram\TelegramQrCodeRenderer;
use App\Services\Telegram\TelegramTranslationCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramPaymentInstructionRendererTest extends TestCase
{
    use RefreshDatabase;

    private RecordingQrCodeRenderer $qrCodes;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
        config()->set('app.timezone', 'Asia/Jakarta');
        date_default_timezone_set('Asia/Jakarta');

        $this->qrCodes = new RecordingQrCodeRenderer;
        $this->instance(TelegramQrCodeRenderer::class, $this->qrCodes);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }







    public function test_klikqris_instruction_localizes_warning_for_id_locale(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Jakarta'));
        $payment = $this->klikPayment($order, 125000, '125875.00', 125875);
        $payable = KlikQrisPayableNativeQris::fromPayment($payment);

        $instruction = $this->renderer()->klikQris($order, $payable, 'qris-png:klikqris', 'id');

        $this->assertContains('⚠️ Jangan membulatkan atau mengubah satu digit pun.', $instruction->captionLines());
        $this->assertStringNotContainsString('Do not round or change any digit.', $instruction->caption());
    }


    #[DataProvider('klikQrisExactAmountDisplays')]
    public function test_klikqris_instruction_displays_exact_validated_provider_amount(mixed $providerValue, string $expectedDisplay): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Jakarta'));
        $payment = $this->klikPayment($order, 1000, $providerValue, 1016);
        $payable = KlikQrisPayableNativeQris::fromPayment($payment);

        $instruction = $this->renderer()->klikQris($order, $payable, 'provider-qris-png', 'en');

        $this->assertSame('provider-qris-png', $instruction->photoContents);
        $this->assertContains(
            '💳 Total QRIS payment: '.$expectedDisplay,
            $instruction->captionLines(),
            "Caption lines:\n".$instruction->caption(),
        );
        $this->assertStringNotContainsString('IDR 1,016.00', $instruction->caption());
    }

    public static function klikQrisExactAmountDisplays(): array
    {
        return [
            'integer' => [1016, 'IDR 1016'],
            'integer string' => ['1016', 'IDR 1016'],
            'one zero decimal' => ['1016.0', 'IDR 1016.0'],
            'two zero decimals' => ['1016.00', 'IDR 1016.00'],
        ];
    }

    public function test_klikqris_payable_payload_refuses_mismatched_persisted_provider_amount(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Jakarta'));
        $payment = $this->klikPayment($order, 1000, '1017.00', 1016);

        $this->expectException(\RuntimeException::class);

        KlikQrisPayableNativeQris::fromPayment($payment);
    }

    public function test_manual_instruction_uses_indonesian_caption_and_no_buyer_actions_for_id_locale(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $manualQrContents = file_get_contents(public_path('qr-lhie.jpg'));
        $this->assertIsString($manualQrContents);

        $instruction = $this->renderer()->manual($order, [
            'label' => 'QR Manual BCA',
            'name' => 'Lhie Store QRIS',
        ], 'warung_support', 'id');

        $this->assertSame([], $this->qrCodes->renderedContents);
        $this->assertSame($manualQrContents, $instruction->photoContents);
        $this->assertSame('qr-lhie.jpg', $instruction->filename);
        $this->assertSame([], $instruction->replyMarkup);
        $this->assertCaptionIncludes($instruction, [
            '✅ Checkout berhasil dibuat',
            '🧾 Invoice: INV-RENDERER-103',
            '💰 Total: Rp 125.000',
            '💳 Bayar via: QR Manual BCA',
            '📲 Scan QR manual di atas.',
            '• Nama QR: Lhie Store QRIS',
            '• Nominal wajib sama persis: Rp 125.000',
            '⏳ Batas bayar:',
            '📦 Pesanan: Sourdough Starter x2, Kefir Kit',
            '/payment',
            'Kalau kesusahan, chat admin @warung_support.',
        ]);
        $this->assertStringContainsString('Setelah bayar, kirim screenshot bukti pembayaran ke bot ini sebagai FOTO dengan caption:', $instruction->caption());
    }

    public function test_payment_deadline_month_is_resolved_by_translation_catalog(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(function (string $key): string {
            return $key === 'date.month.8' ? 'Catalog August' : $key;
        });
        $renderer = new TelegramPaymentInstructionRenderer(
            $this->qrCodes,
            new TelegramTranslationCatalog($translator),
        );
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-08-04 16:30:00', 'Asia/Jakarta'));

        $instruction = $renderer->manual($order, [
            'label' => 'BCA Manual QR',
            'name' => 'Lhie Store QRIS',
        ], 'warung_support', 'en');

        $this->assertStringContainsString('04 Catalog August 2026 16:30 WIB', $instruction->caption());
    }


    public static function paymentDeadlineMonthCases(): array
    {
        return [
            'indonesian' => ['id', 'Agu'],
            'english' => ['en', 'Aug'],
            'malay' => ['ms', 'Ogo'],
        ];
    }

    public function test_manual_instruction_uses_english_caption_and_no_buyer_actions_for_en_locale(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $manualQrContents = file_get_contents(public_path('qr-lhie.jpg'));
        $this->assertIsString($manualQrContents);

        $instruction = $this->renderer()->manual($order, [
            'label' => 'BCA Manual QR',
            'name' => 'Lhie Store QRIS',
        ], 'warung_support', 'en');

        $this->assertSame([], $this->qrCodes->renderedContents);
        $this->assertSame($manualQrContents, $instruction->photoContents);
        $this->assertSame('qr-lhie.jpg', $instruction->filename);
        $this->assertSame([], $instruction->replyMarkup);
        $this->assertCaptionIncludes($instruction, [
            '✅ Checkout created',
            '🧾 Invoice: INV-RENDERER-103',
            '💰 Total: Rp 125.000',
            '💳 Pay via: BCA Manual QR',
            '📲 Scan the manual QR above.',
            '• QR name: Lhie Store QRIS',
            '• Amount must match exactly: Rp 125.000',
            '⏳ Payment deadline:',
            '📦 Order: Sourdough Starter x2, Kefir Kit',
            '/payment',
            'Need help? Chat admin @warung_support.',
        ]);
        $this->assertStringContainsString('After paying, send a payment screenshot to this bot as a PHOTO with this caption:', $instruction->caption());
        $this->assertEnglishCaptionOmitsIndonesianPaymentCopy($instruction);
    }

    public function test_manual_instruction_uses_malay_caption_and_preserves_payment_values(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $paymentOption = [
            'label' => 'QR Manual BCA',
            'name' => 'Lhie Store QRIS',
        ];

        $instruction = $this->renderer()->manual($order, $paymentOption, 'warung_support', 'ms');

        $this->assertCaptionIncludes($instruction, [
            '✅ Checkout berjaya dibuat',
            '🧾 Invois: INV-RENDERER-103',
            '💰 Jumlah: Rp 125.000',
            '💳 Bayar melalui: QR Manual BCA',
            '📲 Imbas QR manual di atas.',
            '• Nama QR: Lhie Store QRIS',
            '• Jumlah mesti sama tepat: Rp 125.000',
            '⏳ Tarikh akhir bayaran:',
            '📦 Pesanan: Sourdough Starter x2, Kefir Kit',
            '/payment',
            'Perlukan bantuan? Hubungi admin @warung_support.',
        ]);
        $this->assertStringContainsString('Selepas membayar, hantar tangkapan skrin pembayaran ke bot ini sebagai FOTO dengan kapsyen:', $instruction->caption());
        $this->assertStringNotContainsString('Checkout berhasil dibuat', $instruction->caption());
        $this->assertStringNotContainsString('Checkout created', $instruction->caption());
    }



    public function test_klikqris_payable_and_retry_states_use_malay_copy(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Jakarta'));
        $payment = $this->klikPayment($order, 125000, '125875.00', 125875);
        $payable = KlikQrisPayableNativeQris::fromPayment($payment);

        $instruction = $this->renderer()->klikQris($order, $payable, 'qris-png:klikqris', 'ms');
        $retry = $this->renderer()->klikQrisRetry($order, KlikQrisNativeQrisOutcome::ProviderTotalPending, 'ms');

        $this->assertCaptionIncludes($instruction, [
            '✅ Checkout KlikQRIS berjaya dibuat',
            '🧾 Invois: INV-RENDERER-103',
            '💳 Jumlah bayaran QRIS: IDR 125875.00',
        ]);
        $this->assertStringContainsString('QRIS ini sah selama 15 minit', $instruction->caption());
        $this->assertStringContainsString('Menunggu jumlah akhir', $retry);
        $this->assertStringContainsString('Jangan bayar jumlah produk sahaja', $retry);
        $this->assertStringContainsString('Semak Bayaran', json_encode($instruction->replyMarkup) ?: '');
        $this->assertStringContainsString('Batalkan Pesanan', json_encode($instruction->replyMarkup) ?: '');
    }

    public function test_binance_instruction_uses_malay_copy_and_preserves_amount_and_receiver(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $payment = Payment::factory()->for($order)->create([
            'amount' => 12_345_678,
            'binance_verification_mode' => 'order_id',
        ]);

        $instruction = $this->renderer()->binancePay($order, $payment, '987654321', 'ms', true, 'BINANCE PAY ID');

        $this->assertStringContainsString('💎 Checkout Binance Pay', $instruction);
        $this->assertStringContainsString('🎫 Pesanan: INV-RENDERER-103', $instruction);
        $this->assertStringContainsString('12.345678 USDT', $instruction);
        $this->assertStringContainsString('📥 BINANCE PAY ID', $instruction);
        $this->assertStringContainsString('987654321', $instruction);
        $this->assertStringContainsString('Tekan butang Saya Sudah Bayar', $instruction);
        $this->assertStringNotContainsString('Amount to send', $instruction);
    }

    private function renderer(): TelegramPaymentInstructionRenderer
    {
        return $this->app->make(TelegramPaymentInstructionRenderer::class);
    }

    private function orderWithItems(CarbonImmutable $expiresAt): Order
    {
        $order = Order::factory()->create([
            'invoice_number' => 'INV-RENDERER-103',
            'total_amount' => 125000,
            'expires_at' => $expiresAt,
        ]);

        OrderItem::factory()->for($order)->create([
            'product_name' => 'Sourdough Starter',
            'quantity' => 2,
            'unit_price' => 50000,
            'subtotal' => 100000,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_name' => 'Kefir Kit',
            'quantity' => 1,
            'unit_price' => 25000,
            'subtotal' => 25000,
        ]);

        return $order;
    }

    private function klikPayment(Order $order, int $amount, mixed $providerValue, int $providerTotal): Payment
    {
        $providerExpiresAt = CarbonImmutable::now('Asia/Jakarta')->addMinutes(15)->startOfSecond();
        $order->update(['expires_at' => $providerExpiresAt->copy()->subMinutes(5)]);

        return Payment::factory()->for($order)->create([
            'provider' => 'klikqris',
            'provider_payment_method' => 'qris',
            'merchant_ref' => $order->invoice_number,
            'amount' => $amount,
            'provider_fee' => $providerTotal - $amount,
            'provider_total_payment' => $providerTotal,
            'provider_expires_at' => $providerExpiresAt,
            'provider_create_state' => 'created',
            'provider_payload' => ['data' => [
                'order_id' => $order->invoice_number,
                'amount' => $amount,
                'total_amount' => $providerValue,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_renderer.png',
                'expired_at' => $providerExpiresAt->utc()->toAtomString(),
            ]],
        ]);
    }


    private function assertEnglishCaptionOmitsIndonesianPaymentCopy(TelegramPaymentInstruction $instruction): void
    {
        $caption = $instruction->caption();
        $indonesianSystemPhrases = [
            'Checkout berhasil dibuat',
            'Checkout QRIS dialihkan',
            '📦 Produk:',
            '🔢 Jumlah:',
            '💰 Total produk:',
            '🏷️ Biaya QRIS:',
            '💳 Total bayar QRIS:',
            '💳 Bayar via:',
            '⏳ Batas bayar',
            '⏳ Order aktif sampai:',
            '📦 Pesanan:',
            'Bayar dengan nominal',
            'QRIS langsung belum bisa dibuat otomatis',
            'Gunakan QR/link website',
            'Scan QRIS di atas',
            'Scan QR manual di atas',
            'Sebelum bayar, pastikan',
            'Nama QR:',
            'Nominal wajib sama persis',
            'Stok dipegang',
            'Setelah bayar',
            'Setelah pembayaran berhasil',
            'Kalau belum ada pesan otomatis',
            'Kalau kesusahan',
        ];

        foreach ($indonesianSystemPhrases as $phrase) {
            $this->assertStringNotContainsString($phrase, $caption, "Unexpected Indonesian payment copy in English caption:\n{$caption}");
        }
    }

    /**
     * @param  list<string>  $expectedLines
     */
    private function assertCaptionIncludes(TelegramPaymentInstruction $instruction, array $expectedLines): void
    {
        foreach ($expectedLines as $expectedLine) {
            $this->assertContains($expectedLine, $instruction->captionLines(), "Caption lines:\n".$instruction->caption());
        }
    }


}

final class RecordingQrCodeRenderer extends TelegramQrCodeRenderer
{
    /** @var list<string> */
    public array $renderedContents = [];

    public function png(string $content): string
    {
        $this->renderedContents[] = $content;

        return 'qr-png:'.$content;
    }
}
