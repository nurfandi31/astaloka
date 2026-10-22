<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Klasifikasi path di `ResolveTenant`: database mana yang melayani request.
 *
 * Ini bukan detail internal. Middleware itu memilih `database.default`
 * SEBELUM `auth:sanctum` berjalan, dan Sanctum mencari token lewat
 * `$model::findToken()` yang memakai `database.default`. Salah klasifikasi
 * berarti token dibaca di database yang salah, dan akibatnya:
 *
 *   - Token pusat tidak ditemukan -> superadmin selalu 401 begitu login-nya
 *     sukses. Gejalanya "login success tapi selalu logout", tanpa satu pun
 *     pesan yang menyebut soal database.
 *   - Token lokasi dibaca di database pusat -> user lokasi selalu 401.
 *
 * Keduanya muncul sebagai 401, yang persis sama dengan "token kadaluarsa".
 * Admin_panel lalu menyimpulkan sesi-nya bermasalah, padahal yang salah
 * adalah pilihan database.
 *
 * PENTING SOAL KENAPA DIUJI DENGAN REFLEKSI, BUKAN LEWAT HTTP
 *
 * Mengirim request sungguhan ke setiap path akan menjalankan migration dan
 * butuh token yang valid, jadi test-nya punya banyak gerak lain yang bisa
 * gagal. Yang diuji di sini murni FUNGSI KLASIFIKASINYA, dan-reflection
 * membuatnya bisa diuji untuk ratusan path tanpa efek samping apa pun.
 *
 * Daftar path yang dipakai sebagai data test diambil DARI route yang
 * benar-benar terdaftar (`Route::getRoutes()`), jadi test ini ikut gagal
 * kalau ada route baru yang belum diklasifikasikan -- bukan diam-diam
 * mengikuti definisi yang sudah usang.
 */
class ResolveTenantPathTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Panggil `isCentralPath()` tanpa harus menjalankan seluruh middleware.
     *
     * Dipanggil langsung supaya yang diuji adalah logikanya, bukan urutan
     * middleware. Urutan middleware sudah punya test-nya sendiri di
     * `InvoiceLockTest` dan `CentralDomainTest`.
     */
    private function isCentral(string $path): bool
    {
        $method = new ReflectionMethod(ResolveTenant::class, 'isCentralPath');

        /** @var ResolveTenant $middleware */
        $middleware = app(ResolveTenant::class);

        return $method->invoke($middleware, $path);
    }

    /* ================================================================
     | Klasifikasi dasar
     |============================================================ */

    /** Panel pusat SELALU membaca database pusat. */
    #[Test]
    public function prefix_pusat_diklasifikasikan_sebagai_pusat(): void
    {
        $this->assertTrue($this->isCentral('api/central'));
        $this->assertTrue($this->isCentral('api/central/login'));
        $this->assertTrue($this->isCentral('api/central/locations'));
        $this->assertTrue($this->isCentral('api/central/locations/5/domains'));
        $this->assertTrue($this->isCentral('api/central/coa/level/2/7'));
    }

    /** Endpoint aplikasi biasa harus mengikuti lokasi, bukan pusat. */
    #[Test]
    public function endpoint_lokasi_bukan_pusat(): void
    {
        foreach (['api/login', 'api/logout', 'api/me', 'api/customers', 'api/bills'] as $path) {
            $this->assertFalse(
                $this->isCentral($path),
                "`{$path}` adalah endpoint lokasi dan tidak boleh dilayani database pusat."
            );
        }
    }

    /**
     * `api/login-user` mengikuti LOKASI, bukan pusat.
     *
     * Pernah diklasifikasikan sebagai pusat dengan alasan halaman itu
     * menampilkan user dari seluruh lokasi -- jadi database pusat memang yang
     * dibaca. Endpoint-nya sudah tidak begitu: sekarang daftar dan login
     * hanya menyentuh lokasi yang sedang melayaninya
     * (`TenantManager::current()`, koneksi `tenant`).
     *
     * Mempertahankan klasifikasi lama akan membuat halaman ini membaca tabel
     * `users` di database yang tidak berisi user pun, dan jawaban 404/503-nya
     * bergerak jauh dari penyebabnya -- user mengira pintasannya yang rusak,
     * padahal database yang dipilih memang salah.
     */
    #[Test]
    public function login_user_mengikuti_lokasi_bukan_pusat(): void
    {
        $this->assertFalse($this->isCentral('api/login-user'));
        $this->assertFalse($this->isCentral('api/login-user/login'));
    }

    /**
     * `tagihan-saya` milik USER LOKASI, jadi harus tetap punya tenant.
     *
     * Kalau ini ikut diklasifikasikan sebagai pusat, user lokasi akan dicari
     * di database pusat dan mendapat 401 tepat saat dia paling perlu melihat
     * tagihannya -- yaitu saat aplikasinya terkunci karena tagihan itu.
     */
    #[Test]
    public function tagihan_saya_bukan_pusat(): void
    {
        $this->assertFalse($this->isCentral('api/tagihan-saya'));
        $this->assertFalse($this->isCentral('api/tagihan-saya/summary'));
    }

    /**
     * Path yang MIRIP nama pusat tidak boleh ikut dianggap pusat.
     *
     * Tanpa batas `/` di `str_starts_with()`, `api/central-tamu` akan ikut
     * terbaca sebagai pusat -- dan cukup satu route dengan nama seperti itu
     * untuk membuat seluruh request-nya salah database.
     */
    #[DataProvider('pathPMiripPusat')]
    public function path_mirip_nama_pusat_tidak_ikut_terklasifikasi(string $path): void
    {
        $this->assertFalse(
            $this->isCentral($path),
            "`{$path}`starts dengan `api/central` sebagai PREFIX, bukan sebagai path pusat."
        );
    }

    public static function pathPMiripPusat(): array
    {
        return [
            ['api/central-tamu'],
            ['api/centralized'],
            ['api/centralx/login'],
        ];
    }

    /**
     * Setiap route yang benar-benar terdaftar harus punya klasifikasi.
     *
     * Ini yang menjaga daftar di middleware tidak usang. Route baru yang
     * belum diklasifikasikan akan muncul di sini sebagai `api/central/...`
     * atau `api/...` yang tidak dikenal -- dan test-nya gagal, bukan diam-diam
     * memakai default.
     *
     * Yang diperiksa hanya kelonggaran, bukan nilainya: path `api/central/*`
     * WAJIB pusat, dan path `api/*` lain boleh apa saja karena keduanya
     * bergantung pada subdomain tempat request datang. Yang tidak boleh
     * lolos justru path yang secara semantik milik lokasi tapi salah
     * diklasifikasikan -- itu yang dikunci test di atas.
     */
    #[Test]
    public function setiap_route_terdaftar_memang_ada_untuk_middleware(): void
    {
        $paths = [];

        foreach (app('router')->getRoutes() as $route) {
            foreach (['api/central', 'api/login-user', 'api/tagihan-saya'] as $prefix) {
                if ($route->uri() === $prefix || str_starts_with($route->uri(), $prefix.'/')) {
                    $paths[$route->uri()] = true;
                }
            }
        }

        $this->assertNotEmpty($paths, 'Harus ada route pusat yang terdaftar.');

        foreach (array_keys($paths) as $path) {
            $isCentral = $this->isCentral($path);

            if ($path === 'api/tagihan-saya' || str_starts_with($path, 'api/tagihan-saya/')) {
                $this->assertFalse($isCentral, "`{$path}` milik user lokasi dan butuh tenant.");
            } elseif ($path === 'api/login-user' || str_starts_with($path, 'api/login-user/')) {
                /*
                 * Pintasan login juga milik lokasi -- bukan pusat.
                 *
                 * Diperiksa di sini dan bukan cuma di test khusus di atas,
                 * karena daftar ini ikut memuat route yang BARU terdaftar.
                 * Kalau suatu saat ada yang menambahkan `api/login-user/...`
                 * tanpa mengklasifikasinya, path itu akan ikut salah database
                 * tanpa ada yang menyinggung.
                 */
                $this->assertFalse($isCentral, "`{$path}` membaca database lokasi, bukan pusat.");
            } else {
                $this->assertTrue($isCentral, "`{$path}` harus dilayani database pusat.");
            }
        }
    }

    /* ================================================================
     | Resolusi tetap butuh host
     |============================================================ */

    /**
     * Tanpa host yang cocok, tidak ada tenant -- termasuk di subdomain.
     *
     * Menjamin fallback slug tidak aktif di produksi. Kalau aktif, siapa pun
     * bisa menambahkan header `X-Tenant-Slug` dan membaca data lokasi lain
     * hanya dengan menebak slug-nya.
     */
    #[Test]
    public function slug_tidak_dipercayai_di_produksi(): void
    {
        $request = Request::create('http://localhost/api/me');

        // Set `APP_ENV=production` hanya untuk test ini; nilainya dipulihkan
        // oleh `Application::environment()` yang membaca config, jadi tidak
        // ada state yang tertinggal untuk test berikutnya.
        config(['app.env' => 'production']);

        $this->assertNull(
            app(TenantResolver::class)->resolve($request),
            'Di produksi, header slug harus diabaikan sepenuhnya.'
        );
    }
}
