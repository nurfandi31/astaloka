<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Menentukan KAPAN jurnal piutang tunggakan harus dibuat, lalu menjalankannya.
 *
 * Masalah yang diselesaikan
 * -------------------------
 *
 * Class ini memindahkan pemicu generate dari frontend ke backend.
 *
 * Sebelumnya generate dipicu `useOverdueGenNotification.js` -- yaitu saat
 * dashboard DIBUKA -- dan hanya jalan bila dua syarat terpenuhi:
 *
 *     DashboardController:451   $configured     = $scheduledDay >= 1 && <= 28
 *     DashboardController:452   $isScheduledDay = $todayDay === $scheduledDay
 *
 * Dua syarat itu membuat generate bergantung pada orang, bukan pada tanggal:
 *
 *   - Kalau tidak ada staff yang membuka dashboard pada tanggal itu, tagihan
 *     menunggak TIDAK PERNAH dijurnalkan. Tidak ada error, tidak ada log,
 *     tidak ada tanda di mana pun. Tagihan tetap `unpaid` di `monthly_bills`
 *     tapi tidak pernah membebani akun Piutang Usaha `1.1.03.01`, sehingga
 *     Neraca dan pelaporan under-report sementara `monthly_bills` tetap
 *     menunjukkan angka yang berbeda -- dua sumber kebenaran yang tidak
 *     sinkron.
 *
 *   - Gate `===` juga membuat tanggal 29, 30, dan 31 tidak pernah bisa
 *     menjadi tanggal generate.
 *
 * Yang dipakai di sini
 * --------------------
 *
 * Gate berubah dari "hari ini PERSIS sama dengan tanggal generate" menjadi
 * "tanggal hari ini sudah lewat atau sama dengan tanggal generate". begitu
 * tanggalnya lewat, generate tetap boleh jalan -- dijalankan pagi atau
 * malam tidak berpengaruh, karena hasilnya sama.
 *
 * Jendela ini juga yang membuat proses idempotent: setiap tagihan punya
 * paling banyak satu jurnal piutang per akun kredit (dicek command lewat
 * pasangan `reverence_id|account_kredit`), jadi menjalankan berkali-kali
 * pada hari yang sama tidak menghasilkan jurnal dobel.
 *
 * Kapan TIDAK berjalan
 * --------------------
 *
 * 1. Tanggal generate bulan ini belum tercapai. Contoh: `toleransi 6`,
 *    hari ini tanggal 3 -> belum jalan.
 * 2. Sudah dikunci untuk tanggal tersebut di lokasi ini (mutex), supaya
 *    dua login bersamaan tidak menjalankan proses dua kali.
 *
 * Kegagalan
 * ---------
 *
 * Generate TIDAK BOLEH menggagalkan login. Password sudah benar, akun
 * sudah aktif, dan tagihan langganan sudah lunas -- sisa pekerjaan
 * akuntansi internal tidak boleh menjadi alasan orang tidak bisa masuk.
 * Semua kegagalan ditangkap dan dicatat; login tetap sukses.
 */
class ReceivableGenerator
{
    public function __construct(private TenantCache $cache) {}

    /**
     * Apakah jurnal piutang hari ini masih perlu dibuat.
     *
     * Dipakai endpoint `dashboard/auto-generate-overdue/check` supaya
     * pop up hanya muncul saat memang ada pekerjaan yang perlu dikerjakan.
     */
    public function shouldRun(?Carbon $now = null): bool
    {
        $now ??= now();
        $scheduledDay = $this->scheduledDay();

        // SOP belum diisi -- tidak ada tanggal generate yang bisa dipilih.
        if ($scheduledDay < 1 || $scheduledDay > 28) {
            return false;
        }

        return $now->day >= $scheduledDay;
    }

