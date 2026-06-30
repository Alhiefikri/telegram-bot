<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBroadcastService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminBroadcastController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, TelegramBroadcastService $broadcast): RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);

        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:3500'],
        ], [
            'message.required' => 'Isi pesan broadcast terlebih dahulu.',
            'message.max' => 'Pesan broadcast maksimal 3500 karakter.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('admin.dashboard', ['tab' => 'broadcast'])
                ->withErrors($validator, 'broadcast')
                ->withInput();
        }

        $result = $broadcast->sendToActiveUsers($validator->validated()['message']);
        $status = $result['skipped']
            ? 'Broadcast dilewati karena token Telegram belum dikonfigurasi.'
            : "Broadcast terkirim ke {$result['sent']} user. Gagal: {$result['failed']}.";

        return redirect()
            ->route('admin.dashboard', ['tab' => 'broadcast'])
            ->with('status', $status);
    }
}
