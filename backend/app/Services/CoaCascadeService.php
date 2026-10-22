<?php

namespace App\Services;

use App\Models\Central\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menghitung dan menjalankan penghapusan berjenjang struktur akun pusat.
 *
 * KENAPA LAYANAN INI ADA
 *
 * `accounts` -- akun level 4 -- hidup di database SETIAP lokasi, sedangkan
 * induknya (`akun_level_3`) hidup di database pusat. Keduanya berbeda
 * database, jadi MySQL tidak mungkin memakai foreign key lintas database,
 * dan memang tidak ada satu pun FK yang menghubungkan keduanya.
 *
 * Akibatnya, menghapus satu baris level 3 di pusat TIDAK menyentuh akun
 * level 4 di lokasi mana pun. Daun pohonnya tetap tertinggal dengan
 * `parent_id` menunjuk `id` yang sudah tidak ada. Tidak ada error yang
 * muncul -- laporan hanya tiba-tiba kehilangan baris-baris itu jauh setelah
 * tombol hapus ditekan, tanpa satu pun pesan yang menunjukkan penyebabnya.
 *
 * Pohon yang dibongkar hanya tiga tingkat di pusat plus satu tingkat di tiap
 * lokasi. Bukan rekursif: `accounts` juga tidak berinduk satu sama lain
 * lewat `parent_id` (kolom `lev4` di sana hanya urutan, bukan referensi),
 * jadi tidak ada jeopardize yang membuat jumlah tingkatnya tak terbatas.
 *
 * KENAPA DATA KEUANGAN TIDAK IKUT HILANG
 *
 * Satu akun level 4 yang sudah dipakai bertransaksi adalah data akuntansi
 * yang sudah dibukukan. Menghapusnya berarti membuang `amount`,
 * `ebudgeting`, dan jejaknya di `transactions` milik lokasi orang lain --
 * hasil pembukuan yang tidak bisa dikembalikan, dan tidak ada yang
 * meminta.
 *
 * Karena itu penghapusan BERHENTI begitu menemukan salah satu dari tiga
 * itu, dan jawaban 409 menyebutkan persis akun mana, di lokasi mana, dan
 * kenapa terhalang. Setelah itu superadmin punya dua jalan yang jelas:
 * keluarkan akun bermasalah itu di lokasi masing-masing lewat aplikasi
 * lokasi, atau memindahkannya lewat migrasi yang terkoordinasi. Apa pun
 * pilihannya, buku besar tetap utuh sampai keputusan itu diambil.
 *
 * URUTAN: DAUN DULU, BATANG KEMUDIAN
 *
 *   1. Akun level 4 di setiap lokasi
 *   2. Level 3, lalu level 2, lalu level 1 di database pusat
 *
 * Urutan terbalik kelihatan lebih wajar ("hapus induk, anak ikut hilang"),
 * tapi justru itulah yang berbahaya. Kalau batang dihapus lebih dulu dan
 * salah satu lokasi gagal di tengah, lokasi yang SUDAH sempat dihapus
 * kehilangan akunnya tanpa jejak, sementara lokasi yang belum sempat
 * dihapus tetap memegang `parent_id` yang menunjuk `id` yang sudah tidak ada --
 * persis kondisi yang diurus kelas ini.
 *
 * Daun dulu membuat kondisi yang tersisa saat gagal selalu "lokasi
 * tertentu belum ikut terhapus", yang bisa dibaca dan diselesaikan. Karena
 * operasi ini menyentuh banyak database, tidak ada satu transaksi SQL yang
 * bisa membungkus semuanya; yang dijaga di sini adalah ARAH kegagalan,
 * bukan atomisitas.
 */
class CoaCascadeService
{
    public function __construct(private TenantManager $manager) {}

    /* ============================================================
     | MENGHITUNG DAMPAK
     |============================================================
     */

