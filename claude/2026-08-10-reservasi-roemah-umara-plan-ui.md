# Sistem Reservasi Roemah Umara — Implementation Plan, Bagian 2: UI Filament

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Prasyarat:** Task 0 sampai 11 pada `2026-08-10-reservasi-roemah-umara-plan.md` sudah selesai dan seluruh testnya hijau. Dokumen ini melanjutkan penomoran dari sana.

**Goal:** Membangun seluruh antarmuka sistem reservasi memakai Filament v5, di atas backend yang sudah teruji.

**Architecture:** Filament Resource untuk CRUD, ditambah satu custom Page untuk kalender. Penyimpanan reservasi **tidak** diserahkan ke Filament — halaman Create dan Edit meng-override `handleRecordCreation()` dan `handleRecordUpdate()` agar melewati `ReservationWriter`, sehingga idempotency, optimistic lock, dan penanganan duplikat tetap berlaku.

**Tech Stack (terverifikasi 10 Agustus 2026):** PHP 8.3.1, Laravel 12.65.0, Filament v5.7.6, Livewire v4.3.5, MySQL 8.

## Global Constraints

Berlaku penuh dari dokumen bagian 1, ditambah:

- **Remark selalu ditampilkan penuh.** Di tabel memakai `Panel` **tanpa** `->collapsible()`. Dilarang memakai `->limit()` atau `->words()` pada kolom remark.
- **Penyimpanan wajib lewat `ReservationWriter`.** Dilarang membiarkan Filament memanggil `$record->save()` sendiri untuk reservasi.
- **Otorisasi memakai Model Policy** yang sudah dibuat di Task 11, yang mengecek `Ability` lewat `spatie/laravel-permission`.
- **Dilarang mengecek nama role di mana pun**, termasuk di Resource. Tidak ada `hasRole('admin')`. Yang dipakai adalah `auth()->user()->can(Ability::X->value)`. Aturan ini berlaku sampai ke tingkat penyembunyian tombol, karena role baru harus langsung bekerja tanpa menyentuh kode.
- **Tidak memakai Filament Shield.** Permission dinamai menurut kemampuan bisnis (delapan `Ability`), bukan per-CRUD-per-Resource. Role dikelola lewat `RoleResource` buatan sendiri di Task 18.
- **Tidak ada pagination.** Volume ± 15 reservasi per bulan.

## Catatan API Filament v5

Diverifikasi dari dokumentasi resmi 5.x pada 10 Agustus 2026. Pola Filament v3 **tidak** berlaku:

| Hal | v5 |
|---|---|
| Form | `public static function form(Schema $schema): Schema`, memakai `Filament\Schemas\Schema` dan `->components([...])` |
| Field form | `Filament\Forms\Components\*` |
| Layout form | `Filament\Schemas\Components\*` |
| Aksi tabel | `Filament\Actions\*` — **bukan** `Filament\Tables\Actions\*` |
| Method tabel | `->recordActions([...])`, `->toolbarActions([...])` |
| Layout tabel | `Filament\Tables\Columns\Layout\{Split, Stack, Grid, Panel}` |
| Struktur berkas | `app/Filament/Resources/<Plural>/` berisi `Pages/`, `Schemas/`, `Tables/` |
| Navigation icon | `protected static string \| BackedEnum \| null $navigationIcon` |
| Navigation group | `protected static string \| UnitEnum \| null $navigationGroup` |

Jika sebuah pemanggilan ternyata tidak ada, jangan menebak — buka
`https://filamentphp.com/docs/5.x/` dan cocokkan.

---

## Task 12: Resource reservasi dan skema form

**Files:**
- Create: `app/Filament/Resources/Reservations/ReservationResource.php`
- Create: `app/Filament/Resources/Reservations/Schemas/ReservationForm.php`
- Create: `app/Filament/Resources/Reservations/Pages/{ListReservations,CreateReservation,EditReservation,ViewReservation}.php`
- Create: `app/Filament/Resources/Reservations/Tables/ReservationsTable.php` (kerangka, diisi Task 13)

**Interfaces:**
- Consumes: `Reservation` (Task 4), `ReservationPolicy` (Task 11), master dari Task 3
- Produces: `ReservationResource::class` terdaftar di panel; `ReservationForm::configure(Schema $schema): Schema`

- [ ] **Step 1: Hasilkan kerangka resource**

```bash
php artisan make:filament-resource Reservation --view
```

Expected: berkas terbentuk di `app/Filament/Resources/Reservations/`, termasuk empat
halaman di `Pages/`, satu skema di `Schemas/`, dan satu tabel di `Tables/`.

- [ ] **Step 2: Atur metadata resource**

Ganti isi `app/Filament/Resources/Reservations/ReservationResource.php`:

```php
<?php

namespace App\Filament\Resources\Reservations;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Filament\Resources\Reservations\Schemas\ReservationForm;
use App\Filament\Resources\Reservations\Tables\ReservationsTable;
use App\Models\Reservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string | UnitEnum | null $navigationGroup = 'Reservasi';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Reservasi';

    protected static ?string $pluralModelLabel = 'Reservasi';

    protected static ?string $recordTitleAttribute = 'guest_name';

    public static function form(Schema $schema): Schema
    {
        return ReservationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReservationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
            'create' => CreateReservation::route('/create'),
            'view' => ViewReservation::route('/{record}'),
            'edit' => EditReservation::route('/{record}/edit'),
        ];
    }
}
```

- [ ] **Step 3: Tulis skema form**

Ganti isi `app/Filament/Resources/Reservations/Schemas/ReservationForm.php`:

```php
<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\Ability;
use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
                        ->options(fn () => EventType::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->helperText('Opsional'),

                    Select::make('menu_style_id')
                        ->label('Menu style')
                        ->options(fn () => MenuStyle::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->helperText('Opsional'),

                    Select::make('area_id')
                        ->label('Area')
                        ->options(fn () => Area::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->helperText('Opsional'),

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
                        ->helperText('Boleh 11, 11.00, atau 11:00. Menulis 12.00-15.00 di sini akan otomatis terpecah.'),

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
                        ->visible(fn (callable $get) => (bool) $get('has_end_time')),

                    Select::make('status')
                        ->label('Status')
                        ->options(self::statusOptions())
                        ->placeholder('Belum ditentukan')
                        ->helperText(fn () => self::canConfirm()
                            ? null
                            : 'Anda tidak punya hak untuk menetapkan CONFIRMED.'),
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

        return $options;
    }
}
```

Tiga hal yang perlu dipahami implementer:

`has_end_time` memakai `->dehydrated(false)` sehingga **tidak** ikut terkirim sebagai
kolom. Ia hanya mengendalikan tampilnya `end_time`, sesuai keputusan spec bahwa
`end_time IS NULL` adalah satu-satunya penanda jam tunggal — tidak ada kolom
`is_range` di database.

`idempotency_key` memakai `Hidden` dengan `default()`. Livewire mempertahankan state
form antar request, sehingga UUID tetap sama selama halaman terbuka, dan berubah
hanya ketika halaman Create dibuka lagi.

`statusOptions()` menyembunyikan CONFIRMED dari staf, tetapi **tidak** menggantikan
otorisasi. Task 14 menambahkan penolakan di sisi server.

- [ ] **Step 4: Verifikasi resource muncul**

```bash
php artisan optimize:clear
php artisan serve
```

Login sebagai admin, buka `/cms`. Periksa:

1. Menu "Reservasi" muncul di navigasi dengan ikon kalender.
2. Membuka `/cms/reservations/create` menampilkan tiga section: Tamu, Detail acara, Catatan.
3. Toggle "Sampai jam tertentu" mati secara bawaan, dan kolom Jam selesai tersembunyi.
4. Menyalakan toggle memunculkan kolom Jam selesai; mematikannya kembali menyembunyikan dan mengosongkannya.
5. Login sebagai staf — dropdown Status hanya berisi "Belum ditentukan" dan TENTATIVE, tanpa CONFIRMED.

