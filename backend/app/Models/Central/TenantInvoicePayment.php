<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu pembayaran penuh untuk satu tagihan langganan.
 *
 * Berada di database pusat. Satu tagihan punya paling banyak satu
 * pembayaran -- ditegakkan index unik di `tenant_invoice_payments`, bukan
 * kode di sini. Lihat migration tabel itu untuk alasannya.
 *
 * @property int $id
 * @property int $tenant_invoice_id
 * @property int $central_account_id
 * @property string $metode  'tunai' | 'bank'
 * @property Carbon $tgl_bayar
 * @property string $jumlah
 */
class TenantInvoicePayment extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_invoice_id',
        'central_account_id',
        'metode',
        'tgl_bayar',
        'jumlah',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tgl_bayar' => 'date',
            'jumlah' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TenantInvoice::class, 'tenant_invoice_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CentralAccount::class, 'central_account_id');
    }
}