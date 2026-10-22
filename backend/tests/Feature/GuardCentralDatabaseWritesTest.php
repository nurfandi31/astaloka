<?php

namespace Tests\Feature;

use App\Listeners\GuardCentralDatabaseWrites;
use Illuminate\Console\Events\CommandStarting;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Menguji GuardCentralDatabaseWrites.
 *
 * Pengaman ini dibuat karena `astaloka` -- database PUSAT -- pernah berisi
 * skema lokasi: `customers`, `monthly_bills`, `villages`, dan trigger
 * `*_amount_debit`, sementara `tenants`, `audit_logs`, dan `tenant_invoices`
 * tidak pernah ada. Migration log di sana berisi 66 baris, semuanya migrasi
 * lokasi dan nol dari 15 migrasi pusat.
 *
 * Test ini mengunci bentuk perbaikan yang dipakai listener: pemeriksaan
 * PASANGAN (database tujuan + folder yang dipindai), bukan hanya salah satu.
 *
 * Alasannya `migrate --database=central` -- yang menyebut targetnya dengan
 * jelas -- justru MEMBUKA lubang yang sama persis kalau hanya target yang
 * diperiksa: tanpa `--path`, folder yang dipindai tetap
 * `database/migrations/`, yaitu skema lokasi. Karena itu ada test khusus untuk
 * kombinasi itu, dan ada test yang memastikan jalur sah tetap jalan.
 *
 * Listener dipanggil langsung, bukan lewat `$this->artisan()`, supaya tidak
 * ada migrasi sungguhan yang sempat berjalan di atas database uji.
 */
class GuardCentralDatabaseWritesTest extends TestCase
{
    private function fire(string $command, array $options = []): void
    {
        $input = new ArrayInput($options);
        $input->setInteractive(false);

        (new GuardCentralDatabaseWrites)->handle(
            new CommandStarting($command, $input, new NullOutput)
        );
    }

    /**
     * Jalankan guard dengan `tenancy.central_database` dan default connection
     * ditirukan, tanpa membuka koneksi sungguhan.
     *
     * Koneksi yang aktif diperiksa lewat `getDatabaseName()`, jadi menyamar
     * hanya dengan mengubah config TIDAK cukup untuk membuat listener melihat
     * nama yang dikehendaki test -- database uji benar-benar masih terbuka.
     * Karena itu pemalsuan dilakukan pada nama database itu sendiri, lalu
     * dipulihkan.
     */
    private function fireWithDatabases(string $central, string $default, string $command, array $options = []): void
    {
        $asliCentral = config('tenancy.central_database');
        $asliDefault = config('database.default');
        $asliTenant = config('database.connections.tenant.database');
        $asliKoneksiPusat = config('database.connections.central.database');

        config([
            'tenancy.central_database' => $central,
            'database.default' => 'tenant',
            'database.connections.tenant.database' => $default,
            /*
             * Koneksi `central` WAJIB ikut ditimpa, bukan hanya
             * `tenancy.central_database`.
             *
             * `resolveTargetDatabase()` membaca `--database=central` sebagai
             * NAMA KONEKSI dan mencari nama database di
             * `database.connections.central.database`. Menyalin nilai ke
             * `tenancy.central_database` saja tidak mengubah apa yang dibaca
             * di sana, jadi perbandingannya membandingkan nilai central yang
             * asli dengan nilai tiruan test -- tidak akan sama, dan guard
             * lolos.
             *
             * Gejalanya test ini gagal dengan "exception tidak dilempar",
             * padahal test-nya sendiri tidak salah: justru inilah yang terjadi
             * kalau penyalinannya ditambahkan tapi hanya di satu jalur.
             */
            'database.connections.central.database' => $central,
        ]);

        try {
            $this->fire($command, $options);
        } finally {
            config([
                'tenancy.central_database' => $asliCentral,
                'database.default' => $asliDefault,
                'database.connections.tenant.database' => $asliTenant,
                'database.connections.central.database' => $asliKoneksiPusat,
            ]);
        }
    }

    public function test_migrasi_ke_database_pusat_tanpa_path_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('skema LOKASI ke database PUSAT');

