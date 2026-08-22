<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReservationFactory extends Factory
{
    /**
     * Penghitung milik factory sendiri, terpisah dari tabel counters.
     *
     * Rentangnya sengaja dimulai dari 900000 agar tidak pernah bertabrakan dengan
     * nomor sungguhan yang dialokasikan ReservationWriter mulai dari 1. Nilainya
     * tidak di-reset antar test, dan memang tidak perlu — yang dibutuhkan hanya
     * keunikan, bukan urutan tertentu.
     */
    private static int $numberSeed = 900000;

    public function definition(): array
    {
        return [
            'reservation_number' => Reservation::NUMBER_PREFIX.(++static::$numberSeed),
            'reservation_date' => '2026-08-08',
            'guest_name' => $this->faker->name(),
            'company' => null,
            'phone' => '08123456789',
            'email' => null,
            'pic_id' => User::factory(),
            'event_type_id' => null,
            'menu_style_id' => null,
            // Area wajib di database sejak 2026-08-22. Area yang sudah ada dipakai
            // ulang, bukan dibuat baru setiap kali — kalau setiap reservasi punya
            // areanya sendiri, test bentrok tidak akan pernah menemukan bentrok
            // dan lulus tanpa membuktikan apa pun.
            'area_id' => fn () => Area::query()->value('id')
                ?? Area::create(['name' => 'AREA UJI'])->id,
            'start_time' => '12:00:00',
            'end_time' => null,
            'pax' => 4,
            'status' => null,
            'remark' => null,
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => 'confirmed']);
    }

    public function withRange(): static
    {
        return $this->state(fn () => ['start_time' => '12:00:00', 'end_time' => '15:00:00']);
    }
}
