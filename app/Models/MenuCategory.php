<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori hidangan — pengelompokan yang dipakai daftar menu.
 *
 * Urutan tampilnya mengikuti id, sama seperti master lain (aturan #14
 * CLAUDE.md). Karena penyisipan awalnya mengikuti urutan alur hidangan —
 * pembuka lebih dulu, minuman belakangan — kategori baru yang ditambahkan
 * kemudian akan muncul di paling bawah.
 */
class MenuCategory extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }
}