    /**
     * Ringkasan berjenjang dari satu baris, termasuk apa yang akan hilang di
     * setiap lokasi.
     *
     * Dipakai endpoint `impact` untuk mengisi dialog konfirmasi.
     * `dapat_dihapus` dijawab SEBAB, bukan hanya true/false: dialog perlu
     * menampilkan daftar akun yang menahan, dan "tidak bisa dihapus" tanpa
     * menyebut siapa tidak akan membuat siapa pun bergerak.
     *
     * @return array{
     *   level:int, id:int,
     *   jumlah_level2:int, jumlah_level3:int, jumlah_level4:int,
     *   jumlah_lokasi:int, locations:array<int,array<string,mixed>>,
     *   dapat_dihapus:bool, blocked:bool,
     *   message:string, blockers:array<int,array<string,mixed>>
     * }
     */
    public function impact(int $level, int $row): array
    {
        /*
         * Turunan dihitung DUA kali, untuk dua tujuan yang berbeda.
         *
         * Yang pertama untuk PENGHAPUSAN: anak yang benar-benar ikut hilang
         * bersama baris ini. Untuk level 3, `keturunan()` mengembalikan
         * `[], [$row]` -- yaitu baris itu sendiri, karena memang itu yang
         * dihapus, bukan keturunannya.
         *
         * Yang kedua untuk TAMPILAN: "akun ini punya N anak" adalah
         * pertanyaan yang selalu menarik, untuk orang yang sedang
         * menimbang menghapus baris. Untuk level 3, jawabannya adalah
         * anak-anaknya di level berikutnya -- karena itulah yang akan hilang
         * bersamanya.
         *
         * memakai angka yang sama untuk keduanya menghasilkan kalimat seperti
         * "menghapus akun level 3 ini sekaligus menghapus 1 akun level 3" --
         * secara hitungan benar, tapi tidak menjawab apa pun, dan setelah
         * dibaca orang tidak tahu apakah ada akun lain ikut hilang atau tidak.
         */
        [$anakL2, $anakL3] = $this->keturunan($level, $row);
        [$tampilL2, $tampilL3] = $this->turunanUntukTampilan($level, $row);

        $lokasi = $this->pindaiLokasi($anakL3);

        $jumlahL4 = array_sum(array_column($lokasi, 'jumlah_level4'));

        /*
         * Lokasi yang tidak terbaca ikut menjadi penghalang, dan ini
         * disengaja (fail-closed). Database lokasi yang tidak bisa dibaca
         * bisa jadi berisi akun level 4 yang menunjuk baris yang sedang
         * dihapus -- dan kalau pusat tetap melanjutkan, akun itu berubah
         * jadi yatim di database yang justru tidak bisa diperiksa siapa pun.
         * "Tidak tahu" tidak boleh diperlakukan sebagai "tidak ada".
         */
        $blockers = $this->kumpulkanBlocker($lokasi);

        $dapat = $blockers === [];

        return [
            'level' => $level,
            'id' => $row,
            'jumlah_level2' => count($tampilL2),
            'jumlah_level3' => count($tampilL3),
            'jumlah_level4' => $jumlahL4,
            'jumlah_lokasi' => count($lokasi),
            'locations' => array_map(
                fn (array $l) => [
                    'slug' => $l['slug'],
                    'error' => $l['error'],
                    'jumlah_level4' => $l['jumlah_level4'],
                    'accounts' => array_map(
                        fn (array $a) => [
                            'kode_akun' => $a['kode_akun'],
                            'nama_akun' => $a['nama_akun'],
                            'alasan' => $a['alasan'],
                        ],
                        $l['accounts']
                    ),
                ],
                $lokasi
            ),
            'dapat_dihapus' => $dapat,
            'blocked' => ! $dapat,
            'blockers' => $blockers,
            'message' => $dapat
                ? $this->pesanDapat($level, count($tampilL2), count($tampilL3), $jumlahL4, $lokasi)
                : $this->pesanBlocked($blockers, $lokasi),
        ];
    }

