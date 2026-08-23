<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Menu extends Model
{
    /**
     * Kategori hidangan, sekaligus urutan tampilnya.
     *
     * Ditulis di sini, bukan diambil dari nilai unik di database, supaya salah
     * ketik tidak diam-diam melahirkan kategori baru — "Sop" dan "SOP" akan
     * jadi dua kelompok terpisah di layar tanpa ada yang menyadarinya.
     * MenuSeederTest memastikan setiap kategori di database/data/menu.json ada
     * di daftar ini.
     *
     * "Gaya Sajian" bukan hidangan. Ia menampung BUFFET dan AL CARTE, dua baris
     * peninggalan sebelum konsep menu style diganti daftar hidangan pada
     * 2026-08-23; salah satunya masih dipakai reservasi lama.
     */
    public const CATEGORIES = [
        'Hidangan Pembuka',
        'Aneka Jajanan Ringan',
        'Selada',
        'Sop',
        'Hidangan Nasi & Mie',
        'Pasta',
        'Lauk Unggas',
        'Lauk Daging',
        'Boga Bahari',
        'Aneka Hidangan Sayuran',
        'Spesial Menu Anak',
        'Aneka Nasi',
        'Aneka Sambal',
        'Hidangan Penutup',
        'Signature Rempah Umara',
        'Signature Healthy Drink',
        'Smoothies',
        'Fresh Juice',
        'Tea Selection',
        'Artisan Flavored Tea',
        'Classic Coffee & Chocolate Selection',
        'Manual Brew & Cold Brew Selection',
        'Soft Drink',
        'Mineral Water',
        'Gaya Sajian',
    ];

    protected $fillable = ['name', 'category', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class)->withPivot('pax');
    }

    /**
     * Diurutkan menurut urutan kategori di CATEGORIES, bukan menurut abjad.
     * Daftar menu yang dibaca manusia mengikuti alur hidangan — pembuka lebih
     * dulu, minuman belakangan — dan abjad merusak alur itu.
     */
    public function scopeInMenuOrder(Builder $query): void
    {
        $urutan = collect(self::CATEGORIES)
            ->map(fn (string $kategori) => '?')
            ->implode(', ');

        $query
            ->orderByRaw("FIELD(category, {$urutan})", self::CATEGORIES)
            ->orderBy('name');
    }
}
