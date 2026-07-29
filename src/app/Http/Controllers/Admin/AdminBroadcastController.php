<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBroadcastRequest;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\Telegram\TelegramBroadcastService;
use App\Support\AdminTelegramDogfoodTiming;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;

class AdminBroadcastController extends Controller
{
    public function __invoke(StoreBroadcastRequest $request, TelegramBroadcastService $broadcast, AdminDashboardRedirector $redirector): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();

        $result = $broadcast->queueAdminBroadcast($request->validated('message'));
        AdminTelegramDogfoodTiming::logRequestFinished('admin.broadcast', $startedAt, $result);

        $status = $result['skipped']
            ? 'Broadcast dilewati karena token Telegram belum dikonfigurasi.'
            : "Broadcast #{$result['broadcast_id']} masuk antrean. Progres bisa dipantau di tab Broadcast.";

        return $redirector->broadcast()
            ->with('status', $status);
    }
}