    /**
     * Eksekusi penghapusan berjenjang.
     *
     * Pemeriksaan penghalang DIULANG di sini, bukan mempercayai hasil
     * `impact()`. Antara dialog konfirmasi yang terbuka dan tombol hapus yang
     * ditekan bisa berlalu beberapa detik, dan di sela itu sebuah akun
     * bisa saja mulai dipakai. Penghapusan yang lolos karena "tidak ada yang
     * pakai" di menit lalu akan menghapus baris yang baru saja berisi data.
     *
     * @return array{
     *   jumlah_level4:int, locations:array<int,array<string,mixed>>,
     *   ids_level2:int[], ids_level3:int[]
     * }
     *
     * @throws CoaCascadeException Kalau ada lokasi yang terhalang atau tidak
     *                             terbaca. Dalam kasus itu tidak ada satu
     *                             baris pun yang dihapus.
     */
    public function delete(int $level, int $row): array
    {
        [$anakL2, $anakL3] = $this->keturunan($level, $row);

        $pindaian = $this->pindaiLokasi($anakL3);

        foreach ($pindaian as $l) {
            if ($l['error'] !== null) {
                throw new CoaCascadeException(
                    "Database lokasi `{$l['slug']}` tidak bisa dibaca ({$l['error']}). Penghapusan dibatalkan "
                    .'supaya tidak ada akun di lokasi itu yang menjadi yatim tanpa bisa diperiksa.',
                    $this->kumpulkanBlocker($pindaian)
                );
            }

            foreach ($l['accounts'] as $akun) {
                if ($akun['alasan'] !== []) {
                    throw new CoaCascadeException(
                        "Akun `{$akun['kode_akun']}` di lokasi `{$l['slug']}` sudah punya data keuangan, "
                        .'jadi tidak ikut dihapus. Tidak ada data yang dihapus.',
                        $this->kumpulkanBlocker($pindaian)
                    );
                }
            }
        }

        /*
         * Daun dulu, batang kemudian -- lihat catatan di kepala kelas.
         *
         * `runFor()` mengembalikan `database.default` ke nilai semula di blok
         * `finally`-nya, jadi kueri pusat yang dijalankan sesudah loop ini
         * tidak ikut membaca database lokasi terakhir yang sempat dibuka.
         */
        $perLokasi = [];

        foreach ($pindaian as $l) {
            $terhapus = $this->manager->runFor($l['tenant'], function () use ($l) {
                $ids = array_column($l['accounts'], 'id');

                if ($ids === []) {
                    return 0;
                }

                /*
                 * Tabel `amount` TIDAK dihapus di sini.
                 *
                 * `amount` punya FK `account_id -> accounts.id` dengan
                 * `ON DELETE CASCADE` (lihat migrasi `create_amount_table`),
                 * jadi MySQL yang membersihkannya. Menulis loop eksplisit
                 * untuk tabel yang sudah dijamin database hanya menambah satu
                 * cara gagal tanpa menambah jaminan apa pun.
                 */
                return (int) DB::connection(TenantManager::CONNECTION)
                    ->table('accounts')
                    ->whereIn('id', $ids)
                    ->delete();
            });

            $perLokasi[] = [
                'slug' => $l['slug'],
                'terhapus' => $terhapus,
            ];
        }

        return [
            'jumlah_level4' => array_sum(array_column($perLokasi, 'terhapus')),
            'locations' => $perLokasi,
            'ids_level2' => $anakL2,
            'ids_level3' => $anakL3,
        ];
    }

    /* ============================================================
     | PEKERJAAN INTERNAL
     |============================================================
     */

    /**
     * Turunannya di database pusat: [id level 2, id level 3].
     *
     * Level 1 tidak punya `parent_id` sama sekali -- strukturnya mulai dari
     * `akun_level_2`. Level 4 tidak dihitung di sini karena ia hidup di
     * database lokasi dan dibaca oleh {@see pindaiLokasi()}.
     *
     * @return array{0:int[],1:int[]}
     */
    private function keturunan(int $level, int $row): array
    {
        $central = DB::connection('central');

        if ($level === 1) {
            $l2 = $central->table('akun_level_2')
                ->where('parent_id', $row)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            $l3 = $l2 === []
                ? []
                : $central->table('akun_level_3')
                    ->whereIn('parent_id', $l2)
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all();

            return [$l2, $l3];
        }

        if ($level === 2) {
            $l3 = $central->table('akun_level_3')
                ->where('parent_id', $row)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            return [[], $l3];
        }

        /*
         * Level 3: baris ini sendiri ikut terhapus, jadi masuk daftar.
         *
         * Dan anak-anaknya -- kalau ada -- juga. Kerangka bawaan tidak punya
         * level 3 yang induknya level 3, tapi tidak dikecualikan: kalau
         * suatu saat ada baris seperti itu, menghapusnya tanpa menyapu
         * anaknya berarti meninggalkan turunan yatim DI PUSAT, yang justru
         * masalah yang sama dalam bentuk lebih kecil.
         */
        $anak = $this->idsLevel3AnakDariLevel3($row);

        return [[], array_merge([$row], $anak)];
    }

