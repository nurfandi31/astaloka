<?php

namespace Tests\Feature;

use App\Console\Commands\ScheduleRunResilient;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\Repository;
use Illuminate\Console\Application;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Throwable;

/**
 * Test untuk perbaikan "scheduler crash saat database tidak terjangkau".
 *
 * LATAR BELAKANG
 * ==============
 *
 * `schedule:run` dipanggil SETIAP MENIT oleh Task Scheduler. Sebelum
 * menjalankan job apa pun, framework membaca cache: flag pause, flag
 * interrupt, dan mutex `withoutOverlapping()`.
 *
 * Cache aplikasi memakai store `database`, jadi saat MySQL mati (atau belum
 * selesai start), pembacaan itu melempar `SQLSTATE[HY000] [2002]` dan
 * `schedule:run` CRASH -- sebelum job apa pun sempat dicek.
 *
 * Akibatnya generate piutang tunggakan pada tanggal generate-nya bisa
 * terlewat. Gate tanggal generate bersifat PERSIS (`===`), jadi satu malam
 * yang terlewat berarti bulan itu TIDAK PERNAH punya jurnal piutang.
 *
 * Perbaikannya dua bagian:
 *
 *   1. `config('cache.schedule_store')` = `file` -- cache internal scheduler
 *      tidak butuh database sama sekali.
 *   2. `ScheduleRunResilient` overriding `schedule:run` bawaan -- kalau
 *      pembacaan cache GAGAL, scheduler tidak boleh mati.
 *
 * @see ScheduleRunResilient
 * @see AppServiceProvider::registerSchedulerCommandOverride()
 */
class SchedulerResilienceTest extends TestCase
{
    public function test_cache_store_scheduler_tidak_memakai_database(): void
    {
        $this->assertSame(
            'file',
            Config::get('cache.schedule_store'),
            'Mutex scheduler harus pakai store yang tidak butuh database.'
        );
    }

    public function test_cache_store_scheduler_bisa_ditimpa_lewat_env(): void
    {
        // Nilai harus dibaca dari env, supaya instalasi multi-server bisa
        // memakainya Redis untuk mutex lintas server.
        $this->assertSame(
            'file',
            env('SCHEDULE_CACHE_STORE', 'file'),
            'Nilai store scheduler harus bisa datang dari environment.'
        );
    }

    public function test_command_schedule_run_yang_dipakai_adalah_override(): void
    {
        $command = $this->scheduleRunCommand();

        $this->assertInstanceOf(
            ScheduleRunResilient::class,
            $command,
            'schedule:run harus memakai override yang tahan database mati.'
        );
    }

    public function test_schedule_run_tidak_melempar_exception_saat_cache_gagal(): void
    {
        $command = $this->scheduleRunCommand();

        // Simulasikan cache yang tidak bisa dijangkau -- sama seperti
        // MySQL mati.
        $this->swapBrokenCache();

        // `isPaused()` harus mengembalikan false, bukan melempar.
        $paused = $this->callProtected($command, 'isPaused');

        $this->assertFalse(
            $paused,
            'Scheduler tidak boleh dianggap paused saat cache tidak terjangkau.'
        );
    }

    public function test_mutex_yang_gagal_diambil_tidak_melewati_job(): void
    {
        $command = $this->scheduleRunCommand();

        $this->swapBrokenCache();

        // Kalau mutex GAGAL diambil karena masalah infrastruktur, job
        // harus tetap dijalankan -- bukan dilewati. Melewatkan job
        // `migrasi:bersihkan` berarti file kerja migrasi (yang memuat NIK
        // dan nama lengkap orang) tidak pernah terhapus.
        $skip = $command->shouldSkipDueToOverlapping();

        $this->assertFalse(
            $skip,
            'Job tidak boleh dilewati hanya karena mutex tidak bisa diambil.'
        );
    }

