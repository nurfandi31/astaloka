<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `users` di DATABASE PUSAT, khusus user superadmin ASTA BRATA.
 *
 * Sengaja memakai nama tabel yang sama dengan database lokasi (`users`) dan
 * struktur yang mirip. Alasannya bukan estetika, tapi soal teknis:
 *
 * Sanctum memverifikasi token lewat `Guard::hasValidProvider()`, yang
 * membandingkan `instanceof config('auth.providers.users.model')` -- yaitu
 * `App\Models\User`. Kalau user pusat memakai model terpisah, tokennya akan
 * dianggap tidak valid dan tidak ada yang bisa login ke panel pusat tanpa
 * menulis ulang provider dan guard.
 *
 * Yang membedakan keduanya adalah KONEKSI, bukan tabel. `User` memakai
 * `database.default`; middleware tenancy yang mengarahkan default ke database
 * lokasi, dan `EnsureSuperadmin` yang mengembalikannya ke database pusat.
 * Jadi satu model, dua database, tanpa perubahan pada kode yang sudah ada.
 *
 * Kolom yang tidak ada di sini (`avatar_path`, `jabatan_id`) memang tidak
 * relevan untuk user pusat -- panel pusat tidak punya tiket instalasi atau
 * penandatangan laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = config('tenancy.central_connection');

        if (Schema::connection($central)->hasTable('users')) {
            return;
        }

        Schema::connection($central)->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('superadmin');
            $table->rememberToken();
            $table->timestamps();
        });

        // Tanpa ini, DB::table('users')->where('role','superadmin')->count()
        // menyapu seluruh tabel pusat pada setiap pengecekan halaman.
        Schema::connection($central)->table('users', function (Blueprint $table) {
            $table->index('role');
        });

        // Sanctum butuh tabel token-nya sendiri di database pusat.
        if (! Schema::connection($central)->hasTable('personal_access_tokens')) {
            Schema::connection($central)->create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $central = config('tenancy.central_connection');
        Schema::connection($central)->dropIfExists('personal_access_tokens');
        Schema::connection($central)->dropIfExists('users');
    }
};
