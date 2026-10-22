<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /**
     * Rincian baris tagihan.
     *
     * Diurutkan lewat relasi, bukan lewat `orderBy()` di query pemanggil:
     * setiap tempat yang memuat item untuk PDF atau form edit memerlukan
     * urutan yang sama, dan `with()` menaruhnya di relasi supaya tidak bisa
     * terlewat di satu jalur sementara jalur lain mengurutkannya.
     */
    public function items(): HasMany
    {
        return $this->hasMany(TenantInvoiceItem::class, 'tenant_invoice_id')->orderBy('urutan');
    }

    /**
     * Pembayaran tagihan ini.
     *
     * `HasOne`, bukan `HasMany`, dan itu bukan pertanyaan lain yang
     * dijawab. Satu tagihan hanya punya satu pembayaran: index unik di
     * `tenant_invoice_payments.tenant_invoice_id` yang menegakkan itu,
     * jadi bentuk relasinya di sini mengikuti constraint itu.
     *
     * Dipakai `HasOne` karena bentuk JSON-nya ikut berubah: `HasOne`
     * dibaca frontend sebagai satu objek, sedangkan `HasMany` mengirim
     * array yang isinya nol atau satu elemen -- dan pemanggil harus
     * memeriksa dua-duanya di setiap tempat. Tagihan yang belum dibayar
     * mengirim `null`, yang jelas lebih jujur daripada array kosong yang
     * terlihat seperti "sudah ada pembayarannya tapi tidak terbaca".
     */
    public function payment(): HasOne
    {
        return $this->hasOne(TenantInvoicePayment::class, 'tenant_invoice_id');
    }

    /**
     * Rincian item sebagai satu baris teks untukcetakan.
     *
     * Invoice lama (sebelum tabel item ada) tidak punya baris apa pun. Untuk
     * keduanya, teks ini harus selalu punya isi -- cetakan tidak boleh
     * menampilkan baris DESCRIPTION kosong. Kalau tidak ada item, nama
     * layanan diambil dari `keterangan`, dan kalau itu juga kosong barisnya
     * dijatuhkan karena "jumlah" saja sudah tercetak di kolom TOTAL.
     */
    public function itemLabel(): string
    {
        if ($this->items->isNotEmpty()) {
            return $this->items->first()->nama;
        }

        return trim((string) $this->keterangan) !== ''
            ? (string) $this->keterangan
            : 'Tagihan Langganan ASTALOKA';
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
