<?php

namespace Tests\Feature;

use App\Support\Branding;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Branding halaman login per lokasi: isi teks, warna, dan endpoint publiknya.
 *
 * Yang diuji di sini BUKAN "formnya menyimpan" -- itu soal satu baris
 * `update()`. Yang diuji adalah tiga hal yang kalau rusak tidak pernah muncul
 * sebagai error, dan muncul sebagai tampilan yang salah:
 *
 *   1. `GET /api/branding` mengembalikan branding TENANT yang melayani request,
 *      bukan tenant lain dan bukan bawaan.
 *   2. Panel pusat menampilkan keadaan SEBENARNYA: kolom kosong tetap kosong,
 *      bukan teks bawaan yang terlihat seolah-olah sengaja diisi.
 *   3. Kolom kosong kembali ke bawaan saat disimpan -- dan tidak diam-diam
 *      menjadi "selalu punya nilai".
 *
 * Poin 3 punya akibat yang nyata: kalau panel menampilkan teks bawaan untuk
 * kolom kosong, admin menyimpannya tanpa mengubah apa pun, dan setelah itu
 * "kosong = pakai bawaan" tidak akan pernah bisa dilakukan lagi untuk tenant
 * itu -- karena isinya sudah jadi nilai biasa yang tidak bisa dibedakan dari
 * yang sengaja diketik.
 *
 * CATATAN SOAL DATABASE PUSAT: sama seperti `CentralDomainTest`, tabel pusat
 * tidak ikut ter-refresh oleh `RefreshDatabase`, dan baris yang dibuat di sini
 * tidak ikut ter-rollback. Karena itu `setUp()` membersihkan sisa run
 * sebelumnya dan `tearDown()` menghapus eksplisit.
 */
class BrandingTest extends TestCase
{
    use EnsuresCentralTables;
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private const CENTRAL = 'central';

    private string $prefix;

    /** @var array<int, int> */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $central = DB::connection(self::CENTRAL);

        $this->prefix = 'brandtest-'.uniqid().'-';

        $this->runCentralMigrations();

