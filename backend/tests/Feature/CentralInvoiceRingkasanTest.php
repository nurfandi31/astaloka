<?php

namespace Tests\Feature;

use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\EnsuresCentralTables;
use Tests\TestCase;
use Throwable;

/**
 * Ringkasan tagihan Panel Pusat: angka yang harus sama dengan dashboard.
 *
 * ============================================================
 * BUG YANG DIKUNCI TEST INI
 * ============================================================
 *
 * Dilaporkan: "di dashboard ada riwayat pembayaran lunas, sedangkan di
 * tagihannya kok ngk ada". Dua halaman itu menghitung dari TABEL YANG
 * SAMA, jadi angkanya harus sama -- dan tiba-tiba tidak.
 *
 * Penyebabnya ada di `InvoiceController::index()`:
 *
 *   1. Filter `status` ikut masuk ke closure yang sama dengan filter
 *      lokasi dan pencarian. Closure itu dipakai DUA kali: satu untuk
 *      daftar, satu untuk menghitung "belum lunas".
 *
 *   2. Query "belum lunas" lalu menambah syarat `status = 'UNPAID'`
 *      di atas closure itu. Jadi begitu orang memilih filter "Lunas",
 *      query-nya jadi `status = 'PAID' AND status = 'UNPAID'` -- dua
 *      syarat yang mustahil dipenuhi bersamaan.
 *
 * Hasilnya: "Belum Lunas 0" dan "Total Tunggakan Rp 0" -- bukan hanya di
 * halaman Tagihan, tapi juga di `CentralLokasiDetail` yang membaca
 * `ringkasan` dari endpoint yang sama. Dan angka nol itu dibaca orang
 * sebagai "tidak ada tunggakan sama sekali", bukan sebagai "filter ini
 * tidak bisa memberi tahu".
 *
 * Bentuk sekarang: `ringkasan` dihitung dari query yang HANYA dibatasi
 * filter lokasi dan pencarian, sementara filter status tetap dipakai untuk
 * DAFTAR. Jadi menyaring status tidak lagi mengubah angka ringkasan, dan
 * angkanya sama dengan yang dipakai dashboard.
 *
 * ============================================================
 * CATATAN SOAL DATA DI DATABASE UJI
 * ============================================================
 *
 * Database uji pusat dipakai BERSAMA oleh beberapa test, dan test lain
 * pernah meninggalkan baris invoice di sana -- misalnya `InvoiceLockTest`
 * yang meninggalkan beberapa tagihan milik tenant artificial. Jadi semua
 * test di bawah mengikat jawabannya ke tenant milik test ini lewat
 * `tenant_id`, dan membandingkan dengan angka MUTLAK -- bukan dengan
 * hitungan baris halaman, yang bisa ikut baris milik orang lain.
 *
 * Satu test memang sengaja memanggil TANPA `tenant_id`: yang memastikan
 * dua lokasi dihitung bersama. Test itu memakai SELISIH terhadap angka
 * sebelum baris-barisnya dibuat, jadi baris asing yang sudah ada di sana
 * tidak ikut dihitung dan tidak menggagalkan hasilnya.
 *
 * Filter tenant TIDAK membuat test ini buta: `filter_lokasi_tetap_
 * menentukan_ringkasan` mengirim `tenant_id` secara eksplisit dan
 * memeriksa angkanya secara mutlak. Kalau filter itu bocor atau hilang,
 * angka itu jadi baris milik semua tenant -- dan pemeriksaan itu gagal.
 *
 * `RefreshDatabase` SENGAJA tidak dipakai, dengan alasan yang sama seperti
 * di `InvoiceLockTest`: middleware tenancy mengarahkan koneksi default ke
 * koneksi `tenant`, jadi data yang ditulis di dalam transaksi test tidak
 * terlihat dari sisi sana. Test ini membiarkan datanya benar-benar
 * di-commit dan membersihkannya sendiri lewat `tearDown()`.
 */
class CentralInvoiceRingkasanTest extends TestCase
{
    use EnsuresCentralTables;

    private const CENTRAL = 'central';

    private string $prefix;

    private Tenant $tenant;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'ringkasan-'.uniqid().'-';

        $this->runCentralMigrations();

