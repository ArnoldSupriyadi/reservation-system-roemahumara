# Checkpoint deployment — VPS 192.168.88.33

Berkas ini menjawab satu pertanyaan: **sudah sampai mana?**

Dipakai supaya pemasangan bisa dilanjutkan dari laptop lain, atau setelah jeda
beberapa hari, tanpa menebak-nebak langkah mana yang sudah jalan. Langkahnya
sendiri ada di [RUNBOOK.md](RUNBOOK.md) — di sini hanya statusnya.

Berkas ini ikut git, jadi cukup `git pull` di mesin mana pun untuk melihat
keadaan terakhir. **Perbarui setiap kali sebuah bagian selesai**, lalu commit.

---

# ▶ SISTEM SUDAH LIVE DI INTERNET

Bagian 0–12 selesai dan sudah diuji. Sistem melayani publik di
**<https://reservation.roemahumara.com/>** sejak **2026-09-07**, dan tetap
terjangkau lewat `http://192.168.88.33` dari jaringan kantor. Deploy otomatis
sudah terbukti (Success, 46 detik, 2026-08-24); setiap push ke `main` memicu
deploy sendiri.

Bagian 13 bukan langkah pemasangan — itu prosedur cadangan, dibaca hanya kalau
runner mati dan Anda perlu merilis dengan tangan.

**Bagian 8 tuntas 2026-09-07.** Ketiga hambatan yang menahannya sejak 2026-08-24
— port 443 dipakai mesin lain, port 80 belum di-forward, dan status IP publik —
sudah tidak menghalangi. **Cara menyelesaikannya akan diisi Arnold menyusul**;
sampai itu masuk, jangan menebaknya dari berkas ini dan jangan menyalin ulang
langkahnya ke RUNBOOK.

Yang **teramati dari luar** pada 2026-09-07 (dicatat sebagai bukti, bukan
sebagai rancangan yang sudah disepakati):

| Diperiksa | Hasil |
|---|---|
| `https://reservation.roemahumara.com/` | HTTP 200, kalender publik terbaca — `<title>` "Jadwal September 2026 — Roemah Umara" |
| `https://reservation.roemahumara.com/cms/login` | HTTP 200 |
| Tautan dan aset di halaman | sudah `https://reservation.roemahumara.com/...` — artinya `APP_URL` di server **sudah** diganti |
| Header balasan | `Server: Microsoft-IIS/10.0` + `X-Powered-By: ARR/3.0` |

Baris terakhir itu berarti trafik masuk **lewat reverse proxy IIS**, bukan
langsung ke Nginx di VPS — mesin Windows yang dulu tercatat "memakai port 443"
tampaknya kini yang meneruskan permintaannya. Cookie `roemah-umara-reservation-session`
tetap muncul di balasannya, jadi yang dilayani memang aplikasi ini. Konsekuensi
praktisnya: **kalau suatu saat domainnya bermasalah, penyebabnya bisa ada di
mesin IIS itu, bukan hanya di Nginx VPS.**

## ⚠️ Tiga hal yang jadi mendesak justru karena sudah live

Ketiganya terpantau 2026-09-07 dan **belum** dikerjakan:

1. **`http://` (tanpa S) menjawab HTTP 200, tidak dialihkan ke `https://`.**
   Selama begitu, ada jalur yang melewatkan sesi staf dalam keadaan terbuka.
   Yang benar: port 80 mengalihkan permanen ke 443. Karena masuknya lewat IIS,
   pengalihan ini kemungkinan besar disetel **di IIS**, bukan di
   `deploy/nginx/roemahumara.conf`.
2. **`SESSION_SECURE_COOKIE` masih `false`.** Cookie sesi pada balasan HTTPS
   datang tanpa tanda `secure`. Wajib jadi `true` — lihat "Yang sengaja belum
   dikerjakan" di bawah. Aplikasi di balik reverse proxy juga perlu memercayai
   proxy-nya (`TrustProxies`), kalau tidak Laravel bisa mengira koneksinya
   `http` dan menolak menyetel cookie `secure`.
3. **`robots.txt` masih `Disallow:` kosong — mengizinkan semua.** Halaman `/`
   menampilkan nama tamu, perusahaan, PIC, dan remark **tanpa login**, sedangkan
   remark di sistem ini terbiasa memuat keterangan pembayaran. Di jaringan lokal
   itu hanya terbaca orang kantor; sekarang terbaca siapa saja **dan boleh
   diindeks mesin pencari**. Ini keputusan pemilik sistem, bukan keputusan
   teknis — aturan #10 `CLAUDE.md` mencatat pelonggaran kolomnya memang atas
   permintaan eksplisit Arnold, tapi permintaan itu diberikan saat sistem masih
   di jaringan lokal. Menariknya kembali cukup menghapus kolomnya dari
   `select()` di `PublicCalendarController`.

