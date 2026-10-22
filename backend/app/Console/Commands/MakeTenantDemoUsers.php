<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tambahkan empat akun demo ke satu atau semua lokasi yang sudah ada.
 *
 * Perintah ini menjawab kasus yang provisioning tidak bisa tangani sendiri:
 * lokasi yang dibuat SEBELUM `DemoUserSeeder` ada, atau yang dibuat dengan
 * `--skip-migrate`, atau yang `--force`-kan di atas database lama yang belum
 * pernah punya akun demo.
 *
 * Aman diulang. `DemoUserSeeder` hanya membuat baris yang belum ada dan tidak
 * pernah menyentuh password baris yang sudah ada -- jadi lokasi yang sudah
 * mengganti password demo-nya sendiri tidak akan kehilangan-password itu
 * karena perintah ini dijalankan ulang.
 *
 * Tidak ada `--force` di sini, dan itu disengaja. Perintah lain yang menghapus
 * data mempunyai `--force`; yang ini hanya membuat akun yang belum ada, jadi
 * tidak ada yang perlu dipaksa melewati konfirmasi.
 */
class MakeTenantDemoUsers extends Command
{
    protected $signature = 'tenancy:make-demo-users
        {tenant? : Slug lokasi. Kosongkan untuk semua lokasi aktif.}
        {--list : Tampilkan akun demo yang sudah ada, tanpa membuat apa pun}';

    protected $description = 'Buat akun demo (admin/pelanggan/surveyor/teknisi) di database lokasi';

    public function handle(TenantManager $manager): int
    {
        $slug = $this->argument('tenant');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('is_active', true)->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error($slug
                ? "Lokasi `{$slug}` tidak terdaftar."
                : 'Tidak ada lokasi aktif.');

            return self::FAILURE;
        }

        if (! config('tenancy.demo_users.enabled', true)) {
            $this->warn('Akun demo dimatikan (TENANCY_DEMO_USERS=false).');
            $this->line('   Nyalakan dulu kalau memang mau dibuat.');

            return self::FAILURE;
        }

        $gagal = 0;
        $dibuat = 0;

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->line("<fg=cyan>──</> {$tenant->slug} <fg=gray>({$tenant->database})</>");

            if (! $tenant->databaseExists()) {
                $this->error('   Database tidak ada. Lokasi ini tidak bisa disiapkan.');

                $gagal++;

                continue;
            }

            if ($this->option('list')) {
                $this->listDemoUsers($manager, $tenant);

                continue;
            }

            try {
                // nolint:unused-variable -- dipakai lewat report() di bawah.
                $baru = $manager->seedDemoUsers($tenant);
            } catch (Throwable $e) {
                report($e);

                $this->error('   GAGAL: '.$e->getMessage());

                $gagal++;

                continue;
            }

            $dibuat += $baru;

            if ($baru === 0) {
                $this->line('   <fg=gray>akun demo sudah lengkap.</>');

                continue;
            }

            $this->line("   <fg=green>{$baru} akun dibuat.</>");

            foreach ($this->credentials() as $akun) {
                $this->line("     {$akun['role']}: {$akun['email']} / {$akun['password']}");
            }
        }

        $this->newLine();

        if ($this->option('list')) {
            return self::SUCCESS;
        }

        if ($gagal > 0) {
            $this->error("{$gagal} lokasi gagal.");

            return self::FAILURE;
        }

        $this->info($dibuat.' akun demo dibuat di '.count($tenants).' lokasi.');

        return self::SUCCESS;
    }

    /**
     * Tampilkan akun demo yang sudah ada di satu lokasi.
     *
     * Menampilkan KOLOM ROLE, bukan membocorkan apa pun: nama akun dan
     * passwordnya sudah ada di `config('tenancy.demo_users')` dan di README.
     * Yang belum tentu ada adalah barisnya -- jadi itulah yang dicek di sini.
     */
    private function listDemoUsers(TenantManager $manager, Tenant $tenant): void
    {
        $emails = array_column($this->credentials(), 'email');

        $ada = $manager->runFor($tenant, fn () => DB::connection(TenantManager::CONNECTION)
            ->table('users')
            ->whereIn('email', $emails)
            ->pluck('role', 'email')
            ->all());

        if ($ada === []) {
            $this->line('   <fg=gray>belum ada akun demo.</>');

            return;
        }

        foreach ($ada as $email => $role) {
            $this->line("   {$email} <fg=gray>({$role})</>");
        }
    }

    /**
     * Akun demo sesuai konfigurasi, sudah dibersihkan.
     *
     * @return array<int, array{role: string, email: string, password: string}>
     */
    private function credentials(): array
    {
        $password = (string) config('tenancy.demo_users.password', 'password');

        $akun = [];

        foreach ((array) config('tenancy.demo_users.emails', []) as $role => $email) {
            $email = trim((string) $email);

            if ($email === '') {
                continue;
            }

            $akun[] = [
                'role' => (string) $role,
                'email' => $email,
                'password' => $password,
            ];
        }

        return $akun;
    }
}
