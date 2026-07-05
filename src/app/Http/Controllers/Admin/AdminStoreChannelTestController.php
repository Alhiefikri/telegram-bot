<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreChannelSetting;
use App\Services\AuditLogger;
use App\Services\StoreChannelSetupVerifier;
use App\Services\Telegram\TelegramBotApiRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminStoreChannelTestController extends Controller
{
    public function __invoke(Request $request, StoreChannelSetupVerifier $verifier, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        abort_unless((bool) $user->role?->canManagePaymentSettings(), 403);

        $setting = StoreChannelSetting::query()
            ->where('singleton_key', StoreChannelSetting::SINGLETON_KEY)
            ->first() ?? StoreChannelSetting::create(['singleton_key' => StoreChannelSetting::SINGLETON_KEY]);
        $result = $verifier->test($setting);
        if ($result['error'] !== null) {
            $result['error'] = TelegramBotApiRedactor::redactText($result['error']);
        }

        $setting = DB::transaction(function () use ($setting, $result): StoreChannelSetting {
            $locked = StoreChannelSetting::query()->whereKey($setting->id)->lockForUpdate()->firstOrFail();
            $locked->telegram_chat_id = $result['chat_id'] ?: $locked->telegram_chat_id;
            $locked->last_tested_at = now();
            $locked->last_test_success = $result['success'];
            $locked->last_test_message_id = $result['message_id'];
            $locked->last_test_error = $result['error'];
            $locked->save();

            return $locked;
        }, 5);

        $auditLogger->log('store_channel.tested', $setting, [], $result, $user, $request->ip());

        $redirect = redirect()->route('admin.dashboard', ['tab' => 'telegram-channel']);

        return $result['success']
            ? $redirect->with('status', 'Test Channel berhasil. Toggle tetap perlu diaktifkan manual.')
            : $redirect->with('error', $result['error']);
    }
}
