<?php

namespace Tests\Feature;

use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReceivableGenerator;
use App\Services\TenantCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Generate piutang tunggakan yang dipicu LOGIN.
 *
 * Latar belakang
 * ---------------
 *
 * Sebelum ada test ini, generate piutang hanya dipicu dari frontend
 * (`useOverdueGenNotification.js`) saat dashboard dibuka, dan hanya jalan bila
 * hari ini PERSIS sama dengan `toleransi_tunggakan`.
 *
 * Dua kegagalan yang bisa terjadi tanpa error, tanpa log, tanpa jejak:
 *
 *   1. Tidak ada staff yang membuka dashboard pada tanggal itu. Tagihan
 *      menunggak tidak pernah dijurnalkan. `monthly_bills` tetap `unpaid`,
 *      tapi akun Piutang Usaha `1.1.03.01` tidak pernah ditambah --
 *      Neraca under-report sementara daftar tagihan tidak.
 *
 *   2. Gate `===` membuat tanggal 29, 30, dan 31 tidak pernah bisa menjadi
 *      tanggal generate.
 *
 * Test di sini mengunci perilaku yang baru: login adalah pemicu generate.
 */
class LoginGeneratePiutangTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Satu lokasi dengan satu pelanggan dan satu tagihan menunggak.
     *
     * Mengembalikan `[$admin, $bill]`.
     *
     * @return array{0: User, 1: MonthlyBill}
     */
    private function seedLokasiDenganTagihanMenunggak(int $penalty = 0): array
    {
        $admin = $this->createUser('admin');

        $packageId = \DB::table('installation_packages')->insertGetId([
            'name' => 'Paket Test',
            'installation_fee' => 0,
            'monthly_abodemen' => 10_000,
            'late_penalty' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = \DB::table('installation_tickets')->insertGetId([
            'package_id' => $packageId,
            'applicant_name' => 'Budi Santoso',
            'nik' => '3321010101900002',
            'order_date' => now()->toDateString(),
            'address' => 'Alamat Test',
            'lat' => -7.1234567,
            'lng' => 110.1234567,
            'status' => 'completed',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pelanggan = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi-'.uniqid().'@pdam.test',
            'password' => Hash::make('password'),
            'role' => 'pelanggan',
        ]);

        $customerId = \DB::table('customers')->insertGetId([
            'ticket_id' => $ticketId,
            'user_id' => $pelanggan->id,
            'customer_code' => '7350',
            'initial_meter_reading' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bill = MonthlyBill::create([
            'customer_id' => $customerId,
            'billing_period_year' => now()->year,
            'billing_period_month' => max(1, (int) now()->format('m') - 1),
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 50_000,
            'abodemen' => 10_000,
            'penalty_amount' => $penalty,
            'total_amount' => 60_000 + $penalty,
            'status' => 'unpaid',
            // Awal bulan lalu: pasti lebih tua dari ambang berapa pun tanggal
            // test dijalankan.
            'due_date' => now()->subMonths(1)->day(1)->toDateString(),
        ]);

        return [$admin, $bill];
    }

    private function createUser(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'login-'.$role.'-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('rahasia123'),
            'role' => $role,
        ]);
    }

    /**
     * Login dan kembalikan respons JSON.
     */
    private function login(User $user)
    {
        return $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'rahasia123',
        ]);
    }

    #[Test]
    public function login_membuat_jurnal_piutang_tunggakan_tanpa_perlu_buka_dashboard(): void
    {
        // Tanggal generate = hari ini, supaya generate HARUS boleh jalan.
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 27,
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin, $bill] = $this->seedLokasiDenganTagihanMenunggak();

        $this->assertSame(
            0,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Jurnal piutang harus belum ada sebelum login.'
        );

        $response = $this->login($admin);

        $response->assertOk()->assertJson(['success' => true]);

        // Inilah inti dari test ini: tidak ada panggilan ke dashboard, tidak
        // ada frontend yang menyentuh apa pun. Cukuplah login.
        $jurnal = Transaction::where('reverence_type', 'overdue_bill')
            ->where('reverence_id', $bill->id)
            ->get();

        $this->assertCount(2, $jurnal, 'Harus ada jurnal piutang abodemen dan pemakaian.');
        $this->assertSame(
            ['4.1.01.02', '4.1.01.03'],
            $jurnal->pluck('account_kredit')->sort()->values()->all(),
            'Akun kredit harus abodemen dan pemakaian.'
        );

        foreach ($jurnal as $trx) {
            $this->assertSame('1.1.03.01', $trx->account_debet, 'Piutang Usaha harus di-debet.');
        }
    }

    #[Test]
    public function login_melaporkan_ringkasan_generate_piutang(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 27,
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $response = $this->login($admin);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.piutang_tunggakan.ran', true)
            ->assertJsonPath('data.piutang_tunggakan.summary.jurnal_abodemen', 1)
            ->assertJsonPath('data.piutang_tunggakan.summary.jurnal_pemakaian', 1);
    }

    #[Test]
    public function login_tidak_menjalankan_generate_sebelum_tanggal_generate(): void
    {
        // Tanggal generate = besok, jadi hari ini belum waktunya.
        $besok = (int) now()->addDay()->format('d');

        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 27,
            'toleransi_tunggakan' => $besok > 28 ? 28 : $besok,
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        // Kalau `besok` jatuh di tanggal <= 28 dan masih "besok", gate
        // `day >= scheduledDay` HARUS menahannya. Kalau tidak, test ini
        // gagal dan berarti gate-nya salah.
        if ((int) now()->format('d') < $besok) {
            $this->login($admin)->assertOk()
                ->assertJsonPath('data.piutang_tunggakan.ran', false);

            $this->assertSame(
                0,
                Transaction::where('reverence_type', 'overdue_bill')->count(),
                'Tidak boleh ada jurnal sebelum tanggal generate.'
            );
        }
    }

    #[Test]
    public function login_tetap_berhasil_walaupun_generate_gagal(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 27,
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        // Paksa `runIfDue()` melempar exception dari dalam dirinya, supaya
        // catch di `ReceivableGenerator` yang diuji -- bukan catch di
        // `AuthController`.
        //
        // Cara: injeksi dependency lewat container. Tabel TIDAK di-drop di
        // sini: `RefreshDatabase` membungkus tiap test dalam transaksi,
        // dan drop tabel di tengahnya membuat rollback framework-nya sendiri
        // gagal dengan "SAVEPOINT does not exist" -- itu menguji savepoint,
        // bukan ketahanan login.
        $generator = Mockery::mock(ReceivableGenerator::class);
        $generator->shouldReceive('runIfDue')
            ->andThrow(new \RuntimeException('Koneksi tagihan terputus.'));
        app()->instance(ReceivableGenerator::class, $generator);

        $response = $this->login($admin);

        // Password benar + akun aktif + tagihan langganan lunas.
        // Kegagalan pekerjaan akuntansi internal TIDAK BOLEH menjadi alasan
        // orang tidak bisa masuk.
        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.piutang_tunggakan', null)
            ->assertJsonPath('data.token', fn ($t) => is_string($t) && $t !== '');

        $this->assertSame(
            1,
            User::where('email', $admin->email)->count(),
            'User harus tetap ada setelah generate gagal.'
        );
    }

    #[Test]
    public function generate_gagal_dilaporkan_tanpa_menggagalkan_generate_berikutnya(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 27,
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        // `ReceivableGenerator` yang benar, tapi generate-nya gagal sekali.
        $partial = new class(app(TenantCache::class)) extends ReceivableGenerator
        {
            public int $calls = 0;

            public function execute(?Carbon $now = null): ?array
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new \RuntimeException('Deadlock saat agregasi amount.');
                }

                return parent::execute($now);
            }
        };
        app()->instance(ReceivableGenerator::class, $partial);

        // Percobaan pertama: gagal, tapi login tetap berhasil, dan mutex
        // HARUS dilepas supaya percobaan kedua di hari yang sama masih punya
        // kesempatan.
        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', false);

        // Percobaan kedua: berhasil.
        $kedua = $this->login($admin);
        $kedua->assertOk()->assertJsonPath('data.piutang_tunggakan.ran', true);

        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Percobaan kedua harusnya membuat jurnal piutang.'
        );
    }

    /**
     * Generate boleh dijalankan ulang di hari yang sama.
     *
     * Dulu ada mutex "sekali per tanggal generate" yang membuat login kedua
     * pada tanggal yang sama dilewati. Mutex itu diturunkan menjadi
     * BUSY-GUARD saja (mencegah dua proses agregasi `amount` berjalan
     * bersamaan), bukan penghalang generate.
     *
     * Alasan operasionalnya: kadang ada pelanggan yang lupa digenerate, atau
     * tagihannya lewat dari tanggal generate. Generate ulang harus bisa
     * menutup kekurangan itu tanpa intervensi manual.
     *
     * Yang tetap dijaga: TIDAK boleh ada jurnal dobel. Command-nya sudah
     * idempotent lewat pasangan `reverence_id|account_kredit`.
     */
    #[Test]
    public function generate_boleh_dijalankan_ulang_di_hari_yang_sama_tanpa_jurnal_ganda(): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 27,
            'toleransi_tunggakan' => (int) now()->format('d'),
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', true);

        $jurnalSetelahLoginPertama = Transaction::where('reverence_type', 'overdue_bill')->count();
        $this->assertSame(2, $jurnalSetelahLoginPertama);

        // Login kedua di hari yang sama: generate TETAP dijalankan.
        $kedua = $this->login($admin);
        $kedua->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', true);

        // Tapi tidak boleh menambah jurnal: command-nya idempotent.
        $this->assertSame(
            $jurnalSetelahLoginPertama,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Login kedua tidak boleh menambah jurnal piutang.'
        );
    }

    /**
     * Generate tetap jalan setelah tanggal generate terlewat.
     *
     * Gate `>=` (bukan `===`): kalau tanggal generate terlewat, generate
     * tetap boleh jalan. Dulu gate `===` membuat bulan tanpa staff yang
     * login tepat pada tanggal itu TIDAK PERNAH punya jurnal piutang --
     * tanpa error, tanpa log, tanpa jejak.
     */
    #[Test]
    public function generate_tetap_jalan_setelah_tanggal_generate_terlewat(): void
    {
        // Tanggal generate = 1, "hari ini" sengaja lebih besar supaya
        // pastilah sudah lewat dari tanggal generate.
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => 27,
            'toleransi_tunggakan' => 1,
        ]);

        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $this->login($admin)->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', true);

        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Tanggal generate yang terlewat tidak boleh menggagalkan generate.'
        );
    }

    #[Test]
    public function login_tidak_menjalankan_generate_kalendar_sistem_kosong(): void
    {
        // Tidak ada baris Setting sama sekali.
        [$admin] = $this->seedLokasiDenganTagihanMenunggak();

        $response = $this->login($admin);

        $response->assertOk()
            ->assertJsonPath('data.piutang_tunggakan.ran', false);

        $this->assertSame(
            0,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Tanpa tanggal generate di SOP, tidak ada yang boleh dijurnalkan.'
        );
    }
}
