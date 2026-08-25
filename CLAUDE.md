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
- `/` adalah **kalender publik**, bukan pengalihan ke `/cms`. Staf masuk lewat
  `/cms` langsung. Sidebar CMS punya tautan **Kalender publik** ke sana, di grup
  Reservasi, dibuka di **tab baru** — staf membukanya untuk memeriksa bagaimana
  sebuah reservasi terbaca dari luar lalu kembali bekerja, dan membukanya di tab
  yang sama akan membuang formulir yang belum tersimpan. URL-nya dibangun di
  dalam closure supaya `route()` dipanggil saat sidebar dirender, bukan saat
  provider boot; kalau tidak, tautannya bisa menunjuk ke localhost di produksi.

Kata `admin` di dalam proyek ini **selalu berarti nama role**, tidak pernah nama panel.

## Dashboard

Dua widget di `app/Filament/Widgets/`, ditemukan lewat `discoverWidgets`.
`FilamentInfoWidget` bawaan sengaja dilepas — isinya versi Filament dan tautan
dokumentasi, tidak ada gunanya bagi staf.

**`TodayWidget`** — tanggal dan jam berjalan. Jamnya adalah **jam venue**, bukan
jam komputer yang membukanya: titik awalnya dari server, ditambah selisih yang
berjalan di peramban, ditampilkan lewat `Intl` dengan `timeZone: 'Asia/Jakarta'`.
Memakai `Date.now()` telanjang akan menampilkan jam laptop masing-masing orang,
dan satu laptop yang zonanya keliru membuat stafnya mencatat jam yang salah tanpa
merasa ada yang aneh. Locale `en-GB`, bukan `id-ID` — yang kedua memisahkan jam
dengan titik (`10.11.05`).

**`UpcomingReservationsWidget`** — versi ringkas tabel reservasi, enam kolom.
Punya tiga rentang:

| Rentang | Isi | Batas jumlah |
|---|---|---|
| Terdekat (bawaan) | mulai hari ini | 10 baris |
| Minggu ini | satu minggu penuh, termasuk yang lampau | tidak ada |
| Bulan ini | satu bulan penuh, termasuk yang lampau | tidak ada |

Minggu dan bulan memuat periode **penuh**, bukan dipotong dari hari ini:
pertanyaan "bulan ini ada apa saja" hampir selalu berarti seluruh bulannya, dan
memotongnya membuat "Bulan ini" nyaris kosong setiap akhir bulan. Saringan
`>= hari ini` karena itu hanya ada di cabang Terdekat — **jangan memindahkannya
ke query bersama**, di sana ia akan mengosongkan lagi bagian periode yang sudah
lewat, diam-diam.

Karena tanggal lampau ikut, dua penanda wajib ada dan keduanya punya test:
pita peringatan di atas tabel (`memuatTanggalLampau()`, bergantung pada RENTANG
yang dipilih — bukan pada ada tidaknya baris lampau, supaya tidak muncul-hilang
tergantung data), dan keterangan "sudah lewat" per baris di kolom Tanggal.

Hal lain yang perlu diketahui:

