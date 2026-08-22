<?php

namespace App\Filament\Resources\Areas;

use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Models\Area;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use UnitEnum;

class AreaResource extends Resource
{
    protected static ?string $model = Area::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-map-pin';

    protected static string | UnitEnum | null $navigationGroup = 'Master';

    protected static ?string $modelLabel = 'Area';

    protected static ?string $pluralModelLabel = 'Area';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(80)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (string $state) => mb_strtoupper(trim($state))),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Yang tidak aktif tidak muncul di form reservasi baru.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                // Nomor urut baris, bukan kolom di database. sort_order sengaja dihapus
                // 2026-08-22; daftar ini diurutkan id, dan angka di layar hanya untuk
                // memudahkan menghitung serta menunjuk baris.
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex()
                    ->color('gray')
                    // width:1% membuat kolom menyempit sampai selebar isinya.
                    // Tanpa itu tiga kolom berbagi rata lebar tabel dan angkanya
                    // terdorong jauh dari nama.
                    ->extraHeaderAttributes(['style' => 'width:1%; white-space:nowrap'])
                    ->extraCellAttributes(['style' => 'width:1%; white-space:nowrap']),

                TextColumn::make('name')->label('Nama')->searchable(),
                // Menyempit juga, supaya kolom Nama yang mengambil sisa lebar tabel.
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->extraHeaderAttributes(['style' => 'width:1%; white-space:nowrap'])
                    ->extraCellAttributes(['style' => 'width:1%; white-space:nowrap']),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->action(function (Area $record, DeleteAction $action) {
                        try {
                            $record->delete();
                        } catch (QueryException $e) {
                            if (($e->errorInfo[1] ?? null) === 1451) {
                                Notification::make()
                                    ->danger()
                                    ->title('Tidak bisa dihapus')
                                    ->body(sprintf(
                                        '"%s" sudah dipakai reservasi. Nonaktifkan saja lewat kolom Aktif.',
                                        $record->name
                                    ))
                                    ->send();

                                $action->halt();
                            }

                            throw $e;
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAreas::route('/')];
    }
}
