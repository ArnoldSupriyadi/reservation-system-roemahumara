# Runbook Provisioning VPS — Reservation System Roemah Umara

Target: **Ubuntu 24.04 LTS** (diuji di 24.04.4), stack Nginx + PHP 8.3-FPM + MySQL 8. Ubuntu 22.04 juga jalan, dengan satu langkah tambahan di bagian 2.

Deploy berjalan otomatis setiap push ke `main` lewat self-hosted GitHub Actions
runner yang dipasang di VPS (bagian 12). Prosedur manualnya tetap ada di bagian
13 sebagai cadangan.

Semua perintah dijalankan lewat SSH ke VPS. Ganti setiap `CHANGE_ME`.

> **Keadaan saat ini: jaringan lokal, belum HTTPS.**
>
> VPS beralamat `192.168.88.33` — alamat privat yang hanya terjangkau dari dalam
> jaringan Anda. Konsekuensinya sepanjang runbook ini:
>
> - **Belum ada SSL.** Let's Encrypt butuh domain publik; bagian 8 dilewati dulu.
> - **`SESSION_SECURE_COOKIE` harus `false`.** Cookie "secure" hanya dikirim
>   lewat HTTPS — dibiarkan `true` di alamat http, sesi tidak pernah tersimpan
>   dan sandi yang benar pun berakhir kembali di halaman login tanpa pesan
>   kesalahan apa pun.
> - **GitHub tidak bisa menghubungi VPS.** Itu sebabnya deploy memakai
>   self-hosted runner yang menghubungi GitHub keluar, bukan sebaliknya.
> - **Halaman publik hanya terbuka di jaringan lokal.** Nama tamu, PIC, dan
>   remark yang tampil di sana belum terbaca dari internet. **Itu berubah begitu
>   domain publik dipasang** — lihat bagian 8.

Konvensi yang dipakai di semua file config:

| Item | Nilai |
|---|---|
| Direktori aplikasi | `/var/www/roemahumara` |
| User deploy | `marcom` |
| User web | `www-data` |
| PHP | 8.3 (`/usr/bin/php8.3`) |
| Database | `roemahumara` |
| Service queue | `roemahumara-queue` |

---

## 0. Prasyarat

- Akses root/sudo ke VPS
- VPS bisa dijangkau dari komputer Anda:
  ```bash
  ping -c 2 192.168.88.33
  ```
- Domain `reservation.roemahumara.com` **belum diperlukan sekarang**. Baru dibutuhkan saat menyiapkan HTTPS (bagian 8).
- RAM minimal 2 GB. Di bawah itu MySQL 8 + PHP-FPM akan sesak. Kalau hanya 1 GB, tambahkan swap (langkah 1c).

---

## 1. Hardening dasar

### 1a. Update & user non-root

```bash
sudo apt update && sudo apt upgrade -y

sudo adduser --gecos "" marcom
sudo usermod -aG sudo marcom
```

### 1b. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable
sudo ufw status
```

### 1c. Swap (lewati kalau RAM >= 4 GB)

```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### 1d. Matikan login password SSH

> **Boleh ditunda selama VPS masih di jaringan lokal.** Langkah ini melindungi
> dari serangan tebak-sandi dari internet, sementara `192.168.88.33` hanya
> terjangkau lewat jaringan kantor atau VPN. Risiko menjalankannya justru lebih
> nyata: satu kekeliruan pada penyiapan SSH key membuat Anda terkunci di luar,
> dan pemulihannya menuntut akses konsol lewat hypervisor.
>
> **WAJIB dikerjakan sebelum VPS punya IP publik** — lihat daftar di bagian 8.
> Bot pemindai menemukan port 22 yang baru terbuka dalam hitungan menit.

Lakukan **setelah** memastikan login dengan SSH key berhasil, kalau tidak kamu terkunci di luar.

```bash
sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/'                /etc/ssh/sshd_config
sudo systemctl restart ssh
```

---

## 2. Install PHP 8.3

**Periksa dulu — di Ubuntu 24.04 tidak perlu menambah repositori apa pun.**

```bash
lsb_release -ds
apt-cache policy php8.3-fpm
```

- **Ubuntu 24.04** — PHP 8.3 sudah ada di repositori bawaan (`Candidate: 8.3.x`).
  **Lewati blok PPA di bawah**, langsung ke `apt install`.
