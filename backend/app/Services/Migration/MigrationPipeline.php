<?php

namespace App\Services\Migration;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menjalankan migrasi dari file yang sudah diunggah.
 *
 * ALURNYA, DAN MENGAPA DIPISAH DUA TAHAP
 * ======================================
 *
 * Tahap 1 (validasi) membaca file, memetakan tiap baris, dan berhenti
 * sebelum menyentuh database lokasi. Tahap 2 (commit) menulis apa yang
 * tadi lolos validasi.
 *
 * Pemisahan ini bukan karena formalitas: kalau penulisan langsung terjadi
 * sambil memvalidasi, satu baris yang gagal di baris 900 membuat
 * superadmin tidak tahu apakah 899 baris sebelumnya sudah tersimpan.
 * Tanpa pemisahan, "migrasi gagal" tidak bisa dibedakan dari "migrasi
 * separuh jalan" -- dan yang kedua jauh lebih berbahaya, karena terlihat
 * berhasil di toast.
 *
 * HASIL VALIDASI DISIMPAN DI FILE, BUKAN DI CACHE ATAU DATABASE
 * ============================================================
 *
 * Kedengarannya berlebihan untuk "cuma" menyimpan baris yang sudah
 * dipetakan, tapi tiga alternatifnya masing-masing punya masalah nyata di
 * lingkungan aplikasi ini:
 *
 *   - CACHE. `CACHE_STORE=database`, jadi setiap `Cache::put()` adalah
 *     satu INSERT. Yang lebih buruk: blueprint dijalankan DI DALAM
 *     `TenantManager::runFor()`, yang mengganti `database.default` ke
 *     database lokasi -- sehingga hasil validasi tiap lokasi akan
 *     ditulis ke database lokasi itu. Baris validasi jadi hilang
 *     bersama cache-nya, dan tidak ada tempat sentral untuk melihat
 *     jejak migrasi yang pernah terjadi.
 *
 *   - TABEL BARU DI PUSAT. Butuh migration di `database/migrations_central/`
 *     yang punya aturan sendiri (`tenancy:migrate-central`) dan tidak
 *     bisa ikut jalan pada `migrate` biasa. Satu fitur akan menambah satu
 *     langkah operasional di server produksi.
 *
 *   - KIRIM ULANG FILENYA. Frontend harus menyimpan file 5 MB selama
 *     superadmin hesitate di halaman konfirmasi, dan memakai FormData
 *     lagi hanya untuk commit berarti ada dua kali waktu unggah.
 *
 * File sementara di `storage/app/private/migrasi/` menyelesaikan ketiganya:
 * tidak butuh tabel, tidak butuh cache yang salah-koneksi, dan tidak ada
 * file besar yang harus dikirim dua kali. File dihapus begitu commit
 * selesai atau lewat masa berlaku -- bukan "nanti saja", karena file
 * hasil migrasi memuat NIK dan nama lengkap orang.
 *
 * BATCH
 * =====
 *
 * Penulisan dipecah per 50 baris dalam satu transaksi. Alasannya bukan
 * performa, tapi cermin dari apa yang terjadi kalau tidak dipecah: satu
 * transaksi untuk 5.000 baris berarti 5.000 baris yang terkunci sampai
 * selesai, dan kalau salah satu gagal di baris 4.900, seluruh 4.900
 * dibatalkan -- termasuk satu jam kerja.
 *
 * Dengan batch, baris yang berhasil tetap tersimpan, dan hasil akhirnya
 * bisa jujur: "4.850 berhasil, 150 gagal, perinciannya di sini".
 *
 * TIDAK ADA PROGRESS YANG DI-BATCH
 * =================================
 *
 * `commit()` berjalan sinkron di dalam satu request dan mengembalikan
 * hasilnya sekaligus. Tidak ada antrean pekerjaan di aplikasi ini --
 * `app/Jobs/` tidak ada dan `queue` tidak dipakai -- jadi tidak ada yang
 * bisa dipolling dari request terpisah.
 *
 * Pembacaan file SANGAT lambat untuk beberapa ribu baris, tapi commit
 * tidak: isinya sudah dipetakan dan sudah divalidasi, jadi yang tersisa
 * hanya INSERT. Untuk 5.000 baris itu beberapa detik, bukan beberapa
 * menit. Kalau nanti memang butuh lebih cepat, `MigrationController`
 * adalah tempatnya -- bukan di sini, karena di sini tidak ada mekanisme
 * apa pun yang bisa melaporkan apa pun yang belum ada.
 */
