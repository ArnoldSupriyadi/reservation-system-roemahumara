# Kalender Publik dan Nomor Reservasi — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menambahkan nomor reservasi berurutan `RU-R1` dan halaman kalender publik baca-saja bergaya neo-brutalism, di atas sistem v1 yang sudah selesai.

**Architecture:** Nomor dialokasikan dari tabel penghitung di dalam transaksi yang sudah ada di `ReservationWriter`, sehingga penyimpanan yang gagal tidak membuang nomor. Halaman publik memakai Blade biasa tanpa Livewire, dengan state di query string, dan membatasi data lewat `select()` eksplisit di lapisan query.

**Tech Stack:** PHP 8.3, Laravel 12.65, Filament v5.7.6, Livewire v4.3, MySQL 5.7.24, Tailwind CSS v3.2, PHPUnit 11.

**Spec:** `claude/2026-08-20-kalender-publik-dan-nomor-reservasi-design.md`

**Prasyarat:** Task 0–18 pada rencana v1 selesai dan seluruh testnya hijau (187 test).

## Global Constraints

Seluruh aturan CLAUDE.md tetap berlaku penuh, ditambah:

- **MySQL 5.7.24, bukan 8.0.** Dilarang memakai CTE, window function, functional index, atau `CHECK` constraint. Generated column dilarang merujuk kolom `AUTO_INCREMENT` (error 3109).
- **Penyimpanan reservasi tetap wajib lewat `ReservationWriter`.** Nomor dialokasikan di dalamnya, bukan di halaman Filament.
- **`NumberSequence::next()` wajib dipanggil di dalam transaksi.** Di luar transaksi, `FOR UPDATE` tidak menahan apa pun.
- **Halaman publik tidak boleh memuat kolom pribadi.** Batasnya di `select()`, bukan di template. Kolom terlarang: `guest_name`, `company`, `phone`, `email`, `remark`, `pax`, `pic_id`.
- **Nomor reservasi tidak pernah berubah** setelah dibuat, dan tidak masuk `$fillable`.
- **Test memakai MySQL**, bukan SQLite. Database dev dan test sama-sama `ru_reservation`.
- **Tidak ada Livewire di halaman publik.**
- Format nomor: `RU-R` + angka tanpa padding, mulai dari 1, tidak pernah di-reset.

---

## Task 19: Tabel penghitung dan alokator nomor

**Files:**
- Create: `database/migrations/2026_08_20_000001_create_counters_table.php`
- Create: `app/Services/NumberSequence.php`
- Test: `tests/Feature/NumberSequenceTest.php`

**Interfaces:**
- Consumes: tidak ada
- Produces: `NumberSequence::next(string $name): int` — mengembalikan nilai berikutnya dan menaikkan penghitung. Melempar `LogicException` bila dipanggil di luar transaksi atau bila penghitung bernama itu tidak ada.

Tabel bernama `counters`, bukan `reservation_counters`, agar penghitung lain di masa depan tidak memerlukan tabel baru.

- [ ] **Step 1: Buat migration**

`database/migrations/2026_08_20_000001_create_counters_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        // Barisnya dibuat di sini, bukan dibuat sendiri oleh NumberSequence saat
        // dibutuhkan. Membuatnya saat dibutuhkan berarti dua permintaan bersamaan
        // bisa sama-sama mendapati barisnya belum ada lalu sama-sama membuatnya.
        DB::table('counters')->insert([
            'name' => 'reservation',
            'value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('counters');
    }
};
```

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/NumberSequenceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\NumberSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class NumberSequenceTest extends TestCase
{
    use RefreshDatabase;

    private NumberSequence $sequence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sequence = app(NumberSequence::class);
    }

    private function next(): int
    {
        return DB::transaction(fn () => $this->sequence->next('reservation'));
    }

    public function test_the_first_number_is_one(): void
    {
        $this->assertSame(1, $this->next());
    }

    public function test_numbers_run_in_order(): void
    {
        $this->assertSame(1, $this->next());
        $this->assertSame(2, $this->next());
        $this->assertSame(3, $this->next());
    }

    /**
     * Inilah alasan seluruh tabel penghitung ini ada. AUTO_INCREMENT tidak ikut
     * mundur saat transaksi dibatalkan, sehingga nomor yang diturunkan darinya
     * akan bolong setiap kali sebuah penyimpanan ditolak.
     */
    public function test_a_rolled_back_transaction_does_not_consume_a_number(): void
    {
        $this->assertSame(1, $this->next());

        try {
            DB::transaction(function () {
                $this->sequence->next('reservation');

                throw new RuntimeException('batal');
            });
        } catch (RuntimeException) {
            // diharapkan
        }

        $this->assertSame(2, $this->next(), 'Nomor 2 tidak boleh terbuang.');
    }

    /** Spec nomor 28. */
    public function test_calling_outside_a_transaction_is_refused(): void
    {
        $this->expectException(LogicException::class);

        $this->sequence->next('reservation');
    }

    public function test_an_unknown_counter_is_refused(): void
    {
        $this->expectException(LogicException::class);

        DB::transaction(fn () => $this->sequence->next('tidak-ada'));
    }

    public function test_the_stored_value_matches_the_number_handed_out(): void
    {
        $this->next();
        $this->next();

        $this->assertSame(2, (int) DB::table('counters')->where('name', 'reservation')->value('value'));
    }
}
```

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=NumberSequenceTest`
Expected: FAIL dengan `Target class [App\Services\NumberSequence] does not exist.`

- [ ] **Step 4: Buat NumberSequence**

`app/Services/NumberSequence.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

class NumberSequence
{
    /**
     * Ambil nomor berikutnya dan naikkan penghitungnya.
     *
     * Wajib dipanggil di dalam transaksi. Kenaikan penghitung ikut mundur bila
     * transaksinya dibatalkan — inilah yang membuat nomor tidak pernah bolong,
     * dan yang membedakannya dari AUTO_INCREMENT.
     */
    public function next(string $name): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                "NumberSequence::next('{$name}') harus dipanggil di dalam transaksi. "
                .'Di luar transaksi, FOR UPDATE tidak menahan apa pun, sehingga dua '
                .'permintaan bersamaan bisa menerima nomor yang sama.'
            );
        }

        $current = DB::table('counters')
            ->where('name', $name)
            ->lockForUpdate()
            ->value('value');

        if ($current === null) {
            throw new LogicException("Penghitung '{$name}' tidak ada di tabel counters.");
        }

        $next = ((int) $current) + 1;

        DB::table('counters')
            ->where('name', $name)
            ->update([
                'value' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }
}
```

`counters` tidak punya audit log dan bukan model berperilaku, sehingga memakai query
builder di sini tidak melanggar aturan #2 CLAUDE.md. Aturan itu ada untuk menjaga
`activity_log`, yang tidak berlaku bagi tabel ini.

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=NumberSequenceTest`
Expected: 6 test PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Services/NumberSequence.php tests/Feature/NumberSequenceTest.php
git commit -m "feat: tabel penghitung dan alokator nomor berurutan"
```

