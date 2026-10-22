<?php

namespace Tests\Feature;

use App\Models\Central\Tenant;
use App\Support\StatistikPengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Bagian "sudah dipakai siapa" di halaman depan.
 *
 * Yang diuji di sini bukan tampilan -- bukan ada screenshot, bukan ada
 * assertion soal class CSS. Yang diuji adalah dua aturan yang, kalau
 * dilanggar, TIDAK PERNAH muncul sebagai error:
 *
 *   1. Angkanya harus berasal dari data. Section ini sengaja tidak memakai
 *      class `mock`, jadi setiap angka di dalamnya adalah klaim yang benar-benar
 *      diklaim ke calon pembeli. Kalau angka itu ditulis manual di Blade, tidak
 *      ada test yang gagal -- halamannya tetap terlihat benar, hanya bohong.
 *
 *   2. Halaman publik tidak boleh menyebut nama lokasi maupun domainnya.
 *      Ini yang membuat test ini punya test "tidak bocor", dan itu bukan
 *      formalitas: nama usaha adalah keputusan pemilik lokasi, dan domain
 *      adalah peta menuju subdomain yang bisa ditebak orang.
 *
 * Test kedua biasanya dianggap berlebihan untuk landing page. Justru itu
 * alasannya ada -- tidak ada mekanisme lain yang akan menahan perubahan ini
 * nanti, dan ketika nama tenant ikut ditambahkan ke Blade, halaman itu akan
 * tetap lulus test lain yang ada.
 *
 * CATATAN SOAL DATABASE PUSAT: sama seperti `CentralDomainTest` dan
 * `BrandingTest`, tabel pusat tidak ikut ter-refresh oleh `RefreshDatabase`,
 * dan baris yang dibuat di sini tidak ikut ter-rollback. Karena itu
 * `setUp()` membersihkan sisa run sebelumnya dan `tearDown()` menghapus
 * eksplisit.
 */
class LandingPenggunaTest extends TestCase
{
    use EnsuresCentralTables;
    use RefreshDatabase;

    private const CENTRAL = 'central';

    private string $prefix;

    /** @var array<int, int> */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $central = DB::connection(self::CENTRAL);

        $this->prefix = 'landingtest-'.uniqid().'-';

        $this->runCentralMigrations();

