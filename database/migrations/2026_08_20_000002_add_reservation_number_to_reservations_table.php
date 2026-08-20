<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolomnya NOT NULL, sehingga tidak bisa langsung dibuat begitu pada tabel
        // yang sudah berisi baris. Urutannya: nullable, isi, baru dikunci.
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('reservation_number', 20)->nullable()->after('id');
        });

        $last = 0;

        // Baris ter-soft-delete ikut diberi nomor. Nomor melekat pada reservasi
        // seumur hidupnya dan tidak pernah didaur ulang.
        foreach (DB::table('reservations')->orderBy('id')->pluck('id') as $id) {
            $last++;

            DB::table('reservations')
                ->where('id', $id)
                ->update(['reservation_number' => 'RU-R'.$last]);
        }

        if ($last > 0) {
            DB::table('counters')
                ->where('name', 'reservation')
                ->update(['value' => $last, 'updated_at' => now()]);
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('reservation_number', 20)->nullable(false)->change();
            $table->unique('reservation_number', 'uniq_reservations_number');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique('uniq_reservations_number');
            $table->dropColumn('reservation_number');
        });
    }
};
