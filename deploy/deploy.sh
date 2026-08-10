#!/usr/bin/env bash
# =============================================================================
# deploy.sh - dijalankan DI SERVER, dipanggil oleh GitHub Actions via SSH.
#
# Asumsi: kode & vendor & public/build sudah di-rsync ke $APP_DIR oleh CI.
# Script ini hanya menangani langkah yang HARUS jalan di server:
# migrate, cache, restart worker.
#
# Manual run:  cd /var/www/roemahumara && ./deploy/deploy.sh
# =============================================================================

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/roemahumara}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.3}"
FPM_SERVICE="${FPM_SERVICE:-php8.3-fpm}"
QUEUE_SERVICE="${QUEUE_SERVICE:-roemahumara-queue}"

log()  { printf '\033[0;36m[deploy]\033[0m %s\n' "$*"; }
fail() { printf '\033[0;31m[deploy][ERROR]\033[0m %s\n' "$*" >&2; }

# Kalau ada langkah yang gagal, keluarkan dari maintenance mode supaya situs
# tidak tertinggal down. Migration yang gagal tetap harus diperiksa manual.
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

# --- 2. Migrasi database -----------------------------------------------------
# --force wajib di produksi (Laravel menolak migrate non-interaktif tanpa ini).
log "Menjalankan migration"
"$PHP_BIN" artisan migrate --force --no-interaction

# --- 3. Storage symlink ------------------------------------------------------
if [[ ! -L public/storage ]]; then
    log "Membuat symlink storage"
    "$PHP_BIN" artisan storage:link
fi

# --- 4. Rebuild cache --------------------------------------------------------
# Urutan penting: clear dulu, baru cache ulang, supaya config lama tidak nyangkut.
log "Membangun ulang cache"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

# --- 5. Permission -----------------------------------------------------------
log "Menyesuaikan permission storage & cache"
chmod -R ug+rw storage bootstrap/cache

# --- 6. Restart service ------------------------------------------------------
# queue:restart memberi sinyal graceful ke worker; systemd restart sebagai jaring
# pengaman kalau worker tidak merespons.
log "Merestart queue worker"
"$PHP_BIN" artisan queue:restart
sudo systemctl restart "$QUEUE_SERVICE" || fail "Gagal restart $QUEUE_SERVICE (lanjut)"

log "Reload PHP-FPM"
sudo systemctl reload "$FPM_SERVICE"

# --- 7. Keluar maintenance ---------------------------------------------------
log "Keluar maintenance mode"
"$PHP_BIN" artisan up

log "Deploy selesai."
