# Progress dan Titik Lanjut

Berkas ini menjawab satu pertanyaan: **kalau saya duduk di mesin lain besok, apa
yang sudah selesai dan apa yang harus saya kerjakan?**

Isinya sengaja ikut git. Catatan sesi Claude Code tersimpan per-mesin dan **tidak
ikut berpindah laptop**; sampai 2026-08-29 hasil brainstorm meja hanya hidup di
sana, dan pindah mesin akan menghapusnya tanpa jejak. Karena itu ia dipindahkan
ke sini.

Perbarui berkas ini setiap kali sebuah keputusan diambil atau sebuah task
selesai — bukan setiap commit.

**Terakhir diperbarui:** 2026-08-29, setelah commit `0856f18`.

---

## Keadaan sekarang

| | |
|---|---|
| Cabang | `main`, working tree bersih, seluruhnya sudah di-push |
| Commit terakhir | `0856f18` "update progress" (2026-08-29) |
| Test | **434 hijau, 1329 assertion**, ± 139 detik |
| Mesin dev | PHP 8.3.1, MySQL 5.7.24 (MAMP, port 3306) |

Tidak ada pekerjaan yang tergantung setengah jalan. Tidak ada branch lain, tidak
ada PR — pekerjaan masuk **langsung ke `main`**, dan pengamannya suite test yang
hijau, bukan review. Jalankan `php artisan test` sampai hijau sebelum commit.

## Yang sudah selesai

**Task 0–24 seluruhnya**, yaitu kedua dokumen rencana sampai habis: model dan
penulisan reservasi, panel CMS Filament, role dan permission, nomor reservasi
`RU-R1`, kalender publik, dan gaya neo-brutalism.

Setelah Task 24, pekerjaan berjalan di luar dokumen rencana. Berurutan:

| Commit | Isi |
|---|---|
| `cdef860` | Dashboard: jam venue berjalan + ringkasan reservasi terdekat |
| `010de87` | Export `.xlsx` dari toolbar `/cms/reservations` |
| `d6cae1f` | Tautan kalender publik di sidebar CMS |
| `959b9e0`…`03f07e4` | Jam jadi value object `App\Support\Jam`; jam operasional 08:00–22:00 |
| `0d589fe` | Pax boleh berupa rentang (`10–14`) |
| `0856f18` | `MasterSeeder::MELIPUTI` jadi sumber tunggal pasangan area yang saling meliputi |

Masing-masing sudah punya aturannya sendiri di `CLAUDE.md` (nomor 15–18 dan
bagian Dashboard/Export). Baca dari sana, bukan dari sini — di sini hanya
urutannya.

---

## Yang memblokir pekerjaan berikutnya

### Survei meja — menunggu Arnold di lapangan

Brainstorm 2026-08-28: satuan reservasi pindah dari **area** ke **nomor meja**.
Prosesnya berhenti di tengah atas permintaan Arnold, untuk melihat keadaan
lapangan dulu. **Jangan mulai implementasi apa pun sampai jawabannya masuk.**

Rancangan tersampaikan sampai **Bagian 1 dari 6** (bentuk data). Lima sisanya
belum ditulis: deteksi bentrok, form dan penyimpanan, tujuh permukaan tampilan,
migrasi data yang sudah hidup di VPS, dan test.

**Sudah diputuskan — jangan ditanyakan ulang:**

- Nomor meja **ditetapkan tergantung jenis acara**. Acara besar cukup area;
  makan biasa butuh nomor meja.
- Kapasitas kursi tiap area **belum pernah dihitung** dan tidak ada di kepala
  siapa pun.
- **Pendekatan C dipilih**: meja jadi unit yang dijaga sistem lewat tabel pivot.
  Rekomendasi saya waktu itu pendekatan A (kolom `capacity` nullable di `areas`);
  C dipilih justru karena ia menjawab "sudah penuh?" tanpa menghitung kursi —
  penuh berarti tidak ada meja tersisa. Pilihan ini menggantikan jawaban Arnold
  sebelumnya bahwa meja "cukup dicatat, tidak dijaga".
