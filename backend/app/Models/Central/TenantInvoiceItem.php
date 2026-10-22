<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris rincian pada invoice langganan.
 *
 * Lives in database pusat -- sama seperti TenantInvoice, koneksinya dikunci.
 *
 * Perhatikan bahwa `jumlah` di sini BUKAN selalu `qty * harga_satuan`.
 * Keduanya diinput terpisah supaya nominal yang benar-benar ditagihkan tetap
 * bisa berbeda dari hasil perkalian (pembulatan, harga sudah termasuk
 * diskon). Lihat migration tabelnya untuk alasannya.
 *
 * @property int $id
 * @property int $tenant_invoice_id
 * @property int $urutan
 * @property string $nama
 * @property string $qty
 * @property string $harga_satuan
 * @property string $jumlah
 * @property string|null $satuan
 * @property string|null $catatan
 */
class TenantInvoiceItem extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_invoice_id',
        'urutan',
        'nama',
        'qty',
        'harga_satuan',
        'jumlah',
        'satuan',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
            'qty' => 'decimal:2',
            'harga_satuan' => 'decimal:2',
            'jumlah' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TenantInvoice::class, 'tenant_invoice_id');
    }

    /**
     * Sum nominal rincian, bukan sumber kebenaran.
     *
     * Dipakai di cetakan untuk membandingkan dengan `jumlah` invoice dan
     * menampilkan selisihnya sebagai Diskon. Tidak boleh dipakai untuk
     *rogate nilai tagihan yang harus dibayar.
     */
    public static function totalFor(int $invoiceId): float
    {
        return (float) static::query()
            ->where('tenant_invoice_id', $invoiceId)
            ->sum('jumlah');
    }
}