# Desain — Kalender Publik dan Nomor Reservasi (v2)

Tanggal: 2026-08-20
Melanjutkan: `claude/2026-08-10-reservasi-roemah-umara-design.md` (v1, Task 0–18 selesai)

---

## 1. Konteks

Sistem v1 seluruhnya berada di balik login. Dokumen ini menambahkan dua hal yang
diminta setelah v1 berjalan:

1. **Kalender publik** — halaman tanpa login yang menampilkan tanggal-tanggal yang
   sudah dipesan, agar calon tamu bisa melihat sendiri apakah tanggal yang
   diinginkannya masih terbuka.
2. **Nomor reservasi** — pengenal berurutan berformat `RU-R1`, `RU-R2`, dan
   seterusnya, untuk dipakai staf saat merujuk sebuah reservasi.

Keduanya tidak saling bergantung dan bisa dikerjakan terpisah, tetapi diminta
bersamaan sehingga dirancang dalam satu dokumen.

### 1.1 Yang berubah dari keputusan v1

Bagian 2.2 v1 berjudul **"Semua di balik login"**. Dokumen ini melanggarnya secara
sadar dan terbatas: satu halaman baca-saja terbuka untuk umum, dan halaman itu
**tidak pernah memuat data pribadi tamu**. Seluruh halaman lain tetap di balik login.

Batas ini bukan sekadar niat, melainkan ditegakkan di lapisan query — lihat bagian 5.2.

---

## 2. Keputusan arsitektur

### 2.1 Halaman publik hanya menampilkan ketersediaan

Yang boleh terlihat umum: **tanggal, jam, area, jenis acara, status**.

Yang tidak pernah keluar: **nama tamu, perusahaan, nomor HP, email, remark, pax, PIC**.

Alasannya konkret. Remark pada data nyata memuat kalimat seperti
`"Sudah DP 50%. Sisa dilunasi H-3."` dan `"Grand total sudah termasuk tax & service 21%."`
Itu kesepakatan komersial, bukan informasi ketersediaan. Nomor HP tamu apalagi.

Kalender ketersediaan tetap berguna penuh tanpa semua itu: calon tamu cukup tahu
"VIP 1 terisi 12:00–15:00 pada 8 Agustus".

### 2.2 Tiga status dibedakan, tidak disamakan dan tidak disembunyikan

CONFIRMED ditampilkan tegas sebagai terisi. TENTATIVE dan yang belum berstatus
ditampilkan berbeda sebagai "sedang dijajaki".

Menyamakan ketiganya membuat tanggal yang sebenarnya masih terbuka terlihat tertutup.
Menyembunyikan yang belum CONFIRMED membuat staf menerima pertanyaan untuk tanggal
yang hampir pasti terpakai. Membedakannya menyelesaikan keduanya sekaligus.

### 2.3 Nomor reservasi harus rapat, bukan sekadar unik

Nomor diambil dari tabel penghitung sendiri, bukan dari `id` tabel `reservations`.

Alasannya adalah interaksi dengan constraint duplikat dari v1 Task 5. Setiap kali
seseorang tidak sengaja menyimpan reservasi ganda, InnoDB **sudah terlanjur memakai**
satu nilai `AUTO_INCREMENT` sebelum menolak barisnya. Nomor yang diturunkan dari `id`
karena itu akan bolong: `RU-R1`, `RU-R2`, lalu `RU-R5`. Datanya benar, tetapi terlihat
seperti ada tiga reservasi yang hilang, dan itu pertanyaan yang akan terus muncul.

Penghitung sendiri tidak punya masalah itu, karena kenaikannya berada di dalam
transaksi yang sama dengan penyimpanannya — ikut mundur ketika penyimpanan gagal.

Diverifikasi 2026-08-20: MySQL 5.7.24 **menolak** generated column yang merujuk kolom
`AUTO_INCREMENT` (error 3109), sehingga menurunkan nomor dari `id` lewat generated
column — cara termurah yang sempat dipertimbangkan — memang tidak tersedia.

### 2.4 Halaman publik tidak memakai Livewire

Halaman ini baca-saja, tidak punya form, dan tidak butuh pembaruan sebagian.
Blade biasa dengan state di URL sudah cukup, lebih ringan, dan hasilnya bisa
ditautkan serta di-bookmark.

---

## 3. Model data

### 3.1 Tabel baru `counters`

```
id          BIGINT UNSIGNED PK
name        VARCHAR(50) NOT NULL UNIQUE
value       BIGINT UNSIGNED NOT NULL DEFAULT 0
timestamps
```

Berisi satu baris: `('reservation', 0)`, dibuat oleh migration.

