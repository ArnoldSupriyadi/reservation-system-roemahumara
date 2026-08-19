# Desain — Sistem Reservasi Roemah Umara (v1)

- **Tanggal:** 10 Agustus 2026
- **Repositori:** `ArnoldSupriyadi/reservation-system-roemahumara` (branch `main`)
- **Status:** menunggu review
- **Simpan ke:** `docs/superpowers/specs/2026-08-10-reservasi-roemah-umara-design.md`

---

## 1. Konteks

Roemah Umara mencatat reservasi restoran/venue di spreadsheet Excel. File contoh
(`RESERVASI ROEMAH UMARA`, sheet Agustus 2026) berisi 15 reservasi dengan kolom:
No., NAMA, TANGGAL, COMPANY, HP, EMAIL, SALES/PIC, EVENT, MENU STYLE, TIME BOOKING,
PAX, STATUS, VENUE (Rp) yang terbagi atas 7 area, dan REMARK.

Volume ± 15 reservasi per bulan (± 180 per tahun).

### Masalah yang dipecahkan

Dipilih oleh pemilik sistem dari daftar kandidat:

1. **Data berantakan.** Format tidak konsisten — `NA` untuk nomor telepon, jam ditulis
   `11.00` / `12:00:00` / `12.00-15.00`, jumlah tamu ditulis `80 s/d 120`, kategori
   ditulis `TEST FOOD ` dengan spasi di belakang. Akibatnya data sulit dicari,
   difilter, dan direkap.
2. **Tidak ada jejak perubahan.** Tidak diketahui siapa mengubah apa dan kapan.

### Masalah yang secara sadar TIDAK dipecahkan di v1

- **Bentrok area dan jam.** Tidak dipilih sebagai masalah utama. v1 hanya memberi
  peringatan, tidak memblokir. Lihat bagian 8.
- **Akses bersamaan dan pelaporan.** Tidak dipilih. Tidak ada halaman laporan di v1.

### Skala

Karena volume hanya ± 15 baris per bulan, seluruh pertimbangan performa dianggap
tidak relevan: tidak ada pagination, tidak ada caching, tidak ada queue, tidak ada
endpoint API terpisah.

---

## 2. Keputusan arsitektur

### 2.1 Stack: Laravel 12 + Filament v5 + MySQL

Terverifikasi di mesin pengembang pada 10 Agustus 2026: PHP 8.3.1, Laravel 12.65.0,
Filament v5.7.6, Livewire v4.3.5.

Repositori awalnya adalah starter Laravel 12 + Breeze varian React/Inertia tanpa
modifikasi — `routes/web.php` masih berisi halaman Welcome dan Dashboard bawaan, dan
belum ada model maupun migration reservasi. Praktis greenfield.

**Filament v5 adalah satu-satunya lapisan tampilan.** Breeze, Inertia, React, dan
Ziggy dihapus seluruhnya. Autentikasi memakai halaman login bawaan panel Filament di
`/cms`, dan `/` diarahkan ke sana.

**Riwayat keputusan ini, agar tidak diulang.** Awalnya direncanakan memakai Inertia +
React karena itulah yang sudah terpasang. Keputusan tersebut dibalik setelah disadari
bahwa Task 1 sampai 11 — seluruh backend — identik untuk kedua pilihan, sehingga
perdebatannya hanya menyangkut lapisan tampilan. Untuk CRUD dengan dua role dan volume
sekecil ini, Filament memberi tabel, filter, form builder, dan otorisasi berbasis
Policy secara gratis. Faktor penentunya bukan kecepatan pembuatan melainkan perawatan:
setiap baris UI yang ditulis sendiri harus dirawat sendiri selamanya, sementara
Filament merawat sebagian besar UI-nya lewat pembaruan paket.

Menjalankan Filament berdampingan dengan Inertia ditolak karena tidak ada audiens
kedua — hasilnya hanya dua UI internal, dua design system, dan dua sistem autentikasi
yang harus dijaga bersamaan.

**Satu-satunya bagian yang tidak dibantu Filament adalah kalender**, yang dibangun
sebagai custom Page dengan CSS Grid. Biayanya sama saja di kedua pilihan.

Paket tambahan: `spatie/laravel-activitylog`, `spatie/laravel-permission`.

### 2.2 Semua di balik login

Tidak ada halaman untuk tamu. Tidak ada form booking publik. Seluruh halaman berada di
dalam panel Filament yang sudah dilindungi autentikasi.

### 2.3 Dua peran awal: `admin` dan `staff`

Role dan permission memakai `spatie/laravel-permission`. Dua role di bawah adalah isi
awal dari seeder, **bukan daftar tertutup** — admin dapat menambah role baru lewat UI
tanpa perubahan kode.

| Kemampuan (`Ability`) | staff | admin |
|---|---|---|
| `reservation.view` | ya | ya |
| `reservation.create` | ya | ya |
| `reservation.update` | ya | ya |
| `reservation.delete` | tidak | ya |
| `reservation.confirm` | tidak | ya |
| `master.manage` | tidak | ya |
| `user.manage` | tidak | ya |
| `role.manage` | tidak | ya |

**Aturan yang mengikat: Policy mengecek `Ability`, tidak pernah nama role.** Menulis
`hasRole('admin')` di Policy akan membuat penambahan role tetap memerlukan perubahan
kode, sehingga seluruh alasan memakai spatie hilang.

