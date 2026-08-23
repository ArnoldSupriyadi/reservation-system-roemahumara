<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan per menu yang dipesan: "tidak pedas", "tanpa kacang", "pisahkan
 * sausnya", dan sejenisnya.
 *
 * Terpisah dari remark reservasi. Remark reservasi berlaku untuk acaranya
 * secara keseluruhan; yang ini menempel pada satu hidangan tertentu, dan
 * dibaca dapur saat menyiapkan hidangan itu — bukan saat membaca reservasinya.
 * Menggabungkan keduanya membuat permintaan "tidak pedas" tenggelam di tengah
 * catatan tentang sekat ruangan dan pembayaran.
 *
 * TEXT, bukan VARCHAR pendek: aturan #4 CLAUDE.md melarang catatan dipotong,
 * dan batas panjang di kolom adalah bentuk pemotongan yang paling sunyi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_reservation', function (Blueprint $table) {
            $table->text('remark')->nullable()->after('pax');
        });
    }

    public function down(): void
    {
        Schema::table('menu_reservation', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};