Tabel ini sengaja bernama umum, bukan `reservation_counters`, agar penghitung lain
di masa depan tidak memerlukan tabel baru.

### 3.2 Kolom baru `reservations.reservation_number`

```
reservation_number  VARCHAR(20) NOT NULL UNIQUE   -- 'RU-R1'
```

Diletakkan setelah `id`. Diisi sekali saat pembuatan dan **tidak pernah berubah**
saat pengeditan.

Index UNIQUE-nya bukan hiasan. Kalau logika alokasi suatu hari salah, database yang
menolak — bukan dua reservasi diam-diam bernomor sama.

### 3.3 Migration untuk baris yang sudah ada

Kolomnya `NOT NULL`, sehingga migration tidak boleh langsung membuatnya. Urutannya:

1. Tambahkan kolom sebagai nullable.
2. Isi baris lama berurutan menurut `id`, termasuk yang ter-soft-delete.
3. Setel `counters.value` ke nomor terakhir yang dipakai.
4. Ubah kolom menjadi `NOT NULL` dan pasang index UNIQUE.

Baris ter-soft-delete ikut diberi nomor. Nomor melekat pada reservasi seumur hidupnya,
tidak didaur ulang.

---

## 4. Alokasi nomor

### 4.1 Kelas dan tempatnya

`App\Services\NumberSequence` dengan satu method:

```php
public function next(string $name): int
```

Isinya:

```sql
SELECT value FROM counters WHERE name = ? FOR UPDATE
UPDATE counters SET value = ? WHERE name = ?
```

Mengembalikan nilai baru.

**Wajib dipanggil di dalam transaksi.** Di luar transaksi, `FOR UPDATE` tidak menahan
apa pun dan jaminan keunikannya hilang. Method ini memeriksa
`DB::transactionLevel() > 0` dan melempar `LogicException` bila dipanggil di luar
transaksi, supaya kesalahan itu tidak pernah lolos diam-diam.

`counters` tidak punya audit log dan bukan model berperilaku, sehingga memakai query
builder di sini tidak melanggar aturan #2 CLAUDE.md — aturan itu ada untuk menjaga
`activity_log`, yang tidak berlaku bagi tabel ini.

### 4.2 Tempat pemanggilan

Di dalam closure `DB::transaction()` yang **sudah ada** di
`ReservationWriter::create()`, sebelum `$reservation->save()`:

```php
$reservation->reservation_number = 'RU-R'.app(NumberSequence::class)->next('reservation');
```

Tiga jalur yang perlu diperhatikan, dan ketiganya berperilaku benar tanpa kode tambahan:

| Kejadian | Akibat pada nomor |
|---|---|
| Penyimpanan berhasil | Nomor terpakai, penghitung naik |
| Ditolak constraint duplikat | Transaksi mundur, penghitung ikut mundur, nomor tidak terbuang |
| `idempotency_key` sama dikirim ulang | Baris lama dikembalikan **sebelum** transaksi dimulai, sehingga tidak ada nomor baru diambil |

Baris ketiga adalah yang paling mudah salah. `create()` memeriksa `idempotency_key`
dan melakukan `return` lebih dulu, di luar transaksi — jadi alokasi memang tidak
pernah tersentuh. Ini diuji secara eksplisit.

### 4.3 Prefiks

`RU-R` ditulis di satu tempat sebagai konstanta pada `Reservation`, bukan disebar
sebagai literal. Formatnya `RU-R` + angka tanpa padding: `RU-R1`, bukan `RU-R0001`.

### 4.4 Factory

`ReservationFactory` wajib mengisi `reservation_number` karena kolomnya `NOT NULL`,
dan dipakai sekitar tiga puluh test yang sudah ada. Nilainya diambil dari penghitung
statis milik factory, bukan dari `NumberSequence`, agar test tidak bergantung pada
keadaan tabel `counters`.

---

## 5. Halaman publik

### 5.1 Rute

```
GET  /                    kalender publik        (BERUBAH, sebelumnya redirect ke /cms)
GET  /cms                 panel staf             (tetap)
```

`/` tidak lagi mengalihkan ke `/cms`. Staf masuk lewat `/cms` langsung.

Ini mengubah perilaku yang sudah ada dan sudah dijaga test
`tests/Feature/ExampleTest.php::test_root_redirects_to_the_cms_panel`. Test itu
diganti, bukan dihapus.

State halaman ada di query string:

```
/?bulan=2026-08&pilih=12
```

- `bulan` — format `Y-m`. Bila kosong atau tidak sah, memakai bulan berjalan.
- `pilih` — id reservasi yang panel detailnya dibuka. Bila tidak ada di bulan yang
  sedang tampil, diabaikan — mencerminkan perilaku kalender CMS.

### 5.2 Batas data ditegakkan di query, bukan di template

