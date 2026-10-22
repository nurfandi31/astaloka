<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil usaha untuk setiap tenant.
 *
 * Kenapa kolom ini ditambahkan
 * ============================
 *
 * Dashboard pusat dipakai untuk menjawab pertanyaan "lokasi ini usaha apa,
 * dan tagihannya berapa". Sebagian pertanyaannya sudah bisa dijawab dari kolom
 * yang ada (`nama`, `nama_lembaga`, `alamat`), tapi pertanyaan "usaha apa"
 * tidak. Nama lembaga tidak pernah menyebut bidang usaha: "Perum Tirto Mulo"
 * bisa saja bergerak di air minum, sanitasi, atau pengelolaan sampah, dan
 * tabel `tenants` tidak punya tempat untuk menyimpan jawabannya.
 *
 * Inilah yang membuat dashboard lama tidak bisa menampilkan apa yang
 * sebenarnya dikerjakan sebuah lokasi -- bukan karena tampilannya kurang kaya,
 * tapi karena datanya memang tidak ada.
 *
 * Tiga kolom, bukan satu
 * ---------------------
 *
 * `sektor` -> kategori luas ("Air Bersih", "Sanitasi", "Koperasi"). Dipakai
 *   untuk mengelompokkan, jadi nilainya dari daftar tertutup dan pendek.
 * `bidang_usaha` -> nama usaha yang lebih spesifik ("Perusahaan Air Minum
 *   Tirto Mulo"). Ini yang muncul sebagai judul di pop-up detail.
 * `deskripsi_usaha` -> kalimat bebas untuk kasus yang tidak muat di dua kolom
 *   di atas.
 *
 * Dipisah tiga, bukan satu kolom bebas, karena bentuk pertanyaannya berbeda:
 * mengelompokkan butuh nilai yang comparable, sedangkan menjelaskan tidak.
 * Kalau semuanya jadi satu kolom bebas, pengelompokan pertama yang muncul
 * adalah Whatever yang diketik admin -- dan grup itu tidak pernah bisa
 * diperbaiki tanpa mengubah semua isinya satu per satu.
 *
 * Semua nullable, dan tidak ada backfill
 * -------------------------------------
 *
 * Data lama tidak punya jawaban, jadi dikosongkan -- bukan ditebak dari nama.
 * Menebak "Perum Tirto Mulo" = air minum terdengar meyakinkan, dan kalau
 * ternyata salah, angkanya yang salah sementara terlihat benar. Ruangan yang
 * kosong jelas diisi lewat form Ubah; angka yang salah tidak terlihat begitu
 * saja.
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
        if (! $schema->hasColumn('tenants', 'sektor')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->string('sektor', 60)->nullable()->after('nama_lembaga');
            });
        }

        if (! $schema->hasColumn('tenants', 'bidang_usaha')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->string('bidang_usaha', 120)->nullable()->after('sektor');
            });
        }

        if (! $schema->hasColumn('tenants', 'deskripsi_usaha')) {
            $schema->table('tenants', function (Blueprint $table) {
                $table->string('deskripsi_usaha', 500)->nullable()->after('bidang_usaha');
            });
        }
    }

    public function down(): void
    {
        Schema::connection(config('tenancy.central_connection'))
            ->table('tenants', function (Blueprint $table) {
                $table->dropColumn(['sektor', 'bidang_usaha', 'deskripsi_usaha']);
            });
    }
};
