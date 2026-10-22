<?php

namespace Database\Seeders;

use App\Models\Jabatan;
use Illuminate\Database\Seeder;

/**
 * Jabatan bawaan untuk SETIAP lokasi baru.
 *
 * LIMA WAJIB
 * ----------
 * Admin, Surveyor, Teknisi, Cater, dan Pelanggan selalu dibuat. Kelimanya
 * adalah role yang punya halaman/login sendiri, dan ketiadaan salah satu
 * membuat salah satu dari dua keadaan yang sulit dibaca:
 *
 *   - Halaman Hak Akses (`/central/hak-akses`) tidak punya kotak centang
 *     untuk role itu, jadi admin lokasi yang butuh orang lapangan harus
 *     membuat jabatan itu manual sebelum bisa memberi akses apa pun.
 *   - `User::findByJabatan()` -- yang dipakai laporan untuk mencari siapa
 *     penandatangnya -- kembali null, dan laporan yang tadinya tercetak
 *     menjadi kosong tanpa pesan apa pun.
 *
 * Idempoten: `firstOrCreate` berdasarkan NAMA, bukan `updateOrCreate`
 * berdasarkan ID. Seeder versi lama menulis ID eksplisit (1..8) supaya cocok
 * dengan data legacy, dan itu justru berbahaya sekarang: `updateOrCreate`
 * menulis berdasarkan ID, jadi menjalankan seeder ini pada lokasi yang
 * sudah punya jabatan dengan ID tersebut akan menimpa nama jabatan yang
 * sudah ada. Nama sudah unik di tabel (lihat migrasi) dan sudah jadi kunci
 * di `Jabatan::findByName()`, jadi itu kunci yang benar.
 *
 * ID LAMA TIDAK DIBAWA
 * --------------------
 * `LegacyUserJabatanSeeder` masih memetakan user ke ID 1/3/5/8. Itu tidak
 * rusak karena lokasi lama sudah punya baris dengan ID itu dari seeder
 * versi sebelumnya, dan seeder ini tidak mengubah baris yang sudah ada
 * (`firstOrCreate` dilewati kalau namanya sudah ada).
 *
 * JABATAN TAMBAHAN (Direktur, Sekretaris, dst.)
 * --------------------------------------------
 * Tetap ikut, karena sudah ada di data lama dan dipakai `LegacyUserJabatanSeeder`
 * lewat ID. Keduanya tidak bertabrakan: daftar di bawah memakai
 * `firstOrCreate` per nama, jadi menjalankan seeder ini dua kali aman.
 */
class JabatanSeeder extends Seeder
{
    /**
     * Lima jabatan wajib, dengan nama yang DIPAKAI di `config/menu_access.php`
     * pada `jabatan_roles`.
     *
     * Tulisan huruf kecil semua karena cocoknya nama jabatan ke role
     * dilakukan case-insensitive. Yang tersimpan tetap persis seperti
     * ditulis di sini supaya daftar di `/central/hak-akses` terbaca rapi.
     */
    private const WAJIB = [
        'admin',
        'surveyor',
        'teknisi',
        'cater',
        'pelanggan',
    ];

    /**
     * Jabatan lama yang dipertahankan.
     *
     * Huruf besar persis seperti sebelumnya, karena `LegacyUserJabatanSeeder`
     * dan perintah `legacy:*` masih mereferensikan nama itu. Baris yang
     * sudah ada di lokasi lama dibiarkan apa adanya: `firstOrCreate`
     * mencocokkan nama dengan collation MySQL yang default-nya
     * case-insensitive, jadi "Caters" versi lama ikut dikenali dan tidak
     * jadi dua baris.
     */
    private const LAMA = [
        'Direktur',
        'Sekretaris',
        'Bendahara',
        'Pengawas',
        'Pos Bayar',
        'Ketua',
    ];

    public function run(): void
    {
        foreach ($this->urutan() as $nama) {
            Jabatan::firstOrCreate(['nama_jabatan' => $nama]);
        }
    }

    /**
     * Semua nama jabatan, tanpa duplikat dan dengan urutan yang stabil.
     *
     * `array_unique` membandingkan string DENGAN AKUT (case-sensitive),
     * jadi "Cater" dan "cater" akan dianggap dua nama berbeda -- dan itu
     * memang benar: keduanya tidak pernah muncul di daftar ini. Yang
     * dijaga di sini hanya pengulangan PERSIS SAMA, yang bisa terjadi
     * kalau suatu saat satu nama masuk ke `WAJIB` dan juga ke `LAMA`.
     *
     * Pencocokan case-insensitive yang sebenarnya terjadi di TABEL: kolom
     * `nama_jabatan` punya collation MySQL yang default-nya
     * case-insensitive, jadi `firstOrCreate` menemukan "Caters" versi lama
     * ketika yang dicari "cater" -- dan baris itu tidak digandakan.
     *
     * @return array<int, string>
     */
    private function urutan(): array
    {
        return array_values(array_unique(array_merge(self::WAJIB, self::LAMA)));
    }
}
