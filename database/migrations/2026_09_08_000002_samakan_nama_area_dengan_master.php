<?php

use App\Models\Area;
use App\Models\Reservation;
use Illuminate\Database\Migrations\Migration;

/**
 * Menyamakan nama area di server lama dengan daftar MasterSeeder.
 *
 * Server yang dipasang sebelum 2026-08-28 memegang daftar master versi lama
 * (ALL BALLROOM 1–4). Daftarnya diganti hari itu jadi GRAND BALLROOM 1–2, tapi
 * perubahan itu tidak pernah sampai ke server yang sudah hidup: deploy
 * menjalankan `migrate`, TIDAK PERNAH `db:seed`. Seeder hanya berpengaruh pada
 * pemasangan baru.
 *
 * Karena itu perubahan daftar master yang harus menjangkau server yang sudah
 * berjalan perlu dibawa migrasi, seperti berkas ini — bukan dengan menyunting
 * MasterSeeder saja.
 *
 * **Jangan membetulkannya dengan menjalankan MasterSeeder di server.** Seeder
 * itu memakai `firstOrCreate(['name' => ...])`, yang mencari berdasarkan nama.
 * Karena "FOYE" belum ada, ia akan MEMBUAT BARIS BARU alih-alih mengganti nama
 * "FOYER" — menghasilkan empat pasang area yang artinya sama, sama-sama aktif,
 * sama-sama muncul di form, dan staf menebak harus memilih yang mana.
 *
 * Ini migrasi DATA, bukan struktur. Pantas dilakukan justru karena yang diubah
 * adalah data master — daftar tetap yang memang mengikuti kode. Tidak ada satu
 * pun baris reservasi yang disentuh.
 */
return new class extends Migration
{
    /** Nama lama → nama di MasterSeeder. */
    private const RENAME = [
        'FOYER' => 'FOYE',
        'REGULAR' => 'INDOOR',
        'SOFA REGULAR' => 'SOFA',
        'ALL BALLROOM' => 'GRAND BALLROOM',
    ];

    /** Tidak ada padanannya di daftar master yang baru. */
    private const DIHAPUS = ['BALLROOM 3', 'BALLROOM 4'];

    public function up(): void
    {
        foreach (self::RENAME as $lama => $baru) {
            $this->gantiNama($lama, $baru);
        }

        foreach (self::DIHAPUS as $nama) {
            $this->buang($nama);
        }
    }

    /**
     * Mengembalikan nama lamanya.
     *
     * Yang TIDAK bisa dikembalikan: BALLROOM 3 dan 4 yang sudah terhapus.
     * Membuatnya ulang akan menghasilkan id baru, dan itu bukan pembalikan
     * melainkan baris lain yang kebetulan senama. Dicatat terang-terangan di
     * sini supaya yang menjalankan `migrate:rollback` tahu apa yang tidak ia
     * dapatkan kembali.
     */
    public function down(): void
    {
        foreach (self::RENAME as $lama => $baru) {
            $this->gantiNama($baru, $lama);
        }
    }

    /**
     * Rename, bukan hapus-lalu-buat: id-nya tetap, sehingga reservasi yang
     * sudah menunjuk area itu ikut terbawa. Lewat save() per baris, bukan
     * update() massal (aturan #2 CLAUDE.md).
     */
    private function gantiNama(string $lama, string $baru): void
    {
        $area = Area::where('name', $lama)->first();

        if ($area === null) {
            return;
        }

        // Kalau nama tujuannya sudah dipakai baris lain, berhenti. Memaksanya
        // hanya akan ditolak unique constraint, dan menggagalkan seluruh deploy
        // demi kerapian daftar master bukan pertukaran yang sepadan. Keadaan
        // ini menuntut mata manusia: dua baris untuk satu ruangan berarti ada
        // yang sudah membuatnya dengan tangan.
        if (Area::where('name', $baru)->exists()) {
            return;
        }

        $area->name = $baru;
        $area->save();
    }

    /**
     * Menghapus area yang tidak lagi ada di daftar master.
     *
     * Hanya kalau benar-benar tidak dipakai. Area yang masih ditunjuk reservasi
     * ditolak foreign key, dan migrasi yang memaksanya akan MENJATUHKAN DEPLOY
     * di produksi. Gantinya dinonaktifkan: tidak muncul lagi di form reservasi
     * baru, tapi reservasi lamanya tetap utuh dan tetap terbaca.
     *
     * Baris `area_overlaps` ikut terhapus sendiri — kolomnya cascadeOnDelete.
     */
    private function buang(string $nama): void
    {
        $area = Area::where('name', $nama)->first();

        if ($area === null) {
            return;
        }

        if (Reservation::where('area_id', $area->id)->exists()) {
            $area->is_active = false;
            $area->save();

            return;
        }

        $area->delete();
    }
};
