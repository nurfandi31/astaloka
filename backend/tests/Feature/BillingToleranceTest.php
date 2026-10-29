<?php

namespace Tests\Feature;

use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Services\BillingTolerance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pemisahan arti `toleransi_tunggakan` dari aturan yang benar-benar berbeda.
 *
 * Latar belakang
 * ---------------
 *
 * Satu kolom `settings.toleransi_tunggakan` dibaca sebagai empat hal yang
 * tidak bisa bergerak naik-turun bersama dalam satu rentang angka:
 *
 *   1. Tanggal generate piutang dalam bulan (1-28).
 *   2. Jumlah hari mundur dari hari run untuk ambang piutang.
 *   3. Tanggal dalam bulan pemakaian tagihan untuk deteksi advance payment.
 *   4. Jumlah tagihan unpaid sebelum sambungan pelanggan diputus.
 *
 * Dengan nilai 6, semuanya aktif bersamaan: generate hanya jalan tanggal 6,
 * ambang piutang = hari ini - 6 hari, bayar sebelum tanggal 6 dianggap
 * advance, dan suspend setelah 7 tagihan unpaid. Admin yang ingin menunda
 * generate ke tanggal 20 ikut mengubah batas tagihan yang boleh menunggak.
 *
 * Test di sini mengunci bahwa keempatnya membaca SATU sumber angka yang
 * sama, dan bahwa sumber itu tetap `toleransi_tunggakan`.
 *
 * Catatan: pernah ada kolom override `billing_unpaid_limit` khusus untuk
 * makna (4), beserta input sendiri di halaman SOP. Keduanya sudah
 * DIHAPUS -- inputnya justru membuat masalah, karena dua angka dengan
 * satuan berbeda (tanggal vs jumlah tagihan) berdampingan di satu kartu
 * dan terlihat saling menimpa. Kolomnya masih ada di database, tapi tidak
 * lagi dibaca kode mana pun. Test di bawah mengunci itu.
 */
class BillingToleranceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tulis (atau timpa) SOP lokasi ini.
     *
     * Dipakai `updateOrCreate`, bukan `create`, karena beberapa test memanggilnya
     * lebih dari sekali untuk diperiksa dua nilai berbeda dalam satu test --
     * dan `settings.key` itu unik, jadi `create` kedua akan meledak.
     */
    private function setSOP(int $toleransi): void
    {
        Setting::updateOrCreate(
            ['key' => 'sop'],
            [
                'batas_tagihan' => 27,
                'toleransi_tunggakan' => $toleransi,
            ]
        );
    }

    #[Test]
    public function keempat_arti_membaca_satu_sumber_angka(): void
    {
        $this->setSOP(6);

        $t = app(BillingTolerance::class);

        $this->assertSame(6, $t->legacy(), 'Nilai mentah harus sama.');
        $this->assertSame(6, $t->generateDay(), 'Tanggal generate harus sama.');
        $this->assertSame(6, $t->graceDays(), 'Ambang piutang harus sama.');
        $this->assertSame(6, $t->advanceDayOfMonth(), 'Ambang advance harus sama.');
        $this->assertSame(6, $t->unpaidBillLimit(), 'Batas suspend harus sama.');
    }

    #[Test]
    public function batas_suspend_tidak_lagi_pakai_kolom_override(): void
    {
        /*
         * Kolom `billing_unpaid_limit` masih ADA di database, tapi tidak lagi
         * dibaca. Kalau ada yang mengisinya lagi, angka yang dipakai batas
         * suspend harus tetap `toleransi_tunggakan` -- persis seperti
         * aplikasi pamsides.
         *
         * Ditulis langsung lewat query builder karena kolomnya sudah tidak
         * ada di `$fillable` model `Setting` -- sengaja, supaya tidak ada
         * jalur kode yang bisa menuliskannya lagi.
         */
        \DB::table('settings')->updateOrInsert(
            ['key' => 'sop'],
            [
                'batas_tagihan' => 27,
                'toleransi_tunggakan' => 6,
                'billing_unpaid_limit' => 3,
            ]
        );

        $t = app(BillingTolerance::class);

        $this->assertSame(
            6,
            $t->unpaidBillLimit(),
            'Batas suspend harus pakai toleransi_tunggakan, bukan kolom override.'
        );
    }

    #[Test]
    public function batas_suspend_nol_berarti_tidak_pernah_memutus(): void
    {
        $this->setSOP(0);

        $t = app(BillingTolerance::class);

        $this->assertSame(0, $t->unpaidBillLimit());
        $this->assertFalse(
            $t->shouldSuspend(999),
            'Nilai 0 berarti tidak pernah memutus sambungan otomatis.'
        );
    }

    #[Test]
    public function batas_suspend_memakai_operator_ketidak_sama(): void
    {
        $this->setSOP(3);

        $t = app(BillingTolerance::class);

        // Perilaku lama `MonthlyBillController`/`MeterReadingController`:
        // `$unpaidCount > $toleransi`. Harus dipertahankan persis -- yang
        // berubah hanya sumber angkanya.
        $this->assertFalse($t->shouldSuspend(3), 'Tepat sama dengan batas: belum diputus.');
        $this->assertTrue($t->shouldSuspend(4), 'Melebihi batas: diputus.');
    }

    #[Test]
    public function batas_suspend_tidak_berlaku_kalau_tidak_ada_setting(): void
    {
        // Tidak ada baris Setting sama sekali.
        $t = app(BillingTolerance::class);

        $this->assertSame(0, $t->legacy());
        $this->assertSame(0, $t->generateDay());
        $this->assertSame(0, $t->unpaidBillLimit());
        $this->assertFalse($t->shouldSuspend(5), 'Tanpa SOP, tidak ada pemutusan otomatis.');
    }

    #[Test]
    public function tanggal_generate_di_luar_rentang_berarti_tidak_dijadwalkan(): void
    {
        // Nilai 0 berarti "SOP belum diisi", bukan "generate tanggal 0".
        $this->setSOP(0);
        $this->assertSame(0, app(BillingTolerance::class)->generateDay());

        // Nilai 30 tidak bisa jadi tanggal generate.
        $this->setSOP(30);
        $this->assertSame(0, app(BillingTolerance::class)->generateDay());
    }

    #[Test]
    public function tenggang_tagihan_langganan_terpisah_dan_satuan_bulan(): void
    {
        $this->setSOP(6);

        $bulan = app(BillingTolerance::class)->invoiceGraceMonths();

        $this->assertNotSame(
            app(BillingTolerance::class)->legacy(),
            $bulan,
            'Tenggangan langganan (BULAN) tidak boleh disamakan dengan toleransi (HARI/TANGGAL).'
        );
    }

    #[Test]
    public function tagihan_menunggak_menghormati_batas_suspend(): void
    {
        // Regresi langsung untuk `MeterReadingController::store`, yang
        // memakai `BillingTolerance::unpaidBillLimit()`.
        //
        // Yang diperiksa di sini adalah sumber angkanya, bukan seluruh alur
        // meter reading: seluruh logika suspend di controller sudah ada dan
        // tidak berubah -- hanya nilai yang dibacanya.
        $this->setSOP(3);
        $this->assertSame(3, app(BillingTolerance::class)->unpaidBillLimit());

        // Count unpaid seperti yang dibaca controller.
        $unpaid = MonthlyBill::where('status', 'unpaid')->count();

        $this->assertFalse(
            app(BillingTolerance::class)->shouldSuspend($unpaid),
            'Tidak ada tagihan unpaid: tidak ada yang diputus.'
        );
    }
}
