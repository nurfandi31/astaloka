<?php

namespace Database\Seeders;

use App\Services\TenantManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Empat akun demo per lokasi, satu untuk setiap role.
 *
 *   admin@gmail.com      role admin     password `password`
 *   pelanggan@gmail.com  role pelanggan password `password`
 *   surveyor@gmail.com   role surveyor  password `password`
 *   teknisi@gmail.com    role teknisi   password `password`
 *
 * Dijalankan sekali per lokasi, dari provisioning di panel pusat lewat
 * {@see TenantManager::seedDemoUsers()} dan dari perintah
 * `tenancy:make-tenant`. Email dan password-nya dibaca dari
 * `config('tenancy.demo_users')`, dan seluruh proses dilewati kalau
 * `demo_users.enabled` mati.
 *
 * MENGAPA TIDAK DI `tenancy.provision.seeders`
 * -------------------------------------------
 * Daftar provisioning itu dijalankan lagi oleh `tenancy:reseed-master` untuk
 * SEMUA lokasi -- termasuk lokasi produksi yang sudah berjalan lama. Kalau
 * akun demo ikut di sana, setiap `reseed-master` akan mengembalikan password
 * setiap akun demo ke `password`, membatalkan password yang sudah diganti
 * pemilik lokasi. Itu perubahan keamanan yang terjadi karena seseorang
 * menjalankan perintah pemeliharaan, tanpa ada yang menyangka.
 *
 * Jadi seeder ini hanya idempoten terhadap dirinya sendiri -- baris yang belum
 * ada dibuat, baris yang sudah ada dari provisioning lain dibiarkan -- dan
 * SENGAJA TIDAK menyentuh password baris yang sudah ada. Itu sebabnya
 * seeder ini tidak boleh masuk ke daftar provisioning.
 *
 * MENGAPA INSERT LANGSUNG, BUKAN `User::create`
 * --------------------------------------------
 * `User` terikat ke koneksi default. Pemanggilnya sudah memastikan default itu
 * menunjuk database lokasi, tapi `DB::table()` di sini memakai koneksi default
 * yang sama -- tidak ada yang perlu diasumsikan soal koneksi mana yang
 * aktif, dan tidak ada model yang bisa diam-diam menulis ke database pusat.
 *
 * Kolom `jabatan_id` sengaja TIDAK diisi.
 *
 * `MenuAccessService::allowedKeysFor()` membaca `users.jabatan_id` dan, kalau
 * ada, ikut membatasi menu yang boleh dibuka. Jadi admin demo yang diberi
 * jabatan `Direktur` bisa langsung terkunci dari sebagian besar halaman -- dan
 * yang menguncinya bukan sistemnya, tapi seeder demo ini. Akun demo ada untuk
 * login dan mencoba, bukan untuk dicabut aksesnya.
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! $this->enabled()) {
            return;
        }

        /*
         * Satu hash untuk keempat akun, bukan satu hash per akun.
         *
         * bcrypt memakai salt, jadi `Hash::make()` yang sama dipanggil empat
         * kali menghasilkan empat hash berbeda -- yang berarti empat kali
         * kerja bcrypt untuk nilai yang sama persis.
         *
         * Hash yang sama di empat baris bukan masalah: yang membuat password
         * bisa ditebak adalah panjangnya, bukan kesamaan hash antar akun.
         * Hash-nya juga tidak pernah dikirim ke browser --
         * `ShortcutLoginController` mencocokkannya lewat `Hash::check()`.
         */
        $hash = Hash::make((string) config('tenancy.demo_users.password', 'password'));

        foreach ($this->akun() as $akun) {
            $this->buat($akun, $hash);
        }
    }

    /**
     * Bentuk akhir setiap akun, dengan nama tampilan yang sudah dirapikan.
     *
     * Nama diambil dari bagian email sebelum `@`, dengan huruf pertama
     * dikapitalkan: `surveyor@gmail.com` -> `Surveyor`.
     *
     * Kenapa dari email dan bukan daftar terpisah: nama tampilan tidak punya
     * arti apa pun di luar tampilan, sedangkan email punya tiga -- dipakai
     * login, jadi kunci idempotensi, dan jadi sumber nama. Menyimpan ketiganya
     * di dua tempat berarti ada bentuk yang bisa berbeda.
     *
     * @return array<int, array{role: string, email: string, name: string}>
     */
    private function akun(): array
    {
        $akun = [];

        foreach ((array) config('tenancy.demo_users.emails', []) as $role => $email) {
            $email = trim((string) $email);

            if ($email === '') {
                continue;
            }

            $akun[] = [
                'role' => (string) $role,
                'email' => $email,
                'name' => Str::title(Str::before($email, '@')),
            ];
        }

        return $akun;
    }

    /**
     * Tulis satu akun kalau belum ada.
     *
     * Kalau email-nya sudah dipakai, akun itu milik orang yang sudah
     * menyetel passwordnya sendiri -- jadi TIDAK disentuh. `name` pun
     * dibiarkan, termasuk kalau berbeda: baris itu bukan milik seeder ini.
     *
     * Bandingkan dengan `UserSeeder` yang memakai `updateOrCreate` dan akan
     * menimpa password baris yang emailnya sama. Itu aman di database
     * pengembangan yang isinya memang seeder, tapi di sini setiap baris yang
     * sudah ada milik lokasi yang sedang dipakai orang sungguhan.
     */
    private function buat(array $akun, string $hash): void
    {
        $ada = DB::table('users')->where('email', $akun['email'])->exists();

        if ($ada) {
            return;
        }

        DB::table('users')->insert([
            'name' => $akun['name'],
            'email' => $akun['email'],
            'password' => $hash,
            'role' => $akun['role'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Apakah akun demo boleh dibuat. */
    private function enabled(): bool
    {
        return (bool) config('tenancy.demo_users.enabled', true);
    }
}
