<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBroadcastService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminBroadcastController extends Controller
{
    public function __invoke(Request $request, TelegramBroadcastService $broadcast): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        $panel = Filament::getPanel('admin');

        abort_unless($user->canAccessPanel($panel), 403);

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
