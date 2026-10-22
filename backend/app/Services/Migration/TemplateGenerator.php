<?php

namespace App\Services\Migration;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Buat file template untuk satu jenis migrasi ke satu lokasi.
 *
 * TEMPLATE YANG DIHASILKAN MEMANG DIISI SENDIRI OLEH USER
 * =========================================================
 *
 * Ini bukan ekspor data: tidak ada satu baris pun yang berasal dari
 * database lokasi di sheet `Data`. Yang ada hanya tiga sheet:
 *
 *   `Data`       Kosong, header + satu baris contoh.
 *   `Petunjuk`   Aturan isi, format, dan contoh tiap kolom.
 *   `Referensi`  Nilai yang HARUS dipilih (paket, desa, jabatan) yang
 *                dibaca dari database lokasi -- ini yang membuat template
 *                terasa "dibuat untuk lokasi ini", bukan generik.
 *
 * Alasan `Referensi` ada: hampir semua kegagalan migrasi yang terjadi
 * berasal dari user mengetik nama paket yang tidak persis sama dengan
 * yang ada di database ("Paket A" vs "Paket_radius_20"). Dengan daftar
 * itu ditulis di file yang sama, kesalahan itu bisa dicegat di tahap
 * validasi dengan pesan yang menyebut pilihannya, bukan ditolak diam-diam.
 *
 * MENGAPA TIDAK CUMA CSV
 * =====================
 *
 * CSV bisa dibuka di Excel, tapi tidak bisa punya dropdown, tidak bisa
 * memblokir isi yang salah, dan tidak bisa punya sheet penjelasan.
 * Untuk file yang diisi orang yang bukan superadmin -- dan sering kali
 * memang admin lokasi -- ketiga hal itu adalah selisih antara "selesai
 * dalam satu percobaan" dan "lima percobaan dengan lima pertanyaan".
 *
 * Karena itu CSV TETAP disediakan sebagai pilihan kedua (`?format=csv`),
 * bukan sebagai satu-satunya pilihan: sheet penjelasan tidak bisa
 * menyertai file CSV, dan user yang memakai WPS/LibreOffice tetap butuh
 * keduanya.
 */
class TemplateGenerator
{
    /**
     * Baris terakhir template.
     *
     * BUKAN baris terakhir Excel (1.048.576).
     *
     * Rentang format sel seperti `B2:B1048576` kelihatan murah --
     * satu panggilan, bukan sejuta -- tapi setiap sel di dalamnya tetap
     * dimaterialkan sebagai objek `CellAddress` oleh PhpSpreadsheet.
     * Untuk satu kolom itu 1.048.576 objek; untuk kolom Tiket yang punya
     * 15 kolom, 15 juta. Writer lalu ikut serialize semuanya, dan proses
     * tidak pernah selesai: template 12 KB yang seharusnya ditulis
     * dalam 0,2 detik justru butuh lebih dari 3 menit, lalu berhenti dengan
     * "Allowed memory size exhausted" yang tidak menyebut penyebabnya.
     *
     * Batas 10.000 baris dipilih karena lebih besar dari kapasitas
     * maksimum file migrasi (lihat `config/migrasi.php`: 10 MB ~= 50.000
     * baris, tapi template tidak perlu menampung semuanya). Kalau
     * file ternyata melebihi, yang terjadi bukan file rusak: validasi
     * menolak baris yang di luar rentang dengan pesan yang menyebut
     * angkanya.
     */
    private const BARIS_AKHIR = 10_000;

    /**
     * Batas panjang daftar dropdown Excel.
     *
     * Excel hanya menerima 255 karakter untuk daftar dropdown inline.
     * Lebih dari itu, file hasil simpan BERHASI tapi Excel menampilkan
     * "daftar sumber data tidak valid" saat file dibuka -- dan itu baru
     * ketahuan setelah template diunduh, ditransfer ke komputer lain, dan
     * dibuka. Jadi daftar yang melebihi batas ini dibiarkan sebagai teks
     * bebas, dan nilainya tetap divalidasi di server.
     */
    private const MAKS_DROPDOWN = 240;

