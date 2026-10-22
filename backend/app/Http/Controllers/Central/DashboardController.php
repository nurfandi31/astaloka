<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Agregat untuk dashboard panel pusat.
 *
 * Bentuk endpoint ini mengikuti satu pemakai saja: `CentralDashboardView`.
 * Jadi yang di bawah adalah jawaban atas pertanyaan yang benar-benar ditanya
 * halaman itu, dan bukan daftar semua angka yang bisa dihitung dari tabel
 * `tenants` dan `tenant_invoices`.
 *
 * ==========
 * PEMISAHAN
 * ==========
 *
 * Kenapa endpoint ini ada, dan bukan frontend yang menjumlahkan sendiri
 * -----------------------------------------------------------------------
 *
 * 1. `invoices` dipaginasi 50 baris per halaman. Menjumlahkan dari sana
 *    menghasilkan "total tunggakan" yang sebenarnya hanya 50 tagihan
 *    terbaru. Lokasi yang tagihannya jatuh di luar 50 baris itu terlihat
 *    lunas, dan tidak ada error apa pun yang mengatakannya -- grafiknya
 *    hanya menampilkan angka yang lebih kecil tanpa penjelasan.
 *
 * 2. Chart butuh SEJUMLAH baris. Baris ke-51 dan seterusnya tidak pernah
 *    dikirim, jadi setiap chart yang dibuat dari endpoint paginasi diam-diam
 *    memotong data. Chart yang salah lebih berbahaya daripada tidak ada
 *    chart, karena ia terlihat meyakinkan.
 *
 * 3. `overduePerLokasi` dikirim sebagai peta hitungan, bukan daftar tagihan
 *    yang dipotong. Setiap pemotongan membuat dashboard menampilkan "3
 *    terlambat" untuk lokasi yang sebenarnya punya 9.
 *
 * Pembagian tanggung jawab
 * -------------------------
 * Backend menyediakan angka yang butuh COUNT/SUM dan seluruh riwayat.
 * Frontend hanya membungkus angka itu menjadi config Chart.js dan menentukan
 * warnanya. Tidak ada penjumlahan parsial di sisi klien.
 *
 * ==========
 * RUANG LINGKUP
 * ==========
 *
 * Dashboard ini menjawab lima pertanyaan saja:
 *
 *   1. Keadaan sekarang secara keseluruhan (jumlah lokasi, tunggakan).
 *   2. Komposisi lokasi menurut kondisinya.
 *   3. Komposisi seluruh tagihan menurut kelompok waktunya.
 *   4. Lokasi mana yang paling perlu perhatian, dan berapa tagihannya.
 *   5. -- PER LOKASI -- berapa tagihannya, dan tagihan itu milik siapa.
 *
 * Poin 5 adalah tambahan 2026-10-15, hasilnya ada di `rincianLokasi`. Ada
 * karena pop-up detail di dashboard baru ini menampilkan daftar tagihan satu
 * per satu plus total seluruh tagihan milik lokasi itu -- dan itu harusnya
 * berasal dari tabel yang SAMA dengan angka ringkasan di atas.
 *
 * Kenapa tidak diambil dari `/central/invoices?tenant_id=X` saja
 * ---------------------------------------------------------
 *
 * Dua alasan, dan keduanya soal bentuk, bukan performa:
 *
 * 1. Endpoint itu dipaginasi 50 baris. Lokasi yang sudah berumur tiga tahun
 *    punya lebih dari itu, jadi "total seluruh tagihan" dari sana bukan total
 *    -- dan pop-up yang menampilkan total yang sebenarnya parsial lebih buruk
 *    daripada tidak menampilkan total sama sekali.
 *
 * 2. Kalau dipanggil per lokasi dari frontend, membuka pop-up satu tenant akan
 *    menjadi satu request jaringan tambahan. Di halaman yang menampilkan
 *    daftar semua tenant sekaligus, pop-up harus muncul tanpa jeda yang terasa.
 *
 * Yang dikirim BUKAN daftar tagihan lengkap, dan itu disengaja: yang dikirim
 * adalah AGREGAT per lokasi (jumlah dan nominal per kelompok waktu). Daftar
 * tagihannya tetap diambil terpisah saat pop-up dibuka, lewat endpoint
 * `invoices` yang sudah dipaginasi dan sudah punya filter `tenant_id`. Dengan
 * begitu daftar panjang tidak pernah ikut inflating respons yang di-poll
 * setiap 30 detik -- lihat catatan `RIWAYAT_PER_LOKASI` di bawah untuk
 * analoginya.
 *
 * Total tagihan per lokasi mencakup yang sudah lunas. Itu beda dari
 * "tunggakan", dan bedanya penting: tunggakan menjawab "berapa yang masih
 * harus dibayar", sedangkan total menjawab "berapa yang pernah ditagihkan".
 * Pop-up menampilkan keduanya dengan label berbeda supaya tidak tertukar.
 */
