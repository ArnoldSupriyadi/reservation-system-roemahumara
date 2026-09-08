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

    /**
     * Diperiksa seluruhnya dulu, baru ditulis.
     *
     * Versi pertama memeriksa sambil menulis, dan itu terbukti buruk begitu
     * dijalankan sungguhan di database yang daftar areanya versi lama: ia
     * menulis dua foto, berhenti di nama ketiga, dan hanya menyebut nama itu —
     * padahal empat nama meleset sekaligus. Akibatnya dua hal yang keduanya
     * merugikan: database tertinggal setengah terisi, dan pemakainya menempuh
     * empat putaran jalankan–gagal–betulkan untuk masalah yang sudah diketahui
     * seluruhnya sejak awal.
     */
    public function run(): void
    {
        $siapDitulis = [];
        $masalah = [];

        foreach (self::FOTO as $namaArea => $namaBerkas) {
            $area = Area::where('name', $namaArea)->first();

            if ($area === null) {
                $masalah[] = "area \"{$namaArea}\" tidak ada di database";

                continue;
            }

            // Area yang sudah punya foto tidak diusik — kalau tidak,
            // menjalankan seeder ini sekali lagi akan menimpa foto yang baru
            // diunggah staf dengan foto bawaan, tanpa ada yang meminta.
            if ($area->photo_path !== null) {
                continue;
            }

            $sumber = public_path('img/area/'.$namaBerkas);

            if (! is_file($sumber)) {
                $masalah[] = "berkas \"{$namaBerkas}\" tidak ada di public/img/area/";

                continue;
            }

            $siapDitulis[] = [$area, $namaBerkas, $sumber];
        }

        if ($masalah !== []) {
            throw new RuntimeException(
                "AreaPhotoSeeder berhenti tanpa menulis apa pun.\n- ".
                implode("\n- ", $masalah).
                "\n\nDaftar AreaPhotoSeeder::FOTO tidak lagi cocok dengan isi database. ".
                'Samakan dulu nama areanya, baru jalankan lagi.'
            );
        }

        foreach ($siapDitulis as [$area, $namaBerkas, $sumber]) {
            $tujuan = self::TUJUAN.'/'.$namaBerkas;
            Storage::disk('public')->put($tujuan, file_get_contents($sumber));

            $area->photo_path = $tujuan;
            $area->save();
        }
    }
}
