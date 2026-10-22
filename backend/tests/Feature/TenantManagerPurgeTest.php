<?php

namespace Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Menguji bahwa `TenantManager::deactivate()` tidak memutus transaksi yang
 * sedang berjalan.
 *
 * Latar belakang: `purge()` menutup PDO. Kalau koneksi itu sedang dipakai
 * transaksi, transaksi tidak ikut rollback dan MySQL menahannya sebagai
 * transaksi terlantar yang memegang metadata lock -- sehingga test berikutnya
 * yang menjalankan `drop table` menggantung tanpa batas.
 */
class TenantManagerPurgeTest extends TestCase
{
    #[
        \PHPUnit\Framework\Attributes\Test
    ]
    public function transaction_tetap_hidup_setelah_deactivate(): void
    {
        $manager = app(\App\Services\TenantManager::class);

        $mysql = DB::connection('mysql');
        $mysql->beginTransaction();

        try {
            $manager->deactivate();

            $this->assertSame(
                1,
                $mysql->transactionLevel(),
                'deactivate() tidak boleh memutus transaksi yang sedang berjalan.'
            );
        } finally {
            if ($mysql->transactionLevel() > 0) {
                $mysql->rollBack();
            }

            DB::disconnect('mysql');
        }
    }

    #[
        \PHPUnit\Framework\Attributes\Test
    ]
    public function deactivate_menyalakan_default_ke_pusat(): void
    {
        $before = config('database.default');

        app(\App\Services\TenantManager::class)->deactivate();

        $this->assertSame(
            (string) config('tenancy.central_connection', 'central'),
            config('database.default'),
            'Setelah deactivate, default harus menunjuk database pusat.'
        );

        config(['database.default' => $before]);
    }
}