Dan dua hal yang sejak awal ditandai "berhenti jadi opsional begitu server
menghadap internet" — sekarang saatnya:

- **Kunci SSH (bagian 1d)** — port 22 yang menghadap internet dipindai bot dalam
  hitungan menit. Perlu dipastikan port 22 memang tidak ikut diteruskan dari
  luar; jangan mengandalkan asumsi.
- **Sandi awal sebelas akun staf** masih sama semua. Selama belum diganti
  masing-masing, `activity_log` bisa menunjuk orang yang keliru.

Catatan yang masih berlaku: **konfigurasi Nginx sudah selesai dan tidak perlu
disentuh.** `deploy/nginx/roemahumara.conf` sudah memuat
`reservation.roemahumara.com` di `server_name` sejak awal, berdampingan dengan
IP privatnya. Godaan untuk "memperbaiki nginx" saat domain bermasalah akan
membuang waktu di tempat yang bukan penyebabnya.

---

## Keadaan server

| | |
|---|---|
| IP privat | `192.168.88.33` (hanya dari VPN kantor) |
| IP publik kantor | `103.138.40.54` (`ip-40-54.balifiber.id`) — statis atau dinamis **belum dikonfirmasi**; kalau dinamis, domainnya akan menunjuk ke pelanggan lain begitu IP berganti |
| Domain | **`https://reservation.roemahumara.com/` — LIVE sejak 2026-09-07**, HTTP 200 dari luar jaringan kantor |
| DNS dikelola di | panel Niagahoster (`ns1/ns2.niagahoster.com`) |
| OS | Ubuntu 24.04.4 LTS |
| Hostname | `cms-ru-reservation` |
| RAM | 1,9 GiB + swap 2 GiB (sudah ada bawaan, tidak perlu dibuat) |
| PHP | 8.3.6 di `/usr/bin/php8.3` |
| Composer | 2.10.2 |
| User deploy | `ictumara` — sekaligus akun login SSH |
| Terakhir diperbarui | 2026-09-07 (bagian 8 selesai — sistem live di domain publik) |

## Status per bagian

| # | Bagian | Status | Catatan |
|---|---|---|---|
| 0 | Prasyarat | ✅ | |
| 1a | Update paket & user deploy | ✅ | User deploy = `ictumara`, akun yang sudah ada |
| 1b | Firewall UFW | ✅ | Perintah `ufw` harus pakai `sudo`, kalau tidak muncul "you need to be root" |
| 1c | Swap | ✅ | Sudah ada 2 GiB bawaan, tidak dibuat ulang |
| 1d | Kunci SSH | ⏸️ | **Ditunda sengaja.** Akses lewat VPN kantor, jadi belum mendesak. Wajib sebelum server menghadap internet — dan itu sekarang sedang disiapkan (bagian 8) |
| 2 | PHP 8.3 | ✅ | Dari repo Ubuntu sendiri (`noble-updates/universe`). **PPA ondrej tidak dipakai dan tidak dibutuhkan** di 24.04 |
| 3 | MySQL 8 | ✅ | User `roemahumara`, database `roemahumara`. Sandi ada di catatan pribadi — dipakai lagi di `.env` bagian 6 |
| 4 | Nginx | ✅ | |
| 5 | Composer | ✅ | 2.10.2, terikat ke `/usr/bin/php8.3` |
| 6 | Direktori aplikasi & `.env` | ✅ | Admin `roemahumara@gmail.com` sudah punya role — uji `can('reservation.delete')` mengembalikan `true` |
| 7 | Nginx server block | ✅ | `/cms/login` → HTTP 200. `/` sempat HTTP 500 (`Vite manifest not found`) sampai deploy pertama mengisi `public/build` — sekarang 200 |
| 8 | Domain publik: DNS, port forward, SSL | ✅ | **Selesai 2026-09-07.** `https://reservation.roemahumara.com/` menjawab 200 dari luar; `APP_URL` sudah ikut diganti. Trafik masuk lewat reverse proxy IIS. Cara ketiga hambatannya diselesaikan **akan diisi Arnold menyusul**. Sisa pekerjaan yang lahir dari ini (redirect 80→443, `SESSION_SECURE_COOKIE`, `robots.txt`) ada di blok ⚠️ paling atas |
| 9 | Queue worker | ✅ | `active (running)`, `enabled` — ikut hidup setelah reboot. Diuji 2026-08-24 |
| 10 | Scheduler cron | ✅ | Dipasang lewat berkas (`crontab -u ictumara /tmp/ru-cron`), bukan editor. Terbukti jalan tiap menit di `/var/log/syslog` |
| 11 | Sudo terbatas untuk deploy | ✅ | Dipasang lewat berkas + `visudo -cf`, bukan `visudo` interaktif. `sudo -l -U ictumara` memastikan **kedua** perintah tercakup, bukan hanya yang diuji |
| 12 | Self-hosted runner | ✅ | Deploy manual pertama Success dalam 46 detik (2026-08-24). `/` kini HTTP 200, aset Vite termuat |
| 13 | Deploy manual (cadangan) | — | Bukan langkah pemasangan. Prosedur cadangan, dibaca kalau runner mati |

