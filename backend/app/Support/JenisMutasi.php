<?php

namespace App\Support;

/**
 * Satu-satunya tempat yang memutuskan "apakah akun ini bersifat kredit".
 *
 * Kenapa harus satu berkas
 * -----------------------
 *
 * Kolom `jenis_mutasi` pernah ditulis dengan dua kapitalisasi berbeda:
 * seeder pertama memakai `'Kredit'`, seeder berikutnya `'kredit'`, dan
 * panel pusat bisa menambah bentuk baru lagi. Perbandingan sama persis
 * (`== 'kredit'`) tidak aman untuk data seperti itu -- akun yang tersimpan
 * `'Kredit'` akan diperlakukan sebagai akun debit, sehingga saldonya
 * berbalik tanda.
 *
 * Sudah diverifikasi: `==` dan `===` sama-sama tidak cocok, karena PHP
 * membandingkan string secara case-SENSITIVE sehingga
 * `'Kredit' == 'kredit'` bernilai FALSE. Akun yang pernah tersimpan
 * seperti itu adalah `2.1.02.02` "Utang FEE Kolektor" -- akun kewajiban
 * yang perhitungan saldonya jadi berperilaku seperti aset.
 *
 * Setelah file ini dibuat, tidak boleh ada perbandingan `jenis_mutasi`
 * literal di mana pun. Semua lewat `isKredit()`.
 */
final class JenisMutasi
{
    /** Nilai baku yang dipakai seluruh aplikasi (selalu huruf kecil). */
    public const DEBET = 'debet';

    public const KREDIT = 'kredit';

    /**
     * Apakah nilai mutasi ini berarti kredit.
     *
     * Case-insensitive dan tahan spasi, karena data lama tidak dijamin
     * rapi. Nilai yang tidak dikenal dianggap debet -- sama dengan
     * perilaku lama di semua call site.
     */
    public static function isKredit(mixed $jenis): bool
    {
        return strtolower(trim((string) $jenis)) === self::KREDIT;
    }

    /**
     * Ubah apa pun yang masuk menjadi salah satu dari dua nilai baku.
     *
     * Dipakai di pintu masuk penyimpanan (panel pusat) supaya bentuk
     * yang tidak baku tidak pernah ikut masuk database.
     */
    public static function normalisasi(mixed $jenis): string
    {
        return self::isKredit($jenis) ? self::KREDIT : self::DEBET;
    }
}