class MigrationPipeline
{
    /** Masa berlaku file kerja, dalam detik. 30 menit cukup untuk review. */
    private const TTL_KERJA = 1800;

    /** Jumlah baris per transaksi. */
    private const UKURAN_BATCH = 50;

    /** Berapa banyak baris yang dikirim ke frontend untuk ditampilkan. */
    private const MAKS_TAMPIL = 500;

    /** Folder tempat file kerja disimpan, relatif terhadap storage. */
    private const FOLDER = 'migrasi';

    /**
     * Penanda baris contoh di kolom pertama template.
     *
     * PUBLIK karena `TemplateGenerator` menulis penanda yang PERSIS
     * sama di kolom pertama sheet `Data`. Dua konstanta terpisah untuk
     * satu nilai yang harus sama akan tetap sama sampai salah satu
     * diubah -- dan gejalanya bukan error, melainkan baris contoh ikut
     * terimpor sebagai data sungguhan.
     */
    public const PENANDA_CONTOH = 'CONTOH';

    public function __construct(private TenantManager $manager) {}

    /* ================================================================
     | TAHAP 1 -- VALIDASI
     |============================================================
     */

    /**
     * Baca file, petakan tiap baris, simpan hasilnya sebagai file kerja.
     *
     * @return array{
     *     hash: string,total: int,siap: int,gagal: int,baru: int,
     *     diperbarui: int,baris: array<int, array<string, mixed>>,
     *     dipotong: int
     * }
     */
    public function validate(MigrationBlueprint $blueprint, Tenant $tenant, string $isi, string $ekstensi): array
    {
        $terbaca = SpreadsheetReader::bacaDariIsi($isi, $ekstensi, $blueprint->sheetName());

        $header = $terbaca['header'];
        $baris = $terbaca['baris'];

        $petakanKolom = $this->cocokkanHeader($header, $blueprint);

        /*
         * HEADER TIDAK COCOK = FILE SALAH, BUKAN "SEMUA KOLOM KOSONG".
         *
         * Kalau dibiarkan, setiap baris akan ditolak dengan "Kolom NIK
         * wajib diisi" -- padahal masalahnya file-nya salah yang dibuka,
         * dan pesan itu akan mengirim user untuk mengisi ulang seluruh
         * file. Jadi dicek di sini, dengan pesan yang menyebut kolom apa
         * yang tidak ditemukan dan apa yang HARUS ada.
         */
        $kurang = $this->kolomYangHilang($petakanKolom, $blueprint);

        if ($kurang !== []) {
            throw MigrationRowException::kolomTidakDitemukan($kurang, $blueprint);
        }

        $hasilPerBaris = [];
        $siap = 0;
        $gagal = 0;
        $baru = 0;
        $diperbarui = 0;

        /*
         * Peta "sudah ada" diambil SATU KALI di dalam runFor, lalu dipakai
         * untuk semua baris. Kalau diambil per baris, 5.000 baris berarti
         * 5.000 query `SELECT` penuh tabel -- dan untuk tabel `customers`
         * yang sudah berisi puluhan ribu baris di lokasi yang sudah lama
         * berjalan, itu bukan lagi migrasi, itu kejadian yang membuat
         * server berat.
         */
        $this->manager->runFor($tenant, function () use (
            $baris, $blueprint, $petakanKolom, &$hasilPerBaris,
            &$siap, &$gagal, &$baru, &$diperbarui
        ) {
            $peta = $blueprint->existingIndex();

            foreach ($baris as $nomor => $sel) {
                $penanda = self::penandaBaris($sel);

                // Baris contoh dari template, kalau user belum menghapusnya.
                if ($penanda !== null && Str::contains(Str::upper($penanda), self::PENANDA_CONTOH)) {
                    continue;
                }

                /*
                 * Baris yang seluruh kolom template-nya kosong: dilewati
                 * tanpa dihitung jadi gagal. User sering menyisakan baris
                 * kosong atau baris dengan judul seksi, dan menghitungnya
                 * sebagai error akan membuat halaman hasil selalu merah
                 * padahal tidak ada satu pun masalah sebenarnya.
                 */
                if ($this->barisKosong($sel, $petakanKolom)) {
                    continue;
                }

                $nilai = $this->ambilNilai($sel, $petakanKolom);

                try {
                    $data = $blueprint->mapRow($nilai, $nomor);

                    $konflik = $this->cocokkanKonflik($data, $peta);

                    $hasilPerBaris[] = [
                        'nomor' => $nomor,
                        'status' => 'siap',
                        'aksi' => $konflik === [] ? 'baru' : 'diperbarui',
                        'id' => $konflik === [] ? null : $konflik['id'],
                        'kunci' => $konflik === [] ? null : $konflik['nilai'],
                        'data' => $data,
                        'pesan' => null,
                        'kolom' => [],
                        'kode' => null,
                        // `$nilai` (mentah dari file), bukan `$data`
                        // (hasil `mapRow()`): preview menampilkan yang
                        // diketik user, dan kunci kolomnya harus sama
                        // dengan baris yang ditolak. Lihat `ringkas()`.
                        'ringkas' => $this->ringkas($nilai, $blueprint),
                    ];

                    $siap++;
                    $konflik === [] ? $baru++ : $diperbarui++;
                } catch (MigrationRowException $e) {
                    $hasilPerBaris[] = [
                        'nomor' => $nomor,
                        'status' => 'gagal',
                        'aksi' => null,
                        'id' => null,
                        'kunci' => null,
                        'data' => $nilai,
                        'pesan' => $e->getMessage(),
                        'kolom' => $e->kolom(),
                        'kode' => $e->kode(),
                        'ringkas' => $this->ringkas($nilai, $blueprint),
                    ];

                    $gagal++;
                } catch (Throwable $e) {
                    /*
                     * Galat di luar perkiraan (mis. PDOException dari query
                     * referensi) dilaporkan per baris juga, bukan membuat
                     * seluruh upload gagal -- supaya user melihat baris
                     * mana yang bermasalah, bukan satu error global.
                     */
                    report($e);

                    $hasilPerBaris[] = [
                        'nomor' => $nomor,
                        'status' => 'gagal',
                        'aksi' => null,
                        'id' => null,
                        'kunci' => null,
                        'data' => $nilai,
                        'pesan' => 'Gagal diproses: '.$e->getMessage(),
                        'kolom' => [],
                        'kode' => 'ERROR_INTERNAL',
                        'ringkas' => $this->ringkas($nilai, $blueprint),
                    ];

                    $gagal++;
                }
            }
        });

        $hash = Str::random(40);

        $this->simpanKerja($hash, [
            'type' => $blueprint->type(),
            'tenant' => $tenant->slug,
            'location_id' => $tenant->id,
            'created_at' => now()->toIso8601String(),
            'rows' => $hasilPerBaris,
        ]);

        $tampil = $this->potongUntukTampilan($hasilPerBaris);

        return [
            'hash' => $hash,
            'total' => count($hasilPerBaris),
            'siap' => $siap,
            'gagal' => $gagal,
            'baru' => $baru,
            'diperbarui' => $diperbarui,
            'baris' => $tampil['baris'],
            // Berapa baris yang TIDAK ikut dikirim ke tabel preview.
            'disembunyikan' => $tampil['disembunyikan'],
        ];
    }

