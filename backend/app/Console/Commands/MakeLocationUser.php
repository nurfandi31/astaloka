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

        $ada = $manager->runFor($tenant, fn () => DB::connection(TenantManager::CONNECTION)
            ->table('users')->where('email', $email)->exists());

        if ($ada) {
            $this->error("Email {$email} sudah dipakai di lokasi {$slug}.");

            return self::FAILURE;
        }

        // Kuota pengguna, kalau lokasi punya batas.
        if ($tenant->max_users !== null) {
            $jumlah = $manager->runFor($tenant, fn () => DB::connection(TenantManager::CONNECTION)
                ->table('users')->count());

            if ($jumlah >= $tenant->max_users) {
                $this->error("Kuota pengguna lokasi {$slug} penuh ({$jumlah}/{$tenant->max_users}).");
                $this->line('   Naikkan max_users di registry, atau hapus user lama.');

                return self::FAILURE;
            }
        }

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