Jangan menyimpan apa pun dulu. Penyimpanan baru benar setelah Task 14.

- [ ] **Step 5: Commit**

```bash
git add app/Filament
git commit -m "feat: resource reservasi dan skema form"
```

---

## Task 13: Tabel reservasi dengan remark penuh

**Files:**
- Modify: `app/Filament/Resources/Reservations/Tables/ReservationsTable.php`
- Modify: `app/Filament/Resources/Reservations/Pages/ListReservations.php`

**Interfaces:**
- Consumes: `ReservationResource` (Task 12)
- Produces: `ReservationsTable::configure(Table $table): Table`

Kolom remark **tidak** diletakkan di baris utama. Teks bebas yang panjang di dalam sel
akan menekan lebar kolom lain. Sebagai gantinya dipakai `Panel` — komponen layout
Filament yang merender konten di baris tersendiri di bawah baris data, dengan latar
dan sudut membulat. `Panel` **tidak** diberi `->collapsible()`, sehingga isinya selalu
terlihat.

- [ ] **Step 1: Tulis konfigurasi tabel**

Ganti isi `app/Filament/Resources/Reservations/Tables/ReservationsTable.php`:

```php
<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\EventType;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'pic:id,name',
                'area:id,name',
                'eventType:id,name',
                'menuStyle:id,name',
            ]))
            ->defaultSort('reservation_date')
            ->paginated(false)
            ->columns([
                Split::make([
                    TextColumn::make('reservation_date')
                        ->label('Tanggal')
                        ->date('d M Y')
                        ->sortable()
                        ->grow(false),

                    TextColumn::make('start_time')
                        ->label('Jam')
                        ->formatStateUsing(fn ($record) => self::timeRange($record))
                        ->sortable()
                        ->grow(false),

                    Stack::make([
                        TextColumn::make('guest_name')
                            ->label('Nama')
                            ->weight(FontWeight::Bold)
                            ->searchable(),

                        TextColumn::make('company')
                            ->label('Company')
                            ->size(TextColumn\TextColumnSize::ExtraSmall)
                            ->color('gray')
                            ->searchable()
                            ->placeholder(''),
                    ]),

                    Stack::make([
                        TextColumn::make('pic.name')
                            ->label('PIC')
                            ->icon('heroicon-m-user'),

                        TextColumn::make('phone')
                            ->label('HP')
                            ->icon('heroicon-m-phone')
                            ->searchable(),
                    ])->visibleFrom('md'),

                    Stack::make([
                        TextColumn::make('eventType.name')
                            ->label('Event')
                            ->badge()
                            ->placeholder('—'),

                        TextColumn::make('area.name')
                            ->label('Area')
                            ->color('gray')
                            ->placeholder('—'),
                    ])->visibleFrom('lg'),

                    TextColumn::make('pax')
                        ->label('Pax')
                        ->numeric()
                        ->sortable()
                        ->grow(false),

                    TextColumn::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (?ReservationStatus $state) => $state?->label() ?? '—')
                        ->color(fn (?ReservationStatus $state) => match ($state) {
                            ReservationStatus::Confirmed => 'success',
                            ReservationStatus::Tentative => 'warning',
                            default => 'gray',
                        })
                        ->grow(false),
                ]),

                // Remark selalu tampil penuh. JANGAN tambahkan ->collapsible()
                // dan JANGAN memakai ->limit() pada kolom di dalamnya.
                Panel::make([
                    TextColumn::make('remark')
                        ->label('Remark')
                        ->icon('heroicon-m-chat-bubble-bottom-center-text')
                        ->wrap()
                        ->searchable(),
                ])->visible(fn ($record) => filled($record?->remark)),
            ])
            ->filters([
                SelectFilter::make('pic_id')
                    ->label('PIC')
                    ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id')),

                SelectFilter::make('event_type_id')
                    ->label('Event')
                    ->options(fn () => EventType::query()->active()->orderBy('sort_order')->pluck('name', 'id')),

                SelectFilter::make('area_id')
                    ->label('Area')
                    ->options(fn () => Area::query()->active()->orderBy('sort_order')->pluck('name', 'id')),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'confirmed' => 'CONFIRMED',
                        'tentative' => 'TENTATIVE',
                    ]),

                Filter::make('undetermined_status')
                    ->label('Status belum ditentukan')
                    ->query(fn (Builder $query) => $query->whereNull('status'))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada reservasi')
            ->emptyStateDescription('Tekan tombol tambah untuk mencatat reservasi pertama.');
    }

    private static function timeRange($record): string
    {
        $start = substr((string) $record->start_time, 0, 5);

        if (blank($record->end_time)) {
            return $start;
        }

        return $start.'–'.substr((string) $record->end_time, 0, 5);
    }
}
```

`->paginated(false)` sesuai keputusan spec: volume ± 15 reservasi per bulan tidak
memerlukan pagination.

**Perlu diverifikasi saat implementasi:** apakah `Panel::make()->visible()` menerima
closure dengan parameter `$record`. Jika Filament menolak, gantinya adalah menghapus
`->visible()` dan menambahkan `->placeholder('')` pada `TextColumn::make('remark')` —
panel akan tetap muncul tetapi kosong. Jangan menyelesaikannya dengan memotong teks.

- [ ] **Step 2: Batasi daftar ke satu bulan**

Ganti isi `app/Filament/Resources/Reservations/Pages/ListReservations.php`:

```php
<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tambah reservasi'),
        ];
    }

    /**
     * Tab bulan: tiga bulan lalu sampai tiga bulan ke depan, plus Semua.
     */
    public function getTabs(): array
    {
        $tabs = [];

        for ($offset = -3; $offset <= 3; $offset++) {
            $month = Carbon::now()->startOfMonth()->addMonths($offset);
            $key = $month->format('Y-m');

            $tabs[$key] = Tab::make($month->translatedFormat('F Y'))
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereYear('reservation_date', $month->year)
                    ->whereMonth('reservation_date', $month->month));
        }

        $tabs['all'] = Tab::make('Semua');

        return $tabs;
    }

    public function getDefaultActiveTab(): string
    {
        return Carbon::now()->format('Y-m');
    }
}
```

Tab bulan menggantikan navigasi bulan manual. Ini memberi hasil yang sama dengan
kode jauh lebih sedikit, dan pemilihan tab tersimpan di URL sehingga bisa ditautkan.

- [ ] **Step 3: Siapkan data uji**

```bash
php artisan migrate:fresh --seed
php artisan tinker --execute="
\$admin = \App\Models\User::factory()->admin()->create(['name' => 'IRA', 'email' => 'ira@umara.test', 'password' => bcrypt('password')]);
\$area = \App\Models\Area::where('name', 'VIP 1')->first();
\App\Models\Reservation::factory()->create(['reservation_date' => now()->startOfMonth()->addDays(7), 'guest_name' => 'Ibu There', 'pic_id' => \$admin->id, 'created_by' => \$admin->id, 'start_time' => '12:00:00', 'pax' => 5, 'remark' => 'MAIN CONTRACTOR ROEMAH UMARA']);
\App\Models\Reservation::factory()->create(['reservation_date' => now()->startOfMonth()->addDays(8), 'guest_name' => 'Dharmadi', 'pic_id' => \$admin->id, 'created_by' => \$admin->id, 'start_time' => '12:00:00', 'end_time' => '15:00:00', 'status' => 'confirmed', 'pax' => 40, 'area_id' => \$area->id, 'remark' => \"Pakai VIP 1 + VIP 2 + FOYER FnB sekaligus, sekat dibuka jam 11.30.\nGrand total sudah termasuk tax & service 21%.\"]);
\App\Models\Reservation::factory()->create(['reservation_date' => now()->startOfMonth()->addDays(9), 'guest_name' => 'Tanti', 'pic_id' => \$admin->id, 'created_by' => \$admin->id, 'start_time' => '11:00:00', 'pax' => 3]);
"
```

- [ ] **Step 4: Periksa di browser**

