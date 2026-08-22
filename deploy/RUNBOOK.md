# Runbook Provisioning VPS — Reservation System Roemah Umara

Target: **Ubuntu 22.04 / 24.04 LTS**, stack Nginx + PHP 8.3-FPM + MySQL 8, deploy manual lewat SSH (bagian 12).

Semua perintah dijalankan lewat SSH ke VPS. Ganti setiap `CHANGE_ME`.

Konvensi yang dipakai di semua file config:

| Item | Nilai |
|---|---|
| Direktori aplikasi | `/var/www/roemahumara` |
| User deploy | `deployer` |
| User web | `www-data` |
| PHP | 8.3 (`/usr/bin/php8.3`) |
| Database | `roemahumara` |
| Service queue | `roemahumara-queue` |

---

## 0. Prasyarat

- Akses root/sudo ke VPS
- Domain sudah diarahkan (A record) ke IP VPS — **cek dulu**, Certbot akan gagal kalau DNS belum propagasi:
  ```bash
  dig +short CHANGE_ME_DOMAIN
  ```
- RAM minimal 2 GB. Di bawah itu MySQL 8 + PHP-FPM akan sesak. Kalau hanya 1 GB, tambahkan swap (langkah 1c).

---

## 1. Hardening dasar

### 1a. Update & user non-root

```bash
sudo apt update && sudo apt upgrade -y

sudo adduser --gecos "" deployer
sudo usermod -aG sudo deployer
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

Lakukan **setelah** memastikan login dengan SSH key berhasil, kalau tidak kamu terkunci di luar.

```bash
sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/'                /etc/ssh/sshd_config
sudo systemctl restart ssh
```

---

## 2. Install PHP 8.3

Ubuntu 22.04 default-nya PHP 8.1 — terlalu tua untuk Laravel 12 (`php: ^8.2`). Pakai PPA ondrej.

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

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
> saya tidak berpengaruh". Langkahnya ada di bagian 12.

```bash
sudo systemctl restart php8.3-fpm
```

---

## 3. Install & amankan MySQL 8

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation
```

Buat database dan user aplikasi:

```bash
sudo mysql
```

```sql
CREATE DATABASE roemahumara
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'roemahumara'@'localhost'
  IDENTIFIED BY 'CHANGE_ME_DB_PASSWORD';

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
sudo chown -R deployer:www-data /var/www/roemahumara
sudo chmod -R 2775 /var/www/roemahumara   # setgid: file baru ikut grup www-data
```

Clone sekali untuk bootstrap awal (deploy berikutnya lewat rsync dari CI):

```bash
cd /var/www
sudo -u deployer git clone https://github.com/ArnoldSupriyadi/reservation-system-roemahumara.git roemahumara
cd roemahumara
sudo -u deployer composer install --no-dev --optimize-autoloader
```

### Konfigurasi `.env`

```bash
sudo -u deployer cp .env.production.example .env
sudo -u deployer php8.3 artisan key:generate
sudo -u deployer nano .env     # isi APP_URL, DB_PASSWORD, MAIL_*
sudo chmod 640 .env
sudo chown deployer:www-data .env
```

### Permission storage