    /* ================================================================
     | TAHAP 2 -- COMMIT
     |============================================================
     */

    /**
     * Tulis baris yang lolos validasi, per batch.
     *
     * File kerja dihapus sebelum method ini selesai -- bukan setelah commit
     * berikutnya. Praktisnya tidak ada bedanya untuk pemanggil, tapi
     * membuat "file ini masih bisa di-commit ulang" mustahil terjadi
     * hanya karena ada satu baris kode yang terlewat.
     *
     * @return array{
     *     ok: bool,total: int,berhasil: int,gagal: int,
     *     detail: array<int, array{nomor:int, pesan:string, kolom:array}>
     * }
     */
    public function commit(
        MigrationBlueprint $blueprint,
        Tenant $tenant,
        string $hash,
    ): array {
        $kerja = $this->bacaKerja($hash);

        if ($kerja === null) {
            return $this->hasilSisih(
                'Sesi migrasi sudah berakhir atau file sudah dibersihkan. '
                .'Silakan unggah file-nya sekali lagi.'
            );
        }

        /*
         * Batch TIDAK boleh diproses ulang untuk tenant yang berbeda.
         *
         * Hash disimpan tanpa nama lokasi, jadi ada satu kasus yang harus
         * dijaga: file divalidasi untuk Lokasi A, lalu commit dikirim ke
         * Lokasi B. Tanpa pemeriksaan ini, data pelanggan Lokasi A akan
         * ditulis ke database Lokasi B -- dan karena kode itu unik per
         * lokasi tapi TIDAK unik lintas lokasi, tidak ada konflik yang
         * muncul. Kerusakannya baru ketahuan saat Lokasi B mulai
         * menerbitkan tagihan ke pelanggan yang tidak pernah ada.
         */
        if (($kerja['location_id'] ?? null) !== $tenant->id) {
            return $this->hasilSisih(
                'File ini divalidasi untuk lokasi lain. '
                .'Unggah ulang di lokasi yang benar.'
            );
        }

        if (($kerja['type'] ?? null) !== $blueprint->type()) {
            return $this->hasilSisih(
                'Jenis data file ini tidak cocok dengan yang dipilih.'
            );
        }

        $rows = array_values(array_filter(
            $kerja['rows'],
            fn (array $r) => ($r['status'] ?? '') === 'siap'
        ));

        $total = count($rows);

        if ($total === 0) {
            $this->hapusKerja($hash);

            return [
                'ok' => true,
                'total' => 0,
                'berhasil' => 0,
                'gagal' => 0,
                'detail' => [],
            ];
        }

        $berhasil = 0;
        $detail = [];

        $this->manager->runFor($tenant, function () use (
            $rows, $blueprint, &$berhasil, &$detail
        ) {
            foreach (array_chunk($rows, self::UKURAN_BATCH) as $batch) {
                /*
                 * Dua tingkat transaksi, bukan satu.
                 *
                 * Yang mengarahkan semua ini: SAVEPOINT PER BARIS.
                 *
                 * `DB::transaction()` yang dipanggil di dalam transaksi
                 * lain membuat savepoint, bukan transaksi baru. Jadi baris
                 * yang gagal bisa dibatalkan SENDIRI: akun yang sudah
                 * dibuat untuk baris itu ikut hilang, tapi 49 baris lain
                 * di blok yang sama tetap tersimpan.
                 *
                 * Tanpa savepoint, satu email yang salah ketik
                 * menghilangkan 49 pelanggan yang tidak salah apa pun --
                 * dan pesannya menempel ke semuanya, jadi user tidak
                 * tahu harus memperbaiki baris yang mana. Itu bukan
                 * "gagal dengan pesan jelas", itu 49 kegagalan palsu.
                 *
                 * Transaksi LUAR tetap ada, dan tetap berguna: kalau
                 * yang gagal bukan isi file tapi sistemnya (koneksi
                 * putus, tabel berubah, deadlock), seluruh blok dibatal
                 * supaya tidak ada data separuh -- akun tanpa pelanggan,
                 * paket tanpa blok tarif.
                 */
                $tersimpan = 0;
                $gagalBatch = [];

                try {
                    DB::transaction(function () use ($batch, $blueprint, &$tersimpan, &$gagalBatch) {
                        foreach ($batch as $row) {
                            try {
                                DB::transaction(function () use ($blueprint, $row) {
                                    $blueprint->persist(
                                        $row['data'],
                                        [
                                            'id' => $row['id'] ?? null,
                                            'nilai' => $row['kunci'] ?? null,
                                        ],
                                        $row['nomor']
                                    );
                                });

                                $tersimpan++;
                            } catch (Throwable $e) {
                                /*
                                 * `MigrationRowException` dibuat justru
                                 * untuk ini: pesannya sudah ditulis
                                 * supaya aman dibaca user, dan menyebut
                                 * kolom yang salah.
                                 *
                                 * Exception lain berarti ada yang tidak
                                 * terduga -- koneksi, locking, trigger.
                                 * SQL-nya tidak boleh bocor ke layar
                                 * user, jadi pesannya diganti; aslinya
                                 * tetap masuk log untuk dibaca
                                 * developer lewat `report()`.
                                 */
                                $dikenal = $e instanceof MigrationRowException;

                                if (! $dikenal) {
                                    report($e);
                                }

                                $gagalBatch[] = [
                                    'nomor' => $row['nomor'],
                                    'pesan' => $dikenal
                                        ? $e->getMessage()
                                        : 'Baris ini gagal disimpan karena kesalahan pada sistem, '
                                            .'bukan karena isinya. Coba lagi; bila tetap gagal, '
                                            .'hubungi administrator.',
                                    'kolom' => $dikenal ? $e->kolom() : [],
                                ];
                            }
                        }
                    });
                } catch (Throwable $e) {
                    /*
                     * Yang sampai ke sini bukan dari `persist()` --
                     * karena kegagalan itu sudah ditangkap per baris di
                     * atas. Jadi penyebabnya benar-benar di luar file,
                     * dan membatalkan seluruh blok adalah pilihan yang
                     * benar: tanpa itu, separuh blok bisa tersimpan
                     * tanpa bagian pasangannya.
                     */
                    report($e);

                    $tersimpan = 0;
                    $gagalBatch = [];

                    foreach ($batch as $row) {
                        $gagalBatch[] = [
                            'nomor' => $row['nomor'],
                            'pesan' => 'Seluruh blok ini gagal disimpan karena kesalahan pada sistem '
                                .'(bukan karena isi file). Tidak ada data yang tersimpan dari blok '
                                .'ini. Coba lagi dalam beberapa saat.',
                            'kolom' => [],
                        ];
                    }
                }

                $berhasil += $tersimpan;

                foreach ($gagalBatch as $satu) {
                    $detail[] = $satu;
                }
            }
        });

        // File kerja sudah tidak berguna setelah commit, dan isinya memuat
        // NIK. Menghapus sekarang berarti file unggahan user tidak tertinggal
        // di disk lebih lama dari yang perlu.
        $this->hapusKerja($hash);

        return [
            'ok' => true,
            'total' => $total,
            'berhasil' => $berhasil,
            'gagal' => count($detail),
            'detail' => array_slice($detail, 0, self::MAKS_TAMPIL),
            'semuaDetail' => count($detail),
        ];
    }

