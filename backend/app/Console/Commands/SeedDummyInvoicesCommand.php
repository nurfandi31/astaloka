<?php

namespace App\Console\Commands;

use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Isi tagihan langganan contoh untuk development.
 *
 * Command, bukan seeder, karena `db:seed` tidak menerima flag sedangkan
 * pekerjaan ini paling sering dijalankan ulang ("coba tambah yang belum
 * lunas", "coba yang ada diskonnya"). `--force` membersihkan lebih dulu, jadi
 * mengulanginya aman tanpa harus menyunting kode.
 *
 * Data yang dibuat sengaja mencakup semua keadaan yang harus bisa dihitung dan
 * ditampilkan halaman tagihan maupun dashboard. Nominal yang aneh adalah
 * tempat bug agregasi bersembunyi:
 *
 *   - Lunas: tidak menambah tunggakan, tapi menambah total tagihan.
 *   - Belum lunas + terlambat jauh: masuk kelompok "Terlambat".
 *   - Belum lunas, jatuh tempo < 14 hari: kelompok "Jatuh Tempo 14 Hari".
 *   - Belum lunas, jatuh tempo masih jauh: kelompok "Aman".
 *   - Ada tagihan tanpa rincian item, seperti invoice lama.
 *   - Ada tagihan dengan diskon: `jumlah` lebih kecil dari jumlah item.
 *
 * Kalau salah satu kelompok kosong, panel komposisi status tagihan menampilkan
 * komposisi yang tidak pernah terjadi di dunia nyata -- dan grafik seperti itu
 * sulit dipercaya tanpa alasan yang jelas.
 *
 * Semua tanggal dihitung relatif terhadap hari ini, bukan tanggal tetap, jadi
 * datanya tetap masuk akal selama dipakai berapa bulan pun.
 */
class SeedDummyInvoicesCommand extends Command
{
    protected $signature = 'tenancy:seed-dummy-invoices
        {--tenant= : Slug lokasi (default: semua lokasi)}
        {--force : Hapus tagihan lama dulu, lalu buat ulang}
        {--months= : Berapa bulan riwayat tagihan lunas (default 12)}';

    protected $description = 'Buat tagihan langganan contoh untuk development';

    /**
     * Paket langganan per lokasi, dipilih lewat `id % count(Paket)`.
     *
     * Nilainya sengaja TIDAK sama untuk semua lokasi. Kalau semuanya identik,
     * grafik tunggakan per lokasi terlihat seperti bug -- dan grafik yang
     * terlihat seperti bug jauh lebih sulit dicari daripada yang jelas salah.
     *
     * Bentuk tiap paket: [nama item, qty, satuan, harga satuan, catatan].
     */
    private const PAKET = [
        0 => [
            ['Langganan ASTALOKA', 1, 'bulan', 1500000, 'Lisensi aplikasi bulanan'],
            ['Modul Pelaporan Keuangan', 1, 'bulan', 250000, 'Modul laporan keuangan dan laba rugi'],
        ],
        1 => [
            ['Langganan ASTALOKA', 1, 'bulan', 1500000, 'Lisensi aplikasi bulanan'],
            ['Modul Pembayaran & Tagihan', 1, 'bulan', 350000, 'Modul penagihan dan rekonsiliasi'],
            ['User Tambahan', 2, 'user', 75000, 'User di atas jatah paket'],
        ],
        2 => [
            ['Langganan ASTALOKA', 1, 'bulan', 1500000, 'Lisensi aplikasi bulanan'],
            ['Modul Pembayaran & Tagihan', 1, 'bulan', 350000, 'Modul penagihan dan rekonsiliasi'],
            ['Dukungan Implementasi', 1, 'kali', 500000, 'Pendampingan konfigurasi awal'],
        ],
    ];

    /** Ambang "jatuh tempo segera" di dashboard. Harus sama dengan DashboardController. */
    private const SEGERA_HARI = 14;

    public function handle(): int
    {
        $bulan = max(1, min(60, (int) $this->option('months')));
        $slug = $this->option('tenant');

        $tenants = Tenant::query()
            ->when($slug, fn ($q) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->error($slug ? "Lokasi `{$slug}` tidak terdaftar." : 'Belum ada lokasi terdaftar.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            try {
                $this->seedTenant($tenant, $bulan, (bool) $this->option('force'));
            } catch (Throwable $e) {
                $this->error("Lokasi `{$tenant->slug}` GAGAL: ".$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->cetakRingkasan();

        return self::SUCCESS;
    }

    private function seedTenant(Tenant $tenant, int $bulan, bool $force): void
    {
        $ada = TenantInvoice::query()->where('tenant_id', $tenant->id)->count();

        if ($ada > 0 && ! $force) {
            $this->warn("Lokasi `{$tenant->slug}` sudah punya {$ada} tagihan -- dilewati.");
            $this->line('   Pakai --force untuk menghapus dan buat ulang.');

            return;
        }

        if ($ada > 0) {
            /*
             * Rincian item ikut terhapus: `tenant_invoice_items` punya
             * `cascadeOnDelete` pada `tenant_invoice_id`. Kalau tidak,
             * sisa baris item menunjuk invoice yang sudah tidak ada dan
             * precludes terbaru tidak bisa dibuka.
             */
            TenantInvoice::query()->where('tenant_id', $tenant->id)->delete();

            $this->line("Lokasi `{$tenant->slug}`: {$ada} tagihan lama dihapus.");
        }

        $paket = self::PAKET[$tenant->id % count(self::PAKET)];
        $urutanNomor = [];

        foreach ($this->skenario($bulan) as $s) {
            $this->buatInvoice($tenant, $paket, $s, $urutanNomor);
        }

        $total = TenantInvoice::query()->where('tenant_id', $tenant->id)->count();

        $this->info("Lokasi `{$tenant->slug}`: {$total} tagihan dibuat.");
    }

    /**
     * Daftar tagihan yang akan dibuat untuk satu lokasi.
     *
     * Riwayat bulanan dibuat lebih dulu, dari `bulan` bulan lalu sampai 3 bulan
     * lalu, dan semuanya dianggap lunas. Tagihan lama yang sengaja dibiarkan
     * menunggak akan membuat dashboard menampilkan tumpukan tunggakan yang
     * tidak realistis -- dan tidak ada yang bisa membedakan itu dari data
     * produksi yang benar.
     *
     * @return array<int, array<string, mixed>>
     */
    private function skenario(int $bulan): array
    {
        $hariIni = Carbon::now()->startOfDay();
        $rows = [];

        for ($i = $bulan; $i >= 3; $i--) {
            $terbit = $hariIni->copy()->subMonthsNoOverflow($i)->day(5);

            $rows[] = [
                'terbit' => $terbit,
                'tempo' => $terbit->copy()->addDays(21),
                'status' => 'PAID',
                'lunas' => $terbit->copy()->addDays(18),
                // Selang-seling ada rincian dan tidak, supaya kedua jalur
                // cetakan ikut teruji pada data yang sama.
                'pakaiItem' => $i % 2 === 0,
                'diskon' => 0,
                'keterangan' => 'Tagihan langganan periode berikutnya.',
            ];
        }

        $rows[] = [
            'terbit' => $hariIni->copy()->subMonthsNoOverflow(2)->day(20),
            'tempo' => $hariIni->copy()->subDays(48),
            'status' => 'UNPAID',
            'pakaiItem' => true,
            'diskon' => 425000,
            'keterangan' => 'Tagihan langganan dua periode lalu. Pembayaran sudah lewat batas.',
        ];

        $rows[] = [
            'terbit' => $hariIni->copy()->subDays(38),
            'tempo' => $hariIni->copy()->subDays(17),
            'status' => 'UNPAID',
            'pakaiItem' => true,
            'diskon' => 0,
            'keterangan' => 'Tagihan langganan periode lalu.',
        ];

        $rows[] = [
            'terbit' => $hariIni->copy()->subDays(6),
            'tempo' => $hariIni->copy()->addDays(8),
            'status' => 'UNPAID',
            'pakaiItem' => true,
            'diskon' => 0,
            'keterangan' => 'Tagihan langganan periode berjalan.',
        ];

        $rows[] = [
            'terbit' => $hariIni->copy()->day(1),
            'tempo' => $hariIni->copy()->addDays(40),
            'status' => 'UNPAID',
            // Tanpa rincian: seperti invoice yang terbit sebelum tabel item
            // ada. `itemLabel()` harus tetap punya teks untuk dicetak.
            'pakaiItem' => false,
            'diskon' => 0,
            'keterangan' => 'Tagihan langganan periode berikutnya.',
        ];

        return $rows;
    }

    /**
     * Buat satu invoice beserta rinciannya.
     *
     * `jumlah` invoice boleh lebih kecil dari jumlah item kalau ada diskon --
     * persis seperti invoice pada umumnya yang punya Sub Total, Diskon, dan
     * Total. Baris Diskon muncul pada cetakan, dan sengaja dipakai pada satu
     * tagihan supaya jalur "Penyesuaian" ikut teruji.
     */
    private function buatInvoice(Tenant $tenant, array $paket, array $s, array &$urutanNomor): void
    {
        $semuaBaris = $this->barisItem($paket);
        $items = $s['pakaiItem'] ? $semuaBaris : [];

        /*
         * Nominal dihitung dari SELURUH baris paket, bukan dari `$items`.
         *
         * Tagihan tanpa rincian tetap punya nominal -- seperti invoice yang
         * terbit sebelum tabel item ada: tidak ada rinciannya, tapi uangnya
         * tetap ditagih. Menghitung dari `$items` yang kosong akan menjatuhkan
         * tagihan bernilai Rp 0, dan itu bukan sekadar data aneh: tunggakan
         * dashboard ikut understated karena tagihan itu tidak lagi menambah
         * satu pun rupiah.
         */
        $jumlah = max(0, array_sum(array_column($semuaBaris, 'jumlah')) - (float) $s['diskon']);

        $invoice = TenantInvoice::create([
            'tenant_id' => $tenant->id,
            'nomor' => $this->nomorBerikutnya($tenant, $s['terbit'], $urutanNomor),
            'tgl_invoice' => $s['terbit']->toDateString(),
            'tgl_jatuh_tempo' => $s['tempo']->toDateString(),
            'jumlah' => $jumlah,
            'status' => $s['status'],
            'keterangan' => $s['keterangan'],
        ]);

        foreach ($items as $urutan => $item) {
            $invoice->items()->create($item + ['urutan' => $urutan + 1]);
        }

        if ($s['status'] === 'PAID') {
            $invoice->update([
                'tgl_lunas' => $s['lunas']->toDateString(),
                'paid_at' => $s['lunas']->copy()->setTime(10, 30),
                'confirmed_by' => null,
            ]);
        }

        /*
         * Kolom keempat menampilkan sisa waktu hanya untuk tagihan yang BELUM
         * lunas. Untuk yang sudah lunas, sisa hari tidak berarti apa-apa --
         * tagihan lunas 8 bulan lalu bukan "lewat 243 hari", dan
         * `diffForHumans()` tidak bisa dipakai: ia mengeluarkan "1 year
         * before" dalam Bahasa Inggris di konsol, sementara aplikasi ini
         * berbahasa Indonesia.
         */
        $waktu = $s['status'] === 'UNPAID'
            ? (function () use ($invoice) {
                $n = $invoice->daysRemaining();

                return $n >= 0 ? $n.' hari lagi' : abs($n).' hari lewat';
            })()
            : 'lunas '.$s['lunas']->format('d M Y');

        $this->line(sprintf(
            '   %-16s %-6s Rp %-14s %-20s %s',
            $invoice->nomor,
            $invoice->status,
            number_format((float) $invoice->jumlah, 0, ',', '.'),
            $waktu,
            $items === [] ? 'tanpa rincian' : count($items).' baris',
        ));
    }

    /** Rincian item dari paket lokasi, dengan `jumlah` terisi eksplisit. */
    private function barisItem(array $paket): array
    {
        return array_map(fn (array $b) => [
            'nama' => $b[0],
            'qty' => $b[1],
            'satuan' => $b[2],
            'harga_satuan' => $b[3],
            'catatan' => $b[4],
            /*
             * `jumlah` diisi di sini, bukan dikosongkan supaya dihitung dari
             * `qty * harga_satuan`. Form di panel pusat sering mengirim
             * `jumlah` kosong (jalur hitung), sedangkan data ini mengirim
             * nilainya (jalur pakai). Dua-duanya perlu benar, dan hanya
             * salah satunya yang bisa diuji lewat UI.
             */
            'jumlah' => round($b[1] * $b[3], 2),
        ], $paket);
    }

    /**
     * Nomor berikutnya untuk satu lokasi pada tahun tagihan.
     *
     * Format `INV/<tahun>/<urutan>`, urutan mulai dari 1 per tahun -- sama
     * dengan `InvoiceController::nextNumber()`.
     *
     * Dihitung ulang dari nol tiap kali command dijalankan, bukan dilanjutkan
     * dari nomor yang sudah ada. Setelah `--force` mengosongkan tabel, nomor
     * lama sudah tidak ada yangosial Successfully referenced; melanjutkan dari
     * nomor terakhir hanya akan meninggalkan celah yang tidak menjelaskan apa pun.
     *
     * @param  array<int, array<int, int>>  $urutanNomor  [tenant_id][tahun] => urutan terakhir
     */
    private function nomorBerikutnya(Tenant $tenant, Carbon $terbit, array &$urutanNomor): string
    {
        $tahun = (int) $terbit->format('Y');

        $urutanNomor[$tenant->id][$tahun] ??= 0;
        $urutanNomor[$tenant->id][$tahun]++;

        return sprintf('INV/%d/%04d', $tahun, $urutanNomor[$tenant->id][$tahun]);
    }

    /**
     * Cetak angka akhir supaya bisa dibandingkan dengan dashboard.
     *
     * Perhitungan di sini sengaja dibuat berbeda dari `DashboardController`:
     * yang di sini dijumlahkan per lokasi dari daftar yang barusan dibuat, sedangkan
     * dashboard memakai agregat SQL dan filter waktu yang berlainan. Kalau
     * angkanya berbeda, itu tanda salah satu dari keduanya salah -- dan mencatat
     * keduanya di sini membuat perbedaan itu kelihatan tanpa harus membuka
     * browser.
     */
    private function cetakRingkasan(): void
    {
        $semua = TenantInvoice::query()->with('tenant')->get();
        $unpaid = $semua->where('status', 'UNPAID');
        $terlambat = $unpaid->filter(fn (TenantInvoice $i) => $i->isOverdue());
        $segera = $unpaid->reject(fn (TenantInvoice $i) => $i->isOverdue())
            ->filter(fn (TenantInvoice $i) => $i->daysRemaining() <= self::SEGERA_HARI);
        $aman = $unpaid->reject(fn (TenantInvoice $i) => $i->isOverdue() || $i->daysRemaining() <= self::SEGERA_HARI);

        $this->line('Ringkasan (pembanding untuk /central/dashboard):');
        $this->line('   total tagihan    : '.$semua->count());
        $this->line('   lunas            : '.$semua->where('status', 'PAID')->count()
            .' -- Rp '.number_format((float) $semua->where('status', 'PAID')->sum('jumlah'), 0, ',', '.'));
        $this->line('   belum lunas      : '.$unpaid->count()
            .' -- Rp '.number_format((float) $unpaid->sum('jumlah'), 0, ',', '.'));
        $this->line('   terlambat        : '.$terlambat->count()
            .' -- Rp '.number_format((float) $terlambat->sum('jumlah'), 0, ',', '.'));
        $this->line('   jatuh tempo <14h : '.$segera->count()
            .' -- Rp '.number_format((float) $segera->sum('jumlah'), 0, ',', '.'));
        $this->line('   aman             : '.$aman->count()
            .' -- Rp '.number_format((float) $aman->sum('jumlah'), 0, ',', '.'));
        $this->line('   nilai total      : Rp '.number_format((float) $semua->sum('jumlah'), 0, ',', '.'));

        $this->line('Per lokasi (belum lunas):');
        foreach ($unpaid->groupBy('tenant_id') as $tenantId => $rows) {
            $this->line(sprintf(
                '   %-28s %2d tagihan  Rp %s',
                $rows->first()->tenant?->label() ?? "#{$tenantId}",
                $rows->count(),
                number_format((float) $rows->sum('jumlah'), 0, ',', '.'),
            ));
        }
    }
}