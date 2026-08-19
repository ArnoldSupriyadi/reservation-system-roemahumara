# Sistem Reservasi Roemah Umara — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membangun sistem reservasi internal Roemah Umara yang menggantikan spreadsheet, dengan data ternormalisasi, jejak audit per perubahan, dan pencegahan duplikat di level database.

**Architecture:** Laravel 12 monolith dengan **Filament v5** (Livewire v4) sebagai satu-satunya lapisan tampilan. Integritas data dijaga oleh constraint database — UNIQUE pada generated column dan optimistic lock lewat kolom `version` — bukan oleh pengecekan di kode aplikasi. Penulisan reservasi tidak diserahkan ke Filament, melainkan melewati `ReservationWriter` lewat hook `handleRecordCreation()` dan `handleRecordUpdate()`.

**Tech Stack (terverifikasi di mesin pengembang, 10 Agustus 2026):** PHP 8.3.1, Laravel 12.65.0, Filament v5.7.6, Livewire v4.3.5, MySQL 8, PHPUnit 11, `spatie/laravel-activitylog`.

**Spec:** `docs/superpowers/specs/2026-08-10-reservasi-roemah-umara-design.md`

## Global Constraints

- **MySQL 8 wajib**, termasuk untuk menjalankan test. `dedupe_key` memakai generated stored column dengan `IF()`, `CONCAT_WS()`, `DATE_FORMAT()`, `TIME_FORMAT()` — tidak satu pun tersedia di SQLite. Menjalankan test di SQLite akan melewati constraint terpenting dalam sistem ini tanpa memberi tanda apa pun.
- **Filament v5 adalah satu-satunya UI.** Tidak ada Inertia, React, Breeze, atau Ziggy. Panel berada di `/cms`, dan `/` diarahkan ke sana.
- **Autentikasi memakai panel Filament**, bukan Breeze. Tidak ada `routes/auth.php`.
- **Otorisasi memakai Model Policy**, bukan middleware. Filament membaca `viewAny`, `view`, `create`, `update`, `delete`, dan `deleteAny` secara otomatis.
- **Role dan permission memakai `spatie/laravel-permission` v8.** Tidak ada kolom `role` di tabel `users`.
- **Policy WAJIB mengecek permission, bukan role.** Menulis `$user->hasRole('admin')` di dalam Policy membatalkan seluruh alasan memakai spatie — menambah role baru akan tetap memerlukan perubahan kode. Yang benar adalah `$user->can(Ability::DeleteReservation->value)`. Role hanyalah wadah yang memuat permission.
- **Permission dinamai menurut kemampuan bisnis, bukan per-CRUD-per-Resource.** Ada delapan, didefinisikan di `App\Enums\Ability`. Jangan memakai Filament Shield, yang meng-generate sekitar empat puluh permission per-Resource dan memaksa aturan sederhana diekspresikan lewat puluhan checkbox.
- **Nama permission adalah kode, isi role adalah data.** Daftar `Ability` hanya berubah lewat commit. Role dan pemetaannya ke permission boleh diubah lewat UI oleh admin.
- **Tidak ada migrasi data lama.** Database dimulai kosong. Tidak ada command import.
- **Tidak ada pustaka kalender.** Grid bulanan memakai CSS Grid di dalam custom Filament Page.
- **Tidak ada pagination, caching, queue.** Volume ± 15 reservasi per bulan.
- **Remark selalu ditampilkan penuh.** Dilarang memotong teks, menyembunyikan di balik hover, atau di balik tombol. Di tabel, remark memakai `Panel` layout **tanpa** `->collapsible()`. Di kalender, chip menggantinya dengan panel detail.
- **Update model wajib lewat `$model->save()`**, tidak boleh `Model::where(...)->update(...)`. Update massal tidak memicu event Eloquent, sehingga `activity_log` tidak tercatat dan audit trail bolong tanpa error.
- **Optimistic lock memakai kolom `version` (integer)**, bukan `updated_at`. TIMESTAMP MySQL berpresisi detik.
- **Durasi asumsi reservasi tanpa `end_time` adalah 2 jam**, dibaca dari `config('reservation.default_duration_minutes')`.
- **Kunci duplikat:** `reservation_date` + `LOWER(TRIM(guest_name))` + `start_time`.
- Nama tabel, kolom, dan class memakai bahasa Inggris. Label yang terlihat pengguna memakai bahasa Indonesia.

## Catatan API Filament v5

Diverifikasi dari dokumentasi resmi 5.x. Jangan memakai pola dari Filament v3 — banyak yang berubah:

| Hal | v5 |
|---|---|
| Form | `public static function form(Schema $schema): Schema` memakai `Filament\Schemas\Schema` dan `->components([...])` |
| Field | tetap di `Filament\Forms\Components\*` |
| Layout form | `Filament\Schemas\Components\*` |
| Table actions | `Filament\Actions\*`, bukan `Filament\Tables\Actions\*` |
| Method table | `->recordActions([...])` dan `->toolbarActions([...])` |
| Layout tabel | `Filament\Tables\Columns\Layout\{Split, Stack, Grid, Panel}` |
| Struktur berkas | `app/Filament/Resources/<Plural>/` berisi `Pages/`, `Schemas/`, `Tables/` |
| Navigation icon | `protected static string \| BackedEnum \| null $navigationIcon` |
| Navigation group | `protected static string \| UnitEnum \| null $navigationGroup` |

## File Structure

**Konfigurasi & fondasi**

