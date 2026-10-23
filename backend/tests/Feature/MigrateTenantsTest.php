<?php

namespace Tests\Feature;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Perintah `tenancy:migrate-tenants`.
 *
 * Yang diuji di sini bukan "perintahnya jalan", tapi dua hal yang jadi
 * alasan perintah ini ada:
 *
 *  1. Migrasi LOKASI benar-benar dijalankan untuk setiap lokasi terdaftar.
 *     Schema tiap lokasi ada di database masing-masing, jadi satu perintah
 *     pusat tidak cukup untuk memperbarui semuanya.
 *
 *  2. Satu lokasi yang bermasalah TIDAK membuat lokasi lain ikut gagal.
 *     Kalau deploy berhenti di lokasi pertama yang error, separuh lokasi
 *     tidak pernah mendapat migrasi -- dan tidak ada yang tahu mana yang
 *     sudah dan mana yang belum.
 *
 * Yang TIDAK diuji di sini: isi migrasinya sendiri. Itu urusan folder
 * `database/migrations_tenant/`, dan sudah ada test-nya di
 * `CentralTenantProvisionTest` (lokasi baru harus punya skema + master
 * data).
 *
 * Database yang dibuat test ini dibuang di `tearDown` -- polanya sama
 * dengan `CentralTenantProvisionTest`, dan alasannya juga sama: database
 * yang dibuat di dalam test tidak ikut ter-rollback, jadi kalau tidak
 * dibuang manual, database itu menumpuk di server selamanya.
 */
class MigrateTenantsTest extends TestCase
{
    private const CENTRAL = 'central';

    /** Prefix unik: sisa run sebelumnya tidak boleh bentrok. */
    private string $prefix;

    /** Nama database yang wajib dibuang di akhir test. */
    private array $databaseDibuat = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Prefix TANPA hyphen.
         *
         * `Tenant::databaseExists()` dan `TenantManager::validateDatabaseName()`
         * keduanya menolak nama database yang mengandung karakter di luar
         * `[A-Za-z0-9_]`. Prefix dengan `uniqid()` mentah menghasilkan
         * hex yang aman, tapi kalau ada tanda hubung di antaranya, nama
         * database jadi tidak valid -- dan `databaseExists()` mengembalikan
         * false tanpa alasan yang jelas: database-nya ADA, tapi namanya
         * ditolak.
         *
         * Gejalanya di test: lokasi "dilewati" tanpa migrasi apa pun, dan
         * tabel yang diharapkan tidak pernah dibuat.
         */
        $this->prefix = 'migtest_'.uniqid().'_';

        // Sisa run sebelumnya: tabel pusat tidak ikut ter-rollback, jadi
        // harus dihapus eksplisit. Tanpa ini, slug-nya menabrak unique
        // index dengan pesan yang tidak menyebut test mana yang
        // menyisakannya.
        $central = DB::connection(self::CENTRAL);

