<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBroadcastService;
use App\Support\AdminTelegramDogfoodTiming;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminBroadcastController extends Controller
{
    public function __invoke(Request $request, TelegramBroadcastService $broadcast): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();
        $user = $request->attributes->get('adminUser');

        abort_unless((bool) $user->role?->canBroadcast(), 403);

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
        AdminTelegramDogfoodTiming::logRequestFinished('admin.broadcast', $startedAt, $result);

        $status = $result['skipped']
            ? 'Broadcast dilewati karena token Telegram belum dikonfigurasi.'
            : "Broadcast terkirim ke {$result['sent']} user. Gagal: {$result['failed']}.";

        return redirect()
            ->route('admin.dashboard', ['tab' => 'broadcast'])
            ->with('status', $status);
    }
}