Login sebagai `ira@umara.test` / `password`, buka `/cms/reservations`. Periksa:

1. Tab bulan muncul, dan bulan berjalan aktif secara bawaan.
2. Baris Ibu There menampilkan jam `12:00` tanpa tanda hubung.
3. Baris Dharmadi menampilkan `12:00–15:00`.
4. Di bawah baris Ibu There ada panel REMARK berisi teks penuh, **tidak terpotong** dan **tanpa tombol expand**.
5. Panel remark Dharmadi menampilkan kedua baris teksnya.
6. Baris Tanti tidak punya panel remark sama sekali.
7. Mengetik `contractor` di pencarian menyisakan satu baris, membuktikan remark ikut dicari.
8. Filter PIC, Event, Area, dan Status bekerja.
9. Sebagai staf, tombol hapus massal tidak muncul.

- [ ] **Step 5: Commit**

```bash
git add app/Filament
git commit -m "feat: tabel reservasi dengan remark tampil penuh"
```

---

## Task 14: Sambungkan penyimpanan ke ReservationWriter

**Files:**
- Modify: `app/Filament/Resources/Reservations/Pages/CreateReservation.php`
- Modify: `app/Filament/Resources/Reservations/Pages/EditReservation.php`
- Test: `tests/Feature/ReservationFilamentTest.php`

**Interfaces:**
- Consumes: `ReservationInput` (Task 8), `ReservationWriter` (Task 9), `ConflictChecker` (Task 10), `ReservationPolicy` (Task 11)
- Produces: halaman Create dan Edit yang menegakkan idempotency, optimistic lock, penolakan duplikat, otorisasi CONFIRMED, dan peringatan bentrok area

Ini task paling penting di dokumen ini. Tanpa override berikut, Filament akan
menyimpan langsung ke model dan seluruh perlindungan dari Task 9 terlewati diam-diam.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/ReservationFilamentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\EditReservation;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

use function Pest\Livewire\livewire;

class ReservationFilamentTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['name' => 'IRA']);
        $this->actingAs($this->staff);
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'phone' => '0811-2233-445',
            'pic_id' => $this->staff->id,
            'start_time' => '12.00',
            'end_time' => null,
            'pax' => 3,
            'status' => null,
            'remark' => null,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    public function test_staff_can_create_a_reservation(): void
    {
        \Livewire\Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::sole();

        $this->assertSame('08112233445', $r->phone, 'Nomor HP harus dinormalkan.');
        $this->assertSame('12:00:00', $r->start_time);
        $this->assertNull($r->end_time);
        $this->assertSame(1, $r->version);
        $this->assertSame($this->staff->id, $r->created_by);
        $this->assertNotNull($r->idempotency_key);
    }

    public function test_range_typed_into_start_time_is_split(): void
    {
        \Livewire\Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['start_time' => '12.00-15.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $r = Reservation::sole();

        $this->assertSame('12:00:00', $r->start_time);
        $this->assertSame('15:00:00', $r->end_time);
    }

    public function test_na_phone_is_rejected(): void
    {
        \Livewire\Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['phone' => 'NA']))
            ->call('create')
            ->assertHasFormErrors(['phone']);

        $this->assertSame(0, Reservation::count());
    }

    public function test_duplicate_shows_a_readable_error(): void
    {
        \Livewire\Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create');

        \Livewire\Livewire::test(CreateReservation::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasFormErrors(['guest_name']);

        $this->assertSame(1, Reservation::count());
    }

    public function test_staff_cannot_set_confirmed(): void
    {
        \Livewire\Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['status' => 'confirmed']))
            ->call('create')
            ->assertHasFormErrors(['status']);

        $this->assertSame(0, Reservation::count());
    }

    public function test_admin_can_set_confirmed(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        \Livewire\Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['status' => 'confirmed']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('confirmed', Reservation::sole()->status->value);
    }

    public function test_edit_increments_version_and_logs_the_change(): void
    {
        $r = Reservation::factory()->create(['pax' => 5, 'pic_id' => $this->staff->id]);
        $before = $r->activities()->count();

        \Livewire\Livewire::test(EditReservation::class, ['record' => $r->getKey()])
            ->fillForm(['pax' => 8])
            ->call('save')
            ->assertHasNoFormErrors();

        $r->refresh();

        $this->assertSame(8, $r->pax);
        $this->assertSame(2, $r->version);
        $this->assertSame($this->staff->id, $r->updated_by);
        $this->assertSame($before + 1, $r->activities()->count());
    }

    public function test_stale_version_is_rejected_and_changes_nothing(): void
    {
        $r = Reservation::factory()->create(['pax' => 5, 'pic_id' => $this->staff->id]);

        $page = \Livewire\Livewire::test(EditReservation::class, ['record' => $r->getKey()]);

        // Orang lain menyimpan lebih dulu.
        $r->pax = 8;
        $r->version = 2;
        $r->save();

        $page->fillForm(['pax' => 10])
            ->call('save')
            ->assertHasFormErrors(['version']);

        $this->assertSame(8, $r->fresh()->pax);
    }

    public function test_area_overlap_still_saves(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        Reservation::factory()->create([
            'area_id' => $area->id,
            'reservation_date' => '2026-08-07',
            'start_time' => '12:00:00',
            'guest_name' => 'Tamu Lebih Dulu',
        ]);

        \Livewire\Livewire::test(CreateReservation::class)
            ->fillForm($this->formData(['area_id' => $area->id, 'start_time' => '13.00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(2, Reservation::count());
    }
}
```

Hapus baris `use function Pest\Livewire\livewire;` jika proyek memakai PHPUnit murni —
test di atas memakai `\Livewire\Livewire::test()` yang tersedia di kedua runner.

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationFilamentTest`
Expected: FAIL — nomor HP belum dinormalkan dan `version` belum naik.

- [ ] **Step 3: Tulis halaman Create**

Ganti isi `app/Filament/Resources/Reservations/Pages/CreateReservation.php`:

```php
<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Enums\ReservationStatus;
use App\Exceptions\DuplicateReservationException;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Services\ConflictChecker;
use App\Services\ReservationWriter;
use App\Support\ReservationInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ReservationInput::normalize($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $this->guardConfirmedStatus($data);

        $key = $data['idempotency_key'] ?? (string) Str::uuid();
        unset($data['idempotency_key']);

        try {
            $reservation = app(ReservationWriter::class)->create($data, $key, auth()->user());
        } catch (DuplicateReservationException $e) {
            $this->throwDuplicateError($e);
        }

        $this->warnAboutConflicts($data, $reservation->id);

        return $reservation;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function guardConfirmedStatus(array $data): void
    {
        if (($data['status'] ?? null) !== ReservationStatus::Confirmed->value) {
            return;
        }

        if (auth()->user()?->can('confirm', Reservation::class)) {
            return;
        }

        throw ValidationException::withMessages([
            'data.status' => 'Hanya admin yang boleh menetapkan status CONFIRMED.',
        ]);
    }

    private function throwDuplicateError(DuplicateReservationException $e): never
    {
        $existing = $e->existing();

        $message = $existing
            ? sprintf(
                'Sudah ada reservasi atas nama %s pada %s jam %s.',
                $existing->guest_name,
                $existing->reservation_date->format('d/m/Y'),
                substr((string) $existing->start_time, 0, 5)
            )
            : 'Reservasi dengan tanggal, nama, dan jam mulai yang sama sudah ada.';

        throw ValidationException::withMessages(['data.guest_name' => $message]);
    }

    private function warnAboutConflicts(array $data, int $ignoreId): void
    {
        $conflicts = app(ConflictChecker::class)->check(
            $data['area_id'] ?? null,
            $data['reservation_date'],
            $data['start_time'],
            $data['end_time'] ?? null,
            $ignoreId,
        );

        if ($conflicts->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Area bentrok')
            ->body($conflicts
                ->map(fn (Reservation $other) => sprintf(
                    '%s jam %s',
                    $other->guest_name,
                    substr((string) $other->start_time, 0, 5)
                ))
                ->join(', '))
            ->persistent()
            ->send();
    }
}
```

Kunci error validasi memakai awalan `data.` karena Filament menaruh seluruh state
form di dalam properti Livewire bernama `data`. Tanpa awalan itu, pesan error tidak
akan muncul di bawah field yang tepat.

- [ ] **Step 4: Tulis halaman Edit**

Ganti isi `app/Filament/Resources/Reservations/Pages/EditReservation.php`:

```php
<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Enums\ReservationStatus;
use App\Exceptions\DuplicateReservationException;
use App\Exceptions\StaleReservationException;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Reservation;
use App\Services\ConflictChecker;
use App\Services\ReservationWriter;
use App\Support\ReservationInput;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditReservation extends EditRecord
{
    protected static string $resource = ReservationResource::class;

    /** Versi yang dilihat pengguna saat form dimuat. */
    public ?int $loadedVersion = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->loadedVersion = $this->getRecord()->version;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ReservationInput::normalize($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->guardConfirmedStatus($record, $data);

        unset($data['idempotency_key']);

        try {
            $updated = app(ReservationWriter::class)->update(
                $record,
                $data,
                $this->loadedVersion ?? $record->version,
                auth()->user(),
            );
        } catch (StaleReservationException) {
            throw ValidationException::withMessages([
                'data.version' => 'Reservasi ini baru saja diubah orang lain. '
                    . 'Muat ulang halaman untuk melihat perubahan terbaru.',
            ]);
        } catch (DuplicateReservationException $e) {
            $existing = $e->existing();

            throw ValidationException::withMessages([
                'data.guest_name' => $existing
                    ? sprintf(
                        'Sudah ada reservasi atas nama %s pada %s jam %s.',
                        $existing->guest_name,
                        $existing->reservation_date->format('d/m/Y'),
                        substr((string) $existing->start_time, 0, 5)
                    )
                    : 'Reservasi dengan tanggal, nama, dan jam mulai yang sama sudah ada.',
            ]);
        }

        $this->loadedVersion = $updated->version;

        $this->warnAboutConflicts($data, $updated->id);

        return $updated;
    }

    private function guardConfirmedStatus(Model $record, array $data): void
    {
        $becomingConfirmed = ($data['status'] ?? null) === ReservationStatus::Confirmed->value
            && $record->status !== ReservationStatus::Confirmed;

        if (! $becomingConfirmed) {
            return;
        }

        if (auth()->user()?->can('confirm', $record)) {
            return;
        }

        throw ValidationException::withMessages([
            'data.status' => 'Hanya admin yang boleh menetapkan status CONFIRMED.',
        ]);
    }

    private function warnAboutConflicts(array $data, int $ignoreId): void
    {
        $conflicts = app(ConflictChecker::class)->check(
            $data['area_id'] ?? null,
            $data['reservation_date'],
            $data['start_time'],
            $data['end_time'] ?? null,
            $ignoreId,
        );

        if ($conflicts->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Area bentrok')
            ->body($conflicts
                ->map(fn (Reservation $other) => sprintf(
                    '%s jam %s',
                    $other->guest_name,
                    substr((string) $other->start_time, 0, 5)
                ))
                ->join(', '))
            ->persistent()
            ->send();
    }
}
```

`loadedVersion` disimpan sebagai properti publik Livewire, sehingga nilainya bertahan
antar request selama halaman terbuka. Inilah yang membuat optimistic lock bekerja:
nilai yang dibandingkan adalah versi **saat form dimuat**, bukan versi terbaru di
database.

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=ReservationFilamentTest`
Expected: 9 test PASS

- [ ] **Step 6: Jalankan seluruh test**

Run: `php artisan test`
Expected: semua PASS

- [ ] **Step 7: Periksa perilaku dua tab di browser**

1. Buka reservasi yang sama untuk diedit di dua tab.
2. Di tab pertama, ubah Pax lalu simpan. Berhasil.
3. Di tab kedua, ubah Pax lalu simpan. Muncul pesan bahwa reservasi baru saja diubah orang lain, dan nilai dari tab pertama tetap utuh.
4. Buat reservasi dengan area yang sudah dipakai pada jam berdekatan. Data tersimpan **dan** muncul notifikasi peringatan bentrok yang tidak hilang sendiri.
5. Tekan tombol Simpan dua kali cepat pada form tambah. Hanya satu reservasi terbentuk.

- [ ] **Step 8: Commit**

```bash
git add app/Filament tests/Feature/ReservationFilamentTest.php
git commit -m "feat: sambungkan Filament ke ReservationWriter"
```

---

## Task 15: Halaman detail dan riwayat perubahan

**Files:**
- Create: `app/Filament/Resources/Reservations/Schemas/ReservationInfolist.php`
- Modify: `app/Filament/Resources/Reservations/ReservationResource.php`
- Modify: `app/Filament/Resources/Reservations/Pages/ViewReservation.php`
- Create: `resources/views/filament/audit-timeline.blade.php`

**Interfaces:**
- Consumes: audit log (Task 7)
- Produces: `ReservationInfolist::configure(Schema $schema): Schema`

- [ ] **Step 1: Buat skema infolist**

`app/Filament/Resources/Reservations/Schemas/ReservationInfolist.php`:

```php
<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Enums\ReservationStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ReservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Reservasi')
                ->columns(4)
                ->schema([
                    TextEntry::make('reservation_date')->label('Tanggal')->date('d M Y'),

                    TextEntry::make('start_time')
                        ->label('Jam')
                        ->formatStateUsing(function ($record) {
                            $start = substr((string) $record->start_time, 0, 5);

                            return blank($record->end_time)
                                ? $start.' (jam tunggal)'
                                : $start.'–'.substr((string) $record->end_time, 0, 5).' (rentang)';
                        }),

                    TextEntry::make('guest_name')->label('Nama tamu'),
                    TextEntry::make('company')->label('Company')->placeholder('—'),
                    TextEntry::make('phone')->label('HP'),
                    TextEntry::make('email')->label('Email')->placeholder('—'),
                    TextEntry::make('pic.name')->label('PIC / Sales'),
                    TextEntry::make('pax')->label('Pax')->numeric(),
                    TextEntry::make('eventType.name')->label('Event')->placeholder('—'),
                    TextEntry::make('menuStyle.name')->label('Menu style')->placeholder('—'),
                    TextEntry::make('area.name')->label('Area')->placeholder('—'),

                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (?ReservationStatus $state) => $state?->label() ?? 'Belum ditentukan')
                        ->color(fn (?ReservationStatus $state) => match ($state) {
                            ReservationStatus::Confirmed => 'success',
                            ReservationStatus::Tentative => 'warning',
                            default => 'gray',
                        }),
                ]),

            Section::make('Remark')
                ->schema([
                    TextEntry::make('remark')
                        ->hiddenLabel()
                        ->placeholder('Tidak ada remark.')
                        ->columnSpanFull(),
                ]),

            Section::make('Riwayat perubahan')
                ->schema([
                    View::make('filament.audit-timeline')->columnSpanFull(),
                ]),
        ]);
    }
}
```

`TextEntry` untuk remark tidak diberi `->limit()` maupun `->words()`. Teksnya tampil
utuh, sesuai aturan global.

- [ ] **Step 2: Daftarkan infolist di resource**

Tambahkan import dan method berikut ke `ReservationResource`:

```php
use App\Filament\Resources\Reservations\Schemas\ReservationInfolist;

public static function infolist(Schema $schema): Schema
{
    return ReservationInfolist::configure($schema);
}
```

- [ ] **Step 3: Buat view riwayat**

`resources/views/filament/audit-timeline.blade.php`:

```blade
@php
    $labels = [
        'reservation_date' => 'Tanggal',
        'guest_name' => 'Nama tamu',
        'company' => 'Company',
        'phone' => 'HP',
        'email' => 'Email',
        'pic_id' => 'PIC',
        'event_type_id' => 'Event',
        'menu_style_id' => 'Menu style',
        'area_id' => 'Area',
        'start_time' => 'Jam mulai',
        'end_time' => 'Jam selesai',
        'pax' => 'Pax',
        'status' => 'Status',
        'remark' => 'Remark',
    ];

    $activities = $getRecord()->activities()->with('causer:id,name')->latest('id')->get();