    public function __construct(private TenantManager $manager) {}

    /**
     * Bangun file template untuk satu jenis data di satu lokasi.
     *
     * SELURUH prosesnya -- termasuk membaca daftar referensi dari
     * database lokasi -- ada di dalam satu `runFor()`.
     *
     * Ini bukan sekadar kerapian. `Spreadsheet` sendiri tidak menyentuh
     * database, tapi `references()` memanggil model yang tidak mengunci
     * `$connection`, jadi kalau dipanggil di luar penjaga, yang terbaca
     * adalah tabel pusat -- yang tidak punya `installation_packages`.
     * Hasilnya PDOException, dan superadmin melihat "database lokasi
     * tidak bisa dibaca" untuk lokasi yang sebenarnya baik-baik saja.
     *
     * @return array{body: string, filename: string}
     */
    public function buat(MigrationBlueprint $blueprint, Tenant $tenant, string $format = 'xlsx'): array
    {
        $format = strtolower($format) === 'csv' ? 'csv' : 'xlsx';

        return $this->manager->runFor($tenant, function () use ($blueprint, $format, $tenant) {
            $body = $format === 'csv'
                ? $this->csv($blueprint)
                : $this->xlsx($blueprint);

            /*
             * Timestamp pada nama file tidak disengaja: user sering
             * mengunduh template yang sama berulang kali, dan nama yang
             * sama akan menimpa unduhan sebelumnya di folder yang sama.
             */
            return [
                'body' => $body,
                'filename' => 'template-'.$blueprint->type().'-'.$tenant->slug.'-'.date('Ymd-Hi').'.'.$format,
            ];
        });
    }

    /* ================================================================
     | XLSX
     |============================================================
     */

