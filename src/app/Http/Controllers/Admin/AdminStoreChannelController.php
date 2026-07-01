<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StoreChannelSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminStoreChannelController extends Controller
{
    public function __invoke(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->attributes->get('adminUser');
        abort_unless((bool) $user->role?->canManagePaymentSettings(), 403);

        $validator = Validator::make($request->all(), [
            'channel_username' => ['nullable', 'string', 'max:255'],
            'channel_link' => ['nullable', 'string', 'max:255'],
            'community_gate_enabled' => ['nullable', 'boolean'],
            'sales_proof_enabled' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('admin.dashboard', ['tab' => 'telegram-channel'])->withErrors($validator, 'storeChannel')->withInput();
        }

        $data = $validator->validated();
        $data['channel_username'] = trim((string) ($data['channel_username'] ?? '')) ?: null;
        $data['channel_link'] = trim((string) ($data['channel_link'] ?? '')) ?: null;
        if ($data['channel_link'] !== null) {
            $data['channel_username'] = null;
        }

        [$setting, $old] = DB::transaction(function () use ($data): array {
            $setting = StoreChannelSetting::query()
                ->where('singleton_key', StoreChannelSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first() ?? new StoreChannelSetting(['singleton_key' => StoreChannelSetting::SINGLETON_KEY]);
            $old = $setting->exists ? $setting->only(['channel_username', 'channel_link', 'community_gate_enabled', 'sales_proof_enabled']) : [];
            $oldChannelUsername = $setting->channel_username;
            $oldChannelLink = $setting->channel_link;
            $setting->channel_username = $data['channel_username'] ?? null;
            $setting->channel_link = $data['channel_link'] ?? null;
            $setting->community_gate_enabled = (bool) ($data['community_gate_enabled'] ?? false);
            $setting->sales_proof_enabled = (bool) ($data['sales_proof_enabled'] ?? false);

            if ($oldChannelUsername !== $setting->channel_username || $oldChannelLink !== $setting->channel_link) {
                $setting->telegram_chat_id = null;
                $setting->last_tested_at = null;
                $setting->last_test_success = null;
                $setting->last_test_message_id = null;
                $setting->last_test_error = null;
            }

            $setting->save();

            return [$setting, $old];
        }, 5);

        $auditLogger->log('store_channel.updated', $setting, $old, $setting->only(['channel_username', 'channel_link', 'community_gate_enabled', 'sales_proof_enabled']), $user, $request->ip());

        return redirect()->route('admin.dashboard', ['tab' => 'telegram-channel'])->with('status', 'Pengaturan Telegram Channel disimpan. Jalankan Test Channel untuk validasi akses bot.');
    }
}
