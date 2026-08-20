<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reservation_date' => '2026-08-08',
            'guest_name' => $this->faker->name(),
            'company' => null,
            'phone' => '08123456789',
            'email' => null,
            'pic_id' => User::factory(),
            'event_type_id' => null,
            'menu_style_id' => null,
            'area_id' => null,
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