| File | Tanggung jawab |
|---|---|
| `config/reservation.php` | Durasi asumsi untuk cek bentrok |
| `phpunit.xml` | Diubah: koneksi test ke MySQL |
| `app/Enums/Ability.php` | Delapan kemampuan; dipakai Policy dan UI |
| `app/Enums/ReservationStatus.php` | `tentative` / `confirmed` |

**Data layer**

| File | Tanggung jawab |
|---|---|
| `database/migrations/*_add_is_active_to_users_table.php` | Kolom `is_active` saja |
| `database/migrations/*_create_permission_tables.php` | Lima tabel spatie, dari vendor publish |
| `database/migrations/*_create_master_tables.php` | `areas`, `event_types`, `menu_styles` |
| `database/migrations/*_create_reservations_table.php` | Tabel utama |
| `database/migrations/*_add_dedupe_key_to_reservations_table.php` | Generated column + UNIQUE |
| `app/Models/User.php` | Diubah: trait `HasRoles`, `is_active`, scope `active()` |
| `app/Models/{Area,EventType,MenuStyle}.php` | Master, struktur identik |
| `app/Models/Reservation.php` | Model utama + `LogsActivity` |
| `database/factories/ReservationFactory.php` | Data test |
| `database/seeders/RolePermissionSeeder.php` | Permission dari `Ability`, role `admin` dan `staff` |
| `database/seeders/MasterSeeder.php` | Isi awal master |

**Tidak ada kolom `role` dan tidak ada `User::isAdmin()`.** Role disimpan spatie di
`model_has_roles`, dan Policy mengecek `Ability`, bukan nama role.

**Domain logic — dipisah dari Filament agar bisa diuji tanpa Livewire**

| File | Tanggung jawab |
|---|---|
| `app/Support/TimeInput.php` | Parsing `11`, `11.00`, `11:00`, `12.00-15.00` |
| `app/Services/ConflictChecker.php` | Deteksi tumpang tindih area |
| `app/Services/ReservationWriter.php` | Transaksi, optimistic lock, idempotency |

**Otorisasi**

| File | Tanggung jawab |
|---|---|
| `app/Policies/ReservationPolicy.php` | Hak akses per aksi, dibaca Filament otomatis |
| `app/Policies/MasterPolicy.php` | Basis policy untuk tiga master, admin saja |
| `app/Policies/UserPolicy.php` | Admin saja |

**Filament**

| File | Tanggung jawab |
|---|---|
| `app/Providers/Filament/CmsPanelProvider.php` | Diubah: judul, warna, urutan navigasi |
| `app/Filament/Resources/Reservations/ReservationResource.php` | Titik masuk resource |
| `app/Filament/Resources/Reservations/Schemas/ReservationForm.php` | Skema form |
| `app/Filament/Resources/Reservations/Schemas/ReservationInfolist.php` | Skema halaman View |
| `app/Filament/Resources/Reservations/Tables/ReservationsTable.php` | Kolom, filter, aksi, `Panel` remark |
| `app/Filament/Resources/Reservations/Pages/ListReservations.php` | Daftar |
| `app/Filament/Resources/Reservations/Pages/CreateReservation.php` | Override `handleRecordCreation()` |
| `app/Filament/Resources/Reservations/Pages/EditReservation.php` | Override `handleRecordUpdate()` |
| `app/Filament/Resources/Reservations/Pages/ViewReservation.php` | Detail + riwayat |
| `app/Filament/Pages/ReservationCalendar.php` | Custom page kalender |
| `resources/views/filament/pages/reservation-calendar.blade.php` | Grid bulanan CSS Grid |
| `resources/views/filament/audit-timeline.blade.php` | Riwayat perubahan |
| `app/Filament/Resources/Areas/*` | Simple resource |
| `app/Filament/Resources/EventTypes/*` | Simple resource |
| `app/Filament/Resources/MenuStyles/*` | Simple resource |
| `app/Filament/Resources/Users/*` | Resource pengguna |

**Alasan `TimeInput`, `ReservationInput`, `ConflictChecker`, dan `ReservationWriter` berada di luar Filament:** keempatnya berisi logika yang paling mudah salah dan paling perlu diuji berulang. Menaruhnya di dalam Resource memaksa setiap test melewati Livewire, yang membuat test lambat dan pesan kegagalannya kabur. Dengan dipisah, Task 1 sampai 11 bisa diselesaikan dan diuji sepenuhnya sebelum satu baris UI dibuat.

---

## Task 0: Bersihkan Breeze dan siapkan panel Filament

**Files:**
- Delete: `resources/js/`, `resources/views/app.blade.php`, `routes/auth.php`, `app/Http/Controllers/ProfileController.php`, `app/Http/Requests/ProfileUpdateRequest.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/View/Components/AppLayout.php`, `app/View/Components/GuestLayout.php`
- Modify: `composer.json`, `package.json`, `routes/web.php`, `bootstrap/app.php`, `vite.config.js`
- Modify: `app/Providers/Filament/CmsPanelProvider.php`

**Interfaces:**
- Consumes: tidak ada
- Produces: aplikasi yang hanya punya satu sistem autentikasi, yaitu panel Filament di `/cms`

Kondisi awal yang sudah diverifikasi: `resources/js`, `routes/auth.php`, dan `resources/js/Pages` semuanya ada; `composer.json` masih memuat `inertiajs/inertia-laravel`, `tightenco/ziggy`, dan `laravel/breeze`; `package.json` masih memuat React dan Inertia. Filament sudah terpasang lewat `filament:install --panels`.