        $this->tenant = Tenant::create([
            'slug' => $this->prefix.'pdam',
            'nama' => 'PDAM Uji',
            'nama_lembaga' => 'PDAM Uji Bersama',
            'database' => (string) config('database.connections.mysql.database'),
            'is_active' => true,
        ]);

        $this->superadmin = $this->makeSuperadmin();
    }

    protected function tearDown(): void
    {
        try {
            $central = DB::connection(self::CENTRAL);

            $ids = $central->table('tenants')
                ->where('slug', 'like', $this->prefix.'%')
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                // Anak lebih dulu: `tenant_invoice_items` cascade dari
                // `tenant_invoices`, yang cascade dari `tenants`.
                $central->table('tenant_invoice_items')
                    ->whereIn('tenant_invoice_id', function ($q) use ($central, $ids) {
                        $q->select('id')->from('tenant_invoices')->whereIn('tenant_id', $ids);
                    })
                    ->delete();

                $central->table('tenant_invoice_payments')
                    ->whereIn('tenant_invoice_id', function ($q) use ($central, $ids) {
                        $q->select('id')->from('tenant_invoices')->whereIn('tenant_id', $ids);
                    })
                    ->delete();

                $central->table('tenant_invoices')->whereIn('tenant_id', $ids)->delete();
                $central->table('tenants')->whereIn('id', $ids)->delete();
            }

            $central->table('personal_access_tokens')
                ->where('tokenable_id', $this->superadmin->id)
                ->delete();

            $central->table('users')->where('id', $this->superadmin->id)->delete();

            $central->table('audit_logs')
                ->where('actor_id', $this->superadmin->id)
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }

        parent::tearDown();
    }

    /* ============================================================
     | RINGKASAN TIDAK BERUBAH SAAT STATUS DI-SARING
     |============================================================
     */

    /**
     * Memfilter "Lunas" tidak boleh membuat tunggakan jadi nol.
     *
     * Ini inti bug-nya. Tanpa test ini, memperbaiki halaman Tagihan saja
     * tidak cukup -- karena `ringkasan` juga dibaca oleh
     * `CentralLokasiDetail`, dan di situ angka nol berarti "lokasi ini
     * tidak punya tunggakan" yang jauh lebih berbahaya daripada "tabelnya
     * kosong".
     */
    #[Test]
    public function filter_lunas_tidak_membuat_tunggakan_jadi_nol(): void
    {
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 1_500_000]);
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 2_000_000]);
        $this->invoice(['status' => 'PAID', 'jumlah' => 750_000]);

        $semua = $this->ringkasanLokasi();

        $this->assertSame(2, $semua['belumLunas'], 'Dua tagihan belum lunas harus ikut terhitung.');
        $this->assertSame(3_500_000.0, (float) $semua['totalTunggakan']);

        $lunas = $this->ringkasanLokasi(['status' => 'PAID']);

        $this->assertSame(
            2,
            $lunas['belumLunas'],
            'BUG INI: memilih "Lunas" membuat "Belum Lunas" jadi 0 -- filter status '
            .'memasukkan syarat `status = PAID` DAN `status = UNPAID` ke query yang sama.',
        );
        $this->assertSame(
            3_500_000.0,
            (float) $lunas['totalTunggakan'],
            'BUG INI: "Total Tunggakan" ikut jadi 0, dan nol itu dibaca sebagai "tidak ada tunggakan".',
        );
    }

    /**
     * Memfilter "Belum Lunas" juga tidak boleh mengubah tunggakan.
     *
     * Arah yang lain dari bug yang sama. Kalau filter status ikut masuk ke
     * query tunggakan, menyaring "Belum Lunas" kebetulan menghasilkan
     * angka yang benar -- karena `UNPAID` cocok dengan `UNPAID`. Itu
     * sebabnya bugnya bisa bertahan lama: Setengah dari pilihan filter
     * SELALU benar, dan yang salah hanya separuhnya -- sehingga orang
     * menyimpulkan halamannya yang bermasalah, bukan angkanya.
     */
    #[Test]
    public function filter_belum_lunas_tetap_menampilkan_seluruh_tunggakan(): void
    {
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 1_500_000]);
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 2_000_000]);
        $this->invoice(['status' => 'PAID', 'jumlah' => 750_000]);

        $disaring = $this->ringkasanLokasi(['status' => 'UNPAID']);

        $this->assertSame(2, $disaring['belumLunas']);
        $this->assertSame(3_500_000.0, (float) $disaring['totalTunggakan']);
    }

    /**
     * Angka "lunas" harus ada dan harus benar.
     *
     * Tanpa ini, pertanyaan "berapa yang sudah dibayar" hanya bisa
     * dijawab dashboard -- dan halaman Tagihan, yang justru tempat orang
     * mencari tagihan yang sudah dibayar, tidak bisa menjawabnya.
     */
    #[Test]
    public function ringkasan_menyertakan_jumlah_lunas(): void
    {
        $this->invoice(['status' => 'PAID', 'jumlah' => 750_000]);
        $this->invoice(['status' => 'PAID', 'jumlah' => 250_000]);
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 1_500_000]);

        $semua = $this->ringkasanLokasi();

        $this->assertSame(2, $semua['lunas'], 'Dua tagihan lunas harus ikut terhitung.');
        $this->assertSame(1_000_000.0, (float) $semua['totalLunas']);
        $this->assertSame(1, $semua['belumLunas']);
        $this->assertSame(1_500_000.0, (float) $semua['totalTunggakan']);
    }

    /**
     * `lunas` juga harus bertahan saat status disaring.
     *
     * Kalau `ringkasan` dihitung dari satu query yang sudah dibatasi
     * filter status, maka menyaring "Belum Lunas" membuat `lunas` jadi
     * nol -- dan halaman Tagihan akan menampilkan "Sudah Lunas 0" tepat
     * saat orang sedang mencari tagihan yang SUDAH dibayar.
     */
    #[Test]
    public function jumlah_lunas_tidak_ikut_hilang_saat_status_disaring(): void
    {
        $this->invoice(['status' => 'PAID', 'jumlah' => 750_000]);
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 1_500_000]);

        $disaring = $this->ringkasanLokasi(['status' => 'UNPAID']);

        $this->assertSame(
            1,
            $disaring['lunas'],
            'BUG INI: menyaring "Belum Lunas" membuat "Sudah Lunas" jadi 0.',
        );
        $this->assertSame(750_000.0, (float) $disaring['totalLunas']);
    }

    /* ============================================================
     | DAFTAR TETAP MENGHORMATI FILTER STATUS
     |============================================================
     */

    /**
     * Memisahkan filter status dari ringkasan TIDAK boleh membuat
     * filter statusnya mati.
     *
     * Sisi lain dari perbaikan yang sama. Kalau `status` dibuang begitu
     * saja dari query daftar, tabel akan menampilkan seluruh tagihan
     * apa pun yang disaring -- dan orang yang memilih "Lunas" akan
     * melihat tagihan yang belum dibayar, tanpa ada tanda apa pun.
     */
    #[Test]
    public function filter_status_tetap_menyaring_daftar(): void
    {
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 1_500_000]);
        $this->invoice(['status' => 'PAID', 'jumlah' => 750_000]);

        $res = $this->actingAsSuperadmin()
            ->getJson('/api/central/invoices?tenant_id='.$this->tenant->id.'&status=PAID')
            ->assertOk();

        $status = array_column($res->json('data.data'), 'status');

        $this->assertSame(['PAID'], $status, 'Filter status harus tetap menyaring daftar.');
    }

    /**
     * Tanpa filter status, daftar memuat semuanya -- dan TIDAK satu baris
     * pun yang bukan milik tenant ini.
     *
     * Filter tenant dipakai bersamaan supaya baris milik test lain tidak
     * ikut masuk. Kalau pengikatan tenant ini bocor, test ini akan gagal
     * dengan baris asing yang tidak ada hubungannya.
     */
    #[Test]
    public function tanpa_filter_status_daftar_memuat_semua_status(): void
    {
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 1_500_000]);
        $this->invoice(['status' => 'PAID', 'jumlah' => 750_000]);

        $res = $this->actingAsSuperadmin()
            ->getJson('/api/central/invoices?tenant_id='.$this->tenant->id)
            ->assertOk();

        $status = array_column($res->json('data.data'), 'status');

        sort($status);

        $this->assertSame(['PAID', 'UNPAID'], $status);
    }

    /* ============================================================
     | RINGKASAN MENGIKUTI FILTER LOKASI
     |============================================================
     */

    /**
     * Filter lokasi TETAP ikut ke ringkasan.
     *
     * Ini yang harus tetap benar: memilih "Lokasi A" harus menampilkan
     * tunggakan Lokasi A saja, bukan seluruh lokasi. Yang tidak boleh ikut
     * adalah filter status -- dan alasannya beda: lokasi menjawab
     * "tagihan siapa yang masih menunggak", sedangkan status hanya
     * menyaring DAFTAR yang sedang dibaca.
     *
     * Dua pemeriksaan, dengan cara yang berbeda karena tujuannya berbeda:
     *
     *   - angka per lokasi diperiksa secara MUTLAK, karena tenant_id
     *     membuat angkanya bebas dari baris milik test lain;
     *   - jumlah seluruh lokasi diperiksa lewat SELISIH sebelum-sesudah,
     *     karena tanpa filter tenant, angka itu sudah termasuk baris milik
     *     test lain yang tertinggal di database uji pusat.
     */
    #[Test]
    public function filter_lokasi_tetap_menentukan_ringkasan(): void
    {
        $lain = Tenant::create([
            'slug' => $this->prefix.'lain',
            'nama' => 'Lokasi Lain',
            'nama_lembaga' => 'Lokasi Lain Bersama',
            'database' => (string) config('database.connections.mysql.database'),
            'is_active' => true,
        ]);

        // Angka "semua lokasi" diambil DULU, sebelum tagihan test ini ada.
        $sebelum = $this->ringkasan();

        $this->invoice(['status' => 'UNPAID', 'jumlah' => 1_500_000]);
        $this->invoice(['status' => 'UNPAID', 'jumlah' => 2_000_000, 'tenant_id' => $lain->id]);

        $sesudah = $this->ringkasan();
        $satu = $this->ringkasanLokasi();
        $dua = $this->ringkasan(['tenant_id' => $lain->id]);

        $this->assertSame(
            $sesudah['belumLunas'] - $sebelum['belumLunas'],
            $satu['belumLunas'] + $dua['belumLunas'],
            'Tanpa filter lokasi, ringkasan harus menjumlahkan seluruh lokasi.',
        );

        $this->assertSame(
            round((float) $sesudah['totalTunggakan'] - (float) $sebelum['totalTunggakan'], 2),
            round((float) $satu['totalTunggakan'] + (float) $dua['totalTunggakan'], 2),
            'Nominal seluruh lokasi harus ikut dijumlahkan.',
        );

        $this->assertSame(
            1,
            $satu['belumLunas'],
            'Filter lokasi harus tetap ikut ke ringkasan.',
        );
        $this->assertSame(1_500_000.0, (float) $satu['totalTunggakan']);

        $this->assertSame(1, $dua['belumLunas']);
        $this->assertSame(2_000_000.0, (float) $dua['totalTunggakan']);
    }

    /* ============================================================
     | PAGINASI: HALAMAN 2 TIDAK BOLEH KOSONG
     |============================================================
     */

    /**
     * Halaman kedua harus berisi BARISNYA sendiri.
     *
     * Ini sisi server dari bug yang sama. Sisi frontend-nya --
     * `DataTable` yang memotong `data` dua kali sehingga halaman 2
     * merender kosong padahal server mengirimnya dengan benar -- diuji
     * di `frontend/test/tagihanPanel.test.js`.
     *
     * `tenant_id` tetap dipakai supaya baris milik test lain tidak
     * menambah jumlah halaman. Yang diperiksa bukan `total` (yang bisa
     * dipengaruhi baris asing kalau filternya bocor), tapi:
     *
     *   - halaman 2 berisi 5 baris, bukan 0;
     *   - dan tidak satu pun id-nya sama dengan id halaman 1.
     *
     * Pemeriksaan kedua itu yang menjaga server tidak mengulang halaman
     * 1: jumlah barisnya tetap benar dalam keadaan itu, jadi test pertama
     * akan lulus -- tapi orangnya membaca tagihan yang sama dua kali
     * tanpa pernah melihat sisanya.
     */
    #[Test]
    public function halaman_kedua_mengisi_barisnya_sendiri(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->invoice([
                'status' => $i % 2 === 0 ? 'PAID' : 'UNPAID',
                'jumlah' => 100_000 * $i,
                'tgl_invoice' => now()->subDays(20 - $i)->toDateString(),
            ]);
        }

        $dasar = '/api/central/invoices?tenant_id='.$this->tenant->id.'&per_page=10';

        $pertama = $this->actingAsSuperadmin()
            ->getJson($dasar.'&page=1')
            ->assertOk();

        $kedua = $this->actingAsSuperadmin()
            ->getJson($dasar.'&page=2')
            ->assertOk();

        $this->assertCount(10, $pertama->json('data.data'));
        $this->assertSame(2, (int) $kedua->json('data.last_page'));

        $this->assertCount(
            5,
            $kedua->json('data.data'),
            'BUG INI: halaman 2 kosong. `DataTable` memotong `data` yang sudah dipaginasi server.',
        );

        $this->assertSame(
            [],
            array_values(array_intersect(
                array_column($pertama->json('data.data'), 'id'),
                array_column($kedua->json('data.data'), 'id'),
            )),
            'Halaman 2 mengulang baris halaman 1.',
        );
    }

    /* ============================================================
     | BANTUAN
     |============================================================
     */

    /**
     * Ambil `ringkasan` dari endpoint tagihan.
     *
     * @param  array<string, string|int>  $params
     * @return array<string, mixed>
     */
    private function ringkasan(array $params = []): array
    {
        $query = $params === [] ? '' : '?'.http_build_query($params);

        $res = $this->actingAsSuperadmin()
            ->getJson('/api/central/invoices'.$query)
            ->assertOk();

        return $res->json('ringkasan');
    }

    /**
     * `ringkasan` untuk satu lokasi saja.
     *
     * Hampir semua test memakai pembungkus ini, bukan `ringkasan()` langsung,
     * karena database uji pusat dipakai bersama dan menyisakan baris invoice
     * milik test lain. Tanpa `tenant_id`, angka `belumLunas` jadi hitungan
     * seluruh database dan tidak ada yang bisa diperiksa secara mutlak.
     *
     * `tenant_id` sengaja TIDAK dijadikan nilai bawaan di dalam `ringkasan()`:
     * satu test di bawah memang sengaja memanggil tanpa filter lokasi, dan
     * membiarkan filter itu tersembunyi di dalam helper membuat penyimpangan
     * sekecil `tenant_id` diabaikan diam-diam -- persis yang harus dicek.
     *
     * @param  array<string, string|int>  $params
     * @return array<string, mixed>
     */
    private function ringkasanLokasi(array $params = []): array
    {
        return $this->ringkasan(['tenant_id' => $this->tenant->id] + $params);
    }

    private function invoice(array $attrs = []): TenantInvoice
    {
        static $urut = 0;

        return TenantInvoice::create($attrs + [
            'tenant_id' => $this->tenant->id,
            'nomor' => 'INV/'.date('Y').'/'.str_pad((string) ++$urut, 4, '0', STR_PAD_LEFT),
            'tgl_invoice' => now()->subDays(10)->toDateString(),
            'jumlah' => 1_500_000,
            'status' => 'UNPAID',
        ]);
    }

    private function makeSuperadmin(): User
    {
        $central = DB::connection(self::CENTRAL);

        $id = $central->table('users')->insertGetId([
            'name' => 'Admin Pusat',
            'email' => 'ringkasan-'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $model = new User;
        $model->setConnection(self::CENTRAL);
        $model->forceFill([
            'name' => 'Admin Pusat',
            'email' => 'ringkasan-'.$id.'@test.local',
            'password' => Hash::make('password'),
            'role' => 'superadmin',
            'is_active' => true,
        ]);

        $model->id = $id;
        $model->exists = true;

        return $model;
    }

    private function actingAsSuperadmin(): self
    {
        Sanctum::actingAs($this->superadmin, ['*'], 'sanctum');

        return $this;
    }
}