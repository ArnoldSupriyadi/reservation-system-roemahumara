<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Enums\ReservationStatus;
use App\Exceptions\DuplicateReservationException;
use App\Exceptions\StaleReservationException;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Services\ConflictChecker;
use App\Services\ReservationWriter;
use App\Support\ReservationInput;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditReservation extends EditRecord
{
    protected static string $resource = ReservationResource::class;

    /** Versi yang dilihat pengguna saat form dimuat. */
    public ?int $loadedVersion = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->loadedVersion = $this->getRecord()->version;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ReservationInput::normalize($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->guardConfirmedStatus($record, $data);

        unset($data['idempotency_key']);

        try {
            $updated = app(ReservationWriter::class)->update(
                $record,
                $data,
                $this->loadedVersion ?? $record->version,
                auth()->user(),
            );
        } catch (StaleReservationException) {
            throw ValidationException::withMessages([
                'data.version' => 'Reservasi ini baru saja diubah orang lain. '
                    .'Muat ulang halaman untuk melihat perubahan terbaru.',
            ]);
        } catch (DuplicateReservationException $e) {
            $existing = $e->existing();

            throw ValidationException::withMessages([
                'data.guest_name' => $existing
                    ? sprintf(
                        'Sudah ada reservasi atas nama %s pada %s jam %s.',
                        $existing->guest_name,
                        $existing->reservation_date->format('d/m/Y'),
                        substr((string) $existing->start_time, 0, 5)
                    )
                    : 'Reservasi dengan tanggal, nama, dan jam mulai yang sama sudah ada.',
            ]);
        }

        $this->loadedVersion = $updated->version;

        $this->warnAboutConflicts($data, $updated->id);

        return $updated;
    }

    private function guardConfirmedStatus(Model $record, array $data): void
    {
        $becomingConfirmed = ($data['status'] ?? null) === ReservationStatus::Confirmed->value
            && $record->status !== ReservationStatus::Confirmed;

        if (! $becomingConfirmed) {
            return;
        }

        if (auth()->user()?->can('confirm', $record)) {
            return;
        }

        throw ValidationException::withMessages([
            'data.status' => 'Hanya admin yang boleh menetapkan status CONFIRMED.',
        ]);
    }

    private function warnAboutConflicts(array $data, int $ignoreId): void
    {
        $conflicts = app(ConflictChecker::class)->check(
            $data['area_id'] ?? null,
            $data['reservation_date'],
            $data['start_time'],
            $data['end_time'] ?? null,
            $ignoreId,
        );

        if ($conflicts->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Area bentrok')
            ->body($conflicts
                ->map(fn (Reservation $other) => sprintf(
                    '%s jam %s',
                    $other->guest_name,
                    substr((string) $other->start_time, 0, 5)
                ))
                ->join(', '))
            ->persistent()
            ->send();
    }
}