---

## Task 20: Kolom nomor reservasi dan alokasinya

**Files:**
- Create: `database/migrations/2026_08_20_000002_add_reservation_number_to_reservations_table.php`
- Modify: `app/Models/Reservation.php`
- Modify: `app/Services/ReservationWriter.php`
- Modify: `database/factories/ReservationFactory.php`
- Test: `tests/Feature/ReservationNumberTest.php`

**Interfaces:**
- Consumes: `NumberSequence::next(string $name): int` (Task 19)
- Produces: `Reservation::NUMBER_PREFIX` bernilai `'RU-R'`; kolom `reservations.reservation_number` berisi `RU-R1` dan seterusnya; `ReservationWriter` menerima `NumberSequence` lewat constructor.

Menutup spec bagian 8 nomor 23 sampai 28.

- [ ] **Step 1: Buat migration**

`database/migrations/2026_08_20_000002_add_reservation_number_to_reservations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kolomnya NOT NULL, sehingga tidak bisa langsung dibuat begitu pada tabel
        // yang sudah berisi baris. Urutannya: nullable, isi, baru dikunci.
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('reservation_number', 20)->nullable()->after('id');
        });

        $last = 0;

        // Baris ter-soft-delete ikut diberi nomor. Nomor melekat pada reservasi
        // seumur hidupnya dan tidak pernah didaur ulang.
        foreach (DB::table('reservations')->orderBy('id')->pluck('id') as $id) {
            $last++;

            DB::table('reservations')
                ->where('id', $id)
                ->update(['reservation_number' => 'RU-R'.$last]);
        }

        if ($last > 0) {
            DB::table('counters')
                ->where('name', 'reservation')
                ->update(['value' => $last, 'updated_at' => now()]);
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('reservation_number', 20)->nullable(false)->change();
            $table->unique('reservation_number', 'uniq_reservations_number');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique('uniq_reservations_number');
            $table->dropColumn('reservation_number');
        });
    }
};
```

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/ReservationNumberTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateReservationException;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationNumberTest extends TestCase
{
    use RefreshDatabase;

    private ReservationWriter $writer;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(ReservationWriter::class);
        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'company' => null,
            'phone' => '08112233445',
            'email' => null,
            'pic_id' => $this->actor->id,
            'event_type_id' => null,
            'menu_style_id' => null,
            'area_id' => null,
            'start_time' => '12:00',
            'end_time' => null,
            'pax' => 3,
            'status' => null,
            'remark' => null,
        ], $overrides);
    }

    private function create(array $overrides = []): Reservation
    {
        return $this->writer->create($this->payload($overrides), (string) Str::uuid(), $this->actor);
    }

    /** Spec nomor 23. */
    public function test_the_first_reservation_is_numbered_one(): void
    {
        $this->assertSame('RU-R1', $this->create()->reservation_number);
    }

    /** Spec nomor 24. */
    public function test_numbers_run_in_order(): void
    {
        $this->assertSame('RU-R1', $this->create(['guest_name' => 'Satu'])->reservation_number);
        $this->assertSame('RU-R2', $this->create(['guest_name' => 'Dua'])->reservation_number);
        $this->assertSame('RU-R3', $this->create(['guest_name' => 'Tiga'])->reservation_number);
    }

    /**
     * Spec nomor 25. Inilah alasan tabel penghitung dipakai, bukan id.
     */
    public function test_a_rejected_duplicate_does_not_burn_a_number(): void
    {
        $this->assertSame('RU-R1', $this->create(['guest_name' => 'Tanti'])->reservation_number);

        try {
            $this->create(['guest_name' => 'Tanti']);
            $this->fail('Duplikat seharusnya ditolak.');
        } catch (DuplicateReservationException) {
            // diharapkan
        }

        $this->assertSame(
            'RU-R2',
            $this->create(['guest_name' => 'Melinda'])->reservation_number,
            'Percobaan yang ditolak tidak boleh membuang nomor.'
        );
    }

    /** Spec nomor 26. */
    public function test_a_repeated_idempotency_key_does_not_take_a_new_number(): void
    {
        $key = (string) Str::uuid();

        $first = $this->writer->create($this->payload(), $key, $this->actor);
        $second = $this->writer->create($this->payload(['pax' => 99]), $key, $this->actor);

        $this->assertSame($first->reservation_number, $second->reservation_number);
        $this->assertSame('RU-R1', $second->reservation_number);
        $this->assertSame(1, Reservation::count());

        $this->assertSame(
            'RU-R2',
            $this->create(['guest_name' => 'Berikutnya'])->reservation_number,
            'Submit ulang tidak boleh menggeser nomor berikutnya.'
        );
    }

    /** Spec nomor 27. */
    public function test_editing_never_changes_the_number(): void
    {
        $r = $this->create();

        $updated = $this->writer->update($r, $this->payload(['pax' => 8]), 1, $this->actor);

        $this->assertSame('RU-R1', $updated->reservation_number);
        $this->assertSame(8, $updated->pax);
    }

    public function test_the_number_is_not_mass_assignable(): void
    {
        $r = $this->writer->create(
            $this->payload(['reservation_number' => 'RU-R999']),
            (string) Str::uuid(),
            $this->actor
        );

        $this->assertSame('RU-R1', $r->reservation_number, 'Nomor tidak boleh bisa diisi dari input.');
    }

    /**
     * Factory memakai rentang angka tinggi supaya tidak pernah bertabrakan dengan
     * nomor sungguhan yang dimulai dari 1.
     */
    public function test_factory_numbers_never_collide_with_allocated_ones(): void
    {
        Reservation::factory()->count(3)->create();

        $this->assertSame('RU-R1', $this->create(['guest_name' => 'Sungguhan'])->reservation_number);
    }
}
```

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationNumberTest`
Expected: FAIL — kolomnya belum diisi siapa pun, sehingga penyimpanan ditolak `NOT NULL`.

- [ ] **Step 4: Tambahkan konstanta prefiks pada model**

Di `app/Models/Reservation.php`, tepat di bawah `use SoftDeletes;`, tambahkan:

```php
    /**
     * Prefiks nomor reservasi. Ditulis sekali di sini, bukan disebar sebagai
     * literal di beberapa berkas.
     */
    public const NUMBER_PREFIX = 'RU-R';
```

**Jangan** menambahkan `reservation_number` ke `$fillable`. Kolom ini sejajar dengan
`version`, `idempotency_key`, `created_by`, dan `updated_by` yang sudah lebih dulu
berada di luar `$fillable` karena diisi `ReservationWriter`, bukan oleh input pengguna.

- [ ] **Step 5: Alokasikan nomor di ReservationWriter**

