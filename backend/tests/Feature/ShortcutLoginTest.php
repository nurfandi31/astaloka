<?php

namespace Tests\Feature;

use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Login pintasan `/login/user`: daftar user LOKASI INI + masuk tanpa password.
 *
 * Yang paling penting diuji di sini justru hal yang TIDAK terlihat dari
 * pemakaian normal:
 *
 *  1. Password yang ditampilkan HANYA yang benar-benar cocok dengan password
 *     demo. Kalau ini bocor hash atau menampilkan password salah, seluruh
 *     alasan dibacanya `Hash::check()` hilang.
 *  2. Gerbang tagihan tetap berlaku. Pintasan tanpa password yang sekaligus
 *     melewati penagihan akan membuat `POST /login` jadi tidak berarti.
 *  3. Endpointnya TIDAK boleh menyentuh lokasi lain. Ini yang paling mudah
 *     rusak diam-diam: `user_id` yang sama ada di setiap database lokasi, jadi
 *     "user id 1" di satu lokasi bukan "user id 1" di lokasi lain.
 *  4. `enabled=false` mematikan keduanya dengan 404, dan route-nya tetap
 *     terdaftar (tidak 500) supaya salah konfigurasi itu kelihatan.
 *
 * `RefreshDatabase` SENGAJA TIDAK dipakai -- alasannya sama seperti di
 * `InvoiceLockTest`: setiap request melewati `ResolveTenant` yang membuka
 * koneksi PDO sendiri, sehingga data user uji harus benar-benar ter-commit.
 */
class ShortcutLoginTest extends TestCase
{
    use EnsuresCentralTables;

    private const CENTRAL = 'central';

    private string $prefix;

    private Tenant $tenant;

    /** Database yang dibuat test ini, untuk dibuang di `tearDown()`. */
    private array $databaseDibuat = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'shortcut-'.uniqid().'-';

        $this->runCentralMigrations();

