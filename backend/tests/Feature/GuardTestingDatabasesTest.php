<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Pengaman agar test tidak pernah menyentuh database operasional.
 *
 * Test di `tests/TestCase.php` menolak jalan kalau nama database yang dipakai
 * tidak mengandung `testing`. Test ini memastikan penolakan itu benar-benar
 * terjadi -- pengaman yang tidak pernah diuji adalah pengaman yang tidak ada.
 *
 * Skenario yang diuji adalah kondisi nyata yang sudah pernah terjadi di repo
 * ini: `phpunit.xml` menunjuk `astaloka_testing`, tapi ada proses yang
 * memanggil `artisan test` tanpa memuatnya, sehingga `.env` yang menang.
 */
class GuardTestingDatabasesTest extends TestCase
{
    #[Test]
    public function test_ditolak_saat_database_pusat_produksi(): void
    {
        /*
         * Disimulasikan dengan mengubah config lalu memanggil aturan
         * pengamanannya langsung -- bukan dengan menjalankan `php artisan
         * test` tanpa `phpunit.xml` sungguhan.
         *
         * Alasannya: cara sungguhan butuh proses terpisah, dan setiap proses
         * punya container sendiri, jadi instance test yang sedang berjalan
         * tidak bisa dipakai di sana. Yang diuji sebenarnya adalah aturan
         * pemeriksaannya, dan aturan itu tidak berubah tergantung siapa yang
         * memanggilnya.
         */
        $penolakan = $this->jalankanPenjagaDenganConfig([
            'tenancy.central_database' => 'astaloka',
        ]);

        $this->assertNotNull($penolakan, 'Pengaman harus menolak database pusat produksi.');
        $this->assertStringContainsString('astaloka', $penolakan);
        $this->assertStringContainsString('testing', $penolakan);
    }

    #[Test]
    public function test_ditolak_saat_database_lokasi_produksi(): void
    {
        $penolakan = $this->jalankanPenjagaDenganConfig([
            'database.connections.mysql.database' => 'astaloka_lokasi',
        ]);

        $this->assertNotNull($penolakan);
        $this->assertStringContainsString('astaloka_lokasi', $penolakan);
    }

    #[Test]
    public function test_ditolak_saat_database_pusat_bukan_punya_lokasi(): void
    {
        $penolakan = $this->jalankanPenjagaDenganConfig([
            'tenancy.central_database' => 'astaloka_testing_central',
            'database.connections.central.database' => 'situs_orang_lain',
        ]);

        $this->assertNotNull($penolakan);
        $this->assertStringContainsString('situs_orang_lain', $penolakan);
    }

    #[Test]
    public function test_dilewati_saat_semua_database_sudah_bernama_testing(): void
    {
        $penolakan = $this->jalankanPenjagaDenganConfig([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'astaloka_testing',
            'database.connections.central.database' => 'astaloka_testing_central',
            'tenancy.central_database' => 'astaloka_testing_central',
        ]);

        $this->assertNull($penolakan, 'Konfigurasi uji yang benar tidak boleh ditolak.');
    }

    #[Test]
    public function nama_database_memakai_huruf_besar_tetap_dikenali(): void
    {
        /*
         * Pengaman tidak boleh bisa dilewati dengan menulis `ASTALOKA_TESTING`.
         * Orang tidak sengaja menulis huruf besar -- tapi `.env` dengan
         * `DB_DATABASE=ASTALOKA_TESTING` itu mungkin, dan konfigurasi itu
         * menunjuk ke database uji yang benar juga, jadi tidak boleh ditolak.
         */
        $ditolak = $this->jalankanPenjagaDenganConfig([
            'tenancy.central_database' => 'ASTALOKA',
        ]);

        $this->assertNotNull($ditolak, 'Pencocokan harus case-insensitive.');

        $lolos = $this->jalankanPenjagaDenganConfig([
            'tenancy.central_database' => 'ASTALOKA_TESTING',
        ]);

        $this->assertNull($lolos);
    }

    #[Test]
    public function database_memori_tidak_diperiksa(): void
    {
        /*
         * SQLite in-memory dipakai beberapa test unit. Tidak ada data nyata
         * yang bisa hilang di sana, jadi menolaknya hanya menambah langkah
         * tanpa manfaat.
         */
        $penolakan = $this->jalankanPenjagaDenganConfig([
            'database.connections.sqlite.database' => ':memory:',
        ]);

        $this->assertNull($penolakan);
    }

    #[Test]
    public function database_kosong_tidak_diperiksa(): void
    {
        $penolakan = $this->jalankanPenjagaDenganConfig([
            'database.connections.kosong.database' => '',
        ]);

        $this->assertNull($penolakan);
    }

