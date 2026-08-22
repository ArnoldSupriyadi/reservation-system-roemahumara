<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Area yang secara fisik saling meliputi.
 *
 * ALL BALLROOM adalah BALLROOM 1-4 dengan sekat dibuka. Tanpa tabel ini
 * ConflictChecker membandingkan area_id secara persis, sehingga memesan ALL
 * BALLROOM pada jam yang sama dengan BALLROOM 2 tidak memunculkan bentrok apa
 * pun — sistem diam, padahal ruangannya benar-benar bertabrakan.
 *
 * Pasangannya disimpan DUA ARAH: baris (ALL, B2) dan (B2, ALL). Menyimpan satu
 * arah saja membuat setiap query harus memeriksa dua kolom, dan cukup satu
 * tempat lupa melakukannya untuk membuat bentrok hanya terdeteksi dari satu
 * sisi. Penulisan dua arah diurus Area::overlapWith().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_overlaps', function (Blueprint $table) {
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->foreignId('overlaps_area_id')->constrained('areas')->cascadeOnDelete();

            $table->primary(['area_id', 'overlaps_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_overlaps');
    }
};
