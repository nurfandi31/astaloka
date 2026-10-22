<?php

namespace App\Services\Migration;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Menyamakan nilai sel dari Excel/CSV sebelum dipakai.
 *
 * MASALAH YANG DISERTAKAN KELAS INI
 * ================================
 *
 * File yang sama bisa berisi angka `1234` sebagai integer, `"1234"`
 * sebagai teks, atau `1234.00` sebagai float -- tergantung program yang
 * menyimpan dan format selnya. Pada kolom `nik` (yang secara teknis
 * string 20 karakter, dan bisa diawali nol) perbedaan ini menentukan
 * apakah NIK `081234567890` tersimpan sebagai `81234567890`.
 *
 * Jadi setiap nilai dari spreadsheet WAJIB lewat kelas ini sebelum
 * dipakai. Tanpa itu, "0" di depan NIK hilang, "1.500" dari Excel
 * menjadi 1 dan bukan 1500, dan tanggal `01/02/2026` ditafsirkan sebagai
 * Januari bukan Februari.
 *
 * SEMUA ATURAN DI SINI DISALIN KE TEMPLATE.
 *
 * Format yang diterima di setiap kolom template ditulis persis sama
 * dengan yang dikembalikan {@see bersih()} di sini. Kalau user
 * complains "kenapa 1.500 jadi 1" sementara dokumentasi/template
 * sudah menyebutkan "boleh pakai titik sebagai pemisah ribuan",
 * jawabannya ada di file ini -- bukan di controller yang memanggilnya.
 */
final class CellValue
{
    /** Nilai yang dianggap "tidak diisi" oleh setiap kolom. */
    private const KOSONG = ['', '-', '--', 'n/a', 'na', 'null', 'nil', 'tbd', 'kosong', '#n/a'];

    /**
     * Buang spasi, dan ubah penanda kosong jadi `null`.
     *
     * Dipanggil SEBELUM pengecekan kolom wajib, karena "  " (spasi)
     * adalah kesalahan yang paling sering lolos dari form Excel dan
     * akan membuat kolom yang dianggap terisi padahal tidak.
     */
    public static function kosongkan(mixed $nilai): mixed
    {
        if ($nilai === null) {
            return null;
        }

        if (is_bool($nilai)) {
            return $nilai ? '1' : '0';
        }

        if ($nilai instanceof DateTimeInterface) {
            return $nilai->format('Y-m-d');
        }

        $teks = trim((string) $nilai);

        // `00:00:00` adalah hasil cell formula yang tidak dievaluasi, dan
        // "0000-00-00" adalah hasil tanggal yang tidak pernah diisi di
        // Excel lama. Keduanya bukan data.
        if ($teks === '') {
            return null;
        }

        if (self::adalahPenandaKosong($teks)) {
            return null;
        }

        if ($teks === '0000-00-00' || $teks === '0000-00-00 00:00:00' || $teks === '00:00:00') {
            return null;
        }

        return $teks;
    }

    private static function adalahPenandaKosong(string $teks): bool
    {
        foreach (self::KOSONG as $penanda) {
            if (mb_strtolower($teks) === $penanda) {
                return true;
            }
        }

        return false;
    }

    /**
     * Angka bulat, dengan toleransi format manusia.
     *
     * Menerima:
     *   1234          -> 1234
     *   "1234"        -> 1234
     *   "1.234"       -> 1234      (pemisah ribuan gaya Indonesia)
     *   "1,234"       -> 1234      (pemisah ribuan gaya Inggris)
     *   "1 234"       -> 1234      (spasi tipis dari Excel)
     *   "1.234,50"    -> 1235 (dibulatkan, untuk kolom integer)
     *   "-1.234"      -> -1234
     *
     * TIDAK menerima "1.5" sebagai 15 atau 1500: titik hanya boleh
     * diperlakukan sebagai pemisah ribuan kalau berada di posisi yang
     * konsisten -- lihat catatan di dalam method.
     */
    public static function integer(mixed $nilai): ?int
    {
        /*
         * Dijawab lewat `decimal()`, bukan dengan normalisasi sendiri.
         *
         * Dua implementasi terpisah dari aturan yang sama akan selalu
         * menyimpang. Versi lama punya pars-nya sendiri, yang tidak
         * memeriksa tanda penanda kosong ("tidak ada", "N/A") dan tidak
         * membuang awalan "Rp" -- jadi `Rp 1.500.000` terbaca nol, dan
         * kolom integer yang sengaja dikosongkan ikut terbaca nol. Keduanya
         * salah tanpa Exception, jadi tidak ada yang melihatnya sampai
         * terlihat sampai ada angka yang tidak masuk akal di tagihan.
         *
         * Pembulatan di sini yang.make `integer()` berbeda dari
         * `decimal()`: bukan pembulatan ke arah nol.
         */
        $nilai = self::bolehKosongkan($nilai);

        if ($nilai === null) {
            return null;
        }

        if (is_int($nilai)) {
            return $nilai;
        }

        $teks = (string) $nilai;

        /*
         * Tanda hubung hanya boleh di awal.
         *
         * Di tengah angka itu adalah tanda pengurang dalam ekspresi
         * ("5-10"), bukan bagian dari angka -- dan membacanya sebagai
         * negatif akan menghasilkan nilai yang tidak pernah diketik
         * siapa pun.
         */
        $negatif = str_starts_with(trim($teks), '-');

        $desimal = self::decimal($teks);

        if ($desimal === null) {
            return null;
        }

        return (int) round($negatif ? -abs($desimal) : abs($desimal));
    }

