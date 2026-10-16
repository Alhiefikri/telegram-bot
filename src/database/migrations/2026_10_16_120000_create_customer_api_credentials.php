<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('selector', 32)->unique();
            $table->string('secret_digest', 64);
            $table->timestamp('created_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('rotation_digest', 64)->nullable();
            $table->unsignedBigInteger('rotation_message_id')->nullable();
            $table->timestamp('rotation_expires_at')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX customer_api_one_active ON customer_api_credentials (customer_id) WHERE revoked_at IS NULL');
        Schema::create('customer_api_usage', function (Blueprint $table): void {
            $table->foreignId('customer_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('request_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE customer_api_credentials ADD CONSTRAINT customer_api_digest_shape CHECK (selector ~ '^[0-9a-f]{32}$' AND secret_digest ~ '^[0-9a-f]{64}$')");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_customer_api_credential() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Customer API credential history is immutable';
    END IF;
    IF NEW.customer_id IS DISTINCT FROM OLD.customer_id OR NEW.selector IS DISTINCT FROM OLD.selector
       OR NEW.secret_digest IS DISTINCT FROM OLD.secret_digest OR NEW.created_at IS DISTINCT FROM OLD.created_at
       OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at) THEN
        RAISE EXCEPTION 'Customer API credential binding is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER customer_api_credential_guard BEFORE UPDATE OR DELETE ON customer_api_credentials
FOR EACH ROW EXECUTE FUNCTION guard_customer_api_credential();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_api_usage');
        Schema::dropIfExists('customer_api_credentials');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS guard_customer_api_credential()');
        }
    }
};
