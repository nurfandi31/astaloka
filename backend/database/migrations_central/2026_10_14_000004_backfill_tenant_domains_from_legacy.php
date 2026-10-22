<?php

use App\Services\DomainNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Isi ulang `tenant_domains` yang ikut terhapus.
 *
 * KENAPA MIGRASI INI ADA
 *
 * Migrasi `2026_10_14_000002` membuat tabel sekaligus menyalin
 * `tenants.domain` / `tenants.domain_alternatif` ke dalamnya. Pemindahan itu
 * berjalan di dalam `up()` yang sama, dan `up()` berjalan SATU KALI.
 *
 * Sementara itu, `tenant_domains` adalah tabel pusat yang bisa dikosongkan
 * tanpa meninggalkan jejak: beberapa test-feature memanggil
 * `DB::connection('central')->table('tenant_domains')->delete()` tanpa filter
 * untuk membersihkan sisa run sebelumnya, dan `RefreshDatabase` tidak pernah
 * menyentuh database pusat karena `TENANCY_CENTRAL_DATABASE` menunjuk database
 * yang berbeda.
 *
 * Akibatnya test yang berjalan dengan `TENANCY_CENTRAL_DATABASE` kelewat
 * menunjuk database pusat produksi akan MENGHAPUS SELURUH daftar domain --
 * termasuk domain yang masih dipakai semua lokasi. Yang tersisa hanyalah kolom
 * `tenants.domain` yang tidak berubah, jadi tidak ada yang rusak secara
 * Functional, dan tidak ada yang menyadarinya: panel pusat masih terbuka,
 * resolver masih menemukan tenant lewat fallback kolom legacy.
 *
 * Buktinya ada di metadata MySQL. Setelah kejadian itu, `tenant_domains`
 * menunjukkan `AUTO_INCREMENT = 4` dengan `TABLE_ROWS = 0` --artinya tiga
 * INSERT pernah terjadi lalu semua barisnya hilang, sementara tabelnya sendiri
 * tidak pernah di-drop.
 *
 * Yang dipulihkan di sini HANYA domain yang berasal dari kolom legacy, dan itu
 * disengaja: domain yang ditambahkan lewat panel pusat setelah tabelnya dibuat
 * tidak ada salinannya di kolom mana pun, jadi tidak bisa dipulihkan. Lokasi
 * yang kehilangan domain tambahan harus menambahkannya ulang lewat panel -- dan
 * karena domainnya tidak tercatat di mana pun, tidak ada yang bisa memberitahu
 * mana yang hilang.
 *
 * Operasinya idempoten: `insertOrIgnore` melewati host yang sudah ada, jadi
 * migrasi ini aman dijalankan ulang, dan index unik
 * `tenant_domains_host_unique` mencegah satu host terisi dua kali.
 *
 * PERINGATAN: baris `domain`/`domain_alternatif` tidak pernah dihapus oleh
 * migrasi apa pun, dan `TenantResolver` masih membacanya sebagai fallback. Jadi
 * tabel yang kosong tidak membuat lokasi tidak bisa diakses -- tapi panel pusat
 *kehilangan daftar domain, dan itu yang dipulihkan di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        if (! Schema::connection($central)->hasTable('tenant_domains')) {
            // Migrasi 000002 belum jalan. Tidak ada yang perlu dipulihkan --
            // `up()`-nya sudah melakukan pemindahan saat tabel pertama dibuat.
            return;
        }

        $hasil = DomainNormalizer::backfillLegacyDomains();

        if ($hasil['disimpan'] > 0) {
            logger()->info('Domain legacy dipulihkan ke `tenant_domains`.', $hasil);
        }
    }

    public function down(): void
    {
        /*
         * Tidak ada yang dikembalikan.
         *
         * Baris yang dibuat migrasi ini berasal dari kolom `tenants.domain` dan
         * `tenants.domain_alternatif`, yang TIDAK dihapus. `rollback` jadi tidak
         * perlu mengembalikan apa pun: data aslinya masih utuh di kolom asalnya.
         *
         * Menghapus barisnya di sini justru berbahaya -- kalau migrasi ini
         * dijalankan lalu di-rollback, domain yang sebelumnya tidak ada di
         * `tenant_domains` ikut hilang, dan tidak ada yang bisa memulihkannya.
         */
    }
};