    /**
     * Ubah tanda pemisah ribuan menjadi tanda desimal, atau sebaliknya.
     *
     * Yang menentukan: ukuran GRUP, bukan jumlah tanda baca.
     *
     * `1.500` memakai titik sebagai pemisah ribuan; `106.8166667` memakai
     * titik sebagai desimal. Keduanya satu titik, dan keduanya terlihat
     * seperti "angka dengan satu titik" kalau yang diperiksa hanya
     * jumlahnya. Versi sebelumnya menghapus titik lebih dulu lalu
     * mengecek apakah sisa angkanya habis dibagi tiga -- dan
     * `1068166667` habis dibagi tiga, sehingga koordinat customers dan
     * tarif blok ikut jadi satu juta lebih. Baris seperti itu ditolak
     * dengan pesan "Harus antara 94 dan 142", yang benar secara
     * harfiah dan sama sekali tidak berguna.
     */
    private static function normalisasiPemisahRibuan(string $teks): string
    {
        $adaTitik = str_contains($teks, '.');
        $adaKoma = str_contains($teks, ',');

        if ($adaTitik && $adaKoma) {
            // Yang TERAKHIR adalah desimal -- konvensi yang dipakai hampir
            // semua locale termasuk Indonesia dan Amerika.
            if (strrpos($teks, ',') > strrpos($teks, '.')) {
                return str_replace(',', '.', str_replace('.', '', $teks));
            }

            return str_replace(',', '', $teks);
        }

        if ($adaKoma) {
            return self::pemisahRibuan($teks, ',')
                ? str_replace(',', '', $teks)
                : str_replace(',', '.', $teks);
        }

        if ($adaTitik) {
            return self::pemisahRibuan($teks, '.')
                ? str_replace('.', '', $teks)
                : $teks;
        }

        return $teks;
    }

    /**
     * Apakah tanda baca di `$teks` functioning sebagai pemisah ribuan.
     *
     * Definisi sebenarnya: setiap grup di belakang tanda pertama
     * PERSIS tiga digit. `1.500.000` ya, `106.8166667` tidak -- karena
     * `8166667` bukan kelipatan tiga yang wajar untuk pemisah ribuan.
     *
     * Membuang tanda baca lebih dulu lalu mengecek sisa angkanya
     * (pendekatan lama) tidak bisa membedakan keduanya: `1068166667`
     * dan `1500000` sama-sama habis dibagi tiga.
     */
    private static function pemisahRibuan(string $teks, string $tanda): bool
    {
        if (substr_count($teks, $tanda) < 1) {
            return false;
        }

        // Tidak ada desimal di bagian awal yang membuat ambigu, tapi
        // tanda yang berulang dengan grup bukan 3 digit langsung gugur.
        $bagian = explode($tanda, $teks);

        // Grup pertama boleh 1-3 digit (`500.000`), atau lebih untuk
        // angka yang sudah punya tanda di depan seperti `-1.500`.
        $pertama = array_shift($bagian);

        if (! self::kelompokAngka($pertama, 1, 3)) {
            return false;
        }

        foreach ($bagian as $grup) {
            if (! self::kelompokAngka($grup, 3, 3)) {
                return false;
            }
        }

        return true;
    }

    /** Apakah `$teks` hanya berisi angka, dengan panjang 3-9 digit? */
    private static function kelompokAngka(string $teks, int $min, int $maks): bool
    {
        $panjang = strlen($teks);

        return $panjang >= $min
            && $panjang <= $maks
            && preg_match('/^\d+$/', $teks) === 1;
    }

