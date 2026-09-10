<?php

namespace App\Services\Telegram;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationRenderOutcome;
use App\Exceptions\TelegramApiException;
use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owns the one recoverable, bot-owned navigation message per private customer
 * chat. Durable transactional artifacts are never tracked here.
 */
class TelegramNavigationRootService
{
    private const CLAIM_LEASE_SECONDS = 120;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramWelcomeBanner $banner,
    ) {}

    public function current(Customer $customer): ?TelegramNavigationRoot
    {
        return TelegramNavigationRoot::query()
            ->where('customer_id', $customer->id)
            ->first();
    }

    /**
     * Resolve the current root only when the callback really targets it.
     */
    public function match(Customer $customer, string $chatId, ?int $messageId): ?TelegramNavigationRoot
    {
        $chatId = trim($chatId);
        if ($messageId === null
            || $messageId < 1
            || $chatId === ''
            || (string) $customer->telegram_id !== $chatId) {
            return null;
        }

        $root = $this->current($customer);
        if ($root === null || $root->chat_id !== $chatId || $root->message_id !== $messageId) {
            return null;
        }

        return $root;
    }

    public function establish(Customer $customer, string $chatId, TelegramNavigationSurface $surface): TelegramNavigationRoot
    {
        $chatId = trim($chatId);
        $current = $this->current($customer);
        $preferredReference = $current?->chat_id === $chatId ? $current->banner_reference : null;
        $sent = $this->banner->send(
            $chatId,
            $surface->caption,
            $surface->keyboard,
            $preferredReference,
        );

        return $this->persistRoot($customer, $chatId, $surface, $sent);
    }

    public function render(TelegramNavigationRoot $root, string $chatId, TelegramNavigationSurface $surface): TelegramNavigationRenderOutcome
    {
        $chatId = trim($chatId);
        $claimed = $this->claim($root, $chatId, $surface);
        if ($claimed === null) {
            return TelegramNavigationRenderOutcome::Superseded;
        }

        try {
            $this->editRootMessage($claimed, $chatId, $surface);
        } catch (TelegramApiException $exception) {
            if ($exception->isMessageNotModified()) {
                return $this->finishClaim($claimed)
                    ? TelegramNavigationRenderOutcome::Unchanged
                    : TelegramNavigationRenderOutcome::Superseded;
            }

            if ($exception->isDefiniteEditFailure()) {
                return $this->replaceDefiniteFailure($claimed, $chatId, $surface);
            }

            $this->releaseClaim($claimed);
            $this->logAmbiguous($claimed, $exception);

            return TelegramNavigationRenderOutcome::Ambiguous;
        } catch (Throwable $exception) {
            $this->releaseClaim($claimed);
            $this->logAmbiguous($claimed, $exception);

            return TelegramNavigationRenderOutcome::Ambiguous;
        }

        return $this->finishClaim($claimed)
            ? TelegramNavigationRenderOutcome::Applied
            : TelegramNavigationRenderOutcome::Superseded;
    }

    private function persistRoot(Customer $customer, string $chatId, TelegramNavigationSurface $surface, TelegramWelcomeBannerSend $sent): TelegramNavigationRoot
    {
        try {
            return $this->persistRootOnce($customer, $chatId, $surface, $sent);
        } catch (UniqueConstraintViolationException) {
            // Keep the unique customer row as the final guard if a database
            // races a legacy deployment without the customer lock.
            return $this->persistRootOnce($customer, $chatId, $surface, $sent);
        }
    }

    private function persistRootOnce(Customer $customer, string $chatId, TelegramNavigationSurface $surface, TelegramWelcomeBannerSend $sent): TelegramNavigationRoot
    {
        return DB::transaction(function () use ($customer, $chatId, $surface, $sent): TelegramNavigationRoot {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $existing = TelegramNavigationRoot::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'chat_id' => $chatId,
                'message_id' => $sent->messageId,
                'media_kind' => $sent->mediaKind,
                'screen' => $surface->screen,
                'locale' => $surface->locale,
                'banner_reference' => $sent->reference,
                'render_token' => null,
                'render_started_at' => null,
                'rendered_at' => now(),
            ];

            if ($existing === null) {
                return TelegramNavigationRoot::query()->create([
                    'customer_id' => $customer->id,
                    ...$attributes,
                    'generation' => 1,
                ]);
            }

            $existing->update([
                ...$attributes,
                'generation' => $existing->generation + 1,
            ]);

            return $existing->refresh();
        });
    }

    private function claim(TelegramNavigationRoot $root, string $chatId, TelegramNavigationSurface $surface): ?TelegramNavigationRoot
    {
        $now = now();
        $token = (string) Str::uuid();
        $staleAt = $now->copy()->subSeconds(self::CLAIM_LEASE_SECONDS);

        $claimed = TelegramNavigationRoot::query()
            ->whereKey($root->id)
            ->where('customer_id', $root->customer_id)
            ->where('chat_id', $chatId)
            ->where('message_id', $root->message_id)
            ->where('generation', $root->generation)
            ->where(function ($query) use ($staleAt): void {
                $query
                    ->whereNull('render_token')
                    ->orWhereNull('render_started_at')
                    ->orWhere('render_started_at', '<=', $staleAt);
            })
            ->update([
                'screen' => $surface->screen,
                'locale' => $surface->locale,
                'generation' => $root->generation + 1,
                'render_token' => $token,
                'render_started_at' => $now,
                'updated_at' => $now,
            ]);

        return $claimed === 1
            ? $root->fresh()
            : null;
    }

    private function finishClaim(TelegramNavigationRoot $root): bool
    {
        return TelegramNavigationRoot::query()
            ->whereKey($root->id)
            ->where('generation', $root->generation)
            ->where('render_token', $root->render_token)
            ->update([
                'render_token' => null,
                'render_started_at' => null,
                'rendered_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    private function releaseClaim(TelegramNavigationRoot $root): void
    {
        TelegramNavigationRoot::query()
            ->whereKey($root->id)
            ->where('generation', $root->generation)
            ->where('render_token', $root->render_token)
            ->update([
                'render_token' => null,
                'render_started_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function replaceDefiniteFailure(TelegramNavigationRoot $root, string $chatId, TelegramNavigationSurface $surface): TelegramNavigationRenderOutcome
    {
        try {
            $sent = $this->banner->send($chatId, $surface->caption, $surface->keyboard, $root->banner_reference);
        } catch (Throwable $exception) {
            $this->releaseClaim($root);
            $this->logAmbiguous($root, $exception);

            return TelegramNavigationRenderOutcome::Ambiguous;
        }

        $replaced = TelegramNavigationRoot::query()
            ->whereKey($root->id)
            ->where('generation', $root->generation)
            ->where('render_token', $root->render_token)
            ->update([
                'chat_id' => $chatId,
                'message_id' => $sent->messageId,
                'media_kind' => $sent->mediaKind,
                'banner_reference' => $sent->reference,
                'screen' => $surface->screen,
                'locale' => $surface->locale,
                'generation' => $root->generation + 1,
                'render_token' => null,
                'render_started_at' => null,
                'rendered_at' => now(),
                'updated_at' => now(),
            ]) === 1;

        if (! $replaced) {
            Log::warning('telegram.navigation_root_replacement_superseded', [
                'customer_id' => $root->customer_id,
                'message_id' => $sent->messageId,
            ]);
        }

        return $replaced
            ? TelegramNavigationRenderOutcome::Replaced
            : TelegramNavigationRenderOutcome::Superseded;
    }

    private function editRootMessage(TelegramNavigationRoot $root, string $chatId, TelegramNavigationSurface $surface): void
    {
        $options = ['parse_mode' => 'HTML'];

        if ($root->media_kind === TelegramNavigationMediaKind::Photo) {
            $this->bot->editMessageCaption($chatId, $root->message_id, $surface->caption, $surface->keyboard, $options);

            return;
        }

        $this->bot->editMessageText($chatId, $root->message_id, $surface->caption, $surface->keyboard, $options);
    }

    private function logAmbiguous(TelegramNavigationRoot $root, Throwable $exception): void
    {
        Log::warning('telegram.navigation_root_ambiguous', [
            'customer_id' => $root->customer_id,
            'chat_id' => $root->chat_id,
            'message_id' => $root->message_id,
            'exception' => $exception::class,
        ]);
    }
}
