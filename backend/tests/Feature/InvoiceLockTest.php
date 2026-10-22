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
 * Penguncian aplikasi lokasi karena tagihan langganan belum lunas.
 *
 * Yang diuji di sini adalah satu aturan yang berlaku di tiga tempat:
 * login ditolak, request setelah login dibalas 402, dan ringkasan untuk
 * popup melaporkan hal yang sama. Kalau ketiganya menyimpang, gejalanya
 * adalah user yang "berhasil login tapi halamannya kosong" -- dan itu
 * tidak akan terlihat di mana pun kecuali lewat test seperti ini.
 *
 * Catatan penting soal `invoice_block_on_unpaid`:
 *
 *  Default (true) memblokir SETIAP invoice UNPAID, termasuk yang belum lewat
 *   jatuh tempo. Test `tagihan_belum_lewat_tempo_tetap_memblokir` menjaga
 *   itu, karena itulah perubahan perilaku dari versi lama -- dan perubahan
 *   seperti itu mudah hilang tanpa disadari saat ada refactor lain.
 */
class InvoiceLockTest extends TestCase
{
    use EnsuresCentralTables;

    private const CENTRAL = 'central';

    /*
     * `RefreshDatabase` SENGAJA TIDAK dipakai di test ini.
     *
     * Trait itu membungkus HANYA koneksi default dalam transaksi. Tapi
     * setiap request di dalam test melewati `ResolveTenant`, yang
     * mengarahkan `database.default` ke koneksi `tenant` -- koneksi PDO
     * yang berbeda, meski menunjuk database yang sama. User uji ditulis
     * lewat koneksi default, jadi saat request membacanya lewat koneksi
     * `tenant`, datanya masih belum di-commit: yang terlihat di sisi sana
     * hanya transaksi kosong, dan setiap login berakhir "Cek email atau
     * password".
     *
     * Menambah `tenant` ke `connectionsToTransact()` tidak menolong --
     * dua transaksi pada dua koneksi tetap tidak saling melihat. Yang
     * benar adalah membiarkan datanya benar-benar di-commit.
     *
     * Konsekuensinya, test ini harus membersihkan sisanya sendiri. Aman
     * karena dua hal: email user uji unik per run (lihat `emailLokasi()`)
     * dan tenant dibersihkan lewat `tearDown()` -- jadi sisa run yang
     * gagal tidak akan menabrak baris run berikutnya.
     */
    private string $prefix;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'invlock-'.uniqid().'-';

        $this->runCentralMigrations();

        /*
         * `database` menunjuk database UJI yang sama dengan koneksi default.
         *
         * Test suite ini single-DB (lihat catatan di `phpunit.xml`):
         * `DB_DATABASE` sekaligus jadi fallback koneksi `tenant` dan
         * satu-satunya database yang boleh disentuh. Kalau kolom `database`
         * diisi nama lain, `TenantManager::activate()` mengarahkan koneksi
         * ke database itu, user uji tidak ada di sana, dan setiap login
         * berakhir dengan "Cek email atau password" -- pesan yang sama
         * sekali tidak menyinggung penyebab sebenarnya.
         */
        $this->tenant = Tenant::create([
            'slug' => $this->prefix.'pdam',
            'nama' => 'PDAM Uji',
            'nama_lembaga' => 'PDAM Uji Bersama',
            'database' => (string) config('database.connections.mysql.database'),
            'is_active' => true,
        ]);