Membiarkan Breeze berdampingan dengan Filament menghasilkan **dua halaman login** yang memakai guard `web` yang sama — `/login` dan `/cms/login`. Ini tidak langsung menimbulkan error, sehingga mudah lolos ke produksi dan baru terasa saat ada yang bingung harus login di mana.

- [ ] **Step 1: Buat cabang kerja**

```bash
git checkout -b feat/filament-reservation
git status
```

Expected: working tree bersih selain instalasi Filament yang sudah dilakukan. Commit dulu instalasi Filament jika belum.

- [ ] **Step 2: Hapus paket Breeze, Inertia, dan Ziggy**

```bash
composer remove laravel/breeze inertiajs/inertia-laravel tightenco/ziggy
npm remove @inertiajs/react @headlessui/react @vitejs/plugin-react react react-dom @tailwindcss/forms
```

- [ ] **Step 3: Hapus berkas scaffolding**

```bash
rm -rf resources/js
rm -f resources/views/app.blade.php
rm -f routes/auth.php
rm -f app/Http/Controllers/ProfileController.php
rm -f app/Http/Requests/ProfileUpdateRequest.php
rm -f app/Http/Middleware/HandleInertiaRequests.php
rm -rf app/View/Components
rm -rf tests/Feature/Auth
rm -f tests/Feature/ProfileTest.php
```

Test bawaan Breeze ikut dihapus karena menguji route yang sudah tidak ada.

- [ ] **Step 4: Sederhanakan routes/web.php**

Ganti seluruh isi `routes/web.php` dengan:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/cms');
```

Seluruh route lain dihasilkan oleh panel Filament.

- [ ] **Step 5: Bersihkan middleware Inertia**

Buka `bootstrap/app.php`. Di dalam `->withMiddleware(...)`, hapus setiap baris yang menyebut `HandleInertiaRequests` atau `AddLinkHeadersForPreloadedAssets`. Jika blok `withMiddleware` menjadi kosong, biarkan closure-nya kosong.

- [ ] **Step 6: Sederhanakan vite.config.js**

Ganti seluruh isi `vite.config.js` dengan:

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
```

Lalu buat dua berkas kosong yang dirujuk di atas, karena Filament tidak memakainya tetapi Vite tetap membutuhkannya agar build tidak gagal:

```bash
mkdir -p resources/js
printf '' > resources/js/app.js
```

Pastikan `resources/css/app.css` masih ada. Jika Breeze menghapusnya, buat berkas kosong.

- [ ] **Step 7: Buktikan tidak ada sisa Inertia**

```bash
grep -rn "Inertia\|inertia\|ziggy\|Ziggy" app/ routes/ config/ bootstrap/ resources/views/ 2>/dev/null
```

Expected: tidak ada hasil. Perbaiki setiap kemunculan yang tersisa.

- [ ] **Step 8: Atur panel Filament**

Buka `app/Providers/Filament/CmsPanelProvider.php`. Di dalam rantai `$panel`, pastikan tiga hal berikut ada. Jangan hapus konfigurasi lain yang sudah dihasilkan installer.

```php
->id('cms')
->path('cms')
->login()
->brandName('Roemah Umara')
->navigationGroups([
    'Reservasi',
    'Master',
    'Pengaturan',
])
```

`->login()` memasang halaman login milik Filament. Jangan tambahkan `->registration()` — pendaftaran mandiri tidak diinginkan pada sistem internal.

- [ ] **Step 9: Verifikasi aplikasi hidup**

```bash
php artisan optimize:clear
php artisan route:list --path=cms
npm run build
php artisan serve
```

Expected: `route:list` menampilkan route panel Filament termasuk `filament.cms.auth.login`. Build selesai tanpa error.

Buka `http://localhost:8000/` di browser — harus otomatis berpindah ke `/cms/login` dan menampilkan halaman login Filament.

Buka `http://localhost:8000/login` — harus menghasilkan 404, membuktikan Breeze benar-benar hilang.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "chore: hapus Breeze, Inertia, dan React; siapkan panel Filament"
```

---

---

## Task 1: Fondasi — config, enum, dan test berjalan di MySQL

**Files:**
- Create: `config/reservation.php`
- Create: `app/Enums/Ability.php`
- Create: `app/Enums/ReservationStatus.php`
- Modify: `phpunit.xml`
- Modify: `.env.example`
- Test: `tests/Unit/ConfigTest.php`

**Interfaces:**
- Consumes: tidak ada, ini task pertama
- Produces: `Ability` (backed enum, delapan case) dengan `Ability::values(): array` dan `label()`; `ReservationStatus::Tentative`, `ReservationStatus::Confirmed`; `config('reservation.default_duration_minutes')` mengembalikan `int`

`Ability` diberi nama demikian, bukan `Permission`, agar tidak bentrok dengan
`Spatie\Permission\Models\Permission` yang akan dipakai di Task 2.

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

`app/Enums/Ability.php`:

```php
<?php

namespace App\Enums;

/**
 * Daftar kemampuan yang dikenali sistem.
 *
 * Ini adalah KODE, bukan data. Menambah kemampuan baru selalu lewat commit,
 * karena setiap kemampuan harus punya Policy yang memakainya. Yang boleh
 * diubah admin lewat UI adalah role dan kemampuan apa saja yang dimuatnya.
 *
 * Sengaja dinamai menurut kemampuan bisnis, bukan per-CRUD-per-Resource.
 * Delapan baris di sini menggantikan sekitar empat puluh permission yang
 * akan di-generate Filament Shield.
 */
