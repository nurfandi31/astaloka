<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Jalankan migrasi DATABASE PUSAT.
 *
 * Perintah ini membungkus satu baris yang mudah salah ketik:
 *
 *     php artisan migrate --database=central --path=database/migrations/central
 *
 * Kenapa `--database=central` itu WAJIB, bukan sekadar rapi:
 *
 * `migrate` membuat tabelnya di database yang dipilih `--database`, karena
 * tiap migrasi pusat memanggil `Schema::connection('central')`. TAPI catatan
 * "sudah jalan" ditulis ke tabel `migrations` pada koneksi yang SEDANG AKTIF
 * -- yaitu `database.default`. Kalau `--database` dihapus, tabelnya benar
 * dibuat di database pusat, sementara catatannya diam-diam masuk ke tabel
 * `migrations` database lokasi.
 *
 * Akibatnya lokasi itu mewarisi lima baris "sudah jalan" untuk migrasi yang
 * tidak pernah jalan di sana. Provisioning berikutnya tidak akan pernah
 * menjalankannya, dan tidak ada pesan error apa pun yang mengarahkan ke
 * penyebabnya.
 *
 * Folder `migrations/central/` sengaja TIDAK didaftarkan lewat
 * `loadMigrationsFrom()` di AppServiceProvider, supaya `php artisan migrate`
 * biasa tidak bisa jatuh ke kesalahan yang sama. Penjelasan lengkapnya ada
 * di sana.
 */
class MigrateCentral extends Command
{
    protected $signature = 'tenancy:migrate-central
        {--pretend : Tampilkan SQL tanpa menjalankannya}';

    protected $description = 'Jalankan migrasi database pusat (tenants, user superadmin, invoice, audit)';

    public function handle(): int
    {
        $central = (string) config('tenancy.central_connection', 'central');
        $path = database_path('migrations/central');

        if (! is_dir($path)) {
            $this->error("Folder migrasi pusat tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        if ($this->option('pretend')) {
            $this->line('<fg=cyan>PRATINJAU</> migrasi database pusat:');
        }

        // `--database` eksplisit, bukan Config::set('database.default').
        // Menukar default global mengubah koneksi yang dipakai seluruh
        // proses -- termasuk tempat `migrate` menulis catatan, yang justru
        // menjadi masalah yang ingin dibasmi perintah ini.
        //
        // Nama command `migrate` jadi elemen PERTAMA array, bukan key:
        // `Artisan::call($command, $parameters)` membedakannya dari argumen
        // berdasar posisi, bukan nama parameter.
        Artisan::call('migrate', [
            '--database' => $central,
            '--path' => $path,
            '--force' => true,
        ]);

        $output = trim(Artisan::output());

        if ($output !== '') {
            $this->line($output);
        }

        if ($this->option('pretend')) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Database pusat siap.');

        return self::SUCCESS;
    }
}
