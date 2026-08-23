<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Menu extends Model
{
    protected $fillable = ['name', 'menu_category_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * qualifyColumn(), bukan 'is_active' polos. Kalau kelak ada join ke tabel
     * yang juga punya kolom itu, kondisi tanpa nama tabel akan ditolak MySQL —
     * dan gagalnya baru muncul di halaman yang memadukan keduanya, jauh dari
     * baris ini.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class)->withPivot('pax', 'remark');
    }

    /**
     * Diurutkan menurut urutan kategori, bukan menurut abjad. Daftar menu yang
     * dibaca manusia mengikuti alur hidangan — pembuka lebih dulu, minuman
     * belakangan — dan abjad merusak alur itu.
     *
     * TANPA join ke menu_categories, meski itu tampak wajar. Urutan kategori
     * mengikuti id-nya (aturan #14 CLAUDE.md), dan id itu sudah ada di
     * menus.menu_category_id — join hanya mengulang yang sudah diketahui.
     *
     * Versi pertama memakai join, dan itu memecahkan halaman Edit reservasi
     * pada 2026-08-23: kedua tabel sama-sama punya kolom is_active dan name,
     * sehingga setiap kondisi yang tidak menyebut nama tabel ditolak MySQL
     * dengan "Column ... is ambiguous". Yang berbahaya, kueri masing-masing
     * baik-baik saja; ledakannya baru terjadi ketika scope ini dipadukan dengan
     * ->active() atau dengan pencarian tabel Filament.
     */
    public function scopeInMenuOrder(Builder $query): void
    {
        $query
            ->orderBy('menus.menu_category_id')
            ->orderBy('menus.name');
    }
}
