<?php

namespace App\Services\Migration;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Baca file yang diunggah menjadi baris array yang bisa dipetakan.
 *
 * BENTUK FILE YANG DITERIMA
 * ==========================
 *
 * `.xlsx` dan `.xls` dibaca sebagai spreadsheet sungguhan -- kolom bertipe
 * tanggal jadi objek tanggal, angka jadi float, dan dropdown bisa
 * dipasang di template.
 *
 * `.csv` dibaca tanpa PhpSpreadsheet. Alasannya bukan performa, tapi
 * KEBERHASILAN: CSV yang disimpan Excel di Windows sering memakai
 * separator `;` dan encoding `CP1252`, dan PhpSpreadsheet menebak
 * keduanya dengan benar hanya kadang. Pembaca manual di bawah
 * mendeteksi keduanya secara deterministik, dan karena tidak ada
 * ketergantungan pada tebakan, hasilnya sama di server mana pun.
 *
 * MENGAPA TIDAK PAKAI SATU JALUR UNTUK KEDUANYA
 * ==============================================
 *
 * Karena `.csv` yang ditulis Excel Indonesia punya dua masalah yang
 * keduanya lazim di dunia nyata, dan keduanya gagal diam-diam kalau
 * dibaca sebagai spreadsheet biasa:
 *
 *   1. Separator `;` -- kalau dibaca sebagai `,`, seluruh file jadi
 *      SATU kolom. Pesan errornya nanti berbunyi "kolom NIK kosong"
 *      untuk file yang jelas-jelas punya kolom NIK terisi.
 *   2. Encoding CP1252 -- karakter `├⌐` jadi `├â┬⌐`, dan kalau lalu
 *      disimpan ke MySQL dengan utf8mb4, nama pelanggan jadi rusak
 *      permanen.
 *
 * Keduanya ditangani di sini secara eksplisit, bukan diserahkan ke
 * tebakan library.
 */
class SpreadsheetReader
{
    /** Batas aman: file sebesar ini tidak akan membuat proses kehabisan memori. */
    private const MAX_BARIS = 50_000;

    /** Jumlah byte pertama yang dipakai untuk mendeteksi encoding CSV. */
    private const CUPLIKAN_ENCODING = 8192;

    /** Extension yang boleh. */
    private const EKSTENSI = ['xlsx', 'xls', 'xlsm', 'csv', 'txt'];

    /**
     * Pemetaan nama file -> alasan penolakan, ditulis untuk dibaca admin.
     *
     * @return array<int, string>
     */
    public static function ekstensiYangBoleh(): array
    {
        return self::EKSTENSI;
    }

    /**
     * Baca file menjadi baris-baris data.
     *
     * @param  UploadedFile  $file  Berkas yang sudah tervalidasi
     * @param  string  $sheet  Nama sheet yang dibaca (hanya untuk xlsx)
     * @return array{header: array<string,int>, baris: array<int, array<string, string|null>>, jumlah: int}
     *
     * @throws \RuntimeException kalau file tidak bisa dibaca sama sekali
     */
    public static function baca(UploadedFile $file, string $sheet): array
    {
        $ekstensi = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        return $ekstensi === 'csv' || $ekstensi === 'txt'
            ? self::bacaCsv($file)
            : self::bacaSpreadsheet($file, $sheet);
    }

