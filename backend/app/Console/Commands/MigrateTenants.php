<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * Jalankan migration LOKASI untuk semua lokasi terdaftar.
 *
 * Kenapa perintah ini ada
 * ======================
 *
 * Migrasi di repo ini terbagi dua, dan keduanya harus jalan saat deploy:
 *
 *   - `database/migrations_central/` -> `tenancy:migrate-central`
 *   - `database/migrations_tenant/`  -> perintah INI
 *
 * Yang kedua tidak pernah ada sebelumnya. Padahal tanpa itu, deploy hanya
 * memperbarui database PUSAT, dan seluruh lokasi -- yang datanya ada di
 * database masing-masing -- tetap memakai skema versi lama.
 *
 * Gejalanya muncul lama setelah deploy, dan tidak ada yang menyinggung
 * penyebabnya: satu kolom baru dipakai di panel lokasi, dan query-nya
 * gagal dengan "Unknown column 'x' in 'field list'". Log server menunjuk
 * ke controller, sementara penyebabnya -- migrasi yang tidak pernah
 * dijalankan -- ada di jalur yang tidak pernah dieksekusi.
 *
 * Kenapa tidak pakai `php artisan migrate` biasa
 * ===============================================
 *
 * Folder default Migrator, `database/migrations/`, sengaja dikosongkan --
 * skema dipisah ke dua folder lain (lihat `config/tenancy.php`). Perintah
 * biasa keluar dengan status SUKSES sambil mencetak "Nothing to migrate",
 * tanpa membuat satu tabel pun.
 *
 * Dan `migrate` biasa juga menyasar `database.default`, yaitu database
 * LOKASI yang sedang aktif -- bukan daftar semua lokasi. Sebaliknya,
 * `tenancy:migrate-central` punya masalah kebalikan: `--database` eksplisit
 * membuat catatan migrasi ditulis ke koneksi yang sedang aktif.
 *
 * Yang dipakai di sini adalah `TenantManager::migrateTenantDatabase()`,
 * yang sudah benar: ia menjalankan `migrate` dengan `--path` ke folder
 * migrasi lokasi dan `--database` ke koneksi tenant, di dalam
 * `runFor()` -- jadi `database.default` Dialihkan ke database lokasi yang
 * sedang dimigrasi dan dikembalikan lagi setelah selesai.
 *
 * Perbedaan penting: `--database` di Artisan::call() berarti catatan
 * migrasi ditulis ke tabel `migrations` di koneksi itu juga, bukan ke
 * koneksi default. Itu yang membuat tiap lokasi punya catatan migrasi
 * sendiri, dan bukan satu daftar global yang dipakai bersama.
 *
 * Kegagalan satu lokasi TIDAK menghentikan lokasi lain
 * ====================================================
 *
 * Sama seperti `tenancy:reseed-master`: satu lokasi yang database-nya
 * bermasalah tidak boleh membuat lokasi lain ikut gagal. Kalau deploy
 * berhenti di lokasi pertama yang bermasalah, separuh lokasi tidak pernah
 * mendapat migrasi -- dan tidak ada yang tahu mana yang sudah dan mana
 * yang belum.
 *
 * Karena itu setiap lokasi dicoba, hasilnya dicetak per lokasi, dan
 * perintah berhenti dengan status gagal kalau ada YANG GAGAL -- supaya
 * deploy terlihat merah, bukan hijau dengan lokasi tertinggal.
 */
class MigrateTenants extends Command
{
    protected $signature = 'tenancy:migrate-tenants
        {tenant? : Slug lokasi. Kosongkan untuk semua lokasi terdaftar.}
        {--dry-run : Tampilkan rencana tanpa menjalankan migrasi}';

    protected $description = 'Jalankan migrasi database lokasi untuk semua lokasi terdaftar';

