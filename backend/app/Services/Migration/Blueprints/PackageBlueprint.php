<?php

namespace App\Services\Migration\Blueprints;

use App\Models\InstallationPackage;
use App\Models\WaterTariffBlock;
use App\Services\Migration\CellValue;
use App\Services\Migration\MigrationBlueprint;
use App\Services\Migration\MigrationBlueprintConcerns;
use App\Services\Migration\MigrationRowException;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi paket instalasi beserta blok tarifnya.
 *
 * SATU BARIS = SATU PAKET DAN BLOK TARNYA DI BAWAHNYA
 * ===================================================
 *
 * Bentuk file: kolom blok tarif diulang untuk setiap blok, jadi satu baris
 * adalah satu blok tarif milik satu paket. Dua blok tarif untuk paket yang
 * sama ditulis sebagai dua baris dengan nama paket yang sama.
 *
 * Bentuk ini dipilih, bukan dua sheet terpisah, karena relasi antar
 * sheet akan hilang begitu file diedit: user memotong baris dari sheet
 * blok tanpa memotong baris paketnya, dan hasilnya paket tanpa tarif --
 * yang tidak bisa dipakai untuk menerbitkan tagihan.
 *
 * Kalau hanya ada satu blok (paket flat), satu baris sudah cukup.
 *
 * ISI PAKET HANYA DITULIS DARI BARIS PERTAMA
 * ===========================================
 *
 * File diurutkan per blok tarif, bukan per paket, jadi baris kedua untuk
 * paket yang sama boleh diisi hanya dengan nama paket dan batas blok.
 * Tanpa penjaga, baris kedua dengan abodemen kosong akan menimpa
 * abodemen paket menjadi 0 -- dan user tidak akan diberi tahu, karena
 * baris kedua memang tidak salah.
 */
class PackageBlueprint implements MigrationBlueprint
{
    use MigrationBlueprintConcerns;

    /**
     * Nama paket yang kolomnya sudah ditulis di file ini.
     *
     * Diisi ulang setiap kali `existingIndex()` dipanggil -- yaitu satu
     * kali per proses, sebelum baris pertama disimpan. Kesalahan paling
     * mungkin di sini adalah state yang bertahan antar-request pada
     * instance yang sama, dan registry membuat instance baru setiap
     * `semua()`, jadi tidak ada jalan yang membuat
     * state itu bocor antar-request.
     */
    private array $sudahDitulis = [];

    /**
     * Nama paket yang sudah ada di lokasi ini, huruf kecil -> id.
     *
     * `null` berarti belum dibangun. Dibangun sekali oleh
     * `existingIndex()`, lalu dibaca oleh `mapRow()` untuk memutuskan
     * apakah baris ini baris pertama paket tersebut atau baris
     * lanjutannya.
     *
     * @var array<string, int>|null
     */
    private ?array $idPaket = null;

    public function type(): string
    {
        return 'packages';
    }

    public function label(): string
    {
        return 'Paket Instalasi';
    }

    public function description(): string
    {
        return 'Paket pemasangan beserta blok tarif pemakaiannya. Satu baris = satu blok '
            .'tarif. Paket dengan beberapa blok ditulis beberapa baris.';
    }

    public function icon(): string
    {
        return 'boxes';
    }

    public function order(): int
    {
        return 20;
    }

