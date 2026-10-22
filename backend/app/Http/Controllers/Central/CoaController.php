<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\AkunLevel1;
use App\Models\AkunLevel2;
use App\Models\AkunLevel3;
use App\Services\AuditLogger;
use App\Services\CoaCascadeException;
use App\Services\CoaCascadeService;
use App\Support\JenisMutasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel pusat: pengelolaan struktur akun level 1-3.
 *
 * Level 1-3 adalah KERANGKA chart of accounts yang sama untuk semua lokasi,
 * jadi disimpan sekali di database pusat. Level 4 (`accounts`) tetap per
 * lokasi -- tiap lokasi boleh menambah atau menyesuaikan akunnya sendiri.
 * Lihat {@see PohonAkunService} untuk bagaimana pohon laporan menyambung
 * kedua database itu.
 *
 * ATURAN YANG TAK BOLEH DILANGGAR
 *
 * `accounts.parent_id` di database SETIAP lokasi menunjuk `akun_level_3.id`
 * milik pusat -- dan TIDAK ada foreign key yang menegakkannya. Verified di
 * DB: nol constraint, nol dangling. Konsekuensinya:
 *
 *   Mengubah atau menghapus `id` level 3 --> `accounts.parent_id` di semua
 *   lokasi menjadi yatim. Tidak ada error. Laporan hanya tiba-tiba kosong
 *   sebagian, tanpa satu pun pesan yang menunjukkan kegagalan.
 *
 * Jadi `id` dan `parent_id` diperlakukan SEBAGAI BEKU di sini: panel pusat
 * tidak pernah menyuntingnya, dan tidak ada endpoint yang memindahkan induk
 * sebuah baris ke tempat lain.
 *
 * Yang BOLEH dilakukan -- dan itu keputusan sadar, bukan kelalaian -- adalah
 * menambah dan menghapus baris, dengan dua syarat:
 *
 *   1. Penghapusan berjenjang. Menghapus level 1 atau 2 menghapus seluruh
 *      turunannya di pusat; menghapus level 3 ikut menghapus akun level 4
 *      yang menunjuknya di SETIAP lokasi. Daun lebih dulu, batang
 *      kemudian. Lihat {@see CoaCascadeService}.
 *
 *   2. Data yang sudah dibukukan tidak ikut hilang. Kalau akun level 4 sudah
 *      punya `amount`, `ebudgeting`, atau `transactions`, penghapusan
 *      DITOLAK dengan 409 yang menyebut akun mana, di lokasi mana, dan
 *      kenapa terhalang. Inilah yang membuat aturan "hapus berjenjang" tetap
 *      aman: yang hilang adalah struktur yang tidak dipakai, bukan
 *      pembukuan yang sudah terjadi.
 *
 * Menambah baris aman karena `id` yang baru tidak pernah diawali oleh akun
 * mana pun -- account yang menunjuk `id` yang belum ada tidak dapat terjadi,
 * dan tidak ada baris di lokasi yang perlu diperbarui.
 */
