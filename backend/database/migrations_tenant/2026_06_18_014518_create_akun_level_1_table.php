<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `akun_level_1` TIDAK lagi dibuat di database lokasi.
 *
 * Level 1-3 adalah kerangka chart of accounts yang sama untuk semua lokasi,
 * jadi sekarang disimpan di database PUSAT (`astaloka`) dan dikelola dari
 * panel pusat. Definisi tabelnya pindah ke
 * `database/migrations_central/2026_10_14_000001_create_account_level_tables_in_central.php`.
 *
 * File ini sengaja TIDAK dihapus, hanya dibuat tidak melakukan apa-apa pada
 * `up()`. Alasannya bookkeeping: baris migrasi ini sudah tercatat di tabel
 * `migrations` setiap lokasi yang sudah ada. Menghapus filenya tidak menghapus
 * catatannya, dan `migrate` akan terus melihat baris yang tidak ada filenya --
 * tidak error, tapi jejak audit skema jadi tidak bisa dipercaya.
 *
 * Menyisakan `down()` yang menjatuhkan tabel tetap berguna untuk dua hal:
 *   - database lokasi lama yang masih menyimpan salinan tabel ini, dibersihkan
 *     lewat jalur `migrate:rollback`.
 *   - test yang membuat skema dari nol.
 */
return new class extends Migration
{
    /**
     * Tidak membuat apa-apa.
     *
     * Tabel dibuat di database pusat, bukan di sini. Lihat docblock di atas.
     */
    public function up(): void
    {
        //
    }

    /**
     * Buang tabel bila masih ada di database lokasi.
     *
     * Database lokasi yang sudah dipindahkan ke pusat tidak punya tabel ini,
     * jadi `dropIfExists` tidak melakukan apa-apa -- itu kondisi normal, bukan
     * tanda gagal.
     */
    public function down(): void
    {
        Schema::dropIfExists('akun_level_1');
    }
};