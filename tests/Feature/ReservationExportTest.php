<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationSpreadsheet;
use Database\Seeders\MasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class ReservationExportTest extends TestCase
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
            'reservation_date' => today(),
            'start_time' => '11:00',
            'guest_name' => 'Tamu Uji',
            'pax' => 10,
            'area_id' => Area::first()->id,
            'pic_id' => $this->staf->id,
        ], $atribut));
    }

    /**
     * Membaca kembali berkas yang dihasilkan, bukan sekadar memeriksa header
     * HTTP-nya. Berkas .xlsx yang rusak tetap terkirim dengan status 200 dan
     * content-type yang benar; yang ketahuan hanya saat Excel menolaknya.
     *
     * @return array<int, array<int, mixed>>
     */
    private function bacaKembali(BinaryFileResponse $response): array
    {
        // Baris kosong ikut dibaca. Bawaannya dilewati, dan itu akan membuat
        // jeda antara judul dan kepala kolom tak terlihat oleh test — padahal
        // jeda itulah yang menjaga judul tidak ikut tersortir di Excel.
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        $reader = new Reader($options);
        $reader->open($response->getFile()->getPathname());

        $baris = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $baris[] = $row->toArray();
            }

            break;
        }

        $reader->close();

        return $baris;
    }

    public function test_it_produces_a_readable_spreadsheet(): void
    {
        $this->buatReservasi(['guest_name' => 'PT Bangun Karya', 'pax' => 42]);

        $response = app(ReservationSpreadsheet::class)->unduh(
            Reservation::query()->orderBy('reservation_date'),
            'uji.xlsx',
            'Data Reservation — Agustus 2026'
        );

        $baris = $this->bacaKembali($response);

        $this->assertSame('Data Reservation — Agustus 2026', $baris[0][0], 'Baris pertama harus judul.');
        $this->assertSame('No. Reservasi', $baris[2][0], 'Kepala kolom di baris ketiga, setelah judul dan satu baris kosong.');
        $this->assertContains('Remark', $baris[2]);
        $this->assertContains('PT Bangun Karya', $baris[3]);
        $this->assertContains(42, $baris[3]);
    }

    /**
     * Kolom dan judulnya ditulis sebagai satu larik supaya tidak bisa bergeser
     * sendiri-sendiri. Test ini yang membuktikannya: berkas yang isinya melenceng
     * satu kolom tetap terbuka di Excel tanpa keluhan apa pun.
     */
    public function test_every_column_header_has_a_value_beneath_it(): void
    {
        $this->buatReservasi([
            'guest_name' => 'Tamu Lengkap',
            'company' => 'PT Contoh',
            'remark' => 'DP 50% sudah masuk.',
            'end_time' => '14:00',
            'status' => ReservationStatus::Confirmed->value,
        ]);

        $baris = $this->bacaKembali(app(ReservationSpreadsheet::class)->unduh(
            Reservation::query(),
            'uji.xlsx',
            'Data Reservation — Agustus 2026'
        ));

        $this->assertCount(count($baris[2]), $baris[3], 'Jumlah sel harus sama dengan jumlah judul kolom.');

        $isi = array_combine($baris[2], $baris[3]);

        $this->assertSame('Tamu Lengkap', $isi['Nama tamu']);
        $this->assertSame('PT Contoh', $isi['Perusahaan']);
        $this->assertSame('DP 50% sudah masuk.', $isi['Remark']);
        $this->assertSame('11:00', $isi['Jam mulai']);
        $this->assertSame('14:00', $isi['Jam selesai']);
    }

    /**
     * Yang diekspor adalah hasil saringan yang sedang tampak, bukan seluruh
     * tabel. Menyaring satu PIC lalu mendapat berkas berisi segalanya adalah
     * kebocoran yang tidak diminta siapa pun.
     */
    public function test_it_exports_only_the_filtered_rows(): void
    {
        $orangLain = User::factory()->create();
        $orangLain->assignRole('staff');

        $milikStaf = $this->buatReservasi(['guest_name' => 'Punya Staf']);
        $this->buatReservasi(['guest_name' => 'Punya Orang Lain', 'pic_id' => $orangLain->id]);

        $query = Livewire::test(ListReservations::class)
            ->set('tableFilters.pic_id.value', $this->staf->id)
            ->instance()
            ->getFilteredSortedTableQuery();

        $baris = $this->bacaKembali(app(ReservationSpreadsheet::class)->unduh($query, 'uji.xlsx', 'Data Reservation — Agustus 2026'));

        $this->assertCount(4, $baris, 'Judul, baris kosong, kepala kolom, dan satu baris data.');
        $this->assertContains('Punya Staf', $baris[3]);
        $this->assertSame($milikStaf->reservation_number, $baris[3][0]);
    }

    public function test_the_filename_carries_the_period_and_the_moment(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 25)->setTime(14, 30));

        $this->assertSame(
            'reservasi-2026-07-2026-08-25-1430.xlsx',
            app(ReservationSpreadsheet::class)->namaBerkas('2026-07'),
            'Periode yang diekspor dulu, lalu kapan berkasnya dibuat.'
        );

        $this->assertSame(
            'reservasi-semua-2026-08-25-1430.xlsx',
            app(ReservationSpreadsheet::class)->namaBerkas('all')
        );
    }

    /**
     * Judul mengikuti tab bulan yang sedang dipilih.
     *
     * Dibaca dari kunci tab, bukan labelnya: label dibangun ulang tiap bulan
     * oleh ListReservations::getTabs() dan bergantung locale.
     */
    public function test_the_title_follows_the_selected_month(): void
    {
        $spreadsheet = app(ReservationSpreadsheet::class);

        $this->assertSame('Data Reservation — Agustus 2026', $spreadsheet->judul('2026-08'));
        $this->assertSame('Data Reservation — Desember 2026', $spreadsheet->judul('2026-12'));
        $this->assertSame('Data Reservation — Semua Bulan', $spreadsheet->judul('all'));
        $this->assertSame('Data Reservation — Semua Bulan', $spreadsheet->judul(null));
    }

    /**
     * Judulnya sampai ke berkas dengan periode yang benar, lewat aksi tabel —
     * bukan hanya lewat pemanggilan langsung ke servicenya.
     */
    public function test_the_export_action_carries_the_active_tab_into_the_file(): void
    {
        $this->buatReservasi(['reservation_date' => today(), 'guest_name' => 'Tamu Bulan Ini']);

        $halaman = Livewire::test(ListReservations::class)->instance();
        $spreadsheet = app(ReservationSpreadsheet::class);

        $baris = $this->bacaKembali($spreadsheet->unduh(
            $halaman->getFilteredSortedTableQuery(),
            $spreadsheet->namaBerkas($halaman->activeTab),
            $spreadsheet->judul($halaman->activeTab),
        ));

        $this->assertSame(
            'Data Reservation — '.today()->translatedFormat('F Y'),
            $baris[0][0]
        );
    }
}
