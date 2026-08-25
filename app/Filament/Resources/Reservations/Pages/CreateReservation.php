<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Enums\ReservationStatus;
use App\Exceptions\DuplicateReservationException;
use App\Filament\Resources\Reservations\Concerns\ChecksAreaConflicts;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Services\ReservationWriter;
use App\Support\ReservationInput;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateReservation extends CreateRecord
{
    use ChecksAreaConflicts;

    protected static string $resource = ReservationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ReservationInput::normalize($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $this->guardConfirmedStatus($data);
        $this->requireRemarkWhenAreaConflicts($data);

        $key = $data['idempotency_key'] ?? (string) Str::uuid();
        unset($data['idempotency_key']);

        try {
            $reservation = app(ReservationWriter::class)->create($data, $key, auth()->user());
        } catch (DuplicateReservationException $e) {
            $this->throwDuplicateError($e);
        }

        $this->warnAboutConflicts($data, $reservation->id);

        return $reservation;
    }

    private function guardConfirmedStatus(array $data): void
    {
        if (($data['status'] ?? null) !== ReservationStatus::Confirmed->value) {
            return;
        }

        if (auth()->user()?->can('confirm', Reservation::class)) {
            return;
        }

        throw ValidationException::withMessages([
            'data.status' => 'Hanya admin yang boleh menetapkan status CONFIRMED.',
        ]);
    }

    private function throwDuplicateError(DuplicateReservationException $e): never
    {
        $existing = $e->existing();

        $message = $existing
            ? sprintf(
                'Sudah ada reservasi atas nama %s pada %s jam %s.',
                $existing->guest_name,
                $existing->reservation_date->format('d/m/Y'),
                (string) $existing->start_time
            )
            : 'Reservasi dengan tanggal, nama, dan jam mulai yang sama sudah ada.';

        throw ValidationException::withMessages(['data.guest_name' => $message]);
    }
}
