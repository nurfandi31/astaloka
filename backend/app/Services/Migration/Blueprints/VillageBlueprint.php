<?php

namespace App\Services\Migration\Blueprints;

use App\Models\Village;
use App\Services\Migration\CellValue;
use App\Services\Migration\MigrationBlueprint;
use App\Services\Migration\MigrationBlueprintConcerns;
use App\Services\Migration\MigrationRowException;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi master desa + dusun.
 *
 * DITARUH PALING AWAL DARI SENAIMNYA
 * ================================
 *
 * `customers.customer_code` dibangun dari `villages.code` (lihat
 * `Village::generateNextCustomerCode()`). Desa yang diimpor setelah
 * pelanggan akan menghasilkan kode pelanggan yang tidak sesuai dengan
 * pola yang dipakai di lokasi itu -- jadi urutannya penting, dan ini
 * ditulis di `description()` supaya terbaca di kartu menu, bukan
 * hanya di dokumen.
 */
class VillageBlueprint implements MigrationBlueprint
{
    use MigrationBlueprintConcerns;

    public function type(): string
    {
        return 'villages';
    }

    public function label(): string
    {
        return 'Desa & Dusun';
    }

    public function description(): string
    {
        return 'Master desa beserta dusun dan kodenya. Kode desa dipakai untuk '
            .'membentuk kode pelanggan, jadi migrasikan ini sebelum migrasi pelanggan.';
    }

    public function icon(): string
    {
        return 'map-marker-alt';
    }

    public function order(): int
    {
        return 10;
    }

    public function sheetName(): string
    {
        return 'Desa';
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'kode',
                'label' => 'Kode Desa',
                'type' => 'text',
                'required' => false,
                'help' => 'Format hierarkis "kecamatan.desa.dusun", contoh 005.0001.0001. '
                    .'Kosongkan jika belum ada -- sistem akan membuat sendiri.',
                'example' => '005.0001.0001',
            ],
            [
                'key' => 'nama_desa',
                'label' => 'Nama Desa',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama desa saja, tanpa awalan kecamatan.',
                'example' => 'Sukamaju',
            ],
            [
                'key' => 'nama_dusun',
                'label' => 'Nama Dusun',
                'type' => 'text',
                'required' => true,
                'help' => 'Nama dusun atau bairro di dalam desa.',
                'example' => 'Dusun 1',
            ],
            [
                'key' => 'alamat',
                'label' => 'Alamat',
                'type' => 'text',
                'required' => false,
                'help' => 'Alamat lengkap desa atau kantor desa.',
                'example' => 'Jl. Raya Bogor KM 12',
            ],
            [
                'key' => 'telepon',
                'label' => 'Telepon',
                'type' => 'phone',
                'required' => false,
                'help' => 'Nomor telepon desa. Boleh ditulis 021-1234567 atau 081234567890.',
                'example' => '021-1234567',
            ],
        ];
    }

    public function tables(): array
    {
        return ['villages'];
    }

    public function mapRow(array $row, int $nomorUrut): array
    {
        $desa = CellValue::kosongkan($row['nama_desa'] ?? null);
        $dusun = CellValue::kosongkan($row['nama_dusun'] ?? null);

        if ($desa === null) {
            throw MigrationRowException::kosong('Nama Desa');
        }

        if ($dusun === null) {
            throw MigrationRowException::kosong('Nama Dusun');
        }

        $kode = CellValue::kosongkan($row['kode'] ?? null);

        if ($kode !== null) {
            // Panjang maksimum 30 (dari migrasi `add_code_to_villages_table`).
            // Dipotong di sini, bukan hopeskan tidak terjadi: kode yang
            // kepanjangan akan ditolak MySQL dengan pesan yang menyebut
            // "Data too long for column 'code'" -- yang tidak memberi
            // tahu baris mana dan kolom mana.
            if (mb_strlen($kode) > 30) {
                throw MigrationRowException::tidakValid(
                    'Kode Desa',
                    $kode,
                    'Maksimal 30 karakter'
                );
            }

            if (! preg_match('/^[0-9.]+$/', $kode)) {
                throw MigrationRowException::tidakValid(
                    'Kode Desa',
                    $kode,
                    'Hanya boleh berisi angka dan titik, contoh 005.0001.0001'
                );
            }
        }

        return [
            'village_name' => $desa,
            'hamlet_name' => $dusun,
            'address' => CellValue::kosongkan($row['alamat'] ?? null),
            'phone' => CellValue::telepon($row['telepon'] ?? null),
            'code' => $kode,
            '__kunci' => self::kunci($desa, $dusun),
        ];
    }

    public function existingIndex(): array
    {
        $peta = [];

        foreach (Village::query()->get(['id', 'village_name', 'hamlet_name']) as $v) {
            $peta[self::kunci($v->village_name, $v->hamlet_name)] = (int) $v->id;
        }

        return $peta;
    }

    public function persist(array $data, array $konflik, int $nomorUrut): void
    {
        $isidata = [
            'village_name' => $data['village_name'],
            'hamlet_name' => $data['hamlet_name'],
            'address' => $data['address'],
            'phone' => $data['phone'],
        ];

        $ada = ($konflik['id'] ?? null) !== null;

        if ($ada) {
            /*
             * Kode hanya ditulis kalau baris ini punya kode sendiri.
             *
             * Menulis `null` di kolom `code` untuk desa yang sudah punya
             * kode akan menghapusnya -- dan kode itu adalah dasar dari
             * `customer_code` setiap pelanggan di desa tersebut. Kode
             * pelanggan yang sudah terbentuk tidak ikut berubah, jadi
             * hasilnya
             * kode pelanggan yang tidak lagi cocok dengan pola kode desa
             * tanpa ada yang memberi tahu.
             */
            if (! empty($data['code'])) {
                $isidata['code'] = $data['code'];
            }

            Village::query()->whereKey($konflik['id'])->update($isidata);

            return;
        }

        $isidata['code'] = $data['code'] ?: Village::generateNextCode();

        $id = DB::table('villages')->insertGetId([
            ...$isidata,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($id) {
            return;
        }

        // Tidak mungkin, tapi `insertGetId` yang mengembalikan 0 berarti
        // kueri tidak benar-benar menulis apa pun -- diamkan saja.
    }

    /**
     * Kunci alami: nama desa + dusun.
     *
     * Mengabaikan `code` sebagai kunci karena `code` boleh kosong di
     * template -- dua baris dengan `code` kosong tapi nama sama adalah
     * data yang sama, dan mengimpannya dua kali menghasilkan dua dusun
     * dengan nama identik yang membuat dropdown pelanggan ambigu.
     *
     * `mb_strtolower` dipakai supaya "Sukamaju" dan "sukamaju" dianggap
     * satu desa, yang memang begitu cara manusianya menulis.
     */
    private static function kunci(?string $desa, ?string $dusun): string
    {
        return mb_strtolower(trim((string) $desa)).'|'.mb_strtolower(trim((string) $dusun));
    }

    /** @return array<string, array<int, string>> */
    public function references(): array
    {
        return [];
    }
}
