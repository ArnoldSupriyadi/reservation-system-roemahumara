<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReservation extends ViewRecord
{
    protected static string $resource = ReservationResource::class;

    /** Nomor dulu, baru nama tamu — supaya judul halaman bisa dirujuk lewat telepon. */
    public function getTitle(): string
    {
        return $this->getRecord()->reservation_number.' · '.$this->getRecord()->guest_name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Cetak PDF')
                ->icon('heroicon-m-printer')
                ->color('gray')
                ->url(fn () => route('filament.cms.reservations.pdf', $this->getRecord()))
                ->openUrlInNewTab(),

            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
