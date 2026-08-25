<?php

namespace App\Exceptions;

use App\Models\Reservation;
use Exception;
use Illuminate\Support\Collection;

/**
 * Aturan isi reservasi yang ditegakkan ReservationWriter.
 *
 * Halaman Filament sudah memeriksa hal yang sama dan memberi pesan yang jauh
 * lebih ramah lewat ValidationException, jadi pengguna biasa tidak akan pernah
 * melihat exception ini. Ia ada untuk jalur yang melewati form — seeder,
 * tinker, command, dan kode yang ditulis nanti — supaya aturannya benar-benar
 * berlaku, bukan sekadar disepakati di lapisan UI.
 */
class InvalidReservationException extends Exception
{
    /** @var Collection<int, Reservation> */
    private Collection $conflicts;

    private function __construct(string $message, ?Collection $conflicts = null)
    {
        parent::__construct($message);

        $this->conflicts = $conflicts ?? collect();
    }

    public static function missingArea(): self
    {
        return new self(
            'Area wajib diisi. Reservasi tanpa area tidak bisa diperiksa bentroknya, '
            .'sehingga slot yang sudah terpakai bisa dipesan ulang tanpa peringatan.'
        );
    }

    public static function outsideOperatingHours(string $field, string $jam, string $buka, string $tutup): self
    {
        return new self(sprintf(
            '%s %s di luar jam operasional %s–%s. Kalau jam venue berubah, setel '
            .'RESERVATION_OPENING_TIME dan RESERVATION_CLOSING_TIME di .env.',
            $field,
            $jam,
            $buka,
            $tutup
        ));
    }

    public static function invalidPaxRange(int $bawah, int $atas): self
    {
        return new self(sprintf(
            'Rentang pax %d–%d tidak masuk akal: angka tertinggi harus lebih besar '
            .'daripada Pax. Kalau jumlahnya sudah pasti, kosongkan pax_max.',
            $bawah,
            $atas
        ));
    }

    /** @param  Collection<int, Reservation>  $conflicts */
    public static function unexplainedConflict(Collection $conflicts): self
    {
        return new self(
            sprintf(
                'Area bentrok dengan %s, dan Remark kosong. Bentrok boleh saja terjadi, '
                .'tapi alasannya harus tertulis di Remark.',
                $conflicts
                    ->map(fn (Reservation $other) => sprintf(
                        '%s jam %s',
                        $other->guest_name,
                        (string) $other->start_time
                    ))
                    ->join(', ')
            ),
            $conflicts
        );
    }

    /** @return Collection<int, Reservation> */
    public function conflicts(): Collection
    {
        return $this->conflicts;
    }
}