        /*
         * User dibuat di sini, bukan per-test.
         *
         * Hampir semua test di file ini Eventually memanggil `login()`,
         * dan `AuthController` memeriksa password DULU sebelum mengecek
         * tagihan. Test yang terlupa membuat user akan gagal dengan 422
         * "Cek email atau password" -- bukan karena aturan tagihannya
         * salah, tapi karena kredensialnya memang tidak ada. Itu pesan
         * yang sama sekali tidak menyinggung apa yang sebenarnya diuji.
         *
         * `userLokasi()` aman dipanggil ulang karena memakai
         * `firstOrCreate`, jadi test yang memanggilnya eksplisit tetap
         * tidak menabrak unique index `email`.
         */
        $this->userLokasi();
    }

    /**
     * Request yang diarahkan ke lokasi uji.
     *
     * Tanpa ini `ResolveTenant` tidak menemukan tenant sama sekali, sehingga
     * `InvoiceGate` selalu melihat daftar kosong dan tidak ada yang diblokir
     * -- test tetap hijau, tapi tidak menguji apa pun.
     *
     * Yang dikirim adalah `X-Tenant-Slug`, bukan host. `tenancy.lookup`
     * bernilai `slug` di environment test, dan `TenantResolver` mencoba
     * host lebih dulu lalu jatuh ke slug -- jadi header inilah yang
     * membuat tenant ditemukan. Host uji sengaja tidak dipakai: kalau
     * suatu saat `lookup` diubah ke `host`, test ini akan gagal dengan
     * "Cek email atau password", bukan dengan "tenant tidak ditemukan",
     * dan itu menyesatkan.
     */
    private function asLokasi(): self
    {
        return $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /**
     * Bersihkan sisa test ini.
     *
     * Dua database, dua cleanup. Yang di database pusat (registry tenant,
     * invoice, audit log) memakai filter `$this->prefix`, sedangkan yang di
     * database lokasi (user uji dan token Sanctum-nya) memakai email --
     * karena baris-baris itu tidak punya jejak lokasi yang bisa difilter
     * dari registry.
     *
     * `delete()` per-id, bukan `truncate()`: truncate menghapus seluruh
     * isi tabel, dan kalau konfigurasi database uji salah sasaran,
     * seluruh data development ikut hilang tanpa sisa. Pola yang sama
     * dipakai `CentralSuperadminTest::tearDown()`.
     */
    protected function tearDown(): void
    {
        try {
            $lokasi = DB::connection('mysql');

            $userIds = $lokasi->table('users')
                ->where('email', $this->emailLokasi())
                ->pluck('id');

            // Token lebih dulu: `personal_access_tokens` cascade dari
            // `users` di sebagian versi dan tidak di versi lain. Menghapus
            // eksplisit membuat test ini tidak bergantung pada itu.
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
                // Urutan penting: anak lebih dulu.
                //
                // `tenant_invoice_items` cascade dari `tenant_invoices`,
                // dan itu cascade dari `tenants` -- jadi menghapus
                // `tenants` saja sebenarnya sudah cukup. Dijamin eksplisit
                // supaya test ini tidak bergantung pada perilaku cascade
                // yang bisa berubah antar versi MySQL.
                $central->table('tenant_invoice_items')
                    ->whereIn('tenant_invoice_id', function ($q) use ($central, $ids) {
                        $q->select('id')->from('tenant_invoices')->whereIn('tenant_id', $ids);
                    })
                    ->delete();

                $central->table('tenant_invoices')->whereIn('tenant_id', $ids)->delete();
                $central->table('tenants')->whereIn('id', $ids)->delete();
            }

            $central->table('audit_logs')->where('tenant_id', $ids)->delete();
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    private function invoice(array $attrs = []): TenantInvoice
    {
        // Nomor unik lintas seluruh file test, bukan per tenant. Kalau ikut
        // per tenant, dua test yang sama-sama memakai tenant uji akan
        // menghasilkan nomor kembar -- dan unique index (tenant_id, nomor)
        // akan menolaknya.
        static $urut = 0;

        return TenantInvoice::create($attrs + [
            'tenant_id' => $this->tenant->id,
            'nomor' => 'INV/'.date('Y').'/'.str_pad((string) ++$urut, 4, '0', STR_PAD_LEFT),
            'tgl_invoice' => now()->subDays(10)->toDateString(),
            'jumlah' => 1_500_000,
            'status' => 'UNPAID',
        ]);
    }

    /** Email user uji, unik per run supaya tidak menabrak data run lain. */
    private function emailLokasi(): string
    {
        return 'admin-'.$this->prefix.'@pdam.test';
    }

    /**
     * User lokasi untuk login.
     *
     * Dipanggil dari beberapa test, jadi harus aman dipanggil lebih dari
     * sekali: `create` kedua akan menabrak unique index `email` dengan
     * pesan yang sama sekali tidak menyinggung penyebab sebenarnya.
     */
    private function userLokasi(): User
    {
        return User::firstOrCreate(
            ['email' => $this->emailLokasi()],
            [
                'name' => 'Admin Lokasi',
                'password' => Hash::make('rahasia123'),
                'role' => 'admin',
            ],
        );
    }

    /*
     * =====================================================================
     * LOGIN
     * =====================================================================
     */

    /**
     * Password benar tapi tagihan belum lunas -> 402, bukan 200.
     *
     * Status yang dipakai penting: 402 berarti "bayar dulu". 401 akan
     * dibaca interceptor axios sebagai sesi habis, yang menghapus token
     * dan melempar user ke /login -- popup tagihan tidak akan pernah
     * tampil, dan user melihat hasil yang sama berulang kali.
     */
    #[Test]
    public function login_ditolak_saat_tagihan_belum_lunas(): void
    {
        $this->invoice();

        $this->asLokasi()->postJson('/api/login', [
            'email' => $this->emailLokasi(),
            'password' => 'rahasia123',
        ])
            ->assertStatus(402)
            ->assertJsonPath('code', 'INVOICE_UNPAID')
            ->assertJsonPath('data.tagihan_terkunci', 1)
            ->assertJsonPath('data.total_tagihan', 1500000);
    }

    /**
     * Response 402 harus brought nomor dan nominal tagihan.
     *
     * Tanpa ini popup hanya menampilkan kalimat "tagihan belum lunas"
     * tanpa nominal, dan user tidak bisa-disabledcyt check ke pusat
     * apakah yang ditagih benar.
     */
    #[Test]
    public function login_402_membawa_rincian_tagihan(): void
    {
        $invoice = $this->invoice(['jumlah' => 2_750_000]);

        $res = $this->asLokasi()->postJson('/api/login', [
            'email' => $this->emailLokasi(),
            'password' => 'rahasia123',
        ])->assertStatus(402);

        $this->assertSame($invoice->nomor, $res->json('data.invoices.0.nomor'));
        $this->assertSame(2_750_000.0, (float) $res->json('data.invoices.0.jumlah'));
        $this->assertNotNull($res->json('data.invoices.0.deadline'));
    }

    /**
     * Password salah harus tetap 422, bukan ikut jadi 402.
     *
     * Kalau pengecekan tagihan berjalan sebelum verifikasi password,
     * balasan jadi 402 -- dan itu membocorkan bahwa email itu terdaftar.
     */
    #[Test]
    public function password_salah_tetap_422_bukan_402(): void
    {
        $this->invoice();
        $this->userLokasi();

        $this->asLokasi()->postJson('/api/login', [
            'email' => $this->emailLokasi(),
            'password' => 'salahsekali',
        ])->assertStatus(422);
    }

    /**
     * Tidak ada tagihan -> login seperti biasa.
     */
    #[Test]
    public function login_berhasil_bila_tidak_ada_tagihan(): void
    {
        $this->userLokasi();

        $this->asLokasi()->postJson('/api/login', [
            'email' => $this->emailLokasi(),
            'password' => 'rahasia123',
        ])->assertOk()->assertJsonPath('success', true);
    }

    /**
     * Invoice yang sudah LUNAS tidak boleh memblokir.
     *
     * Ini arah kesalahan yang paling merusak: setelah(center) pusat
     * menekan "Tandai Lunas", user lokasi harus bisa langsung masuk lagi.
     * Kalau ini gagal, satu klik di panel pusat mengunci lokasi secara
     * permanen tanpa ada yang bisa membukanya dari sisi lokasi.
     */
    #[Test]
    public function invoice_lunas_tidak_memblokir(): void
    {
        $this->invoice([
            'status' => 'PAID',
            'tgl_lunas' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $this->userLokasi();

        $this->asLokasi()->postJson('/api/login', [
            'email' => $this->emailLokasi(),
            'password' => 'rahasia123',
        ])->assertOk();
    }

    /**
     * Tagihan yang belum lewat jatuh tempo TETAP memblokir.
     *
     * Ini perilaku yang diminta, dan berbeda dari versi lama yang hanya
     * memblokir yang sudah terlambat. Kalau suatu saat aturan ini diubah
     * kembali ke "hanya terlambat", test inilah yang akan gagal -- bukan
     * pengguna yang tiba-tiba tidak bisa login.
     */
    #[Test]
    public function tagihan_belum_lewat_tempo_tetap_memblokir(): void
    {
        $this->invoice([
            'tgl_invoice' => now()->toDateString(),
            'tgl_jatuh_tempo' => now()->addMonth()->toDateString(),
        ]);

        $this->asLokasi()->postJson('/api/login', [
            'email' => $this->emailLokasi(),
            'password' => 'rahasia123',
        ])->assertStatus(402);
    }

    /**
     * Beberapa tagihan sekaligus: semuanya ikut terkunci dan dijumlahkan.
     */
    #[Test]
    public function semua_tagihan_belum_lunas_ikut_memblokir(): void
    {
        $this->invoice(['jumlah' => 1_000_000]);
        $this->invoice(['jumlah' => 500_000]);
        $this->invoice(['jumlah' => 250_000, 'status' => 'PAID', 'tgl_lunas' => now()->toDateString()]);

        $res = $this->asLokasi()->postJson('/api/login', [
            'email' => $this->emailLokasi(),
            'password' => 'rahasia123',
        ])->assertStatus(402);

        $this->assertSame(2, $res->json('data.tagihan_terkunci'));
        $this->assertSame(1_500_000.0, (float) $res->json('data.total_tagihan'));
    }

    /*
     * =====================================================================
     * MIDDLEWARE (setelah login)
     * =====================================================================
     */

    /**
     * Sudah punya token, tapi tagihannya menunggak -> 402.
     *
     * Token TIDAK dicabut. Itulah yang membuat test ini penting: kalau
     * token ikut dicabut, `api/tagihan-saya` dan `api/logout` ikut mati
     * dan user terkunci tanpa jalan keluar -- persis pengucilan yang
     * EnforceInvoiceStatus dirancang untuk hindari.
     */
    #[Test]
    public function request_diblokir_402_saat_tunggak(): void
    {
        $user = $this->userLokasi();
        $this->invoice();

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->asLokasi()->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/customers')
            ->assertStatus(402)
            ->assertJsonPath('code', 'INVOICE_UNPAID');
    }

    /**
     * Endpoint-exempt harus tetap bisa diakses -- kalau tidak, user tidak
     * bisa melihat tagihannya maupun keluar.
     */
    #[Test]
    public function endpoint_exempt_tetap_bisa_diakses(): void
    {
        $user = $this->userLokasi();
        $invoice = $this->invoice();

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->asLokasi()->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tagihan-saya')
            ->assertOk()
            ->assertJsonPath('data.tagihan_terkunci', 1);

        $this->asLokasi()->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/tagihan-saya/summary')
            ->assertOk()
            ->assertJsonPath('data.tagihan_terkunci', 1);
    }

    /**
     * Setelah dilunasi, akses pulih TANPA login ulang.
     *
     * Inilah alasan token tidak dicabut: memaksa user logout
     * dan login lagi setiap kali tagihan dilunasi adalah pengalaman yang
     * jauh lebih buruk daripada popup yang hilang sendiri.
     */
    #[Test]
    public function akses_pulih_setelah_tagihan_dilunasi(): void
    {
        $user = $this->userLokasi();
        $invoice = $this->invoice();

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->asLokasi()->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/customers')
            ->assertStatus(402);

        $invoice->update([
            'status' => 'PAID',
            'tgl_lunas' => now()->toDateString(),
            'paid_at' => now(),
        ]);

        $this->asLokasi()->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/customers')
            ->assertOk();
    }

    /**
     * User lokasi TIDAK boleh menutup tagihannya sendiri.
     *
     * Kalau endpoint `paid` untuk user lokasi ada, penguncian ini hanya
     * formalitas: cukup satu klik untuk membuka diri sendiri.
     */
    #[Test]
    public function user_lokasi_tidak_boleh_menandai_lunas(): void
    {
        $user = $this->userLokasi();
        $this->invoice();

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->asLokasi()->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/tagihan-saya/1/lunas')
            ->assertStatus(404);
    }
}
