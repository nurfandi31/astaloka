<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AkunLevel1;
use App\Models\AkunLevel2;
use App\Models\AkunLevel3;
use App\Models\Amount;
use Illuminate\Support\Collection;

/**
 * Memuat pohon akun yang TERPOTONG di dua database.
 *
 * Latar belakang: `akun_level_1/2/3` adalah kerangka chart of accounts yang
 * sama untuk semua lokasi, jadi disimpan di database pusat. `accounts`
 * (level 4) tetap per lokasi karena tiap lokasi boleh beda. Akibatnya pohon
 * laporan membelah: tiga tabel di `astaloka`, sisanya di `astaloka_<lokasi>`.
 *
 * Eloquent TIDAK bisa memuat relasi seperti itu. `with()` selalu menjalankan
 * setiap relasi pada koneksi model induknya -- sudah dibuktikan:
 *
 *     AkunLevel1::with('akunLevel2.akunLevel3.accountParent')
 *     -> SQLSTATE[42S02] Table 'astaloka.accounts' doesn't exist
 *     (Connection: central)
 *
 * Lazy loading tidak bisa menolong karena mekanismenya sama: relasi ikut
 * koneksi induk. Jadi pohonnya dirakit di sini, per tingkat, lalu dipasang
 * ke model lewat `setRelation()`.
 *
 * Dipakai `setRelation()`, bukan mengubah signature pemanggil, karena seluruh
 * kode di hilir -- `PelaporanService::susunFlatRows()`,
 * `PelaporanController::susunFlatRowsTutupBuku()`, `PelaporanController::neraca()`,
 * dan view Vue yang membaca `akunLevel2`/`akunLevel3` -- sudah ditulis
 * Against the shape Eloquent produces. Relasi yang sudah disetEL akan dibaca
 * Eloquent dari memori, bukan diambil dari database, jadi tidak ada query
 * tambahan dan tidak ada filter yang hilang.
 */
class PohonAkunService
{
    /**
     * Pohon lengkap level 1 -> 2 -> 3 -> accounts(level 4) -> amount.
     *
     * @param  callable|null  $filterAmount  Filter yang dissected ke query
     *                                       `amount`. null = tanpa filter,
     *                                       dipakai saat memuat struktur saja.
     */
    public function load(int|string $tahun, int|string|null $bulan = null, ?callable $filterAmount = null): Collection
    {
        // --- Struktur, tiga query ringan ke database pusat -----------
        $level1 = AkunLevel1::where('lev1', '<=', '3')
            ->orderBy('kode_akun', 'ASC')
            ->get();

        if ($level1->isEmpty()) {
            return $level1;
        }

        $level2 = AkunLevel2::whereIn('parent_id', $level1->pluck('id'))
            ->orderBy('kode_akun', 'ASC')
            ->get()
            ->groupBy('parent_id');

        $level3 = AkunLevel3::whereIn('parent_id', $level2->flatten()->pluck('id'))
            ->orderBy('kode_akun', 'ASC')
            ->get()
            ->groupBy('parent_id');

        // --- Accounts (level 4) per lokasi, satu query ---------------
        // parent_id level 3 dicocokkan SEKALI lewat whereIn, bukan N+1.
        // Akun yang punya parent_id tak dikenal ikut terbawa supaya
        // INV reports tidak diam-diam kehilangan akun.
        $allParentIds = $level3->flatten()->pluck('id');

        $accounts = Account::whereIn('parent_id', $allParentIds)
            ->get()
            ->groupBy('parent_id');

        // --- Amount, satu query per laporan --------------------------
        $amounts = $this->muatAmount($accounts->flatten(), $tahun, $bulan, $filterAmount);

        // --- Rakit & pasang ------------------------------------------
        foreach ($level1 as $l1) {
            $l1->setRelation('akunLevel2', $this->rakitLevel2(
                $level2->get($l1->id, collect()),
                $level3,
                $accounts,
                $amounts
            ));
        }

        return $level1;
    }

    /**
     * Bangun collection level 2 beserta anak-anaknya.
     */
    private function rakitLevel2(Collection $level2, Collection $level3, Collection $accounts, Collection $amounts): Collection
    {
        return $level2->map(function (AkunLevel2 $l2) use ($level3, $accounts, $amounts) {
            $l2->setRelation('akunLevel3', $level3->get($l2->id, collect())->map(
                function (AkunLevel3 $l3) use ($accounts, $amounts) {
                    $akun4 = $accounts->get($l3->id, collect())->map(
                        function (Account $acc) use ($amounts) {
                            $acc->setRelation('amount', $amounts->get($acc->id, collect()));
                            $acc->setRelation('oneAmount', $amounts->get($acc->id, collect())->first());

                            return $acc;
                        }
                    );

                    // Dipasang ke KEDUA nama relasi. Keduanya menunjuk data
                    // yang sama di kode lama, dan menyalin hanya ke salah
                    // satu akan membuat pemanggil yang memakai nama lain
                    // jatuh ke lazy-load -- yang berarti query ke database
                    // pusat, dan gagal.
                    $l3->setRelation('accountParent', $akun4);
                    $l3->setRelation('accounts', $akun4);

                    return $l3;
                }
            ));

            return $l2;
        });
    }

    /**
     * Muat tabel `amount` untuk sekumpulan akun dalam satu query.
     *
     * Disaring per account_id supaya setiap akun hanya membawa saldonya
     * sendiri. Melewati filter `$filterAmount` akan membuat saldo LABA RUGI
     * ikut terpotong dan laporan jadi tidak seimbang -- makanya filter itu
     * wajib diteruskan, bukan sekadar memanggil `amount()`.
     */
    private function muatAmount(Collection $accounts, int|string $tahun, int|string|null $bulan, ?callable $filterAmount): Collection
    {
        if ($accounts->isEmpty()) {
            return collect();
        }

        $query = Amount::whereIn('account_id', $accounts->pluck('id'));

        if ($filterAmount !== null) {
            $query = $filterAmount($query) ?: $query;
        }

        return $query->get()->groupBy('account_id');
    }
}