<?php

namespace App\Filament\Resources\MenuCategories;

use App\Filament\Resources\MenuCategories\Pages\ManageMenuCategories;
use App\Models\MenuCategory;
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

class MenuCategoryResource extends Resource
{
    protected static ?string $model = MenuCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Master';

    protected static ?string $modelLabel = 'Kategori menu';

    protected static ?string $pluralModelLabel = 'Kategori menu';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(80)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (string $state) => trim($state))
                ->helperText('Kategori baru muncul di paling bawah daftar menu, mengikuti urutan pembuatan.'),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Yang tidak aktif tidak ditawarkan saat menambah menu baru.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('index')
                    ->label('No.')
                    ->rowIndex()
                    ->color('gray')
                    ->extraHeaderAttributes(['style' => 'width:1%; white-space:nowrap'])
                    ->extraCellAttributes(['style' => 'width:1%; white-space:nowrap']),

                TextColumn::make('name')->label('Nama')->searchable(),

                // Jumlah menunya ditampilkan supaya terlihat kategori mana yang
                // masih kosong, dan mana yang tidak akan bisa dihapus.
                TextColumn::make('menus_count')
                    ->label('Jumlah menu')
                    ->counts('menus')
                    ->alignEnd()
                    ->extraHeaderAttributes(['style' => 'width:1%; white-space:nowrap'])
                    ->extraCellAttributes(['style' => 'width:1%; white-space:nowrap']),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->extraHeaderAttributes(['style' => 'width:1%; white-space:nowrap'])
                    ->extraCellAttributes(['style' => 'width:1%; white-space:nowrap']),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->action(function (MenuCategory $record, DeleteAction $action) {
                        try {
                            $record->delete();
                        } catch (QueryException $e) {
                            // 1451: masih ada menu yang menunjuk kategori ini.
                            // Ditangkap supaya pesannya menyebut jalan keluar,
                            // bukan melempar galat SQL ke muka pengguna.
                            if (($e->errorInfo[1] ?? null) === 1451) {
                                Notification::make()
                                    ->danger()
                                    ->title('Tidak bisa dihapus')
                                    ->body(sprintf(
                                        '"%s" masih dipakai %d menu. Pindahkan menunya dulu, atau nonaktifkan saja lewat kolom Aktif.',
                                        $record->name,
                                        $record->menus()->count(),
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
        return ['index' => ManageMenuCategories::route('/')];
    }
}
