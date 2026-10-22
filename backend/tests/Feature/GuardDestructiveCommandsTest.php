<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Console\Events\CommandStarting;
use App\Listeners\GuardDestructiveCommands;
use RuntimeException;
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
        $input = new \Symfony\Component\Console\Input\ArrayInput($options);
        $input->setInteractive(false);

        (new GuardDestructiveCommands)->handle(
            new CommandStarting($command, $input, new \Symfony\Component\Console\Output\NullOutput)
        );
    }

    /**
     * Menjalankan guard dalam kondisi database operasional.
     *
     * Saat test, `DB_DATABASE` adalah `astaloka_testing` -- itu memang benar,
     * tapi justru karena itu guard tidak akan menolak apa pun. Supaya yang
     * diuji adalah perilaku penolakan, nama database-nya ditirukan di sini.
     * Tidak ada koneksi yang dibuka, karena listener hanya membaca config.
     *
     * @return void
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

    /**
     * @return void
     */
    public function test_db_wipe_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('db:wipe', ['--force' => true]);
    }

    /**
     * @return void
     */
    public function test_migrate_fresh_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('migrate:fresh', ['--force' => true]);
    }

    /**
     * @return void
     */
    public function test_migrate_refresh_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('migrate:refresh', ['--force' => true]);
    }

    /**
     * @return void
     */
    public function test_migrate_reset_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DITOLAK/');

        $this->fireAgainstProductionName('migrate:reset', ['--force' => true]);
    }

    /**
     * `migrate` biasa tidak menghapus data, jadi tidak boleh diblokir.
     *
     * @return void
     */
    public function test_migrate_tanpa_force_tidak_ditolak(): void
    {
        $this->fire('migrate');

        $this->assertTrue(true, 'migrate tanpa --force harus boleh lewat.');
    }

    /**
     * Perintah yang tidak merusak sama sekali harus sepenuhnya dilewati.
     *
     * @return void
     */
    public function test_perintah_aman_tidak_dipertahankan(): void
    {
        foreach (['migrate:status', 'db:show', 'route:list', 'config:clear'] as $command) {
            $this->fire($command, ['--force' => true]);
        }

        $this->assertTrue(true, 'Perintah aman tidak boleh diblokir.');
    }

    /**
     * Database pengujian tetap boleh di-wipe; itu jalur yang dipakai
     * `php artisan test` untuk menyiapkan ulang state.
     *
     * @return void
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
     *
     * @return void
     */
    public function test_database_eksplisit_tetap_boleh(): void
    {
        config(['database.connections.mysql.database' => 'astaloka_lokasi']);

        $this->fire('migrate:fresh', ['--force' => true, '--database' => 'central']);

        $this->assertTrue(true, 'Migrasi dengan --database eksplisit harus boleh.');
    }

    /**
     * Guard wajib terdaftar di jalur test, bukan hanya di terminal.
     *
     * @return void
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
     *
     * @return void
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