    /**
     * Baca dari isi mentah, bukan dari `UploadedFile`.
     *
     * Dipakai pipeline karena isinya sudah diambil dari request sebelum
     * disimpan. Versi ini menulis isi ke file sementara dulu, karena
     * PhpSpreadsheet_reader hanya menerima nama file -- API-nya memang
     * begitu sejak awal, dan menulis ulang seluruh pembacaan sebagai
     * in-memory reader akan menggandakan semua kode di sini tanpa
     * menghilangkan satu pun ketergantungan sistemnya.
     *
     * File sementara dihapus di `finally`, termasuk kalau pembacaan
     * melempar: file unggahan user tidak boleh tertinggal di disk kalau
     * ada error.
     *
     * @param  string  $ekstensi  `xlsx`, `xls`, atau `csv`
     * @return array{header: array<string,int>, baris: array<int, array<string, string|null>>, jumlah: int, filename: string}
     */
    public static function bacaDariIsi(string $isi, string $ekstensi, string $sheet): array
    {
        $sementara = tempnam(sys_get_temp_dir(), 'migrasi-');

        if ($sementara === false) {
            throw new \RuntimeException('Server tidak bisa membuat file sementara untuk membaca upload.');
        }

        try {
            file_put_contents($sementara, $isi);

            // Disamarkan dengan ekstensi yang benar: `IOFactory::createReaderForFile()`
            // MEMBACA EKSTENSI, bukan magic bytes. File sementara tanpa ekstensi
            // akan dianggap format tidak dikenal.
            $berkas = $sementara.'.'.ltrim($ekstensi, '.');

            if ($berkas !== $sementara) {
                rename($sementara, $berkas) || null;
            }

            $sementara = $berkas;

            $fake = self::fakeUploadedFile($sementara, $ekstensi);

            return [...self::baca($fake, $sheet), 'filename' => basename($sementara)];
        } finally {
            if (is_file($sementara)) {
                @unlink($sementara);
            }
        }
    }

    /**
     * Bungkus file sementara sebagai `UploadedFile`.
     *
     * Dipakai supaya `baca()` punya satu jalur untuk xlsx dan csv, dan
     * supaya penamaan file sementara tidak bocor ke pesan error.
     */
    private static function fakeUploadedFile(string $path, string $ekstensi): UploadedFile
    {
        $file = new class($path, 'template.'.$ekstensi) extends UploadedFile {};

        // `getClientOriginalExtension()` membaca nama yang dikonstruktor
        // terima, jadi ini cukup.
        return $file;
    }

    /* ================================================================
     | SPREADSHEET (xlsx / xls)
     |============================================================
     */

