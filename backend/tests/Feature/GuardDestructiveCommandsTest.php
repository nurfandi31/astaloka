<?php

namespace Tests\Feature;

use App\Listeners\GuardDestructiveCommands;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Menguji GuardDestructiveCommands.
 *
 * Pengaman ini sebelumnya hanya terdaftar saat `runningInConsole()` bernilai
 * true. Ketika test berjalan, aplikasi di-boots ulang di dalam proses dan
 * syarat tersebut bernilai false, sehingga guard tidak aktif -- dan test suite
 * sempat menjatuhkan `astaloka_lokasi`. Test ini mengunci perilaku itu.
 *
 * Perintah diuji dengan memanggil listener secara langsung, BUKAN lewat
 * `$this->artisan()`. Alasannya: `migrate:fresh` dan `db:wipe` yang benar-benar
 * dijalankan akan menghapus tabel yang dipakai test lain -- dan kalau guard
 * sedang diuji, kegagalan sementara sudah cukup jadi bukti, tanpa perlu
 * ikut merusak database.
 */
class GuardDestructiveCommandsTest extends TestCase
{
    private function fire(string $command, array $options = []): void
    {
        $input = new ArrayInput($options);
        $input->setInteractive(false);

        (new GuardDestructiveCommands)->handle(
            new CommandStarting($command, $input, new NullOutput)
        );
    }

    /**
     * Menjalankan guard dalam kondisi database operasional.
     *
     * Saat test, `DB_DATABASE` adalah `astaloka_testing` -- itu memang benar,
     * tapi justru karena itu guard tidak akan menolak apa pun. Supaya yang
     * diuji adalah perilaku penolakan, nama database-nya ditirukan di sini.
     * Tidak ada koneksi yang dibuka, karena listener hanya membaca config.
     */
    private function fireAgainstProductionName(string $command, array $options = []): void
    {
        $original = config('database.connections.mysql.database');

        config(['database.connections.mysql.database' => 'astaloka_lokasi']);

        try {
            $this->fire($command, $options);
        } finally {
            config(['database.connections.mysql.database' => $original]);
        }
    }

