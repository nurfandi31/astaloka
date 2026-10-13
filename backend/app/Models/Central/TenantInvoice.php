<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invoice langganan dari ASTA BRATA (pusat) ke satu lokasi.
 *
 * Meniru `admin_invoice` di SIDBM: pusat menagih biaya langganan, lokasi
 * membayar, dan selama belum dibayar lewat batas toleransi user lokasi
 * diblokir oleh middleware `EnforceInvoiceStatus`.
 *
 * Lives in database pusat -- sama seperti Tenant, koneksinya dikunci.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $nomor
 * @property Carbon $tgl_invoice
 */
class TenantInvoice extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'nomor',
        'tgl_invoice',
        'tgl_jatuh_tempo',
        'tgl_lunas',
        'jumlah',
        'status',
        'periode',
        'keterangan',
        'created_by',
        'confirmed_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'tgl_invoice' => 'date',
            'tgl_jatuh_tempo' => 'date',
            'tgl_lunas' => 'date',
            'paid_at' => 'datetime',
            'jumlah' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'PAID';
    }

    /**
     * Batas akhir invoice belum dibayar.
     *
     * Dipakai `tgl_jatuh_tempo` kalau diisi. Bila kosong, jatuh ke
     * `tgl_invoice` + jumlah bulan toleransi dari config.
     */
    public function deadline(): Carbon
    {
        if ($this->tgl_jatuh_tempo) {
            return $this->tgl_jatuh_tempo->copy();
        }

        return $this->tgl_invoice->copy()
            ->addMonths((int) config('tenancy.invoice_grace_months', 1));
    }

    /** Sudah lewat batas? */
    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->deadline()->isPast();
    }

    /** Sisa hari sebelum diblokir. Negatif = sudah terlambat. */
    public function daysRemaining(): int
    {
        // startOfDay() supaya invoice yang jatuh tempo hari ini tidak
        // dihitung "0 hari lagi" lalu langsung dianggap lewat saat jam 00:01.
        return (int) now()->startOfDay()->diffInDays($this->deadline()->startOfDay(), false);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('status', 'UNPAID');
    }
}