- **Widget mengecek `Ability`**, sama seperti Policy (aturan #3). Tanpa itu, role
  yang sengaja dibuat tanpa hak lihat reservasi tetap membaca nama tamu dan
  remark begitu membuka dashboard.
- **Reservasi `cancelled` tidak ikut**, sejalan dengan aturan #9.
- **`$isLazy = false`** pada keduanya. Widget Filament lazy secara bawaan; di
  sini itu membuat dashboard tampil sebagai kotak kosong selama sesaat setiap
  kali dibuka, dan kotak kosong terbaca sebagai "tidak ada apa-apa".
- **CSS lewat `<style>`, bukan kelas Tailwind.** Filament membangun CSS-nya
  sendiri di `public/css/filament`, terpisah dari `app.css`, dan hanya memuat
  kelas yang dipakai view bawaannya. Alasan yang sama sudah dicatat di
  `resources/views/filament/tabs-full-width.blade.php`. Sorotan hover dibatasi ke
  `.fi-wi-table` supaya tabel di halaman resource tidak ikut berubah.

## Export Excel

Tombol **Export Excel** di toolbar `/cms/reservations`, dilayani
`App\Services\ReservationSpreadsheet`.

Memakai **openspout**, yang sudah ikut sebagai dependensi Filament — jangan
menambah `maatwebsite/excel` untuk ini. Sengaja **bukan**
`Filament\Actions\ExportAction`: yang itu mengantre lewat job batch dan menuntut
tabel `exports` berikut worker yang hidup, sedangkan setahun penuh data di sistem
ini masih di bawah dua ratus baris dan selesai seketika. Antrean hanya menambah
satu bagian yang bisa rusak diam-diam kalau worker mati.

Yang perlu dijaga:

- **Yang diekspor adalah hasil saringan yang sedang tampak**
  (`getFilteredSortedTableQuery()`), bukan seluruh tabel. Tab bulan, filter,
  rentang tanggal, pencarian, dan urutannya semua ikut. Menyaring satu bulan lalu
  mendapat berkas berisi segalanya adalah kejutan yang tidak diminta siapa pun.
- **Judul dan isi kolom ditulis sebagai satu larik** di `kolom()`. Memisahkannya
  membuat berkas melenceng satu kolom tanpa ada yang tahu sampai dibuka di Excel.
- **Susunan berkas: judul, baris kosong, kepala kolom, data.** Baris kosongnya
  bukan hiasan — tanpa jeda, Excel membaca judul sebagai bagian dari tabel begitu
  penggunanya menekan Sort/Filter atau membuat PivotTable, dan judulnya ikut
  tersortir sebagai data. Ditulis sebagai satu sel kosong (`['']`), bukan larik
  kosong: openspout menulis larik kosong sebagai `<row/>` tanpa isi dan pembaca
  spreadsheet melewatinya.
- **Judul dan nama berkas mengikuti KUNCI tab** (`2026-08` / `all`), bukan
  labelnya. Label dibangun ulang tiap bulan oleh `ListReservations::getTabs()` dan
  bergantung locale; kuncinya tetap dan bisa dibaca sebagai tanggal.
- **Nama berkas memuat periode dan waktu pembuatan** —
  `reservasi-2026-08-2026-08-25-1033.xlsx` berarti "isi Agustus 2026, diambil 25
  Agustus pukul 10:33". Periodenya menjawab "isinya apa", jamnya menjawab "yang
  mana yang paling baru" ketika bulan yang sama diekspor berkali-kali.
- **Berkas sementara lalu `->download()`, bukan `openToBrowser()`.** Aksi Filament
  berjalan di dalam permintaan Livewire; menulis langsung ke output akan
  menyisipkan isi berkas ke tengah balasan JSON-nya.

Testnya **membaca kembali** berkas yang dihasilkan. Berkas .xlsx yang rusak tetap
terkirim dengan status 200 dan content-type yang benar — yang ketahuan hanya saat
Excel menolaknya. Pembacanya menyalakan `SHOULD_PRESERVE_EMPTY_ROWS` supaya baris
kosong pemisah judul ikut terlihat.

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

**Akun.** `db:seed` hanya membuat satu akun: admin `roemahumara@gmail.com`, sandinya
dari `INITIAL_USER_PASSWORD` di `.env`. Kalau nilai itu kosong atau masih placeholder,
`DatabaseSeeder` **berhenti** — bukan memakai nilai cadangan. Cadangan `'password'`
dihapus 2026-08-24 setelah pemasangan VPS lahir bersandi placeholder tanpa satu pun
tanda, dan login ditolak dengan pesan yang sama persis untuk email tidak terdaftar,
sandi salah, dan akun nonaktif (Filament menyamakan ketiganya dengan sengaja),
sehingga penyebabnya mustahil dibedakan dari layar. `firstOrCreate` tidak pernah
memperbaiki akun yang terlanjur jadi. Sepuluh akun staf ada di `StaffSeeder` (tidak
ikut `db:seed` polos), memakai sandi dan penjaga yang sama lewat trait
`Concerns\ReadsInitialPassword` — jangan menyalin penjaganya ke masing-masing seeder.
Sandi bersama itu keadaan sementara: selama belum diganti masing-masing,
`activity_log` bisa menunjuk orang yang keliru.

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

   Berlaku juga di **widget dashboard** dan **berkas export** — di keduanya
   remark tampil utuh, dan masing-masing punya test yang menjaganya.

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

    Tiap item yang dipesan boleh punya **catatan sendiri** di
    `menu_reservation.remark` — "tidak pedas", "saus dipisah". Terpisah dari
    remark reservasi: yang itu tentang acaranya, yang ini dibaca dapur saat
    menyiapkan hidangannya. Tampil penuh di CMS dan halaman publik, tidak boleh
    dipotong (aturan #4).

    Kategori menu adalah **tabel master** `menu_categories`, bisa ditambah lewat
    `/cms/menu-categories` atau langsung dari form menu. Sampai 2026-08-23 ia
    konstanta `Menu::CATEGORIES`; itu mencegah salah ketik tapi menuntut
    menyunting PHP setiap kali kategori baru dibutuhkan. Urutan tampil daftar
    menu mengikuti id kategori (`Menu::scopeInMenuOrder`), sehingga kategori baru
    muncul di bawah.

16. **Jam adalah `App\Support\Jam`, bukan string.** `start_time` dan `end_time`
    di-cast lewat `App\Casts\JamCast`. Sebelum 2026-08-25 keduanya string mentah
    dari MySQL, dan tujuh tempat menulis `substr((string) $r->start_time, 0, 5)`
    sendiri-sendiri — tujuh salinan aturan "buang detiknya".

    **Kolom databasenya TETAP `TIME`.** Jangan mengubahnya: `dedupe_key` adalah
    generated stored column yang memakai `TIME_FORMAT()` di dalam MySQL
    (aturan #1). Yang berubah hanya bentuknya di PHP.

    **Tiga bentuk, tiga alasan, dan ketiganya punya test:**

    | Cara | Hasil | Dipakai |
    |---|---|---|
    | `__toString()` | `11:00` | seluruh tampilan |
    | `JamCast::serialize()` | `11:00` | `attributesToArray()` → isian form Filament |
    | `jsonSerialize()` | `11:00:00` | `activity_log` |

    `serialize()` **wajib ada**. Filament mengisi form dari
    `$record->attributesToArray()`; tanpa itu yang masuk ke state Livewire adalah
    objek `Jam`, dan halaman Edit tumbang dengan HTTP 500 "Property type not
    supported in Livewire".

    `jsonSerialize()` sengaja `H:i:s` **demi kesinambungan riwayat**. spatie
    mencatat nilai sesudah cast (`LogsActivity::logChanges`), dan seluruh entri
    yang tercatat sebelum kelas ini ada berisi `'11:00:00'` apa adanya dari
    MySQL. Menyerialkan `H:i` akan membelah riwayat satu reservasi jadi dua
    bentuk, dan itu **tidak bisa diperbaiki belakangan** — entri lama sudah
    tersimpan.

    **Pembagian tugas dengan `TimeInput`:** TimeInput membaca ketikan manusia
    (`11`, `11.00`, `12.00-15.00`), Jam adalah nilainya. `TimeInput::split()`
    dipakai form untuk memecah rentang sebelum ada nilai jam terbentuk.

    `Jam::dari()` mengembalikan **null** untuk yang tidak terbaca, tidak
    melempar: penolakan input adalah tugas validasi form, dan melempar di sini
    akan membuat halaman daftar tumbang gara-gara satu baris lama yang datanya
    aneh.

    Acara yang melewati tengah malam akan membuat `ConflictChecker` menghitung
    jendela terbalik — `end` lebih kecil daripada `start` — sehingga pengecekan
    bentrok untuk baris itu mati tanpa peringatan. Keadaan itu **tidak bisa
    terbentuk** selama aturan #17 berlaku; kalau jam tutup kelak dihapus atau
    disetel lewat tengah malam, bug ini hidup kembali.

17. **Jam mulai dan jam selesai tidak boleh melewati jam tutup.** Batasnya di
    `config('reservation.jam_tutup')`, bawaannya **22:00**, bisa diubah lewat
    `RESERVATION_CLOSING_TIME` di `.env`. Tepat pukul tutup masih boleh — "sampai
    pukul 22:00" berarti 22:00 termasuk.

    **Jam mulai ikut dibatasi, bukan hanya jam selesai.** Reservasi tanpa jam
    selesai diasumsikan berdurasi default oleh `ConflictChecker`, jadi jam mulai
    23:00 menghasilkan jendela yang berakhir esok hari — persis keadaan yang
    batas ini ada untuk mencegahnya (lihat aturan #16).

    **Dua lapis, dan itu disengaja:** form Filament (pesan ramah, menunjuk
    kolomnya, dan **memeriksa kedua ujung rentang** yang diketik di kolom Jam
    mulai — tanpa itu "19.00-23.00" lolos dan baru ditolak writer dengan pesan
    yang tidak menunjuk kolom), dan `ReservationWriter` (berlaku untuk seeder,
    tinker, dan kode lain). **Sengaja TIDAK ada CHECK constraint di database** —
    ini aturan bisnis, bukan bentuk data, dan jam tutup venue lebih mungkin
    berubah daripada struktur tabelnya. Bandingkan dengan `area_id` yang
    `NOT NULL` di aturan #12: yang itu bentuk data.

    Angkanya dibaca lewat `Jam::tutup()`, **jangan mengetiknya ulang** di form
    atau writer — dua salinan aturan yang sama berangsur berbeda, dan yang satu
    akan menolak apa yang diterima yang lain.

    Data lama yang sudah melanggar **tidak diusik**: batas ini berlaku saat
    menyimpan, bukan saat membaca. `ReservationDemoSeeder` diperbaiki
    2026-08-25 karena satu barisnya berjam 20:00–23:00.

18. **Menambah status baru butuh migrasi, bukan hanya case enum.** Kolom `status`
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
