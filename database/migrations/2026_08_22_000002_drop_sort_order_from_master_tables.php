<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Urutan master dihapus; daftarnya memakai id.
 *
 * sort_order menuntut pengelola memikirkan angka setiap kali menambah area atau
 * jenis acara, padahal isinya hanya belasan baris dan urutan tampilnya tidak
 * pernah jadi persoalan. id sudah mencerminkan urutan pembuatan.
 *
 * down() mengembalikan kolomnya, tapi TIDAK bisa mengembalikan nilainya — angka
 * urutan yang lama hilang begitu up() jalan. Yang dipulihkan hanya bentuk
 * kolomnya, terisi ulang dari id supaya urutannya tetap masuk akal.
 */
return new class extends Migration
{
    private const TABLES = ['areas', 'event_types', 'menu_styles'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('sort_order')->default(0);
            });

            // Bukan penulisan aplikasi, jadi update massal di sini tidak
            // melanggar aturan #2 — rollback skema justru tidak boleh
            // membangkitkan event model.
            DB::statement("UPDATE {$name} SET sort_order = id");
        }
    }
};
