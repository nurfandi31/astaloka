<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\MonthlyBill;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillPaymentService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KomisiSPSController extends Controller
{
    private const CASH_KODE_AKUN = ['1.1.01.01', '1.1.01.02', '1.1.01.03', '1.1.01.05', '1.1.01.06', '1.1.01.07'];
    private const UTANG_KODE_AKUN = '2.1.02.02';
    private const BEBAN_KODE_AKUN = '5.1.02.04';
    private const TRANSACTION_GROUP = 2;
    private const KOMISI_RATE = 0.1;

    public function __construct(protected BillPaymentService $billPaymentService) {}

    public function cashAccounts()
    {
        $accounts = Account::whereIn('kode_akun', self::CASH_KODE_AKUN)
            ->whereNull('tgl_nonaktif')
            ->orderBy('kode_akun')
            ->get(['id', 'kode_akun', 'nama_akun']);

        return response()->json([
            'success' => true,
            'data'    => $accounts,
            'rate'    => self::KOMISI_RATE,
        ]);
    }

    public function penerimaKomisi(Request $request)
    {
        $defaultTeknisi = User::where('role', 'teknisi')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        $others = collect();
        if ($request->boolean('include_all')) {
            $others = User::where('role', '!=', 'teknisi')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'default' => $defaultTeknisi,
                'others'  => $others,
                'rate'    => self::KOMISI_RATE,
            ],
        ]);
    }

    public function pelangganWithUnpaid(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $query = Customer::query()
            ->whereHas('monthlyBills', function ($sub) {
                $sub->where('status', 'unpaid');
            })
            ->with(['user', 'ticket']);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('customer_code', 'like', "%{$q}%")
                    ->orWhereHas('user', function ($u) use ($q) {
                        $u->where('name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('ticket', function ($t) use ($q) {
                        $t->where('applicant_name', 'like', "%{$q}%")
                          ->orWhere('nik', 'like', "%{$q}%");
                    });
            });
        }

        $customers = $query
            ->orderBy('customer_code')
            ->limit(50)
            ->get();

        $items = $customers->map(function ($c) {
            $unpaidTotal = (float) $c->monthlyBills()
                ->where('status', 'unpaid')
                ->sum('total_amount');

            return [
                'id'             => $c->id,
                'customer_code'  => $c->customer_code,
                'customerId'     => $c->id,
                'nama'           => optional($c->user)->name ?? $c->ticket?->applicant_name,
                'name'           => optional($c->user)->name ?? $c->ticket?->applicant_name,
                'nik'            => $c->ticket?->nik,
                'total_unpaid'   => $unpaidTotal,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => $items,
            'rate'    => self::KOMISI_RATE,
        ]);
    }

    public function unpaidByCustomer(Request $request)
    {
        $customerId = $request->query('customer_id');
        if (! $customerId) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'customer'      => null,
                    'total_unpaid'  => 0,
                    'bill_count'    => 0,
                    'bills'         => [],
                    'nominal_komisi' => 0,
                    'rate'          => self::KOMISI_RATE,
                ],
            ]);
        }

        $customer = Customer::with('user', 'ticket.package', 'ticket.village')->find($customerId);
        if (! $customer) {
            return response()->json([
                'success' => false,
                'message' => 'Pelanggan tidak ditemukan.',
            ], 404);
        }

        $bills = MonthlyBill::where('customer_id', $customerId)
            ->where('status', 'unpaid')
            ->orderBy('billing_period_year')
            ->orderBy('billing_period_month')
            ->get(['id', 'customer_id', 'billing_period_year', 'billing_period_month', 'total_amount', 'penalty_amount', 'due_date', 'status']);

        $totalUnpaid   = (float) $bills->sum('total_amount');
        $nominalKomisi = round($totalUnpaid * self::KOMISI_RATE, 2);

        return response()->json([
            'success' => true,
            'data'    => [
                'customer'        => [
                    'id'            => $customer->id,
                    'customer_code' => $customer->customer_code,
                    'nama'          => optional($customer->user)->name ?? $customer->ticket?->applicant_name,
                    'nik'           => $customer->ticket?->nik,
                    'alamat'        => $customer->ticket?->address,
                    'package_name'  => $customer->ticket?->package?->name,
                ],
                'total_unpaid'    => $totalUnpaid,
                'nominal_komisi'  => $nominalKomisi,
                'bill_count'      => $bills->count(),
                'bills'           => $bills,
                'rate'            => self::KOMISI_RATE,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'tgl_transaksi'      => 'required|date',
            'customer_ids'       => 'required|array|min:1',
            'customer_ids.*'     => 'integer|exists:customers,id',
            'account_kas'        => 'required|string|in:' . implode(',', self::CASH_KODE_AKUN),
            'penerima_komisi_id' => 'required|integer|exists:users,id',
            'keterangan'         => 'nullable|string|max:255',
        ], [
            'tgl_transaksi.required'      => 'Tanggal transaksi wajib diisi.',
            'customer_ids.required'       => 'Pelanggan wajib dipilih.',
            'customer_ids.array'          => 'Pelanggan tidak valid.',
            'customer_ids.min'            => 'Minimal pilih 1 pelanggan.',
            'customer_ids.*.exists'       => 'Pelanggan tidak ditemukan.',
            'account_kas.required'        => 'Metode pembayaran wajib dipilih.',
            'account_kas.in'              => 'Metode pembayaran tidak valid.',
            'penerima_komisi_id.required' => 'Penerima komisi wajib dipilih.',
            'penerima_komisi_id.exists'   => 'Penerima komisi tidak ditemukan.',
        ]);

        $customerIds = array_values(array_unique(array_map('intval', $request->customer_ids)));

        $bills = MonthlyBill::with('customer.user', 'customer.ticket')
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'unpaid')
            ->get();

        if ($bills->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada tagihan unpaid untuk pelanggan yang dipilih.',
            ], 422);
        }

        $totalUnpaid = (float) $bills->sum('total_amount');
        if ($totalUnpaid <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Total tagihan pelanggan nol.',
            ], 422);
        }

        // Nominal komisi DIHITUNG PER TAGIHAN, bukan dari total.
        //
        // Jurnal di bawah membulatkan 10% tiap tagihan secara terpisah, jadi
        // jumlah nominal di layar harus menjumlahkan pembulatan yang sama.
        // Kalau total_tagihan dikalikan rate sekali, hasilnya bisa berbeda
        // beberapa rupiah dari total jurnal -- dan angka itulah yang tampil
        // di notifikasi sukses serta dipakai user untuk mencocokkan laporan.
        $komisiPerBill = $bills->mapWithKeys(fn ($bill) => [
            $bill->id => round((float) $bill->total_amount * self::KOMISI_RATE, 2),
        ]);
        $nominalKomisi = round($komisiPerBill->sum(), 2);

        if ($nominalKomisi <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Nominal komisi nol, transaksi tidak dapat disimpan.',
            ], 422);
        }

        $customers = Customer::with('user', 'ticket')
            ->whereIn('id', $customerIds)
            ->get();
        $relasiNames = $customers->map(function ($c) {
            return optional($c->user)->name ?? $c->ticket?->applicant_name ?? null;
        })->filter()->values()->all();
        $relasi = !empty($relasiNames)
            ? implode(', ', $relasiNames)
            : count($customerIds) . ' pelanggan';

        $penerima = User::find($request->penerima_komisi_id);
        $penerimaName = $penerima?->name ?? 'Penerima';

        $billPaymentIds = [];
        $komisiTransactionIds = [];
        $payableBills = collect();

        /*
         * `total_unpaid` dan `nominal_komisi` dihitung ulang dari baris yang
         * BENAR-BENAR dibayar request ini, bukan dari pembacaan di luar
         * transaksi. Kalau request lain menang race-nya dan membayar sebagian
         * tagihan lebih dulu, angka yang dikembalikan harus ikut yang
         * tersisa -- kalau tidak, user melihat nominal komisi untuk tagihan
         * yang gagal tertulis.
         */
        try {
            $result = DB::transaction(function () use ($request, $bills, $komisiPerBill, $relasi, $penerimaName, $customerIds, &$billPaymentIds, &$komisiTransactionIds, &$payableBills) {
                $tgl = Carbon::parse($request->tgl_transaksi)->toDateString();
                $keteranganBase = $request->keterangan ?: 'Komisi SPS - ' . $relasi . ' (Penerima: ' . $penerimaName . ')';

                /*
                 * Kunci baris tagihan (SELECT ... FOR UPDATE), lalu menandai lunas
                 * HANYA baris yang masih unpaid -- semuanya di dalam satu transaksi.
                 *
                 * Tanpa kunci, dua request yang menagih pelanggan sama bisa
                 * sama-sama membaca status `unpaid` di luar transaksi, lalu
                 * dua-duanya membuat jurnal kas dan jurnal komisi untuk tagihan
                 * yang sama: tagihan tercatat lunas dua kali dan utang komisi ikut
                 * dobel. Form ini menerima banyak pelanggan sekaligus, jadi
                 * jendelanya jauh lebih lebar daripada pembayaran satu tagihan.
                 *
                 * Pola ini mengikuti `MonthlyBillController::pay()`.
                 *
                 * Baris yang ternyata sudah dibayar request lain TIDAK diproses di
                 * sini -- jurnal untuk tagihan itu sudah dibuat oleh request yang
                 * menang. Membuatnya lagi hanya menggandakan pembukuan.
                 */
                $lockedBills = MonthlyBill::with('customer.user', 'customer.ticket')
                    ->whereIn('id', $bills->pluck('id')->all())
                    ->lockForUpdate()
                    ->get();

                $payableBills = $lockedBills
                    ->where('status', 'unpaid')
                    ->values();

                if ($payableBills->isEmpty()) {
                    throw new RuntimeException('TAGIHAN_SUDAH_DIBAYAR');
                }

                $payableBills->each(fn ($bill) => $bill->update(['status' => 'paid']));

                $totalUnpaidJurnal = round((float) $payableBills->sum('total_amount'), 2);
                $nominalKomisiJurnal = round(
                    $payableBills->sum(fn ($bill) => $komisiPerBill[$bill->id] ?? 0.0),
                    2
                );

                foreach ($payableBills as $bill) {
                    // Ambil dari peta hasil perhitungan di luar transaksi supaya
                    // nominal yang dijurnal identik dengan yang dilaporkan ke user.
                    $komisi = $komisiPerBill[$bill->id] ?? 0.0;

                    $billRelasi = optional($bill->customer?->user)->name ?? $bill->customer?->ticket?->applicant_name ?? 'Pelanggan';
                    $billKeterangan = $keteranganBase . ' | Bill #' . $bill->id . ' (' . number_format($bill->total_amount, 0, ',', '.') . ')';

                    $bp = $this->billPaymentService->recordPayment(
                        $bill,
                        $request->account_kas,
                        $tgl,
                        $billKeterangan,
                        (int) $request->penerima_komisi_id
                    );
                    $billPaymentIds[] = $bp->id;

                    $komisiKeterangan = 'Beban Komisi SPS - ' . $billRelasi . ' (10% × ' . number_format($bill->total_amount, 0, ',', '.') . ' = ' . number_format($komisi, 0, ',', '.') . ') - Penerima: ' . $penerimaName;

                    $komisiTrans = Transaction::create([
                        'tgl_transaksi'        => $tgl,
                        'account_debet'        => self::BEBAN_KODE_AKUN,
                        'account_kredit'       => self::UTANG_KODE_AKUN,
                        'transaction_group'    => self::TRANSACTION_GROUP,
                        'reverence_type'       => 'bill_payment',
                        'reverence_id'         => $bp->id,
                        'penerima_komisi_id'   => $request->penerima_komisi_id,
                        'keterangan_transaksi' => $komisiKeterangan,
                        'relasi'               => $billRelasi,
                        'saldo'                => $komisi,
                        'urutan'               => 1,
                        'id_user'              => Auth::id(),
                    ]);
                    $komisiTransactionIds[] = $komisiTrans->id;
                }

                /*
                 * Isi `urutan` dengan id masing-masing baris lewat `DB::table()`.
                 *
                 * `->update()` lewat Eloquent akan memicu observer
                 * `TransactionObserver::updated()`, yang pada kolom `urutan` tidak
                 * memengaruhi saldo tapi tetap menjalankan agregasi SUM() penuh
                 * (~1 detik per baris) plus trigger MySQL. Form ini bisa menulis
                 * ratusan baris sekaligus karena menerima banyak pelanggan, jadi
                 * selisihnya bukan "~1 detik per baris" tapi menit, dan cukup
                 * sering berakhir sebagai "Lock wait timeout".
                 */
                foreach ($komisiTransactionIds as $trxId) {
                    DB::table('transactions')->where('id', $trxId)->update(['urutan' => $trxId]);
                }

                return [
                    'nominal_komisi' => $nominalKomisiJurnal,
                    'total_unpaid'   => $totalUnpaidJurnal,
                    'bill_count'     => $payableBills->count(),
                ];
            });
        } catch (RuntimeException $e) {
            // Double-pay: request lain baru saja membayar tagihan yang sama.
            if ($e->getMessage() === 'TAGIHAN_SUDAH_DIBAYAR') {
                return response()->json([
                    'success' => false,
                    'message' => 'Tagihan sudah dibayar. Muat ulang halaman lalu coba lagi.',
                ], 400);
            }

            throw $e;
        } catch (QueryException $e) {
            // Deadlock / lock wait timeout / koneksi putus di tengah jalan.
            // Balas 503 (retryable) dengan pesan yang bisa dibaca frontend,
            // jangan 500 kosong supaya user bisa menekan ulang dengan aman
            // -- DB::transaction sudah rollback, jadi tidak ada data setengah
            // jadi.
            if (in_array($e->getCode(), ['40001', '40P01', 'HY000'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Server sedang sibuk, transaksi gagal diproses. Silakan coba lagi.',
                ], 503);
            }

            throw $e;
        }

        $billDetails = $payableBills->map(fn ($bill) => [
            'bill_id'         => $bill->id,
            'customer_id'     => $bill->customer_id,
            'amount'          => (float) $bill->total_amount,
            'komisi_per_bill' => $komisiPerBill[$bill->id] ?? 0.0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transaksi komisi SPS berhasil disimpan.',
            'data'    => [
                'relasi'                 => $relasi,
                'penerima_komisi_id'     => (int) $request->penerima_komisi_id,
                'penerima_komisi_name'   => $penerimaName,
                'customer_ids'           => $customerIds,
                'total_unpaid'           => $result['total_unpaid'],
                'nominal_komisi'         => $result['nominal_komisi'],
                'rate'                   => self::KOMISI_RATE,
                'bill_count'             => $result['bill_count'],
                'bill_details'           => $billDetails,
                'account_kas'            => $request->account_kas,
                'account_utang'          => self::UTANG_KODE_AKUN,
                'account_beban_komisi'   => self::BEBAN_KODE_AKUN,
                'jurnal'                 => [
                    'debet'  => self::BEBAN_KODE_AKUN,
                    'kredit' => self::UTANG_KODE_AKUN,
                ],
            ],
        ], 201);
    }
}
