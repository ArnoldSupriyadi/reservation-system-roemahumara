<?php

namespace App\Filament\Widgets;

use App\Enums\Ability;
use App\Enums\ReservationStatus;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use Filament\Actions\Action;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ringkasan reservasi terdekat di dashboard.
 *
 * Versi sederhana dari tabel di /cms/reservations: enam kolom, tanpa filter,
 * tanpa aksi baris. Yang lengkap tetap di resource-nya — dashboard ini untuk
 * pertanyaan "hari-hari ini ada apa saja", bukan untuk bekerja.
 */
class UpcomingReservationsWidget extends TableWidget
{
    /**
     * Di bawah TodayWidget dan AccountWidget, melebar penuh: tabel enam kolom
     * berisi remark utuh tidak terbaca kalau dijejalkan ke separuh layar.
     */
    /**
     * View sendiri, bukan filament-widgets::table-widget bawaan: rentang minggu
     * dan bulan memuat tanggal yang sudah lewat, dan itu harus diberitahukan di
     * atas tabelnya — bukan hanya tersirat dari isinya.
     */
    protected string $view = 'filament.widgets.upcoming-reservations';

    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    /**
     * Dirender langsung, alasannya sama dengan TodayWidget: sepuluh baris dari
     * satu tabel yang terindeks pada reservation_date tidak sebanding dengan
     * kotak kosong yang berkedip setiap kali dashboard dibuka.
     */
    protected static bool $isLazy = false;

    private const JUMLAH = 10;

    /**
     * Rentang yang sedang dilihat: 'terdekat', 'minggu', atau 'bulan'.
     *
     * Properti Livewire biasa, jadi menggantinya cukup memuat ulang widgetnya
     * saja — bukan seluruh halaman dashboard.
     */
    public string $rentang = 'terdekat';

    public function pilihRentang(string $rentang): void
    {
        $this->rentang = $rentang;

        // Tanpa ini tabel tetap menampilkan halaman dan urutan rentang
        // sebelumnya, karena Filament menyimpan keadaan tabelnya sendiri.
        $this->resetTable();
    }