Keterangan: ✅ selesai · ⏭️ sedang dikerjakan · ⏸️ ditunda sengaja · ⬜ belum

## Insiden 2026-09-07 — deploy gagal di langkah permission

Gejalanya: setiap push memicu workflow, tapi jobnya berakhir
`[deploy][ERROR] Deploy gagal di baris 80` dengan
`chmod: changing permissions of 'storage/fonts/nunito_*': Operation not permitted`.

**Akar masalahnya bukan hak akses `ictumara` yang kurang.** dompdf menulis cache
fontnya ke `storage/fonts` **saat ada yang mencetak PDF**, dan itu berjalan
sebagai `www-data`. `chmod` hanya boleh dilakukan pemilik berkas atau root —
seberapa pun besar sudo yang dipunya user lain. Jadi deploy berjalan mulus sejak
2026-08-24 dan baru pecah pada cetakan PDF pertama di produksi, di berkas yang
belum ada saat `deploy.sh` ditulis.

Diperbaiki di `deploy/deploy.sh`: yang di-chmod hanya berkas milik user deploy
(`find ... -user "$(id -un)"`). Berkas milik `www-data` memang tidak perlu
disentuh — yang membuatnya adalah proses yang perlu menulisinya.

Yang perlu diketahui soal dampaknya: kegagalan itu terjadi **setelah** migrasi
dan cache dibangun, tapi **sebelum** PHP-FPM di-reload dan queue worker
direstart. Jadi selama masa itu kode baru sudah terpasang sementara opcache
masih menyajikan kode lama. Gejala khasnya: "sudah saya deploy tapi
perubahannya tidak muncul".

**Terkonfirmasi di server 2026-09-08.** `ls -ln storage/fonts` menunjukkan
seluruh berkas font milik **UID/GID 33 — `www-data` di Ubuntu** — bertanggal
**26 Agustus**, dua hari setelah deploy sukses terakhir. Di situlah cetakan PDF
pertama di produksi terjadi, dan sejak detik itu setiap deploy gagal di baris
yang sama. Pakai `ls -ln` (numerik), bukan `ls -l`: yang kedua menampilkan nama
user dan menyamarkan bahwa pemiliknya user yang berbeda.

## `.git` di `/var/www/roemahumara` — sudah dihapus 2026-09-08

Deploy mengirim kode lewat **`rsync`, bukan `git pull`**, dan rsync meng-exclude
`.git` — dan **exclude itu benar, jangan dihapus**: riwayat proyek tidak perlu
ikut ke server. Akibatnya `.git` di server berhenti di commit hasil `git clone`
saat pemasangan dan tidak pernah diperbarui sekali pun.

**Angkanya, sebelum dihapus:** `.git` di sana memegang `e019187` (2026-08-23),
sedangkan `main` sudah 30 commit di depannya. Tiga puluh commit itulah yang
selama ini dilaporkan `git status` sebagai "Changes not staged for commit" —
**tanpa ada seorang pun yang menyunting di server**. Itu selisih antara kode
baru hasil rsync dan commit lama yang dipegang `.git`, bukan tanda kerusakan.

Yang benar-benar rusak karena ini cuma satu: prosedur rollback "Opsi B" di
RUNBOOK yang menyuruh `git checkout` di direktori itu — ia tidak pernah bisa
jalan sejak ditulis, dan satu-satunya saat orang membukanya adalah saat produksi
bermasalah. Sudah diganti 2026-09-07.

`.git`-nya dihapus 2026-09-08. Sejak itu `git status` di sana menjawab
`fatal: not a git repository`, dan **itu jawaban yang benar** — direktori itu
memang bukan repo git. Tidak ada langkah deploy yang memakainya; sudah diperiksa
bahwa tidak satu pun kode aplikasi membacanya. Kalau server kelak dipasang
ulang, `git clone` di RUNBOOK bagian 6 tetap jalur bootstrap yang benar —
yang keliru dulu adalah membiarkan sisanya lalu memercayainya.
## Keputusan yang sudah diambil