enum Ability: string
{
    case ViewReservation = 'reservation.view';
    case CreateReservation = 'reservation.create';
    case UpdateReservation = 'reservation.update';
    case DeleteReservation = 'reservation.delete';
    case ConfirmReservation = 'reservation.confirm';
    case ManageMaster = 'master.manage';
    case ManageUser = 'user.manage';
    case ManageRole = 'role.manage';

    public function label(): string
    {
        return match ($this) {
            self::ViewReservation => 'Lihat reservasi',
            self::CreateReservation => 'Tambah reservasi',
            self::UpdateReservation => 'Ubah reservasi',
            self::DeleteReservation => 'Hapus reservasi',
            self::ConfirmReservation => 'Tetapkan status CONFIRMED',
            self::ManageMaster => 'Kelola master area, event, menu',
            self::ManageUser => 'Kelola pengguna',
            self::ManageRole => 'Kelola role dan hak akses',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> untuk dipakai sebagai opsi form */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
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

use App\Enums\Ability;
use App\Enums\ReservationStatus;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    public function test_default_duration_is_two_hours(): void
    {
        $this->assertSame(120, config('reservation.default_duration_minutes'));
    }

    public function test_abilities_are_named_by_business_capability(): void
    {
        $this->assertSame('reservation.delete', Ability::DeleteReservation->value);
        $this->assertSame('master.manage', Ability::ManageMaster->value);
        $this->assertCount(8, Ability::cases());
    }

    public function test_ability_values_and_options_stay_in_sync(): void
    {
        $this->assertCount(8, Ability::values());
        $this->assertSame(
            Ability::values(),
            array_keys(Ability::options()),
            'Setiap Ability wajib punya label.'
        );
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

## Task 2: Role dan permission dengan spatie

**Files:**
- Modify: `composer.json` (lewat composer require)
- Create: `database/migrations/2026_08_10_000001_add_is_active_to_users_table.php`
- Create: `database/migrations/*_create_permission_tables.php` (dari vendor publish)
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Create: `database/seeders/RolePermissionSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/RolePermissionTest.php`

**Interfaces:**
- Consumes: `Ability` dari Task 1
- Produces: `User` memakai trait `HasRoles`; `User::$is_active` bertipe `bool`; scope `User::query()->active()`; state factory `UserFactory::new()->admin()` dan `->inactive()`; role `admin` dan `staff` tersedia setelah `RolePermissionSeeder`

**Tidak ada kolom `role` di tabel `users`.** Role disimpan spatie di `model_has_roles`.
Menyimpannya di dua tempat sekaligus akan menghasilkan dua sumber kebenaran yang
bisa berbeda tanpa ketahuan.

`is_active` **bukan** permission, melainkan status akun: boleh login atau tidak.
Karena itu tetap menjadi kolom biasa dan diperiksa terpisah di setiap Policy.

- [ ] **Step 1: Pasang paket dan buat migration**

```bash
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan make:migration add_is_active_to_users_table
```

Ganti isi migration `add_is_active_to_users_table`:

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
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
```

Jalankan `php artisan migrate` dan pastikan lima tabel spatie terbentuk: `roles`,
`permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`.

- [ ] **Step 2: Tulis test yang gagal**

`tests/Feature/RolePermissionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_every_ability_exists_as_a_permission(): void
    {
        $this->assertSame(
            Ability::values(),
            Permission::orderByRaw('FIELD(name, "'.implode('","', Ability::values()).'")')
                ->pluck('name')
                ->all()
        );
    }

    public function test_two_roles_are_seeded(): void
    {
        $this->assertSame(['admin', 'staff'], Role::orderBy('name')->pluck('name')->all());
    }

    public function test_staff_can_read_and_write_but_not_delete(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->assertTrue($staff->can(Ability::ViewReservation->value));
        $this->assertTrue($staff->can(Ability::CreateReservation->value));
        $this->assertTrue($staff->can(Ability::UpdateReservation->value));

        $this->assertFalse($staff->can(Ability::DeleteReservation->value));
        $this->assertFalse($staff->can(Ability::ConfirmReservation->value));
        $this->assertFalse($staff->can(Ability::ManageMaster->value));
        $this->assertFalse($staff->can(Ability::ManageUser->value));
        $this->assertFalse($staff->can(Ability::ManageRole->value));
    }

    public function test_admin_has_every_ability(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (Ability::cases() as $ability) {
            $this->assertTrue(
                $admin->can($ability->value),
                "Admin seharusnya punya {$ability->value}."
            );
        }
    }

    public function test_a_new_role_can_be_created_without_touching_code(): void
    {
        $manager = Role::create(['name' => 'manajer']);
        $manager->givePermissionTo([
            Ability::ViewReservation->value,
            Ability::CreateReservation->value,
            Ability::UpdateReservation->value,
            Ability::ConfirmReservation->value,
        ]);

        $user = User::factory()->create();
        $user->assignRole('manajer');

        $this->assertTrue($user->can(Ability::ConfirmReservation->value));
        $this->assertFalse($user->can(Ability::DeleteReservation->value));
        $this->assertFalse($user->can(Ability::ManageUser->value));
    }

    public function test_active_scope_excludes_inactive_users(): void
    {
        User::factory()->create(['name' => 'Ira']);
        User::factory()->inactive()->create(['name' => 'Mantan Staf']);

        $this->assertSame(['Ira'], User::query()->active()->pluck('name')->all());
    }
}
```

Test `test_a_new_role_can_be_created_without_touching_code` adalah alasan seluruh
paket ini dipasang. Jika suatu saat test itu gagal karena Policy diam-diam kembali
mengecek nama role, kerugiannya baru akan terasa saat role ketiga benar-benar
dibutuhkan.

- [ ] **Step 3: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=RolePermissionTest`
Expected: FAIL dengan "Class Database\Seeders\RolePermissionSeeder not found"

- [ ] **Step 4: Perbarui model User**

Di `app/Models/User.php`, tambahkan dua import:

```php
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Traits\HasRoles;
```

Tambahkan trait di samping trait yang sudah ada:

```php
use HasFactory, Notifiable, HasRoles;
```

Tambahkan `'is_active'` ke array `$fillable` yang sudah ada, dan tambahkan satu baris
di dalam method `casts()`:

```php
'is_active' => 'boolean',
```

Tambahkan satu method di akhir class:

```php
public function scopeActive(Builder $query): void
{
    $query->where('is_active', true);
}
```

**Jangan menambahkan method `isAdmin()`.** Godaannya besar, tetapi setiap
pemanggilannya di Policy akan mengunci aturan ke nama role dan membatalkan alasan
memakai spatie. Yang dipakai di Policy adalah `$user->can(Ability::X->value)`.

- [ ] **Step 5: Buat seeder role dan permission**

`database/seeders/RolePermissionSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\Ability;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Ability::values() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $admin = Role::findOrCreate('admin', 'web');
        $admin->syncPermissions(Ability::values());

        $staff = Role::findOrCreate('staff', 'web');
        $staff->syncPermissions([
            Ability::ViewReservation->value,
            Ability::CreateReservation->value,
            Ability::UpdateReservation->value,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
```

`forgetCachedPermissions()` dipanggil di awal **dan** akhir. Cache permission spatie
adalah sumber bug paling umum saat memakai paket ini: permission berubah di database
tetapi aplikasi masih memakai nilai lama. Memanggilnya di seeder membuat masalah itu
tidak pernah muncul saat pengembangan.

`syncPermissions()` dipakai, bukan `givePermissionTo()`, agar menjalankan seeder ulang
setelah menghapus sebuah `Ability` benar-benar mencabut permission itu dari role.

- [ ] **Step 6: Daftarkan seeder**

Di `database/seeders/DatabaseSeeder.php`, di dalam `run()`:

```php
$this->call([
    RolePermissionSeeder::class,
]);
```

Seeder master dari Task 3 akan ditambahkan ke array yang sama.

- [ ] **Step 7: Tambahkan state ke factory**

Di `database/factories/UserFactory.php`, tambahkan dua method di akhir class:

```php
public function admin(): static
{
    return $this->afterCreating(fn (\App\Models\User $user) => $user->assignRole('admin'));
}

public function inactive(): static
{
    return $this->state(fn () => ['is_active' => false]);
}
```

`admin()` memakai `afterCreating()`, bukan `state()`, karena role bukan lagi kolom —
penugasannya baru bisa dilakukan setelah baris user punya id. Konsekuensinya, setiap
test yang memakai `->admin()` **wajib** menjalankan `RolePermissionSeeder` lebih dulu,
kalau tidak role `admin` belum ada dan spatie akan melempar `RoleDoesNotExist`.

- [ ] **Step 8: Jalankan test**

Run: `php artisan test --filter=RolePermissionTest`
Expected: 6 test PASS

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock database app/Models/User.php tests/Feature/RolePermissionTest.php
git commit -m "feat: role dan permission dengan spatie/laravel-permission"
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

## Task 8: Normalisasi input reservasi

**Files:**
- Create: `app/Support/ReservationInput.php`
- Test: `tests/Unit/ReservationInputTest.php`

**Interfaces:**
- Consumes: `TimeInput` (Task 6)
- Produces: `ReservationInput::normalize(array $data): array` — mengembalikan array dengan kunci yang sama, dengan `phone` hanya digit atau `null`, `email` huruf kecil atau `null`, `guest_name` dan `company` ter-trim, `start_time` dan `end_time` berformat `H:i` atau `null`, `status` dan `remark` kosong menjadi `null`

Karena UI memakai Filament, **tidak ada `FormRequest`**. Aturan wajib dan panjang maksimum ditulis langsung pada field Filament di Task 12 (`->required()`, `->maxLength()`). Yang tidak bisa dilakukan field Filament adalah membersihkan nilai sebelum divalidasi — itulah tugas kelas ini, yang dipanggil dari `mutateFormDataBeforeCreate()` dan `mutateFormDataBeforeSave()`.

Memisahkannya sebagai kelas murni membuat aturan `NA` menjadi `null`, pemecahan `12.00-15.00`, dan normalisasi nomor telepon bisa diuji tanpa menjalankan Livewire sama sekali.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Unit/ReservationInputTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\ReservationInput;
use PHPUnit\Framework\TestCase;

class ReservationInputTest extends TestCase
{
    private function normalize(array $input): array
    {
        return ReservationInput::normalize($input);
    }

    public function test_na_phone_becomes_null(): void
    {
        $this->assertNull($this->normalize(['phone' => 'NA'])['phone']);
    }

    public function test_phone_is_reduced_to_digits(): void
    {
        $this->assertSame('082249803564', $this->normalize(['phone' => '0822-4980-3564'])['phone']);
    }

    public function test_phone_with_spaces_is_reduced_to_digits(): void
    {
        $this->assertSame('081294489888', $this->normalize(['phone' => '0812 9448 9888'])['phone']);
    }

    public function test_na_email_becomes_null(): void
    {
        $this->assertNull($this->normalize(['email' => 'NA'])['email']);
    }

    public function test_email_is_lowercased_and_trimmed(): void
    {
        $this->assertSame('ira@umara.id', $this->normalize(['email' => '  IRA@Umara.ID '])['email']);
    }

    public function test_guest_name_is_trimmed(): void
    {
        $this->assertSame('Bapak Wanda', $this->normalize(['guest_name' => '  Bapak Wanda  '])['guest_name']);
    }

    public function test_single_start_time_is_normalized(): void
    {
        $out = $this->normalize(['start_time' => '11.00']);

        $this->assertSame('11:00', $out['start_time']);
        $this->assertNull($out['end_time']);
    }

    public function test_range_typed_into_start_time_is_split(): void
    {
        $out = $this->normalize(['start_time' => '12.00-15.00']);

        $this->assertSame('12:00', $out['start_time']);
        $this->assertSame('15:00', $out['end_time']);
    }

    public function test_explicit_end_time_wins_over_split_result(): void
    {
        $out = $this->normalize(['start_time' => '12.00', 'end_time' => '14.30']);

        $this->assertSame('12:00', $out['start_time']);
        $this->assertSame('14:30', $out['end_time']);
    }

    public function test_time_from_a_time_picker_is_accepted(): void
    {
        $out = $this->normalize(['start_time' => '12:00:00']);

        $this->assertSame('12:00', $out['start_time']);
    }

    public function test_blank_status_becomes_null(): void
    {
        $this->assertNull($this->normalize(['status' => ''])['status']);
    }

    public function test_blank_remark_becomes_null(): void
    {
        $this->assertNull($this->normalize(['remark' => '   '])['remark']);
    }

    public function test_missing_keys_are_left_untouched(): void
    {
        $out = $this->normalize(['pax' => 5, 'pic_id' => 3]);

        $this->assertSame(5, $out['pax']);
        $this->assertSame(3, $out['pic_id']);
    }

    public function test_unknown_keys_pass_through(): void
    {
        $out = $this->normalize(['area_id' => 2]);

        $this->assertSame(2, $out['area_id']);
    }
}
```

- [ ] **Step 2: Jalankan test untuk memastikan gagal**

Run: `php artisan test --filter=ReservationInputTest`
Expected: FAIL dengan "Class App\Support\ReservationInput not found"

- [ ] **Step 3: Buat normalizer**

`app/Support/ReservationInput.php`:

```php
<?php

namespace App\Support;

class ReservationInput
{
    /**
     * Bersihkan data form sebelum disimpan.
     * Kunci yang tidak dikenal dibiarkan apa adanya.
     */
    public static function normalize(array $data): array
    {
        foreach (['guest_name', 'company', 'remark'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = self::text($data[$key]);
            }
        }

        if (array_key_exists('phone', $data)) {
            $data['phone'] = self::phone($data['phone']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = self::email($data['email']);
        }

        if (array_key_exists('status', $data)) {
            $data['status'] = self::text($data['status']);
        }

        if (array_key_exists('start_time', $data)) {
            $split = TimeInput::split($data['start_time']);
            $explicitEnd = TimeInput::normalize($data['end_time'] ?? null);

            $data['start_time'] = $split['start'];
            $data['end_time'] = $explicitEnd ?? $split['end'];
        }

        return $data;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function phone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    private static function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        return ($value === '' || $value === 'na' || $value === '-') ? null : $value;
    }
}
```

`phone()` mengubah `NA` menjadi `null` sebagai efek samping yang diinginkan: tidak ada digit di dalamnya, sehingga hasilnya string kosong lalu menjadi `null`, lalu ditolak oleh `->required()` pada field Filament.

Pemrosesan `start_time` juga mengisi `end_time`, sehingga keduanya selalu konsisten walau pengguna mengetik rentang di satu kolom.

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=ReservationInputTest`
Expected: 14 test PASS

- [ ] **Step 5: Commit**

```bash
git add app/Support/ReservationInput.php tests/Unit/ReservationInputTest.php
git commit -m "feat: normalisasi input reservasi"
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
- Create: `app/Policies/AreaPolicy.php`
- Create: `app/Policies/EventTypePolicy.php`
- Create: `app/Policies/MenuStylePolicy.php`
- Create: `app/Policies/UserPolicy.php`
- Test: `tests/Feature/ReservationPolicyTest.php`

**Interfaces:**
- Consumes: `Ability` (Task 1), `HasRoles` pada `User` (Task 2), `Reservation` (Task 4)
- Produces: `ReservationPolicy` dengan method `viewAny`, `view`, `create`, `update`, `delete`, `deleteAny`, `confirm`; empat policy master dan pengguna

**Aturan yang tidak boleh dilanggar: Policy mengecek `Ability`, tidak pernah nama role.**
Menulis `$user->hasRole('admin')` di sini akan membuat role baru tetap memerlukan
perubahan kode, sehingga seluruh alasan memasang spatie hilang. Yang benar adalah
`$user->can(Ability::DeleteReservation->value)`.

Setiap method tetap memeriksa `is_active` secara terpisah. Status akun bukan
permission — pengguna nonaktif tidak boleh melakukan apa pun meski rolenya masih
memuat permission.

**Tidak ada middleware `admin`.** Filament membaca Model Policy secara otomatis dan
menyembunyikan resource dari menu navigasi ketika `viewAny()` mengembalikan `false`.

`deleteAny()` **wajib ada**. Filament memakainya untuk `DeleteBulkAction`, dan tanpa
method itu tombol hapus massal akan muncul untuk staf meski `delete()` menolaknya.

- [ ] **Step 1: Tulis test yang gagal**

`tests/Feature/ReservationPolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\Ability;
use App\Models\Reservation;
use App\Models\User;
use App\Policies\AreaPolicy;
use App\Policies\EventTypePolicy;
use App\Policies\MenuStylePolicy;
use App\Policies\ReservationPolicy;
use App\Policies\UserPolicy;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReservationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ReservationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->policy = new ReservationPolicy();
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->assignRole('staff');

        return $user;
    }

    public function test_active_staff_can_read_and_write(): void
    {
        $staff = $this->staff();
        $r = Reservation::factory()->create();

        $this->assertTrue($this->policy->viewAny($staff));
        $this->assertTrue($this->policy->view($staff, $r));
        $this->assertTrue($this->policy->create($staff));
        $this->assertTrue($this->policy->update($staff, $r));
    }

    public function test_staff_cannot_delete_or_confirm(): void
    {
        $staff = $this->staff();
        $r = Reservation::factory()->create();

        $this->assertFalse($this->policy->delete($staff, $r));
        $this->assertFalse($this->policy->deleteAny($staff));
        $this->assertFalse($this->policy->confirm($staff, $r));
    }

    public function test_admin_can_delete_and_confirm(): void
    {
        $admin = User::factory()->admin()->create();
        $r = Reservation::factory()->create();

        $this->assertTrue($this->policy->delete($admin, $r));
        $this->assertTrue($this->policy->deleteAny($admin));
        $this->assertTrue($this->policy->confirm($admin, $r));
    }

    public function test_master_and_user_policies_follow_abilities(): void
    {
        $staff = $this->staff();
        $admin = User::factory()->admin()->create();

        foreach ([new AreaPolicy(), new EventTypePolicy(), new MenuStylePolicy(), new UserPolicy()] as $policy) {
            $this->assertFalse($policy->viewAny($staff), $policy::class.' seharusnya menolak staf.');
            $this->assertTrue($policy->viewAny($admin), $policy::class.' seharusnya mengizinkan admin.');
        }
    }

    public function test_inactive_user_can_do_nothing(): void
    {
        $inactive = User::factory()->inactive()->create();
        $inactive->assignRole('staff');
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

    /**
     * Inilah alasan spatie dipasang. Role baru dengan kombinasi permission
     * yang belum pernah ada harus langsung bekerja tanpa menyentuh Policy.
     */
    public function test_a_new_role_works_without_changing_any_policy(): void
    {
        $manager = Role::create(['name' => 'manajer']);
        $manager->givePermissionTo([
            Ability::ViewReservation->value,
            Ability::UpdateReservation->value,
            Ability::ConfirmReservation->value,
        ]);

        $user = User::factory()->create();
        $user->assignRole('manajer');

        $r = Reservation::factory()->create();

        $this->assertTrue($this->policy->confirm($user, $r), 'Manajer seharusnya boleh confirm.');
        $this->assertFalse($this->policy->delete($user, $r), 'Manajer seharusnya tidak boleh hapus.');
        $this->assertFalse((new UserPolicy())->viewAny($user), 'Manajer seharusnya tidak boleh kelola pengguna.');
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

use App\Enums\Ability;
use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    /**
     * Aturan dasar: akun harus aktif, DAN rolenya harus memuat kemampuan
     * yang diminta.
     *
     * Perhatikan bahwa nama role tidak pernah disebut di berkas ini.
     * Menambah role baru cukup memberinya kemampuan lewat UI.
     */
    private function allows(User $user, Ability $ability): bool
    {
        return $user->is_active && $user->can($ability->value);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Ability::ViewReservation);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Ability::ViewReservation);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Ability::CreateReservation);
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Ability::UpdateReservation);
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $this->allows($user, Ability::DeleteReservation);
    }