    private function xlsx(MigrationBlueprint $blueprint): string
    {
        $spreadsheet = new Spreadsheet;

        try {
            $this->isiSheetData($spreadsheet, $blueprint);
            $this->isiSheetPetunjuk($spreadsheet, $blueprint);
            $this->isiSheetReferensi($spreadsheet, $blueprint);

            // Sheet `Data` yang aktif saat file dibuka: user harus
            // langsung melihat tempat mengetik, bukan halaman penjelasan.
            $spreadsheet->setActiveSheetIndex(0);

            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);

            ob_start();

            try {
                $writer->save('php://output');

                return (string) ob_get_clean();
            } catch (Throwable $e) {
                // `ob_start()` tanpa `ob_end_clean()` di jalur gagal
                // menyisakan buffer yang tidak pernah ditutup, dan
                // seluruh output setelah titik itu ikut masuk ke
                // respons -- termasuk pesan error PHP.
                ob_end_clean();

                throw $e;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /* ================================================================
     | CSV
     |============================================================
     */

    /**
     * CSV hanya memuat header dan baris contoh.
     *
     * Dua baris: bukan karena cukup untuk apa pun, tapi karena baris
     * kedua berisi nilai contoh yang BISA DISALIN. duly resuelto tanpa
     * pilihan lain adalah membiarkan user menebak format -- dan
     * menebak format NIK atau tanggal adalah penyebab paling sering
     * file ditolak.
     *
     * Pemisah koma dipakai (bukan titik koma) supaya file langsung
     * terbaca sebagai CSV di spreadsheet apa pun; `SpreadsheetReader`
     * mendeteksi keduanya saat mengimpor, jadi pilihan ini tidak
     * membatasi jalur impor.
     */
    private function csv(MigrationBlueprint $blueprint): string
    {
        $kolom = $blueprint->columns();

        $header = array_merge(['#'], array_column($kolom, 'label'));
        $contoh = array_merge(
            [MigrationPipeline::PENANDA_CONTOH],
            array_column($kolom, 'example')
        );

        $keluaran = fopen('php://temp', 'r+b');

        if ($keluaran === false) {
            return '';
        }

        // BOM UTF-8: tanpa itu, Excel di Windows membuka file ini
        // sebagai ANSI dan setiap karakter Indonesia berubah jadi
        // kotak tanya.
        fwrite($keluaran, "\xEF\xBB\xBF");

        fputcsv($keluaran, $header);
        fputcsv($keluaran, $contoh);

        rewind($keluaran);
        $isi = (string) stream_get_contents($keluaran);
        fclose($keluaran);

        return $isi;
    }

    /* ================================================================
     | SHEET DATA
     |============================================================
     */

    /**
     * Sheet utama: header + satu baris contoh.
     *
     * Baris contoh sengaja ikut, bukan dikosongkan: kolom yang kosong
     * menyisakan celah, karena user tidak punya contoh format untuk
     * diikuti, dan format yang salah lebih cepat terlihat ketika ada
     * contoh yang menyalin format itu. Baris ditandai jelas supaya
     * tidak ikut terimpor -- `MigrationPipeline` membuang baris yang
     * ditandai di kolom pertama.
     */
    private function isiSheetData(Spreadsheet $spreadsheet, MigrationBlueprint $blueprint): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($blueprint->sheetName());

        $kolom = $blueprint->columns();
        $jumlah = count($kolom) + 1; // + kolom penanda contoh

        $sheet->fromArray(
            array_merge(['#'], array_column($kolom, 'label')),
            null,
            'A1'
        );

        $sheet->fromArray(
            array_merge(
                [MigrationPipeline::PENANDA_CONTOH],
                array_column($kolom, 'example')
            ),
            null,
            'A2'
        );

        $this->gayaHeader($sheet, $jumlah, 1);

        $huruf = Coordinate::stringFromColumnIndex($jumlah);

        // Baris contoh dibuat redup supaya jelas bukan data.
        $sheet->getStyle("A2:{$huruf}2")->applyFromArray([
            'font' => [
                'italic' => true,
                'color' => ['argb' => 'FF94A3B8'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFF8FAFC'],
            ],
        ]);

        $this->gayaBorder($sheet, $jumlah, 2);

        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->freezePane('B2');

        foreach ($kolom as $i => $definisi) {
            $col = Coordinate::stringFromColumnIndex($i + 2);

            /*
             * Lebar kolom dihitung dari isi header dan contoh, bukan
             * `setAutoSize(true)`.
             *
             * Auto-size mengukur dengan visiting setiap sel -- dan
             * setelah aturan kolom dipasang, "setiap sel" itu berarti
             * sampai 10.000 baris per kolom. Sepuluh template seperti
             * ini butuh 53 detik; tanpa auto-size, di bawah satu
             * detik. Perbedaannya tidak terlihat dari kode, dan file
             * yang dihasilkan tetap benar.
             *
             * Lebarnya dibatasi supaya kolom NIK dan Alamat tidak
             * selebar paragraf.
             */
            $lebar = mb_strlen($definisi['label']) + 2;

            foreach ([$definisi['example'] ?? '', $definisi['help'] ?? ''] as $teks) {
                $lebar = max($lebar, min(40, mb_strlen((string) $teks) + 2));
            }

            $sheet->getColumnDimension($col)->setWidth(max(12, min(40, $lebar)));

            $this->pasangAturanKolom($sheet, $col, $definisi);
        }
    }

    /**
     * Komentar, format sel, dan validasi untuk satu kolom.
     *
     * Ketiganya diletakkan di sini, bukan di {@see isiSheetData()}, karena
     * ketiganya adalah aturan yang SALAH JIKA TIDAK DITERAPKAN -- bukan
     * hiasan. Kalau dipisah ke tempat lain, satu di antaranya bisa hilang
     * tanpa membuat error.
     */
    private function pasangAturanKolom(
        Worksheet $sheet,
        string $col,
        array $definisi
    ): void {
        $rentang = "{$col}2:{$col}".self::BARIS_AKHIR;

        /*
         * Komentar di header.
         *
         * Ini tempat paling murah untuk menjelaskan aturan kolom: user
         * sudah melihat header itu di layar, dan tooltip muncul begitu
         * kursor mendarat di situ. Aturan yang sama juga ditulis di
         * sheet `Petunjuk` untuk yang tidak melihat komentar.
         */
        $sheet->getComment($col.'1')->getText()->createTextRun(
            $definisi['label'].' -- '.$definisi['help']
            .($definisi['required'] ? "\nWAJIB diisi." : '')
        );

        /*
         * NIK dan kolom kode SELALU teks.
         *
         * Ini bukan gaya, ini memperbaiki sumber masalah: kolom yang
         * diformat angka di Excel membuat `0812...` jadi `812...` dan
         * `005.0001.0001` jadi angka, tepat seperti yang terjadi kalau
         * diformat sebagai angka. Format sel hanya mengubah TAMPILAN
         * di Excel -- nilai yang tersimpan di file tetap sama -- tapi
         * format teks memastikan apa yang dilihat dan yang diimpor sama.
         */
        $tipe = $definisi['type'] ?? 'text';

        if (in_array($tipe, ['nik'], true)
            || str_contains(mb_strtolower($definisi['key']), 'kode')
            || ($definisi['max'] ?? null) !== null) {
            $sheet->getStyle($rentang)->getNumberFormat()->setFormatCode('@');
        } elseif ($tipe === 'date') {
            $sheet->getStyle($rentang)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        } elseif (in_array($tipe, ['decimal', 'number'], true)) {
            $sheet->getStyle($rentang)->getNumberFormat()->setFormatCode('#,##0.##');
        }

        $validasi = $this->validasiKolom($definisi);

        if ($validasi === null) {
            return;
        }

        /*
         * Validasi dipasang dengan KUNCI KOORDINAT, bukan dengan
         * `getDataValidationCollection()` yang mengembalikan array
         * baca-saja.
         *
         * Kuncinya adalah rentang kolom, jadi dua kolom berbeda punya
         * dua entri terpisah dan keduanya bertahan. Versi sebelumnya
         * mengira worksheet hanya menyimpan satu validasi terakhir --
         * dan kalau begitu, kolom kedua diam-diam menimpa kolom
         * pertama, dan file yang dihasilkan hanya punya dropdown di
         * kolom terakhir. Tidak ada error, file tetap terbuka normal,
         * dan yang hilang cuma dropdown di kolom lain.
         */
        $objek = new DataValidation;
        $objek->setType($validasi['tipe']);
        $objek->setShowErrorMessage(true);
        $objek->setErrorStyle(DataValidation::STYLE_STOP);
        $objek->setErrorTitle('Nilai tidak sesuai');
        $objek->setError($this->pesanValidasi($definisi));

        if ($validasi['tipe'] === DataValidation::TYPE_LIST) {
            $objek->setFormula1('"'.implode(',', $validasi['pilihan']).'"');
            $objek->setAllowBlank(! $definisi['required']);
        } else {
            $objek->setOperator(DataValidation::OPERATOR_BETWEEN);
            $objek->setFormula1((string) $validasi['min']);
            $objek->setFormula2((string) $validasi['max']);
            $objek->setAllowBlank(true);
        }

        // `setDataValidation()` yang mengisi sqref dari koordinat,
        // jadi tidak perlu `setSqref()` terpisah di sini.
        $sheet->setDataValidation($rentang, $objek);
    }

    /** @return array{tipe: string, pilihan?: array<int,string>, min?: int, max?: int}|null */
    private function validasiKolom(array $definisi): ?array
    {
        $tipe = $definisi['type'] ?? '';

        if ($tipe === 'enum' && ! empty($definisi['options'])) {
            $daftar = array_values($definisi['options']);

            // Terlalu panjang untuk dropdown inline -> biar bebas. Nilai
            // tetap divalidasi di server, dan penolakannya akan menyebut
            // pilihannya.
            if (strlen(implode(',', $daftar)) > self::MAKS_DROPDOWN) {
                return null;
            }

            return ['tipe' => DataValidation::TYPE_LIST, 'pilihan' => $daftar];
        }

        if ($tipe === 'integer') {
            return [
                'tipe' => DataValidation::TYPE_WHOLE,
                'min' => 0,
                'max' => $definisi['max'] ?? 2_000_000_000,
            ];
        }

        return null;
    }

    private function pesanValidasi(array $definisi): string
    {
        if (($definisi['type'] ?? '') === 'enum') {
            return 'Pilih salah satu dari daftar: '.implode(', ', $definisi['options'] ?? []).'.';
        }

        return $definisi['help'];
    }

    /* ================================================================
     | SHEET PETUNJUK
     |============================================================
     */

    /**
     * Sheet kedua: penjelasan kolom per kolom.
     *
     * Baris tabel kolom TIDAK ditulis dengan angka tetap. Versi
     * sebelumnya menulis 27 baris statis lalu menghitung baris awal tabel
     * sebagai angka tetap -- jadi menambah satu baris penjelasan saja akan
     * menimpa judul tabel dengan isi kolom pertama, dan tidak ada yang
     * memberi tanda di file yang sudah terlanjur diunduh ke komputer user.
     * Sekarang semua penomoran dihitung dari isi yang benar-benar ditulis.
     */
    private function isiSheetPetunjuk(Spreadsheet $spreadsheet, MigrationBlueprint $blueprint): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Petunjuk');

        $baris = [];

        $baris[] = ['CARA PAKAI', ''];
        $baris[] = ['', ''];

        $langkah = [
            'Unduh template ini. Jangan mengubah nama sheet "'.$blueprint->sheetName().'".',
            'Isi data pada sheet itu. Baris pertama HARUS tetap berupa nama kolom.',
            'Hapus baris contoh (baris 2 yang kolom pertamanya bertanda CONTOH).',
            'Baris yang kosong di tengah file diabaikan -- tidak perlu dihapus.',
            'Simpan file, lalu unggah kembali di halaman Migrasi Data.',
        ];

        foreach ($langkah as $i => $teks) {
            $baris[] = [(string) ($i + 1).'.', $teks];
        }

        $baris[] = ['', ''];
        $baris[] = ['', 'Kalau hasil migrasi sebagian gagal, halaman hasil akan menyebutkan baris mana'];
        $baris[] = ['', 'dan alasannya. Perbaiki baris itu di file yang sama, lalu unggah ulang.'];
        $baris[] = ['', 'Data yang sudah berhasil tidak akan terduplikasi: baris dengan kunci yang'];
        $baris[] = ['', 'sama diperbarui, bukan ditambahkan lagi.'];
        $baris[] = ['', ''];

        $barisAturanMulai = count($baris) + 1;
        $baris[] = ['ATURAN YANG BERLAKU UNTUK SEMUA KOLOM', ''];
        $baris[] = ['', ''];

        $aturanUmum = [
            'Nomor boleh memakai pemisah ribuan: 1.500, 1,500, atau 1 500 semuanya berarti 1500.',
            'Angka desimal boleh memakai koma atau titik: 12,5 dan 12.5 sama.',
            'Tanggal bisa ditulis 2026-02-01, 01-02-2026, atau 01/02/2026 (hari/bulan/tahun).',
            'Tanda -, N/A, kosong, dan "tidak ada" dianggap kolom kosong, bukan teks.',
            'NIK dan kolom kode harus diketik sebagai teks -- kolomnya sudah diformat begitu',
            'supaya nol di depan tidak hilang (mis. 0812... jadi 812...).',
        ];

        foreach ($aturanUmum as $teks) {
            $baris[] = ['-', $teks];
        }

        $baris[] = ['', ''];

        $barisKolom = count($baris) + 1;
        $baris[] = ['KETERANGAN KOLOM', ''];
        $baris[] = ['', ''];
        $baris[] = ['Kolom', 'Wajib?', 'Aturan', 'Contoh'];

        $sheet->fromArray($baris, null, 'A1');

        $sheet->getStyle('A'.$barisAturanMulai)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A'.$barisKolom)->getFont()->setBold(true)->setSize(12);

        // Tabel kolom per kolom.
        $barisTabel = $barisKolom + 1;

        foreach ($blueprint->columns() as $definisi) {
            $sheet->fromArray([
                $definisi['label'],
                $definisi['required'] ? 'YA' : 'tidak',
                $definisi['help'],
                $definisi['example'],
            ], null, 'A'.$barisTabel);

            $barisTabel++;
        }

        $this->gayaHeader($sheet, 4, $barisKolom + 1);
        $sheet->getStyle('A'.($barisKolom + 1).':D'.($barisKolom + 1))->getFont()
            ->setBold(true);

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->getColumnDimension('A')->setWidth(26);
        $sheet->getColumnDimension('B')->setWidth(10);
        $sheet->getColumnDimension('C')->setWidth(62);
        $sheet->getColumnDimension('D')->setWidth(24);

        // Bungkus teks di kolom Aturan supaya tidak terpotong di layar.
        $sheet->getStyle('A1:D'.max($barisTabel, count($baris)))->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);
    }