Nama permission adalah kode — `Ability` hanya berubah lewat commit, karena setiap
kemampuan harus punya Policy yang memakainya. Yang boleh diubah admin lewat UI adalah
role dan kemampuan apa saja yang dimuatnya.

**Filament Shield tidak dipakai.** Shield meng-generate permission per-CRUD-per-Resource,
yang untuk lima resource menghasilkan sekitar empat puluh permission. Delapan kemampuan
di atas dinamai menurut fungsi bisnis dan mengekspresikan aturan yang sama.

### 2.4 PIC diambil dari tabel `users`

Setiap nama PIC pada data operasional — JOESOEF, UCR, ARIF, IRA, CASSIE, AGUS, SONIA,
IBU MARLUCE — dibuat sebagai baris di `users` dengan `is_active = true`, termasuk yang
pada praktiknya tidak pernah login. `is_active = false` hanya dipakai untuk staf yang
sudah tidak bekerja lagi.

Alasan: `pic_id` sebagai foreign key ke `users` adalah satu-satunya cara audit log
dapat menyebut nama orang yang bertanggung jawab. Tabel staf terpisah dari `users`
akan menghasilkan dua daftar orang yang harus disinkronkan manual.

### 2.5 Tidak ada migrasi data lama

Database dimulai kosong. Reservasi Agustus 2026 yang masih berjalan diinput ulang
secara manual (15 baris).

Alasan: `phone` dan `pic_id` diputuskan wajib, sementara 9 dari 15 baris data lama
tidak memiliki nomor telepon (`NA`) dan 3 baris tidak memiliki SALES. Mengimpor data
tersebut memerlukan jalur yang melewati validasi, yang membuat aturan wajib menjadi
tidak berarti sejak hari pertama. Command `reservations:import` dan seluruh
penanganan CSV dihapus dari scope.

### 2.6 Satu area per reservasi

`reservations.area_id` adalah foreign key nullable ke `areas`. Tidak ada tabel pivot
dan tidak ada kolom biaya venue.

Alasan: pada spreadsheet, angka `0` di kolom area dinyatakan tidak memiliki arti, jadi
penempatan area lama tidak dapat dipercaya dan tidak dimigrasikan. Aturan baru
ditetapkan dari nol dalam bentuk yang paling sederhana.

**Batasan yang diterima:** event yang memakai beberapa area sekaligus — misalnya
DHARMADI pada 9 Agustus yang memakai VIP 1, VIP 2, dan FOYER — tidak tertampung dalam
satu field. Kasus seperti ini ditulis di REMARK. Jika di kemudian hari kebutuhan
multi-area terbukti sering, perubahan ke tabel pivot bersifat aditif dan tidak
membongkar tabel `reservations`.

---

## 3. Model data

### 3.1 `users`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `name` | varchar(100) | |
| `email` | varchar(150) unique | |
| `password` | varchar(255) | |
| `is_active` | boolean | default `true`. `false` berarti staf sudah tidak bekerja: tidak bisa login dan tidak muncul di dropdown PIC untuk reservasi baru. Reservasi lama yang menunjuk user tersebut tetap menampilkan namanya. |
| `timestamps` | | |

**Tidak ada kolom `role`.** Role disimpan `spatie/laravel-permission` di
`model_has_roles`. Menyimpannya di dua tempat akan menghasilkan dua sumber kebenaran
yang bisa berbeda tanpa ketahuan.

`is_active` **bukan** permission melainkan status akun, karena itu tetap kolom biasa
dan diperiksa terpisah di setiap Policy. Pengguna nonaktif tidak boleh melakukan apa
pun meski rolenya masih memuat kemampuan.

### 3.1.1 Tabel spatie

Lima tabel dari `vendor:publish` paket: `roles`, `permissions`, `model_has_roles`,
`model_has_permissions`, `role_has_permissions`. Isi awalnya dibuat
`RolePermissionSeeder` dari daftar `App\Enums\Ability`.

### 3.2 `areas`

| Kolom | Tipe |
|---|---|
| `id` | bigint PK |
| `name` | varchar(80) |
| `sort_order` | int, default 0 |
| `is_active` | boolean, default true |
| `timestamps` | |

Seed awal: VIP 1, VIP 2, FOYER FnB, KORIDOR, SOFA REGULAR, REGULAR, OUTDOOR.

### 3.3 `event_types`

Struktur identik dengan `areas`.
Seed awal: TEST FOOD, PRIVATE, MEETING, LUNCH, DINNER, GATHERING.

### 3.4 `menu_styles`

Struktur identik dengan `areas`.
Seed awal: BUFFET, AL CARTE.

### 3.5 `reservations`

