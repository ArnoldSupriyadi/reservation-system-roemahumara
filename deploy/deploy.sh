#!/usr/bin/env bash
# =============================================================================
# deploy.sh — dijalankan DI SERVER.
#
# Dipanggil GitHub Actions lewat self-hosted runner yang berada di dalam
# jaringan yang sama, atau langsung dengan tangan:
#
#     cd /var/www/roemahumara && ./deploy/deploy.sh
#
# Asumsi: kode dan public/build sudah berada di $APP_DIR. Skrip ini hanya
# menangani langkah yang HARUS jalan di server — dependensi, migrasi, cache,
# permission, restart.
# =============================================================================

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/roemahumara}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.3}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"
FPM_SERVICE="${FPM_SERVICE:-php8.3-fpm}"
QUEUE_SERVICE="${QUEUE_SERVICE:-roemahumara-queue}"

log()  { printf '\033[0;36m[deploy]\033[0m %s\n' "$*"; }
fail() { printf '\033[0;31m[deploy][ERROR]\033[0m %s\n' "$*" >&2; }

# Kalau ada langkah yang gagal, keluarkan dari maintenance mode supaya situs
# tidak tertinggal mati. Migrasi yang gagal tetap harus diperiksa manual.
on_error() {
    fail "Deploy gagal di baris $1. Menonaktifkan maintenance mode."
    "$PHP_BIN" artisan up || true
    exit 1
}
trap 'on_error $LINENO' ERR

cd "$APP_DIR"

if [[ ! -f .env ]]; then
    fail ".env tidak ditemukan di $APP_DIR. Salin dari .env.production.example dulu."
    exit 1
fi

# --- 1. Maintenance mode -----------------------------------------------------
# --render memakai view statis, jadi tetap tampil walau cache dibersihkan.
log "Masuk maintenance mode"
"$PHP_BIN" artisan down --render="errors::503" --retry=15 || true

# --- 2. Dependensi PHP -------------------------------------------------------
# Dijalankan setiap deploy, bukan sekali di awal. composer.lock ikut berubah
# ketika ada pustaka baru — dompdf ditambahkan 2026-08-23 — dan melewatinya
# menghasilkan "Class not found" yang tidak menunjuk ke penyebabnya.
log "Memasang dependensi PHP"
"$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

# --- 3. Migrasi database -----------------------------------------------------
# --force wajib di produksi (Laravel menolak migrate non-interaktif tanpa ini).
log "Menjalankan migration"
"$PHP_BIN" artisan migrate --force --no-interaction

# --- 4. Storage symlink ------------------------------------------------------
if [[ ! -L public/storage ]]; then
    log "Membuat symlink storage"
    "$PHP_BIN" artisan storage:link
fi

# --- 5. Rebuild cache --------------------------------------------------------
# Urutan penting: clear dulu, baru cache ulang, supaya config lama tidak nyangkut.
log "Membangun ulang cache"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

# --- 6. Permission -----------------------------------------------------------
# storage/fonts ikut disebut: dompdf menulis font olahannya ke sana saat membuat
# PDF reservasi. Kalau tidak bisa ditulis, pembuatan PDF gagal dengan TypeError
# dari fwrite() yang tidak menyebut direktori sama sekali.
#
# Yang di-chmod HANYA berkas milik user deploy — itu inti baris `-user` di
# bawah, jangan dihapus. Sampai 2026-09-07 baris ini `chmod -R ug+rw storage
# bootstrap/cache`, dan deploy berjalan mulus berminggu-minggu sampai ada orang
# pertama yang mencetak PDF di produksi. Saat itu dompdf membuat
# storage/fonts/nunito_*.ttf|ufm|json **sebagai www-data**, dan chmod atas
# berkas milik user lain selalu ditolak kernel: "Operation not permitted".
# Itu bukan soal sudo — hanya pemilik berkas (atau root) yang boleh mengubah
# mode sebuah berkas, seberapa pun besar hak yang dipunya user lain.
#
# Melewatinya aman justru karena yang membuat berkas itu adalah proses yang
# perlu menulisinya: www-data sudah jadi pemiliknya, jadi ia sudah bisa
# menulis. Yang perlu dipastikan skrip ini hanyalah berkas yang IA sendiri
# buat — hasil rsync dan cache Laravel — dan itu semua miliknya.
#
# Akibat kegagalannya dulu tidak berhenti di pesan merah: `set -e` menghentikan
# skrip di sini, sebelum langkah 7 dan 8, sehingga kode baru sudah terpasang
# tapi PHP-FPM belum di-reload (opcache masih menyajikan kode lama) dan queue
# worker belum direstart. Situsnya sendiri tetap hidup karena trap on_error
# memanggil `artisan up`.
log "Menyesuaikan permission storage & cache"
mkdir -p storage/fonts
find storage bootstrap/cache -user "$(id -un)" -exec chmod ug+rw {} +

# --- 7. Restart service ------------------------------------------------------
# queue:restart memberi sinyal graceful ke worker; systemd restart sebagai jaring
# pengaman kalau worker tidak merespons.
log "Merestart queue worker"
"$PHP_BIN" artisan queue:restart
sudo systemctl restart "$QUEUE_SERVICE" || fail "Gagal restart $QUEUE_SERVICE (lanjut)"

# opcache.validate_timestamps = 0 berarti PHP tidak mengecek perubahan file.
# Tanpa reload, kode lama terus tersaji padahal berkasnya sudah baru.
log "Reload PHP-FPM"
sudo systemctl reload "$FPM_SERVICE"

# --- 8. Keluar maintenance ---------------------------------------------------
log "Keluar maintenance mode"
"$PHP_BIN" artisan up

log "Deploy selesai."
