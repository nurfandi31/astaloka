<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `cache` dan `cache_locks` di database PUSAT.
 *
 * Kenapa perlu
 * ------------
 *
 * `config/cache.php` tidak lagi membiarkan `DB_CACHE_CONNECTION` kosong.
 * Kalau kosong, `DatabaseStore` dibangun tanpa nama koneksi dan memakai
 * koneksi DEFAULT saat pertama kali di-resolve. Karena
 * `TenantManager::activate()` hanya memanggil `DB::purge('tenant')` -- store
 * cache tidak pernah dibangun ulang -- store itu terkunci ke database lokasi
 * PERTAMA yang menyentuh cache di proses itu.
 *
 * Gejalanya nyata dan sunyi: satu proses FPM yang melayani lokasi A lalu
 * lokasi B memakai tabel `cache` milik A saja. Lokasi B tidak melihat cache
 * miliknya sendiri sehingga statistik dihitung ulang terus-menerus, dan
 * kunci cache yang tidak di-scope per lokasi bisa dibaca lintas lokasi.
 *
 * Memindahkan keyspace ke database pusat membuat masalah itu hilang total:
 * satu keyspace bersama untuk semua lokasi, dan pemisahannya jadi tanggung
 * jawab `TenantCache` lewat awalan kunci `t{tenantId}:v{n}:`, bukan
 * bergantung pada urutan request yang tidak bisa diprediksi.
 *
 * Tabel lama di database LOKASI tidak dihapus di sini. Tabel itu masih
 * dipakai kalau ada instalasi yang sengaja men-set `DB_CACHE_CONNECTION` ke
 * koneksi lokasi, dan menghapusnya di migrasi yang sama akan membuat
 * `down()` tidak bisa dibatalkan. Tabel `cache` lama yang sekarang tidak
 * terpakai juga tidak otomatis dikosongkan: membuang seluruh cache aplikasi
 * tidak berbahaya, tapi tidak perlu.
 *
 * Dijalankan dengan:
 *     php artisan migrate --path=database/migrations_central
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        $schema = Schema::connection($central);

        // `hasTable` di depan `create` supaya migrasi ini idempotent,
        // sama seperti seluruh migrasi `central/` lainnya: tabelnya bisa
        // sudah ada dari instalasi yang membuatnya manual dengan
        // `--path=database/migrations_central` sebelum file ini dibuat.
        if (! $schema->hasTable('cache')) {
            $schema->create('cache', function (Blueprint $table) {
                $table->string('key')->primary();

                /*
                 * `mediumText`, bukan `text`.
                 *
                 * Isi cache di aplikasi ini adalah hasil agregasi dashboard
                 * dan ringkasan keuangan yang bisa melewati batas 64 KB
                 * `TEXT`. Kegagalan di sini muncul sebagai 500 sporadik,
                 * hanya pada lokasi dengan data paling banyak, jadi sulit
                 * dikaitkan dengan cache.
                 *
                 * `expiration` NULL berarti "forever", sesuai hasil
                 * `Cache::forever()` dan TTL yang dihitung helper Laravel.
                 */
                $table->mediumText('value');
                $table->unsignedInteger('expiration')->nullable();
            });
        }

        if (! $schema->hasTable('cache_locks')) {
            $schema->create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();

                /*
                 * `owner` menyimpan session cache yang memegang lock, supaya
                 * `DatabaseLock` bisa membedakan lock miliknya sendiri dari
                 * lock proses lain yang sudah mati -- bukan dari tabrakan
                 * nama kunci saja.
                 */
                $table->string('owner');

                /*
                 * NOT NULL dan tanpa default, sama seperti tabel bawaan
                 * Laravel. `expiration` yang selalu terisi membuat
                 * `DatabaseLock::acquire()` bisa melakukan satu UPDATE
                 * bersyarat, bukan SELECT lalu UPDATE, sehingga dua proses
                 * tidak bisa sama-sama thinks mereka memegang lock.
                 */
                $table->unsignedInteger('expiration');
            });
        }
    }

    public function down(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        $schema = Schema::connection($central);

        /*
         * `dropIfExists` supaya `down()` aman pada instalasi yang tabel
         * Central-nya belum pernah dibuat. Isi cache ikut hilang bersama
         * tabelnya; tidak ada langkah pembersihan terpisah yang perlu.
         */
        $schema->dropIfExists('cache_locks');
        $schema->dropIfExists('cache');
    }
};
