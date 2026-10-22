<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding halaman login per lokasi.
 *
 * Kenapa kolom ini ditambahkan
 * ============================
 *
 * Halaman login tiap lokasi menampilkan judul, sub judul, dan deskripsi yang
 * SELALU sama untuk semua lokasi: "ASTALOKA / Digital Platform" followed by
 * "Asta Layanan Operasional, Keuangan, dan Administrasi. Satu sistem untuk
 * air minum, internet, pembayaran sampah, dan usaha lainnya."
 *
 * Semuanya literal di `LoginView.vue`. Akibatnya setiap lokasi -- PDAM air
 * minum, penyedia internet, pengumpul sampah, atau usaha lainnya --
 * menampilkan kalimat yang sama persis, dan tidak ada tempat untuk
 * mengubahnya, karena tidak ada kolom yang menyimpan isinya.
 *
 * Kenapa di `tenants` (pusat), bukan di `settings` (per lokasi)
 * =============================================================
 *
 * Dua alasan, keduanya nyata:
 *
 *  1. Permintaan asalnya adalah "disunting dari detail tenant di panel
 *     pusat". Panel pusat membaca dan menulis database PUSAT. Kalau branding
 *     disimpan di `settings`, superadmin harus menyalin isinya ke setiap
 *     database lokasi satu per satu -- dan setiap salinan bisa gagal diam-diam
 *     sehingga panel terlihat tersimpan tapi halamannya tidak berubah.
 *
 *  2. Halaman login tidak punya sesi. `settings` hanya bisa dibaca lewat
 *     `auth:sanctum` + `role:admin`, sedangkan teks ini harus tampil SEBELUM
 *     orang mengetik apa pun. Endpoint publik (lihat
 *     `PublicBrandingController`) jauh lebih sederhana kalau sumbernya
 *     registry pusat, yang memang sudah dibaca tanpa sesi untuk resolving
 *     tenant.
 *
 * Warna, kenapa satu kolom hex saja
 * =================================
 *
 * Menyimpan satu warna `#rrggbb`, bukan sepuluh. Skala penuh (50 sampai 900)
 * yang ikut disimpan pasti akan menyimpang dari warna induknya: admin
 * memilih hijau, lalu menyimpan biru muda yang tidak ada hubungannya dengan
 * hijau itu.
 *
 * Turunannya dihitung di frontend dari warnanya (`utils/themeColor.js`), jadi
 * apa pun yang dipilih admin tetap punya lightness dan hue yang sama di semua
 * turunannya.
 *
 * Semua nullable, dan tidak ada backfill
 * -------------------------------------
 *
 * Data lama tidak punya branding. Migration TIDAK menuliskan judul dan warna
 * bawaan ke setiap baris: baris yang kosong adalah jawaban yang jujur ("belum
 * diatur, pakai bawaan"), sedangkan baris yang diisi teks bawaan membuat panel
 * pusat menampilkan isian yang seolah sudah sengaja diisi padahal tidak pernah
 * ada yang mengetiknya.
 *
 * Nilai bakunya hidup di satu tempat -- `App\Support\Branding` -- dan dipakai
 * oleh endpoint publik maupun serializer panel pusat, jadi builtin dan bawaan
 * tidak bisa berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = config('tenancy.central_connection');
        $schema = Schema::connection($central);

        if (! $schema->hasTable('tenants')) {
            return;
        }

        // Dijaga satu per satu, bukan dibungkus satu `if (! hasColumns(...))`.
        // Dua file migration bisa sama-sama membuka tabel ini -- misalnya satu
        // dehumanize data dan satu menambah kolom -- dan pemeriksaan gabungan
        // membuat keduanya melihat "sudah ada" lalu saling dilewati.
        if (! $schema->hasColumn('tenants', 'judul_login')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->string('judul_login', 80)->nullable()->after('deskripsi_usaha');
            });
        }

        if (! $schema->hasColumn('tenants', 'sub_judul_login')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->string('sub_judul_login', 80)->nullable()->after('judul_login');
            });
        }

        if (! $schema->hasColumn('tenants', 'deskripsi_login')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->string('deskripsi_login', 255)->nullable()->after('sub_judul_login');
            });
        }

        if (! $schema->hasColumn('tenants', 'warna_utama')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->string('warna_utama', 7)->nullable()->after('deskripsi_login');
            });
        }
    }

    public function down(): void
    {
        Schema::connection(config('tenancy.central_connection'))
            ->table('tenants', function (Blueprint $table) {
                $table->dropColumn([
                    'judul_login',
                    'sub_judul_login',
                    'deskripsi_login',
                    'warna_utama',
                ]);
            });
    }
};