Query memakai `select()` eksplisit hanya untuk kolom yang boleh terlihat:

```php
Reservation::query()
    ->select(['id', 'reservation_date', 'start_time', 'end_time', 'status', 'area_id', 'event_type_id'])
    ->with(['area:id,name', 'eventType:id,name'])
```

Konsekuensinya kolom sensitif **tidak pernah dimuat ke memori**. Kalau suatu hari ada
yang keliru menulis `{{ $r->remark }}` di template, hasilnya kosong — bukan bocor.

Ini keputusan yang disengaja: mengandalkan "tidak ditulis di Blade" berarti satu baris
ceroboh di masa depan cukup untuk membocorkan catatan pembayaran tamu.

### 5.3 Isi grid

Satu sel per tanggal, minggu dimulai hari Senin. Chip di dalam sel memuat:

```
12:00  VIP 1
```

Bila area belum ditentukan, chip hanya memuat jamnya saja.

Kata `Terisi` sengaja **tidak** dipakai sebagai teks pengganti area, karena kata itu
sudah dipakai sebagai label status CONFIRMED di bawah. Memakainya untuk dua arti
berbeda pada halaman yang sama akan membingungkan.

Chip **tidak pernah** memuat nama tamu. Ini konsisten dengan pengecualian aturan #4
CLAUDE.md pada kalender: chip tidak memuat remark, panel detail yang menggantikannya —
di sini panel detail pun tidak memuat remark, karena halamannya publik.

Pembeda status:

| Status | Tampilan |
|---|---|
| CONFIRMED | garis tegas, warna penuh |
| TENTATIVE | garis putus-putus, warna lebih redup |
| Belum ditentukan | sama seperti TENTATIVE |

Label yang dipakai di halaman publik adalah **"Terisi"** dan **"Sedang dijajaki"**,
bukan `CONFIRMED` dan `TENTATIVE`. Istilah internal tidak perlu dibawa ke luar.

### 5.4 Panel detail

Muncul di bawah grid ketika `pilih` sah. Isinya:

- Tanggal lengkap berbahasa Indonesia
- Jam — `12:00 (jam tunggal)` atau `12:00–15:00`
- Area, atau `Belum ditentukan`
- Jenis acara, atau `—`
- Status dalam istilah publik

Tanpa tombol apa pun ke `/cms`. Halaman publik tidak menautkan ke panel staf.

### 5.5 Grid bulan dipakai bersama

Aritmetika grid — minggu mulai Senin lewat `($first->dayOfWeek + 6) % 7`, jumlah sel
kosong di awal bulan, jumlah hari — sekarang ada di
`App\Filament\Pages\ReservationCalendar::getCellsProperty()`.

Bagian itu dipindahkan ke `App\Support\MonthGrid` dan dipakai oleh kedua halaman.
Aritmetika ini sudah diuji untuk tujuh bulan yang tanggal 1-nya jatuh pada tujuh hari
berbeda; menyalinnya ke halaman kedua berarti mengundang salah satu salinan menyimpang.

Perapian ini terbatas pada apa yang melayani pekerjaan ini. Tidak ada refactor lain.

---

## 6. Neo-brutalism

### 6.1 Keadaan Tailwind sekarang

Terpasang `tailwindcss ^3.2.1` beserta `postcss.config.js` dan `tailwind.config.js`,
**dan juga** `@tailwindcss/vite ^4.0.0` yang menganggur sisa scaffolding.

Keputusan: pakai v3 yang sudah terkonfigurasi, buang paket v4 yang menganggur.
Dua versi Tailwind dalam satu proyek adalah sumber kebingungan yang tidak dibayar
manfaat apa pun di sini.

Filament tidak terpengaruh — ia memuat CSS hasil kompilasinya sendiri dan tidak
membaca `tailwind.config.js` proyek.

### 6.2 Bahasa visual

| Unsur | Aturan |
|---|---|
| Garis tepi | hitam pekat, tebal 3–4px, di semua elemen |
| Bayangan | padat bergeser tanpa blur, misalnya `6px 6px 0 #000` |
| Sudut | siku, tanpa pembulatan |
| Warna | rata dan berani, tanpa gradien |
| Huruf | tebal, judul kapital |
| Gerak | hanya pergeseran bayangan saat hover; tanpa transisi halus |

Warna status memakai kontras tinggi, bukan pastel: terisi memakai satu warna penuh,
sedang dijajaki memakai garis putus-putus dengan latar netral.

### 6.3 Berkas

```
resources/css/app.css                        token dan kelas komponen
resources/views/layouts/public.blade.php     kerangka halaman
resources/views/public/calendar.blade.php    kalender dan panel detail
```

---