    /* ================================================================
     | SHEET REFERENSI
     |============================================================
     */

    /**
     * Sheet ketiga: nilai yang harus dipilih, dibaca dari lokasi.
     *
     * Sheet ini kosong dengan sengaja kalau blueprint tidak punya
     * referensi -- dan `Petunjuk` akan bilang begitu, supaya user tidak
     * mencari daftar yang memang tidak ada.
     */
    private function isiSheetReferensi(Spreadsheet $spreadsheet, MigrationBlueprint $blueprint): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Referensi');

        $referensi = method_exists($blueprint, 'references') ? $blueprint->references() : [];

        if ($referensi === []) {
            $sheet->fromArray([
                ['Tidak ada daftar referensi untuk data ini.'],
                [],
                ['Semua kolom diisi bebas sesuai format yang tertulis di sheet "Petunjuk".'],
            ], null, 'A1');

            $sheet->getStyle('A1')->getFont()->setBold(true);
            $sheet->getColumnDimension('A')->setWidth(70);

            return;
        }

        $baris = 1;

        foreach ($referensi as $judul => $daftar) {
            $sheet->fromArray([(string) strtoupper((string) $judul)], null, 'A'.$baris);
            $sheet->getStyle('A'.$baris)->getFont()->setBold(true)
                ->getColor()->setRGB('1D4ED8');
            $baris++;

            foreach ($daftar as $nilai) {
                $sheet->fromArray([(string) $nilai], null, 'A'.$baris);
                $baris++;
            }

            $baris++; // Baris kosong sebagai pemisah antar daftar.
        }

