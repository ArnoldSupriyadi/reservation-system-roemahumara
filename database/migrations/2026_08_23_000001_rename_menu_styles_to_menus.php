<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Menu style" jadi "Menu", dan isinya berubah dari gaya sajian menjadi daftar
 * hidangan sungguhan (24 kategori, 137 item).
 *
 * Dua baris lama — BUFFET dan AL CARTE — sengaja TIDAK dihapus. Salah satunya
 * masih dipakai reservasi RU-R11, dan menghapusnya berarti membuang informasi
 * yang pernah dicatat orang. Keduanya diberi kategori "Gaya Sajian" supaya
 * jelas terpisah dari hidangan, dan bisa dihapus sendiri lewat /cms/menus
 * kalau memang tidak diperlukan.
 */
return new class extends Migration
{
    private const KATEGORI_LAMA = 'Gaya Sajian';

    public function up(): void
    {
        Schema::rename('menu_styles', 'menus');

        Schema::table('menus', function (Blueprint $table) {
            $table->string('category', 80)->after('name')->index();
        });

        // Baris yang sudah ada lahir sebelum kategori dikenal.
        DB::table('menus')->update(['category' => self::KATEGORI_LAMA]);
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });

        Schema::rename('menus', 'menu_styles');
    }
};
