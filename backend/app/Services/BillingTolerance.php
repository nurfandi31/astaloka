<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Satu tempat untuk semua aturan yang dulu berbagi kolom `toleransi_tunggakan`.
 *
 * MASALAH
 * -------
 *
 * Satu kolom itu dibaca sebagai empat hal berbeda:
 *
 *   1. TANGGAL dalam bulan, 1-28 -> `DashboardController` menentukan hari
 *      generate piutang.
 *   2. JUMLAH HARI mundur dari hari run -> `GenerateOverdueTransactions`
 *      menghitung `thresholdDate = today - N days`.
 *   3. TANGGAL dalam bulan pemakaian tagihan -> `MonthlyBillController::pay`
 *      menentukan ambang deteksi advance payment.
 *   4. JUMLAH tagihan unpaid sebelum suspend -> `MeterReadingController::store`
 *      memutus sambungan dengan `$unpaidCount > $toleransi`.
 *
 * Kelima: `TenantManager:511` mengisi kolom ini dari `invoice_grace_months`
 * yang satuan-nya BULAN, untuk tagihan langganan ke lokasi.
 *
 * Dengan nilai 6, semua aktif bersamaan:
 *   - generate hanya jalan tanggal 6
 *   - ambang piutang = hari ini - 6 hari
 *   - bayar sebelum tanggal 6 bulan tagihan = advance
 *   - suspend setelah 7 tagihan unpaid
 *   - tagihan langganan tenggang 6 BULAN
 *
 * KENAPA SATU ANGKA TIDAK CUKUP
 * -----------------------------
 *
 * Tiga makna pertama tidak bisa bergerak naik-turun bersama dalam satu
 * rentang. Ambang piutang butuh nilai kecil, tanggal generate butuh nilai
 * besar, batas suspend butuh angka puluhan. Admin yang ingin menunda
 * generate sampai tanggal 20 tidak akan ikutan ingin ambang piutang 20 hari.
 *
 * YANG DILAKUKAN
 * -------------
 *
 * Kolom `toleransi_tunggakan` TETAP ADA dan tetap jadi sumber utama untuk
 * semua makna yang memakainya, sehingga:
 *
 *   - Data lama tidak berubah.
 *   - Perilaku default tidak berubah. pamsides-v2 dan astaloka menghasilkan
 *     angka yang sama persis untuk lokasi yang tidak mengubah SOP-nya.
 *
 * Ditambah override opsional untuk makna yang paling tidak cocok, yaitu
 * batas tagihan menunggak sebelum suspend. Bila kolom barunya diisi, makna
 * itu memakai nilai sendiri; bila kosong, ia kembali ke nilai lama.
 *
 * INVARIANT
 * ---------
 *
 * `legacy()` mengembalikan angka yang dulu dipakai semua consumer. Setiap
 * perbaikan PERILAKU dilakukan lewat override, sehingga selama override
 * kosong hasilnya identik dengan versi lama.
 */
class BillingTolerance
{
    private ?Setting $settings = null;

    /**
     * Ambil `Setting` satu kali per instance.
     *
     * Satu request bisa memanggil beberapa accessor di sini (login memanggil
     * `generateDay()`, `graceDays()`, dan `unpaidBillLimit()`), dan tiap
     * pemanggilan tanpa cache adalah satu query.
     */
    private function settings(): Setting
    {
        return $this->settings ??= Setting::first() ?? new Setting;
    }

    /**
     * Nilai mentah kolom `toleransi_tunggakan`.
     *
     * Angka yang dulu dipakai seluruh consumer. Semua accessor lain memakai
     * ini sebagai fallback.
     */
    public function legacy(): int
    {
        return (int) ($this->settings()->toleransi_tunggakan ?? 0);
    }

    /**
     * (1) Tanggal generate piutang dalam bulan, rentang 1-28.
     *
     * Nilai di luar rentang berarti SOP belum diisi, dan pemanggil wajib
     * menganggap generate tidak dijadwalkan sama sekali -- bukan diam-diam
     * memakai angka yang tidak masuk akal seperti 30 atau 0.
     */
    public function generateDay(): int
    {
        $day = $this->legacy();

        return ($day >= 1 && $day <= 28) ? $day : 0;
    }

    /**
     * (2) Ambang piutang: berapa HARI sebelum hari generate sebuah tagihan
     *     dianggap menunggak.
     *
     * Satu-satunya tempat nilai ini dibaca sebagai JUMLAH HARI. Frontend
     * tidak pernah memakainya, jadi tidak ada risiko pratinjau melenceng
     * seperti pada makna advance payment.
     *
     * Tidak ada override. Ambang ini terlalu dekat dengan tanggal generate:
     * kalau orang menggeser tanggal generate, mereka hampir selalu juga
     * ingin ambang piutang ikut bergeser.
     */
    public function graceDays(): int
    {
        return $this->legacy();
    }

    /**
     * (3) Ambang deteksi advance payment: TANGGAL dalam bulan pemakaian tagihan.
     *
     * Logika yang SANGAT sensitif, tidak boleh diubah tanpa persetujuan
     * bisnis. Perhitungannya:
     *
     *     threshold = Carbon::create(year, month, min(day, daysInMonth))
     *     isAdvance = paidAtDate->lt(threshold)
     *
     * Frontend `BillingForm.vue` menghitung ulang hal yang sama untuk
     * menampilkan pratinjau, jadi keduanya harus memakai angka yang sama.
     * Kalau tidak, pratinjau dan hasil sebenarnya berbeda dan user melihat
     * status advance yang salah.
     */
    public function advanceDayOfMonth(): int
    {
        return $this->legacy();
    }

    /**
     * (4) Batas tagihan unpaid sebelum sambungan diputus.
     *
     * Makna paling tidak kebetulan cocok dengan tiga lainnya, jadi punya
     * kolom sendiri: `billing_unpaid_limit`. Nilai lama dari
     * `toleransi_tunggakan` tetap dipakai sebagai fallback supaya perilaku
     * tidak berubah untuk lokasi yang belum mengisi kolom baru.
     */
    public function unpaidBillLimit(): int
    {
        $override = $this->settings()->billing_unpaid_limit;

        return $override !== null && $override !== ''
            ? (int) $override
            : $this->legacy();
    }

    /**
     * Apakah sambungan pelanggan diputus setelah mencatat meter.
     *
     * Dipakai `MeterReadingController::store`. Defaultnya sama persis dengan
     * versi lama; yang berubah hanya sumber nilainya.
     */
    public function shouldSuspend(int $unpaidCount, ?int $limit = null): bool
    {
        $limit ??= $this->unpaidBillLimit();

        return $limit > 0 && $unpaidCount > $limit;
    }

    /**
     * (5) Masa tenggang tagihan langganan ASTALOKA.
     *
     * Bedanya keempat: satuan BULAN, bukan hari, dan berlaku untuk tagihan
     * dari pusat ke lokasi -- bukan tunggakan air pelanggan.
     *
     * Nilai ini TIDAK boleh dibaca dari `toleransi_tunggakan`.
     * `TenantInvoice::deadline()` memakai `config('tenancy.invoice_grace_months')`.
     * Yang ada di sini hanya pengingat eksplisit supaya keduanya tidak
     * disamakan di kemudian hari.
     */
    public function invoiceGraceMonths(): int
    {
        return (int) config('tenancy.invoice_grace_months', 1);
    }
}