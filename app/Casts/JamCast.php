<?php

namespace App\Casts;

use App\Support\Jam;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Kolom TIME MySQL <-> App\Support\Jam.
 *
 * @implements CastsAttributes<Jam, Jam|string|null>
 */
class JamCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Jam
    {
        return Jam::dari($value);
    }

    /**
     * Ditulis sebagai H:i:s, bukan H:i.
     *
     * MySQL menerima keduanya untuk kolom TIME, tapi yang tersimpan selalu
     * berdetik. Menulisnya lengkap membuat nilai yang dikirim sama persis dengan
     * nilai yang dibaca kembali — jadi `isDirty()` tidak menyala hanya karena
     * bentuk penulisannya berbeda, dan activity_log tidak mencatat perubahan
     * yang tidak pernah terjadi.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return Jam::dari($value)?->format('H:i:s');
    }

    /**
     * Bentuk yang keluar lewat attributesToArray(): string H:i.
     *
     * Wajib ada, bukan pemanis. Filament mengisi form dari
     * `$record->attributesToArray()` (EditRecord.php:139), dan tanpa serialize()
     * yang masuk ke state Livewire adalah objek Jam — Livewire menolaknya dengan
     * "Property type not supported in Livewire" dan halaman Edit tumbang dengan
     * HTTP 500. Ditemukan lewat test, bukan di produksi.
     *
     * H:i, bukan H:i:s, karena inilah yang muncul di kotak isian jam. Nilai yang
     * masuk ke activity_log tidak lewat sini — itu memakai getAttribute() lalu
     * jsonSerialize(), dan tetap H:i:s demi kesinambungan riwayat.
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof Jam ? $value->format('H:i') : null;
    }
}
