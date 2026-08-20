<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateReservationException;
use App\Exceptions\StaleReservationException;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationWriterTest extends TestCase
{
    use RefreshDatabase;

    private ReservationWriter $writer;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(ReservationWriter::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'company' => null,
            'phone' => '08112233445',
            'email' => null,
            'pic_id' => $this->actor->id,
            'event_type_id' => null,
            'menu_style_id' => null,
            'area_id' => null,
            'start_time' => '12:00',
            'end_time' => null,
            'pax' => 3,
            'status' => null,
            'remark' => null,
        ], $overrides);
    }

    public function test_create_stores_bookkeeping_columns(): void
    {
        $key = (string) Str::uuid();

        $r = $this->writer->create($this->payload(), $key, $this->actor);

        $this->assertSame(1, $r->version);
        $this->assertSame($key, $r->idempotency_key);
        $this->assertSame($this->actor->id, $r->created_by);
        $this->assertNull($r->updated_by);
    }

    public function test_same_idempotency_key_returns_the_same_row(): void
    {
        $key = (string) Str::uuid();

        $first = $this->writer->create($this->payload(), $key, $this->actor);
        $second = $this->writer->create($this->payload(['pax' => 99]), $key, $this->actor);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Reservation::count());
        $this->assertSame(3, $second->pax, 'Submit kedua tidak boleh mengubah data.');
    }

    public function test_duplicate_is_rejected_with_a_domain_exception(): void
    {
        $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);

        try {
            $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);
            $this->fail('Duplikat seharusnya ditolak.');
        } catch (DuplicateReservationException $e) {
            $this->assertSame('Bapak Wanda', $e->existing()->guest_name);
        }

        $this->assertSame(1, Reservation::count());
    }

    /**
     * findDuplicate() harus menemukan baris yang bentrok baik ketika jam ditulis
     * H:i (bentuk keluaran ReservationInput::normalize) maupun H:i:s (bentuk yang
     * tersimpan di kolom TIME).
     */
    public function test_the_colliding_row_is_reported_for_both_time_formats(): void
    {
        foreach (['12:00', '12:00:00'] as $format) {
            Reservation::query()->forceDelete();

            $this->writer->create($this->payload(['start_time' => $format]), (string) Str::uuid(), $this->actor);

            try {
                $this->writer->create($this->payload(['start_time' => $format]), (string) Str::uuid(), $this->actor);
                $this->fail("Duplikat seharusnya ditolak untuk format {$format}.");
            } catch (DuplicateReservationException $e) {
                $this->assertNotNull($e->existing(), "Baris yang bentrok tidak ditemukan untuk format {$format}.");
                $this->assertSame('Bapak Wanda', $e->existing()->guest_name);
            }
        }
    }

    public function test_update_increments_version_and_sets_updated_by(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);
        $editor = User::factory()->create();

        $updated = $this->writer->update($r, $this->payload(['pax' => 8]), 1, $editor);

        $this->assertSame(8, $updated->pax);
        $this->assertSame(2, $updated->version);
        $this->assertSame($editor->id, $updated->updated_by);
    }

    public function test_update_with_stale_version_is_rejected_and_changes_nothing(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);
        $this->writer->update($r, $this->payload(['pax' => 8]), 1, $this->actor);

        try {
            $this->writer->update($r->fresh(), $this->payload(['pax' => 10]), 1, $this->actor);
            $this->fail('Version basi seharusnya ditolak.');
        } catch (StaleReservationException) {
            // diharapkan
        }

        $this->assertSame(8, $r->fresh()->pax, 'Data tidak boleh berubah.');
        $this->assertSame(2, $r->fresh()->version);
    }

    public function test_update_records_exactly_one_audit_entry(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);
        $before = $r->activities()->count();

        $this->writer->update($r, $this->payload(['pax' => 8]), 1, $this->actor);

        $this->assertSame($before + 1, $r->fresh()->activities()->count());
    }

    public function test_update_into_an_existing_duplicate_is_rejected(): void
    {
        $this->writer->create($this->payload(['guest_name' => 'Tanti']), (string) Str::uuid(), $this->actor);
        $second = $this->writer->create($this->payload(['guest_name' => 'Melinda']), (string) Str::uuid(), $this->actor);

        $this->expectException(DuplicateReservationException::class);

        $this->writer->update($second, $this->payload(['guest_name' => 'Tanti']), 1, $this->actor);
    }
}