    /* ================================================================
     | FILE KERJA
     |============================================================
     */

    /**
     * @param  array<string, mixed>  $data
     */
    private function simpanKerja(string $hash, array $data): void
    {
        $path = $this->pathKerja($hash);

        $this->pastikanFolder();

        // Ditulis sebagai file sementara lalu di-rename, supaya file kerja
        // yang sudah ada tidak pernah dalam keadaan setengah tertulis --
        // kalau request mati di tengah, yang tertinggal bukan file rusak
        // yang akan dibaca sebagai data valid.
        $sementara = $path.'.tmp';

        file_put_contents(
            $sementara,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        rename($sementara, $path);

        /*
         * Masa berlaku TIDAK diperhatikan di sini. File dihapus saat
         * commit, dan file yang tidak pernah di-commit dihapus oleh
         * `bersihkanKedaluwarsa()` yang dipanggil dari scheduler --
         * bukan oleh penomoran di sini, yang hanya akan berlaku kalau
         * ada proses lain yang benar-benar memanggilnya.
         */
    }

    /** @return array<string, mixed>|null */
    private function bacaKerja(string $hash): ?array
    {
        $path = $this->pathKerja($hash);

        if (! is_file($path)) {
            return null;
        }

        $isi = file_get_contents($path);

        if ($isi === false) {
            return null;
        }

        $data = json_decode($isi, true);

        return is_array($data) ? $data : null;
    }

    private function hapusKerja(string $hash): void
    {
        $path = $this->pathKerja($hash);

        if (is_file($path)) {
            @unlink($path);
        }

        if (is_file($path.'.tmp')) {
            @unlink($path.'.tmp');
        }
    }

    private function pathKerja(string $hash): string
    {
        // Hash sudah diacak 40 karakter alfanumerik, tapi tetap dibatasi
        // supaya path-nya tidak bisa keluar dari folder kerja.
        $hash = preg_replace('/[^a-z0-9]/i', '', $hash) ?? '';

        return storage_path('app/private/'.self::FOLDER.'/kerja-'.$hash.'.json');
    }

    private function pastikanFolder(): void
    {
        $folder = storage_path('app/private/'.self::FOLDER);

        if (! is_dir($folder)) {
            @mkdir($folder, 0700, true);
        }
    }

    /**
     * Hapus file kerja yang sudah lewat masa berlaku.
     *
     * Dipanggil oleh command `migrasi:bersihkan`. Returns jumlah file yang
     * dihapus supaya command bisa melaporkannya di log.
     */
    public function bersihkanKedaluwarsa(): int
    {
        $folder = storage_path('app/private/'.self::FOLDER);

        if (! is_dir($folder)) {
            return 0;
        }

        $batas = time() - self::TTL_KERJA;
        $dihapus = 0;

        foreach (glob($folder.'/kerja-*.json*') ?: [] as $file) {
            if (filemtime($file) < $batas) {
                @unlink($file);
                $dihapus++;
            }
        }

        return $dihapus;
    }

    /* ================================================================
     | PENCOCOKAN HEADER DAN BARIS
     |============================================================
     */

    /**
     * Cocokkan nama kolom di file dengan kolom template.
     *
     * Yang diterima selain persis sama: huruf besar/kecil, spasi
     * berlebih, garis bawah vs spasi, dan tanda baca yang biasa diketik
     * orang. Tanpa ini, file yang header-nya "Kode Pelanggan" ditulis
     * dengan bentuk sedikit berbeda akan ditolak padahal isinya benar --
     * dan pesan errornya berbunyi "kolom tidak ditemukan" yang sama
     * sekali tidak membantu.
     *
     * @param  array<int, string>  $header
     * @return array<int, string> indeks kolom di file => key kolom template
     */
    private function cocokkanHeader(array $header, MigrationBlueprint $blueprint): array
    {
        $definisi = array_column($blueprint->columns(), null, 'key');
        $terpetakan = [];

        foreach ($header as $indeks => $nama) {
            $normal = self::normalisasi($nama);

            if ($normal === '') {
                continue;
            }

            foreach ($definisi as $key => $col) {
                if (self::normalisasi($col['label']) === $normal
                    || self::normalisasi($key) === $normal) {
                    $terpetakan[$indeks] = $key;
                    break;
                }
            }
        }

        return $terpetakan;
    }

    /**
     * Kolom wajib yang tidak ada di header file.
     *
     * @param  array<int, string>  $petaman  indeks file => key template
     * @return array<int, string> label kolom yang hilang
     */
    private function kolomYangHilang(array $petaman, MigrationBlueprint $blueprint): array
    {
        $kunciAda = array_values(array_unique(array_map('strval', array_values($petaman))));

        $hilang = [];

        foreach ($blueprint->columns() as $col) {
            if ($col['required'] && ! in_array($col['key'], $kunciAda, true)) {
                $hilang[] = $col['label'];
            }
        }

        return $hilang;
    }

    /** Samakan bentuk teks: lowercase, tanpa spasi/garis/tanda baca. */
    private static function normalisasi(?string $teks): string
    {
        if ($teks === null) {
            return '';
        }

        $teks = mb_strtolower(trim($teks));
        $teks = preg_replace('/[^a-z0-9]+/', '', $teks) ?? '';

        return $teks;
    }

    /**
     * @param  array<int, string|null>  $sel
     * @param  array<int, string>  $petakan  indeks file => key template
     * @return array<string, string|null>
     */
    private function ambilNilai(array $sel, array $petaman = []): array
    {
        $nilai = [];

        foreach ($petaman as $indeks => $key) {
            $nilai[$key] = CellValue::kosongkan($sel[$indeks] ?? null);
        }

        return $nilai;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $peta
     * @return array{id: int, nilai: string}|array{}
     */
    private function cocokkanKonflik(array $data, array $peta): array
    {
        $cari = $data['__kunci'] ?? null;

        if ($cari === null || $peta === []) {
            return [];
        }

        $cari = (string) $cari;

        if (! isset($peta[$cari])) {
            return [];
        }

        $entri = $peta[$cari];

        return [
            'id' => is_array($entri) ? (int) $entri[0] : (int) $entri,
            'nilai' => $cari,
        ];
    }

    /**
     * @param  array<int, string|null>  $sel
     * @param  array<int, string>  $petaman
     */
    private function barisKosong(array $sel, array $petaman): bool
    {
        foreach ($petaman as $indeks => $key) {
            if (CellValue::kosongkan($sel[$indeks] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Teks di kolom paling kiri baris ini, kalau ada isinya.
     *
     * Ini allow-list, bukan filter "kalau tidak kosong". Alasannya:
     * kolom pertama template adalah penanda, BUKAN data -- isinya
     * sengaja dikosongkan untuk semua baris yang benar. Hanya baris
     * contoh dari template yang mengisinya, dan hanya baris itu
     * yang boleh dilewati tanpa dihitung.
     *
     * Kalau ini dibalik jadi "skip kalau kolom pertama kosong",
     * yang terlewat adalah kebalikannya: kolom pertama kosong pada
     * baris data yang normal, sehingga setiap baris ikut terlewat
     * dan hasil migrasi melaporkan "0 baris" untuk file yang jelas
     * berisi data.
     */
    private static function penandaBaris(array $sel): ?string
    {
        $teks = isset($sel[0]) ? trim((string) $sel[0]) : '';

        return $teks === '' ? null : $teks;
    }

    /**
     * Potong baris untuk dikirim ke frontend.
     *
     * Kirim seluruhnya berarti payload bisa puluhan megabyte untuk file
     * besar, dan tabel preview tidak bisa menampilkan 5.000 baris anyways
     * -- jadi yang dikirim adalah 50 baris pertama, 25 terakhir, dan
     * SEMUA baris yang gagal. Baris yang gagal di tengah file harus
     * terlihat; itu justru bagian yang paling perlu diperbaiki.
     *
     * `@return array{baris: array<int, array<string, mixed>>, disembunyikan: int}`
     *   `disembunyikan` penting: preview yang dipotong tanpa memberi tahu
     *   terlihat sama dengan preview yang lengkap, dan orang akan menyimpulkan
     *   file-nya hanya punya baris yang terlihat -- lalu commit tanpa
     *   pernah tahu ada baris lain yang tidak pernah dipratinjau.
     */
    private function potongUntukTampilan(array $rows): array
    {
        $gagal = array_values(array_filter(
            $rows,
            fn (array $r) => ($r['status'] ?? '') === 'gagal'
        ));

        $siap = array_values(array_filter(
            $rows,
            fn (array $r) => ($r['status'] ?? '') === 'siap'
        ));

        $dipilih = [];

        foreach (array_slice($siap, 0, 50) as $row) {
            $dipilih[$row['nomor']] = $row;
        }

        foreach (array_slice($siap, -25) as $row) {
            $dipilih[$row['nomor']] = $row;
        }

        foreach ($gagal as $row) {
            $dipilih[$row['nomor']] = $row;
        }

        ksort($dipilih);

        $terpotong = array_slice($dipilih, 0, self::MAKS_TAMPIL, true);

        return [
            'baris' => array_values(array_map(
                static fn (array $r) => [
                    'nomor' => $r['nomor'],
                    'status' => $r['status'],
                    'aksi' => $r['aksi'],
                    'pesan' => $r['pesan'],
                    'kolom' => $r['kolom'],
                    'kode' => $r['kode'],
                    'ringkas' => $r['ringkas'],
                ],
                $terpotong
            )),
            'disembunyikan' => count($dipilih) - count($terpotong),
        ];
    }

    /**
     * Ringkas isi baris untuk ditampilkan di tabel preview.
     *
     * SELURUH nilai baris ikut, bukan hanya enam pertama.
     *
     * Kenapa tidak dipotong enam:
     *
     * Preview dipakai untuk mencocokkan layar dengan file yang sedang
     * diedit, jadi isinya harus utuh. Memotong enam berarti baris yang
     * salah di kolom ketujuh tidak pernah terlihat -- padahal kolom
     * ketujuh itu yang perlu diperbaiki -- dan tabelnya tetap terlihat
     * benar. Untuk jenis Tiket yang punya 15 kolom, enam pertama selalu
     * kolom yang sama, jadi bagian yang paling relevan justru tidak pernah
     * ditampilkan.
     *
     * SUMBERNYA: BARIS MENTAH DARI FILE, BUKAN HASIL `mapRow()`
     * =====================================================
     *
     * `mapRow()` mengembalikan kolom DATABASE (`village_name`,
     * `initial_meter_reading`, `package_id`). Yang dibutuhkan di sini
     * adalah kolom TEMPLATE (`nama_desa`, `meter_awal`, `paket`) -- dan
     * pasangan itu tidak ada di mana pun: begitu `mapRow()` selesai,
     * kunci template sudah hilang, karena tidak ada yang menyimpan
     * salinannya.
     *
     * Menebak balik dari nama kolom database akan salah secara diam-diam.
     * `village_name` bukan `nama_desa`, dan tebakan terdekat menghasilkan
     * preview yang terisi nilai milik kolom lain -- dan preview yang salah
     * lebih berbahaya daripada preview yang kosong, karena orang
     * mempercayainya.
     *
     * `$nilai` dari {@see ambilNilai()} sudah berkunci kolom template,
     * karena dibangun dari `$petamanKolom` yang nilainya adalah nama kolom
     * template. Jadi baris yang LOLOS dan baris yang DITOLAK memakai sumber
     * yang sama dan muncul di kolom yang sama, tanpa ada yang ditebak --
     * karena `validate()` memanggil `ringkas($nilai, ...)` di kedua cabang.
     *
     * Yang ditampilkan adalah nilai yang diketik user, bukan nilai yang
     * sudah dinormalisasi: `021-1234567` tetap tampil `021-1234567`,
     * bukan `+62211234567`. Yang kedua adalah bentuk yang akan tersimpan,
     * dan membandingkan bentuk tersimpan dengan file aslinya hanya membuat
     * orang mencari selisih yang memang tidak ada.
     *
     * @param  array<string, string|null>  $nilai  key = kolom template
     * @param  MigrationBlueprint  $blueprint
     * @return array<string, string>  key = kolom template, value = teks tampil
     */
    private function ringkas(array $nilai, MigrationBlueprint $blueprint): array
    {
        $out = [];

        foreach ($blueprint->columns() as $col) {
            $out[$col['key']] = CellValue::tampil($nilai[$col['key']] ?? null);
        }

        return $out;
    }


    /**
     * @return array{ok: bool,total: int,berhasil: int,gagal: int,detail: array}
     */
    private function hasilSisih(string $pesan): array
    {
        return [
            'ok' => false,
            'total' => 0,
            'berhasil' => 0,
            'gagal' => 0,
            'detail' => [['nomor' => 0, 'pesan' => $pesan, 'kolom' => []]],
        ];
    }

    /* ================================================================
     | BATASAN
     |============================================================
     */

    /** Batas ukuran file yang diterima, dalam kilobyte. */
    public static function maksKilobyte(): int
    {
        return (int) config('migrasi.maks_kilobyte', 10 * 1024);
    }
}
