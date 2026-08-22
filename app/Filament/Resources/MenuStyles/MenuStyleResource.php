<?php

namespace App\Filament\Resources\MenuStyles;

use App\Filament\Resources\MenuStyles\Pages\ManageMenuStyles;
use App\Models\MenuStyle;
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

class MenuStyleResource extends Resource
{
    protected static ?string $model = MenuStyle::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cake';

    protected static string | UnitEnum | null $navigationGroup = 'Master';

    protected static ?string $modelLabel = 'Menu style';

    protected static ?string $pluralModelLabel = 'Menu style';

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
                TextColumn::make('name')->label('Nama')->searchable(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->action(function (MenuStyle $record, DeleteAction $action) {
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
        return ['index' => ManageMenuStyles::route('/')];
    }
}