    public function sheetName(): string
    {
        return 'Paket';
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'nama_paket',
                'label' => 'Nama Paket',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama paket. Baris berikutnya dengan nama yang sama dianggap '
                    .'SATU paket dengan blok tarif tambahan.',
                'example' => 'Rumah Tangga 10',
            ],
            [
                'key' => 'biaya_pasang',
                'label' => 'Biaya Pasang',
                'type' => 'decimal',
                'required' => true,
                'help' => 'Biaya pemasangan sekali bayar, dalam rupiah.',
                'example' => '750000',
            ],
            [
                'key' => 'abodemen',
                'label' => 'Abodemen',
                'type' => 'decimal',
                'required' => false,
                'help' => 'Iuran tetap per bulan, dalam rupiah. Boleh 0.',
                'example' => '25000',
            ],
            [
                'key' => 'denda',
                'label' => 'Denda Keterlambatan',
                'type' => 'decimal',
                'required' => false,
                'help' => 'Denda per bulan telat bayar, dalam rupiah. Boleh 0.',
                'example' => '5000',
            ],
            [
                'key' => 'pemakaian_min',
                'label' => 'Batas Bawah (m3)',
                'type' => 'integer',
                'required' => false,
                'help' => 'Batas bawah pemakaian untuk blok ini. Untuk blok pertama biasanya 0.',
                'example' => '0',
            ],
            [
                'key' => 'pemakaian_maks',
                'label' => 'Batas Atas (m3)',
                'type' => 'integer',
                'required' => false,
                'help' => 'Batas atas pemakaian. Kosongkan hanya untuk blok terakhir '
                    .'(pemakaian di atas batas bawah tidak dibatasi).',
                'example' => '10',
            ],
            [
                'key' => 'harga_per_m3',
                'label' => 'Harga per m3',
                'type' => 'decimal',
                'required' => false,
                'help' => 'Tarif per meter kubik untuk blok ini. Wajib diisi kalau Batas Bawah diisi.',
                'example' => '3500',
            ],
        ];
    }

    public function tables(): array
    {
        return ['installation_packages', 'water_tariff_blocks'];
    }

    public function mapRow(array $row, int $nomorUrut): array
    {
        $nama = CellValue::kosongkan($row['nama_paket'] ?? null);

        if ($nama === null) {
            throw MigrationRowException::kosong('Nama Paket');
        }

        if (mb_strlen($nama) > 100) {
            throw MigrationRowException::tidakValid('Nama Paket', $nama, 'Maksimal 100 karakter');
        }

        $min = CellValue::integer($row['pemakaian_min'] ?? null);
        $maks = CellValue::integer($row['pemakaian_maks'] ?? null);
        $harga = CellValue::decimal($row['harga_per_m3'] ?? null);
        $pasang = CellValue::decimal($row['biaya_pasang'] ?? null);

        /*
         * KOLOM PAKET TIDAK WAJIB DI SEMUA BARIS
         * =======================================
         *
         * Satu baris = satu blok tarif milik satu paket. Baris kedua
         * untuk paket yang sama adalah blok tarif BERIKUTNYA, jadi
         * biaya pasang dan abodemen di baris itu memang tidak ada --
         * nilainya sudah ditulis di baris pertama. Memintanya lagi
         * membuat baris lanjutan ditolak, dan format yang
         * didokumentasikan di sheet `Petunjuk` jadi tidak bisa dipakai.
         *
         * Yang tetap dijaga: baris PERTAMA untuk setiap paket baru
         * harus punya biaya pasang. Tanpa itu, paket tercipta tanpa
         * harga yang bisa ditagihkan.
         */
        $paketBaru = ! isset($this->sudahDitulis[mb_strtolower($nama)]) && ! $this->paketAda($nama);
        $tanpaBiaya = $pasang === null;

        if ($tanpaBiaya) {
            if (! $paketBaru) {
                // Baris lanjutan: biayanya milik baris pertama.
                $pasang = 0.0;
            } else {
                throw MigrationRowException::kosong('Biaya Pasang');
            }
        }

        if ($pasang < 0) {
            throw MigrationRowException::tidakValid(
                'Biaya Pasang',
                CellValue::tampil($pasang),
                'Tidak boleh negatif'
            );
        }

        if ($min !== null && $min < 0) {
            throw MigrationRowException::tidakValid('Batas Bawah (m3)', (string) $min, 'Tidak boleh negatif');
        }

        if ($maks !== null && $min !== null && $maks < $min) {
            throw MigrationRowException::tidakValid(
                'Batas Atas (m3)',
                (string) $maks,
                "Tidak boleh lebih kecil dari Batas Bawah ({$min})"
            );
        }

        if ($min !== null && $harga === null) {
            throw MigrationRowException::kosong('Harga per m3');
        }

        return [
            'name' => $nama,
            'installation_fee' => round($pasang, 2),
            'monthly_abodemen' => round(CellValue::decimal($row['abodemen'] ?? null) ?? 0, 2),
            'late_penalty' => round(CellValue::decimal($row['denda'] ?? null) ?? 0, 2),
            'usage_min_m3' => $min,
            'usage_max_m3' => $maks,
            'price_per_m3' => $harga === null ? null : round($harga, 2),
            // Ditandai supaya `persist()` tahu kolom paket pada baris ini
            // tidak boleh ditulis -- bukan sekadar bernilai 0.
            '__tanpa_biaya' => $tanpaBiaya,
            '__kunci' => self::kunci($nama, $min, $maks),
        ];
    }

    public function existingIndex(): array
    {
        $this->sudahDitulis = [];

        $idPaket = [];

        foreach (InstallationPackage::query()->get(['id', 'name']) as $paket) {
            $idPaket[mb_strtolower(trim((string) $paket->name))] = (int) $paket->id;
        }

        /*
         * Disimpan sebagai cache, bukan variabel lokal saja.
         *
         * `mapRow()` membutuhkannya untuk membedakan baris pertama
         * dari baris lanjutan, dan `mapRow()` dipanggilousands kali
         * setelah `existingIndex()`. Tanpa cache, setiap baris berarti
         * satu query tambahan ke `installation_packages` -- dan untuk
         * 5.000 baris paket itu 5.000 query yang hasilnya sudah
         * diketahui sebelum baris pertama dibaca.
         */
        $this->idPaket = $idPaket;

        $peta = [];

        foreach (WaterTariffBlock::query()->get(['id', 'package_id', 'usage_min_m3', 'usage_max_m3']) as $blok) {
            $nama = array_search((int) $blok->package_id, $idPaket, true);

            if ($nama === false) {
                // Blok tarif yang paketnya sudah tidak ada. Diabaikan:
                // mengimpor baris yang menunjuk paket yang sudah hilang
                // cukup informasi untuk memulihkannya.
                continue;
            }

            $peta[self::kunci($nama, $blok->usage_min_m3, $blok->usage_max_m3)] = (int) $blok->id;
        }

        return $peta;
    }

    public function persist(array $data, array $konflik, int $nomorUrut): void
    {
        $paket = $this->pakaiAtauBuatPaket($data);

        if ($data['usage_min_m3'] === null && $data['price_per_m3'] === null) {
            // Baris tanpa blok tarif sama sekali: paket sudah dibuat,
            // tidak ada lagi yang perlu ditulis.
            return;
        }

        $isi = [
            'package_id' => $paket->id,
            'usage_min_m3' => $data['usage_min_m3'] ?? 0,
            'usage_max_m3' => $data['usage_max_m3'],
            'price_per_m3' => $data['price_per_m3'],
        ];

        /*
         * Blok dicari dengan query, bukan dari `$konflik['id']`.
         *
         * Peta konflik hanya berisi blok tarif yang PAKETNYA ADA SAAT
         * validasi. Kalau baris pertama file membuat paket baru, blok
         * tarifnya juga baru -- tidak ada di peta. Query di bawah
         * menutup kedua kasus: blok lama yang sudah ada (ketemu),
         * dan blok baru (tidak ketemu, jadi disisipkan).
         */
        $blok = WaterTariffBlock::query()
            ->where('package_id', $paket->id)
            ->where('usage_min_m3', $isi['usage_min_m3'])
            ->when(
                $isi['usage_max_m3'] === null,
                static fn ($q) => $q->whereNull('usage_max_m3'),
                static fn ($q) => $q->where('usage_max_m3', $isi['usage_max_m3'])
            )
            ->first();

        if ($blok !== null) {
            DB::table('water_tariff_blocks')
                ->where('id', $blok->id)
                ->update([...$isi, 'updated_at' => now()]);

            return;
        }

        DB::table('water_tariff_blocks')->insert([
            ...$isi,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<int, string>> */
    public function references(): array
    {
        return [];
    }

    /* ================================================================
     | BANTUAN
     |============================================================
     */

    /**
     * Paket untuk baris ini, dibuat kalau belum ada.
     *
     * Kolom paket hanya ditulis pada baris pertama yang menyebut nama
     * tersebut di file ini. Baris berikutnya memakai paket yang sama
     * tanpa menyentuh kolom paket -- jadi abodemen yang diisi di baris
     * pertama tidak ditimpa oleh sel kosong di baris kedua.
     *
     * Penanda `__tanpa_biaya` datang dari validasi, bukan dicek ulang di
     * sini: keputusan "baris ini lanjutan" diambil sekali, di tempat
     * yang sama untuk seluruh baris. Kalau dicek ulang di sini,
     * keputusannya bisa berbeda antara validasi dan commit -- baris
     * yang lolos validasi tiba-tiba ditolak saat commit, dengan pesan
     * yang tidak pernah dilihat user.
     */
    private function pakaiAtauBuatPaket(array $data): InstallationPackage
    {
        $nama = mb_strtolower(trim($data['name']));

        $paket = InstallationPackage::query()
            ->whereRaw('LOWER(name) = ?', [$nama])
            ->first();

        if ($paket === null) {
            $paket = InstallationPackage::query()->create([
                'name' => $data['name'],
                'installation_fee' => $data['installation_fee'],
                'monthly_abodemen' => $data['monthly_abodemen'],
                'late_penalty' => $data['late_penalty'],
            ]);
        } elseif (! isset($this->sudahDitulis[$nama]) && ! ($data['__tanpa_biaya'] ?? false)) {
            $paket->update([
                'installation_fee' => $data['installation_fee'],
                'monthly_abodemen' => $data['monthly_abodemen'],
                'late_penalty' => $data['late_penalty'],
            ]);
        }

        $this->sudahDitulis[$nama] = true;

        return $paket;
    }

    /**
     * Paket dengan nama ini sudah ada di lokasi ini?
     *
     * Dipakai `mapRow()` untuk membedakan "baris pertama paket ini"
     * dari "baris lanjutan". Bedanya menentukan apakah Biaya Pasang
     * wajib diisi: untuk paket yang belum pernah ada, harga tanpa
     * biaya pasang berarti paket tercipta tanpa harga yang bisa
     * ditagihkan.
     *
     * Cache-nya dibuild sekali lewat `existingIndex()`, yang dipanggil
     * pipeline tepat sebelum baris pertama dipetakan.
     */
    private function paketAda(string $nama): bool
    {
        if ($this->idPaket === null) {
            $this->idPaket = [];

            foreach (InstallationPackage::query()->get(['id', 'name']) as $paket) {
                $this->idPaket[mb_strtolower(trim((string) $paket->name))] = (int) $paket->id;
            }
        }

        return isset($this->idPaket[mb_strtolower(trim($nama))]);
    }

    /**
     * Kunci alami: nama paket + batas blok.
     *
     * Angka batas dinormalkan ke teks polos supaya blok yang sudah ada
     * (`0.00` dari MySQL) cocok dengan blok dari file (`0` dari
     * `CellValue::integer()`). Tanpa itu setiap blok tarif akan ditulis
     * dua kali.
     */
    private static function kunci(?string $nama, mixed $min, mixed $maks): string
    {
        return mb_strtolower(trim((string) $nama))
            .'|'.self::angka($min)
            .'|'.self::angka($maks);
    }

    private static function angka(mixed $nilai): string
    {
        if ($nilai === null) {
            return '-';
        }

        $teks = rtrim(rtrim(number_format((float) $nilai, 2, '.', ''), '0'), '.');

        return ($teks === '' || $teks === '-') ? '0' : $teks;
    }
}