    public function handle(TenantManager $manager): int
    {
        $slug = $this->argument('tenant');
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Hanya lokasi AKTIF yang dimigrasi.
         *
         * Lokasi non-aktif tidak dilayani -- tidak ada request yang sampai
         * ke sana -- jadi tidak ada yang perlu diperbarui. Dan decryptnya
         * hanya menambah pekerjaan: database lokasi yang sudah tidak
         * dipakai bisa saja sudah dihapus, dan itu bukan kegagalan.
         *
         * `runForEachActive()` (yang dipakai scheduler) memakai aturan yang
         * sama, jadi keduanya tidak bisa berbeda pendapat soal "lokasi mana
         * yang sedang hidup".
         */
        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('is_active', true)->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            /*
             * Dua kasus yang kelihatannya sama, tapi dampaknya BERBEDA.
             *
             * Ditentukan secara eksplisit, bukan ditumpukan jadi satu
             * "tidak ada lokasi" -- karena status keluar yang berbeda.
             */
            if ($slug) {
                // Operator menebak slug secara spesifik -- jadi tidak
                // ketemu itu salah ketik, dan itu harus terlihat.
                $this->error("Lokasi `{$slug}` tidak terdaftar.");

                return self::FAILURE;
            }

            /*
             * Tidak ada lokasi AKTIF -- dan itu bukan kegagalan.
             *
             * Install baru memang belum punya lokasi, dan deploynya harus
             * tetap bisa jalan. Kalau perintah ini keluar dengan status
             * gagal, seluruh deploy berhenti DI SINI -- container belum
             * dibangun, frontend belum di-deploy, dan tidak ada satu pun
             * yang menyalin alasan sebenarnya ke mana pun.
             *
             * Perbandingan dengan `migrate`: "Nothing to migrate" keluar
             * dengan status SUKSES. "Tidak ada yang perlu dimigrasi" adalah
             * jawaban yang benar, bukan error.
             *
             * Yang tetap GAGAL: lokasi AKTIF yang database-nya hilang
             * (lihat di bawah). Itu tidak boleh lolos, karena lokasi aktif
             * berarti ada request yang dilayaninya.
             */
            $this->info('Tidak ada lokasi aktif terdaftar -- tidak ada yang perlu dimigrasi.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('<fg=yellow>DRY RUN</> -- tidak ada yang dijalankan.');
        }

        $gagal = 0;
        $sukses = 0;

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->line("<fg=cyan>──</> {$tenant->slug} <fg=gray>({$tenant->database})</>");

            /*
             * Lokasi AKTIF yang database-nya belum ada adalah KEGAGALAN,
             * bukan hal yang boleh dilewati.
             *
             * Registry pusat dan database lokasi ought to sinkron: lokasi
             * aktif berarti ada request yang dilayaninya, dan request itu
             * butuh database. Kalau database-nya tidak ada, lokasinya
             * praktis mati -- dan deploy yang melintasinya tanpa
             * complaining akan terlihat BERHASIL padahal ada lokasi yang
             * tidak bisa dipakai.
             *
             * Dilewati dengan diam-diam, hal itu persis yang terjadi
             * sekarang: `migrate` biasa keluar dengan status sukses
             * sambil mencetak "Nothing to migrate", dan tidak ada satu pun
             * tanda bahwa ada lokasi yang tidak pernah disentuh.
             */
            if (! $tenant->databaseExists()) {
                $this->error('   database lokasi tidak ada -- lokasi ini tidak bisa dilayani.');
                $gagal++;

                continue;
            }

            if ($dryRun) {
                $this->line('   akan menjalankan migrasi lokasi');

                continue;
            }

            try {
                $manager->migrateTenantDatabase($tenant);

                $this->line('   <fg=green>migrasi selesai.</>');
                $sukses++;
            } catch (Throwable $e) {
                $this->error('   GAGAL: '.$e->getMessage());
                $gagal++;
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info(count($tenants).' lokasi akan dimigrasi. Tidak ada yang dijalankan.');

            return self::SUCCESS;
        }

        if ($gagal > 0) {
            $this->error("{$gagal} lokasi gagal dimigrasi.");
            $this->error('Lokasi yang gagal TIDAK ikut ter-migrasi di percobaan berikutnya.');
            $this->error('Perbaiki penyebabnya, lalu jalankan ulang perintah ini.');

            return self::FAILURE;
        }

        $this->info("{$sukses} lokasi dimigrasi.");

        return self::SUCCESS;
    }
}
