<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\Ability;
use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\EventType;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\User;
use App\Support\TimeInput;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Tamu')
                ->columns(2)
                ->schema([
                    // Bukan field, hanya keterangan. Namanya sengaja BUKAN
                    // 'reservation_number': nomor ditetapkan NumberSequence di dalam
                    // transaksi saat disimpan, jadi menawarkannya sebagai isian akan
                    // mengundang pengguna menimpanya.
                    //
                    // Di Create nomornya belum ada dan JANGAN ditebak — duplikat yang
                    // ditolak tidak membakar nomor, dan dua pembuatan bersamaan akan
                    // menebak angka yang sama padahal hanya satu yang mendapatkannya.
                    Placeholder::make('reservation_number_display')
                        ->label('No. reservasi')
                        ->columnSpanFull()
                        ->content(fn (?Reservation $record) => $record?->reservation_number
                            ?? 'Dibuat otomatis setelah disimpan.'),

                    DatePicker::make('reservation_date')
                        ->label('Tanggal')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    TextInput::make('guest_name')
                        ->label('Nama tamu')
                        ->required()
                        ->maxLength(150),

                    TextInput::make('company')
                        ->label('Company')
                        ->maxLength(150)
                        ->helperText('Opsional'),

                    TextInput::make('phone')
                        ->label('HP')
                        ->required()
                        ->tel()
                        ->maxLength(30)
                        ->placeholder('0812 3456 7890'),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(150)
                        ->helperText('Opsional'),

                    Select::make('pic_id')
                        ->label('PIC / Sales')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id')),
                ]),

            Section::make('Detail acara')
                ->columns(2)
                ->schema([
                    Select::make('event_type_id')
                        ->label('Event')
                        ->options(fn () => EventType::query()->active()->orderBy('id')->pluck('name', 'id'))
                        ->helperText('Opsional'),

                    // Wajib. Area yang kosong membuat ConflictChecker melewati
                    // baris ini sepenuhnya, sehingga kewajiban menjelaskan bentrok
                    // di Remark bisa dihindari hanya dengan tidak mengisi area.
                    Select::make('area_id')
                        ->label('Area')
                        ->required()
                        ->options(fn () => Area::query()->active()->orderBy('id')->pluck('name', 'id')),

                    TextInput::make('pax')
                        ->label('Pax')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Kalau jumlahnya berupa perkiraan, isi angka terendah lalu tulis rentangnya di Remark.'),

                    TextInput::make('start_time')
                        ->label('Jam mulai')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('11.00')
                        ->helperText('Boleh 11, 11.00, atau 11:00. Menulis 12.00-15.00 di sini akan otomatis terpecah.')
                        ->rules([
                            fn (): Closure => function (string $attribute, $value, Closure $fail) {
                                $split = TimeInput::split($value);

                                if ($split['start'] === null) {
                                    $fail('Jam mulai tidak dikenali. Contoh yang benar: 11, 11.00, atau 11:00.');

                                    return;
                                }

                                if ($split['end'] !== null && $split['end'] <= $split['start']) {
                                    $fail('Pada rentang yang diketik di sini, jam selesai harus lebih lambat daripada jam mulai.');
                                }
                            },
                        ]),

                    Toggle::make('has_end_time')
                        ->label('Sampai jam tertentu')
                        ->dehydrated(false)
                        ->live()
                        ->afterStateHydrated(fn (Toggle $component, $state, $record) => $component->state(filled($record?->end_time)))
                        ->afterStateUpdated(function (bool $state, callable $set) {
                            if (! $state) {
                                $set('end_time', null);
                            }
                        }),

                    TextInput::make('end_time')
                        ->label('Jam selesai')
                        ->maxLength(20)
                        ->placeholder('15.00')
                        ->visible(fn (callable $get) => (bool) $get('has_end_time'))
                        ->rules([
                            fn (callable $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                $end = TimeInput::normalize($value);

                                if ($end === null) {
                                    $fail('Jam selesai tidak dikenali. Contoh yang benar: 15, 15.00, atau 15:00.');

                                    return;
                                }

                                $start = TimeInput::split($get('start_time'))['start'];

                                if ($start !== null && $end <= $start) {
                                    $fail('Jam selesai harus lebih lambat daripada jam mulai.');
                                }
                            },
                        ]),

                    Select::make('status')
                        ->label('Status')
                        ->options(self::statusOptions())
                        ->placeholder('Belum ditentukan')
                        ->helperText(fn () => self::canConfirm()
                            ? null
                            : 'Anda tidak punya hak untuk menetapkan CONFIRMED.'),
                ]),

            /*
             * Menu bersifat opsional dan boleh berisi banyak item, masing-masing
             * dengan jumlah porsinya sendiri.
             *
             * Kuncinya 'menu_items', bukan 'menus'. Kalau memakai nama relasinya,
             * Filament akan mencoba mengisi dan menyimpannya sendiri lewat
             * relationship handling, dan penulisan pivot lolos dari
             * ReservationWriter — melanggar aturan #5 CLAUDE.md sekaligus
             * membuat sinkronisasinya terjadi di luar transaksi.
             */
            Section::make('Menu')
                ->description('Opsional. Jumlah porsi tiap item boleh berbeda dari jumlah tamu.')
                ->schema([
                    Repeater::make('menu_items')
                        ->hiddenLabel()
                        ->dehydrated()
                        ->default([])
                        ->addActionLabel('Tambah menu')
                        ->columns(3)
                        ->itemLabel(fn (array $state) => filled($state['remark'] ?? null) ? '• ada catatan' : null)
                        ->schema([
                            Select::make('menu_id')
                                ->label('Menu')
                                ->required()
                                ->searchable()
                                ->columnSpan(2)
                                // Dikelompokkan per kategori: 137 item dalam satu
                                // daftar rata tidak mungkin ditelusuri dengan mata.
                                ->options(fn () => self::menuOptions()),

                            TextInput::make('pax')
                                ->label('Porsi')
                                ->required()
                                ->numeric()
                                ->minValue(1)
                                ->default(fn (callable $get) => $get('../../pax')),

                            // Catatan per hidangan, terpisah dari Remark
                            // reservasi. Yang ini dibaca dapur saat menyiapkan
                            // hidangan itu; digabungkan ke Remark reservasi,
                            // permintaan "tidak pedas" akan tenggelam di antara
                            // catatan soal sekat ruangan dan pembayaran.
                            Textarea::make('remark')
                                ->label('Catatan')
                                ->rows(2)
                                ->columnSpanFull()
                                ->placeholder('Tidak pedas, tanpa kacang, saus dipisah…'),
                        ])
                        // Satu menu tidak boleh dipesan dua kali dalam satu
                        // reservasi; pivot-nya berkunci gabungan dan akan
                        // menolak dengan galat SQL yang tidak menolong.
                        ->distinct()
                        ->columnSpanFull(),
                ]),

            Section::make('Catatan')
                ->schema([
                    Textarea::make('remark')
                        ->label('Remark')
                        ->rows(4)
                        ->columnSpanFull()
                        ->helperText('Ditampilkan penuh di daftar reservasi.'),
                ]),

            Hidden::make('idempotency_key')
                ->default(fn () => (string) Str::uuid())
                ->dehydrated(),
        ]);
    }

    /**
     * Pilihan menu, dikelompokkan per kategori dan diurutkan menurut alur
     * hidangan — pembuka lebih dulu, minuman belakangan.
     *
     * @return array<string, array<int, string>>
     */
    private static function menuOptions(): array
    {
        return Menu::query()
            ->active()
            ->with('category:id,name')
            ->inMenuOrder()
            ->get()
            ->groupBy(fn (Menu $menu) => $menu->category?->name ?? 'Tanpa kategori')
            ->map(fn ($items) => $items->pluck('name', 'id')->all())
            ->all();
    }

    private static function canConfirm(): bool
    {
        return auth()->user()?->can(Ability::ConfirmReservation->value) ?? false;
    }

    /**
     * CONFIRMED hanya ditawarkan kepada yang punya kemampuannya. Ini
     * kenyamanan UI — penegakan sebenarnya ada di CreateReservation dan
     * EditReservation.
     *
     * Perhatikan bahwa yang dicek adalah kemampuan, bukan nama role.
     * Ketika role "manajer" dibuat lewat UI dan diberi kemampuan
     * reservation.confirm, opsi ini langsung muncul untuknya tanpa
     * perubahan kode apa pun.
     *
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [ReservationStatus::Tentative->value => 'TENTATIVE'];

        if (self::canConfirm()) {
            $options[ReservationStatus::Confirmed->value] = 'CONFIRMED';
        }

        // CANCEL tidak digerbangi kemampuan apa pun: siapa pun yang boleh
        // mengubah reservasi boleh membatalkannya. Perhatikan konsekuensinya —
        // staf tanpa reservation.confirm yang membatalkan reservasi CONFIRMED
        // tidak akan bisa mengembalikannya sendiri.
        $options[ReservationStatus::Cancelled->value] = 'CANCEL';

        return $options;
    }
}