- **Ubuntu 22.04** — bawaannya PHP 8.1, terlalu tua untuk Laravel 12
  (`php: ^8.2`). `Candidate: (none)` menandakan itu; tambahkan PPA ondrej dulu.

```bash
# HANYA untuk Ubuntu 22.04
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
```

Pemasangan, sama untuk kedua versi:

```bash
sudo apt install -y \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl

php8.3 -v
```

### Tuning `php.ini`

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```

```ini
memory_limit = 256M
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 60
expose_php = Off

; OPcache — wajib di produksi, dampaknya ke performa besar.
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0
```

> `opcache.validate_timestamps = 0` berarti PHP **tidak** mengecek perubahan file.
> Konsekuensinya: setiap deploy WAJIB reload PHP-FPM. Deploy di sini dikerjakan
> manual, jadi tidak ada yang mengingatkan — lupa reload berarti kode lama terus
> tersaji padahal file di server sudah baru, dan itu terlihat seperti "deploy
> saya tidak berpengaruh". Langkahnya sudah ada di dalam `deploy/deploy.sh`.

```bash
sudo systemctl restart php8.3-fpm
```

---

## 3. Install & amankan MySQL 8

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation
```

`mysql_secure_installation` bertanya beberapa hal. Jawab `y` untuk semuanya —
hapus pengguna anonim, tolak login root dari jarak jauh, hapus database test,
muat ulang tabel privilege.

Pertanyaan pertama, **VALIDATE PASSWORD component**, boleh `y` maupun `n`:

- **`y`** — MySQL menolak sandi lemah. Sandi database ini disimpan sekali di
  `.env` dan tidak pernah diketik manusia lagi, jadi membuatnya panjang dan acak
  tidak merepotkan siapa pun. Ini pilihan yang lebih baik.
- **`n`** — sandi apa pun diterima.

Siapkan sandi databasenya lebih dulu, di terminal biasa:

```bash
openssl rand -base64 24
```

**Catat hasilnya.** Nilai itu dipakai dua kali: di perintah `CREATE USER` di
bawah, dan sebagai `DB_PASSWORD` di `.env` pada bagian 6.

Buat database dan user aplikasi:

```bash
sudo mysql
```

```sql
CREATE DATABASE roemahumara
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Ganti dengan hasil `openssl rand -base64 24` di atas. Mengetik placeholder
-- ini apa adanya ditolak dengan ERROR 1819: ia tidak memuat angka, sehingga
-- gagal memenuhi kebijakan VALIDATE PASSWORD.
CREATE USER 'roemahumara'@'localhost'
  IDENTIFIED BY 'GANTI_DENGAN_SANDI_ACAK';

-- Sengaja TIDAK diberi ALL PRIVILEGES. Aplikasi tidak perlu DROP DATABASE.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON roemahumara.* TO 'roemahumara'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

Verifikasi:

```bash
mysql -u roemahumara -p roemahumara -e "SELECT 1;"
```

> `-p` ditulis tanpa sandi menempel supaya MySQL menanyakannya secara
> tersembunyi. Menulis `-pSANDI` juga jalan, tapi sandinya tertinggal di riwayat
> shell.
>
> Kalau `CREATE USER` ditolak dengan **ERROR 1819**, sandinya tidak memenuhi
> kebijakan: minimal 8 karakter dengan huruf besar, huruf kecil, angka, dan
> karakter khusus. Periksa aturannya dengan
> `SHOW VARIABLES LIKE 'validate_password%';`

---

## 4. Install Nginx

```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

---

## 5. Composer

```bash
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
sudo php8.3 /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
composer --version
```

> Node **tidak perlu diinstall di server**. Build aset dilakukan di komputer lokal
> lalu dikirim lewat rsync — lihat bagian 12a.

---

## 6. Siapkan direktori aplikasi

```bash
sudo mkdir -p /var/www/roemahumara
sudo chown -R marcom:www-data /var/www/roemahumara
sudo chmod -R 2775 /var/www/roemahumara   # setgid: file baru ikut grup www-data
```

Clone sekali untuk bootstrap awal (deploy berikutnya lewat rsync dari CI):

```bash
cd /var/www
sudo -u marcom git clone https://github.com/ArnoldSupriyadi/reservation-system-roemahumara.git roemahumara
cd roemahumara
sudo -u marcom composer install --no-dev --optimize-autoloader
```

### Konfigurasi `.env`

Isi yang wajib disesuaikan sekarang:

