<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Daftar hidangan Roemah Umara — 24 kategori, 137 item.
 *
 * Datanya ada di database/data/menu.json, bukan ditulis sebagai larik PHP di
 * sini. Berkas JSON itu yang dikirim pihak restoran; menyalinnya ke dalam kode
 * berarti setiap pembaruan menu menuntut penyuntingan tangan yang gampang
 * meleset satu-dua item tanpa ketahuan.
 *
 * Dipanggil dari MasterSeeder, jadi ikut `php artisan db:seed`. Aman diulang:
 * memakai firstOrCreate atas nama, sehingga menu yang namanya sudah ada tidak
 * digandakan dan kategorinya yang sudah disunting tangan tidak ditimpa.
 */
class MenuSeeder extends Seeder
{
    public const SUMBER = 'database/data/menu.json';

    public function run(): void
    {
        $path = base_path(self::SUMBER);

        if (! is_file($path)) {
            throw new RuntimeException('Berkas menu tidak ditemukan: '.self::SUMBER);
        }

        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $tidakDikenal = collect($data['categories'])
            ->pluck('category')
            ->reject(fn (string $kategori) => in_array($kategori, Menu::CATEGORIES, true));

        if ($tidakDikenal->isNotEmpty()) {
            // Dihentikan, bukan dibiarkan lewat. Kategori yang tidak terdaftar
            // akan tersimpan tapi tidak pernah muncul di Select master maupun
            // di urutan tampil — menunya seolah hilang.
            throw new RuntimeException(
                'Kategori berikut belum terdaftar di Menu::CATEGORIES: '.$tidakDikenal->join(', ')
            );
        }

        $jumlah = 0;

        foreach ($data['categories'] as $kategori) {
            foreach ($kategori['items'] as $nama) {
                Menu::firstOrCreate(
                    ['name' => $nama],
                    ['category' => $kategori['category'], 'is_active' => true],
                );

                $jumlah++;
            }
        }

        $this->command?->info("Menu: {$jumlah} item dalam ".count($data['categories']).' kategori.');
    }
}
