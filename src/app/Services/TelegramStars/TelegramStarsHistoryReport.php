<?php

namespace App\Services\TelegramStars;

use App\Models\Payment;
use App\Models\PaymentTelegramStarsDetail;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class TelegramStarsHistoryReport
{
    public const PAGE_SIZE = 100;

    public const MAX_PAGES = 5;

    public function __construct(private TelegramBotClient $bot) {}

    public function scan(int $offset, int $pages): array
    {
        if (DB::transactionLevel() !== 0 || $pages < 1 || $pages > self::MAX_PAGES
            || $offset < 0 || $offset > PHP_INT_MAX - $pages * self::PAGE_SIZE) {
            throw new LogicException('Invalid Stars history scan boundary.');
        }
        $namespace = TelegramStarsChargeIdentity::configuredNamespace()
            ?? throw new LogicException('Stars bot identity is unavailable.');
        $rowsScanned = 0;
        $findings = [];
        for ($page = 0; $page < $pages; $page++) {
            $rows = $this->bot->getStarTransactions($offset, self::PAGE_SIZE);
            foreach ($rows as $index => $row) {
                $reason = $this->finding($row, $namespace);
                if ($reason !== null) {
                    // No bot token, payload, actor, or charge ID in the report.
                    $findings[] = ['offset' => $offset + $index, 'reason' => $reason];
                }
            }
            $rowsScanned += count($rows);
            if (count($rows) < self::PAGE_SIZE) {
                return ['scanned' => $rowsScanned, 'next_offset' => null, 'findings' => $findings];
            }
            $offset += self::PAGE_SIZE;
        }

        return ['scanned' => $rowsScanned, 'next_offset' => $offset, 'findings' => $findings];
    }

    private function finding(mixed $row, string $namespace): ?string
    {
        if (! is_array($row) || array_key_exists('source', $row) === array_key_exists('receiver', $row)) {
            return 'ambiguous_direction';
        }

        $incoming = array_key_exists('source', $row);
        $partner = $row[$incoming ? 'source' : 'receiver'];
        if (! is_array($partner) || ! is_string($partner['type'] ?? null)) {
            return 'incomplete_evidence';
        }
        if ($partner['type'] !== 'user') {
            return null; // Ads, Fragment withdrawals, affiliate income, etc.
        }
        $charge = $row['id'] ?? null;
        $identity = is_string($charge) && trim($charge) !== '' && strlen($charge) <= 256
            ? TelegramStarsChargeIdentity::incoming($namespace, TelegramStarsChargeIdentity::hash($charge))
            : null;

        if (! $incoming) {
            // Refund and original payment may share an ID. A debit is
            // never a matched credit or a refund confirmation in this lane.
            return ($partner['transaction_type'] ?? null) === 'invoice_payment'
                || ($identity !== null && DB::table('external_money_receipts')->where('canonical_key', $identity->canonicalKey())->exists())
                ? 'outgoing_invoice_review' : null;
        }
        if (! is_string($partner['transaction_type'] ?? null)) {
            return 'incomplete_evidence';
        }
        if ($partner['transaction_type'] !== 'invoice_payment') {
            return null;
        }

        $payload = $partner['invoice_payload'] ?? null;
        $buyer = $partner['user']['id'] ?? null;
        $amount = $row['amount'] ?? null;
        if ($identity === null || ! is_string($payload) || $payload === '' || strlen($payload) > 128 || str_contains($payload, "\0")
            || ! is_int($buyer) || $buyer < 1 || ! is_int($amount) || $amount < 1
            || (array_key_exists('nanostar_amount', $row) && $row['nanostar_amount'] !== 0)
            || array_key_exists('subscription_period', $partner)) {
            return 'incomplete_evidence';
        }

        $target = PaymentTelegramStarsDetail::where('invoice_payload', $payload)->first();
        if ($target === null) {
            return 'unknown_invoice';
        }
        if ($target->telegram_user_id !== $buyer || $target->total_xtr !== $amount) {
            return 'invoice_mismatch';
        }
        $local = DB::table('external_money_receipts as receipt')
            ->leftJoin('telegram_stars_settlements as settlement', 'receipt.id', '=', 'settlement.external_money_receipt_id')
            ->leftJoin('telegram_stars_payment_reviews as review', 'receipt.id', '=', 'review.external_money_receipt_id')
            ->where('receipt.canonical_key', $identity->canonicalKey())
            ->first(['settlement.payment_id', 'review.reason as review_reason', 'receipt.target_id']);

        return match (true) {
            $local === null => 'missing_settlement',
            $local->payment_id !== null && $local->payment_id !== $target->payment_id => 'charge_target_mismatch',
            $local->target_id !== (string) Payment::whereKey($target->payment_id)->value('order_id') => 'charge_target_mismatch',
            $local->review_reason !== null => 'charge_held_for_review',
            $local->payment_id === null => 'missing_settlement',
            default => null,
        };
    }
}