| Kolom | Tipe | Wajib | Keterangan |
|---|---|---|---|
| `id` | bigint PK | | |
| `reservation_date` | date | **ya** | tanggal reservasi |
| `guest_name` | varchar(150) | **ya** | NAMA |
| `company` | varchar(150) | tidak | |
| `phone` | varchar(30) | **ya** | HP, disimpan sebagai digit |
| `email` | varchar(150) | tidak | |
| `pic_id` | FK `users` | **ya** | SALES / PIC |
| `event_type_id` | FK `event_types` | tidak | |
| `menu_style_id` | FK `menu_styles` | tidak | |
| `area_id` | FK `areas` | tidak | |
| `start_time` | time | **ya** | jam mulai |
| `end_time` | time | tidak | diisi hanya jika reservasi punya rentang |
| `pax` | unsigned int | **ya** | jumlah tamu |
| `status` | enum(`tentative`,`confirmed`) nullable | tidak | `NULL` = belum ditentukan |
| `remark` | text | tidak | catatan bebas |
| `version` | unsigned int | **ya** | default 1, naik tiap update. Optimistic lock — lihat bagian 9 |
| `idempotency_key` | char(36) nullable unique | tidak | UUID dari form, mencegah double-submit — lihat bagian 9 |
| `dedupe_key` | varchar(191) nullable unique | | kolom generated, mencegah duplikat — lihat bagian 9 |
| `created_by` | FK `users` | **ya** | |
| `updated_by` | FK `users` nullable | tidak | |
| `deleted_at` | timestamp nullable | | soft delete |
| `timestamps` | | | |

Index: `reservation_date`, `pic_id`, `status`.

Semua foreign key master (`event_type_id`, `menu_style_id`, `area_id`, `pic_id`)
memakai `restrictOnDelete` — baris master yang sudah dipakai tidak bisa dihapus,
hanya bisa dinonaktifkan lewat `is_active`.

### 3.6 `activity_log`

Tabel bawaan `spatie/laravel-activitylog`, dibuat lewat migration paket.

### 3.7 Catatan tentang PAX

Kolom `pax` adalah satu integer, bukan rentang. Kasus seperti `80 s/d 120` pada data
lama diinput sebagai `80` dan rentangnya ditulis di REMARK. Rencana awal
`pax_min`/`pax_max` dibuang karena spesifikasi menyebut PAX sebagai satu angka.

### 3.8 Penanganan TIME BOOKING: jam tunggal dan rentang

Data operasional memakai dua pola: jam tunggal (`11.00`, `17.00`) dan rentang
(`12.00-15.00`, `11.00-13.00`). Keduanya ditangani oleh **satu struktur**, bukan dua.

| | `start_time` | `end_time` |
|---|---|---|
| Jam tunggal | wajib | `NULL` |
| Rentang | wajib | terisi |

`end_time IS NULL` **adalah** penanda bahwa reservasi berjam tunggal. Tidak ada kolom
`is_range` atau sejenisnya — kolom penanda terpisah hanya menciptakan kemungkinan
kondisi tidak konsisten (`is_range = true` tetapi `end_time` kosong).

**Input.** Form menampilkan satu input jam mulai, ditambah checkbox "sampai jam".
Saat dicentang, input kedua muncul; saat dihilangkan centangnya, `end_time` dikosongkan
kembali menjadi `NULL`. Staf tidak perlu memilih "mode" apa pun terlebih dahulu.

**Validasi.** `end_time` bersifat `nullable` dengan aturan `after:start_time`.
Reservasi yang melewati tengah malam tidak didukung di v1 — jam operasional restoran
tidak memerlukannya, dan mendukungnya akan memaksa `end_time` menjadi datetime penuh.

**Tampilan.** Satu helper dipakai di seluruh aplikasi agar formatnya konsisten:
`end_time` kosong dirender sebagai `11.00`, terisi dirender sebagai `12.00–15.00`.

**Parsing input dari staf.** `prepareForValidation()` menerima variasi penulisan
`11.00`, `11:00`, dan `11` lalu menormalkannya ke `H:i`. Jika staf mengetik rentang
sekaligus di kolom jam mulai (misalnya `12.00-15.00`), string dipecah pada tanda
hubung dan bagian kedua mengisi `end_time`. Ini mencegah kebiasaan lama dari
spreadsheet menghasilkan data yang gagal validasi.

**Dampak pada pengecekan bentrok.** Lihat bagian 8 — reservasi tanpa `end_time`
memerlukan asumsi durasi.

---

## 4. Halaman dan rute

Seluruh halaman berada di dalam panel Filament di `/cms`. Tidak ada route manual
selain pengalihan dari `/`.

```
GET  /                    redirect ke /cms
     /cms/login         halaman login bawaan panel Filament

     /cms/reservations              ListReservations, tab per bulan
     /cms/reservations/create       CreateReservation
     /cms/reservations/{id}         ViewReservation, detail + riwayat
     /cms/reservations/{id}/edit    EditReservation

     /cms/reservation-calendar      custom Page, grid bulanan

     /cms/areas                     simple resource   [master.manage]
     /cms/event-types               simple resource   [master.manage]
     /cms/menu-styles               simple resource   [master.manage]
     /cms/users                     resource          [user.manage]
     /cms/roles                     simple resource   [role.manage]
```

Ketiga master berbagi struktur kolom yang identik (`name`, `sort_order`, `is_active`)
dan memakai **simple resource** — seluruh CRUD terjadi lewat modal pada satu halaman.
Halaman Create dan Edit terpisah hanya menambah klik tanpa memberi manfaat untuk tabel
sekecil ini.

Resource tidak muncul di navigasi bila `viewAny()` pada Policy-nya menolak. Tidak ada
middleware tambahan; Filament membaca Model Policy secara otomatis.

### 4.1 Daftar reservasi

`ListReservations` memakai **tab bulan** — tiga bulan ke belakang sampai tiga bulan ke
depan, ditambah tab "Semua". Bulan berjalan aktif secara bawaan, dan pilihan tab
tersimpan di URL sehingga bisa ditautkan.