@endphp

<div class="space-y-4">
    @forelse ($activities as $activity)
        <div class="border-s-2 border-gray-200 ps-3 dark:border-gray-700">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ $activity->causer?->name ?? 'Sistem' }}
                </span>
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $activity->event === 'created' ? 'membuat reservasi' : ($activity->event === 'deleted' ? 'menghapus reservasi' : 'mengubah') }}
                </span>
                <span class="ms-auto text-xs text-gray-400">
                    {{ $activity->created_at->translatedFormat('d M Y, H:i') }}
                </span>
            </div>

            @if ($activity->event === 'updated')
                <ul class="mt-1 space-y-1">
                    @foreach (($activity->properties['attributes'] ?? []) as $field => $new)
                        @php $old = $activity->properties['old'][$field] ?? null; @endphp

                        @if ($field === 'remark')
                            <li>
                                <div class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                                    {{ $labels[$field] ?? $field }}
                                </div>
                                <div class="mt-0.5 whitespace-pre-line text-xs text-gray-400 line-through">
                                    {{ filled($old) ? $old : 'kosong' }}
                                </div>
                                <div class="mt-0.5 whitespace-pre-line text-xs text-gray-800 dark:text-gray-200">
                                    {{ filled($new) ? $new : 'kosong' }}
                                </div>
                            </li>
                        @else
                            <li class="text-xs text-gray-700 dark:text-gray-300">
                                {{ $labels[$field] ?? $field }}:
                                <span class="text-gray-400">{{ filled($old) ? $old : 'kosong' }}</span>
                                &rarr;
                                <span>{{ filled($new) ? $new : 'kosong' }}</span>
                            </li>
                        @endif
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">Belum ada riwayat perubahan.</p>
    @endforelse
</div>
```

Perubahan remark ditampilkan sebagai dua blok bertumpuk dengan `whitespace-pre-line`,
sehingga teks panjang tetap utuh dan baris barunya terjaga.

- [ ] **Step 4: Tambahkan aksi di halaman View**

Ganti isi `app/Filament/Resources/Reservations/Pages/ViewReservation.php`:

```php
<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Filament\Resources\Reservations\ReservationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReservation extends ViewRecord
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 5: Periksa di browser**

1. Buka detail reservasi Dharmadi. Remark tampil utuh dengan baris barunya terjaga.
2. Jamnya tertulis `12:00–15:00 (rentang)`; pada Ibu There tertulis `12:00 (jam tunggal)`.
3. Ubah Pax lalu buka detail lagi. Riwayat menampilkan `Pax: 40 → 45` dengan nama pengubah dan waktunya.
4. Ubah remark menjadi teks dua baris. Riwayat menampilkan nilai lama tercoret di atas dan nilai baru di bawah, keduanya utuh.
5. Kosongkan Company lalu simpan. Riwayat menampilkan `Company: ... → kosong`.
6. Sebagai staf, tombol Hapus tidak muncul di halaman detail.

- [ ] **Step 6: Commit**

```bash
git add app/Filament resources/views/filament
git commit -m "feat: halaman detail reservasi dan riwayat perubahan"
```

---

## Task 16: Halaman kalender

**Files:**
- Create: `app/Filament/Pages/ReservationCalendar.php`
- Create: `resources/views/filament/pages/reservation-calendar.blade.php`

**Interfaces:**
- Consumes: `Reservation` (Task 4), `ReservationResource` (Task 12)
- Produces: halaman `/cms/reservation-calendar` dengan grid bulanan dan panel detail

Grid dibangun dengan CSS Grid, tanpa pustaka kalender. Yang dibutuhkan hanya
menempatkan chip pada sel tanggal dan menangani klik — tidak ada penjadwalan,
drag-drop, atau sinkronisasi yang memerlukan pustaka.

Chip **tidak** memuat remark karena sel terlalu sempit, dan memotong teks melanggar
aturan. Remark ditampilkan utuh di panel detail di bawah grid.

- [ ] **Step 1: Buat halaman**

`app/Filament/Pages/ReservationCalendar.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Models\Reservation;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

class ReservationCalendar extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-calendar';

    protected static string | UnitEnum | null $navigationGroup = 'Reservasi';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Kalender';

    protected static ?string $navigationLabel = 'Kalender';

    protected string $view = 'filament.pages.reservation-calendar';

    /** Bulan aktif dalam format Y-m. */
    public string $month;

    public ?int $selectedId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Reservation::class) ?? false;
    }

    public function mount(): void
    {
        $this->month = Carbon::now()->format('Y-m');
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->month.'-01')
            ->addMonths($delta)
            ->format('Y-m');

        $this->selectedId = null;
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    /** @return Collection<int, Reservation> */
    public function getReservationsProperty(): Collection
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();

        return Reservation::query()
            ->with(['pic:id,name', 'area:id,name', 'eventType:id,name', 'menuStyle:id,name'])
            ->whereYear('reservation_date', $start->year)
            ->whereMonth('reservation_date', $start->month)
            ->orderBy('reservation_date')
            ->orderBy('start_time')
            ->get();
    }

    public function getSelectedProperty(): ?Reservation
    {
        return $this->reservations->firstWhere('id', $this->selectedId);
    }

    /**
     * Sel kalender dengan minggu dimulai hari Senin.
     * Sel kosong di awal bulan bernilai null.
     *
     * @return array<int, array{day: ?int, iso: ?string}>
     */
    public function getCellsProperty(): array
    {
        $first = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();

        // dayOfWeek: 0 = Minggu. Geser agar Senin menjadi 0.
        $lead = ($first->dayOfWeek + 6) % 7;

        $cells = array_fill(0, $lead, ['day' => null, 'iso' => null]);

        for ($day = 1; $day <= $first->daysInMonth; $day++) {
            $cells[] = [
                'day' => $day,
                'iso' => sprintf('%s-%02d', $this->month, $day),
            ];
        }

        return $cells;
    }

    public function getMonthLabelProperty(): string
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->translatedFormat('F Y');
    }
}
```

- [ ] **Step 2: Buat view**

`resources/views/filament/pages/reservation-calendar.blade.php`:

