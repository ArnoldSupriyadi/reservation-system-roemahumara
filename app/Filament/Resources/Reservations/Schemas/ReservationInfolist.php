<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\ReservationStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ReservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Reservasi')
                ->columns(4)
                ->schema([
                    TextEntry::make('reservation_number')->label('No. reservasi'),

                    TextEntry::make('reservation_date')->label('Tanggal')->date('d M Y'),

                    TextEntry::make('start_time')
                        ->label('Jam')
                        ->formatStateUsing(function ($record) {
                            $start = (string) $record->start_time;

                            return blank($record->end_time)
                                ? $start.' (jam tunggal)'
                                : $start.'–'.$record->end_time.' (rentang)';
                        }),

                    TextEntry::make('guest_name')->label('Nama tamu'),
                    TextEntry::make('company')->label('Company')->placeholder('—'),
                    TextEntry::make('phone')->label('HP'),
                    TextEntry::make('email')->label('Email')->placeholder('—'),
                    TextEntry::make('pic.name')->label('PIC / Sales'),
                    TextEntry::make('pax')->label('Pax')->formatStateUsing(fn ($record) => $record->paxLabel().' orang'),
                    TextEntry::make('eventType.name')->label('Event')->placeholder('—'),
                    TextEntry::make('area.name')->label('Area')->placeholder('—'),

                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (?ReservationStatus $state) => $state?->label() ?? 'Belum ditentukan')
                        ->color(fn (?ReservationStatus $state) => match ($state) {
                            ReservationStatus::Confirmed => 'success',
                            ReservationStatus::Tentative => 'warning',
                            ReservationStatus::Cancelled => 'danger',
                            default => 'gray',
                        }),
                ]),

            Section::make('Menu')
                ->schema([
                    View::make('filament.reservation-menus')->columnSpanFull(),
                ]),

            Section::make('Remark')
                ->schema([
                    TextEntry::make('remark')
                        ->hiddenLabel()
                        ->placeholder('Tidak ada remark.')
                        ->columnSpanFull(),
                ]),

            Section::make('Riwayat perubahan')
                ->schema([
                    View::make('filament.audit-timeline')->columnSpanFull(),
                ]),
        ]);
    }
}
