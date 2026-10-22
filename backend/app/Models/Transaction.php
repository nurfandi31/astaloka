<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tgl_transaksi',
        'account_debet',
        'account_kredit',
        'transaction_group',
        'reverence_type',
        'reverence_id',
        'penerima_komisi_id',
        'keterangan_transaksi',
        'relasi',
        'saldo',
        'urutan',
        'id_user',
    ];

    protected $casts = [
        'tgl_transaksi' => 'date',
        'saldo'         => 'decimal:0',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function penerimaKomisi()
    {
        return $this->belongsTo(User::class, 'penerima_komisi_id');
    }

    public function accountDebet()
    {
        return $this->belongsTo(Account::class, 'account_debet', 'kode_akun');
    }

    public function accountKredit()
    {
        return $this->belongsTo(Account::class, 'account_kredit', 'kode_akun');
    }

    public function bill() {
        return $this->belongsTo(MonthlyBill::class, 'reverence_id', 'id');
    }
}