    /**
     * Widget ikut Policy yang sama dengan resource-nya.
     *
     * Aturan #3 CLAUDE.md: yang diperiksa Ability, bukan nama role. Tanpa ini,
     * role baru yang sengaja dibuat tanpa hak lihat reservasi tetap melihat
     * sepuluh baris berisi nama tamu dan remark begitu membuka dashboard.
     */
    public static function canView(): bool
    {
        return auth()->user()?->can(Ability::ViewReservation->value) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->heading($this->judul())
            ->description($this->keterangan())
            ->headerActions([
                $this->tombolRentang('terdekat', 'Terdekat'),
                $this->tombolRentang('minggu', 'Minggu ini'),
                $this->tombolRentang('bulan', 'Bulan ini'),
            ])
            ->paginated(false)
            ->defaultSort('reservation_date')
            /*
             * Baris bisa diklik ke halaman detailnya. Ini yang membuat sorotan
             * hover punya arti — tanpa tujuan, baris yang menyala hanya menggoda
             * pengguna mengklik sesuatu yang tidak terjadi apa-apa.
             */
            ->recordUrl(fn (Reservation $record) => ReservationResource::getUrl('view', ['record' => $record]))
            ->columns([
                /*
                 * Baris yang tanggalnya sudah lewat ditandai di kolomnya
                 * sendiri, bukan hanya lewat peringatan di atas tabel.
                 * Peringatan menjelaskan daftarnya secara keseluruhan; penanda
                 * ini yang menjawab "yang mana" tanpa pembacanya perlu
                 * membandingkan setiap tanggal dengan hari ini di kepalanya.
                 */
                TextColumn::make('reservation_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->color(fn (Reservation $record) => $record->reservation_date->isBefore(today()) ? 'gray' : null)
                    ->description(fn (Reservation $record) => $record->reservation_date->isBefore(today())
                        ? $record->reservation_date->translatedFormat('l').' · sudah lewat'
                        : $record->reservation_date->translatedFormat('l')),

                TextColumn::make('start_time')
                    ->label('Jam')
                    ->formatStateUsing(fn (Reservation $record) => self::rentangJam($record)),

                TextColumn::make('guest_name')
                    ->label('Nama tamu')
                    ->weight(FontWeight::Bold)
                    ->description(fn (Reservation $record) => $record->company),

                TextColumn::make('area.name')
                    ->label('Area')
                    ->placeholder('—'),

                TextColumn::make('pax')
                    ->label('Pax')
                    ->formatStateUsing(fn (Reservation $record) => $record->paxLabel())
                    ->alignEnd(),

                // Aturan #4 CLAUDE.md: remark selalu penuh.
                // JANGAN tambahkan ->limit(), ->words(), atau ->toggleable().
                TextColumn::make('remark')
                    ->label('Remark')
                    ->wrap()
                    ->placeholder('—')
                    ->extraHeaderAttributes(['style' => 'min-width: 18rem'])
                    ->extraCellAttributes(['style' => 'min-width: 18rem']),
            ])
            ->emptyStateHeading('Tidak ada reservasi mendatang')
            ->emptyStateDescription('Reservasi yang tanggalnya sudah lewat ada di menu Reservasi.');
    }

    /**
     * Tombol pemilih rentang, yang sedang aktif diberi warna primary.
     *
     * Warna saja tidak cukup sebagai penanda — mata yang sulit membedakan
     * amber dari abu-abu tidak akan tahu mana yang aktif — jadi yang aktif juga
     * dipenuhi warnanya (filled), bukan sekadar diberi garis tepi.
     */
    private function tombolRentang(string $nilai, string $label): Action
    {
        $aktif = $this->rentang === $nilai;

        return Action::make("rentang_{$nilai}")
            ->label($label)
            ->size('sm')
            ->color($aktif ? 'primary' : 'gray')
            ->button()
            ->outlined(! $aktif)
            ->action(fn () => $this->pilihRentang($nilai));
    }

    private function judul(): string
    {
        return match ($this->rentang) {
            'minggu' => 'Reservasi minggu ini',
            'bulan' => 'Reservasi bulan ini',
            default => 'Reservasi terdekat',
        };
    }

    private function keterangan(): string
    {
        $rentang = match ($this->rentang) {
            'minggu' => today()->startOfWeek()->translatedFormat('j F').' – '.today()->endOfWeek()->translatedFormat('j F'),
            'bulan' => today()->startOfMonth()->translatedFormat('j F').' – '.today()->endOfMonth()->translatedFormat('j F'),
            default => 'Sepuluh reservasi mulai hari ini',
        };

        return $rentang.'. Yang batal tidak ditampilkan.';
    }

    /**
     * Apakah rentang yang sedang dipilih bisa memuat tanggal yang sudah lewat.
     *
     * Dipakai view untuk memutuskan menampilkan peringatan. Sengaja memeriksa
     * RENTANGNYA, bukan apakah ada baris lampau di hasilnya: peringatan yang
     * muncul-hilang tergantung isi data justru membuat pengguna berhenti
     * mempercayainya, dan di tanggal 1 setiap bulan ia memang tidak akan pernah
     * muncul meski rentangnya sama.
     */
    public function memuatTanggalLampau(): bool
    {
        return $this->rentang !== 'terdekat';
    }

    public function labelRentang(): string
    {
        return match ($this->rentang) {
            'minggu' => 'minggu ini',
            'bulan' => 'bulan ini',
            default => 'rentang ini',
        };
    }

    /**
     * Terdekat mulai hari ini, bukan besok: acara yang berlangsung hari ini
     * justru yang paling sering ditanyakan, dan menghilangkannya begitu lewat
     * tengah malam akan membuat dashboard kosong tepat di pagi acara.
     *
     * Minggu dan bulan sebaliknya memuat SATU PERIODE PENUH — dari awal minggu
     * atau awal bulan, termasuk tanggal yang sudah lewat. Sempat dipotong dari
     * hari ini, dan itu keliru: pertanyaan "bulan ini ada apa saja" hampir
     * selalu berarti seluruh bulannya, dan memotongnya membuat "Bulan ini"
     * nyaris kosong setiap akhir bulan. Karena tanggal lampau ikut, widget-nya
     * memberi peringatan di atas tabel dan menandai barisnya satu per satu —
     * lihat view filament.widgets.upcoming-reservations dan kolom Tanggal.
     *
     * Yang batal disaring di sini, sejalan dengan aturan #9 CLAUDE.md —
     * reservasi batal tidak memakai tempat, jadi memampangkannya hanya
     * mendorong keluar acara yang sungguh berjalan. Di kalender staf ia tetap
     * tampil, dicoret.
     */
    private function query(): Builder
    {
        $query = Reservation::query()
            ->with(['area:id,name'])
            ->where(fn (Builder $q) => $q
                ->whereNull('status')
                ->orWhere('status', '!=', ReservationStatus::Cancelled->value))
            ->orderBy('reservation_date')
            ->orderBy('start_time');

        return match ($this->rentang) {
            'minggu' => $query->whereBetween('reservation_date', [today()->startOfWeek(), today()->endOfWeek()]),
            'bulan' => $query->whereBetween('reservation_date', [today()->startOfMonth(), today()->endOfMonth()]),
            // Satu-satunya rentang yang menyaring tanggal lampau. Batas
            // bawahnya di sini, bukan di query bersama di atas — di sana ia
            // akan ikut berlaku untuk minggu dan bulan dan mengosongkan lagi
            // bagian periode yang sudah lewat, diam-diam.
            //
            // Dipotong jumlahnya karena "terdekat" tidak punya ujung yang
            // alami; tanpa limit ia akan memuat seluruh tabel.
            default => $query
                ->whereDate('reservation_date', '>=', today())
                ->limit(self::JUMLAH),
        };
    }

    /**
     * Sama seperti di ReservationsTable: jam tunggal ditulis apa adanya, rentang
     * dipisah en dash.
     */
    private static function rentangJam(Reservation $record): string
    {
        $mulai = (string) $record->start_time;

        if (blank($record->end_time)) {
            return $mulai;
        }

        return $mulai.'–'.$record->end_time;
    }
}
