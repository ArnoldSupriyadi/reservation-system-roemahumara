<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(100),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(150)
                ->unique(ignoreRecord: true),

            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                ->helperText(fn (string $operation) => $operation === 'edit'
                    ? 'Kosongkan jika password tidak diubah.'
                    : null),

            Select::make('roles')
                ->label('Role')
                ->relationship('roles', 'name')
                ->required()
                ->preload()
                ->searchable()
                ->getOptionLabelFromRecordUsing(fn (Role $record) => Str::headline($record->name))
                ->helperText('Satu pengguna satu role. Kalau butuh kombinasi kemampuan yang belum ada, buat role baru di menu Role.'),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Yang tidak aktif tidak bisa login dan tidak muncul sebagai pilihan PIC.')
                ->disabled(fn (?User $record) => $record?->is(auth()->user()) ?? false)
                ->hint(fn (?User $record) => $record?->is(auth()->user())
                    ? 'Anda tidak bisa menonaktifkan akun sendiri.'
                    : null),
        ]);
    }
}
