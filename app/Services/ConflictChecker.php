<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\Reservation;
use App\Support\Jam;
use Illuminate\Support\Collection;

class ConflictChecker
{
    /**
     * Reservasi lain yang memakai area sama pada tanggal sama dengan waktu tumpang tindih.
     *
     * @return Collection<int, Reservation>
     */
    public function check(
        ?int $areaId,
        string $date,
        Jam|string $startTime,
        Jam|string|null $endTime,
        ?int $ignoreId = null
    ): Collection {
        if ($areaId === null) {
            return collect();
        }

        [$start, $end] = $this->window($startTime, $endTime);

        // Bukan hanya area yang dipilih, tapi juga yang secara fisik meliputinya.
        // GRAND BALLROOM memakai ruang BALLROOM 1 dan 2, jadi memesan salah
        // satunya membuat yang lain ikut terpakai. Tanpa ini sistem diam saat
        // seluruh ballroom dipesan di atas acara yang sudah ada di salah satu
        // bagiannya.
        $areaIds = Area::find($areaId)?->occupiedAreaIds() ?? [$areaId];

        return Reservation::query()
            ->with(['pic:id,name'])
            ->whereIn('area_id', $areaIds)
            ->whereDate('reservation_date', $date)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            // Reservasi yang sudah dibatalkan tidak memakai tempat. Ikut
            // menghitungnya akan memunculkan peringatan bentrok palsu yang
            // menghalangi pengguna memakai slot yang sebenarnya sudah bebas.
            ->where(fn ($q) => $q
                ->whereNull('status')
                ->orWhere('status', '!=', ReservationStatus::Cancelled->value))
            ->get()
            ->filter(function (Reservation $other) use ($start, $end) {
                [$otherStart, $otherEnd] = $this->window($other->start_time, $other->end_time);

                return $start < $otherEnd && $otherStart < $end;
            })
            ->values();
    }

    /**
     * Rentang efektif sebuah reservasi dalam menit sejak tengah malam.
     * Reservasi tanpa end_time diasumsikan berdurasi default.
     *
     * Menerima Jam maupun string: pemanggilnya bisa berupa model yang sudah
     * ter-cast, atau array mentah dari form dan seeder.
     *
     * @return array{0: int, 1: int}
     */
    private function window(Jam|string $startTime, Jam|string|null $endTime): array
    {
        $start = Jam::dari($startTime)->menitSejakTengahMalam();
        $selesai = Jam::dari($endTime);

        $end = $selesai !== null
            ? $selesai->menitSejakTengahMalam()
            : $start + config('reservation.default_duration_minutes');

        return [$start, $end];
    }
}