        // Sisa run sebelumnya yang gagal di tengah jalan. Baris di database
        // pusat tidak ikut ter-rollback transaksi RefreshDatabase.
        $central->table('tenants')->where('slug', 'like', 'brandtest-%')->delete();
        $central->table('users')->where('email', 'like', 'brandtest-%@test.local')->delete();
    }

    protected function tearDown(): void
    {
        try {
            $central = DB::connection(self::CENTRAL);

            foreach ($this->tenantIds as $id) {
                $central->table('tenants')->where('id', $id)->delete();
            }

            $central->table('users')->where('email', 'like', 'brandtest-%@test.local')->delete();
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    /* ============================================================
     | ENDPOINT PUBLIK
    |============================================================
     */

    #[Test]
    public function endpoint_branding_mengembalikan_branding_bawaan_saat_tidak_ada_lokasi(): void
    {
        /*
         * Bukan 404.
         *
         * Halaman login di domain pusat memang tidak terjadi, tapi SPA tetap
         * memanggil endpoint ini saat boot. Balasan 404 di situ menjadi
         * request yang gagal tanpa pesan di halaman yang sedang tampil,
         * sementara branding bawaan membuat halaman tampil normal.
         */
        $res = $this->getJson('/api/branding');

        $res->assertOk();
        $this->assertSame(Branding::DEFAULT_JUDUL, $res->json('data.judul'));
        $this->assertSame(Branding::DEFAULT_WARNA, $res->json('data.warna'));
    }

    #[Test]
    public function endpoint_branding_tidak_membocorkan_field_tenant_lain(): void
    {
        $this->makeTenant('bocor', [
            'nama_lembaga' => 'PDAM Tersembunyi',
            'email' => 'rahasia@test.local',
            'alamat' => 'Jl. Rahasia No. 1',
            'telepon' => '08123456789',
            'database' => 'astaloka_rahasia',
        ]);

        $data = $this->getJson('/api/branding')->json('data');

        /*
         * Bentuk `branding` yang lengkap, tapi HANYA empat field itu.
         *
         * Empat field ini sudah tercetak di layar publik. Sisanya -- nama
         * database, email admin, alamat, telepon, tagihan -- tidak dibutuhkan
         * untuk merender halaman login, dan semuanya berguna untuk memetakan
         * infrastruktur internal.
         */
        $this->assertSame(
            ['judul', 'sub_judul', 'deskripsi', 'warna'],
            array_keys($data),
            'Endpoint publik mengembalikan field di luar branding.',
        );

        $json = json_encode($data);

        foreach (['PDAM Tersembunyi', 'rahasia@test.local', 'Jl. Rahasia', 'astaloka_rahasia'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, (string) $json);
        }
    }

    /* ============================================================
     | PENYIMPANAN DARI PANEL PUSAT
    |============================================================
     */

    #[Test]
    public function superadmin_bisa_menyimpan_branding(): void
    {
        $tenant = $this->makeTenant('simpan');

        Sanctum::actingAs($this->superadmin(), abilities: ['*']);

        $res = $this->putJson("/api/central/locations/{$tenant->id}", [
            'judul_login' => 'PDAM Sejahtera',
            'sub_judul_login' => 'Layanan Lebih Baik.',
            'deskripsi_login' => 'Setiap tegukan penting.',
            'warna_utama' => '#10B981',
        ]);

        $res->assertOk();

        $tersimpan = DB::connection(self::CENTRAL)->table('tenants')->where('id', $tenant->id)->first();

        $this->assertSame('PDAM Sejahtera', $tersimpan->judul_login);
        $this->assertSame('Layanan Lebih Baik.', $tersimpan->sub_judul_login);
        $this->assertSame('Setiap tegukan penting.', $tersimpan->deskripsi_login);

        /*
         * Huruf besar disimpan sebagai huruf kecil.
         *
         * Ini bukan kerapian: nilai ini dipakai apa adanya di `color-mix()`
         * dan di komputasi lightness frontend. Bentuk yang berbeda menghasilkan
         * warna yang berbeda tanpa error apa pun yang menyinggungnya -- dan
         * Panel pusat yang membandingkan hex untuk menandai preset terpilih
         * akan menganggap warna yang sama sebagai warna yang berbeda.
         */
        $this->assertSame('#10b981', $tersimpan->warna_utama);
    }

    #[Test]
    public function warna_bukan_hex_ditolak_dan_menyebut_fieldnya(): void
    {
        $tenant = $this->makeTenant('warnahalah');

        Sanctum::actingAs($this->superadmin(), abilities: ['*']);

        $res = $this->putJson("/api/central/locations/{$tenant->id}", [
            'warna_utama' => 'biru',
        ]);

        $res->assertStatus(422);

        /*
         * `warna_utama` harus muncul di `errors`.
         *
         * Kalau tidak, errornya jadi "422 dari mana saja" -- persis masalah
         * yang membuat daftar sektor ditulis ulang di frontend, dan yang di
         * sini berarti form menampilkan error di field yang SALAH sementara
         * field yang sebenarnya salah hanya diam saja.
         */
        $res->assertJsonValidationErrors('warna_utama');
    }

    #[Test]
    public function warna_enam_karakter_tanpa_hash_ditolak(): void
    {
        $tenant = $this->makeTenant('warnapanjang');

        Sanctum::actingAs($this->superadmin(), abilities: ['*']);

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'warna_utama' => '10b981',
        ])->assertStatus(422)->assertJsonValidationErrors('warna_utama');
    }

    /* ============================================================
     | KOLOM KOSONG = PAKAI BAWAAN
    |============================================================
     */

    #[Test]
    public function mengosongkan_teks_mengembalikan_ke_bawaan(): void
    {
        $tenant = $this->makeTenant('kosong', [
            'judul_login' => 'Judul Lama',
            'sub_judul_login' => 'Sub Judul Lama',
        ]);

        Sanctum::actingAs($this->superadmin(), abilities: ['*']);

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'judul_login' => null,
            'sub_judul_login' => null,
        ])->assertOk();

        $tersimpan = DB::connection(self::CENTRAL)->table('tenants')->where('id', $tenant->id)->first();

        $this->assertNull($tersimpan->judul_login);
        $this->assertNull($tersimpan->sub_judul_login);

        /*
         * Yang tampil di halaman login tetap bawaan -- `null` di database
         * tidak boleh berarti halaman kosong.
         */
        $model = $this->reload($tenant->id);

        $this->assertSame(Branding::DEFAULT_JUDUL, Branding::for($model)['judul']);
    }

    #[Test]
    public function spasi_saja_dianggap_kosong(): void
    {
        /*
         * Admin yang mengetik spasi lalu menyimpan tidak sedang meminta
         * judul kosong -- dia sedang mengetik judul yang tidak terlihat ada
         * isinya. Kalau spasi disimpan apa adanya, panel pusat akan
         * menampilkan isian yang isinya "ada" tapi tidak terbaca, dan
         * "kosong = pakai bawaan" akan rusak untuk isian itu saja.
         */
        $tenant = $this->makeTenant('spasi');

        Sanctum::actingAs($this->superadmin(), abilities: ['*']);

        $this->putJson("/api/central/locations/{$tenant->id}", [
            'judul_login' => '   ',
            'deskripsi_login' => "\n\t ",
        ])->assertOk();

        $tersimpan = DB::connection(self::CENTRAL)->table('tenants')->where('id', $tenant->id)->first();

        $this->assertNull($tersimpan->judul_login);
        $this->assertNull($tersimpan->deskripsi_login);
    }

    /* ============================================================
     | PANEL PUSAT: APA YANG DITAMPILKAN
    |============================================================
     */

    #[Test]
    public function panel_pusat_menampilkan_keadaan_sebenarnya_bukan_bawaan(): void
    {
        $tenant = $this->makeTenant('panel');

        Sanctum::actingAs($this->superadmin(), abilities: ['*']);

        $branding = $this->getJson("/api/central/locations/{$tenant->id}")
            ->json('data.branding');

        /*
         * `Branding::raw()`, bukan `Branding::for()`.
         *
         * Kalau panel menampilkan teks bawaan untuk kolom yang kosong, orang
         * akan mengira isian itu sudah sengaja diisi dan tidak akan pernah
         * menyentuhnya -- padahal yang sebenarnya terjadi, halamannya memakai
         * bawaan. Dan kalau ia menyimpannya tanpa mengubah apa pun, "kosong =
         * pakai bawaan" berhenti bisa dilakukan untuk tenant itu selamanya.
         */
        $this->assertNull($branding['judul']);
        $this->assertNull($branding['sub_judul']);
        $this->assertNull($branding['deskripsi']);

        // Teks bawaan tetap ikut -- form butuh untuk placeholder.
        $this->assertSame(Branding::DEFAULT_JUDUL, $branding['default_judul']);
        $this->assertSame(Branding::DEFAULT_SUB_JUDUL, $branding['default_sub_judul']);
        $this->assertSame(Branding::DEFAULT_WARNA, $branding['default_warna']);

        /*
         * Warna dikecualikan: `raw()` tetap menurunkan ke bawaan.
         *
         * Panel pusat memakai nilai itu untuk menggambar contoh warna, dan
         * contoh warna tidak bisa menggambar "tidak ada warna" -- yang akan
         * tampil sebagai kotak kosong dan dibaca sebagai bug, bukan sebagai
         * "pakai warna bawaan".
         */
        $this->assertSame(Branding::DEFAULT_WARNA, $branding['warna']);
    }

    #[Test]
    public function daftar_warna_preset_hanya_memakai_hex_yang_valid(): void
    {
        Sanctum::actingAs($this->superadmin(), abilities: ['*']);

        $res = $this->getJson('/api/central/warna')->assertOk();

        $daftar = $res->json('data');

        $this->assertNotEmpty($daftar, 'Daftar warna branding kosong.');

        foreach ($daftar as $warna) {
            $this->assertArrayHasKey('hex', $warna);
            $this->assertArrayHasKey('label', $warna);

            /*
             * Setiap preset harus lolos rule yang sama dengan validasi
             * `warna_utama`.
             *
             * Kalau ada preset yang tidak lolos, admin mengklik warna itu,
             * mengisi formnya, dan baru ditolak backend dengan 422 -- dan
             * karena errornya tidak menyebut warna mana, dia tidak punya cara
             * untuk mengetahuinya. Daftar preset harus BISA dipakai.
             */
            $this->assertMatchesRegularExpression(Branding::POLA_WARNA, $warna['hex']);
        }

        /*
         * Warna BAWAAN harus ada di daftar preset.
         *
         * Ini bukan kontradiksi dengan "kosong = pakai warna bawaan". Yang
         * berbeda adalah sengaja memilih warna bawaan (penyimpanannya jadi
         * nilai biasa, bisa diubah lagi) dan membiarkan kolomnya kosong
         * (selalu ikut bawaan, tidak punya nilai). Kalau warna bawaan tidak
         * ada di daftar, admin tidak punya cara untuk KEMBALI ke warna
         * bawaan tanpa mengetik hex-nya sendiri -- dan mengubah warna
         * kembali ke default akan jadi langkah yang hanya bisa dilakukan
         * kalau hafal formatnya.
         */
        $hexTersedia = array_column($daftar, 'hex');

        $this->assertContains(
            Branding::DEFAULT_WARNA,
            $hexTersedia,
            'Warna bawaan tidak bisa dipilih dari daftar preset.',
        );

        // Tidak ada warna yang sama dua kali: preset yang kembar tidak
        // menambah pilihan apa pun, hanya menambah satu klik.
        $this->assertSame(
            count($hexTersedia),
            count(array_unique($hexTersedia)),
            'Ada warna yang muncul lebih dari sekali di daftar preset.',
        );

        // Bentuk map `kunci => [hex, label]` di konstanta PHP tidak boleh
        // bocor ke frontend: yang dibutuhkan form adalah pasangan.
        foreach ($daftar as $warna) {
            $this->assertSame(array_keys($warna), ['hex', 'label']);
            $this->assertNotSame('', trim((string) $warna['label']));
        }
    }

    /* ============================================================
     | PEMBANTU
    |============================================================
     */

    private function makeTenant(string $slug, array $tambahan = []): object
    {
        $central = DB::connection(self::CENTRAL);

        $slug = $this->prefix.$slug;

        $central->table('tenants')->insert(array_merge([
            'slug' => $slug,
            'nama' => ucfirst($slug),
            'nama_lembaga' => 'Lembaga '.$slug,
            'database' => 'astaloka_'.str_replace('-', '_', $slug),
            'domain' => $slug.'.test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $tambahan));

        $id = (int) $central->table('tenants')->where('slug', $slug)->value('id');

        $this->tenantIds[] = $id;

        return $this->reload($id);
    }

    /** Baris tenant dari database pusat, sebagai model. */
    private function reload(int $id): object
    {
        $row = DB::connection(self::CENTRAL)->table('tenants')->where('id', $id)->first();

        return (new \App\Models\Central\Tenant)->forceFill((array) $row);
    }

    /**
     * Akun pusat untuk `Sanctum::actingAs()`.
     *
     * Diisi di memori saja, tidak disimpan: `Sanctum::actingAs()` butuh user
     * yang bisa di-resolve, dan test ini tidak pernah menyentuh endpoint yang
     * menuliskan token.
     */
    private function superadmin(): object
    {
        $central = DB::connection(self::CENTRAL);

        $email = $this->prefix.'admin@test.local';

        if (! $central->table('users')->where('email', $email)->exists()) {
            $central->table('users')->insert([
                'name' => 'Admin Pusat',
                'email' => $email,
                'password' => bcrypt('password'),
                'role' => 'superadmin',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = new \App\Models\User;

        $user->forceFill([
            'name' => 'Admin Pusat',
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => 'superadmin',
        ]);

        $user->id = $central->table('users')->where('email', $email)->value('id');

        return $user;
    }
}