```bash
sudo chown -R deployer:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Migrasi awal

```bash
cd /var/www/roemahumara
sudo -u deployer php8.3 artisan migrate --force
sudo -u deployer php8.3 artisan storage:link
```

### Data awal & akun pertama

**Jangan lewati bagian ini.** `migrate` hanya membuat tabel kosong. Tanpa langkah
di bawah, `/cms/login` terbuka tapi tidak ada satu pun akun yang bisa masuk, dan
form reservasi tidak punya pilihan area maupun jenis acara. Anda terkunci di luar
sistem sendiri.

```bash
sudo -u deployer php8.3 artisan db:seed --class=RolePermissionSeeder --force
sudo -u deployer php8.3 artisan db:seed --class=MasterSeeder --force
```

Yang pertama membuat role `admin` dan `staff` beserta seluruh permission-nya. Yang
kedua mengisi master area, jenis acara, dan menu style.

> `db:seed` polos juga membuat akun admin `roemahumara@gmail.com`. Sejak
> 2026-08-22 itu akun sungguhan, bukan lagi `test@example.com`, jadi
> menjalankannya di produksi memang diperlukan. Seeder ini aman diulang.
>
> **Setel `INITIAL_USER_PASSWORD` di `.env` server SEBELUM menjalankannya.**
> Sandi awal semua akun diambil dari sana. Kalau belum disetel, sandinya jatuh ke
> `password` — dan karena seeder memakai `firstOrCreate`, menjalankannya lagi
> setelah `.env` dibetulkan TIDAK akan memperbaiki sandi yang terlanjur dibuat.

Untuk sepuluh akun staf Roemah Umara, ada seedernya sendiri:

```bash
sudo -u deployer php8.3 artisan db:seed --class=StaffSeeder --force
```

> **Sandi awalnya sama untuk semua**, diambil dari `INITIAL_USER_PASSWORD` di
> `.env`. Nilainya tidak ada di dalam kode — repositori ini publik. Minta setiap
> orang menggantinya setelah masuk pertama kali. Selama belum diganti, satu orang
> yang tahu sandinya bisa masuk sebagai siapa saja dan `activity_log` akan
> menunjuk orang yang keliru. Menjalankan seeder ini lagi tidak mengembalikan
> sandi yang sudah diganti — ia memakai `firstOrCreate`.

Akun staf tidak bisa menghapus reservasi. Untuk akun admin, buat sendiri —
perintahnya menanyakan kata sandi secara tersembunyi, jadi sandi tidak
tertinggal di riwayat shell:

```bash
sudo -u deployer php8.3 artisan make:filament-user
```

**Akun itu belum bisa apa-apa.** `make:filament-user` tidak memberi role, dan
seluruh policy di sistem ini memeriksa kemampuan lewat role — tanpa role, pengguna
bisa masuk tapi setiap tombol tertutup. Beri role admin, lalu bersihkan cache
permission (aturan #8 CLAUDE.md):

```bash
sudo -u deployer php8.3 artisan tinker --execute="App\Models\User::where('email','EMAIL_KAMU')->firstOrFail()->assignRole('admin');"
sudo -u deployer php8.3 artisan permission:cache-reset
```

Uji sebelum lanjut — harus mencetak `true`:

```bash
sudo -u deployer php8.3 artisan tinker --execute="echo var_export(App\Models\User::where('email','EMAIL_KAMU')->firstOrFail()->can('reservation.delete'), true);"
```

> Kolom `is_active` bernilai `true` secara bawaan, jadi tidak perlu disetel manual.
> Kalau suatu saat akun dinonaktifkan, ia ditolak middleware Filament dengan 403 —
> bukan pesan "sandi salah", jadi jangan tertukar.

---

## 7. Pasang Nginx server block

```bash
cd /var/www/roemahumara
sudo cp deploy/nginx/roemahumara.conf /etc/nginx/sites-available/roemahumara
sudo sed -i 's/CHANGE_ME_DOMAIN/your-domain.com/g' /etc/nginx/sites-available/roemahumara

sudo ln -sf /etc/nginx/sites-available/roemahumara /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

sudo nginx -t && sudo systemctl reload nginx
```

Cek via HTTP dulu (`http://your-domain.com`) sebelum lanjut ke SSL.

---

## 8. SSL dengan Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com -d www.your-domain.com

# Renewal otomatis sudah dipasang lewat systemd timer. Uji:
sudo certbot renew --dry-run
```

Setelah SSL aktif, pastikan `.env` sudah `APP_URL=https://...` dan `SESSION_SECURE_COOKIE=true`, lalu:

```bash
sudo -u deployer php8.3 artisan config:cache
```

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
sudo crontab -u deployer -e
```

```cron
* * * * * cd /var/www/roemahumara && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

---

## 11. Izin sudo terbatas untuk deploy

Langkah deploy di bagian 12 perlu `systemctl restart` dan `reload` tanpa prompt password. Beri izin **hanya** untuk dua perintah itu — jangan NOPASSWD untuk semua.

