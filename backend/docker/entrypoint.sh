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
        rm -rf /var/www/public/assets
        cp -r /app-build/dist/. /var/www/public/

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