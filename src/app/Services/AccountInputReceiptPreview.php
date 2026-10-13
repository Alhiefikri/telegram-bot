<?php

namespace App\Services;

use App\Models\Product;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramReceiptPlan;
use App\Services\Telegram\TelegramUiIcons;

/**
 * Produces credential-free metadata for the Account Input review surface.
 *
 * Plaintext fields are used only to measure the private receipt in process;
 * this object never returns them or a rendered receipt.
 */
final readonly class AccountInputReceiptPreview
{
    public function __construct(
        private TelegramLocale $locale,
        private TelegramUiIcons $uiIcons,
        private CustomEmojiCapability $customEmoji,
    ) {}

    /**
     * @param  list<StructuredCredentialPayload>  $accounts
     * @return array<string, mixed>
     */
    public function metadata(Product $product, array $accounts, ?string $buyerLocale): array
    {
        $locale = $this->locale->codeForLocale($buyerLocale);
        $note = trim((string) $product->delivery_note);
        $guideTitle = trim((string) $product->usage_guide_title);
        $guideUrl = trim((string) $product->usage_guide_url);
        $lines = [
            $this->locale->translateLocale($locale, 'receipt.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translateLocale($locale, 'receipt.order_id', ['order' => '[ORDER ID]']),
            $this->locale->translateLocale($locale, 'receipt.products'),
            '- '.$product->name.' x '.$this->accountQuantity($accounts),
            $this->locale->translateLocale($locale, 'receipt.payment_method', ['method' => '[PAYMENT METHOD]']),
            $this->locale->translateLocale($locale, 'receipt.paid_at', ['date' => '[PAID DATE]']),
            $this->locale->translateLocale($locale, 'receipt.total_quantity', ['quantity' => $this->accountQuantity($accounts)]),
            $this->locale->translateLocale($locale, 'receipt.amount_paid', ['amount' => '[TOTAL]']),
            $this->locale->translateLocale($locale, 'receipt.product_detail'),
        ];

        foreach ($accounts as $index => $account) {
            $lines[] = $this->locale->translateLocale($locale, 'receipt.account_label', ['number' => $index + 1]);
            $lines[] = 'email : '.$account->email;
            $lines[] = 'password : '.$account->password;
            if ($account->accessMethod->value === 'otp_mail_link') {
                $lines[] = $this->locale->translateLocale($locale, 'receipt.access_otp', ['domain' => $account->otpMailDomain() ?? '[OTP DOMAIN]']);
            }
            if ($account->accessMethod->value === 'auth_token') {
                $lines[] = 'Auth Token : '.$account->accessValue;
            }
        }

        if ($note !== '') {
            $lines[] = $this->locale->translateLocale($locale, 'receipt.delivery_note');
            $lines[] = $note;
        }
        if ($guideTitle !== '' && $guideUrl !== '') {
            $lines[] = $this->uiIcons->renderLegacyIcons('📘 ').$guideTitle;
        }
        $lines[] = $this->locale->translateLocale($locale, 'receipt.save_details');
        $lines[] = $this->locale->translateLocale($locale, 'receipt.copy_guidance');
        $lines[] = $this->locale->translateLocale($locale, 'receipt.thanks');

        $chunks = $this->pack($lines);
        $captionEstimate = mb_strlen($chunks[0] ?? '', 'UTF-8');
        $continuationCount = max(0, count($chunks) - 1);
        $warnings = [];
        if ($continuationCount > 0) {
            $warnings[] = 'photo_caption_split_required';
        }
        if (count($chunks) > 1 && mb_strlen($chunks[count($chunks) - 1], 'UTF-8') > TelegramReceiptPlan::MESSAGE_TEXT_LIMIT) {
            $warnings[] = 'continuation_text_split_required';
        }

        return [
            'buyer_locale' => $locale,
            'photo_caption_limit' => TelegramReceiptPlan::PHOTO_CAPTION_LIMIT,
            'continuation_text_limit' => TelegramReceiptPlan::MESSAGE_TEXT_LIMIT,
            'photo_caption_estimate' => $captionEstimate,
            'continuation_count_estimate' => $continuationCount,
            'warning_codes' => $warnings,
            // The dashboard renders these as plain text, so the internal icon
            // markers are stripped to their glyphs before they leave this seam.
            'preview' => [
                'locale' => $locale,
                'title' => $this->stripped($locale, 'receipt.title'),
                'order_id' => $this->stripped($locale, 'receipt.order_id', ['order' => '[ORDER ID]']),
                'payment_method' => $this->stripped($locale, 'receipt.payment_method', ['method' => '[PAYMENT METHOD]']),
                'paid_at' => $this->stripped($locale, 'receipt.paid_at', ['date' => '[PAID DATE]']),
                'amount_paid' => $this->stripped($locale, 'receipt.amount_paid', ['amount' => '[TOTAL]']),
                'product_detail' => $this->stripped($locale, 'receipt.product_detail'),
                'delivery_note_occurrences' => $note === '' ? 0 : 1,
                'usage_guide_occurrences' => $guideTitle !== '' && $guideUrl !== '' ? 1 : 0,
            ],
        ];
    }

    /** @param array<string, scalar|null> $replace */
    private function stripped(string $locale, string $key, array $replace = []): string
    {
        return $this->customEmoji->stripTextCustomEmoji($this->locale->translateLocale($locale, $key, $replace));
    }

    /** @param list<StructuredCredentialPayload> $accounts */
    private function accountQuantity(array $accounts): int
    {
        return max(1, count($accounts));
    }

    /** @param list<string> $lines @return list<string> */
    private function pack(array $lines): array
    {
        $chunks = [];
        $current = '';
        $limit = TelegramReceiptPlan::PHOTO_CAPTION_LIMIT;
        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current."\n".$line;
            if ($current !== '' && mb_strlen($candidate, 'UTF-8') > $limit) {
                $chunks[] = $current;
                $current = $line;
                $limit = TelegramReceiptPlan::MESSAGE_TEXT_LIMIT;

                continue;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