Tidak ada pagination (`->paginated(false)`), sesuai keputusan skala pada bagian 1.

### 4.1.1 Dua tampilan

**Tabel** — `ListReservations`. Satu baris per reservasi berisi Tanggal, Jam, Nama,
PIC, HP, Event, Area, Pax, Status, ditambah baris REMARK selebar tabel di bawahnya
sesuai aturan bagian 4.3. Cocok untuk membaca banyak reservasi sekaligus dan menyisir
catatan.

**Kalender** — halaman tersendiri `ReservationCalendar`, grid bulanan tujuh kolom
dengan minggu dimulai Senin. Tiap sel berisi chip ringkas bertuliskan jam mulai dan
nama tamu, diurutkan menurut jam. Garis kiri chip menandai status: penuh untuk
`CONFIRMED`, putus-putus untuk `TENTATIVE`, abu-abu untuk yang belum ditentukan.

Chip sengaja ringkas karena ruang sel terbatas — dan karena remark tidak boleh
dipotong, remark **tidak** ditampilkan di dalam chip. Mengklik chip membuka **panel
detail** di bawah grid yang menampilkan seluruh field beserta remark utuh, sekaligus
menjadi jalan masuk ke halaman detail dan edit.

Keduanya adalah halaman terpisah, bukan toggle dalam satu halaman. Filament memuat
datanya sendiri per halaman lewat Livewire, sehingga berbagi state antar mode tidak
memberi keuntungan seperti pada arsitektur klien.

**Kalender dibuat sendiri dengan CSS Grid, bukan memakai pustaka.** Yang dibutuhkan
hanya menempatkan chip pada grid bulanan dan menangani klik — tidak ada penjadwalan,
drag-drop, maupun sinkronisasi yang memerlukan pustaka.

### 4.2 Berkas Filament

| Berkas | Tanggung jawab |
|---|---|
| `Resources/Reservations/ReservationResource.php` | Titik masuk resource |
| `Resources/Reservations/Schemas/ReservationForm.php` | Skema form |
| `Resources/Reservations/Schemas/ReservationInfolist.php` | Skema halaman View |
| `Resources/Reservations/Tables/ReservationsTable.php` | Kolom, filter, `Panel` remark |
| `Resources/Reservations/Pages/ListReservations.php` | Tab bulan |
| `Resources/Reservations/Pages/CreateReservation.php` | Override `handleRecordCreation()` |
| `Resources/Reservations/Pages/EditReservation.php` | Override `handleRecordUpdate()` |
| `Resources/Reservations/Pages/ViewReservation.php` | Detail + riwayat |
| `Pages/ReservationCalendar.php` + Blade | Grid bulanan |
| `views/filament/audit-timeline.blade.php` | Riwayat perubahan |
| `Resources/{Areas,EventTypes,MenuStyles}/*` | Simple resource master |
| `Resources/Users/*`, `Resources/Roles/*` | Pengguna dan role |

**Penyimpanan reservasi tidak diserahkan ke Filament.** `CreateReservation` dan
`EditReservation` meng-override `handleRecordCreation()` dan `handleRecordUpdate()`
agar melewati `ReservationWriter`. Tanpa override itu, Filament menyimpan langsung ke
model dan seluruh perlindungan pada bagian 9 — idempotency, optimistic lock, penolakan
duplikat — terlewati tanpa menghasilkan error apa pun.

### 4.3 Penanganan REMARK

**Aturan wajib: remark selalu ditampilkan penuh. Tidak boleh dipotong, disembunyikan
di balik hover, atau disembunyikan di balik tombol.**

Karena itu remark tidak menjadi kolom pada baris tabel. Teks bebas yang panjang di
dalam satu sel akan menekan lebar kolom lain dan membuat seluruh tabel sulit dibaca.
Sebagai gantinya, setiap reservasi dirender sebagai **dua baris** `<tr>`:

1. Baris data — Tanggal, Jam, Nama, HP, PIC, Event, Menu, Area, Pax, Status, aksi.
2. Baris remark — satu `<td colspan>` selebar tabel, berisi teks remark utuh dengan
   `white-space: pre-line` sehingga baris baru yang diketik staf tetap terjaga.
   Diberi garis aksen di sisi kiri dan label `REMARK` agar terbaca sebagai catatan,
   bukan sebagai data kolom.

Baris remark **tidak dirender** jika `remark` kosong, sehingga tidak ada ruang
terbuang untuk reservasi tanpa catatan.

Konsekuensi yang diterima: tinggi tabel bertambah. Pada volume ± 15 reservasi per
bulan, tabel terpanjang sekitar 30 baris — masih nyaman di satu layar gulir dan tidak
memerlukan pagination.

| Tampilan | Perlakuan |
|---|---|
| Form (create/edit) | `<textarea>` 4 baris, tanpa batas panjang |
| Tabel Index | baris kedua selebar tabel, teks utuh |
| Chip kalender | tidak ditampilkan — ruang sel tidak cukup untuk teks utuh, dan memotongnya melanggar aturan. Diakses lewat panel detail |
| Panel detail kalender | blok tersendiri, teks utuh |
| Halaman detail | blok tersendiri, teks utuh |
| Cetak / ekspor | ikut tercetak sebagai baris kedua |

Pada timeline audit, perubahan remark ditampilkan **utuh** sebagai dua blok
bertumpuk — nilai lama di atas dengan penanda coret, nilai baru di bawah — bukan
dalam satu baris berdampingan. Ini menjaga aturan "remark selalu tampil penuh"
sekaligus tetap terbaca.

