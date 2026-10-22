<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hapus booking migrasi pusat yang bocor ke database lokasi.
 *
 * Kapan perlu dijalankan: kalau folder migrasi pusat pernah dipindai
 * bersama database lokasi. Perintah ini hanya menghapus CATATAN, tidak
 * menyentuh tabel dan tidak menyentuh data -- jadi aman dijalankan pada
 * database yang berisi data operasional, karena isinya murni bookkeeping.
 *
 * Yang dipulihkan CATATAN saja, bukan tabelnya. Tabelnya memang tidak
 * pernah salah tempat: migrasi pusat memakai `Schema::connection('central')`,
 * jadi selalu membuat tabelnya di pusat. Yang bocor hanya baris "sudah
 * jalankan" di tabel `migrations` lokasi -- dan baris itulah yang membuat
 * provisioning berikutnya melompati migrasi yang tidak pernah jalan.
 *
 * Aman dijalankan berulang: baris yang dihapus hanya yang namanya cocok
 * dengan pola migrasi pusat, dan jumlahnya selalu dicetak supaya bisa
 * dibandingkan dengan `migrate:status`.
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
        $centralMigrations = collect(\glob(base_path(
            (string) config('tenancy.central_migrations_path', 'database/migrations_central').'/*.php'
        )))
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
            $this->line('Jalankan `php artisan tenancy:migrate-central` untuk memastikan database pusat lengkap.');
        }

        return self::SUCCESS;
    }
}
