<?php

namespace Tests\Feature;

use App\Exceptions\InvalidReservationException;
use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Widgets\UpcomingReservationsWidget;
use App\Models\Area;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use Database\Seeders\MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pax boleh berupa rentang: 10–14 tamu.
 *
 * `pax` tetap wajib dan tetap berarti batas BAWAH, jadi seluruh kode lama terus
 * bekerja. `pax_max` boleh kosong, dan kosong berarti jumlahnya sudah pasti.
 */
class PaxRangeTest extends TestCase
{
    use RefreshDatabase;

    private User $staf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(MasterSeeder::class);

        $this->staf = User::factory()->create();
        $this->staf->assignRole('staff');
        $this->actingAs($this->staf);
    }

    /** @return array<string, mixed> */
    private function data(array $ubah = []): array
    {
        return array_merge([
            'reservation_date' => today()->addDay()->toDateString(),
            'guest_name' => 'Tamu Uji',
            'phone' => '08120000000',
            'pic_id' => $this->staf->id,
            'event_type_id' => EventType::first()->id,
            'area_id' => Area::first()->id,
            'pax' => 10,
            'start_time' => '11:00',
        ], $ubah);
    }

    private function buat(array $ubah = []): Reservation
    {
        return app(ReservationWriter::class)->create(
            $this->data($ubah),
            (string) Str::uuid(),
            $this->staf
        );
    }

    public function test_a_range_is_stored_as_two_numbers(): void
    {
        $r = $this->buat(['pax' => 10, 'pax_max' => 14]);

        $this->assertSame(10, $r->pax);
        $this->assertSame(14, $r->pax_max);
    }

    /**
     * Angka, bukan teks "10–14": berkas export dibaca untuk dijumlah dan
     * disaring, dan teks mematikan keduanya.
     */
    public function test_an_exact_count_leaves_the_upper_bound_empty(): void
    {
        $r = $this->buat(['pax' => 50]);

        $this->assertNull($r->pax_max);
        $this->assertSame('50', $r->paxLabel());
    }

    public function test_the_label_reads_as_a_range(): void
    {
        $this->assertSame('10–14', $this->buat(['pax' => 10, 'pax_max' => 14])->paxLabel());
    }

    /**
     * Dapur menyiapkan untuk jumlah terbanyak yang mungkin datang: kekurangan
     * makanan di tengah acara lebih mahal daripada kelebihan.
     */
    public function test_the_kitchen_number_follows_the_upper_bound(): void
    {
        $this->assertSame(14, $this->buat(['pax' => 10, 'pax_max' => 14])->paxUntukDapur());

        // Jam berbeda, supaya bukan bentrok area yang menghentikan test ini.
        $this->assertSame(50, $this->buat([
            'pax' => 50,
            'guest_name' => 'Pasti',
            'start_time' => '15:00',
        ])->paxUntukDapur());
    }

    public function test_the_writer_refuses_an_upper_bound_below_the_lower(): void
    {
        $this->expectException(InvalidReservationException::class);
        $this->expectExceptionMessage('tidak masuk akal');

        $this->buat(['pax' => 20, 'pax_max' => 15]);
    }

    public function test_the_writer_refuses_an_upper_bound_equal_to_the_lower(): void
    {
        $this->expectException(InvalidReservationException::class);

        $this->buat(['pax' => 20, 'pax_max' => 20]);
    }

    /**
     * Diperiksa terhadap keadaan HASIL, bukan input mentah.
     *
     * Menaikkan pax saja — tanpa menyentuh pax_max — bisa membuat rentangnya
     * terbalik. Memeriksa $data apa adanya akan melewatkannya, karena pax_max
     * tidak ikut dikirim.
     */
    public function test_raising_pax_alone_cannot_invert_the_range(): void
    {
        $r = $this->buat(['pax' => 10, 'pax_max' => 14]);

        $this->expectException(InvalidReservationException::class);

        app(ReservationWriter::class)->update($r, ['pax' => 20], $r->version, $this->staf);
    }

    public function test_the_form_refuses_an_inverted_range(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['pax' => 20, 'pax_max' => 15]))
            ->call('create')
            ->assertHasFormErrors(['pax_max']);

        $this->assertSame(0, Reservation::count());
    }

    /**
     * Akibat di dapur ikut disebut di form, karena itulah yang tidak terlihat
     * dari angkanya sendiri.
     */
    public function test_the_form_echoes_what_the_range_means(): void
    {
        Livewire::test(CreateReservation::class)
            ->fillForm($this->data(['pax' => 10, 'pax_max' => 40]))
            ->assertSee('Rentang 10–40 tamu. Porsi menu terisi 40.');
    }

    public function test_the_table_and_the_dashboard_show_the_range(): void
    {
        $this->buat(['pax' => 10, 'pax_max' => 14, 'reservation_date' => today()->toDateString()]);

        Livewire::test(UpcomingReservationsWidget::class)->assertSee('10–14');
        Livewire::test(ListReservations::class)->assertSee('10–14');
    }

    /**
     * Aturan #10 CLAUDE.md: halaman publik dibatasi select() eksplisit, dan
     * pelonggarannya selalu atas permintaan pemilik sistem. pax_max belum
     * diminta, jadi yang terbaca umum adalah angka pastinya saja.
     */
    public function test_the_public_page_shows_only_the_guaranteed_count(): void
    {
        $r = $this->buat([
            'pax' => 10,
            'pax_max' => 14,
            'reservation_date' => today()->toDateString(),
            'status' => 'confirmed',
        ]);

        // Jumlah tamu muncul di panel detail, bukan di kotak kalendernya.
        $this->get(route('public.calendar').'?pilih='.$r->id)
            ->assertOk()
            ->assertSee('10 orang')
            ->assertDontSee('10–14');
    }
}
