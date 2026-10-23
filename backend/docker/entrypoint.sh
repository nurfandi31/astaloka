#!/bin/sh
set -e

# ---------------------------------------------------------------------------
# Pastikan hasil build frontend terlihat di host
# ---------------------------------------------------------------------------
# Build frontend terjadi saat image dibuat (tahap `frontend-build` di Dockerfile)
# dan hasilnya ada di `/app-build/dist`. Hasil itu TIDAK disalin langsung ke
# `/var/www/public`, karena folder itu di-bind-mount dari host
# (`backend/public`) -- apa pun yang ditulis ke sana saat build image langsung
# hilang begitu container berjalan, dan nginx (yang membaca folder yang sama
# dari host) tidak pernah melihat index.html sama sekali.
#
# Karena itu salinan dilakukan di sini, saat start. `cmp` membuat langkah ini
# jadi murah kalau isinya sudah sama: restart tidak menyalin ulang puluhan
# megabyte setiap kali.
if [ -d /app-build/dist ]; then
    if ! cmp -s /app-build/dist/index.html /var/www/public/index.html 2>/dev/null; then
        echo "==> Menyinkronkan build frontend ke public/"

        # index.html ditulis DULU, baru aset lama dibuang. Urutan ini penting:
        # kalau dibalik dan salinan gagal di tengah, aplikasi akan punya
        # index.html yang menunjuk aset yang sudah tidak ada -- gejalanya
        # "halaman terbuka tapi putih kosong", bukan error yang bisa dibaca.
        cp /app-build/dist/index.html /var/www/public/index.html

        # `assets/` dibuang dulu -- TAPI hanya folder hasil build.
        #
        # `rm -rf /var/www/public/assets` yang dulu dipakai menghapus
        # SELURUH folder, termasuk `assets/branding/` -- yang isinya
        # (`logo.png`, `lunas.png`, `ttd.png`) di-commit di repo dan dibaca
        # `InvoiceController::stempel()` lewat `public_path()`.
        #
        # Gejalanya: cap LUNAS dan tanda tangan di surat tagihan hilang
        # diam-diam. Tidak ada error, tidak ada log -- template-nya cuma
        # ReceivedNull yang membuat blok kosong dicetak. Dan yang hilang
        # itu baru kembali kalau `git checkout` dilakukan manual.
        #
        # Folder branding di-pin aside dulu, lalu dikembalikan setelah
        # hasil build disalin.
        if [ -d /var/www/public/assets ]; then
            mv /var/www/public/assets /var/www/public/.assets-build-lama 2>/dev/null \
                || rm -rf /var/www/public/assets
        fi

        cp -r /app-build/dist/. /var/www/public/

        # Kembalikan branding dari folder yang dipin aside.
        #
        # SUMBERNYA folder lama (`.assets-build-lama/branding`), TUJUANNYA
        # folder hasil build yang baru (`assets/branding`). Arahnya penting:
        # hasil build frontend tidak pernah memuat `branding/`, jadi kalau
        # yang dicek adalah folder TUJUAN, syaratnya tidak pernah terpenuhi
        # dan branding hilang bersama folder lamanya.
        if [ -d /var/www/public/.assets-build-lama/branding ]; then
            mkdir -p /var/www/public/assets/branding
            cp -r /var/www/public/.assets-build-lama/branding/. \
                   /var/www/public/assets/branding/ 2>/dev/null || true
        fi

        # Buang sisa aset build yang lama. Branding sudah disalin ke
        # tempatnya sebelumnya, jadi tidak ikut terhapus.
        rm -rf /var/www/public/.assets-build-lama

        # `chown` SENGAJA tidak dipanggil. Proses ini berjalan sebagai user
        # non-root (`sam`), jadi `chown` akan selalu gagal dan hanya menambah
        # satu baris `|| true` yang menyembunyikan kegagalan. Tidak diperlukan
        # juga: nginx membaca file sebagai user-nya sendiri, dan `cp` sudah
        # menghasilkan file dengan izin baca untuk semua orang.
    fi
else
    echo "==> PERINGATAN: /app-build/dist tidak ada; SPA tidak akan dilayani." >&2
    echo "==> Halaman seperti /login dan /central/login akan 404." >&2
fi

# ---------------------------------------------------------------------------
# Laravel
# ---------------------------------------------------------------------------
# `artisan storage:link` aman dipanggil berulang. Tanpa symlink ini, logo
# hasil unggahan dan PDF yang diunduh akan 404 -- sementara gejalanya (tag
# gambar rusak) mengarah ke frontend, bukan ke langkah ini.
php artisan storage:link --force --no-interaction || true

# Cache dibersihkan, tapi `config:cache` SENGAJA tidak dijalankan. File .env
# di-bind-mount dan sering berubah saat pengembangan; meng-cache config
# membuat nilai lama tetap berlaku setelah .env diedit -- dan itu muncul
# sebagai "perubahannya tidak berpengaruh" tanpa error di mana pun.
php artisan config:clear --no-interaction || true
php artisan cache:clear --no-interaction || true
php artisan view:clear --no-interaction || true

exec "$@"