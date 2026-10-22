<?php

namespace Tests\Unit;

use App\Services\TenantManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nama database lokasi: satu definisi, tidak ada salinan.
 *
 * Aturan ini pernah ditulis ulang di tiga tempat -- `TenantManager`,
 * `MakeTenant`, dan `LocationController` -- dan ketiganya tidak sama.
 * Dua melakukan `substr()` lalu `rtrim()`, satu melakukan `rtrim()` lalu
 * `substr()`.
 *
 * Kenapa bedanya tidak pernah ketahuan: untuk prefix bawaan
 * `TENANCY_DATABASE_PREFIX = astaloka_` (9 karakter), kedua urutan itu
 * SELALU menghasilkan nama yang sama. `substr()` hanya memotong di posisi
 * ke-64, dan karena prefix cuma 9 karakter, posisi itu selalu jatuh di
 * dalam slug -- tidak pernah di underscore pemisah.
 *
 * Jadi di konfigurasi bawaan ini daftar kode yang tidak sama itu sebenarnya
 * tidak merusak apa pun; ia bom waktu, bukan bug yang sudah meledak. Yang
 * membuatnya meledak baru kalau prefix diganti lewat env, dan test
 * `prefix_tanpa_underscore_tetap_valid` di bawah menjalankan persis kasus
 * itu.
 *
 * Yang paling penting dari audit ini sebenarnya BUKAN bedanya, tapi
 * banyaknya definisi: tiga salinan dari satu aturan berarti setiap
 * perbaikan harus diulang tiga kali, dan hanya satu yang akan diingat.
 * Sekarang tinggal satu.
 *
 * Test di sini mengunci hasil yang harus dijaga, supaya definisi kedua
 * yang muncul belakangan langsung ketahuan.
 */
class DatabaseNameTest extends TestCase
{
    #[Test]
    #[DataProvider('slugYangHarusNormal')]
    public function nama_database_turun_dari_slug(string $slug, string $harapan): void
    {
        $this->assertSame($harapan, TenantManager::databaseNameFor($slug));
    }

    public static function slugYangHarusNormal(): array
    {
        return [
            'sederhana' => ['tirtomulo', 'astaloka_tirtomulo'],
            'hyphen jadi underscore' => ['tirto-mulo', 'astaloka_tirto_mulo'],
            'beberapa hyphen' => ['a-b-c-d', 'astaloka_a_b_c_d'],
            'sudah underscore' => ['tirto_mulo', 'astaloka_tirto_mulo'],
        ];
    }

    /**
     * Nama SELALU valid sebagai identifier MySQL dan tidak melebihi batasnya.
     *
     * `TenantManager::validateDatabaseName()` menolak apa pun di luar
     * `[A-Za-z0-9_]` dan apa pun yang lebih dari 64 karakter, karena nama
     * database disisipkan apa adanya ke `CREATE DATABASE` -- tidak bisa
     * diparameterisasi. Kalau nama dari `databaseNameFor()` bisa ditolak
     * validasi itu, provisioning yang baru saja selesai akan gagal di langkah
     * yang tidak bisa dikembalikan: database sudah dibuat, tapi tenant
     * gagal ditulis.
     */
    #[Test]
    #[DataProvider('slugYangHarusValid')]
    public function nama_database_selalu_lolos_validasi(string $slug): void
    {
        $nama = TenantManager::databaseNameFor($slug);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/', $nama);
        $this->assertLessThanOrEqual(64, strlen($nama), 'Melebihi batas nama database MySQL.');
    }

    public static function slugYangHarusValid(): array
    {
        return [
            'tepat di batas' => [str_repeat('a', 55)],
            'melewati batas' => [str_repeat('a', 120)],
            'berakhiran hyphen' => [str_repeat('a', 55).'-b'],
            'berakhiran huruf' => [str_repeat('a', 62).'-xyz'],
            'sangat panjang dengan hyphen' => ['x-'.str_repeat('y', 200)],
            'berselang' => [str_repeat('ab-', 40)],
        ];
    }

