<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateReservationException;
use App\Exceptions\InvalidReservationException;
use App\Exceptions\StaleReservationException;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationWriterTest extends TestCase
{
    use RefreshDatabase;

    private ReservationWriter $writer;

    private User $actor;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(ReservationWriter::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);

        // ReservationWriter mewajibkan area sejak 2026-08-22.
        $this->area = Area::create(['name' => 'VIP 1']);
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
            'area_id' => $this->area->id,
            'start_time' => '12:00',
            'end_time' => null,
            'pax' => 3,
            'status' => null,
            // Diisi karena beberapa test di sini membuat beberapa reservasi pada
            // area dan jam yang sama. Yang diuji penomoran dan dedupe, bukan
            // bentrok area — remark kosong akan membuat writer menolaknya lebih
            // dulu dan menutupi hal yang sebenarnya diperiksa.
            'remark' => 'Uji tulis.',
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

    // ---------------------------------------------------------------------
    // Aturan isi. Halaman Filament sudah memeriksa hal yang sama, tapi
    // pemeriksaan di sana hanya berlaku untuk data yang masuk lewat form.
    // Yang diuji di bawah adalah jalur seeder, tinker, dan kode lain.
    // ---------------------------------------------------------------------

    public function test_create_without_an_area_is_rejected(): void
    {
        $this->expectException(InvalidReservationException::class);

        $this->writer->create($this->payload(['area_id' => null]), (string) Str::uuid(), $this->actor);
    }

    public function test_nothing_is_written_when_the_area_is_missing(): void
    {
        try {
            $this->writer->create($this->payload(['area_id' => null]), (string) Str::uuid(), $this->actor);
        } catch (InvalidReservationException) {
            // Diharapkan.
        }

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, DB::table('counters')->sum('value'), 'Nomor tidak boleh ikut terpakai.');
    }

    public function test_an_area_conflict_without_a_remark_is_rejected(): void
    {
        $this->writer->create(
            $this->payload(['guest_name' => 'Lebih Dulu']),
            (string) Str::uuid(),
            $this->actor
        );

        $this->expectException(InvalidReservationException::class);

        $this->writer->create(
            $this->payload(['guest_name' => 'Menyusul', 'start_time' => '13:00', 'remark' => null]),
            (string) Str::uuid(),
            $this->actor
        );
    }

    public function test_the_rejection_names_the_reservation_it_clashes_with(): void
    {
        $this->writer->create(
            $this->payload(['guest_name' => 'Lebih Dulu']),
            (string) Str::uuid(),
            $this->actor
        );

        try {
            $this->writer->create(
                $this->payload(['guest_name' => 'Menyusul', 'start_time' => '13:00', 'remark' => null]),
                (string) Str::uuid(),
                $this->actor
            );
            $this->fail('Seharusnya ditolak.');
        } catch (InvalidReservationException $e) {
            $this->assertStringContainsString('Lebih Dulu', $e->getMessage());
            $this->assertStringContainsString('12:00', $e->getMessage());
            $this->assertCount(1, $e->conflicts());
        }
    }

    public function test_an_area_conflict_passes_once_the_remark_explains_it(): void
    {
        $this->writer->create(
            $this->payload(['guest_name' => 'Lebih Dulu']),
            (string) Str::uuid(),
            $this->actor
        );

        $r = $this->writer->create(
            $this->payload([
                'guest_name' => 'Menyusul',
                'start_time' => '13:00',
                'remark' => 'Sekat dibuka, satu rombongan.',
            ]),
            (string) Str::uuid(),
            $this->actor
        );

        $this->assertSame(2, Reservation::count());
        $this->assertSame('Menyusul', $r->guest_name);
    }

    /**
     * Duplikat persis juga tampak sebagai bentrok area. Yang harus muncul tetap
     * DuplicateReservationException, karena pesannya menyebut nama dan tanggal —
     * jauh lebih menolong daripada "Remark kosong".
     */
    public function test_an_exact_duplicate_still_reports_itself_as_a_duplicate(): void
    {
        $this->writer->create($this->payload(['remark' => null]), (string) Str::uuid(), $this->actor);

        $this->expectException(DuplicateReservationException::class);

        $this->writer->create($this->payload(['remark' => null]), (string) Str::uuid(), $this->actor);
    }

    /**
     * update() menerima array parsial: fill() hanya menimpa kunci yang ada.
     * Memeriksa $data mentah akan menolak perubahan pax yang tidak menyentuh
     * area sama sekali, hanya karena area tidak ikut dikirim.
     */
    public function test_a_partial_update_is_judged_on_the_resulting_state(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);

        $updated = $this->writer->update($r, ['pax' => 9], 1, $this->actor);

        $this->assertSame(9, $updated->pax);
        $this->assertSame($this->area->id, $updated->area_id);
    }

    public function test_an_update_that_clears_the_area_is_rejected(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);

        $this->expectException(InvalidReservationException::class);

        $this->writer->update($r, ['area_id' => null], 1, $this->actor);
    }
}
