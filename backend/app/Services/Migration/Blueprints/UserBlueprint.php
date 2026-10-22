<?php

namespace App\Services\Migration\Blueprints;

use App\Models\Jabatan;
use App\Models\User;
use App\Services\Migration\CellValue;
use App\Services\Migration\MigrationBlueprint;
use App\Services\Migration\MigrationBlueprintConcerns;
use App\Services\Migration\MigrationRowException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Migrasi akun staff lokasi: admin lokasi, surveyor, teknisi.
 *
 * TIDAK MENCAKUP PELANGGAN
 * ========================
 *
 * Kolom Peran di sini sengaja tidak memuat `pelanggan`. Akun pelanggan
 * selalu lahir bersama tiket dan baris `customers` -- tiga tabel yang
 * harus konsisten satu sama lain -- dan memuatnya di sini berarti ada
 * cara membuat akun pelanggan tanpa data pendukung yang membuat error.
 * Pelanggan punya jalurnya sendiri: `CustomerBlueprint`.
 *
 * Kata "staff" di sini berarti orang yang bekerja di lokasi itu.
 */
class UserBlueprint implements MigrationBlueprint
{
    use MigrationBlueprintConcerns;

    /** Peran yang boleh dibuat lewat migrasi ini. */
    private const PERAN = ['admin', 'surveyor', 'teknisi'];

    /** Panjang minimum hash bcrypt yang dianggap sudah di-hash. */
    private const PANJANG_HASH = 20;

    public function type(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Akun Staff Lokasi';
    }

    public function description(): string
    {
        return 'Akun admin lokasi, surveyor, dan teknisi beserta jabatan dan telepon '
            .'kontaknya. Akun pelanggan dibuat lewat menu Pelanggan, bukan dari sini.';
    }

    public function icon(): string
    {
        return 'user-shield';
    }

    public function order(): int
    {
        return 5;
    }

