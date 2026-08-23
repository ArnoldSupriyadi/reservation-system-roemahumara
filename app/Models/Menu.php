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

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
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
     * Urutan kategori sendiri mengikuti id-nya (aturan #14 CLAUDE.md), jadi
     * kategori yang ditambahkan belakangan muncul di bawah.
     */
    public function scopeInMenuOrder(Builder $query): void
    {
        $query
            ->leftJoin('menu_categories', 'menu_categories.id', '=', 'menus.menu_category_id')
            ->orderBy('menu_categories.id')
            ->orderBy('menus.name')
            ->select('menus.*');
    }
}
