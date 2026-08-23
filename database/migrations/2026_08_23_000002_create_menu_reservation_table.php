<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu reservasi bisa memesan banyak menu, masing-masing dengan jumlah porsinya
 * sendiri — 30 porsi nasi, 20 tusuk sate, 50 gelas teh. Karena itu pax ada di
 * tabel penghubung, bukan di reservasi: jumlah porsi tiap item sering berbeda
 * satu sama lain dan berbeda pula dari jumlah tamu.
 *
 * reservations.menu_style_id yang lama dipindahkan ke sini lebih dulu, baru
 * kolomnya dihapus. Nilai pax-nya diambil dari pax reservasi — itu tebakan
 * terbaik yang tersedia, dan lebih jujur daripada mengisi nol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_reservation', function (Blueprint $table) {
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete, bukan cascade: menu yang sudah pernah dipesan
            // tidak boleh lenyap dari catatan hanya karena dihapus dari master.
            // Master punya kolom is_active untuk menonaktifkannya.
            $table->foreignId('menu_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('pax');

            $table->primary(['reservation_id', 'menu_id']);
        });

        $lama = DB::table('reservations')
            ->whereNotNull('menu_style_id')
            ->select('id', 'menu_style_id', 'pax')
            ->get();

        foreach ($lama as $r) {
            DB::table('menu_reservation')->insert([
                'reservation_id' => $r->id,
                'menu_id' => $r->menu_style_id,
                'pax' => max(1, (int) $r->pax),
            ]);
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_style_id');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('menu_style_id')->nullable()->after('event_type_id')->constrained('menus')->restrictOnDelete();
        });

        // Hanya satu yang bisa dikembalikan; kolom lama memang cuma memuat satu
        // menu per reservasi. Reservasi yang sesudah ini memesan beberapa menu
        // akan kehilangan sisanya kalau di-rollback — tidak terhindarkan.
        foreach (DB::table('menu_reservation')->orderBy('menu_id')->get() as $baris) {
            DB::table('reservations')
                ->where('id', $baris->reservation_id)
                ->whereNull('menu_style_id')
                ->update(['menu_style_id' => $baris->menu_id]);
        }

        Schema::dropIfExists('menu_reservation');
    }
};