class CoaController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private CoaCascadeService $cascade,
    ) {}

    /**
     * Struktur level 1-3 lengkap, sebagai pohon.
     *
     * Satu payload berisi ketiga tingkat supaya frontend tidak perlu tiga
     * request dan bisa merender pohon langsung. `parent` diambil lewat
     * relasi (`with('parent')`), yang aman karena level 1-2-3 semuanya di
     * database yang sama.
     */
    public function index(): JsonResponse
    {
        $level1 = AkunLevel1::query()
            ->orderBy('kode_akun')
            ->get();

        $level2 = AkunLevel2::query()
            ->whereIn('parent_id', $level1->pluck('id'))
            ->orderBy('kode_akun')
            ->get()
            ->groupBy('parent_id');

        $level3 = AkunLevel3::query()
            ->whereIn('parent_id', $level2->flatten()->pluck('id'))
            ->orderBy('kode_akun')
            ->get()
            ->groupBy('parent_id');

        return response()->json([
            'success' => true,
            'data' => [
                'level1' => $level1->map(fn (AkunLevel1 $m) => $this->present($m, 1)),
                'level2' => $level2->flatten()->values()
                    ->map(fn (AkunLevel2 $m) => $this->present($m, 2)),
                'level3' => $level3->flatten()->values()
                    ->map(fn (AkunLevel3 $m) => $this->present($m, 3)),
                'tree' => $this->buildTree($level1, $level2, $level3),
            ],
        ]);
    }

    /**
     * Satu baris level 1-3 untuk diedit.
     *
     * Route dipisah per tingkat (`/coa/level/{level}/{id}`) supaya level
     * tidak pernah datang dari body request yang bisa dipalsui -- kalau
     * level ikut ditentukan klien, satu request bisa menulis baris level 1
     * ke tabel level 3 tanpa jejak.
     */
    public function show(int $level, int $id): JsonResponse
    {
        $model = $this->modelFor($level);

        $row = $model::query()->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->present($row, $level),
        ]);
    }

    /**
     * Ubah satu baris struktur akun.
     *
     * HANYA kolom yang aman: nama, kode, jenis mutasi, posisi. `id` dan
     * `parent_id` tidak ada di `$fillable` daftar di bawah dan tidak pernah
     * dibaca dari payload, jadi tidak bisa diubah meski dialects sengaja
     * mengirim field itu.
     */
    public function update(Request $request, int $level, int $id): JsonResponse
    {
        $rules = [
            1 => [
                'kode_akun' => ['sometimes', 'required', 'string', 'max:10'],
                'nama_akun' => ['sometimes', 'required', 'string', 'max:100'],
                'jenis_mutasi' => ['sometimes', 'required', 'string', 'max:6'],
            ],
            2 => [
                'kode_akun' => ['sometimes', 'required', 'string', 'max:10'],
                'nama_akun' => ['sometimes', 'required', 'string', 'max:100'],
                'jenis_mutasi' => ['sometimes', 'required', 'string', 'max:6'],
            ],
            3 => [
                'kode_akun' => ['sometimes', 'required', 'string', 'max:10'],
                'nama_akun' => ['sometimes', 'required', 'string', 'max:100'],
                'jenis_mutasi' => ['sometimes', 'required', 'string', 'max:6'],
                'posisi' => ['sometimes', 'required', 'integer', 'min:0', 'max:9'],
            ],
        ];

        /*
         * `modelFor()` dipanggil LEBIH DAHULU dari `$rules[$level]`.
         *
         * `modelFor()` adalah satu-satunya tempat yang menolak level di luar
         * 1-3. Kalau urutannya dibalik -- validate dulu, baru modelFor -- maka
         * `PATCH /coa/level/9/1` menyentuh `$rules[9]` yang tidak ada dan
         * berakhir sebagai `Undefined array key` 500. Route-nya memang
         * dibatasi `whereNumber('level')`, tapi itu hanya menjamin level
         * adalah angka, bukan angka yang punya tabel.
         */
        $model = $this->modelFor($level);

        $this->rejectStructuralEdits($request);

        $data = $request->validate($rules[$level]);

        $this->normalizeJenisMutasi($data);

        $row = $model::query()->findOrFail($id);

        // Disalin sebelum update: setelah `update()`, atribut di model sudah
        // berisi nilai baru sehingga `diff()` tidak menemukan apa pun.
        $sebelum = $row->only(array_keys($data));

        $row->update($data);

        $perubahan = AuditLogger::diff($sebelum, $row->only(array_keys($data)));

        if ($perubahan !== []) {
            $this->audit->record(
                "coa.level{$level}.update",
                subjectType: $model,
                subjectId: $row->id,
                description: "Struktur akun level {$level} `{$row->kode_akun}` diperbarui ("
                    .implode(', ', array_keys($perubahan)).'). Berlaku untuk semua lokasi.',
                properties: [
                    'level' => $level,
                    'kode_akun' => $row->kode_akun,
                    'changes' => $perubahan,
                ],
            );
        }

        // Tidak ada perubahan? Jawab apa adanya, jangan tetap bilang
        // "berhasil diperbarui".
        //
        // Akun ini berlaku untuk SEMUA lokasi, jadi panel pusat bukan tempat
        // untuk mencoba-coba. Kalau klien mengirim nilai yang sama dengan yang
        // sudah ada, respons "berhasil" yang tidak menjelaskan apa pun membuat
        // orang mengira datanya sudah ditulis, padahal tidak ada satu baris pun
        // yang tersentuh -- dan tidak ada jejak audit untuk dicari saat
        // menyelidiki kenapa efeknya tidak muncul.
        $pesan = $perubahan === []
            ? 'Tidak ada perubahan: nilai yang dikirim sudah sama dengan yang tersimpan.'
            : 'Struktur akun diperbarui. Berlaku untuk semua lokasi.';

        return response()->json([
            'success' => true,
            'changed' => $perubahan !== [],
            'message' => $pesan,
            'data' => $this->present($row->fresh(), $level),
        ]);
    }

    /**
     * Ringkasan dampak menghapus satu baris, untuk dialog konfirmasi.
     *
     * Endpoint terpisah dari `destroy` karena frontend perlu DUA hal yang
     * berbeda: tahu dampaknya sebelum menekan tombol, dan tahu apakah itu
     * diperbolehkan. Keduanya dijawab dalam satu request supaya tidak ada
     * kemungkinan dialog menghitung dari pemindaian yang berbeda dengan
     * pemindaian yang benar-benar dipakai untuk menghapus -- dan seperti
     * hal lain di kelas ini, perbedaan semecil itu berarti superadmin
     * mengira sedang menghapus satu baris padahal sebenarnya menghapus
     * semuanya.
     */
    public function impact(int $level, int $id): JsonResponse
    {
        $model = $this->modelFor($level);

        // 404 kalau barisnya memang tidak ada, bukan dampak kosong: dialog
        // yang dibuka untuk baris yang sudah hilang adalah kebuntuan, bukan
        // "aman dihapus".
        $row = $model::query()->findOrFail($id);

        $hasil = $this->cascade->impact($level, $row->id);

        return response()->json([
            'success' => true,
            'data' => $hasil + [
                'kode_akun' => $row->kode_akun,
                'nama_akun' => $row->nama_akun,
            ],
        ]);
    }

    /**
     * Tambah satu baris struktur akun.
     *
     * Level 2 dan 3 wajib menyertakan `parent_id`, dan induknya diperiksa
     * benar-benar ada -- bukan hanya "angka yang valid". Tanpa itu, POST
     * dengan `parent_id` menunjuk baris yang sudah dihapus akan tersimpan,
     * dan baris itu tidak akan pernah muncul di pohon -- `index()` hanya
     * membaca turunannya lewat `whereIn('parent_id', ...)` milik induk yang
     * sudah tidak ada. Akun seperti itu tidak terlihat di panel, tidak bisa
     * dihapus dari panel, dan tidak bisa ditemukan lewat pencarian.
     *
     * `jenis_mutasi` mewarisi induk kalau tidak diisi, dan TIDAK ditebak dari
     * nama akun. Kolom ini menentukan arah saldo di laporan, jadi nilai
     * bawaan yang keliru akan menulis nilai yang salah ke setiap turunan
     * tanpa satu pun yang bisa memperbaikinya -- dan tidak ada jejak yang
     * menunjukkan itu terjadi, karena barisnya terlihat benar.
     */
    public function store(Request $request, int $level): JsonResponse
    {
        $rules = [
            1 => [
                'kode_akun' => ['required', 'string', 'max:10'],
                'nama_akun' => ['required', 'string', 'max:100'],
                'jenis_mutasi' => ['nullable', 'string', 'max:6'],
            ],
            2 => [
                'parent_id' => ['required', 'integer'],
                'kode_akun' => ['required', 'string', 'max:10'],
                'nama_akun' => ['required', 'string', 'max:100'],
                'jenis_mutasi' => ['nullable', 'string', 'max:6'],
            ],
            3 => [
                'parent_id' => ['required', 'integer'],
                'kode_akun' => ['required', 'string', 'max:10'],
                'nama_akun' => ['required', 'string', 'max:100'],
                'jenis_mutasi' => ['nullable', 'string', 'max:6'],
                'posisi' => ['nullable', 'integer', 'min:0', 'max:9'],
            ],
        ];

        /*
         * `modelFor()` lebih dulu dari `$rules[$level]`, sama seperti
         * `update()` -- urutannya dibalik berakhir sebagai
         * `Undefined array key` 500 untuk level yang bukan angka.
         */
        $model = $this->modelFor($level);

        $data = $request->validate($rules[$level]);

        $induk = $this->cariInduk($level, $data['parent_id'] ?? null);

        /*
         * `jenis_mutasi` mewarisi induk kalau tidak dikirim. Lihat catatan
         * di docblock: kolom ini menentukan arah saldo, jadi menebaknya
         * lebih berbahaya daripada memintanya.
         */
        if (! array_key_exists('jenis_mutasi', $data) || $data['jenis_mutasi'] === null || $data['jenis_mutasi'] === '') {
            $data['jenis_mutasi'] = JenisMutasi::normalisasi($induk?->jenis_mutasi);
        } else {
            $data['jenis_mutasi'] = JenisMutasi::normalisasi($data['jenis_mutasi']);
        }

        $data = $this->lengkapiLev($level, $induk, $data);

        $baris = $model::query()->create($data);

        $this->audit->record(
            "coa.level{$level}.create",
            subjectType: $model,
            subjectId: $baris->id,
            description: "Struktur akun level {$level} `{$baris->kode_akun}` ditambahkan"
                .($induk ? " di bawah `{$induk->kode_akun}`" : '').'. Berlaku untuk semua lokasi.',
            properties: [
                'level' => $level,
                'kode_akun' => $baris->kode_akun,
                'nama_akun' => $baris->nama_akun,
                'parent_id' => $induk?->id,
                'parent_kode' => $induk?->kode_akun,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => "Akun level {$level} `{$baris->kode_akun}` ditambahkan.",
            'data' => $this->present($baris, $level),
        ], 201);
    }

    /**
     * Hapus satu baris struktur akun, beserta seluruh turunannya.
     *
     * Penghapusan berjenjang -- termasuk akun level 4 di setiap lokasi yang
     * menunjuk baris ini atau turunannya. Yang menjaga keadaan data
     * bookkeeping ada di {@see CoaCascadeService}: akun level 4 yang sudah
     * punya `amount`/`ebudgeting`/`transactions` menahan penghapusan dan
     * dijawab 409.
     *
     * `confirm` WAJIB berisi kode akun yang dihapus, dan nilainya dibandingkan,
     * bukan sekadar dicek ada/tidaknya. Penghapusan ini tidak punya tempat
     * sampah, tidak ada soft delete, dan satu klik salah sasaran menghapus akun
     * lengkap dengan akun-akun turunannya di semua lokasi. Meminta orang
     * mengetik ulang kode yang sedang dihapus adalah satu-satunya penghalang
     * antara niat dan kehancuran yang tidak bisa ditembus klik salah tempat.
     *
     * Bandingkan ISI-nya, bukan hanya keberadaan `confirm`: aturan validasi
     * `required` hanya menyatakan bahwa ada isinya, jadi `confirm: 1` akan
     * lolos dan sudah cukup untuk melewati seluruh layar konfirmasi tanpa
     * benar-benar mengonfirmasi apa pun. Perbandingan di bawah inilah yang
     * membuat baris itu berarti.
     */
    public function destroy(Request $request, int $level, int $id): JsonResponse
    {
        $model = $this->modelFor($level);

        $row = $model::query()->findOrFail($id);

        /*
         * `confirm` diperiksa MANUAL, bukan lewat `$request->validate()`.
         *
         * Alasannya bentuk responsnya. Validasi bawaan Laravel menjawab 422
         * dengan bentuk `{"message": "...", "errors": {...}}` -- tanpa
         * `success`, tanpa `code`. Frontend panel pusat membaca `message`
         * dan `success` di seluruh endpoint lain, jadi memakai bentuk
         * bawaan di sini berarti satu jalur yang menjawab dengan bahasa
         * berbeda dari semua jalur lain: bagian yang menampilkan
         * `success: false` dapat `undefined`, dan teks yang ditampilkan
         * jadi pesan bawaan Laravel ("The confirm field is required.")
         * -- bukan kalimat yang menjelaskan apa yang harus diperbaiki.
         *
         * Bentuk respons di seluruh kelas ini dijaga seragam: `success`,
         * `code`, `message`.
         */
        $confirm = trim((string) $request->input('confirm', ''));

        if ($confirm === '') {
            return response()->json([
                'success' => false,
                'code' => 'COA_CONFIRM_REQUIRED',
                'message' => 'Ketik kode akun yang ingin dihapus untuk mengonfirmasi. '
                    .'Tidak ada data yang dihapus.',
            ], 422);
        }

        if ($confirm !== (string) $row->kode_akun) {
            return response()->json([
                'success' => false,
                'code' => 'COA_CONFIRM_MISMATCH',
                'message' => 'Kode akun yang diketik tidak cocok dengan akun yang dihapus. '
                    .'Tidak ada data yang dihapus.',
            ], 422);
        }

        $central = DB::connection('central');

        try {
            $hasil = $this->cascade->delete($level, $row->id);
        } catch (CoaCascadeException $e) {
            return response()->json([
                'success' => false,
                'code' => 'COA_HAS_DEPENDENTS',
                'message' => $e->getMessage(),
                'blockers' => $e->blockers(),
            ], 409);
        }

        /*
         * Ketiga-tiganya di bawah dihapus level 3 --> level 2 --> level 1, bukan
         * sebaliknya. `CoaCascadeService` sudah menexplain kenapa: batang
         * yang hilang sebelum daunnya berarti setiap lokasi yang belum sempat
         * dihapus memegang `parent_id` yang menunjuk `id` yang sudah lenyap,
         * dan tidak ada yang bisa mengetahuinya.
         *
         * Transaksi di bawah hanya mencakup tiga baris ini di database
         * pusat. Akun level 4 di tiap lokasi sudah terhapus SEBELUM blok ini
         * dijalankan -- tidak bisa satu transaksi membungkusnya, karena
         * database lokasi berbeda-beda dan MySQL tidak punya transaksi lintas
         * database.
         */
        $central->transaction(function () use ($central, $level, $id, $hasil) {
            if ($level === 1) {
                if ($hasil['ids_level3'] !== []) {
                    $central->table('akun_level_3')->whereIn('id', $hasil['ids_level3'])->delete();
                }

                if ($hasil['ids_level2'] !== []) {
                    $central->table('akun_level_2')->whereIn('id', $hasil['ids_level2'])->delete();
                }

                $central->table('akun_level_1')->where('id', $id)->delete();
            } elseif ($level === 2) {
                if ($hasil['ids_level3'] !== []) {
                    $central->table('akun_level_3')->whereIn('id', $hasil['ids_level3'])->delete();
                }

                $central->table('akun_level_2')->where('id', $id)->delete();
            } else {
                $central->table('akun_level_3')->where('id', $id)->delete();
            }
        });

        $rincian = $this->ringkasHapus($level, $hasil);

        $this->audit->record(
            "coa.level{$level}.delete",
            subjectType: $model,
            subjectId: $id,
            description: "Struktur akun level {$level} `{$row->kode_akun}` dihapus berserta "
                .$rincian['ringkas'].'. Berlaku untuk semua lokasi.',
            properties: [
                'level' => $level,
                'kode_akun' => $row->kode_akun,
                'nama_akun' => $row->nama_akun,
                'dihapus' => $rincian,
                'locations' => $hasil['locations'],
            ],
        );

        return response()->json([
            'success' => true,
            'message' => "Akun `{$row->kode_akun}` dihapus. ".$rincian['ringkas'],
            'data' => $rincian,
        ]);
    }

    /**
     * Cari dan pastikan induk benar-benar ada.
     *
     * Level 1 tidak punya induk, jadi `null` di situ adalah jawaban yang
     * benar, bukan kelewatan. Level 2 dan 3 wajib punya induk yang nyata:
     * `parent_id` yang menunjuk baris yang sudah dihapus akan membuat akun
     * baru tersimpan tanpa pernah muncul di pohon.
     */
    private function cariInduk(int $level, mixed $parentId): ?object
    {
        if ($level === 1) {
            return null;
        }

        $model = $this->modelFor($level - 1);

        $induk = $model::query()->find($parentId);

        if ($induk === null) {
            abort(response()->json([
                'success' => false,
                'code' => 'COA_PARENT_NOT_FOUND',
                'message' => 'Induk level '.($level - 1).' dengan id '.$parentId.' tidak ada. '
                    .'Akun tidak disimpan, karena tanpa induk yang ada, akun ini tidak akan '
                    .'pernah tampil di pohon.',
            ], 422));
        }

        return $induk;
    }

    /**
     * Isi kolom `lev1`/`lev2`/`lev3` sesuai posisinya di pohon.
     *
     * Kolom-kolom ini adalah ORDINAL ("berikutnya yang ke-3"), bukan
     * referensi ke id -- `akun_level_3` id 111 punya `lev1=1, lev2=1,
     * lev3=1`, sementara id 112 yang juga anak `11` punya `lev3=2`. Dipakai
     * `AkunLevel1::hasMany(Account::class, 'lev1', 'lev1')` dan oleh
     * `AccountController::index()` untuk memfilter kategori.
     *
     * Hitung ulang dari database, bukan dari `max()+1` milik baris yang
     * baru saja disimpan: kalau penghapusan pernah membuat nomor meloncat
     * (level 2 terakhir dihapus, lalu ditambahkan lagi), `max()+1` menulis
     * nomor yang sama ke dua baris berbeda -- dan laporan yang mengelompokkan
     * lewat kolom ini akan menghitung akun dua kali.
     */
    private function lengkapiLev(int $level, ?object $induk, array $data): array
    {
        $central = DB::connection('central');

        if ($level === 1) {
            $data['lev1'] = $central->table('akun_level_1')->max('lev1') + 1;
            $data['lev2'] = 0;
            $data['lev3'] = 0;
            $data['lev4'] = 0;

            return $data;
        }

        // Ordinal level 1-2 diwarisi apa adanya dari induknya.
        $data['lev1'] = $induk->lev1;
        $data['lev2'] = $induk->lev2;
        $data['lev4'] = 0;

        if ($level === 2) {
            $data['lev3'] = $central->table('akun_level_2')
                ->where('parent_id', $induk->id)
                ->max('lev2') + 1;
        } else {
            $data['lev3'] = $central->table('akun_level_3')
                ->where('parent_id', $induk->id)
                ->max('lev3') + 1;
        }

        return $data;
    }

    /**
     * Ringkasan apa saja yang ikut terhapus, untuk pesan dan audit.
     *
     * @return array{ringkas:string, level1:int, level2:int, level3:int, level4:int}
     */
    private function ringkasHapus(int $level, array $hasil): array
    {
        $l4 = (int) $hasil['jumlah_level4'];

        $rincian = [
            'level1' => $level === 1 ? 1 : 0,
            'level2' => $level === 1 ? count($hasil['ids_level2']) : ($level === 2 ? 1 : 0),
            'level3' => $level === 1 || $level === 2 ? count($hasil['ids_level3']) : 1,
            'level4' => $l4,
        ];

        /*
         * Yang disebut dulu adalah yang paling besar dampaknya.
         *
         * "1 akun dihapus" padahal ada 40 akun level 4 di dua lokasi adalah
         * informasi yang secara teknis benar dan secara praktis menipu --
         * pembaca berhenti di kalimat pertama dan tidak pernah membaca
         * sampai bawah. Level 4 disebut paling dulu justru karena itu
         * bagian yang paling jarang disadari orang: dia ada di database yang
         * tidak sedang dibuka.
         */
        $bagian = [];

        if ($l4 > 0) {
            /*
             * Lokasi yang dihitung adalah yang PUNYA akun yang terhapus,
             * bukan seluruh lokasi yang diperiksa.
             *
             * `locations` memuat satu entri per lokasi yang dipindai, dan
             * sebagian besar di antaranya tidak punya apa pun -- jumlahnya
             * sama dengan jumlah lokasi terdaftar, yang tidak ada hubungannya
             * dengan berapa yang benar-benar kehilangan akun.
             */
            $lokasiBerisi = count(array_filter(
                $hasil['locations'],
                fn (array $l) => (int) $l['terhapus'] > 0
            ));

            $bagian[] = $l4.' akun level 4 di '.$lokasiBerisi.' lokasi';
        }

        if ($rincian['level3'] > 1) {
            $bagian[] = $rincian['level3'].' akun level 3';
        }

        if ($rincian['level2'] > 1) {
            $bagian[] = $rincian['level2'].' akun level 2';
        }

        return $rincian + [
            'ringkas' => $bagian === []
                ? 'Tidak ada akun lain yang ikut terhapus.'
                : 'Ikut terhapus: '.implode(', ', $bagian).'.',
        ];
    }

    /**
     * Normalisasi `jenis_mutasi` ke huruf kecil.
     *
     * Kolom ini jadi BANDINGAN, bukan tampilan: `PelaporanService` punya
     * dua jalur yang tidak sepakat soal kapitalitas --
     * `isKredit()` memakai `strtolower()` (case-insensitive), sementara
     * `hitungSaldoAccount()` memakai `==` (case-SENSITIVE). Akun yang
     * tersimpan `'Kredit'` dihitung bertanda berlawanan di CALK dibanding
     * Neraca.
     *
     * Ini bug yang sudah ada sejak seeder pertama (akun `2.1.02.02`
     * "Utang FEE Kolektor" ditulis `'Kredit'`), tapi panel pusat sekarang
     * bisa memperburuknya kalau tidak dinormalkan di pintu masuk. Menyimpan
     * selalu huruf kecil membuat kedua jalur sepakat tanpa harus menyentuh
     * kode lama.
     */
    private function normalizeJenisMutasi(array &$data): void
    {
        if (! array_key_exists('jenis_mutasi', $data)) {
            return;
        }

        $data['jenis_mutasi'] = JenisMutasi::normalisasi($data['jenis_mutasi']);
    }

    /**
     * Tolak percobaan mengubah identitas/induk sebuah baris struktur.
     *
     * Lihat blok komentar di `update()` untuk alasan kenapa ini ditolak,
     * bukan diabaikan.
     */
    private function rejectStructuralEdits(Request $request): void
    {
        $terlarang = array_intersect(
            array_keys($request->all()),
            ['id', 'parent_id']
        );

        if ($terlarang !== []) {
            abort(response()->json([
                'success' => false,
                'message' => 'Kolom `'.implode('`, `', $terlarang).'` tidak bisa diubah dari panel pusat. '
                    .'`id` dan `parent_id` adalah acuan `accounts.parent_id` di setiap '
                    .'database lokasi, dan tidak ada foreign key yang menegakkannya.',
            ], 422));
        }
    }

    /** @return class-string<AkunLevel1|AkunLevel2|AkunLevel3> */
    private function modelFor(int $level): string
    {
        return match ($level) {
            1 => AkunLevel1::class,
            2 => AkunLevel2::class,
            3 => AkunLevel3::class,
            default => abort(404, 'Level struktur akun hanya 1, 2, atau 3.'),
        };
    }

    private function buildTree($level1, $level2, $level3): array
    {
        return $level1->map(fn (AkunLevel1 $l1) => [
            'level' => 1,
            'id' => $l1->id,
            'kode_akun' => $l1->kode_akun,
            'nama_akun' => $l1->nama_akun,
            'jenis_mutasi' => $l1->jenis_mutasi,
            'children' => $level2->get($l1->id, collect())->map(fn (AkunLevel2 $l2) => [
                'level' => 2,
                'id' => $l2->id,
                'kode_akun' => $l2->kode_akun,
                'nama_akun' => $l2->nama_akun,
                'jenis_mutasi' => $l2->jenis_mutasi,
                'children' => $level3->get($l2->id, collect())->map(fn (AkunLevel3 $l3) => [
                    'level' => 3,
                    'id' => $l3->id,
                    'kode_akun' => $l3->kode_akun,
                    'nama_akun' => $l3->nama_akun,
                    'jenis_mutasi' => $l3->jenis_mutasi,
                    'posisi' => (int) $l3->posisi,
                    'children' => [],
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }

    private function present(object $row, int $level): array
    {
        $data = [
            'level' => $level,
            'id' => $row->id,
            'kode_akun' => $row->kode_akun,
            'nama_akun' => $row->nama_akun,
            'jenis_mutasi' => $row->jenis_mutasi,
        ];

        if ($level > 1) {
            $data['parent_id'] = $row->parent_id;
        }

        if ($level === 3) {
            $data['posisi'] = (int) $row->posisi;
        }

        return $data;
    }
}
