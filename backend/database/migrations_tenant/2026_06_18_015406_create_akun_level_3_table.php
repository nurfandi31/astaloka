<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `akun_level_3` TIDAK lagi dibuat di database lokasi.
 *
 * Level 1-3 adalah kerangka chart of accounts yang sama untuk semua lokasi,
 * jadi sekarang disimpan di database PUSAT (`astaloka`) dan dikelola dari
 * panel pusat. Definisi tabelnya pindah ke
 * `database/migrations_central/2026_10_14_000001_create_account_level_tables_in_central.php`.
 *
 * File ini sengaja TIDAK dihapus, hanya dibuat tidak melakukan apa-apa pada
 * `up()`, supaya baris catatannya di tabel `migrations` setiap lokasi yang
 * sudah ada tetap punya file yang cocok.
 *
 * CATATAN PENTING untuk pembaca `accounts`: kolom `accounts.parent_id` di
 * database lokasi menunjuk `akun_level_3.id` yang sekarang tinggal di pusat.
 * Nilainya dijaga tetap sama oleh seeder pusat, tapi tidak ada foreign key
 * yang menegakkan itu -- kalau level 3 di pusat berubah nomornya, relasi
 * parent level 4 ikut tidak sinkron tanpa error apa pun.
 *
 * `down()` tetap menjatuhkan tabel untuk membersihkan lokasi lama.
 */
return new class extends Migration
{
    /**
     * Tidak membuat apa-apa.
     *
     * Tabel dibuat di database pusat, bukan di sini.
     */
    public function up(): void
    {
        //
    }

    /**
     * Buang tabel bila masih ada di database lokasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('akun_level_3');
    }
};