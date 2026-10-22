<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rekening penampung pembayaran tagihan milik ASTA BRATA.
 *
 * Berada di database pusat, bukan di database lokasi. Lihat
 * `2026_10_16_000003_create_central_accounts_table.php` untuk seluruh
 * alasan kenapa tabel ini ada terpisah dari `accounts` milik lokasi.
 *
 * @property int $id
 * @property string $kode_akun
 * @property string $nama_akun
 * @property string $kategori  'tunai' | 'bank'
 * @property string $jenis_mutasi
 * @property bool $is_active
 */
class CentralAccount extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'kode_akun',
        'nama_akun',
        'kategori',
        'jenis_mutasi',
        'is_active',
        'no_rekening',
        'atas_nama',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TenantInvoicePayment::class, 'central_account_id');
    }

    /** Akun kas tunai. */
    public function isTunai(): bool
    {
        return $this->kategori === 'tunai';
    }

    /**
     * Nama yang ditampilkan di dropdown form pembayaran.
     *
     * Nomor rekening ikut disertakan kalau ada, karena itu yang dicari
     * orang saat memilih: "mana rekening BRI kita". Memaksakan orang
     * membuka halaman Rekening Pusat untuk mencocokkan nomor rekening
     * setiap kali mencatat pembayaran berarti memilih berdasarkan nama
     * saja -- dan dua rekening dengan nama mirip
     * (mis. "Bank BRI" dan "Bank BRI Tabungan") jadi tidak bisa
     * dibedakan.
     */
    public function label(): string
    {
        if (! $this->no_rekening) {
            return (string) $this->nama_akun;
        }

        return sprintf(
            '%s - %s%s',
            $this->nama_akun,
            $this->no_rekening,
            $this->atas_nama ? " ({$this->atas_nama})" : '',
        );
    }
}