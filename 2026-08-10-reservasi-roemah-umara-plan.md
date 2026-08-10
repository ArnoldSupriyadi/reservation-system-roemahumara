# Sistem Reservasi Roemah Umara — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun sistem reservasi internal Roemah Umara yang menggantikan spreadsheet, dengan data ternormalisasi, jejak audit per perubahan, dan pencegahan duplikat di level database.

**Architecture:** Laravel 12 monolith dengan Inertia 2 + React 18 sebagai lapisan tampilan. Seluruh data satu bulan dikirim sekaligus sebagai props Inertia, lalu difilter dan disortir di klien — tidak ada endpoint API terpisah, tidak ada pagination. Integritas data dijaga oleh constraint database (UNIQUE pada generated column, optimistic lock lewat kolom `version`), bukan oleh pengecekan di kode aplikasi.

**Tech Stack:** PHP 8.2, Laravel 12, Inertia.js 2, React 18, Tailwind 3, Vite 7, MySQL 8, PHPUnit 11, `spatie/laravel-activitylog`.

**Spec:** `docs/superpowers/specs/2026-08-10-reservasi-roemah-umara-design.md`

## Global Constraints

- **MySQL 8 wajib**, termasuk untuk menjalankan test. `dedupe_key` memakai generated stored column dengan `IF()`, `CONCAT_WS()`, `DATE_FORMAT()`, `TIME_FORMAT()` — tidak satu pun tersedia di SQLite. Menjalankan test di SQLite akan melewati constraint terpenting dalam sistem ini tanpa memberi tanda apa pun.
- **Tidak ada migrasi data lama.** Database dimulai kosong. Tidak ada command import.
- **Tidak ada Filament.** Seluruh UI memakai Inertia + React.
- **Tidak ada pustaka kalender.** Grid bulanan memakai CSS Grid.
- **Tidak ada pagination, caching, queue.** Volume ± 15 reservasi per bulan.
- **Remark selalu ditampilkan penuh.** Dilarang memotong teks, menyembunyikan di balik hover, atau di balik tombol. Satu-satunya pengecualian adalah chip kalender, yang menggantinya dengan panel detail.
- **Update model wajib lewat `$model->save()`**, tidak boleh `Model::where(...)->update(...)`. Update massal tidak memicu event Eloquent, sehingga `activity_log` tidak tercatat dan audit trail bolong tanpa error.
- **Optimistic lock memakai kolom `version` (integer)**, bukan `updated_at`. TIMESTAMP MySQL berpresisi detik.
- **Durasi asumsi reservasi tanpa `end_time` adalah 2 jam**, dibaca dari `config('reservation.default_duration_minutes')`.
- **Kunci duplikat:** `reservation_date` + `LOWER(TRIM(guest_name))` + `start_time`.
- Nama tabel, kolom, dan route memakai bahasa Inggris. Label yang terlihat pengguna memakai bahasa Indonesia.

## File Structure

**Konfigurasi & fondasi**

| File | Tanggung jawab |
|---|---|
| `config/reservation.php` | Durasi asumsi, daftar status |
| `phpunit.xml` | Diubah: koneksi test ke MySQL |
| `app/Enums/UserRole.php` | `admin` / `staff` |
| `app/Enums/ReservationStatus.php` | `tentative` / `confirmed` |

**Data layer**

| File | Tanggung jawab |
|---|---|
| `database/migrations/*_add_role_and_is_active_to_users_table.php` | Kolom `role`, `is_active` |
| `database/migrations/*_create_master_tables.php` | `areas`, `event_types`, `menu_styles` |
| `database/migrations/*_create_reservations_table.php` | Tabel utama |
| `database/migrations/*_add_dedupe_key_to_reservations_table.php` | Generated column + UNIQUE |
| `app/Models/User.php` | Diubah: cast role, scope aktif, `isAdmin()` |
| `app/Models/{Area,EventType,MenuStyle}.php` | Master, struktur identik |
| `app/Models/Reservation.php` | Model utama + `LogsActivity` |
| `database/factories/ReservationFactory.php` | Data test |
| `database/seeders/MasterSeeder.php` | Isi awal master |
| `database/seeders/UserSeeder.php` | Akun staf |

**Domain logic — dipisah dari controller agar bisa diuji tanpa HTTP**

| File | Tanggung jawab |
|---|---|
| `app/Support/TimeInput.php` | Parsing `11`, `11.00`, `11:00`, `12.00-15.00` |
| `app/Services/ConflictChecker.php` | Deteksi tumpang tindih area |
| `app/Services/ReservationWriter.php` | Transaksi, optimistic lock, idempotency |

**HTTP layer**

| File | Tanggung jawab |
|---|---|
| `app/Http/Requests/StoreReservationRequest.php` | Validasi + normalisasi input baru |
| `app/Http/Requests/UpdateReservationRequest.php` | Sama, ditambah `version` |
| `app/Http/Controllers/ReservationController.php` | CRUD reservasi |
| `app/Http/Controllers/Master/*Controller.php` | Tiga master CRUD |
| `app/Http/Controllers/UserController.php` | CRUD pengguna |
| `app/Policies/ReservationPolicy.php` | Hak akses per aksi |
| `app/Http/Middleware/HandleInertiaRequests.php` | Diubah: bagikan `auth.user.role` |
| `routes/web.php` | Diubah: seluruh route |

**Frontend**

| File | Tanggung jawab |
|---|---|
| `resources/js/Utils/formatTimeRange.js` | Satu-satunya sumber format jam |
| `resources/js/Pages/Reservations/Index.jsx` | Layout, state filter, toggle mode |
| `resources/js/Pages/Reservations/Form.jsx` | Dipakai Create dan Edit |
| `resources/js/Pages/Reservations/Show.jsx` | Detail + timeline audit |
| `resources/js/Components/ReservationTable.jsx` | Mode tabel |
| `resources/js/Components/RemarkRow.jsx` | Baris remark selebar tabel |
| `resources/js/Components/MonthGrid.jsx` | Mode kalender |
| `resources/js/Components/ReservationChip.jsx` | Chip di sel tanggal |
| `resources/js/Components/ReservationDetailPanel.jsx` | Detail di bawah kalender |
| `resources/js/Components/FilterBar.jsx` | Pencarian dan filter |
| `resources/js/Components/PicCombobox.jsx` | Dropdown PIC dengan pencarian |
| `resources/js/Components/StatusBadge.jsx` | Badge status |
| `resources/js/Components/TimeRangeField.jsx` | Jam mulai + checkbox "sampai jam" |
| `resources/js/Pages/Master/SimpleMasterPage.jsx` | Dipakai bersama tiga master |

**Alasan pemisahan `TimeInput`, `ConflictChecker`, dan `ReservationWriter` dari controller:** ketiganya berisi logika yang paling mudah salah dan paling perlu diuji berulang. Menaruhnya di controller memaksa setiap test melewati lapisan HTTP, yang membuat test lambat dan pesan kegagalannya kabur.

---

## Task 1: Fondasi — config, enum, dan test berjalan di MySQL

**Files:**
- Create: `config/reservation.php`
- Create: `app/Enums/UserRole.php`
- Create: `app/Enums/ReservationStatus.php`
- Modify: `phpunit.xml`
- Modify: `.env.example`
- Test: `tests/Unit/ConfigTest.php`

**Interfaces:**
- Consumes: tidak ada, ini task pertama
- Produces: `UserRole::Admin`, `UserRole::Staff`, `ReservationStatus::Tentative`, `ReservationStatus::Confirmed` (keduanya backed enum bertipe string); `config('reservation.default_duration_minutes')` mengembalikan `int`

- [ ] **Step 1: Buat database test di MySQL**

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS roemah_umara_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "SELECT VERSION();"
```

Expected: versi MySQL 8.0 atau lebih tinggi. Jika di bawah 5.7, **hentikan** dan laporkan — seluruh rencana `dedupe_key` bergantung pada generated column.

- [ ] **Step 2: Arahkan test ke MySQL**

Di `phpunit.xml`, ganti dua baris `DB_CONNECTION` dan `DB_DATABASE` yang ada di dalam `<php>`:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="roemah_umara_test"/>
<env name="DB_HOST" value="127.0.0.1"/>
<env name="DB_PORT" value="3306"/>
```

Jika ada baris `<env name="DB_DATABASE" value=":memory:"/>`, hapus.

- [ ] **Step 3: Buat file config**

`config/reservation.php`:

```php
<?php

return [

    /*
     * Durasi yang diasumsikan untuk reservasi yang tidak punya end_time,
     * dipakai HANYA untuk mendeteksi tumpang tindih area.
     * Nilai ini tidak pernah disimpan ke database.
     */
    'default_duration_minutes' => (int) env('RESERVATION_DEFAULT_DURATION', 120),

];
```

- [ ] **Step 4: Tambahkan variabel ke `.env.example`**

Tambahkan di bagian bawah `.env.example`:

```
RESERVATION_DEFAULT_DURATION=120
```

- [ ] **Step 5: Buat enum**

`app/Enums/UserRole.php`:

```php
<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Staff => 'Staf',
        };
    }
}
```

`app/Enums/ReservationStatus.php`:

```php
<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Tentative => 'TENTATIVE',
            self::Confirmed => 'CONFIRMED',
        };
    }
}
```

- [ ] **Step 6: Tulis test yang gagal**

`tests/Unit/ConfigTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_default_duration_is_two_hours(): void
    {
        $this->assertSame(120, config('reservation.default_duration_minutes'));
    }

    public function test_user_roles_are_admin_and_staff(): void
    {
        $this->assertSame('admin', UserRole::Admin->value);
        $this->assertSame('staff', UserRole::Staff->value);
    }

    public function test_reservation_statuses_render_uppercase_labels(): void
    {
        $this->assertSame('CONFIRMED', ReservationStatus::Confirmed->label());
        $this->assertSame('TENTATIVE', ReservationStatus::Tentative->label());
    }

    public function test_database_connection_is_mysql(): void
    {
        $this->assertSame('mysql', config('database.default'));
    }
}
```

- [ ] **Step 7: Jalankan test**

Run: `php artisan test --filter=ConfigTest`
Expected: 4 test PASS. Jika `test_database_connection_is_mysql` gagal, `phpunit.xml` belum benar — perbaiki sebelum lanjut, karena semua task berikutnya bergantung padanya.

- [ ] **Step 8: Commit**

```bash
git add config/reservation.php app/Enums .env.example phpunit.xml tests/Unit/ConfigTest.php
git commit -m "feat: config, enum, dan test berjalan di MySQL"
```

---

## Task 2: Kolom role dan is_active pada users

**Files:**
- Create: `database/migrations/2026_08_10_000001_add_role_and_is_active_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Test: `tests/Feature/UserRoleTest.php`

**Interfaces:**
- Consumes: `UserRole` dari Task 1
- Produces: `User::isAdmin(): bool`; `User::$role` bertipe `UserRole`; `User::$is_active` bertipe `bool`; scope `User::query()->active()`; state factory `UserFactory::new()->admin()` dan `->inactive()`

- [ ] **Step 1: Buat migration**

```bash
php artisan make:migration add_role_and_is_active_to_users_table
```

Ganti isinya:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('staff')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
```

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/UserRoleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_defaults_to_staff_and_active(): void
    {
        $user = User::factory()->create();

        $this->assertSame(UserRole::Staff, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->isAdmin());
    }

    public function test_admin_is_detected(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->isAdmin());
    }

    public function test_active_scope_excludes_inactive_users(): void
    {
        User::factory()->create(['name' => 'Ira']);
        User::factory()->inactive()->create(['name' => 'Mantan Staf']);

        $names = User::query()->active()->pluck('name')->all();

        $this->assertSame(['Ira'], $names);
    }
}
```

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=UserRoleTest`
Expected: FAIL dengan "Call to undefined method App\Models\User::isAdmin()"

- [ ] **Step 4: Perbarui model User**

Di `app/Models/User.php`, tambahkan import dan tiga anggota berikut. Jangan hapus isi yang sudah ada dari Breeze.

```php
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
```

Tambahkan `'role'` dan `'is_active'` ke array `$fillable` yang sudah ada.

Di dalam method `casts()` yang sudah ada, tambahkan dua baris:

```php
'role' => UserRole::class,
'is_active' => 'boolean',
```

Tambahkan dua method di akhir class:

```php
public function isAdmin(): bool
{
    return $this->role === UserRole::Admin;
}

public function scopeActive(Builder $query): void
{
    $query->where('is_active', true);
}
```

- [ ] **Step 5: Tambahkan state ke factory**

Di `database/factories/UserFactory.php`, tambahkan dua method di akhir class:

```php
public function admin(): static
{
    return $this->state(fn () => ['role' => 'admin']);
}

public function inactive(): static
{
    return $this->state(fn () => ['is_active' => false]);
}
```

- [ ] **Step 6: Jalankan test**

Run: `php artisan test --filter=UserRoleTest`
Expected: 3 test PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/User.php database/factories/UserFactory.php tests/Feature/UserRoleTest.php
git commit -m "feat: tambahkan role dan is_active pada users"
```

---

## Task 3: Tabel master — areas, event types, menu styles

**Files:**
- Create: `database/migrations/2026_08_10_000002_create_master_tables.php`
- Create: `app/Models/Area.php`
- Create: `app/Models/EventType.php`
- Create: `app/Models/MenuStyle.php`
- Create: `database/seeders/MasterSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/MasterSeederTest.php`

**Interfaces:**
- Consumes: tidak ada
- Produces: model `Area`, `EventType`, `MenuStyle`, masing-masing dengan `$fillable = ['name', 'sort_order', 'is_active']`, cast `is_active` ke `bool`, dan scope `active()`. `MasterSeeder` mengisi 7 area, 6 event type, 2 menu style.

- [ ] **Step 1: Buat migration**

```bash
php artisan make:migration create_master_tables
```

Ganti isinya:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['areas', 'event_types', 'menu_styles'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name', 80)->unique();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_styles');
        Schema::dropIfExists('event_types');
        Schema::dropIfExists('areas');
    }
};
```

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/MasterSeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_fills_all_three_masters(): void
    {
        $this->seed(MasterSeeder::class);

        $this->assertSame(7, Area::count());
        $this->assertSame(6, EventType::count());
        $this->assertSame(2, MenuStyle::count());
    }

    public function test_areas_are_ordered_as_in_the_spreadsheet(): void
    {
        $this->seed(MasterSeeder::class);

        $this->assertSame(
            ['VIP 1', 'VIP 2', 'FOYER FnB', 'KORIDOR', 'SOFA REGULAR', 'REGULAR', 'OUTDOOR'],
            Area::orderBy('sort_order')->pluck('name')->all()
        );
    }

    public function test_active_scope_filters_inactive_rows(): void
    {
        Area::create(['name' => 'VIP 3', 'sort_order' => 1, 'is_active' => true]);
        Area::create(['name' => 'GUDANG', 'sort_order' => 2, 'is_active' => false]);

        $this->assertSame(['VIP 3'], Area::query()->active()->pluck('name')->all());
    }
}
```

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=MasterSeederTest`
Expected: FAIL dengan "Class App\Models\Area not found"

- [ ] **Step 4: Buat tiga model**

`app/Models/Area.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
```

`app/Models/EventType.php` — isi identik, hanya nama class yang berbeda:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
```

`app/Models/MenuStyle.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MenuStyle extends Model
{
    protected $fillable = ['name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
```

- [ ] **Step 5: Buat seeder**

`database/seeders/MasterSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
use Illuminate\Database\Seeder;

class MasterSeeder extends Seeder
{
    public function run(): void
    {
        $areas = ['VIP 1', 'VIP 2', 'FOYER FnB', 'KORIDOR', 'SOFA REGULAR', 'REGULAR', 'OUTDOOR'];
        $eventTypes = ['TEST FOOD', 'PRIVATE', 'MEETING', 'LUNCH', 'DINNER', 'GATHERING'];
        $menuStyles = ['BUFFET', 'AL CARTE'];

        foreach ($areas as $i => $name) {
            Area::firstOrCreate(['name' => $name], ['sort_order' => $i + 1]);
        }

        foreach ($eventTypes as $i => $name) {
            EventType::firstOrCreate(['name' => $name], ['sort_order' => $i + 1]);
        }

        foreach ($menuStyles as $i => $name) {
            MenuStyle::firstOrCreate(['name' => $name], ['sort_order' => $i + 1]);
        }
    }
}
```

`firstOrCreate` dipakai agar seeder aman dijalankan berulang tanpa melanggar UNIQUE pada `name`.

- [ ] **Step 6: Daftarkan di DatabaseSeeder**

Di `database/seeders/DatabaseSeeder.php`, di dalam method `run()`, ganti seluruh isinya dengan:

```php
$this->call([
    MasterSeeder::class,
]);
```

- [ ] **Step 7: Jalankan test**

Run: `php artisan test --filter=MasterSeederTest`
Expected: 3 test PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Models database/seeders tests/Feature/MasterSeederTest.php
git commit -m "feat: tabel master areas, event types, menu styles"
```

---

## Task 4: Tabel reservations dan model

**Files:**
- Create: `database/migrations/2026_08_10_000003_create_reservations_table.php`
- Create: `app/Models/Reservation.php`
- Create: `database/factories/ReservationFactory.php`
- Test: `tests/Feature/ReservationModelTest.php`

**Interfaces:**
- Consumes: `ReservationStatus` (Task 1), `User` (Task 2), `Area`, `EventType`, `MenuStyle` (Task 3)
- Produces: model `Reservation` dengan relasi `pic()`, `area()`, `eventType()`, `menuStyle()`, `createdBy()`, `updatedBy()`; cast `reservation_date` ke `date`, `status` ke `ReservationStatus`, `version` ke `integer`; `ReservationFactory` dengan default valid dan state `->confirmed()`, `->withRange()`

- [ ] **Step 1: Buat migration**

```bash
php artisan make:migration create_reservations_table
```

Ganti isinya:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            $table->date('reservation_date');
            $table->string('guest_name', 150);
            $table->string('company', 150)->nullable();
            $table->string('phone', 30);
            $table->string('email', 150)->nullable();

            $table->foreignId('pic_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_type_id')->nullable()->constrained('event_types')->restrictOnDelete();
            $table->foreignId('menu_style_id')->nullable()->constrained('menu_styles')->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->restrictOnDelete();

            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->unsignedInteger('pax');
            $table->enum('status', ['tentative', 'confirmed'])->nullable();
            $table->text('remark')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->char('idempotency_key', 36)->nullable()->unique();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('reservation_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
```

`pic_id` sudah otomatis terindeks oleh foreign key constraint, jadi tidak perlu `$table->index('pic_id')` terpisah.

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/ReservationModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_reservation(): void
    {
        $r = Reservation::factory()->create();

        $this->assertNotNull($r->reservation_date);
        $this->assertNotNull($r->start_time);
        $this->assertNull($r->end_time);
        $this->assertNull($r->status);
        $this->assertSame(1, $r->version);
    }

    public function test_status_is_cast_to_enum(): void
    {
        $r = Reservation::factory()->confirmed()->create();

        $this->assertSame(ReservationStatus::Confirmed, $r->status);
    }

    public function test_range_state_sets_end_time(): void
    {
        $r = Reservation::factory()->withRange()->create();

        $this->assertSame('15:00:00', $r->end_time);
    }

    public function test_relations_resolve(): void
    {
        $pic = User::factory()->create(['name' => 'IRA']);
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        $r = Reservation::factory()->create([
            'pic_id' => $pic->id,
            'area_id' => $area->id,
        ]);

        $this->assertSame('IRA', $r->pic->name);
        $this->assertSame('VIP 1', $r->area->name);
    }

    public function test_soft_delete_hides_row_from_default_query(): void
    {
        $r = Reservation::factory()->create();
        $r->delete();

        $this->assertSame(0, Reservation::count());
        $this->assertSame(1, Reservation::withTrashed()->count());
    }
}
```

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationModelTest`
Expected: FAIL dengan "Class App\Models\Reservation not found"

- [ ] **Step 4: Buat model**

`app/Models/Reservation.php`:

```php
<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'reservation_date',
        'guest_name',
        'company',
        'phone',
        'email',
        'pic_id',
        'event_type_id',
        'menu_style_id',
        'area_id',
        'start_time',
        'end_time',
        'pax',
        'status',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'status' => ReservationStatus::class,
            'pax' => 'integer',
            'version' => 'integer',
        ];
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function menuStyle(): BelongsTo
    {
        return $this->belongsTo(MenuStyle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
```

`version`, `idempotency_key`, `created_by`, dan `updated_by` sengaja **tidak** masuk `$fillable`. Keempatnya diisi oleh `ReservationWriter` (Task 8), bukan oleh input pengguna.

- [ ] **Step 5: Buat factory**

`database/factories/ReservationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reservation_date' => '2026-08-08',
            'guest_name' => $this->faker->name(),
            'company' => null,
            'phone' => '08123456789',
            'email' => null,
            'pic_id' => User::factory(),
            'event_type_id' => null,
            'menu_style_id' => null,
            'area_id' => null,
            'start_time' => '12:00:00',
            'end_time' => null,
            'pax' => 4,
            'status' => null,
            'remark' => null,
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => 'confirmed']);
    }

    public function withRange(): static
    {
        return $this->state(fn () => ['start_time' => '12:00:00', 'end_time' => '15:00:00']);
    }
}
```

Factory boleh mengisi `version` dan `created_by` meskipun tidak `$fillable`, karena factory memakai `forceCreate` secara internal.

- [ ] **Step 6: Jalankan test**

Run: `php artisan test --filter=ReservationModelTest`
Expected: 5 test PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/Reservation.php database/factories/ReservationFactory.php tests/Feature/ReservationModelTest.php
git commit -m "feat: tabel dan model reservations"
```

