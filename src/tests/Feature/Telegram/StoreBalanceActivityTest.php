<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\Order;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Services\StoreBalance\StoreBalanceActivityCursor;
use App\Services\StoreBalance\StoreBalanceActivityService;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\TelegramBalanceActivityToken;
use App\Services\Telegram\TelegramNavigationRootService;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceActivityTest extends TelegramBotTestSupport
{
    public function test_activity_page_keeps_top_up_state_and_immutable_ledger_effects_distinct(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $customer = Customer::factory()->create(['telegram_id' => 46301, 'locale' => 'en']);
        $pending = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Pending, 'created', now()->subMinutes(4));
        $paid = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Paid, 'provider_SUCCESS', now()->subMinutes(3));
        $expired = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Expired, 'provider_expired', now()->subMinutes(2));

        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 5000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', (string) $paid->id, 'balance_top_up:'.$paid->id.':credit');
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-ACTIVITY-46301',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 2000,
        ]);
        $ledger->debit($customer, 2000, 'order', (string) $order->id, 'order:'.$order->id.':debit');
        $ledger->refund($customer, 2000, 'order', (string) $order->id, 'order:'.$order->id.':refund');

        $page = app(StoreBalanceActivityService::class)->firstPage($customer);

        $this->assertCount(5, $page->items);
        $this->assertSame(5000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
        $this->assertSame(1, count(array_filter($page->items, fn ($item) => $item->reference === '#'.$paid->id)));
        $this->assertSame(1, count(array_filter($page->items, fn ($item) => $item->reference === '#'.$pending->id)));
        $this->assertSame(1, count(array_filter($page->items, fn ($item) => $item->reference === '#'.$expired->id)));

        $paidItem = collect($page->items)->first(fn ($item) => $item->reference === '#'.$paid->id);
        $pendingItem = collect($page->items)->first(fn ($item) => $item->reference === '#'.$pending->id);
        $refundItem = collect($page->items)->first(fn ($item) => $item->kind === 'refund');
        $debitItem = collect($page->items)->first(fn ($item) => $item->kind === 'purchase');
        $this->assertSame('credited', $paidItem->state);
        $this->assertSame(5000, $paidItem->signedAmount);
        $this->assertSame('pending', $pendingItem->state);
        $this->assertNull($pendingItem->signedAmount);
        $this->assertSame(2000, $refundItem->signedAmount);
        $this->assertSame(-2000, $debitItem->signedAmount);
        $this->assertSame('#INV-ACTIVITY-46301', $refundItem->reference);
        $this->assertSame($debitItem->id, $refundItem->relatedEntryId);

        $paidDetail = app(StoreBalanceActivityService::class)->detail($customer, StoreBalanceActivityCursor::TYPE_TOP_UP, $paid->id);
        $pendingDetail = app(StoreBalanceActivityService::class)->detail($customer, StoreBalanceActivityCursor::TYPE_TOP_UP, $pending->id);
        $this->assertSame(5000, $paidDetail?->item->signedAmount);
        $this->assertNull($pendingDetail?->item->signedAmount);
        $this->assertNull(app(StoreBalanceActivityService::class)->detail(Customer::factory()->create(), StoreBalanceActivityCursor::TYPE_TOP_UP, $paid->id));
    }

    public function test_older_pages_do_not_resurface_settled_top_up_ledger_entries(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $customer = Customer::factory()->create(['telegram_id' => 46302, 'locale' => 'en']);
        $ledger = app(StoreBalanceLedger::class);

        for ($index = 1; $index <= 11; $index++) {
            $topUp = $this->makeTopUp(
                $customer,
                StoreBalanceTopUpStatus::Paid,
                'provider_SUCCESS',
                now()->subMinutes(10 + $index),
            );
            $ledger->credit(
                $customer,
                5000,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                (string) $topUp->id,
                'settled-top-up:'.$index,
            );
        }

        $firstPage = app(StoreBalanceActivityService::class)->firstPage($customer);
        $this->assertCount(10, $firstPage->items);
        $this->assertNotNull($firstPage->olderCursor);

        $olderPage = app(StoreBalanceActivityService::class)->olderPage($customer, $firstPage->olderCursor);

        $this->assertSame('#11', $olderPage->items[0]->reference);
        $this->assertSame('credited', $olderPage->items[0]->state);
    }

    public function test_equal_timestamps_keep_same_second_rows_reachable_in_both_directions(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $customer = Customer::factory()->create(['telegram_id' => 46303, 'locale' => 'en']);
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 1000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', 'orphan', 'equal:orphan');

        $topUp = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Paid, 'provider_SUCCESS', now());
        $ledger->credit(
            $customer,
            5000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            (string) $topUp->id,
            'equal:top-up',
        );
        $order = Order::factory()->for($customer)->create(['total_amount' => 1000]);
        $ledger->debit($customer, 1000, 'order', (string) $order->id, 'equal:debit');

        $page = app(StoreBalanceActivityService::class)->firstPage($customer);
        $topUpItem = collect($page->items)->first(fn ($item) => $item->isTopUp());
        $debitItem = collect($page->items)->first(fn ($item) => $item->kind === 'purchase');
        $this->assertNotNull($topUpItem);
        $this->assertNotNull($debitItem);

        // Entries rank newer than a top-up row inside one timestamp, so the
        // rows above the top-up are the same-second entries that are not
        // already presented by that top-up row itself.
        $newerFromTopUp = app(StoreBalanceActivityService::class)->newerPage($customer, $topUpItem->cursor());
        $this->assertCount(2, $newerFromTopUp->items);
        $this->assertFalse(collect($newerFromTopUp->items)->contains(fn ($item) => $item->isTopUp()));

        // Walking older from an entry still reaches the lower-ranked top-up
        // row of the same timestamp exactly once.
        $olderFromDebit = app(StoreBalanceActivityService::class)->olderPage($customer, $debitItem->cursor());
        $this->assertTrue(collect($olderFromDebit->items)->contains(fn ($item) => $item->isTopUp()));
        $topUps = collect($olderFromDebit->items)->filter(fn ($item) => $item->isTopUp());
        $this->assertCount(1, $topUps);
    }

    public function test_same_second_top_up_row_at_a_page_boundary_is_never_lost(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $customer = Customer::factory()->create(['telegram_id' => 46304, 'locale' => 'en']);
        $account = CustomerBalanceAccount::query()->create(['customer_id' => $customer->id, 'balance' => 0, 'version' => 0]);
        $at = now()->startOfSecond();
        foreach (range(1, StoreBalanceActivityService::PAGE_SIZE) as $index) {
            StoreBalanceEntry::query()->create([
                'customer_balance_account_id' => $account->id,
                'kind' => StoreBalanceEntryKind::TopUpCredit,
                'amount' => 1000,
                'balance_after' => $index * 1000,
                'source_type' => 'manual_test',
                'source_id' => 'boundary:'.$index,
                'idempotency_key' => 'boundary:'.$index,
                'created_at' => $at,
            ]);
        }
        $topUp = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Pending, 'created', $at);

        $service = app(StoreBalanceActivityService::class);
        $firstPage = $service->firstPage($customer);
        $this->assertCount(StoreBalanceActivityService::PAGE_SIZE, $firstPage->items);
        $this->assertNotNull($firstPage->olderCursor);
        $this->assertFalse(collect($firstPage->items)->contains(fn ($item) => $item->isTopUp()));

        $olderPage = $service->olderPage($customer, $firstPage->olderCursor);
        $this->assertNotNull($olderPage);
        $topUpItems = collect($olderPage->items)->filter(fn ($item) => $item->isTopUp());
        $this->assertCount(1, $topUpItems, 'The same-second top-up row beyond the page boundary must be reachable.');
        $this->assertSame('#'.$topUp->id, $topUpItems->first()->reference);

        $back = $service->newerPage($customer, $olderPage->newerCursor);
        $this->assertNotNull($back);
        $this->assertSame(
            array_map(static fn ($item): string => $item->type.':'.$item->id, $firstPage->items),
            array_map(static fn ($item): string => $item->type.':'.$item->id, $back->items),
        );
    }

    public function test_paid_top_up_without_an_exact_credit_is_support_review_without_available_amount(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $customer = Customer::factory()->create(['telegram_id' => 46304, 'locale' => 'en']);
        $topUp = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Paid, 'provider_SUCCESS', now());
        app(StoreBalanceLedger::class)->credit(
            $customer,
            4000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            (string) $topUp->id,
            'mismatched-credit',
        );

        $item = app(StoreBalanceActivityService::class)->firstPage($customer)->items[0];

        $this->assertSame('review', $item->state);
        $this->assertNull($item->signedAmount);
        $this->assertSame(5000, $item->requestedAmount);
    }

    public function test_unknown_top_up_provider_state_fails_closed_without_customer_actions(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $customer = Customer::factory()->create(['telegram_id' => 46305, 'locale' => 'en']);
        $this->makeTopUp($customer, StoreBalanceTopUpStatus::Pending, 'provider_state_renamed', now());

        $item = app(StoreBalanceActivityService::class)->firstPage($customer)->items[0];

        $this->assertSame('review', $item->state);
        $this->assertNull($item->signedAmount);
        $this->assertFalse($item->topUpCanCheck);
        $this->assertFalse($item->topUpCanRetry);
    }

    public function test_wallet_history_reveals_the_purchase_hidden_by_the_recent_activity_preview(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 46310, 'locale' => 'id']);
        $topUp = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Paid, 'provider_SUCCESS', now());
        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit($customer, 5000, StoreBalanceEntryKind::TopUpCredit, 'balance_top_up', (string) $topUp->id, 'wallet-history:credit');
        $orders = [];
        foreach (range(1, 5) as $index) {
            $this->travel(1)->minutes();
            $order = Order::factory()->for($customer)->create([
                'invoice_number' => 'INV-WALLET-HISTORY-'.$index,
                'order_status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
                'total_amount' => 1000,
            ]);
            $ledger->debit($customer, 1000, 'order', (string) $order->id, 'wallet-history:debit:'.$index);
            $orders[] = $order;
        }

        $this->postTelegramWebhook($this->messageUpdate(46300, '/start', [], 46310))->assertOk();
        $root = app(TelegramNavigationRootService::class)->currentForChat($customer, '46310');
        $this->postTelegramWebhook($this->callbackUpdate(
            46301, 'wallet-preview', 'balance', [], 46310, 'private', $root->message_id,
        ))->assertOk();
        $overview = $this->lastCanonicalEdit(46310);
        $buttons = collect($overview['reply_markup']['inline_keyboard'])->flatten(1);
        $history = $buttons->firstWhere('callback_data', 'balance:activity');
        $this->assertNotNull($history, 'The abbreviated wallet must offer access to the full history.');

        $entryCount = StoreBalanceEntry::query()->count();
        $this->postTelegramWebhook($this->callbackUpdate(
            46302, 'wallet-history', $history['callback_data'], [], 46310, 'private', $root->message_id,
        ))->assertOk();

        $copy = $this->editCopy($this->lastCanonicalEdit(46310));
        foreach ($orders as $order) {
            $this->assertStringContainsString('#'.$order->invoice_number, $copy);
        }
        $this->assertStringContainsString('Referensi: #'.$topUp->id, $copy);
        $this->assertSame(0, $ledger->snapshot($customer)->balance());
        $this->assertSame($entryCount, StoreBalanceEntry::query()->count());
    }

    public function test_activity_callbacks_use_cursors_and_reject_foreign_or_tampered_tokens(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 46311, 'locale' => 'en']);
        $ledger = app(StoreBalanceLedger::class);
        for ($index = 1; $index <= 11; $index++) {
            $ledger->credit(
                $customer,
                1000,
                StoreBalanceEntryKind::TopUpCredit,
                'balance_top_up',
                'orphan-'.$index,
                'activity-orphan:'.$index,
            );
        }

        $this->postTelegramWebhook($this->messageUpdate(46311, '/start', [], 46311))->assertOk();
        $root = app(TelegramNavigationRootService::class)->currentForChat($customer, '46311');
        $this->assertNotNull($root);
        $this->postTelegramWebhook($this->callbackUpdate(
            46312,
            'activity-open',
            'balance:activity',
            [],
            46311,
            'private',
            $root->message_id,
        ))->assertOk();

        $root->refresh();
        $firstEdit = $this->lastCanonicalEdit(46311);
        $firstKeyboard = $firstEdit['reply_markup']['inline_keyboard'];
        foreach (range(2, 11) as $reference) {
            $this->assertMatchesRegularExpression(
                '/Reference: #'.$reference.'(?:\n|$)/',
                $this->editCopy($firstEdit),
            );
        }
        $this->assertSame($root->message_id, (int) $firstEdit['message_id']);
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '46311')->count());
        $detailCallback = $firstKeyboard[2][0]['callback_data'];
        $pagingCallback = $firstKeyboard[array_key_last($firstKeyboard)][0]['callback_data'];
        $this->assertStringStartsWith('balance:a:o:', $pagingCallback);
        $this->assertLessThanOrEqual(64, strlen($pagingCallback));

        $this->postTelegramWebhook($this->callbackUpdate(
            46313,
            'activity-older',
            $pagingCallback,
            [],
            46311,
            'private',
            $root->message_id,
        ))->assertOk();
        $olderEdit = $this->lastCanonicalEdit(46311);
        $this->assertStringContainsString('#1', $this->editCopy($olderEdit));
        $this->assertStringNotContainsString('#11', $this->editCopy($olderEdit));

        $newerCallback = null;
        foreach ($olderEdit['reply_markup']['inline_keyboard'] as $row) {
            foreach ($row as $button) {
                if (str_starts_with((string) ($button['callback_data'] ?? ''), 'balance:a:n:')) {
                    $newerCallback = $button['callback_data'];
                }
            }
        }
        $this->assertNotNull($newerCallback);
        $this->postTelegramWebhook($this->callbackUpdate(
            46314,
            'activity-newer',
            $newerCallback,
            [],
            46311,
            'private',
            $root->message_id,
        ))->assertOk();
        $newerEdit = $this->lastCanonicalEdit(46311);
        $this->assertStringContainsString('#11', $this->editCopy($newerEdit));
        $this->assertDoesNotMatchRegularExpression('/Reference: #1(?:\n|$)/', $this->editCopy($newerEdit));

        $this->postTelegramWebhook($this->callbackUpdate(
            46315,
            'activity-detail',
            $detailCallback,
            [],
            46311,
            'private',
            $root->message_id,
        ))->assertOk();
        $detailEdit = $this->lastCanonicalEdit(46311);
        $this->assertStringContainsString('Store Balance activity details', $this->editCopy($detailEdit));
        $this->assertStringContainsString('#11', $this->editCopy($detailEdit));

        $foreign = Customer::factory()->create(['telegram_id' => 46312, 'locale' => 'en']);
        $this->postTelegramWebhook($this->messageUpdate(46316, '/start', [], 46312))->assertOk();
        $foreignRoot = app(TelegramNavigationRootService::class)->currentForChat($foreign, '46312');
        $this->assertNotNull($foreignRoot);
        $this->postTelegramWebhook($this->callbackUpdate(
            46317,
            'activity-foreign',
            $detailCallback,
            [],
            46312,
            'private',
            $foreignRoot->message_id,
        ))->assertOk();
        $foreignEdit = $this->lastCanonicalEdit(46312);
        $this->assertStringNotContainsString('#6', $this->editCopy($foreignEdit));
        $this->assertStringContainsString('No Store Balance activity yet.', $this->editCopy($foreignEdit));

        $tampered = substr($detailCallback, 0, -1).(str_ends_with($detailCallback, 'A') ? 'B' : 'A');
        $this->postTelegramWebhook($this->callbackUpdate(
            46318,
            'activity-tampered',
            $tampered,
            [],
            46311,
            'private',
            $root->message_id,
        ))->assertOk();
        $staleEdit = $this->lastCanonicalEdit(46311);
        $this->assertStringContainsString('This activity page is out of date.', $this->editCopy($staleEdit));
    }

    public function test_projection_mismatch_hides_activity_amounts_and_top_up_actions(): void
    {
        $this->fakeCanonicalTelegram();
        $customer = Customer::factory()->create(['telegram_id' => 46322, 'locale' => 'en']);
        app(StoreBalanceLedger::class)->credit(
            $customer,
            10000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            'mismatch',
            'activity-mismatch',
        );
        CustomerBalanceAccount::query()
            ->where('customer_id', $customer->id)
            ->update(['balance' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(46322, '/start', [], 46322))->assertOk();
        $root = app(TelegramNavigationRootService::class)->currentForChat($customer, '46322');
        $this->assertNotNull($root);
        $this->postTelegramWebhook($this->callbackUpdate(
            46323,
            'activity-mismatch',
            'balance',
            [],
            46322,
            'private',
            $root->message_id,
        ))->assertOk();

        $edit = $this->lastCanonicalEdit(46322);
        $caption = $this->editCopy($edit);
        $this->assertStringContainsString('Activity is temporarily hidden while we verify the balance.', $caption);
        $this->assertStringContainsString('If this continues, open Support.', $caption);
        $this->assertStringNotContainsString('Rp', $caption);
        $this->assertStringNotContainsString('balance:topup:', json_encode($edit['reply_markup'], JSON_THROW_ON_ERROR));

        $entry = StoreBalanceEntry::query()->sole();
        $detailToken = app(TelegramBalanceActivityToken::class)->detailToken(
            $customer->id,
            StoreBalanceActivityCursor::TYPE_ENTRY,
            $entry->id,
        );
        $this->postTelegramWebhook($this->callbackUpdate(
            46324,
            'activity-mismatch-detail',
            'balance:a:d:'.$detailToken,
            [],
            46322,
            'private',
            $root->message_id,
        ))->assertOk();

        $detailEdit = $this->lastCanonicalEdit(46322);
        $detailCaption = $this->editCopy($detailEdit);
        $this->assertStringContainsString('Activity is temporarily hidden while we verify the balance.', $detailCaption);
        $this->assertStringNotContainsString('Store Balance activity details', $detailCaption);
    }

    public function test_paid_webhook_and_refund_activity_keep_exact_balance_and_recovered_state(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $this->fakeTelegramHttp([
            'https://klikqris.com/api/sandbox/qris/status/*' => function (Request $request) {
                return Http::response(['data' => [
                    'order_id' => basename($request->url()),
                    'amount' => 5000,
                    'total_amount' => 5016,
                    'status' => 'SUCCESS',
                    'signature' => 'top-up-signature',
                    'paid_at' => now()->toISOString(),
                    'expired_at' => now()->addMinutes(15)->toISOString(),
                ]]);
            },
        ]);
        $customer = Customer::factory()->create(['telegram_id' => 46321, 'locale' => 'en']);
        $topUp = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Payable, 'created', now()->subMinutes(2));

        $this->postJson('/api/webhook/klikqris/sandbox', [
            'order_id' => $topUp->merchant_ref,
            'amount' => 5000,
            'total_amount' => 5016,
            'status' => 'SUCCESS',
            'signature' => 'top-up-signature',
        ])->assertOk();

        $topUp = $topUp->fresh();
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->status);
        $recoveredTopUp = $this->makeTopUp($customer, StoreBalanceTopUpStatus::Paid, 'reconciled_SUCCESS', now()->subMinute());

        $ledger = app(StoreBalanceLedger::class);
        $ledger->credit(
            $customer,
            5000,
            StoreBalanceEntryKind::TopUpCredit,
            'balance_top_up',
            (string) $recoveredTopUp->id,
            'balance_top_up:'.$recoveredTopUp->id.':credit',
        );
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-WEBHOOK-46321',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 3000,
        ]);
        $ledger->debit($customer, 3000, 'order', (string) $order->id, 'order:'.$order->id.':debit');
        $ledger->refund($customer, 3000, 'order', (string) $order->id, 'order:'.$order->id.':refund');
        $unresolved = $this->makeTopUp($customer, StoreBalanceTopUpStatus::RecoveryPending, 'outcome_unknown', now()->addMinute());

        $page = app(StoreBalanceActivityService::class)->firstPage($customer);
        $recovered = collect($page->items)->first(fn ($item) => $item->reference === '#'.$recoveredTopUp->id);
        $refund = collect($page->items)->first(fn ($item) => $item->kind === 'refund');
        $debit = collect($page->items)->first(fn ($item) => $item->kind === 'purchase');
        $pending = collect($page->items)->first(fn ($item) => $item->reference === '#'.$unresolved->id);
        $this->assertSame('credited', collect($page->items)->first(fn ($item) => $item->reference === '#'.$topUp->id)->state);
        $this->assertSame('recovered', $recovered->state);
        $this->assertSame(5000, $recovered->signedAmount);
        $this->assertSame(3000, $refund->signedAmount);
        $this->assertSame(-3000, $debit->signedAmount);
        $this->assertSame('uncertain', $pending->state);
        $this->assertNull($pending->signedAmount);
        $this->assertSame(10000, app(StoreBalanceLedger::class)->snapshot($customer)->balance());
    }

    private function makeTopUp(
        Customer $customer,
        StoreBalanceTopUpStatus $status,
        string $providerCreateState,
        CarbonInterface $createdAt,
    ): StoreBalanceTopUp {
        $expiresAt = now()->addMinutes(15)->startOfSecond();
        $merchantRef = 'BALANCE-'.Str::upper(Str::random(12));
        $intent = StoreBalanceFundingIntent::query()->create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 5000,
            'nonce' => Str::random(32),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => $expiresAt,
            'claimed_at' => now(),
        ]);
        $topUp = StoreBalanceTopUp::query()->create([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => 1,
            'locale' => 'en',
            'provider' => 'klikqris',
            'merchant_ref' => $merchantRef,
            'idempotency_key' => 'top-up-'.$merchantRef,
            'currency' => 'IDR',
            'amount' => 5000,
            'status' => $status,
            'provider_reference' => null,
            'provider_payload' => [
                'data' => [
                    'order_id' => $merchantRef,
                    'amount' => 5000,
                    'total_amount' => 5016,
                    'status' => 'PENDING',
                ],
            ],
            'provider_qris_url' => 'https://klikqris.com/storage/sandbox/activity.png',
            'provider_fee' => 16,
            'provider_total_payment' => 5016,
            'provider_payment_method' => 'qris',
            'provider_created_at' => $createdAt,
            'provider_expires_at' => $expiresAt,
            'expires_at' => $expiresAt,
            'provider_signature' => 'top-up-signature',
            'provider_create_state' => $providerCreateState,
            'provider_create_attempted_at' => $createdAt,
        ]);
        $topUp->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $topUp->fresh();
    }

    private function fakeCanonicalTelegram(): void
    {
        $nextMessageId = 700;
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => $nextMessageId, 'photo' => [['file_id' => 'banner-'.$nextMessageId]]],
                ]);
            },
            'https://api.telegram.org/*/sendMessage' => function () use (&$nextMessageId) {
                $nextMessageId++;

                return Http::response(['ok' => true, 'result' => ['message_id' => $nextMessageId]]);
            },
            'https://api.telegram.org/*/editMessageCaption' => Http::response(['ok' => true]),
            'https://api.telegram.org/*/editMessageText' => Http::response(['ok' => true]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
    }

    private function lastCanonicalEdit(int $chatId): array
    {
        $edits = Http::recorded(fn (Request $request): bool => (str_ends_with($request->url(), '/editMessageCaption')
                || str_ends_with($request->url(), '/editMessageText')
                || str_ends_with($request->url(), '/sendMessage'))
            && (string) ($request['chat_id'] ?? '') === (string) $chatId);
        $this->assertNotEmpty($edits, "Expected a canonical message for chat {$chatId}.");

        [$request, $response] = $edits->last();

        return [
            ...$request->data(),
            'message_id' => $request['message_id'] ?? $response->json('result.message_id'),
        ];
    }

    private function editCopy(array $edit): string
    {
        return (string) ($edit['caption'] ?? $edit['text'] ?? '');
    }
}
