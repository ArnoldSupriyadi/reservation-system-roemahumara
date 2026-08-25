<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Support\MonthGrid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicCalendarController extends Controller
{
    public function __invoke(Request $request): View
    {
        // query() mengembalikan array bila pengunjung mengirim `?bulan[]=x`.
        // Disaring di sini, di batas HTTP, supaya MonthGrid tetap bertipe ketat.
        $bulan = $request->query('bulan');
        $month = MonthGrid::normalize(is_string($bulan) ? $bulan : null);
        $reservations = $this->reservationsIn($month);

        $pilih = $request->query('pilih');
        $selectedId = is_scalar($pilih) ? (int) $pilih : 0;

        return view('public.calendar', [
            'month' => $month,
            'monthLabel' => MonthGrid::label($month),
            'previousMonth' => MonthGrid::shift($month, -1),
            'nextMonth' => MonthGrid::shift($month, 1),
            'cells' => MonthGrid::cells($month),
            'byDate' => $reservations->groupBy(fn (Reservation $r) => $r->reservation_date->toDateString()),
            'total' => $reservations->count(),
            'selectedId' => $selectedId,
            // Dicari di dalam koleksi bulan yang sedang tampil, sehingga pilihan
            // dari bulan lain otomatis terabaikan.
            'selected' => $reservations->firstWhere('id', $selectedId),
        ]);
    }

    /**
     * HANYA kolom yang boleh dilihat umum.
     *
     * Batas ini sengaja ditegakkan di sini, bukan di template. Dengan select()
     * eksplisit, kolom pribadi tidak pernah dimuat ke memori — sehingga satu baris
     * ceroboh di Blade suatu hari nanti menghasilkan nilai kosong, bukan kebocoran
     * nomor HP dan catatan pembayaran tamu.
     *
     * JANGAN menambahkan phone dan email ke daftar ini. Keduanya kontak pribadi
     * tamu — satu-satunya yang tersisa tertutup, dan nilainya justru bertambah
     * sekarang karena semua konteks di sekitarnya sudah terbuka.
     *
     * Riwayat pelonggaran, semuanya atas permintaan eksplisit pemilik sistem:
     *
     * - 2026-08-22: pax dan menu style — keterangan acara, bukan identitas.
     *   Menu style diganti daftar hidangan pada 2026-08-23; kini relasi menus.
     * - 2026-08-22: company.
     * - 2026-08-22: guest_name, pic_id, dan remark. Ini pelonggaran yang jauh
     *   lebih besar dan konsekuensinya perlu diketahui siapa pun yang membaca
     *   berkas ini: remark adalah catatan internal yang di sistem ini terbiasa
     *   memuat keterangan pembayaran, dan halaman ini terbuka tanpa login serta
     *   dapat terindeks mesin pencari. Kalau suatu saat diputuskan menarik
     *   kembali, cukup hapus ketiganya dari select() di bawah — Blade akan
     *   menampilkan nilai kosong, bukan error.
     *
     * `pax_max` sengaja TIDAK ikut, dan itu bukan kelalaian. Kolomnya lahir
     * 2026-08-25 dan pelonggaran halaman ini selalu atas permintaan eksplisit
     * pemilik sistem. Akibatnya perlu diketahui: reservasi berjumlah 10–14
     * terbaca "10 orang" di halaman publik, karena `paxLabel()` membaca
     * `pax_max` sebagai null pada model yang kolomnya tidak ikut dipilih. Itu
     * angka yang sudah pasti, bukan angka yang keliru. Menambahkannya cukup
     * menyisipkan 'pax_max' ke select() di bawah.
     *
     * @return Collection<int, Reservation>
     */
    private function reservationsIn(string $month): Collection
    {
        [$year, $monthNumber] = explode('-', $month);

        return Reservation::query()
            ->select([
                'id',
                'reservation_date',
                'start_time',
                'end_time',
                'status',
                'area_id',
                'event_type_id',
                'pax',
                'guest_name',
                'company',
                'pic_id',
                'remark',
            ])
            ->with(['area:id,name', 'eventType:id,name', 'menus:id,name,menu_category_id', 'menus.category:id,name', 'pic:id,name'])
            ->whereYear('reservation_date', (int) $year)
            ->whereMonth('reservation_date', (int) $monthNumber)
            // Reservasi batal tidak ditampilkan ke umum. Blade menganggap semua
            // yang bukan CONFIRMED sebagai "Sedang dijajaki", jadi kalau yang
            // batal ikut termuat, pengunjung membaca slot yang sudah bebas
            // sebagai slot terpakai. Disaring di query, bukan di template, atas
            // alasan yang sama dengan select() di atas.
            ->where(fn ($q) => $q
                ->whereNull('status')
                ->orWhere('status', '!=', ReservationStatus::Cancelled->value))
            ->orderBy('reservation_date')
            ->orderBy('start_time')
            ->get();
    }
}