    public function sheetName(): string
    {
        return 'Staff';
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'nama',
                'label' => 'Nama Lengkap',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama lengkap sesuai identitas. Maksimal 255 karakter.',
                'example' => 'Budi Santoso',
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => true,
                'help' => 'Email untuk login. Harus unik di lokasi ini.',
                'example' => 'budi.santoso@astaloka.test',
            ],
            [
                'key' => 'peran',
                'label' => 'Peran',
                'type' => 'enum',
                'required' => true,
                'options' => self::PERAN,
                'help' => 'Hanya admin, surveyor, atau teknisi. Akun pelanggan tidak dibuat dari sini.',
                'example' => 'surveyor',
            ],
            [
                'key' => 'jabatan',
                'label' => 'Jabatan',
                'type' => 'text',
                'required' => false,
                'help' => 'Nama jabatan, misalnya Surveyor atau Surveyor Regional. '
                    .'Dibuat otomatis kalau belum ada.',
                'example' => 'Surveyor',
            ],
            [
                'key' => 'sandi',
                'label' => 'Kata Sandi',
                'type' => 'text',
                'required' => false,
                'help' => 'Boleh dikosongkan. Kalau kosong, akun dibuat dengan kata sandi '
                    .'"password" dan WAJIB diganti saat login pertama.',
                'example' => '',
            ],
            [
                'key' => 'telepon',
                'label' => 'Telepon',
                'type' => 'phone',
                'required' => false,
                'help' => 'Nomor WhatsApp/telepon untuk Pemberitahuan.',
                'example' => '081234567890',
            ],
        ];
    }

    public function tables(): array
    {
        return ['users'];
    }

    public function mapRow(array $row, int $nomorUrut): array
    {
        $nama = CellValue::kosongkan($row['nama'] ?? null);

        if ($nama === null) {
            throw MigrationRowException::kosong('Nama Lengkap');
        }

        if (mb_strlen($nama) > 255) {
            throw MigrationRowException::tidakValid('Nama Lengkap', $nama, 'Maksimal 255 karakter');
        }

        $email = CellValue::email($row['email'] ?? null);

        if ($email === null) {
            $raw = CellValue::kosongkan($row['email'] ?? null);

            throw $raw === null
                ? MigrationRowException::kosong('Email')
                : MigrationRowException::tidakValid(
                    'Email',
                    $raw,
                    'Format email tidak dikenali, contoh: nama@domain.com'
                );
        }

        $peran = CellValue::kosongkan($row['peran'] ?? null);

        if ($peran === null) {
            throw MigrationRowException::kosong('Peran');
        }

        $peran = mb_strtolower($peran);

        if (! in_array($peran, self::PERAN, true)) {
            throw MigrationRowException::tidakDikenal('Peran', $peran, self::PERAN);
        }

        $jabatan = CellValue::kosongkan($row['jabatan'] ?? null);

        if ($jabatan !== null && mb_strlen($jabatan) > 100) {
            throw MigrationRowException::tidakValid('Jabatan', $jabatan, 'Maksimal 100 karakter');
        }

        return [
            'name' => $nama,
            'email' => $email,
            'role' => $peran,
            'jabatan_id' => $jabatan === null ? null : $this->cariAtauBuatJabatanId($jabatan),
            /*
             * Hash dihitung DI SINI, bukan di `persist()`.
             *
             * Alasannya: `persist()` dipanggil per baris di dalam satu
             * transaksi yang menahan koneksi database, dan bcrypt butuh
             * ~50 ms per hash. 5.000 baris = 4 menit koneksi itu
             * tertahan hanya untuk hashing. Hashing saat validasi
             * memindahkannya ke tahap yang memang hanya membaca --
             * biaya yang sama, tapi tanpa menahan koneksi.
             */
            'password' => $this->sandi($row['sandi'] ?? null),
            '__kunci' => $email,
        ];
    }

    public function existingIndex(): array
    {
        $peta = [];

        foreach (User::query()->get(['id', 'email']) as $user) {
            $peta[mb_strtolower(trim((string) $user->email))] = (int) $user->id;
        }

        return $peta;
    }

    public function persist(array $data, array $konflik, int $nomorUrut): void
    {
        $isidata = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'jabatan_id' => $data['jabatan_id'],
        ];

        $id = $konflik['id'] ?? null;

        if ($id !== null) {
            /*
             * Password TIDAK ditulis ulang pada baris yang memperbarui.
             *
             * Akun yang sudah dipakai staff mungkin sudah mengganti
             * sandinya. Menimpanya dengan "password" dari file -- yang
             * kebetulan sama dengan bawaan, atau lebih buruk, menghasilkan
             * hash berbeda -- mengunci orang keluar dari akunnya sendiri
             * tepat setelah migrasi. Password hanya berlaku untuk akun
             * yang baru dibuat.
             */
            DB::table('users')->where('id', $id)->update($isidata);

            return;
        }

        DB::table('users')->insert([
            ...$isidata,
            'password' => $data['password'],
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Jabatan yang boleh dipilih, dibaca dari lokasi.
     *
     * Daftar ini hanya berisi nama yang sudah ada. Jabatan dibuat
     * otomatis saat migrasi, jadi daftar di sheet Referensi tidak pernah
     * menampilkan opsi yang tidak akan ada.
     *
     * @return array<string, array<int, string>>
     */
    public function references(): array
    {
        $daftar = Jabatan::query()
            ->orderBy('nama_jabatan')
            ->pluck('nama_jabatan')
            ->map(static fn ($nama) => (string) $nama)
            ->all();

        return $daftar === [] ? [] : ['Jabatan yang tersedia' => $daftar];
    }

    /* ================================================================
     | BANTUAN
     |============================================================
     */

    /**
     * ID jabatan, dibuat kalau belum ada.
     *
     * Dibuat di tahap validasi, bukan di `persist()`, supaya dua baris
     * dengan jabatan yang sama tidak membuat dua jabatan -- dan supaya
     * jabatan yang gagal pembuatan muncul sebagai error baris, bukan
     * error 500 di tengah commit.
     */
    private function cariAtauBuatJabatanId(string $nama): int
    {
        static $cache = [];

        $kunci = mb_strtolower(trim($nama));

        if (isset($cache[$kunci])) {
            return $cache[$kunci];
        }

        $jabatan = Jabatan::findByName($nama);

        if ($jabatan === null) {
            $jabatan = Jabatan::query()->create(['nama_jabatan' => trim($nama)]);
        }

        return $cache[$kunci] = (int) $jabatan->id;
    }

    /**
     * Kata sandi dari file, di-hash.
     *
     * Dua hal yang dijaga di sini:
     *
     *   1. Isi yang sudah berupa hash bcrypt DIBAWA SEBAGAI hash, bukan
     *      di-hash lagi. Tanpa ini, menyalin baris `password` dari dump
     *      database lama akan menghasilkan hash dari hash -- aka kata
     *      sandi yang tidak pernah bisa dipakai.
     *
     *   2. Kolom password template diformat sebagai TEKS di Excel. Kalau
     *      diformat sebagai teks dan user mengetik "123456", yang
     *      tersimpan adalah "123456" -- dan kata sandi enam angka tanpa
     *      hash adalah lubang keamanan, bukan fitur.
     */
    private function sandi(mixed $isi): string
    {
        $teks = CellValue::kosongkan($isi);

        if ($teks === null) {
            return Hash::make('password');
        }

        $teks = (string) $teks;

        if (str_starts_with($teks, '$2y$')
            || str_starts_with($teks, '$2a$')
            || str_starts_with($teks, '$argon2')) {
            return $teks;
        }

        return Hash::make($teks);
    }
}
