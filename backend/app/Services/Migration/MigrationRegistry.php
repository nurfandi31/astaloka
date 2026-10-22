<?php

namespace App\Services\Migration;

use App\Services\Migration\Blueprints\CustomerBlueprint;
use App\Services\Migration\Blueprints\InstallationTicketBlueprint;
use App\Services\Migration\Blueprints\PackageBlueprint;
use App\Services\Migration\Blueprints\UserBlueprint;
use App\Services\Migration\Blueprints\VillageBlueprint;

/**
 * Daftar jenis data yang bisa dimigrasi, dan cara mencarinya.
 *
 * DAFTAR DITULIS DI SINI, BUKAN SCAN DIREKTORI
 * ===========================================
 *
 * Auto-discovery lewat `glob()` akan terasa lebih rapi, tapi
 * membuatnya mustahil untuk terlihat saat membaca kode: tidak ada satu
 * tempat yang menyebutkan "ini semua yang bisa dimigrasi", dan menambah
 * kelas baru tidak akan terlihat di diff mana pun.
 *
 * Daftar eksplisit lebih pendek, dan yang lebih penting: nama yang
 * salah ketik di sini akan langsung gagal saat request, bukan diam-diam
 * menyebabkan satu jenis migrasi hilang dari UI.
 */
class MigrationRegistry
{
    /**
     * Semua jenis migrasi, sudah diurutkan sesuai nomor urut.
     *
     * @return array<int, MigrationBlueprint>
     */
    public static function semua(): array
    {
        $daftar = [
            new UserBlueprint,
            new VillageBlueprint,
            new PackageBlueprint,
            new InstallationTicketBlueprint,
            new CustomerBlueprint,
        ];

        usort(
            $daftar,
            static fn (MigrationBlueprint $a, MigrationBlueprint $b) => $a->order() <=> $b->order()
        );

        return $daftar;
    }

    /**
     * Satu jenis migrasi berdasarkan `type()`-nya, atau null.
     *
     * `null` berarti "jenis ini tidak dikenal", yang dibedakan dari
     * "ditolak karena alasan lain" oleh controller -- supaya pesan 404
     * menyebut nama yang salah ketik, bukan "tidak ditemukan".
     */
    public static function cari(string $type): ?MigrationBlueprint
    {
        foreach (self::semua() as $blueprint) {
            if ($blueprint->type() === $type) {
                return $blueprint;
            }
        }

        return null;
    }

    /**
     * Ringkasan untuk endpoint daftar.
     *
     * PENTING: fungsi ini TIDAK menyentuh database lokasi mana pun.
     *
     * Endpoint ini dipanggil tanpa lokasi -- daftarnya sama untuk semua
     * lokasi, dan memang tidak ada lokasi yang bisa dibaca. Itu sebabnya
     * `references()` tidak boleh dipanggil di sini, meskipun kelihatannya
     * mudah: `references()` membaca tabel lokasi (paket, desa, jabatan),
     * dan dipanggil tanpa `TenantManager::runFor()` artinya query itu
     * jatuh ke `database.default`.
     *
     * Yang terjadi kalau itu bocor: endpoint daftar, yang seharusnya
     * bekerja tanpa lokasi, berakhir dengan error 500 "Table
     * 'astaloka.jabatans' doesn't exist" -- menunjuk ke database PUSAT,
     * yang memang tidak punya tabel lokasi. Pesan itu membuat yang salah
     * mengira database pusatnya rusak, padahal tidak ada yang rusak.
     *
     * Karena itu `punyaReferensi` di bawah hanya menanyakan KEMAMPUAN
     * (apakah blueprint punya daftar referensi), bukan isinya. Isinya
     * baru dibaca di `template()`, yang sudah berada di dalam
     * `runFor()` untuk lokasi yang dipilih.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ringkasan(): array
    {
        return array_map(
            static fn (MigrationBlueprint $b) => [
                'type' => $b->type(),
                'label' => $b->label(),
                'deskripsi' => $b->description(),
                'icon' => $b->icon(),
                'sheet' => $b->sheetName(),
                'tabel' => $b->tables(),
                'kolom' => array_map(
                    static fn (array $c) => [
                        'key' => $c['key'],
                        'label' => $c['label'],
                        'type' => $c['type'],
                        'required' => $c['required'],
                        'help' => $c['help'],
                        'example' => $c['example'],
                        'options' => $c['options'] ?? null,
                    ],
                    $b->columns()
                ),
                /*
                 * "Bisa punya referensi", bukan "punya referensi sekarang".
                 *
                 * Jawaban yang jujur untuk daftar tanpa lokasi: blueprint
                 * ini bisa punya daftar referensi, tapi isinya bergantung
                 * lokasi -- jadi nilainya baru diketahui setelah lokasi
                 * dipilih. Frontend memakainya untuk menampilkan catatan
                 * "isi template ini diambil dari lokasi", yang memang
                 * selalu benar kapan pun template diunduh.
                 */
                'punyaReferensi' => method_exists($b, 'references'),
            ],
            self::semua()
        );
    }
}
