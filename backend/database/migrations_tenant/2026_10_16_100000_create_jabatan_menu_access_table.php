<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Menyimpan menu yang DIJALANKAN untuk setiap jabatan.
     *
     * Dua keadaan yang harus bisa dibedakan, dan keduanya sah:
     *
     *  - "Belum pernah diatur" -> semua menu boleh. Lokasi yang baru
     *    di-migrate harus tetap bisa dipakai tanpa perlu dikonfigurasi
     *    dulu; kalau ini berarti "tidak boleh apa-apa", setiap lokasi
     *    baru akan mendarat di aplikasi tanpa menu sama sekali.
     *
     *  - "Sudah diatur, semua dicabut" -> hanya `dashboard` yang boleh.
     *    Ini hasil dari menekan "Hapus semua" di halaman Hak Akses.
     *
     * Keduanya berarti NOL BARIS di tabel ini kalau yang disimpan hanya
     * pilihan admin mentah, sehingga keberadaan baris tidak bisa dipakai
     * sebagai penanda "sudah dikonfigurasi". Karena itu `dashboard`
     * (yang `always` di `config/menu_access.php`) SELALU ikut ditulis,
     * apa pun yang dikirim admin. Dengan begitu jabatan yang sudah
     * dikonfigurasi tight tidak pernah bisa punya nol baris.
     *
     * `dashboard` sendiri selalu diizinkan di luar preferensi, jadi
     * menulisnya tidak menambah atau mengurangi akses -- ia hanya
     * menegaskan bahwa jabatan ini memang sudah diatur.
     */
    public function up(): void
    {
        Schema::create('jabatan_menu_access', function (Blueprint $table) {
            $table->id();

            $table->foreignId('jabatan_id')
                ->constrained('jabatans')
                // Jabatan dihapus -> hak aksesnya ikut hilang. Tidak ada
                // yang perlu dipulihkan kalau jabatan dibuat ulang dengan
                // nama sama: itu jabatan baru, dan harus diatur ulang.
                ->cascadeOnDelete();

            // Key menu dari `config/menu_access.php`. Panjang 100 memberi
            // ruang untuk key bertingkat (`settings.signatures`) tanpa
            // terburu-buru dibatasi VARCHAR pendek.
            $table->string('menu_key', 100);

            $table->timestamps();

            // Satu baris per (jabatan, menu). Memakai unique -- bukan
            // `$table->id()` saja -- supaya menyimpan dua kali menu yang
            // sama tidak menumpuk duplikat, yang membuat "dipilih" sulit
            // dibalik dengan benar.
            $table->unique(['jabatan_id', 'menu_key'], 'jabatan_menu_access_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jabatan_menu_access');
    }
};