```blade
<x-filament-panels::page>
    @php
        $byDate = $this->reservations->groupBy(fn ($r) => $r->reservation_date->toDateString());
        $selected = $this->selected;
    @endphp

    <div class="flex items-center gap-2">
        <h2 class="text-lg font-bold">{{ $this->monthLabel }}</h2>

        <x-filament::button size="xs" color="gray" wire:click="shiftMonth(-1)">‹</x-filament::button>
        <x-filament::button size="xs" color="gray" wire:click="shiftMonth(1)">›</x-filament::button>

        <span class="ms-auto text-xs text-gray-500">
            {{ $this->reservations->count() }} reservasi bulan ini
        </span>
    </div>

    <div class="mt-3 grid grid-cols-7 gap-px overflow-hidden rounded-lg border border-gray-200 bg-gray-200 dark:border-gray-700 dark:bg-gray-700">
        @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $name)
            <div class="bg-white py-1 text-center text-[10px] uppercase tracking-wider text-gray-500 dark:bg-gray-900">
                {{ $name }}
            </div>
        @endforeach

        @foreach ($this->cells as $cell)
            <div class="min-h-[86px] p-1 {{ $cell['day'] === null ? 'bg-gray-50 dark:bg-gray-800' : 'bg-white dark:bg-gray-900' }}">
                @if ($cell['day'] !== null)
                    @php $items = $byDate[$cell['iso']] ?? collect(); @endphp

                    <div class="mb-1 text-[11px] {{ $items->isNotEmpty() ? 'font-bold text-gray-900 dark:text-gray-100' : 'text-gray-400' }}">
                        {{ $cell['day'] }}
                    </div>

                    @foreach ($items as $r)
                        <button
                            type="button"
                            wire:click="select({{ $r->id }})"
                            title="{{ substr($r->start_time, 0, 5) }} {{ $r->guest_name }}"
                            @class([
                                'mb-0.5 block w-full truncate border-s-2 py-0.5 pe-0.5 ps-1 text-start text-[10px] leading-tight',
                                'border-s-green-600' => $r->status?->value === 'confirmed',
                                'border-s-amber-500 border-dashed' => $r->status?->value === 'tentative',
                                'border-s-gray-300' => $r->status === null,
                                'bg-gray-900 font-bold text-white' => $r->id === $this->selectedId,
                                'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' => $r->id !== $this->selectedId,
                            ])
                        >
                            <span class="font-bold">{{ substr($r->start_time, 0, 5) }}</span>
                            {{ $r->guest_name }}
                        </button>
                    @endforeach
                @endif
            </div>
        @endforeach
    </div>

    @if ($selected)
        <x-filament::section class="mt-3">
            <x-slot name="heading">{{ $selected->guest_name }}</x-slot>

            <x-slot name="description">
                {{ $selected->reservation_date->translatedFormat('d F Y') }} ·
                @if (blank($selected->end_time))
                    {{ substr($selected->start_time, 0, 5) }} (jam tunggal)
                @else
                    {{ substr($selected->start_time, 0, 5) }}–{{ substr($selected->end_time, 0, 5) }} (rentang)
                @endif
            </x-slot>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                @foreach ([
                    'Company' => $selected->company,
                    'HP' => $selected->phone,
                    'Email' => $selected->email,
                    'PIC / Sales' => $selected->pic?->name,
                    'Event' => $selected->eventType?->name,
                    'Menu style' => $selected->menuStyle?->name,
                    'Area' => $selected->area?->name,
                    'Pax' => $selected->pax,
                ] as $label => $value)
                    <div>
                        <dt class="text-[9px] font-semibold uppercase tracking-wider text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-3">
                <div class="text-[9px] font-semibold uppercase tracking-wider text-gray-400">Remark</div>
                @if (filled($selected->remark))
                    <p class="mt-0.5 whitespace-pre-line border-s-2 border-amber-500 ps-3 text-sm text-gray-700 dark:text-gray-300">
                        {{ $selected->remark }}
                    </p>
                @else
                    <p class="mt-0.5 text-sm text-gray-400">Tidak ada remark.</p>
                @endif
            </div>

            <div class="mt-4 flex gap-2">
                <x-filament::button
                    tag="a"
                    size="sm"
                    color="gray"
                    href="{{ \App\Filament\Resources\Reservations\ReservationResource::getUrl('view', ['record' => $selected]) }}"
                >
                    Detail &amp; riwayat
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    size="sm"
                    color="gray"
                    href="{{ \App\Filament\Resources\Reservations\ReservationResource::getUrl('edit', ['record' => $selected]) }}"
                >
                    Ubah
                </x-filament::button>
            </div>
        </x-filament::section>
    @else
        <p class="mt-3 rounded-lg border border-gray-200 px-4 py-6 text-center text-sm text-gray-500 dark:border-gray-700">
            Klik salah satu reservasi di kalender untuk melihat detail lengkapnya.
        </p>
    @endif
</x-filament-panels::page>
```

`$this->selected` diambil dari koleksi bulan yang sedang tampil, sehingga berpindah
bulan otomatis mengosongkan panel detail dan tidak menampilkan reservasi yang chipnya
sudah tidak terlihat.

- [ ] **Step 3: Aktifkan pelokalan tanggal**

Agar `translatedFormat()` menghasilkan nama bulan dan hari berbahasa Indonesia,
pastikan `config/app.php` memuat:

```php
'locale' => 'id',
'fallback_locale' => 'en',
```

Jika berkas terjemahan Indonesia belum ada, Carbon tetap menerjemahkan nama bulan
karena pelokalannya bawaan Carbon, bukan berkas lang Laravel.

- [ ] **Step 4: Periksa di browser**

1. Menu "Kalender" muncul di grup Reservasi.
2. Grid menampilkan tujuh kolom dengan header Sen sampai Min.
3. Kolom pertama benar-benar hari Senin — cocokkan tanggal 1 bulan berjalan dengan kalender sistem.
4. Reservasi Ibu There muncul sebagai chip `12:00 Ibu There`.
5. Chip Dharmadi bergaris hijau (CONFIRMED); chip tanpa status abu-abu.
6. Mengklik chip membuka panel di bawah grid dengan remark **utuh**, tidak terpotong.
7. Panel menampilkan `(jam tunggal)` untuk Ibu There dan `(rentang)` untuk Dharmadi.
8. Menekan `›` berpindah bulan dan panel detail kembali kosong.
9. Sebagai staf, menu Kalender tetap muncul — halaman ini hanya butuh `viewAny`.

- [ ] **Step 5: Commit**

```bash
git add app/Filament resources/views/filament config/app.php
git commit -m "feat: halaman kalender reservasi"
```

---

## Task 17: Resource master dan pengguna

**Files:**
- Create: `app/Filament/Resources/Areas/*`, `EventTypes/*`, `MenuStyles/*`, `Users/*`
- Test: `tests/Feature/MasterResourceTest.php`

**Interfaces:**
- Consumes: policy master dan pengguna (Task 11)
- Produces: empat resource yang hanya terlihat oleh admin

Ketiga master memakai **simple resource** — seluruh CRUD terjadi lewat modal pada satu
halaman. Struktur kolomnya identik dan sangat kecil, sehingga halaman Create dan Edit
terpisah hanya menambah klik tanpa memberi manfaat.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/MasterResourceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_open_master_pages(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/cms/areas')
            ->assertForbidden();
    }

    public function test_admin_can_open_master_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/cms/areas')
            ->assertOk();
    }

    public function test_staff_cannot_open_user_management(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/cms/users')
            ->assertForbidden();
    }

    public function test_area_in_use_cannot_be_deleted(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);
        Reservation::factory()->create(['area_id' => $area->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $area->delete();
    }
}
```

Test terakhir memastikan `restrictOnDelete` benar-benar aktif. Penanganan pesannya
diperiksa manual di Step 5.

- [ ] **Step 2: Hasilkan resource**

```bash
php artisan make:filament-resource Area --simple
php artisan make:filament-resource EventType --simple
php artisan make:filament-resource MenuStyle --simple
php artisan make:filament-resource User
```

- [ ] **Step 3: Isi resource master**

Ganti isi `app/Filament/Resources/Areas/AreaResource.php`:

```php
<?php

namespace App\Filament\Resources\Areas;

