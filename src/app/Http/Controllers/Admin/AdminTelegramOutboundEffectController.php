<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

class AdminTelegramOutboundEffectController extends Controller
{
    public function index(TelegramOutboundEffectService $effects): View
    {
        Gate::authorize('viewAny', TelegramOutboundEffect::class);

        $reviews = $effects->reviewQuery()->paginate(20);
        $reviews->getCollection()->transform(
            fn (TelegramOutboundEffect $effect): array => $effects->reviewSummary($effect),
        );

        return view('admin.telegram-outbound-effects.index', compact('reviews'));
    }

    public function show(TelegramOutboundEffect $effect, TelegramOutboundEffectService $effects): View
    {
        Gate::authorize('view', $effect);

        return view('admin.telegram-outbound-effects.show', [
            'effect' => $effect,
            'review' => $effects->reviewSummary($effect),
        ]);
    }

    public function markSent(Request $request, TelegramOutboundEffect $effect, TelegramOutboundEffectService $effects): RedirectResponse
    {
        Gate::authorize('markSent', $effect);

        $data = $request->validate([
            'telegram_message_id' => ['required', 'string', 'max:64', 'regex:/^[0-9]+$/'],
        ]);

        /** @var User $actor */
        $actor = $request->attributes->get('adminUser');
        $marked = $effects->markSent($effect, (string) $data['telegram_message_id'], $actor, $request->ip());

        if (! $marked) {
            return back()->withErrors(['effect' => 'Effect sudah tidak berada dalam antrean review.']);
        }

        return redirect()
            ->route('admin.telegram-outbound-effects.show', $effect)
            ->with('status', 'Effect ditandai sent tanpa mengirim request Telegram.');
    }

    public function resend(Request $request, TelegramOutboundEffect $effect, TelegramOutboundEffectService $effects): RedirectResponse
    {
        Gate::authorize('resend', $effect);

        $request->validate([
            'confirm_duplicate_risk' => ['accepted'],
        ]);

        /** @var User $actor */
        $actor = $request->attributes->get('adminUser');

        try {
            $resend = $effects->resend($effect, $actor, $request->ip());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['effect' => $exception->getMessage()]);
        }

        $effects->dispatchExplicitResend($resend);

        return redirect()
            ->route('admin.telegram-outbound-effects.show', $resend)
            ->with('status', 'Versi resend baru dibuat. Periksa duplicate-risk sebelum melanjutkan.');
    }
}