class DashboardController extends Controller
{
    /** Tagihan yang jatuh tempo dalam N hari ke depan masih dihitung "segera". */
    private const SEGERA_HARI = 14;

    /** Langganan yang berakhir dalam N hari ke depan dihitung "hampir habis". */
    private const HAMPIR_HABIS_HARI = 30;

    /** Jumlah lokasi yang ditampilkan di chart tunggakan. */
    private const TOP_TUNGGAKAN = 8;

    /**
     * Jumlah tagihan terbaru per lokasi yang ikut dikirim di `rincianLokasi`.
     *
     * Dipotong, dan TIDAK ada tanda "dipotong" di respons -- pemanggil wajib
     * memperlakukannya sebagai sampel, bukan riwayat. Ini berbeda dari
     * `overduePerLokasi` yang sengaja exact: di situ angka yang dipotong akan
     * tampil sebagai angka yang salah di kolom yang sedang dibaca orang,
     * sedangkan di sini yang dipotong adalah daftar per-tagihan di dalam
     * pop-up yang memang punya batas tampil.
     *
     * Yang tidak dipotong adalah AGREGAT: jumlah dan nominal lunas /
     * belum lunas / terlambat selalu dihitung dari seluruh baris. Pop-up
     * menampilkan total dari aggregate itu, bukan dari menjumlahkan daftar yang
     * sudah dipotong.
     */
    private const RIWAYAT_PER_LOKASI = 6;

    public function index(): JsonResponse
    {
        $lokasi = Tenant::query()
            ->withCount(['unpaidInvoices as tagihan_belum_lunas'])
            ->withSum(
                ['invoices as total_tagihan' => fn ($q) => $q->where('status', 'UNPAID')],
                'jumlah'
            )
            ->orderBy('id')
            ->get();

        // `sisa_hari` dihitung sekali di sini dan dipakai ulang di setiap
        // tempat yang butuh. Di frontend nilai ini dihitung ulang per kategori
        // chart, jadi satu lokasi bisa sampai dihitung lima kali dalam satu
        // render hanya untuk hal yang sama.
        foreach ($lokasi as $t) {
            $t->setAttribute('sisa_hari', $this->hariTersisa($t->tgl_berakhir));
            $t->setAttribute('db_ada', $t->databaseExists());
        }

        $unpaid = $this->tagihanBelumLunas();
        $kondisi = $this->kondisiLokasi($lokasi);

        return response()->json([
            'success' => true,
            'data' => [
                'ringkasan' => [
                    'totalLokasi' => $lokasi->count(),
                    'lokasiAktif' => $lokasi->where('is_active', true)->count(),
                    'lokasiNonaktif' => $lokasi->where('is_active', false)->count(),
                    'nilaiTunggakan' => round((float) $unpaid->sum('jumlah'), 2),
                    'tagihanBelumLunas' => $unpaid->count(),
                    'nilaiTerlambat' => round((float) $unpaid->where('terlambat', true)->sum('jumlah'), 2),
                    'tagihanTerlambat' => $unpaid->where('terlambat', true)->count(),
                    'lokasiSehat' => $kondisi['healthy'],
                    'lokasiMasalah' => $lokasi->count() - $kondisi['healthy'],

                    // Total SELURUH tagihan yang pernah terbit, lunas ikut
                    // dihitung. Tidak sama dengan `nilaiTunggakan`: yang satu
                    // "belum dibayar", yang lain "pernah ditagihkan". Keduanya
                    // dipakai dashboard -- yang tunggakan untuk kartu
                    // ringkasan, yang ini untuk panel "total tagihan".
                    'nilaiTotalTagihan' => $this->nilaiSeluruhTagihan(),
                    'jumlahTagihanDiterbitkan' => TenantInvoice::query()->count(),
                ],

                'kondisiLokasi' => $this->daftarKondisiLokasi($kondisi),
                'statusTagihan' => $this->daftarStatusTagihan($this->ringkasanTagihan($unpaid)),
                'tunggakanLokasi' => $this->daftarTunggakanLokasi($lokasi),
                'overduePerLokasi' => $this->overduePerLokasi($unpaid),
                'rincianLokasi' => $this->rincianPerLokasi($lokasi),

                'diperbaruiPada' => now()->toIso8601String(),
            ],
        ]);
    }

