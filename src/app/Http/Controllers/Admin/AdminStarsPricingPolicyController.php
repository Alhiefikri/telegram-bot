<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\StarsPricingPolicyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StarsPricingPolicyRequest;
use App\Models\User;
use App\Services\TelegramStars\StarsPricingPolicyManager;
use App\Services\TelegramStars\StarsQuoteCalculator;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AdminStarsPricingPolicyController extends Controller
{
    public function show(Request $request, StarsPricingPolicyManager $policies): View
    {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User && $actor->role?->canManageStarsPricing() === true, 403);

        return view('admin.stars-pricing.show', [
            'policy' => $policies->current(),
            'preview' => $request->session()->get('stars_pricing_preview'),
        ]);
    }

    public function preview(StarsPricingPolicyRequest $request, StarsQuoteCalculator $quotes): RedirectResponse
    {
        $proposal = $request->proposal();
        $totalIdr = $request->validated('total_idr');
        $redirect = to_route('admin.stars-pricing.show')
            ->withInput($proposal->values() + ['total_idr' => $totalIdr]);

        try {
            $totalXtr = $quotes->quote($totalIdr, $proposal);
        } catch (DomainException $exception) {
            return $redirect->withErrors(['total_idr' => $exception->getMessage()]);
        }

        return $redirect->with('stars_pricing_preview', ['total_idr' => $totalIdr, 'total_xtr' => $totalXtr]);
    }

    public function update(StarsPricingPolicyRequest $request, StarsPricingPolicyManager $policies): RedirectResponse
    {
        $actor = $request->attributes->get('adminUser');
        abort_unless($actor instanceof User, 403);

        try {
            $changed = $policies->change($actor, $request->proposal(), $request->ip());
        } catch (StarsPricingPolicyConflict $exception) {
            return to_route('admin.stars-pricing.show')->withErrors(['version' => $exception->getMessage()]);
        }

        return to_route('admin.stars-pricing.show')->with(
            'status',
            $changed ? 'Kebijakan harga Telegram Stars disimpan.' : 'Tidak ada perubahan kebijakan.',
        );
    }
}
