<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        // Barisnya dibuat di sini, bukan dibuat sendiri oleh NumberSequence saat
        // dibutuhkan. Membuatnya saat dibutuhkan berarti dua permintaan bersamaan
        // bisa sama-sama mendapati barisnya belum ada lalu sama-sama membuatnya.
        DB::table('counters')->insert([
            'name' => 'reservation',
            'value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
