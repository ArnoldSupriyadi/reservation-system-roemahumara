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

    // ---------------------------------------------------------------------
    // Area yang saling meliputi. ALL BALLROOM adalah BALLROOM 1-4 dengan sekat
    // dibuka, jadi memesan salah satunya membuat yang lain ikut terpakai.
    // ---------------------------------------------------------------------

    /** @return array{0: Area, 1: Area, 2: Area} ALL BALLROOM, BALLROOM 1, BALLROOM 2 */
    private function ballrooms(): array
    {
        $all = Area::create(['name' => 'ALL BALLROOM']);
        $satu = Area::create(['name' => 'BALLROOM 1']);
        $dua = Area::create(['name' => 'BALLROOM 2']);

        $all->overlapWith($satu);
        $all->overlapWith($dua);

        return [$all, $satu, $dua];
    }

    private function bookedIn(Area $area, string $start, ?string $end = null): Reservation
    {
        return Reservation::factory()->create([
            'area_id' => $area->id,
            'reservation_date' => '2026-08-09',
            'start_time' => $start,
            'end_time' => $end,
            'guest_name' => 'Penghuni '.$area->name,
        ]);
    }

    public function test_booking_the_whole_ballroom_clashes_with_a_part_of_it(): void
    {
        [$all, $satu] = $this->ballrooms();
        $this->bookedIn($satu, '10:00:00', '14:00:00');

        $this->assertCount(1, $this->checker->check($all->id, '2026-08-09', '11:00', '13:00'));
    }

    /** Arah sebaliknya harus sama, itu sebabnya relasinya disimpan dua arah. */
    public function test_booking_a_part_clashes_with_the_whole_ballroom(): void
    {
        [$all, $satu] = $this->ballrooms();
        $this->bookedIn($all, '10:00:00', '14:00:00');

        $this->assertCount(1, $this->checker->check($satu->id, '2026-08-09', '11:00', '13:00'));
    }

    /**
     * Dua bagian yang berbeda TIDAK saling meliputi. Kalau ini ikut dianggap
     * bentrok, ballroom praktis hanya bisa dipakai satu acara sekali waktu dan
     * pemisahannya jadi sia-sia.
     */
    public function test_two_different_parts_do_not_clash_with_each_other(): void
    {
        [, $satu, $dua] = $this->ballrooms();
        $this->bookedIn($satu, '10:00:00', '14:00:00');

        $this->assertCount(0, $this->checker->check($dua->id, '2026-08-09', '11:00', '13:00'));
    }

    public function test_an_unrelated_area_is_untouched_by_the_overlap(): void
    {
        [$all] = $this->ballrooms();
        $this->bookedIn($all, '10:00:00', '14:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '11:00', '13:00'));
    }

    /** Jam tetap diperhitungkan; meliputi ruangnya, bukan seharian penuh. */
    public function test_the_overlap_still_respects_the_clock(): void
    {
        [$all, $satu] = $this->ballrooms();
        $this->bookedIn($satu, '10:00:00', '14:00:00');

        $this->assertCount(0, $this->checker->check($all->id, '2026-08-09', '15:00', '17:00'));
    }

    /** Bagian yang batal tidak memakai ruang, termasuk lewat jalur meliputi. */
    public function test_a_cancelled_part_does_not_block_the_whole(): void
    {
        [$all, $satu] = $this->ballrooms();
        $r = $this->bookedIn($satu, '10:00:00', '14:00:00');
        $r->status = ReservationStatus::Cancelled;
        $r->save();

        $this->assertCount(0, $this->checker->check($all->id, '2026-08-09', '11:00', '13:00'));
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
