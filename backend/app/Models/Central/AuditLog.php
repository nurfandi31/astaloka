<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit untuk aksi yang mengubah data sensitif.
 *
 * Berada di database PUSAT -- satu tabel untuk dua sisi aplikasi:
 * aksi superadmin di panel pusat dan aksi user di dalam lokasi.
 *
 * Karena tabel ini tidak punya soft delete dan aplikasi tidak menyediakan
 * endpoint untuk menghapus, ia diperlakukan sebagai catatan append-only.
 * Query ke model ini akan selalu membaca database pusat, apa pun koneksi
 * default yang sedang aktif -- penting, karena `AuditLogger` juga dipanggil
 * dari dalam konteks tenant.
 */
class AuditLog extends Model
{
    /**
     * Dipaksa ke database pusat.
     *
     * Ini bukan sekadar dokumentasi: `TenantManager::activate()` mengganti
     * `database.default` ke database lokasi. Tanpa override di sini, setiap
     * pencatatan audit yang terjadi di tengah request yang sudah ter-resolve
     * tenant akan diam-diam menulis ke database lokasi -- dan log milik
     * pusat hilang tanpa jejak.
     */
    protected $connection = 'central';

    protected $fillable = [
        'actor_id',
        'actor_type',
        'actor_name',
        'actor_role',
        'tenant_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /*
     * Catatan: label dan warna badge (`actionLabel`, `toneFor`) ikut dihapus
     * bersama halaman audit 2026-10-15. Tidak ada yang memakainya lagi --
     * `/central/overview` hanya menghitung jumlah baris, tidak membaca
     * label. Kalau someday butuh membaca log dari panel lagi, kedua
     * helper itu harus dibangun ulang dari data yang tercatat, bukan dari
     * daftar aksi hardcoded yang pasti akan basi.
     */
}