        $central->table('tenant_domains')->delete();
        $central->table('tenants')->where('slug', 'like', 'migtest_%')->delete();
    }

    protected function tearDown(): void
    {
        try {
            $central = DB::connection(self::CENTRAL);

            $central->table('tenant_domains')->whereIn(
                'tenant_id',
                $central->table('tenants')->where('slug', 'like', 'migtest_%')->pluck('id')
            )->delete();

            $central->table('tenants')->where('slug', 'like', 'migtest_%')->delete();

            foreach ($this->dropOwnedDatabases() as $nama) {
                $this->dropDatabaseIfExists($nama);
            }
        } catch (Throwable) {
            // Pembersihan test tidak boleh menutupi kegagalan test-nya
            // sendiri.
        }

        parent::tearDown();
    }

    #[Test]
    public function perintah_terdaftar_di_daftar_artisan(): void
    {
        $this->assertArrayHasKey(
            'tenancy:migrate-tenants',
            $this->app[Kernel::class]->all(),
            'Perintah ten:migrate-tenants harus terdaftar di artisan.'
        );
    }

    #[Test]
    public function lokasi_aktif_tanpa_database_dinyatakan_gagal(): void
    {
        /*
         * Lokasi AKTIF yang database-nya belum ada bukan hal yang boleh
         * dilewati diam-diam.
         *
         * Kalau dilewati, deploy keluar dengan status sukses padahal ada
         * lokasi yang praktis mati -- tidak ada request yang bisa dilayaninya.
         * Dan tidak ada satu pun tanda di log yang menyebut lokasi itu.
         */
        $slug = $this->prefix.'nodb';

        $this->daftarkanLokasi($slug, $this->prefix.'_tidak_ada');

        $exit = $this->artisan('tenancy:migrate-tenants')
            ->expectsOutputToContain('database lokasi tidak ada');

        $exit->run();
        $exit->assertFailed();
    }

    #[Test]
    public function lokasi_non_aktif_tidak_dimigrasi(): void
    {
        /*
         * Lokasi non-aktif tidak dilayani, jadi tidak ada yang perlu
         * diperbarui -- dan database-nya boleh sudah dihapus.
         *
         * Kalau lokasi non-aktif ikut diproses, deploy akan gagal karena
         * database yang memang sudah dibuang -- dan lokasi aktif yang
         * seharusnya migrated jadi tidak pernah sampai.
         *
         * Di sini yang didaftarkan HANYA lokasi non-aktif, jadi hasil
         * akhirnya "tidak ada yang dimigrasi" -- dan itu sukses, bukan
         * kegagalan. Lihat `MigrateTenants::handle()`.
         */
        $slug = $this->prefix.'nonaktif';

        $this->daftarkanLokasi($slug, $this->prefix.'_nonaktif', active: false);

        $this->artisan('tenancy:migrate-tenants')->assertSuccessful();
    }

    #[Test]
    public function lokasi_aktif_dan_non_aktif_hanya_migrasi_yang_aktif(): void
    {
        /*
         * Yang diuji di sini adalah PERBEDAAN antara keduanya -- bukan
         * sekadar "lokasi non-aktif dilewati".
         *
         * Kalau perintahnya hanya melihat satu jenis, test di atas juga
         * akan lolos. Yang membuat bug ini berbahaya justru kalau
         * lokasi non-aktif ikut diproses SAMPAI SEBERHASIL: database-nya
         * yang sudah dibuang akan dibuat ulang, dan isinya yang sudah
         * sengaja dibuang ikut kembali.
         *
         * Jadi di sini ada dua lokasi: satu aktif (diproses) dan satu
         * non-aktif (harus dilewati). Yang non-aktif TIDAK boleh punya
         * database sama sekali -- kalau command menyentuhnya, PDO gagal
         * membuka koneksi ke database yang tidak ada.
         */
        $namaAktif = $this->daftarkanLokasi($this->prefix.'aktif', $this->prefix.'_aktif');
        $namaNonAktif = $this->daftarkanLokasi($this->prefix.'nonaktif2', $this->prefix.'_nonaktif2', active: false);

        $this->buatDatabaseLokasi($namaAktif);

        try {
            $this->artisan('tenancy:migrate-tenants')
                ->expectsOutputToContain($this->prefix.'aktif')
                ->assertSuccessful();

            $this->assertTrue(
                $this->tabelAdaDiLokasi($namaAktif, 'users'),
                'Lokasi aktif harus dimigrasi.'
            );

            $this->assertFalse(
                $this->tabelAdaDiLokasi($namaNonAktif, 'users'),
                'Lokasi non-aktif tidak boleh dimigrasi -- database-nya memang tidak dibuat.'
            );
        } finally {
            $this->dropDatabaseIfExists($namaAktif);
            $this->dropDatabaseIfExists($namaNonAktif);
        }
    }

    #[Test]
    public function dry_run_tidak_menulis_apa_pun(): void
    {
        $slug = $this->prefix.'dry';

        $nama = $this->daftarkanLokasi($slug, $this->prefix.'_dry');
        $this->buatDatabaseLokasi($nama);

        try {
            $this->artisan('tenancy:migrate-tenants', ['--dry-run' => true])->run();

            // Tidak ada tabel yang dibuat -- dry run harus benar-benar
            // tidak menyentuh database.
            $this->assertFalse(
                $this->tabelAdaDiLokasi($nama, 'users'),
                'Dry run tidak boleh membuat tabel apa pun.'
            );
        } finally {
            $this->dropDatabaseIfExists($nama);
        }
    }

    #[Test]
    public function migrasi_lokasi_berjalan_dan_membuat_tabel(): void
    {
        $slug = $this->prefix.'jalan';

        $nama = $this->daftarkanLokasi($slug, $this->prefix.'_jalan');
        $this->buatDatabaseLokasi($nama);

        try {
            $this->artisan('tenancy:migrate-tenants')->run();

            $this->assertTrue(
                $this->tabelAdaDiLokasi($nama, 'users'),
                'Migrasi lokasi harus membuat tabel users di database lokasi.'
            );

            // Tabel `migrations` juga harus ada -- itu yang membuat migrasi
            // tidak dijalankan dua kali pada deploy berikutnya.
            $this->assertTrue(
                $this->tabelAdaDiLokasi($nama, 'migrations'),
                'Tabel migrations harus dibuat di database lokasi.'
            );
        } finally {
            $this->dropDatabaseIfExists($nama);
        }
    }

    #[Test]
    public function lokasi_yang_gagal_tidak_menggagalkan_lokasi_lain(): void
    {
        $slugRusak = $this->prefix.'rusak';
        $slugJalan = $this->prefix.'lancar';

        $namaRusak = $this->daftarkanLokasi($slugRusak, $this->prefix.'_rusak');
        $namaJalan = $this->daftarkanLokasi($slugJalan, $this->prefix.'_lancar');

        // Hanya lokasi kedua yang punya database. Yang pertama sengaja
        // tidak -- jadi migrasinya pasti gagal.
        $this->buatDatabaseLokasi($namaJalan);

        try {
            /*
             * `->run()` WAJIB dipanggil eksplisit.
             *
             * `$this->artisan()` mengembalikan `PendingCommand`, yang
             * baru benar-benar menjalankan perintahnya di `__destruct()`.
             * Tanpa `->run()`, seluruh assertion di bawahnya dievaluasi
             * SEBELUM perintahnya jalan -- dan assertion soal isi database
             * jelas akan gagal, karena database-nya belum pernah disentuh.
             *
             * Gejalanya menipu: `assertFailed()` yang dipakai di sini hanya
             * MENCATATKAN ekspektasi exit code, dan Assertions lain
             * dijalankan duluan. Test gagal dengan "tabel tidak ada",
             * padahal masalahnya urutan eksekusi, bukan perintahnya.
             */
            $exit = $this->artisan('tenancy:migrate-tenants');
            $exit->run();

            // Perintah HARUS gagal di akhir -- ada lokasi yang tidak
            // termigrasi, dan deploy harus terlihat merah.
            $exit->assertFailed();

            // Tapi lokasi kedua tetap harus termigrasi.
            $this->assertTrue(
                $this->tabelAdaDiLokasi($namaJalan, 'users'),
                'Lokasi yang sehat harus tetap termigrasi meski lokasi lain gagal.'
            );
        } finally {
            $this->dropDatabaseIfExists($namaRusak);
            $this->dropDatabaseIfExists($namaJalan);
        }
    }
    // ================================================================
    // Helper
    // ================================================================

    /**
     * Daftarkan satu lokasi di registry pusat.
     *
     * Baris ditulis langsung ke tabel `tenants`, bukan lewat endpoint
     * panel pusat: endpoint itu akan ikut menjalankan provisioning
     * (migrasi + seeder), dan yang sedang diuji di sini justru jalannya
     * migrasi secara manual.
     */
    private function daftarkanLokasi(string $slug, string $namaDatabase, bool $active = true): string
    {
        DB::connection(self::CENTRAL)->table('tenants')->insert([
            'slug' => $slug,
            'nama' => 'Lokasi Uji',
            'database' => $namaDatabase,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->databaseDibuat[] = $namaDatabase;

        return $namaDatabase;
    }

    /**
     * Buat database lokasi kosong.
     *
     * Sengaja TANPA tabel: migrasi-lah yang harus membuatnya.
     */
    private function buatDatabaseLokasi(string $nama): void
    {
        $central = DB::connection(self::CENTRAL);

        $central->statement(
            "CREATE DATABASE IF NOT EXISTS `{$nama}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    /**
     * Apakah sebuah tabel ada di database lokasi tertentu?
     */
    private function tabelAdaDiLokasi(string $namaDatabase, string $tabel): bool
    {
        $central = DB::connection(self::CENTRAL);

        $ada = $central->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$namaDatabase, $tabel]
        );

        return $ada !== [];
    }

    /**
     * Semua database yang dibuat test ini.
     *
     * Dicari dari PREFIX, bukan dari daftar yang dicatat -- daftar itu
     * dicatat SESUDAH database dibuat, jadi test yang gagal tepat setelah
     * database-nya dibuat tidak pernah masuk daftar, dan database itu
     * tertinggal selamanya.
     *
     * @return list<string>
     */
    private function dropOwnedDatabases(): array
    {
        $central = DB::connection(self::CENTRAL);

        return $central->table('tenants')
            ->where('slug', 'like', 'migtest_%')
            ->pluck('database')
            ->all();
    }

    private function dropDatabaseIfExists(string $nama): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $nama)) {
            return;
        }

        DB::connection(self::CENTRAL)->statement("DROP DATABASE IF EXISTS `{$nama}`");
    }
}