---

## Task 5: Generated column dedupe_key dan constraint duplikat

**Files:**
- Create: `database/migrations/2026_08_10_000004_add_dedupe_key_to_reservations_table.php`
- Test: `tests/Feature/DuplicateConstraintTest.php`

**Interfaces:**
- Consumes: tabel `reservations` (Task 4)
- Produces: kolom `dedupe_key` dan index `uniq_reservations_dedupe`. Pelanggarannya melempar `Illuminate\Database\QueryException` dengan `errorInfo[1] === 1062` dan pesan yang memuat `uniq_reservations_dedupe`.

Constraint dipasang pada generated column, bukan langsung pada tiga kolom, karena dua alasan yang keduanya diuji di task ini: baris ter-soft-delete tidak boleh menghalangi input ulang, dan perbedaan huruf besar-kecil tidak boleh meloloskan duplikat.

- [ ] **Step 1: Buat migration**

```bash
php artisan make:migration add_dedupe_key_to_reservations_table
```

Ganti isinya:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE reservations
            ADD COLUMN dedupe_key VARCHAR(191)
            GENERATED ALWAYS AS (
                IF(deleted_at IS NULL,
                   CONCAT_WS('|',
                       DATE_FORMAT(reservation_date, '%Y-%m-%d'),
                       LOWER(TRIM(guest_name)),
                       TIME_FORMAT(start_time, '%H:%i')
                   ),
                   NULL)
            ) STORED
        ");

        DB::statement('
            CREATE UNIQUE INDEX uniq_reservations_dedupe
            ON reservations (dedupe_key)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX uniq_reservations_dedupe ON reservations');
        DB::statement('ALTER TABLE reservations DROP COLUMN dedupe_key');
    }
};
```

`DATE_FORMAT` dan `TIME_FORMAT` dipakai secara eksplisit, bukan mengandalkan konversi implisit DATE dan TIME ke string, agar nilai yang dihasilkan pasti deterministik dan tidak bergantung pada pengaturan sesi.

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/DuplicateConstraintTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_date_name_and_start_time_is_rejected(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        $this->expectException(QueryException::class);

        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);
    }

    public function test_casing_and_trailing_space_do_not_bypass_the_constraint(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        $this->expectException(QueryException::class);

        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'bapak wanda ',
            'start_time' => '12:00:00',
        ]);
    }

    public function test_different_start_time_is_allowed(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '18:00:00',
        ]);

        $this->assertSame(2, Reservation::count());
    }

    public function test_soft_deleted_row_does_not_block_reinsert(): void
    {
        $first = Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        $first->delete();

        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        $this->assertSame(1, Reservation::count());
        $this->assertSame(2, Reservation::withTrashed()->count());
    }

    public function test_violation_names_the_dedupe_index(): void
    {
        Reservation::factory()->create([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'start_time' => '12:00:00',
        ]);

        try {
            Reservation::factory()->create([
                'reservation_date' => '2026-08-07',
                'guest_name' => 'Bapak Wanda',
                'start_time' => '12:00:00',
            ]);
            $this->fail('Duplikat seharusnya ditolak.');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
            $this->assertStringContainsString('uniq_reservations_dedupe', $e->getMessage());
        }
    }
}
```

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=DuplicateConstraintTest`
Expected: FAIL — duplikat masih tersimpan karena constraint belum ada

- [ ] **Step 4: Jalankan migration**

Run: `php artisan migrate`
Expected: migration `add_dedupe_key_to_reservations_table` berhasil.

Jika muncul error "This version of MySQL doesn't yet support generated columns", server memakai MySQL di bawah 5.7 — **hentikan** dan laporkan sebelum melanjutkan.

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=DuplicateConstraintTest`
Expected: 5 test PASS

- [ ] **Step 6: Periksa hasil generated column secara langsung**

```bash
php artisan tinker --execute="
\App\Models\Reservation::factory()->create(['reservation_date' => '2026-08-09', 'guest_name' => '  Dharmadi  ', 'start_time' => '12:00:00']);
echo \DB::table('reservations')->latest('id')->value('dedupe_key');
"
```

Expected: `2026-08-09|dharmadi|12:00`

- [ ] **Step 7: Commit**

```bash
git add database/migrations tests/Feature/DuplicateConstraintTest.php
git commit -m "feat: constraint duplikat lewat generated column dedupe_key"
```

---

## Task 6: Parser input jam

**Files:**
- Create: `app/Support/TimeInput.php`
- Test: `tests/Unit/TimeInputTest.php`

**Interfaces:**
- Consumes: tidak ada. Kelas ini murni, tanpa database dan tanpa framework.
- Produces:
  - `TimeInput::normalize(?string $value): ?string` — mengembalikan `H:i` atau `null` jika tidak bisa diparse
  - `TimeInput::split(?string $value): array` — mengembalikan `['start' => ?string, 'end' => ?string]`, keduanya `H:i` atau `null`

Kelas ini menangani kebiasaan penulisan dari spreadsheet: `11`, `11.00`, `11:00`, `12:00:00`, dan rentang `12.00-15.00` yang diketik sekaligus pada satu kolom.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Unit/TimeInputTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\TimeInput;
use PHPUnit\Framework\TestCase;

class TimeInputTest extends TestCase
{
    public static function normalizeCases(): array
    {
        return [
            'jam saja' => ['11', '11:00'],
            'titik' => ['11.00', '11:00'],
            'titik dua' => ['11:00', '11:00'],
            'dengan detik' => ['12:00:00', '12:00'],
            'menit bukan nol' => ['11.30', '11:30'],
            'satu digit jam' => ['9.00', '09:00'],
            'spasi di ujung' => ['  14.00  ', '14:00'],
            'tengah malam' => ['0.00', '00:00'],
            'jam terakhir' => ['23.59', '23:59'],
        ];
    }

    /** @dataProvider normalizeCases */
    public function test_normalize_accepts_common_formats(string $input, string $expected): void
    {
        $this->assertSame($expected, TimeInput::normalize($input));
    }

    public static function invalidCases(): array
    {
        return [
            'kosong' => [''],
            'null' => [null],
            'hanya spasi' => ['   '],
            'jam di luar rentang' => ['25.00'],
            'menit di luar rentang' => ['11.75'],
            'huruf' => ['siang'],
            'strip saja' => ['-'],
        ];
    }

    /** @dataProvider invalidCases */
    public function test_normalize_returns_null_for_invalid_input(?string $input): void
    {
        $this->assertNull(TimeInput::normalize($input));
    }

    public function test_split_returns_start_only_for_single_time(): void
    {
        $this->assertSame(['start' => '11:00', 'end' => null], TimeInput::split('11.00'));
    }

    public function test_split_separates_a_range(): void
    {
        $this->assertSame(['start' => '12:00', 'end' => '15:00'], TimeInput::split('12.00-15.00'));
    }

    public function test_split_tolerates_spaces_around_the_dash(): void
    {
        $this->assertSame(['start' => '12:00', 'end' => '15:00'], TimeInput::split('12.00 - 15.00'));
    }

    public function test_split_handles_en_dash(): void
    {
        $this->assertSame(['start' => '12:00', 'end' => '15:00'], TimeInput::split('12.00 – 15.00'));
    }

    public function test_split_returns_nulls_for_unparseable_input(): void
    {
        $this->assertSame(['start' => null, 'end' => null], TimeInput::split('NA'));
    }

    public function test_split_ignores_a_third_segment(): void
    {
        $this->assertSame(['start' => '12:00', 'end' => '15:00'], TimeInput::split('12.00-15.00-18.00'));
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=TimeInputTest`
Expected: FAIL dengan "Class App\Support\TimeInput not found"

- [ ] **Step 3: Implementasikan parser**

`app/Support/TimeInput.php`:

```php
<?php

namespace App\Support;

class TimeInput
{
    /**
     * Ubah berbagai gaya penulisan jam menjadi format H:i.
     * Mengembalikan null jika input tidak bisa diparse.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // 11 | 11.00 | 11:00 | 11:00:00 | 1130
        if (! preg_match('/^(\d{1,2})(?:[.:]?(\d{2}))?(?::\d{2})?$/', $value, $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = isset($m[2]) ? (int) $m[2] : 0;

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Pecah input yang mungkin berupa rentang menjadi start dan end.
     *
     * @return array{start: ?string, end: ?string}
     */
    public static function split(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return ['start' => null, 'end' => null];
        }

        // Terima tanda hubung biasa maupun en dash.
        $parts = preg_split('/\s*[-–]\s*/u', trim($value));

        return [
            'start' => self::normalize($parts[0] ?? null),
            'end' => self::normalize($parts[1] ?? null),
        ];
    }
}
```

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=TimeInputTest`
Expected: 23 test PASS (16 dari data provider, 7 test tunggal)

- [ ] **Step 5: Commit**

```bash
git add app/Support/TimeInput.php tests/Unit/TimeInputTest.php
git commit -m "feat: parser input jam untuk format tunggal dan rentang"
```

---

## Task 7: Jejak audit dengan activity log

**Files:**
- Modify: `composer.json` (lewat composer require)
- Create: `database/migrations/*_create_activity_log_table.php` (dari vendor publish)
- Modify: `app/Models/Reservation.php`
- Test: `tests/Feature/ReservationAuditTest.php`

**Interfaces:**
- Consumes: model `Reservation` (Task 4)
- Produces: `Reservation` mengimplementasikan `Spatie\Activitylog\Contracts\Activity`-compatible logging. Relasi `$reservation->activities` mengembalikan koleksi `Activity` terurut lama ke baru; tiap entri punya `properties['old']` dan `properties['attributes']`, serta `causer` berupa `User`.

- [ ] **Step 1: Pasang paket**

```bash
composer require spatie/laravel-activitylog
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

Expected: tabel `activity_log` terbentuk.

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/ReservationAuditTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_reservation_is_logged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $r = Reservation::factory()->create();

        $this->assertCount(1, $r->activities);
        $this->assertSame('created', $r->activities->first()->event);
        $this->assertTrue($user->is($r->activities->first()->causer));
    }

    public function test_changing_pax_records_old_and_new_value(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $r = Reservation::factory()->create(['pax' => 5]);
        $r->pax = 8;
        $r->save();

        $update = $r->activities()->where('event', 'updated')->first();

        $this->assertSame(5, $update->properties['old']['pax']);
        $this->assertSame(8, $update->properties['attributes']['pax']);
    }

    public function test_saving_without_changes_creates_no_log_entry(): void
    {
        $this->actingAs(User::factory()->create());

        $r = Reservation::factory()->create();
        $before = $r->activities()->count();

        $r->save();

        $this->assertSame($before, $r->activities()->count());
    }

    public function test_bookkeeping_columns_are_not_logged(): void
    {
        $this->actingAs(User::factory()->create());

        $r = Reservation::factory()->create();
        $logged = array_keys($r->activities->first()->properties['attributes']);

        $this->assertNotContains('version', $logged);
        $this->assertNotContains('created_by', $logged);
        $this->assertNotContains('updated_by', $logged);
        $this->assertNotContains('idempotency_key', $logged);
        $this->assertContains('guest_name', $logged);
    }
}
```

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationAuditTest`
Expected: FAIL dengan "Call to undefined method App\Models\Reservation::activities()"

- [ ] **Step 4: Aktifkan logging pada model**

Di `app/Models/Reservation.php`, tambahkan import:

```php
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
```

Tambahkan trait di samping trait yang sudah ada:

```php
use HasFactory;
use LogsActivity;
use SoftDeletes;
```

Tambahkan satu method di akhir class:

```php
public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logOnly([
            'reservation_date',
            'guest_name',
            'company',
            'phone',
            'email',
            'pic_id',
            'event_type_id',
            'menu_style_id',
            'area_id',
            'start_time',
            'end_time',
            'pax',
            'status',
            'remark',
        ])
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs()
        ->useLogName('reservation');
}
```

Daftar `logOnly` sengaja ditulis eksplisit, bukan `logAll()->except()`, agar kolom baru yang ditambahkan di masa depan tidak ikut tercatat tanpa disengaja.

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=ReservationAuditTest`
Expected: 4 test PASS

- [ ] **Step 6: Jalankan seluruh test untuk memastikan tidak ada yang rusak**

Run: `php artisan test`
Expected: semua PASS

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock database/migrations app/Models/Reservation.php tests/Feature/ReservationAuditTest.php
git commit -m "feat: jejak audit perubahan reservasi"
```

---

## Task 8: Validasi dan normalisasi input reservasi

**Files:**
- Create: `app/Http/Requests/StoreReservationRequest.php`
- Test: `tests/Unit/StoreReservationRequestTest.php`

**Interfaces:**
- Consumes: `TimeInput` (Task 6), `ReservationStatus` (Task 1)
- Produces: `StoreReservationRequest` dengan `rules(): array` dan `prepareForValidation(): void`. Setelah validasi, `$request->validated()` berisi kunci: `reservation_date`, `guest_name`, `company`, `phone`, `email`, `pic_id`, `event_type_id`, `menu_style_id`, `area_id`, `start_time`, `end_time`, `pax`, `status`, `remark`. Nilai `phone` hanya digit, `start_time` dan `end_time` berformat `H:i`.

Normalisasi berjalan di `prepareForValidation()` sehingga aturan validasi bekerja pada nilai yang sudah bersih. Ini yang membuat `NA` ditolak oleh `required` alih-alih lolos sebagai string dua huruf.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Unit/StoreReservationRequestTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Http\Requests\StoreReservationRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\TestCase;

class StoreReservationRequestTest extends TestCase
{
    private function prepared(array $input): array
    {
        $request = new StoreReservationRequest();
        $request->merge($input);
        $request->prepareForValidationPublic();

        return $request->all();
    }

    public function test_na_phone_becomes_null(): void
    {
        $this->assertNull($this->prepared(['phone' => 'NA'])['phone']);
    }

    public function test_phone_is_reduced_to_digits(): void
    {
        $this->assertSame('082249803564', $this->prepared(['phone' => '0822-4980-3564'])['phone']);
    }

    public function test_phone_with_spaces_is_reduced_to_digits(): void
    {
        $this->assertSame('081294489888', $this->prepared(['phone' => '0812 9448 9888'])['phone']);
    }

    public function test_na_email_becomes_null(): void
    {
        $this->assertNull($this->prepared(['email' => 'NA'])['email']);
    }

    public function test_email_is_lowercased_and_trimmed(): void
    {
        $this->assertSame('ira@umara.id', $this->prepared(['email' => '  IRA@Umara.ID '])['email']);
    }

    public function test_guest_name_is_trimmed(): void
    {
        $this->assertSame('Bapak Wanda', $this->prepared(['guest_name' => '  Bapak Wanda  '])['guest_name']);
    }

    public function test_single_start_time_is_normalized(): void
    {
        $out = $this->prepared(['start_time' => '11.00']);

        $this->assertSame('11:00', $out['start_time']);
        $this->assertNull($out['end_time']);
    }

    public function test_range_typed_into_start_time_is_split(): void
    {
        $out = $this->prepared(['start_time' => '12.00-15.00']);

        $this->assertSame('12:00', $out['start_time']);
        $this->assertSame('15:00', $out['end_time']);
    }

    public function test_explicit_end_time_wins_over_split_result(): void
    {
        $out = $this->prepared(['start_time' => '12.00', 'end_time' => '14.30']);

        $this->assertSame('12:00', $out['start_time']);
        $this->assertSame('14:30', $out['end_time']);
    }

    public function test_blank_status_becomes_null(): void
    {
        $this->assertNull($this->prepared(['status' => ''])['status']);
    }

    public function test_end_time_must_be_after_start_time(): void
    {
        $rules = (new StoreReservationRequest())->rules();

        $validator = Validator::make(
            ['start_time' => '15:00', 'end_time' => '12:00'],
            ['end_time' => $rules['end_time']]
        );

        $this->assertTrue($validator->errors()->has('end_time'));
    }

    public function test_pax_must_be_at_least_one(): void
    {
        $rules = (new StoreReservationRequest())->rules();

        $validator = Validator::make(['pax' => 0], ['pax' => $rules['pax']]);

        $this->assertTrue($validator->errors()->has('pax'));
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=StoreReservationRequestTest`
Expected: FAIL dengan "Class App\Http\Requests\StoreReservationRequest not found"