- **Tidak ada tumpang tindih non-hierarki**, jadi `parent_id` aman dipakai.
- **Venue punya dua lantai, dan lantai bisa dipesan utuh.** Borongan satu lantai
  tidak menyentuh lantai lain, jadi lantai adalah baris `areas` yang bisa
  dipesan, bukan kolom keterangan. Karena itu **tidak** ada kolom `areas.floor`.
- **GRAND BALLROOM = seluruh lantai 3.** Tidak ada tempat lain di sana, jadi
  TIDAK dibuat baris "LANTAI 3" terpisah — dua baris untuk satu ruang fisik yang
  sama hanya membuat staf menebak harus memilih yang mana.
- **Batas kedalaman dua tingkat dicabut.** Alasan lamanya (MySQL 5.7 tanpa
  recursive CTE) tidak berlaku: `areas` hanya ± 30 baris, jadi seluruh pohon
  dimuat sekali ke memori dan ditelusuri di PHP.

Pohon yang disepakati:

```
LANTAI 1 (akar)                GRAND BALLROOM (akar, = lantai 3)
├── VIP 1                      ├── BALLROOM 1
├── VIP 2                      └── BALLROOM 2
├── FOYE
├── INDOOR ── MEJA ...
├── SOFA ──── MEJA ...
├── KORIDOR
└── OUTDOOR ─ MEJA ...
```

Aturan bentrok, satu kalimat untuk semua tingkat: **sebuah unit terpakai kalau
ia sendiri, salah satu INDUKNYA, atau salah satu BAGIANNYA sedang dipesan.**
Bersaudara (MEJA 3 vs MEJA 4, VIP 1 vs INDOOR) tidak saling bentrok.

**Dua hal yang masih menggantung — tanyakan ini dulu saat Arnold kembali:**

1. **Persetujuan Arnold** untuk menghapus `reservations.area_id`. Itu menurunkan
   kewajiban mengisi area dari tiga lapis jadi dua (form + `ReservationWriter`,
   tanpa `NOT NULL` di database), sehingga melonggarkan aturan #12 `CLAUDE.md`
   yang ditulis sebagai keputusan sadar. Persetujuannya milik pemilik sistem,
   bukan keputusan teknis.
2. **Hasil survei lapangan:** ada berapa meja, di INDOOR/SOFA/OUTDOOR yang mana
   saja, dan penomorannya seperti apa. Ditanyakan juga apakah borongan lantai 1
   punya sebutan lain di lapangan selain "LANTAI 1".

Temuan yang menurunkan ongkos dan layak diingat: `dedupe_key` **tidak** memuat
`area_id` — isinya hanya tanggal, nama tamu, dan jam mulai — jadi generated
column MySQL (aturan #1) tidak ikut terguncang oleh perubahan ini.

### Deployment bagian 8 — menunggu pihak lain

Pemasangan server selesai; yang tersisa adalah membuka sistem ke internet, dan
itu tertahan tiga pertanyaan jaringan sejak 2026-08-27. Rinciannya —
berikut cara menjawabnya tanpa menyentuh router — ada di
**`deploy/CHECKPOINT.md`**, dan sengaja tidak disalin ke sini.

Dua di antaranya bisa dijawab tanpa saya: uji port 80 dari HP dengan data
seluler (WiFi kantor dimatikan), dan bandingkan `curl -s ifconfig.me` di VPS
dengan `103.138.40.54`.

---

## Kalau melanjutkan di mesin lain

1. Ikuti `claude/SETUP-MESIN-BARU.md` sampai `php artisan test` hijau.
2. Baca `CLAUDE.md` — sembilan belas aturan yang tidak boleh dilanggar.
3. Baca berkas ini untuk tahu di mana kita berhenti.
4. `deploy/CHECKPOINT.md` hanya perlu dibuka kalau yang dikerjakan urusan server.

Yang **tidak** ikut pindah dan memang tidak perlu: `.env`, isi database,
`vendor/`, `node_modules/`, `public/build/`. Semuanya dibangkitkan ulang oleh
langkah di `SETUP-MESIN-BARU.md`.
