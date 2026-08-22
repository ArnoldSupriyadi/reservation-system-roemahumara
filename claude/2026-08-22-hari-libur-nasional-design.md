# Desain — Hari Libur Nasional (v3)

Tanggal: 2026-08-22
Prasyarat: Task 0–24 selesai, 226 test hijau.
Penomoran task melanjutkan v2, yang berakhir di Task 24.

## 1. Konteks

Kalender publik sudah menandai hari Minggu dengan warna merah, tapi tidak mengenal
hari libur nasional sama sekali. Kata "libur" nol kemunculan di seluruh spec, rencana,
dan kode v1 maupun v2 — fitur ini tidak pernah masuk lingkup, jadi ketiadaannya bukan
bug.

Yang dibangun: tabel master hari libur yang dikelola staf lewat `/cms`, ditampilkan
merah beserta namanya di kalender publik, dan ditandai di daftar reservasi CMS.

### 1.1 Mengapa ini master, bukan konfigurasi

Hari libur Indonesia ditetapkan SKB 3 Menteri dan berubah tiap tahun. Idul Fitri,
Idul Adha, Nyepi, Waisak, dan Imlek bergeser mengikuti kalender lunar. Cuti bersama
bahkan bisa berubah beberapa bulan sebelum hari-H.

Data yang berubah tiap tahun dan bisa berubah mendadak tidak boleh tinggal di kode,
karena artinya setiap perubahan menuntut commit dan rilis ulang — dan pada saat
pemerintah menggeser cuti bersama, yang dibutuhkan venue adalah staf yang bisa
memperbaikinya dalam satu menit, bukan menunggu pengembang.

## 2. Keputusan arsitektur

### 2.1 Tanggal konkret, bukan aturan berulang

Tabel menyimpan satu baris per tanggal kalender, bukan aturan seperti "setiap 17
Agustus". Aturan berulang hanya benar untuk lima libur bertanggal tetap dari sekitar
enam belas yang ada. Sistem yang benar untuk sebagian kasus dan diam-diam salah untuk
sisanya lebih berbahaya daripada sistem yang menuntut pengisian manual, karena
kesalahannya tidak memberi tanda apa pun.

Konsekuensi yang diterima: tabel perlu diisi tiap tahun. Itu sekitar 26 baris setahun
untuk sistem yang menangani 15 reservasi sebulan — beban yang sepadan.

### 2.2 Satu tanggal, satu baris

Kolom `date` dibuat unik. Bila suatu saat dua hari besar jatuh pada tanggal yang sama,
namanya digabung dalam satu baris, misalnya "Nyepi & Isra Mikraj".

Alternatifnya, unik pada pasangan (tanggal, nama), ditolak: ia menghasilkan dua
penanda menumpuk di satu sel kalender yang tingginya sudah terbatas, dan satu nama
panjang lebih mudah dibaca daripada dua label yang saling berdesakan.

### 2.3 Seeder hanya memuat libur bertanggal tetap

Hanya lima tanggal yang tidak pernah bergeser:

| Tanggal | Nama |
|---|---|
| 1 Januari | Tahun Baru Masehi |
| 1 Mei | Hari Buruh Internasional |
| 1 Juni | Hari Lahir Pancasila |
| 17 Agustus | Hari Kemerdekaan Republik Indonesia |
| 25 Desember | Hari Raya Natal |

Kelimanya libur nasional, bukan cuti bersama.

Libur lunar dan cuti bersama **sengaja tidak diseed**. Menuliskan tanggal SKB dari
ingatan berisiko menampilkan tanggal merah yang salah kepada tamu, dan tidak ada test
yang bisa menangkap kesalahan semacam itu — ia hanya ketahuan saat ada yang datang di
hari yang ternyata bukan libur. Sisanya diisi staf lewat `/cms` dari sumber resmi.

Karena kelima tanggal ini tetap, seedernya menghasilkannya secara terhitung untuk
rentang tahun mana pun dan tidak akan pernah usang.

### 2.4 Tanpa kemampuan baru

Policy hari libur memakai `Ability::ManageMaster` yang sudah ada, sama seperti area,
jenis acara, dan gaya menu. Hari libur adalah master keempat, bukan wilayah wewenang
baru. Menambah `Ability` berarti menambah baris yang harus dipahami saat menyusun
role, dan tidak ada satu pun peran yang masuk akal boleh mengelola area tapi tidak
boleh mengelola hari libur.

Label `Ability::ManageMaster` diperbarui agar menyebut hari libur.

### 2.5 Penanda reservasi lewat relasi, bukan hitungan per baris

Daftar reservasi di CMS menandai baris yang jatuh pada hari libur. Cara yang tampak
paling mudah — memeriksa tabel `holidays` untuk setiap baris yang tampil — mengirim
satu query per reservasi dan menjadi N+1 diam-diam.

