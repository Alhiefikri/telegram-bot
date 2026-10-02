<?php

namespace App\Services\Admin;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

final class SupplierOperationConfirmation
{
    public function requireRecent(Request $request): void
    {
        $confirmed = (int) $request->session()->get('auth.password_confirmed_at', 0);
        if ($confirmed <= 0 || $confirmed > time() || time() - $confirmed > 900) {
            $request->session()->put('url.intended', route('admin.dashboard', ['tab' => 'suppliers']));
            throw new HttpResponseException(response()->json(['message' => 'Konfirmasi password dahulu, lalu masukkan ulang data rahasia.', 'confirmation_url' => route('password.confirm')], 428));
        }
    }
}