        $sheet->fromArray(
            ['', 'Salin nilai persis seperti tertulis di atas. Nama yang beda sedikit akan ditolak saat validasi.'],
            null,
            'A'.($baris + 1)
        );

        $sheet->getColumnDimension('A')->setWidth(44);
    }

    /* ================================================================
     | GAYA
     |============================================================
     */

    /**
     * Gaya header tabel.
     *
     * Menerima WORKSHEET, bukan `Spreadsheet`.
     *
     * Versi sebelumnya menerima `Spreadsheet` dan memanggil
     * `getActiveSheet()` di dalamnya -- yang selalu mengembalikan sheet
     * `Data`, yaitu sheet pertama yang dibuat. Jadi `gayaHeader()` yang
     * dimaksudkan untuk header tabel di sheet `Petunjuk` sebenarnya
     * menimpa baris 13 sheet `Data`. File yang dihasilkan tetap terbuka
     * normal di Excel dan tidak ada keluhan apa pun; hanya format yang
     * tidak pernah sampai ke tempatnya, dan tidak ada yang mengetahuinya
     * selain orang yang membuka file itu.
     */
    private function gayaHeader(Worksheet $sheet, int $jumlahKolom, int $baris): void
    {
        $sheet->getStyle($this->rentang($jumlahKolom, $baris))->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF0F172A'],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->getRowDimension($baris)->setRowHeight(28);
    }

    private function gayaBorder(Worksheet $sheet, int $jumlahKolom, int $baris): void
    {
        $sheet->getStyle($this->rentang($jumlahKolom, $baris))->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFE2E8F0'],
                ],
            ],
        ]);
    }

    private function rentang(int $jumlahKolom, int $baris): string
    {
        return 'A'.$baris.':'.Coordinate::stringFromColumnIndex($jumlahKolom).$baris;
    }
}
