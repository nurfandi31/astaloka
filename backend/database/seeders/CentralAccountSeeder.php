<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Rekening penampung pembayaran tagihan milik ASTA BRATA.
 *
 * Baris pertama yang dibuat bersama install: tanpa satu rekening pun,
 * form pembayaran tidak punya apa yang bisa dipilih, dan panel pusat
 * tidak bisa dipakai sama sekali sampai seseorang membuat manual
 * sesuatu yang tidak pernah disebut sebagai langkah wajib.
 *
 * ID-nya dibiarkan auto-increment, tidak dipatok seperti seeder
 * `akun_level_*`. Tidak ada `accounts.parent_id` di lokasi yang menunjuk
 * ke sini, jadi tidak ada acuan eksternal yang harus dijaga -- dan
 * mempatokkan id berarti seeder ini ikut Rewrite kalau satu baris pernah
 * dihapus, walau di tabel ini penghapusan tidak pernah terjadi.
 *
 * `no_rekening` sengaja TIDAK diisi. Nomor rekening itu data yang hanya
 * benar di tangan owning ASTA BRATA, dan seeder yang menebak angka
 * akan menghasilkan nomor rekening palsu yang lebih berbahaya daripada
 * kosong: yang kosong terlihat jelas belum diisi, sedangkan yang salah
 * dipakai orang untuk transfer.
 */
class CentralAccountSeeder extends Seeder
{
    public function run(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        // `updateOrInsert`, bukan `insert`.
        //
        // Seeder pusat dipanggil ulang setiap kali `tenancy:migrate-central
        // --seed` dijalankan (dan lewat `seedCentralDatabase()` saat
        // provisioning lokasi), bukan hanya sekali saat install. `insert`
        // akan menabrak constraint unique `kode_akun` pada pemanggilan
        // kedua, dan seluruh operasi seeding berhenti di situ -- termasuk
        // seeder chart of accounts yang jalan sesudahnya.
        //
        // Yang TIDAK ditulis ulang: `no_rekening`, `atas_nama`, `catatan`.
        // Kolom itu diisi orang, dan menimpanya dengan nilai kosong tiap
        // kali seeder jalan menghapus data yang sudah benar.
        $rekening = [
            [
                'kode_akun' => '1.1.01.01',
                'nama_akun' => 'Kas Tunai',
                'kategori' => 'tunai',
                'jenis_mutasi' => 'debet',
                'is_active' => true,
            ],
            [
                'kode_akun' => '1.1.01.03',
                'nama_akun' => 'Kas di Bank BRI',
                'kategori' => 'bank',
                'jenis_mutasi' => 'debet',
                'is_active' => true,
            ],
            [
                'kode_akun' => '1.1.01.05',
                'nama_akun' => 'Kas di Bank Mandiri',
                'kategori' => 'bank',
                'jenis_mutasi' => 'debet',
                'is_active' => true,
            ],
            [
                'kode_akun' => '1.1.01.06',
                'nama_akun' => 'Kas di Bank BNI',
                'kategori' => 'bank',
                'jenis_mutasi' => 'debet',
                'is_active' => true,
            ],
            [
                'kode_akun' => '1.1.01.07',
                'nama_akun' => 'Kas di Bank BCA',
                'kategori' => 'bank',
                'jenis_mutasi' => 'debet',
                'is_active' => true,
            ],
        ];

        foreach ($rekening as $row) {
            DB::connection($central)->table('central_accounts')->updateOrInsert(
                ['kode_akun' => $row['kode_akun']],
                $row,
            );
        }
    }
}