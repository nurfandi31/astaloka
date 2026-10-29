<?php

namespace App\Services;

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
 * KONSEP BARU: generate boleh diulang, bukan sekali-sekali
 * -------------------------------------------------------
 *
 * Permintaan operasionalnya jelas: "kalau bulan ini sudah generate, jangan
 * halangi generate lagi; kalau hari ini adalah hari generate, setiap login
 * boleh memicu generate ulang -- supaya pelanggan yang lupa somehow tetap
 * tertangani".
 *
 * Karena itu tiga lapis penghalang yang tadinya ada DILETAKHKAN:
 *
 *   1. Gate `===` (tanggal hari ini HARUS PERSIS sama)  -> jadi `>=`.
 *      Tanggal yang terlewat tidak lagi menggagalkan generate selamanya.
 *
 *   2. Cache "sudah pernah dijalankan bulan ini" (TTL 35 hari)
 *      -> DIHAPUS. Ini penghalang palingKetat: begitu generate jalan sekali
 *      pada tanggal 6, seluruh bulan berikutnya di lokasi itu tidak pernah
 *      bisa generate lagi -- termasuk kasus tagihan baru yang masuk lewat
 *      salesperson, atau tagihan yang tadinya salah dan sudah diperbaiki.
 *
 *   3. Mutex "sudah dijalankan hari ini" (TTL 1 jam)
 *      -> diturunkan menjadi BUSY-GUARD SAJA. Gunanya hanya mencegah dua
 *      proses agregasi `amount` berjalan bersamaan (lock wait timeout),
 *      BUKAN mencegah generate.
 *
 * Yang PEGANG rumoredup-nya tetap command itu sendiri, yang sudah
 * idempotent: setiap tagihan punya paling banyak satu jurnal piutang per
 * akun kredit (dicek lewat pasangan `reverence_id|account_kredit`).
 * Menjalankan berkali-kali pada hari yang sama tidak menghasilkan jurnal
 * dobel.
 *
 * Efek samping yang diinginkan: kalau ada pelanggan yang lupa dicatat
 * meternya, atau tagihannya baru saja diperbaiki admin, generate ulang
 * akan terlihat dan menagihnya tanpa perlu intervensi manual.
 *
 * Kapan TIDAK berjalan
 * --------------------
 *
 * 1. Tanggal generate bulan ini belum tercapai. Contoh: `toleransi 6`,
 *    hari ini tanggal 3 -> belum jalan.
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
     * Kunci BUSY-GUARD generate piutang.
     *
     * SATU-SATUNYA tempat kunci ini dibuat. Sebelumnya ada dua kunci
     * berbeda untuk hal yang sama:
     *
     *   - `ReceivableGenerator`  : `overdue_gen_lock_{tanggal generate}`
     *   - `DashboardController`   : `overdue_gen_lock_{tanggal hari ini}`
     *
     * Kalau tanggal generate BEDA dari hari ini -- yang pasti terjadi
     * begitu tanggal generate terlewat, atau saat gate `>=` sudah berjalan
     * -- kedua kunci itu tidak pernah sama. Akibatnya dua proses yang
     * seharusnya saling menjaga bisa berjalan bersamaan, persis hal yang
     * busy-guard ini-exist untuk mencegahnya.
     *
     * Dan kalau tanggal generate justru SAMA dengan hari ini, keduanya
     * kebetulan sama -- jadi gejalanya baru muncul pada kasus yang paling
     * jarang diuji: tanggal generate yang sudah terlewat.
     *
     * Kuncinya berbasis TANGGAL HARI INI, bukan tanggal generate, karena
     * yang perlu dijaga adalah "jangan agregasi `amount` dua kali
     * bersamaan", dan itu soal waktu, bukan soal tanggal generate.
     */
    public function lockKey(?Carbon $now = null): string
    {
        $now ??= now();

        return $this->cache->flagKey('overdue_gen_lock_'.$now->toDateString());
    }

    /**
     * Jalankan generate piutang bila memang sudah waktunya.
     *
     * `scheduled_day` ikut dikembalikan supaya frontend bisa menampilkan
     * tanggal generate di popup tanpa harus memanggil endpoint lain --
     * bentuknya sama dengan yang dikirim `DashboardController`.
     *
     * @return array{ran: bool, reason: string, summary: array<string,mixed>|null, scheduled_day: int, today_day: int}
     */
    public function runIfDue(?Carbon $now = null): array
    {
        $now ??= now();

        if (! $this->shouldRun($now)) {
            return [
                'ran' => false,
                'reason' => 'Belum tanggal generate piutang bulan ini.',
                'summary' => null,
                'scheduled_day' => $this->scheduledDay(),
                'today_day' => (int) $now->format('d'),
            ];
        }

        $lockKey = $this->lockKey($now);

        /*
         * BUSY-GUARD, bukan blocker.
         *
         * Fungsinya hanya mencegah dua proses agregasi tabel `amount` di atas
         * tabel `transactions` berjalan bersamaan. Dua proses itu bukan
         * merusak data (command-nya idempotent), tapi boros mahal dan bisa
         * memicu lock wait timeout -- terutama di lokasi besar dengan
         * puluhan ribu tagihan.
         *
         * PENTING: kegagalan acquiring lock TIDAK berarti "generate sudah
         * pernah jalan, jadi jangan jalan lagi". Itu semantics yang
         * lama -- dan justru alasan generate tidak pernah bisa dijalankan
         * ulang dalam sebulan.
         *
         * TTL-nya 10 menit, bukan 1 jam: kalau proses pertama mati di
         * tengah jalan (timeout, OOM, koneksi putus), percobaan berikutnya
         * tidak perlu menunggu satu jam penuh.
         *
         * Dan kalau proses yang sedang jalan BENAR-BENAR selesai, kuncinya
         * dilepas di akhir supaya generate berikutnya (login berikutnya)
         * langsung boleh jalan.
         */
        if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
            return [
                'ran' => false,
                'reason' => 'Generate sedang berjalan di proses lain. Coba lagi sebentar lagi.',
                'summary' => null,
                'busy' => true,
                'scheduled_day' => $this->scheduledDay(),
                'today_day' => (int) $now->format('d'),
            ];
        }

        try {
            $summary = $this->execute($now);
        } catch (\Throwable $e) {
            // Lepas kunci supaya percobaan berikutnya tidak terkunci hanya
            // karena satu kegagalan.
            Cache::forget($lockKey);

            Log::error('Generate piutang tunggakan gagal: '.$e->getMessage(), [
                'tenant' => $this->cache->scope(),
                'exception' => $e,
            ]);

            return [
                'ran' => false,
                'reason' => 'Generate gagal: '.$e->getMessage(),
                'summary' => null,
                'scheduled_day' => $this->scheduledDay(),
                'today_day' => (int) $now->format('d'),
            ];
        } finally {
            // Lepas kunci juga setelah sukses: generate berikutnya
            // (misalnya login berikutnya) harus BOLEH jalan lagi.
            Cache::forget($lockKey);
        }

        return [
            'ran' => true,
            'reason' => 'Generate piutang tunggakan selesai.',
            'summary' => $summary,
            'scheduled_day' => $this->scheduledDay(),
            'today_day' => (int) $now->format('d'),
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

        return $this->runSummary($now);
    }

    /**
     * Ringkasan run generate yang terakhir.
     *
     * `null` kalau tidak ada ringkasan yang bisa dipercaya.
     *
     * Kenapa harus dicek tanggalnya
     * ---------------------------
     *
     * Ringkasan disimpan di cache dengan TTL 26 jam -- cukup untuk menutup
     * seluruh jendela "hari generate". Tapi TTL 26 jam itu LEBIH LAMA dari
     * satu hari, jadi ringkasan dari run kemarin masih hidup saat generate
     * hari ini dijalankan.
     *
     * Kalau ringkasan basi itu ditampilkan, user melihat angka tagihan
     * yang sudah berumur sehari, dan dianggap generate hari ini memproses
     * ribuan tagihan padahal yang terjadi adalah "tidak ada yang perlu
     * dijurnalkan".
     *
     * Command selalu menulis ulang ringkasan saat ia jalan -- termasuk
     * saat tidak menemukan tagihan menunggak (angka nolnya memang nol) --
     * jadi satu-satunya cara ringkasan basi sampai ke user adalah ketika
     * command-nya sendiri tidak sempat menulis. `date` di dalamnya
     * membedakan kedua kasus itu.
     *
     * @return array<string,mixed>|null
     */
    public function runSummary(?Carbon $now = null): ?array
    {
        $now ??= now();

        $summary = $this->cache->flagGet('overdue_gen_run_summary');

        if (! is_array($summary)) {
            return null;
        }

        if (($summary['date'] ?? null) !== $now->toDateString()) {
            return null;
        }

        return $summary;
    }

    /**
     * Tanggal generate dari SOP lokasi.
     *
     * Satu-satunya tempat nilai `toleransi_tunggakan` dibaca sebagai
     * TANGGAL. Nilai yang sama punya arti lain di tempat lain -- lihat
     * `BillingTolerance` -- sehingga pembacaan ini sengaja tidak disebar
     * ke seluruh aplikasi.
     *
     * Membaca lewat `BillingTolerance::generateDay()` bukan langsung dari
     * `Setting`, karena accessor itu yang mengubah nilai di luar rentang
     * 1-28 menjadi 0 -- artinya "belum dijadwalkan". Tanpa itu, nilai 30
     * akan lolos ke sini dan `shouldRun()` menganggapnya valid.
     */
    public function scheduledDay(): int
    {
        return app(BillingTolerance::class)->generateDay();
    }
}
