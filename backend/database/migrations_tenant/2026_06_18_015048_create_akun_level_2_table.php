<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `akun_level_2` TIDAK lagi dibuat di database lokasi.
 *
 * Level 1-3 adalah kerangka chart of accounts yang sama untuk semua lokasi,
 * jadi sekarang disimpan di database PUSAT (`astaloka`) dan dikelola dari
 * panel pusat. Definisi tabelnya pindah ke
 * `database/migrations_central/2026_10_14_000001_create_account_level_tables_in_central.php`.
 *
 * File ini sengaja TIDAK dihapus, hanya dibuat tidak melakukan apa-apa pada
 * `up()`, supaya baris catatannya di tabel `migrations` setiap lokasi yang
 * sudah ada tetap punya file yang cocok. Menghapus filenya tidak menghapus
 * catatannya, dan jejak audit skema jadi tidak bisa dipercaya.
 *
 * `down()` tetap menjatuhkan tabel untuk membersihkan lokasi lama yang
 * masih menyimpan salinannya sebelum dipindah ke pusat.
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
        Schema::dropIfExists('akun_level_2');
    }
};