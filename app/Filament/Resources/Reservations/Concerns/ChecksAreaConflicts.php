<?php

namespace App\Filament\Resources\Reservations\Concerns;

use App\Models\Reservation;
use App\Services\ConflictChecker;
use App\Services\ReservationWriter;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Perlakuan terhadap bentrok area, dipakai bersama oleh halaman Create dan Edit.
 *
 * Disatukan di sini justru karena aturannya harus berperilaku sama persis di
 * kedua halaman. Sebelumnya warnAboutConflicts() disalin di dua berkas, dan
 * salinan seperti itu berangsur berbeda tanpa ada yang menyadarinya — pengguna
 * lalu menemukan larangan yang berlaku saat membuat tapi tidak saat mengubah.
 *
 * Sikap sistem terhadap bentrok: **tidak melarang, tapi menuntut penjelasan.**
 * Melarang keras terdengar lebih aman, tapi di lapangan ada bentrok yang sah —
 * dua acara berurutan yang bersinggungan saat bongkar-pasang, atau sekat VIP 1
 * dan VIP 2 yang dibuka jadi satu ruangan. Kalau sistem menolak mentah-mentah,
 * staf akan mengosongkan kolom Area supaya bisa menyimpan, dan begitu itu
 * terjadi pengecekan bentrok mati total untuk baris itu. Menuntut alasan di
 * Remark menjaga catatannya tetap jujur.
 */
trait ChecksAreaConflicts
{
    /**
     * Menolak simpan bila ada bentrok area sementara Remark masih kosong.
     *
     * Dipanggil SEBELUM penulisan, tidak seperti warnAboutConflicts() yang
     * berjalan sesudahnya — kalau tidak, barisnya sudah terlanjur tersimpan
     * ketika penolakan muncul.
     */
    protected function requireRemarkWhenAreaConflicts(array $data, ?int $ignoreId = null): void
    {
        if (filled($data['remark'] ?? null)) {
            return;
        }

        // Duplikat persis — tanggal, nama tamu, dan jam mulai yang sama — juga
        // terbaca sebagai bentrok area, karena memang menempati area yang sama.
        // Tapi menyuruh pengguna menulis Remark untuk itu menyesatkan: yang
        // sebenarnya terjadi adalah reservasinya sudah ada. Diserahkan ke
        // ReservationWriter supaya pesan duplikatnya yang muncul, lengkap dengan
        // nama dan tanggalnya.
        if (app(ReservationWriter::class)->findDuplicate($data, $ignoreId) !== null) {
            return;
        }

        $conflicts = $this->areaConflicts($data, $ignoreId);

        if ($conflicts->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'data.remark' => sprintf(
                'Area ini bentrok dengan %s. Kalau memang disengaja — misalnya acara '
                .'berurutan atau sekat dibuka — tuliskan alasannya di Remark, lalu simpan lagi.',
                $this->describe($conflicts)
            ),
        ]);
    }

    /** Pemberitahuan setelah tersimpan: bentroknya tetap perlu terlihat. */
    protected function warnAboutConflicts(array $data, ?int $ignoreId = null): void
    {
        $conflicts = $this->areaConflicts($data, $ignoreId);

        if ($conflicts->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Area bentrok')
            ->body($this->describe($conflicts))
            ->persistent()
            ->send();
    }

    /** @return Collection<int, Reservation> */
    private function areaConflicts(array $data, ?int $ignoreId): Collection
    {
        return app(ConflictChecker::class)->check(
            $data['area_id'] ?? null,
            $data['reservation_date'],
            $data['start_time'],
            $data['end_time'] ?? null,
            $ignoreId,
        );
    }

    /** @param  Collection<int, Reservation>  $conflicts */
    private function describe(Collection $conflicts): string
    {
        return $conflicts
            ->map(fn (Reservation $other) => sprintf(
                '%s jam %s',
                $other->guest_name,
                (string) $other->start_time
            ))
            ->join(', ');
    }
}
