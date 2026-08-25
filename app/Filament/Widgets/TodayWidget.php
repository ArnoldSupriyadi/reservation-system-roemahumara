<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/**
 * Tanggal dan jam berjalan di dashboard.
 *
 * Ada gunanya di sini, bukan sekadar hiasan: staf mencatat reservasi sambil
 * menerima telepon, dan "hari ini tanggal berapa" adalah pertanyaan yang paling
 * sering ditanyakan ke layar sebelum mengisi kolom Tanggal.
 */
class TodayWidget extends Widget
{
    protected string $view = 'filament.widgets.today';

    /**
     * Di atas AccountWidget (-3), jadi tanggal adalah hal pertama yang terbaca.
     */
    protected static ?int $sort = -4;

    protected int|string|array $columnSpan = 1;

    /**
     * Dirender langsung, tidak menunggu permintaan Livewire kedua.
     *
     * Widget Filament lazy secara bawaan, dan itu masuk akal untuk widget yang
     * query-nya berat. Di sini isinya cuma tanggal hari ini — menundanya justru
     * membuat dashboard tampil sebagai dua kotak kosong selama sesaat setiap
     * kali dibuka, dan kotak kosong terbaca sebagai "tidak ada apa-apa".
     */
    protected static bool $isLazy = false;
}
