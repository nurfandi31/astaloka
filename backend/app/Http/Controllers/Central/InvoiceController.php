<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\CentralAccount;
use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use App\Services\AuditLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Tagihan langganan dari ASTA BRATA ke setiap lokasi.
 *
 * Meniru `admin_invoice` di SIDBM: pusat menagih, lokasi membayar, dan yang
 * belum lunas lewat batas akan diblokir oleh middleware `EnforceInvoiceStatus`
 * di sisi lokasi.
 */
class InvoiceController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** Daftar invoice, bisa disaring per lokasi. */
    public function index(Request $request): JsonResponse
    {
        /*
         * Filter LOKASI dan PENCARIAN ditulis sebagai closure, bukan
         * langsung di query daftar.
         *
         * Alasannya angka ringkasan di bawah WAJIB mengikuti filter yang sama
         * dengan daftar -- kalau tidak, memilih "Lokasi A" akan tetap
         * menampilkan "Total Tunggakan" seluruh lokasi, dan angka itu jadi
         * tidak bisa dipercaya saat dibandingkan dengan dashboard.
         *
         * Closure ini yang menjamin keduanya tidak berbeda: satu tempat
         * menulis syarat, dua query memanggilnya.
         *
         * Filter STATUS sengaja TIDAK ada di closure ini, dan itu bukan
         * kelalaian -- lihat catatan panjang di bagian `$status` di bawah.
         */
        $filter = function ($query) use ($request) {
            if ($request->filled('tenant_id')) {
                $query->where('tenant_id', $request->integer('tenant_id'));
            }

            /*
             * Pencarian bebas: nomor invoice, keterangan, dan nama lokasi.
             *
             * Dua kolom pertama milik `tenant_invoices`; nama lokasi ada di
             * tabel `tenants`, jadi harus lewat relasi -- bukan `whereHas` dengan
             * closure yang memanggil `$filter` lagi, karena itu akan membuat
             * syarat yang sama ditulis di dua tempat dan bisa berbeda diam-diam
             * begitu salah satunya diubah.
             *
             * Nama lokasi ikut dicari karena daftar ini dibuka paling sering
             * dengan pertanyaan "tagihan lokasi mana yang menunggak", bukan
             * "invoice nomor berapa". `like` dengan `%` di belakang saja:
             * nomor invoice berawalan huruf/tahun, jadi prefix match
             * menggunakan indeks; `%keyword%` di depan tidak akan, dan daftar
             * tagihan bisa berisi ribuan baris.
             *
             * `escapeLike` dipakai untuk wildcard LIKE-nya. Tanpa itu, mengetik
             * `%` atau `_` di kotak pencarian membuat hasil benar-benar hilang
             * atau melebar -- dan pengguna mengira datanya hilang, bukan bahwa
             * dia mengetik karakter yang punya arti khusus.
             */
            if ($search = trim((string) $request->string('search')->toString())) {
                $escaped = addcslashes($search, '%_\\');
                $pattern = '%'.$escaped.'%';

                $query->where(function ($q) use ($pattern) {
                    $q->where('nomor', 'like', $pattern)
                        ->orWhere('keterangan', 'like', $pattern)
                        ->orWhereHas('tenant', fn ($t) => $t->where(function ($tt) use ($pattern) {
                            $tt->where('nama', 'like', $pattern)
                                ->orWhere('nama_lembaga', 'like', $pattern)
                                ->orWhere('slug', 'like', $pattern);
                        }));
                });
            }

            return $query;
        };

        /*
         * Filter STATUS dipisah dari closure di atas, dan itu wajib.
         *
         * Alasannya "Belum Lunas" dan "Total Tunggakan" di toolbar harus
         * menjawab pertanyaan yang sama apa pun yang sedang disaring di
         * daftar. Kalau syarat status ikut masuk ke closure yang sama, maka
         * memilih "Lunas" membuat query tunggakan searching `status = 'PAID'`
         * DAN `status = 'UNPAID'` -- dua syarat yang mustahil dipenuhi
         * bersamaan -- sehingga keduanya jadi nol.
         *
         * Gejalanya persis seperti yang dilaporkan: dashboard Central
         * menampilkan riwayat pembayaran lunas, sementara halaman Tagihan
         * tidak menampilkan apa pun. Dan bukan hanya di halaman Tagihan --
         * `CentralLokasiDetail` juga membaca `ringkasan` dari endpoint ini
         * untuk kartu "N belum lunas" miliknya, jadi angka nol itu ikut
         * muncul di sana.
         *
         * Perhatikan bahwa ini BUKAN berarti filter status diabaikan
         * sama sekali: filter status tetap dipakai untuk DAFTAR, dan
         * `ringkasan` sekarang menghitung lunas dan belum lunas secara
         * terpisah dari filter status -- masing-masing dari query yang
         * hanya dibatasi filter lokasi dan pencarian.
         *
         * Sumber angka yang sama dipakai dashboard, jadi kedua halaman
         * akhirnya menjawab pertanyaan yang sama untuk data yang sama.
         */
        $status = $request->filled('status')
            ? strtoupper($request->string('status')->toString())
            : null;

        $query = $filter(
            TenantInvoice::query()
                ->with('tenant:id,slug,nama,nama_lembaga')
                // Rincian ikut dimuat supaya `present()` bisa mengirimnya
                // tanpa query tambahan per baris. Tanpa ini `items` selalu
                // kosong di respons, dan `present()` tidak bisa membedakan
                // "invoice ini memang tidak punya rincian" dari "rinciannya
                // belum dimuat" -- dua keadaan yang berlawanan.
                ->with('items')
                // Pembayaran dan rekeningnya, untuk alasan yang sama:
                // kolom "Dibayar lewat" di halaman tagihan menampilkan
                // nama rekening, dan satu request tambahan per baris di
                // sini sama dengan N+1 yang hanya terlihat saat tabelnya
                // sudah berisi puluhan baris.
                ->with('payment.account')
        );

        if ($status !== null) {
            $query->where('status', $status);
        }

        /*
         * `per_page` opsional, bukan tanpa batas.
         *
         * Dipakai pop-up detail dashboard: dibuka dengan 8 baris supaya cepat,
         * lalu 50 saat orang menekan "Muat semua". Tanpa parameter ini, satu
         * klik akan menarik seluruh riwayat tiga tahun sekaligus -- yang
         * memperlambat halaman yang secara keseluruhan sudah cukup berat.
         *
         * Dibatasi 200. Tanpa plafon, `per_page=999999` jadi cara untuk menarik
         * seluruh tabel ke satu response; plafon membuat batas itu ditegakkan
         * di satu tempat, dan nilai di luar itu di-clamp dengan diam karena
         * angka yang lebih besar dari kebutuhan nyata hampir selalu salah
         * ketik.
         */
        $perPage = (int) $request->integer('per_page', 50);
        $perPage = max(5, min($perPage, 200));

        $data = $query->orderByDesc('tgl_invoice')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $data->through(fn (TenantInvoice $i) => $this->present($i)),
            'ringkasan' => $this->ringkasan($filter),
        ]);
    }

    /**
     * Angka ringkasan untuk halaman tagihan.
     *
     * Dihitung server-side, BUKAN dijumlahkan dari baris yang sudah dipaginasi.
     *
     * Ini sumber desync yang nyata dan pernah terjadi: halaman tagihan
     * menjumlahkan `invoices.value` -- isi satu halaman -- sehingga begitu
     * tagihan melewati 50 baris, "Belum Lunas" dan "Total Tunggakan" di
     * toolbar mulai menghitung tagihan terbaru saja. Dashboard tidak pernah
     * melakukan itu, jadi kedua halaman menampilkan angka berbeda untuk data
     * yang sama, dan tidak ada error yang mengatakannya.
     *
     * Menghapus pagination bukan jawabannya: 50 baris ada karena daftar ini
     * bisa berisi ribuan tagihan. Jawaban yang benar adalah total dihitung
     * terpisah dari isi halaman -- persis seperti yang sudah dilakukan
     * `/central/dashboard`, dan dengan filter yang sama supaya keduanya
     * menjawab pertanyaan yang sama.
     *
     * Dikirim sebagai kunci terpisah dari `data` (yang sudah dipakai paginator)
     * supaya bentuk respons tidak berubah bagi pemanggil yang hanya membaca
     * `data` -- termasuk dashboard yang sudah berjalan.
     *
     * ==========
     * STATUS DI LUAR RINGKASAN
     * ==========
     *
     * `belumLunas` dan `totalTunggakan` dihitung dari query yang TIDAK
     * dibatasi filter status -- hanya lokasi dan pencarian. Itu yang membuat
     * angkanya tetap sama dengan dashboard, yang juga menghitung dari seluruh
     * tabel.
     *
     * Kenapa tidak mengikuti filter status: begitu filter itu ikut masuk,
     * memilih "Lunas" membuat query tunggakan searching `status = 'PAID'`
     * DAN `status = 'UNPAID'` -- dua syarat yang mustahil dipenuhi
     * bersamaan -- sehingga "Belum Lunas" dan "Total Tunggakan" jadi nol.
     * Superadmin yang sedangмотре daftar tagihan lunas lalu melihat
     * "Belum Lunas 0 / Total Tunggakan Rp 0" dan menyimpulkan tidak ada
     * tunggakan sama sekali, padahal yang ada di database bisa beribu-ribu.
     *
     * `lunas` ditambahkan supaya halaman ini bisa menampilkan angka yang
     * sama persis dengan kartu "Sudah Lunas" di dashboard. Tanpa itu,
     * dashboard menjawab "berapa yang sudah lunas" dan halaman Tagihan
     * hanya bisa menjawab "berapa yang belum lunas" -- dua halaman berbeda
     * untuk data yang sama.
     *
     * Dijumlahkan dari TIGA query terpisah (lunas, belum lunas, total)
     * dan bukan dari satu query dengan CASE, supaya setiap angka punya
     * syaratnya sendiri yang bisa dibaca langsung -- dan supaya menambah
     * status ketiga di kemudian hari tidak ikut mengubah rumus yang sudah
     * benar.
     */
    private function ringkasan(Closure $filter): array
    {
        $lunas = $filter(TenantInvoice::query())
            ->where('status', 'PAID')
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(jumlah), 0) as nominal')
            ->first();

        $unpaid = $filter(TenantInvoice::query())
            ->where('status', 'UNPAID')
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(jumlah), 0) as nominal')
            ->first();

        return [
            'belumLunas' => (int) $unpaid->jumlah,
            'totalTunggakan' => round((float) $unpaid->nominal, 2),
            'lunas' => (int) $lunas->jumlah,
            'totalLunas' => round((float) $lunas->nominal, 2),
        ];
    }

    /** Buat invoice untuk satu lokasi. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'jumlah' => ['required', 'numeric', 'min:0'],
            'tgl_invoice' => ['required', 'date'],
            'tgl_jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tgl_invoice'],
            /*
             * `periode` tidak lagi ada di form penerbitan, jadi tidak perlu
             * diisi di sini. Aturannya tetap dibiarkan validasi nullable
             * supaya data lama yang sudah terlanjur punya nilai tidak
             * jadi rusak, dan supaya pemanggil lama yang masih mengirimnya
             * tidak ditolak tanpa alasan yang jelas.
             */
            'periode' => ['nullable', 'string', 'max:20'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            /*
             * Rincian item bersifat opsional.
             *
             * Tidak mewajibkan `items` karena tagihan yang sudah terbit
             * sebelumnya tidak punya rincian sama sekali, dan form yang
             * dipaksa mengisinya akan menolak invoice yang sah -- misalnya
             * hanya pencicatan ulang nominal yang sama dengan periode
             * berikutnya. Yang diwajibkan hanya KONSISTENSI: kalau ada
             * item, `jumlah` invoice harus sama dengan jumlah item.
             *
             * Batas 5 baris, bukan 50.
             *
             * Cetakan invoice sengaja dirancang muat SATU lembar A4, dan
             * jumlah baris rincian adalah satu-satunya bagian yang bisa
             * memaksa tagihan jadi dua halaman -- diukur ulang oleh
             * `cek_halaman.php` dan `cek_baris.php`. Tanpa batas di sini,
             * API diam-diam menerima tagihan yang nanti tercetak dua lembar:
             * PDF-nya tetap "berhasil" dibuat, tidak ada error, dan baru
             * ketahuan saat dicetak di meja admin.
             *
             * Form penerbitan sendiri sudah tidak punya kolom item, jadi
             * batas ini hanya menyentuh panggilan API -- dan 5 baris masih
             * jauh di bawah ruang yang tersisa.
             */
            'items' => ['nullable', 'array', 'max:5'],
            'items.*.nama' => ['required_with:items', 'string', 'max:200'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.harga_satuan' => ['nullable', 'numeric', 'min:0'],
            'items.*.jumlah' => ['nullable', 'numeric', 'min:0'],
            'items.*.satuan' => ['nullable', 'string', 'max:30'],
            'items.*.catatan' => ['nullable', 'string', 'max:500'],
        ], [], [
            'items' => 'rincian tagihan',
            'items.max' => 'rincian tagihan maksimal 5 baris (satu lembar A4). Tagihan dengan lebih banyak baris akan tercetak 2 halaman.',
            'items.*.nama' => 'nama item',
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);

        $items = $this->normalizeItems($data['items'] ?? []);

        /*
             * `jumlah` invoice harus sama dengan jumlah rincian item.
             *
             * Tanpa pemeriksaan ini ada dua sumber kebenaran yang bisa
             * berbeda, dan yang salah bisa ikut tercetak di PDF -- user
             * lokasi membaca nominal yang tidak sama dengan yang harus ia
             * bayar, lalu pusat memakai angka lain untuk pencatatan. Selisih
             * kecil akibat pembulatan diizinkan: `>= 0.01` tetap dianggap
             * sama, jadi tagihan 1.500.000,00 dari item 1.500.000,40
             * tidak ditolak.
             */
        if ($items !== [] && abs(array_sum(array_column($items, 'jumlah')) - (float) $data['jumlah']) >= 0.01) {
            return response()->json([
                'success' => false,
                'message' => 'Total rincian item tidak sama dengan jumlah tagihan.',
                'errors' => ['jumlah' => ['Total rincian item harus sama dengan jumlah tagihan.']],
            ], 422);
        }

        try {
            /*
             * Invoice dan itemnya dibuat dalam satu transaksi.
             *
             * Tanpa itu, gagalnya insert item setelah invoice tersimpan
             * meninggalkan tagihan dengan nominal tapi cetakan kosong --
             * dan user lokasi tidak bisa memperbaikinya karena tagihan
             * sudah terbit. Sebaliknya, kalau item-nya dulu, invoice-nya
             * bisa hilang bersama cascade. Keduanya lebih buruk daripada
             * tidak sama sekali ada.
             */
            $invoice = DB::transaction(function () use ($tenant, $data, $request, $items) {
                $invoice = TenantInvoice::create([
                    'tenant_id' => $tenant->id,
                    'nomor' => $this->nextNumber($tenant, (int) date('Y', strtotime($data['tgl_invoice']))),
                    'tgl_invoice' => $data['tgl_invoice'],
                    'tgl_jatuh_tempo' => $data['tgl_jatuh_tempo'] ?? null,
                    'jumlah' => $data['jumlah'],
                    'status' => 'UNPAID',
                    'periode' => $data['periode'] ?? null,
                    'keterangan' => $data['keterangan'] ?? null,
                    'created_by' => $request->user()?->id,
                ]);

                foreach ($items as $urutan => $item) {
                    $invoice->items()->create($item + ['urutan' => $urutan + 1]);
                }

                return $invoice;
            });
        } catch (Throwable $e) {
            // Unik violated = nomor bentrok. Nomor bisa
            // bentrok bila ada dua invoice dibuat di detik yang sama.
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '23000')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nomor invoice bentrok. Coba lagi.',
                ], 409);
            }

            throw $e;
        }

        $this->audit->record(
            'invoice.create',
            tenant: $tenant,
            subjectType: TenantInvoice::class,
            subjectId: $invoice->id,
            description: "Tagihan {$invoice->nomor} sebesar Rp "
                .number_format((float) $invoice->jumlah, 0, ',', '.')
                ." diterbitkan untuk lokasi `{$tenant->slug}`.",
            properties: [
                'nomor' => $invoice->nomor,
                'jumlah' => (float) $invoice->jumlah,
                'tgl_invoice' => $invoice->tgl_invoice?->toDateString(),
                'tgl_jatuh_tempo' => $invoice->tgl_jatuh_tempo?->toDateString(),
                'keterangan' => $invoice->keterangan,
                'rincian_item' => count($items),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Invoice dibuat.',
            'data' => $this->present($invoice->load('items')),
        ], 201);
    }

    /**
     * Bersihkan dan isi default baris item dari input form.
     *
     * Baris kosong dibuang, bukan disimpan sebagai item bernilai nol --
     * form tambah-baris meninggalkan satu baris kosong di akhir, dan
     * menyimpan baris itu akan muncul di PDF sebagai item Rp 0.
     *
     * `jumlah` per item boleh kosong; kalau kosong, yang dipakai adalah
     * `qty * harga_satuan`. `harga_satuan` tidak diturunkan dari
     * `jumlah / qty`: kalau pengadmin mengisi harga satuan 1.500.000 untuk
     * qty 2, ia benar-benar ingin menagih 3.000.000, bukan melihat
     * kesalahan input. Pembagian juga tidak aman di sini karena `qty`
     * boleh nol, dan membagi dengan nol menghasilkan error yang jauh
     * lebih sulit dipahami daripada mengisi nilai yang salah.
     */
    private function normalizeItems(?array $items): array
    {
        $bersih = [];

        foreach ($items ?? [] as $item) {
            $nama = trim((string) ($item['nama'] ?? ''));

            if ($nama === '') {
                continue;
            }

            $qty = (float) ($item['qty'] ?? 1);
            $hargaSatuan = (float) ($item['harga_satuan'] ?? 0);

            $jumlah = array_key_exists('jumlah', $item) && $item['jumlah'] !== null && $item['jumlah'] !== ''
                ? (float) $item['jumlah']
                : $qty * $hargaSatuan;

            $bersih[] = [
                'nama' => $nama,
                'qty' => $qty,
                'harga_satuan' => $hargaSatuan,
                'jumlah' => round($jumlah, 2),
                'satuan' => trim((string) ($item['satuan'] ?? '')) ?: null,
                'catatan' => trim((string) ($item['catatan'] ?? '')) ?: null,
            ];
        }

        return $bersih;
    }

    /**
     * Tandai lunas, sambil mencatat pembayaran.
     *
     * `tgl_lunas` dan `central_account_id` dibaca dari sini kalau ada.
     * Form mengirim rekening tujuan saja; pemanggil lama yang tidak
     * mengirimnya tetap jalan seperti sebelumnya, dengan pembayaran
     * dicatat memakai tanggal hari ini.
     *
     * `metode` TIDAK lagi dikirim dari form. Dia diturunkan dari rekening
     * yang dipilih, karena `kategori` rekening itu sudah menyatakan cara
     * pembayarannya. Dua isian yang saling government's itu hanya membuka
     * pintu untuk keadaan yang salah -- "Transfer Bank" tapi rekeningnya
     * kas tunai, atau sebaliknya. Dengan cara ini satu pilihan sudah
     * cukup dan hasilnya tidak bisa tidak konsisten.
     *
     * Kolom `metode` tetap ADA di tabel pembayaran: riwayat lama sudah
     * menuliskannya, dan salinan itu justru yang membuat catatan
     * pembayaran lama tidak ikut berubah kalau kategori rekening diubah
     * di kemudian hari.
     */
    public function markPaid(Request $request, TenantInvoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'tgl_lunas' => ['nullable', 'date'],
            'central_account_id' => ['required', 'integer', 'exists:central_accounts,id'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ], [
            'central_account_id.required' => 'Pilih kas atau rekening bank yang menerima pembayaran.',
        ], [
            'central_account_id' => 'kas atau rekening bank',
        ]);

        $tglLunas = $data['tgl_lunas'] ?? now()->toDateString();
        $sebelumLunas = $invoice->tgl_lunas?->toDateString();

        /*
         * Cara pembayaran dibaca dari rekening tujuan, bukan dari request.
         *
         * Rekening diambil ulang dari database, bukan memakai nilai yang
         * lolos dari validasi: `exists` hanya membuktikan bahwa id-nya
         * ada, bukan kategori apa yang dimilikinya. Membaca kategorinya
         * dari baris yang benar memastikan `metode` yang ditulis selalu
         * sama dengan rekening yang dipilih -- termasuk untuk request
         * yang dibuat langsung di luar form ini.
         */
        $rekening = CentralAccount::findOrFail($data['central_account_id']);
        $metode = $rekening->kategori;

        try {
            /*
             * Pembayaran DAN status tagihan disimpan dalam satu transaksi.
             *
             * Tanpa itu, ada dua keadaan yang tidak bisa dibayar, dan
             * keduanya terlihat benar dari luar: tagihan sudah `PAID`
             * sementara tabel pembayaran kosong (uang tercatat masuk tanpa
             * rekening yang bisa diperiksa), atau pembayaran sudah
             * tercatat sementara tagihan masih `UNPAID` (rekening sudah
             * bertambah untuk uang yang secara sistem belum diterima).
             */
            DB::transaction(function () use ($invoice, $tglLunas, $data, $rekening, $metode, $request) {
                $invoice->update([
                    'status' => 'PAID',
                    'tgl_lunas' => $tglLunas,
                    'paid_at' => now(),
                    'confirmed_by' => $request->user()?->id,
                ]);

                /*
                 * `jumlah` disamakan dengan `invoice.jumlah`, bukan
                 * ditanyakan di sini: nominal yang dibayar adalah
                 * keputusan yang sudah diambil saat uang diterima.
                 *
                 * Pembayaran SELALU ditulis sekarang -- tidak lagi
                 * bergantung pada `central_account_id` yang ada atau
                 * tidak, karena form yang tidak mengirim rekening sudah
                 * ditolak validasi di atas. Jadi di dalam transaksi ini
                 * tidak mungkin ada keadaan setengah jadi.
                 */
                $invoice->payment()->create([
                    'central_account_id' => $rekening->id,
                    'metode' => $metode,
                    'tgl_bayar' => $tglLunas,
                    'jumlah' => $invoice->jumlah,
                    'catatan' => $data['catatan'] ?? null,
                ]);
            });
        } catch (Throwable $e) {
            // Pelanggaran unique `tenant_invoice_id` = tagihan sudah punya
            // pembayaran (double submit, atau status di-rollback manual di
            // luar aplikasi). Kembalikan pesan yang bisa ditindaklanjuti,
            // bukan error 500 yang tidak menjelaskan apa pun.
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '23000')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tagihan ini sudah punya catatan pembayaran. Gunakan "Batalkan Lunas" dulu kalau memang perlu diganti.',
                ], 409);
            }

            throw $e;
        }

        // Menandai lunas tagihan yang sudah lunas bukan kejadian baru --
        // cukup klik lagi tanpa mengubah apa pun. Dicatat hanya kalau
        // status atau tanggal lunasnya benar-benar bergeser.
        if ($invoice->getOriginal('status') !== 'PAID' || $sebelumLunas !== $tglLunas) {
            $this->audit->record(
                'invoice.mark_paid',
                tenant: $invoice->tenant,
                subjectType: TenantInvoice::class,
                subjectId: $invoice->id,
                description: "Tagihan {$invoice->nomor} untuk lokasi `{$invoice->tenant?->slug}` ditandai lunas.",
                properties: [
                    'nomor' => $invoice->nomor,
                    'jumlah' => (float) $invoice->jumlah,
                    'tgl_lunas' => $tglLunas,
                    'tgl_lunas_sebelumnya' => $sebelumLunas,
                ],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Invoice ditandai lunas.',
            'data' => $this->present($invoice->fresh()),
        ]);
    }

    /**
     * Batalkan lunas (mis. pembayaran dibatalkan).
     *
     * Baris di `tenant_invoice_payments` ikut DIHAPUS di sini, sama
     * sekali dengan perubahan status. Alasannya supaya tabel itu selalu
     * berarti "pembayaran yang sedang berlaku", bukan riwayat semua
     * pembayaran yang pernah tercatat -- kalau tidak, "Batalkan Lunas"
     * hanya mengubah tampilan tapi tidak mengubah saldo rekening, dan
     * saldo rekening menjadi angka yang tidak bisa dipercaya.
     *
     * Jejak historisnya tetap ada di `audit_logs`, yang tidak pernah
     * dihapus di sini.
     */
    public function markUnpaid(TenantInvoice $invoice): JsonResponse
    {
        $sebelumLunas = $invoice->tgl_lunas?->toDateString();
        $pembayaranLama = $invoice->payment()->first();

        $invoice->update([
            'status' => 'UNPAID',
            'tgl_lunas' => null,
            'paid_at' => null,
            'confirmed_by' => null,
        ]);

        if ($pembayaranLama) {
            $pembayaranLama->delete();
        }

        if ($sebelumLunas !== null) {
            $this->audit->record(
                'invoice.mark_unpaid',
                tenant: $invoice->tenant,
                subjectType: TenantInvoice::class,
                subjectId: $invoice->id,
                description: "Pelunasan tagihan {$invoice->nomor} untuk lokasi `{$invoice->tenant?->slug}` dibatalkan.",
                properties: [
                    'nomor' => $invoice->nomor,
                    'jumlah' => (float) $invoice->jumlah,
                    'tgl_lunas_sebelumnya' => $sebelumLunas,
                ],
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Invoice dikembalikan ke status belum lunas.',
            'data' => $this->present($invoice->fresh()),
        ]);
    }

    /**
     * Cetak invoice ke PDF.
     *
     * Di-stream, bukan di-download, supaya `window.open()` langsung
     * menampilkan pratinjau di tab baru dan tombol "Cetak" pada dialog
     * bawaan PDF bisa dipakai. Memaksa download membuat user harus
     * mencari file-nya dulu sebelum bisa mencetak.
     */
    public function pdf(TenantInvoice $invoice): Response
    {
        $invoice->loadMissing(['tenant', 'items']);

        $data = [
            'invoice' => $invoice,
            // Dipakai template untuk menampilkan cap LUNAS.
            'stempelLunas' => $this->stempel('lunas.png'),
            // Tanda tangan Direktur Utama, sama seperti di SABIT. Null kalau
            // berkasnya belum ada -- template tetap mencetak blok tanda tangan,
            // hanya ruang untuk gambarnya yang dibiarkan kosong.
            'ttd' => $this->stempel('ttd.png'),
        ];

        /*
         * Logo perusahaan di-inline sebagai base64, bukan lewat path absolut.
         *
         * Dompdf membaca gambar lewat protocol file:// yang di-chroot ke
         * public/. Path di luar folder itu (mis. storage/) ditolak, dan
         * gejalanya gambar kosong tanpa error -- sulit ditemukan begitu
         * saja. Base64 melewati seluruh mekanisme itu.
         */
        $logo = $this->stempel('logo.png');

        if ($logo !== null) {
            $data['logo'] = $logo;
        }

        return Pdf::loadView('central.invoice-pdf', $data)
            ->setPaper('a4', 'portrait')
            ->stream($this->streamName($invoice));
    }

    /**
     * Nama file unduhan.
     *
     * Nomor invoice mengandung garis miring -- `INV/2026/0001` -- yang
     * memotong path di header `Content-Disposition`. Dua invoice yang
     * diunduh berturut-turut akan terlihat sama-sama bernama `INV`.
     * Garis miring diganti tanda hubung.
     */
    private function streamName(TenantInvoice $invoice): string
    {
        return 'tagihan-'.str_replace('/', '-', $invoice->nomor).'.pdf';
    }

    /**
     * Baca gambar dari `public/assets/branding`, sebagai base64.
     *
     * Mengembalikan null kalau berkasnya tidak ada, supaya template bisa
     * menentukan sendiri tampil atau tidak -- memaksa gambar kosong
     * merusak tata letak cetakan.
     */
    private function stempel(string $filename): ?string
    {
        $path = public_path('assets/branding/'.$filename);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $isi = @file_get_contents($path);

        return $isi === false ? null : base64_encode($isi);
    }

    public function show(TenantInvoice $invoice): JsonResponse
    {
        $invoice->load('tenant:id,slug,nama,nama_lembaga,domain', 'items', 'payment.account');

        return response()->json([
            'success' => true,
            'data' => $this->present($invoice),
        ]);
    }

    /**
     * Nomor invoice berikutnya untuk sebuah lokasi.
     *
     * Format `INV/<tahun>/<urutan>`, urutan dimulai dari 1 per tahun. Unik
     * dijamin index unik (tenant_id, nomor).
     */
    private function nextNumber(Tenant $tenant, int $tahun): string
    {
        $prefix = "INV/{$tahun}/";

        $terakhir = TenantInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('nomor', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->first();

        $urutan = 1;

        if ($terakhir) {
            $suffix = substr((string) $terakhir->nomor, strlen($prefix));
            $urutan = ((int) $suffix) + 1;
        }

        return $prefix.str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }

    private function present(TenantInvoice $i): array
    {
        /*
         * Pembayaran ikut dimuat, bukan diambil terpisah.
         *
         * Halaman tagihan menampilkan kolom "Dibayar lewat" -- kas tunai
         * atau transfer bank. Kalau itu diambil lewat endpoint lain,
         * setiap baris daftar butuh request tambahan; kalau dikirim
         * di sini, satu respons sudah cukup untuk seluruh tabel.
         *
         * `relationLoaded()` dipakai, bukan memanggil `payment` langsung,
         * karena pemanggil yang memuat invoice dengan `with('payment')`
         * sudah punya datanya -- dan memanggil relasi tanpa
         * `relationLoaded()` di sini akan memicu query tambahan per baris
         * yang justru membatalkan tujuan `with()`.
         */
        $payment = $i->relationLoaded('payment')
            ? $i->payment
            : $i->payment()->first();

        return [
            'id' => $i->id,
            'nomor' => $i->nomor,
            'tenant_id' => $i->tenant_id,
            'tenant' => $i->tenant ? [
                'id' => $i->tenant->id,
                'slug' => $i->tenant->slug,
                'label' => $i->tenant->label(),
            ] : null,
            'jumlah' => (float) $i->jumlah,
            'status' => $i->status,
            'keterangan' => $i->keterangan,
            'tgl_invoice' => $i->tgl_invoice?->toDateString(),
            'tgl_jatuh_tempo' => $i->tgl_jatuh_tempo?->toDateString(),
            'tgl_lunas' => $i->tgl_lunas?->toDateString(),
            'deadline' => $i->deadline()->toDateString(),
            'terlambat' => $i->isOverdue(),
            'sisa_hari' => $i->daysRemaining(),
            /*
             * Cara pembayaran beserta rekening tujuannya.
             *
             * Untuk tagihan yang belum dibayar, `payment` = null dan kolom
             * "Dibayar lewat" kosong -- itu yang seharusnya tampil. Yang
             * tidak terjadi: `metode` diisi `tunai` sebagai nilai bawaan
             * supaya tabel tidak terlihat bolong, karena itu akan berarti
             * setiap tagihan belum lunas terlihat sudah dibayar tunai.
             */
            'payment' => $payment ? [
                'id' => $payment->id,
                'tgl_bayar' => $payment->tgl_bayar?->toDateString(),
                'jumlah' => (float) $payment->jumlah,
                'metode' => $payment->metode,
                'central_account_id' => $payment->central_account_id,
                'rekening' => $payment->relationLoaded('account') && $payment->account
                    ? $payment->account->label()
                    : null,
                'catatan' => $payment->catatan,
            ] : null,
            /*
             * Rincian item ikut dikirim, bukan diambil terpisah lewat
             * endpoint lain.
             *
             * Daftar tagihan milik SATU tenant, jadi menyertakannya di
             * respons yang sama tidak menambah permintaan -- sedangkan
             * form terbitkan tagihan berada di halaman yang sama dan
             * butuh dua daftar itu berdampingan.
             */
            'items' => $i->relationLoaded('items')
                ? $i->items->map(fn ($item) => [
                    'nama' => $item->nama,
                    'qty' => (float) $item->qty,
                    'harga_satuan' => (float) $item->harga_satuan,
                    'jumlah' => (float) $item->jumlah,
                    'satuan' => $item->satuan,
                    'catatan' => $item->catatan,
                ])->values()
                : [],
        ];
    }
}
