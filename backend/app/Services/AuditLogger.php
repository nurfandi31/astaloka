<?php

namespace App\Services;

use App\Models\Central\AuditLog;
use App\Models\Central\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pencatat jejak audit.
 *
 * SELALU menulis ke database pusat, apa pun koneksi default yang sedang
 * aktif. Ini penting karena `AuditLogger` dipanggil dari dua sisi:
 *
 *   1. Panel pusat, saat `database.default` = central.
 *   2. Aplikasi lokasi, saat `TenantManager::activate()` sudah menggantinya
 *      ke database lokasi.
 *
 * Karena `AuditLog` mengunci `$connection = 'central'`, pemanggil tidak
 * perlu repot memindahkan koneksi sendiri.
 *
 * KEGAGALOGAN LOGGING TIDAK BOLEH MENGGAGALKAN AKSI UTAMA.
 *
 * Semua penulisan dibungkus try/catch dan hanya di-`report()` kalau gagal.
 * Kalau pencatatan audit melempar exception ke request, satu tabel yang
 * sedang bermasalah akan membuat seluruh aplikasi tidak bisa dipakai --
 * termasuk user yang sedang mencoba memperbaiki masalahnya. Log yang hilang
 * jauh lebih mudah diperbaiki daripada data yang gagal disimpan.
 */
class AuditLogger
{
    public function __construct(private ?Request $request = null) {}

    /**
     * Catat satu aksi.
     *
     * @param  string  $action  Dot-notation, mis. `tenant.create`
     * @param  Tenant|null  $tenant  Lokasi yang terkena dampak (null untuk aksi tingkat pusat)
     * @param  string|null  $subjectType  Class model yang diubah, mis. `App\Models\Pelanggan`
     * @param  mixed  $subjectId  Primary key objek
     * @param  array  $properties  Detail tambahan; untuk update isinya hasil diff()
     * @param  object|null  $actor  Aktor eksplisit; hanya perlu diisi kalau `auth()` belum punya user
     */
    public function record(
        string $action,
        ?Tenant $tenant = null,
        ?string $subjectType = null,
        mixed $subjectId = null,
        string $description = '',
        array $properties = [],
        ?object $actor = null,
    ): void {
        try {
            // Aktor DIBERIKAN PENGLANGGAR di beberapa tempat -- terutama
            // login. Pada login yang berhasil, `auth()->user()` masih null
            // saat baris audit ditulis: token baru saja dibuat pada
            // request ini, dan Sanctum baru bisa mencarinya pada request
            // BERIKUTNYA. Tanpa parameter ini, `central.login` selalu
            // tercatat sebagai `system`, padahal pelakunya jelas orang
            // yang baru saja berhasil login.
            //
            // Nullable dan dipakai hanya kalau auth benar-benar tidak punya
            // user. Aksi sistem (scheduler, artisan) tetap `system`.
            $aktor = $actor ?? $this->actor();

            $request = $this->request ?? request();

            AuditLog::query()->create([
                'actor_id' => $aktor?->id,
                'actor_type' => $this->actorType($aktor),
                'actor_name' => $aktor?->name,
                'actor_role' => $aktor?->role,
                'tenant_id' => $tenant?->id,
                'action' => $action,
                'subject_type' => $subjectType ? $this->shortClassName($subjectType) : null,
                'subject_id' => $subjectId !== null ? (int) $subjectId : null,
                'description' => $description !== '' ? $description : $this->describe($action, $aktor),
                'properties' => $properties ?: null,
                'ip_address' => $request instanceof Request ? $request->ip() : null,
                'user_agent' => $request instanceof Request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Catat perubahan: otomatis hitung mana yang berubah.
     *
     * Dipakai update supaya pemanggil tidak perlu membandingkan sendiri.
     * Field yang tidak berubah TIDAK ikut dicatat -- log yang memuat 20
     * field padahal hanya satu yang berubah tidak berguna saat investigasi,
     * dan hanya menambah ukuran tabel.
     *
     * @param  array<string, mixed>  $before  Nilai sebelum perubahan
     * @param  array<string, mixed>  $after  Nilai sesudah perubahan
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;

            // Loose comparison dipakai sengaja: nilai dari form selalu
            // string, sedangkan dari database bisa int. Tanpa `!=` yang
            // longgar, ubah "25" -> 25 akan tercatat sebagai perubahan
            // padahal isinya identik.
            if ($old != $value) {
                $changes[$key] = ['old' => $old, 'new' => $value];
            }
        }

        return $changes;
    }

    /**
     * Daftar aksi yang tersedia untuk filter.
     *
     * Berasal dari data yang sudah tercatat, bukan dari konstanta di kode.
     */
    public function availableActions(): array
    {
        try {
            return AuditLog::query()
                ->distinctActions()
                ->pluck('action')
                ->sort()
                ->values()
                ->all();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /* ============================================================
     | PEKERJAAN INTERNAL
     |============================================================
     */

    /** User yang sedang login, atau null untuk aksi sistem. */
    private function actor(): ?object
    {
        try {
            return auth()->user();
        } catch (Throwable) {
            return null;
        }
    }

    private function actorType(?object $actor): string
    {
        if ($actor === null) {
            return 'system';
        }

        return ($actor->role ?? '') === 'superadmin' ? 'superadmin' : 'user';
    }

    /** Deskripsi default kalau pemanggil tidakmemberikannya. */
    private function describe(string $action, ?object $actor): string
    {
        $nama = $actor?->name ?? 'sistem';

        return "{$nama} menjalankan {$action}.";
    }

    /**
     * Ambil nama class tanpa namespace-nya.
     *
     * `App\Models\Central\Tenant` disimpan sebagai `Tenant`, bukan
     * `App\Models\Central\Tenant`. Query `subject_type` jadi jauh lebih
     *-ringkas dan tidak harus diubah kalau namespace suatu model berubah
     * karena refactor.
     */
    private function shortClassName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }
}
