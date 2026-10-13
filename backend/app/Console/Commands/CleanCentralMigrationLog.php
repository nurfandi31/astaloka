<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hapus booking migrasi pusat yang bocor ke database lokasi.
 *
 * Kapan perlu dijalankan: hanya kalau folder `database/migrations/central/`
 * pernah didaftarkan lewat `loadMigrationsFrom()`. Perintah itu sudah dihapus dari `AppServiceProvider`, tapi
 * database yang pernah terdampak masih menyimpan baris "sudah jalan" untuk
 * migrasi yang tidak pernah jalan di sana.
 *
 * Akibatnya kalau nanti `tenants` atau `audit_logs` perlu ada di database
 * lokasi (mis. saat menambah fitur), `php artisan migrate` akan
 * melewatkannya tanpa pesan apa pun -- karena catatannya sudah ada.
 *
 * Perintah ini hanya menghapus CATATAN. Tidak menyentuh tabel, tidak
 * menyentuh data. Aman dijalankan pada database yang berisi data
 * operasional, karena isinya murni bookkeeping.
 *
 * Baris yang dihapus hanya yang namanya cocok dengan pola migrasi pusat
 * (`YYYY_MM_DD_*` di folder central). Baris lain -- termasuk 66 migrasi
 * lokasi yang sudah berjalan -- tidak tersentuh, dan jumlah yang
 * terhapus selalu dicetak supaya bisa dibandingkan dengan `migrate:status`.
 */
class CleanCentralMigrationLog extends Command
{
    protected $signature = 'tenancy:clean-migration-log
        {tenant? : Slug lokasi. Kosongkan untuk semua lokasi terdaftar.}
        {--dry-run : Tampilkan apa yang akan dihapus tanpa menghapusnya}';

    protected $description = 'Hapus catatan migrasi pusat yang bocor ke migrations database lokasi';

    public function handle(TenantManager $manager): int
    {
        $slug = $this->argument('tenant');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error($slug ? "Lokasi `{$slug}` tidak terdaftar." : 'Tidak ada lokasi terdaftar.');

            return self::FAILURE;
        }

        // Nama file migrasi pusat, diambil dari foldernya langsung --
        // bukan daftar yang ditulis manual di sini, supaya perintah ini
        // tidak pernah usang kalau migrasi pusat baru ditambahkan.
        $centralMigrations = collect(\glob(database_path('migrations/central/*.php')))
            ->map(fn (string $f) => basename($f, '.php'))
            ->all();

        if ($centralMigrations === []) {
            $this->error('Tidak ada migrasi pusat ditemukan.');

            return self::FAILURE;
        }

        $this->line('Migrasi pusat yang dicari: '.count($centralMigrations).' file.');
        $this->newLine();

        $totalTerhapus = 0;

        foreach ($tenants as $tenant) {
            if (! $tenant->databaseExists()) {
                $this->line("<fg=yellow>—</> {$tenant->slug}: database tidak ada, dilewati.");

                continue;
            }

            $manager->runFor($tenant, function () use ($tenant, $centralMigrations, &$totalTerhapus) {
                $migrations = DB::connection(TenantManager::CONNECTION)->table('migrations');

                $booking = $migrations
                    ->whereIn('migration', $centralMigrations)
                    ->pluck('migration')
                    ->all();

                if ($booking === []) {
                    $this->line("<fg=green>—</> {$tenant->slug}: bersih.");

                    return;
                }

                foreach ($booking as $nama) {
                    $this->line("   {$tenant->slug}: ditemukan `{$nama}`");
                }

                if ($this->option('dry-run')) {
                    $this->line("   <fg=yellow>dry-run</>: {$tenant->slug} punya ".count($booking).' baris untuk dihapus.');
                    $totalTerhapus += count($booking);

                    return;
                }

                $deleted = DB::connection(TenantManager::CONNECTION)
                    ->table('migrations')
                    ->whereIn('migration', $centralMigrations)
                    ->delete();

                $this->line("   <fg=green>✓</> {$tenant->slug}: {$deleted} baris dihapus.");
                $totalTerhapus += $deleted;
            });
        }

        $this->newLine();

        if ($totalTerhapus === 0) {
            $this->info('Tidak ada booking yang perlu dibersihkan.');

            return self::SUCCESS;
        }

        $this->info("Total {$totalTerhapus} baris booking dibersihkan.");

        if (! $this->option('dry-run')) {
            $this->line('Jalankan `php artisan migrate --database='
                .config('tenancy.central_connection').' --path=database/migrations/central` untuk memastikan database pusat lengkap.');
        }

        return self::SUCCESS;
    }
}
