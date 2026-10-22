<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AkunLevel1 extends Model
{
    /**
     * Struktur akun level 1 ada di DATABASE PUSAT, bukan di database lokasi.
     *
     * Level 1-3 adalah kerangka chart of accounts yang sama untuk semua lokasi,
     * jadi disimpan sekali di `astaloka` dan disetting dari panel pusat.
     * Level 4 (`accounts`) tetap per lokasi -- tiap lokasi boleh beda.
     *
     * Koneksi dikunci di sini, TIDAK lewat tukar `database.default` seperti
     * tabel lokasi. Konsekuensinya: relasi ke `accounts` (level 4) tidak lagi
     * bisa dimuat lewat `with()`, karena Eloquent menjalankan setiap relasi
     * pada koneksi model induknya. Relasi lintas database harus diambil
     * terpisah -- lihat `PelaporanService::loadAkunTree()`.
     */
    protected $connection = 'central';

    protected $table = 'akun_level_1';

    protected $fillable = [
        'lev1',
        'lev2',
        'lev3',
        'lev4',
        'kode_akun',
        'nama_akun',
        'jenis_mutasi',
    ];

    protected $casts = [
        'lev1' => 'integer',
        'lev2' => 'integer',
        'lev3' => 'integer',
        'lev4' => 'integer',
    ];

    public function akunLevel2(): HasMany
    {
        return $this->hasMany(AkunLevel2::class, 'parent_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'lev1', 'lev1');
    }
}