    /**
     * Turunan untuk TAMPILAN, bukan untuk penghapusan: [id level 2, id level 3].
     *
     * Bedanya dengan {@see keturunan()} cuma di satu hal, tapi hal itu
     * menentukan apakah kalimat impact-nya berguna:
     *
     *   - `keturunan()` untuk level 3 mengembalikan baris itu sendiri,
     *     karena itulah yang AKAN dihapus.
     *   - `turunanUntukTampilan()` untuk level 3 mengembalikan anak-anaknya,
     *     karena itulah yang menjawab "berapa banyak yang ikut hilang".
     *
     * Untuk level 1 dan 2 keduanya sama, jadi tidak ada biaya tambahan.
     *
     * @return array{0:int[],1:int[]}
     */
    private function turunanUntukTampilan(int $level, int $row): array
    {
        $central = DB::connection('central');

        if ($level === 1) {
            $l2 = $central->table('akun_level_2')
                ->where('parent_id', $row)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            $l3 = $l2 === []
                ? []
                : $central->table('akun_level_3')
                    ->whereIn('parent_id', $l2)
                    ->pluck('id')
                    ->map(fn ($v) => (int) $v)
                    ->all();

            return [$l2, $l3];
        }

        if ($level === 2) {
            $l3 = $central->table('akun_level_3')
                ->where('parent_id', $row)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            return [[], $l3];
        }

        // Level 3: yang ditampilkan adalah anak-anaknya di level berikutnya.
        $l4 = $this->idsLevel3AnakDariLevel3($row);

        return [[], $l4];
    }

