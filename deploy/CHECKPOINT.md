# Checkpoint deployment — VPS 192.168.88.33

Berkas ini menjawab satu pertanyaan: **sudah sampai mana?**

Dipakai supaya pemasangan bisa dilanjutkan dari laptop lain, atau setelah jeda
beberapa hari, tanpa menebak-nebak langkah mana yang sudah jalan. Langkahnya
sendiri ada di [RUNBOOK.md](RUNBOOK.md) — di sini hanya statusnya.

Berkas ini ikut git, jadi cukup `git pull` di mesin mana pun untuk melihat
keadaan terakhir. **Perbarui setiap kali sebuah bagian selesai**, lalu commit.

---

# ▶ PEMASANGAN SERVER SELESAI — sisanya di luar server

Bagian 0–12 selesai dan sudah diuji. Sistem berjalan di
`http://192.168.88.33`, deploy otomatis sudah terbukti (Success, 46 detik,
2026-08-24). Setiap push ke `main` sekarang memicu deploy sendiri.

Bagian 13 bukan langkah pemasangan — itu prosedur cadangan, dibaca hanya kalau
runner mati dan Anda perlu merilis dengan tangan.

**Yang tersisa hanya bagian 8: membuka sistem ke internet.** Kerjanya bukan di
server melainkan di router kantor dan di BaliFiber, jadi tidak ada perintah yang
bisa dijalankan lewat SSH untuk menuntaskannya. Lihat "Yang sedang menunggu
pihak lain" di bawah.

Sebelum bagian 8 dikerjakan, dua hal ini berhenti jadi opsional:

- **Kunci SSH (bagian 1d)** — port 22 yang menghadap internet dipindai bot dalam
  hitungan menit. Selama SSH tidak ikut di-forward di router, ia memang belum
  terjangkau; tapi jangan mengandalkan itu.
- **Sandi awal sebelas akun staf** masih sama semua.

Dan satu keputusan yang perlu diambil sadar, bukan dilewati: halaman `/`
menampilkan nama tamu, perusahaan, PIC, dan remark **tanpa login**, sedangkan
remark di sistem ini terbiasa memuat keterangan pembayaran. Di jaringan lokal
itu hanya terbaca orang kantor. Begitu port 80 dibuka, terbaca siapa saja dan
bisa terindeks mesin pencari. Menariknya kembali cukup menghapus kolomnya dari
`select()` di `PublicCalendarController`.

---

## Keadaan server

| | |
|---|---|
| IP privat | `192.168.88.33` (hanya dari VPN kantor) |
| IP publik kantor | `103.138.40.54` (`ip-40-54.balifiber.id`) — **belum diketahui statis atau dinamis** |
| Domain | `reservation.roemahumara.com` → `103.138.40.54` — **A record sudah ada**, tinggal port forwarding |
| DNS dikelola di | panel Niagahoster (`ns1/ns2.niagahoster.com`) |
| OS | Ubuntu 24.04.4 LTS |
| Hostname | `cms-ru-reservation` |
| RAM | 1,9 GiB + swap 2 GiB (sudah ada bawaan, tidak perlu dibuat) |
| PHP | 8.3.6 di `/usr/bin/php8.3` |
| Composer | 2.10.2 |
| User deploy | `ictumara` — sekaligus akun login SSH |
| Terakhir diperbarui | 2026-08-24 (bagian 12 selesai) |

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
| 8 | Domain publik: DNS, port forward, SSL | ⏭️ | DNS ✅ sudah diarahkan. Port forwarding **belum** — menunggu pihak lain, lihat di bawah |
| 9 | Queue worker | ✅ | `active (running)`, `enabled` — ikut hidup setelah reboot. Diuji 2026-08-24 |
| 10 | Scheduler cron | ✅ | Dipasang lewat berkas (`crontab -u ictumara /tmp/ru-cron`), bukan editor. Terbukti jalan tiap menit di `/var/log/syslog` |
| 11 | Sudo terbatas untuk deploy | ✅ | Dipasang lewat berkas + `visudo -cf`, bukan `visudo` interaktif. `sudo -l -U ictumara` memastikan **kedua** perintah tercakup, bukan hanya yang diuji |
| 12 | Self-hosted runner | ✅ | Deploy manual pertama Success dalam 46 detik (2026-08-24). `/` kini HTTP 200, aset Vite termuat |
| 13 | Deploy manual (cadangan) | — | Bukan langkah pemasangan. Prosedur cadangan, dibaca kalau runner mati |

Keterangan: ✅ selesai · ⏭️ sedang dikerjakan · ⏸️ ditunda sengaja · ⬜ belum

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

## Yang sedang menunggu pihak lain

Ketiganya menghalangi bagian 8, tidak menghalangi 9–12.

- [ ] **Port 443 sudah dipakai mesin lain.** `https://103.138.40.54/` menjawab
      dengan header `Server: Microsoft-HTTPAPI/2.0` — layanan Windows, bukan VPS
      ini. Perlu diketahui itu apa dan milik siapa sebelum aturan forwarding
      diubah; mengarahkan 443 ke VPS akan mematikannya.
- [ ] **Port 80 belum terbuka.** Perlu aturan forwarding di router kantor,
      dan perlu dipastikan BaliFiber tidak memblokir inbound 80/443.
- [ ] **Status IP `103.138.40.54`: statis atau dinamis?** Tanyakan ke BaliFiber.
      Kalau dinamis, perlu DDNS — domainnya akan menunjuk ke pelanggan lain
      begitu IP-nya berganti.

Catatan: hasil pemeriksaan port di atas diambil dari **dalam** jaringan kantor,
jadi sifatnya petunjuk. Verifikasi yang sahih dilakukan dari HP dengan data
seluler, WiFi kantor dimatikan.

## Yang sengaja belum dikerjakan

Dicatat di sini supaya tidak hilang; rinciannya di RUNBOOK bagian 8.

- `SESSION_SECURE_COOKIE` masih `false` — **wajib** jadi `true` begitu HTTPS aktif
- `APP_URL` masih `http://192.168.88.33` — ikut diganti saat HTTPS aktif, termasuk secret di GitHub
- Login SSH dengan sandi masih terbuka (bagian 1d)
- Sebelas akun staf masih memakai sandi awal yang sama dari `INITIAL_USER_PASSWORD`
- Halaman publik menampilkan nama tamu, perusahaan, dan remark tanpa login
