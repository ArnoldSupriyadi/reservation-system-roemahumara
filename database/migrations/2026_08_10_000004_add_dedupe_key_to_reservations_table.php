<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE reservations
            ADD COLUMN dedupe_key VARCHAR(191)
            GENERATED ALWAYS AS (
                IF(deleted_at IS NULL,
                   CONCAT_WS('|',
                       DATE_FORMAT(reservation_date, '%Y-%m-%d'),
                       LOWER(TRIM(guest_name)),
                       TIME_FORMAT(start_time, '%H:%i')
                   ),
                   NULL)
            ) STORED
        ");

        DB::statement('
            CREATE UNIQUE INDEX uniq_reservations_dedupe
            ON reservations (dedupe_key)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX uniq_reservations_dedupe ON reservations');
        DB::statement('ALTER TABLE reservations DROP COLUMN dedupe_key');
    }
};
