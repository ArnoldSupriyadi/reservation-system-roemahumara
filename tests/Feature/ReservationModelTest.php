<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_reservation(): void
    {
        $r = Reservation::factory()->create();

        $this->assertNotNull($r->reservation_date);
        $this->assertNotNull($r->start_time);
        $this->assertNull($r->end_time);
        $this->assertNull($r->status);
        $this->assertSame(1, $r->version);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $r = Reservation::factory()->confirmed()->create();

        $this->assertSame(ReservationStatus::Confirmed, $r->status);
    }

    public function test_range_state_sets_end_time(): void
    {
        $r = Reservation::factory()->withRange()->create();

        $this->assertSame('15:00:00', $r->end_time);
    }

    public function test_relations_resolve(): void
    {
        $pic = User::factory()->create(['name' => 'IRA']);
        $area = Area::create(['name' => 'VIP 1']);

        $r = Reservation::factory()->create([
            'pic_id' => $pic->id,
            'area_id' => $area->id,
        ]);

        $this->assertSame('IRA', $r->pic->name);
        $this->assertSame('VIP 1', $r->area->name);
    }

    public function test_soft_delete_hides_row_from_default_query(): void
    {
        $r = Reservation::factory()->create();
        $r->delete();

        $this->assertSame(0, Reservation::count());
        $this->assertSame(1, Reservation::withTrashed()->count());
    }
}