    /**
     * Angka desimal, untuk meter, tarif, dan nominal uang.
     *
     * Mengembalikan float; pemanggil yang butuh presisi uang sebaiknya
     * membulatkan sendiri dengan jumlah desimal yang diketahui kolomnya.
     */
    public static function decimal(mixed $nilai): ?float
    {
        $nilai = self::bolehKosongkan($nilai);

        if ($nilai === null) {
            return null;
        }

        if (is_int($nilai) || is_float($nilai)) {
            return (float) $nilai;
        }

        $teks = (string) $nilai;
        $teks = str_replace(["\xc2\xa0", ' ', '_'], '', $teks);
        $teks = str_replace(['Rp', 'rp', 'RP'], '', $teks);
        $teks = self::normalisasiPemisahRibuan($teks);

        if (! is_numeric($teks)) {
            return null;
        }

        return (float) $teks;
    }

    /**
     * Tanggal dalam format `Y-m-d`, atau `null`.
     *
     * Menerima berurutan dari yang paling tidak ambigu:
     *   `2026-02-01`      (format ISO, hasil bawaan sheet)
     *   `01-02-2026`      (Indonesia)
     *   `01/02/2026`      (Indonesia)
     *   `02/01/2026`      (Amerika)
     *   `2026/02/01`
     *   `1 Februari 2026`
     *
     * BULAN DAN TANGGAL YANG TERBALIK TIDAK BISA DIDETEKSI.
     *
     * `03/04/2026` bisa 3 April atau 4 Maret, dan tidak ada cara untuk
     * tahu maksudnya tanpa melihat seluruh file. Aturan yang dipakai di
     * sini: `DD/MM/YYYY` -- konvensi Indonesia, sesuai audiensnya.
     * Dokumentasi template menyebutkan aturan ini, dan `02/13/2026`
     * (bulan 13) otomatis ditolak sebagai tanggal tak sah -- jadi yang
     * benar-benar ambigu hanyalah kasus seperti `05/06/2026`, dan
     * untuk itu cara paling aman bagi user adalah menulis format ISO.
     *
     * Sheet "Petunjuk" di template menyebutkan hal ini secara terbuka;
     * menyembunyikannya hanya membuat user menemukan hasilnya lewat
     * baris yang ditolak.
     */
    public static function tanggal(mixed $nilai): ?string
    {
        $nilai = self::bolehKosongkan($nilai);

        if ($nilai === null) {
            return null;
        }

        if ($nilai instanceof DateTimeInterface) {
            return CarbonImmutable::instance($nilai)->format('Y-m-d');
        }

        $teks = trim((string) $nilai);

        // Excel/Ampere menyimpan tanggal sebagai angka serial.
        // 45000 = 2023-03-15. Hanya ditebak kalau seluruh isinya angka
        // DAN berada di rentang yang masuk akal untuk tanggal.
        if (ctype_digit(str_replace('.', '', $teks)) && ! str_contains($teks, '-')) {
            $serial = (int) $nilai;

            if ($serial > 20000 && $serial < 80000) {
                try {
                    return CarbonImmutable::createFromTimestamp(($serial - 25569) * 86400)
                        ->setTimezone('UTC')
                        ->format('Y-m-d');
                } catch (Throwable) {
                    // Falls through ke parser di bawah.
                }
            }
        }

        $teks = str_replace(['.', ' '], ['-', ' '], $teks);
        $teks = trim(preg_replace('/\s+/', ' ', $teks) ?? $teks);

        /*
         * Pola dengan separator eksplisit, dicoba berurutan dari yang
         * paling tidak ambigu.
         *
         * SENGAJA daftar [regex, urutan], bukan associative array.
         * Dua pola terakhir punya regex yang PERSIS sama dan hanya
         * urutannya yang berbeda -- sebagai key array, yang kedua akan
         * menimpa yang pertama dan gaya Amerika tidak pernah akan
         * dicoba sama sekali.
         */
        $pola = [
            ['/^(\d{4})-(\d{1,2})-(\d{1,2})$/', ['y', 'm', 'd']],
            ['/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', ['d', 'm', 'y']],
            ['/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', ['m', 'd', 'y']],
        ];

        foreach ($pola as [$regex, $urutan]) {
            if (! preg_match($regex, $teks, $cocok)) {
                continue;
            }

            $bagian = [];
            foreach ($urutan as $i => $jenis) {
                $bagian[$jenis] = (int) $cocok[$i + 1];
            }

            $tahun = $bagian['y'];
            $bulan = $bagian['m'];
            $hari = $bagian['d'];

            if (! checkdate($bulan, $hari, $tahun)) {
                // Coba ditafsirkan sebagai bulan/tanggal terbalik -- ini
                // yang menyelamatkan "13/02/2026" (Februari, format
                // Amerika) yang kalau tidak akan ditolak.
                if (checkdate($hari, $bulan, $tahun)) {
                    [$tahun, $bulan, $hari] = [$tahun, $hari, $bulan];
                } else {
                    return null;
                }
            }

            // Tanggal yang jauh di masa depan hampir selalu salah ketik
            // tahun (mis. 20260). Tahun 1900-2100 adalah rentang yang
            // mencakup seluruh data yang mungkin relevan untuk data
            // operasional harian sebuah lokasi.
            if ($tahun < 1900 || $tahun > 2100) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
        }

        // "1 Februari 2026" dan sejenisnya.
        $bulanIndonesia = [
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
            'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8, 'agu' => 8,
            'september' => 9, 'oktober' => 10, 'november' => 11, 'nopember' => 11,
            'desember' => 12, 'des' => 12, 'jan' => 1, 'feb' => 2, 'mar' => 3,
            'apr' => 4, 'jun' => 6, 'jul' => 7, 'sep' => 9, 'okt' => 10, 'nov' => 11,
        ];

        if (preg_match('/^(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})$/u', $teks, $cocok)) {
            $bulan = $bulanIndonesia[mb_strtolower($cocok[2])] ?? null;

            if ($bulan !== null && checkdate($bulan, (int) $cocok[1], (int) $cocok[3])) {
                return sprintf('%04d-%02d-%02d', (int) $cocok[3], $bulan, (int) $cocok[1]);
            }
        }

        return null;
    }