    /**
     * Koneksi `legacy` ke server produksi lama tidak boleh menggagalkan suite.
     *
     * Koneksinya read-only dan hanya dipakai untuk import data sekali jalan.
     * Namanya memang tidak mengandung `testing` dan memang tidak akan pernah
     * jadi database uji -- jadi kalau ikut diperiksa, pengaman menolak seluruh
     * suite hanya karena satu definisi koneksi yang tidak pernah disentuh test.
     *
     * Pengaman yang menolak hal yang benar akan dimatikan orang. Begitu
     * dimatikan, ia tidak melindungi apa pun -- dan yang dilindungi justru
     * database produksi yang tadi dihapus tanpa sengaja.
     */
    #[Test]
    public function koneksi_read_only_tidak_menggagalkan_suite(): void
    {
        $penolakan = $this->jalankanPenjagaDenganConfig([
            'database.connections.legacy.database' => 'astaloka_lama',
        ]);

        $this->assertNull(
            $penolakan,
            'Koneksi read-only harus diabaikan, bukan ditolak.'
        );
    }

    /**
     * Mengabaikan `legacy` TIDAK boleh berarti mengabaikan koneksi lain.
     *
     * Dua test sebelumnya sudah membuktikannya: daftar pengecualian dibaca
     * sebagai daftar koneksi yang "dipercayai", dan menambah nama ke sana akan
     * melumpuhkan pengaman tanpa sengaja.
     */
    #[Test]
    public function pengecualian_tidak_melumpuhkan_koneksi_lain(): void
    {
        $penolakan = $this->jalankanPenjagaDenganConfig([
            'database.connections.mysql.database' => 'astaloka_lokasi',
        ]);

        $this->assertNotNull(
            $penolakan,
            'Koneksi biasa yang menunjuk produksi tetap harus ditolak.'
        );
        $this->assertStringContainsString('astaloka_lokasi', $penolakan);
    }

    /**
     * Jalankan aturan pengaman dengan config yang sengaja diubah, lalu kembalikan
     * pesan penolakan -- atau null kalau pengaman meloloskannya.
 *
     * Cara ini memanggil `periksaDatabase()` langsung lewat reflection, bukan
     * membuat instance `TestCase` baru.
     *
     * Alasannya: `TestCase` mewarisi `CreatesApplication`, dan memanggil
     * `createApplication()` di luar siklus PHPUnit menghasilkan
     * `BindingResolutionException` -- `TestCase` milik PHPUnit sendiri yang
     * perlu di-resolve dari container, bukan subclass-nya.
     *
     * Setiap koneksi yang diperiksa pengaman HARUS punya nama database yang
     * mengandung `testing`, dan itu berlaku juga untuk test yang menguji
     * kelolosan. Kalau test itu hanya mengubah satu kunci, koneksi lain yang
     * sudah ada di `config` akan ikut diperiksa dan bisa membuat test gagal
     * karena alasan yang tidak berkaitan dengan yang diujinya.
     *
     * `config()` bersifat global dan di-restore di `finally`, jadi test lain
     * yang berjalan menyusul tidak akan membaca sisa yang salah.
     *
     * @param  array<string, mixed>  $ubah
     */
    private function jalankanPenjagaDenganConfig(array $ubah): ?string
    {
        /*
         * `database.connections` SELURUHNYA diganti, bukan hanya kunci yang
         * disebut oleh `$ubah`.
         *
         * Dua masalah yang muncul kalau hanya sebagian yang diganti:
         *
         *   1. `config()` dengan titik membuat kunci baru kalau belum ada, jadi
         *      `database.connections.kosong` yang dibuat satu test TETAP ADA di
         *      test berikutnya. Pengaman di `setUp()` akan menolaknya -- dan
         *      penolakan itu muncul sebagai kegagalan test, padahal penyebabnya
         *      test pengaman sendiri yang meninggalkan sisa.
         *
         *   2. Koneksi yang benar-benar dipakai pengaman bisa ikut hilang
         *      kalau digantikan sebagian, lalu pengaman meloloskan test yang
         *      seharusnya ditolak -- dan itu jauh lebih buruk daripada
         *      kegagalan yang menyalahkan test pengaman ini sendiri.
         *
         * Karena itu snapshot diambil dari app yang SUDAH di-bootstrap, dan
         * `finally` memulihkannya apa adanya.
         */
        $asli = config('database.connections');

        $koneksi = [];

        foreach ((array) $asli as $nama => $asal) {
            $koneksi[$nama] = is_array($asal)
                ? ['driver' => 'mysql', 'database' => 'astaloka_testing']
                : ['database' => 'astaloka_testing'];
        }

        $koneksi['tenancy.central_database'] = 'astaloka_testing_central';

        config(['database.connections' => $koneksi]);

        try {
            foreach ($ubah as $kunci => $nilai) {
                config([$kunci => $nilai]);
            }

            $metode = new ReflectionMethod(TestCase::class, 'periksaDatabase');
            $metode->setAccessible(true);
            $metode->invoke($this);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            config(['database.connections' => $asli]);
        }
    }
}
