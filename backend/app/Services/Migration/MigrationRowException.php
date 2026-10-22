<?php

namespace App\Services\Migration;

/**
 * Satu baris dari file tidak bisa diproses.
 *
 * Dipakai oleh implementasi {@see MigrationBlueprint::mapRow()} untuk
 * menolak baris yang isinya salah. Pesannya ditulis untuk DIBACA orang
 * yang sedang memperbaiki file, bukan untuk log: nama kolom, isi yang
 * offending, dan apa yang diharapkan.
 *
 * Baris yang melempar ini TIDAK menghentikan proses. Batch berikutnya
 * tetap jalan, dan baris ini dilaporkan di daftar hasil sebagai gagal.
 * itu justru tujuan alur 3 langkahnya: satu baris salah tidak boleh
 * membuang seribu baris yang benar.
 */
class MigrationRowException extends \RuntimeException
{
    /**
     * @param  array<int, string>  $kolom  Nama kolom yang bermasalah, untuk
     *                                     menandai sel di tabel preview
     */
    public function __construct(
        string $message,
        private array $kolom = [],
        private string $kode = 'BARIS_INVALID',
    ) {
        parent::__construct($message);
    }

    /** @return array<int, string> */
    public function kolom(): array
    {
        return $this->kolom;
    }

    public function kode(): string
    {
        return $this->kode;
    }

    /* ---------------------------------------------------------------
     | Pembuat pesan yang sering dipakai.
     |
     | Ditulis di sini, bukan di setiap blueprint, supaya format
     | pesannya sama untuk semua jenis data. "Kolom NIK kosong" ditulis
     | oleh tiga kelas berbeda harus tetap terbaca sebagai kalimat yang
     | sama -- kalau tiap kelas merumuskan formatnya sendiri, user akan
     | menemukan tiga gaya pesan untuk satu masalah.
     |------------------------------------------------------------- */

    public static function kosong(string $label): self
    {
        return new self("Kolom {$label} wajib diisi.", [$label], 'KOLOM_KOSONG');
    }

    public static function tidakValid(string $label, string $isi, string $harapan): self
    {
        return new self(
            "Kolom {$label} berisi \"{$isi}\". {$harapan}.",
            [$label],
            'FORMAT_INVALID'
        );
    }

    public static function tidakDikenal(string $label, string $isi, array $pilihan): self
    {
        return new self(
            "Kolom {$label} berisi \"{$isi}\". Pilihan yang tersedia: "
                .implode(', ', $pilihan).'.',
            [$label],
            'PILIHAN_INVALID'
        );
    }

    /**
     * Nilai acuan yang tidak ada di lokasi tujuan.
     *
     * `$kolom` sengaja WAJIB, bukan opsional. Kesalahan ini selalu punya
     * tepat satu kolom penyebab, dan setiap pemanggil tahu yang mana:
     * `cariPaket()` selalu gagal di kolom paket, `cariDesa()` di desa.
     *
     * Kalau kolomnya opsional, cepat atau lambat ada pemanggil yang
     * tidak mengirimnya, dan baris yang ditolak masuk ke preview TANPA
     * penanda kolom. Itu lebih berbahaya daripada tidak menandai sama
     * sekali: tabelnya tetap terlihat benar, orang mengira masalahnya
     * ada di kolom lain, memperbaiki kolom yang sebenarnya sudah benar,
     * lalu mengunggah ulang file yang sama dan melihat baris itu ditolak
     * lagi dengan alasan yang sama. Penandaan yang hilang diam-diam
     * membuat orang mempercayai sesuatu yang salah.
     */
    public static function tidakDitemukan(string $apa, string $nilai, string $tempat, string $kolom): self
    {
        return new self(
            "{$apa} \"{$nilai}\" tidak ditemukan {$tempat}.",
            [$kolom],
            'REFERENSI_INVALID'
        );
    }

    public static function bentrok(string $apa, string $nilai, string $kolom): self
    {
        return new self(
            "{$apa} \"{$nilai}\" sudah dipakai data lain di lokasi ini.",
            [$kolom],
            'DUPLIKAT'
        );
    }

    /**
     * Header file tidak memuat kolom yang template mewajibkannya.
     *
     * Dipakai SEBELUM baris mana pun diproses. Tanpa pemeriksaan ini,
     * file yang salah kolom akan menghasilkan ribuan pesan "Kolom NIK
     * wajib diisi" -- yang mengarahkan user untuk mengisi ulang seluruh
     * file, padahal masalahnya hanya satu baris header yang salah nama.
     *
     * Pesannya menyebut apa yang ditemukan di file dan apa yang
     * seharusnya ada, karena itu yang perlu diperbaiki user.
     *
     * @param  array<int, string>  $hilang  Label kolom yang tidak ditemukan
     */
    public static function kolomTidakDitemukan(array $hilang, $blueprint): self
    {
        $ada = array_column($blueprint->columns(), 'label');

        return new self(
            'Kolom ini tidak ditemukan di file: '.implode(', ', $hilang).'. '
            .'Header file harus memuat: '.implode(', ', $ada).'. '
            .'Baris pertama file adalah nama kolom -- pastikan tidak terhapus '
            .'saat file diedit.',
            $hilang,
            'KOLOM_TIDAK_DITEMUKAN'
        );
    }
}
