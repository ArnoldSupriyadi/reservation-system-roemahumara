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

Development dan test memakai database **terpisah**:

- Development: `ru_reservation` (dari `.env`)
- Test: `ru_reservation_test` (dari `phpunit.xml`)

Sebelumnya keduanya satu database, dan `RefreshDatabase` menghapus seluruh data uji
coba manual setiap kali `php artisan test` dijalankan. Itu memang keputusan sadar
pada awalnya, tapi terbukti mengganggu, jadi dipisah pada 2026-08-22 persis seperti
yang diantisipasi catatan lama ini — hanya `DB_DATABASE` di `phpunit.xml` yang
berubah, tidak ada perubahan kode.

Database test dibuat sekali dengan:

```sql
CREATE DATABASE ru_reservation_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Kalau mesin lain menjalankan test dan gagal menyambung, itu sebabnya.

**Data contoh** untuk melihat tampilan berisi:

```
php artisan db:seed --class=ReservationDemoSeeder
```

Seeder itu sengaja tidak ikut `db:seed` polos, supaya sistem yang baru dipasang tidak
berisi tamu palsu. Aman dijalankan berulang: penulisannya lewat `ReservationWriter`,
jadi idempotency-nya mencegah data kembar.

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

4. **Remark selalu ditampilkan penuh.** Dilarang `->limit()`, `->words()`,
   `->toggleable()`, atau menyembunyikan di balik hover/tombol. Satu pengecualian:
   chip kalender, yang menggantinya dengan panel detail.

   Di tabel, remark adalah **kolom biasa** dengan `->wrap()`. Sebelumnya aturan ini
   mewajibkan `Panel`, dan itu keliru: Filament mematikan `<thead>` seluruh tabel
   begitu ada satu komponen layout di level atas (`HasColumns::pushColumns` menyetel
   `hasColumnsLayout`, dan `index.blade.php` hanya merender header kalau flag itu
   false). Akibatnya nomor reservasi dan pax tampil sebagai angka telanjang tanpa
   judul kolom, dan pengguna melaporkan tabelnya tidak terbaca. Diganti 2026-08-22.
   `ReservationsTableTest::test_the_table_keeps_its_header_row` menjaga agar
   komponen layout tidak masuk lagi diam-diam.

5. **Penyimpanan reservasi wajib lewat `ReservationWriter`.** Halaman Create dan Edit
   Filament meng-override `handleRecordCreation()` dan `handleRecordUpdate()`. Tanpa
   itu, idempotency dan optimistic lock terlewati diam-diam.

6. **Optimistic lock memakai kolom `version` (integer), bukan `updated_at`.**
   TIMESTAMP MySQL berpresisi detik.

7. **Tidak ada Inertia, React, Breeze, Ziggy, atau Filament Shield.**

8. **Bersihkan cache permission spatie setiap kali role disimpan**
   (`forgetCachedPermissions()`). Kalau terlewat, gejalanya terlihat seperti
   "sistem tidak menyimpan perubahan".

9. **Reservasi berstatus `cancelled` tidak pernah tampil di halaman publik.**
   Blade publik memperlakukan segala yang bukan CONFIRMED sebagai "Sedang
   dijajaki", jadi reservasi batal yang ikut termuat akan terbaca pengunjung
   sebagai slot terpakai padahal sudah bebas. Disaring di `PublicCalendarController`,
   bukan di Blade. Karena alasan yang sama, `ConflictChecker` juga melewatinya —
   reservasi batal tidak memakai tempat. Di kalender staf ia tetap tampil, dicoret.

10. **Halaman publik: hanya `phone` dan `email` yang masih tertutup.** Batasnya
   ditegakkan lewat `select()` eksplisit di `PublicCalendarController`, bukan
   dengan tidak menulisnya di Blade. Dilarang menambahkan keduanya ke `select()`
   itu — keduanya kontak pribadi tamu, dan nilainya justru bertambah sekarang
   karena semua konteks di sekitarnya sudah terbuka.

   Halaman ini dulu dibatasi lima kolom. Pada 2026-08-22 dilonggarkan bertahap
   atas permintaan eksplisit pemilik sistem: `pax`, `menu_style_id`, lalu
   `guest_name`, `pic_id`, `remark`, lalu `company`. Yang perlu disadari pembaca
   berkas ini: halaman terbuka tanpa login dan dapat terindeks mesin pencari,
   sedangkan remark di sistem ini terbiasa memuat keterangan pembayaran.
   Menariknya kembali cukup dengan menghapus kolomnya dari `select()`; Blade akan
   menampilkan nilai kosong, bukan error.

   `PublicCalendarTest::test_the_remaining_private_columns_are_never_even_loaded`
   memeriksa `getAttributes()` hasil query, sehingga kolom terlarang ketahuan
   sudah pada tahap dimuat, bukan menunggu bocor di Blade.

11. **`NumberSequence::next()` wajib dipanggil di dalam transaksi.** Di luar
    transaksi, `FOR UPDATE` tidak menahan apa pun. Nomor reservasi ditetapkan sekali
    saat pembuatan dan tidak pernah berubah.

12. **Bentrok area tidak dilarang, tapi menuntut penjelasan di Remark.** Kalau
    area, tanggal, dan jamnya tumpang tindih dengan reservasi lain, penyimpanan
    ditolak selama Remark kosong; begitu diisi, tersimpan dan peringatan "Area
    bentrok" tetap muncul. **Jangan menggantinya jadi larangan keras** — di
    lapangan ada bentrok yang sah (acara berurutan, sekat VIP dibuka), dan
    penolakan mentah mendorong staf mengosongkan kolom Area supaya bisa
    menyimpan. Begitu Area kosong, pengecekan bentrok mati total untuk baris itu.

    **Area wajib diisi** justru karena aturan ini: area kosong membuat
    `ConflictChecker` melewati barisnya sepenuhnya, sehingga kewajiban menjelaskan
    bentrok bisa dihindari cukup dengan tidak mengisi area.

    **Ditegakkan berlapis, dan itu disengaja.** Area wajib di tiga tempat: form
    Filament (pesan ramah, menunjuk kolom), `ReservationWriter` (berlaku untuk
    seeder, tinker, dan kode lain — melempar `InvalidReservationException`), dan
    kolom `area_id` yang `NOT NULL` di database (berlaku bahkan untuk SQL
    langsung dan impor). Kewajiban menjelaskan bentrok di Remark hanya di dua
    lapis pertama; itu aturan bisnis, bukan bentuk data.

    Penjagaan di writer memeriksa keadaan **hasil** perubahan, bukan input mentah.
    `update()` menerima array parsial, jadi memeriksa `$data` apa adanya akan
    menolak perubahan pax yang tidak menyentuh area sama sekali.

    Aturannya ada di trait `Concerns\ChecksAreaConflicts`, dipakai bersama Create
    dan Edit. **Jangan menyalinnya kembali ke masing-masing halaman** — salinan
    seperti itu berangsur berbeda dan menghasilkan larangan yang berlaku saat
    membuat tapi tidak saat mengubah. Pemeriksaannya berjalan **sebelum**
    penulisan; `warnAboutConflicts()` sesudahnya.

    **Area bisa saling meliputi.** ALL BALLROOM adalah BALLROOM 1–4 dengan sekat
    dibuka, dicatat di tabel `area_overlaps` dan dipakai `ConflictChecker` lewat
    `Area::occupiedAreaIds()`. Relasinya disimpan **dua arah**; selalu memakai
    `Area::overlapWith()`, jangan `attach()` langsung — kalau hanya satu arah,
    bentroknya cuma terdeteksi ketika pengguna kebetulan memesan dari sisi yang
    benar dan diam dari sisi sebaliknya. Dua bagian yang berbeda (BALLROOM 1 dan
    BALLROOM 2) sengaja TIDAK saling meliputi.

    Duplikat persis dikecualikan: ia juga terbaca sebagai bentrok area, tapi
    pesan "sudah ada reservasi atas nama X" lebih menolong daripada "isi Remark".
    Penjaga ini memanggil `ReservationWriter::findDuplicate()` lalu menyingkir —
    jangan menyalin ulang logika dedupe-nya ke tempat ketiga.

13. **Selesai create atau edit, kembali ke index.** Disetel sekali di
    `CmsPanelProvider` lewat `->resourceCreatePageRedirect('index')` dan
    `->resourceEditPageRedirect('index')`, sehingga berlaku untuk semua resource
    termasuk yang dibuat nanti. **Jangan meng-override `getRedirectUrl()` di
    halaman mana pun** — override lokal mengalahkan setelan panel diam-diam, dan
    itulah yang dulu membuat CreateReservation lompat ke halaman view.
    `ReservationFilamentTest::test_creating_redirects_back_to_the_list` menjaganya.

14. **Master (Area, EventType, Menu) tidak punya kolom urutan.** Daftarnya
    diurutkan `id`. `sort_order` dihapus 2026-08-22 karena menuntut pengelola
    memikirkan angka setiap menambah baris, padahal isinya belasan dan urutan
    tampilnya tidak pernah jadi persoalan. Urutan larik di `MasterSeeder` tetap
    menentukan urutan di layar, karena id mengikuti urutan penyisipan.

15. **Menu dipesan lewat pivot, bukan kolom.** Satu reservasi bisa memesan banyak
    menu, masing-masing dengan porsinya sendiri di `menu_reservation.pax`. Porsi
    TIDAK diturunkan dari `reservations.pax` — minuman kerap dipesan lebih banyak
    daripada jumlah tamu.

    Repeaternya di form bernama **`menu_items`, bukan `menus`**. Memakai nama
    relasi membuat Filament menyimpan pivotnya sendiri lewat relationship
    handling, sehingga penulisan lolos dari `ReservationWriter` (aturan #5) dan
    terjadi di luar transaksi. Writer yang melakukan `sync()`, di dalam transaksi
    yang sama dengan penyimpanan reservasinya.

    Pada `update()`, kunci `menu_items` yang **tidak dikirim** berarti "jangan
    sentuh menu"; larik **kosong** berarti "hapus semua". Menyamakan keduanya
    membuat perubahan pax saja diam-diam menghapus pesanan.

    Kategori menu ada di `Menu::CATEGORIES`, bukan diambil dari nilai unik di
    database — salah ketik akan melahirkan kelompok baru di layar tanpa ada yang
    menyadarinya. `MenuSeeder` menolak jalan kalau `database/data/menu.json`
    memuat kategori yang belum terdaftar.

16. **Menambah status baru butuh migrasi, bukan hanya case enum.** Kolom `status`
    bertipe ENUM MySQL. Menambah case di `App\Enums\ReservationStatus` tanpa
    `ALTER TABLE ... MODIFY COLUMN` menghasilkan "Data truncated for column
    'status'" saat menyimpan. Pakai `DB::statement()`, bukan `$table->enum()->change()`
    — doctrine/dbal tidak mengenali ENUM dan diam-diam mengubahnya jadi VARCHAR.
    Status saat ini: `tentative`, `confirmed`, `cancelled`, plus NULL (belum ditentukan).

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