| Kunci | Nilai untuk kondisi sekarang |
|---|---|
| `APP_URL` | `http://192.168.88.33` |
| `SESSION_SECURE_COOKIE` | `false` — **wajib**, selama masih HTTP |
| `DB_PASSWORD` | sandi MySQL dari bagian 3 |
| `INITIAL_USER_PASSWORD` | sandi awal semua akun; isi **sebelum** seeder dijalankan |

```bash
sudo -u marcom cp .env.production.example .env
sudo -u marcom php8.3 artisan key:generate
sudo -u marcom nano .env     # isi APP_URL, DB_PASSWORD, INITIAL_USER_PASSWORD
sudo chmod 640 .env
sudo chown marcom:www-data .env
```

### Permission storage

```bash
sudo chown -R marcom:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

> Termasuk `storage/fonts`, yang dipakai dompdf untuk menyimpan font olahan saat
> membuat PDF reservasi. Direktorinya ikut repositori, tapi kalau tidak bisa
> ditulis, pembuatan PDF gagal dengan `TypeError` dari `fwrite()` — pesan yang
> tidak menyebut direktori sama sekali.

### Migrasi awal

```bash
cd /var/www/roemahumara
sudo -u marcom php8.3 artisan migrate --force
sudo -u marcom php8.3 artisan storage:link
```

### Data awal & akun pertama

**Jangan lewati bagian ini.** `migrate` hanya membuat tabel kosong. Tanpa langkah
di bawah, `/cms/login` terbuka tapi tidak ada satu pun akun yang bisa masuk, dan
form reservasi tidak punya pilihan area maupun jenis acara. Anda terkunci di luar
sistem sendiri.

> **Setel `INITIAL_USER_PASSWORD` di `.env` LEBIH DULU.** Sandi awal semua akun
> diambil dari sana. Kalau belum disetel, sandinya jatuh ke `password` — dan
> karena seeder memakai `firstOrCreate`, membetulkan `.env` sesudahnya TIDAK
> memperbaiki akun yang terlanjur dibuat.

Dua perintah, itu saja:

```bash
cd /var/www/roemahumara

# Role, permission, master (area, jenis acara, 137 menu), dan akun admin
sudo -u marcom php8.3 artisan db:seed --force

# Sepuluh akun staf
sudo -u marcom php8.3 artisan db:seed --class=StaffSeeder --force
```

`db:seed` polos sudah membuat akun admin **`roemahumara@gmail.com`** sekaligus
memberinya role dan membersihkan cache permission. Tidak perlu
`make:filament-user` — itu hanya diperlukan kalau kelak ingin menambah admin
kedua, dan perintah itu **tidak memberi role sama sekali** sehingga akunnya bisa
masuk tapi setiap tombol tertutup.

Kedua seeder aman diulang.

> **Sandi awalnya sama untuk sebelas akun.** Minta setiap orang menggantinya
> setelah masuk pertama kali. Selama belum diganti, satu orang yang tahu sandinya
> bisa masuk sebagai siapa saja dan `activity_log` akan menunjuk orang yang
> keliru.

Uji sebelum lanjut — harus mencetak `true`:

```bash
sudo -u marcom php8.3 artisan tinker --execute="echo var_export(App\Models\User::where('email','roemahumara@gmail.com')->firstOrFail()->can('reservation.delete'), true);"
```

Kalau hasilnya `false`, rolenya belum terbaca:

```bash
sudo -u marcom php8.3 artisan permission:cache-reset
```

---

## 7. Pasang Nginx server block

```bash
cd /var/www/roemahumara
sudo cp deploy/nginx/roemahumara.conf /etc/nginx/sites-available/roemahumara
# server_name sudah berisi 192.168.88.33; ganti ke domain saat HTTPS disiapkan

sudo ln -sf /etc/nginx/sites-available/roemahumara /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

sudo nginx -t && sudo systemctl reload nginx
```

Cek lewat `http://192.168.88.33` dari komputer di jaringan yang sama.

---

## 8. SSL dengan Let's Encrypt — **nanti, saat domain siap**

**Lewati bagian ini sekarang.** Certbot memverifikasi kepemilikan domain lewat
internet, dan `192.168.88.33` tidak terjangkau dari sana. Selama masih jaringan
lokal, aksesnya `http://192.168.88.33`.

Kerjakan langkah di bawah **hanya** setelah domain diarahkan ke IP publik VPS:

