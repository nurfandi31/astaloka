<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Tambah admin/user di dalam satu lokasi.
 *
 * User lokasi hidup di database lokasi itu, bukan di database pusat.
 * User superadmin hanya boleh dibuat lewat `tenancy:make-superadmin`, dan
 * tidak bisa dibuat lewat perintah ini.
 */
class MakeLocationUser extends Command
{
    protected $signature = 'tenancy:make-user
        {slug : Slug lokasi tujuan}
        {--email= : Email login}
        {--password= : Password (kosong = ditanya interaktif)}
        {--name= : Nama tampilan}
        {--role=admin : admin, teknisi, surveyor, atau pelanggan}';

    protected $description = 'Buat user di dalam database sebuah lokasi';

    public function handle(TenantManager $manager): int
    {
        $slug = (string) $this->argument('slug');
        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            $this->error("Lokasi `{$slug}` tidak terdaftar.");

            return self::FAILURE;
        }

        if (! $tenant->databaseExists()) {
            $this->error("Database `{$tenant->database}` tidak ada di MySQL.");
            $this->line('   Lokasi terdaftar tapi database-nya hilang. Cek dump/restore sebelum lanjut.');

            return self::FAILURE;
        }

        $email = (string) ($this->option('email') ?: $this->ask('Email'));
        $name = (string) ($this->option('name') ?: $this->ask('Nama', 'User Lokasi'));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));
        $role = (string) $this->option('role');

        // Role superadmin SENGAJA ditolak. User pusat tidak boleh dibuat di
        // database lokasi: kalau bocor ke sana, ia akan punya akses ke panel
        // pusat lewat jalur yang tidak diregistrer di audit mana pun.
        $roleValid = ['admin', 'teknisi', 'surveyor', 'pelanggan'];

        if (! in_array($role, $roleValid, true)) {
            $this->error("Role tidak dikenal: {$role}.");
            $this->line('   Pilihan: '.implode(', ', $roleValid));

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        /*
         * Email akun demo dilaporkan dengan kalimat tambahan, karena operator
         * yang ketemu "sudah dipakai" lalu berhenti di situ tidak akan pernah
         * tebak kenapa.
         *
         * Empat email itu dibuat otomatis di setiap lokasi baru (lihat
         * `DemoUserSeeder`), jadi bentroknya hampir selalu berarti "orang ini
         * tanpa sadar membuat akun demo kedua" -- bukan "email itu milik
         * pelanggan lama". Memberi tahu passwordnya yang sekarang membuat
         * perbedaan itu jelas tanpa harus membuka database.
         *
         * Password yang ditampilkan di sini adalah nilai yang TERBACA DARI
         * KONFIGURASI, bukan hasil membalik hash. Kalau password demo sudah
         * diganti lewat `TENANCY_DEMO_USERS_PASSWORD`, yang ditampilkan ikut
         * berubah -- kalau tidak, perintah ini akan menampilkan password yang
         * sudah tidak berlaku.
         */
        foreach ((array) config('tenancy.demo_users.emails', []) as $demoEmail) {
            if (strcasecmp(trim((string) $demoEmail), $email) !== 0) {
                continue;
            }

            $this->error("Email {$email} dipakai oleh akun demo (role {$role}).");
            $this->line('   Password demo saat ini: '
                .(string) config('tenancy.demo_users.password', 'password'));
            $this->line('   Pakai email lain kalau ini memang akun orang sungguhan.');

            return self::FAILURE;
        }

        $ada = $manager->runFor($tenant, fn () => DB::connection(TenantManager::CONNECTION)
            ->table('users')->where('email', $email)->exists());

        if ($ada) {
            $this->error("Email {$email} sudah dipakai di lokasi {$slug}.");

            return self::FAILURE;
        }

        // Tidak ada pemeriksaan kuota pengguna di sini.
        //
        // Dulu ada: `max_users` per lokasi, dan perintah ini menolak membuat
        // user ke-N kalau kuota sudah penuh. Kolomnya dihapus di
        // `2026_10_16_000002_drop_max_users_from_tenants_table` karena tidak
        // pernah dipakai -- semua tenant bernilai `NULL`, dan `NULL` sudah
        // berarti "tanpa batas" sejak awal.
        //
        // Kalau batas ini dibutuhkan lagi, tegakkan di sini: ini satu-satunya
        // tempat user dibuat di dalam lokasi, jadi di sinilah batasnya harus
        // ditegakkan.

        $manager->runFor($tenant, function () use ($email, $name, $password, $role) {
            DB::connection(TenantManager::CONNECTION)->table('users')->insert([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->info("User dibuat di lokasi {$slug}: {$email} (role {$role})");

        return self::SUCCESS;
    }
}
