# Runbook Provisioning VPS — Reservation System Roemah Umara

Target: **Ubuntu 22.04 / 24.04 LTS**, stack Nginx + PHP 8.3-FPM + MySQL 8, deploy via GitHub Actions.

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
> Konsekuensinya: setiap deploy WAJIB reload PHP-FPM — dan itu sudah ada di `deploy.sh`.
> Kalau kamu pernah deploy manual tanpa reload, kode lama akan terus tersaji.

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

> Node **tidak perlu diinstall di server**. Build aset dilakukan di GitHub Actions.

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

## 11. Izin sudo terbatas untuk deploy script

`deploy.sh` perlu `systemctl restart` dan `reload` tanpa prompt password. Beri izin **hanya** untuk dua perintah itu — jangan NOPASSWD untuk semua.

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

## 12. Hubungkan GitHub Actions

### 12a. Buat SSH key khusus deploy (di komputer lokal, bukan di server)

```bash
ssh-keygen -t ed25519 -C "github-actions-roemahumara" -f ~/.ssh/roemahumara_deploy -N ""
```

Salin **public key** ke server:

```bash
ssh-copy-id -i ~/.ssh/roemahumara_deploy.pub deployer@SERVER_IP
```

### 12b. Daftarkan secrets

GitHub → repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Nilai | Contoh |
|---|---|---|
| `SSH_PRIVATE_KEY` | Isi **penuh** `~/.ssh/roemahumara_deploy` (termasuk baris BEGIN/END) | `-----BEGIN OPENSSH...` |
| `SSH_HOST` | IP atau hostname VPS | `103.xxx.xxx.xxx` |
| `SSH_USER` | User deploy | `deployer` |
| `SSH_PORT` | Port SSH | `22` |
| `DEPLOY_PATH` | Path aplikasi (tanpa trailing slash) | `/var/www/roemahumara` |
| `APP_URL` | URL untuk smoke test | `https://your-domain.com` |

### 12c. Uji

Jalankan manual dulu lewat tab **Actions → Deploy to Production → Run workflow**, jangan langsung push ke `main`.

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
# Opsi A - lewat GitHub: revert commit, push, workflow jalan otomatis.

# Opsi B - manual di server (darurat):
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