    /**
     * NIK: 16 digit, tanpa lose leading zero.
     *
     * Berbeda dari kolom teks biasa, NIK SELALU dinormalkan jadi string
     * dan nol di depan dipertahankan -- kolomnya varchar(20), dan
     * `0812...` yang tersimpan sebagai `812...` tidak bisa dicocokkan
     * lagi dengan NIK dari KTP mana pun.
     */
    public static function nik(mixed $nilai): ?string
    {
        $nilai = self::bolehKosongkan($nilai);

        if ($nilai === null) {
            return null;
        }

        if (is_int($nilai) || is_float($nilai)) {
            return (string) $nilai;
        }

        $teks = preg_replace('/\D/', '', (string) $nilai) ?? '';

        return $teks === '' ? null : $teks;
    }

    /** Email: lowercase + trim. `null` kalau tidak terbaca sebagai email. */
    public static function email(mixed $nilai): ?string
    {
        $nilai = self::bolehKosongkan($nilai);

        if ($nilai === null) {
            return null;
        }

        $teks = mb_strtolower(trim((string) $nilai));

        return filter_var($teks, FILTER_VALIDATE_EMAIL) ? $teks : null;
    }

    /** Nomor telepon: hanya digit, awalan `+62` untuk nomor Indonesia. */
    public static function telepon(mixed $nilai): ?string
    {
        $nilai = self::bolehKosongkan($nilai);

        if ($nilai === null) {
            return null;
        }

        $teks = (string) $nilai;

        // Simbol telepon yang biasa diketik manusia (spasi, tanda hubung,
        // kurung, titik) dibuang; yang tersisa harus angka.
        $angka = preg_replace('/\D/', '', $teks) ?? '';

        if ($angka === '') {
            return null;
        }

        // Nomor Indonesia yang ditulis "08..." jadi "+628..." supaya
        // konsisten dengan form pendaftaran yang menerima format
        // internasional. Yang sudah "+62" atau "62" tidak diubah lagi.
        if (str_starts_with($angka, '0')) {
            $angka = '62'.substr($angka, 1);
        }

        return '+'.$angka;
    }

    /** Umpan balik ke bentuk aslinya untuk laporan: `null` jadi "-". */
    public static function tampil(mixed $nilai): string
    {
        if ($nilai === null || $nilai === '') {
            return '-';
        }

        if (is_bool($nilai)) {
            return $nilai ? 'Ya' : 'Tidak';
        }

        if (is_float($nilai)) {
            // Buang nol desimal yang tidak berarti: 1500.00 -> "1500".
            return rtrim(rtrim(number_format($nilai, 2, ',', '.'), '0'), ',');
        }

        return (string) $nilai;
    }

    /**
     * Boleh kosong, atau lempar kalau wajib diisi.
     *
     * Satu tempat untuk aturan "wajib" supaya blueprint tidak menulis
     * `if ($nilai === null) throw ...` yang bentuknya bisa berbeda
     * antarimplementasi.
     */
    private static function bolehKosongkan(mixed $nilai): mixed
    {
        return self::kosongkan($nilai);
    }
}
