<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Telegram\FakeSalesProofPostPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminFakeSalesProofPostController extends Controller
{
    public function __invoke(Request $request, FakeSalesProofPostPublisher $publisher): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        abort_unless((bool) $user->role?->canManagePaymentSettings(), 403);

        $result = $publisher->publish($request->input('product_id'));

        return redirect()
            ->route('admin.dashboard', ['tab' => 'telegram-channel'])
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }
}
