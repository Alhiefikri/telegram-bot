<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PaymentProviderNotReady;
use App\Exceptions\PaymentProviderSwitchConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSakurupiahChannelRequest;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\Admin\SakurupiahChannelSettings;
use Illuminate\Http\RedirectResponse;

final class AdminSakurupiahChannelController extends Controller
{
    public function __invoke(
        UpdateSakurupiahChannelRequest $request,
        SakurupiahChannelSettings $settings,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $validated = $request->validated();
        try {
            $changed = $settings->change(
                $validated['sakurupiah_channel'],
                (int) $validated['payment_provider_version'],
                $request->attributes->get('adminUser'),
                $request->ip(),
            );
        } catch (PaymentProviderNotReady|PaymentProviderSwitchConflict $exception) {
            return redirect()->route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran'])
                ->withErrors(['sakurupiah_channel' => $exception->getMessage()], 'sakurupiahChannel')->withInput();
        }

        return $redirector->paymentSettings()->with('status', $changed
            ? 'Channel Sakurupiah diperbarui untuk transaksi baru. Provider aktif dan transaksi lama tidak berubah.'
            : 'Channel Sakurupiah tidak berubah.');
    }
}
