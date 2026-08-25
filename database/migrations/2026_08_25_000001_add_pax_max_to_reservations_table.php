<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas atas jumlah tamu, untuk reservasi yang jumlahnya masih perkiraan.
 *
 * `pax` tetap wajib dan tetap berarti batas BAWAH — seluruh kode yang sudah ada
 * terus bekerja tanpa disentuh. Kolom baru ini boleh kosong, dan kosong berarti
 * jumlahnya sudah pasti.
 *
 * Sebelum ini rentang ditulis di Remark ("10-14 pax") atas petunjuk teks bantuan
 * di form. Itu berfungsi, tapi rentangnya jadi teks bebas yang tidak bisa
 * dicari, tidak bisa dijumlah, dan tenggelam di antara catatan pembayaran yang
 * juga tinggal di Remark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('pax_max')->nullable()->after('pax');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('pax_max');
        });
    }
};