    /* ================================================================
     | LOKASI
     *============================================================
     */

    /**
     * Kategori lokasi, satu lokasi masuk tepat satu kategori.
     *
     * Sifat "tepat satu" itu yang membuat total kategori selalu sama dengan
     * jumlah lokasi. Kalau satu lokasi boleh masuk dua kategori (mis. nonaktif
     * DAN database hilang), jumlah irisannya melebihi jumlah lokasi dan pie
     * menampilkan komposisi yang tidak pernah ada.
     *
     * Urutan pemeriksaan di bawah adalah urutan KEPUTUSAN, bukan urutan
     * prioritas tampilan: kategori pertama yang cocok menang. `db_ada` dicek
     * paling dulu karena lokasi tanpa database tidak bisa dipakai sama
     * sekali -- jadi status "nonaktif" miliknya tidak relevan di depan
     * masalah yang lebih besar.
     */
    private function kondisiLokasi($lokasi): array
    {
        $broken = 0;
        $expired = 0;
        $off = 0;
        $due = 0;
        $expiring = 0;

        foreach ($lokasi as $t) {
            if (! $t->db_ada) {
                $broken++;

                continue;
            }

            if ($t->sisa_hari !== null && $t->sisa_hari < 0) {
                $expired++;

                continue;
            }

            if (! $t->is_active) {
                $off++;

                continue;
            }

            if ((int) $t->tagihan_belum_lunas > 0) {
                $due++;

                continue;
            }

            if ($t->sisa_hari !== null && $t->sisa_hari <= self::HAMPIR_HABIS_HARI) {
                $expiring++;

                continue;
            }
        }

        // Lokasi tanpa `tgl_berakhir` dan tanpa masalah lain ikut dihitung
        // sebagai sehat: langganannya memang tidak punya batas, jadi tidak
        // ada tanggal yang bisa "hampir habis".
        $total = $lokasi->count();
        $healthy = max(0, $total - $broken - $expired - $off - $due - $expiring);

        return compact('broken', 'expired', 'off', 'due', 'expiring', 'healthy', 'total');
    }