    /**
     * Id level 3 yang menjadi anak dari satu level 3 tertentu.
     *
     * Ini "${parent_id} level 3 menunjuk level 3 lain" -- struktur seperti
     * itu tidak ada di kerangka bawaan (level 3 induknya selalu level 2),
     * tapi TIDAK dikecualikan di sini: kalau suatu saat ada baris seperti itu,
     * `keturunan()` harus tetap bisa mengikutinya supaya penghapusan tidak
     * meninggalkan turunan yatim di pusat.
     *
     * @return int[]
     */
    private function idsLevel3AnakDariLevel3(int $row): array
    {
        return DB::connection('central')->table('akun_level_3')
            ->where('parent_id', $row)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Baca setiap lokasi aktif dan laporkan apa saja yang menunjuk baris
     * level 3 yang dihapus.
     *
     * Satu lokasi yang gagal TIDAK menggagalkan seluruh pemindaian: tanpa
     * begitu, satu database yang sedang di-restore membuat superadmin tidak
     * bisa melihat dampak di lokasi lain yang sehat -- padahal informasi itu
     * justru yang paling dibutuhkan untuk memutuskan. Kegagalan tetap
     * dikembalikan sebagai penghalang di {@see impact()}.
     *
     * @param  int[]  $idsLevel3
     * @return array<int,array<string,mixed>>
     */
    private function pindaiLokasi(array $idsLevel3): array
    {
        if ($idsLevel3 === []) {
            return [];
        }

        $hasil = [];

        foreach (Tenant::query()->where('is_active', true)->orderBy('id')->get() as $tenant) {
            $hasil[] = $this->pindaiSatuLokasi($tenant, $idsLevel3);
        }

        return $hasil;
    }

    /**
     * @return array{tenant:Tenant,slug:string,error:?string,jumlah_level4:int,accounts:array<int,array<string,mixed>>}
     */
    private function pindaiSatuLokasi(Tenant $tenant, array $idsLevel3): array
    {
        $dasar = [
            'tenant' => $tenant,
            'slug' => $tenant->slug,
            'error' => null,
            'jumlah_level4' => 0,
            'accounts' => [],
        ];

        try {
            $akun = $this->manager->runFor($tenant, function () use ($idsLevel3) {
                $db = DB::connection(TenantManager::CONNECTION);

                /*
                 * Lokasi yang belum punya tabel `accounts` belum pernah
                 * menjalankan migrasi aplikasi: tidak ada akun yang bisa
                 * menunjuk apa pun, jadi ini kondisi AMAN.
                 *
                 * Membedakannya dari "gagal dibaca" penting, karena yang kedua
                 * harus memblokir penghapusan. `SELECT` ke tabel yang hilang
                 * akan melempar PDOException di tengah pemindaian, bukan
                 * mengembalikan nol baris.
                 */
                if (! $db->getSchemaBuilder()->hasTable('accounts')) {
                    return [];
                }

                return $this->akunYangMenunjuk($db, $idsLevel3);
            });
        } catch (Throwable $e) {
            return array_merge($dasar, ['error' => $e->getMessage()]);
        }

        return array_merge($dasar, [
            'jumlah_level4' => count($akun),
            'accounts' => $akun,
        ]);
    }

    /**
     * Semua akun level 4 di satu lokasi yang menunjuk id level 3 tertentu,
     * beserta alasan kalau masing-masing tidak boleh dihapus.
     *
     * @param  int[]  $idsLevel3
     * @return array<int,array<string,mixed>>
     */
    private function akunYangMenunjuk(mixed $db, array $idsLevel3): array
    {
        $akun = $db->table('accounts')
            ->whereIn('parent_id', $idsLevel3)
            ->orderBy('kode_akun')
            ->get(['id', 'kode_akun', 'nama_akun']);

        if ($akun->isEmpty()) {
            return [];
        }

        $ids = $akun->map(fn ($a) => (int) $a->id)->all();
        $kode = $akun->map(fn ($a) => (string) $a->kode_akun)->all();

        $pakaiAmount = $this->hitungPemakaian(
            $db,
            $this->adaTabel($db, 'amount') ? 'amount' : null,
            ['account_id'],
            ['id' => $ids, 'kode' => $kode]
        );

        $pakaiEbudget = $this->hitungPemakaian(
            $db,
            $this->adaTabel($db, 'ebudgeting') ? 'ebudgeting' : null,
            ['account_id'],
            ['id' => $ids, 'kode' => $kode]
        );

        /*
         * `transactions` tidak pernah menunjuk `accounts.id`. Ia menyimpan
         * KODE akun sebagai teks di `account_debet` dan `account_kredit` --
         * lihat `Transaction::accountDebet()`.
         *
         * Konsekuensinya lebih serius daripada sekadar join yang lebih
         * panjang: menghapus akun tidak merusak baris transaksinya, tapi
         * baris itu langsung menggantung -- `account_debet` menunjuk kode
         * yang tidak ada lagi, dan laporan tiba-tiba kehilangan transaksi
         * yang saldonya tidak pernah nol. Karena itu transaksi ikut
         * dihitung sebagai penghalang, meski barisnya sendiri tidak dihapus.
         */
        $pakaiTransaksi = $this->hitungPemakaian(
            $db,
            $this->adaTabel($db, 'transactions') ? 'transactions' : null,
            ['account_debet', 'account_kredit'],
            ['id' => $ids, 'kode' => $kode],
            true
        );

        return $akun->map(function ($a) use ($pakaiAmount, $pakaiEbudget, $pakaiTransaksi) {
            $id = (int) $a->id;
            $k = (string) $a->kode_akun;

            $alasan = [];

            if (isset($pakaiAmount[$id])) {
                $alasan[] = 'punya '.$pakaiAmount[$id].' baris saldo (amount)';
            }

            if (isset($pakaiEbudget[$id])) {
                $alasan[] = 'punya '.$pakaiEbudget[$id].' baris anggaran (ebudgeting)';
            }

            if (isset($pakaiTransaksi[$k])) {
                $alasan[] = 'dipakai '.$pakaiTransaksi[$k].' baris jurnal (transactions)';
            }

            return [
                'id' => $id,
                'kode_akun' => $k,
                'nama_akun' => $a->nama_akun,
                'alasan' => $alasan,
            ];
        })->all();
    }

    /** Apakah tabel opsional ini ada di database lokasi yang sedang aktif. */
    private function adaTabel(mixed $db, string $tabel): bool
    {
        return $db->getSchemaBuilder()->hasTable($tabel);
    }

    /**
     * Hitung berapa baris yang benar-benar memakai tiap akun.
     *
     * `GROUP BY` + `COUNT(*)` sekali jalan, bukan satu query per akun: yang
     * kedua berarti puluhan query per lokasi untuk jawaban yang bisa
     * dijawab dalam satu, dan pemindaian ini berjalan setiap kali dialog
     * konfirmasi dibuka.
     *
     * `$lewatKode` dipakai untuk `transactions`, yang merujuk kode akun
     * sebagai TEKS. Angka dari beberapa kolom dijumlahkan, bukan ditimpa --
     * satu baris jurnal bisa menyebut kode yang sama di `account_debet` dan
     * `account_kredit`, dan menimpa salah satunya membuat angka yang terlewat
     * kecil untuk kode tersebut.
     *
     * @param  string[]  $kolom
     * @param  array{id:int[],kode:string[]}  $terlucut
     * @return array<int|string,int>
     */
    private function hitungPemakaian(
        mixed $db,
        ?string $tabel,
        array $kolom,
        array $terlucut,
        bool $lewatKode = false
    ): array {
        if ($tabel === null) {
            return [];
        }

        $nilai = $lewatKode ? $terlucut['kode'] : $terlucut['id'];

        if ($nilai === []) {
            return [];
        }

        $hasil = [];

        foreach ($kolom as $satu) {
            $baris = $db->table($tabel)
                ->select($satu, DB::raw('COUNT(*) as jml'))
                ->whereIn($satu, $nilai)
                ->groupBy($satu)
                ->get();

            foreach ($baris as $b) {
                $kunci = $lewatKode ? (string) $b->{$satu} : (int) $b->{$satu};

                $hasil[$kunci] = ($hasil[$kunci] ?? 0) + (int) $b->jml;
            }
        }

        return $hasil;
    }

    /**
     * @param  array<int,array<string,mixed>>  $pindaian
     * @return array<int,array<string,mixed>>
     */
    private function kumpulkanBlocker(array $pindaian): array
    {
        $hasil = [];

        foreach ($pindaian as $l) {
            if ($l['error'] !== null) {
                $hasil[] = [
                    'location' => $l['slug'],
                    'kode_akun' => null,
                    'nama_akun' => null,
                    'alasan' => 'database lokasi tidak bisa dibaca ('.$l['error'].')',
                ];

                continue;
            }

            foreach ($l['accounts'] as $akun) {
                foreach ($akun['alasan'] as $alasan) {
                    $hasil[] = [
                        'location' => $l['slug'],
                        'kode_akun' => $akun['kode_akun'],
                        'nama_akun' => $akun['nama_akun'],
                        'alasan' => $alasan,
                    ];
                }
            }
        }

        return $hasil;
    }

    private function pesanDapat(int $level, int $l2, int $l3, int $l4, array $pindaian): string
    {
        $bagian = [];

        if ($l2 > 0) {
            $bagian[] = $l2.' akun level 2';
        }

        if ($l3 > 0) {
            $bagian[] = $l3.' akun level 3';
        }

        if ($l4 > 0) {
            /*
             * Jumlah lokasinya menghitung yang PUNYA akun -- bukan seluruh
             * lokasi yang terdaftar.
             *
             * Dengan dua lokasi terdaftar tapi hanya satu yang punya akun
             * turunan, kalimat "1 akun level 4 di 2 lokasi" secara harfiah
             * benar (akun itu memang tersebar di antara keduanya) tapi
             * menyesatkan: yang terbaca adalah "ada akun di dua tempat",
             * padahal di satu tempat tidak ada apa-apa.
             */
            $lokasiBerisi = count(array_filter(
                $pindaian,
                fn (array $l) => $l['jumlah_level4'] > 0
            ));

            $bagian[] = $l4.' akun level 4 di '.$lokasiBerisi.' lokasi';
        }

        /*
         * "1 akun" saja tidak cukup untuk menilai risiko. Akun level 1 dan 2
         * biasanya tidak punya apa-apa, tapi level 3 bisa menarik seluruh
         * akun level 4 satu lokasi -- itu yang perlu disebut lebih dulu.
         *
         * Urutannya level 4 dulu, karena itu bagian yang paling jarang
         * disadari orang: akun level 4 ada di database yang TIDAK sedang
         * dibuka, jadi cepat atau lambat ia melupakan bahwa akun di
         * database lain ikut hilang.
         */
        if ($bagian === []) {
            return 'Akun ini belum punya anak di mana pun. Hanya baris ini yang dihapus.';
        }

        return 'Menghapus akun level '.$level.' ini sekaligus menghapus '
            .implode(', ', $bagian).'.';
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @param  array<int,array<string,mixed>>  $pindaian
     */
    private function pesanBlocked(array $blockers, array $pindaian): string
    {
        foreach ($pindaian as $l) {
            if ($l['error'] !== null) {
                return 'Penghapusan dibatalkan: database lokasi `'.$l['slug']
                    .'` tidak bisa dibaca. Akun di lokasi itu tidak boleh dibiarkan '
                    .'tanpa induk tanpa bisa diperiksa.';
            }
        }

        $nama = [];

        foreach ($blockers as $b) {
            $nama[] = '`'.$b['kode_akun'].'` ('.$b['alasan'].')';
        }

        $daftar = implode('; ', array_slice($nama, 0, 5));
        $sisanya = count($nama) > 5 ? ' (+'.(count($nama) - 5).' lainnya)' : '';

        return 'Tidak bisa dihapus. Akun di '.count(array_unique(array_column($blockers, 'location')))
            .' lokasi sudah dipakai: '.$daftar.$sisanya
            .'. Data keuangan tidak dihapus otomatis -- tangani dulu di lokasi masing-masing.';
    }
}
