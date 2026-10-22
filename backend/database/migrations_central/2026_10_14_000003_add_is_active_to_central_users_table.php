<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah `is_active` ke tabel `users` di DATABASE PUSAT.
 *
 * Tanpa kolom ini, "menonaktifkan superadmin" hanya bisa berarti menghapus
 * barisnya. Dan menghapus baris itu punya dua akibat yang tidak terlihat di
 * layar:
 *
 *   1. Jejak audit lama ikut hilang. Tabel `audit_logs` sengaja tidak punya
 *      foreign key, jadi baris auditnya tetap ada -- tapi kolom
 *      `subject_id` dan nama aktornya jadi menunjuk id yang sudah tidak punya
 *      arti. Menyelidiki insiden dua bulan kemudian jadi jauh lebih sulit.
 *   2. Satu-satunya akun yang tersisa bisa hilang tanpa peringatan. Kalau
 *      superadmin terakhir dihapus, tidak ada yang bisa masuk ke panel pusat
 *      untuk membuat akun baru -- jalan keluar hanya lewat akses database.
 *
 * Menonaktifkan lebih aman: baris tetap ada untuk dibaca, tapi token yang
 * sedang dipakai ikut dicabut supaya akses yang sudah terbuka tidak bertahan
 * lebih lama dari keputusan admin.
 *
 * Kolom ini HANYA ada di database pusat. Tabel `users` di tiap database lokasi
 * tetap tanpa kolom ini -- status user lokasi bukan urusan panel pusat, dan
 * menambahkannya di sini hanya membuka kemungkinan dua daftar status yang
 * berbeda untuk konsep yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = $this->central();

        // Migration ini harus bisa dijalankan ulang: `tenancy:migrate-central`
        // sering dipanggil pada database yang sudah sebagian jadi, dan gagal
        // di tengah tidak boleh membuat database pusat tidak bisa dipakai lagi.
        if ($central->hasColumn('users', 'is_active')) {
            return;
        }

        $central->table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
        });

        /*
         * Filter "tampilkan hanya yang aktif" adalah permintaan paling sering
         * dari halaman ini -- daftar biasanya dibaca untuk mencari satu akun,
         * dan akun nonaktif hanya jadi derau di antara mereka. Tanpa index,
         * tiap pencarian menyapu seluruh tabel pusat.
         */
        $central->table('users', function (Blueprint $table) {
            $table->index(['is_active', 'role']);
        });
    }

    public function down(): void
    {
        $central = $this->central();

        if (! $central->hasColumn('users', 'is_active')) {
            return;
        }

        $central->table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'role']);
        });

        $central->table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    /**
     * Koneksi ditulis eksplisit, bukan lewat `Schema::table()` biasa.
     *
     * Tabel `users` ada di DUA database dengan isi berbeda: database pusat
     * (superadmin) dan tiap database lokasi (admin, teknisi, dst). Yang diubah
     * di sini hanya yang pusat -- lihat catatan di migration
     * `2026_10_13_000005_add_superadmin_role_to_users_table` untuk contoh nyata
     * akibatnya kalau koneksi diambil dari `database.default`.
     */
    private function central(): Builder
    {
        return Schema::connection((string) config('tenancy.central_connection', 'central'));
    }
};