    /**
     * Bentuk kirim untuk chart komposisi lokasi.
     *
     * Warna TIDAK ikut dikirim. Pilihan warnanya milik frontend karena harus
     * ikut token CSS aplikasi, sedangkan di backend tidak ada akses ke itu --
     * dan warna hex yang dipatok di sini akan jadi warna yang tidak pernah
     * berubah saat palet aplikasi berubah.
     *
     * Kategori bernilai nol dibuang: irisan setebal nol hanya menambah satu
     * warna di legenda dan memotong sudut yang sebenarnya bisa dipakai
     * kategori yang punya nilai.
     */
    private function daftarKondisiLokasi(array $k): array
    {
        $rows = [
            ['key' => 'healthy', 'label' => 'Sehat', 'nilai' => $k['healthy']],
            ['key' => 'expiring', 'label' => 'Hampir Habis', 'nilai' => $k['expiring']],
            ['key' => 'due', 'label' => 'Ada Tunggakan', 'nilai' => $k['due']],
            ['key' => 'off', 'label' => 'Nonaktif', 'nilai' => $k['off']],
            ['key' => 'expired', 'label' => 'Langganan Habis', 'nilai' => $k['expired']],
            ['key' => 'broken', 'label' => 'Database Hilang', 'nilai' => $k['broken']],
        ];

        return array_values(array_filter($rows, fn ($r) => $r['nilai'] > 0));
    }

    /**
     * Lokasi dengan tunggakan terbesar, paling banyak delapan.
     *
     * Dipotong ke delapan karena inilah batas yang muat pada tinggi kanvas
     * tanpa labelnya saling tumpang tindih. Lokasi di bawah ambang tidak
     * hilang: jumlahnya ada di kartu ringkasan, dan daftar lengkapnya ada di
     * halaman lokasi yang memang bisa difilter dan diurutkan.
     */
    private function daftarTunggakanLokasi($lokasi): array
    {
        return $lokasi
            ->map(fn (Tenant $t) => [
                'id' => $t->id,
                'label' => $t->label(),
                'slug' => $t->slug,
                'nilai' => round((float) ($t->getAttribute('total_tagihan') ?? 0), 2),
            ])
            ->filter(fn ($r) => $r['nilai'] > 0)
            ->sortByDesc('nilai')
            // `values()` wajib setelah `filter`: tanpa itu kunci array ikut
            // terbawa (id tenant yang tidak berurutan), dan frontend memakai
            // indeks array untuk label sumbu.
            ->values()
            ->take(self::TOP_TUNGGAKAN)
            ->values()
            ->all();
    }

    /* ================================================================
     | RINGKASAN BIAYA PER LOKASI
     |============================================================
     */

