<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralAccount;
use App\Models\Central\TenantInvoicePayment;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rekening penampung pembayaran tagihan milik ASTA BRATA.
 *
 * Berdiri sendiri, bukan turunan dari `CoaController`. Alasannya
 * tujuannya tidak sama: `CoaController` mengelola `akun_level_1..3`,
 * yang itu KERANGKA milik semua lokasi -- tidak boleh ditambah, dihapus,
 * atau dinomori ulang dari panel pusat. Yang di sini adalah rekening
 * milik pusat yang dipakai untuk mencatat uang yang benar-benar masuk,
 * dan menerima pembayaran. Jadi menambah rekening baru di sini adalah
 * kejadian rutin, bukan perubahan struktur.
 *
 * Tidak ada `destroy`. Baris yang pernah dipakai pembayaran menunjuk
 * `central_accounts` ini, dan menghapusnya membuat riwayat pembayaran
 * menunjuk rekening yang tidak ada -- transaksi yang sudah masuk jadi
 * tidak bisa ditelusuri. Menonaktifkan mencapai tujuan yang sama tanpa
 * akibat itu: rekening lama tidak muncul lagi di form, tapi catatan
 * yang menunjuknya tetap utuh dan tetap menampilkan nama rekening
 * yang benar.
 */
class CentralAccountController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Daftar rekening, beserta saldo masuk per rekening.
     *
     * `saldo` dihitung dari `tenant_invoice_payments`, bukan disimpan
     * sebagai kolom dan dinaikkan setiap ada pembayaran. Kolom saldo
     * adalah cache yang harus selalu benar, dan setiap jalur yang
     * menambah atau mengurangi pembayaran tanpa menyentuh kolom itu
     * akan membuatnya diam-diam menyimpang. Query SUM dengan indeks
     * pada `central_account_id` sudah cukup cepat untuk tabel yang
     * isinya ratusan baris.
     *
     * `?all=1` mengembalikan yang nonaktif juga, untuk dipakai halaman
     * daftar. Form pembayaran TIDAK boleh memakainya -- form yang
     * menampilkan rekening nonaktif membuat orang memilih rekening yang
     * sudah tidak dipakai untuk apa pun.
     */
    public function index(Request $request): JsonResponse
    {
        $semua = $request->boolean('all');

        $rekening = CentralAccount::query()
            ->when(! $semua, fn ($q) => $q->where('is_active', true))
            ->orderBy('kategori') // tunai dulu: kas tunai yang sering dipakai
            ->orderBy('kode_akun')
            ->get();

        /*
         * Satu query, bukan satu query per rekening.
         *
         * `N+1` di sini akan berarti 1 + jumlah rekening permintaan
         * database setiap kali halaman dibuka -- dan halaman ini dibuka
         * justru saat sedang mencari saldo, yaitu saat paling tidak boleh
         * melambat.
         */
        $saldoPerRekening = TenantInvoicePayment::query()
            ->whereIn('central_account_id', $rekening->pluck('id'))
            ->selectRaw('central_account_id, COUNT(*) as jumlah_payment, COALESCE(SUM(jumlah), 0) as total')
            ->groupBy('central_account_id')
            ->get()
            ->keyBy('central_account_id');

        return response()->json([
            'success' => true,
            'data' => $rekening->map(function (CentralAccount $a) use ($saldoPerRekening) {
                $baris = $saldoPerRekening->get($a->id);

                return $this->present($a) + [
                    'saldo' => round((float) ($baris->total ?? 0), 2),
                    'jumlah_payment' => (int) ($baris->jumlah_payment ?? 0),
                ];
            })->values(),
        ]);
    }

    /**
     * Simpan rekening baru.
     *
     * `kode_akun` uniknya dicegah constraint, tapi tetap divalidasi
     * `unique` supaya pesannya menyebut kolom yang bentrok --
     * pesan bawaan MySQL ("Duplicate entry ... for key
     * 'central_accounts_kode_akun_unique'") tidak menjelaskan ke admin
     * bahwa kodenya yang sama.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kode_akun' => ['required', 'string', 'max:10', 'unique:central_accounts,kode_akun'],
            'nama_akun' => ['required', 'string', 'max:100'],
            'kategori' => ['required', 'in:tunai,bank'],
            'jenis_mutasi' => ['nullable', 'string', 'max:6'],
            'no_rekening' => ['nullable', 'string', 'max:50'],
            'atas_nama' => ['nullable', 'string', 'max:100'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ], [], [
            'kode_akun' => 'kode akun',
            'nama_akun' => 'nama akun',
        ]);

        $data['kode_akun'] = trim($data['kode_akun']);
        $data['nama_akun'] = trim($data['nama_akun']);

        /*
         * `jenis_mutasi` diseragamkan ke huruf kecil.
         *
         * Sama seperti di `CoaController::normalizeJenisMutasi()`: nilai
         * ini jadi BANDINGAN di sisi lokasi, dan ada jalur laporan yang
         * membandingkan dengan `==` (case-sensitive) sementara yang lain
         * memakai `strtolower()`. Disimpan sebagai "Debet" berarti akun
         * ini nanti dihitung dengan tanda berlawanan di salah satu laporan.
         */
        if (($data['jenis_mutasi'] ?? null) !== null) {
            $data['jenis_mutasi'] = strtolower(trim($data['jenis_mutasi']));
        }

        $rekening = CentralAccount::create($data + ['is_active' => true]);

        $this->audit->record(
            'central_account.create',
            subjectType: CentralAccount::class,
            subjectId: $rekening->id,
            description: "Rekening `{$rekening->kode_akun}` ({$rekening->nama_akun}) ditambahkan.",
            properties: [
                'kode_akun' => $rekening->kode_akun,
                'nama_akun' => $rekening->nama_akun,
                'kategori' => $rekening->kategori,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Rekening ditambahkan.',
            'data' => $this->present($rekening),
        ], 201);
    }

    /**
     * Ubah rekening, termasuk mengaktifkan/menonaktifkan.
     *
     * `kategori` BOLEH diubah bahkan kalau rekening sudah dipakai
     * pembayaran. Pembayaran yang sudah tercatat menyimpan salinannya
     * sendiri di `tenant_invoice_payments.metode`, jadi riwayat lama
     * tidak ikut berubah -- itu memang yang membuat kolom itu disalin
     * di tempat pertama. Yang berubah cuma form untuk pembayaran baru.
     */
    public function update(Request $request, CentralAccount $account): JsonResponse
    {
        $data = $request->validate([
            'kode_akun' => [
                'sometimes', 'required', 'string', 'max:10',
                'unique:central_accounts,kode_akun,'.$account->id,
            ],
            'nama_akun' => ['sometimes', 'required', 'string', 'max:100'],
            'kategori' => ['sometimes', 'required', 'in:tunai,bank'],
            'jenis_mutasi' => ['nullable', 'string', 'max:6'],
            'no_rekening' => ['nullable', 'string', 'max:50'],
            'atas_nama' => ['nullable', 'string', 'max:100'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], [
            'kode_akun' => 'kode akun',
            'nama_akun' => 'nama akun',
        ]);

        foreach (['kode_akun', 'nama_akun'] as $trim) {
            if (array_key_exists($trim, $data)) {
                $data[$trim] = trim($data[$trim]);
            }
        }

        if (array_key_exists('jenis_mutasi', $data) && $data['jenis_mutasi'] !== null) {
            $data['jenis_mutasi'] = strtolower(trim($data['jenis_mutasi']));
        }

        // Disalin sebelum update: sesudahnya atribut di model sudah berisi
        // nilai baru, jadi `diff()` tidak menemukan apa pun.
        $sebelum = $account->only(array_keys($data));

        $account->update($data);

        $perubahan = AuditLogger::diff($sebelum, $account->fresh()->only(array_keys($data)));

        if ($perubahan !== []) {
            $this->audit->record(
                'central_account.update',
                subjectType: CentralAccount::class,
                subjectId: $account->id,
                description: $account->is_active
                    ? "Rekening `{$account->kode_akun}` diperbarui ("
                        .implode(', ', array_keys($perubahan)).').'
                    : "Rekening `{$account->kode_akun}` dinonaktifkan.",
                properties: [
                    'kode_akun' => $account->kode_akun,
                    'changes' => $perubahan,
                ],
            );
        }

        $pesan = $perubahan === []
            ? 'Tidak ada perubahan: nilai yang dikirim sudah sama dengan yang tersimpan.'
            : 'Rekening diperbarui.';

        return response()->json([
            'success' => true,
            'changed' => $perubahan !== [],
            'message' => $pesan,
            'data' => $this->present($account->fresh()),
        ]);
    }

    /**
     * Mutasi per rekening: pembayaran yang masuk lewat rekening ini.
     *
     * Endpoint terpisah dari `index` karena yang dijawabnya berbeda --
     * `index` menjawab "berapa saldo saya", yang ini menjawab "dari
     * pembayaran mana saldo itu". Menggabungkan keduanya berarti
     * mengambil seluruh riwayat pembayaran setiap kali panel dibuka
     * untuk melihat daftar rekening yang biasanya tiga baris.
     */
    public function payments(Request $request, CentralAccount $account): JsonResponse
    {
        $perPage = max(5, min((int) $request->integer('per_page', 50), 200));

        $pembayaran = TenantInvoicePayment::query()
            ->where('central_account_id', $account->id)
            ->with('invoice:id,nomor,tenant_id,tenant:nama,nama_lembaga,slug')
            ->orderByDesc('tgl_bayar')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $pembayaran->through(fn (TenantInvoicePayment $p) => [
                'id' => $p->id,
                'tgl_bayar' => $p->tgl_bayar?->toDateString(),
                'jumlah' => (float) $p->jumlah,
                'metode' => $p->metode,
                'catatan' => $p->catatan,
                'invoice' => $p->invoice ? [
                    'id' => $p->invoice->id,
                    'nomor' => $p->invoice->nomor,
                    'tenant' => $p->invoice->tenant?->label(),
                ] : null,
            ]),
        ]);
    }

    private function present(CentralAccount $a): array
    {
        return [
            'id' => $a->id,
            'kode_akun' => $a->kode_akun,
            'nama_akun' => $a->nama_akun,
            'kategori' => $a->kategori,
            'jenis_mutasi' => $a->jenis_mutasi,
            'is_active' => (bool) $a->is_active,
            'no_rekening' => $a->no_rekening,
            'atas_nama' => $a->atas_nama,
            'catatan' => $a->catatan,
            'label' => $a->label(),
        ];
    }
}