`PicCombobox` memakai `@headlessui/react` yang sudah ada di `package.json` — tidak
perlu dependency baru.

---

## 5. Validasi

Karena UI memakai Filament, **tidak ada `FormRequest`**. Aturan wajib dan panjang
maksimum ditulis langsung pada field Filament (`->required()`, `->maxLength()`,
`->numeric()`, `->minValue()`), yang setara dengan daftar berikut:

```php
'reservation_date' => ['required', 'date'],
'guest_name'       => ['required', 'string', 'max:150'],
'company'          => ['nullable', 'string', 'max:150'],
'phone'            => ['required', 'string', 'max:30'],
'email'            => ['nullable', 'email', 'max:150'],
'pic_id'           => ['required', 'exists:users,id'],
'event_type_id'    => ['nullable', 'exists:event_types,id'],
'menu_style_id'    => ['nullable', 'exists:menu_styles,id'],
'area_id'          => ['nullable', 'exists:areas,id'],
'start_time'       => ['required', 'date_format:H:i'],
'end_time'         => ['nullable', 'date_format:H:i', 'after:start_time'],
'pax'              => ['required', 'integer', 'min:1'],
'status'           => ['nullable', Rule::enum(ReservationStatus::class)],
'remark'           => ['nullable', 'string'],
```

Normalisasi berjalan di `App\Support\ReservationInput::normalize()`, sebuah kelas
murni tanpa framework yang dipanggil dari `mutateFormDataBeforeCreate()` dan
`mutateFormDataBeforeSave()`:

- `phone` — buang semua karakter selain digit. String `NA`, `-`, atau kosong menjadi
  `null` dan kemudian ditolak oleh `->required()` pada field.
- `guest_name`, `company`, `remark` — `trim`, kosong menjadi `null`.
- `email` — `trim` dan huruf kecil; string `NA` menjadi `null`.
- `start_time` — dinormalkan ke `H:i`, dan bila berisi rentang `12.00-15.00` dipecah
  menjadi `start_time` dan `end_time`.

Memisahkannya sebagai kelas murni membuat seluruh aturan di atas bisa diuji tanpa
menjalankan Livewire.

Ubah status menjadi `confirmed` divalidasi di Policy, bukan di lapisan form.

---

## 6. Hak akses

`ReservationPolicy` — setiap method memeriksa dua hal: akun aktif, dan role memuat
`Ability` yang diminta.

```php
private function allows(User $u, Ability $a): bool
{
    return $u->is_active && $u->can($a->value);
}

viewAny(User $u)                    => allows($u, Ability::ViewReservation)
view(User $u, Reservation $r)       => allows($u, Ability::ViewReservation)
create(User $u)                     => allows($u, Ability::CreateReservation)
update(User $u, Reservation $r)     => allows($u, Ability::UpdateReservation)
delete(User $u, Reservation $r)     => allows($u, Ability::DeleteReservation)
deleteAny(User $u)                  => allows($u, Ability::DeleteReservation)
confirm(User $u, ?Reservation $r)   => allows($u, Ability::ConfirmReservation)
```

**Nama role tidak pernah disebut di Policy.** Ini yang membuat role baru langsung
bekerja tanpa perubahan kode, dan dikunci oleh test
`test_a_new_role_works_without_changing_any_policy`.

`deleteAny()` wajib ada karena Filament memakainya untuk `DeleteBulkAction`; tanpa
method itu tombol hapus massal tetap muncul bagi yang tidak berhak.

`confirm` menerima `?Reservation` nullable karena halaman Create memanggilnya untuk
reservasi yang barisnya belum ada.

`AreaPolicy`, `EventTypePolicy`, dan `MenuStylePolicy` memakai `Ability::ManageMaster`;
`UserPolicy` memakai `Ability::ManageUser`; `RolePolicy` memakai `Ability::ManageRole`.
`UserPolicy::delete()` selalu mengembalikan `false` — pengguna dinonaktifkan, tidak
dihapus.

Tidak ada middleware otorisasi. Filament membaca Model Policy secara otomatis dan
menyembunyikan resource dari navigasi ketika `viewAny()` menolak. Satu pengecualian:
`Spatie\Permission\Models\Role` berada di luar `App\Models`, sehingga `RolePolicy`
harus didaftarkan manual lewat `Gate::policy()` di `AppServiceProvider`.

---

## 7. Audit trail

Model `Reservation` mengimplementasikan `LogsActivity` dari
`spatie/laravel-activitylog`:

- `logOnly([...])` — seluruh kolom bisnis, tanpa `created_by`/`updated_by`/timestamps
- `logOnlyDirty()` — hanya kolom yang benar-benar berubah
- `dontSubmitEmptyLogs()`
- causer diisi otomatis dari user yang sedang login

Halaman detail menampilkan timeline terbalik, misalnya:

```
Ira mengubah PAX 5 → 8                      10 Agu 2026, 14:32
Cassie mengubah STATUS — → TENTATIVE        09 Agu 2026, 11:05
Arif membuat reservasi                      08 Agu 2026, 16:20
```

Untuk kolom foreign key, nilai lama dan baru ditampilkan sebagai nama, bukan id.
Riwayat dirender oleh `resources/views/filament/audit-timeline.blade.php` yang
disisipkan ke halaman View lewat komponen `View` pada infolist.

