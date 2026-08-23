<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Enums\ReservationStatus;
use App\Exceptions\DuplicateReservationException;
use App\Exceptions\StaleReservationException;
use App\Filament\Resources\Reservations\Concerns\ChecksAreaConflicts;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Services\ReservationWriter;
use App\Support\ReservationInput;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditReservation extends EditRecord
{
    use ChecksAreaConflicts;

    protected static string $resource = ReservationResource::class;

    /** Versi yang dilihat pengguna saat form dimuat. */
    public ?int $loadedVersion = null;

    /** Nomor hanya dibaca di judul; ia tidak boleh muncul sebagai field form. */
    public function getTitle(): string
    {
        return 'Ubah '.$this->getRecord()->reservation_number.' · '.$this->getRecord()->guest_name;
    }

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

        // Pivot menu tidak ikut terisi sendiri karena repeaternya sengaja tidak
        // memakai nama relasi — penulisannya harus lewat ReservationWriter,
        // bukan relationship handling Filament (aturan #5 CLAUDE.md).
        $data['menu_items'] = $this->getRecord()->menus
            ->map(fn ($menu) => ['menu_id' => $menu->id, 'pax' => $menu->pivot->pax])
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ReservationInput::normalize($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->guardConfirmedStatus($record, $data);
        $this->requireRemarkWhenAreaConflicts($data, $record->getKey());

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
}
