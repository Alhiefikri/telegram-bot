<?php

namespace Tests\Unit\Services\Telegram;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisPayableNativeQris;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Services\Sakurupiah\SakurupiahInvoiceOutcome;
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

    public function test_hosted_pakasir_instruction_uses_indonesian_caption_and_buyer_actions_for_id_locale(): void
    {
        $expiresAt = CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta');
        $order = $this->orderWithItems($expiresAt);
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-HOSTED-103',
            'amount' => 125000,
        ]);
        $paymentUrl = 'https://pakasir.example/pay/PKS-HOSTED-103?token=hosted-token';

        $instruction = $this->renderer()->hostedPakasir($order, $payment, $paymentUrl, false, 'id');

        $this->assertSame([$paymentUrl], $this->qrCodes->renderedContents);
        $this->assertSame('qr-png:'.$paymentUrl, $instruction->photoContents);
        $this->assertSame('qris-checkout-PKS-HOSTED-103.png', $instruction->filename);
        $this->assertPakasirActions($instruction, $order, $paymentUrl, 'id');
        $this->assertCaptionIncludes($instruction, [
            '🧾 Invoice: INV-RENDERER-103',
            '📦 Produk: Sourdough Starter x2, Kefir Kit',
            '🔢 Jumlah: 3',
            '💰 Total: Rp125.000',
            '⏳ Order aktif sampai: 04 Jul 2026 16:30 WIB',
        ]);
        $this->assertStringContainsString('📲 Scan QRIS di atas, atau tap tombol bayar untuk buka halaman pembayaran.', $instruction->caption());
        $this->assertStringContainsString('sistem akan verifikasi otomatis', $instruction->caption());
        $this->assertStringNotContainsString('Pakasir', $instruction->caption());
        $this->assertStringNotContainsString('Pakasir', json_encode($instruction->replyMarkup) ?: '');
    }

    public function test_hosted_pakasir_instruction_uses_english_caption_and_buyer_actions_for_en_locale(): void
    {
        $expiresAt = CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta');
        $order = $this->orderWithItems($expiresAt);
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-HOSTED-EN-103',
            'amount' => 125000,
        ]);
        $paymentUrl = 'https://pakasir.example/pay/PKS-HOSTED-EN-103?token=hosted-token';

        $instruction = $this->renderer()->hostedPakasir($order, $payment, $paymentUrl, false, 'en');

        $this->assertSame([$paymentUrl], $this->qrCodes->renderedContents);
        $this->assertSame('qr-png:'.$paymentUrl, $instruction->photoContents);
        $this->assertPakasirActions($instruction, $order, $paymentUrl, 'en');
        $this->assertCaptionIncludes($instruction, [
            '🧾 Invoice: INV-RENDERER-103',
            '📦 Products: Sourdough Starter x2, Kefir Kit',
            '🔢 Quantity: 3',
            '💰 Total: Rp125.000',
            '⏳ Order active until: 04 Jul 2026 16:30 WIB',
        ]);
        $this->assertStringContainsString('Scan the QRIS code above', $instruction->caption());
        $this->assertStringContainsString('payment page', $instruction->caption());
        $this->assertStringContainsString('verified automatically', $instruction->caption());
        $this->assertEnglishCaptionOmitsIndonesianPaymentCopy($instruction);
    }

    public function test_fallback_hosted_instruction_explains_redirect_in_indonesian_for_id_locale(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-FALLBACK-ID-103',
            'amount' => 125000,
        ]);
        $paymentUrl = 'https://pakasir.example/pay/PKS-FALLBACK-ID-103?token=fallback-token';

        $instruction = $this->renderer()->hostedPakasir($order, $payment, $paymentUrl, true, 'id');

        $this->assertCaptionIncludes($instruction, [
            '⚠️ Checkout QRIS dialihkan',
            'QRIS langsung belum bisa dibuat otomatis. Order tetap pending dan stok tetap dipegang.',
            'Gunakan QR/link website di bawah untuk lanjut bayar.',
            '📦 Produk: Sourdough Starter x2, Kefir Kit',
        ]);
        $this->assertPakasirActions($instruction, $order, $paymentUrl, 'id');
    }

    public function test_fallback_hosted_instruction_explains_redirect_in_english_for_en_locale(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-FALLBACK-EN-103',
            'amount' => 125000,
        ]);
        $paymentUrl = 'https://pakasir.example/pay/PKS-FALLBACK-EN-103?token=fallback-token';

        $instruction = $this->renderer()->hostedPakasir($order, $payment, $paymentUrl, true, 'en');

        $this->assertStringContainsString('QRIS checkout', $instruction->caption());
        $this->assertStringContainsString('could not be created automatically', $instruction->caption());
        $this->assertStringContainsString('payment link below', $instruction->caption());
        $this->assertContains('📦 Products: Sourdough Starter x2, Kefir Kit', $instruction->captionLines());
        $this->assertPakasirActions($instruction, $order, $paymentUrl, 'en');
        $this->assertEnglishCaptionOmitsIndonesianPaymentCopy($instruction);
    }

    public function test_native_qris_instruction_uses_indonesian_caption_and_buyer_actions_for_id_locale(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Jakarta'));
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-QRIS-103',
            'amount' => 125000,
            'provider_expires_at' => CarbonImmutable::parse('2026-07-04 16:00:00', 'Asia/Jakarta'),
            'expired_at' => CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'),
        ]);
        $paymentNumber = '00020101021226610016ID.CO.QRIS.ISSUE103';
        $paymentUrl = 'https://pakasir.example/pay/PKS-QRIS-103?token=native-token';
        $transaction = $this->nativeTransaction($paymentNumber);

        $instruction = $this->renderer()->nativePakasir($order, $payment, $transaction, $paymentUrl, 'id');

        $this->assertSame([$paymentNumber], $this->qrCodes->renderedContents);
        $this->assertSame('qr-png:'.$paymentNumber, $instruction->photoContents);
        $this->assertSame('qris-PKS-QRIS-103.png', $instruction->filename);
        $this->assertPakasirActions($instruction, $order, $paymentUrl, 'id');
        $this->assertCaptionIncludes($instruction, [
            '🧾 Invoice: INV-RENDERER-103',
            '📦 Produk: Sourdough Starter x2, Kefir Kit',
            '🔢 Jumlah: 3',
            '💰 Total produk: Rp125.000',
            '🏷️ Biaya QRIS: Rp875',
            '💳 Total bayar QRIS: Rp125.875',
            '⏳ Batas bayar QRIS: 04 Jul 2026 15:45 WIB',
        ]);
        $this->assertStringContainsString('QR tidak bisa discan', $instruction->caption());
        $this->assertStringContainsString('Biaya QRIS dibayar pelanggan dan sudah termasuk dalam total di atas.', $instruction->caption());
        $this->assertStringContainsString('Bayar via Website', $instruction->caption());
        $this->assertStringNotContainsString('Pakasir', $instruction->caption());
        $this->assertStringNotContainsString('Pakasir', json_encode($instruction->replyMarkup) ?: '');
    }

    public function test_native_qris_instruction_uses_english_caption_and_buyer_actions_for_en_locale(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Jakarta'));
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-QRIS-EN-103',
            'amount' => 125000,
            'provider_expires_at' => CarbonImmutable::parse('2026-07-04 16:00:00', 'Asia/Jakarta'),
            'expired_at' => CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'),
        ]);
        $paymentNumber = '00020101021226610016EN.CO.QRIS.ISSUE103';
        $paymentUrl = 'https://pakasir.example/pay/PKS-QRIS-EN-103?token=native-token';
        $transaction = $this->nativeTransaction($paymentNumber);

        $instruction = $this->renderer()->nativePakasir($order, $payment, $transaction, $paymentUrl, 'en');

        $this->assertSame([$paymentNumber], $this->qrCodes->renderedContents);
        $this->assertSame('qr-png:'.$paymentNumber, $instruction->photoContents);
        $this->assertPakasirActions($instruction, $order, $paymentUrl, 'en');
        $this->assertCaptionIncludes($instruction, [
            '🧾 Invoice: INV-RENDERER-103',
            '📦 Products: Sourdough Starter x2, Kefir Kit',
            '🔢 Quantity: 3',
            '💰 Product total: Rp125.000',
            '🏷️ QRIS fee: Rp875',
            '💳 Total QRIS payment: Rp125.875',
            '⏳ QRIS payment deadline: 04 Jul 2026 15:45 WIB',
        ]);
        $this->assertStringContainsString('If the QR code cannot be scanned', $instruction->caption());
        $this->assertStringContainsString('Pay via Website', $instruction->caption());
        $this->assertStringContainsString('verified automatically', $instruction->caption());
        $this->assertStringContainsString('The QRIS fee is paid by the customer and is included in the total above.', $instruction->caption());
        $this->assertEnglishCaptionOmitsIndonesianPaymentCopy($instruction);
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

    public function test_native_qris_expiry_from_provider_utc_is_rendered_in_configured_app_timezone(): void
    {
        config()->set('app.timezone', 'Asia/Singapore');

        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 18:00:00', 'Asia/Singapore'));
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-QRIS-SG-103',
            'amount' => 125000,
            'expired_at' => CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Singapore'),
        ]);
        $paymentNumber = '00020101021226610016SG.QRIS.EXPIRY103';
        $paymentUrl = 'https://pakasir.example/pay/PKS-QRIS-SG-103?token=native-token';
        $transaction = PakasirQrisTransaction::fromPayload([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => 'PKS-QRIS-SG-103',
                'amount' => 125000,
                'total_payment' => 125000,
                'payment_method' => 'qris',
                'payment_number' => $paymentNumber,
                'expired_at' => '2026-07-04T08:45:00+00:00',
            ],
        ]);

        $instruction = $this->renderer()->nativePakasir($order, $payment, $transaction, $paymentUrl, 'id');

        $this->assertContains(
            '⏳ Batas bayar QRIS: 04 Jul 2026 16:45 Asia/Singapore',
            $instruction->captionLines(),
            "Caption lines:\n".$instruction->caption(),
        );
        $this->assertStringNotContainsString('08:45', $instruction->caption());
        $this->assertStringNotContainsString('UTC', $instruction->caption());
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

    public function test_manual_instruction_uses_indonesian_caption_and_buyer_actions_for_id_locale(): void
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
        $this->assertManualActions($instruction, $order, 'id');
        $this->assertCaptionIncludes($instruction, [
            '✅ Checkout berhasil dibuat',
            '🧾 Invoice: INV-RENDERER-103',
            '💰 Total: Rp 125.000',
            '💳 Bayar via: QR Manual BCA',
            '📲 Scan QR di atas dan bayar sesuai nominal.',
            '⏳ Batas bayar: 04 Jul 2026 16:30 WIB',
        ]);
        $this->assertStringNotContainsString('Sourdough Starter', $instruction->caption());
        $this->assertStringNotContainsString('/payment', $instruction->caption());
    }

    public function test_payment_deadline_month_is_resolved_by_translation_catalog(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->andReturnUsing(function (string $key): string {
            return match ($key) {
                'date.month.8' => 'Catalog August',
                'payment.manual.deadline_short' => 'Deadline: :date',
                default => $key,
            };
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

    #[DataProvider('paymentDeadlineMonthCases')]
    public function test_payment_deadline_month_is_localized_for_each_supported_locale(string $locale, string $month): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-08-04 16:30:00', 'Asia/Jakarta'));
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-MONTH-'.$locale,
            'amount' => 125000,
        ]);

        $instruction = $this->renderer()->hostedPakasir($order, $payment, 'https://pakasir.example/pay/month', false, $locale);

        $this->assertStringContainsString('04 '.$month.' 2026 16:30 WIB', $instruction->caption());
    }

    public static function paymentDeadlineMonthCases(): array
    {
        return [
            'indonesian' => ['id', 'Agu'],
            'english' => ['en', 'Aug'],
            'malay' => ['ms', 'Ogo'],
        ];
    }

    public function test_manual_instruction_uses_english_caption_and_buyer_actions_for_en_locale(): void
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
        $this->assertManualActions($instruction, $order, 'en');
        $this->assertCaptionIncludes($instruction, [
            '✅ Checkout created',
            '🧾 Invoice: INV-RENDERER-103',
            '💰 Total: Rp 125.000',
            '💳 Pay via: BCA Manual QR',
            '📲 Scan the QR above and pay the exact amount.',
            '⏳ Pay by: 04 Jul 2026 16:30 WIB',
        ]);
        $this->assertStringNotContainsString('Sourdough Starter', $instruction->caption());
        $this->assertStringNotContainsString('/payment', $instruction->caption());
        $this->assertEnglishCaptionOmitsIndonesianPaymentCopy($instruction);
    }

    public function test_manual_instruction_uses_malay_caption_and_buyer_actions_for_ms_locale(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $paymentOption = [
            'label' => 'QR Manual BCA',
            'name' => 'Lhie Store QRIS',
        ];

        $instruction = $this->renderer()->manual($order, $paymentOption, 'warung_support', 'ms');

        $this->assertManualActions($instruction, $order, 'ms');
        $this->assertCaptionIncludes($instruction, [
            '✅ Checkout berjaya dibuat',
            '🧾 Invois: INV-RENDERER-103',
            '💰 Jumlah: Rp 125.000',
            '💳 Bayar melalui: QR Manual BCA',
            '📲 Imbas QR di atas dan bayar mengikut jumlah tepat.',
            '⏳ Bayar sebelum: 04 Jul 2026 16:30 WIB',
        ]);
        $this->assertStringNotContainsString('Sourdough Starter', $instruction->caption());
        $this->assertStringNotContainsString('/payment', $instruction->caption());
    }

    public function test_hosted_pakasir_instruction_uses_malay_caption_and_actions(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-HOSTED-MS-103',
            'amount' => 125000,
        ]);
        $paymentUrl = 'https://pakasir.example/pay/PKS-HOSTED-MS-103?token=malay-token';

        $instruction = $this->renderer()->hostedPakasir($order, $payment, $paymentUrl, false, 'ms');

        $this->assertPakasirActions($instruction, $order, $paymentUrl, 'ms');
        $this->assertCaptionIncludes($instruction, [
            '🧾 Invois: INV-RENDERER-103',
            '📦 Produk: Sourdough Starter x2, Kefir Kit',
            '🔢 Kuantiti: 3',
            '💰 Jumlah: Rp125.000',
            '⏳ Pesanan aktif sehingga: 04 Jul 2026 16:30 WIB',
        ]);
        $this->assertStringContainsString('📲 Imbas kod QRIS di atas', $instruction->caption());
        $this->assertStringContainsString('Selepas bayaran berjaya', $instruction->caption());
    }

    public function test_native_pakasir_instruction_uses_malay_fee_and_deadline_copy(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Jakarta'));
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-QRIS-MS-103',
            'amount' => 125000,
            'provider_expires_at' => CarbonImmutable::parse('2026-07-04 16:00:00', 'Asia/Jakarta'),
            'expired_at' => CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'),
        ]);
        $transaction = $this->nativeTransaction('00020101021226610016MS.QRIS.ISSUE103');
        $paymentUrl = 'https://pakasir.example/pay/PKS-QRIS-MS-103?token=native-token';

        $instruction = $this->renderer()->nativePakasir($order, $payment, $transaction, $paymentUrl, 'ms');

        $this->assertCaptionIncludes($instruction, [
            '💰 Jumlah produk: Rp125.000',
            '🏷️ Yuran QRIS: Rp875',
            '💳 Jumlah bayaran QRIS: Rp125.875',
            '⏳ Tarikh akhir bayaran QRIS: 04 Jul 2026 15:45 WIB',
        ]);
        $this->assertStringContainsString('Yuran QRIS dibayar oleh pelanggan', $instruction->caption());
        $this->assertStringContainsString('Jika QR tidak boleh diimbas', $instruction->caption());
        $this->assertPakasirActions($instruction, $order, $paymentUrl, 'ms');
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

    public function test_sakurupiah_presentation_pending_retry_copy_warns_without_provider_data(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 17:00:00', 'Asia/Jakarta'));

        $retry = $this->renderer()->sakurupiahRetry(
            $order,
            SakurupiahInvoiceOutcome::ProviderPresentationPending,
            'en',
        );

        $this->assertStringContainsString('safe Sakurupiah payment display', $retry);
        $this->assertStringContainsString('Do not pay', $retry);
        $this->assertStringNotContainsString('provider-secret', $retry);
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

    private function nativeTransaction(string $paymentNumber): PakasirQrisTransaction
    {
        return PakasirQrisTransaction::fromPayload([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => 'PKS-QRIS-103',
                'amount' => 125000,
                'fee' => 875,
                'total_payment' => 125875,
                'payment_method' => 'qris',
                'payment_number' => $paymentNumber,
                'expired_at' => '2026-07-04T08:45:00+00:00',
            ],
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

    private function assertManualActions(TelegramPaymentInstruction $instruction, Order $order, string $locale): void
    {
        $rows = $instruction->replyMarkup['inline_keyboard'] ?? [];
        $buttons = array_merge(...$rows);
        $expectedLabels = match ($locale) {
            'en' => ['paid' => '✅ I\'ve Paid', 'cancel' => '❌ Cancel Order'],
            'ms' => ['paid' => '✅ Saya Sudah Bayar', 'cancel' => '❌ Batalkan Pesanan'],
            default => ['paid' => '✅ Saya Sudah Bayar', 'cancel' => '❌ Batalkan Order'],
        };

        $this->assertTrue(
            $this->hasButton($buttons, fn (array $button): bool => ($button['callback_data'] ?? null) === 'manual:paid:'.$order->id
                && ($button['text'] ?? null) === $expectedLabels['paid']),
            'Expected a localized manual paid button.',
        );
        $this->assertTrue(
            $this->hasButton($buttons, fn (array $button): bool => ($button['callback_data'] ?? null) === 'buyer:cancel-pending:'.$order->id
                && ($button['text'] ?? null) === $expectedLabels['cancel']),
            'Expected a localized manual cancel button.',
        );
    }

    private function assertPakasirActions(TelegramPaymentInstruction $instruction, Order $order, string $paymentUrl, string $locale): void
    {
        $rows = $instruction->replyMarkup['inline_keyboard'] ?? [];
        $buttons = array_merge(...$rows);
        $expectedLabels = match ($locale) {
            'en' => ['website' => '💳 Pay via Website', 'check' => '✅ Check Payment', 'cancel' => '❌ Cancel Order'],
            'ms' => ['website' => '💳 Bayar melalui Laman Web', 'check' => '✅ Semak Bayaran', 'cancel' => '❌ Batalkan Pesanan'],
            default => ['website' => '💳 Bayar via Website', 'check' => '✅ Cek Pembayaran', 'cancel' => '❌ Batalkan Order'],
        };

        $this->assertTrue(
            $this->hasButton($buttons, fn (array $button): bool => ($button['url'] ?? null) === $paymentUrl
                && ($button['text'] ?? null) === $expectedLabels['website']),
            'Expected a localized Pakasir website payment button.',
        );
        $this->assertTrue(
            $this->hasButton($buttons, fn (array $button): bool => ($button['callback_data'] ?? null) === 'order:'.$order->invoice_number
                && ($button['text'] ?? null) === $expectedLabels['check']),
            'Expected a localized check-payment button that refreshes the order detail.',
        );
        $this->assertTrue(
            $this->hasButton($buttons, fn (array $button): bool => ($button['callback_data'] ?? null) === 'buyer:cancel-pending:'.$order->id
                && ($button['text'] ?? null) === $expectedLabels['cancel']),
            'Expected a localized buyer cancel-pending button.',
        );
    }

    /**
     * @param  list<array{text?: string, url?: string, callback_data?: string}>  $buttons
     */
    private function hasButton(array $buttons, callable $predicate): bool
    {
        foreach ($buttons as $button) {
            if ($predicate($button)) {
                return true;
            }
        }

        return false;
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
