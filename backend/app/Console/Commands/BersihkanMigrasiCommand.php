<?php

namespace App\Console\Commands;

use App\Services\Migration\MigrationPipeline;
use Illuminate\Console\Command;

/**
 * Hapus file kerja migrasi yang sudah lewat masa berlaku.
 *
 * MENGAPA PERLU ADA, KETIKA `commit()` SUDAH MENGHAPUS FILENYA
 * ============================================================
 *
 * Karena ada dua jalan keluar dari file kerja, dan hanya satu yang
 * menutup jalur di server:
 *
 *   1. Commit berhasil atau gagal -> `commit()` menghapus filenya.
 *   2. User MEMBATALKAN setelah validasi, atau menutup tab, atau
 *      jawabannya tidak pernah dikirim. Tidak ada request yang pernah
 *      sampai ke server, jadi tidak ada yang menghapus filenya.
 *
 * Jalur kedua tidak pernah menghasilkan response ke user -- jadi kalau
 * tidak ada perintah ini, file itu hanya akan hilang karena disk penuh,
 * atau tidak sama sekali. Isinya memuat NIK dan nama lengkap orang,
 * jadi "menunggu giliran" bukan pilihan yang bisa diterima.
 */
class BersihkanMigrasiCommand extends Command
{
    protected $signature = 'migrasi:bersihkan';

    protected $description = 'Hapus file kerja migrasi yang sudah lewat masa berlaku';

    public function handle(MigrationPipeline $pipeline): int
    {
        $folder = storage_path('app/private/migrasi');

        if (! is_dir($folder)) {
            $this->info('Belum ada file kerja migrasi.');

            return self::SUCCESS;
        }

        $dihapus = $pipeline->bersihkanKedaluwarsa();

        $this->info($dihapus === 0
            ? 'Tidak ada file kerja migrasi yang kedaluwarsa.'
            : "{$dihapus} file kerja migrasi dihapus.");

        return self::SUCCESS;
    }
}