---

## 8. Peringatan bentrok area

Saat menyimpan atau memperbarui, jika `area_id` terisi, jalankan satu query:

```php
Reservation::where('area_id', $areaId)
    ->whereDate('reservation_date', $date)
    ->when($id, fn ($q) => $q->whereKeyNot($id))
    ->where(function ($q) use ($start, $end) {
        $q->where('start_time', '<', $end ?? '23:59')
          ->where(fn ($q2) => $q2->whereNull('end_time')
                                 ->orWhere('end_time', '>', $start));
    })
    ->get();
```

### 8.1 Durasi asumsi untuk reservasi berjam tunggal

Reservasi tanpa `end_time` tidak punya akhir yang tercatat, sehingga pengecekan
tumpang tindih memerlukan asumsi durasi.

**Asumsi yang dipakai: 2 jam**, disimpan di `config('reservation.default_duration')`
agar bisa diubah tanpa deploy.

Pilihan "berlangsung sampai akhir hari" sempat dipertimbangkan dan **ditolak**: Ibu
There jam 12.00 akan dianggap bentrok dengan David Pribadi jam 18.00 di area yang
sama, padahal jelas tidak. Peringatan palsu sebanyak itu membuat staf mengabaikan
seluruh peringatan, termasuk yang benar — kegagalan yang lebih buruk daripada tidak
punya peringatan sama sekali.

Asumsi ini **hanya** memengaruhi kapan peringatan muncul. Nilai yang tersimpan di
database tidak berubah, dan `end_time` tetap `NULL`.

### 8.2 Sifat non-blocking

Hasilnya **tidak memblokir penyimpanan**. Data tetap tersimpan, lalu redirect
membawa flash prop `warnings` yang dirender sebagai banner kuning berisi daftar
reservasi yang bertabrakan.

Alasan tidak memblokir: bentrok area tidak dipilih sebagai masalah yang harus
dipecahkan, dan memblokir penyimpanan berisiko menghalangi staf mencatat reservasi
nyata yang memang sengaja tumpang tindih. Mengubahnya menjadi blocking di kemudian
hari hanya perlu mengganti flash message dengan `ValidationException`.

---

## 9. Pencegahan duplikat dan race condition

Persyaratan: tidak boleh ada penginputan ganda saat dua orang menyimpan bersamaan.

Istilah "race condition" di sini mencakup tiga masalah berbeda yang memerlukan
mitigasi berbeda. Menyamakan ketiganya adalah penyebab paling umum sistem tetap bocor
meski merasa sudah aman.

**Prinsip dasar yang mengikat seluruh bagian ini:** memeriksa "apakah sudah ada?" di
kode aplikasi lalu melakukan insert **tidak** menyelesaikan race condition. Di antara
`SELECT` dan `INSERT` selalu ada celah, dan dua request bersamaan dapat lolos
keduanya. Satu-satunya mitigasi yang tahan adalah constraint di level database.
Validasi di aplikasi hanya berfungsi untuk menghasilkan pesan error yang terbaca.

### 9.1 Lapisan mitigasi

| Lapis | Mekanisme | Menangani | Dipercaya |
|---|---|---|---|
| 1 | `processing` menonaktifkan tombol Simpan | Klik ganda | Tidak — hanya UX |
| 2 | `idempotency_key` UUID + UNIQUE | Double-submit, refresh, retry jaringan | Ya |
| 3 | `dedupe_key` generated + UNIQUE | Duplikat semantik | Ya |
| 4 | Kolom `version` + `lockForUpdate` | Lost update saat edit | Ya |
| 5 | `DB::transaction()` | Konsistensi saat gagal sebagian | Ya |

### 9.2 Lapis 2 — idempotency key

Form Create membangkitkan satu UUID saat komponen pertama kali dirender
(`useState(() => crypto.randomUUID())`) dan mengirimnya sebagai field tersembunyi.
UUID hanya diganti setelah penyimpanan berhasil.

Perilaku server saat menerima `idempotency_key` yang sudah ada: **bukan error**.
Server mencari reservasi dengan key tersebut dan melakukan redirect ke halaman
detailnya, sehingga hasil submit kedua identik dengan submit pertama. Ini definisi
idempoten yang benar — pengguna yang menekan Simpan dua kali melihat satu reservasi,
bukan pesan kesalahan.

### 9.3 Lapis 3 — kunci duplikat

Dua reservasi dianggap duplikat jika **tanggal, nama tamu, dan jam mulai** sama.

Constraint tidak dipasang langsung pada ketiga kolom itu, melainkan pada kolom
generated, karena dua alasan:

1. Reservasi yang sudah di-soft-delete tidak boleh ikut menghalangi. Jika reservasi
   dihapus lalu diinput ulang dengan data sama, penyimpanan harus berhasil.
2. Perbedaan huruf besar-kecil dan spasi di ujung tidak boleh meloloskan duplikat
   (`Bapak Wanda` versus `bapak wanda `).

```php
$table->string('dedupe_key', 191)->nullable()->storedAs(
    "IF(deleted_at IS NULL,
        CONCAT_WS('|', reservation_date, LOWER(TRIM(guest_name)), start_time),
        NULL)"
);
$table->unique('dedupe_key', 'uniq_reservations_dedupe');
```