    /**
     * Dipakai Filament untuk DeleteBulkAction. Tanpa method ini, tombol
     * hapus massal tetap muncul untuk staf.
     */
    public function deleteAny(User $user): bool
    {
        return $this->allows($user, Ability::DeleteReservation);
    }

    /**
     * $reservation bernilai null saat status confirmed dipilih pada form
     * pembuatan, ketika barisnya belum ada.
     */
    public function confirm(User $user, ?Reservation $reservation = null): bool
    {
        return $this->allows($user, Ability::ConfirmReservation);
    }
}
```

Parameter `$reservation` pada `confirm()` **wajib** nullable dengan nilai bawaan.
Halaman Create memanggil `can('confirm', Reservation::class)` saat status confirmed
dipilih untuk reservasi baru, dan pada pemanggilan itu Laravel tidak mengoper instance.

Pemeriksaan `is_active` disatukan di `allows()`, bukan di `before()`, karena `before()`
yang mengembalikan `false` akan memblokir seluruh gate di aplikasi termasuk yang tidak
berkaitan dengan reservasi.

**Jangan mengganti `$user->can(...)` menjadi `$user->hasRole('admin')`** meski terlihat
lebih ringkas. Itu akan membuat penambahan role baru memerlukan perubahan kode di
berkas ini, dan test `test_a_new_role_works_without_changing_any_policy` akan gagal.

- [ ] **Step 4: Buat empat policy master dan pengguna**

Keempatnya berbagi bentuk yang sama, hanya berbeda pada `Ability` yang diperiksa.
Isi `app/Policies/AreaPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Models\User;

