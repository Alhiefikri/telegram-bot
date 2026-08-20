<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_identity_backfill_readiness', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 64)->unique();
            $table->string('status', 16)->default('pending');
            // This is a SHA-256 digest of the dedicated HMAC key, never the
            // key or any decrypted credential value.
            $table->char('fingerprint_key_digest', 64)->nullable();
            $table->unsignedBigInteger('scan_upper_bound_id')->nullable();
            $table->unsignedBigInteger('last_processed_id')->default(0);
            $table->unsignedBigInteger('scanned_count')->default(0);
            $table->unsignedBigInteger('registered_count')->default(0);
            $table->unsignedBigInteger('already_registered_count')->default(0);
            $table->unsignedBigInteger('skipped_unrecognized_count')->default(0);
            $table->unsignedBigInteger('conflict_count')->default(0);
            $table->unsignedBigInteger('unresolved_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'fingerprint_key_digest'], 'credential_identity_readiness_status_key_index');
        });

        DB::table('credential_identity_backfill_readiness')->insert([
            'singleton_key' => 'credential_identity_registry',
            'status' => 'pending',
            'fingerprint_key_digest' => null,
            'scan_upper_bound_id' => null,
            'last_processed_id' => 0,
            'scanned_count' => 0,
            'registered_count' => 0,
            'already_registered_count' => 0,
            'skipped_unrecognized_count' => 0,
            'conflict_count' => 0,
            'unresolved_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'ready_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_identity_backfill_readiness');
    }
};
