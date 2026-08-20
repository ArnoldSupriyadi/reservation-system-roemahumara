<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Ability;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | UnitEnum | null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Role';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama role')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Role $record) => in_array($record?->name, ['admin', 'staff'], true))
                ->helperText('Huruf kecil tanpa spasi, misalnya manajer.')
                ->dehydrateStateUsing(fn (string $state) => Str::slug(trim($state))),

            CheckboxList::make('permissions')
                ->label('Kemampuan')
                ->relationship('permissions', 'name')
                ->options(fn () => static::abilityOptions())
                ->descriptions(fn () => static::abilityDescriptions())
                ->columns(2)
                ->bulkToggleable()
                ->required(),
        ]);
    }

    /**
     * Pilihan dibatasi pada delapan Ability yang dikenali kode. Kalau suatu saat
     * ada baris permission liar di database, baris itu tidak muncul dan tidak
     * bisa diberikan — inilah yang menjaga nama permission tetap menjadi kode.
     *
     * Kuncinya wajib id permission, bukan namanya. Pivot role_has_permissions
     * menyimpan permission_id bertipe integer, sehingga mengirim nama akan
     * menggagalkan penyimpanan dengan MySQL error 1366.
     *
     * @return array<int, string>
     */
    private static function abilityOptions(): array
    {
        $order = array_flip(Ability::values());

        return Permission::query()
            ->whereIn('name', Ability::values())
            ->get()
            ->sortBy(fn (Permission $permission) => $order[$permission->name])
            ->mapWithKeys(fn (Permission $permission) => [
                $permission->getKey() => Ability::from($permission->name)->label(),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private static function abilityDescriptions(): array
    {
        $notes = [
            Ability::ConfirmReservation->value => 'Memberi kemampuan ini berarti mempercayai pengguna untuk mengunci status reservasi.',
            Ability::ManageRole->value => 'Hati-hati. Pengguna dengan kemampuan ini bisa mengubah hak akses siapa pun, termasuk dirinya sendiri.',
        ];

        return Permission::query()
            ->whereIn('name', array_keys($notes))
            ->get()
            ->mapWithKeys(fn (Permission $permission) => [
                $permission->getKey() => $notes[$permission->name],
            ])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->formatStateUsing(fn (string $state) => Str::headline($state))
                    ->searchable(),

                TextColumn::make('permissions_count')
                    ->label('Jumlah kemampuan')
                    ->counts('permissions'),

                TextColumn::make('users_count')
                    ->label('Jumlah pengguna')
                    ->counts('users'),
            ])
            ->recordActions([
                EditAction::make()->after(fn () => static::flushPermissionCache()),
                DeleteAction::make()->after(fn () => static::flushPermissionCache()),
            ]);
    }

    /**
     * Filament menyimpan kemampuan lewat sync() pada pivot, yang tidak memicu
     * event model apa pun — sehingga trait RefreshesPermissionCache milik spatie
     * tidak ikut jalan dan cache tetap memegang nilai lama.
     *
     * Gejalanya menipu: datanya sudah tersimpan, tetapi hak aksesnya belum
     * berlaku, dan staf melaporkannya sebagai "sistem tidak menyimpan perubahan".
     */
    public static function flushPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
