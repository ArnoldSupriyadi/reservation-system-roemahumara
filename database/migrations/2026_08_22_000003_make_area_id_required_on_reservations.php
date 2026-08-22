<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * area_id jadi NOT NULL.
 *
 * Form Filament dan ReservationWriter sudah mewajibkannya sejak 2026-08-22.
 * Migrasi ini menutup lapisan terakhir: perintah SQL langsung, impor, dan
 * kode yang melewati writer pun tidak bisa lagi menyisipkan reservasi tanpa
 * area. Reservasi tanpa area dilewati ConflictChecker sepenuhnya, sehingga
 * slot yang sudah terpakai bisa dipesan ulang tanpa peringatan apa pun.
 *
 * Ditulis dengan DB::statement, bukan $table->foreignId()->change(), karena
 * kolom ini punya foreign key. Mengubahnya lewat Schema builder membuat
 * doctrine/dbal menulis ulang definisi kolom dan berisiko menjatuhkan
 * constraint reservations_area_id_foreign tanpa memberi tanda.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tanpaArea = DB::table('reservations')->whereNull('area_id')->count();

        if ($tanpaArea > 0) {
            // Dihentikan dengan pesan yang bisa ditindaklanjuti. Tanpa ini
            // MySQL menolak dengan "Invalid use of NULL value" yang tidak
            // memberi tahu baris mana yang harus dibereskan.
            throw new RuntimeException(
                "Ada {$tanpaArea} reservasi tanpa area. Isi dulu areanya sebelum migrasi ini "
                .'dijalankan: SELECT id, reservation_date, guest_name FROM reservations WHERE area_id IS NULL;'
            );
        }

        DB::statement('ALTER TABLE reservations MODIFY COLUMN area_id BIGINT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservations MODIFY COLUMN area_id BIGINT UNSIGNED NULL');
    }
};
