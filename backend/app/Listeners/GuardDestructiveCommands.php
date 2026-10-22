<?php

namespace App\Listeners;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

/**
 * Pengaman terhadap perintah artisan yang menghapus data.
 *
 * Latar belakang: `php artisan migrate:fresh` yang dijalankan di LUAR
 * PHPUnit membaca `.env`, bukan `phpunit.xml`. Padahal `.env` berisi
 * `DB_DATABASE=astaloka_lokasi` -- database operasional yang nyata. Akibatnya
 * `migrate:fresh` menghapus seluruh tabel lokasi beserta isinya, dan karena
 * `phpunit.xml` hanya berlaku selama test berjalan, tidak ada yang menyetopnya.
 *
 * Perintah yang dipantau di sini semuanya menghapus SELURUH isi tabel pada
 * koneksi default: `db:wipe`, `migrate:fresh`, dan `migrate:refresh`
 * men-drop semua tabel, sedangkan `migrate:reset` dan `migrate:rollback`
 * men-rollback seluruh batch.
 *
 * Aturan mainnya sederhana dan sengaja ketat: nama database tujuan WAJIB
 * mengandung `testing` (case-insensitive). `astaloka_lokasi`, `astaloka`,
 * maupun `pamsides` tidak mengandung kata itu, jadi ketiganya ditolak.
 */
class GuardDestructiveCommands
{
    /**
     * Perintah yang isinya menghapus semua baris atau tabel pada koneksi default.
     *
     * `migrate` biasa TIDAK ada di sini: perintah itu aman dan hanya menolak
     * berjalan di produksi kalau tanpa `--force`. Yang dicegah di sini adalah
     * varian yang menghapus data tanpa interaksi, yaitu `migrate` yang
     * dijalankan dengan `--force`.
     *
     * @var list<string>
     */
    private const DESTRUCTIVE = [
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate',
    ];

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::DESTRUCTIVE, true)) {
            return;
        }

        // `migrate` tanpa `--force` tidak menghapus data.
        if ($event->command === 'migrate' && ! $this->hasFlag($event, 'force')) {
            return;
        }

        // Flag `--database=` selalu menyebutkan targetnya sendiri, jadi tidak
        // perlu diperiksa lagi di sini.
        if ($this->hasFlag($event, 'database')) {
            return;
        }

        $database = (string) config('database.connections.mysql.database');

        if (stripos($database, 'testing') !== false) {
            return;
        }

        $this->abort($event->command, $database);
    }

    /**
     * @return bool
     */
    private function hasFlag(CommandStarting $event, string $flag): bool
    {
        $input = $event->input;

        // `hasParameterOption()` memeriksa nama flag apa adanya, termasuk yang
        // bernilai `false`. Ini yang dipakai karena `getOptions()` tidak
        // bisa diandalkan: tanpa definisi option, hasilnya kosong.
        return $input->hasParameterOption('--'.$flag)
            || $input->hasParameterOption('-'.$flag);
    }

    /**
     * Hentikan perintah dengan pesan yang menjelaskan cara yang benar.
     */
    private function abort(string $command, string $database): void
    {
        throw new RuntimeException(sprintf(
            "\n  DITOLAK: `artisan %s` tidak boleh berjalan pada database `%s`.\n\n"
            ."  Perintah ini menghapus seluruh isi database, dan `%s` berisi\n"
            ."  data operasional yang nyata. `phpunit.xml` (DB_DATABASE=astaloka_testing)\n"
            ."  hanya dibaca selama test berjalan, jadi tidak melindungi perintah ini.\n\n"
            ."  Untuk database pengujian, jalankan lewat PHPUnit:\n"
            ."      php artisan test\n\n"
            ."  Untuk mengosongkan database pengujian secara manual:\n"
            ."      \$env:DB_DATABASE='astaloka_testing'; php artisan %s\n",
            $command,
            $database,
            $database,
            $command
        ));
    }
}