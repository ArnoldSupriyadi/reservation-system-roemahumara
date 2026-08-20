<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\EventType;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
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
                'menuStyle:id,name',
            ]))
            ->defaultSort('reservation_date')
            ->paginated(false)
            ->columns([
                Split::make([
                    TextColumn::make('reservation_number')
                        ->label('No.')
                        ->searchable()
                        ->weight(FontWeight::Bold)
                        ->color('gray')
                        ->grow(false),

                    TextColumn::make('reservation_date')
                        ->label('Tanggal')
                        ->date('d M Y')
                        ->sortable()
                        ->grow(false),

                    TextColumn::make('start_time')
                        ->label('Jam')
                        ->formatStateUsing(fn ($record) => self::timeRange($record))
                        ->sortable()
                        ->grow(false),

                    Stack::make([
                        TextColumn::make('guest_name')
                            ->label('Nama')
                            ->weight(FontWeight::Bold)
                            ->searchable(),

                        TextColumn::make('company')
                            ->label('Company')
                            ->size(TextSize::ExtraSmall)
                            ->color('gray')
                            ->searchable()
                            ->placeholder(''),
                    ]),

                    Stack::make([
                        TextColumn::make('pic.name')
                            ->label('PIC')
                            ->icon('heroicon-m-user'),

                        TextColumn::make('phone')
                            ->label('HP')
                            ->icon('heroicon-m-phone')
                            ->searchable(),
                    ])->visibleFrom('md'),

                    Stack::make([
                        TextColumn::make('eventType.name')
                            ->label('Event')
                            ->badge()
                            ->placeholder('—'),

                        TextColumn::make('area.name')
                            ->label('Area')
                            ->color('gray')
                            ->placeholder('—'),
                    ])->visibleFrom('lg'),

                    TextColumn::make('pax')
                        ->label('Pax')
                        ->numeric()
                        ->sortable()
                        ->grow(false),

                    TextColumn::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (?ReservationStatus $state) => $state?->label() ?? '—')
                        ->color(fn (?ReservationStatus $state) => match ($state) {
                            ReservationStatus::Confirmed => 'success',
                            ReservationStatus::Tentative => 'warning',
                            default => 'gray',
                        })
                        ->grow(false),
                ]),

                // Remark selalu tampil penuh. JANGAN tambahkan ->collapsible()
                // dan JANGAN memakai ->limit() pada kolom di dalamnya.
                Panel::make([
                    TextColumn::make('remark')
                        ->label('Remark')
                        ->icon('heroicon-m-chat-bubble-bottom-center-text')
                        ->wrap()
                        ->searchable(),
                ])->visible(fn ($record) => filled($record?->remark)),
            ])
            ->filters([
                SelectFilter::make('pic_id')
                    ->label('PIC')
                    ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id')),

                SelectFilter::make('event_type_id')
                    ->label('Event')
                    ->options(fn () => EventType::query()->active()->orderBy('sort_order')->pluck('name', 'id')),

                SelectFilter::make('area_id')
                    ->label('Area')
                    ->options(fn () => Area::query()->active()->orderBy('sort_order')->pluck('name', 'id')),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'confirmed' => 'CONFIRMED',
                        'tentative' => 'TENTATIVE',
                    ]),

                Filter::make('undetermined_status')
                    ->label('Status belum ditentukan')
                    ->query(fn (Builder $query) => $query->whereNull('status'))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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