- [ ] **Step 3: Buat FormRequest**

`app/Http/Requests/StoreReservationRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\ReservationStatus;
use App\Support\TimeInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Otorisasi ditangani Policy di controller.
    }

    public function rules(): array
    {
        return [
            'reservation_date' => ['required', 'date'],
            'guest_name' => ['required', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'pic_id' => ['required', Rule::exists('users', 'id')->where('is_active', true)],
            'event_type_id' => ['nullable', 'exists:event_types,id'],
            'menu_style_id' => ['nullable', 'exists:menu_styles,id'],
            'area_id' => ['nullable', 'exists:areas,id'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'pax' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(ReservationStatus::class)],
            'remark' => ['nullable', 'string'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'reservation_date' => 'tanggal',
            'guest_name' => 'nama tamu',
            'phone' => 'nomor HP',
            'pic_id' => 'PIC',
            'start_time' => 'jam mulai',
            'end_time' => 'jam selesai',
            'pax' => 'jumlah tamu',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'guest_name' => $this->cleanText($this->input('guest_name')),
            'company' => $this->cleanText($this->input('company')),
            'phone' => $this->cleanPhone($this->input('phone')),
            'email' => $this->cleanEmail($this->input('email')),
            'remark' => $this->cleanText($this->input('remark')),
            'status' => $this->blankToNull($this->input('status')),
            ...$this->cleanTimes(),
        ]);
    }

    /**
     * Dipanggil oleh test unit. Laravel memanggil prepareForValidation()
     * secara otomatis saat request nyata diproses.
     */
    public function prepareForValidationPublic(): void
    {
        $this->prepareForValidation();
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === '' || $value === null) ? null : $value;
    }

    private function cleanPhone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function cleanEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if ($value === '' || $value === 'na' || $value === '-') {
            return null;
        }

        return $value;
    }

    /**
     * @return array{start_time: ?string, end_time: ?string}
     */
    private function cleanTimes(): array
    {
        $split = TimeInput::split($this->input('start_time'));
        $explicitEnd = TimeInput::normalize($this->input('end_time'));

        return [
            'start_time' => $split['start'],
            'end_time' => $explicitEnd ?? $split['end'],
        ];
    }
}
```

`cleanPhone` mengubah `NA` menjadi `null` sebagai efek samping yang diinginkan: tidak ada digit di dalamnya, sehingga hasilnya string kosong lalu menjadi `null`, lalu ditolak oleh aturan `required`.

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=StoreReservationRequestTest`
Expected: 12 test PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/StoreReservationRequest.php tests/Unit/StoreReservationRequestTest.php
git commit -m "feat: validasi dan normalisasi input reservasi"
```

---

## Task 9: Penulisan reservasi yang aman dari race condition

**Files:**
- Create: `app/Exceptions/DuplicateReservationException.php`
- Create: `app/Exceptions/StaleReservationException.php`
- Create: `app/Services/ReservationWriter.php`
- Test: `tests/Feature/ReservationWriterTest.php`

**Interfaces:**
- Consumes: `Reservation` (Task 4), constraint `uniq_reservations_dedupe` (Task 5), `LogsActivity` (Task 7)
- Produces:
  - `ReservationWriter::create(array $data, string $idempotencyKey, User $actor): Reservation`
  - `ReservationWriter::update(Reservation $reservation, array $data, int $expectedVersion, User $actor): Reservation`
  - `DuplicateReservationException` dengan method `existing(): ?Reservation`
  - `StaleReservationException` tanpa parameter tambahan

Seluruh logika penulisan dikumpulkan di satu kelas agar controller tidak perlu tahu soal transaksi, lock, maupun kode error MySQL — dan agar perilaku ini bisa diuji tanpa melewati HTTP.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/ReservationWriterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateReservationException;
use App\Exceptions\StaleReservationException;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationWriterTest extends TestCase
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

    public function test_create_stores_bookkeeping_columns(): void
    {
        $key = (string) Str::uuid();

        $r = $this->writer->create($this->payload(), $key, $this->actor);

        $this->assertSame(1, $r->version);
        $this->assertSame($key, $r->idempotency_key);
        $this->assertSame($this->actor->id, $r->created_by);
        $this->assertNull($r->updated_by);
    }

    public function test_same_idempotency_key_returns_the_same_row(): void
    {
        $key = (string) Str::uuid();

        $first = $this->writer->create($this->payload(), $key, $this->actor);
        $second = $this->writer->create($this->payload(['pax' => 99]), $key, $this->actor);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Reservation::count());
        $this->assertSame(3, $second->pax, 'Submit kedua tidak boleh mengubah data.');
    }

    public function test_duplicate_is_rejected_with_a_domain_exception(): void
    {
        $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);

        try {
            $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);
            $this->fail('Duplikat seharusnya ditolak.');
        } catch (DuplicateReservationException $e) {
            $this->assertSame('Bapak Wanda', $e->existing()->guest_name);
        }

        $this->assertSame(1, Reservation::count());
    }

    public function test_update_increments_version_and_sets_updated_by(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);
        $editor = User::factory()->create();

        $updated = $this->writer->update($r, $this->payload(['pax' => 8]), 1, $editor);

        $this->assertSame(8, $updated->pax);
        $this->assertSame(2, $updated->version);
        $this->assertSame($editor->id, $updated->updated_by);
    }

    public function test_update_with_stale_version_is_rejected_and_changes_nothing(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);
        $this->writer->update($r, $this->payload(['pax' => 8]), 1, $this->actor);

        try {
            $this->writer->update($r->fresh(), $this->payload(['pax' => 10]), 1, $this->actor);
            $this->fail('Version basi seharusnya ditolak.');
        } catch (StaleReservationException) {
            // diharapkan
        }

        $this->assertSame(8, $r->fresh()->pax, 'Data tidak boleh berubah.');
        $this->assertSame(2, $r->fresh()->version);
    }

    public function test_update_records_exactly_one_audit_entry(): void
    {
        $r = $this->writer->create($this->payload(), (string) Str::uuid(), $this->actor);
        $before = $r->activities()->count();

        $this->writer->update($r, $this->payload(['pax' => 8]), 1, $this->actor);

        $this->assertSame($before + 1, $r->fresh()->activities()->count());
    }

    public function test_update_into_an_existing_duplicate_is_rejected(): void
    {
        $this->writer->create($this->payload(['guest_name' => 'Tanti']), (string) Str::uuid(), $this->actor);
        $second = $this->writer->create($this->payload(['guest_name' => 'Melinda']), (string) Str::uuid(), $this->actor);

        $this->expectException(DuplicateReservationException::class);

        $this->writer->update($second, $this->payload(['guest_name' => 'Tanti']), 1, $this->actor);
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationWriterTest`
Expected: FAIL dengan "Target class [App\Services\ReservationWriter] does not exist."

- [ ] **Step 3: Buat dua exception**

`app/Exceptions/DuplicateReservationException.php`:

```php
<?php

namespace App\Exceptions;

use App\Models\Reservation;
use Exception;

class DuplicateReservationException extends Exception
{
    public function __construct(private readonly ?Reservation $existing = null)
    {
        parent::__construct('Reservasi dengan tanggal, nama, dan jam mulai yang sama sudah ada.');
    }

    public function existing(): ?Reservation
    {
        return $this->existing;
    }
}
```

`app/Exceptions/StaleReservationException.php`:

```php
<?php

namespace App\Exceptions;

use Exception;

class StaleReservationException extends Exception
{
    public function __construct()
    {
        parent::__construct('Reservasi ini baru saja diubah orang lain.');
    }
}
```

- [ ] **Step 4: Buat ReservationWriter**

`app/Services/ReservationWriter.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\DuplicateReservationException;
use App\Exceptions\StaleReservationException;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReservationWriter
{
    private const DUPLICATE_ERROR = 1062;
    private const DEDUPE_INDEX = 'uniq_reservations_dedupe';
    private const IDEMPOTENCY_INDEX = 'reservations_idempotency_key_unique';

    public function create(array $data, string $idempotencyKey, User $actor): Reservation
    {
        $existing = Reservation::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($data, $idempotencyKey, $actor) {
                $reservation = new Reservation();
                $reservation->fill($data);
                $reservation->idempotency_key = $idempotencyKey;
                $reservation->created_by = $actor->id;
                $reservation->version = 1;
                $reservation->save();

                return $reservation;
            });
        } catch (QueryException $e) {
            // Submit kedua yang tiba bersamaan dengan yang pertama.
            if ($this->violates($e, self::IDEMPOTENCY_INDEX)) {
                return Reservation::where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            if ($this->violates($e, self::DEDUPE_INDEX)) {
                throw new DuplicateReservationException($this->findDuplicate($data));
            }

            throw $e;
        }
    }

    public function update(
        Reservation $reservation,
        array $data,
        int $expectedVersion,
        User $actor
    ): Reservation {
        return DB::transaction(function () use ($reservation, $data, $expectedVersion, $actor) {
            $fresh = Reservation::whereKey($reservation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->version !== $expectedVersion) {
                throw new StaleReservationException();
            }

            $fresh->fill($data);
            $fresh->version = $fresh->version + 1;
            $fresh->updated_by = $actor->id;

            try {
                $fresh->save();
            } catch (QueryException $e) {
                if ($this->violates($e, self::DEDUPE_INDEX)) {
                    throw new DuplicateReservationException($this->findDuplicate($data));
                }

                throw $e;
            }

            return $fresh;
        });
    }

    private function violates(QueryException $e, string $index): bool
    {
        return ($e->errorInfo[1] ?? null) === self::DUPLICATE_ERROR
            && str_contains($e->getMessage(), $index);
    }

    private function findDuplicate(array $data): ?Reservation
    {
        return Reservation::query()
            ->whereDate('reservation_date', $data['reservation_date'])
            ->whereRaw('LOWER(TRIM(guest_name)) = ?', [mb_strtolower(trim($data['guest_name']))])
            ->whereTime('start_time', $data['start_time'])
            ->first();
    }
}
```

**Catatan penting untuk implementer.** `$fresh->save()` dipakai, bukan
`Reservation::whereKey(...)->update(...)`. Update massal tidak memicu event Eloquent,
sehingga `activity_log` tidak akan mencatat apa pun — audit trail bolong tanpa
menghasilkan error. Test `test_update_records_exactly_one_audit_entry` ada khusus
untuk menangkap kesalahan ini.

`StaleReservationException` dilempar dari dalam `DB::transaction()`, yang otomatis
melakukan rollback. Inilah yang menjamin data tidak berubah sama sekali saat version
basi.

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=ReservationWriterTest`
Expected: 7 test PASS

- [ ] **Step 6: Commit**

```bash
git add app/Exceptions app/Services/ReservationWriter.php tests/Feature/ReservationWriterTest.php
git commit -m "feat: penulisan reservasi dengan idempotency dan optimistic lock"
```

---

## Task 10: Deteksi tumpang tindih area

**Files:**
- Create: `app/Services/ConflictChecker.php`
- Test: `tests/Feature/ConflictCheckerTest.php`

**Interfaces:**
- Consumes: `Reservation` (Task 4), `config('reservation.default_duration_minutes')` (Task 1)
- Produces: `ConflictChecker::check(?int $areaId, string $date, string $startTime, ?string $endTime, ?int $ignoreId = null): Collection` — koleksi `Reservation` yang bertabrakan, kosong jika tidak ada atau `$areaId` null

Peringatan ini **tidak memblokir penyimpanan**. Fungsinya memberi tahu, bukan menolak.

