<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $query = TenantInvoice::query()->with('tenant:id,slug,nama,nama_lembaga');

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->integer('tenant_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->string('status')->toString()));
        }

        $data = $query->orderByDesc('tgl_invoice')->orderByDesc('id')->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $data->through(fn (TenantInvoice $i) => $this->present($i)),
        ]);
    }

    /** Buat invoice untuk satu lokasi. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'jumlah' => ['required', 'numeric', 'min:0'],
            'tgl_invoice' => ['required', 'date'],
            'tgl_jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tgl_invoice'],
            'periode' => ['nullable', 'string', 'max:20'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);

        try {
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
                'periode' => $invoice->periode,
                'tgl_invoice' => $invoice->tgl_invoice?->toDateString(),
                'tgl_jatuh_tempo' => $invoice->tgl_jatuh_tempo?->toDateString(),
                'keterangan' => $invoice->keterangan,
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Invoice dibuat.',
            'data' => $this->present($invoice),
        ], 201);
    }

    /** Tandai lunas. */
    public function markPaid(Request $request, TenantInvoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'tgl_lunas' => ['nullable', 'date'],
        ]);

        $tglLunas = $data['tgl_lunas'] ?? now()->toDateString();
        $sebelumLunas = $invoice->tgl_lunas?->toDateString();

        $invoice->update([
            'status' => 'PAID',
            'tgl_lunas' => $tglLunas,
            'paid_at' => now(),
            'confirmed_by' => $request->user()?->id,
        ]);

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

    /** Batalkan lunas (mis. pembayaran dibatalkan). */
    public function markUnpaid(TenantInvoice $invoice): JsonResponse
    {
        $sebelumLunas = $invoice->tgl_lunas?->toDateString();

        $invoice->update([
            'status' => 'UNPAID',
            'tgl_lunas' => null,
            'paid_at' => null,
            'confirmed_by' => null,
        ]);

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

    public function show(TenantInvoice $invoice): JsonResponse
    {
        $invoice->load('tenant:id,slug,nama,nama_lembaga,domain');

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
            'periode' => $i->periode,
            'keterangan' => $i->keterangan,
            'tgl_invoice' => $i->tgl_invoice?->toDateString(),
            'tgl_jatuh_tempo' => $i->tgl_jatuh_tempo?->toDateString(),
            'tgl_lunas' => $i->tgl_lunas?->toDateString(),
            'deadline' => $i->deadline()->toDateString(),
            'terlambat' => $i->isOverdue(),
            'sisa_hari' => $i->daysRemaining(),
        ];
    }
}
