# Mulai di Claude Code — Sistem Reservasi Roemah Umara

Dokumen ini adalah titik masuk. Baca ini dulu sebelum yang lain.

---

## 1. Salin berkas ke repositori

Dari folder output Cowork, salin tiga berkas ke repositori
`D:\Development\reservation-system-roemahumara`:

```
docs/superpowers/specs/2026-08-10-reservasi-roemah-umara-design.md
docs/superpowers/plans/2026-08-10-reservasi-roemah-umara-plan.md
docs/superpowers/plans/2026-08-10-reservasi-roemah-umara-plan-ui.md
```

Lalu commit:

```bash
git checkout -b feat/filament-reservation
git add docs/
git commit -m "docs: spec dan rencana implementasi"
```

**Penting:** pada berkas `...-plan.md`, ada pembatas bertanda **⛔ BATAS DOKUMEN INI**
setelah Task 11. Seluruh isi di bawah pembatas itu **usang** — ditulis ketika UI masih
direncanakan memakai React. Hapus bagian itu saat menyalin, atau biarkan tetapi jangan
pernah dikerjakan.

---

## 2. Buat `CLAUDE.md` di root repositori

Ini membuat aturan proyek terbaca otomatis oleh Claude Code di setiap sesi. Salin
persis:

```markdown
# Sistem Reservasi Roemah Umara

Sistem reservasi internal untuk restoran/venue. Menggantikan spreadsheet Excel.
Volume ± 15 reservasi per bulan, sekitar 8 pengguna, semua internal.

## Dokumen

- Spec: `docs/superpowers/specs/2026-08-10-reservasi-roemah-umara-design.md`
- Rencana backend: `docs/superpowers/plans/2026-08-10-reservasi-roemah-umara-plan.md` (Task 0–11)
- Rencana UI: `docs/superpowers/plans/2026-08-10-reservasi-roemah-umara-plan-ui.md` (Task 12–18)

Kerjakan berurutan. Jangan melompat.

## Stack

PHP 8.3, Laravel 12, Filament v5, Livewire v4, MySQL 8, PHPUnit 11,
spatie/laravel-activitylog, spatie/laravel-permission.

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
```

Commit:

```bash
git add CLAUDE.md
git commit -m "docs: aturan proyek untuk Claude Code"
```

---

## 3. Status saat ini

| | Status |
|---|---|
| Spec desain | Selesai |
| Rencana backend Task 0–11 | Selesai ditulis |
| Rencana UI Task 12–18 | Selesai ditulis |
| **Kode** | **Belum ada sama sekali** |

Sudah terpasang di repositori: Filament v5.7.6, Livewire v4.3.5.
Belum dibersihkan: Breeze, Inertia, React, Ziggy — itu isi Task 0.

---

## 4. Verifikasi sebelum mulai

Jalankan empat perintah ini. Kalau ada yang tidak sesuai, **selesaikan dulu** sebelum
mengerjakan task apa pun.

```powershell
php --version                      # harus 8.3+
php artisan --version              # harus Laravel 12.x
mysql --version                    # harus MySQL 8.x
composer show filament/filament    # harus v5.x
```

MySQL adalah yang paling kritis. Seluruh rencana `dedupe_key` bergantung pada
generated stored column, tersedia sejak MySQL 5.7. Task 1 Step 1 dan Task 5 Step 4
punya langkah cek yang akan **menghentikan pengerjaan** kalau tidak terpenuhi — lebih
baik ketahuan sekarang.

---

## 5. Prompt pembuka di Claude Code

Buka terminal di `D:\Development\reservation-system-roemahumara`, jalankan `claude`,
lalu tempel:

```
Baca CLAUDE.md dan docs/superpowers/plans/2026-08-10-reservasi-roemah-umara-plan.md.

Kerjakan Task 0 sampai selesai, lalu berhenti dan laporkan hasilnya sebelum
melanjutkan ke Task 1.

Ikuti setiap step persis seperti tertulis, termasuk urutan menulis test lebih dulu
sebelum implementasi. Jangan melompati step verifikasi.
```

Setelah Task 0 selesai dan kamu setujui, lanjutkan dengan:

```
Lanjutkan ke Task 1. Berhenti setelah selesai.
```

Dan seterusnya. **Kerjakan satu task per sesi review.** Rencana ini disusun agar
setiap task punya siklus test sendiri dan bisa ditolak tanpa membatalkan tetangganya.

---

## 6. Titik henti yang harus dihormati

Tiga task ini adalah gerbang. Jangan lanjut sebelum testnya hijau.

| Task | Kenapa gerbang |
|---|---|
| **1** | Kalau test masih jalan di SQLite, seluruh Task 5 jadi tidak berarti |
| **5** | Constraint duplikat adalah satu-satunya perlindungan yang tahan race condition |
| **14** | Kalau override `handleRecordCreation()` salah, Filament menyimpan langsung dan Task 9 terlewati tanpa error |

Task 14 adalah yang paling mudah terlihat benar padahal salah. Jalankan
`ReservationFilamentTest` dan pastikan sembilan testnya lulus, terutama
`test_edit_increments_version_and_logs_the_change`.

---

## 7. Dua hal yang belum terverifikasi

Keduanya sudah ditandai di rencana beserta jalur cadangannya. Kalau kena, jangan
memaksakan solusi yang melanggar aturan remark.

1. **`Panel::make()->visible()` dengan closure `$record`** — Task 13 Step 1.
   Cadangan: hapus `->visible()`, tambahkan `->placeholder('')`. Jangan menyelesaikannya
   dengan memotong teks.

2. **Namespace `Filament\Schemas\Components\Tabs\Tab`** untuk tab bulan — Task 13
   Step 2. Kalau berbeda, cari `Tab` di dokumentasi 5.x bagian List page.

---

## 8. Kalau ada yang perlu diputuskan ulang

Semua keputusan beserta alasannya ada di spec bagian 2 dan bagian 12. Yang paling
mungkin ditinjau ulang seiring pemakaian:

- **Satu area per reservasi** — event multi-area seperti DHARMADI tidak tertampung,
  saat ini ditulis di REMARK. Pindah ke tabel pivot bersifat aditif.
- **Bentrok area hanya peringatan, tidak memblokir** — mengubahnya menjadi blocking
  hanya perlu mengganti `Notification` menjadi `ValidationException`.
- **Tanpa pagination** — aman sampai sekitar 300 baris per bulan.
- **Nama PIC sebagai user** — mengasumsikan "IBU MARLUCE" memang orang, bukan label
  peran. Kalau ternyata label, `pic_id` perlu ditinjau.
