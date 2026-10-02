<?php

namespace App\Services\Admin;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

final class SupplierOperationConfirmation
{
    private const CONFIRMATION_WINDOW_SECONDS = 900;

    public function isRecent(Request $request): bool
    {
        $confirmed = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmed > 0
            && $confirmed <= time()
            && time() - $confirmed <= self::CONFIRMATION_WINDOW_SECONDS;
    }

    public function requireRecent(Request $request): void
    {
        if ($this->isRecent($request)) {
            return;
        }

        $request->session()->put('url.intended', app(AdminSupplierRedirector::class)->contextUrl($request));

        if ($request->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Konfirmasi password dahulu, lalu masukkan ulang data rahasia.',
                'confirmation_url' => route('password.confirm'),
            ], 428));
        }

        throw new HttpResponseException(
            redirect()
                ->route('password.confirm')
                ->with('status', 'Sesi konfirmasi password sudah berakhir. Konfirmasi password dulu, lalu isi ulang form.')
        );
    }
}
