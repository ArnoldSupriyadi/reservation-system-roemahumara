<?php

namespace App\Http\Controllers;

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
     * JANGAN menambahkan guest_name, company, phone, email, remark, pax, atau
     * pic_id ke daftar ini.
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
            ])
            ->with(['area:id,name', 'eventType:id,name'])
            ->whereYear('reservation_date', (int) $year)
            ->whereMonth('reservation_date', (int) $monthNumber)
            ->orderBy('reservation_date')
            ->orderBy('start_time')
            ->get();
    }
}