use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Models\Area;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AreaResource extends Resource
{
    protected static ?string $model = Area::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-map-pin';

    protected static string | UnitEnum | null $navigationGroup = 'Master';

    protected static ?string $modelLabel = 'Area';

    protected static ?string $pluralModelLabel = 'Area';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(80)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (string $state) => mb_strtoupper(trim($state))),

            TextInput::make('sort_order')
                ->label('Urutan')
                ->numeric()
                ->default(0)
                ->minValue(0),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Yang tidak aktif tidak muncul di form reservasi baru.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('sort_order')->label('Urutan')->numeric(),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAreas::route('/')];
    }
}
```

Salin isi yang sama ke `EventTypeResource` dan `MenuStyleResource`, dengan penyesuaian:

| Resource | `$model` | `$navigationIcon` | `$modelLabel` | Halaman |
|---|---|---|---|---|
| `EventTypeResource` | `EventType::class` | `heroicon-o-sparkles` | `Event` | `ManageEventTypes` |
| `MenuStyleResource` | `MenuStyle::class` | `heroicon-o-cake` | `Menu style` | `ManageMenuStyles` |

Sesuaikan juga namespace dan import halaman masing-masing.

`dehydrateStateUsing()` menyeragamkan nama master menjadi huruf kapital, sehingga
`TEST FOOD` tidak berdampingan dengan `Test Food` di daftar pilihan.

- [ ] **Step 4: Tangani penghapusan master yang sedang dipakai**

Tambahkan method berikut ke ketiga resource master, di bawah `table()`. Contoh untuk
`AreaResource`:

```php
public static function table(Table $table): Table
{
    return $table
        // ... konfigurasi di atas ...
        ->recordActions([
            EditAction::make(),
            DeleteAction::make()
                ->action(function (Area $record, DeleteAction $action) {
                    try {
                        $record->delete();
                    } catch (\Illuminate\Database\QueryException $e) {
                        if (($e->errorInfo[1] ?? null) === 1451) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Tidak bisa dihapus')
                                ->body(sprintf(
                                    '"%s" sudah dipakai reservasi. Nonaktifkan saja lewat kolom Aktif.',
                                    $record->name
                                ))
                                ->send();

                            $action->halt();
                        }

                        throw $e;
                    }
                }),
        ]);
}
```

Tanpa penanganan ini, menghapus area yang sedang dipakai menghasilkan halaman error
500, bukan pesan yang bisa dipahami staf.

- [ ] **Step 5: Isi resource pengguna**

Ganti isi `app/Filament/Resources/Users/UserResource.php`:

```php
<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static string | UnitEnum | null $navigationGroup = 'Pengaturan';

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(100),

            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->maxLength(150)
                ->unique(ignoreRecord: true),

            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->dehydrateStateUsing(fn (string $state) => Hash::make($state))
                ->helperText(fn (string $operation) => $operation === 'edit'
                    ? 'Kosongkan jika password tidak diubah.'
                    : null),

            Select::make('roles')
                ->label('Role')
                ->relationship('roles', 'name')
                ->required()
                ->preload()
                ->searchable()
                ->getOptionLabelFromRecordUsing(fn (Role $record) => Str::headline($record->name))
                ->helperText('Satu pengguna satu role. Kalau butuh kombinasi kemampuan yang belum ada, buat role baru di menu Role.'),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Yang tidak aktif tidak bisa login dan tidak muncul sebagai pilihan PIC.')
                ->disabled(fn (?User $record) => $record?->is(auth()->user()) ?? false)
                ->hint(fn (?User $record) => $record?->is(auth()->user())
                    ? 'Anda tidak bisa menonaktifkan akun sendiri.'
                    : null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Str::headline($state)),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
```

Tidak ada `DeleteAction` di mana pun. `UserPolicy::delete()` mengembalikan `false`,
dan kolom `reservations.pic_id` serta `created_by` memakai `restrictOnDelete` —
menghapus pengguna yang pernah menangani reservasi akan selalu gagal di level
database. Menonaktifkan lewat `is_active` adalah jalur yang benar.

- [ ] **Step 6: Jalankan seluruh test**

Run: `php artisan test`
Expected: semua PASS

- [ ] **Step 7: Periksa di browser**

1. Sebagai admin, grup Master berisi Area, Event, Menu style; grup Pengaturan berisi Pengguna.
2. Sebagai staf, keempatnya **tidak muncul** di navigasi, dan membuka `/cms/areas` langsung menghasilkan 403.
3. Tambahkan area `vip 3`. Tersimpan sebagai `VIP 3` huruf kapital.
4. Tambahkan `VIP 3` lagi — muncul error nama sudah dipakai.
5. Hapus area yang sedang dipakai reservasi — muncul notifikasi merah yang menyarankan menonaktifkan, bukan error 500.
6. Nonaktifkan satu area, lalu buka form reservasi — area itu hilang dari dropdown.
7. Tambahkan pengguna staf baru, lalu login dengan akun itu di jendela penyamaran.
8. Buka form ubah untuk akun Anda sendiri — toggle Aktif dalam keadaan disabled dengan keterangannya.
9. Tidak ada tombol Hapus di mana pun pada halaman Pengguna.

- [ ] **Step 8: Commit**

```bash
git add app/Filament tests/Feature/MasterResourceTest.php
git commit -m "feat: resource master dan pengguna"
```

---

## Task 18: Resource role dan hak akses

**Files:**
- Create: `app/Filament/Resources/Roles/RoleResource.php`
- Create: `app/Filament/Resources/Roles/Pages/ManageRoles.php`
- Create: `app/Policies/RolePolicy.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/RoleResourceTest.php`

**Interfaces:**
- Consumes: `Ability` (Task 1), `spatie/laravel-permission` (Task 2)
- Produces: halaman `/cms/roles` tempat admin membuat role baru dan memilih kemampuannya

Inilah task yang membuat seluruh keputusan memakai spatie berbuah. Setelah task ini
selesai, menambahkan role "Manajer" yang boleh menetapkan CONFIRMED tetapi tidak boleh
menghapus reservasi maupun mengelola pengguna dilakukan **sepenuhnya lewat UI**, tanpa
menyentuh kode dan tanpa deploy.

- [ ] **Step 1: Buat policy untuk Role**

`app/Policies/RolePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    private function allowed(User $user): bool
    {
        return $user->is_active && $user->can(Ability::ManageRole->value);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->allowed($user);
    }

    /**
     * Role bawaan tidak boleh dihapus. Menghapus "admin" akan mengunci
     * semua orang keluar dari pengaturan; menghapus "staff" membuat
     * pengguna yang memakainya kehilangan seluruh akses.
     */
    public function delete(User $user, Role $role): bool
    {
        return $this->allowed($user) && ! in_array($role->name, ['admin', 'staff'], true);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

- [ ] **Step 2: Daftarkan policy secara manual**

`Spatie\Permission\Models\Role` berada di luar `App\Models`, sehingga penemuan policy
otomatis Laravel **tidak** menemukannya. Di `app/Providers/AppServiceProvider.php`,
dalam method `boot()`:

```php
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

public function boot(): void
{
    Gate::policy(Role::class, RolePolicy::class);
}
```

Tanpa baris ini, halaman Role akan terbuka untuk siapa saja yang bisa login.

- [ ] **Step 3: Tulis test yang gagal**

`tests/Feature/RoleResourceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_cannot_open_role_page(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/cms/roles')->assertForbidden();
    }

    public function test_admin_can_open_role_page(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/cms/roles')
            ->assertOk();
    }

    public function test_builtin_roles_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertFalse($admin->can('delete', Role::findByName('admin')));
        $this->assertFalse($admin->can('delete', Role::findByName('staff')));
    }

    public function test_custom_role_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $custom = Role::create(['name' => 'manajer']);

        $this->assertTrue($admin->can('delete', $custom));
    }

    public function test_new_role_takes_effect_immediately(): void
    {
        $manager = Role::create(['name' => 'manajer']);
        $manager->givePermissionTo(Ability::ConfirmReservation->value);

        $user = User::factory()->create();
        $user->assignRole('manajer');

        $this->assertTrue($user->can(Ability::ConfirmReservation->value));
    }
}
```

- [ ] **Step 4: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=RoleResourceTest`
Expected: FAIL — route `/cms/roles` belum ada

- [ ] **Step 5: Buat resource**

```bash
php artisan make:filament-resource Role --simple --model-namespace=Spatie\\Permission\\Models
```

Ganti isi `app/Filament/Resources/Roles/RoleResource.php`:

```php
<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Ability;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | UnitEnum | null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Role';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama role')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Role $record) => in_array($record?->name, ['admin', 'staff'], true))
                ->helperText('Huruf kecil tanpa spasi, misalnya manajer.')
                ->dehydrateStateUsing(fn (string $state) => Str::slug(trim($state))),

            CheckboxList::make('permissions')
                ->label('Kemampuan')
                ->relationship('permissions', 'name')
                ->options(Ability::options())
                ->descriptions([
                    Ability::ConfirmReservation->value => 'Memberi kemampuan ini berarti mempercayai pengguna untuk mengunci status reservasi.',
                    Ability::ManageRole->value => 'Hati-hati. Pengguna dengan kemampuan ini bisa mengubah hak akses siapa pun, termasuk dirinya sendiri.',
                ])
                ->columns(2)
                ->bulkToggleable()
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->formatStateUsing(fn (string $state) => Str::headline($state))
                    ->searchable(),

                TextColumn::make('permissions_count')
                    ->label('Jumlah kemampuan')
                    ->counts('permissions'),

                TextColumn::make('users_count')
                    ->label('Jumlah pengguna')
                    ->counts('users'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
```

`CheckboxList::options(Ability::options())` membatasi pilihan hanya pada delapan
kemampuan yang dikenali kode. Jika suatu saat ada baris permission liar di database
yang tidak punya `Ability`, baris itu tidak akan muncul dan tidak bisa diberikan —
inilah yang menjaga agar nama permission tetap menjadi kode.

- [ ] **Step 6: Bersihkan cache permission setiap kali role berubah**

Ganti isi `app/Filament/Resources/Roles/Pages/ManageRoles.php`:

```php
<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Spatie\Permission\PermissionRegistrar;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah role')
                ->after(fn () => $this->flushPermissionCache()),
        ];
    }

    protected function getTableActions(): array
    {
        return [];
    }

    public function flushPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
