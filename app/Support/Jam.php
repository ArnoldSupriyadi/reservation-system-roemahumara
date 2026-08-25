<?php

namespace App\Support;

use JsonSerializable;
use Stringable;

/**
 * Jam dalam sehari, tanpa tanggal.
 *
 * Sebelum ini `start_time` dan `end_time` diserahkan Eloquent sebagai string
 * mentah dari MySQL ('11:00:00'), dan setiap tempat yang menampilkannya menulis
 * `substr((string) $record->start_time, 0, 5)` sendiri — tujuh salinan aturan
 * "buang detiknya", tersebar di tabel, widget, infolist, export, dan dua halaman
 * Filament. Perbandingan pun tidak bisa dipercaya bentuknya: '11:00' dan
 * '11:00:00' pernah bertabrakan, dan bekasnya masih terlihat sebagai
 * `whereRaw("TIME_FORMAT(...)")` di ReservationWriter::findDuplicate().
 *
 * Pembagian tugas dengan TimeInput: **TimeInput membaca ketikan manusia**
 * ('11', '11.00', '12.00-15.00'), **Jam adalah nilainya**. Keduanya tidak
 * digabung karena TimeInput::split() dipakai form untuk memecah rentang sebelum
 * ada satu pun nilai jam terbentuk.
 *
 * Kolom databasenya TETAP bertipe TIME dan tidak boleh diubah — `dedupe_key`
 * adalah generated stored column yang memakai TIME_FORMAT() di dalam MySQL
 * (aturan #1 CLAUDE.md).
 */
final class Jam implements JsonSerializable, Stringable
{
    private function __construct(
        public readonly int $jam,
        public readonly int $menit,
    ) {}

    /**
     * Menerima apa saja yang masuk akal sebagai jam, termasuk Jam itu sendiri.
     *
     * Sengaja permisif: nilainya datang dari form, dari seeder, dari tinker, dan
     * dari model yang sudah ter-cast. Yang tidak bisa dibaca mengembalikan null,
     * bukan melempar — penolakan input adalah tugas validasi form, dan melempar
     * di sini akan membuat halaman daftar tumbang gara-gara satu baris lama yang
     * datanya aneh.
     */
    public static function dari(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $normal = TimeInput::normalize((string) $value);

        if ($normal === null) {
            return null;
        }

        [$jam, $menit] = array_map('intval', explode(':', $normal));

        return new self($jam, $menit);
    }

    /**
     * Menit sejak tengah malam — satuan yang dipakai ConflictChecker.
     */
    public function menitSejakTengahMalam(): int
    {
        return $this->jam * 60 + $this->menit;
    }

    public function format(string $format = 'H:i'): string
    {
        return match ($format) {
            'H:i' => sprintf('%02d:%02d', $this->jam, $this->menit),
            'H:i:s' => sprintf('%02d:%02d:00', $this->jam, $this->menit),
            default => throw new \InvalidArgumentException("Format jam tidak dikenal: {$format}"),
        };
    }

    /**
     * Jam tutup venue, dari config('reservation.jam_tutup').
     *
     * Di sini, bukan diketik ulang di form dan writer: dua salinan aturan yang
     * sama berangsur berbeda, dan yang satu akan menolak apa yang diterima yang
     * lain.
     */
    public static function tutup(): self
    {
        return self::dari(config('reservation.jam_tutup'))
            ?? throw new \RuntimeException(
                'RESERVATION_CLOSING_TIME di .env tidak terbaca sebagai jam: '
                .var_export(config('reservation.jam_tutup'), true)
            );
    }

    /**
     * Lebih malam daripada jam tutup. Tepat pukul tutup masih boleh — "sampai
     * pukul 22:00" berarti 22:00 termasuk.
     */
    public function melewatiJamTutup(): bool
    {
        return self::tutup()->sebelum($this);
    }

    public function sebelum(self $lain): bool
    {
        return $this->menitSejakTengahMalam() < $lain->menitSejakTengahMalam();
    }

    public function sama(?self $lain): bool
    {
        return $lain !== null && $this->menitSejakTengahMalam() === $lain->menitSejakTengahMalam();
    }

    /**
     * Bentuk yang dilihat manusia: H:i.
     *
     * Dipakai di seluruh tampilan, dan juga oleh Filament saat mengisi
     * TextInput dari model — itu sebabnya detiknya tidak ikut. Kalau di sini
     * H:i:s, kotak isian jam akan menampilkan '11:00:00' dan staf harus
     * menghapus detiknya setiap kali menyunting.
     */
    public function __toString(): string
    {
        return $this->format('H:i');
    }

    /**
     * Bentuk yang MASUK KE activity_log: H:i:s.
     *
     * Sengaja berbeda dari __toString(), dan itu bukan kelalaian. spatie
     * mencatat nilai SESUDAH cast — `$model->getAttribute($attribute)` di
     * LogsActivity::logChanges() — lalu menyimpannya sebagai JSON. Seluruh entri
     * yang sudah tercatat sebelum kelas ini ada berisi '11:00:00' apa adanya
     * dari MySQL. Menyerialkan H:i akan membuat riwayat satu reservasi terbelah
     * dua bentuk, dan itu TIDAK bisa diperbaiki belakangan — entri lama sudah
     * tersimpan.
     */
    public function jsonSerialize(): string
    {
        return $this->format('H:i:s');
    }
}
