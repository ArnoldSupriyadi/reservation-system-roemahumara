# Menyiapkan Proyek di Mesin Baru

Dari repositori kosong sampai halaman publik terbuka. Sekitar 10 menit, sebagian besar
menunggu `composer install`.

**Tidak ada data yang perlu dibawa dari mesin lama.** Seluruh isi database dibangkitkan
ulang dari seeder: role, permission, master area/jenis acara/gaya menu, akun masuk, dan
reservasi contoh. Tidak perlu dump, tidak perlu ekspor.

---

## 1. Prasyarat

| Kebutuhan | Versi | Catatan |
|---|---|---|
| PHP | 8.2 atau lebih baru | Mesin lama memakai 8.3.1 dari MAMP |
| MySQL | 5.7 | **Bukan** SQLite — lihat aturan nomor 1 di CLAUDE.md |
| Composer | 2.x | |
| Node.js | 20 atau lebih baru | |
| Git | | |

MySQL 5.7 di mesin lama datang dari **MAMP**, bukan service Windows. Kalau di mesin
baru memakai MAMP juga, binary-nya ada di `C:\MAMP\bin\mysql\bin\mysqld.exe`.

## 2. Langkah

```bash
git clone https://github.com/ArnoldSupriyadi/reservation-system-roemahumara.git
cd reservation-system-roemahumara

composer install
npm install

cp .env.example .env
php artisan key:generate
```

`.env.example` sudah memuat seluruh nilai yang benar — locale `id`, zona waktu
`Asia/Jakarta`, dan sambungan MySQL ke `ru_reservation` dengan `root`/`root`. Ubah
`DB_USERNAME` dan `DB_PASSWORD` bila mesin barumu berbeda.

**Nyalakan MySQL lebih dulu**, lalu buat kedua database:

```sql
CREATE DATABASE ru_reservation      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE ru_reservation_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Keduanya wajib. Yang kedua dipakai `php artisan test`; tanpa itu seluruh test gagal
menyambung.

```bash
php artisan migrate --seed
npm run build
```

`npm run build` **tidak boleh dilewati**. `public/build` diabaikan git, jadi di mesin
baru belum ada. Tanpa itu halaman publik gagal dengan `Vite manifest not found` —
pesan yang tidak menunjuk ke penyebabnya sama sekali.

Terakhir, isi data contoh bila ingin melihat kalender berisi:

```bash
php artisan db:seed --class=ReservationDemoSeeder
```

## 3. Menjalankan

```bash
php artisan serve
```

- Halaman publik: <http://localhost:8000/>
- Panel staf: <http://localhost:8000/cms>
- Masuk dengan `test@example.com` / `password`

Akun itu dibuat seeder dan berperan `admin`. **Ganti sebelum dipakai sungguhan.**

## 4. Memastikan semuanya benar

```bash
php artisan test
```

Harus 226 test hijau. Kalau hijau, pemindahan berhasil sepenuhnya.

## 5. Hal yang menjebak

**MySQL mati.** `php artisan test` akan menggantung lama tanpa mencetak satu baris pun,
bukan gagal cepat. Kalau suite diam berlarut-larut, periksa MySQL dulu sebelum
menyalahkan test.

**MAMP bentrok port.** Bila MySQL dinyalakan sebagai proses lepas
(`mysqld.exe --standalone`), MAMP Control Panel tidak mengetahuinya dan tombol Start
akan gagal karena port 3306 sudah terpakai. Matikan dulu prosesnya.

**Berkas `public/hot` tertinggal.** Dibuat `npm run dev` dan seharusnya terhapus saat
Ctrl+C. Bila prosesnya mati tidak wajar, berkasnya tertinggal, dan Laravel terus
memuat CSS dari server Vite yang sudah tidak ada — halaman tampil polos tanpa gaya
tanpa pesan error apa pun. Hapus `public/hot` bila itu terjadi.

**`php artisan serve` tidak mati bersama induknya.** Proses anaknya yang mendengarkan
port 8000. Bila port masih terpakai setelah server ditutup, cari PID-nya dengan
`netstat -ano | findstr :8000` lalu matikan langsung.

## 6. Yang tidak ikut git

Semuanya dibangkitkan ulang oleh langkah di atas, tidak ada yang perlu disalin manual:

| | Dipulihkan oleh |
|---|---|
| `vendor/` | `composer install` |
| `node_modules/` | `npm install` |
| `public/build/` | `npm run build` |
| `.env` | `cp .env.example .env` + `key:generate` |
| Isi database | `migrate --seed` dan `ReservationDemoSeeder` |

Logo di `public/img/` **ikut** git, jadi tidak perlu disalin.
