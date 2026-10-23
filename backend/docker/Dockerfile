# ===========================================================================
# Tahap 1 -- build frontend
# ===========================================================================
# Aplikasi ini TIDAK bisa jalan tanpa hasil `vite build`. Yang di-build bukan
# Blade: panel pusat dan aplikasi lokasi adalah SPA Vue yang punya router
# sendiri (`/login`, `/app/...`, `/central/...`).
#
# Tanpa tahap ini, `backend/public/` hanya berisi `index.php` -- dan subdomain
# lokasi yang diarahkan ke `/login` (lihat `routes/web.php`) berakhir di 404:
# tidak ada route Laravel untuk path itu, dan tidak ada index.html untuk
# dilayani.
#
# Berdiri sebagai tahap TERPISAH, bukan tahap pertama di file yang sama,
# karena `ARG` tidak boleh di-CV-rupada di belakang `FROM` -- dan `user`/
# `uid` memang harus dibaca lagi di tahap runtime.
FROM node:22-alpine AS frontend-build

# Folder frontend, RELATIF terhadap konteks build.
#
# Nilai lama `/var/www/frontend` salah: sumber `COPY` selalu relatif
# terhadap konteks, jadi leading `/` dibuang dan yang dicari adalah
# `<konteks>/var/www/frontend/package.json` -- folder yang tidak ada.
# Build gagal dengan "package.json: not found".
#
# Konteks build untuk service `app` adalah repo root (`./`, lihat
# `docker-compose.yml`), jadi folder frontend ada tepat di `./frontend`.
ARG frontend_dir=frontend

WORKDIR /build

# Manifest dulu, lalu dependensi. Dipisah supaya layer dependensi hanya
# dibangun ulang ketika package.json/pnpm-lock.yaml berubah -- bukan setiap
# kali satu berkas Vue disentuh.
COPY ${frontend_dir}/package.json ./
COPY ${frontend_dir}/pnpm-lock.yaml* ./

# `--frozen-lockfile` menolak build kalau lockfile tidak sinkron dengan
# manifest. Tanpa itu, build di server bisa diam-diam memakai versi
# dependensi yang berbeda dari yang diuji lokal.
#
# `--ignore-scripts`: tidak ada postinstall yang dibutuhkan, dan skrip
# paket pihak ketiga adalah tempat paling umum masuknya kode yang tidak
# pernah diulas.
RUN corepack enable && \
    pnpm install --frozen-lockfile --ignore-scripts

COPY ${frontend_dir}/ ./

# Konfigurasi build-time. Nilai di sini hanya yang ter-bake ke dalam bundle.
#
# Kalau dikosongkan (nilai bawaannya), frontend mengambil origin API dari
# `window.location` saat runtime -- lihat `frontend/src/utils/centralAxios.js`.
# Itu yang membuatnya bisa dipindahkan ke belakang reverse proxy tanpa build
# ulang, jadi hampir selalu lebih baik membiarkan kosong daripada mengunci
# satu URL ke dalam bundle.
ARG VITE_API_BASE_URL=""
ARG VITE_BACKEND_URL=""
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL} \
    VITE_BACKEND_URL=${VITE_BACKEND_URL}

RUN pnpm build

# ===========================================================================
# Tahap 2 -- runtime PHP
# ===========================================================================
FROM php:8.4-fpm

# Arguments defined in docker-compose.yml
ARG user
ARG uid

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && pecl install redis \
    && docker-php-enable redis

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u ${uid} -d /home/${user} ${user}
RUN mkdir -p /home/${user}/.composer && \
    chown -R ${user}:${user} /home/${user}

WORKDIR /var/www

# Hasil build frontend TIDAK langsung disalin ke `public/`.
#
# `public/` di-mount dari host (lihat docker-compose.yml), jadi apa pun yang
# ditulis ke sana saat build image akan hilang begitu container berjalan.
# Hasil build ditaruh di `/app-build/dist`, lalu disalin ke `public/` oleh
# `docker/entrypoint.sh` saat start -- supaya file-nya benar-benar muncul di
# host, yang juga dibaca oleh container nginx.
COPY --from=frontend-build --chown=${user}:${user} /build/dist/ /app-build/dist/

# Path di bawah RELATIF terhadap konteks build (`./` di docker-compose.yml),
# bukan relatif terhadap letak file ini. Dua-duanya bisa membingungkan --
# `COPY docker/entrypoint.sh` terlihat benar karena file-nya memang ada di
# folder yang sama dengan Dockerfile, tapi konteksnya repo root, jadi yang
# dicari adalah `./docker/entrypoint.sh` yang tidak ada, dan build gagal dengan
# `"/docker/entrypoint.sh": not found`.
COPY --chown=${user}:${user} backend/docker/entrypoint.sh /var/www/docker/entrypoint.sh
RUN chmod +x /var/www/docker/entrypoint.sh

USER ${user}

ENTRYPOINT ["/var/www/docker/entrypoint.sh"]
CMD ["php-fpm"]