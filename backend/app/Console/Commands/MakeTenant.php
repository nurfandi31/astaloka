<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Daftarkan lokasi baru: buat database, jalankan migration, buat admin.
 *
 * Padanan CLI dari provisioning yang nanti dipanggil halaman admin pusat.
 * Dipisah supaya provisioning bisa diuji di terminal tanpa harus login ke
 * panel pusat.
 *
 * Urutan langkah penting. Database dibuat DULUAN, baru registry, baru
 * migration, baru admin. Registry ditulis sebelum migration karena
 * `migrate()` dijalankan lewat `TenantManager::runFor()`, yang butuh baris
 * tenant untuk tahu database mana yang jadi tujuan.
 *
 * Sebaliknya, baris tenant yang gagal ditulis DIBUANG kalau database tidak
 * sempat terbentuk. Kalau tidak, leftover-nya akan menggantung: setiap request
 * ke lokasi itu gagal dengan error koneksi, tanpa cara memperbaikinya selain
 * edit manual.
 */
class MakeTenant extends Command
{
    protected $signature = 'tenancy:make
        {slug : Identitas lokasi, mis. tirtomulo}
        {--nama= : Nama tampilan}
        {--nama-lembaga= : Nama lembaga resmi (kop laporan)}
        {--domain= : Host/subdomain untuk produksi}
        {--database= : Nama database (default: <prefix><slug>)}
        {--alamat=}
        {--email= : Email kontak lokasi}
        {--telepon=}
        {--admin-email= : Email admin lokasi pertama}
        {--admin-password= : Password admin lokasi}
        {--admin-name= : Nama admin lokasi}
        {--skip-migrate : Buat database saja, tanpa migration/seed}
        {--force : Timpa database yang sudah ada}';

    protected $description = 'Daftarkan lokasi tenant baru (buat database + migration + admin)';

    public function handle(TenantManager $manager): int
    {
        $slug = $this->askSlug();

        if (! $slug) {
            $this->error('Slug tidak valid.');

            return self::FAILURE;
        }

        $dbName = (string) ($this->option('database') ?: $this->buildDatabaseName($slug));

        if (Tenant::where('database', $dbName)->exists()) {
            $this->error("Database `{$dbName}` sudah terdaftar untuk lokasi lain.");

            return self::FAILURE;
        }

        $sudahAda = $this->databaseExists($dbName);

        if ($sudahAda && ! $this->option('force')) {
            $this->error("Database `{$dbName}` sudah ada di MySQL.");
            $this->line('   Pakai nama lain, atau --force untuk MEMAKAI ULANG database itu.');
            $this->line('   --force tidak menghapus isinya, tapi migration akan jalan di atas data lama.');

            return self::FAILURE;
        }

        $tenant = null;

        try {
            // 1. database
            $this->line("<fg=cyan>[1/4]</> Menyiapkan database `{$dbName}`...");
            if (! $sudahAda) {
                $manager->createDatabase($slug);
                $this->line('      dibuat.');
            } else {
                $this->line('      sudah ada, dipakai ulang.');
            }

            // 2. registry -- ditulis SEBELUM migration supaya migration
            //    bisa memakai TenantManager::runFor() yang butuh baris ini.
            $tenant = Tenant::updateOrCreate(
                ['slug' => $slug],
                [
                    'nama' => (string) ($this->option('nama') ?: Str::headline($slug)),
                    'nama_lembaga' => $this->option('nama-lembaga'),
                    'database' => $dbName,
                    'domain' => $this->option('domain'),
                    'alamat' => $this->option('alamat'),
                    'email' => $this->option('email'),
                    'telepon' => $this->option('telepon'),
                    'tgl_registrasi' => now()->toDateString(),
                    'tgl_pakai' => now()->toDateString(),
                    'is_active' => true,
                ]
            );
            $this->line("      tenant #{$tenant->id} terdaftar.");

            // 3. migration + seeder master
            if ($this->option('skip-migrate')) {
                $this->line('<fg=yellow>[3/4]</> Migration dilewati (--skip-migrate).');
                $this->line('      Isi master datanya nanti dengan:');
                $this->line("        php artisan tenancy:reseed-master {$slug}");
            } else {
                $this->line('<fg=cyan>[3/4]</> Menjalankan migration ke database lokasi...');
                $manager->migrateTenantDatabase($tenant);
                $this->line('      selesai.');

                // Master data (CoA, jabatan, jenis transaksi, arus kas).
                // WAJIB, bukan opsional: database yang sudah punya skema tapi
                // belum punya master data akan membuka aplikasi yang langsung
                // kosong -- jurnal tanpa chart of accounts, form jabatan tanpa
                // pilihan -- dan itu terlihat sebagai "lokasi rusak".
                $this->line('<fg=cyan>[3b/4]</> Menjalankan seeder master...');
                $manager->seedTenantDatabase($tenant);
                $this->line('      selesai.');
            }

            // 4. admin lokasi + identitas settings
            $this->makeLocationAdmin($tenant, $dbName, $sudahAda);
        } catch (Throwable $e) {
            $this->error('Provisioning GAGAL: '.$e->getMessage());

            // Baris tenant yang sudah tertulis harus dibuang kalau database
            // belum sempat jadi, kalau tidak leftover-nya akan menggantung.
            if ($tenant && ! $this->databaseExists($dbName)) {
                $tenant->delete();
                $this->line('      registry dibersihkan (database tidak terbentuk).');
            } elseif ($tenant) {
                $this->line("      tenant #{$tenant->id} TETAP tersimpan.");
                $this->line('      Perbaiki masalahnya lalu ulangi migration dengan:');
                $this->line("        php artisan migrate --database={$dbName} --force");
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Lokasi `{$slug}` siap dipakai.");
        $this->line("   database : {$dbName}");

        $domain = $this->option('domain');
        if ($domain) {
            $this->line("   alamat   : https://{$domain}");
        } else {
            $this->line('   alamat   : (belum diisi) -- pakai ?tenant='.$slug.' saat lokal');
        }

        return self::SUCCESS;
    }

    private function askSlug(): ?string
    {
        $slug = strtolower(trim((string) $this->argument('slug')));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
            return null;
        }

        if (in_array($slug, (array) config('tenancy.reserved_slugs', []), true)) {
            $this->error("Slug `{$slug}` dicadangkan untuk sistem.");

            return null;
        }

        return $slug;
    }

    private function buildDatabaseName(string $slug): string
    {
        $prefix = (string) config('tenancy.database_prefix', 'astaloka_lokasi_');
        $batas = (int) config('tenancy.max_database_name_length', 64);

        // Slug untuk URL boleh mengandung hyphen (`tirto-mulo`), tapi nama
        // database tidak boleh -- MySQL menerima `-` di dalam nama database
        // yang wajib di-backtick, dan `TenantManager::validateDatabaseName`
        // menolaknya karena tidak bisa diparameterisasi. Jadi slug diganti ke
        // underscore untuk nama database, sementara kolom `slug` tetap
        // menyimpan bentuk aslinya (dengan hyphen) untuk URL.
        $slugUntukDb = str_replace('-', '_', $slug);

        $nama = $prefix.$slugUntukDb;

        // Nama yang terpotong bisa berujung dengan underscore kembar, mis.
        // prefix + slug panjang lalu dipotong tepat sebelum huruf. Bersihkan
        // supaya tidak menghasilkan `astaloka_lokasi__` yang membingungkan.
        $nama = rtrim($nama, '_');

        return substr($nama, 0, $batas);
    }

    private function databaseExists(string $name): bool
    {
        $ada = DB::connection(config('tenancy.central_connection'))->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$name]
        );