`ReservationWriter` sudah berada di namespace `App\Services`, sama dengan
`NumberSequence`, sehingga **tidak perlu menambahkan `use` statement apa pun**.

Di `app/Services/ReservationWriter.php`, tambahkan constructor tepat di bawah
deklarasi konstanta:

```php
    public function __construct(private readonly NumberSequence $sequence) {}
```

Lalu di dalam closure `DB::transaction()` pada `create()`, tambahkan satu baris
sebelum `$reservation->save()`:

```php
            return DB::transaction(function () use ($data, $idempotencyKey, $actor) {
                $reservation = new Reservation();
                $reservation->fill($data);
                $reservation->reservation_number = Reservation::NUMBER_PREFIX
                    .$this->sequence->next('reservation');
                $reservation->idempotency_key = $idempotencyKey;
                $reservation->created_by = $actor->id;
                $reservation->version = 1;
                $reservation->save();

                return $reservation;
            });
```

Method `update()` **tidak** disentuh sama sekali. Nomor ditetapkan sekali seumur hidup
reservasi.

Perhatikan bahwa pemeriksaan `idempotency_key` di awal `create()` melakukan `return`
sebelum transaksi dimulai, sehingga submit ulang memang tidak pernah menyentuh
alokator. Ini yang diuji `test_a_repeated_idempotency_key_does_not_take_a_new_number`.

- [ ] **Step 6: Isi nomor pada factory**

Di `database/factories/ReservationFactory.php`, tambahkan properti statis di dalam
class, di atas `definition()`:

```php
    /**
     * Penghitung milik factory sendiri, terpisah dari tabel counters.
     *
     * Rentangnya sengaja dimulai dari 900000 agar tidak pernah bertabrakan dengan
     * nomor sungguhan yang dialokasikan ReservationWriter mulai dari 1. Nilainya
     * tidak di-reset antar test, dan memang tidak perlu — yang dibutuhkan hanya
     * keunikan, bukan urutan tertentu.
     */
    private static int $numberSeed = 900000;
```

Tambahkan import di bagian atas berkas, di samping `use App\Models\User;` yang sudah ada:

```php
use App\Models\Reservation;
```

Lalu tambahkan satu baris di dalam array `definition()`:

```php
            'reservation_number' => Reservation::NUMBER_PREFIX.(++static::$numberSeed),
```

- [ ] **Step 7: Jalankan test**

Run: `php artisan test --filter=ReservationNumberTest`
Expected: 7 test PASS

- [ ] **Step 8: Jalankan seluruh test**

Run: `php artisan test`
Expected: seluruhnya PASS. Bila ada test lama yang gagal karena `NOT NULL`, berarti
ada jalur pembuatan reservasi yang belum lewat factory atau writer — perbaiki jalurnya,
jangan melonggarkan kolomnya.

- [ ] **Step 9: Commit**

```bash
git add database app/Models/Reservation.php app/Services/ReservationWriter.php tests/Feature/ReservationNumberTest.php
git commit -m "feat: nomor reservasi berurutan RU-R yang tidak pernah bolong"
```

---

## Task 21: Nomor reservasi di panel CMS

**Files:**
- Modify: `app/Filament/Resources/Reservations/Tables/ReservationsTable.php`
- Modify: `app/Filament/Resources/Reservations/Schemas/ReservationInfolist.php`
- Test: `tests/Feature/ReservationNumberUiTest.php`

**Interfaces:**
- Consumes: kolom `reservation_number` (Task 20)
- Produces: tidak ada antarmuka baru untuk task berikutnya

