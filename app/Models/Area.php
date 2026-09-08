<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Area extends Model
{
    protected $fillable = ['name', 'photo_path', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * URL foto panduan area, atau null kalau belum ada fotonya.
     *
     * Null adalah keadaan yang sah, bukan kesalahan: area yang baru ditambah
     * belum sempat difoto. Form CMS memakai nilai ini untuk memutuskan
     * menyembunyikan pratinjaunya sama sekali — mengembalikan string kosong
     * akan menghasilkan ikon gambar rusak, yang lebih buruk daripada tidak
     * menampilkan apa-apa.
     *
     * URL-nya dibangun di sini, bukan disimpan di database. Kolomnya menyimpan
     * path relatif, sehingga foto tetap benar setelah APP_URL berganti.
     */
    public function photoUrl(): ?string
    {
        if ($this->photo_path === null) {
            return null;
        }

        return Storage::disk('public')->url($this->photo_path);
    }

    /**
     * Area lain yang secara fisik memakai ruang yang sama.
     *
     * GRAND BALLROOM meliputi BALLROOM 1 dan 2, jadi memesan salah satunya
     * membuat yang lain ikut terpakai.
     */
    public function overlaps(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'area_overlaps', 'area_id', 'overlaps_area_id');
    }

    /**
     * Menghubungkan dua area secara timbal balik.
     *
     * Selalu lewat sini, jangan attach() langsung. Relasi ini harus tersimpan
     * dua arah — kalau hanya satu, bentroknya cuma terdeteksi ketika pengguna
     * kebetulan memesan dari sisi yang benar, dan diam dari sisi sebaliknya.
     */
    public function overlapWith(self $other): void
    {
        $this->overlaps()->syncWithoutDetaching([$other->getKey()]);
        $other->overlaps()->syncWithoutDetaching([$this->getKey()]);
    }

    /**
     * Id area ini berikut semua yang meliputinya — dipakai ConflictChecker
     * sebagai daftar area yang harus ikut diperiksa.
     *
     * @return array<int, int>
     */
    public function occupiedAreaIds(): array
    {
        return [$this->getKey(), ...$this->overlaps()->pluck('areas.id')->all()];
    }
}