Yang dipakai: relasi Eloquent yang menyambung kolom bukan-id.

```php
public function holiday(): BelongsTo
{
    return $this->belongsTo(Holiday::class, 'reservation_date', 'date');
}
```

Dengan `->with('holiday')` pada query tabel, seluruh baris yang tampil terambil dalam
satu query tambahan.

**Risiko yang harus diuji, bukan diasumsikan.** `reservation_date` di-cast ke `date`,
sehingga nilainya Carbon, bukan string. Saat Eloquent menyusun `whereIn` untuk eager
load, Carbon terikat sebagai `Y-m-d H:i:s` sementara kolom `holidays.date` bertipe
DATE. MySQL biasanya mengonversinya, tapi ini bergantung perilaku implisit dan harus
dibuktikan pada MySQL 5.7 yang dipakai proyek ini, bukan diyakini.

Bila terbukti tidak cocok, jalan mundurnya: muat tanggal libur bulan yang tampil
sebagai koleksi ber-key, lalu cocokkan di PHP. Tetap satu query, tanpa bergantung
konversi tipe implisit.

## 3. Model data

### 3.1 Tabel `holidays`

```php
Schema::create('holidays', function (Blueprint $table) {
    $table->id();
    $table->date('date')->unique();
    $table->string('name', 100);
    $table->string('type', 20)->default('national');
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

Tidak ada `CHECK` constraint pada `type`. MySQL 5.7 tidak mendukungnya, dan nilainya
dijaga enum PHP serta pilihan tertutup di form.

Tidak ada `sort_order` seperti master lain: hari libur punya urutan alami, yaitu
tanggalnya sendiri.

### 3.2 Enum `App\Enums\HolidayType`

```php
enum HolidayType: string
{
    case National = 'national';
    case JointLeave = 'joint_leave';

    public function label(): string
    {
        return match ($this) {
            self::National => 'Libur Nasional',
            self::JointLeave => 'Cuti Bersama',
        };
    }
}
```

Mengikuti pola `ReservationStatus`: nilai tersimpan dalam bahasa Inggris, label
tampilan dalam bahasa Indonesia.

### 3.3 Model `App\Models\Holiday`

`$fillable`: `date`, `name`, `type`, `is_active`. Cast `date` ke `date`, `type` ke
`HolidayType`, `is_active` ke `boolean`. Scope `active()` seperti master lain.

## 4. Halaman publik

### 4.1 Query

`PublicCalendarController` memuat hari libur bulan yang tampil:

```php
Holiday::query()
    ->active()
    ->whereYear('date', $year)
    ->whereMonth('date', $monthNumber)
    ->get()
    ->keyBy(fn (Holiday $h) => $h->date->toDateString());
```

Di-key menurut tanggal ISO agar cocok langsung dengan `$cell['iso']` dari `MonthGrid`,
tanpa pencarian ulang di dalam loop.

Aturan nomor 9 di CLAUDE.md tidak dilonggarkan: batas lima kolom itu berlaku untuk
reservasi. Hari libur bukan data tamu dan tidak memuat apa pun yang bersifat pribadi.

### 4.2 Warna

| Peran | Warna | Kontras di atas kertas `#FFFDF5` |
|---|---|---|
| Hari Minggu | `#D40000` (token `sunday`) | 5,43:1 |
| Libur nasional | `#D40000` (token `sunday`) | 5,43:1 |
| Cuti bersama | `#A6127A` (token baru `jointleave`) | 6,90:1 |

Ambang WCAG AA untuk teks normal adalah 4,5:1; ketiganya lolos.

Libur nasional memakai merah yang sama dengan hari Minggu dan sengaja tidak diberi
warna sendiri. Keduanya berarti hal yang sama bagi pengunjung — hari tidak biasa — dan
menambah merah ketiga hanya menuntut pembaca membedakan nuansa yang tidak membawa
informasi tambahan.

`#A6127A` **tidak boleh dipakai di atas bilah hitam**; kontrasnya di sana hanya 2,69:1.
Bilah hitam hanya memuat nama hari, dan satu-satunya yang berwarna di sana adalah
"Min" dengan `#FF6B6B`.

### 4.3 Hari Minggu yang sekaligus hari libur

Angkanya sudah merah karena hari Minggu, jadi warna tidak menambah informasi apa pun.
Aturannya:

- Minggu + libur nasional → angka tetap `#D40000`, nama libur tercetak di sel.
- Minggu + cuti bersama → angka berubah ke `#A6127A`, nama libur tercetak di sel.

Jenis yang lebih spesifik menang, karena itulah yang belum diketahui pembaca.

### 4.4 Nama libur di sel

Nama tercetak kecil di bawah angka tanggal, di atas chip reservasi. Sel kalender
tingginya `h-24` dan sudah memuat angka serta chip; nama libur menambah satu baris.
Nama panjang dibiarkan membungkus, tidak dipotong — sama seperti aturan remark: yang
terpotong menyesatkan.

