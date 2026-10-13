<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Jalankan ulang migration + seeder master untuk satu atau semua lokasi.
 *
 * Perintah ini menjawab satu kebutuhan nyata: lokasi yang sudah terlanjur
 * ada di daftar -- dibuat sebelum daftar seeder provision diperbaiki, atau
 * dibuat dengan `--skip-migrate`, atau dipulihkan dari backup lama -- bisa
 * punya skema tapi tidak punya master data. Gejalanya: jurnal kosong karena
 * chart of accounts belum ada, form jabatan tanpa pilihan, dropdown jenis
 * transaksi kosong.
 *
 * Perintah-perintah di panel pusat tidak menutup kasus ini. `store()`
 * hanya berjalan saat lokasi BARU didaftarkan; `update()` sengaja tidak
 * menyentuh database lokasi sama sekali, supaya perubahan identitas tidak
 * diam-diam menulis ke data operasional.
 *
 * Karena itu perbaikan master data harus punya jalurnya sendiri, dan
 * harus bisa dijalankan tanpa panel pusat -- server produksi sering tidak
 * punya akses HTTP ke panel.
 *
 * Semua seeder yang dijalankan bersifat idempoten (truncate lalu insert,
 * atau updateOrInsert), jadi perintah ini aman diulang. Yang TIDAK
 * idempoten dan tidak ada di daftar: pelanggan, meter reading, struk,
 * transaksi. Kalau ada yang seperti itu pernah masuk ke daftar `seeders`,
 * perintah ini akan menghapus data operasional lokasi itu tanpa
 * peringatan.
 */
class ReseedMasterData extends Command
{
    protected $signature = 'tenancy:reseed-master
        {tenant? : Slug lokasi. Kosongkan untuk semua lokasi aktif.}
        {--migrate : Jalankan migration yang belum jalan lebih dulu}
        {--identity : Tulis ulang identitas lokasi di tabel settings}
        {--dry-run : Tampilkan rencana tanpa menulis apa pun}';

    protected $description = 'Isi ulang master data (CoA, jabatan, jenis transaksi) di database lokasi';

    public function handle(TenantManager $manager): int
    {
        $slug = $this->argument('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('is_active', true)->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error($slug
                ? "Lokasi `{$slug}` tidak terdaftar."
                : 'Tidak ada lokasi aktif.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line('<fg=yellow>DRY RUN</> -- tidak ada yang akan ditulis.');
            $this->line('Seeder yang akan dijalankan:');

            foreach ((array) config('tenancy.provision.seeders', []) as $seeder) {
                $this->line('  - '.$seeder);
            }
            $this->newLine();
        }

        $gagal = 0;

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->line("<fg=cyan>──</> {$tenant->slug} <fg=gray>({$tenant->database})</>");

            if (! $tenant->databaseExists()) {
                $this->error('   Database tidak ada. Lokasi ini tidak bisa disiapkan.');
                $gagal++;

                continue;
            }

            if ($dryRun) {
                $this->line('   akan menjalankan seeder master');
                $this->line($this->option('migrate')
                    ? '   akan menjalankan migration yang belum jalan'
                    : '   migration tidak dijalankan (--migrate tidak diisi)');
                $this->line($this->option('identity')
                    ? '   akan menulis ulang tabel settings'
                    : '   tabel settings tidak disentuh (--identity tidak diisi)');

                continue;
            }

            try {
                if ($this->option('migrate')) {
                    $this->line('   migration...');
                    $manager->migrateTenantDatabase($tenant);
                }

                $this->line('   seeder master...');
                $manager->seedTenantDatabase($tenant);

                if ($this->option('identity')) {
                    $this->line('   identitas settings...');
                    $manager->applyTenantIdentity($tenant);
                }
            } catch (Throwable $e) {
                $this->error('   GAGAL: '.$e->getMessage());
                $gagal++;

                continue;
            }

            $this->line('   <fg=green>selesai.</>');
        }

        $this->newLine();

        if ($dryRun) {
            $this->info(count($tenants).' lokasi akan disiapkan. Tidak ada yang ditulis.');

            return self::SUCCESS;
        }

        if ($gagal > 0) {
            $this->error("{$gagal} lokasi gagal disiapkan.");

            return self::FAILURE;
        }

        $this->info(count($tenants).' lokasi siap dipakai.');

        return self::SUCCESS;
    }
}
