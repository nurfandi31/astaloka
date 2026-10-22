<?php

namespace Tests\Feature;

use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\MonthlyBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pengaman untuk alur "Transaksi Komisi SPS".
 *
 * Tiga hal yang diuji di sini adalah kegagalan yang pernah nyata dan tidak
 * terlihat dari layar: tanggal laporan meleset satu periode, nominal komisi
 * di layar berbeda dari total jurnal, dan tagihan bisa dibayar dua kali.
 */
class KomisiSpsTest extends TestCase
{
    use RefreshDatabase;

    private const KAS = '1.1.01.01';
    private const UTANG_KOMISI = '2.1.02.02';
    private const BEBAN_KOMISI = '5.1.02.04';

    private User $admin;
    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin Test',
            'email'    => 'admin@komisi.test',
            'password' => Hash::make('password'),
            'role'     => 'admin',
        ]);

        $this->teknisi = User::create([
            'name'     => 'Teknisi Test',
            'email'    => 'teknisi@komisi.test',
            'password' => Hash::make('password'),
            'role'     => 'teknisi',
        ]);
    }

    private function makeCustomer(string $code, string $email): Customer
    {
        $package = InstallationPackage::create([
            'name'             => 'Paket Test',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 15_000,
            'late_penalty'     => 10_000,
        ]);

        $user = User::create([
            'name'     => 'Pelanggan '.$code,
            'email'    => $email,
            'password' => Hash::make('password'),
            'role'     => 'pelanggan',
        ]);

        $ticket = InstallationTicket::create([
            'package_id'     => $package->id,
            'applicant_name' => 'Pelanggan '.$code,
            'nik'            => '330000000000'.random_int(1000, 9999),
            'address'        => 'Jl. Test No. 1',
            'lat'            => -7.797068,
            'lng'            => 110.370529,
            'status'         => 'completed',
            'created_by'     => $this->admin->id,
        ]);

        return Customer::create([
            'ticket_id'              => $ticket->id,
            'user_id'                => $user->id,
            'customer_code'          => $code,
            'initial_meter_reading'  => 0,
            'meter_photo_url'        => '/storage/test.jpg',
            'activated_at'           => now(),
        ]);
    }

    private function makeBill(Customer $customer, float $total, int $bulan = 7): MonthlyBill
    {
        return MonthlyBill::create([
            'customer_id'          => $customer->id,
            'billing_period_year'  => 2026,
            'billing_period_month' => $bulan,
            'meter_reading_start'  => 0,
            'meter_reading_end'    => 10,
            'usage_m3'             => 10,
            'usage_charge'         => $total - 15_000,
            'abodemen'             => 15_000,
            'penalty_amount'       => 0,
            'total_amount'         => $total,
            'status'               => 'unpaid',
            'due_date'             => '2026-08-10',
        ]);
    }

    private function simpan(array $overrides = [])
    {
        return $this->postJson('/api/komisi-sps', array_merge([
            'tgl_transaksi'      => '2026-08-15',
            'customer_ids'       => $overrides['customer_ids'] ?? [],
            'account_kas'        => self::KAS,
            'penerima_komisi_id' => $this->teknisi->id,
            'keterangan'         => 'Komisi test',
        ], array_diff_key($overrides, array_flip(['customer_ids']))));
    }

    /**
     * `paid_at` harus sama dengan tanggal transaksi yang dipilih admin.
     *
     * Laporan Piutang Komisi menyaring berdasarkan `whereDate('paid_at')`,
     * sementara jurnal memakai `tgl_transaksi`. Kalau `paid_at` diisi jam
     * server, memilih tanggal bulan lain membuat jurnal masuk periode itu
     * sementara baris piutang komisinya tetap di periode sekarang.
     */
    #[Test]
    public function tanggal_transaksi_juga_menentukan_tanggal_piutang_komisi(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $customer = $this->makeCustomer('KOMISI-00001', 'p1@komisi.test');
        $this->makeBill($customer, 65_000);

        $this->simpan(['customer_ids' => [$customer->id]])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $payment = BillPayment::latest('id')->first();

        $this->assertNotNull($payment, 'Baris bill_payments tidak terbentuk.');
        $this->assertSame(
            '2026-08-15',
            $payment->paid_at->toDateString(),
            'paid_at harus mengikuti tanggal transaksi, bukan waktu server.',
        );

        // Jurnal pada periode yang sama -- inilah yang harus cocok saat dicek.
        $trx = Transaction::where('reverence_type', 'bill_payment')
            ->where('reverence_id', $payment->id)
            ->first();

        $this->assertSame('2026-08-15', $trx->tgl_transaksi->toDateString());
    }

    /**
     * Nominal komisi yang dilaporkan harus sama persis dengan total jurnal.
     *
     * Jurnal membulatkan 10% per tagihan. Kalau response menghitung rate atas
     * total tagihan sekali, jumlah keduanya bisa berbeda beberapa rupiah --
     * user lalu menganggap jurnal yang salah.
     */
    #[Test]
    public function nominal_komisi_sama_dengan_total_jurnal_beban(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        // Tiga tagihan yang individually menghasilkan pembulatan berbeda dari
        // pembulatan atas totalnya.
        $customer = $this->makeCustomer('KOMISI-00002', 'p2@komisi.test');
        $this->makeBill($customer, 65_000, 5);
        $this->makeBill($customer, 73_500, 6);
        $this->makeBill($customer, 41_250, 7);

        $response = $this->simpan(['customer_ids' => [$customer->id]])->assertStatus(201);

        $reported = (float) $response->json('data.nominal_komisi');

        $jurnalTotal = (float) Transaction::where('account_debet', self::BEBAN_KOMISI)
            ->where('account_kredit', self::UTANG_KOMISI)
            ->sum('saldo');

        $this->assertSame(
            $reported,
            round($jurnalTotal, 2),
            'Nominal yang dilaporkan harus sama dengan total jurnal komisi.',
        );

        // Dan harus sama dengan pembulatan per tagihan, bukan atas totalnya.
        $perTagihan = 65_000 * 0.1 + 73_500 * 0.1 + 41_250 * 0.1;

        $this->assertSame(
            round($perTagihan, 2),
            $reported,
            'Komisi harus dihitung per tagihan, bukan rate dikalikan sekali atas total.',
        );
    }

    /**
     * Tagihan yang sudah dibayar tidak boleh dicatat dua kali.
     *
     * Pilihannya dijaga `lockForUpdate()` di dalam transaksi: tanpa itu,
     * request kedua masih membaca `unpaid` dan membuat jurnal kas serta
     * komisi kedua untuk tagihan yang sama.
     */
    #[Test]
    public function tagihan_yang_sudah_dibayar_ditolak_saat_dipilih_lagi(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $customer = $this->makeCustomer('KOMISI-00003', 'p3@komisi.test');
        $bill = $this->makeBill($customer, 65_000);

        $this->simpan(['customer_ids' => [$customer->id]])->assertStatus(201);

        // Request kedua atas pelanggan yang sama. Tagihannya sudah lunas,
        // jadi tidak boleh ada tagihan unpaid yang bisa dibayar lagi.
        $response = $this->simpan(['customer_ids' => [$customer->id]]);

        $response->assertStatus(422);

        $this->assertSame(1, BillPayment::where('bill_id', $bill->id)->count(), 'Hanya boleh ada satu pembayaran per tagihan.');
        $this->assertSame('paid', $bill->fresh()->status);
    }

    /**
     * Pelanggan tanpa tagihan unpaid ditolak, bukan jurnal kosong yang tertulis.
     */
    #[Test]
    public function pelanggan_tanpa_tagihan_unpaid_ditolak(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $customer = $this->makeCustomer('KOMISI-00004', 'p4@komisi.test');

        $this->simpan(['customer_ids' => [$customer->id]])->assertStatus(422);

        $this->assertSame(0, Transaction::where('account_debet', self::BEBAN_KOMISI)->count());
    }

    /**
     * Akun kas dan penerima komisi wajib diisi.
     */
    #[Test]
    public function akun_kas_dan_penerima_komisi_wajib_diisi(): void
    {
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $customer = $this->makeCustomer('KOMISI-00005', 'p5@komisi.test');
        $this->makeBill($customer, 65_000);

        $this->postJson('/api/komisi-sps', [
            'tgl_transaksi' => '2026-08-15',
            'customer_ids'  => [$customer->id],
            'account_kas'   => '9.9.99.99',
            'penerima_komisi_id' => $this->teknisi->id,
        ])->assertStatus(422)->assertJsonValidationErrors('account_kas');

        $this->postJson('/api/komisi-sps', [
            'tgl_transaksi' => '2026-08-15',
            'customer_ids'  => [$customer->id],
            'account_kas'   => self::KAS,
        ])->assertStatus(422)->assertJsonValidationErrors('penerima_komisi_id');
    }
}