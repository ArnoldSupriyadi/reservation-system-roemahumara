<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->date('reservation_date');
            $table->string('guest_name', 150);
            $table->string('company', 150)->nullable();
            $table->string('phone', 30);
            $table->string('email', 150)->nullable();

            $table->foreignId('pic_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_type_id')->nullable()->constrained('event_types')->restrictOnDelete();
            $table->foreignId('menu_style_id')->nullable()->constrained('menu_styles')->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->restrictOnDelete();

            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->unsignedInteger('pax');
            $table->enum('status', ['tentative', 'confirmed'])->nullable();
            $table->text('remark')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->char('idempotency_key', 36)->nullable()->unique();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('reservation_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
