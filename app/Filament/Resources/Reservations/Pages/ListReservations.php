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

    /**
     * Urutan bawaan tabel, ditulis sekali dan dipakai di dua tempat di bawah.
     */
    private const URUTAN_BAWAAN = 'reservation_date:asc';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah reservasi'),
        ];
    }

    /**
     * Menyamakan keadaan urutan yang TERSIMPAN dengan yang TAMPIL.
     *
     * Filament menyimpan urutan aktif di properti $tableSort, sementara
     * defaultSort() diterapkan langsung ke kueri tanpa mengisi properti itu.
     * Akibatnya tabel tampil terurut menaik padahal $tableSort masih null —
     * dan klik pertama pada judul kolom Tanggal, yang menyetel "menaik", tidak
     * mengubah apa pun yang terlihat. Pengguna mengira tombolnya rusak dan
     * mengklik berkali-kali.
     */
    public function mount(): void
    {
        parent::mount();

        $this->tableSort ??= self::URUTAN_BAWAAN;
    }

    /**
     * Filament memutar tiga keadaan: menaik, menurun, lalu TANPA urutan. Yang
     * ketiga tampil persis sama dengan bawaan, sehingga klik berikutnya kembali
     * tidak terasa. Di sini keadaan ketiga dipetakan balik ke bawaan, jadi
     * putarannya tinggal dua dan setiap klik selalu mengubah sesuatu.
     */
    public function sortTable(?string $column = null, ?string $direction = null): void
    {
        parent::sortTable($column, $direction);

        $this->tableSort ??= self::URUTAN_BAWAAN;
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