        // `database` menunjuk database UJI yang sama dengan koneksi default,
        // jadi user uji benar-benar bisa ditemukan lewat `runFor()`.
        $this->tenant = Tenant::create([
            'slug' => $this->prefix.'pdam',
            'nama' => 'PDAM Uji Pintasan',
            'nama_lembaga' => 'PDAM Uji Pintasan Bersama',
            'database' => (string) config('database.connections.mysql.database'),
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $lokasi = DB::connection('mysql');

            $userIds = $lokasi->table('users')
                ->where('email', 'like', $this->prefix.'%')
                ->pluck('id');

            if ($userIds->isNotEmpty()) {
                $lokasi->table('personal_access_tokens')
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();

                $lokasi->table('users')->whereIn('id', $userIds)->delete();
            }

            $central = DB::connection(self::CENTRAL);

            $ids = $central->table('tenants')
                ->where('slug', 'like', $this->prefix.'%')
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $central->table('tenant_invoice_items')
                    ->whereIn('tenant_invoice_id', function ($q) use ($central, $ids) {
                        $q->select('id')->from('tenant_invoices')->whereIn('tenant_id', $ids);
                    })
                    ->delete();

                $central->table('tenant_invoices')->whereIn('tenant_id', $ids)->delete();
                $central->table('tenants')->whereIn('id', $ids)->delete();

                $central->table('audit_logs')->whereIn('tenant_id', $ids)->delete();
            }

            // PENTING: penghapusan `audit_logs` DI DALAM `if ($ids->isNotEmpty())`
            // di atas, dan itu bukan kerapian.
            //
            // `where('tenant_id', $ids)` dengan `$ids` KOSONG tidak berarti
            // "tanpa filter" -- ia tetap menempel satu placeholder `?`, lalu
            // PDO melempar `SQLSTATE[HY093]: Invalid parameter number` karena
            // tidak ada nilai untuknya. Sebelumnya baris itu berada DI LUAR
            // blok `if`, jadi teardown praktis selalu gagal tepat di situ --
            // dan karena berada SEBELUM loop DROP di bawah, tidak satu pun
            // database uji yang dibuat test ikut terjatuhkan.
            //
            // Gejalanya tidak terlihat dari hasil test: test-nya hijau semua,
            // hanya menyisakan 1-3 database `shortcut_*_terpisah` utuh di
            // MySQL setiap kali suite dijalankan. Setelah beberapa puluh run,
            // `SHOW DATABASES` jadi penuh nama uji yang tidak pernah
            // dijelaskan apa pun.
            //
            // Hapus per-nama, bukan `information_schema`-wide: nama database
            // yang dibuat di sini selalu berawalan prefix test, jadi tidak
            // ada yang bisa ikut terhapus tanpa sengaja. Pola yang sama dipakai
            // `CentralTenantProvisionTest`.
            foreach ($this->databaseDibuat as $nama) {
                $central->statement("DROP DATABASE IF EXISTS `{$nama}`");
            }
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    private function userLokasi(array $attrs = []): User
    {
        return User::create($attrs + [
            'name' => 'Admin Pintasan',
            'email' => $this->prefix.'admin@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    /**
     * Header yang membuat request ini dilayani oleh lokasi di atas.
     *
     * `TENANCY_LOOKUP` di `phpunit.xml` tidak diatur, jadi `.env` yang dipakai
     * test ikut terbaca. Kalau `.env` development memakai `TENANCY_LOOKUP=slug`
     -- dan memang begitu -- header `X-Tenant-Slug` dipakai untuk menentukan
     * lokasi. Kalau environment-nya `host`, `slugAllowed()` tetap mengizinkan
     * header selama environment bukan `production`, jadi header ini bekerja di
     * kedua mode.
     *
     * Dipakai di SEMUA test yang memanggil endpoint, karena tanpa lokasi yang
     * ter-resolve jawabannya 404 `NO_TENANT` -- bukan hasil yang sedang diuji.
     */
    private function asLokasiAktif(): static
    {
        return $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /**
     * Buat satu tenant dengan databaseNYA SENDIRI.
     *
     * Dipakai untuk menguji isolasi antar lokasi. Dua tenant yang menunjuk
     * database yang sama tidak menguji apa pun: user-nya ada di kedua tempat,
     * jadi setiap percobaan "penggunaan silang" akan berhasil.
     *
     * `$buatTabelUsers` membedakan dua kondisi yang berbeda failure-nya:
     *
     *   `true`  -> tabel ada tapi kosong. User dari lokasi lain tidak ditemukan
     *              -> 422.
     *   `false` -> tabelnya tidak ada sama sekali. Query meledak -> 503.
     *
     * Keduanya harus punya test-nya sendiri; kalau digabung jadi satu, hanya
     * salah satu yang akan pernah diuji.
     *
     * Database yang dibuat DIBUANG di `tearDown()` -- tanpa itu, setiap run
     * `php artisan test` meninggalkan satu database utuh di MySQL.
     *
     * Namanya dibangun dari prefix test dan hanya berisi huruf, angka,
     * underscore, dan dash, jadi aman disisipkan apa adanya ke
     * `CREATE DATABASE` (MySQL tidak menerima placeholder di sana).
     */
    private function buatLokasiTerpisah(bool $buatTabelUsers = true): Tenant
    {
        $nama = str_replace('-', '_', $this->prefix.'terpisah');

        $central = DB::connection(self::CENTRAL);

        $central->statement("DROP DATABASE IF EXISTS `{$nama}`");
        $central->statement("CREATE DATABASE `{$nama}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->databaseDibuat[] = $nama;

        if ($buatTabelUsers) {
            // Cuma `id` + `email`: yang dibutuhkan controller adalah query
            // `where('id', ...)` dan `first()`. Tabel penuhnya sudah ada di
            // database uji utama.
            $central->statement(
                "CREATE TABLE `{$nama}`.`users` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(255) NOT NULL,
                    `email` VARCHAR(255) NOT NULL,
                    `role` VARCHAR(50) NOT NULL DEFAULT 'pelanggan',
                    `password` VARCHAR(255) NOT NULL,
                    `created_at` TIMESTAMP NULL,
                    `updated_at` TIMESTAMP NULL
                )"
            );
        }

        return Tenant::create([
            'slug' => $this->prefix.'terpisah',
            'nama' => 'PDAM Uji Terpisah',
            'database' => $nama,
            'is_active' => true,
        ]);
    }

    /*
     * =====================================================================
     * DAFTAR USER
     * =====================================================================
     */

    #[Test]
    public function daftar_user_mengembalikan_nama_email_dan_role(): void
    {
        $user = $this->userLokasi();

        $this->asLokasiAktif()
            ->getJson('/api/login-user')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [[
                    'tenant_id',
                    'slug',
                    'nama',
                    'label',
                    'users' => [['id', 'name', 'email', 'role', 'password']],
                ]],
            ])
            ->assertJsonPath("data.0.users.0.email", $user->email)
            ->assertJsonPath("data.0.users.0.name", 'Admin Pintasan')
            ->assertJsonPath("data.0.users.0.role", 'admin');
    }

    /**
     * Yang dilayani HANYA lokasi yang sedang melayani request.
     *
     * Ini test yang mengunci seluruh perubahan ini. Dulu endpoint ini
     * membaca SETIAP lokasi aktif dan mengembalikan semuanya dalam satu
     * respons -- jadi user dari lokasi lain ikut terlihat, dan `user_id` yang
     * sama bisa dipetakan ke akun yang berbeda di database berbeda.
     *
     * Yang diuji bukan cuma "lokasi lain tidak muncul" (itu bisa terjadi
     * kebetulan kalau lokasi lain kebetulan tidak punya user), tapi
     * "respons hanya berisi SATU grup, dan grup itu milik lokasi yang
     * dilayani" -- dengan lokasi lain yang nyata-nyata punya user sendiri.
     */
    #[Test]
    public function daftar_hanya_menampilkan_user_dari_lokasi_yang_dilayani(): void
    {
        $userLokal = $this->userLokasi();

        // Lokasi kedua dengan database sendiri, berisi user dengan `id` yang
        // sama persis. Kalau endpoint masih membaca lintas lokasi, user ini
        // akan muncul di respons dan `id`-nya bentrok dengan yang di atas.
        $tenantLain = $this->buatLokasiTerpisah();

        DB::connection('central')->statement(
            "INSERT INTO `{$tenantLain->database}`.`users`
                (`id`, `name`, `email`, `role`, `password`, `created_at`, `updated_at`)
             VALUES (1, 'Admin Lokasi Lain', 'lain@pdam.test', 'admin', 'x', NOW(), NOW())"
        );

        $res = $this->asLokasiAktif()
            ->getJson('/api/login-user')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame(
            $this->tenant->id,
            $res->json('data.0.tenant_id'),
            'Grup yang dikirim harus milik lokasi yang sedang dilayani.'
        );

        $this->assertSame(
            [$userLokal->email],
            array_column($res->json('data.0.users'), 'email'),
            'User lokasi lain tidak boleh ikut terbaca.'
        );
    }

    /**
     * Password demo ditampilkan apa adanya.
     *
     * Ini satu-satunya password yang bisa ditampilkan, dan hanya karena nilainya
     * sudah tertulis di README dan dipakai seeder.
     */
    #[Test]
    public function password_demo_ditampilkan_apa_adanya(): void
    {
        $this->userLokasi();

        $this->asLokasiAktif()
            ->getJson('/api/login-user')
            ->assertOk()
            ->assertJsonPath('data.0.users.0.password', 'password');
    }

    /**
     * Password yang SUDAH diganti user tidak boleh ditampilkan.
     *
     * Ini arah yang paling merusak kalau bocor: menuliskan password yang salah
     * membuat user mengira sandinya itu `rahasia-2026`, lalu mengetiknya
     * manual di `/login` dan gagal -- dan dia tidak akan pernah tahu kalau
     * teks yang ditampilkan memang bukan sandinya.
     *
     * Yang tampil harus `null`, supaya frontend merender `••••••`.
     */
    #[Test]
    public function password_yang_sudah_diganti_tidak_ditampilkan(): void
    {
        $this->userLokasi(['password' => Hash::make('rahasia-2026')]);

        $this->asLokasiAktif()
            ->getJson('/api/login-user')
            ->assertOk()
            ->assertJsonPath('data.0.users.0.password', null);
    }

    /**
     * Hash bcrypt tidak boleh bocor ke respons dalam bentuk apa pun.
     *
     * Yang dikirim hanya `password` berupa teks demo atau `null`. Kolom
     * `password` milik model di-`select` eksplisit justru untuk dibandingkan
     * dengan `Hash::check()`, jadi yang perlu dijaga adalah tidak ikut
     * ter-serialize ke JSON.
     */
    #[Test]
    public function hash_bcrypt_tidak_pernah_keluar_di_respons(): void
    {
        $this->userLokasi();

        $raw = $this->asLokasiAktif()->getJson('/api/login-user')->getContent();

        $this->assertStringNotContainsString('$2y$', $raw);
        $this->assertStringNotContainsString('$argon', $raw);
    }

    /**
     * `reveal_password=false` menyembunyikan SEMUA password, termasuk yang demo.
     */
    #[Test]
    public function reveal_password_mati_menyembunyikan_semua(): void
    {
        config()->set('shortcut_login.reveal_password', false);

        $this->userLokasi();

        $this->asLokasiAktif()
            ->getJson('/api/login-user')
            ->assertOk()
            ->assertJsonPath('data.0.users.0.password', null);
    }

    /**
     * Lokasi tanpa user membalas dengan satu grup kosong -- bukan daftar kosong.
     *
     * Bentuk respons tetap "satu grup lokasi", karena yang berubah hanya
     * isinya. Daftar kosong tanpa grup akan berarti "tidak ada lokasi", dan
     * itu kesimpulan yang berbeda: frontend perlu tahu lokasi mana yang
     * sedang dilayani supaya bisa menampilkan namanya.
     */
    #[Test]
    public function lokasi_tanpa_user_tetap_membalas_satu_grup_kosong(): void
    {
        $this->asLokasiAktif()
            ->getJson('/api/login-user')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tenant_id', $this->tenant->id)
            ->assertJsonPath('data.0.users', []);
    }

    /*
     * =====================================================================
     * LOGIN PINTASAN
     * =====================================================================
     */

    /**
     * Token terbit tanpa password sama sekali -- inilah tujuannya.
     */
    #[Test]
    public function login_pintasan_terbitkan_token_tanpa_password(): void
    {
        $user = $this->userLokasi(['password' => Hash::make('rahasia-2026')]);

        $res = $this->asLokasiAktif()->postJson('/api/login-user/login', [
            'user_id' => $user->id,
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertNotEmpty($res->json('data.token'));
    }

    /**
     * Slug lokasi ikut dikirim balik, supaya frontend bisa menyimpannya.
     *
     * Tanpa ini, `localStorage.tenant_slug` yang tertinggal dari sesi
     * sebelumnya tetap dipakai untuk SETIAP request sesudahnya -- termasuk
     * `GET /api/branding`. Gejalanya: dashboard terbuka dengan benar tapi
     * branding-nya milik lokasi lain, dan tidak ada satu pun request yang
     * gagal.
     */
    #[Test]
    public function login_mengirim_slug_lokasi_yg_dilayani(): void
    {
        $user = $this->userLokasi();

        $this->asLokasiAktif()
            ->postJson('/api/login-user/login', ['user_id' => $user->id])
            ->assertOk()
            ->assertJsonPath('data.tenant_slug', $this->tenant->slug);
    }

    /**
     * Token yang terbit harus benar-benar bisa dipakai.
     *
     * Assert 200 dari endpoint login saja tidak cukup: bisa jadi token-nya
     * tidak pernah disimpan, atau disimpan di database yang salah sehingga
     * Sanctum tidak menemukannya. Test ini memakai token itu untuk request
     * ber-autentikasi sungguhan.
     */
    #[Test]
    public function token_pintasan_bisa_dipakai_untuk_request_berikutnya(): void
    {
        $user = $this->userLokasi();

        $token = $this->asLokasiAktif()
            ->postJson('/api/login-user/login', ['user_id' => $user->id])
            ->json('data.token');

        $this->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertOk()
            // `AuthController::me()` membungkus payload-nya di `data`. Assert
            // ke `email` polos akan lolos dengan nilai null dan tidak pernah
            // menyinggung kalau bentuk responsnya berubah -- bukan karena
            // token-nya salah, tapi karena salah tempat mengira.
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'admin');
    }

    /**
     * `tenant_id` LAMA harus diabaikan, bukan diartikan.
     *
     * Frontend versi lama masih mengirim `tenant_id`. Kalau backend memakainya,
     * `tenant_id` itu menjadi jalur untuk mengarahkan token ke lokasi lain --
     * persis celah yang sedang ditutup. Mengabaikannya membuat permintaan
     * versi lama tetap dilayani untuk lokasi yang sedang aktif.
     */
    #[Test]
    public function tenant_id_yang_lama_dikirim_tidak_berpengaruh(): void
    {
        $user = $this->userLokasi();

        $tenantLain = $this->buatLokasiTerpisah();

        $this->asLokasiAktif()
            ->postJson('/api/login-user/login', [
                'tenant_id' => $tenantLain->id,
                'user_id' => $user->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.tenant_slug', $this->tenant->slug);
    }

    /**
     * User ID milik lokasi lain tidak boleh bisa dipakai.
     *
     * Endpoint ini hanya membaca database lokasi yang sedang dilayani. Jadi
     * `user_id` yang ada di lokasi LAIN harus dianggap tidak ada -- bukan
     * "cari di database lain".
     *
     * Di sini lokasi yang dilayani adalah lokasi TERPISAH -- database sendiri
     * dengan tabel `users` yang benar-benar ADA tapi KOSONG. Dua tenant yang
     * menunjuk database yang sama tidak menguji apa pun: user-nya ada di sana
     * juga, dan test-nya jadi kosong tanpa pernah gagal.
     */
    #[Test]
    public function user_dari_lokasi_lain_tidak_bisa_dipakai(): void
    {
        // User ini hidup di database lokasi UTAMA, bukan di lokasi terpisah.
        $user = $this->userLokasi();

        $tenantLain = $this->buatLokasiTerpisah();

        $this->withHeader('X-Tenant-Slug', $tenantLain->slug)
            ->postJson('/api/login-user/login', ['user_id' => $user->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User tidak ditemukan di lokasi ini.');
    }

    /**
     * User ID yang tidak ada sama sekali juga ditolak.
     */
    #[Test]
    public function user_id_tidak_ada_ditolak(): void
    {
        $this->asLokasiAktif()
            ->postJson('/api/login-user/login', ['user_id' => 999999])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User tidak ditemukan di lokasi ini.');
    }

    /**
     * Database lokasi yang rusak membalas 503, bukan 500.
     *
     * Dua hal yang diuji sekaligus. Pertama, pesannya menjelaskan penyebabnya --
     * database lokasi tidak terbaca -- dan bukan error PHP yang tidak
     * menyinggung apa pun. Kedua, statusnya 503 karena masalahnya ada di server
     * dan mungkin hilang sendiri, bukan 422 yang berarti "inputmu salah";
     * input di sini benar.
     */
    #[Test]
    public function database_lokasi_rusak_membalas_503(): void
    {
        $this->userLokasi();

        // Database dibuat tapi TIDAK diberi tabel `users`: persis seperti lokasi
        // yang migrasinya belum selesai dijalankan, atau yang database-nya
        // dihapus tanpa registry ikut dibersihkan.
        $tenantRusak = $this->buatLokasiTerpisah(buatTabelUsers: false);

        $this->withHeader('X-Tenant-Slug', $tenantRusak->slug)
            ->postJson('/api/login-user/login', ['user_id' => 1])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Database lokasi tidak bisa dibuka. Periksa lokasi `'.$tenantRusak->slug.'` di panel pusat.');
    }

    /**
     * Satu lokasi rusak tidak boleh menggagalkan lokasi yang sehat.
     *
     * Dulu `index()` melewati seluruh lokasi aktif, dan satu database yang
     * hilang membuat seluruh halaman 500 -- user di lokasi lain yang
     * sebenarnya baik-baik saja ikut kehilangan akses.
     *
     * Sekarang hanya ada satu lokasi per request, jadi ini diuji dari sisi
     * sebaliknya: lokasi yang rusak TIDAK boleh ikut muncul sebagai baris
     * di daftar lokasi yang sehat, dan halaman yang sehat tetap jalan.
     */
    #[Test]
    public function lokasi_rusak_tidak_menggagalkan_daftar_lokasi_sehat(): void
    {
        $user = $this->userLokasi();

        $tenantRusak = $this->buatLokasiTerpisah(buatTabelUsers: false);

        $res = $this->asLokasiAktif()
            ->getJson('/api/login-user')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame(
            $this->tenant->id,
            $res->json('data.0.tenant_id'),
            'Hanya lokasi yang dilayani yang boleh tampil.'
        );

        $this->assertNotSame(
            $tenantRusak->id,
            $res->json('data.0.tenant_id'),
            'Lokasi yang database-nya rusak tidak boleh ikut masuk daftar.'
        );

        $this->assertSame($user->email, $res->json('data.0.users.0.email'));
    }

    /**
     * Request tanpa lokasi yang ter-resolve membalas 404 `NO_TENANT`.
     *
     * Bukan 503 dan bukan daftar kosong. 503 membuat frontend mencoba lagi
     * (padahal request-nya akan selalu sama), sedangkan daftar kosong terlihat
     * seperti lokasi yang memang belum punya akun.
     */
    #[Test]
    public function tanpa_lokasi_membalas_404_no_tenant(): void
    {
        $this->getJson('/api/login-user')
            ->assertStatus(404)
            ->assertJsonPath('code', 'NO_TENANT')
            ->assertJsonPath('success', false);
    }

    /**
     * Tanpa lokasi, login juga ditolak -- dan tidak pernah jadi token.
     */
    #[Test]
    public function login_tanpa_lokasi_membalas_404_no_tenant(): void
    {
        $this->postJson('/api/login-user/login', ['user_id' => 1])
            ->assertStatus(404)
            ->assertJsonPath('code', 'NO_TENANT');
    }

    #[Test]
    public function parameter_wajib_divalidasi(): void
    {
        $this->asLokasiAktif()
            ->postJson('/api/login-user/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    /*
     * =====================================================================
     * GERBANG TAGIHAN
     * =====================================================================
     */

    /**
     * Pintasan tidak boleh jadi jalan melewati penagihan.
     *
     * Tanpa test ini, `/login/user` menjadi cara paling mudah masuk ke lokasi
     * yang `POST /login` menolak dengan 402 -- dan popup tagihan di halaman
     * login resmi jadi tidak berarti.
     */
    #[Test]
    public function login_pintasan_ditolak_saat_tagihan_belum_lunas(): void
    {
        $user = $this->userLokasi();

        TenantInvoice::create([
            'tenant_id' => $this->tenant->id,
            'nomor' => 'INV/'.date('Y').'/SHORTCUT-'.$this->prefix,
            'tgl_invoice' => now()->subDays(30)->toDateString(),
            'jumlah' => 1_500_000,
            'status' => 'UNPAID',
        ]);

        $this->asLokasiAktif()
            ->postJson('/api/login-user/login', ['user_id' => $user->id])
            ->assertStatus(402)
            ->assertJsonPath('code', 'INVOICE_UNPAID')
            ->assertJsonPath('data.tagihan_terkunci', 1)
            ->assertJsonPath('data.total_tagihan', 1500000);
    }

    /**
     * Endpoint daftar harus tetap bisa dibaca walau tagihannya menunggak.
     *
     * `EnforceInvoiceStatus` memblokir request yang punya sesi; endpoint ini
     * memang tidak punya sesi, jadi tidak ada yang perlu dilindungi -- tapi
     * daftar yang ikut terblokir akan membuat halaman `/login/user` ikut mati
     * tepat saat paling dibutuhkan.
     */
    #[Test]
    public function daftar_user_tetaks_terbaca_saat_tagihan_menunggak(): void
    {
        $this->userLokasi();

        TenantInvoice::create([
            'tenant_id' => $this->tenant->id,
            'nomor' => 'INV/'.date('Y').'/SHORTCUT-LIST-'.$this->prefix,
            'tgl_invoice' => now()->subDays(30)->toDateString(),
            'jumlah' => 1_500_000,
            'status' => 'UNPAID',
        ]);

        $this->asLokasiAktif()->getJson('/api/login-user')->assertOk();
    }

    /*
     * =====================================================================
     * MATIKAN LEWAT CONFIG
     * =====================================================================
     */

    /**
     * `enabled=false` harus membalas 404, bukan 500.
     *
     * 500 membuat orang mengira aplikasinya rusak; 404 sambil menyuruh
     * menyalakan flag-nya langsung memberi tahu apa yang harus dilakukan.
     */
    #[Test]
    public function dimatikan_lewat_config_membalas_404(): void
    {
        config()->set('shortcut_login.enabled', false);

        $this->getJson('/api/login-user')->assertStatus(404);

        $this->postJson('/api/login-user/login', ['user_id' => 1])->assertStatus(404);
    }

    /**
     * Endpoint WAJIB di luar `auth:sanctum`.
     *
     * Test ini ada untuk mengunci keputusan itu. Kalau suatu saat ada yang
     * "membuat rapi" dengan memindahkan route-nya ke dalam grup terautentikasi,
     * pintasan ini mati total -- dan gejalanya (halaman kosong) tidak
     * menyuruh siapa pun curiga ke group middleware.
     */
    #[Test]
    public function endpoint_terbuka_tanpa_token(): void
    {
        $this->userLokasi();

        // Tidak ada header Authorization sama sekali.
        $this->asLokasiAktif()->getJson('/api/login-user')->assertOk();
    }
}
