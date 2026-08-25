<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Mengubah hasil query reservasi menjadi berkas .xlsx.
 *
 * Memakai openspout, yang sudah ikut sebagai dependensi Filament — tidak perlu
 * menambah maatwebsite/excel hanya untuk satu tombol. Openspout menulis sambil
 * berjalan (streaming), jadi jumlah barisnya tidak menahan memori.
 *
 * Disengaja BUKAN lewat Filament\Actions\ExportAction. Yang itu mengantre
 * pekerjaannya lewat job batch dan menuntut tabel `exports` berikut worker yang
 * hidup; hasilnya sampai ke pengguna sebagai notifikasi beberapa saat kemudian.
 * Untuk volume sistem ini — belasan reservasi sebulan, setahun penuh pun di
 * bawah dua ratus baris — berkasnya selesai seketika, dan menunggu antrean
 * hanya menambah satu bagian yang bisa rusak diam-diam kalau worker mati.
 */
class ReservationSpreadsheet
{
    /**
     * Judul kolom, berikut cara mengambil nilainya dari satu reservasi.
     *
     * Ditulis sebagai satu larik supaya judul dan isinya tidak bisa bergeser
     * sendiri-sendiri — menambah kolom di satu tempat tapi lupa di tempat lain
     * menghasilkan berkas yang isinya melenceng satu kolom, dan itu tidak
     * terlihat sampai ada yang membacanya di Excel.
     *
     * @var array<string, callable(Reservation): (string|int|null)>
     */
    private function kolom(): array
    {
        return [
            'No. Reservasi' => fn (Reservation $r) => $r->reservation_number,
            'Tanggal' => fn (Reservation $r) => $r->reservation_date->format('Y-m-d'),
            'Hari' => fn (Reservation $r) => $r->reservation_date->translatedFormat('l'),
            'Jam mulai' => fn (Reservation $r) => (string) $r->start_time,
            'Jam selesai' => fn (Reservation $r) => $r->end_time?->format('H:i'),
            'Nama tamu' => fn (Reservation $r) => $r->guest_name,
            'Perusahaan' => fn (Reservation $r) => $r->company,
            'HP' => fn (Reservation $r) => $r->phone,
            'Email' => fn (Reservation $r) => $r->email,
            'PIC' => fn (Reservation $r) => $r->pic?->name,
            'Event' => fn (Reservation $r) => $r->eventType?->name,
            'Area' => fn (Reservation $r) => $r->area?->name,
            'Pax' => fn (Reservation $r) => $r->pax,
            'Menu' => fn (Reservation $r) => $r->menus
                ->map(fn ($menu) => $menu->name.' ('.$menu->pivot->pax.')')
                ->implode(', '),
            'Status' => fn (Reservation $r) => $r->status?->label() ?? 'Belum ditentukan',
            'Remark' => fn (Reservation $r) => $r->remark,
        ];
    }

    /**
     * @param  Builder<Reservation>  $query  sudah tersaring dan terurut oleh pemanggilnya
     * @param  string  $judul  baris judul di atas kepala kolom, menyebut periode yang diekspor
     */
    public function unduh(Builder $query, string $namaBerkas, string $judul): BinaryFileResponse
    {
        $kolom = $this->kolom();

        // Berkas sementara lalu diunduh, bukan openToBrowser(): aksi Filament
        // berjalan di dalam permintaan Livewire, dan menulis langsung ke output
        // akan menyisipkan isi berkas ke tengah balasan JSON-nya.
        $path = tempnam(sys_get_temp_dir(), 'reservasi-').'.xlsx';

        $options = new Options;
        // Judulnya digabung selebar tabel. openspout menghitung baris dari 1
        // dan kolom dari 0, jadi ini A1 sampai kolom terakhir di baris pertama.
        $options->mergeCells(0, 1, count($kolom) - 1, 1);

        $writer = new Writer($options);
        $writer->openToFile($path);

        /*
         * Judul, satu baris kosong, baru kepala kolom.
         *
         * Baris kosongnya bukan hiasan: tanpa jeda, Excel membaca judul sebagai
         * bagian dari tabel yang sama begitu penggunanya menekan Sort/Filter
         * atau membuat PivotTable — dan judulnya ikut tersortir sebagai data.
         */
        $writer->addRow(Row::fromValues(
            [$judul],
            (new Style)->setFontBold()->setFontSize(14)
        ));

        // Satu sel kosong, bukan larik kosong: openspout menulis larik kosong
        // sebagai <row/> tanpa isi, dan pembaca spreadsheet melewatinya — jeda
        // yang dimaksud tidak pernah sampai ke berkasnya.
        $writer->addRow(Row::fromValues(['']));

        $writer->addRow(Row::fromValues(
            array_keys($kolom),
            (new Style)->setFontBold()
        ));

        $baris = (new Style)->setShouldWrapText(false);

        // chunk, bukan get(): tanpa itu seluruh hasil query masuk memori
        // sekaligus, dan alasan memilih openspout jadi hilang.
        $query->with(['pic:id,name', 'eventType:id,name', 'area:id,name', 'menus:id,name'])
            ->chunk(200, function ($reservasi) use ($writer, $kolom, $baris) {
                foreach ($reservasi as $r) {
                    $writer->addRow(Row::fromValues(
                        array_map(fn (callable $ambil) => $ambil($r), array_values($kolom)),
                        $baris
                    ));
                }
            });

        $writer->close();

        return response()
            ->download($path, $namaBerkas, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Nama berkas: periode yang diekspor, lalu tanggal dan jam pembuatannya.
     *
     * Contoh: reservasi-2026-08-2026-08-25-1022.xlsx dibaca sebagai "isi bulan
     * Agustus 2026, diambil 25 Agustus 2026 pukul 10:22". Keduanya perlu —
     * periodenya menjawab "berkas ini isinya apa", jamnya menjawab "yang mana
     * yang paling baru" ketika satu bulan yang sama diekspor berkali-kali
     * setelah datanya berubah.
     *
     * Tanpa penanda waktu, berkas ekspor menumpuk di folder Unduhan sebagai
     * "reservasi (3).xlsx" dan tidak ada yang tahu lagi isinya sampai kapan.
     */
    public function namaBerkas(?string $tabAktif = null): string
    {
        $periode = blank($tabAktif) || $tabAktif === 'all' ? 'semua' : $tabAktif;

        return 'reservasi-'.$periode.'-'.now()->format('Y-m-d-Hi').'.xlsx';
    }

    /**
     * Judul yang menyebut periode yang sedang dilihat.
     *
     * Diambil dari KUNCI tab bulan di ListReservations ('2026-08' atau 'all'),
     * bukan dari labelnya: label dibangun ulang tiap bulan oleh getTabs() dan
     * bergantung locale, sedangkan kuncinya tetap dan bisa dibaca sebagai
     * tanggal.
     */
    public function judul(?string $tabAktif): string
    {
        if (blank($tabAktif) || $tabAktif === 'all') {
            return 'Data Reservation — Semua Bulan';
        }

        $bulan = Carbon::canBeCreatedFromFormat($tabAktif, 'Y-m')
            ? Carbon::createFromFormat('Y-m', $tabAktif)->startOfMonth()
            : null;

        return 'Data Reservation — '.($bulan?->translatedFormat('F Y') ?? $tabAktif);
    }
}