```

Tambahkan juga `->after(fn () => $this->flushPermissionCache())` pada `EditAction` dan
`DeleteAction` di `RoleResource::table()`.

**Ini bukan detail opsional.** Cache permission spatie adalah penyebab keluhan paling
umum saat memakai paket ini: hak akses diubah lewat UI, tetapi tidak berlaku sampai
cache kedaluwarsa atau aplikasi di-restart. Staf akan melaporkannya sebagai "sistem
tidak menyimpan perubahan", padahal datanya sudah tersimpan.

- [ ] **Step 7: Jalankan seluruh test**

Run: `php artisan test`
Expected: semua PASS

- [ ] **Step 8: Uji skenario yang menjadi alasan seluruh keputusan ini**

Login sebagai admin, lalu kerjakan **tanpa menyentuh kode sama sekali**:

1. Buka menu Role. Terlihat `Admin` dan `Staff` dengan jumlah kemampuan dan penggunanya.
2. Tambah role baru bernama `manajer`. Centang: Lihat reservasi, Tambah reservasi, Ubah reservasi, dan Tetapkan status CONFIRMED. Simpan.
3. Buka menu Pengguna, ubah satu akun staf menjadi role Manajer.
4. Login sebagai akun itu di jendela penyamaran.
5. Buka form reservasi — opsi CONFIRMED **sekarang muncul**, padahal sebelumnya tidak.
6. Tombol Hapus pada reservasi **tidak** muncul.
7. Menu Area, Event, Menu style, Pengguna, dan Role **tidak** muncul.
8. Coba hapus role `admin` — tombol Hapus tidak tersedia.
9. Hapus role `manajer` setelah penggunanya dipindah — berhasil.

Jika langkah 5 gagal padahal permission sudah dicentang, penyebabnya hampir pasti cache
permission. Jalankan `php artisan permission:cache-reset` dan periksa ulang Step 6.

- [ ] **Step 9: Commit**

```bash
git add app resources tests
git commit -m "feat: kelola role dan hak akses lewat UI"
```

---

## Self-Review

**Cakupan spec.** Bagian spec yang menyangkut UI dipetakan ke task di dokumen ini:

| Bagian spec | Task |
|---|---|
| 4 Halaman dan rute | 12 (resource), 17 (master dan pengguna) |
| 4.1.1 Dua mode tampilan | 13 (tabel), 16 (kalender) |
| 4.3 Remark selalu penuh | 13 (`Panel`), 15 (infolist dan riwayat), 16 (panel kalender) |
| 5 Validasi | 8 di bagian 1 (normalisasi), 12 (`->required()`, `->maxLength()`) |
| 6 Hak akses | 11 di bagian 1 (policy), 12 dan 14 (CONFIRMED), 17 (master) |
| 7 Audit trail | 15 |
| 8 Peringatan bentrok | 14 |
| 9 Race condition | 14 |
| 3.8 Jam tunggal dan rentang | 12 (toggle), 13 (tampilan), 15 dan 16 (label) |

Bagian spec 2 dan 3 seluruhnya diselesaikan di dokumen bagian 1.

**Placeholder.** Tidak ada "TBD" atau instruksi tanpa kode. Setiap step yang mengubah
kode memuat kode lengkapnya, kecuali Step 3 Task 17 yang secara sengaja menginstruksikan
penyalinan dengan tabel perbedaan eksplisit — kodenya identik dan menuliskannya tiga
kali justru mengundang perbedaan yang tidak disengaja.

**Konsistensi tipe.** Diperiksa dan diselaraskan:

- `ReservationWriter::create()` dan `update()` dipanggil dengan urutan argumen yang sama seperti definisinya di Task 9.
- `ReservationInput::normalize()` dipanggil dari `mutateFormDataBeforeCreate()` dan `mutateFormDataBeforeSave()`, keduanya menerima dan mengembalikan `array`.
- `ConflictChecker::check()` dipanggil dengan lima argumen sesuai signature Task 10.
- `DuplicateReservationException::existing()` mengembalikan `?Reservation`, dan kedua halaman memeriksa null sebelum memakainya.
- `ReservationPolicy::confirm()` menerima `?Reservation` nullable, sehingga `can('confirm', Reservation::class)` pada halaman Create tetap sah.
- Kunci error validasi konsisten memakai awalan `data.` di seluruh halaman Filament.

**Dua hal yang perlu diverifikasi saat implementasi**, sudah ditandai di badan task:

1. `Panel::make()->visible()` dengan closure `$record` — Task 13 Step 1, beserta jalur cadangannya.
2. Ketersediaan `Filament\Schemas\Components\Tabs\Tab` untuk tab bulan — Task 13 Step 2. Jika namespace-nya berbeda, cari `Tab` di dokumentasi 5.x bagian List page.

---

## Ringkasan urutan pengerjaan

| Task | Deliverable | Bisa diuji sendiri |
|---|---|---|
| 12 | Resource dan form reservasi | Manual |
| 13 | Tabel dengan remark penuh | Manual |
| 14 | Idempotency, optimistic lock, peringatan bentrok | **Ya, otomatis** |
| 15 | Detail dan riwayat perubahan | Manual |
| 16 | Kalender | Manual |
| 17 | Master dan pengguna | Ya + manual |
| 18 | Kelola role dan hak akses lewat UI | **Ya, otomatis** |

Task 14 dan 18 adalah dua task UI dengan test otomatis penuh, dan keduanya memang
memegang hal yang paling berisiko: Task 14 menjaga integritas data, Task 18 menjaga
agar penambahan role tidak pernah lagi memerlukan perubahan kode. Task lainnya
bersifat presentasi dan diverifikasi lewat pemeriksaan manual yang tertulis eksplisit.

## Yang berubah karena spatie ditambahkan

Ringkasan agar mudah ditelusuri saat mengerjakan:

| Bagian | Sebelum | Sesudah |
|---|---|---|
| `users.role` | Kolom enum | **Dihapus.** Role di `model_has_roles` |
| `App\Enums\UserRole` | Ada | **Dihapus**, diganti `App\Enums\Ability` (8 case) |
| `User::isAdmin()` | Ada | **Dihapus.** Dilarang dibuat kembali |
| Policy | `$user->isAdmin()` | `$user->can(Ability::X->value)` |
| Factory `->admin()` | `state(['role' => 'admin'])` | `afterCreating(assignRole('admin'))` |
| Test yang memakai `->admin()` | Tidak perlu seeder | **Wajib** `seed(RolePermissionSeeder::class)` dulu |
| Menambah role baru | Ubah kode + deploy | Satu form di `/cms/roles` |
| Filament Shield | — | **Tidak dipakai.** 8 Ability, bukan ~40 permission per-Resource |
