# Sistem Reservasi Roemah Umara

Sistem reservasi internal untuk restoran/venue. Menggantikan spreadsheet Excel.
Volume ± 15 reservasi per bulan, sekitar 8 pengguna, semua internal.

## Dokumen

- Spec: `claude/2026-08-10-reservasi-roemah-umara-design.md`
- Rencana backend: `claude/2026-08-10-reservasi-roemah-umara-plan.md` (Task 0–11)
- Rencana UI: `claude/2026-08-10-reservasi-roemah-umara-plan-ui.md` (Task 12–18)
- Spec v2: `claude/2026-08-20-kalender-publik-dan-nomor-reservasi-design.md`
- Rencana v2: `claude/2026-08-20-kalender-publik-dan-nomor-reservasi-plan.md` (Task 19–24)

Kerjakan berurutan. Jangan melompat. Satu task per sesi review.

## Stack

PHP 8.3, Laravel 12, Filament v5.7, Livewire v4.3, MySQL, PHPUnit 11,
spatie/laravel-activitylog, spatie/laravel-permission.

## Panel Filament

Panel bernama **`cms`**, bukan `admin`.

- Provider: `app/Providers/Filament/CmsPanelProvider.php`
- `->id('cms')` dan `->path('cms')`
- URL: `/cms`, login di `/cms/login`
- Nama route: `filament.cms.*` (mis. `filament.cms.auth.login`)
- `/` diarahkan ke `/cms`

Kata `admin` di dalam proyek ini **selalu berarti nama role**, tidak pernah nama panel.

## Database

Development dan test memakai database yang sama: **`ru_reservation`**.

Konsekuensinya: `php artisan test` memakai `RefreshDatabase`, yang menjalankan
`migrate:fresh` dan **menghapus seluruh data uji coba manual**. Ini keputusan yang
diambil sadar. Kalau nanti mengganggu, pindahkan ke database terpisah dengan mengubah
`DB_DATABASE` di `phpunit.xml` — tidak ada perubahan kode lain yang diperlukan.

## Aturan yang tidak boleh dilanggar

1. **MySQL, bukan SQLite, termasuk untuk test.** `dedupe_key` memakai generated
   stored column dengan `IF()`, `CONCAT_WS()`, `DATE_FORMAT()`, `TIME_FORMAT()`.
   Menjalankan test di SQLite akan melewati constraint terpenting tanpa memberi tanda.

2. **Update model lewat `$model->save()`, bukan `Model::where()->update()`.**
   Update massal tidak memicu event Eloquent, sehingga `activity_log` tidak tercatat
   dan audit trail bolong tanpa error.

3. **Policy mengecek `Ability`, tidak pernah nama role.** Dilarang menulis
   `hasRole('admin')` atau membuat `User::isAdmin()`. Yang benar:
   `$user->can(Ability::DeleteReservation->value)`.

4. **Remark selalu ditampilkan penuh.** Dilarang `->limit()`, `->words()`, atau
   menyembunyikan di balik hover/tombol. Di tabel memakai `Panel` tanpa
   `->collapsible()`. Satu pengecualian: chip kalender, yang menggantinya dengan
   panel detail.

5. **Penyimpanan reservasi wajib lewat `ReservationWriter`.** Halaman Create dan Edit
   Filament meng-override `handleRecordCreation()` dan `handleRecordUpdate()`. Tanpa
   itu, idempotency dan optimistic lock terlewati diam-diam.

6. **Optimistic lock memakai kolom `version` (integer), bukan `updated_at`.**
   TIMESTAMP MySQL berpresisi detik.

7. **Tidak ada Inertia, React, Breeze, Ziggy, atau Filament Shield.**

8. **Bersihkan cache permission spatie setiap kali role disimpan**
   (`forgetCachedPermissions()`). Kalau terlewat, gejalanya terlihat seperti
   "sistem tidak menyimpan perubahan".

9. **Halaman publik hanya boleh memuat lima kolom reservasi:** tanggal, jam, area,
   jenis acara, status. Batasnya ditegakkan lewat `select()` eksplisit di
   `PublicCalendarController`, bukan dengan tidak menulisnya di Blade. Dilarang
   menambahkan `guest_name`, `company`, `phone`, `email`, `remark`, `pax`, atau
   `pic_id` ke `select()` itu.

10. **`NumberSequence::next()` wajib dipanggil di dalam transaksi.** Di luar
    transaksi, `FOR UPDATE` tidak menahan apa pun. Nomor reservasi ditetapkan sekali
    saat pembuatan dan tidak pernah berubah.

## API Filament v5 — jangan pakai pola v3

- Form: `form(Schema $schema): Schema` dengan `Filament\Schemas\Schema` + `->components([...])`
- Aksi tabel: `Filament\Actions\*`, bukan `Filament\Tables\Actions\*`
- Method tabel: `->recordActions()`, `->toolbarActions()`
- Layout tabel: `Filament\Tables\Columns\Layout\{Split, Stack, Grid, Panel}`
- Struktur: `app/Filament/Resources/<Plural>/` berisi `Pages/`, `Schemas/`, `Tables/`

Kalau sebuah pemanggilan tidak ada, jangan menebak — buka
https://filamentphp.com/docs/5.x/

## Perintah

- Test: `php artisan test`
- Test satu berkas: `php artisan test --filter=NamaTest`
- Reset cache permission: `php artisan permission:cache-reset`
- Dev: `php artisan serve`
- Lihat route panel: `php artisan route:list --path=cms`
