<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE public.customer_auth_mail_works
    DROP CONSTRAINT IF EXISTS customer_auth_mail_works_status_check;
ALTER TABLE public.customer_auth_mail_works
    ADD CONSTRAINT customer_auth_mail_works_status_check
    CHECK (status IN ('pending', 'sending', 'unknown', 'sent', 'cancelled', 'failed'));
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM customer_auth_mail_works WHERE status IN ('sending', 'unknown')) THEN
        RAISE EXCEPTION 'Resolve ambiguous auth mail deliveries before rolling back review states';
    END IF;
END $$;
ALTER TABLE customer_auth_mail_works DROP CONSTRAINT IF EXISTS customer_auth_mail_works_status_check;
ALTER TABLE customer_auth_mail_works
    ADD CONSTRAINT customer_auth_mail_works_status_check
    CHECK (status IN ('pending', 'sent', 'cancelled', 'failed'));
SQL);
    }
};