## 7. Yang berubah pada kode yang sudah ada

Daftar ini eksplisit karena menyentuh bagian yang sudah teruji:

| Berkas | Perubahan |
|---|---|
| `routes/web.php` | `/` menjadi kalender publik, bukan redirect |
| `tests/Feature/ExampleTest.php` | assertion `/` disesuaikan |
| `app/Models/Reservation.php` | konstanta prefiks ditambahkan; `reservation_number` **tidak** masuk `$fillable`, sejalan dengan `version`, `idempotency_key`, `created_by`, dan `updated_by` yang sudah lebih dulu di luar `$fillable` karena diisi `ReservationWriter` |
| `app/Services/ReservationWriter.php` | alokasi nomor di dalam transaksi `create()` |
| `database/factories/ReservationFactory.php` | mengisi `reservation_number` |
| `app/Filament/Pages/ReservationCalendar.php` | memakai `MonthGrid` |
| Tabel dan infolist reservasi CMS | kolom nomor ditampilkan dan bisa dicari |
| `package.json` | `@tailwindcss/vite` dibuang |

Nomor reservasi **tidak** ditampilkan di halaman publik dan **tidak** muncul di form
Filament — nomor ditetapkan sistem, bukan diisi pengguna.

---

## 8. Testing

Menyambung daftar v1 bagian 11. Nomor melanjutkan dari 22.

**Nomor reservasi:**

23. Reservasi pertama menerima `RU-R1`.
24. Reservasi berikutnya menerima nomor berikutnya secara berurutan.
25. Penyimpanan yang ditolak constraint duplikat **tidak** membuang nomor — reservasi
    sah berikutnya tetap mendapat nomor tepat setelahnya.
26. `create()` dengan `idempotency_key` yang sama dikirim ulang tidak mengambil nomor
    baru dan mengembalikan reservasi bernomor sama.
27. Mengedit reservasi tidak mengubah nomornya.
28. `NumberSequence::next()` yang dipanggil di luar transaksi melempar `LogicException`.
29. Nomor tampil dan bisa dicari di tabel reservasi CMS.

**Kalender publik:**

30. Halaman terbuka tanpa login dan mengembalikan 200.
31. Nama tamu, perusahaan, HP, email, remark, dan pax **tidak pernah muncul** di HTML,
    diuji dengan reservasi yang keenam kolomnya terisi nilai yang khas.
32. Hanya reservasi bulan yang diminta yang tampil.
33. Reservasi ter-soft-delete tidak tampil.
34. CONFIRMED dan TENTATIVE dirender berbeda.
35. Panel detail menampilkan area, jam, jenis acara, dan status, dan tetap tanpa data
    pribadi.
36. `bulan` yang tidak sah jatuh kembali ke bulan berjalan, bukan menghasilkan error.
37. `pilih` yang menunjuk reservasi di luar bulan tampil diabaikan.
38. `MonthGrid` menempatkan tanggal 1 pada kolom yang benar untuk tujuh bulan yang
    awalnya jatuh pada tujuh hari berbeda — pemindahan test yang sudah ada.

Test nomor 31 adalah yang paling penting di daftar ini. Ia menjaga satu-satunya
keputusan yang bisa merugikan tamu bila salah.

---

## 9. Yang tidak masuk

- Form reservasi publik. Tamu tidak bisa memesan sendiri dari web; alur pemesanan
  tetap lewat staf.
- Pencarian status reservasi oleh tamu memakai nomor `RU-R`.
- SEO, sitemap, dan meta tag berbagi. Halaman ini alat, bukan materi pemasaran.
- Cache halaman. Pada volume ±15 reservasi per bulan, query-nya sepele.
- Penomoran yang di-reset per tahun atau per bulan. Nomor berjalan terus sejak `RU-R1`.
- Mengubah tampilan panel `/cms`. Neo-brutalism hanya untuk halaman publik.

---

## 10. Risiko dan asumsi

| Hal | Catatan |
|---|---|
| Data reservasi menjadi sebagian publik | Dibatasi lima kolom dan ditegakkan di lapisan query, bukan di template |
| Kunci baris pada `counters` | Satu baris dikunci per pembuatan reservasi. Pada 15/bulan tidak pernah terasa; pada ribuan per menit akan menjadi antrean |
| Pola kunjungan publik tidak diketahui | Halaman tidak di-cache. Bila suatu saat ramai, cache per bulan adalah langkah pertama yang murah |
| `/` tidak lagi ke `/cms` | Staf yang terbiasa mengetik alamat pendek perlu diberi tahu sekali |
| Nomor mulai dari 1 | Bila nanti diinginkan mulai dari angka lain, cukup mengubah satu baris di `counters` tanpa migrasi |
