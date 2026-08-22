<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status ketiga: cancelled.
 *
 * Kolomnya ENUM MySQL, jadi menambah nilai harus lewat ALTER — menambah case di
 * App\Enums\ReservationStatus saja tidak cukup, dan gejalanya berupa
 * "Data truncated for column 'status'" saat menyimpan, bukan error yang jelas.
 *
 * Ditulis dengan DB::statement, bukan $table->enum()->change(), karena
 * doctrine/dbal tidak mengenali tipe ENUM dan akan mengubahnya jadi VARCHAR
 * secara diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE reservations
            MODIFY COLUMN status ENUM('tentative', 'confirmed', 'cancelled') NULL
        ");
    }

    public function down(): void
    {
        // Nilai yang hendak dihapus harus dikosongkan dulu, kalau tidak ALTER
        // di bawah menolak jalan.
        //
        // Update massal di sini disengaja dan tidak melanggar aturan #2: aturan
        // itu menjaga audit trail pada penulisan aplikasi, sedangkan rollback
        // skema justru tidak boleh membangkitkan event model.
        DB::table('reservations')->where('status', 'cancelled')->update(['status' => null]);

        DB::statement("
            ALTER TABLE reservations
            MODIFY COLUMN status ENUM('tentative', 'confirmed') NULL
        ");
    }
};
