<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah reservasi'),
        ];
    }

    /**
     * Tab bulan: tiga bulan lalu sampai tiga bulan ke depan, plus Semua.
     */
    public function getTabs(): array
    {
        $tabs = [];

        for ($offset = -3; $offset <= 3; $offset++) {
            $month = Carbon::now()->startOfMonth()->addMonths($offset);
            $key = $month->format('Y-m');

            $tabs[$key] = Tab::make($month->translatedFormat('F Y'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereYear('reservation_date', $month->year)
                    ->whereMonth('reservation_date', $month->month));
        }

        $tabs['all'] = Tab::make('Semua');

        return $tabs;
    }

    public function getDefaultActiveTab(): string
    {
        return Carbon::now()->format('Y-m');
    }
}
