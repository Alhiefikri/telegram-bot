<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->text('support_contact_value')->nullable()->change();
        });

        DB::table('orders')
            ->whereNotNull('support_contact_value')
            ->orderBy('id')
            ->select(['id', 'support_contact_value'])
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $value = (string) $order->support_contact_value;

                    if ($this->isEncrypted($value)) {
                        continue;
                    }

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['support_contact_value' => Crypt::encryptString($value)]);
                }
            }, 'id');
    }

    public function down(): void
    {
        DB::table('orders')
            ->whereNotNull('support_contact_value')
            ->orderBy('id')
            ->select(['id', 'support_contact_value'])
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $value = (string) $order->support_contact_value;

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update(['support_contact_value' => $this->decryptIfNeeded($value)]);
                }
            }, 'id');

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('support_contact_value', 120)->nullable()->change();
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    private function decryptIfNeeded(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }
};