Kolom bernilai `NULL` ketika baris ter-soft-delete, dan MySQL tidak menganggap `NULL`
sebagai duplikat pada UNIQUE index — sehingga baris terhapus otomatis keluar dari
constraint tanpa kode tambahan.

Seluruh fungsi yang dipakai (`IF`, `CONCAT_WS`, `LOWER`, `TRIM`) bersifat
deterministik dan diizinkan pada generated column.

**Perlu diverifikasi sebelum implementasi:** versi MySQL di server produksi.
Generated stored column memerlukan MySQL 5.7 ke atas; MySQL 8 aman.

**Batasan yang diterima:** satu tamu dengan dua grup terpisah pada tanggal dan jam
mulai yang sama akan ditolak. Penanganannya adalah membedakan nama, misalnya
`Bapak Wanda (Grup A)`. Ini konsekuensi yang disadari saat memilih kunci duplikat ini.

### 9.4 Lapis 4 — lost update saat edit

Skenario yang dicegah: Ira dan Cassie sama-sama membuka form edit reservasi #5. Ira
menyimpan `pax = 8`. Cassie kemudian menyimpan `pax = 10` dari form yang dimuat
sebelum perubahan Ira. Tanpa mitigasi, perubahan Ira hilang tanpa jejak — dan ini
merusak audit trail yang menjadi alasan utama sistem dibangun.

Form edit membawa nilai `version` yang dimuat. Saat menyimpan:

```php
DB::transaction(function () use ($data, $id, $expectedVersion) {
    $r = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();

    if ($r->version !== $expectedVersion) {
        throw ValidationException::withMessages([
            'version' => 'Reservasi ini baru saja diubah orang lain. '
                       . 'Muat ulang halaman untuk melihat perubahan terbaru.',
        ]);
    }

    $r->fill($data);
    $r->version = $r->version + 1;
    $r->updated_by = auth()->id();
    $r->save();
});
```

Dua keputusan implementasi yang penting:

- **Memakai kolom `version`, bukan `updated_at`.** Kolom TIMESTAMP MySQL secara
  default berpresisi detik, sehingga dua update dalam detik yang sama tidak
  terdeteksi. Integer selalu tepat.
- **Memakai `$r->save()`, bukan `Model::where(...)->update(...)`.** Update massal
  tidak memicu event model, sehingga `spatie/laravel-activitylog` tidak akan mencatat
  perubahan. Ini jebakan yang mudah terlewat dan akan membuat audit trail bolong
  secara diam-diam.

`lockForUpdate()` menahan baris sampai transaksi selesai. Pada volume ± 15 reservasi
per bulan, kontensi praktis nol.

### 9.5 Menerjemahkan pelanggaran constraint menjadi pesan

`QueryException` dengan SQLSTATE 23000 ditangkap di `ReservationWriter`, diubah
menjadi `DuplicateReservationException`, lalu diterjemahkan menjadi
`ValidationException` oleh halaman Create dan Edit Filament. Pemetaannya berdasarkan
nama index:

| Index | Respons |
|---|---|
| `reservations_idempotency_key_unique` | Redirect ke reservasi yang sudah ada. Bukan error |
| `uniq_reservations_dedupe` | 422 dengan pesan "Sudah ada reservasi atas nama {nama} pada {tanggal} jam {jam}." disertai tautan ke reservasi tersebut |

Tanpa pemetaan ini, pengguna akan melihat halaman error 500.

### 9.6 Interaksi dengan pengecekan bentrok area

Pengecekan bentrok area (bagian 8) dijalankan **setelah** transaksi commit, bukan di
dalamnya. Peringatan itu bersifat informatif dan tidak memblokir, sehingga tidak perlu
menahan lock lebih lama. Jika di kemudian hari peringatan diubah menjadi blocking,
pengecekan harus dipindahkan ke dalam transaksi dan memakai `lockForUpdate` pada
rentang baris yang relevan.

---

## 10. Yang secara eksplisit tidak masuk v1

| Dibuang | Alasan |
|---|---|
| BEO / quotation menu (Sheet2) | Diputuskan sebagai fase 2 |
| Master menu dan harga | Bagian dari BEO |
| Halaman laporan dan rekap | Tidak dipilih sebagai masalah utama |
| Pustaka FullCalendar | Tampilan kalender **masuk** v1, tetapi dibangun dengan CSS Grid. Yang dibuang adalah pustakanya, bukan fiturnya |
| Tampilan kalender mingguan dan harian | Hanya grid bulanan di v1 |
| Drag-drop untuk memindahkan reservasi | Perubahan tanggal dan jam lewat form edit, agar tetap melewati validasi dan optimistic lock |
| Command import CSV/XLSX | Data lama tidak dimigrasikan |
| Multi-area per reservasi | Satu dropdown dinilai cukup |
| Biaya venue per area | Angka pada spreadsheet dinyatakan tidak bermakna |
| Notifikasi email / WhatsApp | Tidak diminta |
| Portal tamu | Semua pengguna internal |
| Pagination, caching, queue | Volume ± 15 baris per bulan |

Relasi ke fase 2 disiapkan tanpa membuat tabelnya: BEO nantinya cukup menambahkan
`beos.reservation_id` yang menunjuk ke `reservations.id`.

---

## 11. Testing

Feature test (Pest atau PHPUnit, mengikuti konfigurasi repositori):

1. Staf dapat membuat reservasi dengan data valid.
2. Reservasi ditolak jika `guest_name`, `reservation_date`, `phone`, `pic_id`,
   `start_time`, atau `pax` kosong.
