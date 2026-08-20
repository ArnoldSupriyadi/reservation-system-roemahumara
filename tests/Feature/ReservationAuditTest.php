<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_reservation_is_logged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $r = Reservation::factory()->create();

        $this->assertCount(1, $r->activities);
        $this->assertSame('created', $r->activities->first()->event);
        $this->assertTrue($user->is($r->activities->first()->causer));
    }

    public function test_changing_pax_records_old_and_new_value(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $r = Reservation::factory()->create(['pax' => 5]);
        $r->pax = 8;
        $r->save();

        $update = $r->activities()->where('event', 'updated')->first();

        $this->assertSame(5, $update->properties['old']['pax']);
        $this->assertSame(8, $update->properties['attributes']['pax']);
    }

    public function test_saving_without_changes_creates_no_log_entry(): void
    {
        $this->actingAs(User::factory()->create());

        $r = Reservation::factory()->create();
        $before = $r->activities()->count();

        $r->save();

        $this->assertSame($before, $r->activities()->count());
    }

    public function test_bookkeeping_columns_are_not_logged(): void
    {
        $this->actingAs(User::factory()->create());

        $r = Reservation::factory()->create();
        $logged = array_keys($r->activities->first()->properties['attributes']);

        $this->assertNotContains('version', $logged);
        $this->assertNotContains('created_by', $logged);
        $this->assertNotContains('updated_by', $logged);
        $this->assertNotContains('idempotency_key', $logged);
        $this->assertContains('guest_name', $logged);
    }
}
