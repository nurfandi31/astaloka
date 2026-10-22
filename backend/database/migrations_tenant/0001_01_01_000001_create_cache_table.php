<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bentuk kolom di sini harus sama dengan bentuk resmi Laravel:
     *
     *     cache.expiration       unsignedInteger NULL
     *     cache_locks.expiration unsignedInteger NOT NULL
     *
     * `cache.expiration` WAJIB bisa menerima NULL, karena `Cache::forever()`
     * menulis NULL sebagai tanda "tidak pernah kedaluwarsa". Versi
     * sebelumnya memakai `bigInteger` dengan NOT NULL, jadi setiap cache
     * permanen gagal dengan "Column 'expiration' cannot be null" -- dan
     * gejalanya baru muncul saat aplikasi pertama kali memakai cache
     * permanen, bukan saat migrasi berjalan.
     *
     * Lebar kolom tidak penting di sini: `expiration` adalah timestamp
     * Unix dalam detik, dan `unsignedInteger` sudah cukup sampai tahun
     * 2106. Yang penting adalah NULLABILITY-nya.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->unsignedInteger('expiration')->nullable();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->unsignedInteger('expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
