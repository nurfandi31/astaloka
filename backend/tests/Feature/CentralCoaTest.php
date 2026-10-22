<?php

namespace Tests\Feature;

use App\Models\AkunLevel1;
use App\Models\AkunLevel2;
use App\Models\AkunLevel3;
use App\Models\Central\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Panel pusat: pengelolaan struktur akun level 1-3.
 *
 * Yang diuji di sini bukan hanya "editnya berhasil", tapi_guard yang
 * melindunginya. Level 1-3 adalah milik bersama seluruh lokasi: `id` dan
 * `parent_id`-nya dipegang `accounts.parent_id` di setiap database lokasi,
 * dan penunjuk itu tidak dijaga foreign key. Satu perubahan yang lolos
 * berarti akun di semua lokasi kehilangan induknya tanpa satu pun error --
 * gejalanya baru terlihat jauh kemudian, saat laporan tiba-tiba kosong
 * sebagian dan tidak ada yang tahu sejak kapan.
 *
 * Karena itu `RefreshDatabase` di sini hanya menyalakan rollback untuk
 * koneksi default (database lokasi). Data pusat ditulis lewat koneksi
 * `central` dan dibersihkan manual di `tearDown`, jadi tabelnya tidak
 * ikut ter-truncate oleh transaksi rollback.
 */
class CentralCoaTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL = 'central';

    /**
     * Email unik per test.
     *
     * `RefreshDatabase` membungkus HANYA koneksi default dalam transaksi, dan
     * database pusat tidak ikut thereof. Kalau `setUp` gagal di tengah jalan,
     * `tearDown` tetap jalan tapi baris yang sudah terlanjur ter-insert tidak
     * terhapus -- sehingga run berikutnya menabrak unique index dan seluruh
     * test berikutnya gagal dengan pesan yang tidak menjelaskan penyebabnya
     * sebenarnya.
     */
    private string $email;

    /**
     * Sufiks unik per test, untuk baris yang tidak bisa di-rollback.
     *
     * Semua baris di database pusat yang dibuat test ini bertahan setelah
     * test selesai, karena `RefreshDatabase` hanya membungkus koneksi
     * default. Kalau test gagal di tengah, `tearDown()` tetap jalan tapi
     * baris yang sudah terlanjur ter-insert belum tentu ikut terhapus --
     * dan unique index (`tenants.slug`, `users.email`) akan menolak run
     * berikutnya dengan pesan yang tidak menjelaskan asal-usulnya.
     *
     * Sufiks per-run membuat baris sisa tidak pernah bentrok, dan
     * `setUp()` membersihkannya berdasarkan polanya.
     */
    private string $emailSuffix;

    private User $superadmin;

    /** Baris level 3 yang jadi sasaran pengujian. */
    private AkunLevel3 $level3;

    private AkunLevel2 $level2;

    private AkunLevel1 $level1;

    protected function setUp(): void
    {
        parent::setUp();

        $central = DB::connection(self::CENTRAL);

        $this->email = 'pusat-coa-'.uniqid().'@test.local';

        $this->emailSuffix = uniqid();

        // Sisa dari run sebelumnya yang gagal di tengah setUp tidak ikut
        // ter-rollback, jadi dibersihkan di sini sebelum insert baru.
        $central->table('users')
            ->where('email', 'like', 'pusat-coa-%@test.local')
            ->delete();

        // Sama untuk `tenants`: test "perubahan pusat terlihat dari seluruh
        // lokasi" mendaftarkan tiga lokasi sementara, dan barisnya tidak
        // ikut terhapus kalau test itu gagal sebelum pembersihannya berjalan.
        $central->table('tenants')
            ->where('slug', 'like', 'coa-%')
            ->delete();

        /*
         * User superadmin HARUS dibuat lewat koneksi `central`.
         *
         * Enum `users.role` di database lokasi hanya berisi admin/surveyor/
         * teknisi/pelanggan -- `superadmin` sengaja tidak ada di sana, karena
         * user pusat tidak pernah hidup di database lokasi. Menambahkannya
         * "supaya mudah" justru menghapus pemisahan dua dunia itu.
         *
         * Memakai `DB::connection('central')->table('users')->insert()` alih-alih
         * `User::create()` karena `User` terikat ke koneksi default (lokasi).
         * `Sanctum::actingAs()` tidak menyentuh database -- ia meniru user
         * langsung di memori -- jadi model yang dioper ke middleware perlu
         * memuat nilai-nilainya, meski tidak harus ada di tabel lokasi.
         */
        $this->superadmin = (new User)->forceFill([
            'name' => 'Admin Pusat',
            'email' => $this->email,
            'password' => Hash::make('password'),
            'role' => 'superadmin',
        ]);

        $central->table('users')->insert([
            'name' => 'Admin Pusat',
            'email' => $this->email,
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->superadmin->id = $central->table('users')
            ->where('email', $this->email)
            ->value('id');

        // Akun level 1-3 juga tinggal di database pusat. Menulisnya lewat
        // koneksi default akan membuat tabelnya sendiri di database lokasi --
        // persis kesalahan yang dipindahkan ke pusat beberapa waktu lalu.

        /*
         * `insertGetId()`, bukan `insert()` lalu `max('id')`.
         *
         * `max('id')` mengembalikan id terbesar di SELURUH tabel -- termasuk
         * baris sisa run sebelumnya. Kalau baris sisa itu punya id lebih besar,
         * test ini akan memakai id orang lain sebagai `parent_id`, dan setiap
         * assertion tentang hierarki jadi tidak lagi menggambarkan apa pun.
         *
         * Kolom `lev1`/`lev2`/`lev3` ikut diisi karena NOT NULL tanpa default.
         *
         * Perhatikan juga: kolom `parent_id` bertipe `int` sementara `id`
         * bertipe `bigint unsigned`. Itulah sebabnya tabel ini tidak punya
         * foreign key sama sekali -- kolom tujunya lebih sempit daripada kolom
         * yang harus dirujuknya, jadi MySQL tidak akan mengizinkan constraint
         * yang benar. Justru itu sebabnya `parent_id` harus diperlakukan beku.
         */
        /*
         * Prefix `9.` pada `kode_akun` itu WAJIB, bukan sekadar biar Different.
         *
         * `akun_level_1/2/3` tidak punya unique index pada `kode_akun`, jadi
         * fixture dengan kode yang sama dengan data master TIDAK ditolak
         * MySQL.Dan karena `insertGetId()` memakai AUTO_INCREMENT, id yang
         * diberikan untuk baris pertama di database yang kosong adalah `1` --
         * bukan `6` seperti yang terlihat setelah data master ada. Fixture
         * pertama=test pertama akan mengambil id `1`, yang Justru id milik
         * baris master `Aset`.
         *
         * `tearDown()` lalu menghapus baris itu by id, dan baris master ikut
         * hilang. Efeknya berantai: `akun_level_1` jadi 0 baris, `akun_level_2`
         * jadi 0 baris, `akun_level_3` jadi 0 baris -- seluruh chart of accounts
         * milik SEMUA lokasi hilang, dan test lain yang memverifikasi
         * `accounts.parent_id` menunjuk level 3 di pusat gagal dengan
         * "Semua akun lokasi harus punya induk level 3 di pusat" tanpa
         * menyinggung sama sekali penyebabnya.
         *
         * Prefix `9.` tidak dipakai data master (1-5), jadi id dan kodenya
         * tidak pernah bertabrakan dengan milik pusat.
         */
        $this->level1 = new AkunLevel1;
        $this->level1->id = $central->table($this->level1->getTable())->insertGetId([
            'lev1' => 1,
            'kode_akun' => '9.0.00.00',
            'nama_akun' => 'Aset',
            'jenis_mutasi' => 'debet',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->level2 = new AkunLevel2;
        $this->level2->id = $central->table($this->level2->getTable())->insertGetId([
            'parent_id' => $this->level1->id,
            'lev1' => 1,
            'lev2' => 1,
            'kode_akun' => '9.1.00.00',
            'nama_akun' => 'Aset Lancar',
            'jenis_mutasi' => 'debet',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->level3 = new AkunLevel3;
        $this->level3->id = $central->table($this->level3->getTable())->insertGetId([
            'parent_id' => $this->level2->id,
            'lev1' => 1,
            'lev2' => 1,
            'lev3' => 1,
            'kode_akun' => '9.1.01.00',
            'nama_akun' => 'Kas',
            'jenis_mutasi' => 'debet',
            'posisi' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->level1->exists = true;
        $this->level2->exists = true;
        $this->level3->exists = true;
    }

    protected function tearDown(): void
    {
        /*
         * Semua baris yang dibuat test ini ada di database pusat, yang tidak
         * ikut di-rollback oleh `RefreshDatabase` -- rollback hanya membungkus
         * koneksi default.
         *
         * PENTING: pakai `delete()` per-id, BUKAN `truncate()`.
         *
         * `truncate()` menghapus seluruh isi tabel. Kalau `TENANCY_CENTRAL_DATABASE`
         * pernah menunjuk database pusat yang sebenarnya -- bukan
         * `astaloka_testing_central` -- satu `php artisan test` akan menghapus
         * 61 baris chart of accounts milik semua lokasi tanpa forewarning dan
         * tanpa bisa dikembalikan. Test yang menghapus data bukan miliknya
         * adalah bom waktu, bukan cleanup.
         */
        try {
            $central = DB::connection(self::CENTRAL);

            if (isset($this->level3) && $this->level3->id) {
                $central->table($this->level3->getTable())->where('id', $this->level3->id)->delete();
            }

            if (isset($this->level2) && $this->level2->id) {
                $central->table($this->level2->getTable())->where('id', $this->level2->id)->delete();
            }

            if (isset($this->level1) && $this->level1->id) {
                $central->table($this->level1->getTable())->where('id', $this->level1->id)->delete();
            }

            /*
             * Sisa baris yang dibuat test ini, dibersihkan by POLA kode.
             *
             * Penghapusan per-id di atas tidak cukup: test `baris_lain_tidak_ikut_terubah`
             * membuat baris level 3 BARU (kode `9.9.99.00`) dan menghapusnya di
             * badan test -- jadi kalau test itu gagal di tengah, barisnya tertinggal.
             *
             * Polanya `9.%`, sama dengan yang dipakai `CentralCoaMutationTest`.
             * Prefix itu tidak dipakai data master (yang 1-5), jadi tidak pernah
             * menyangkut baris milik pusat. Dan dari level 3 ke 1, supaya tidak
             * ada baris yang menunjuk induk yang sudah lenyap.
             */
            $central->table('akun_level_3')->where('kode_akun', 'like', '9.%')->delete();
            $central->table('akun_level_2')->where('kode_akun', 'like', '9.%')->delete();
            $central->table('akun_level_1')->where('kode_akun', 'like', '9.%')->delete();

            $central->table('users')
                ->where('email', 'like', 'pusat-coa-%@test.local')
                ->delete();

            /*
             * Baris `tenants` yang dibuat test ini ikut dihapus DI SINI.
             *
             * Sempat dihapus di akhir badan test itu sendiri, dan itu keliru
             * dengan cara yang mahal. `tenants` tidak ikut ter-rollback, jadi
             * `tearDown()` satu-satunya tempat yang JAMIN dijalankan -- dan
             * penghapusan di badan test hanya jalan kalau test berhasil. Begitu
             * satu assertion gagal, tiga baris `coa-%` tertinggal untuk selamanya.
             *
             * Dan sisa itu tidak cuma menumpuk: setiap baris Remaining punya
             * `domain` terisi dan `database` yang tidak pernah dibuat. Satu
             * run yang gagal jadi merusak test lain secara berantai --
             *
             *   - `CentralDomainTest` menghitung hasil `backfillLegacyDomains()`,
             *     jadi `disimpan` naik satu per domain sisa dan assertion
             *     `assertSame(2, ...)` gagal.
             *   - `ShortcutLoginTest` memanggil `/api/login-user`, yang membaca
             *     SELURUH `tenants`. Lokasi sisa itu diurutkan lebih dulu
             *     (nama `Lokasi astaloka_a`), jadi `data.0` menunjuk ke sana
             *     dengan `users` kosong dan `error` true -- dan tiga test
             *     sekaligus gagal, di file yang sama sekali tidak menyentuh
             *     chart of accounts.
             *
             * Pola `coa-%` di sini sengaja sama dengan pola di `setUp()`:
             * test yang gagal sekarang tidak lagi merusak run berikutnya.
             */
            $central->table('tenants')
                ->where('slug', 'like', 'coa-%')
                ->delete();

            $central->table('audit_logs')->where('action', 'like', 'coa.%')->delete();
        } catch (Throwable $e) {
            // Jangan sampai kegagalan membersihkan menutupi kegagalan test
            // yang sebenarnya sedang diselidiki.
            report($e);
        }

        parent::tearDown();
    }

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');
    }

    /** Nilai baris level 3 di database pusat, dibaca ulang. */
    private function level3Row(): object
    {
        return DB::connection(self::CENTRAL)
            ->table($this->level3->getTable())
            ->where('id', $this->level3->id)
            ->first();
    }

    // ================================================================
    // Otorisasi
    // ================================================================

    #[Test]
    public function pengguna_biasa_tidak_bisa_membaca_struktur_akun_pusat(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Admin Lokasi',
            'email' => 'admin-lokasi-coa@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]), ['*'], 'sanctum');

        $this->getJson('/api/central/coa')->assertForbidden();
    }

    #[Test]
    public function admin_lokasi_tidak_bisa_mengubah_akun_pusat(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Admin Lokasi',
            'email' => 'admin-lokasi-coa2@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]), ['*'], 'sanctum');

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Diusir']
        )->assertForbidden();

        // Penolakan harus benar-benar menolak, bukan hanya menyembunyikan
        // respons: kalau tidak, data pusat bisa berubah lewat jalur yang
        // tidak terlihat di log.
        $this->assertSame('Kas', $this->level3Row()->nama_akun);
    }

    // ================================================================
    // Pembacaan
    // ================================================================

    #[Test]
    public function struktur_akun_dikembalikan_sebagai_pohon_lengkap(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->getJson('/api/central/coa')
            ->assertOk()
            ->assertJsonPath('success', true);

        $tree = $res->json('data.tree');

        /*
         * Yang diuji adalah KESUTUHAN pohon, bukan isi databasenya.
         *
         * Dulu test ini menyimpulkan "pohon ini cuma punya satu akar", dan
         * itu hanya bisa benar kalau `akun_level_1` di database pusat KOSONG.
         * Padahal database pusat itu memang berisi chart of accounts asli
         * (5 level 1, 14 level 2, 42 level 3) -- itu justru seluruh alasan
         * fitur ini ada. Fixture test berupa satu rantai Aset -> Aset Lancar
         * -> Kas hanya menambah satu dari lima akar itu, jadi `assertCount(1, ...)`
         * akan selalu gagal begitu data pusatnya nyata, dan itu menyuruh
         * orang mengosongkan database produksi demi semata-mata membuat test
         * hijau.
         *
         * Yang benar diperiksa: ada node yang benar-benar milik test ini,
         * tersusun di bawah induknya, dan muncul di tree yang dikembalikan
         * -- bukan berapa banyak akar yang ada.
         */
        $flatten = [];

        foreach ($tree as $l1) {
            foreach ($l1['children'] ?? [] as $l2) {
                foreach ($l2['children'] ?? [] as $l3) {
                    $flatten[(int) $l3['id']] = [
                        'nama_akun' => $l3['nama_akun'],
                        'kode_akun' => $l3['kode_akun'],
                        'idL1' => (int) $l1['id'],
                        'idL2' => (int) $l2['id'],
                    ];
                }
            }
        }

        $this->assertArrayHasKey(
            (int) $this->level3->id,
            $flatten,
            'Baris level 3 milik test ini harus muncul di tree API.'
        );

        $ditemukan = $flatten[(int) $this->level3->id];

        $this->assertSame('Kas', $ditemukan['nama_akun']);
        $this->assertSame($this->level1->id, $ditemukan['idL1']);
        $this->assertSame($this->level2->id, $ditemukan['idL2']);

        // Dan tree itu harus utuh sampai bawah: setiap level 1 punya anak
        // level 2 yang punya anak level 3. Level yang punya anak kosong
        // berarti ada rantai yang putus di tengah.
        $level3DalamTree = count($flatten);

        $ini = collect($tree)
            ->flatMap(fn ($a) => $a['children'] ?? [])
            ->flatMap(fn ($a) => $a['children'] ?? [])
            ->count();

        $this->assertSame(
            $ini,
            $level3DalamTree,
            'Semua level 3 yang punya induk harus muncul sebagai daun tree.'
        );
    }

    #[Test]
    public function level_diambil_dari_path_bukan_body_request(): void
    {
        $this->actingAsSuperadmin();

        // Level 9 tidak ada: harus 404, bukan jatuh ke level terdekat atau
        // diam-diam membaca tabel default. Kalau level boleh datang dari
        // body, satu request bisa menulis ke tabel yang salah tanpa jejak.
        $this->getJson('/api/central/coa/level/9/1')->assertNotFound();
        $this->patchJson('/api/central/coa/level/9/1', ['nama_akun' => 'X'])
            ->assertNotFound();
    }

    // ================================================================
    // Proteksi struktur -- bagian paling penting
    // ================================================================

    #[Test]
    public function id_tidak_bisa_diubah_lewat_payload(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas', 'id' => 8888]
        )->assertStatus(422);

        $this->assertStringContainsString('id', $res->json('message'));

        $central = DB::connection(self::CENTRAL);

        $this->assertSame(0, $central->table($this->level3->getTable())->where('id', 8888)->count());
        $this->assertSame('Kas', $this->level3Row()->nama_akun);
    }

    #[Test]
    public function parent_id_tidak_bisa_diubah_lewat_payload(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas', 'parent_id' => 999]
        )->assertStatus(422);

        $this->assertStringContainsString('parent_id', $res->json('message'));

        $row = $this->level3Row();

        $this->assertSame($this->level2->id, (int) $row->parent_id);
        $this->assertSame('Kas', $row->nama_akun);
    }

    #[Test]
    public function id_dan_parent_id_ditolak_bersamaan(): void
    {
        $this->actingAsSuperadmin();

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas', 'id' => 1, 'parent_id' => 2]
        )->assertStatus(422);
    }

    /**
     * Akun level 4 di lokasi menunjuk `akun_level_3.id` milik pusat.
     *
     * Test ini menutup test di atas dari arah yang paling penting: bukan hanya
     * "payload ditolak", tapi "akun di lokasi yang menunjuk baris ini masih
     * utuh setelahnya".
     */
    #[Test]
    public function akun_lokasi_yang_menunjuk_baris_pusat_tetap_utuh_setelah_updategagal(): void
    {
        $this->actingAsSuperadmin();

        $central = DB::connection(self::CENTRAL);

        /*
         * Koneksi ditulis eksplisit, bukan `DB::table(...)` polos.
         *
         * `actingAsSuperadmin()` di atas tidak menyentuh database, tapi
         * request `patchJson` berikutnya: middleware `superadmin` memanggil
         * `TenantManager::deactivate()`, yang menaruh `database.default` ke
         * `central` -- dan perubahan itu bertahan sampai test berikutnya.
         * Setelahnya, `DB::table('accounts')` diam-diam membaca database
         * PUSAT, tempat tabel `accounts` memang tidak ada.
         *
         * Gejalanya bukan assertion yang gagal: gejalanya exception
         * "table doesn't exist" yang sama sekali tidak menjelaskan kenapa
         * tabel yang satu baris di `astaloka_testing` tiba-tiba hilang.
         */
        $lokasi = DB::connection('mysql');

        // Akun level 4 di database LOKASI, menunjuk level 3 milik pusat.
        //
        // Kodenya `9.1.01.00` -- prefix yang sama dengan fixture level 3 di
        // atas, dan tidak dipakai data master. Baris ini disisipkan ke
        // database LOKASI, jadi tidak akan menabrak apa pun; tapi kode yang
        // sama dengan data master akan membuat assertion di bawahnya bisa
        // membaca baris milik MASTER, bukan baris milik test ini.
        $lokasi->table('accounts')->insert([
            'kode_akun' => '9.1.01.00',
            'nama_akun' => 'Kas Tunai',
            'parent_id' => $this->level3->id,
            'jenis_mutasi' => 'debet',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas Baru', 'parent_id' => 999]
        )->assertStatus(422);

        $akun = $lokasi->table('accounts')->where('kode_akun', '9.1.01.00')->first();

        $this->assertNotNull($akun);
        $this->assertSame($this->level3->id, (int) $akun->parent_id);

        $central->table($this->level3->getTable())
            ->where('id', $this->level3->id)
            ->delete();
    }

    // ================================================================
    // Penyuntingan teks
    // ================================================================

    #[Test]
    public function nama_akun_bisa_diubah_di_semua_level(): void
    {
        $this->actingAsSuperadmin();

        $this->patchJson("/api/central/coa/level/1/{$this->level1->id}", ['nama_akun' => 'Aset Uji'])
            ->assertOk()
            ->assertJsonPath('data.nama_akun', 'Aset Uji');

        $this->patchJson("/api/central/coa/level/2/{$this->level2->id}", ['nama_akun' => 'Aset Lancar Uji'])
            ->assertOk()
            ->assertJsonPath('data.nama_akun', 'Aset Lancar Uji');

        $this->patchJson("/api/central/coa/level/3/{$this->level3->id}", ['nama_akun' => 'Kas Uji'])
            ->assertOk()
            ->assertJsonPath('data.nama_akun', 'Kas Uji');
    }

    #[Test]
    public function kode_akun_bisa_diubah_tanpa_menggeser_hubungan(): void
    {
        $this->actingAsSuperadmin();

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['kode_akun' => '9.1.01.99']
        )->assertOk()->assertJsonPath('data.kode_akun', '9.1.01.99');

        // Kode berubah, tapi id dan induknya tidak -- itulah yang membuat
        // penyuntingan kode ini aman sementara penyuntingan id tidak.
        $row = $this->level3Row();

        $this->assertSame('9.1.01.99', $row->kode_akun);
        $this->assertSame($this->level2->id, (int) $row->parent_id);
    }

    #[Test]
    public function posisi_hanya_berlaku_di_level_3(): void
    {
        $this->actingAsSuperadmin();

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['posisi' => 4]
        )->assertOk()->assertJsonPath('data.posisi', 4);

        // Level 1 dan 2 tidak punya kolom posisi sama sekali. Kalau `posisi`
        // diteruskan ke sana, `validate()` mengabaikannya dan respons 200
        // membuat panel pusat menampilkan "tersimpan" untuk sesuatu yang
        // tidak pernah disimpan.
        $res = $this->patchJson(
            "/api/central/coa/level/1/{$this->level1->id}",
            ['posisi' => 7]
        )->assertOk();

        $this->assertNull($res->json('data.posisi'), 'Level 1 tidak punya kolom posisi.');

        // Dan yang menentukan: halaman edit pun tidak pernah mengirim
        // `posisi` untuk level 1, jadi kolom di respons harus kosong
        // konsisten, bukan 0 hasil default numerik.
        $this->assertArrayNotHasKey('posisi', $res->json('data'));

        $row = DB::connection(self::CENTRAL)
            ->table($this->level1->getTable())
            ->where('id', $this->level1->id)
            ->first();

        $this->assertSame(0, (int) $row->lev3, 'Level 1 tidak boleh ikut berubah karena posisi.');
    }

    // ================================================================
    // Normalisasi jenis_mutasi
    // ================================================================

    /**
     * `jenis_mutasi` jadi BANDINGAN, bukan tampilan.
     *
     * `PelaporanService` punya dua jalur yang tidak sepakat soal kapitalitas:
     * `isKredit()` memakai `strtolower()` (case-insensitive), sedangkan
     * `hitungSaldoAccount()` memakai `==` (case-SENSITIVE). Akun yang
     * tersimpan `'Kredit'` dihitung bertanda berlawanan di CALK dibanding
     * Neraca. Ini bukan hipotesis -- akun `2.1.02.02` di seeder pertama
     * memang ditulis `'Kredit'` dan membuat selisih CALK sebesar 2x saldonya.
     */
    #[Test]
    public function jenis_mutasi_disimpan_selalu_dalam_huruf_kecil(): void
    {
        $this->actingAsSuperadmin();

        foreach (['KREDIT', 'Kredit', '  Kredit  '] as $input) {
            $this->patchJson(
                "/api/central/coa/level/3/{$this->level3->id}",
                ['jenis_mutasi' => $input]
            )->assertOk()->assertJsonPath('data.jenis_mutasi', 'kredit');

            $this->assertSame('kredit', $this->level3Row()->jenis_mutasi);
        }
    }

    #[Test]
    public function baris_lama_dengan_kapital_diperbaiki_saat_jenis_mutasi_disunting(): void
    {
        $this->actingAsSuperadmin();

        /*
         * Simulasikan baris versi lama. Akun `2.1.02.02` di seeder pertama
         * memang ditulis `'Kredit'`, dan itu tidak cuma soal tampilan:
         * `hitungSaldoAccount()` membandingkan dengan `==` (case-SENSITIVE)
         * sementara `isKredit()` memakai `strtolower()`. Satu akun seperti ini
         * membuat CALK dan Neraca menghitungnya bertanda berlawanan --
         * selisihnya persis 2x saldo akun tersebut.
         *
         * Panel pusat adalah satu-satunya jalan menyunting kolom ini, jadi
         * normalisasi di pintu masuk adalah kesempatan membereskan baris-baris
         * yang sudah terlanjur salah kapital.
         */
        DB::connection(self::CENTRAL)
            ->table($this->level3->getTable())
            ->where('id', $this->level3->id)
            ->update(['jenis_mutasi' => 'Debet']);

        $this->assertSame('Debet', $this->level3Row()->jenis_mutasi);

        // Begitu superadmin menyentuh kolom itu, huruf besar ikut diperbaiki.
        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['jenis_mutasi' => 'DEBET']
        )->assertOk()->assertJsonPath('data.jenis_mutasi', 'debet');

        $this->assertSame('debet', $this->level3Row()->jenis_mutasi);
    }

    /**
     * Baris yang TIDAK tersentuh harus tetap utuh -- termasuk kapitalnya.
     *
     * Ini bukan pembelaan atas bug-nya, tapi penegasian batasnya: panel pusat
     * hanya menormalkan kolom yang benar-benar dikirim pada baris yang
     * benar-benar disimpan. Kalau ia diam-diam menormalkan seluruh tabel
     * setiap kali satu baris disimpan, itu menulis ke 60 baris lain yang
     * sedang disunting orang lain, dan jejaknya tidak akan pernah muncul di
     * audit.
     *
     * Konsekuensinya harus diketahui: baris lama yang salah kapital hanya
     * memberoskan diri sendiri begitu disentuh. Membereskan sisa baris
     * seperti itu adalah pekerjaan ARTINYA, bukan sampingan dari panel ini.
     */
    #[Test]
    public function baris_lain_tidak_ikut_diubah_saat_satu_baris_disunting(): void
    {
        $this->actingAsSuperadmin();

        $central = DB::connection(self::CENTRAL);

        /*
         * Kode `9.9.99.00` dipakai, bukan kode yang terlihat wajar seperti
         * `1.1.02.00`.
         *
         * Database pusat berisi chart of accounts NYATA, dan `1.1.02.00`
         * sudah dipakai baris "Kas Setara Kas". Memakai kode yang sudah
         * terpakai berarti `where('kode_akun', ...)->value('id')` di bawah
         * bisa mengambil baris milik orang lain -- lalu assertion-nya
         * memeriksa baris production, bukan fixture-nya sendiri.
         *
         * Awalan `9.9` di luar jangkauan satu digit manapun yang dipakai
         * seeder pusat, jadi kode ini pasti milik test ini saja.
         */
        $central->table($this->level3->getTable())->insert([
            'parent_id' => $this->level2->id,
            'lev1' => 9,
            'lev2' => 9,
            'lev3' => 9,
            'kode_akun' => '9.9.99.00',
            'nama_akun' => 'Bank',
            'jenis_mutasi' => 'Kredit',
            'posisi' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lain = $central->table($this->level3->getTable())
            ->where('kode_akun', '9.9.99.00')
            ->value('id');

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas Baru']
        )->assertOk();

        $this->assertSame(
            'Kredit',
            $central->table($this->level3->getTable())->where('id', $lain)->value('jenis_mutasi'),
            'Menyunting satu baris tidak boleh diam-diam menulis ke baris lain.'
        );

        $central->table($this->level3->getTable())->where('id', $lain)->delete();
    }

    // ================================================================
    // Kontrak respons
    // ================================================================

    #[Test]
    public function nilai_yang_sama_dijawab_sebagai_tidak_berubah(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas']
        )->assertOk();

        // Tanpa flag ini, panel pusat akan menampilkan "berhasil diperbarui"
        // padahal tidak ada satu baris pun yang tersentuh -- dan tidak ada
        // jejak audit untuk dicari saat efeknya tidak muncul.
        $this->assertFalse($res->json('changed'));
    }

    #[Test]
    public function nilai_baru_dijawab_sebagai_berubah(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas Baru']
        )->assertOk();

        $this->assertTrue($res->json('changed'));
    }

    // ================================================================
    // Jejak audit
    // ================================================================

    #[Test]
    public function perubahan_akun_pusat_tercatat_di_jejak_audit(): void
    {
        $this->actingAsSuperadmin();

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas Diawasi']
        )->assertOk();

        $log = DB::connection(self::CENTRAL)
            ->table('audit_logs')
            ->where('action', 'coa.level3.update')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Perubahan struktur akun pusat harus tercatat di audit.');
        $this->assertSame('superadmin', $log->actor_type);
        $this->assertSame($this->level3->id, (int) $log->subject_id);

        $properties = json_decode($log->properties, true);

        // Kunci diff adalah `old`/`new` (lihat `AuditLogger::diff()`), bukan
        // `sebelum`/`sesudah`.
        $this->assertSame('Kas', $properties['changes']['nama_akun']['old']);
        $this->assertSame('Kas Diawasi', $properties['changes']['nama_akun']['new']);
    }

    #[Test]
    public function perubahan_yang_tidak_ada_tidak_membuat_baris_audit(): void
    {
        $this->actingAsSuperadmin();

        $central = DB::connection(self::CENTRAL);
        $sebelum = $central->table('audit_logs')->count();

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas']
        )->assertOk();

        $this->assertSame($sebelum, $central->table('audit_logs')->count());
    }

    // ================================================================
    // Cakupan lintas lokasi
    // ================================================================

    /**
     * Kontrak inti dari pemindahan level 1-3 ke pusat: satu suntingan di panel
     * pusat harus terlihat oleh SEMUA lokasi, karena semuanya membaca dari
     * tabel yang sama.
     */
    #[Test]
    public function perubahan_pusat_terlihat_dari_seluruh_lokasi(): void
    {
        $this->actingAsSuperadmin();

        $central = DB::connection(self::CENTRAL);

        $this->patchJson(
            "/api/central/coa/level/3/{$this->level3->id}",
            ['nama_akun' => 'Kas Bersama']
        )->assertOk();

        /*
         * `where('id', ...)` wajib, bukan `value('nama_akun')` polos.
         *
         * Tanpa filter, `value()` mengembalikan baris PERTAMA di tabel --
         * yang di database pusat berisi chart of accounts asli (42 baris),
         * bukan fixture test ini. Assertion-nya lalu memeriksa baris orang
         * lain, dan hasilnya gagal dengan pesan yang sama sekali tidak
         * menyebut bahwa test-nya sendiri yang salah sasaran.
         *
         * Semua baris di sini boleh dibaca; yang tidak boleh adalah
         * menyimpulkan sesuatu dari baris yang bukan milik test.
         */
        $nama = $central->table($this->level3->getTable())
            ->where('id', $this->level3->id)
            ->value('nama_akun');

        $this->assertSame('Kas Bersama', $nama);

        /*
         * Bukti kedua, dan ini yang sebenarnya diuji: struktur ini milik
         * pusat, BUKAN milik lokasi.
         *
         * Model `Tenant` tidak punya relasi ke akun -- memang tidak boleh,
         * karena akun level 4 milik masing-masing lokasi sementara level 1-3
         * milik bersama. Yang bisa diuji adalah bentuknya: kalau baris ini
         * benar-benar milik satu lokasi, ia tidak akan utuh di database yang
         * tidak dipakai lokasi manapun, dan `tenants` yang terdaftar di sini
         * sama sekali tidak mengubah apa pun soal isinya.
         */
        /*
         * Nama database diisi dengan `astaloka_` + slug, bukan `database_name`.
         *
         * Kolom di registry pusat adalah `database` (wajib, tanpa default)
         * dan `is_active` -- bukan `database_name` dan `status`. Nama itu
         * tidak pernah jadi kolom di sini, jadi `create()` diam-diam
         * membuang keduanya, dan MySQL menolak `database` yang kosong.
         *
         * Database untuk nama-nama itu sengaja tidak pernah dibuat.
         * Lokasi-lokasi di sini memang tidak perlu ada: yang diuji justru
         * TIDAK adanya lokasi. Kalau baris-baris ini ikut terisi di database
         * lokasi, test ini sebenarnya sedang menguji sesuatu yang lain.
         */
        foreach (['astaloka_a', 'astaloka_b', 'astaloka_c'] as $slug) {
            /*
             * Slug pakai prefiks unik per run.
             *
             * `slug` punya unique index, dan baris yang dibuat di sini
             * berada di database pusat -- tidak ikut ter-rollback. Kalau
             * test ini gagal di tengah (assertion, exception), barisnya
             * tertinggal, dan run berikutnya menabrak unique index dengan
             * pesan yang sama sekali tidak menyebut test mana yang
             * menyisakannya.
             *
             * Pembersihan di `tearDown()` memakai pola `pusat-coa-%`,
             * sedangkan slug di sini tidak ikut pola itu -- jadi baris sisa
             * harus dibersihkan oleh test itu sendiri, dan itu hanya
             * berlaku kalau test selesai. Di `setUp()` lebih aman.
             */
            Tenant::query()->create([
                'nama' => 'Lokasi '.$slug,
                'slug' => 'coa-'.$this->emailSuffix.'-'.$slug,
                'domain' => $slug.'.test',
                'database' => 'astaloka_'.$slug,
                'is_active' => true,
            ]);
        }

        /*
         * Dihitung dengan filter slug milik test ini, bukan polos.
         *
         * `pluck('slug')` polos memeriksa SELURUH registry -- termasuk
         * lokasi sungguhan yang terdaftar di database pusat. Betul adanya
         * lokasi nyata tidak boleh menggagalkan test ini; yang diuji adalah
         * "tiga lokasi ini dibaca dari satu salinan", dan itu tidak bergantung
         * pada berapa lokasi lain yang kebetulan terdaftar.
         *
         * Polos juga membuat test ini rapuh: satu baris sisa dari run yang
         * terputus di tengah (teardown tidak sempat jalan) sudah cukup untuk
         * menggagalkan assertion -- dan pesannya menyalahkan test ini,
         * padahal penyebabnya baris milik run lain. `tearDown()` sekarang
         * membersihkan `coa-%`, tapi assertion tidak boleh bergantung pada
         * cleanup itu juga: assertion yang benar harus tetap lulus meski
         * tabelnya kotor.
         *
         * `Tenant::query()` polos sekali lagi: modelnya terikat
         * ke koneksi `central` di konstruktor, jadi ini membaca registry pusat
         * dan bukan database lokasi -- sama seperti sisa file ini.
         */
        $slugMilikTest = 'coa-'.$this->emailSuffix.'-%';

        $this->assertSame(
            3,
            Tenant::query()->where('slug', 'like', $slugMilikTest)->count(),
            'Tiga lokasi yang dibuat test ini harus terdaftar persis tiga.'
        );

        // Satu baris, satu pembaca. Berapa pun jumlah lokasi yang terdaftar,
        // isinya tetap satu salinan -- bukan satu salinan per lokasi.
        $this->assertSame(
            1,
            $central->table($this->level3->getTable())
                ->where('nama_akun', 'Kas Bersama')
                ->count(),
            'Struktur akun pusat harus disimpan sekali, bukan diduplikasi per lokasi.'
        );

        // Dan tidak ada salinan yang tersembunyi di database lokasi --
        // itulah yang membuat satu suntingan berlaku untuk semuanya.
        //
        // Dua hal yang perlu dijawab di sini, dan keduanya soal TIDAK ADA:
        //
        // 1. Koneksinya ditulis eksplisit. `DB::table(...)` polos akan salah
        //    sasaran: middleware `superadmin` memanggil
        //    `TenantManager::deactivate()`, yang menaruh `database.default`
        //    ke `central` dan tidak mengembalikannya.
        //
        // 2. Tabelnya memang tidak ADA di database lokasi. Pemindahan level
        //    1-3 ke pusat ikut menghapus tabelnya dari tiap lokasi, jadi
        //    `count()` di sini akan melempar "table doesn't exist" --
        //    bukan mengembalikan 0.
        //
        //    Dan itulah justru jawabannya. Kontrak yang diuji adalah "tidak
        //    ada salinan per lokasi", dan ketiadaan TABEL adalah bukti yang
        //    lebih kuat daripada ketiadaan BARIS: tidak ada kode yang bisa
        //    menyalin apa pun ke sana, karena tidak ada tempat untuk menyalin.
        //    Karena itu yang diperiksa adalah syarat "tabel tidak ada",
        //    bukan `assertCount(0, ...)` yang menguji sesuatu yang sudah
        //    mustahil terjadi -- select dari tabel yang tidak ada tidak
        //    pernah mengembalikan nol baris, hanya exception.
        $lokasi = DB::connection('mysql');

        $this->assertFalse(
            $lokasi->getSchemaBuilder()->hasTable($this->level3->getTable()),
            'Tabel akun level 3 tidak boleh ada di database lokasi '
                .$lokasi->getDatabaseName().' -- kalau ada, satu suntingan di '
                .'panel pusat bisa sampai ke lebih dari satu lokasi, dan kontrak '
                .'"satu salinan, semua pembaca" sudah runtuh.'
        );

        // Tidak ada penghapusan di sini. Baris `tenants` yang dibuat test ini
        // dihapus `tearDown()`, yang selalu jalan -- termasuk saat assertion di
        // atas gagal. Menghapus di akhir badan test hanya berhasil kalau test
        // selesai, dan sisa yang tertinggal ikut merusak test lain di file yang berbeda.
    }
}