    /**
     * Ringkasan tagihan milik setiap lokasi, untuk pop-up detail.
     *
     * Satu query mengambil seluruh invoice -- termasuk yang sudah lunas --
     * lalu dikelompokkan di memori per `tenant_id`. Dipilih dibanding satu
     * query SUM per lokasi karena itu berarti satu query per tenant, dan
     * dashboard ini dipoll setiap 30 detik: dengan 20 lokasi itu jadi 20
     * query tambahan tiap 30 detik hanya untuk mengisi panel yang biasanya
     * sedang tertutup.     *
     * Bentuk per lokasi:
     *
     *   belumLunas  { jumlah, nominal }
     *   terlambat   { jumlah, nominal }
     *   lunas       { jumlah, nominal }
     *   total       { jumlah, nominal }   -- lunas + belum lunas
     *   terbaru     [ ...max 6 tagihan... ]
     *
     * `total` = lunas + belum lunas, dan itu disengaja: yang disebut "total
     * tagihan" di layar adalah seluruh yang PERNAH ditagihkan ke lokasi itu,
     * bukan yang masih tersisa. `belumLunas` untuk yang masih harus dibayar.
     * Kalau `total` dihitung sebagai `belumLunas` saja, angka "total" di
     * pop-up akan sama persis dengan kartu "tunggakan" di atas -- dua nama,
     * satu angka, dan yang berbeda hanya keterangan kecilnya.
     *
     * `terlambat` adalah SUBDIRISI dari `belumLunas`, bukan kelompok terpisah,
     * dan tidak ikut dijumlahkan ke `total` untuk menghindari penghitungan
     * ganda.
     *
     * `terbaru` sengaja dipotong. Alasannya sama dengan `TOP_TUNGGAKAN` di
     * chart: daftar yang tidak dipotong ikut di respons yang di-poll tiap 30
     * detik akan jadi payload yang tumbuh terus. Yang dipotong adalah
     * DAFTAR; semua ANGKA di atas tetap dihitung dari seluruh baris.
     */
    private function rincianPerLokasi($lokasi): array
    {
        $semua = TenantInvoice::query()
            ->orderByDesc('tgl_invoice')
            ->orderByDesc('id')
            ->get()
            ->map(function (TenantInvoice $i) {
                $i->setAttribute('terlambat', $i->isOverdue());

                return $i;
            });

        $perTenant = $semua->groupBy('tenant_id');

        $hasil = [];

        foreach ($lokasi as $t) {
            $inv = $perTenant->get($t->id, collect());
            $unpaid = $inv->where('status', 'UNPAID');
            $terlambat = $unpaid->where('terlambat', true);

            $kelompok = fn ($rows) => [
                'jumlah' => $rows->count(),
                'nominal' => round((float) $rows->sum('jumlah'), 2),
            ];

            $hasil[$t->id] = [
                'id' => $t->id,
                'slug' => $t->slug,
                'label' => $t->label(),

                /*
                 * Profil usaha.
                 *
                 * `usaha` adalah hasil fallback `usahaLabel()` -- nama yang
                 * paling spesifik yang tersedia. Dua kolom aslinya ikut
                 * dikirim SEBAGIMANYA, karena `usaha` selalu terisi sedangkan
                 * "belum diisi" adalah informasi yang berbeda: `usaha` yang
                 * berisi nama tenant berarti profil usaha belum diisi, dan
                 * pop-up perlu bisa mengatakan itu secara jujur -- bukan
                 * menampilkan nama tenant seolah-olah itu jawaban "usaha
                 * apa".
                 */
                'usaha' => $t->usahaLabel(),
                'bidang_usaha' => $t->bidang_usaha,
                'deskripsi_usaha' => $t->deskripsi_usaha,
                'sektor' => $t->sektor,

                'alamat' => $t->alamat,
                'email' => $t->email,
                'telepon' => $t->telepon,
                'domain' => $t->domain,
                'database' => $t->database,
                'database_ada' => $t->db_ada,
                'is_active' => $t->is_active,
                'tgl_berakhir' => $t->tgl_berakhir?->toDateString(),

                /*
                 * Dua field ini DUPLIKAT dari `belumLunas.jumlah` dan
                 * `belumLunas.nominal` -- dengan sengaja.
                 *
                 * `belumLunas` menjawab "berapa yang belum dibayar" dalam
                 * bentuk kelompok, sementara `tagihan_belum_lunas` /
                 * `total_tagihan` menjawab pertanyaan yang lebih sempit
                 * ("berapa tagihan yang belum lunas") dan sudah jadi
                 * bagian dari kontrak `/central/locations` sejak sebelum
                 * dashboard ini ditulis.
                 *
                 * Keduanya dihitung dari sumber yang sama (tabel
                 * `tenant_invoices` dengan filter status yang sama), jadi
                 * tidak mungkin berbeda. Kalau nanti salah satu berubah
                 * sumber, keduanya bisa menyimpang -- dan angka yang sama akan
                 * tampil sebagai dua nilai berbeda di dua tempat pada layar
                 * yang sama.
                 */
                'tagihan_belum_lunas' => (int) $t->getAttribute('tagihan_belum_lunas'),
                'total_tagihan' => round((float) ($t->getAttribute('total_tagihan') ?? 0), 2),

                'belumLunas' => $kelompok($unpaid),
                'terlambat' => $kelompok($terlambat),
                'lunas' => $kelompok($inv->where('status', 'PAID')),

                // Seluruh tagihan yang pernah terbit, lunas ikut dihitung.
                'total' => [
                    'jumlah' => $inv->count(),
                    'nominal' => round((float) $inv->sum('jumlah'), 2),
                ],

                'terbaru' => $inv
                    ->take(self::RIWAYAT_PER_LOKASI)
                    ->map(fn (TenantInvoice $i) => [
                        'id' => $i->id,
                        'nomor' => $i->nomor,
                        'periode' => $i->periode,
                        'keterangan' => $i->keterangan,
                        'jumlah' => (float) $i->jumlah,
                        'status' => $i->status,
                        'tgl_invoice' => $i->tgl_invoice?->toDateString(),
                        'tgl_jatuh_tempo' => $i->tgl_jatuh_tempo?->toDateString(),
                        'deadline' => $i->deadline()->toDateString(),
                        'terlambat' => (bool) $i->terlambat,
                        'sisa_hari' => $i->daysRemaining(),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $hasil;
    }

    /* ================================================================
     | TAGIHAN
     *============================================================
     */

    /**
     * Semua tagihan UNPAID, lengkap dengan sisa hari dan tanda terlambat.
     *
     * Penanda `terlambat` dihitung di PHP, bukan lewat kondisi tanggal di
     * SQL, karena batas tagihan bisa datang dari dua sumber:
     * `tgl_jatuh_tempo` kalau diisi, atau `tgl_invoice` + jumlah bulan
     * toleransi dari config kalau tidak (lihat
     * `TenantInvoice::deadline()`). Config tidak bisa diterjemahkan jadi
     * kondisi SQL tanpa menyalin aturan yang sama di dua tempat -- dan dua
     * salinan aturan yang sama akan berbeda begitu satu satunya diubah.
     *
     * Hanya UNPAID yang diambil, dan itu penting untuk batas muatannya:
     * tagihan lunas bisa bertumbuh tanpa henti selama bertahun-tahun,
     * sedangkan tagihan yang belum dibayar mengikuti jumlah lokasi, yaitu
     * besaran yang memang sudah muat di satu request.
     *
     * Relasi `tenant` sengaja tidak di-eager-load. kolom ini hanya butuh
     * `tenant_id` untuk dipetakan lewat `overduePerLokasi()`; nama lokasi
     * sudah ada di kumpulan `$lokasi` yang diambil terpisah, jadi memuatnya
     * di sini hanya menambah satu query tanpa menambah satu pun data yang
     * dipakai.
     */
    private function tagihanBelumLunas()
    {
        return TenantInvoice::query()
            ->unpaid()
            ->orderByDesc('tgl_invoice')
            ->orderByDesc('id')
            ->get()
            ->map(function (TenantInvoice $i) {
                $i->setAttribute('terlambat', $i->isOverdue());
                $i->setAttribute('sisa_hari', $i->daysRemaining());

                return $i;
            });
    }

    /**
     * Jumlah tagihan terlambat per lokasi -- PERSIS, bukan sampel.
     *
     * Ini dikirim sebagai peta `tenant_id => jumlah`, bukan daftar tagihan
     * yang dipotong oleh frontend. Alasannya bukan hemat payload: daftar
     * tagihan hanya bisa dipotong supaya muat, dan setiap pemotongan membuat
     * frontend menampilkan "3 terlambat" untuk lokasi yang sebenarnya punya 9.
     * Peta dihitung dari seluruh baris UNPAID, jadi angkanya benar tanpa
     * perlu penanda "setidaknya".
     */
    private function overduePerLokasi($unpaid): object
    {
        $peta = $unpaid
            ->filter(fn ($i) => $i->terlambat)
            ->groupBy('tenant_id')
            ->map->count()
            ->all();

        // `(object)` wajib, bukan array kosong. Array kosong di-JSON menjadi
        // `[]`, dan `[]?.[3]` di JavaScript menghasilkan `undefined` -- sama
        // seperti object kosong. Bedanya ada di maknanya: `[]` berarti daftar,
        // `{}` berarti peta. Peta yang kosong harus tetap dikirim sebagai `{}`.
        return (object) $peta;
    }

    /** Bagi tagihan UNPAID ke tiga kelompok waktu, plus total yang sudah lunas. */
    private function ringkasanTagihan($unpaid): array
    {
        $terlambat = $unpaid->filter(fn ($i) => $i->terlambat);
        $segera = $unpaid->filter(fn ($i) => ! $i->terlambat && $i->sisa_hari <= self::SEGERA_HARI);

        // "Aman" adalah sisa yang bukan dua kelompok di atas. Ditulis sebagai
        // penolakan (reject), bukan pengurangan jumlah, supaya tidak pernah
        // menjadi negatif kalau nanti kedua filter itu tumpang tindih.
        $aman = $unpaid->reject(fn ($i) => $i->terlambat || $i->sisa_hari <= self::SEGERA_HARI);

        $kelompok = fn ($koleksi) => [
            'jumlah' => $koleksi->count(),
            'nominal' => round((float) $koleksi->sum('jumlah'), 2),
        ];

        $lunas = TenantInvoice::query()->where('status', 'PAID');

        return [
            'terlambat' => $kelompok($terlambat),
            'segera' => $kelompok($segera),
            'aman' => $kelompok($aman),
            'lunas' => [
                'jumlah' => (clone $lunas)->count(),
                'nominal' => round((float) (clone $lunas)->sum('jumlah'), 2),
            ],
        ];
    }

    private function daftarStatusTagihan(array $t): array
    {
        $rows = [
            ['key' => 'aman', 'label' => 'Aman', 'nilai' => $t['aman']['jumlah'], 'nominal' => $t['aman']['nominal']],
            [
                'key' => 'segera',
                'label' => 'Jatuh Tempo '.self::SEGERA_HARI.' Hari',
                'nilai' => $t['segera']['jumlah'],
                'nominal' => $t['segera']['nominal'],
            ],
            ['key' => 'terlambat', 'label' => 'Terlambat', 'nilai' => $t['terlambat']['jumlah'], 'nominal' => $t['terlambat']['nominal']],
            ['key' => 'lunas', 'label' => 'Sudah Lunas', 'nilai' => $t['lunas']['jumlah'], 'nominal' => $t['lunas']['nominal']],
        ];

        return array_values(array_filter($rows, fn ($r) => $r['nilai'] > 0));
    }

    /* ================================================================
     | UTILITAS
     *============================================================
     */

    /**
     * Total nominal seluruh tagihan yang PERNAH terbit, lunas ikut dihitung.
     *
     * Dipisah dari `ringkasanTagihan()` yang sudah punya `lunas` karena
     * keduanya menjawab pertanyaan berbeda dan dihitung dari query berbeda:
     * ini satu SUM tanpa filter, sedangkan `lunas` di sana ikut menanggung
     * `WHERE status = 'PAID'` -- dan di sini tidak ada PAID di mana pun.
     *
     * Satu query, bukan menjumlahkan `lunas` + `belumLunas` dari
     * `ringkasanTagihan()`: kalau nanti ada status tagihan ketiga di luar
     * UNPAID/PAID, penjumlahan manual diam-diam akan jadi tidak sama dengan
     * COUNT/SUM seluruh tabel -- dan tidak ada yang akan memberi tahu.
     */
    private function nilaiSeluruhTagihan(): float
    {
        return round((float) TenantInvoice::query()->sum('jumlah'), 2);
    }

    /**
     * Sisa hari dari tengah malam.
     *
     * `tgl_berakhir` cuma tanggal, tanpa jam. Kalau selisihnya dihitung dari
     * `now()`, lokasi yang berakhirnya besok sore terbaca "0 hari lagi" --
     * dan yang berakhir hari ini terbaca "-1", seolah sudah lewat padahal belum.
     * Menyamakan jam ke 00:00 lokal membuat hasilnya sama dengan yang orang
     * baca sendiri di kalender.
     */
    private function hariTersisa(?Carbon $tgl): ?int
    {
        if (! $tgl) {
            return null;
        }

        return (int) today()->diffInDays($tgl->copy()->startOfDay(), false);
    }
}
