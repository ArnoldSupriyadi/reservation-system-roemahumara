<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Tentative => 'TENTATIVE',
            self::Confirmed => 'CONFIRMED',
            self::Cancelled => 'CANCEL',
        };
    }

    /**
     * Istilah untuk halaman publik. CONFIRMED dan TENTATIVE adalah kosakata
     * internal yang tidak perlu dibawa ke luar.
     *
     * CANCEL tidak punya padanan publik dengan sengaja: reservasi yang batal
     * tidak ditampilkan di halaman publik sama sekali (lihat
     * PublicCalendarController). Kalau ia sampai tampil, pengunjung akan
     * membaca slot yang sebenarnya kosong sebagai slot terpakai.
     */
    public function publicLabel(): string
    {
        return match ($this) {
            self::Tentative => 'Tentatif',
            self::Confirmed => 'BOOKED',
            self::Cancelled => 'Batal',
        };
    }

    /**
     * Ikon pendamping label publik.
     *
     * Bukan hiasan: chip kalender publik membedakan status lewat warna, dan
     * warna saja tidak terbaca oleh pengunjung yang buta warna. Label teks
     * sudah menutup itu, ikon menambah satu isyarat lagi yang terbaca sekilas.
     */
    public function publicIcon(): string
    {
        return match ($this) {
            self::Tentative => 'heroicon-m-clock',
            self::Confirmed => 'heroicon-m-lock-closed',
            self::Cancelled => 'heroicon-m-x-mark',
        };
    }

    /**
     * Status kosong dibaca publik sebagai Tentatif.
     *
     * Dipusatkan di sini supaya Blade tidak perlu mengulang literal
     * '?? "Tentatif"' di tiga tempat — pengulangan itulah yang dulu membuat
     * label dan ikon gampang berbeda antara chip, legenda, dan panel detail.
     */
    public static function publicOrDefault(?self $status): self
    {
        return $status ?? self::Tentative;
    }

    /** Reservasi batal tidak memakai tempat, jadi tidak ikut dihitung bentrok. */
    public function blocksArea(): bool
    {
        return $this !== self::Cancelled;
    }
}
