<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Str::headline($state)),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            // Tidak ada DeleteAction di mana pun. UserPolicy::delete() selalu false,
            // dan reservations.pic_id serta created_by memakai restrictOnDelete —
            // menghapus pengguna yang pernah menangani reservasi akan selalu gagal
            // di level database. Menonaktifkan lewat is_active adalah jalur yang benar.
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