3. `phone` bernilai `NA` ditolak setelah normalisasi.
4. `end_time` lebih awal dari `start_time` ditolak.
5. Staf tidak dapat menghapus reservasi (403).
6. Staf tidak dapat mengubah status menjadi `confirmed` (403).
7. Admin dapat melakukan keduanya.
8. Mengubah `pax` menghasilkan satu entri `activity_log` dengan causer yang benar.
9. Menyimpan reservasi yang bertabrakan area tetap berhasil dan mengembalikan
   flash `warnings`.
10. Baris master yang sedang dipakai tidak dapat dihapus.

Test khusus untuk bagian 9 — bagian ini yang paling mudah terlihat benar padahal
salah, jadi harus diuji secara eksplisit:

11. Menyimpan dua reservasi dengan tanggal, nama, dan jam mulai sama ditolak, dan
    pesan errornya menyebut nama serta tanggal, bukan error 500.
12. `Bapak Wanda` dan `bapak wanda ` pada tanggal dan jam yang sama dianggap
    duplikat — membuktikan normalisasi `LOWER(TRIM())` bekerja.
13. Reservasi di-soft-delete, lalu data yang sama diinput ulang — harus **berhasil**.
    Ini membuktikan `dedupe_key` menjadi `NULL` saat terhapus.
14. Submit dua kali dengan `idempotency_key` sama menghasilkan satu record, dan
    request kedua menghasilkan redirect ke record tersebut, bukan error.
15. Update dengan `version` yang sudah basi ditolak, dan data di database tidak
    berubah sama sekali.
16. Update yang berhasil menaikkan `version` tepat satu dan mencatat satu entri
    `activity_log` — membuktikan `save()` memicu event, bukan update massal.

Test untuk penanganan jam (bagian 3.8 dan 8.1):

17. Menyimpan `start_time` tanpa `end_time` berhasil, dan `end_time` tersimpan `NULL`.
18. `end_time` lebih awal atau sama dengan `start_time` ditolak.
19. Input `12.00-15.00` yang diketik pada kolom jam mulai terpecah menjadi
    `start_time = 12:00` dan `end_time = 15:00`.
20. Variasi penulisan `11`, `11.00`, dan `11:00` semuanya tersimpan sebagai `11:00`.
21. Dua reservasi berjam tunggal di area sama, pukul 12.00 dan 18.00, **tidak**
    menghasilkan peringatan bentrok — membuktikan asumsi durasi 2 jam dipakai, bukan
    sampai akhir hari.
22. Reservasi berjam tunggal pukul 12.00 dan 13.00 di area sama **menghasilkan**
    peringatan.

Test 11 dan 14 dijalankan lewat pemanggilan berurutan, bukan proses paralel. Yang
diuji adalah constraint database dan penanganan errornya; jaminan atas eksekusi
bersamaan datang dari constraint itu sendiri, bukan dari test.

Tidak ada test frontend di v1. Untuk tim sekecil ini, biaya penyiapannya melebihi
manfaat yang realistis.

---

## 12. Risiko dan asumsi

| Hal | Catatan |
|---|---|
| Volume 15/bulan | Diambil dari satu sheet saja (Agustus 2026). Jika volume sebenarnya jauh lebih besar, keputusan "tanpa pagination" perlu ditinjau ulang. Ambang aman: sekitar 300 baris per bulan. |
| Satu area per reservasi | Sudah diketahui tidak menampung event multi-area seperti DHARMADI. Diterima sebagai batasan v1. |
| Data lama tidak diimpor | Riwayat sebelum go-live hanya ada di Excel. |
| Nama PIC sebagai user | Mengasumsikan IBU MARLUCE dan nama sejenis memang orang, bukan label peran. Jika ternyata label, perlu penyesuaian. |
| Ketergantungan pada Filament | Kustomisasi di luar jalur yang disediakan Filament menjadi mahal. Sejauh ini hanya kalender yang keluar jalur, dan itu dibangun sebagai custom Page. |
| Cache permission spatie | Perubahan hak akses tidak berlaku sampai cache dibersihkan. Ditangani dengan memanggil `forgetCachedPermissions()` setiap kali role disimpan. Kalau terlewat, gejalanya terlihat seperti "sistem tidak menyimpan perubahan". |
| Verifikasi API Filament | Dua pemanggilan belum diverifikasi langsung: `Panel::make()->visible()` dengan closure `$record`, dan namespace `Tabs\Tab` untuk tab bulan. Keduanya ditandai di rencana beserta jalur cadangannya. |
| Versi MySQL produksi | `dedupe_key` memerlukan generated stored column, tersedia sejak MySQL 5.7. **Harus dipastikan sebelum implementasi.** Jika server memakai versi lebih lama, kolom diisi lewat model event dan keunikan tetap dijaga UNIQUE index — sedikit lebih rapuh karena bergantung pada kode aplikasi untuk mengisi nilai. |
| Kunci duplikat menolak reservasi sah | Satu tamu dengan dua grup berbeda pada tanggal dan jam mulai sama akan ditolak. Penanganan: bedakan nama. Perlu diberitahukan ke staf saat pelatihan agar tidak dianggap bug. |

---

## 13. Langkah berikutnya

Setelah spec ini disetujui, lanjut ke penyusunan rencana implementasi bertahap
(skill `writing-plans`).