    /**
     * @return array{header: array<string,int>, baris: array<int, array<string, string|null>>, jumlah: int}
     */
    private static function bacaSpreadsheet(UploadedFile $file, string $sheet): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new \RuntimeException('File yang diunggah tidak bisa dibaca dari server.');
        }

        /*
         * Setelan yang mengubah APA yang ditulis ke disk, bukan apa yang
         * dibaca: PhpSpreadsheet secara bawaan menyimpan cell kosong
         * sebagai `<v></v>` dan formula sebagai teksnya. Keduanya tidak
         * masalah untuk pembacaan.
         */
        $reader = self::buatReader($path);

        /*
         * `setReadDataOnly(true)` penting untuk file besar: tanpa itu
         * PhpSpreadsheet membangun objek lengkap untuk setiap cell --
         * termasuk style, formula, dan merged cell -- yang untuk 50.000
         * baris berarti ratusan MB memori. Yang dibutuhkan di sini
         * hanya nilai string-nya.
         */
        $reader->setReadDataOnly(true);

        try {
            $spreadsheet = $reader->load($path);
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'File spreadsheet tidak bisa dibuka. Pastikan bukan hasil '
                .'simpan parsial dan tidak sedang dibuka terkunci di Excel: '.$e->getMessage(),
                0,
                $e
            );
        }

        try {
            $worksheet = self::cariSheet($spreadsheet, $sheet);

            return self::bacaWorksheet($worksheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    private static function buatReader(string $path): IReader
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'Format file tidak dikenali. Gunakan .xlsx, .xls, atau .csv.',
                0,
                $e
            );
        }

        // XLS lama (BIFF) dibaca lewat converter yang butuh ekstensi
        // `iconv`. Kalau tidak ada, file .xls akan gagal dengan pesan
        // internal yang tidak menjelaskan apa pun -- jadi dicek di sini
        // dan dikembalikan sebagai instruksi yang bisa dibaca.
        if ($reader instanceof Xls
            && ! extension_loaded('iconv')) {
            throw new \RuntimeException(
                'Server ini tidak bisa membaca .xls. Simpan ulang filenya '
                .'sebagai .xlsx atau .csv.'
            );
        }

        return $reader;
    }

    private static function cariSheet(Spreadsheet $spreadsheet, string $wanted): Worksheet
    {
        $sebuah = $spreadsheet->getSheetByName($wanted);

        if ($sebuah instanceof Worksheet) {
            return $sebuah;
        }

        /*
         * Nama sheet dari template bisa berbeda karena Windows menambahkan
         * spasi di akhir, atau karena Excel menamai ulang sheet jadi
         * "Data (1)". Menolak file dengan pesan "sheet tidak ditemukan"
         * akan sangat membingungkan: file-nya jelas-jelas benar, hanya
         * nama sheet-nya yang bergeser sedikit.
         *
         * Jadi dicocokkan longgar dulu, baru ke sheet pertama sebagai
         * jaring pengaman.
         */
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if (Str::lower(trim($sheet->getTitle())) === Str::lower(trim($wanted))) {
                return $sheet;
            }
        }

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if (Str::contains(Str::lower($sheet->getTitle()), Str::lower($wanted))) {
                return $sheet;
            }
        }

        return $spreadsheet->getSheet(0);
    }

    /**
     * @return array{header: array<string,int>, baris: array<int, array<string, string|null>>, jumlah: int}
     */
    private static function bacaWorksheet(Worksheet $worksheet): array
    {
        $tertinggi = $worksheet->getHighestRow();
        $terlebar = $worksheet->getHighestColumn();

        if ($tertinggi < 1) {
            return ['header' => [], 'baris' => [], 'jumlah' => 0];
        }

        /*
         * Kolom dari A sampai kolom terakhir yang benar-benar terpakai.
         * `getHighestColumn()` mengembalikan kolom terakhir yang punya
         * isi, jadi daftar ini hanya berisi kolom yang mungkin berisi
         * data.
         */
        $kolomAngka = Coordinate::rangeBoundaries('A1:'.$terlebar);

        $barisTerbaca = min($tertinggi, self::MAX_BARIS + 1);

        /*
         * Nilai dikembalikan apa adanya (bukan lewat `toString()`) karena
         * `toString()` mengubah objek tanggal menjadi "01 Feb 2026 00:00:00"
         * dan angka 1234 menjadi "1234" yang kehilangan fakta bahwa itu
         * Float. Pembacaan string dilakukan di {@see normalize()},
         * yang tahu kalender dan formatnya.
         */
        $matrix = [];
        for ($r = 1; $r <= $barisTerbaca; $r++) {
            $row = [];
            for ($c = 1; $c <= $kolomAngka[1]; $c++) {
                $nilai = $worksheet->getCell([$r, $c])->getValue();
                $row[$c] = $nilai;
            }
            $matrix[$r] = $row;
        }

        return self::matrixKeBaris($matrix);
    }

    /* ================================================================
     | CSV
     |============================================================
     */

    /**
     * @return array{header: array<string,int>, baris: array<int, array<string, string|null>>, jumlah: int}
     */
    private static function bacaCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new \RuntimeException('File CSV tidak bisa dibaca dari server.');
        }

        $isi = file_get_contents($path);

        if ($isi === false) {
            throw new \RuntimeException('Isi file CSV tidak bisa dibaca.');
        }

        /*
         * Deteksi encoding.
         *
         * Urutannya penting: cek UTF-8 DULU. Hampir semua file modern
         * sudah UTF-8, dan `mb_check_encoding` menolaknya kalau ada satu
         * saja byte yang tidak valid -- sedangkan CP1252 mendefinisikan
         * semua byte, jadi HANYA bisa salah kalau file memang UTF-8
         * dan kita memaksa membacanya sebagai CP1252. Urutan yang
         * terbalik akan merusak setiap nama dengan karakter non-ASCII.
         */
        $encoding = mb_check_encoding($isi, 'UTF-8') ? 'UTF-8' : self::tebakEncoding($isi);

        if ($encoding !== 'UTF-8') {
            $konversi = @mb_convert_encoding($isi, 'UTF-8', $encoding);

            if ($konversi !== false) {
                $isi = $konversi;
            }
        }

        /*
         * Buang BOM. Excel menambahkan U+FEFF di depan file UTF-8, dan
         * tanpa dihapus dia jadi bagian dari NAMA KOLOM PERTAMA -- jadi
         * setiap file yang disimpan Excel akan melaporkan "kolom NIK tidak
         * ditemukan" padahal NIK ada. Ini penyebab paling sering dari
         * template yang "tidak jalan".
         */
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi) ?? $isi;

        // Baris baru Windows (`\r\n`) dan Mac lama (`\r`) disamakan.
        $isi = str_replace(["\r\n", "\r"], "\n", $isi);

        $separator = self::tebakSeparator($isi);

        $baris = [];

        foreach (explode("\n", $isi) as $isiBaris) {
            // Baris kosong di tengah file bukan error; diabaikan diam-diam
            // supaya user tidak perlu menghapus baris kosong sebelum upload.
            if (trim($isiBaris) === '') {
                continue;
            }

            $kolom = str_getcsv($isiBaris, $separator, '"', '\\');

            // Baris yang hanya berisi satu kolom kosong: sisa baris yang
            // terpotong di tengah file. Ditumpuk agar tidak jadi data.
            if (count($kolom) === 1 && trim((string) $kolom[0]) === '') {
                continue;
            }

            $baris[] = $kolom;

            if (count($baris) > self::MAX_BARIS + 1) {
                break;
            }
        }

        $matrix = [];
        foreach ($baris as $i => $kolom) {
            foreach ($kolom as $j => $nilai) {
                $matrix[$i + 1][$j + 1] = $nilai;
            }
        }

        return self::matrixKeBaris($matrix);
    }

    /**
     * Separator mana yang dipakai file ini.
     *
     * `;` didahulukan karena itu default Excel untuk locale yang pakai
     * koma sebagai desimal -- termasuk Indonesia. Kalau file pakai `,`
     * dan desimalnya `;`, file itu jadi ambigu; untuk kasus data kita
     * (NIK, nama, alamat) tidak ada angka desimal di kolom utama,
     * jadi `,` adalah pilihan yang aman.
     */
    private static function tebakSeparator(string $isi): string
    {
        $cuplikan = substr($isi, 0, self::CUPLIKAN_ENCODING);

        // Header dipakai, bukan isi seluruh file: baris data bisa punya
        // koma di dalam alamat ("Jl. Merdeka, No. 5") yang membuat
        // hitungan kasar salah pilih.
        $barisHeader = strtok($cuplikan, "\n") ?: $cuplikan;

        $koma = substr_count($barisHeader, ',');
        $titikKoma = substr_count($barisHeader, ';');
        $tab = substr_count($barisHeader, "\t");

        $terbanyak = max($koma, $titikKoma, $tab);

        if ($terbanyak === 0) {
            return ',';
        }

        // Kalau `,` dan `;` sama banyak, teks BERKOMA lebih mungkin
        // sebagai pemisah -- bukan ada koma di dalam teks.
        if ($koma >= $titikKoma && $koma >= $tab) {
            return ',';
        }

        return $tab >= $koma && $tab >= $titikKoma ? "\t" : ';';
    }

    /**
     * Tebak encoding untuk file yang bukan UTF-8.
     *
     * Diawali `windows-1252` karena overwhelmingly kasusnya file dari
     * Excel di Windows -- dan secara teknis CP1252 bisa membaca semua
     * byte, jadi tebakan ini selalu "berhasil" dan tidak pernah gagal.
     * Risikonya hanya file UTF-8 yang salah baca, tapi `mb_check_encoding`
     * di atas sudah menyingkirkan kasus itu sebelum sampai ke sini.
     */
    private static function tebakEncoding(string $isi): string
    {
        foreach (['windows-1252', 'ISO-8859-1'] as $kandidat) {
            if (function_exists('mb_check_encoding') && mb_check_encoding($isi, $kandidat)) {
                return $kandidat;
            }
        }

        return 'windows-1252';
    }

    /* ================================================================
     | BERSAMA
     |============================================================
     */

    /**
     * Ubah matrix angka (1-indexed) menjadi header + baris.
     *
     * Baris pertama yang TIDAK kosong dianggap header. Ini lebih tahan
     * terhadap file yang benar-benar dipakai user daripada memaksa
     * baris pertama jadi header: banyak orang mengetik judul di baris
     * 1 dan header sebenarnya ada di baris 2.
     *
     * @param  array<int, array<int, mixed>>  $matrix
     * @return array{header: array<string,int>, baris: array<int, array<string, string|null>>, jumlah: int}
     */
    private static function matrixKeBaris(array $matrix): array
    {
        $urutanBaris = array_keys($matrix);
        sort($urutanBaris);

        $barisHeader = null;

        foreach ($urutanBaris as $r) {
            $adaIsi = false;

            foreach ($matrix[$r] as $nilai) {
                if (self::adaIsi($nilai)) {
                    $adaIsi = true;
                    break;
                }
            }

            if ($adaIsi) {
                $barisHeader = $r;
                break;
            }
        }

        if ($barisHeader === null) {
            return ['header' => [], 'baris' => [], 'jumlah' => 0];
        }

        // Nama kolom: apa adanya, tanpa normalisasi. Normalisasi terjadi
        // di `cocokkanHeader()` supaya pesan errornya bisa menyebut nama
        // yang benar-benar diketik user.
        $header = [];
        foreach ($matrix[$barisHeader] as $kol => $nilai) {
            $header[$kol] = trim(self::keTeks($nilai));
        }

        $baris = [];

        foreach ($urutanBaris as $r) {
            if ($r <= $barisHeader) {
                continue;
            }

            // Baris yang seluruhnya kosong dilewati, tapi NOMOR BARIS
            // ASLINYA tetap disimpan -- supaya pesan error bisa menulis
            // "baris 47", bukan "baris 12", yang tidak akan cocok dengan
            // apa yang user lihat di Excel.
            $adaIsi = false;
            foreach ($matrix[$r] as $nilai) {
                if (self::adaIsi($nilai)) {
                    $adaIsi = true;
                    break;
                }
            }

            if (! $adaIsi) {
                continue;
            }

            $baris[$r] = array_map(
                fn ($nilai) => self::keTeks($nilai),
                $matrix[$r]
            );

            if (count($baris) >= self::MAX_BARIS) {
                break;
            }
        }

        return [
            'header' => $header,
            'baris' => $baris,
            'jumlah' => count($baris),
        ];
    }

    private static function adaIsi(mixed $nilai): bool
    {
        if ($nilai === null) {
            return false;
        }

        if (is_string($nilai)) {
            return trim($nilai) !== '';
        }

        return true;
    }

    /**
     * Nilai sel -> string, TANPA kehilangan informasi.
     *
     * Float dari spreadsheet tidak boleh diubah ke string dengan
     * `number_format()` -- 1500.0 akan jadi "1.500" (pemisah ribuan)
     * dan merusak kolom yang isninya NIK atau kode. Karena itu
     * `rtrim` dipakai, bukan pemformat ribuan.
     */
    private static function keTeks(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        if (is_bool($nilai)) {
            return $nilai ? 'Ya' : 'Tidak';
        }

        if ($nilai instanceof \DateTimeInterface) {
            return $nilai->format('Y-m-d');
        }

        if (is_float($nilai)) {
            // 1234.0 -> "1234", bukan "1234.00" dan bukan "1,234".
            $teks = rtrim(rtrim(number_format($nilai, 10, '.', ''), '0'), '.');

            return $teks === '' || $teks === '-' ? '0' : $teks;
        }

        $teks = trim((string) $nilai);

        return $teks === '' ? null : $teks;
    }
}
