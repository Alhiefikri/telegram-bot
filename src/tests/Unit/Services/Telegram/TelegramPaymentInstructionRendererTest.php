<?php

namespace Tests\Unit\Services\Telegram;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Services\Telegram\TelegramPaymentInstruction;
use App\Services\Telegram\TelegramPaymentInstructionRenderer;
use App\Services\Telegram\TelegramQrCodeRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramPaymentInstructionRendererTest extends TestCase
{
    use RefreshDatabase;

    private RecordingQrCodeRenderer $qrCodes;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Jakarta']);

        $this->qrCodes = new RecordingQrCodeRenderer;
        $this->instance(TelegramQrCodeRenderer::class, $this->qrCodes);
    }

    public function test_hosted_pakasir_instruction_uses_hosted_url_qr_shared_caption_pieces_and_website_button(): void
    {
        $expiresAt = CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta');
        $order = $this->orderWithItems($expiresAt);
        $payment = Payment::factory()->for($order)->create([
            'merchant_ref' => 'PKS-HOSTED-103',
            'amount' => 125000,
        ]);
        $paymentUrl = 'https://pakasir.example/pay/PKS-HOSTED-103?token=hosted-token';

        $instruction = $this->renderer()->hostedPakasir($order, $payment, $paymentUrl);

        $this->assertSame([$paymentUrl], $this->qrCodes->renderedContents);
        $this->assertSame('qr-png:'.$paymentUrl, $instruction->photoContents);
        $this->assertSame('pakasir-PKS-HOSTED-103.png', $instruction->filename);
        $this->assertSame([
            'inline_keyboard' => [[['text' => '💳 Bayar via Website', 'url' => $paymentUrl]]],
        ], $instruction->replyMarkup);
        $this->assertCaptionIncludes($instruction, [
            '🧾 Invoice: INV-RENDERER-103',
            '📦 Produk: Sourdough Starter x2, Kefir Kit',
            '🔢 Jumlah: 3',
            '💰 Total: Rp125.000',
            '⏳ Order aktif sampai: 04 Jul 2026 16:30 WIB',
        ]);
        $this->assertStringContainsString('📲 Scan QRIS di atas, atau tap tombol bayar untuk buka halaman pembayaran.', $instruction->caption());
    }

    public function test_native_qris_instruction_uses_payment_number_qr_payable_caption_pieces_and_website_fallback_button(): void
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
        $transaction = PakasirQrisTransaction::fromPayload([
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

        $instruction = $this->renderer()->nativePakasir($order, $payment, $transaction, $paymentUrl);

        $this->assertSame([$paymentNumber], $this->qrCodes->renderedContents);
        $this->assertSame('qr-png:'.$paymentNumber, $instruction->photoContents);
        $this->assertSame('pakasir-qris-PKS-QRIS-103.png', $instruction->filename);
        $this->assertSame([
            'inline_keyboard' => [[['text' => '💳 Bayar via Website', 'url' => $paymentUrl]]],
        ], $instruction->replyMarkup);
        $this->assertCaptionIncludes($instruction, [
            '🧾 Invoice: INV-RENDERER-103',
            '📦 Produk: Sourdough Starter x2, Kefir Kit',
            '🔢 Jumlah: 3',
            '💰 Total produk: Rp125.000',
            '🏷️ Biaya QRIS: Rp875',
            '💳 Total bayar QRIS: Rp125.875',
            '⏳ Batas bayar QRIS: 04 Jul 2026 15:45 WIB',
        ]);
        $this->assertStringContainsString('🌐 Kalau QR tidak bisa discan, tap tombol Bayar via Website sebagai fallback.', $instruction->caption());
    }

    public function test_manual_instruction_uses_manual_qr_file_proof_upload_caption_and_no_website_button(): void
    {
        $order = $this->orderWithItems(CarbonImmutable::parse('2026-07-04 16:30:00', 'Asia/Jakarta'));
        $manualQrContents = file_get_contents(public_path('qr-lhie.jpg'));
        $this->assertIsString($manualQrContents);

        $instruction = $this->renderer()->manual($order, [
            'label' => 'QR Manual BCA',
            'name' => 'Lhie Store QRIS',
        ], 'warung_support');

        $this->assertSame([], $this->qrCodes->renderedContents);
        $this->assertSame($manualQrContents, $instruction->photoContents);
        $this->assertSame('qr-lhie.jpg', $instruction->filename);
        $this->assertSame([], $instruction->replyMarkup);
        $this->assertCaptionIncludes($instruction, [
            '🧾 Invoice: INV-RENDERER-103',
            '💰 Total: Rp 125.000',
            '💳 Bayar via: QR Manual BCA',
            '• Nama QR: Lhie Store QRIS',
            '• Nominal wajib sama persis: Rp 125.000',
            '📦 Pesanan: Sourdough Starter x2, Kefir Kit',
            '/payment',
            'Kalau kesusahan, chat admin @warung_support.',
        ]);
        $this->assertStringContainsString('Setelah bayar, kirim screenshot bukti pembayaran ke bot ini sebagai FOTO dengan caption:', $instruction->caption());
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

    /**
     * @param list<string> $expectedLines
     */
    private function assertCaptionIncludes(TelegramPaymentInstruction $instruction, array $expectedLines): void
    {
        foreach ($expectedLines as $expectedLine) {
            $this->assertContains($expectedLine, $instruction->captionLines());
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