Menutup spec bagian 8 nomor 29. Nomor **tidak** ditambahkan ke form Filament — nomor
ditetapkan sistem, bukan diisi pengguna.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/ReservationNumberUiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\CreateReservation;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Filament\Resources\Reservations\Pages\ViewReservation;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ReservationNumberUiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Filament::setCurrentPanel('cms');

        $this->admin = User::factory()->admin()->create(['name' => 'IRA']);
        $this->actingAs($this->admin);
    }

    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'reservation_date' => Carbon::now()->startOfMonth()->addDays(5),
            'pic_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    /** Spec nomor 29. */
    public function test_the_number_is_shown_in_the_table(): void
    {
        $r = $this->reservation();

        Livewire::test(ListReservations::class)
            ->assertTableColumnStateSet('reservation_number', $r->reservation_number, $r);
    }

    public function test_the_number_can_be_searched(): void
    {
        $wanted = $this->reservation(['guest_name' => 'Dicari']);
        $other = $this->reservation(['guest_name' => 'Bukan Ini']);

        Livewire::test(ListReservations::class)
            ->searchTable($wanted->reservation_number)
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_number_is_shown_on_the_detail_page(): void
    {
        $r = $this->reservation();

        Livewire::test(ViewReservation::class, ['record' => $r->getKey()])
            ->assertSee($r->reservation_number);
    }

    /**
     * Nomor ditetapkan sistem. Menawarkannya sebagai field yang bisa diisi akan
     * mengundang pengguna menimpanya.
     */
    public function test_the_number_is_not_offered_as_a_form_field(): void
    {
        Livewire::test(CreateReservation::class)
            ->assertFormFieldDoesNotExist('reservation_number');
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationNumberUiTest`
Expected: FAIL — kolom `reservation_number` belum ada di tabel maupun infolist.

- [ ] **Step 3: Tambahkan kolom ke tabel**

Di `app/Filament/Resources/Reservations/Tables/ReservationsTable.php`, di dalam
`Split::make([...])`, tambahkan sebagai unsur **pertama**, sebelum
`TextColumn::make('reservation_date')`:

```php
                    TextColumn::make('reservation_number')
                        ->label('No.')
                        ->searchable()
                        ->weight(FontWeight::Bold)
                        ->color('gray')
                        ->grow(false),
```

`FontWeight` sudah diimpor di berkas itu.

- [ ] **Step 4: Tambahkan entri ke infolist**

Di `app/Filament/Resources/Reservations/Schemas/ReservationInfolist.php`, di dalam
`Section::make('Reservasi')->schema([...])`, tambahkan sebagai unsur **pertama**:

```php
                    TextEntry::make('reservation_number')->label('No. reservasi'),
```

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=ReservationNumberUiTest`
Expected: 4 test PASS

- [ ] **Step 6: Commit**

```bash
git add app/Filament tests/Feature/ReservationNumberUiTest.php
git commit -m "feat: tampilkan nomor reservasi di tabel dan detail CMS"
```

---

## Task 22: Grid bulan dipakai bersama

**Files:**
- Create: `app/Support/MonthGrid.php`
- Modify: `app/Filament/Pages/ReservationCalendar.php`
- Create: `tests/Unit/MonthGridTest.php`
- Modify: `tests/Feature/ReservationCalendarTest.php`

**Interfaces:**
- Consumes: tidak ada
- Produces:
  - `MonthGrid::cells(string $month): array` — array sel `['day' => ?int, 'iso' => ?string]`, minggu dimulai Senin, sel kosong di awal bulan bernilai `null`
  - `MonthGrid::label(string $month): string` — nama bulan berbahasa Indonesia, misalnya `Agustus 2026`
  - `MonthGrid::normalize(?string $month): string` — mengembalikan `Y-m` yang sah, jatuh ke bulan berjalan bila masukannya kosong atau tidak sah
  - `MonthGrid::shift(string $month, int $delta): string` — bulan lain relatif terhadap bulan itu

Aritmetika ini sudah diuji ketat di Task 16 untuk tujuh bulan yang tanggal 1-nya jatuh
pada tujuh hari berbeda. Menyalinnya ke halaman publik berarti mengundang salah satu
salinan menyimpang tanpa ketahuan.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Unit/MonthGridTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\MonthGrid;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthGridTest extends TestCase
{
    /**
     * Spec nomor 38. Tujuh bulan yang tanggal 1-nya jatuh pada tujuh hari berbeda.
     */
    public function test_the_week_starts_on_monday_whatever_day_the_month_begins(): void
    {
        foreach (['2026-06', '2026-09', '2026-04', '2026-01', '2026-05', '2026-08', '2026-02'] as $month) {
            $cells = MonthGrid::cells($month);
            $first = Carbon::createFromFormat('Y-m-d', $month.'-01');
            $expectedLead = ($first->dayOfWeek + 6) % 7;

            $lead = 0;

            foreach ($cells as $cell) {
                if ($cell['day'] !== null) {
                    break;
                }

                $lead++;
            }

            $this->assertSame($expectedLead, $lead, "Sel kosong salah untuk {$month} ({$first->format('l')}).");
            $this->assertSame($expectedLead + $first->daysInMonth, count($cells), "Jumlah sel salah untuk {$month}.");
        }
    }

    public function test_january_first_2026_lands_in_the_thursday_column(): void
    {
        $cells = MonthGrid::cells('2026-01');

        $this->assertSame('Thursday', Carbon::parse('2026-01-01')->format('l'));
        $this->assertSame(3, array_search(1, array_column($cells, 'day'), true));
    }

    public function test_each_day_carries_its_iso_date(): void
    {
        $cells = MonthGrid::cells('2026-08');
        $days = array_values(array_filter($cells, fn ($cell) => $cell['day'] !== null));

        $this->assertSame('2026-08-01', $days[0]['iso']);
        $this->assertSame('2026-08-09', $days[8]['iso']);
        $this->assertSame('2026-08-31', $days[30]['iso']);
    }

    public function test_the_label_is_written_in_indonesian(): void
    {
        $this->assertSame('Agustus 2026', MonthGrid::label('2026-08'));
    }

    public function test_a_valid_month_passes_through_normalize(): void
    {
        $this->assertSame('2026-08', MonthGrid::normalize('2026-08'));
    }

    public function test_rubbish_falls_back_to_the_current_month(): void
    {
        $now = Carbon::now()->format('Y-m');

        $this->assertSame($now, MonthGrid::normalize(null));
        $this->assertSame($now, MonthGrid::normalize(''));
        $this->assertSame($now, MonthGrid::normalize('bukan-bulan'));
        $this->assertSame($now, MonthGrid::normalize('2026-13'));
        $this->assertSame($now, MonthGrid::normalize('2026-8'));
        $this->assertSame($now, MonthGrid::normalize('<script>alert(1)</script>'));
    }

    public function test_shift_moves_by_whole_months(): void
    {
        $this->assertSame('2026-09', MonthGrid::shift('2026-08', 1));
        $this->assertSame('2026-07', MonthGrid::shift('2026-08', -1));
        $this->assertSame('2027-01', MonthGrid::shift('2026-12', 1));
        $this->assertSame('2025-12', MonthGrid::shift('2026-01', -1));
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=MonthGridTest`
Expected: FAIL dengan `Class "App\Support\MonthGrid" not found`

- [ ] **Step 3: Buat MonthGrid**

`app/Support/MonthGrid.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Aritmetika grid kalender bulanan, dipakai bersama oleh halaman kalender staf
 * dan halaman kalender publik.
 */
class MonthGrid
{
    /**
     * Sel kalender dengan minggu dimulai hari Senin.
     * Sel kosong di awal bulan bernilai null.
     *
     * @return array<int, array{day: ?int, iso: ?string}>
     */
    public static function cells(string $month): array
    {
        $first = self::firstDay($month);

        // dayOfWeek: 0 = Minggu. Geser agar Senin menjadi 0.
        $lead = ($first->dayOfWeek + 6) % 7;

        $cells = array_fill(0, $lead, ['day' => null, 'iso' => null]);

        for ($day = 1; $day <= $first->daysInMonth; $day++) {
            $cells[] = [
                'day' => $day,
                'iso' => sprintf('%s-%02d', $month, $day),
            ];
        }

        return $cells;
    }

    public static function label(string $month): string
    {
        return self::firstDay($month)->translatedFormat('F Y');
    }

    public static function shift(string $month, int $delta): string
    {
        return self::firstDay($month)->addMonths($delta)->format('Y-m');
    }

    /**
     * Kembalikan Y-m yang sah. Masukan kosong atau tidak sah jatuh ke bulan berjalan,
     * sehingga halaman publik tidak bisa dijatuhkan lewat query string.
     */
    public static function normalize(?string $month): string
    {
        if ($month === null || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return Carbon::now()->format('Y-m');
        }

        return $month;
    }

    private static function firstDay(string $month): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
    }
}
```

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=MonthGridTest`
Expected: 8 test PASS

- [ ] **Step 5: Pakai MonthGrid di halaman kalender staf**

Di `app/Filament/Pages/ReservationCalendar.php`, tambahkan import:

```php
use App\Support\MonthGrid;
```

Ganti isi `shiftMonth()`, `getCellsProperty()`, dan `getMonthLabelProperty()`:

```php
    public function shiftMonth(int $delta): void
    {
        $this->month = MonthGrid::shift($this->month, $delta);

        $this->selectedId = null;
    }

    /**
     * @return array<int, array{day: ?int, iso: ?string}>
     */
    public function getCellsProperty(): array
    {
        return MonthGrid::cells($this->month);
    }

    public function getMonthLabelProperty(): string
    {
        return MonthGrid::label($this->month);
    }
```

Import `Illuminate\Support\Carbon` tetap dibutuhkan oleh `mount()` dan
`getReservationsProperty()`, jadi jangan dihapus.

- [ ] **Step 6: Pindahkan test grid dari ReservationCalendarTest**

Di `tests/Feature/ReservationCalendarTest.php`, hapus dua method berikut karena
sekarang sudah tercakup `MonthGridTest` dan tidak perlu dijalankan dua kali lewat
Livewire yang jauh lebih lambat:

- `test_the_week_starts_on_monday_whatever_day_the_month_begins()`
- `test_january_first_2026_lands_in_the_thursday_column()`

Method lainnya **jangan** disentuh. `test_the_month_label_is_written_in_indonesian()`
tetap tinggal, karena ia menguji halaman Livewire-nya, bukan aritmetikanya.

- [ ] **Step 7: Jalankan seluruh test**

Run: `php artisan test`
Expected: seluruhnya PASS. Kalender staf harus tetap berperilaku sama persis.

- [ ] **Step 8: Commit**

```bash
git add app/Support/MonthGrid.php app/Filament/Pages/ReservationCalendar.php tests/Unit/MonthGridTest.php tests/Feature/ReservationCalendarTest.php
git commit -m "refactor: pisahkan aritmetika grid bulan agar dipakai bersama"
```

---

## Task 23: Halaman kalender publik

**Files:**
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/PublicCalendarController.php`
- Modify: `app/Enums/ReservationStatus.php`
- Create: `resources/views/layouts/public.blade.php`
- Create: `resources/views/public/calendar.blade.php`
- Modify: `tests/Feature/ExampleTest.php`
- Test: `tests/Feature/PublicCalendarTest.php`

**Interfaces:**
- Consumes: `MonthGrid` (Task 22), `Reservation` (v1 Task 4)
- Produces: rute bernama `public.calendar` pada `/`; `ReservationStatus::publicLabel(): string`

Menutup spec bagian 8 nomor 30 sampai 37. Task ini menghasilkan halaman yang
**berfungsi dan aman**, belum bergaya. Gayanya dikerjakan Task 24, sehingga
peninjau bisa menerima perilakunya dan menolak tampilannya secara terpisah.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/PublicCalendarTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\EventType;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $pic;

    private Area $area;

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();

        // Nama PIC sengaja dibuat mencolok dan tidak mungkin muncul kebetulan,
        // supaya assertDontSee() di bawah benar-benar bermakna.
        $this->pic = User::factory()->create(['name' => 'RAHASIAPIC']);
        $this->area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);
        $this->month = Carbon::now()->format('Y-m');
    }

    private function reservation(array $overrides = []): Reservation
    {
        return Reservation::factory()->create(array_merge([
            'reservation_date' => Carbon::now()->startOfMonth()->addDays(7),
            'pic_id' => $this->pic->id,
            'created_by' => $this->pic->id,
            'start_time' => '12:00:00',
            'area_id' => $this->area->id,
        ], $overrides));
    }

    /** Spec nomor 30. */
    public function test_the_page_opens_without_logging_in(): void
    {
        $this->reservation();

        $this->assertGuest();
        $this->get('/')->assertOk();
    }

    /**
     * Spec nomor 31. Test terpenting di berkas ini: ia menjaga satu-satunya
     * keputusan yang bisa merugikan tamu bila salah.
     */
    public function test_no_private_data_ever_reaches_the_page(): void
    {
        $r = $this->reservation([
            'guest_name' => 'RAHASIANAMA',
            'company' => 'RAHASIAPERUSAHAAN',
            'phone' => '081999888777',
            'email' => 'rahasia@contoh.test',
            'remark' => 'RAHASIAREMARK sudah DP 50 persen',
            'pax' => 4242,
        ]);

        foreach (['/', "/?bulan={$this->month}", "/?bulan={$this->month}&pilih={$r->id}"] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertDontSee('RAHASIANAMA')
                ->assertDontSee('RAHASIAPERUSAHAAN')
                ->assertDontSee('081999888777')
                ->assertDontSee('rahasia@contoh.test')
                ->assertDontSee('RAHASIAREMARK')
                ->assertDontSee('4242')
                ->assertDontSee('RAHASIAPIC');
        }
    }

    public function test_the_page_shows_what_it_is_supposed_to(): void
    {
        $this->reservation(['end_time' => '15:00:00', 'status' => 'confirmed']);

        $this->get("/?bulan={$this->month}")
            ->assertOk()
            ->assertSee('12:00')
            ->assertSee('VIP 1');
    }

    /** Spec nomor 32. */
    public function test_only_the_requested_month_is_listed(): void
    {
        $this->reservation(['start_time' => '06:30:00']);

        $next = Carbon::now()->startOfMonth()->addMonth();
        $this->reservation([
            'reservation_date' => $next->copy()->addDays(3),
            'start_time' => '19:45:00',
        ]);

        $this->get("/?bulan={$this->month}")
            ->assertOk()
            ->assertSee('06:30')
            ->assertDontSee('19:45');

        $this->get('/?bulan='.$next->format('Y-m'))
            ->assertOk()
            ->assertSee('19:45')
            ->assertDontSee('06:30');
    }

    /** Spec nomor 33. */
    public function test_a_soft_deleted_reservation_disappears(): void
    {
        $r = $this->reservation(['start_time' => '07:15:00']);

        $this->get("/?bulan={$this->month}")->assertSee('07:15');

        $r->delete();

        $this->get("/?bulan={$this->month}")->assertDontSee('07:15');
    }

    /** Spec nomor 34. */
    public function test_confirmed_and_tentative_read_differently(): void
    {
        $this->reservation(['status' => 'confirmed', 'start_time' => '08:00:00']);

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('Terisi');

        Reservation::query()->forceDelete();
        $this->reservation(['status' => 'tentative', 'start_time' => '08:00:00']);

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('Sedang dijajaki');
    }

    public function test_a_reservation_without_a_status_reads_as_tentative(): void
    {
        $this->reservation(['status' => null, 'start_time' => '09:00:00']);

        $this->get("/?bulan={$this->month}")->assertOk()->assertSee('Sedang dijajaki');
    }

    public function test_internal_status_words_never_appear(): void
    {
        $this->reservation(['status' => 'confirmed']);

        $this->get("/?bulan={$this->month}")
            ->assertOk()
            ->assertDontSee('CONFIRMED')
            ->assertDontSee('TENTATIVE');
    }

    /** Spec nomor 35. */
    public function test_the_detail_panel_shows_area_time_and_event(): void
    {
        $event = EventType::create(['name' => 'MEETING', 'sort_order' => 1]);
        $r = $this->reservation(['end_time' => '15:00:00', 'event_type_id' => $event->id]);

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertSee('12:00')
            ->assertSee('15:00')
            ->assertSee('VIP 1')
            ->assertSee('MEETING');
    }

    /** Spec nomor 36. */
    public function test_a_rubbish_month_falls_back_instead_of_failing(): void
    {
        $this->reservation();

        foreach (['bukan-bulan', '2026-13', '2026-8', '<script>alert(1)</script>', ''] as $rubbish) {
            $this->get('/?bulan='.urlencode($rubbish))->assertOk();
        }
    }

    /** Spec nomor 37. */
    public function test_a_selection_from_another_month_is_ignored(): void
    {
        $next = Carbon::now()->startOfMonth()->addMonth();
        $other = $this->reservation([
            'reservation_date' => $next->copy()->addDays(3),
            'start_time' => '21:45:00',
        ]);

        $this->get("/?bulan={$this->month}&pilih={$other->id}")
            ->assertOk()
            ->assertDontSee('21:45');
    }

    public function test_a_nonexistent_selection_does_not_fail(): void
    {
        $this->reservation();

        $this->get("/?bulan={$this->month}&pilih=999999")->assertOk();
        $this->get("/?bulan={$this->month}&pilih=bukan-angka")->assertOk();
    }

    public function test_the_page_does_not_link_to_the_staff_panel(): void
    {
        $r = $this->reservation();

        $this->get("/?bulan={$this->month}&pilih={$r->id}")
            ->assertOk()
            ->assertDontSee('/cms');
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=PublicCalendarTest`
Expected: FAIL — `/` masih mengalihkan ke `/cms`, sehingga `assertOk()` mendapat 302.

- [ ] **Step 3: Tambahkan label publik pada enum status**

Di `app/Enums/ReservationStatus.php`, tambahkan satu method di bawah `label()`:

```php
    /**
     * Istilah untuk halaman publik. CONFIRMED dan TENTATIVE adalah kosakata
     * internal yang tidak perlu dibawa ke luar.
     */
    public function publicLabel(): string
    {
        return match ($this) {
            self::Tentative => 'Sedang dijajaki',
            self::Confirmed => 'Terisi',
        };
    }
```

Status yang bernilai `null` ditangani di controller, bukan di sini.

- [ ] **Step 4: Buat controller**

`app/Http/Controllers/PublicCalendarController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Support\MonthGrid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicCalendarController extends Controller
{
    public function __invoke(Request $request): View
    {
        $month = MonthGrid::normalize($request->query('bulan'));
        $reservations = $this->reservationsIn($month);

        $selectedId = (int) $request->query('pilih');

        return view('public.calendar', [
            'month' => $month,
            'monthLabel' => MonthGrid::label($month),
            'previousMonth' => MonthGrid::shift($month, -1),
            'nextMonth' => MonthGrid::shift($month, 1),
            'cells' => MonthGrid::cells($month),
            'byDate' => $reservations->groupBy(fn (Reservation $r) => $r->reservation_date->toDateString()),
            'total' => $reservations->count(),
            'selectedId' => $selectedId,
            // Dicari di dalam koleksi bulan yang sedang tampil, sehingga pilihan
            // dari bulan lain otomatis terabaikan.
            'selected' => $reservations->firstWhere('id', $selectedId),
        ]);
    }

    /**
     * HANYA kolom yang boleh dilihat umum.
     *
     * Batas ini sengaja ditegakkan di sini, bukan di template. Dengan select()
     * eksplisit, kolom pribadi tidak pernah dimuat ke memori — sehingga satu baris
     * ceroboh di Blade suatu hari nanti menghasilkan nilai kosong, bukan kebocoran
     * nomor HP dan catatan pembayaran tamu.
     *
     * JANGAN menambahkan guest_name, company, phone, email, remark, pax, atau
     * pic_id ke daftar ini.
     *
     * @return Collection<int, Reservation>
     */
    private function reservationsIn(string $month): Collection
    {
        [$year, $monthNumber] = explode('-', $month);

        return Reservation::query()
            ->select([
                'id',
                'reservation_date',
                'start_time',
                'end_time',
                'status',
                'area_id',
                'event_type_id',
            ])
            ->with(['area:id,name', 'eventType:id,name'])
            ->whereYear('reservation_date', (int) $year)
            ->whereMonth('reservation_date', (int) $monthNumber)
            ->orderBy('reservation_date')
            ->orderBy('start_time')
            ->get();
    }
}
```

Baris ter-soft-delete tersaring sendiri oleh global scope `SoftDeletes` pada model.

- [ ] **Step 5: Ganti rute**

Ganti seluruh isi `routes/web.php`:

```php
<?php

use App\Http\Controllers\PublicCalendarController;
use Illuminate\Support\Facades\Route;

// `/` tidak lagi mengalihkan ke /cms. Staf masuk lewat /cms langsung.
Route::get('/', PublicCalendarController::class)->name('public.calendar');
```

- [ ] **Step 6: Buat layout publik**

`resources/views/layouts/public.blade.php`. Task ini sengaja belum bergaya —
Task 24 yang mengisinya.

**Perhatian:** `@vite()` membaca `public/build/manifest.json`. Bila berkas itu belum
ada, seluruh test halaman publik gagal dengan `Vite manifest not found`, bukan dengan
pesan yang menunjuk ke penyebabnya. Jalankan `npm run build` sekali sebelum
menjalankan test task ini bila belum pernah dibangun di mesin tersebut.

```blade
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Jadwal Roemah Umara')</title>
    @vite(['resources/css/app.css'])
</head>
<body>
    <header>
        <h1>Roemah Umara</h1>
        <p>Jadwal ketersediaan tempat</p>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        <p>Halaman ini hanya menampilkan ketersediaan. Untuk memesan, hubungi kami langsung.</p>
    </footer>
</body>
</html>
```

- [ ] **Step 7: Buat halaman kalender**

`resources/views/public/calendar.blade.php`:

```blade
@extends('layouts.public')

@section('title', 'Jadwal '.$monthLabel.' — Roemah Umara')

@section('content')
    <section>
        <h2>{{ $monthLabel }}</h2>

        <a href="{{ route('public.calendar', ['bulan' => $previousMonth]) }}" rel="nofollow">Bulan sebelumnya</a>
        <a href="{{ route('public.calendar', ['bulan' => $nextMonth]) }}" rel="nofollow">Bulan berikutnya</a>

        <p>{{ $total }} jadwal pada bulan ini</p>
    </section>

    <table>
        <thead>
            <tr>
                @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $name)
                    <th scope="col">{{ $name }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach (array_chunk($cells, 7) as $week)
                <tr>
                    @foreach ($week as $cell)
                        <td>
                            @if ($cell['day'] !== null)
                                <span>{{ $cell['day'] }}</span>

                                @foreach (($byDate[$cell['iso']] ?? collect()) as $r)
                                    <a
                                        href="{{ route('public.calendar', ['bulan' => $month, 'pilih' => $r->id]) }}"
                                        @class(['is-selected' => $r->id === $selectedId])
                                    >
                                        <strong>{{ substr($r->start_time, 0, 5) }}</strong>
                                        {{ $r->area?->name }}
                                    </a>
                                @endforeach
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($selected)
        <section>
            <h3>{{ $selected->reservation_date->translatedFormat('l, d F Y') }}</h3>

            <dl>
                <dt>Jam</dt>
                <dd>
                    @if (blank($selected->end_time))
                        {{ substr($selected->start_time, 0, 5) }} (jam tunggal)
                    @else
                        {{ substr($selected->start_time, 0, 5) }}–{{ substr($selected->end_time, 0, 5) }}
                    @endif
                </dd>

                <dt>Area</dt>
                <dd>{{ $selected->area?->name ?? 'Belum ditentukan' }}</dd>

                <dt>Jenis acara</dt>
                <dd>{{ $selected->eventType?->name ?? '—' }}</dd>

                <dt>Status</dt>
                <dd>{{ $selected->status?->publicLabel() ?? 'Sedang dijajaki' }}</dd>
            </dl>
        </section>
    @endif
@endsection
```

Baris terakhir sel kalender bisa berisi kurang dari tujuh sel bila bulannya tidak
berakhir pada hari Minggu. `array_chunk()` menghasilkan baris pendek, dan itu sah
secara HTML — tidak perlu diisi sel kosong.

- [ ] **Step 8: Sesuaikan test rute lama**

Di `tests/Feature/ExampleTest.php`, ganti method `test_root_redirects_to_the_cms_panel()`:

```php
    public function test_root_serves_the_public_calendar(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Roemah Umara');
    }

    public function test_the_staff_panel_still_needs_a_login(): void
    {
        $this->get('/cms')->assertRedirect('/cms/login');
    }
```

Method kedua ditambahkan supaya perpindahan `/` tidak diam-diam membuka panel staf.

- [ ] **Step 9: Jalankan test**

Run: `php artisan test --filter=PublicCalendarTest`
Expected: 13 test PASS

- [ ] **Step 10: Jalankan seluruh test**

Run: `php artisan test`
Expected: seluruhnya PASS.

- [ ] **Step 11: Commit**

```bash
git add routes app/Http/Controllers/PublicCalendarController.php app/Enums/ReservationStatus.php resources/views tests/Feature/PublicCalendarTest.php tests/Feature/ExampleTest.php
git commit -m "feat: kalender publik baca-saja tanpa data pribadi"
```

---

## Task 24: Gaya neo-brutalism

**Files:**
- Modify: `package.json`
- Modify: `tailwind.config.js`
- Modify: `resources/css/app.css`
- Modify: `resources/views/layouts/public.blade.php`
- Modify: `resources/views/public/calendar.blade.php`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: halaman kalender publik (Task 23)
- Produces: tidak ada antarmuka baru

Task ini hanya mengubah tampilan. Seluruh test Task 23 harus tetap hijau tanpa
disentuh — itulah pembuktian bahwa penataan gaya tidak mengubah data yang keluar.

- [ ] **Step 1: Buang paket Tailwind v4 yang menganggur**

```bash
npm remove @tailwindcss/vite
```

Proyek memakai Tailwind v3 lewat `postcss.config.js`. Paket v4 tersisa dari
scaffolding dan tidak dipakai; membiarkan dua versi terpasang hanya mengundang
kebingungan saat menelusuri masalah CSS.

Filament tidak terpengaruh — ia memuat CSS hasil kompilasinya sendiri dan tidak
membaca `tailwind.config.js` proyek.

- [ ] **Step 2: Arahkan Tailwind ke berkas yang benar**

Ganti seluruh isi `tailwind.config.js`:

```js
import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './app/Http/Controllers/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ink: '#111111',
                paper: '#FFFDF5',
                brand: '#FFD400',
                taken: '#FF5A36',
                tentative: '#7FB3FF',
            },
            boxShadow: {
                brut: '6px 6px 0 0 #111111',
                'brut-sm': '3px 3px 0 0 #111111',
            },
        },
    },

    plugins: [],
};
```

Glob `vendor/laravel/framework/.../Pagination` dari konfigurasi lama dibuang karena
halaman publik tidak memakai pagination sama sekali.

- [ ] **Step 3: Tulis token dan kelas komponen**

Ganti seluruh isi `resources/css/app.css`:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
    body {
        @apply bg-paper text-ink font-sans;
    }
}

@layer components {
    .brut-box {
        @apply border-[3px] border-ink bg-white shadow-brut;
    }

    .brut-btn {
        @apply inline-block border-[3px] border-ink bg-brand px-4 py-2
               text-sm font-black uppercase tracking-wide shadow-brut-sm
               hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none;
    }

    .brut-chip {
        @apply mb-1 block w-full border-[2px] border-ink px-1 py-0.5
               text-left text-[11px] font-bold leading-tight;
    }

    .brut-chip-taken {
        @apply bg-taken text-white;
    }

    .brut-chip-tentative {
        @apply border-dashed bg-tentative/40 text-ink;
    }

    .brut-chip-selected {
        @apply bg-ink text-white;
    }
}
```

Warna `taken` dan `tentative` sengaja berkontras tinggi, bukan pastel, agar
pembedanya tetap terbaca pada layar ponsel di bawah sinar matahari.

- [ ] **Step 4: Tata layout**

Ganti bagian `<body>` pada `resources/views/layouts/public.blade.php`:

```blade
<body class="min-h-screen">
    <header class="border-b-[3px] border-ink bg-brand">
        <div class="mx-auto max-w-5xl px-4 py-6">
            <h1 class="text-3xl font-black uppercase tracking-tight sm:text-4xl">Roemah Umara</h1>
            <p class="mt-1 text-sm font-bold uppercase tracking-wide">Jadwal ketersediaan tempat</p>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-5xl px-4 pb-10">
        <p class="brut-box p-4 text-sm font-bold">
            Halaman ini hanya menampilkan ketersediaan. Untuk memesan, hubungi kami langsung.
        </p>
    </footer>
</body>
```

- [ ] **Step 5: Tata halaman kalender**

Ganti seluruh isi `resources/views/public/calendar.blade.php`:

```blade
@extends('layouts.public')

@section('title', 'Jadwal '.$monthLabel.' — Roemah Umara')

@section('content')
    <section class="mb-5 flex flex-wrap items-center gap-3">
        <h2 class="text-2xl font-black uppercase">{{ $monthLabel }}</h2>

        <a href="{{ route('public.calendar', ['bulan' => $previousMonth]) }}" rel="nofollow" class="brut-btn">‹ Sebelumnya</a>
        <a href="{{ route('public.calendar', ['bulan' => $nextMonth]) }}" rel="nofollow" class="brut-btn">Berikutnya ›</a>

        <p class="ms-auto text-sm font-bold uppercase">{{ $total }} jadwal</p>
    </section>

    <div class="brut-box overflow-x-auto">
        <table class="w-full table-fixed border-collapse">
            <thead>
                <tr>
                    @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $name)
                        <th scope="col" class="border-[2px] border-ink bg-ink py-2 text-[11px] font-black uppercase tracking-widest text-white">
                            {{ $name }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach (array_chunk($cells, 7) as $week)
                    <tr>
                        @foreach ($week as $cell)
                            <td class="h-24 border-[2px] border-ink p-1 align-top {{ $cell['day'] === null ? 'bg-black/5' : '' }}">
                                @if ($cell['day'] !== null)
                                    <span class="mb-1 block text-xs font-black">{{ $cell['day'] }}</span>

                                    @foreach (($byDate[$cell['iso']] ?? collect()) as $r)
                                        <a
                                            href="{{ route('public.calendar', ['bulan' => $month, 'pilih' => $r->id]) }}"
                                            @class([
                                                'brut-chip',
                                                'brut-chip-taken' => $r->status?->value === 'confirmed',
                                                'brut-chip-tentative' => $r->status?->value !== 'confirmed',
                                                'brut-chip-selected' => $r->id === $selectedId,
                                            ])
                                        >
                                            {{ substr($r->start_time, 0, 5) }}
                                            @if ($r->area)
                                                <span class="block font-normal">{{ $r->area->name }}</span>
                                            @endif
                                        </a>
                                    @endforeach
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <section class="mt-5 flex flex-wrap gap-4 text-xs font-bold uppercase">
        <span class="flex items-center gap-2">
            <span class="inline-block h-4 w-6 border-[2px] border-ink bg-taken"></span> Terisi
        </span>
        <span class="flex items-center gap-2">
            <span class="inline-block h-4 w-6 border-[2px] border-dashed border-ink bg-tentative/40"></span> Sedang dijajaki
        </span>
    </section>

    @if ($selected)
        <section class="brut-box mt-5 p-5">
            <h3 class="text-xl font-black uppercase">
                {{ $selected->reservation_date->translatedFormat('l, d F Y') }}
            </h3>

            <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Jam</dt>
                    <dd class="text-sm font-bold">
                        @if (blank($selected->end_time))
                            {{ substr($selected->start_time, 0, 5) }} (jam tunggal)
                        @else
                            {{ substr($selected->start_time, 0, 5) }}–{{ substr($selected->end_time, 0, 5) }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Area</dt>
                    <dd class="text-sm font-bold">{{ $selected->area?->name ?? 'Belum ditentukan' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Jenis acara</dt>
                    <dd class="text-sm font-bold">{{ $selected->eventType?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[10px] font-black uppercase tracking-widest">Status</dt>
                    <dd class="text-sm font-bold">{{ $selected->status?->publicLabel() ?? 'Sedang dijajaki' }}</dd>
                </div>
            </dl>
        </section>
    @endif
@endsection
```

- [ ] **Step 6: Bangun aset dan pastikan tidak ada peringatan**

```bash
npm run build
```

Expected: build selesai tanpa error, dan `public/build/assets/app-*.css` berukuran
jauh lebih besar daripada 9 kB sebelumnya karena kini benar-benar memuat kelas
Tailwind yang dipakai.

- [ ] **Step 7: Jalankan seluruh test**

Run: `php artisan test`
Expected: seluruhnya PASS. `PublicCalendarTest` **tidak boleh disentuh** — kalau ada
yang gagal setelah penataan gaya, artinya penataan itu mengubah data yang keluar,
dan itu yang harus diperbaiki.

- [ ] **Step 8: Perbarui CLAUDE.md**

Di `CLAUDE.md`, pada bagian **Dokumen**, tambahkan dua baris di bawah daftar yang ada:

```markdown
- Spec v2: `claude/2026-08-20-kalender-publik-dan-nomor-reservasi-design.md`
- Rencana v2: `claude/2026-08-20-kalender-publik-dan-nomor-reservasi-plan.md` (Task 19–24)
```

Pada bagian **Aturan yang tidak boleh dilanggar**, tambahkan dua aturan baru:

```markdown
9. **Halaman publik hanya boleh memuat lima kolom reservasi:** tanggal, jam, area,
   jenis acara, status. Batasnya ditegakkan lewat `select()` eksplisit di
   `PublicCalendarController`, bukan dengan tidak menulisnya di Blade. Dilarang
   menambahkan `guest_name`, `company`, `phone`, `email`, `remark`, `pax`, atau
   `pic_id` ke `select()` itu.

10. **`NumberSequence::next()` wajib dipanggil di dalam transaksi.** Di luar
    transaksi, `FOR UPDATE` tidak menahan apa pun. Nomor reservasi ditetapkan sekali
    saat pembuatan dan tidak pernah berubah.
```

- [ ] **Step 9: Periksa tampilan sendiri**

```bash
php artisan migrate:fresh --seed
php artisan serve
```

Buka `http://localhost:8000/`. Periksa:

1. Halaman terbuka tanpa login, dengan kepala kuning dan garis hitam tebal.
2. Grid tujuh kolom, kolom pertama hari Senin — cocokkan tanggal 1 bulan berjalan
   dengan kalender ponsel.
3. Bulan kosong tetap tampil rapi, tanpa error.
4. Tekan Sebelumnya dan Berikutnya — bulannya berpindah dan alamatnya berubah.
5. Buat satu reservasi CONFIRMED dan satu TENTATIVE lewat `/cms`, lalu muat ulang
   halaman publik — keduanya tampil dengan gaya berbeda sesuai keterangan warna.
6. Klik salah satu chip — panel detail muncul di bawah, dan alamatnya bisa disalin
   lalu dibuka di jendela lain dengan hasil sama.
7. Buka halaman lewat ponsel atau jendela sempit — tabel bisa digeser mendatar dan
   tidak merusak tata letak.
8. Buka `view-source:` halaman itu, cari nama tamu — tidak boleh ditemukan.

- [ ] **Step 10: Commit**

```bash
git add package.json package-lock.json tailwind.config.js resources CLAUDE.md
git commit -m "feat: gaya neo-brutalism untuk halaman publik"
```

---

## Ringkasan urutan pengerjaan

| Task | Deliverable | Bisa diuji otomatis |
|---|---|---|
| 19 | Tabel penghitung dan `NumberSequence` | Ya |
| 20 | Kolom nomor dan alokasinya di writer | Ya |
| 21 | Nomor tampil di CMS | Ya |
| 22 | `MonthGrid` dipakai bersama | Ya |
| 23 | Kalender publik yang berfungsi dan aman | Ya |
| 24 | Gaya neo-brutalism | Sebagian, sisanya manual |

Task 19, 20, dan 23 memegang hal yang paling berisiko: Task 19 dan 20 menjaga nomor
tidak pernah kembar maupun bolong, Task 23 menjaga data pribadi tamu tidak keluar.
Ketiganya bertest penuh.

Task 24 satu-satunya yang menyisakan pemeriksaan manual, dan memang seharusnya —
yang tersisa di sana murni soal enak dilihat atau tidak.

## Yang sengaja tidak dikerjakan

Sesuai spec bagian 9: tidak ada form reservasi publik, tidak ada pencarian status oleh
tamu memakai nomor `RU-R`, tidak ada SEO atau meta tag berbagi, tidak ada cache
halaman, tidak ada penomoran yang di-reset per tahun, dan tampilan panel `/cms` tidak
diubah sama sekali.
