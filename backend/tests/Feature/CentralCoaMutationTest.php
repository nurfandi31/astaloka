<?php

namespace Tests\Feature;

use App\Models\AkunLevel1;
use App\Models\AkunLevel2;
use App\Models\AkunLevel3;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Panel pusat: menambah dan menghapus struktur akun level 1-3.
 *
 * Berkas ini menguji hal yang membuat fitur ini berisiko, bukan
 * "tombolnya ada". Yaitu dua hal:
 *
 *   1. Penghapusan benar-benar berjenjang lintas database. Akun level 4
 *      ada di tiap lokasi, induknya di pusat, dan tidak ada satu pun
 *      foreign key yang menghubungkan keduanya. Kalau hapus level 3 tidak ikut
 *      menyapu akun level 4 di lokasi, akun itu tertinggal dengan
 *      `parent_id` menunjuk id yang sudah tidak ada -- tanpa error, dan
 *      laporan baru kosong sebagian jauh kemudian.
 *
 *   2. Penghapusan BERHENTI kalau ada data yang sudah dibukukan. Akun level 4
 *      yang sudah punya `amount`/`ebudgeting`/`transactions` ditolak dengan
 *      409. Tanpa aturan ini, "hapus berjenjang" berarti panel pusat bisa
 *      menghapus pembukuan lokasi orang lain tanpa diminta.
 *
 * `RefreshDatabase` di sini hanya menyalakan rollback untuk koneksi default.
 * Data pusat ditulis lewat koneksi `central` dan dibersihkan manual di
 * `tearDown` -- sama seperti {@see CentralCoaTest}, dengan alasan yang sama.
 */
class CentralCoaMutationTest extends TestCase
{
    use RefreshDatabase;

    private const CENTRAL = 'central';

    private string $emailSuffix;

    private User $superadmin;

    private AkunLevel1 $level1;

    private AkunLevel2 $level2;

    private AkunLevel3 $level3;

