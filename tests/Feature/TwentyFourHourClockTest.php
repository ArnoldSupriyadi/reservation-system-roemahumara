<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Widgets\UpcomingReservationsWidget;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationSpreadsheet;
use App\Support\Jam;
use Database\Seeders\MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Jam selalu 24 jam, di mana pun ia tampil.
 *
 * Sebelas malam ditulis 23:00, tidak pernah "11:00 PM" atau "11 malam".
 * Dikumpulkan dalam satu berkas karena aturannya satu, sementara tempat
 * tampilnya tersebar — tanpa test bersama seperti ini, satu tempat bisa
 * berangsur berbeda tanpa ada yang menyadarinya.
 *
 * Jam 19:00 dipilih sebagai contoh justru karena di format 12 jam ia berubah
 * jadi angka lain (7). Memakai 09:00 tidak membuktikan apa-apa: angkanya sama
 * di kedua format.
 */
class TwentyFourHourClockTest extends TestCase
{
    use RefreshDatabase;

    private User $staf;

    private Reservation $malam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(MasterSeeder::class);

        $this->staf = User::factory()->create();
        $this->staf->assignRole('staff');
        $this->actingAs($this->staf);

        $this->malam = Reservation::factory()->create([
            'reservation_date' => today(),
            'start_time' => '19:00',
            'end_time' => '21:30',
            'guest_name' => 'Acara Malam',
            'pax' => 20,
            'area_id' => Area::first()->id,
            'pic_id' => $this->staf->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_the_value_object_never_speaks_twelve_hour(): void
    {
        $jam = Jam::dari('19:00');

        $this->assertSame('19:00', (string) $jam);
        $this->assertSame('19:00:00', $jam->jsonSerialize());
    }

    /**
     * Format 12 jam ditolak keras, bukan diam-diam diterima.
     *
     * Jam::format() sengaja hanya mengenal dua bentuk. Menambahkan 'h:i A' ke
     * sana adalah keputusan sadar, bukan sesuatu yang bisa menyelinap masuk
     * lewat satu pemanggilan di ujung aplikasi.
     */
    public function test_a_twelve_hour_format_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Jam::dari('19:00')->format('h:i A');
    }

    public function test_the_reservation_table_shows_twenty_four_hour(): void
    {
        Livewire::test(ListReservations::class)
            ->assertSee('19:00–21:30')
            ->assertDontSee('7:00');
    }

    public function test_the_dashboard_widget_shows_twenty_four_hour(): void
    {
        Livewire::test(UpcomingReservationsWidget::class)
            ->assertSee('19:00–21:30')
            ->assertDontSee('7:00');
    }

    public function test_the_public_calendar_shows_twenty_four_hour(): void
    {
        $this->get(route('public.calendar'))
            ->assertOk()
            ->assertSee('19:00')
            ->assertDontSee('7:00 PM');
    }

    /**
     * Yang diperiksa Blade-nya, bukan PDF jadinya.
     *
     * Isi PDF terkompresi, jadi mencari '19:00' di dalamnya tidak akan
     * menemukannya meski jamnya benar. Merender view yang sama dengan yang
     * dipakai controller sudah cukup untuk menangkap pergantian format.
     */
    public function test_the_printed_document_shows_twenty_four_hour(): void
    {
        $html = view('pdf.reservation', [
            'reservation' => $this->malam->load(['pic', 'area', 'eventType', 'menus.category']),
            'dicetakOleh' => $this->staf->name,
        ])->render();

        $this->assertStringContainsString('19:00', $html);
        $this->assertStringContainsString('21:30', $html);
        $this->assertStringNotContainsString('7:00 PM', $html);
    }

    public function test_the_export_writes_twenty_four_hour(): void
    {
        $spreadsheet = app(ReservationSpreadsheet::class);

        $response = $spreadsheet->unduh(
            Reservation::query(),
            'uji.xlsx',
            $spreadsheet->judul('all')
        );

        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());

        $baris = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $baris[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        $isi = array_combine($baris[1], $baris[2]);

        $this->assertSame('19:00', $isi['Jam mulai']);
        $this->assertSame('21:30', $isi['Jam selesai']);

        // Teks, bukan nilai waktu. Sel bertipe waktu akan ditampilkan ulang
        // Excel menurut locale komputer pembacanya — di komputer berlocale
        // Amerika, 19:00 berubah jadi 7:00 PM tanpa berkasnya berubah sama
        // sekali. Sebagai teks, yang tertulis itulah yang terbaca di mana pun.
        $this->assertIsString($isi['Jam mulai']);
    }
}
