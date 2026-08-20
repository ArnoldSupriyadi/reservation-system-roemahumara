<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Carbon;
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
        string $startTime,
        ?string $endTime,
        ?int $ignoreId = null
    ): Collection {
        if ($areaId === null) {
            return collect();
        }

        [$start, $end] = $this->window($startTime, $endTime);

        return Reservation::query()
            ->with(['pic:id,name'])
            ->where('area_id', $areaId)
            ->whereDate('reservation_date', $date)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
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
     * @return array{0: int, 1: int}
     */
    private function window(string $startTime, ?string $endTime): array
    {
        $start = $this->minutes($startTime);

        $end = $endTime !== null
            ? $this->minutes($endTime)
            : $start + config('reservation.default_duration_minutes');

        return [$start, $end];
    }

    private function minutes(string $time): int
    {
        $parsed = Carbon::createFromFormat('H:i', substr($time, 0, 5));

        return $parsed->hour * 60 + $parsed->minute;
    }
}
