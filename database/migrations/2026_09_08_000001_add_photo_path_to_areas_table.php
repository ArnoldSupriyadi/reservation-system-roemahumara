<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto area sebagai panduan visual di form CMS.
 *
 * Boleh kosong, dan kosong berarti "belum ada fotonya" — bukan keadaan salah.
 * Form reservasi menyembunyikan pratinjaunya sama sekali kalau kolom ini null,
 * jadi area baru yang belum sempat difoto tidak membuat form rusak.
 *
 * Isinya path relatif terhadap disk `public` (mis. `area/OUTDOOR.jpg`), bukan
 * URL penuh. URL dibangun saat dirender lewat Storage::url(); menyimpan URL
 * penuh akan membekukan APP_URL lama ke dalam baris database, dan itu persis
 * yang membuat tautan menunjuk ke localhost setelah pindah ke domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