```bash
# 1. Pastikan DNS sudah propagasi — Certbot gagal kalau belum.
#    Hasilnya harus IP PUBLIK VPS, bukan 192.168.88.33.
dig +short reservation.roemahumara.com

# 2. server_name TIDAK perlu diubah — sudah memuat domain ini sejak awal.
#    Cukup pastikan konfigurasinya masih sah:
sudo nginx -t && sudo systemctl reload nginx

# 3. Terbitkan sertifikat
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d reservation.roemahumara.com
```

> Tanpa `-d www.` — ini subdomain, dan `www.reservation.roemahumara.com` tidak
> lazim dipakai. Menambahkannya membuat Certbot gagal kalau DNS-nya tidak ada.

### Sebelum menghubungkan ke internet

Selama di jaringan lokal, beberapa langkah pengerasan boleh ditunda. Begitu VPS
punya IP publik, semuanya jadi wajib:

- [ ] **Matikan login password SSH** (bagian 1d). Pastikan dulu SSH key benar-benar
      bekerja, lalu jalankan. Port 22 yang terbuka ke internet dipindai bot dalam
      hitungan menit.
- [ ] **Batasi akses SSH** kalau memungkinkan — hanya dari IP kantor, lewat
      `sudo ufw allow from IP_KANTOR to any port 22` lalu hapus aturan
      `OpenSSH` yang terbuka untuk semua.
- [ ] **Ganti sandi awal semua akun.** Sebelas akun masih memakai sandi yang sama
      dari `INITIAL_USER_PASSWORD`.
- [ ] **Timbang ulang data tamu di halaman publik** — lihat catatan di bawah.

Setelah HTTPS aktif, **tiga hal wajib ikut diubah**:

```bash
cd /var/www/roemahumara
sudo -u marcom nano .env
```

```dotenv
APP_URL=https://reservation.roemahumara.com
SESSION_SECURE_COOKIE=true
```

lalu perbarui secret `APP_URL` di GitHub (dipakai smoke test), dan jalankan
`php8.3 artisan config:cache`.

