<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Central\AuditLog;
use App\Models\Central\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Baca jejak audit. Tidak ada aksi tulis di sini.
 *
 * Tabel `audit_logs` tidak punya soft delete dan aplikasi tidak menyediakan
 * endpoint untuk menghapus -- itu disengaja. Log yang bisa dihapus oleh
 * orang yang sedang diselidiki tidak berlaku sebagai bukti.
 *
 * `index()` sengaja tidak mengirim kolom `properties`. Isinya bisa berupa
 * diff field yang sizable, dan daftar di halaman audit tidak membutuhkannya.
 * Payload penuh diambil terpisah lewat `show()` per baris.
 */
class AuditLogController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'actor_type' => ['nullable', 'string', 'max:30'],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 25);

        // `with('tenant:id,slug,nama')` bukan `with('tenant')`: nama kolom
        // registry berubah-ubah dan memuat seluruh tabel untuk setiap baris
        // hanya menambah query yang tidak perlu.
        $rows = AuditLog::query()
            ->with('tenant:id,slug,nama,nama_lembaga')
            ->filter($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => collect($rows->items())->map(fn (AuditLog $a) => $this->brief($a))->all(),
                'meta' => [
                    'current_page' => $rows->currentPage(),
                    'last_page' => $rows->lastPage(),
                    'per_page' => $rows->perPage(),
                    'total' => $rows->total(),
                ],
            ],
        ]);
    }

    /** Baris tunggal, termasuk `properties` lengkap. */
    public function show(AuditLog $audit): JsonResponse
    {
        $audit->load('tenant:id,slug,nama,nama_lembaga');

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->brief($audit),
                'properties' => $audit->properties,
                'user_agent' => $audit->user_agent,
            ],
        ]);
    }

    /**
     * Daftar aksi yang tersedia untuk filter dropdown.
     *
     * Diambil dari data yang benar-benar tercatat, bukan dari konstanta di
     * kode. Dropdown aksi yang belum pernah terjadi hanya menambah noise,
     * dan daftar yang berasal dari data ikut tumbuh sendiri saat ada
     * fitur baru tanpa perlu disentuh di sini.
     */
    public function actions(): JsonResponse
    {
        $actions = $this->audit->availableActions();

        return response()->json([
            'success' => true,
            'data' => [
                'actions' => array_map(
                    fn (string $a) => ['value' => $a, 'label' => AuditLog::actionLabel($a)],
                    $actions
                ),
            ],
        ]);
    }

    /**
     * Bentuk ringkas untuk daftar.
     *
     * `label` dan `tone` dihitung di backend supaya tampilan audit di
     * beberapa tempat -- dashboard, daftar, detail -- tidak punya tiga
     * versi berbeda dari penamaan aksi yang sama.
     */
    private function brief(AuditLog $a): array
    {
        return [
            'id' => $a->id,
            'action' => $a->action,
            'label' => AuditLog::actionLabel($a->action),
            'tone' => AuditLog::toneFor($a->action),
            'description' => $a->description,
            'actor_id' => $a->actor_id,
            'actor_type' => $a->actor_type,
            'actor_name' => $a->actor_name,
            'actor_role' => $a->actor_role,
            'tenant_id' => $a->tenant_id,
            'tenant' => $a->tenant ? [
                'id' => $a->tenant->id,
                'slug' => $a->tenant->slug,
                'nama' => $a->tenant->nama,
            ] : null,
            'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id,
            'ip_address' => $a->ip_address,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