**Sistem dibuka ke internet lewat port forwarding** (2026-08-24). Bukan
internal-saja, bukan Cloudflare Tunnel. Konsekuensinya dicatat di bagian 8
RUNBOOK — terutama bahwa halaman publik `/` menampilkan nama tamu, perusahaan,
PIC, dan remark tanpa login, dan itu akan terbaca siapa saja begitu port 80
terbuka.

**User deploy = `ictumara`** (2026-08-23). RUNBOOK sebelumnya menyebut user
khusus bernama `marcom`; ke-35 rujukannya diganti karena `ictumara` sudah ada
dan dipakai untuk masuk server.

Yang perlu disadari: runner GitHub Actions berjalan sebagai akun yang sama
dengan akun administrasi server, jadi keduanya berbagi hak yang sama. Kalau
kredensial runner bocor, yang terpapar bukan sekadar hak deploy — `sudo -l -U ictumara`
pada 2026-08-24 memastikan akun ini memang `(ALL : ALL) ALL`. Memisahkannya
nanti masih mungkin — buat user baru, pindahkan kepemilikan
`/var/www/roemahumara`, daftarkan ulang runner.

## Hambatan bagian 8 — riwayatnya

Ketiganya menahan bagian 8 dari 2026-08-24 sampai 2026-09-06. **Sejak 2026-09-07
tidak ada lagi yang menghalangi** — domainnya sudah menjawab dari luar. Riwayat
ini disimpan, bukan dihapus, supaya kalau domainnya suatu saat mati, yang
membaca tahu apa saja yang dulu jadi soal.

- [x] **Port 443 sudah dipakai mesin lain.** `https://103.138.40.54/` dulu
      menjawab dengan header `Server: Microsoft-HTTPAPI/2.0` — layanan Windows,
      bukan VPS ini. Balasan domainnya sekarang menyebut `Microsoft-IIS/10.0`
      dan `ARR/3.0`, jadi kemungkinan besar mesin itu **tidak digusur melainkan
      dijadikan reverse proxy** ke VPS. Belum dikonfirmasi Arnold.
- [x] **Port 80 belum terbuka.** Sekarang terbuka — `http://` menjawab 200 dari
      luar. Justru itu masalah barunya: ia **tidak** dialihkan ke `https://`
      (lihat blok ⚠️ di atas).
- [ ] **Status IP `103.138.40.54`: statis atau dinamis? — MASIH TERBUKA.**
      Domainnya jalan hari ini, dan itu tidak menjawab pertanyaannya: IP dinamis
      juga jalan sampai ia berganti. Kalau dinamis, perlu DDNS — kalau tidak,
      domainnya akan menunjuk ke pelanggan lain begitu IP-nya berganti, dan
      gejalanya muncul mendadak tanpa ada yang menyentuh sistem. Tanyakan ke
      BaliFiber.

**Cara ketiganya diselesaikan belum tercatat** — Arnold akan mengisinya
menyusul. Sampai itu masuk, RUNBOOK bagian 8 masih memuat rencana lama (port
forwarding + Certbot langsung ke VPS), yang **tidak** cocok dengan apa yang
teramati sekarang. Jangan mengikutinya mentah-mentah untuk memasang ulang.

## Yang sengaja belum dikerjakan

Dicatat di sini supaya tidak hilang; rinciannya di RUNBOOK bagian 8.

- `SESSION_SECURE_COOKIE` masih `false` — **sudah jatuh tempo**: HTTPS aktif
  sejak 2026-09-07 dan cookie sesi masih datang tanpa tanda `secure`
- ~~`APP_URL` masih `http://192.168.88.33`~~ — **selesai**: tautan dan aset di
  halaman publik sudah `https://reservation.roemahumara.com/...` (2026-09-07).
  Pastikan secret `APP_URL` di GitHub ikut berubah, kalau belum
- Redirect `http://` → `https://` belum ada — port 80 masih menjawab 200
- `robots.txt` masih mengizinkan seluruh halaman diindeks, termasuk kalender
  publik berisi nama tamu dan remark
- Login SSH dengan sandi masih terbuka (bagian 1d)
- Sepuluh akun staf **belum dibuat** — jalankan `db:seed --class=StaffSeeder`
  setelah `INITIAL_USER_PASSWORD` diisi. Sesudahnya sebelas akun memakai sandi
  yang sama sampai masing-masing menggantinya sendiri
- Halaman publik menampilkan nama tamu, perusahaan, dan remark tanpa login