    /**
     * Tanggal generate bulan ini.
     *
     * Dipakai untuk mutex: dua proses pada hari yang sama harus melihat
     * kunci yang sama, dan tanggal inilah yang menentukan kunci itu.
     */
    public function scheduledDate(?Carbon $now = null): string
    {
        $now ??= now();

        return $now->copy()->day(max(1, min(28, $this->scheduledDay())))->toDateString();
    }

    /**
     * Jalankan generate piutang bila memang sudah waktunya.
     *
     * @return array{ran: bool, reason: string, summary: array<string,int>|null}
     */
    public function runIfDue(?Carbon $now = null): array
    {
        $now ??= now();

        if (! $this->shouldRun($now)) {
            return [
                'ran' => false,
                'reason' => 'Belum tanggal generate piutang bulan ini.',
                'summary' => null,
            ];
        }

        $lockKey = $this->cache->flagKey('overdue_gen_lock_'.$this->scheduledDate($now));

        /*
         * Mutex dengan TTL 1 jam.
         *
         * Dua admin yang login pada detik yang sama akan menjalankan
         * `billing:generate-overdue-transactions` bersamaan kalau tanpa ini.
         * Command-nya sendiri sudah idempotent sehingga tidak ada jurnal
         * dobel -- tapi dua proses agregasi tabel `amount` di atas tabel
         * `transactions` yang bisa mencapai puluhan ribu baris adalah
         * pemborosan mahal, dan bisa memicu lock wait timeout.
         *
         * TTL-nya satu jam, bukan sampai akhir hari: kalau proses pertama
         * mati di tengah jalan (timeout, OOM, koneksi putus), tagihan yang
         * belum sempat dijurnal harus tetap punya kesempatan dicoba lagi
         * pada login berikutnya di hari yang sama.
         */
        if (! Cache::add($lockKey, 1, now()->addHour())) {
            return [
                'ran' => false,
                'reason' => 'Generate sudah dijalankan atau sedang berjalan hari ini.',
                'summary' => null,
            ];
        }

        try {
            $summary = $this->execute($now);
        } catch (\Throwable $e) {
            // Lepas kunci supaya percobaan berikutnya di hari yang sama
            // tidak terkunci selama satu jam hanya karena satu kegagalan.
            Cache::forget($lockKey);

            Log::error('Generate piutang tunggakan gagal: '.$e->getMessage(), [
                'tenant' => $this->cache->scope(),
                'exception' => $e,
            ]);

            return [
                'ran' => false,
                'reason' => 'Generate gagal: '.$e->getMessage(),
                'summary' => null,
            ];
        }

        return [
            'ran' => true,
            'reason' => 'Generate piutang tunggakan selesai.',
            'summary' => $summary,
        ];
    }

    /**
     * Jalankan command aslinya lalu kembalikan ringkasannya.
     *
     * Dipisahkan dari {@see runIfDue()} supaya logika penjadwalan (gate,
     * mutex, penanganan kegagalan) tidak tercampur dengan pemanggilan
     * command yang juga dipakai di dua tempat lain.
     *
     * @return array<string,mixed>|null
     */
    public function execute(?Carbon $now = null): ?array
    {
        $now ??= now();
        $tanggal = $now->toDateString();

        $exit = Artisan::call(
            'billing:generate-overdue-transactions',
            ['--tanggal' => $tanggal],
        );

        Log::info('Generate piutang tunggakan dijalankan', [
            'tenant' => $this->cache->scope(),
            'tanggal' => $tanggal,
            'exit_code' => $exit,
        ]);

        return $this->cache->flagGet('overdue_gen_run_summary');
    }

    /**
     * Tanggal generate dari SOP lokasi.
     *
     * Satu-satunya tempat nilai `toleransi_tunggakan` dibaca sebagai
     * TANGGAL. Nilai yang sama punya arti lain di tempat lain -- lihat
     * `BillingTolerance` -- sehingga pembacaan ini sengaja tidak disebar
     * ke seluruh aplikasi.
     */
    public function scheduledDay(): int
    {
        return (int) (Setting::first()?->toleransi_tunggakan ?? 0);
    }
}