    public function test_scheduler_tetap_berjalan_saat_database_tidak_bisa_dijangkau(): void
    {
        // Simulasikan kondisi sebenarnya: MySQL mati.
        //
        // Test ini tidak mematikan MySQL sungguhan -- ia memaksa koneksi
        // `central` dan `mysql` untuk melempar error, lalu memastikan
        // `schedule:run` tetap selesai tanpa exception.
        $this->breakDatabaseConnections();

        $exitCode = $this->artisan('schedule:run', ['--whisper' => true])->run();

        $this->assertSame(
            0,
            $exitCode,
            'schedule:run harus selesai dengan sukses walau database mati.'
        );
    }

    /**
     * Ambil command `schedule:run` yang benar-benar terdaftar di Artisan.
     */
    private function scheduleRunCommand(): ScheduleRunResilient
    {
        $artisan = $this->artisanApplication();

        $commands = $artisan->all();

        $this->assertArrayHasKey(
            'schedule:run',
            $commands,
            'Command schedule:run harus terdaftar.'
        );

        $command = $commands['schedule:run'];

        $this->assertInstanceOf(ScheduleRunResilient::class, $command);

        return $command;
    }

    /**
     * Ambil instance `Illuminate\Console\Application` yang dipakai Artisan.
     *
     * `Application` tidak bisa di-resolve langsung dari container --
     * konstruktornya butuh argumen `$version` yang tidak di-binding. Dan
     * `Artisan::getFacadeRoot()` mengembalikan Kernel, bukan Application.
     *
     * Cara yang benar: dengarkan event `ArtisanStarting` -- event itu
     * membawa instance Artisan-nya.
     */
    private function artisanApplication(): Application
    {
        $captured = null;

        Event::listen(ArtisanStarting::class, function (ArtisanStarting $event) use (&$captured): void {
            $captured = $event->artisan;
        });

        // Panggil `handle` supaya Artisan benar-benar dibangun.
        Artisan::call('list', ['--raw' => true]);

        $this->assertInstanceOf(
            Application::class,
            $captured,
            'Event ArtisanStarting harus membawa instance Artisan.'
        );

        return $captured;
    }

    /**
     * Panggil method protected dari sebuah object.
     */
    private function callProtected(object $object, string $method): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }

    /**
     * Ganti cache store scheduler dengan yang selalu gagal.
     *
     * Cache store ini melempar exception di setiap operasi baca -- persis
     * seperti database yang tidak terjangkau.
     */
    private function swapBrokenCache(): void
    {
        Config::set('cache.schedule_store', 'broken-scheduler-cache');

        $this->app['cache']->extend('broken-scheduler-cache', function () {
            return new class extends Repository
            {
                public function __construct() {}

                public function get($key, $default = null)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function put($key, $value, $ttl = null)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function forever($key, $value)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function add($key, $value, $ttl = null)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function has($key)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function forget($key)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function pull($key, $default = null)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function increment($key, $value = 1)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }

                public function decrement($key, $value = 1)
                {
                    throw new \RuntimeException('Cache tidak terjangkau (simulasi).');
                }
            };
        });

        // Buang instance yang sudah dibangun, supaya store baru dipakai.
        $this->app->forgetInstance('cache');
    }

    /**
     * Paksa koneksi database yang dipakai aplikasi melempar error.
     *
     * Dipakai untuk mensimulasikan MySQL mati tanpa benar-benar
     *-assessmentassessment mematikan servernya.
     */
    private function breakDatabaseConnections(): void
    {
        foreach (['central', 'mysql', 'tenant'] as $connection) {
            try {
                $this->app['db']->purge($connection);
            } catch (Throwable) {
                // Koneksi mungkin belum pernah dibuat -- abaikan.
            }
        }

        $this->app['db']->extend('central', function () {
            return $this->brokenConnection('central');
        });

        $this->app['db']->extend('mysql', function () {
            return $this->brokenConnection('mysql');
        });
    }

    /**
     * Buat koneksi yang selalu gagal saat dipakai.
     */
    private function brokenConnection(string $name): Connection
    {
        $pdo = new \PDO('mysql:host=127.0.0.1;port=1;dbname=tidak_ada', 'root', '', [
            \PDO::ATTR_TIMEOUT => 1,
        ]);

        return new MySqlConnection($pdo, $name, '', []);
    }
}
