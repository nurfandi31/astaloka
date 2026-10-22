<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillPayment extends Model
{
    protected $fillable = [
        'bill_id',
        'amount_paid',
        'confirmed_by',
        'paid_at'
    ];

    /**
     * `paid_at` harus dikembalikan sebagai objek Carbon, bukan string polos.
     *
     * Tanpa cast, atribut ini keluar sebagai `"2026-08-15 00:00:00"` dan
     * pemanggil yang memformatnya sebagai tanggal -- misalnya
     * `MonthlyBillController` yang mengoper `$payment->paid_at` ke
     * `tgl_transaksi`, atau laporan Piutang Komisi -- harus memformat ulang
     * sendiri. Model `Payment` dan `Central\TenantInvoice` sudah punya cast
     * yang sama; model ini tertinggal.
     */
    protected $casts = [
        'paid_at'     => 'datetime',
        'amount_paid' => 'decimal:2',
    ];

    public function bill()
    {
        return $this->belongsTo(MonthlyBill::class, 'bill_id');
    }

    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'reverence');
    }
}
