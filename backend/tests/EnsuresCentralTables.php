<?php

namespace Tests;

use Illuminate\Support\Facades\Schema;

/**
 * Menyiapkan tabel database PUSAT untuk test.
 *
 * Kenapa helper ini ada, bukan daftar `require` manual di tiap test
 * ======================================================================
 *
 * Test panel pusat memakai `RefreshDatabase`, yang hanya me-refresh database
 * LOKASI (`astaloka_testing`). Tabel pusat -- `tenants`, `users` pusat,
 * `tenant_domains`, `audit_logs` -- tidak ada di sana, jadi tiap test yang
 * butuh tabel-tabel itu menulis sendiri blok "kalau tabelnya belum ada,
 * jalankan file migrasinya".
 *
 * Masalahnya: daftar itu ditulis manual, per file, dari migrasi yang ada
 * SAAT ITU. Begitu ada migrasi pusat baru, daftar itu tidak ikut panjang --
 * tidak ada yang gagal, tidak ada yang memperingatkan, dan testnya lewat
 * dengan skema yang lebih tua dari kodenya.
 *
 * Gejalanya nyata dan sudah terjadi: migrasi
 * `2026_10_15_000001_add_business_profile_to_tenants_table` menambahkan
 * `sektor`/`bidang_usaha`/`deskripsi_usaha`, dan `Tenant` langsung ikut
 * mengirim kolom itu di setiap INSERT. Test provisioning tidak pernah
 * menjalankan migrasi itu, jadi tabel `tenants` di database uji tidak punya
 * kolom tersebut -- dan seluruh test provisioning gagal dengan
 * "Unknown column 'sektor'", yang sama sekali tidak menyinggung
 * migrasinya.
 *
 * Test yang gagal karena migrasi belum ikut tidak ada gunanya sebagai
 * umpan balik: yang dilaporkan adalah kesalahan skema yang tidak ada
 * hubungannya dengan test yang sedang dijalankan.
 *
 * Yang dilakukan di sini
 * ======================
 *
 * Semua migrasi di `database/migrations_central/` dijalankan berurutan
 * berdasarkan nama file. Tidak ada daftar yang harus diperbarui -- menambah
 * migrasi pusat baru otomatis ikut terpakai, tanpa ada yang perlu diingat
 * untuk menyesuaikan.
 *
 * Setiap migrasi central sudah dijaga idempoten (`if (! hasTable)` /
 * `if (! hasColumn)`), jadi menjalankannya berulang aman. Itu yang membuat
 * pemanggilan per-test ini bisa dilakukan tanpa perlu tahu tabel mana yang
 * sudah ada.
 */
trait EnsuresCentralTables
{
    /**
     * Jalankan seluruh migrasi pusat, lalu pastikan tabel token ada.
     *
     * Nama method-nya `runCentralMigrations()`, bukan `ensureCentralTables()`,
     * karena test yang memakainya sudah punya method private dengan nama itu
     * -- nama yang sama akan saling menutupi, dan pemanggil diam-diam
     * memanggil versi yang salah tanpa error.
     */
    protected function runCentralMigrations(string $connection = 'central'): void
    {
        $schema = Schema::connection($connection);

        foreach ($this->centralMigrations() as $path) {
            (require $path)->up();
        }

        /*
         * `personal_access_tokens` dicek TERPISAH dari daftar di atas.
         *
         * Tabelnya dibuat di dalam migrasi `create_central_users_table`,
         * yang seluruh filenya dilewati begitu `users` sudah ada. Kalau
         * `users` sudah ada sementara tabel token belum -- test yang jalan
         * setelah migrasi itu pernah terpasang di environment sebenarnya --
         * file tersebut keluar lebih awal dan tabel token tidak pernah ikut
         * terbawa.
         *
         * Sanctum MEMBUTUHKAN tabel ini. Tanpa itu, `Sanctum::actingAs()` dan
         * setiap `auth:sanctum` gagal dengan "Table
         * 'personal_access_tokens' doesn't exist" -- yang muncul sebagai 401
         * di test mana pun yang memakai token, bukan sebagai masalah skema.
         */
        if (! $schema->hasTable('personal_access_tokens')) {
            $schema->create('personal_access_tokens', function ($table) {
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

    /**
     * Semua migrasi pusat, urut berdasarkan nama file.
     *
     * Urut WAJIB dan diambil dari nama file, bukan dari urutan directory
     * read: `2026_10_13_000001` harus jalan sebelum `2026_10_14_000002`.
     * Nama file sudah membawa timestamp-nya, jadi urutannya deterministik di
     * semua mesin.
     *
     * Folder diambil dari `config('tenancy.central_migrations_path')`, bukan
     * ditulis di sini. Jalur relatif itu yang diberikan Migrator -- kalau
     * yang dipakai `database_path()`, hasilnya backslash di Windows dan
     * `glob()` tidak akan mencocokkan satu file pun.
     *
     * @return list<string>
     */
    private function centralMigrations(): array
    {
        $folder = (string) config('tenancy.central_migrations_path', 'database/migrations_central');

        $files = glob(base_path($folder.'/*.php')) ?: [];

        sort($files);

        return array_values($files);
    }
}