    public function test_db_wipe_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('db:wipe', ['--force' => true]);
    }

    public function test_migrate_fresh_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('migrate:fresh', ['--force' => true]);
    }

    public function test_migrate_refresh_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('migrate:refresh', ['--force' => true]);
    }

    public function test_migrate_reset_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('migrate:reset', ['--force' => true]);
    }

    /**
     * `migrate` biasa tidak menghapus data, jadi tidak boleh diblokir.
     */
    public function test_migrate_tanpa_force_tidak_ditolak(): void
    {
        $this->fire('migrate');

        $this->assertTrue(true, 'migrate tanpa --force harus boleh lewat.');
    }

    /**
     * Perintah yang tidak merusak sama sekali harus sepenuhnya dilewati.
     */
    public function test_perintah_aman_tidak_dipertahankan(): void
    {
        foreach (['migrate:status', 'db:show', 'route:list', 'config:clear'] as $command) {
            $this->fire($command, ['--force' => true]);
        }

        $this->assertTrue(true, 'Perintah aman tidak boleh diblokir.');
    }

    /**
     * `migrate` biasa tanpa `--force` tidak boleh diblokir.
     *
     * Tanpa `--force`, `migrate` di produksi hanya menampilkan konfirmasi
     * dan berhenti -- tidak ada satu baris pun yang tertulis. Yang
     * berbahaya adalah `--force`, dan itu diuji terpisah.
     *
     * Jalur provisioning internal (`--database=tenant`) tetap boleh,
     * walau `--force` ada: itu jalur yang dipakai `TenantManager` untuk
     * menyiapkan lokasi baru.
     */
    public function test_migrate_biasa_tidak_ditolak(): void
    {
        $original = config('database.connections.mysql.database');

        config(['database.connections.mysql.database' => 'astaloka_lokasi']);

        try {
            $this->fire('migrate');
            $this->fire('migrate', ['--force' => true, '--database' => 'tenant']);
        } finally {
            config(['database.connections.mysql.database' => $original]);
        }

        $this->assertTrue(true, 'migrate tanpa --force, dan migrate ke jalur provisioning, harus boleh lewat.');
    }

    /**
     * Database pengujian tetap boleh di-wipe; itu jalur yang dipakai
     * `php artisan test` untuk menyiapkan ulang state.
     */
    public function test_database_pengujian_boleh_di_wipe(): void
    {
        config(['database.connections.mysql.database' => 'astaloka_testing']);

        $this->fire('db:wipe', ['--force' => true]);

        $this->assertTrue(true, 'db:wipe pada DB pengujian harus diizinkan.');
    }

    /**
     * Menyebut target lewat `--database` membuat perintah itu
     * terikat ke database yang disebutkannya, jadi tidak perlu diperiksa lagi.
     */
    public function test_database_eksplisit_tetap_boleh(): void
    {
        config(['database.connections.mysql.database' => 'astaloka_lokasi']);

        $this->fire('migrate:fresh', ['--force' => true, '--database' => 'central']);

        $this->assertTrue(true, 'Migrasi dengan --database eksplisit harus boleh.');
    }

    /**
     * `--database=mysql` TIDAK boleh jadi jalan pintas melewati guard.
     *
     * Ini celah yang nyata, bukan ketakutan teoretis.
     *
     * `--database=mysql` menunjuk NAMA KONEKSI, dan koneksi `mysql` itulah
     * yang menyimpan `DB_DATABASE` dari `.env` -- yaitu `astaloka_lokasi`,
     * database operasional yang berisi data nyata. Jadi perintah itu
     * menjatuhkan seluruh isi database lokasi.
     *
     * Dulu flag `--database` apa pun langsung berarti "periakan", sehingga
     * perintah ini lolos tanpa diperiksa sama sekali.
     *
     * Yang diuji di sini adalah penolakanNYA, jadi tidak ada tabel yang
     * benar-benar dihapus.
     */
    public function test_database_koneksi_mysql_yang_operasional_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $original = config('database.connections.mysql.database');

        config(['database.connections.mysql.database' => 'astaloka_lokasi']);

        try {
            $this->fire('migrate:fresh', ['--force' => true, '--database' => 'mysql']);
        } finally {
            config(['database.connections.mysql.database' => $original]);
        }
    }

    /**
     * Nama database mentah juga harus diperiksa.
     *
     * `--database=astaloka_lokasi` tidak berupa nama koneksi yang dikenal,
     * jadi Laravel memperlakukannya sebagai nama database MySQL apa adanya.
     * Nama itu tidak mengandung `testing`, jadi harus ditolak.
     */
    public function test_nama_database_mentah_operasional_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fire('migrate:fresh', ['--force' => true, '--database' => 'astaloka_lokasi']);
    }

    /**
     * Database pengujian tetap boleh dijatuhkan lewat `--database` pun.
     */
    public function test_database_pengujian_boleh_ditambah_flag_database_eksplisit(): void
    {
        config(['database.connections.mysql.database' => 'astaloka_testing']);

        $this->fire('migrate:fresh', ['--force' => true, '--database' => 'mysql']);

        $this->assertTrue(true, 'Database pengujian tetap boleh di-wipe.');
    }

    /**
     * `migrate:rollback` menjalankan method `down()` -- yang di repo ini
     * men-drop tabel dan kolom.
     *
     * `migrate:rollback --database=central` sudah terbukti mau menghapus
     * `cache`, `cache_locks`, `tenant_invoice_payments`, `central_accounts`,
     * `tenant_invoice_items`, dan kolom branding dari `tenants`. Semuanya
     * milik produksi, dan perintahnya tidak butuh interaksi apa pun.
     *
     * Perintah ini TIDAK ada di daftar `DESTRUCTIVE` versi lama -- jadi
     * guard membiarkan lewat, dan tabel pusat bisa dijatuhkan hanya dengan
     * satu perintah yang kelihatan biasa.
     */
    public function test_migrate_rollback_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('migrate:rollback', ['--force' => true]);
    }

    /**
     * `migrate:rollback` pada database pusat juga ditolak.
     *
     * Yang diuji di sini adalah arah yang berbeda: `--database=central`
     * menunjuk database pusat (`astaloka`), bukan database lokasi. Keduanya
     * berisi data nyata, jadi keduanya harus ditolak.
     */
    public function test_migrate_rollback_ke_database_pusat_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $original = config('database.connections.central.database');

        config(['database.connections.central.database' => 'astaloka']);

        try {
            $this->fire('migrate:rollback', ['--force' => true, '--database' => 'central']);
        } finally {
            config(['database.connections.central.database' => $original]);
        }
    }

    /**
     * `migrate:rollback` pada database pengujian tetap boleh.
     *
     * Rollback dipakai untuk menyiapkan ulang database uji, jadi database
     * yang namanya mengandung `testing` harus tetap bisa di-rollback.
     */
    public function test_migrate_rollback_di_database_pengujian_boleh(): void
    {
        config(['database.connections.mysql.database' => 'astaloka_testing']);

        $this->fire('migrate:rollback', ['--force' => true]);

        $this->assertTrue(true, 'Rollback pada DB pengujian harus diizinkan.');
    }

    /**
     * `migrate` biasa dengan `--force` ke database OPERASIONAL tetap
     * ditolak.
     *
     * Aturan ini ada sejak awal: `migrate` di produksi tanpa `--force`
     * hanya menampilkan konfirmasi dan berhenti, jadi `--force` itulah yang
     * membuatnya benar-benar menulis ke database nyata.
     *
     * Yang diuji adalah jalur yang paling mungkin dilalui tanpa sadar:
     * `TenantManager` memanggil `migrate --database=tenant --force` untuk
     * menyiapkan lokasi, dan `MigrateCentral` memanggil
     * `migrate --database=central --force` untuk menyiapkan pusat. Keduanya
     * harus tetap BOLEH -- dan keduanya punya pengecualian tersendiri.
     *
     * Tapi `migrate --database=mysql --force` -- yaitu koneksi default dari
     * `.env`, yaitu `astaloka_lokasi` -- harus ditolak.
     */
    public function test_migrate_biasa_dengan_force_ke_database_operasional_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $original = config('database.connections.mysql.database');

        config(['database.connections.mysql.database' => 'astaloka_lokasi']);

        try {
            $this->fire('migrate', ['--force' => true]);
            $this->fire('migrate', ['--force' => true, '--database' => 'mysql']);
        } finally {
            config(['database.connections.mysql.database' => $original]);
        }
    }

    /**
     * Jalur provisioning internal tetap boleh.
     *
     * `TenantManager::migrateTenantDatabase()` dan `MigrateCentral` memanggil
     * `migrate` dengan `--database=tenant` / `--database=central` pada
     * database yang namanya TIDAK mengandung `testing` -- karena memang
     * itulah database lokasi dan database pusat yang nyata.
     *
     * Kalau keduanya ditolak, lokasi baru tidak pernah bisa disiapkan dan
     * deploy tidak pernah bisa menyiapkan skema pusat.
     */
    public function test_jalur_provisioning_internal_boleh(): void
    {
        $originalMysql = config('database.connections.mysql.database');
        $originalTenant = config('database.connections.tenant.database');
        $originalCentral = config('database.connections.central.database');

        config([
            'database.connections.mysql.database' => 'astaloka_lokasi',
            'database.connections.tenant.database' => 'astaloka_lokasi',
            'database.connections.central.database' => 'astaloka',
        ]);

        try {
            $this->fire('migrate', ['--force' => true, '--database' => 'tenant']);
            $this->fire('migrate', ['--force' => true, '--database' => 'central']);
        } finally {
            config([
                'database.connections.mysql.database' => $originalMysql,
                'database.connections.tenant.database' => $originalTenant,
                'database.connections.central.database' => $originalCentral,
            ]);
        }

        $this->assertTrue(true, 'Provisioning internal harus boleh lewat.');
    }

    /**
     * Pengecualian provisioning TIDAK berlaku untuk perintah destruktif.
     *
     * `migrate:fresh --database=central` menjatuhkan seluruh tabel pusat.
     * Nama koneksinya `central` memang salah satu yang dipakai provisioning,
     * tapi perintahnya menghapus segalanya -- jadi harus tetap ditolak.
     *
     * Kalau pengecualiannya longgar, `migrate:fresh --database=tenant` juga
     * akan lolos dan menjatuhkan seluruh isi database lokasi.
     */
    public function test_perintah_destructive_tidak_dibebaskan_oleh_koneksi_provisioning(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $original = config('database.connections.central.database');

        config(['database.connections.central.database' => 'astaloka']);

        try {
            $this->fire('migrate:fresh', ['--force' => true, '--database' => 'central']);
        } finally {
            config(['database.connections.central.database' => $original]);
        }
    }

    /**
     * Guard wajib terdaftar di jalur test, bukan hanya di terminal.
     */
    public function test_guard_terdaftar_di_jalur_test(): void
    {
        $listeners = Event::getRawListeners()[CommandStarting::class] ?? [];

        $registered = collect($listeners)->contains(function ($listener) {
            $class = is_array($listener) ? $listener[0] : $listener;

            return is_string($class) && str_contains($class, GuardDestructiveCommands::class);
        });

        $this->assertTrue(
            $registered,
            'GuardDestructiveCommands harus terdaftar pada event CommandStarting di jalur test.'
        );
    }

    /**
     * Penjaga terakhir: kalau test suite sampai salah menyasar database
     * operasional, gagal di sini lebih baik daripada data hilang diam-diam.
     */
    public function test_database_default_adalah_database_pengujian(): void
    {
        $this->assertStringContainsString(
            'testing',
            strtolower(DB::connection()->getDatabaseName()),
            'Test harus selalu berjalan pada database pengujian.'
        );
    }
}
