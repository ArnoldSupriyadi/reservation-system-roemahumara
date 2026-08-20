<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Sengaja kosong. Scaffold menaruh DeleteAction di sini, tetapi pengguna
     * tidak pernah boleh dihapus: reservations.pic_id dan created_by memakai
     * restrictOnDelete, sehingga penghapusan selalu gagal di level database.
     * UserPolicy::delete() memang sudah menyembunyikannya, tapi menghapusnya
     * di sini juga membuat tombol tidak hidup kembali kalau policy berubah.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
