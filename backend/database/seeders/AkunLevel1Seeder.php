<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AkunLevel1Seeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Ditulis ke koneksi `central`: level 1-3 adalah kerangka chart of accounts
     * yang sama untuk semua lokasi, jadi disimpan sekali di database pusat.
     * Seeding per lokasi akan membuat tiap lokasi punya salinan sendiri --
     * persis yang harus dihindari, karena perubahannya tidak akan ikut ke
     * lokasi lain.
     */
    public function run(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        // Kosongkan tabel terlebih dahulu sebelum diisi
        DB::connection($central)->table('akun_level_1')->truncate();

        $data = [
            ['id' => 1, 'lev1' => 1, 'lev2' => 0, 'lev3' => 0, 'lev4' => 0, 'kode_akun' => '1.0.00.00', 'nama_akun' => 'Aset', 'jenis_mutasi' => 'debet'],
            ['id' => 2, 'lev1' => 2, 'lev2' => 0, 'lev3' => 0, 'lev4' => 0, 'kode_akun' => '2.0.00.00', 'nama_akun' => 'Utang', 'jenis_mutasi' => 'kredit'],
            ['id' => 3, 'lev1' => 3, 'lev2' => 0, 'lev3' => 0, 'lev4' => 0, 'kode_akun' => '3.0.00.00', 'nama_akun' => 'Modal', 'jenis_mutasi' => 'kredit'],
            ['id' => 4, 'lev1' => 4, 'lev2' => 0, 'lev3' => 0, 'lev4' => 0, 'kode_akun' => '4.0.00.00', 'nama_akun' => 'Pendapatan', 'jenis_mutasi' => 'kredit'],
            ['id' => 5, 'lev1' => 5, 'lev2' => 0, 'lev3' => 0, 'lev4' => 0, 'kode_akun' => '5.0.00.00', 'nama_akun' => 'Beban', 'jenis_mutasi' => 'debet'],
        ];

        DB::connection($central)->table('akun_level_1')->insert($data);
    }
}