### 4.5 Keterangan warna

Bertambah dua baris di bawah kalender, di samping "Terisi" dan "Sedang dijajaki":
"Libur nasional" dan "Cuti bersama", masing-masing dengan contoh warnanya.

## 5. CMS

### 5.1 `HolidayResource`

Grup navigasi `Master`, satu halaman kelola seperti `AreaResource`. Form: `DatePicker`
untuk tanggal, `TextInput` untuk nama, `Select` untuk jenis, `Toggle` untuk aktif.
Tabel urut menurut tanggal dengan penyaring tahun, karena daftar bertumbuh sekitar 26
baris tiap tahun dan tanpa penyaring akan bercampur antar-tahun.

### 5.2 Policy

`HolidayPolicy` menyalin `AreaPolicy` — mengecek `Ability::ManageMaster` lewat
`$user->can()`, tidak pernah nama role.

### 5.3 Penanda di daftar reservasi

Kolom baru di tabel reservasi CMS yang menampilkan nama hari libur bila tanggalnya
jatuh di sana, kosong bila tidak. Query tabel diberi `->with('holiday')`.

## 6. Seeder

`HolidaySeeder` menghasilkan kelima tanggal tetap untuk **tahun berjalan dikurangi 1
sampai tahun berjalan ditambah 3** — lima tahun, 25 baris — idempoten lewat
`updateOrCreate` pada `date`.

Tahun sebelumnya ikut diisi karena kalender publik bisa ditelusuri mundur, dan 17
Agustus tahun lalu tetap hari libur. Rentangnya dihitung dari tahun saat seeder
dijalankan, bukan ditulis mati, sehingga seeder ini tidak pernah kedaluwarsa.

Berbeda dari `ReservationDemoSeeder`, seeder ini **masuk** `DatabaseSeeder`: datanya
nyata, bukan contoh, dan sistem yang baru dipasang sebaiknya sudah mengenal 17 Agustus.

`DatabaseSeederTest` yang ada akan perlu diperbarui karena isi seedernya bertambah.

## 7. Yang berubah pada kode yang sudah ada

| Berkas | Perubahan |
|---|---|
| `app/Enums/Ability.php` | Label `ManageMaster` menyebut hari libur |
| `app/Models/Reservation.php` | Relasi `holiday()` |
| `app/Http/Controllers/PublicCalendarController.php` | Muat hari libur bulan tampil |
| `resources/views/public/calendar.blade.php` | Warna, nama libur, keterangan warna |
| `tailwind.config.js` | Token warna `jointleave` |
| `database/seeders/DatabaseSeeder.php` | Panggil `HolidaySeeder` |
| `app/Filament/Resources/Reservations/Tables/ReservationsTable.php` | Kolom penanda, `->with('holiday')` |
| `tests/Feature/DatabaseSeederTest.php` | Menyesuaikan isi seeder yang bertambah |
| `CLAUDE.md` | Dokumen v3, master keempat |

## 8. Testing

Yang dijaga:

1. Seeder menghasilkan lima libur per tahun, seluruhnya bertipe nasional, dan aman
   dijalankan dua kali tanpa menghasilkan baris kembar.
2. Tanggal libur tampil merah beserta namanya di kalender publik.
3. Cuti bersama memakai warna yang berbeda dari libur nasional.
4. Hari Minggu yang sekaligus cuti bersama memakai warna cuti bersama.
5. Libur yang `is_active = false` tidak tampil.
6. Libur bulan lain tidak bocor ke bulan yang sedang tampil.
7. Tabel reservasi CMS menampilkan penanda, dan **jumlah query tidak bertambah
   sebanding jumlah baris** — diuji dengan menghitung query, bukan diyakini dari
   membaca kode.
8. `HolidayResource` hanya bisa dibuka pemegang `ManageMaster`.
9. Halaman publik tetap nol data pribadi setelah seluruh perubahan ini.

Nomor 7 dan 9 yang paling mudah rusak diam-diam, dan keduanya diuji eksplisit.

## 9. Yang tidak masuk

- Tidak ada aturan berulang tahunan.
- Tidak ada pengambilan otomatis dari API mana pun.
- Tidak ada libur daerah atau libur khusus venue — kalau nanti perlu, `type`
  tinggal ditambah kasus, tanpa mengubah tabel.
- Hari libur **tidak** memblokir maupun memperingatkan saat reservasi disimpan.
  Venue seperti ini justru ramai saat libur; memblokir salah, dan memperingatkan
  hanya menghasilkan dialog yang selalu diklik lewat.
- Panel detail di halaman publik tidak menyebut hari libur. Namanya sudah tercetak
  di sel kalender, dan mengulangnya tidak menambah apa pun.
