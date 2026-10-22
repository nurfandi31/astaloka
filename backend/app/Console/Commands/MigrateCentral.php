<?php

namespace App\Console\Commands;

use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Jalankan migrasi DATABASE PUSAT.
 *
 * Perintah ini membungkus satu baris yang mudah salah ketik:
 *
 *     php artisan migrate --database=central --path=database/migrations_central
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
 * Folder migrasi pusat berada DI LUAR `database/migrations/` -- lihat
 * `tenancy.central_migrations_path` di `config/tenancy.php`. Tanpa `--path`,
 * Migrator memindai default-nya sendiri, yaitu `database/migrations/`.
 * Folder itu tidak lagi berisi apa pun, jadi perintahnya keluar dengan
 * status SUKSES sambil mencetak "Nothing to migrate" dan database pusat
 * tetap kosong. Tidak ada error, tidak ada tabel yang dibuat.
 *
 * Folder pusat juga sengaja TIDAK didaftarkan lewat
 * `loadMigrationsFrom()` di AppServiceProvider, supaya `php artisan migrate`
 * biasa tidak pernah bisa menyalin skema lokasi ke pusat. Penjelasan
 * lengkapnya ada di sana.
 */
class MigrateCentral extends Command
{
    protected $signature = 'tenancy:migrate-central
        {--pretend : Tampilkan SQL tanpa menjalankannya}
        {--seed : Jalankan juga seeder pusat (level 1-3 chart of accounts)}';

    protected $description = 'Jalankan migrasi database pusat (tenants, user superadmin, invoice, audit)';

    public function handle(): int
    {
        $central = (string) config('tenancy.central_connection', 'central');

        // Path dibiarkan RELATIF terhadap root aplikasi.
        //
        // `database_path()` mengembalikan path absolut ber-backslash di
        // Windows (`C:\...\database\migrations\central`). Path itu diteruskan
        // apa adanya ke Migrator, yang mencocokkannya dengan nama file dari
        // `glob()` ber-pemisah `/` -- tidak satu pun yang cocok, sehingga semua
        // migrasi dianggap tidak ada dan laporan berbunyi "Nothing to migrate"
        // padahal tabelnya belum pernah dibuat.
        //
        // Gejalanya menipu dan berbahaya: perintah ini keluar dengan status
        // SUKKES sambil mencetak "Database pusat siap", database pusat hanya
        // berisi tabel `migrations` yang kosong, dan tidak ada error apa pun
        // yang menunjuk ke penyebabnya. Perintah yang sama dipanggil manual
        // dengan path relatif langsung berhasil.
        //
        // Bandingkan `TenantManager::migrateTenantDatabase()`, yang memanggil
        // `migrate` tanpa `--path` sama sekali -- pemanggilan itu aman karena
        // Migrator me-resolve path default-nya sendiri lewat
        // `$this->getMigrationPath()`.
        /*
         * Path diambil dari config, dan dipakai apa adanya.
         *
         * Dipisah dari string literal supaya tidak ada lagi salinan: tiga
         * tempat pernah menulis path ini sendiri, dan kelalaian di salah
         * satunya tidak terlihat -- perintahnya tetap keluar dengan status
         * SUKSES sambil mencetak "Nothing to migrate". `MigrateCentral` dan
         * `CleanCentralMigrationLog` sekarang membaca satu nilai yang sama.
         *
         * Bandingkan `TenantManager::migrateTenantDatabase()`, yang memanggil
         * `migrate` dengan `--path` sendiri -- pemanggilan tanpa `--path`
         * tidak lagi aman di sini, karena folder default Migrator sudah
         * tidak berisi apa pun.
         */
        $path = (string) config('tenancy.central_migrations_path', 'database/migrations_central');

        if (! is_dir(base_path($path))) {
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

        // Seeder pusat terpisah dari `migrate`, dan hanya berjalan bila
        // diminta. Level 1-3 chart of accounts adalah data yang tidak berubah
        // dari hari ke hari, jadi rebuild paksa setiap kali migration
        // dijalankan tidak hanya sia-sia -- `truncate` di dalamnya membuat
        // struktur akun kosong persis di saat sedang dipakai.
        if ($this->option('seed')) {
            $this->newLine();
            $this->line('<fg=cyan>Menjalankan seeder pusat (level 1-3 chart of accounts)...</>');

            app(TenantManager::class)->seedCentralDatabase();

            $this->line('      selesai.');
        }

        $this->newLine();
        $this->info('Database pusat siap.');

        return self::SUCCESS;
    }
}