Penyaringan dilakukan di PHP, bukan lewat query SQL bersyarat. Untuk satu area pada satu tanggal, jumlah baris kandidat praktis 1 sampai 3 dan tidak pernah melebihi jumlah reservasi satu bulan. Menyaring di PHP membuat aturan tumpang tindih terbaca dalam satu ekspresi dan bisa diuji langsung, sementara versi SQL-nya sulit dibaca dan mudah salah pada kasus `end_time` yang `NULL`.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/ConflictCheckerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Services\ConflictChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConflictCheckerTest extends TestCase
{
    use RefreshDatabase;

    private ConflictChecker $checker;
    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = app(ConflictChecker::class);
        $this->area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);
    }

    private function existing(string $start, ?string $end = null): Reservation
    {
        return Reservation::factory()->create([
            'area_id' => $this->area->id,
            'reservation_date' => '2026-08-09',
            'start_time' => $start,
            'end_time' => $end,
            'guest_name' => 'Existing '.$start,
        ]);
    }

    public function test_no_area_means_no_conflict(): void
    {
        $this->existing('12:00:00');

        $this->assertCount(0, $this->checker->check(null, '2026-08-09', '12:00', null));
    }

    public function test_single_time_bookings_two_hours_apart_do_not_conflict(): void
    {
        $this->existing('12:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '18:00', null));
    }

    public function test_single_time_bookings_one_hour_apart_do_conflict(): void
    {
        $this->existing('12:00:00');

        $this->assertCount(1, $this->checker->check($this->area->id, '2026-08-09', '13:00', null));
    }

    public function test_exactly_two_hours_later_does_not_conflict(): void
    {
        $this->existing('12:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '14:00', null));
    }

    public function test_ranges_that_overlap_conflict(): void
    {
        $this->existing('12:00:00', '15:00:00');

        $this->assertCount(1, $this->checker->check($this->area->id, '2026-08-09', '14:00', '16:00'));
    }

    public function test_ranges_that_touch_do_not_conflict(): void
    {
        $this->existing('12:00:00', '15:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '15:00', '17:00'));
    }

    public function test_other_dates_are_ignored(): void
    {
        $this->existing('12:00:00', '15:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-10', '12:00', '15:00'));
    }

    public function test_other_areas_are_ignored(): void
    {
        $this->existing('12:00:00', '15:00:00');
        $other = Area::create(['name' => 'VIP 2', 'sort_order' => 2]);

        $this->assertCount(0, $this->checker->check($other->id, '2026-08-09', '12:00', '15:00'));
    }

    public function test_the_row_being_edited_is_ignored(): void
    {
        $r = $this->existing('12:00:00', '15:00:00');

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '12:00', '15:00', $r->id));
    }

    public function test_soft_deleted_rows_are_ignored(): void
    {
        $this->existing('12:00:00', '15:00:00')->delete();

        $this->assertCount(0, $this->checker->check($this->area->id, '2026-08-09', '13:00', '14:00'));
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ConflictCheckerTest`
Expected: FAIL dengan "Target class [App\Services\ConflictChecker] does not exist."

- [ ] **Step 3: Buat ConflictChecker**

`app/Services/ConflictChecker.php`:

```php
<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ConflictChecker
{
    /**
     * Reservasi lain yang memakai area sama pada tanggal sama dengan waktu tumpang tindih.
     *
     * @return Collection<int, Reservation>
     */
    public function check(
        ?int $areaId,
        string $date,
        string $startTime,
        ?string $endTime,
        ?int $ignoreId = null
    ): Collection {
        if ($areaId === null) {
            return collect();
        }

        [$start, $end] = $this->window($startTime, $endTime);

        return Reservation::query()
            ->with(['pic:id,name'])
            ->where('area_id', $areaId)
            ->whereDate('reservation_date', $date)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->filter(function (Reservation $other) use ($start, $end) {
                [$otherStart, $otherEnd] = $this->window($other->start_time, $other->end_time);

                return $start < $otherEnd && $otherStart < $end;
            })
            ->values();
    }

    /**
     * Rentang efektif sebuah reservasi dalam menit sejak tengah malam.
     * Reservasi tanpa end_time diasumsikan berdurasi default.
     *
     * @return array{0: int, 1: int}
     */
    private function window(string $startTime, ?string $endTime): array
    {
        $start = $this->minutes($startTime);

        $end = $endTime !== null
            ? $this->minutes($endTime)
            : $start + config('reservation.default_duration_minutes');

        return [$start, $end];
    }

    private function minutes(string $time): int
    {
        $parsed = Carbon::createFromFormat('H:i', substr($time, 0, 5));

        return $parsed->hour * 60 + $parsed->minute;
    }
}
```

Perbandingan memakai `<` di kedua sisi, bukan `<=`, sehingga dua reservasi yang
bersentuhan ujung ke ujung — satu berakhir pukul 15.00 dan berikutnya mulai pukul
15.00 — tidak dianggap bertabrakan.

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=ConflictCheckerTest`
Expected: 10 test PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ConflictChecker.php tests/Feature/ConflictCheckerTest.php
git commit -m "feat: deteksi tumpang tindih area sebagai peringatan"
```

---

## Task 11: Hak akses

**Files:**
- Create: `app/Policies/ReservationPolicy.php`
- Create: `app/Http/Middleware/EnsureUserIsAdmin.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/ReservationPolicyTest.php`

**Interfaces:**
- Consumes: `User::isAdmin()` (Task 2), `Reservation` (Task 4)
- Produces: `ReservationPolicy` dengan method `viewAny`, `view`, `create`, `update`, `delete`, `confirm` — semuanya menerima `User` dan mengembalikan `bool`; alias middleware `admin` terdaftar di `bootstrap/app.php`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/ReservationPolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Models\User;
use App\Policies\ReservationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ReservationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ReservationPolicy();
    }

    public function test_active_staff_can_read_and_write(): void
    {
        $staff = User::factory()->create();
        $r = Reservation::factory()->create();

        $this->assertTrue($this->policy->viewAny($staff));
        $this->assertTrue($this->policy->view($staff, $r));
        $this->assertTrue($this->policy->create($staff));
        $this->assertTrue($this->policy->update($staff, $r));
    }

    public function test_staff_cannot_delete_or_confirm(): void
    {
        $staff = User::factory()->create();
        $r = Reservation::factory()->create();

        $this->assertFalse($this->policy->delete($staff, $r));
        $this->assertFalse($this->policy->confirm($staff, $r));
    }

    public function test_admin_can_delete_and_confirm(): void
    {
        $admin = User::factory()->admin()->create();
        $r = Reservation::factory()->create();

        $this->assertTrue($this->policy->delete($admin, $r));
        $this->assertTrue($this->policy->confirm($admin, $r));
    }

    public function test_inactive_user_can_do_nothing(): void
    {
        $inactive = User::factory()->inactive()->create();
        $r = Reservation::factory()->create();

        $this->assertFalse($this->policy->viewAny($inactive));
        $this->assertFalse($this->policy->create($inactive));
        $this->assertFalse($this->policy->update($inactive, $r));
    }

    public function test_inactive_admin_can_do_nothing(): void
    {
        $inactive = User::factory()->admin()->inactive()->create();
        $r = Reservation::factory()->create();

        $this->assertFalse($this->policy->delete($inactive, $r));
        $this->assertFalse($this->policy->confirm($inactive, $r));
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationPolicyTest`
Expected: FAIL dengan "Class App\Policies\ReservationPolicy not found"

- [ ] **Step 3: Buat Policy**

`app/Policies/ReservationPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active;
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $user->is_active;
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    /**
     * Hanya admin yang boleh mengubah status menjadi confirmed.
     *
     * $reservation bernilai null saat status confirmed dipilih pada form
     * pembuatan, ketika barisnya belum ada.
     */
    public function confirm(User $user, ?Reservation $reservation = null): bool
    {
        return $user->is_active && $user->isAdmin();
    }
}
```

Parameter `$reservation` pada `confirm()` **wajib** nullable dengan nilai bawaan.
Controller memanggil `authorize('confirm', Reservation::class)` saat membuat reservasi
baru berstatus confirmed, dan pada pemanggilan itu Laravel tidak mengoper instance.

Pemeriksaan `is_active` diulang di setiap method, bukan diringkas lewat `before()`,
karena `before()` yang mengembalikan `false` akan memblokir seluruh gate di aplikasi
termasuk yang tidak berkaitan dengan reservasi.

- [ ] **Step 4: Buat middleware admin**

`app/Http/Middleware/EnsureUserIsAdmin.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->is_active && $user->isAdmin(), 403);

        return $next($request);
    }
}
```

- [ ] **Step 5: Daftarkan alias middleware**

Di `bootstrap/app.php`, di dalam `->withMiddleware(function (Middleware $middleware) { ... })`, tambahkan:

```php
$middleware->alias([
    'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
]);
```

Jika blok `withMiddleware` sudah berisi pemanggilan lain seperti
`$middleware->web(append: [...])` untuk Inertia, biarkan dan tambahkan `alias`
sesudahnya.

- [ ] **Step 6: Jalankan test**

Run: `php artisan test --filter=ReservationPolicyTest`
Expected: 5 test PASS

- [ ] **Step 7: Commit**

```bash
git add app/Policies app/Http/Middleware/EnsureUserIsAdmin.php bootstrap/app.php tests/Feature/ReservationPolicyTest.php
git commit -m "feat: policy reservasi dan middleware admin"
```

---

## Task 12: Controller reservasi — simpan, ubah, hapus

**Files:**
- Create: `app/Http/Requests/UpdateReservationRequest.php`
- Create: `app/Http/Controllers/ReservationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ReservationHttpTest.php`

**Interfaces:**
- Consumes: `StoreReservationRequest` (Task 8), `ReservationWriter` (Task 9), `ConflictChecker` (Task 10), `ReservationPolicy` (Task 11)
- Produces: route bernama `reservations.index`, `reservations.create`, `reservations.store`, `reservations.show`, `reservations.edit`, `reservations.update`, `reservations.destroy`. Redirect setelah simpan membawa flash `success` (string) dan `warnings` (array of string).

- [ ] **Step 1: Buat UpdateReservationRequest**

`app/Http/Requests/UpdateReservationRequest.php`:

```php
<?php

namespace App\Http\Requests;

class UpdateReservationRequest extends StoreReservationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        // Idempotency hanya relevan saat membuat baris baru.
        unset($rules['idempotency_key']);

        $rules['version'] = ['required', 'integer', 'min:1'];

        return $rules;
    }
}
```

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/ReservationHttpTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['name' => 'IRA']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'reservation_date' => '2026-08-07',
            'guest_name' => 'Bapak Wanda',
            'phone' => '0811-2233-445',
            'pic_id' => $this->staff->id,
            'start_time' => '12.00',
            'pax' => 3,
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post(route('reservations.store'), $this->payload())
            ->assertRedirect(route('login'));
    }

    public function test_staff_can_store_a_reservation(): void
    {
        $this->actingAs($this->staff)
            ->post(route('reservations.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $r = Reservation::sole();

        $this->assertSame('Bapak Wanda', $r->guest_name);
        $this->assertSame('08112233445', $r->phone, 'Nomor HP harus dinormalkan ke digit.');
        $this->assertSame('12:00:00', $r->start_time);
        $this->assertNull($r->end_time);
        $this->assertSame($this->staff->id, $r->created_by);
    }

    public function test_range_typed_into_start_time_is_split(): void
    {
        $this->actingAs($this->staff)
            ->post(route('reservations.store'), $this->payload(['start_time' => '12.00-15.00']));

        $r = Reservation::sole();

        $this->assertSame('12:00:00', $r->start_time);
        $this->assertSame('15:00:00', $r->end_time);
    }

    public function test_na_phone_is_rejected(): void
    {
        $this->actingAs($this->staff)
            ->post(route('reservations.store'), $this->payload(['phone' => 'NA']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(0, Reservation::count());
    }

    public function test_missing_pic_is_rejected(): void
    {
        $this->actingAs($this->staff)
            ->post(route('reservations.store'), $this->payload(['pic_id' => null]))
            ->assertSessionHasErrors('pic_id');
    }

    public function test_duplicate_returns_a_readable_error(): void
    {
        $this->actingAs($this->staff)->post(route('reservations.store'), $this->payload());

        $this->actingAs($this->staff)
            ->post(route('reservations.store'), $this->payload())
            ->assertSessionHasErrors('guest_name');

        $this->assertSame(1, Reservation::count());
    }

    public function test_resubmitting_the_same_idempotency_key_creates_one_row(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->staff)->post(route('reservations.store'), $payload);
        $this->actingAs($this->staff)->post(route('reservations.store'), $payload)
            ->assertRedirect();

        $this->assertSame(1, Reservation::count());
    }

    public function test_staff_cannot_set_status_to_confirmed(): void
    {
        $this->actingAs($this->staff)
            ->post(route('reservations.store'), $this->payload(['status' => 'confirmed']))
            ->assertForbidden();

        $this->assertSame(0, Reservation::count());
    }

    public function test_admin_can_set_status_to_confirmed(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('reservations.store'), $this->payload(['status' => 'confirmed']))
            ->assertRedirect();

        $this->assertSame('confirmed', Reservation::sole()->status->value);
    }

    public function test_area_overlap_produces_a_warning_but_still_saves(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        Reservation::factory()->create([
            'area_id' => $area->id,
            'reservation_date' => '2026-08-07',
            'start_time' => '12:00:00',
            'guest_name' => 'Tamu Lebih Dulu',
        ]);

        $this->actingAs($this->staff)
            ->post(route('reservations.store'), $this->payload([
                'area_id' => $area->id,
                'start_time' => '13.00',
            ]))
            ->assertSessionHas('warnings');

        $this->assertSame(2, Reservation::count());
    }

    public function test_stale_version_is_rejected_on_update(): void
    {
        $r = Reservation::factory()->create(['pax' => 5, 'pic_id' => $this->staff->id]);

        $this->actingAs($this->staff)->put(route('reservations.update', $r), [
            'reservation_date' => $r->reservation_date->toDateString(),
            'guest_name' => $r->guest_name,
            'phone' => $r->phone,
            'pic_id' => $this->staff->id,
            'start_time' => '12.00',
            'pax' => 8,
            'version' => 1,
        ]);

        $this->actingAs($this->staff)->put(route('reservations.update', $r), [
            'reservation_date' => $r->reservation_date->toDateString(),
            'guest_name' => $r->guest_name,
            'phone' => $r->phone,
            'pic_id' => $this->staff->id,
            'start_time' => '12.00',
            'pax' => 10,
            'version' => 1,
        ])->assertSessionHasErrors('version');

        $this->assertSame(8, $r->fresh()->pax);
    }

    public function test_staff_cannot_delete(): void
    {
        $r = Reservation::factory()->create();

        $this->actingAs($this->staff)
            ->delete(route('reservations.destroy', $r))
            ->assertForbidden();

        $this->assertSame(1, Reservation::count());
    }

    public function test_admin_can_delete(): void
    {
        $r = Reservation::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('reservations.destroy', $r))
            ->assertRedirect();

        $this->assertSame(0, Reservation::count());
        $this->assertSame(1, Reservation::withTrashed()->count());
    }
}
```

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationHttpTest`
Expected: FAIL dengan "Route [reservations.store] not defined."

- [ ] **Step 4: Buat controller**

`app/Http/Controllers/ReservationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Exceptions\DuplicateReservationException;
use App\Exceptions\StaleReservationException;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Models\Reservation;
use App\Services\ConflictChecker;
use App\Services\ReservationWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationWriter $writer,
        private readonly ConflictChecker $checker,
    ) {}

    public function store(StoreReservationRequest $request): RedirectResponse
    {
        $this->authorize('create', Reservation::class);

        $data = $request->validated();
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);

        $this->authorizeConfirmIfNeeded($data);

        try {
            $reservation = $this->writer->create($data, $key, $request->user());
        } catch (DuplicateReservationException $e) {
            throw $this->duplicateError($e);
        }

        return redirect()
            ->route('reservations.show', $reservation)
            ->with('success', 'Reservasi tersimpan.')
            ->with('warnings', $this->warnings($data, $reservation->id));
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation): RedirectResponse
    {
        $this->authorize('update', $reservation);

        $data = $request->validated();
        $version = (int) $data['version'];
        unset($data['version']);

        $this->authorizeConfirmIfNeeded($data, $reservation);

        try {
            $this->writer->update($reservation, $data, $version, $request->user());
        } catch (DuplicateReservationException $e) {
            throw $this->duplicateError($e);
        } catch (StaleReservationException $e) {
            throw ValidationException::withMessages([
                'version' => 'Reservasi ini baru saja diubah orang lain. '
                    . 'Muat ulang halaman untuk melihat perubahan terbaru.',
            ]);
        }

        return redirect()
            ->route('reservations.show', $reservation)
            ->with('success', 'Perubahan tersimpan.')
            ->with('warnings', $this->warnings($data, $reservation->id));
    }

    public function destroy(Reservation $reservation): RedirectResponse
    {
        $this->authorize('delete', $reservation);

        $reservation->delete();

        return redirect()
            ->route('reservations.index')
            ->with('success', 'Reservasi dihapus.');
    }

    private function authorizeConfirmIfNeeded(array $data, ?Reservation $reservation = null): void
    {
        if (($data['status'] ?? null) !== ReservationStatus::Confirmed->value) {
            return;
        }

        if ($reservation && $reservation->status === ReservationStatus::Confirmed) {
            return; // Status tidak berubah, tidak perlu izin tambahan.
        }

        $this->authorize('confirm', $reservation ?? Reservation::class);
    }

    private function duplicateError(DuplicateReservationException $e): ValidationException
    {
        $existing = $e->existing();

        $message = $existing
            ? sprintf(
                'Sudah ada reservasi atas nama %s pada %s jam %s.',
                $existing->guest_name,
                $existing->reservation_date->format('d/m/Y'),
                substr($existing->start_time, 0, 5)
            )
            : 'Reservasi dengan tanggal, nama, dan jam mulai yang sama sudah ada.';

        return ValidationException::withMessages(['guest_name' => $message]);
    }

    /**
     * @return array<int, string>
     */
    private function warnings(array $data, int $ignoreId): array
    {
        return $this->checker
            ->check(
                $data['area_id'] ?? null,
                $data['reservation_date'],
                $data['start_time'],
                $data['end_time'] ?? null,
                $ignoreId
            )
            ->map(fn (Reservation $other) => sprintf(
                'Area ini bentrok dengan reservasi %s jam %s.',
                $other->guest_name,
                substr($other->start_time, 0, 5)
            ))
            ->all();
    }
}
```

- [ ] **Step 5: Pastikan controller punya trait otorisasi**

