<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah role `superadmin` untuk user pusat ASTA BRATA.
 *
 * Role ini TIDAK ada di database lokasi -- hanya di database pusat. Admin
 * lokasi tetap `admin`, persis seperti sebelumnya, supaya pemisahan
 * "mengelola lokasi" vs "mengelola data lokasi" tidak bercampur.
 *
 * Enum MySQL harus diubah dengan MODIFY COLUMN (bukan ADD), karena ADD COLUMN
 * dengan enum baru akan menabrak kolom yang sudah ada.
 */
return new class extends Migration
{
    /**
     * Koneksi ditulis eksplisit, bukan lewat `Schema::table()` biasa.
     *
     * Tabel `users` ada di DUA database dengan isi berbeda: database pusat
     * (superadmin) dan tiap database lokasi (admin, teknisi, dst). Yang
     * diubah di sini hanya yang pusat. Kalau koneksi diambil dari
     * `database.default`, migrasi ini akan mengubah kolom `role` di
     * database LOKASI -- dan nilainya `superadmin` tidak ada di sana,
     * sehingga enum lokasi ikut berubah dan user lokasi kehilangan peran
     * adminnya.
     *
     * Folder `migrations_central/` SENGAJA TIDAK didaftarkan lewat
     * `loadMigrationsFrom()`, jadi migrasi ini tidak pernah ikut terpakai oleh
     * `php artisan migrate` biasa yang koneksinya `tenant`. Ia hanya jalan
     * lewat `tenancy:migrate-central`, yang menyebut `--path` folder ini
     * secara eksplisit.
     */
    public function up(): void
    {
        $this->central()->table('users', function (Blueprint $table) {
            $table->enum('role', ['superadmin', 'admin', 'surveyor', 'teknisi', 'pelanggan'])
                ->default('pelanggan')
                ->change();
        });
    }

    public function down(): void
    {
        // Menarik kembali ke 4 role lama. Baris superadmin yang masih ada
        // TIDAK dihapus otomatis: nilainya tidak muat di enum lama, jadi MySQL
        // akan menggantinya menjadi 'pelanggan' -- hilangnya informasi yang
        // perlu dicek manual sebelum rollback di produksi.
        $this->central()->table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'surveyor', 'teknisi', 'pelanggan'])
                ->default('pelanggan')
                ->change();
        });
    }

    private function central(): Builder
    {
        return Schema::connection((string) config('tenancy.central_connection', 'central'));
    }
};
