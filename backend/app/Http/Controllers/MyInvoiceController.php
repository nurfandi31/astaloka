<?php

namespace App\Http\Controllers;

use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use App\Services\InvoiceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tagihan langganan yang dilihat USER LOKASI.
 *
 * Ini pasangan dari InvoiceController di panel pusat: user lokasi tidak bisa
 * membuat atau mengubah invoice, hanya membaca. Satu-satunya aksi yang boleh
 * dia lakukan adalah mencatat bahwa pembayaran sudah dikirim -- dan itu pun
 * tetap perlu dikonfirmasi pusat sebelum status berubah ke PAID.
 *
 * Syarat "ada tagihan yang mengunci" TIDAK ditulis di sini. Semuanya lewat
 * {@see InvoiceGate}, yang sama dipakai `POST /login` dan middleware
 * `EnforceInvoiceStatus`.
 */
class MyInvoiceController extends Controller
{
    public function __construct(private InvoiceGate $gate) {}

    /**
     * Tagihan langganan lokasi yang sedang dilayani.
     *
     * Route ini ada di daftar `tenancy.invoice_exempt_paths`, jadi tetap bisa
     * diakses meski tagihannya belum lunas -- kalau tidak, user terkunci tanpa
     * jalan keluar dan tidak tahu apa yang harus dibayar.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Halaman ini hanya untuk pengguna lokasi.',
            ], 403);
        }

        $invoices = TenantInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('tgl_invoice')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'lokasi' => [
                    'nama' => $tenant->nama,
                    'nama_lembaga' => $tenant->nama_lembaga,
                    'domain' => $tenant->domain,
                ],
                // Ditambah ringkasan dari gate, supaya popup tidak perlu
                // menghitung ulang apa yang sudah dihitung middleware.
                ...$this->gate->payload($tenant),
                'invoices' => $invoices->map(fn (TenantInvoice $i) => [
                    'nomor' => $i->nomor,
                    'jumlah' => (float) $i->jumlah,
                    'status' => $i->status,
                    'keterangan' => $i->keterangan,
                    'tgl_invoice' => $i->tgl_invoice?->toDateString(),
                    'deadline' => $i->deadline()->toDateString(),
                    'sisa_hari' => $i->daysRemaining(),
                    'terlambat' => $i->isOverdue(),
                    'tgl_lunas' => $i->tgl_lunas?->toDateString(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Ringkasan singkat untuk popup tagihan.
     *
     * Sengaja sangat ringan: hanya menghitung invoice UNPAID, tanpa memuat
     * seluruh riwayat. Dipanggil sekali saat login dan sekali lagi saat
     * dashboard dibuka, jadi tidak boleh menambah beban halaman.
     *
     * Bentuknya sama dengan payload gate -- itulah yang dipakai frontend untuk
     * mengisi popup, sehingga angka di popup login dan popup dashboard tidak
     * mungkin berbeda.
     */
    public function summary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            return response()->json([
                'success' => true,
                'data' => $this->gate->payload(null),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->gate->payload($tenant),
        ]);
    }
}