    protected function setUp(): void
    {
        parent::setUp();

        $central = DB::connection(self::CENTRAL);

        $this->emailSuffix = uniqid();

        // Sisa run sebelumnya dibersihkan di sini, bukan di akhir test:
        // `RefreshDatabase` tidak menyentuh database pusat sama sekali.
        $central->table('users')->where('email', 'like', 'pusat-coa-mut-%@test.local')->delete();
        $central->table('tenants')->where('slug', 'like', 'coa-mut-%')->delete();
        $central->table('audit_logs')->where('action', 'like', 'coa.%')->delete();

        $this->superadmin = (new User)->forceFill([
            'name' => 'Admin Pusat',
            'email' => 'pusat-coa-mut-'.$this->emailSuffix.'@test.local',
            'password' => Hash::make('password'),
            'role' => 'superadmin',
        ]);

        $central->table('users')->insert([
            'name' => 'Admin Pusat',
            'email' => 'pusat-coa-mut-'.$this->emailSuffix.'@test.local',
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->superadmin->id = $central->table('users')
            ->where('email', 'pusat-coa-mut-'.$this->emailSuffix.'@test.local')
            ->value('id');

        /*
         * Rantai fixture: level 1 -> level 2 -> level 3.
         *
         * `lev*` ikut diisi karena NOT NULL tanpa default, dan nilainya
         * ORDINAL (urutan di antara saudara), bukan referensi id.
         */
        $this->level1 = new AkunLevel1;
        $this->level1->id = $central->table('akun_level_1')->insertGetId([
            'lev1' => 90, 'kode_akun' => '9.0.00.00', 'nama_akun' => 'Aset Uji',
            'jenis_mutasi' => 'debet', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->level2 = new AkunLevel2;
        $this->level2->id = $central->table('akun_level_2')->insertGetId([
            'parent_id' => $this->level1->id, 'lev1' => 90, 'lev2' => 1,
            'kode_akun' => '9.1.00.00', 'nama_akun' => 'Aset Lancar Uji',
            'jenis_mutasi' => 'debet', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->level3 = new AkunLevel3;
        $this->level3->id = $central->table('akun_level_3')->insertGetId([
            'parent_id' => $this->level2->id, 'lev1' => 90, 'lev2' => 1, 'lev3' => 1,
            'kode_akun' => '9.1.01.00', 'nama_akun' => 'Kas Uji', 'posisi' => 1,
            'jenis_mutasi' => 'debet', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->level1->exists = true;
        $this->level2->exists = true;
        $this->level3->exists = true;
    }

    protected function tearDown(): void
    {
        /*
         * Hapus per-id, bukan `truncate()`.
         *
         * `truncate()` menghapus seluruh isi tabel. Kalau `TENANCY_CENTRAL_DATABASE`
         * pernah menunjuk database pusat yang sungguhan, satu `php artisan test`
         * akan menghapus 61 baris chart of accounts milik semua lokasi tanpa
         * forewarning. Test yang menghapus data bukan miliknya adalah bom waktu.
         */
        try {
            $central = DB::connection(self::CENTRAL);

            $central->table('akun_level_3')->where('id', $this->level3->id)->delete();
            $central->table('akun_level_2')->where('id', $this->level2->id)->delete();
            $central->table('akun_level_1')->where('id', $this->level1->id)->delete();

            /*
             * Sisa baris yang dibuat test ini, dibersihkan by POLA kode.
             *
             * Daftar per-id di atas tidak cukup: beberapa test membuat baris
             * BARU lewat endpoint (bukan lewat fixture `setUp`), jadi id-nya
             * tidak pernah tercatat dan tidak bisa dihapus per-id. Kalau
             * baris-baris itu tertinggal, database pusat menjadi tercemar:
             * `akun_level_1` grew dari 5 ke 6, `akun_level_3` dari 42 ke 46.
             *
             * Dan pencemarannya tidak kelihatan. Chart of accounts yang
             * bertambah tidak merusak test mana pun -- yang rusak adalah test
             * yang menghitung baris, dan gejalanya muncul jauh dari
             * penyebabnya.
             *
             * Polanya `9.%` karena SELURUH fixture test ini memakai prefix
             * itu, dan prefix itu tidak dipakai data master (yang 1-5). Jadi
             * pola ini tidak mungkin menyangkut baris milik pusat.
             *
             * Urutan: level 3 dulu, lalu 2, lalu 1. Baris level 3 menunjuk
             * level 2 sebagai induknya, jadi menghapus induknya lebih dulu
             * meninggalkan baris level 3 yang menunjuk induk yang sudah lenyap
             * -- kalau ada yang mengetahuinya.
             */
            $central->table('akun_level_3')->where('kode_akun', 'like', '9.%')->delete();
            $central->table('akun_level_2')->where('kode_akun', 'like', '9.%')->delete();
            $central->table('akun_level_1')->where('kode_akun', 'like', '9.%')->delete();

            $central->table('users')->where('email', 'like', 'pusat-coa-mut-%@test.local')->delete();
            $central->table('tenants')->where('slug', 'like', 'coa-mut-%')->delete();
            $central->table('audit_logs')->where('action', 'like', 'coa.%')->delete();
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    private function actingAsSuperadmin(): void
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');
    }

    private function level3Ada(): bool
    {
        return DB::connection(self::CENTRAL)
            ->table('akun_level_3')->where('id', $this->level3->id)->exists();
    }

    // ================================================================
    // TAMBAH
    // ================================================================

    #[Test]
    public function level_1_boleh_ditambah(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->postJson('/api/central/coa/level/1', [
            'kode_akun' => '9.8.00.00',
            'nama_akun' => 'Kategori Uji',
        ])->assertCreated();

        $this->assertSame('Kategori Uji', $res->json('data.nama_akun'));

        $ada = DB::connection(self::CENTRAL)->table('akun_level_1')
            ->where('id', $res->json('data.id'))->first();

        $this->assertNotNull($ada);
        $this->assertSame('9.8.00.00', $ada->kode_akun);
    }

    #[Test]
    public function level_2_boleh_ditambah_dengan_induk_yang_nyata(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->postJson('/api/central/coa/level/2', [
            'parent_id' => $this->level1->id,
            'kode_akun' => '9.8.01.00',
            'nama_akun' => 'Anak Uji',
        ])->assertCreated();

        $ada = DB::connection(self::CENTRAL)->table('akun_level_2')
            ->where('id', $res->json('data.id'))->first();

        $this->assertSame((int) $this->level1->id, (int) $ada->parent_id);
    }

    /**
     * Akun tanpa induk yang nyata akan SELALU tersimpan, tapi tidak akan
     * pernah muncul di pohon: `index()` hanya membaca turunannya lewat
     * `whereIn('parent_id', ...)` milik induk yang ada. Akun seperti itu
     * tidak terlihat di panel, tidak bisa dihapus dari panel, dan tidak
     * bisa ditemukan lewat pencarian -- barisnya ada, tapi tidak bisa
     * dicapai dari mana pun.
     */
    #[Test]
    public function induk_yang_tidak_ada_ditolak(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/central/coa/level/3', [
            'parent_id' => 99999999,
            'kode_akun' => '9.7.01.00',
            'nama_akun' => 'Yatim',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(
            0,
            DB::connection(self::CENTRAL)->table('akun_level_3')->where('kode_akun', '9.7.01.00')->count(),
            'Akun dengan induk yang tidak ada tidak boleh tersimpan.'
        );
    }

    #[Test]
    public function level_3_tanpa_parent_id_ditolak(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/central/coa/level/3', [
            'kode_akun' => '9.7.02.00',
            'nama_akun' => 'Tanpa Induk',
        ])->assertStatus(422);
    }

    /**
     * `jenis_mutasi` mewarisi induk kalau dikosongkan.
     *
     * Diuji karena kolom ini menentukan arah saldo di laporan, dan
     * `JenisMutasi::normalisasi()` menerima apa saja lalu memetakannya ke
     * dua nilai baku. Kalau pewarisan salah, setiap akun turunan baru
     * langsung menyimpan tanda yang terbalik.
     */
    #[Test]
    public function jenis_mutasi_mewarisi_induk_kalaudikosoongkan(): void
    {
        $this->actingAsSuperadmin();

        $central = DB::connection(self::CENTRAL);

        $central->table('akun_level_2')->where('id', $this->level2->id)
            ->update(['jenis_mutasi' => 'kredit']);

        $res = $this->postJson('/api/central/coa/level/3', [
            'parent_id' => $this->level2->id,
            'kode_akun' => '9.1.02.00',
            'nama_akun' => 'Anak Kredit',
        ])->assertCreated();

        $this->assertSame('kredit', $res->json('data.jenis_mutasi'));
    }

    #[Test]
    public function jenis_mutasi_disimpan_selalu_huruf_kecil(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->postJson('/api/central/coa/level/3', [
            'parent_id' => $this->level2->id,
            'kode_akun' => '9.1.03.00',
            'nama_akun' => 'Kapital',
            'jenis_mutasi' => '  KREDIT ',
        ])->assertCreated();

        $this->assertSame('kredit', $res->json('data.jenis_mutasi'));
    }

    /**
     * Kolom `lev*` adalah ORDINAL, bukan referensi id -- jadi harus dihitung
     * ulang dari database, bukan disalin apa adanya dari baris lain.
     *
     * Disalin mentah, `lev3` baris baru akan sama dengan saudaranya dan
     * laporan yang mengelompokkan lewat kolom ini akan menghitung akun dua
     * kali atau gagal mengelompokkan sama sekali.
     */
    #[Test]
    public function ordinal_lev_dihitung_dari_database_bukan_disalin(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->postJson('/api/central/coa/level/3', [
            'parent_id' => $this->level2->id,
            'kode_akun' => '9.1.04.00',
            'nama_akun' => 'Ordinal',
        ])->assertCreated();

        $baru = DB::connection(self::CENTRAL)->table('akun_level_3')
            ->where('id', $res->json('data.id'))->first();

        $saudara = DB::connection(self::CENTRAL)->table('akun_level_3')
            ->where('id', $this->level3->id)->first();

        $this->assertSame((int) $saudara->lev1, (int) $baru->lev1);
        $this->assertSame((int) $saudara->lev2, (int) $baru->lev2);

        $this->assertGreaterThan(
            (int) $saudara->lev3,
            (int) $baru->lev3,
            'Ordinal level 3 harus lebih besar dari saudaranya, bukan sama.'
        );
    }

    #[Test]
    public function admin_lokasi_tidak_bisa_menambah_akun_pusat(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Admin Lokasi',
            'email' => 'admin-lokasi-coa-mut@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]), ['*'], 'sanctum');

        $this->postJson('/api/central/coa/level/1', [
            'kode_akun' => '9.6.00.00',
            'nama_akun' => 'Diusir',
        ])->assertForbidden();

        $this->assertSame(
            0,
            DB::connection(self::CENTRAL)->table('akun_level_1')->where('kode_akun', '9.6.00.00')->count()
        );
    }

    #[Test]
    public function level_diambil_dari_path_bukan_body_request_saat_menambah(): void
    {
        $this->actingAsSuperadmin();

        // Level 9 tidak punya tabel, dan tidak punya aturan validasi juga.
        $this->postJson('/api/central/coa/level/9', [
            'kode_akun' => '9.5.00.00',
            'nama_akun' => 'Tidak despair',
        ])->assertNotFound();
    }

    // ================================================================
    // HAPUS -- PERTAHANAN
    // ================================================================

    #[Test]
    public function hapus_menuntut_kode_yang_cocok(): void
    {
        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/coa/level/3/{$this->level3->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'COA_CONFIRM_REQUIRED');

        $this->assertTrue($this->level3Ada(), 'Tanpa konfirmasi, tidak boleh ada yang terhapus.');
    }

    #[Test]
    public function kode_konfirmasi_yang_salah_tidak_menghapus_apa_pun(): void
    {
        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/coa/level/3/{$this->level3->id}", ['confirm' => '9.1.01.99'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COA_CONFIRM_MISMATCH');

        $this->assertTrue($this->level3Ada());
    }

    /**
     * `confirm` dibandingkan ISI-nya, bukan hanya dicek ada/tidaknya.
     *
     * `required` hanya menyatakan ada isinya, jadi `confirm: 1` lolos
     * validasi dan -- kalau tidak dibandingkan -- sudah cukup untuk
     * melewati seluruh layar konfirmasi tanpa mengonfirmasi apa pun.
     */
    #[Test]
    public function konfirmasi_boleh_berupa_angka_kalau_cocok(): void
    {
        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/coa/level/3/{$this->level3->id}", ['confirm' => '9.1.01.00'])
            ->assertOk();

        $this->assertFalse($this->level3Ada());
    }

    #[Test]
    public function admin_lokasi_tidak_bisa_menghapus_akun_pusat(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Admin Lokasi',
            'email' => 'admin-lokasi-coa-mut2@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]), ['*'], 'sanctum');

        $this->deleteJson("/api/central/coa/level/3/{$this->level3->id}", ['confirm' => '9.1.01.00'])
            ->assertForbidden();

        $this->assertTrue($this->level3Ada());
    }

    // ================================================================
    // HAPUS BERJENJANG DI PUSAT
    // ================================================================

    #[Test]
    public function hapus_level_3_menghapus_barisnya_sendiri(): void
    {
        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/coa/level/3/{$this->level3->id}", ['confirm' => '9.1.01.00'])
            ->assertOk();

        $this->assertFalse($this->level3Ada());
        $this->assertTrue($this->level2Ada(), 'Level 2 tidak ikut terhapus.');
        $this->assertTrue($this->level1Ada(), 'Level 1 tidak ikut terhapus.');
    }

    /**
     * Inti dari "hapus berjenjang": menghapus level 2 ikut menghapus level 3
     * di bawahnya. Kalau ini tidak terjadi, baris level 3 yang tersisa
     * tidak akan pernah muncul di pohon -- `index()` hanya membaca level 3
     * lewat `whereIn('parent_id', ...)` milik level 2 yang sudah dihapus --
     * dan tidak bisa ditemukan lewat panel.
     */
    #[Test]
    public function hapus_level_2_ikut_menghapus_level_3(): void
    {
        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/coa/level/2/{$this->level2->id}", ['confirm' => '9.1.00.00'])
            ->assertOk();

        $this->assertFalse($this->level3Ada());
        $this->assertFalse($this->level2Ada());
        $this->assertTrue($this->level1Ada(), 'Level 1 harus tetap ada.');
    }

    #[Test]
    public function hapus_level_1_ikut_menghapus_seluruh_turunannya(): void
    {
        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/coa/level/1/{$this->level1->id}", ['confirm' => '9.0.00.00'])
            ->assertOk();

        $this->assertFalse($this->level3Ada());
        $this->assertFalse($this->level2Ada());
        $this->assertFalse($this->level1Ada());
    }

    #[Test]
    public function akun_tidak_berhubungan_tidak_ikut_terhapus(): void
    {
        $this->actingAsSuperadmin();

        $central = DB::connection(self::CENTRAL);

        // Level 2 lain yang TIDAK anak dari level 1 yang dihapus.
        $level1Lain = $central->table('akun_level_1')->insertGetId([
            'lev1' => 91, 'kode_akun' => '9.0.00.01', 'nama_akun' => 'Lain',
            'jenis_mutasi' => 'debet', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $level2Lain = $central->table('akun_level_2')->insertGetId([
            'parent_id' => $level1Lain, 'lev1' => 91, 'lev2' => 1,
            'kode_akun' => '9.1.00.09', 'nama_akun' => 'Tetap Ada',
            'jenis_mutasi' => 'debet', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $level3Lain = $central->table('akun_level_3')->insertGetId([
            'parent_id' => $level2Lain, 'lev1' => 91, 'lev2' => 1, 'lev3' => 1,
            'kode_akun' => '9.1.01.09', 'nama_akun' => 'Tetap Ada',
            'posisi' => 1, 'jenis_mutasi' => 'debet', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson("/api/central/coa/level/1/{$this->level1->id}", ['confirm' => '9.0.00.00'])
            ->assertOk();

        $this->assertTrue($central->table('akun_level_1')->where('id', $level1Lain)->exists());
        $this->assertTrue($central->table('akun_level_2')->where('id', $level2Lain)->exists());
        $this->assertTrue($central->table('akun_level_3')->where('id', $level3Lain)->exists());

        $central->table('akun_level_3')->where('id', $level3Lain)->delete();
        $central->table('akun_level_2')->where('id', $level2Lain)->delete();
        $central->table('akun_level_1')->where('id', $level1Lain)->delete();
    }

    // ================================================================
    // IMPACT
    // ================================================================

    #[Test]
    public function impact_menyebutkan_seluruh_turunan(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->getJson("/api/central/coa/level/1/{$this->level1->id}/impact")->assertOk();

        $this->assertTrue($res->json('data.dapat_dihapus'));
        $this->assertFalse($res->json('data.blocked'));
        $this->assertSame(1, $res->json('data.jumlah_level2'));
        $this->assertSame(1, $res->json('data.jumlah_level3'));
        $this->assertSame('9.0.00.00', $res->json('data.kode_akun'));
    }

    #[Test]
    public function impact_baris_yang_tidak_ada_jawab_404(): void
    {
        $this->actingAsSuperadmin();

        $this->getJson('/api/central/coa/level/3/99999999/impact')->assertNotFound();
    }

    #[Test]
    public function impact_admin_lokasi_ditolak(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Admin Lokasi',
            'email' => 'admin-lokasi-coa-mut3@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]), ['*'], 'sanctum');

        $this->getJson("/api/central/coa/level/3/{$this->level3->id}/impact")->assertForbidden();
    }

    // ================================================================
    // JEJAK AUDIT
    // ================================================================

    #[Test]
    public function penambahan_tercatat_di_jejak_audit(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/central/coa/level/2', [
            'parent_id' => $this->level1->id,
            'kode_akun' => '9.8.02.00',
            'nama_akun' => 'Diawasi',
        ])->assertCreated();

        $log = DB::connection(self::CENTRAL)->table('audit_logs')
            ->where('action', 'coa.level2.create')->latest('id')->first();

        $this->assertNotNull($log, 'Penambahan struktur akun pusat harus tercatat di audit.');
        $this->assertSame('superadmin', $log->actor_type);
    }

    #[Test]
    public function penghapusan_tercatat_di_jejak_audit(): void
    {
        $this->actingAsSuperadmin();

        $this->deleteJson("/api/central/coa/level/2/{$this->level2->id}", ['confirm' => '9.1.00.00'])
            ->assertOk();

        $log = DB::connection(self::CENTRAL)->table('audit_logs')
            ->where('action', 'coa.level2.delete')->latest('id')->first();

        $this->assertNotNull($log, 'Penghapusan struktur akun pusat harus tercatat di audit.');
        $this->assertSame('superadmin', $log->actor_type);

        $properties = json_decode($log->properties, true);

        // Apa saja yang hilang harus tercatat, bukan cuma "ada yang dihapus":
        // tanpa ini, tidak ada yang bisa menjawab "berapa akun yang hilang
        // waktu saya menghapus Aset bulan lalu".
        $this->assertArrayHasKey('dihapus', $properties);
        $this->assertSame(1, $properties['dihapus']['level3']);
    }

    /**
     * Penghapusan yang DITOLAK tidak boleh meninggalkan jejak audit yang
     *implies berhasil -- kalau tidak, log akan llenos baris "dihapus"
     * padahal tidak ada satu baris pun yang hilang, dan log itu satu-satunya
     * bukti yang dicari orang saatSacral ini tidak terjadi.
     */
    #[Test]
    public function penghapusan_ditolak_tidak_membuat_baris_audit(): void
    {
        $this->actingAsSuperadmin();

        $central = DB::connection(self::CENTRAL);
        $sebelum = $central->table('audit_logs')->count();

        $this->deleteJson("/api/central/coa/level/3/{$this->level3->id}", ['confirm' => 'salah'])
            ->assertStatus(422);

        $this->assertSame($sebelum, $central->table('audit_logs')->count());
    }

    // ================================================================
    // PELINDUNG YANG SUDAH ADA TIDAK BOLEH BOCOR
    // ================================================================

    #[Test]
    public function id_tidak_bisa_ditentukan_saat_menambah(): void
    {
        $this->actingAsSuperadmin();

        $res = $this->postJson('/api/central/coa/level/3', [
            'parent_id' => $this->level2->id,
            'kode_akun' => '9.1.09.00',
            'nama_akun' => 'Id Palsu',
            'id' => 54321,
        ])->assertCreated();

        $this->assertNotSame(
            54321,
            $res->json('data.id'),
            'Klien tidak boleh menentukan `id`: itu yang dipegang `accounts.parent_id` di tiap lokasi.'
        );
    }

    private function level1Ada(): bool
    {
        return DB::connection(self::CENTRAL)
            ->table('akun_level_1')->where('id', $this->level1->id)->exists();
    }

    private function level2Ada(): bool
    {
        return DB::connection(self::CENTRAL)
            ->table('akun_level_2')->where('id', $this->level2->id)->exists();
    }
}
