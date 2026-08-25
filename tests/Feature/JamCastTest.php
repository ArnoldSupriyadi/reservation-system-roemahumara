<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use App\Support\Jam;
use Database\Seeders\MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Jaminan yang harus tetap berlaku setelah start_time dan end_time berhenti
 * jadi string mentah.
 *
 * Ketiganya adalah hal yang tidak akan terlihat sebagai error kalau rusak:
 * riwayat yang bentuknya berubah, kotak isian yang menampilkan detik, dan
 * dedupe yang berhenti menangkap duplikat.
 */
class JamCastTest extends TestCase
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

    private function buatReservasi(array $atribut = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'reservation_date' => today()->addDay(),
            'start_time' => '11:00',
            'guest_name' => 'Tamu Uji',
            'pax' => 10,
            'area_id' => Area::first()->id,
            'pic_id' => $this->staf->id,
        ], $atribut));
    }

    public function test_the_column_comes_back_as_a_value_object(): void
    {
        $r = $this->buatReservasi(['start_time' => '11:00', 'end_time' => '14:30']);

        $this->assertInstanceOf(Jam::class, $r->fresh()->start_time);
        $this->assertSame('14:30', (string) $r->fresh()->end_time);
        $this->assertNull($this->buatReservasi(['guest_name' => 'Tanpa Selesai'])->end_time);
    }

    /**
     * Bentuk yang tersimpan di database tetap TIME berdetik.
     *
     * Diperiksa lewat getRawOriginal, melewati cast: kolomnya tidak boleh
     * berubah jadi VARCHAR atau kehilangan detiknya, karena `dedupe_key` adalah
     * generated column yang memakai TIME_FORMAT() (aturan #1 CLAUDE.md).
     */
    public function test_the_database_still_holds_a_time(): void
    {
        $r = $this->buatReservasi(['start_time' => '9'])->fresh();

        $this->assertSame('09:00:00', $r->getRawOriginal('start_time'));
    }

    /**
     * Riwayat lama dan baru harus sebangun.
     *
     * spatie mencatat nilai SESUDAH cast (LogsActivity::logChanges) lalu
     * menyimpannya sebagai JSON. Seluruh entri yang tercatat sebelum Jam ada
     * berisi '11:00:00' apa adanya dari MySQL — kalau yang baru berisi '11:00',
     * riwayat satu reservasi terbelah dua bentuk dan itu tidak bisa diperbaiki
     * belakangan.
     */
    public function test_the_activity_log_keeps_the_old_shape(): void
    {
        $r = $this->buatReservasi(['start_time' => '11:00']);

        app(ReservationWriter::class)->update($r, ['start_time' => '13:00'], $r->version, $this->staf);

        $terakhir = Activity::query()->latest('id')->first();

        $this->assertSame('13:00:00', $terakhir->properties['attributes']['start_time']);
        $this->assertSame('11:00:00', $terakhir->properties['old']['start_time']);
    }

    /**
     * Kotak isian jam menampilkan 11:00, bukan 11:00:00 dan bukan objek.
     *
     * Filament mengisi form dari attributesToArray(). Tanpa JamCast::serialize(),
     * yang masuk ke state Livewire adalah objek Jam dan halaman Edit tumbang
     * dengan "Property type not supported in Livewire" — HTTP 500, bukan tampilan
     * yang jelek. Itu benar-benar terjadi saat cast ini dipasang.
     */
    public function test_the_edit_form_shows_a_short_time(): void
    {
        $r = $this->buatReservasi(['start_time' => '11:00', 'end_time' => '14:30']);

        Livewire::test(EditReservation::class, ['record' => $r->getRouteKey()])
            ->assertFormSet([
                'start_time' => '11:00',
                'end_time' => '14:30',
            ]);
    }

    /**
     * Dedupe masih menangkap duplikat.
     *
     * findDuplicate() membandingkan lewat TIME_FORMAT di SQL, dan nilai yang
     * dikirim ke sana sekarang datang dari objek Jam. Kalau bentuknya bergeser,
     * gejalanya bukan error — melainkan duplikat yang lolos sampai ditabrak
     * unique index dedupe_key, dengan pesan yang jauh kurang menolong.
     */
    public function test_duplicates_are_still_caught(): void
    {
        $r = $this->buatReservasi(['guest_name' => 'Ibu Melati', 'start_time' => '11:00']);

        $duplikat = app(ReservationWriter::class)->findDuplicate([
            'reservation_date' => $r->reservation_date->toDateString(),
            'guest_name' => 'ibu melati',
            'start_time' => $r->start_time,
        ]);

        $this->assertNotNull($duplikat, 'Jam dari model harus tetap cocok dengan yang tersimpan.');
        $this->assertTrue($duplikat->is($r));
    }
}
