<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel registry tenant di DATABASE PUSAT (bukan di database lokasi).
 *
 * Satu baris = satu lokasi. Kolom `database` menyimpan nama database MySQL
 * yang dipakai lokasi tersebut; `db_username`/`db_password` disimpan
 * opsional untuk lokasi yang punya kredensial database sendiri, dan
 * NULL berarti memakai kredensial DB_* yang sama seperti lokasi lain.
 *
 * Tabel ini TIDAK di-migrate ke database lokasi -- lihat
 * `migrations_central/` dan `config/tenancy.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection(config('tenancy.central_connection'))->hasTable('tenants')) {
            return;
        }

        Schema::connection(config('tenancy.central_connection'))->create('tenants', function (Blueprint $table) {
            $table->id();

            // Identitas publik lokasi. Dipakai di URL/subdomain dan jadi
            // penentu nama database. Harus unik dan hanya berisi
            // [a-z0-9-].
            $table->string('slug', 64)->unique();

            $table->string('nama', 150);

            // Nama lembaga resmi, dipakai di kop laporan & invoice.
            $table->string('nama_lembaga', 191)->nullable();

            // Nama database MySQL untuk lokasi ini.
            $table->string('database', 64);

            // Kredensial khusus. NULL = pakai DB_* dari .env.
            $table->string('db_username', 191)->nullable();
            $table->string('db_password', 191)->nullable();
            $table->string('db_host', 191)->nullable();
            $table->string('db_port', 11)->nullable();

            // Host/subdomain untuk resolving tenant dari request.
            $table->string('domain', 191)->nullable();
            $table->string('domain_alternatif', 191)->nullable();

            $table->string('alamat')->nullable();
            $table->string('email', 191)->nullable();
            $table->string('telepon', 30)->nullable();
            $table->string('logo')->nullable();

            // Tanggal aktivasi & masa langganan. NULL pada `tgl_berakhir`
            // berarti langganan berjalan tanpa batas.
            $table->date('tgl_registrasi')->nullable();
            $table->date('tgl_pakai')->nullable();
            $table->date('tgl_berakhir')->nullable();

            $table->boolean('is_active')->default(true);

            /*
             * Tidak ada kolom `max_users` di sini.
             *
             * Dulu ada, sebagai batas jumlah user per lokasi. Kolom itu
             * dihapus lagi di `2026_10_16_000002_drop_max_users_from_tenants_table`
             * karena tidak pernah dipakai -- semua tenant bernilai `NULL`, dan
             * `NULL` sudah berarti "tanpa batas" sejak awal.
             *
             * Kolomnya sengaja TIDAK ditulis ulang di file ini, walaupun file
             * ini yang membuat tabel: menambahkan `dropColumn` di migration
             * terpisah sudah menutup kedua arah (install baru tidak pernah
             * membuat kolomnya, install lama meninggalkannya lewat migrasi
             * tersebut), dan menyalin kolom yang sudah dibuang ke sini hanya
             * membuat file yang lebih panjang tanpa mengubah apa pun.
             */

            $table->timestamps();

            $table->index(['is_active', 'tgl_berakhir']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('tenancy.central_connection'))->dropIfExists('tenants');
    }
};