class AreaPolicy
{
    protected function ability(): Ability
    {
        return Ability::ManageMaster;
    }

    private function allowed(User $user): bool
    {
        return $user->is_active && $user->can($this->ability()->value);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user): bool
    {
        return $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user);
    }

    public function update(User $user): bool
    {
        return $this->allowed($user);
    }

    public function delete(User $user): bool
    {
        return $this->allowed($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allowed($user);
    }
}
```

Salin isi yang sama ke `app/Policies/EventTypePolicy.php` dan
`app/Policies/MenuStylePolicy.php` dengan mengganti nama class saja — ketiganya
memakai `Ability::ManageMaster`, karena master area, event, dan menu adalah satu
kemampuan yang sama secara bisnis. Memecahnya menjadi tiga permission terpisah
menambah checkbox tanpa menambah kendali yang benar-benar dipakai.

Untuk `app/Policies/UserPolicy.php`, ganti nama class dan kembalikan
`Ability::ManageUser` pada `ability()`.

Pada `UserPolicy`, ganti `delete()` dan `deleteAny()` menjadi:

```php
public function delete(User $user): bool
{
    return false;
}

public function deleteAny(User $user): bool
{
    return false;
}
```

Pengguna tidak pernah boleh dihapus. Kolom `reservations.pic_id` dan `created_by`
memakai `restrictOnDelete`, sehingga penghapusan akan selalu gagal di level database.
Menonaktifkan lewat `is_active` adalah jalur yang benar, dan menutupnya di policy
membuat tombol Hapus tidak pernah muncul.

- [ ] **Step 5: Verifikasi Laravel menemukan policy**

Laravel 12 menemukan policy secara otomatis selama namanya `App\Policies\<Model>Policy`.
Buktikan dengan data nyata, bukan model yang belum tersimpan — spatie memerlukan baris
user yang punya id untuk membaca rolenya:

```bash
php artisan migrate:fresh --seed
php artisan tinker --execute="
\$staff = \App\Models\User::factory()->create(); \$staff->assignRole('staff');
\$admin = \App\Models\User::factory()->admin()->create();
echo 'staff area  : ' . var_export(\Illuminate\Support\Facades\Gate::forUser(\$staff)->allows('viewAny', \App\Models\Area::class), true) . PHP_EOL;
echo 'admin area  : ' . var_export(\Illuminate\Support\Facades\Gate::forUser(\$admin)->allows('viewAny', \App\Models\Area::class), true) . PHP_EOL;
echo 'staff create: ' . var_export(\Illuminate\Support\Facades\Gate::forUser(\$staff)->allows('create', \App\Models\Reservation::class), true) . PHP_EOL;
echo 'staff delete: ' . var_export(\Illuminate\Support\Facades\Gate::forUser(\$staff)->allows('deleteAny', \App\Models\Reservation::class), true) . PHP_EOL;
"
```

Expected:

```
staff area  : false
admin area  : true
staff create: true
staff delete: false
```

Jika `staff area` bernilai `true`, policy belum terhubung — daftarkan manual di
`AppServiceProvider::boot()` memakai `Gate::policy(Area::class, AreaPolicy::class)`.

Jika semuanya `false` termasuk untuk admin, kemungkinan besar cache permission spatie
masih memegang nilai lama. Jalankan
`php artisan permission:cache-reset` lalu ulangi.

- [ ] **Step 6: Jalankan test**

Run: `php artisan test --filter=ReservationPolicyTest`
Expected: 7 test PASS, termasuk `test_a_new_role_works_without_changing_any_policy`

- [ ] **Step 7: Commit**

```bash
git add app/Policies tests/Feature/ReservationPolicyTest.php
git commit -m "feat: policy reservasi, master, dan pengguna"
```

---

## Selesai — lanjut ke rencana UI

Task 0 sampai 11 di atas adalah seluruh isi dokumen ini. Semuanya backend dan tidak
bergantung pada pilihan UI.

**Lanjutkan ke:** `claude/2026-08-10-reservasi-roemah-umara-plan-ui.md`, yang berisi
Task 12 sampai 18 versi Filament v5.

Task 12–18 versi Inertia + React yang sebelumnya ada di bawah bagian ini sudah dihapus
pada 2026-08-14 karena usang. Riwayatnya tetap bisa ditelusuri lewat git.

## Ringkasan urutan pengerjaan (Task 0–11)

| Task | Deliverable | Bisa diuji sendiri |
|---|---|---|
| 0 | Bersihkan Breeze, siapkan panel Filament | Manual |
| 1 | Config, enum, test di MySQL | Ya |
| 2 | Role dan permission spatie | Ya |
| 3 | Tiga tabel master | Ya |
| 4 | Tabel dan model reservations | Ya |
| 5 | Constraint duplikat | Ya |
| 6 | Parser jam | Ya |
| 7 | Audit trail | Ya |
| 8 | Validasi dan normalisasi | Ya |
| 9 | Idempotency dan optimistic lock | Ya |
| 10 | Deteksi bentrok area | Ya |
| 11 | Policy dan hak akses | Ya |

Setelah Task 11 selesai, seluruh aturan bisnis sudah benar dan teruji meski belum
punya antarmuka. Task 12–18 di `plan-ui.md` membangun antarmukanya.
