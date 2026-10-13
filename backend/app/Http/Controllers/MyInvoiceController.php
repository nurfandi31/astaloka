<?php

namespace App\Http\Controllers;

use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tagihan langganan yang dilihat USER LOKASI.
 *
 * Ini pasangan dari InvoiceController di panel pusat: user lokasi tidak bisa
 * membuat atau mengubah invoice, hanya membaca. Satu-satunya aksi yang boleh
 * dia lakukan adalah mencatat bahwa pembayaran sudah dikirim -- dan itu pun
 * tetap perlu dikonfirmasi pusat sebelum status berubah ke PAID.
 */
class MyInvoiceController extends Controller
{
    /**
     * Tagihan langganan lokasi yang sedang dilayani.
     *
     * Route ini ada di daftar `tenancy.invoice_exempt_paths`, jadi tetap bisa
     * diakses meski tagihan terlambat -- kalau tidak, user terkunci tanpa
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

        $terlambat = $invoices->first(fn (TenantInvoice $i) => $i->isOverdue());

        return response()->json([
            'success' => true,
            'data' => [
                'lokasi' => [
                    'nama' => $tenant->nama,
                    'nama_lembaga' => $tenant->nama_lembaga,
                    'domain' => $tenant->domain,
                ],
                'terlambat' => $terlambat !== null,
                'tagihan_tertunggak' => $invoices->where('status', 'UNPAID')->count(),
                'invoices' => $invoices->map(fn (TenantInvoice $i) => [
                    'nomor' => $i->nomor,
                    'jumlah' => (float) $i->jumlah,
                    'status' => $i->status,
                    'periode' => $i->periode,
                    'keterangan' => $i->keterangan,
                    'tgl_invoice' => $i->tgl_invoice?->toDateString(),
                    'deadline' => $i->deadline()->toDateString(),
                    'sisa_hari' => $i->daysRemaining(),
                    'tgl_lunas' => $i->tgl_lunas?->toDateString(),
                ]),
            ],
        ]);
    }

    /**
     * Ringkasan singkat untuk badge di navbar.
     *
     * Sengaja sangat ringan: hanya satu query `min()` untuk batas terlambat,
     * supaya tidak menambah beban pada setiap pemuatan halaman.
     */
    public function summary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            return response()->json([
                'success' => true,
                'data' => ['terlambat' => false, 'jumlah' => 0],
            ]);
        }

        $jumlah = TenantInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'UNPAID')
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'terlambat' => $this->hasOverdue($tenant),
                'jumlah' => $jumlah,
            ],
        ]);
    }

    private function hasOverdue(Tenant $tenant): bool
    {
        return TenantInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'UNPAID')
            ->get()
            ->contains(fn (TenantInvoice $i) => $i->isOverdue());
    }
}
