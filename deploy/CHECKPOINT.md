# Checkpoint deployment — VPS 192.168.88.33

Berkas ini menjawab satu pertanyaan: **sudah sampai mana?**

Dipakai supaya pemasangan bisa dilanjutkan dari laptop lain, atau setelah jeda
beberapa hari, tanpa menebak-nebak langkah mana yang sudah jalan. Langkahnya
sendiri ada di [RUNBOOK.md](RUNBOOK.md) — di sini hanya statusnya.

Berkas ini ikut git, jadi cukup `git pull` di mesin mana pun untuk melihat
keadaan terakhir. **Perbarui setiap kali sebuah bagian selesai**, lalu commit.

---

# ▶ LANJUT DARI SINI — bagian 7: Nginx server block

Bagian 0–6 sudah selesai. Aplikasi sudah ada di `/var/www/roemahumara`, database
terisi, akun admin siap. Yang belum: Nginx belum tahu harus menyajikannya.

Masuk ke server, lalu:

```bash
ssh ictumara@192.168.88.33     # lewat VPN kantor

cd /var/www/roemahumara
sudo cp deploy/nginx/roemahumara.conf /etc/nginx/sites-available/roemahumara
sudo ln -sf /etc/nginx/sites-available/roemahumara /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

sudo nginx -t && sudo systemctl reload nginx
```

**Ujinya lewat `http://192.168.88.33/cms/login`, bukan `/`.** Halaman depan masih
akan gagal dengan `Vite manifest not found` sampai deploy pertama berjalan —
lihat RUNBOOK bagian 7 untuk sebabnya. Itu bukan tanda pemasangan yang rusak.

Kalau halaman login muncul, tandai bagian 7 ✅ di tabel bawah, commit, lalu
lanjut ke bagian 9 (queue worker) — bagian 8 sengaja dilewati sampai domainnya
siap.

---

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
| User deploy | `ictumara` — sekaligus akun login SSH |
| Terakhir diperbarui | 2026-08-23 |

## Status per bagian

| # | Bagian | Status | Catatan |
|---|---|---|---|
| 0 | Prasyarat | ✅ | |
| 1a | Update paket & user deploy | ✅ | User deploy = `ictumara`, akun yang sudah ada |
| 1b | Firewall UFW | ✅ | Perintah `ufw` harus pakai `sudo`, kalau tidak muncul "you need to be root" |
| 1c | Swap | ✅ | Sudah ada 2 GiB bawaan, tidak dibuat ulang |
| 1d | Kunci SSH | ⏸️ | **Ditunda sengaja.** Akses lewat VPN kantor, jadi belum mendesak. Wajib sebelum server menghadap internet |
| 2 | PHP 8.3 | ✅ | Dari repo Ubuntu sendiri (`noble-updates/universe`). **PPA ondrej tidak dipakai dan tidak dibutuhkan** di 24.04 |
| 3 | MySQL 8 | ✅ | User `roemahumara`, database `roemahumara`. Sandi ada di catatan pribadi — dipakai lagi di `.env` bagian 6 |
| 4 | Nginx | ✅ | |
| 5 | Composer | ✅ | 2.10.2, terikat ke `/usr/bin/php8.3` |
| 6 | Direktori aplikasi & `.env` | ✅ | Admin `roemahumara@gmail.com` sudah punya role — uji `can('reservation.delete')` mengembalikan `true` |
| 7 | Nginx server block | ⏭️ | **Sedang dikerjakan — lihat "LANJUT DARI SINI" di atas** |
| 8 | SSL Let's Encrypt | ⏸️ | Menunggu domain diarahkan — **lewati**, lanjut ke 9 |
| 9 | Queue worker | ⬜ | Setelah 7 |
| 10 | Scheduler cron | ⬜ | |
| 11 | Sudo terbatas untuk deploy | ⬜ | |
| 12 | Self-hosted runner | ⬜ | |
| 13 | Deploy manual (cadangan) | ⬜ | Hanya dibaca kalau 12 bermasalah |

Keterangan: ✅ selesai · ⏭️ sedang dikerjakan · ⏸️ ditunda sengaja · ⬜ belum

## Keputusan yang sudah diambil

**User deploy = `ictumara`** (2026-08-23). RUNBOOK sebelumnya menyebut user
khusus bernama `marcom`; ke-35 rujukannya diganti karena `ictumara` sudah ada
dan dipakai untuk masuk server.

Yang perlu disadari: runner GitHub Actions berjalan sebagai akun yang sama
dengan akun administrasi server, jadi keduanya berbagi hak yang sama. Kalau
kredensial runner bocor, yang terpapar bukan sekadar hak deploy. Memisahkannya
nanti masih mungkin — buat user baru, pindahkan kepemilikan
`/var/www/roemahumara`, daftarkan ulang runner.

## Yang sengaja belum dikerjakan

Dicatat di sini supaya tidak hilang; rinciannya di RUNBOOK bagian 8.

- SSL / HTTPS — menunggu `reservation.roemahumara.com` diarahkan ke server
- `SESSION_SECURE_COOKIE` masih `false` — **wajib** jadi `true` begitu HTTPS aktif
- Login SSH dengan sandi masih terbuka
- Sebelas akun staf masih memakai sandi awal yang sama
- Halaman publik menampilkan nama tamu, perusahaan, dan remark tanpa login
