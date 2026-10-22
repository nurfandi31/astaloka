<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Hapus kolom `max_users` dari tabel `tenants`
|--------------------------------------------------------------------------
|
| Kolom ini dulu berarti "batas jumlah user yang boleh dibuat untuk lokasi
| ini". Satu-satunya tempat yang menegakkannya adalah perintah
| `make-location-user`, yang menolak membuat user ke-N kalau kuota sudah
| penuh.
|
| Kenapa dihapus
| --------------
| Tidak ada satu pun lokasi yang memakai batas itu. Semua tenant bernilai
| `NULL`, dan `NULL` sudah berarti "tanpa batas" sejak awal -- jadi kolom
| ini tidak pernah membatasi apa pun, hanya menambah satu kolom, satu
| field di dua form, dan satu angka yang selalu tampil "Tanpa batas".
|
| Yang terjadi kalau kolom ini DIBIARKAN
| ------------------------------------
| Migrasi ini menghapus nilai yang sudah terlanjur ditulis. Data lama
| tidak hilang dengan sendirinya -- dan justru itu bahayanya: kalau
| kolomnya dihapus dari kode tapi datanya dibiarkan, akan terlihat seperti
| fitur masih hidup.
|
| Karena itu `down()` dikosongkan dengan sengaja. Kolom yang dihapus
| tidak bisa dipulihkan tanpa isi yang sudah dibuang, dan `down()` yang
| mengarang kolom kosong akan lebih berbahaya daripada tidak punya
| `down()` sama sekali -- rollback yang terlihat berhasil tapi tenant
| yang sudah punya kuota akan terbaca "tanpa batas".
|
| Datanya yang hilang tidak bisa dikembalikan. Kalau kuota lama ternyata
| masih dibutuhkan, satu-satunya sumbernya adalah dump database sebelum
| migrasi ini dijalankan.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        // `tenants` ada di koneksi central, bukan di koneksi default. Tanpa
        // `connection()` di sini, migrasi ini akan mencari tabel di koneksi
        // yang salah -- dan `hasColumn` mengembalikan false, jadi migrasi
        // selesai tanpa meng-drop apa pun tapi tetap ditandai sudah jalan.
        $schema = Schema::connection(config('tenancy.central_connection'));

        if (! $schema->hasColumn('tenants', 'max_users')) {
            return;
        }

        $schema->table('tenants', function (Blueprint $table) {
            $table->dropColumn('max_users');
        });
    }

    public function down(): void
    {
        // Sengaja kosong -- lihat catatan panjang di atas. Kolom yang dihapus
        // tidak bisa dibangun kembali dengan isi yang sama.
    }
};
