<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Mengisi foto bawaan tiap area dari berkas yang ikut repo.
 *
 * Foto yang diunggah staf lewat /cms/areas tersimpan di disk `public`, yang
 * berada di storage/ dan TIDAK ikut git — rsync deploy meng-exclude storage/,
 * jadi unggahan itu selamat dari setiap deploy tapi hilang kalau server dipasang
 * ulang. Sepuluh berkas di public/img/area/ ada justru untuk itu: ia sumber
 * bawaan yang ikut berpindah ke mesin dan server mana pun.
 *
 * Sengaja TIDAK ikut `db:seed` polos, sejalan dengan ReservationDemoSeeder:
 * pemasangan baru tidak seharusnya diam-diam menyalin 1,7 MB gambar.
 *
 * Aman dijalankan berulang. Area yang SUDAH punya foto tidak diusik — kalau
 * tidak, menjalankan seeder ini sekali lagi akan menimpa foto yang baru saja
 * diunggah staf dengan foto bawaan, tanpa ada yang meminta.
 */
class AreaPhotoSeeder extends Seeder
{
    /**
     * Nama area → nama berkas di public/img/area/.
     *
     * Didaftarkan tegas, bukan diturunkan dari nama areanya dengan membuang
     * spasi. Aturan turunan seperti itu bekerja untuk kesepuluh nama hari ini
     * lalu diam-diam meleset pada nama berikutnya yang polanya sedikit berbeda,
     * dan melesetnya berupa area tanpa foto — bukan error. Pelajaran yang sama
     * sudah dibayar sekali di MasterSeeder::MELIPUTI.
     */
    private const FOTO = [
        'VIP 1' => 'VIP1.jpg',
        'VIP 2' => 'VIP2.jpg',
        'FOYE' => 'FOYE.jpg',
        'INDOOR' => 'INDOOR.jpg',
        'SOFA' => 'SOFA.jpg',
        'KORIDOR' => 'KORIDOR.jpg',
        'OUTDOOR' => 'OUTDOOR.jpg',
        'BALLROOM 1' => 'BALLROOM1.jpg',
        'BALLROOM 2' => 'BALLROOM2.jpg',
        'GRAND BALLROOM' => 'GRANDBALLROOM.jpg',
    ];

    /** Folder tujuan di dalam disk `public`. */
    private const TUJUAN = 'area';

    public function run(): void
    {
        foreach (self::FOTO as $namaArea => $namaBerkas) {
            $area = Area::where('name', $namaArea)->first();

            // Melempar, tidak melewati. Area yang tidak ketemu berarti daftar
            // master sudah berubah tanpa daftar di atas ikut berubah, dan
            // seeder yang diam dalam keadaan itu akan dilaporkan sukses
            // sementara sebagian area tidak pernah dapat foto.
            if ($area === null) {
                throw new RuntimeException(
                    "Area \"{$namaArea}\" tidak ada di database. ".
                    'Daftar AreaPhotoSeeder::FOTO tidak lagi cocok dengan MasterSeeder.'
                );
            }

            if ($area->photo_path !== null) {
                continue;
            }

            $sumber = public_path('img/area/'.$namaBerkas);

            if (! is_file($sumber)) {
                throw new RuntimeException(
                    "Berkas foto \"{$namaBerkas}\" tidak ada di public/img/area/."
                );
            }

            $tujuan = self::TUJUAN.'/'.$namaBerkas;
            Storage::disk('public')->put($tujuan, file_get_contents($sumber));

            $area->photo_path = $tujuan;
            $area->save();
        }
    }
}