        /*
         * Setiap test di sini menghitung angka dari SELURUH tenant di
         * database pusat, jadi satu tenant pun milik test lain membuat
         * assertion-nya salah.
         *
         * Titik awal yang bersih, TANPA menghapus baris milik siapa pun.
         *
         * `StatistikPengguna` menghitung hanya tenant `is_active = true`,
         * jadi syarat "nol tenant aktif" dipenuhi dengan menonaktifkan
         * semua, bukan menghapus. Menonaktifkan aman: test lain yang butuh
         * tenantnya tetap menemukan barisnya, hanya tidak aktif.
         *
         * Versi sebelumnya memakai `->delete()` tanpa batas. Itu menghapus
         * tenant milik test lain, dan karena tabel pusat tidak di-rollback,
         * kehapusannya permanen -- test berikutnya gagal dengan pesan yang
         * tidak menyuruhkan penyebabnya ("Failed asserting that 2 is
         * identical to 1"), sementara penyebabnya ada di file lain.
         *
         * Sisa run sebelumnya tidak jadi masalah karena `uniqid()` di prefix
         * membuat slug setiap test unik, dan tenant yang tidak aktif tidak
         * ikut terhitung -- berapa pun jumlahnya.
         */
        $central->table('tenants')->update(['is_active' => false]);
    }

    protected function tearDown(): void
    {
        try {
            $central = DB::connection(self::CENTRAL);

            // Tenant milik test ini dihapus, jadi tidak ikut diaktifkan
            // kembali di bawah.
            $central->table('tenants')->whereIn('id', $this->tenantIds)->delete();

            /*
             * Kembalikan tenant milik test lain ke AKTIF.
             *
             * `setUp()` menonaktifkan semuanya supaya hitungan di sini
             * mulai dari nol. Kalau tidak dipulihkan, setiap test yang
             * dijalankan setelah file ini -- dan yang butuh tenant aktif
             * -- akan gagal karena tenant-nya masih nonaktif.
             *
             * Baris milik test ini sudah dihapus di atas, jadi yang
             * diaktifkan kembali di sini benar-benar hanya baris orang lain.
             */
            $central->table('tenants')->update(['is_active' => true]);
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    /* ================================================================
     | PERHITUNGAN
    |============================================================
     */

    /**
     * Lokasi nonaktif tidak boleh dihitung.
     *
     * Ini bukan kasus tepi: lokasi yang dihentikan langganannya tetap punya
     * baris di `tenants`, hanya dengan `is_active = false`. Menghitungnya
     * membuat halaman mengklaim ada orang yang sebenarnya sudah berhenti
     * memakai ASTALOKA -- dan ini bukan kesalahan yang terlihat dari
     * halaman, karena angkanya sendiri tetap terlihat masuk akal.
     */
    #[Test]
    public function lokasi_tidak_aktif_tidak_dihitung(): void
    {
        $this->makeTenant('aktif');
        $this->makeTenant('nonaktif', ['is_active' => false]);

        $this->assertSame(1, StatistikPengguna::hitung()['lokasi']);
    }

    /**
     * Sektor kosong tidak boleh menambah angka "jenis usaha terlayani".
     *
     * `sektor` nullable dan belum semua lokasi mengisinya. Tanpa filter
     * `whereNotNull`, `COUNT(DISTINCT)` tetap menghitung NULL sebagai satu
     * kelompok -- jadi halaman akan mengklaim ada jenis usaha yang sebenarnya
     * tidak ada datanya.
     */
    #[Test]
    public function sektor_kosong_tidak_menjumlah_jenis_usaha(): void
    {
        $this->makeTenant('tanpa-sektor');
        $this->makeTenant('sektor-kosong', ['sektor' => '']);

        $this->assertSame(0, StatistikPengguna::hitung()['sektor']);
    }

    /**
     * Dua lokasi di sektor yang sama dihitung sebagai SATU jenis usaha.
     *
     * Sector gunanya mengelompokkan. Kalau dua lokasi "Air Bersih" terhitung
     * sebagai dua, angkanya tidak menjawab pertanyaan yang membuatnya
     * ditanyakan ("berapa banyak jenis usaha yang terlayani") -- hanya
     * mengulang "berapa lokasi".
     */
    #[Test]
    public function sektor_yang_sama_dihitung_satu_jenis(): void
    {
        $this->makeTenant('satu', ['sektor' => 'Air Bersih']);
        $this->makeTenant('dua', ['sektor' => 'Air Bersih']);
        $this->makeTenant('tiga', ['sektor' => 'Sanitasi']);

        $this->assertSame(2, StatistikPengguna::hitung()['sektor']);
    }

    /**
     * Tahun yang diambil adalah yang paling awal, bukan yang terakhir.
     *
     * "pertama dipakai ASTALOKA" selalu berarti yang paling awal. Mengambil
     * `max()` akan mengubah artinya jadi "sejak terbaru", dan kalimatnya
     * tidak ikut berubah -- jadi angkanya jadi tidak sesuai dengan yang
     * ditulis di halaman.
     */
    #[Test]
    public function tahun_sejak_diambil_dari_pakai_terlama(): void
    {
        $this->makeTenant('lama', ['tgl_pakai' => '2024-03-15']);
        $this->makeTenant('baru', ['tgl_pakai' => '2026-01-02']);

        $this->assertSame(2024, StatistikPengguna::hitung()['sejak']);
    }

    /**
     * Tanpa `tgl_pakai`, tahun tidak boleh ditebak.
     *
     * `tgl_pakai` nullable dan `tenancy:make` bisa membuat baris tanpa
     * mengisinya. Menebak dari `created_at` terdengar masuk akal di
     * beberapa kasus dan salah di sisanya, dan yang salah tidak pernah
     * kelihatan -- makanya `null` di sini, dan halaman menyembunyikan
     * barisnya.
     */
    #[Test]
    public function tahun_sejak_null_bila_tidak_ada_tanggal_pakai(): void
    {
        $this->makeTenant('tanpa-tanggal', ['tgl_pakai' => null]);

        $this->assertNull(StatistikPengguna::hitung()['sejak']);
    }

    /* ================================================================
     | HALAMAN DEPAN
    |============================================================
     */

    /**
     * Angka yang tampil harus sama dengan yang dihitung.
     *
     * Assertion ini mengunci yang paling rawan: kalau angka di Blade
     * ditulis manual sementara query-nya menghitung yang lain, keduanya
     * bisa saja cocok selama ini -- lalu berhenti cocok begitu ada lokasi
     * kedua.
     */
    #[Test]
    public function angka_di_halaman_sama_dengan_yang_dihitung(): void
    {
        $this->makeTenant('satu');
        $this->makeTenant('dua');

        $expected = StatistikPengguna::hitung()['lokasi'];

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(
            'data-count="'.$expected.'"',
            $html,
            'Angka yang tampil harus angka yang dihitung dari database pusat.'
        );
    }

    /**
     * NAMA LOKASI TIDAK BOLEH MUNCUL di halaman publik.
     *
     * Ini test yang menjaga keputusan yang membuat section ini tidak punya
     * tombol "lihat semua lokasi". Tanpa test ini, menambahkan daftar nama
     * akan terlihat sebagai perbaikan kecil -- dan tidak ada yang cek cepat
     * bahwa baris itu membocorkan nama seluruh pelanggan.
     */
    #[Test]
    public function halaman_tidak_membocorkan_nama_lokasi(): void
    {
        $namaRahasia = 'PDAM Rahasia Yang Tidak Boleh Muncul';

        $this->makeTenant('rahasia', [
            'nama' => $namaRahasia,
            'nama_lembaga' => $namaRahasia,
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            $namaRahasia,
            $this->teksTampil($html),
            'Nama lokasi tidak pernah boleh tampil di halaman publik.'
        );
    }

    /**
     * DOMAIN LOKASI TIDAK BOLEH MUNCUL, dan tidak boleh jadi tautan.
     *
     * Domain adalah peta menuju subdomain yang bisa ditebak orang. Satu
     * `<a href>` ke host lokasi di halaman yang bisa dibaca siapa pun sudah
     * cukup -- dan assertion tentang keberadaan string-nya saja akan menangkap
     * `<a href>` yang dibangun dari slug maupun dari domain alike.
     *
     * Yang dikunci di sini adalah aturannya, bukan bentuk markup sekarang:
     * begitu nilai host sebuah tenant muncul di mana pun di HTML, test gagal.
     */
    #[Test]
    public function halaman_tidak_membocorkan_domain_lokasi(): void
    {
        $this->makeTenant('peta', ['domain' => 'lokasi-rahasia.test']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'lokasi-rahasia.test',
            $html,
            'Domain lokasi tidak pernah boleh tampil di halaman publik.'
        );
    }

    /**
     * Bagian dengan nilai nol harus tetap jujur.
     *
     * Kalau tidak ada lokasi, angka "0" tetap muncul -- hanya kalimat
     * pendukung yang disembunyikan. Menyembunyikan angkanya sendiri
     * membuat halaman terlihat seperti gagal memuat data, bukan seperti
     * belum ada yang memakai; dan "0 jenis usaha terlayani" memang tidak
     * menambah apa pun.
     */
    #[Test]
    public function tanpa_lokasi_angka_nol_tetap_muncul(): void
    {
        DB::connection(self::CENTRAL)->table('tenants')
            ->where('is_active', true)
            ->delete();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-count="0"', $html);

        $teks = $this->teksTampil($html);

        $this->assertStringNotContainsString('jenis usaha terlayani', $teks);
        $this->assertStringNotContainsString('pertama dipakai ASTALOKA', $teks);
    }

    /* ================================================================
     | CARA MEMBACA ANGKA SATU
    |============================================================
     */

    /**
     * Angka satu harus dibaca sebagai POSISI, bukan sebagai kelangkaan.
     *
     * Angka "1" di section ini selalu jujur -- dan tetap 1. Yang boleh
     * diubah adalah kalimatnya saja: "lokasi pertama yang memakai"
     * menyatakan fakta yang sama dengan "satu lokasi memakai", tapi
     * berhenti terdengar seperti kekurangan.
     *
     * Test ini mengunci arah itu. Kegagalan yang paling mungkin terjadi
     * nanti bukan angka yang dikarang -- itu sudah tertahan test lain --
     * tapi kalimatnya yang diam-diam dikembalikan ke bentuk jamak karena
     * satu lokasi memang terlihat sepi.
     */
    #[Test]
    public function satu_lokasi_dibaca_sebagai_pertama_bukan_kelangkaan(): void
    {
        $this->makeTenant('satu');

        $teks = $this->teksTampil($this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('yang pertama', $teks);
        $this->assertStringContainsString('lokasi pertama yang memakai ASTALOKA', $teks);
    }

    /**
     * Begitu ada dua, framing "yang pertama" HARUS hilang.
     *
     * Ini arah dari test sebelumnya, dan itu yang membuatnya berarti.
     * "Lokasi pertama" hanya jujur selama memang cuma satu; kalau masih
     * muncul di angka dua, halaman mengklaim sesuatu yang tidak benar --
     * dan karena kelihatan benar secara visual, tidak ada yang akan
     * merasainya tanpa test ini.
     *
     * Yang dikunci frasa utuhnya, bukan kata "pertama": "pertama dipakai
     * ASTALOKA" di baris tahun tetap boleh muncul, karena itu kalimat
     * berbeda yang maknanya benar.
     */
    #[Test]
    public function dua_lokasi_tidak_lagi_disebut_pertama(): void
    {
        $this->makeTenant('satu');
        $this->makeTenant('dua');

        $teks = $this->teksTampil($this->get('/')->assertOk()->getContent());

        $this->assertStringNotContainsString('yang pertama', $teks);
        $this->assertStringNotContainsString('lokasi pertama yang memakai', $teks);
    }

    /**
     * Tanpa lokasi, halaman tidak boleh menjanjikan ada yang pertama.
     *
     * "Lokasi pertama" menyiratkan setidaknya satu lokasi sudah memakai
     * -- dan di angka nol itu belum terjadi. Yang ditampilkan harus
     * bentuk jamak apa adanya, karena itulah satu-satunya yang jujur.
     */
    #[Test]
    public function tanpa_lokasi_tidak_menjanjikan_lokasi_pertama(): void
    {
        // Hanya baris milik test INI yang dihapus. `where('is_active', true)`
        // tanpa batas ikut menghapus tenant milik test lain, dan database
        // pusat tidak di-rollback -- jadi kehapusannya permanen.
        DB::connection(self::CENTRAL)->table('tenants')
            ->where('slug', 'like', 'landingtest-%')
            ->delete();
        $teks = $this->teksTampil($this->get('/')->assertOk()->getContent());

        $this->assertStringNotContainsString('yang pertama', $teks);
        $this->assertStringNotContainsString('lokasi pertama yang memakai', $teks);
    }

    /**
     * Frasa "yang pertama" tidak boleh bocor ke bagian lain halaman.
     *
     * Ini menangkap perubahan yang lebih halus daripada sekadar angkanya
     * salah: memindahkan kata "yang pertama" ke subjudul atau bullet fitur
     * di section lain, di mana artinya sudah tidak lagi "hanya ada satu
     * lokasi". Setelah itu halaman terlihat memakai framing yang sama,
     * padahal tidak.
     */
    #[Test]
    public function framing_pertama_hanya_berlaku_di_blok_angka(): void
    {
        $this->makeTenant('satu');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'class="pengguna-pertama"'),
            '"yang pertama" hanya boleh muncul di bawah angka lokasi.'
        );
    }

    /* ================================================================
     | PEMBANTU
    |============================================================
     */

    /**
     * HTML yang benar-benar dibaca pembaca, tanpa `<style>` dan `<script>`.
     *
     * Halaman ini memakai komentar penjelasan yang panjang DI DALAM `<style>`
     * dan DI DALAM `{{-- --}}`. Isinya sudah memuat kalimat yang sama dengan
     * yang dirender -- misalnya "jenis usaha terlayani" -- jadi assertion
     * "kalimat ini tidak boleh tampil" akan gagal oleh alasan yang salah.
     *
     * Yang dilindungi di sini adalah apa yang TERLIHAT dan yang dibacakan
     * pembaca layar. Untuk DOM, atribut, dan tautan, assertion harus tetap
     * berjalan di HTML mentah -- dan test domain memang begitu, karena
     * `<a href>` ke subdomain tidak akan pernah muncul sebagai teks.
     */
    private function teksTampil(string $html): string
    {
        $tanpaBlok = preg_replace(
            '#<(style|script)\b[^>]*>.*?</\1>#is',
            '',
            $html
        );

        return html_entity_decode(
            strip_tags($tanpaBlok ?: $html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    private function makeTenant(string $slug, array $tambahan = []): void
    {
        $central = DB::connection(self::CENTRAL);

        $slug = $this->prefix.$slug;

        $central->table('tenants')->insert(array_merge([
            'slug' => $slug,
            'nama' => ucfirst($slug),
            'nama_lembaga' => 'Lembaga '.$slug,
            'database' => 'astaloka_'.str_replace('-', '_', $slug),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $tambahan));

        $this->tenantIds[] = (int) $central->table('tenants')
            ->where('slug', $slug)
            ->value('id');
    }
}