        // Target = pusat, tapi folder yang dipindai = lokasi. Inilah perintah
        // yang merusak `astaloka` tanpa menghasilkan satu pun error.
        $this->fireWithDatabases(
            'astaloka',
            'astaloka',
            'migrate',
            ['--database' => 'astaloka', '--force' => true]
        );
    }

    public function test_migrasi_ke_database_pusat_lewat_nama_koneksi_juga_ditolak(): void
    {
        $this->expectException(RuntimeException::class);

        // `--database=central` menyebut NAMA KONEKSI, bukan nama database.
        // Kalau listener hanya membaca flag mentah, yang dibandingkan jadi
        // string `central` yang tidak pernah sama dengan `astaloka`, dan
        // perintahnya lolos -- persis bug yang harus dicegah.
        $this->fireWithDatabases(
            'astaloka_testing_central',
            'astaloka_testing_central',
            'migrate',
            ['--database' => 'central', '--force' => true]
        );
    }

    public function test_migrate_status_ke_pusat_tanpa_path_ditolak(): void
    {
        $this->expectException(RuntimeException::class);

        $this->fireWithDatabases(
            'astaloka',
            'astaloka',
            'migrate:status'
        );
    }

    public function test_migrasi_pusat_lolos_karena_path_folder_pusat(): void
    {
        // Jalur SAH: `tenancy:migrate-central` memanggil persis ini. Target
        // dan folder sama-sama benar, jadi tidak boleh diblokir.
        //
        // Path diambil dari config, bukan ditulis di sini -- kalau tidak,
        // memindahkan folder migrasi akan membuat test ini gagal dengan
        // pesan yang menyiratkan penjaganya rusak, padahal yang usang justru
        // testnya.
        $this->fireWithDatabases(
            'astaloka',
            'astaloka',
            'migrate',
            [
                '--database' => 'central',
                '--path' => (string) config('tenancy.central_migrations_path'),
                '--force' => true,
            ]
        );

        $this->assertTrue(true, 'migrasi pusat dengan --path yang benar harus lolos');
    }

    public function test_migrasi_lokasi_lolos(): void
    {
        // Target = lokasi, folder = lokasi. Wajar, dan harus tetap jalan.
        $this->fireWithDatabases(
            'astaloka',
            'astaloka_lokasi',
            'migrate',
            ['--database' => 'tenant', '--force' => true]
        );

        $this->assertTrue(true, 'migrasi ke database lokasi harus lolos');
    }

    public function test_migrasi_lokasi_lolos_dengan_path_folder_lokasi(): void
    {
        /*
         * Bentuk yang BENAR-BENAR dipakai `TenantManager::migrateTenantDatabase()`
         * sekarang: `--path` eksplisit ke folder tenant.
         *
         * Tidak ada `--path` tidak boleh dianggap aman hanya karena targetnya
         * benar -- folder default Migrator sudah tidak berisi apa pun, jadi
         * pemanggilan tanpa `--path` hanya menghasilkan "Nothing to migrate"
         * dengan status sukses.
         */
        $this->fireWithDatabases(
            'astaloka',
            'astaloka_lokasi',
            'migrate',
            [
                '--database' => 'tenant',
                '--path' => (string) config('tenancy.migrations_path'),
                '--force' => true,
            ]
        );

        $this->assertTrue(true, 'migrasi lokasi dengan --path tenant harus lolos');
    }

    public function test_migrasi_pusat_ke_database_lokasi_ditolak(): void
    {
        /*
         * Arah yang SEBELUMNYA tidak dijaga sama sekali.
         *
         * Migrasi pusat memakai `Schema::connection('central')`, jadi tabelnya
         * memang dibuat di pusat -- tapi catatan "sudah jalannya" ditulis ke
         * tabel `migrations` koneksi yang sedang aktif. Kalau targetnya
         * database lokasi, baris itu masuk ke `migrations` lokasi, dan
         * provisioning berikutnya melompati migrasi yang tidak pernah jalan
         * di sana. Tidak ada error, tidak ada tabel yang salah tempat.
         */
        $this->expectExceptionMessage('migrasi PUSAT ke database LOKASI');

        $this->fireWithDatabases(
            'astaloka',
            'astaloka_lokasi',
            'migrate',
            [
                '--database' => 'mysql',
                '--path' => (string) config('tenancy.central_migrations_path'),
                '--force' => true,
            ]
        );
    }

    public function test_perintah_bukan_migrasi_tidak_diperturbakan(): void
    {
        // `migrate` internal dari TenantManager memakai `--database=tenant`,
        // tapi perintah lain yang tidak menjalankan migrasi sama sekali tidak
        // boleh tersentuh -- termasuk yang namanya diawali `migrate`.
        $this->fireWithDatabases(
            'astaloka',
            'astaloka',
            'migrate:install'
        );

        $this->assertTrue(true, 'perintah non-migrasi harus lolos');
    }
}