Laravel 12 tidak lagi menyertakan `AuthorizesRequests` di controller dasar. Buka
`app/Http/Controllers/Controller.php` dan pastikan isinya:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;
}
```

Tanpa trait ini, pemanggilan `$this->authorize(...)` akan gagal dengan "Call to
undefined method".

- [ ] **Step 6: Daftarkan route**

Di `routes/web.php`, ganti seluruh isi file dengan:

```php
<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/reservations');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::get('/reservations/create', [ReservationController::class, 'create'])->name('reservations.create');
    Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
    Route::get('/reservations/{reservation}/edit', [ReservationController::class, 'edit'])->name('reservations.edit');
    Route::put('/reservations/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
    Route::delete('/reservations/{reservation}', [ReservationController::class, 'destroy'])->name('reservations.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
```

Route `/reservations/create` **wajib** didaftarkan sebelum `/reservations/{reservation}`,
kalau tidak string `create` akan ditangkap sebagai parameter model.

Method `index`, `create`, `show`, dan `edit` dibuat pada Task 13. Sampai task itu
selesai, test yang menyentuh keempatnya akan gagal — itu diharapkan.

- [ ] **Step 7: Tambahkan method placeholder agar route bisa di-resolve**

Sementara, tambahkan empat method ini ke `ReservationController` supaya redirect di
test `store` dan `update` tidak error. Isinya diganti pada Task 13.

```php
public function index(): \Inertia\Response
{
    return \Inertia\Inertia::render('Reservations/Index', ['reservations' => []]);
}

public function create(): \Inertia\Response
{
    return \Inertia\Inertia::render('Reservations/Form');
}

public function show(Reservation $reservation): \Inertia\Response
{
    $this->authorize('view', $reservation);

    return \Inertia\Inertia::render('Reservations/Show', ['reservation' => $reservation]);
}

public function edit(Reservation $reservation): \Inertia\Response
{
    $this->authorize('update', $reservation);

    return \Inertia\Inertia::render('Reservations/Form', ['reservation' => $reservation]);
}
```

- [ ] **Step 8: Jalankan test**

Run: `php artisan test --filter=ReservationHttpTest`
Expected: 13 test PASS

- [ ] **Step 9: Jalankan seluruh test**

Run: `php artisan test`
Expected: semua PASS

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/UpdateReservationRequest.php app/Http/Controllers routes/web.php tests/Feature/ReservationHttpTest.php
git commit -m "feat: controller reservasi untuk simpan, ubah, dan hapus"
```

---

## Task 13: Halaman daftar dan detail — sisi server

**Files:**
- Modify: `app/Http/Controllers/ReservationController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Test: `tests/Feature/ReservationPagePropsTest.php`

**Interfaces:**
- Consumes: `ReservationController` (Task 12)
- Produces: props Inertia berikut, yang dikonsumsi seluruh task frontend:
  - Halaman `Reservations/Index`: `month` (string `YYYY-MM`), `reservations` (array), `picOptions`, `areas`, `eventTypes`, `menuStyles` (masing-masing array of `{id, name}`)
  - Setiap item `reservations`: `{id, reservation_date, guest_name, company, phone, email, pic, event_type, menu_style, area, start_time, end_time, pax, status, remark}` — `pic`, `event_type`, `menu_style`, `area` berupa string nama atau `null`; `start_time` dan `end_time` berformat `H:i`
  - Halaman `Reservations/Show`: `reservation` (objek di atas ditambah `version`), `activities` (array `{id, description, causer, changes, created_at}`)
  - Shared: `auth.user.role`, `auth.user.is_admin`

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/ReservationPagePropsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ReservationPagePropsTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_shows_only_the_requested_month(): void
    {
        $user = User::factory()->create();
        Reservation::factory()->create(['reservation_date' => '2026-08-08', 'guest_name' => 'Agustus']);
        Reservation::factory()->create(['reservation_date' => '2026-09-02', 'guest_name' => 'September']);

        $this->actingAs($user)
            ->get(route('reservations.index', ['month' => '2026-08']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Index')
                ->where('month', '2026-08')
                ->has('reservations', 1)
                ->where('reservations.0.guest_name', 'Agustus')
            );
    }

    public function test_index_defaults_to_the_current_month(): void
    {
        $this->travelTo('2026-08-10');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('reservations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('month', '2026-08'));
    }

    public function test_index_flattens_relations_to_names(): void
    {
        $pic = User::factory()->create(['name' => 'IRA']);
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        Reservation::factory()->create([
            'reservation_date' => '2026-08-08',
            'pic_id' => $pic->id,
            'area_id' => $area->id,
            'start_time' => '12:00:00',
            'end_time' => '15:00:00',
        ]);

        $this->actingAs($pic)
            ->get(route('reservations.index', ['month' => '2026-08']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reservations.0.pic', 'IRA')
                ->where('reservations.0.area', 'VIP 1')
                ->where('reservations.0.start_time', '12:00')
                ->where('reservations.0.end_time', '15:00')
                ->where('reservations.0.event_type', null)
            );
    }

    public function test_index_sends_single_time_end_as_null(): void
    {
        $user = User::factory()->create();
        Reservation::factory()->create(['reservation_date' => '2026-08-08', 'start_time' => '11:00:00']);

        $this->actingAs($user)
            ->get(route('reservations.index', ['month' => '2026-08']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reservations.0.start_time', '11:00')
                ->where('reservations.0.end_time', null)
            );
    }

    public function test_index_provides_dropdown_options(): void
    {
        $user = User::factory()->create();
        User::factory()->inactive()->create(['name' => 'Mantan Staf']);
        Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        $this->actingAs($user)
            ->get(route('reservations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('picOptions', 1)
                ->has('areas', 1)
                ->has('eventTypes')
                ->has('menuStyles')
            );
    }

    public function test_show_includes_version_and_audit_trail(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $r = Reservation::factory()->create(['pax' => 5]);
        $r->pax = 8;
        $r->save();

        $this->get(route('reservations.show', $r))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Show')
                ->where('reservation.version', 1)
                ->has('activities', 2)
            );
    }

    public function test_shared_props_expose_admin_flag(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reservations.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.is_admin', true)
                ->where('auth.user.role', 'admin')
            );
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationPagePropsTest`
Expected: FAIL — prop `month` belum ada

- [ ] **Step 3: Ganti empat method placeholder di controller**

Di `app/Http/Controllers/ReservationController.php`, hapus empat method placeholder
dari Task 12 Step 7, lalu tambahkan import berikut di bagian atas file:

```php
use App\Models\Area;
use App\Models\EventType;
use App\Models\MenuStyle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
```

Tambahkan method berikut:

```php
public function index(Request $request): Response
{
    $this->authorize('viewAny', Reservation::class);

    $month = $this->resolveMonth($request->query('month'));

    $reservations = Reservation::query()
        ->with(['pic:id,name', 'area:id,name', 'eventType:id,name', 'menuStyle:id,name'])
        ->whereYear('reservation_date', $month->year)
        ->whereMonth('reservation_date', $month->month)
        ->orderBy('reservation_date')
        ->orderBy('start_time')
        ->get()
        ->map(fn (Reservation $r) => $this->toArray($r))
        ->all();

    return Inertia::render('Reservations/Index', [
        'month' => $month->format('Y-m'),
        'reservations' => $reservations,
        ...$this->options(),
    ]);
}

public function create(): Response
{
    $this->authorize('create', Reservation::class);

    return Inertia::render('Reservations/Form', [
        'reservation' => null,
        ...$this->options(),
    ]);
}

public function show(Reservation $reservation): Response
{
    $this->authorize('view', $reservation);

    $reservation->load(['pic:id,name', 'area:id,name', 'eventType:id,name', 'menuStyle:id,name']);

    return Inertia::render('Reservations/Show', [
        'reservation' => [...$this->toArray($reservation), 'version' => $reservation->version],
        'activities' => $this->activities($reservation),
    ]);
}

public function edit(Reservation $reservation): Response
{
    $this->authorize('update', $reservation);

    return Inertia::render('Reservations/Form', [
        'reservation' => [
            ...$this->toArray($reservation),
            'version' => $reservation->version,
            'pic_id' => $reservation->pic_id,
            'area_id' => $reservation->area_id,
            'event_type_id' => $reservation->event_type_id,
            'menu_style_id' => $reservation->menu_style_id,
        ],
        ...$this->options(),
    ]);
}

private function resolveMonth(?string $value): Carbon
{
    if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
        try {
            return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
        } catch (\Throwable) {
            // Format benar tapi nilainya tidak masuk akal, misalnya bulan 13.
        }
    }

    return Carbon::now()->startOfMonth();
}

private function toArray(Reservation $r): array
{
    return [
        'id' => $r->id,
        'reservation_date' => $r->reservation_date->toDateString(),
        'guest_name' => $r->guest_name,
        'company' => $r->company,
        'phone' => $r->phone,
        'email' => $r->email,
        'pic' => $r->pic?->name,
        'event_type' => $r->eventType?->name,
        'menu_style' => $r->menuStyle?->name,
        'area' => $r->area?->name,
        'start_time' => substr($r->start_time, 0, 5),
        'end_time' => $r->end_time ? substr($r->end_time, 0, 5) : null,
        'pax' => $r->pax,
        'status' => $r->status?->value,
        'remark' => $r->remark,
    ];
}

private function options(): array
{
    return [
        'picOptions' => User::query()->active()->orderBy('name')->get(['id', 'name'])->all(),
        'areas' => Area::query()->active()->orderBy('sort_order')->get(['id', 'name'])->all(),
        'eventTypes' => EventType::query()->active()->orderBy('sort_order')->get(['id', 'name'])->all(),
        'menuStyles' => MenuStyle::query()->active()->orderBy('sort_order')->get(['id', 'name'])->all(),
    ];
}

private function activities(Reservation $reservation): array
{
    return $reservation->activities()
        ->with('causer:id,name')
        ->latest('id')
        ->get()
        ->map(fn ($a) => [
            'id' => $a->id,
            'event' => $a->event,
            'causer' => $a->causer?->name ?? 'Sistem',
            'created_at' => $a->created_at->format('d M Y, H:i'),
            'changes' => collect($a->properties['attributes'] ?? [])
                ->map(fn ($new, $field) => [
                    'field' => $field,
                    'old' => $a->properties['old'][$field] ?? null,
                    'new' => $new,
                ])
                ->values()
                ->all(),
        ])
        ->all();
}
```

`toArray()` memotong `start_time` menjadi lima karakter agar frontend selalu menerima
`H:i`, tidak pernah `H:i:s`. Ini penting supaya `formatTimeRange` di frontend tidak
perlu menangani dua bentuk.

- [ ] **Step 4: Bagikan flag admin ke seluruh halaman**

Di `app/Http/Middleware/HandleInertiaRequests.php`, di dalam method `share()`, ganti
bagian `'auth' => [...]` menjadi:

```php
'auth' => [
    'user' => $request->user() ? [
        'id' => $request->user()->id,
        'name' => $request->user()->name,
        'email' => $request->user()->email,
        'role' => $request->user()->role->value,
        'is_admin' => $request->user()->isAdmin(),
    ] : null,
],
```

Tambahkan juga flash message di dalam array yang sama:

```php
'flash' => [
    'success' => fn () => $request->session()->get('success'),
    'warnings' => fn () => $request->session()->get('warnings', []),
],
```

`is_admin` dipakai frontend hanya untuk menyembunyikan tombol. Otorisasi sebenarnya
tetap dijalankan Policy di server.

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=ReservationPagePropsTest`
Expected: 7 test PASS

- [ ] **Step 6: Jalankan seluruh test**

Run: `php artisan test`
Expected: semua PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ReservationController.php app/Http/Middleware/HandleInertiaRequests.php tests/Feature/ReservationPagePropsTest.php
git commit -m "feat: props halaman daftar dan detail reservasi"
```

---

> **Catatan untuk seluruh task frontend (14 sampai 18).** Spec menetapkan tidak ada
> test otomatis untuk frontend di v1. Verifikasi setiap task dilakukan lewat
> `npm run build` yang harus lolos tanpa error, ditambah langkah pemeriksaan manual
> yang ditulis eksplisit di tiap task. Jalankan `composer run dev` di satu terminal
> selama mengerjakan bagian ini.

## Task 14: Mode tabel

**Files:**
- Create: `resources/js/Utils/formatTimeRange.js`
- Create: `resources/js/Components/StatusBadge.jsx`
- Create: `resources/js/Components/RemarkRow.jsx`
- Create: `resources/js/Components/ReservationTable.jsx`
- Create: `resources/js/Components/FilterBar.jsx`
- Create: `resources/js/Components/MonthNav.jsx`
- Create: `resources/js/Pages/Reservations/Index.jsx`

**Interfaces:**
- Consumes: props dari Task 13
- Produces:
  - `formatTimeRange(start, end): string` — `'11:00'` jika `end` kosong, `'12:00–15:00'` jika terisi, `'—'` jika `start` kosong
  - `<StatusBadge status={string|null} />`
  - `<RemarkRow remark={string|null} colSpan={number} />` — mengembalikan `null` jika remark kosong
  - `<ReservationTable reservations={array} />`
  - `<FilterBar value={object} onChange={fn} picOptions={array} />` dengan bentuk value `{q, pic, status}`
  - `<MonthNav month={string} />`

- [ ] **Step 1: Pastikan build saat ini berjalan**

Run: `npm install && npm run build`
Expected: build selesai tanpa error.

Jika build gagal dengan pesan terkait Tailwind, periksa `package.json`: repo saat ini
mencantumkan `tailwindcss@^3.2.1` bersama `@tailwindcss/vite@^4.0.0`, yang merupakan
kombinasi versi mayor berbeda. Selesaikan dengan menyamakan ke Tailwind 3 —
hapus `@tailwindcss/vite` dari `devDependencies`, lalu pastikan `vite.config.js`
memakai plugin PostCSS bawaan Tailwind 3, bukan plugin Vite Tailwind 4.
**Jangan lanjut ke step berikutnya sebelum build hijau.**

- [ ] **Step 2: Buat helper format jam**

`resources/js/Utils/formatTimeRange.js`:

```js
/**
 * Satu-satunya tempat format jam dibuat.
 * end yang kosong berarti reservasi berjam tunggal.
 */
export function formatTimeRange(start, end) {
    if (!start) {
        return '—';
    }

    return end ? `${start}–${end}` : start;
}

export function isRange(end) {
    return Boolean(end);
}
```

- [ ] **Step 3: Buat StatusBadge**

`resources/js/Components/StatusBadge.jsx`:

```jsx
export default function StatusBadge({ status }) {
    if (!status) {
        return <span className="text-gray-400">—</span>;
    }

    const isConfirmed = status === 'confirmed';

    const classes = isConfirmed
        ? 'border-emerald-600 text-emerald-700'
        : 'border-dashed border-amber-600 text-amber-700';

    return (
        <span
            className={`inline-block rounded border px-1.5 py-0.5 text-[10px] font-bold tracking-wide ${classes}`}
        >
            {isConfirmed ? 'CONFIRMED' : 'TENTATIVE'}
        </span>
    );
}
```

- [ ] **Step 4: Buat RemarkRow**

`resources/js/Components/RemarkRow.jsx`:

```jsx
/**
 * Remark selalu ditampilkan utuh. Dilarang memotong teksnya.
 * Baris tidak dirender sama sekali jika remark kosong.
 */
export default function RemarkRow({ remark, colSpan }) {
    if (!remark) {
        return null;
    }

    return (
        <tr>
            <td colSpan={colSpan} className="px-3 pb-3 pt-0">
                <div className="flex gap-2 border-l-2 border-amber-500 pl-3">
                    <span className="shrink-0 pt-px text-[10px] font-bold tracking-wider text-amber-700">
                        REMARK
                    </span>
                    <span className="whitespace-pre-line text-xs leading-relaxed text-gray-600">
                        {remark}
                    </span>
                </div>
            </td>
        </tr>
    );
}
```

- [ ] **Step 5: Buat ReservationTable**

`resources/js/Components/ReservationTable.jsx`:

```jsx
import { Link } from '@inertiajs/react';
import { formatTimeRange } from '@/Utils/formatTimeRange';
import RemarkRow from './RemarkRow';
import StatusBadge from './StatusBadge';

const COLUMNS = 9;

function Dash() {
    return <span className="text-gray-400">—</span>;
}

export default function ReservationTable({ reservations }) {
    if (reservations.length === 0) {
        return (
            <p className="py-10 text-center text-sm text-gray-500">
                Belum ada reservasi pada bulan ini.
            </p>
        );
    }

    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="border-b border-gray-300 text-left text-[10px] uppercase tracking-wider text-gray-500">
                    <th className="px-3 pb-2 font-semibold">Tanggal</th>
                    <th className="px-3 pb-2 font-semibold">Jam</th>
                    <th className="px-3 pb-2 font-semibold">Nama</th>
                    <th className="px-3 pb-2 font-semibold">HP</th>
                    <th className="px-3 pb-2 font-semibold">PIC</th>
                    <th className="px-3 pb-2 font-semibold">Event</th>
                    <th className="px-3 pb-2 font-semibold">Area</th>
                    <th className="px-3 pb-2 text-right font-semibold">Pax</th>
                    <th className="px-3 pb-2 font-semibold">Status</th>
                </tr>
            </thead>
            <tbody>
                {reservations.map((r) => (
                    <>
                        <tr key={r.id} className="border-t border-gray-100 align-top">
                            <td className="whitespace-nowrap px-3 pb-1 pt-2">
                                {r.reservation_date}
                            </td>
                            <td className="whitespace-nowrap px-3 pb-1 pt-2">
                                {formatTimeRange(r.start_time, r.end_time)}
                            </td>
                            <td className="px-3 pb-1 pt-2">
                                <Link
                                    href={route('reservations.show', r.id)}
                                    className="font-semibold text-gray-900 hover:underline"
                                >
                                    {r.guest_name}
                                </Link>
                                {r.company && (
                                    <span className="block text-xs text-gray-500">{r.company}</span>
                                )}
                            </td>
                            <td className="whitespace-nowrap px-3 pb-1 pt-2">{r.phone}</td>
                            <td className="whitespace-nowrap px-3 pb-1 pt-2">{r.pic ?? <Dash />}</td>
                            <td className="px-3 pb-1 pt-2">{r.event_type ?? <Dash />}</td>
                            <td className="px-3 pb-1 pt-2">{r.area ?? <Dash />}</td>
                            <td className="px-3 pb-1 pt-2 text-right">{r.pax}</td>
                            <td className="px-3 pb-1 pt-2">
                                <StatusBadge status={r.status} />
                            </td>
                        </tr>
                        <RemarkRow key={`${r.id}-remark`} remark={r.remark} colSpan={COLUMNS} />
                    </>
                ))}
            </tbody>
        </table>
    );
}
```

Pasangan baris data dan baris remark dibungkus fragment, sehingga keduanya selalu
berpindah bersama saat urutan berubah.

- [ ] **Step 6: Buat FilterBar**

`resources/js/Components/FilterBar.jsx`:

```jsx
export default function FilterBar({ value, onChange, picOptions }) {
    const set = (key) => (e) => onChange({ ...value, [key]: e.target.value });

    return (
        <div className="flex flex-wrap items-center gap-2">
            <input
                type="search"
                value={value.q}
                onChange={set('q')}
                placeholder="Cari nama, company, atau catatan"
                className="w-64 rounded border-gray-300 text-sm"
            />

            <select value={value.pic} onChange={set('pic')} className="rounded border-gray-300 text-sm">
                <option value="">Semua PIC</option>
                {picOptions.map((p) => (
                    <option key={p.id} value={p.name}>
                        {p.name}
                    </option>
                ))}
            </select>

            <select value={value.status} onChange={set('status')} className="rounded border-gray-300 text-sm">
                <option value="">Semua status</option>
                <option value="confirmed">CONFIRMED</option>
                <option value="tentative">TENTATIVE</option>
                <option value="none">Belum ditentukan</option>
            </select>
        </div>
    );
}
```

- [ ] **Step 7: Buat MonthNav**

`resources/js/Components/MonthNav.jsx`:

```jsx
import { Link } from '@inertiajs/react';

const NAMES = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

function shift(month, delta) {
    const [year, m] = month.split('-').map(Number);
    const date = new Date(year, m - 1 + delta, 1);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

export default function MonthNav({ month }) {
    const [year, m] = month.split('-').map(Number);

    return (
        <div className="flex items-center gap-2">
            <h2 className="text-lg font-bold">
                {NAMES[m - 1]} {year}
            </h2>
            <Link
                href={route('reservations.index', { month: shift(month, -1) })}
                className="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50"
                aria-label="Bulan sebelumnya"
            >
                ‹
            </Link>
            <Link
                href={route('reservations.index', { month: shift(month, 1) })}
                className="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50"
                aria-label="Bulan berikutnya"
            >
                ›
            </Link>
        </div>
    );
}
```

- [ ] **Step 8: Buat halaman Index**

`resources/js/Pages/Reservations/Index.jsx`:

```jsx
import { useMemo, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FilterBar from '@/Components/FilterBar';
import MonthNav from '@/Components/MonthNav';
import ReservationTable from '@/Components/ReservationTable';

export default function Index({ month, reservations, picOptions }) {
    const { flash } = usePage().props;
    const [filter, setFilter] = useState({ q: '', pic: '', status: '' });

    const visible = useMemo(() => {
        const q = filter.q.trim().toLowerCase();

        return reservations.filter((r) => {
            if (filter.pic && r.pic !== filter.pic) {
                return false;
            }

            if (filter.status === 'none' && r.status) {
                return false;
            }

            if (filter.status && filter.status !== 'none' && r.status !== filter.status) {
                return false;
            }

            if (!q) {
                return true;
            }

            return [r.guest_name, r.company, r.remark]
                .filter(Boolean)
                .some((field) => field.toLowerCase().includes(q));
        });
    }, [reservations, filter]);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">Reservasi</h2>}
        >
            <Head title="Reservasi" />

            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="mb-4 rounded border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                {flash?.warnings?.length > 0 && (
                    <div className="mb-4 rounded border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                        <ul className="list-inside list-disc">
                            {flash.warnings.map((w, i) => (
                                <li key={i}>{w}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <MonthNav month={month} />
                    <div className="ml-auto flex items-center gap-2">
                        <Link
                            href={route('reservations.create')}
                            className="rounded bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-700"
                        >
                            Tambah reservasi
                        </Link>
                    </div>
                </div>

                <div className="mb-3">
                    <FilterBar value={filter} onChange={setFilter} picOptions={picOptions} />
                </div>

                <p className="mb-2 text-xs text-gray-500">
                    Menampilkan {visible.length} dari {reservations.length} reservasi.
                </p>

                <div className="overflow-x-auto rounded border border-gray-200 bg-white p-2">
                    <ReservationTable reservations={visible} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 9: Verifikasi build**

Run: `npm run build`
Expected: selesai tanpa error.

- [ ] **Step 10: Siapkan data dan periksa di browser**

```bash
php artisan migrate:fresh --seed
php artisan tinker --execute="
\$u = \App\Models\User::factory()->admin()->create(['name' => 'IRA', 'email' => 'ira@umara.test', 'password' => bcrypt('password')]);
\App\Models\Reservation::factory()->create(['reservation_date' => now()->startOfMonth()->addDays(7)->toDateString(), 'guest_name' => 'Ibu There', 'pic_id' => \$u->id, 'created_by' => \$u->id, 'start_time' => '12:00:00', 'remark' => 'MAIN CONTRACTOR ROEMAH UMARA']);
\App\Models\Reservation::factory()->create(['reservation_date' => now()->startOfMonth()->addDays(8)->toDateString(), 'guest_name' => 'Dharmadi', 'pic_id' => \$u->id, 'created_by' => \$u->id, 'start_time' => '12:00:00', 'end_time' => '15:00:00', 'status' => 'confirmed', 'pax' => 40]);
"
```

Buka `http://localhost:8000/reservations`, login sebagai `ira@umara.test` dengan
password `password`, lalu periksa satu per satu:

1. Baris Ibu There menampilkan jam `12:00` tanpa tanda hubung.
2. Baris Dharmadi menampilkan `12:00–15:00`.
3. Di bawah baris Ibu There ada baris REMARK berisi teks penuh `MAIN CONTRACTOR ROEMAH UMARA`.
4. Di bawah baris Dharmadi **tidak ada** baris REMARK sama sekali, tanpa ruang kosong.
5. Badge Dharmadi bertuliskan CONFIRMED; Ibu There menampilkan tanda hubung.
6. Mengetik `contractor` di kotak pencarian menyisakan satu baris — membuktikan pencarian ikut membaca remark.
7. Tombol `›` pindah ke bulan berikutnya dan daftar menjadi kosong.

- [ ] **Step 11: Commit**

```bash
git add resources/js
git commit -m "feat: halaman daftar reservasi mode tabel"
```

---

## Task 15: Mode kalender dan panel detail

**Files:**
- Create: `resources/js/Components/ViewToggle.jsx`
- Create: `resources/js/Components/ReservationChip.jsx`
- Create: `resources/js/Components/DayCell.jsx`
- Create: `resources/js/Components/MonthGrid.jsx`
- Create: `resources/js/Components/ReservationDetailPanel.jsx`
- Modify: `resources/js/Pages/Reservations/Index.jsx`

**Interfaces:**
- Consumes: props dan komponen dari Task 14
- Produces:
  - `<ViewToggle value={'table'|'calendar'} onChange={fn} />`
  - `<MonthGrid month={string} reservations={array} selectedId={number|null} onSelect={fn} />`
  - `<ReservationDetailPanel reservation={object|null} />` — remark ditampilkan utuh
  - `buildMonthGrid(month): Array<{day:number|null, iso:string|null}>` diekspor dari `MonthGrid.jsx`

Chip di kalender **tidak** menampilkan remark. Ruang sel tidak cukup untuk teks utuh,
dan memotongnya melanggar aturan. Remark diakses lewat panel detail di bawah grid.

- [ ] **Step 1: Buat ViewToggle**

`resources/js/Components/ViewToggle.jsx`:

```jsx
const OPTIONS = [
    { value: 'table', label: 'Tabel' },
    { value: 'calendar', label: 'Kalender' },
];

export default function ViewToggle({ value, onChange }) {
    return (
        <div className="inline-flex overflow-hidden rounded border border-gray-300">
            {OPTIONS.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={
                        value === option.value
                            ? 'bg-gray-900 px-3 py-1.5 text-xs font-bold text-white'
                            : 'bg-white px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50'
                    }
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
```

- [ ] **Step 2: Buat ReservationChip**

`resources/js/Components/ReservationChip.jsx`:

```jsx
function accent(status) {
    if (status === 'confirmed') {
        return 'border-l-emerald-600';
    }

    if (status === 'tentative') {
        return 'border-l-amber-500 border-dashed';
    }

    return 'border-l-gray-300';
}

export default function ReservationChip({ reservation, selected, onSelect }) {
    return (
        <button
            type="button"
            aria-current={selected}
            onClick={() => onSelect(reservation.id)}
            title={`${reservation.start_time} ${reservation.guest_name}`}
            className={`mb-0.5 block w-full truncate border-l-2 py-0.5 pl-1 pr-0.5 text-left text-[10px] leading-tight ${accent(
                reservation.status,
            )} ${selected ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'}`}
        >
            <span className="font-bold">{reservation.start_time}</span> {reservation.guest_name}
        </button>
    );
}
```

- [ ] **Step 3: Buat DayCell**

`resources/js/Components/DayCell.jsx`:

```jsx
import ReservationChip from './ReservationChip';

export default function DayCell({ day, reservations, selectedId, onSelect }) {
    if (day === null) {
        return <div className="min-h-[76px] bg-gray-50" />;
    }

    return (
        <div className="min-h-[76px] bg-white p-1">
            <div
                className={`mb-1 text-[11px] ${
                    reservations.length > 0 ? 'font-bold text-gray-900' : 'text-gray-400'
                }`}
            >
                {day}
            </div>

            {reservations.map((r) => (
                <ReservationChip
                    key={r.id}
                    reservation={r}
                    selected={r.id === selectedId}
                    onSelect={onSelect}
                />
            ))}
        </div>
    );
}
```

- [ ] **Step 4: Buat MonthGrid**

`resources/js/Components/MonthGrid.jsx`:

```jsx
import DayCell from './DayCell';

const DAY_NAMES = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

/**
 * Susun sel kalender untuk satu bulan, dengan minggu dimulai hari Senin.
 * Sel kosong di awal bulan diwakili day bernilai null.
 */
export function buildMonthGrid(month) {
    const [year, monthNumber] = month.split('-').map(Number);

    const firstDay = new Date(year, monthNumber - 1, 1);
    const daysInMonth = new Date(year, monthNumber, 0).getDate();

    // getDay() mengembalikan 0 untuk Minggu. Geser agar Senin menjadi 0.
    const lead = (firstDay.getDay() + 6) % 7;

    const cells = [];

    for (let i = 0; i < lead; i += 1) {
        cells.push({ day: null, iso: null });
    }

    for (let day = 1; day <= daysInMonth; day += 1) {
        cells.push({
            day,
            iso: `${month}-${String(day).padStart(2, '0')}`,
        });
    }

    return cells;
}

export default function MonthGrid({ month, reservations, selectedId, onSelect }) {
    const cells = buildMonthGrid(month);

    const byDate = reservations.reduce((acc, r) => {
        (acc[r.reservation_date] ||= []).push(r);

        return acc;
    }, {});

    return (
        <div>
            <div className="grid grid-cols-7 gap-px border border-gray-200 bg-gray-200">
                {DAY_NAMES.map((name) => (
                    <div
                        key={name}
                        className="bg-white py-1 text-center text-[10px] uppercase tracking-wider text-gray-500"
                    >
                        {name}
                    </div>
                ))}

                {cells.map((cell, index) => (
                    <DayCell
                        key={cell.iso ?? `lead-${index}`}
                        day={cell.day}
                        reservations={cell.iso ? (byDate[cell.iso] ?? []) : []}
                        selectedId={selectedId}
                        onSelect={onSelect}
                    />
                ))}
            </div>
        </div>
    );
}
```

Reservasi sudah diurutkan menurut tanggal lalu jam mulai oleh controller, sehingga
chip dalam satu sel otomatis urut tanpa penyortiran ulang di klien.

- [ ] **Step 5: Buat ReservationDetailPanel**

`resources/js/Components/ReservationDetailPanel.jsx`:

```jsx
import { Link } from '@inertiajs/react';
import { formatTimeRange, isRange } from '@/Utils/formatTimeRange';
import StatusBadge from './StatusBadge';

function Field({ label, value }) {
    return (
        <div>
            <dt className="text-[9px] font-semibold uppercase tracking-wider text-gray-400">
                {label}
            </dt>
            <dd className="text-sm text-gray-900">{value || <span className="text-gray-400">—</span>}</dd>
        </div>
    );
}

export default function ReservationDetailPanel({ reservation }) {
    if (!reservation) {
        return (
            <div className="mt-3 rounded border border-gray-200 px-4 py-6 text-center text-sm text-gray-500">
                Klik salah satu reservasi di kalender untuk melihat detail lengkapnya.
            </div>
        );
    }

    return (
        <div className="mt-3 rounded border border-gray-300 px-4 py-3">
            <h3 className="text-base font-bold">{reservation.guest_name}</h3>
            <p className="mb-3 text-xs text-gray-500">
                {reservation.reservation_date} ·{' '}
                {formatTimeRange(reservation.start_time, reservation.end_time)}{' '}
                {isRange(reservation.end_time) ? '(rentang)' : '(jam tunggal)'}
            </p>

            <dl className="mb-3 grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4">
                <Field label="Company" value={reservation.company} />
                <Field label="HP" value={reservation.phone} />
                <Field label="Email" value={reservation.email} />
                <Field label="PIC / Sales" value={reservation.pic} />
                <Field label="Event" value={reservation.event_type} />
                <Field label="Menu style" value={reservation.menu_style} />
                <Field label="Area" value={reservation.area} />
                <Field label="Pax" value={String(reservation.pax)} />
                <Field label="Status" value={<StatusBadge status={reservation.status} />} />
            </dl>

            {reservation.remark ? (
                <div className="flex gap-2 border-l-2 border-amber-500 pl-3">
                    <span className="shrink-0 pt-px text-[10px] font-bold tracking-wider text-amber-700">
                        REMARK
                    </span>
                    <span className="whitespace-pre-line text-xs leading-relaxed text-gray-600">
                        {reservation.remark}
                    </span>
                </div>
            ) : (
                <p className="text-xs text-gray-400">Tidak ada remark.</p>
            )}

            <div className="mt-3 flex gap-2">
                <Link
                    href={route('reservations.show', reservation.id)}
                    className="rounded border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-50"
                >
                    Detail &amp; riwayat
                </Link>
                <Link
                    href={route('reservations.edit', reservation.id)}
                    className="rounded border border-gray-300 px-2.5 py-1 text-xs hover:bg-gray-50"
                >
                    Ubah
                </Link>
            </div>
        </div>
    );
}
```

- [ ] **Step 6: Sambungkan ke halaman Index**

Di `resources/js/Pages/Reservations/Index.jsx`, tambahkan import:

```jsx
import MonthGrid from '@/Components/MonthGrid';
import ReservationDetailPanel from '@/Components/ReservationDetailPanel';
import ViewToggle from '@/Components/ViewToggle';
```

Tambahkan dua state di bawah state `filter` yang sudah ada:

```jsx
const [view, setView] = useState('table');
const [selectedId, setSelectedId] = useState(null);
```

Tambahkan turunan berikut di bawah `visible`:

```jsx
const selected = useMemo(
    () => visible.find((r) => r.id === selectedId) ?? null,
    [visible, selectedId],
);
```

Sisipkan `<ViewToggle />` ke dalam `div` yang berisi tombol "Tambah reservasi",
sebelum `<Link>`:

```jsx
<ViewToggle value={view} onChange={setView} />
```

Ganti blok `<div className="overflow-x-auto ...">` yang berisi `<ReservationTable />`
dengan:

```jsx
{view === 'table' ? (
    <div className="overflow-x-auto rounded border border-gray-200 bg-white p-2">
        <ReservationTable reservations={visible} />
    </div>
) : (
    <div>
        <MonthGrid
            month={month}
            reservations={visible}
            selectedId={selectedId}
            onSelect={setSelectedId}
        />
        <ReservationDetailPanel reservation={selected} />
    </div>
)}
```

`selected` diturunkan dari `visible`, bukan dari `reservations`, sehingga reservasi
yang tersaring keluar oleh filter juga hilang dari panel detail. Tanpa ini, panel
bisa menampilkan reservasi yang chipsnya sudah tidak terlihat di grid.

- [ ] **Step 7: Verifikasi build**

Run: `npm run build`
Expected: selesai tanpa error.

- [ ] **Step 8: Periksa di browser**

Buka `/reservations` dan periksa:

1. Tombol Tabel dan Kalender muncul; Tabel aktif secara bawaan.
2. Menekan Kalender menampilkan grid tujuh kolom dengan header Sen sampai Min.
3. Kolom pertama benar-benar hari Senin — cocokkan tanggal 1 bulan berjalan dengan kalender sistem.
4. Reservasi Ibu There muncul sebagai chip bertuliskan `12:00 Ibu There`.
5. Chip Dharmadi punya garis kiri hijau (CONFIRMED); chip lain abu-abu.
6. Mengklik chip Ibu There membuka panel di bawah grid dengan remark **utuh**, tidak terpotong.
7. Panel menampilkan `(jam tunggal)` untuk Ibu There dan `(rentang)` untuk Dharmadi.
8. Memilih PIC lain di filter membuat panel kosong kembali.
9. Berpindah ke Tabel lalu kembali ke Kalender tidak memicu request baru — periksa tab Network browser, tidak boleh ada permintaan XHR.

- [ ] **Step 9: Commit**

```bash
git add resources/js
git commit -m "feat: mode kalender dengan panel detail reservasi"
```

---

## Task 16: Form tambah dan ubah reservasi

**Files:**
- Create: `resources/js/Components/TimeRangeField.jsx`
- Create: `resources/js/Components/PicCombobox.jsx`
- Create: `resources/js/Pages/Reservations/Form.jsx`

**Interfaces:**
- Consumes: props `reservation`, `picOptions`, `areas`, `eventTypes`, `menuStyles` dari Task 13
- Produces:
  - `<TimeRangeField startTime={string} endTime={string|null} onChange={fn} errors={object} />` — memanggil `onChange({ start_time, end_time })`
  - `<PicCombobox value={number|null} onChange={fn} options={array} error={string|undefined} />`

- [ ] **Step 1: Buat TimeRangeField**

`resources/js/Components/TimeRangeField.jsx`:

```jsx
import { useState } from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';

/**
 * Satu input jam mulai, plus checkbox untuk menambahkan jam selesai.
 * Menghilangkan centang mengosongkan end_time menjadi null.
 */
export default function TimeRangeField({ startTime, endTime, onChange, errors }) {
    const [hasEnd, setHasEnd] = useState(Boolean(endTime));

    const toggle = (checked) => {
        setHasEnd(checked);

        if (!checked) {
            onChange({ start_time: startTime, end_time: '' });
        }
    };

    return (
        <div>
            <InputLabel htmlFor="start_time" value="Jam mulai" />

            <div className="mt-1 flex flex-wrap items-center gap-3">
                <TextInput
                    id="start_time"
                    value={startTime}
                    onChange={(e) => onChange({ start_time: e.target.value, end_time: endTime })}
                    placeholder="11.00"
                    className="w-28"
                />

                <label className="flex items-center gap-1.5 text-sm text-gray-600">
                    <input
                        type="checkbox"
                        checked={hasEnd}
                        onChange={(e) => toggle(e.target.checked)}
                        className="rounded border-gray-300"
                    />
                    sampai jam
                </label>

                {hasEnd && (
                    <TextInput
                        id="end_time"
                        value={endTime ?? ''}
                        onChange={(e) => onChange({ start_time: startTime, end_time: e.target.value })}
                        placeholder="15.00"
                        className="w-28"
                    />
                )}
            </div>

            <p className="mt-1 text-xs text-gray-500">
                Boleh ditulis 11, 11.00, atau 11:00. Menulis 12.00-15.00 di kolom jam mulai
                akan otomatis terpecah menjadi dua.
            </p>

            <InputError message={errors.start_time} className="mt-1" />
            <InputError message={errors.end_time} className="mt-1" />
        </div>
    );
}
```

- [ ] **Step 2: Buat PicCombobox**

`resources/js/Components/PicCombobox.jsx`:

```jsx
import { useState } from 'react';
import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';

export default function PicCombobox({ value, onChange, options, error }) {
    const [query, setQuery] = useState('');

    const selected = options.find((o) => o.id === value) ?? null;

    const filtered =
        query === ''
            ? options
            : options.filter((o) => o.name.toLowerCase().includes(query.toLowerCase()));

    return (
        <div>
            <InputLabel htmlFor="pic_id" value="PIC / Sales" />

            <Combobox value={selected} onChange={(option) => onChange(option?.id ?? null)}>
                <div className="relative mt-1">
                    <ComboboxInput
                        id="pic_id"
                        className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        displayValue={(option) => option?.name ?? ''}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Ketik untuk mencari"
                    />
                    <ComboboxButton className="absolute inset-y-0 right-0 px-2 text-gray-400">
                        ▾
                    </ComboboxButton>

                    <ComboboxOptions className="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg">
                        {filtered.length === 0 && (
                            <div className="px-3 py-2 text-sm text-gray-500">Tidak ditemukan.</div>
                        )}

                        {filtered.map((option) => (
                            <ComboboxOption
                                key={option.id}
                                value={option}
                                className="cursor-pointer px-3 py-1.5 text-sm data-[focus]:bg-gray-100"
                            >
                                {option.name}
                            </ComboboxOption>
                        ))}
                    </ComboboxOptions>
                </div>
            </Combobox>

            <InputError message={error} className="mt-1" />
        </div>
    );
}
```

- [ ] **Step 3: Buat halaman Form**

`resources/js/Pages/Reservations/Form.jsx`:

```jsx
import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PicCombobox from '@/Components/PicCombobox';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import TimeRangeField from '@/Components/TimeRangeField';

function Select({ id, label, value, onChange, options, error }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <select
                id={id}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
                className="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option value="">—</option>
                {options.map((o) => (
                    <option key={o.id} value={o.id}>
                        {o.name}
                    </option>
                ))}
            </select>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

export default function Form({ reservation, picOptions, areas, eventTypes, menuStyles }) {
    const { auth } = usePage().props;
    const isEdit = Boolean(reservation);

    // UUID dibuat sekali saat halaman dibuka, bukan setiap render.
    const [idempotencyKey] = useState(() => crypto.randomUUID());

    const { data, setData, post, put, processing, errors } = useForm({
        reservation_date: reservation?.reservation_date ?? '',
        guest_name: reservation?.guest_name ?? '',
        company: reservation?.company ?? '',
        phone: reservation?.phone ?? '',
        email: reservation?.email ?? '',
        pic_id: reservation?.pic_id ?? null,
        event_type_id: reservation?.event_type_id ?? null,
        menu_style_id: reservation?.menu_style_id ?? null,
        area_id: reservation?.area_id ?? null,
        start_time: reservation?.start_time ?? '',
        end_time: reservation?.end_time ?? '',
        pax: reservation?.pax ?? '',
        status: reservation?.status ?? '',
        remark: reservation?.remark ?? '',
        version: reservation?.version ?? 1,
        idempotency_key: idempotencyKey,
    });

    const submit = (e) => {
        e.preventDefault();

        if (isEdit) {
            put(route('reservations.update', reservation.id));
        } else {
            post(route('reservations.store'));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold text-gray-800">
                    {isEdit ? 'Ubah reservasi' : 'Tambah reservasi'}
                </h2>
            }
        >
            <Head title={isEdit ? 'Ubah reservasi' : 'Tambah reservasi'} />

            <form onSubmit={submit} className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
                {errors.version && (
                    <div className="mb-4 rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">
                        {errors.version}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-4 rounded border border-gray-200 bg-white p-5 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="reservation_date" value="Tanggal" />
                        <TextInput
                            id="reservation_date"
                            type="date"
                            value={data.reservation_date}
                            onChange={(e) => setData('reservation_date', e.target.value)}
                            className="mt-1 w-full"
                        />
                        <InputError message={errors.reservation_date} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="guest_name" value="Nama tamu" />
                        <TextInput
                            id="guest_name"
                            value={data.guest_name}
                            onChange={(e) => setData('guest_name', e.target.value)}
                            className="mt-1 w-full"
                        />
                        <InputError message={errors.guest_name} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="company" value="Company (opsional)" />
                        <TextInput
                            id="company"
                            value={data.company ?? ''}
                            onChange={(e) => setData('company', e.target.value)}
                            className="mt-1 w-full"
                        />
                        <InputError message={errors.company} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="phone" value="HP" />
                        <TextInput
                            id="phone"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            placeholder="0812 3456 7890"
                            className="mt-1 w-full"
                        />
                        <InputError message={errors.phone} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="email" value="Email (opsional)" />
                        <TextInput
                            id="email"
                            type="email"
                            value={data.email ?? ''}
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1 w-full"
                        />
                        <InputError message={errors.email} className="mt-1" />
                    </div>

                    <PicCombobox
                        value={data.pic_id}
                        onChange={(id) => setData('pic_id', id)}
                        options={picOptions}
                        error={errors.pic_id}
                    />

                    <Select
                        id="event_type_id"
                        label="Event (opsional)"
                        value={data.event_type_id}
                        onChange={(v) => setData('event_type_id', v)}
                        options={eventTypes}
                        error={errors.event_type_id}
                    />

                    <Select
                        id="menu_style_id"
                        label="Menu style (opsional)"
                        value={data.menu_style_id}
                        onChange={(v) => setData('menu_style_id', v)}
                        options={menuStyles}
                        error={errors.menu_style_id}
                    />

                    <Select
                        id="area_id"
                        label="Area (opsional)"
                        value={data.area_id}
                        onChange={(v) => setData('area_id', v)}
                        options={areas}
                        error={errors.area_id}
                    />

                    <div>
                        <InputLabel htmlFor="pax" value="Pax" />
                        <TextInput
                            id="pax"
                            type="number"
                            min="1"
                            value={data.pax}
                            onChange={(e) => setData('pax', e.target.value)}
                            className="mt-1 w-full"
                        />
                        <InputError message={errors.pax} className="mt-1" />
                    </div>

                    <div className="sm:col-span-2">
                        <TimeRangeField
                            startTime={data.start_time}
                            endTime={data.end_time}
                            onChange={({ start_time, end_time }) => {
                                setData((previous) => ({ ...previous, start_time, end_time }));
                            }}
                            errors={errors}
                        />
                    </div>

                    <div>
                        <InputLabel htmlFor="status" value="Status (opsional)" />
                        <select
                            id="status"
                            value={data.status ?? ''}
                            onChange={(e) => setData('status', e.target.value)}
                            className="mt-1 w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="">Belum ditentukan</option>
                            <option value="tentative">TENTATIVE</option>
                            <option value="confirmed" disabled={!auth.user.is_admin}>
                                CONFIRMED {auth.user.is_admin ? '' : '(hanya admin)'}
                            </option>
                        </select>
                        <InputError message={errors.status} className="mt-1" />
                    </div>

                    <div className="sm:col-span-2">
                        <InputLabel htmlFor="remark" value="Remark (opsional)" />
                        <textarea
                            id="remark"
                            rows={4}
                            value={data.remark ?? ''}
                            onChange={(e) => setData('remark', e.target.value)}
                            className="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <InputError message={errors.remark} className="mt-1" />
                    </div>
                </div>

                <div className="mt-4 flex items-center gap-3">
                    <PrimaryButton disabled={processing}>
                        {processing ? 'Menyimpan…' : 'Simpan'}
                    </PrimaryButton>
                    <Link
                        href={route('reservations.index')}
                        className="text-sm text-gray-600 hover:underline"
                    >
                        Batal
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
```

`processing` menonaktifkan tombol Simpan saat request berjalan. Ini lapis pertama
terhadap klik ganda dan **tidak dipercaya** — jaminan sebenarnya ada pada
`idempotency_key` di server.

- [ ] **Step 4: Verifikasi build**

Run: `npm run build`
Expected: selesai tanpa error.

- [ ] **Step 5: Periksa di browser sebagai admin**

1. Buka `/reservations/create`. Isi tanggal, nama `Uji Coba`, HP `0812-3456-7890`, PIC, Pax `4`, jam mulai `11.00`. Simpan.
2. Halaman detail terbuka dan menampilkan jam `11:00` tanpa tanda hubung.
3. Buka lagi `/reservations/create`, isi jam mulai dengan `12.00-15.00` saja, nama `Uji Rentang`. Simpan. Detail menampilkan `12:00–15:00`.
4. Buat reservasi dengan tanggal, nama, dan jam mulai persis sama dengan nomor 1. Muncul pesan error di bawah kolom nama yang menyebut nama dan tanggal reservasi yang sudah ada. Jumlah reservasi tidak bertambah.
5. Pada form tambah, centang "sampai jam" lalu hilangkan centangnya, kemudian simpan. `end_time` tersimpan kosong.
6. Buka form ubah salah satu reservasi. Di tab lain, ubah reservasi yang sama lalu simpan. Kembali ke tab pertama dan simpan. Muncul banner merah "Reservasi ini baru saja diubah orang lain". Nilai di database tetap versi dari tab kedua.

- [ ] **Step 6: Periksa sebagai staf**

Login sebagai user dengan role `staff`. Pada form, opsi CONFIRMED di dropdown status
harus dalam keadaan disabled dan diberi keterangan "(hanya admin)".

- [ ] **Step 7: Commit**

```bash
git add resources/js
git commit -m "feat: form tambah dan ubah reservasi"
```

---

## Task 17: Halaman detail dan riwayat perubahan

**Files:**
- Create: `resources/js/Utils/fieldLabels.js`
- Create: `resources/js/Components/AuditTimeline.jsx`
- Create: `resources/js/Pages/Reservations/Show.jsx`

**Interfaces:**
- Consumes: props `reservation` dan `activities` dari Task 13
- Produces:
  - `FIELD_LABELS` — objek pemetaan nama kolom ke label Indonesia
  - `<AuditTimeline activities={array} />`

Perubahan remark ditampilkan **utuh** sebagai dua blok bertumpuk, nilai lama dicoret
di atas dan nilai baru di bawah. Field lain ditampilkan dalam satu baris.

- [ ] **Step 1: Buat pemetaan label**

`resources/js/Utils/fieldLabels.js`:

```js
export const FIELD_LABELS = {
    reservation_date: 'Tanggal',
    guest_name: 'Nama tamu',
    company: 'Company',
    phone: 'HP',
    email: 'Email',
    pic_id: 'PIC',
    event_type_id: 'Event',
    menu_style_id: 'Menu style',
    area_id: 'Area',
    start_time: 'Jam mulai',
    end_time: 'Jam selesai',
    pax: 'Pax',
    status: 'Status',
    remark: 'Remark',
};

export function labelFor(field) {
    return FIELD_LABELS[field] ?? field;
}

/** Field yang isinya bisa panjang dan harus ditampilkan sebagai blok. */
export const LONG_TEXT_FIELDS = ['remark'];
```

- [ ] **Step 2: Buat AuditTimeline**

`resources/js/Components/AuditTimeline.jsx`:

```jsx
import { labelFor, LONG_TEXT_FIELDS } from '@/Utils/fieldLabels';

const EVENT_LABELS = {
    created: 'membuat reservasi',
    updated: 'mengubah',
    deleted: 'menghapus reservasi',
};

function Value({ children }) {
    if (children === null || children === undefined || children === '') {
        return <span className="text-gray-400">kosong</span>;
    }

    return <span>{String(children)}</span>;
}

function Change({ change }) {
    if (LONG_TEXT_FIELDS.includes(change.field)) {
        return (
            <li className="mt-1">
                <div className="text-xs font-semibold text-gray-700">{labelFor(change.field)}</div>
                <div className="mt-0.5 whitespace-pre-line text-xs text-gray-400 line-through">
                    <Value>{change.old}</Value>
                </div>
                <div className="mt-0.5 whitespace-pre-line text-xs text-gray-800">
                    <Value>{change.new}</Value>
                </div>
            </li>
        );
    }

    return (
        <li className="text-xs text-gray-700">
            {labelFor(change.field)}: <Value>{change.old}</Value>{' '}
            <span className="text-gray-400">→</span> <Value>{change.new}</Value>
        </li>
    );
}

export default function AuditTimeline({ activities }) {
    if (activities.length === 0) {
        return <p className="text-sm text-gray-500">Belum ada riwayat perubahan.</p>;
    }

    return (
        <ol className="space-y-3">
            {activities.map((activity) => (
                <li key={activity.id} className="border-l-2 border-gray-200 pl-3">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="text-sm font-semibold text-gray-900">{activity.causer}</span>
                        <span className="text-sm text-gray-600">
                            {EVENT_LABELS[activity.event] ?? activity.event}
                        </span>
                        <span className="ml-auto text-xs text-gray-400">{activity.created_at}</span>
                    </div>

                    {activity.event === 'updated' && (
                        <ul className="mt-1 space-y-0.5">
                            {activity.changes.map((change) => (
                                <Change key={change.field} change={change} />
                            ))}
                        </ul>
                    )}
                </li>
            ))}
        </ol>
    );
}
```

- [ ] **Step 3: Buat halaman Show**

`resources/js/Pages/Reservations/Show.jsx`:

```jsx
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import AuditTimeline from '@/Components/AuditTimeline';
import StatusBadge from '@/Components/StatusBadge';
import { formatTimeRange, isRange } from '@/Utils/formatTimeRange';

function Field({ label, value }) {
    return (
        <div>
            <dt className="text-[9px] font-semibold uppercase tracking-wider text-gray-400">
                {label}
            </dt>
            <dd className="text-sm text-gray-900">
                {value || <span className="text-gray-400">—</span>}
            </dd>
        </div>
    );
}

export default function Show({ reservation, activities }) {
    const { auth, flash } = usePage().props;

    const destroy = () => {
        if (window.confirm(`Hapus reservasi atas nama ${reservation.guest_name}?`)) {
            router.delete(route('reservations.destroy', reservation.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">Detail reservasi</h2>}
        >
            <Head title={reservation.guest_name} />

            <div className="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="mb-4 rounded border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                {flash?.warnings?.length > 0 && (
                    <div className="mb-4 rounded border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                        <ul className="list-inside list-disc">
                            {flash.warnings.map((warning, index) => (
                                <li key={index}>{warning}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="rounded border border-gray-200 bg-white p-5">
                    <div className="mb-4 flex flex-wrap items-start gap-3">
                        <div>
                            <h1 className="text-lg font-bold">{reservation.guest_name}</h1>
                            <p className="text-sm text-gray-500">
                                {reservation.reservation_date} ·{' '}
                                {formatTimeRange(reservation.start_time, reservation.end_time)}{' '}
                                {isRange(reservation.end_time) ? '(rentang)' : '(jam tunggal)'}
                            </p>
                        </div>

                        <div className="ml-auto flex gap-2">
                            <Link
                                href={route('reservations.edit', reservation.id)}
                                className="rounded border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50"
                            >
                                Ubah
                            </Link>
                            {auth.user.is_admin && (
                                <button
                                    type="button"
                                    onClick={destroy}
                                    className="rounded border border-red-300 px-3 py-1.5 text-sm text-red-700 hover:bg-red-50"
                                >
                                    Hapus
                                </button>
                            )}
                        </div>
                    </div>

                    <dl className="mb-4 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
                        <Field label="Company" value={reservation.company} />
                        <Field label="HP" value={reservation.phone} />
                        <Field label="Email" value={reservation.email} />
                        <Field label="PIC / Sales" value={reservation.pic} />
                        <Field label="Event" value={reservation.event_type} />
                        <Field label="Menu style" value={reservation.menu_style} />
                        <Field label="Area" value={reservation.area} />
                        <Field label="Pax" value={String(reservation.pax)} />
                        <Field label="Status" value={<StatusBadge status={reservation.status} />} />
                    </dl>

                    <div>
                        <h2 className="mb-1 text-[9px] font-semibold uppercase tracking-wider text-gray-400">
                            Remark
                        </h2>
                        {reservation.remark ? (
                            <div className="border-l-2 border-amber-500 pl-3">
                                <p className="whitespace-pre-line text-sm leading-relaxed text-gray-700">
                                    {reservation.remark}
                                </p>
                            </div>
                        ) : (
                            <p className="text-sm text-gray-400">Tidak ada remark.</p>
                        )}
                    </div>
                </div>

                <div className="mt-5 rounded border border-gray-200 bg-white p-5">
                    <h2 className="mb-3 text-sm font-bold text-gray-800">Riwayat perubahan</h2>
                    <AuditTimeline activities={activities} />
                </div>

                <div className="mt-4">
                    <Link
                        href={route('reservations.index', {
                            month: reservation.reservation_date.slice(0, 7),
                        })}
                        className="text-sm text-gray-600 hover:underline"
                    >
                        ← Kembali ke daftar
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

Tautan kembali membawa `month` dari tanggal reservasi, sehingga pengguna kembali ke
bulan yang sedang mereka lihat, bukan ke bulan berjalan.

- [ ] **Step 4: Verifikasi build**

Run: `npm run build`
Expected: selesai tanpa error.

- [ ] **Step 5: Periksa di browser**

1. Buka detail salah satu reservasi. Remark tampil utuh dengan garis aksen di kiri.
2. Ubah Pax reservasi tersebut lalu simpan. Kembali ke detail — timeline menampilkan `Pax: 4 → 8` dengan nama pengubah dan waktunya.
3. Ubah remark menjadi teks dua baris. Timeline menampilkan nilai lama tercoret di atas dan nilai baru di bawah, keduanya utuh dan mempertahankan baris baru.
4. Sebagai staf, tombol Hapus tidak muncul. Sebagai admin, tombol muncul dan meminta konfirmasi.
5. Kosongkan Company lalu simpan. Timeline menampilkan `Company: <nilai lama> → kosong`.

- [ ] **Step 6: Commit**

```bash
git add resources/js
git commit -m "feat: halaman detail reservasi dengan riwayat perubahan"
```

---

## Task 18: CRUD master dan pengguna

**Files:**
- Create: `app/Http/Requests/MasterItemRequest.php`
- Create: `app/Http/Controllers/Master/MasterController.php`
- Create: `app/Http/Controllers/Master/AreaController.php`
- Create: `app/Http/Controllers/Master/EventTypeController.php`
- Create: `app/Http/Controllers/Master/MenuStyleController.php`
- Create: `app/Http/Controllers/UserController.php`
- Create: `app/Http/Requests/StoreUserRequest.php`
- Create: `app/Http/Requests/UpdateUserRequest.php`
- Create: `resources/js/Pages/Master/SimpleMasterPage.jsx`
- Create: `resources/js/Pages/Users/Index.jsx`
- Modify: `routes/web.php`
- Modify: `resources/js/Layouts/AuthenticatedLayout.jsx`
- Test: `tests/Feature/MasterCrudTest.php`
- Test: `tests/Feature/UserCrudTest.php`

**Interfaces:**
- Consumes: middleware `admin` (Task 11), model master (Task 3)
- Produces: route `master.areas.*`, `master.event-types.*`, `master.menu-styles.*`, `users.*`

Ketiga master punya struktur kolom identik, sehingga logikanya dikumpulkan di satu
kelas abstrak dan satu komponen React yang dipakai bersama. Menyalin kode yang sama
tiga kali akan membuat perbaikan bug harus dilakukan tiga kali.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/MasterCrudTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_open_master_pages(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('master.areas.index'))
            ->assertForbidden();
    }

    public function test_admin_can_open_master_pages(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('master.areas.index'))
            ->assertOk();
    }

    public function test_admin_can_add_an_area(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('master.areas.store'), ['name' => 'VIP 3', 'sort_order' => 8])
            ->assertRedirect();

        $this->assertDatabaseHas('areas', ['name' => 'VIP 3', 'is_active' => true]);
    }

    public function test_duplicate_master_name_is_rejected(): void
    {
        Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('master.areas.store'), ['name' => 'VIP 1', 'sort_order' => 2])
            ->assertSessionHasErrors('name');
    }

    public function test_admin_can_deactivate_an_area(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('master.areas.update', $area->id), [
                'name' => 'VIP 1',
                'sort_order' => 1,
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($area->fresh()->is_active);
    }

    public function test_area_in_use_cannot_be_deleted(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);
        Reservation::factory()->create(['area_id' => $area->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('master.areas.destroy', $area->id))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('areas', ['id' => $area->id]);
    }

    public function test_unused_area_can_be_deleted(): void
    {
        $area = Area::create(['name' => 'VIP 1', 'sort_order' => 1]);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('master.areas.destroy', $area->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('areas', ['id' => $area->id]);
    }
}
```

`tests/Feature/UserCrudTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_open_user_management(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('users.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_a_staff_account(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('users.store'), [
                'name' => 'CASSIE',
                'email' => 'cassie@umara.test',
                'password' => 'rahasia123',
                'role' => 'staff',
            ])
            ->assertRedirect();

        $user = User::where('email', 'cassie@umara.test')->sole();

        $this->assertSame('CASSIE', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
    }

    public function test_password_is_optional_when_updating(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create(['name' => 'ARIF']);
        $original = $staff->password;

        $this->actingAs($admin)
            ->put(route('users.update', $staff), [
                'name' => 'ARIF SETIAWAN',
                'email' => $staff->email,
                'role' => 'staff',
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertSame('ARIF SETIAWAN', $staff->fresh()->name);
        $this->assertSame($original, $staff->fresh()->password);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'admin',
                'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter="MasterCrudTest|UserCrudTest"`
Expected: FAIL dengan "Route [master.areas.index] not defined."

- [ ] **Step 3: Buat MasterItemRequest**

`app/Http/Requests/MasterItemRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MasterItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Middleware admin sudah menjaga route ini.
    }

    public function rules(): array
    {
        $table = $this->route()->parameter('table');
        $id = $this->route()->parameter('item');

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique($table, 'name')->ignore($id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name'))
                ? mb_strtoupper(trim($this->input('name')))
                : $this->input('name'),
        ]);
    }
}
```

Nama master diseragamkan menjadi huruf kapital agar konsisten dengan data
operasional dan agar `TEST FOOD` tidak berdampingan dengan `Test Food`.

- [ ] **Step 4: Buat controller master**

`app/Http/Controllers/Master/MasterController.php`:

```php
<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterItemRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

abstract class MasterController extends Controller
{
    private const FOREIGN_KEY_ERROR = 1451;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function title(): string;

    /** Prefix nama route, misalnya master.areas */
    abstract protected function routePrefix(): string;

    public function index(): Response
    {
        return Inertia::render('Master/SimpleMasterPage', [
            'title' => $this->title(),
            'routePrefix' => $this->routePrefix(),
            'items' => $this->modelClass()::orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(MasterItemRequest $request): RedirectResponse
    {
        $this->modelClass()::create([
            'name' => $request->validated('name'),
            'sort_order' => $request->validated('sort_order') ?? 0,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Data ditambahkan.');
    }

    public function update(MasterItemRequest $request, string $table, int $item): RedirectResponse
    {
        $model = $this->modelClass()::findOrFail($item);

        $model->update([
            'name' => $request->validated('name'),
            'sort_order' => $request->validated('sort_order') ?? 0,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Data diperbarui.');
    }

    public function destroy(string $table, int $item): RedirectResponse
    {
        $model = $this->modelClass()::findOrFail($item);

        try {
            $model->delete();
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === self::FOREIGN_KEY_ERROR) {
                throw ValidationException::withMessages([
                    'name' => sprintf(
                        '"%s" tidak bisa dihapus karena sudah dipakai reservasi. '
                        . 'Nonaktifkan saja lewat kolom Aktif.',
                        $model->name
                    ),
                ]);
            }

            throw $e;
        }

        return back()->with('success', 'Data dihapus.');
    }
}
```

`update` dan `destroy` menerima `$table` sebagai parameter pertama karena route
menyertakannya untuk keperluan aturan `unique` di `MasterItemRequest`.

`app/Http/Controllers/Master/AreaController.php`:

```php
<?php

namespace App\Http\Controllers\Master;

use App\Models\Area;

class AreaController extends MasterController
{
    protected function modelClass(): string
    {
        return Area::class;
    }

    protected function title(): string
    {
        return 'Area';
    }

    protected function routePrefix(): string
    {
        return 'master.areas';
    }
}
```

`app/Http/Controllers/Master/EventTypeController.php`:

```php
<?php

namespace App\Http\Controllers\Master;

use App\Models\EventType;

class EventTypeController extends MasterController
{
    protected function modelClass(): string
    {
        return EventType::class;
    }

    protected function title(): string
    {
        return 'Event';
    }

    protected function routePrefix(): string
    {
        return 'master.event-types';
    }
}
```

`app/Http/Controllers/Master/MenuStyleController.php`:

```php
<?php

namespace App\Http\Controllers\Master;

use App\Models\MenuStyle;

class MenuStyleController extends MasterController
{
    protected function modelClass(): string
    {
        return MenuStyle::class;
    }

    protected function title(): string
    {
        return 'Menu style';
    }

    protected function routePrefix(): string
    {
        return 'master.menu-styles';
    }
}
```

- [ ] **Step 5: Buat request dan controller pengguna**

`app/Http/Requests/StoreUserRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
```

`app/Http/Requests/UpdateUserRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', Password::min(8)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $isSelf = $this->route('user')->is($this->user());

            if ($isSelf && ! $this->boolean('is_active')) {
                $validator->errors()->add(
                    'is_active',
                    'Anda tidak bisa menonaktifkan akun sendiri.'
                );
            }
        });
    }
}
```

Aturan tambahan ini mencegah admin terakhir mengunci dirinya sendiri keluar dari
halaman pengelolaan pengguna.

`app/Http/Controllers/UserController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        User::create([
            ...$request->safe()->except('password'),
            'password' => Hash::make($request->validated('password')),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Pengguna ditambahkan.');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->except('password'));
        $user->is_active = $request->boolean('is_active');

        if ($request->filled('password')) {
            $user->password = Hash::make($request->validated('password'));
        }

        $user->save();

        return back()->with('success', 'Pengguna diperbarui.');
    }
}
```

Pengguna sengaja tidak bisa dihapus. `reservations.pic_id` dan `created_by` memakai
`restrictOnDelete`, jadi menghapus pengguna yang pernah menangani reservasi akan
selalu gagal. Menonaktifkan lewat `is_active` adalah jalur yang benar.

- [ ] **Step 6: Daftarkan route**

Di `routes/web.php`, tambahkan sebelum baris `require __DIR__.'/auth.php';`:

```php
use App\Http\Controllers\Master\AreaController;
use App\Http\Controllers\Master\EventTypeController;
use App\Http\Controllers\Master\MenuStyleController;
use App\Http\Controllers\UserController;

Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    $masters = [
        ['areas', AreaController::class, 'master.areas'],
        ['event_types', EventTypeController::class, 'master.event-types'],
        ['menu_styles', MenuStyleController::class, 'master.menu-styles'],
    ];

    foreach ($masters as [$table, $controller, $name]) {
        $slug = str_replace('_', '-', $table);

        Route::get("/master/{$slug}", [$controller, 'index'])->name("{$name}.index");
        Route::post("/master/{$slug}", [$controller, 'store'])
            ->defaults('table', $table)
            ->name("{$name}.store");
        Route::put("/master/{$slug}/{item}", [$controller, 'update'])
            ->defaults('table', $table)
            ->name("{$name}.update");
        Route::delete("/master/{$slug}/{item}", [$controller, 'destroy'])
            ->defaults('table', $table)
            ->name("{$name}.destroy");
    }

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
});
```

`->defaults('table', $table)` menyisipkan nama tabel ke parameter route tanpa
memunculkannya di URL. `MasterItemRequest` membacanya untuk menyusun aturan `unique`.

- [ ] **Step 7: Buat halaman master React**

`resources/js/Pages/Master/SimpleMasterPage.jsx`:

```jsx
import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';

export default function SimpleMasterPage({ title, routePrefix, items }) {
    const { flash } = usePage().props;
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        sort_order: 0,
        is_active: true,
    });

    const startEdit = (item) => {
        setEditing(item.id);
        setData({ name: item.name, sort_order: item.sort_order, is_active: item.is_active });
    };

    const cancel = () => {
        setEditing(null);
        reset();
    };

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route(`${routePrefix}.update`, editing), { onSuccess: cancel });
        } else {
            post(route(`${routePrefix}.store`), { onSuccess: () => reset() });
        }
    };

    const remove = (item) => {
        if (window.confirm(`Hapus "${item.name}"?`)) {
            router.delete(route(`${routePrefix}.destroy`, item.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">Master {title}</h2>}
        >
            <Head title={`Master ${title}`} />

            <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="mb-4 rounded border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit} className="mb-5 rounded border border-gray-200 bg-white p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="grow">
                            <TextInput
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder={`Nama ${title.toLowerCase()}`}
                                className="w-full"
                            />
                        </div>

                        <TextInput
                            type="number"
                            min="0"
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', Number(e.target.value))}
                            className="w-20"
                            aria-label="Urutan"
                        />

                        <label className="flex items-center gap-1.5 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="rounded border-gray-300"
                            />
                            Aktif
                        </label>

                        <PrimaryButton disabled={processing}>
                            {editing ? 'Simpan perubahan' : 'Tambah'}
                        </PrimaryButton>

                        {editing && (
                            <button
                                type="button"
                                onClick={cancel}
                                className="text-sm text-gray-600 hover:underline"
                            >
                                Batal
                            </button>
                        )}
                    </div>

                    <InputError message={errors.name} className="mt-2" />
                </form>

                <table className="w-full rounded border border-gray-200 bg-white text-sm">
                    <thead>
                        <tr className="border-b border-gray-200 text-left text-[10px] uppercase tracking-wider text-gray-500">
                            <th className="px-3 py-2 font-semibold">Nama</th>
                            <th className="px-3 py-2 font-semibold">Urutan</th>
                            <th className="px-3 py-2 font-semibold">Aktif</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => (
                            <tr key={item.id} className="border-t border-gray-100">
                                <td className="px-3 py-2">{item.name}</td>
                                <td className="px-3 py-2">{item.sort_order}</td>
                                <td className="px-3 py-2">
                                    {item.is_active ? 'Ya' : <span className="text-gray-400">Tidak</span>}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        onClick={() => startEdit(item)}
                                        className="mr-3 text-xs text-gray-600 hover:underline"
                                    >
                                        Ubah
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => remove(item)}
                                        className="text-xs text-red-600 hover:underline"
                                    >
                                        Hapus
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 8: Buat halaman pengguna React**

`resources/js/Pages/Users/Index.jsx`:

```jsx
import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';

export default function Index({ users }) {
    const { flash } = usePage().props;
    const [editing, setEditing] = useState(null);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        role: 'staff',
        is_active: true,
    });

    const startEdit = (user) => {
        setEditing(user.id);
        setData({
            name: user.name,
            email: user.email,
            password: '',
            role: user.role,
            is_active: user.is_active,
        });
    };

    const cancel = () => {
        setEditing(null);
        reset();
    };

    const submit = (e) => {
        e.preventDefault();

        if (editing) {
            put(route('users.update', editing), { onSuccess: cancel });
        } else {
            post(route('users.store'), { onSuccess: () => reset() });
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">Pengguna</h2>}
        >
            <Head title="Pengguna" />

            <div className="mx-auto max-w-4xl px-4 py-6 sm:px-6 lg:px-8">
                {flash?.success && (
                    <div className="mb-4 rounded border border-emerald-300 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}

                <form onSubmit={submit} className="mb-5 grid grid-cols-1 gap-3 rounded border border-gray-200 bg-white p-4 sm:grid-cols-5">
                    <div>
                        <TextInput
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Nama"
                            className="w-full"
                        />
                        <InputError message={errors.name} className="mt-1" />
                    </div>

                    <div>
                        <TextInput
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="Email"
                            className="w-full"
                        />
                        <InputError message={errors.email} className="mt-1" />
                    </div>

                    <div>
                        <TextInput
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder={editing ? 'Kosongkan jika tetap' : 'Password'}
                            className="w-full"
                        />
                        <InputError message={errors.password} className="mt-1" />
                    </div>

                    <div>
                        <select
                            value={data.role}
                            onChange={(e) => setData('role', e.target.value)}
                            className="w-full rounded-md border-gray-300 shadow-sm"
                        >
                            <option value="staff">Staf</option>
                            <option value="admin">Admin</option>
                        </select>
                        <InputError message={errors.role} className="mt-1" />
                    </div>

                    <div className="flex items-center gap-3">
                        <label className="flex items-center gap-1.5 text-sm text-gray-600">
                            <input
                                type="checkbox"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="rounded border-gray-300"
                            />
                            Aktif
                        </label>
                        <PrimaryButton disabled={processing}>
                            {editing ? 'Simpan' : 'Tambah'}
                        </PrimaryButton>
                        {editing && (
                            <button type="button" onClick={cancel} className="text-sm text-gray-600 hover:underline">
                                Batal
                            </button>
                        )}
                    </div>

                    <div className="sm:col-span-5">
                        <InputError message={errors.is_active} />
                    </div>
                </form>

                <table className="w-full rounded border border-gray-200 bg-white text-sm">
                    <thead>
                        <tr className="border-b border-gray-200 text-left text-[10px] uppercase tracking-wider text-gray-500">
                            <th className="px-3 py-2 font-semibold">Nama</th>
                            <th className="px-3 py-2 font-semibold">Email</th>
                            <th className="px-3 py-2 font-semibold">Role</th>
                            <th className="px-3 py-2 font-semibold">Aktif</th>
                            <th className="px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {users.map((user) => (
                            <tr key={user.id} className="border-t border-gray-100">
                                <td className="px-3 py-2">{user.name}</td>
                                <td className="px-3 py-2">{user.email}</td>
                                <td className="px-3 py-2">{user.role === 'admin' ? 'Admin' : 'Staf'}</td>
                                <td className="px-3 py-2">
                                    {user.is_active ? 'Ya' : <span className="text-gray-400">Tidak</span>}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    <button
                                        type="button"
                                        onClick={() => startEdit(user)}
                                        className="text-xs text-gray-600 hover:underline"
                                    >
                                        Ubah
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <p className="mt-3 text-xs text-gray-500">
                    Pengguna tidak bisa dihapus karena namanya melekat pada riwayat reservasi.
                    Nonaktifkan saja jika sudah tidak bekerja.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 9: Tambahkan menu navigasi**

Di `resources/js/Layouts/AuthenticatedLayout.jsx`, di dalam blok navigasi desktop
yang sudah berisi `NavLink` Dashboard, ganti tautan Dashboard menjadi Reservasi dan
tambahkan menu admin. Ambil `auth` dari `usePage().props` yang sudah tersedia di file
itu.

```jsx
<NavLink href={route('reservations.index')} active={route().current('reservations.*')}>
    Reservasi
</NavLink>

{auth.user.is_admin && (
    <>
        <NavLink href={route('master.areas.index')} active={route().current('master.areas.*')}>
            Area
        </NavLink>
        <NavLink href={route('master.event-types.index')} active={route().current('master.event-types.*')}>
            Event
        </NavLink>
        <NavLink href={route('master.menu-styles.index')} active={route().current('master.menu-styles.*')}>
            Menu
        </NavLink>
        <NavLink href={route('users.index')} active={route().current('users.*')}>
            Pengguna
        </NavLink>
    </>
)}
```

- [ ] **Step 10: Hapus halaman Dashboard bawaan**

```bash
rm resources/js/Pages/Dashboard.jsx
```

Route `/dashboard` sudah dihapus pada Task 12, sehingga file ini tidak lagi
terjangkau. Periksa juga `resources/js/Pages/Auth/` — jika `route('dashboard')` masih
dipanggil di sana, ganti menjadi `route('reservations.index')`.

Run: `grep -rn "route('dashboard')" resources/js`
Expected: tidak ada hasil. Perbaiki setiap kemunculan yang ditemukan.

- [ ] **Step 11: Jalankan test**

Run: `php artisan test --filter="MasterCrudTest|UserCrudTest"`
Expected: 11 test PASS

- [ ] **Step 12: Jalankan seluruh test dan build**

Run: `php artisan test && npm run build`
Expected: semua PASS dan build hijau.

- [ ] **Step 13: Periksa di browser sebagai admin**

1. Menu Area, Event, Menu, dan Pengguna muncul di navigasi. Sebagai staf, keempatnya tidak muncul.
2. Buka Master Area, tambahkan `vip 3`. Tersimpan sebagai `VIP 3` dalam huruf kapital.
3. Coba tambahkan `VIP 3` lagi — muncul error nama sudah dipakai.
4. Coba hapus area yang sedang dipakai reservasi — muncul pesan yang menyarankan menonaktifkan, bukan error 500.
5. Nonaktifkan satu area, lalu buka form reservasi — area tersebut hilang dari dropdown.
6. Buka Pengguna, tambahkan akun staf baru, lalu login dengan akun itu di jendela penyamaran.
7. Coba nonaktifkan akun admin Anda sendiri — muncul pesan penolakan.

- [ ] **Step 14: Commit**

```bash
git add app resources routes tests
git commit -m "feat: CRUD master dan pengelolaan pengguna"
```

---

## Self-Review

**Cakupan spec.** Setiap bagian spec dipetakan ke task:

| Bagian spec | Task |
|---|---|
| 2.1 Stack, tanpa Filament | 14 (verifikasi build), seluruh task frontend |
| 2.2 Semua di balik login | 12 (grup middleware `auth`) |
| 2.3 Dua peran | 2, 11, 18 |
| 2.4 PIC dari `users` | 2, 4, 13 |
| 2.5 Tanpa migrasi data lama | Tidak ada task import — disengaja |
| 2.6 Satu area per reservasi | 4 (`area_id` FK tunggal) |
| 3.1–3.6 Model data | 2, 3, 4, 5, 7 |
| 3.7 PAX satu integer | 4, 8 |
| 3.8 Jam tunggal dan rentang | 6, 8, 14, 16 |
| 4 Halaman dan rute | 12, 13 |
| 4.1.1 Dua mode tampilan | 14, 15 |
| 4.2 Komponen React | 14, 15, 16, 17 |
| 4.3 Remark selalu penuh | 14 (`RemarkRow`), 15 (panel detail), 17 (timeline) |
| 5 Validasi | 8 |
| 6 Hak akses | 11, 12, 18 |
| 7 Audit trail | 7, 13, 17 |
| 8 Peringatan bentrok | 10, 12 |
| 8.1 Durasi asumsi 2 jam | 1 (config), 10 |
| 9.1–9.6 Race condition | 5, 9, 12 |
| 10 Yang tidak masuk v1 | Tidak ada task — disengaja |
| 11 Testing | Tersebar; 22 poin test spec tercakup |
| 12 Risiko | 1 Step 1 dan 5 Step 4 memverifikasi versi MySQL |

Tidak ditemukan bagian spec tanpa task.

**Placeholder.** Tidak ada "TBD", "TODO", atau instruksi tanpa kode. Setiap step yang
mengubah kode memuat kode lengkapnya.

**Konsistensi tipe.** Diperiksa dan diselaraskan:

- `ReservationPolicy::confirm()` memakai `?Reservation $reservation = null`, sesuai
  pemanggilan `authorize('confirm', Reservation::class)` di Task 12.
- `ReservationWriter::create()` dan `update()` sama-sama menerima `User $actor`
  sebagai parameter terakhir.
- `start_time` dan `end_time` selalu berformat `H:i` di sisi frontend karena
  `toArray()` di Task 13 memotongnya; `formatTimeRange` tidak perlu menangani `H:i:s`.
- `DuplicateReservationException::existing()` mengembalikan `?Reservation`, dan Task 12
  memeriksa null sebelum memakainya.
- Prop `is_admin` dipakai konsisten di Task 16, 17, dan 18; `role` dipakai di Task 18.
- `MasterController::update()` dan `destroy()` menerima `string $table` sebagai
  parameter pertama, sesuai `->defaults('table', $table)` pada route Task 18.

**Satu penyimpangan dari spec yang disengaja.** Spec bagian 8 menuliskan pengecekan
bentrok sebagai satu query SQL. Task 10 menggantinya dengan pengambilan baris kandidat
lalu penyaringan di PHP. Alasannya ada di badan task: jumlah kandidat sangat kecil,
dan versi SQL-nya sulit dibaca serta mudah salah pada kasus `end_time` yang `NULL` —
persis kasus yang paling perlu benar.

---

## Ringkasan urutan pengerjaan

| Task | Deliverable | Bisa diuji sendiri |
|---|---|---|
| 1 | Config, enum, test di MySQL | Ya |
| 2 | Role dan is_active | Ya |
| 3 | Tiga tabel master | Ya |
| 4 | Tabel dan model reservations | Ya |
| 5 | Constraint duplikat | Ya |
| 6 | Parser jam | Ya |
| 7 | Audit trail | Ya |
| 8 | Validasi dan normalisasi | Ya |
| 9 | Idempotency dan optimistic lock | Ya |
| 10 | Deteksi bentrok area | Ya |
| 11 | Policy dan middleware admin | Ya |
| 12 | Controller simpan, ubah, hapus | Ya |
| 13 | Props daftar dan detail | Ya |
| 14 | Mode tabel | Manual |
| 15 | Mode kalender | Manual |
| 16 | Form tambah dan ubah | Manual |
| 17 | Detail dan riwayat | Manual |
| 18 | Master dan pengguna | Ya + manual |

Task 1 sampai 13 adalah backend lengkap dengan test otomatis. Setelah Task 13 selesai,
sistem sudah benar secara fungsional meski belum punya antarmuka. Task 14 sampai 18
membangun antarmukanya.

