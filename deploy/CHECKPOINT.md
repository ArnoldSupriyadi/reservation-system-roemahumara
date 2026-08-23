# Checkpoint deployment — VPS 192.168.88.33

Berkas ini menjawab satu pertanyaan: **sudah sampai mana?**

Dipakai supaya pemasangan bisa dilanjutkan dari laptop lain, atau setelah jeda
beberapa hari, tanpa menebak-nebak langkah mana yang sudah jalan. Langkahnya
sendiri ada di [RUNBOOK.md](RUNBOOK.md) — di sini hanya statusnya.

Berkas ini ikut git, jadi cukup `git pull` di mesin mana pun untuk melihat
keadaan terakhir. **Perbarui setiap kali sebuah bagian selesai**, lalu commit.

## Keadaan server

| | |
|---|---|
| IP | `192.168.88.33` (privat — hanya dari VPN kantor) |
| Domain rencana | `reservation.roemahumara.com` (belum diarahkan) |
| OS | Ubuntu 24.04.4 LTS |
| Hostname | `cms-ru-reservation` |
| RAM | 1,9 GiB + swap 2 GiB (sudah ada bawaan, tidak perlu dibuat) |
| PHP | 8.3.6 di `/usr/bin/php8.3` |
| Composer | 2.10.2 |
| Login SSH | `ictumara` |
| Terakhir diperbarui | 2026-08-23 |

## Status per bagian

| # | Bagian | Status | Catatan |
|---|---|---|---|
| 0 | Prasyarat | ✅ | |
| 1a | Update paket & user deploy | ✅ | Login sebagai `ictumara`, **bukan** `marcom` — lihat "Keputusan tertunda" |
| 1b | Firewall UFW | ✅ | Perintah `ufw` harus pakai `sudo`, kalau tidak muncul "you need to be root" |
| 1c | Swap | ✅ | Sudah ada 2 GiB bawaan, tidak dibuat ulang |
| 1d | Kunci SSH | ⏸️ | **Ditunda sengaja.** Akses lewat VPN kantor, jadi belum mendesak. Wajib sebelum server menghadap internet |
| 2 | PHP 8.3 | ✅ | Dari repo Ubuntu sendiri (`noble-updates/universe`). **PPA ondrej tidak dipakai dan tidak dibutuhkan** di 24.04 |
| 3 | MySQL 8 | ✅ | User `roemahumara`, database `roemahumara`. Sandi ada di catatan pribadi — dipakai lagi di `.env` bagian 6 |
| 4 | Nginx | ✅ | |
| 5 | Composer | ✅ | 2.10.2, terikat ke `/usr/bin/php8.3` |
| 6 | Direktori aplikasi & `.env` | ⏭️ | **Berikutnya.** Keputusan `marcom`/`ictumara` harus selesai lebih dulu |
| 7 | Nginx server block | ⬜ | |
| 8 | SSL Let's Encrypt | ⏸️ | Menunggu domain diarahkan |
| 9 | Queue worker | ⬜ | |
| 10 | Scheduler cron | ⬜ | |
| 11 | Sudo terbatas untuk deploy | ⬜ | |
| 12 | Self-hosted runner | ⬜ | |
| 13 | Deploy manual (cadangan) | ⬜ | Hanya dibaca kalau 12 bermasalah |

Keterangan: ✅ selesai · ⏭️ sedang dikerjakan · ⏸️ ditunda sengaja · ⬜ belum

## Keputusan tertunda

**`marcom` vs `ictumara`.** RUNBOOK menyebut user `marcom` di 35 tempat,
sedangkan server dimasuki sebagai `ictumara`. Salah satu harus mengalah
**sebelum bagian 6**: di sanalah kepemilikan `/var/www/roemahumara` ditetapkan,
dan runner GitHub Actions nanti berjalan sebagai user yang sama. Kalau keduanya
tidak konsisten, gejalanya muncul jauh kemudian sebagai "Permission denied" saat
deploy pertama, bukan saat langkah yang keliru dijalankan.

## Yang sengaja belum dikerjakan

Dicatat di sini supaya tidak hilang; rinciannya di RUNBOOK bagian 8.

- SSL / HTTPS — menunggu `reservation.roemahumara.com` diarahkan ke server
- `SESSION_SECURE_COOKIE` masih `false` — **wajib** jadi `true` begitu HTTPS aktif
- Login SSH dengan sandi masih terbuka
- Sebelas akun staf masih memakai sandi awal yang sama
- Halaman publik menampilkan nama tamu, perusahaan, dan remark tanpa login