```bash
sudo visudo -f /etc/sudoers.d/deployer-deploy
```

```
deployer ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm, /usr/bin/systemctl restart roemahumara-queue
```

> Path harus **persis** sama dengan hasil `which systemctl`. Di Ubuntu 22/24 lokasinya
> `/usr/bin/systemctl` (`/bin` hanya symlink, dan sudoers mencocokkan string secara literal —
> menulis `/bin/systemctl` akan membuat aturan ini tidak pernah cocok). Cek dulu:
> ```bash
> which systemctl
> ```

```bash
sudo chmod 440 /etc/sudoers.d/deployer-deploy
```

---

## 12. Prosedur deploy manual

Deploy dikerjakan dengan tangan lewat SSH. Sebelumnya ada `deploy/deploy.sh` yang
dipanggil GitHub Actions; keduanya dihapus 2026-08-22 karena rilis akan dilakukan
bertahap dan diawasi. Urutan di bawah adalah isi skrip itu, dipindahkan ke sini
supaya tidak hilang.

**Urutannya tidak boleh diacak.** Alasan tiap langkah ada di catatan setelahnya.

### 12a. Build aset di komputer lokal, bukan di server

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
test -f public/build/manifest.json || echo "BUILD GAGAL — jangan lanjut"
```

> `npm run build` rutin memakai >1 GB RAM. Di VPS kecil ia kena OOM-kill di tengah
> jalan dan meninggalkan `public/build` rusak — situs hidup tapi tanpa CSS. Build
> di mesin lokal membuat VPS tidak perlu Node sama sekali.

### 12b. Kirim ke server

```bash
rsync -az --delete \
  --exclude='.git' --exclude='.github' --exclude='.env' \
  --exclude='node_modules' --exclude='storage' --exclude='public/storage' \
  --exclude='bootstrap/cache/*.php' --exclude='tests' --exclude='phpunit.xml' \
  -e "ssh -p 22" ./ deployer@SERVER_IP:/var/www/roemahumara/
```

> Semua `--exclude` di atas otomatis terlindungi dari `--delete`, karena
> `--delete-excluded` TIDAK dipakai. Itulah yang menjaga `.env`, `storage/`, dan
> `public/storage` di server tidak ikut terhapus. Jangan menambahkan
> `--delete-excluded`.

### 12c. Aktivasi di server

```bash
ssh deployer@SERVER_IP
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

### 12d. Smoke test

```bash
curl -s -o /dev/null -w '%{http_code}\n' --max-time 20 https://your-domain.com
```

Harus `200`.

---

## Verifikasi akhir

```bash
# Nginx & PHP hidup
sudo systemctl status nginx php8.3-fpm roemahumara-queue --no-pager

# Aplikasi merespons
curl -I https://your-domain.com

# .env TIDAK bisa diakses publik — harus 403 atau 404
curl -I https://your-domain.com/.env

# Debug mode mati — halaman error tidak boleh menampilkan stack trace
curl -s https://your-domain.com/halaman-tidak-ada | grep -ci "stack trace"   # harus 0

# Cek log kalau ada error
tail -50 /var/www/roemahumara/storage/logs/laravel-$(date +%F).log
sudo tail -50 /var/log/nginx/roemahumara-error.log
```

---

## Rollback

Deploy ini **bukan** zero-downtime (tidak ada folder `releases/`), jadi rollback = deploy ulang commit lama:

```bash
# Opsi A - revert commit di git lalu ulangi prosedur deploy bagian 12.

# Opsi B - langsung di server (darurat, lebih cepat):
cd /var/www/roemahumara
sudo -u deployer php8.3 artisan down
sudo -u deployer git checkout <COMMIT_LAMA>
sudo -u deployer composer install --no-dev --optimize-autoloader
sudo -u deployer php8.3 artisan optimize:clear && sudo -u deployer php8.3 artisan config:cache
sudo systemctl reload php8.3-fpm
sudo -u deployer php8.3 artisan up
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
