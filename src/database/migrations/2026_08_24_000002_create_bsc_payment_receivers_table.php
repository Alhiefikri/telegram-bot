<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_ADDRESS_INDEX = 'bsc_receivers_active_address_unique';

    public function up(): void
    {
        Schema::create('bsc_payment_receivers', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120);
            $table->char('address', 42);
            $table->char('address_normalized', 42);
            $table->unsignedBigInteger('chain_id');
            $table->char('token_contract', 42);
            $table->unsignedTinyInteger('token_decimals')->default(18);
            $table->boolean('is_active')->default(false);
            $table->string('verification_status', 32)->default('pending_verification');
            $table->string('verification_method', 32)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'verification_status'], 'bsc_receivers_assignment_index');
            $table->index('address_normalized', 'bsc_receivers_address_lookup');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $predicate = DB::getDriverName() === 'pgsql' ? 'is_active IS TRUE' : 'is_active = 1';
            DB::statement(
                'CREATE UNIQUE INDEX '.self::ACTIVE_ADDRESS_INDEX
                .' ON bsc_payment_receivers (address_normalized) WHERE '.$predicate,
            );
        } else {
            Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
                $table->unique('address_normalized', self::ACTIVE_ADDRESS_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_ADDRESS_INDEX);
        } else {
            Schema::table('bsc_payment_receivers', function (Blueprint $table): void {
                $table->dropUnique(self::ACTIVE_ADDRESS_INDEX);
            });
        }
        Schema::dropIfExists('bsc_payment_receivers');
    }
};
