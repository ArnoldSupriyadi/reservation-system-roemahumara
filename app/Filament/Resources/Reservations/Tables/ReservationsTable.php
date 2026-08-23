<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\EventType;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'pic:id,name',
                'area:id,name',
                'eventType:id,name',
                'menus:id,name',
            ]))
            ->defaultSort('reservation_date')
            ->paginated(false)
            ->striped()
            /*
             * Kolom biasa, bukan Split/Stack, supaya baris header tampil.
             *
             * Filament mematikan <thead> begitu ada satu saja komponen layout di
             * level atas (HasColumns::pushColumns menyetel hasColumnsLayout, lalu
             * index.blade.php hanya merender <thead> kalau flag itu false). Tanpa
             * header, angka pax dan nomor reservasi melayang tanpa keterangan dan
             * pembacanya harus menebak. Itu sebabnya Panel remark ikut dilepas —
             * remark sekarang kolom biasa yang di-wrap, tetap utuh tanpa limit.
             */
            ->columns([
                // Nomor urut baris, bukan nomor reservasi. Ia ikut urutan tampil,
                // jadi berubah begitu tabel disortir atau difilter — gunanya
                // menghitung dan menunjuk baris di layar, bukan mengacu reservasi.
                // Untuk itu ada kolom No. Reservasi di sebelahnya.
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex()
                    ->alignEnd()
                    ->color('gray'),

                TextColumn::make('reservation_number')
                    ->label('No. Reservasi')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Bold)
                    ->fontFamily(FontFamily::Mono),

                TextColumn::make('reservation_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->description(fn ($record) => $record->reservation_date->translatedFormat('l'))
                    ->sortable(),

                TextColumn::make('start_time')
                    ->label('Jam')
                    ->formatStateUsing(fn ($record) => self::timeRange($record))
                    ->description(fn ($record) => blank($record->end_time) ? 'jam tunggal' : 'rentang')
                    ->sortable(),

                TextColumn::make('guest_name')
                    ->label('Nama tamu')
                    ->weight(FontWeight::Bold)
                    ->description(fn ($record) => $record->company)
                    ->searchable(['guest_name', 'company'])
                    ->sortable(),

                TextColumn::make('pic.name')
                    ->label('PIC')
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('HP')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('eventType.name')
                    ->label('Event')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('area.name')
                    ->label('Area')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('menus.name')
                    ->label('Menu')
                    ->badge()
                    ->color('gray')
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('pax')
                    ->label('Pax')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?ReservationStatus $state) => $state?->label() ?? 'Belum ditentukan')
                    ->color(fn (?ReservationStatus $state) => match ($state) {
                        ReservationStatus::Confirmed => 'success',
                        ReservationStatus::Tentative => 'warning',
                        ReservationStatus::Cancelled => 'danger',
                        default => 'gray',
                    }),

                // Aturan #4 CLAUDE.md: remark selalu penuh.
                // JANGAN tambahkan ->limit(), ->words(), atau ->toggleable().
                TextColumn::make('remark')
                    ->label('Remark')
                    ->wrap()
                    ->searchable()
                    ->placeholder('—')
                    ->extraHeaderAttributes(['style' => 'min-width: 18rem'])
                    ->extraCellAttributes(['style' => 'min-width: 18rem']),
            ])
            ->filters([
                SelectFilter::make('pic_id')
                    ->label('PIC')
                    ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id')),

                SelectFilter::make('event_type_id')
                    ->label('Event')
                    ->options(fn () => EventType::query()->active()->orderBy('id')->pluck('name', 'id')),

                SelectFilter::make('area_id')
                    ->label('Area')
                    ->options(fn () => Area::query()->active()->orderBy('id')->pluck('name', 'id')),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'confirmed' => 'CONFIRMED',
                        'tentative' => 'TENTATIVE',
                        'cancelled' => 'CANCEL',
                    ]),

                Filter::make('undetermined_status')
                    ->label('Status belum ditentukan')
                    ->query(fn (Builder $query) => $query->whereNull('status'))
                    ->toggle(),

                Filter::make('hide_cancelled')
                    ->label('Sembunyikan yang batal')
                    ->query(fn (Builder $query) => $query->where(
                        fn (Builder $q) => $q
                            ->whereNull('status')
                            ->orWhere('status', '!=', ReservationStatus::Cancelled->value)
                    ))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                // Dibuka di tab baru, bukan mengganti halaman: staf biasanya
                // mencetak beberapa reservasi berturut-turut dan tidak perlu
                // kembali ke daftar tiap kali.
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-m-printer')
                    ->color('gray')
                    ->url(fn ($record) => route('filament.cms.reservations.pdf', $record))
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada reservasi')
            ->emptyStateDescription('Tekan tombol tambah untuk mencatat reservasi pertama.');
    }

    private static function timeRange($record): string
    {
        $start = substr((string) $record->start_time, 0, 5);

        if (blank($record->end_time)) {
            return $start;
        }

        return $start.'–'.substr((string) $record->end_time, 0, 5);
    }
}