> **Yang perlu disadari sebelum menekan tombol itu.** Halaman publik `/`
> menampilkan nama tamu, perusahaan, PIC, dan remark reservasi tanpa perlu login
> — keputusan yang diambil sadar pada 2026-08-22 (aturan #10 CLAUDE.md). Selama
> di jaringan lokal, itu hanya terbaca orang kantor. Begitu domain publik aktif,
> semuanya terbaca siapa saja di internet dan bisa terindeks mesin pencari.
> Pertimbangkan ulang di titik itu: menariknya kembali cukup dengan menghapus
> kolomnya dari `select()` di `PublicCalendarController`.

---

## 9. Queue worker

```bash
cd /var/www/roemahumara
sudo cp deploy/systemd/roemahumara-queue.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now roemahumara-queue
sudo systemctl status roemahumara-queue
```

---

## 10. Scheduler (cron)

Laravel butuh satu entri cron per menit.

```bash
sudo crontab -u marcom -e
```

```cron
* * * * * cd /var/www/roemahumara && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

---

## 11. Izin sudo terbatas untuk deploy

Langkah deploy perlu `systemctl restart` dan `reload` tanpa prompt password —
baik saat dijalankan runner maupun dengan tangan. Beri izin **hanya** untuk dua
perintah itu; jangan NOPASSWD untuk semua.

```bash
sudo visudo -f /etc/sudoers.d/marcom-deploy
```

```
marcom ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm, /usr/bin/systemctl restart roemahumara-queue
```

> Path harus **persis** sama dengan hasil `which systemctl`. Di Ubuntu 22/24
> lokasinya `/usr/bin/systemctl` — `/bin` hanya symlink, dan sudoers mencocokkan
> string secara literal, jadi menulis `/bin/systemctl` membuat aturan ini tidak
> pernah cocok. Cek dulu:
> ```bash
> which systemctl
> ```

Uji tanpa password:

```bash
sudo -u marcom sudo -n systemctl reload php8.3-fpm && echo "izin sudo siap"
```

---

## 12. Deploy otomatis: self-hosted runner

Setiap push ke `main` memicu `.github/workflows/deploy.yml`. Alurnya dua tahap:

| Tahap | Berjalan di | Melakukan |
|---|---|---|
| `build` | runner GitHub | `npm ci`, `npm run build`, unggah `public/build` sebagai artifact |
| `deploy` | runner di VPS | checkout, ambil artifact, rsync ke `/var/www/roemahumara`, jalankan `deploy.sh`, smoke test |

Aset di-build di runner GitHub, bukan di VPS. `npm run build` rutin memakai
lebih dari 1 GB RAM; di VPS kecil ia kena OOM-kill di tengah jalan dan
meninggalkan `public/build` rusak — situs hidup tapi tanpa gaya sama sekali.
Itu juga membuat VPS tidak perlu memasang Node.

### 12a. Pasang runner di VPS

Runner **menghubungi GitHub keluar** untuk mengambil pekerjaan. Tidak ada port
yang perlu dibuka ke internet — inilah yang membuat pola ini cocok untuk VPS
beralamat privat.

Ambil token pendaftaran di **GitHub → repo → Settings → Actions → Runners →
New self-hosted runner** (token berlaku sebentar, ambil tepat sebelum dipakai):

```bash
sudo -u marcom -i
mkdir -p ~/actions-runner && cd ~/actions-runner

curl -o actions-runner-linux-x64.tar.gz -L \
  https://github.com/actions/runner/releases/latest/download/actions-runner-linux-x64-2.328.0.tar.gz
tar xzf actions-runner-linux-x64.tar.gz

./config.sh \
  --url https://github.com/ArnoldSupriyadi/reservation-system-roemahumara \
  --token TOKEN_DARI_GITHUB \
  --name roemahumara-vps \
  --labels self-hosted,roemahumara \
  --unattended
```

> **Label `roemahumara` wajib.** Workflow menargetkan
> `runs-on: [self-hosted, roemahumara]`. Tanpa label itu pekerjaan menggantung
> selamanya menunggu runner yang tidak pernah ada, tanpa pesan kesalahan.

Pasang sebagai service supaya hidup lagi setelah VPS di-reboot:

```bash
exit   # kembali ke user biasa
cd /home/marcom/actions-runner
sudo ./svc.sh install marcom
sudo ./svc.sh start
sudo ./svc.sh status
```

### 12b. Izin runner atas direktori aplikasi

Runner berjalan sebagai `marcom` dan melakukan rsync ke `/var/www/roemahumara`.

```bash
sudo chown -R marcom:www-data /var/www/roemahumara
sudo chmod -R 2775 /var/www/roemahumara
```

### 12c. Secret yang dibutuhkan

**GitHub → repo → Settings → Secrets and variables → Actions:**

| Secret | Nilai | Keterangan |
|---|---|---|
| `APP_URL` | `http://192.168.88.33` | Dipakai smoke test di akhir deploy. Ganti ke `https://reservation.roemahumara.com` setelah HTTPS aktif |

Hanya satu. Kredensial SSH tidak diperlukan sama sekali — runner sudah berada di
dalam server.

### 12d. Uji sebelum mengandalkannya

Jalankan manual dulu lewat **Actions → Deploy ke VPS → Run workflow**, jangan
langsung push ke `main`. Kalau tahap `deploy` menggantung di status "Queued",
runnernya belum hidup atau labelnya tidak cocok:

```bash
sudo /home/marcom/actions-runner/svc.sh status
```

---

## 13. Prosedur deploy manual (cadangan)

Dipakai kalau runner sedang mati atau Anda ingin merilis tanpa menunggu GitHub.
Hasil akhirnya sama dengan yang dilakukan workflow.

**Urutannya tidak boleh diacak.** Alasan tiap langkah ada di catatan setelahnya.

### 13a. Build aset di komputer lokal, bukan di server

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
test -f public/build/manifest.json || echo "BUILD GAGAL — jangan lanjut"
```

> `npm run build` rutin memakai >1 GB RAM. Di VPS kecil ia kena OOM-kill di tengah
> jalan dan meninggalkan `public/build` rusak — situs hidup tapi tanpa CSS. Build
> di mesin lokal membuat VPS tidak perlu Node sama sekali.

### 13b. Kirim ke server

```bash
rsync -az --delete \
  --exclude='.git' --exclude='.github' --exclude='.env' \
  --exclude='node_modules' --exclude='storage' --exclude='public/storage' \
  --exclude='bootstrap/cache/*.php' --exclude='tests' --exclude='phpunit.xml' \
  -e "ssh -p 22" ./ marcom@SERVER_IP:/var/www/roemahumara/
```

> Semua `--exclude` di atas otomatis terlindungi dari `--delete`, karena
> `--delete-excluded` TIDAK dipakai. Itulah yang menjaga `.env`, `storage/`, dan
> `public/storage` di server tidak ikut terhapus. Jangan menambahkan
> `--delete-excluded`.

### 13c. Aktivasi di server

```bash
ssh marcom@SERVER_IP
cd /var/www/roemahumara

php8.3 artisan down --render="errors::503" --retry=15

php8.3 artisan migrate --force --no-interaction

[ -L public/storage ] || php8.3 artisan storage:link

php8.3 artisan optimize:clear
php8.3 artisan config:cache
php8.3 artisan route:cache
php8.3 artisan view:cache
php8.3 artisan event:cache

chmod -R ug+rw storage bootstrap/cache

php8.3 artisan queue:restart
sudo systemctl restart roemahumara-queue

sudo systemctl reload php8.3-fpm

php8.3 artisan up
```

> **`--force` wajib.** Tanpa itu Laravel menolak `migrate` non-interaktif dan
> langkahnya diam-diam tidak jalan.
>
> **`optimize:clear` harus mendahului `config:cache`.** Kalau dibalik, config lama
> tetap nyangkut di cache dan perubahan `.env` tidak pernah terbaca.
>
> **`reload php8.3-fpm` tidak boleh dilewat**, lihat catatan OPcache di bagian 2.
>
> **Kalau ada langkah yang gagal di tengah, situs sedang dalam maintenance mode.**
> Skrip lama punya `trap` yang otomatis menjalankan `artisan up`; sekarang tidak
> ada. Jalankan `php8.3 artisan up` sendiri setelah masalahnya diperiksa, jangan
> tinggalkan situs mati.

### 13d. Smoke test

```bash
curl -s -o /dev/null -w '%{http_code}\n' --max-time 20 http://192.168.88.33
```

Harus `200`.

---

## Verifikasi akhir

```bash
# Nginx & PHP hidup
sudo systemctl status nginx php8.3-fpm roemahumara-queue --no-pager

# Aplikasi merespons
curl -I http://192.168.88.33

# .env TIDAK bisa diakses publik — harus 403 atau 404
curl -I http://192.168.88.33/.env

# Debug mode mati — halaman error tidak boleh menampilkan stack trace
curl -s http://192.168.88.33/halaman-tidak-ada | grep -ci "stack trace"   # harus 0

# Cek log kalau ada error
tail -50 /var/www/roemahumara/storage/logs/laravel-$(date +%F).log
sudo tail -50 /var/log/nginx/roemahumara-error.log
```

---

## Rollback

Deploy ini **bukan** zero-downtime (tidak ada folder `releases/`), jadi rollback = deploy ulang commit lama:

```bash
# Opsi A - revert commit lalu push; workflow menjalankan deploy sendiri.

# Opsi B - langsung di server (darurat, lebih cepat):
cd /var/www/roemahumara
sudo -u marcom php8.3 artisan down
sudo -u marcom git checkout <COMMIT_LAMA>
sudo -u marcom composer install --no-dev --optimize-autoloader
sudo -u marcom php8.3 artisan optimize:clear && sudo -u marcom php8.3 artisan config:cache
sudo systemctl reload php8.3-fpm
sudo -u marcom php8.3 artisan up
```

> **Peringatan:** `git checkout` mundur **tidak** membatalkan migration yang sudah jalan.
> Kalau deploy yang gagal mengandung migration destruktif (drop/rename kolom), rollback kode saja tidak cukup.
> Ambil backup DB sebelum deploy yang mengandung migration berisiko:
> ```bash
> mysqldump -u roemahumara -p roemahumara > ~/backup-$(date +%F-%H%M).sql
> ```

---

## Backup harian (disarankan sebelum go-live)

```bash
sudo mkdir -p /var/backups/roemahumara
sudo crontab -e
```

```cron
0 2 * * * mysqldump -u roemahumara -p'CHANGE_ME_DB_PASSWORD' roemahumara | gzip > /var/backups/roemahumara/db-$(date +\%F).sql.gz
0 3 * * * find /var/backups/roemahumara -name '*.sql.gz' -mtime +14 -delete
```

> Password di crontab tersimpan plaintext. Untuk produksi sungguhan, pindahkan ke
> `/root/.my.cnf` (chmod 600) dan panggil `mysqldump` tanpa flag `-p`.
> Backup di disk yang sama dengan database **bukan** backup — sinkronkan ke storage eksternal.
