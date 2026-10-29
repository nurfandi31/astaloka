<?php

use App\Services\ReceivableGenerator;
use App\Services\TenantManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Generate piutang tunggakan untuk SEMUA lokasi.
 *
 * Kenapa bukan `Schedule::command(...)` biasa
 * -----------------------------------------
 *
 * `Schedule::command('billing:generate-overdue-transactions')` menjalankan
 * perintah itu satu kali, pada koneksi database yang sedang jadi
 * `database.default` saat proses scheduler hidup.
 *
 * Di pamsides-v2 (satu lokasi) itu benar. Di ASTALOKA tidak: setiap lokasi
 * punya database sendiri, dan proses scheduler tidak punya "lokasi aktif" --
 * yang ada hanyalah nilai `.env`. Akibatnya command hanya menyentuh satu
 * lokasi, dan lokasi mana itu bergantung pada konfigurasi mesin, bukan pada
 * daftar pelanggan. Semua lokasi lain tidak pernah menjalankan generate
 * piutang: tagihan tetap `unpaid`, akun Piutang Usaha tidak pernah ditambah,
 * dan tidak ada error maupun log yang menandakannya.
 *
 * Kenapa TIDAK memanggil command secara langsung
 * ----------------------------------------------
 *
 * Versi lama memanggil `Artisan::call('billing:generate-overdue-transactions')`
 * di dalam loop lokasi. Itu LEWAT dari gate tanggal generate: gate-nya ada
 * di `ReceivableGenerator::shouldRun()`, yang membandingkan `now()->day`
 * dengan `toleransi_tunggakan`.
 *
 * Efeknya: scheduler berjalan setiap malam untuk SEMUA lokasi, termasuk
 * lokasi yang tanggal generate-nya belum tercapai. Gate tanggal generate
 * -- satu-satunya aturan yang menentukan KAPAN piutang tunggakan ought
 * dijurnalkan -- jadi tidak berlaku sama sekali di jalur scheduler.
 *
 * Yang benar: scheduler memanggil `ReceivableGenerator::runIfDue()` --
 * satu-satunya tempat yang memegang gate, busy-guard, dan penanganan
 * kegagalan. Command-nya sendiri tetap idempotent (pasangan
 * `reverence_id|account_kredit`), jadi generate boleh berulang tanpa
 * menghasilkan jurnal dobel.
 *
 * Jadwal 00:01 tetap dipertahankan.
 */
Schedule::call(function () {
    /** @var TenantManager $tenants */
    $tenants = app(TenantManager::class);

    $hasil = $tenants->runForEachActive(function () {
        $hasil = app(ReceivableGenerator::class)->runIfDue();

        return [
            'ran' => (bool) ($hasil['ran'] ?? false),
            'reason' => (string) ($hasil['reason'] ?? ''),
        ];
    });

    $gagal = array_values(array_filter($hasil, fn ($h) => ! $h['ok']));
    $sukses = count($hasil) - count($gagal);
    $jalan = count(array_filter($hasil, fn ($h) => ($h['ok'] ?? false) && ($h['value']['ran'] ?? false)));

    Log::info('Generate piutang tunggakan terjadwal selesai.', [
        'lokasi_diproses' => count($hasil),
        'lokasi_generate_jalan' => $jalan,
        'berhasil' => $sukses,
        'gagal' => count($gagal),
        'lokasi_gagal' => array_map(fn ($h) => $h['tenant']->slug, $gagal),
    ]);
})->dailyAt('00:01')->name('piutang:generate-overdue-transactions')->withoutOverlapping();

/*
 * Hapus file kerja migrasi yang tidak pernah di-commit.
 *
 * Jadwalnya 10 menit, bukan harian: file kerja dibuat setiap kali
 * superadmin mengunggah file, dan isinya memuat NIK serta nama lengkap
 * orang. Kalau dibiarkan sampai harian, satu file bisa bertahan hampir
 * 24 jam setelah user menutup tab tanpa melanjutkan.
 *
 * `withoutOverlapping()` dipakai karena perintah ini idempoten dan
 * cepat -- berkasnya sudah di luar masa berlaku, jadi isinya tidak akan
 * dibaca lagi. Dua proses yang saling menimpa hanya membuang usaha, bukan
 * merusak apa pun.
 */
Schedule::command('migrasi:bersihkan')
    ->everyTenMinutes()
    ->name('migrasi:bersihkan')
    ->withoutOverlapping();
