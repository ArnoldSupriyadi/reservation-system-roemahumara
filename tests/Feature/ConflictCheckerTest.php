<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\Reservation;
use App\Services\ConflictChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConflictCheckerTest extends TestCase
{
    use RefreshDatabase;

    private ConflictChecker $checker;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = app(ConflictChecker::class);
        $this->area = Area::create(['name' => 'VIP 1']);
    }

    private function existing(string $start, ?string $end = null): Reservation
    {
        return Reservation::factory()->create([
            'area_id' => $this->area->id,
            'reservation_date' => '2026-08-09',
            'start_time' => $start,
            'end_time' => $end,
            'guest_name' => 'Existing '.$start,
        ]);
    }

    public function test_no_area_means_no_conflict(): void
    {
        $this->existing('12:00:00');

        $this->assertCount(0, $this->checker->check(null, '2026-08-09', '12:00', null));
    }

    public function test_single_time_bookings_two_hours_apart_do_not_conflict(): void
    {
        $this->existing('12:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '18:00', null));
    }

    public function test_single_time_bookings_one_hour_apart_do_conflict(): void
    {
        $this->existing('12:00:00');

        $this->assertCount(1, $this->checker->check($this->area->id, '2026-08-09', '13:00', null));
    }

    public function test_exactly_two_hours_later_does_not_conflict(): void
    {
        $this->existing('12:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '14:00', null));
    }

    public function test_ranges_that_overlap_conflict(): void
    {
        $this->existing('12:00:00', '15:00:00');

        $this->assertCount(1, $this->checker->check($this->area->id, '2026-08-09', '14:00', '16:00'));
    }

    public function test_ranges_that_touch_do_not_conflict(): void
    {
        $this->existing('12:00:00', '15:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '15:00', '17:00'));
    }

    public function test_other_dates_are_ignored(): void
    {
        $this->existing('12:00:00', '15:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-10', '12:00', '15:00'));
    }

    public function test_other_areas_are_ignored(): void
    {
        $this->existing('12:00:00', '15:00:00');
        $other = Area::create(['name' => 'VIP 2']);

        $this->assertCount(0, $this->checker->check($other->id, '2026-08-09', '12:00', '15:00'));
    }

    public function test_the_row_being_edited_is_ignored(): void
    {
        $r = $this->existing('12:00:00', '15:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '12:00', '15:00', $r->id));
    }

    public function test_soft_deleted_rows_are_ignored(): void
    {
        $this->existing('12:00:00', '15:00:00')->delete();

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '13:00', '14:00'));
    }

    /**
     * Reservasi batal tidak memakai tempat. Kalau ia ikut dihitung, pengguna
     * mendapat peringatan bentrok atas slot yang sebenarnya sudah bebas dan
     * akan belajar mengabaikan peringatan bentrok sama sekali.
     */
    public function test_cancelled_rows_do_not_block_the_area(): void
    {
        $r = $this->existing('12:00:00', '15:00:00');
        $r->status = ReservationStatus::Cancelled;
        $r->save();

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '13:00', '14:00'));
    }

    public function test_confirmed_and_undetermined_rows_still_block_the_area(): void
    {
        $confirmed = $this->existing('12:00:00', '15:00:00');
        $confirmed->status = ReservationStatus::Confirmed;
        $confirmed->save();

        $this->assertCount(1, $this->checker->check($this->area->id, '2026-08-09', '13:00', '14:00'));

        $confirmed->status = null;
        $confirmed->save();

        $this->assertCount(1, $this->checker->check($this->area->id, '2026-08-09', '13:00', '14:00'));
    }
}
