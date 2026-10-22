<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Samakan kapitalisasi `jenis_mutasi` di `akun_level_1/2` jadi huruf kecil.
 *
 * Masalah
 * -------
 *
 * Seeder level 1-3 memakai dua kapitalisasi berbeda dalam repo yang sama:
 *
 *   AkunLevel1Seeder / AkunLevel2Seeder -> 'Debet', 'Kredit'  (kapital)
 *   AkunLevel3Seeder                   -> 'debet', 'kredit'  (kecil)
 *
 * `accounts` (level 4, per lokasi) dan `central_accounts` sudah konsisten
 * huruf kecil. Perbedaan ini tidak merusak angka -- level 1 dan 2 tidak
 * punya baris `amount` sendiri, jadi saldonya selalu dihitung dari akun
 * level 4 di database lokasi, yang sudah huruf kecil.
 *
 * Yang rusak adalah KONSISTENSI TAMPILAN dan KEADAAN SUNGGUHAN:
 *
 *   1. Halaman CoA di panel pusat menampilkan `jenis_mutasi` apa adanya,
 *      jadi satu tabel menampilkan "debet" (level 3) dan "Debet" (level 1)
 *      berdampingan. Setelah admin mengedit level 1 lewat `CoaController`,
 *      nilainya ikut berubah jadi huruf kecil -- maka panel yang sama
 *      menampilkan bentuk berbeda untuk baris yang belum pernah disentuh
 *      dan yang sudah disentuh. Tanpa alasan yang bisa dibaca pengguna.
 *
 *   2. `tenancy:migrate-central --seed` menjalankan truncate + insert,
 *      jadi bentuknya bisa berubah total hanya karena seeder dijalankan.
 *      Data yang tampil sebelum dan sesudah perintah yang tidak mengubah
 *      apa pun tidak boleh berbeda.
 *
 * Kenapa migration, bukan dibenahi di seeder saja
 * ----------------------------------------------
 *
 * Menormalkan seeder hanya memperbaiki baris yang DIBUAT ulang. Baris yang
 * sudah tersimpan tetap seperti apa adanya sampai `migrate --seed`
 * dijalankan ulang, yang berarti data produksi masih punya dua bentuk
 * sementara kodenya sudah satu aturan. Migration menutup keduanya
 * sekaligus: data lama dibenahi, dan seeder yang diperbaiki mencegah
 * bentuk salah kembali muncul.
 *
 * Aman untuk diulang: `WHERE jenis_mutasi <> LOWER(jenis_mutasi)` tidak
 * menyeleksi baris yang sudah huruf kecil, jadi jalankan ulang tidak
 * mengubah apa pun dan tidak menambah write yang tidak perlu.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        foreach (['akun_level_1', 'akun_level_2', 'akun_level_3'] as $tabel) {
            $ada = DB::connection($central)->select(
                'SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$central === 'central'
                    ? DB::connection($central)->getDatabaseName()
                    : $central, $tabel, 'jenis_mutasi']
            );

            if ((int) ($ada[0]->n ?? 0) === 0) {
                continue;
            }

            DB::connection($central)->statement(
                'UPDATE `'.$tabel.'` SET `jenis_mutasi` = LOWER(`jenis_mutasi`)'
                .' WHERE `jenis_mutasi` <> LOWER(`jenis_mutasi`)'
            );
        }
    }

    public function down(): void
    {
        /*
         * Tidak ada jalan kembali yang bermakna. Kapitalisasi lama tidak
         * menyimpan informasi apa pun -- hanya perbedaan bentuk penulisan,
         * dan membalikannya berarti sengaja mengembalikan bukti bahwa
         * dua bentuk itu pernah dipakai. Nilai yang dikembalikan ke
         * bentuk huruf kecil tetap valid untuk semua kode yang membacanya
         * (semuanya sudah case-insensitive lewat `JenisMutasi`).
         *
         * Membatalkan migration tidak mengembalikan nilai data. Tidak ada
         * yang perlu dibuang.
         */
    }
};