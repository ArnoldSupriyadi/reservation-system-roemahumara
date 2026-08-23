<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateReservationException;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationNumberTest extends TestCase
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

    private function create(array $overrides = []): Reservation
    {
        return $this->writer->create($this->payload($overrides), (string) Str::uuid(), $this->actor);
    }

    /** Spec nomor 23. */
    public function test_the_first_reservation_is_numbered_one(): void
    {
        $this->assertSame('RU-R1', $this->create()->reservation_number);
    }

    /** Spec nomor 24. */
    public function test_numbers_run_in_order(): void
    {
        $this->assertSame('RU-R1', $this->create(['guest_name' => 'Satu'])->reservation_number);
        $this->assertSame('RU-R2', $this->create(['guest_name' => 'Dua'])->reservation_number);
        $this->assertSame('RU-R3', $this->create(['guest_name' => 'Tiga'])->reservation_number);
    }

    /**
     * Spec nomor 25. Inilah alasan tabel penghitung dipakai, bukan id.
     */
    public function test_a_rejected_duplicate_does_not_burn_a_number(): void
    {
        $this->assertSame('RU-R1', $this->create(['guest_name' => 'Tanti'])->reservation_number);

        try {
            $this->create(['guest_name' => 'Tanti']);
            $this->fail('Duplikat seharusnya ditolak.');
        } catch (DuplicateReservationException) {
            // diharapkan
        }

        $this->assertSame(
            'RU-R2',
            $this->create(['guest_name' => 'Melinda'])->reservation_number,
            'Percobaan yang ditolak tidak boleh membuang nomor.'
        );
    }

    /** Spec nomor 26. */
    public function test_a_repeated_idempotency_key_does_not_take_a_new_number(): void
    {
        $key = (string) Str::uuid();

        $first = $this->writer->create($this->payload(), $key, $this->actor);
        $second = $this->writer->create($this->payload(['pax' => 99]), $key, $this->actor);

        $this->assertSame($first->reservation_number, $second->reservation_number);
        $this->assertSame('RU-R1', $second->reservation_number);
        $this->assertSame(1, Reservation::count());

        $this->assertSame(
            'RU-R2',
            $this->create(['guest_name' => 'Berikutnya'])->reservation_number,
            'Submit ulang tidak boleh menggeser nomor berikutnya.'
        );
    }

    /** Spec nomor 27. */
    public function test_editing_never_changes_the_number(): void
    {
        $r = $this->create();

        $updated = $this->writer->update($r, $this->payload(['pax' => 8]), 1, $this->actor);

        $this->assertSame('RU-R1', $updated->reservation_number);
        $this->assertSame(8, $updated->pax);
    }

    public function test_the_number_is_not_mass_assignable(): void
    {
        $r = $this->writer->create(
            $this->payload(['reservation_number' => 'RU-R999']),
            (string) Str::uuid(),
            $this->actor
        );

        $this->assertSame('RU-R1', $r->reservation_number, 'Nomor tidak boleh bisa diisi dari input.');
    }

    /**
     * Factory memakai rentang angka tinggi supaya tidak pernah bertabrakan dengan
     * nomor sungguhan yang dimulai dari 1.
     */
    public function test_factory_numbers_never_collide_with_allocated_ones(): void
    {
        Reservation::factory()->count(3)->create();

        $this->assertSame('RU-R1', $this->create(['guest_name' => 'Sungguhan'])->reservation_number);
    }
}
