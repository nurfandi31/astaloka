<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tutup buku: halaman riwayat harus bisa dibaca.
 *
 * Bug yang dicegah di sini nyata dan sudah terjadi. `history()` meminta
 * `created_at` dari tabel `amount`, dan tabel itu TIDAK punya kolom tersebut --
 * nilainya diturunkan dari `tahun` + `bulan`, jadi timestamps tidak pernah
 * ada. Akibatnya `GET /api/tutup-buku/history` balas 500.
 *
 * Yang-gejalanya kecil tapi menyebalkan: halaman ini justru dipakai untuk
 * melihat tahun mana yang sudah ditutup, jadi justru yang paling perlu
 * selalu bisa dibuka justru yang paling sering gagal.
 *
 * Tes ini sengaja memakai jalur HTTP penuh, bukan memanggil controller
 * langsung -- supaya perubahan nama route atau middleware ikut tertangkap.
 */
class TutupBukuHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@tutupbuku.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    #[Test]
    public function riwayat_tutup_buku_dapat_dibaca_ketika_belum_ada_tutup_buku(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $response = $this->getJson('/api/tutup-buku/history');

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('data', []);
    }

    #[Test]
    public function riwayat_tutup_buku_menyebut_tahun_yang_sudah_masuk_bulan_13(): void
    {
        $akun = Account::create([
            'parent_id' => 111, 'lev1' => 1, 'lev2' => 1, 'lev3' => 1, 'lev4' => 1,
            'kode_akun' => '1.1.01.01', 'nama_akun' => 'Kas Tunai', 'jenis_mutasi' => 'debet',
        ]);

        $akunKedua = Account::create([
            'parent_id' => 112, 'lev1' => 1, 'lev2' => 1, 'lev3' => 2, 'lev4' => 1,
            'kode_akun' => '1.1.02.01', 'nama_akun' => 'Kas Bank', 'jenis_mutasi' => 'debet',
        ]);

        /*
         * `bulan = 13` adalah penanda tutup buku -- bukan tanggal, dan bukan
         * baris khusus. Satu tahun ditutup dengan menulis baris ini untuk
         * SETIAP akun, jadi dua akun untuk tahun yang sama harus tetap
         * muncul SATU kali di daftar, bukan dua.
         */
        DB::table('amount')->insert([
            ['id' => $akun->id.'202413', 'account_id' => $akun->id, 'tahun' => '2024', 'bulan' => '13', 'debit' => 0, 'kredit' => 0],
            ['id' => $akunKedua->id.'202413', 'account_id' => $akunKedua->id, 'tahun' => '2024', 'bulan' => '13', 'debit' => 0, 'kredit' => 0],
        ]);

        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $response = $this->getJson('/api/tutup-buku/history');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertCount(1, $data, 'Satu tahun tertutup harus muncul satu kali, bukan sekali per akun.');
        $this->assertSame(2024, $data[0]['tahun']);

        // Disusun dari `tahun`, karena tabel `amount` tidak punya timestamp.
        $this->assertSame('2024-12-31', $data[0]['closed_at']);
    }

    #[Test]
    public function tabel_amount_tidak_punya_timestamp(): void
    {
        /*
         * Pernyataan ini yang jadi alasan seluruh test ini ada. Kalau suatu saat
         * `created_at` ditambahkan ke `amount`, test di atas yang reliant pada
         * penyusunan `closed_at` dari `tahun` masih benar -- tapi kolom itu
         * TIDAK boleh dibaca sebagai tanggal tutup buku, karena nilainya akan
         * mencerminkan kapan baris ditulis ulang.
         */
        $ada = DB::getSchemaBuilder()->hasColumn('amount', 'created_at');

        $this->assertFalse(
            $ada,
            'Kolom created_at pada `amount` tidak pernah diisi trigger. '
            .'Kalau baru ditambahkan, jangan dipakai sebagai tanggal tutup buku.'
        );
    }

    #[Test]
    public function cek_tutup_buku_dan_riwayat_sepakat_untuk_tahun_yang_sama(): void
    {
        $akun = Account::create([
            'parent_id' => 111, 'lev1' => 1, 'lev2' => 1, 'lev3' => 1, 'lev4' => 1,
            'kode_akun' => '1.1.01.01', 'nama_akun' => 'Kas Tunai', 'jenis_mutasi' => 'debet',
        ]);

        DB::table('amount')->insert([
            'id' => $akun->id.'202313', 'account_id' => $akun->id,
            'tahun' => '2023', 'bulan' => '13', 'debit' => 0, 'kredit' => 0,
        ]);

        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $cek = $this->getJson('/api/tutup-buku/check/2023')->assertOk();
        $riwayat = $this->getJson('/api/tutup-buku/history')->assertOk();

        $this->assertTrue($cek->json('data.closed'));

        /*
         * Dua layar ini dibaca berdampingan -- user membuka daftar riwayat,
         * lalu klik salah satu tahun untuk melihat detailnya. Kalau angka
         * `closed_at` berbeda di keduanya, user melihat tanggal yang berbeda
         * untuk buku yang sama, dan tidak ada yang bisa blamed selain kode.
         */
        $this->assertSame(
            $cek->json('data.closed_at'),
            $riwayat->json('data.0.closed_at')
        );
    }
}