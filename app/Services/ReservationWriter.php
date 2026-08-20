<?php

namespace App\Services;

use App\Exceptions\DuplicateReservationException;
use App\Exceptions\StaleReservationException;
use App\Models\Reservation;
use App\Models\User;
use App\Support\TimeInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReservationWriter
{
    private const DUPLICATE_ERROR = 1062;

    private const DEDUPE_INDEX = 'uniq_reservations_dedupe';

    private const IDEMPOTENCY_INDEX = 'reservations_idempotency_key_unique';

    public function create(array $data, string $idempotencyKey, User $actor): Reservation
    {
        $existing = Reservation::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($data, $idempotencyKey, $actor) {
                $reservation = new Reservation();
                $reservation->fill($data);
                $reservation->idempotency_key = $idempotencyKey;
                $reservation->created_by = $actor->id;
                $reservation->version = 1;
                $reservation->save();

                return $reservation;
            });
        } catch (QueryException $e) {
            // Submit kedua yang tiba bersamaan dengan yang pertama.
            if ($this->violates($e, self::IDEMPOTENCY_INDEX)) {
                return Reservation::where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            if ($this->violates($e, self::DEDUPE_INDEX)) {
                throw new DuplicateReservationException($this->findDuplicate($data));
            }

            throw $e;
        }
    }

    public function update(
        Reservation $reservation,
        array $data,
        int $expectedVersion,
        User $actor
    ): Reservation {
        return DB::transaction(function () use ($reservation, $data, $expectedVersion, $actor) {
            $fresh = Reservation::whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->version !== $expectedVersion) {
                throw new StaleReservationException();
            }

            $fresh->fill($data);
            $fresh->version = $fresh->version + 1;
            $fresh->updated_by = $actor->id;

            try {
                $fresh->save();
            } catch (QueryException $e) {
                if ($this->violates($e, self::DEDUPE_INDEX)) {
                    throw new DuplicateReservationException($this->findDuplicate($data));
                }

                throw $e;
            }

            return $fresh;
        });
    }

    private function violates(QueryException $e, string $index): bool
    {
        return ($e->errorInfo[1] ?? null) === self::DUPLICATE_ERROR
            && str_contains($e->getMessage(), $index);
    }

    private function findDuplicate(array $data): ?Reservation
    {
        // Ketiga klausa sengaja mencerminkan definisi generated column dedupe_key,
        // supaya baris yang ditemukan di sini persis baris yang ditabrak constraint.
        //
        // whereTime() tidak dipakai karena menghasilkan `time(start_time) = ?`, dan
        // TIME() MySQL mengembalikan string '12:00:00'. Nilai yang masuk ke sini
        // berformat H:i dari ReservationInput::normalize(), sehingga perbandingannya
        // menjadi '12:00:00' = '12:00' dan tidak pernah cocok.
        return Reservation::query()
            ->whereDate('reservation_date', $data['reservation_date'])
            ->whereRaw('LOWER(TRIM(guest_name)) = ?', [mb_strtolower(trim($data['guest_name']))])
            ->whereRaw("TIME_FORMAT(start_time, '%H:%i') = ?", [TimeInput::normalize($data['start_time'])])
            ->first();
    }
}
