<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_date_name_and_start_time_is_rejected(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        $this->expectException(QueryException::class);

        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);
    }

    public function test_casing_and_trailing_space_do_not_bypass_the_constraint(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        $this->expectException(QueryException::class);

        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'bapak wanda ',
            'start_time' => '12:00:00',
        ]);
    }

    public function test_different_start_time_is_allowed(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '18:00:00',
        ]);

        $this->assertSame(2, Reservation::count());
    }

    public function test_soft_deleted_row_does_not_block_reinsert(): void
    {
        $first = Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        $first->delete();

        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        $this->assertSame(1, Reservation::count());
        $this->assertSame(2, Reservation::withTrashed()->count());
    }

    public function test_violation_names_the_dedupe_index(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        try {
            Reservation::factory()->create([
                'reservation_date' => '2026-08-07',
                'guest_name' => 'Bapak Wanda',
                'start_time' => '12:00:00',
            ]);
            $this->fail('Duplikat seharusnya ditolak.');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
            $this->assertStringContainsString('uniq_reservations_dedupe', $e->getMessage());
        }
    }
}