        return $ada !== [];
    }

    /**
     * Buat admin pertama lokasi.
     *
     * Password TIDAK dibuat bila tidak diberikan -- akun tanpa password tidak
     * bisa dipakai, dan lebih baik dilewati daripada diam-diam memberi
     * password lemah yang tidak diketahui pemiliknya.
     */
    private function makeLocationAdmin(Tenant $tenant, string $dbName, bool $sudahAda): void
    {
        $this->line('<fg=cyan>[4/4]</> Admin lokasi...');

        $email = (string) ($this->option('admin-email') ?: '');
        $password = (string) ($this->option('admin-password') ?: '');

        if ($email === '' || $password === '') {
            $this->line('      dilewati (--admin-email / --admin-password tidak diisi).');
            $this->line('      Buat manual:');
            $this->line("        php artisan tenancy:make-user {$tenant->slug} --email=... --password=...");
        } elseif ($sudahAda) {
            $this->line('      database sudah punya isi lama, admin TIDAK dibuat ulang.');
            $this->line('      Cek dulu user yang ada di database lokasi sebelum menambah admin baru.');
        } elseif (strlen($password) < 8) {
            $this->warn('      password minimal 8 karakter -- admin dilewati.');
        } else {
            app(TenantManager::class)->runFor($tenant, function () use ($email, $password) {
                DB::connection(TenantManager::CONNECTION)->table('users')->insert([
                    'name' => (string) ($this->option('admin-name') ?: 'Admin Lokasi'),
                    'email' => $email,
                    'password' => Hash::make($password),
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->line("      admin dibuat: {$email}");
        }

        $this->writeTenantIdentity($tenant, $sudahAda);
    }

    /**
     * Tulis nama lembaga, alamat, dan domain lokasi ke tabel `settings`.
     *
     * Dijalankan di luar blok pembuatan admin, karena ada dua kondisi yang
     * harus menghasilkan dua perilaku berbeda:
     *
     *   - Admin tidak dibuat (email/password kosong) tapi lokasi tetap
     *     harus punya nama lembaga sendiri di kop laporan.
     *   - Database dipakai ulang (`--force`), yang mungkin sudah punya
     *     identitas yang sudah dikoreksi manual. Menimpanya akan menghapus
     *     pekerjaan yang tidak terlihat dari baris perintah ini.
     *
     * Karena itu hanya lokasi yang benar-benar baru yang ditulis.
     */
    private function writeTenantIdentity(Tenant $tenant, bool $sudahAda): void
    {
        if ($sudahAda) {
            $this->line('      database dipakai ulang, tabel settings TIDAK ditimpa.');
            $this->line('      Jalankan manual kalau memang perlu:');
            $this->line("        php artisan tenancy:reseed-master {$tenant->slug} --identity");

            return;
        }

        app(TenantManager::class)->applyTenantIdentity($tenant);

        $this->line('      identitas settings ditulis.');
    }
}
