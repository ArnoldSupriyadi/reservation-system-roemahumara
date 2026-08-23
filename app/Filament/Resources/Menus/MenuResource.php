<?php

namespace App\Filament\Resources\Menus;

use App\Filament\Resources\Menus\Pages\ManageMenus;
use App\Models\Menu;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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

class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cake';

    protected static string|UnitEnum|null $navigationGroup = 'Master';

    protected static ?string $modelLabel = 'Menu';

    protected static ?string $pluralModelLabel = 'Menu';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Nama TIDAK lagi dipaksa huruf kapital. Daftar hidangan dibaca
            // manusia, dan "Capelli D'angelo Alle Vongole" jauh lebih terbaca
            // daripada versi kapital semuanya.
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(120)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (string $state) => trim($state)),

            // Select, bukan teks bebas: kategori yang salah ketik akan tampil
            // sebagai kelompok baru di daftar menu tanpa ada yang menyadarinya.
            Select::make('menu_category_id')
                ->label('Kategori')
                ->required()
                ->searchable()
                ->preload()
                ->relationship('category', 'name', fn ($query) => $query->active()->orderBy('id'))
                // Kategori baru bisa dibuat langsung dari sini, tanpa harus
                // meninggalkan form menu yang sedang diisi.
                ->createOptionForm([
                    TextInput::make('name')
                        ->label('Nama kategori')
                        ->required()
                        ->maxLength(80)
                        ->unique('menu_categories', 'name'),
                ]),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Yang tidak aktif tidak muncul di form reservasi baru.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Mengikuti urutan kategori di Menu::CATEGORIES, bukan id maupun
            // abjad — daftar menu dibaca menurut alur hidangan.
            ->modifyQueryUsing(fn ($query) => $query->inMenuOrder())
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

                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->extraHeaderAttributes(['style' => 'width:1%; white-space:nowrap'])
                    ->extraCellAttributes(['style' => 'width:1%; white-space:nowrap']),
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
                    ->action(function (Menu $record, DeleteAction $action) {
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
        return ['index' => ManageMenus::route('/')];
    }
}
