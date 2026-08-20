<?php

namespace Tests\Unit;

use App\Enums\Ability;
use App\Enums\ReservationStatus;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_default_duration_is_two_hours(): void
    {
        $this->assertSame(120, config('reservation.default_duration_minutes'));
    }

    public function test_abilities_are_named_by_business_capability(): void
    {
        $this->assertSame('reservation.delete', Ability::DeleteReservation->value);
        $this->assertSame('master.manage', Ability::ManageMaster->value);
        $this->assertCount(8, Ability::cases());
    }

    public function test_ability_values_and_options_stay_in_sync(): void
    {
        $this->assertCount(8, Ability::values());
        $this->assertSame(
            Ability::values(),
            array_keys(Ability::options()),
            'Setiap Ability wajib punya label.'
        );
    }

    public function test_reservation_statuses_render_uppercase_labels(): void
    {
        $this->assertSame('CONFIRMED', ReservationStatus::Confirmed->label());
        $this->assertSame('TENTATIVE', ReservationStatus::Tentative->label());
    }

    public function test_database_connection_is_mysql(): void
    {
        $this->assertSame('mysql', config('database.default'));
    }
}
