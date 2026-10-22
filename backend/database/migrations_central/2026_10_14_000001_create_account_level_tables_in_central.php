<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel struktur akun (level 1-3) di DATABASE PUSAT.
 *
 * Level 1-3 adalah KERANGKA chart of accounts, bukan data operasional.
 * Yang sama untuk semua lokasi, jadi disimpan sekali di `astaloka` dan
 * dikelola dari panel pusat. Level 4 -- tabel `accounts` -- tetap per lokasi,
 * karena setiap lokasi boleh menambah/menyesuaikan akunnya sendiri.
 *
 * Rantai induknya:
 *   akun_level_1 (5)  ->  akun_level_2 (14)  ->  akun_level_3 (42)
 *                                                           -> accounts (per lokasi)
 *
 * Kenapa tiga tabel ini saja yang pindah, dan bukan `accounts` sekalian:
 * `accounts` dipegang FK `amount.account_id` (CASCADE) dan tiga trigger MySQL
 * (`create/update/delete_amount_debit`) yang hidup di database lokasi. Memindahkannya
 * ke pusat memaksa trigger ditunjuk ke database lain -- MySQL trigger tidak
 * boleh spanning database. Dengan level 1-3 di pusat, satu-satunya tempat yang
 * harus melintasi dua database adalah query pembentuk pohon laporan, dan itu
 * ditangani di `PelaporanService`/`PelaporanController` sebagai query manual.
 *
 * TIDAK ada foreign key constraint di ketiga tabel (parent_id hanya
 * ditegakkan oleh logika seeder dan Eloquent) -- diverifikasi di DB: nol
 * constraint, nol dangling. Jadi tidak ada yang perlu dilepas saat tabel
 * dipindah.
 *
 * Kolom dan index sengaja mengikuti migration lokasi asalnya apa adanya supaya
 * kueri yang sudah ada tidak perlu diubah.
 */
return new class extends Migration
{
    private function central(): string
    {
        return (string) config('tenancy.central_connection', 'central');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->central());

        // Satu migrasi per tabel, dengan penjaga `hasTable` yang sama seperti
        // migrasi pusat lain: `migrate` bisa dijalankan ulang tanpa error.
        if (! $schema->hasTable('akun_level_1')) {
            $schema->create('akun_level_1', function (Blueprint $table) {
                $table->id();
                $table->integer('lev1');
                $table->integer('lev2')->default(0);
                $table->integer('lev3')->default(0);
                $table->integer('lev4')->default(0);
                $table->string('kode_akun', 10);
                $table->string('nama_akun', 100);
                $table->string('jenis_mutasi', 6);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('akun_level_2')) {
            $schema->create('akun_level_2', function (Blueprint $table) {
                $table->id();
                $table->integer('parent_id');
                $table->integer('lev1');
                $table->integer('lev2');
                $table->integer('lev3')->default(0);
                $table->integer('lev4')->default(0);
                $table->string('kode_akun', 10);
                $table->string('nama_akun', 100);
                $table->string('jenis_mutasi', 6);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('akun_level_3')) {
            $schema->create('akun_level_3', function (Blueprint $table) {
                $table->id();
                $table->integer('parent_id');
                $table->integer('lev1');
                $table->integer('lev2');
                $table->integer('lev3');
                $table->integer('lev4')->default(0);
                $table->string('kode_akun', 10);
                $table->string('nama_akun', 100);
                $table->integer('posisi')->default(1);
                $table->string('jenis_mutasi', 6);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->central());

        $schema->dropIfExists('akun_level_3');
        $schema->dropIfExists('akun_level_2');
        $schema->dropIfExists('akun_level_1');
    }
};