    /**
     * Prefix yang TIDAK berakhir underscore -- kasus yang membedakan
     * `substr()`-lalu-`rtrim()` dari `rtrim()`-lalu-`substr()`.
     *
     * Dengan prefix `astaloka` (tanpa underscore), pemotongan ke 64 karakter
     * bisa jatuh di posisi yang menjadi underscore setelahnya. Versi yang
     * salah menyisakan underscore menggantung di ujung nama, jadi nama yang
     * satu berbeda dari nama yang lain -- dan registry lokasi akan menunjuk
     * database yang tidak pernah dibuat.
     *
     * Prefix dipasang lewat `config()` karena `databaseNameFor()` membaca
     * `tenancy.database_prefix` setiap pemanggilan. Env tidak bisa dipakai
     * di sini: nilainya sudah ter-cache di balik `config()` sejak aplikasi
     * dimuat, jadi mengubah env di tengah suite tidak punya efek apa pun.
     */
    #[Test]
    public function prefix_tanpa_underscore_tetap_valid(): void
    {
        config(['tenancy.database_prefix' => 'astaloka']);

        foreach (range(1, 70) as $panjang) {
            foreach (['a', 'ab-', 'a-b-c-'] as $pola) {
                $slug = substr(str_repeat($pola, 30), 0, $panjang);

                $nama = TenantManager::databaseNameFor($slug);

                $this->assertMatchesRegularExpression(
                    '/^[A-Za-z0-9_]+$/',
                    $nama,
                    "Pola `{$pola}`, panjang {$panjang}."
                );
                $this->assertStringEndsNotWith(
                    '_',
                    $nama,
                    "Pola `{$pola}`, panjang {$panjang}: underscore menggantung."
                );
                $this->assertLessThanOrEqual(64, strlen($nama));
            }
        }
    }

    /**
     * Nama tidak pernah berakhir dengan underscore -- pengaman yang berlaku
     * untuk prefix apa pun.
     *
     * Dipisah dari test di atas karena ini aturan yang harus tetap benar
     * walaupun prefix diganti di kemudian hari, dan pesannya harus
     * menyebut penyebabnya kalau sampai gagal.
     */
    #[Test]
    public function nama_tidak_pernah_berakhir_dengan_underscore(): void
    {
        $prefixBawaan = config('tenancy.database_prefix');

        foreach (['astaloka_', 'astaloka', 'bpm_', 'bpm'] as $prefix) {
            config(['tenancy.database_prefix' => $prefix]);

            foreach (range(1, 70) as $panjang) {
                $nama = TenantManager::databaseNameFor(str_repeat('a-b', 25));

                $this->assertStringEndsNotWith(
                    '_',
                    $nama,
                    "Prefix `{$prefix}`: nama berakhir underscore."
                );
            }
        }

        config(['tenancy.database_prefix' => $prefixBawaan]);
    }

    /**
     * Slug yang tidak menghasilkan nama valid harus DITOLAK, bukan
     * membuat nama seperti `astaloka_`.
     *
     * Tanpa pengecekan ini, `tenants.database` bisa berisi `astaloka_`,
     * dan dua lokasi berbeda yang slug-nya sama-sama tidak valid akan
     * menunjuk ke database yang sama -- sehingga data satu lokasi bocor ke
     * lokasi lain tanpa satu pun pesan error.
     */
    #[Test]
    #[DataProvider('slugYangTidakValid')]
    public function slug_tidak_valid_ditolak(string $slug): void
    {
        $this->expectException(\RuntimeException::class);

        TenantManager::databaseNameFor($slug);
    }

    public static function slugYangTidakValid(): array
    {
        return [
            'kosong' => [''],
            'hanya underscore' => ['___'],
            'hanya tanda baca' => ['---'],
        ];
    }

    /**
     * Dua slug berbeda tidak boleh menghasilkan nama database yang sama.
     *
     * Yang diuji adalah slug yang PENUH -- pendek, dan tidak pernah
     * terpengaruh pemotongan. Pemotongan ke 64 karakter memang bisa membuat
     * dua slug panjang menunjuk nama yang sama, dan itu dibatasi oleh
     * `max:64` pada validasi slug; yang dijaga di sini adalah hal yang tidak
     * boleh bocor: slug normal wajib menghasilkan nama berbeda.
     *
     * `tirto-mulo` sengaja tidak berpasangan dengan `tirto_mulo` di sini --
     * keduanya memang harus collide, dan itu diuji terpisah di test
     * `hyphen_dan_underscore_sengaja_sama` di bawah.
     */
    #[Test]
    public function slug_normal_tidak_pernah_bertabrakan(): void
    {
        $slug = ['tirtomulo', 'tirto-mulo', 'tirtomul', 'tirtomuloa', 'tirto', 'tirtomulo-2'];

        $nama = array_map(
            fn (string $s) => TenantManager::databaseNameFor($s),
            $slug
        );

        $this->assertSame(
            count($slug),
            count(array_unique($nama)),
            'Dua slug berbeda menghasilkan nama database yang sama.'
        );
    }

    /**
     * Hyphen dan underscore SENGAJA dianggap slug yang sama.
     *
     * Ini bukan kebocoran, ini kontrak normalisasi: `tirto-mulo` dan
     * `tirto_mulo` menunjuk lokasi yang sama, jadi mereka memang harus
     * menghasilkan nama database yang sama. Ditulis eksplisit supaya
     * kesamaan ini tidak ikut dibersihkan sebagai bug someday.
     */
    #[Test]
    public function hyphen_dan_underscore_sengaja_sama(): void
    {
        $this->assertSame(
            TenantManager::databaseNameFor('tirto-mulo'),
            TenantManager::databaseNameFor('tirto_mulo')
        );
    }
}
