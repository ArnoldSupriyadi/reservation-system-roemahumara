<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\EventType;
use Illuminate\Database\Seeder;
use RuntimeException;

class MasterSeeder extends Seeder
{
    /**
     * Area yang secara fisik memuat area lain — GRAND BALLROOM adalah BALLROOM 1
     * dan 2 dengan sekat dibuka.
     *
     * Ditulis sebagai data supaya namanya hanya ada di satu tempat. Sebelumnya
     * nama-nama ini diketik ulang di dalam method penghubungnya, dan ketika
     * daftar master diganti methodnya tidak ikut berubah: ia mencari baris yang
     * sudah tidak ada, keluar tanpa suara, dan seluruh pengecekan bentrok
     * ballroom mati sementara seeder tetap dilaporkan sukses.
     */
    private const MELIPUTI = [
        'GRAND BALLROOM' => ['BALLROOM 1', 'BALLROOM 2'],
    ];

    public function run(): void
    {
        // Ballroom ditaruh di belakang, bukan disisipkan di tengah. Urutan larik
        // ini menentukan id, dan id menentukan urutan tampil — menyisipkan di
        // tengah akan membuat urutan di mesin lama berbeda dari pemasangan baru.
        $areas = [
            'VIP 1', 'VIP 2', 'FOYE', 'INDOOR', 'SOFA', 'KORIDOR', 'OUTDOOR',
            'BALLROOM 1', 'BALLROOM 2', 'GRAND BALLROOM',
        ];
        $eventTypes = ['TEST FOOD', 'PRIVATE', 'MEETING', 'LUNCH', 'DINNER', 'GATHERING', 'BIRTHDAY', 'WEDDING', 'SEMINAR', 'WORKSHOP'];

        // Urutan tampilnya mengikuti id, dan id mengikuti urutan penyisipan di
        // sini — jadi urutan larik di atas tetap menentukan urutan di layar,
        // tanpa perlu kolom sort_order.
        foreach ($areas as $name) {
            Area::firstOrCreate(['name' => $name]);
        }

        foreach ($eventTypes as $name) {
            EventType::firstOrCreate(['name' => $name]);
        }

        // Daftar hidangan dipisahkan ke seedernya sendiri: isinya 137 item dari
        // berkas JSON, terlalu besar untuk disisipkan di antara master lain.
        $this->call(MenuSeeder::class);

        $this->linkOverlappingAreas();
    }

    /**
     * Menyimpan keterkaitan fisik dari MELIPUTI ke tabel area_overlaps.
     *
     * Tanpa hubungan ini ConflictChecker memperlakukan ketiga ballroom sebagai
     * ruangan yang tidak berkaitan, sehingga keseluruhannya bisa dipesan di atas
     * acara yang sudah ada di salah satu bagiannya tanpa peringatan apa pun.
     *
     * Nama yang tidak ketemu MELEMPAR, bukan dilewati. Satu-satunya cara itu
     * terjadi adalah MELIPUTI dan $areas sudah tidak sejalan — dan berhenti
     * dengan keras di sini jauh lebih murah daripada menemukannya berbulan-bulan
     * kemudian lewat bentrok yang tidak pernah muncul.
     */
    private function linkOverlappingAreas(): void
    {
        foreach (self::MELIPUTI as $namaInduk => $namaBagian) {
            $terdaftar = Area::whereIn('name', [$namaInduk, ...$namaBagian])
                ->get()
                ->keyBy('name');

            $hilang = array_diff([$namaInduk, ...$namaBagian], $terdaftar->keys()->all());

            if ($hilang !== []) {
                throw new RuntimeException(sprintf(
                    'MasterSeeder: area %s tidak ada di daftar $areas, padahal disebut '
                    .'di MELIPUTI. Keduanya harus sejalan, kalau tidak pengecekan bentrok '
                    .'untuk %s mati tanpa memberi tanda.',
                    implode(', ', $hilang),
                    $namaInduk
                ));
            }

            foreach ($namaBagian as $nama) {
                $terdaftar[$namaInduk]->overlapWith($terdaftar[$nama]);
            }
        }
    }
}
