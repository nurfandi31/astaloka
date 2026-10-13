<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Membuat user superadmin pertama di database pusat.
 *
 * User ini TIDAK punya lokasi: dia operate pada registry lokasi, bukan pada
 * data operasional lokasi mana pun. Karena itu ia dibuat di database pusat
 * dengan role `superadmin`.
 */
class MakeSuperadmin extends Command
{
    protected $signature = 'tenancy:make-superadmin
        {--name= : Nama tampilan}
        {--email= : Email login}
        {--password= : Password (kosong = ditanya interaktif)}
        {--force : Timpa user dengan email yang sama}';

    protected $description = 'Buat user superadmin ASTA BRATA di database pusat';

    public function handle(): int
    {
        $central = config('tenancy.central_connection');

        $email = (string) ($this->option('email') ?: $this->ask('Email superadmin'));
        $name = (string) ($this->option('name') ?: $this->ask('Nama', 'Admin Pusat'));
        $password = (string) ($this->option('password') ?: $this->secret('Password'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        $ada = DB::connection($central)->table('users')->where('email', $email)->exists();

        if ($ada && ! $this->option('force')) {
            $this->error("Email {$email} sudah dipakai. Tambahkan --force untuk menimpa.");
            $this->line('   Password lama tidak ditampilkan. Hapus user manual bila ingin reset.');

            return self::FAILURE;
        }

        DB::connection($central)->table('users')->updateOrInsert(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role' => 'superadmin',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->info("Superadmin siap: {$email}");
        $this->line('   Login di domain pusat (bukan subdomain lokasi).');

        return self::SUCCESS;
    }
}
