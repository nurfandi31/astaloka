<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterArusKas extends Model
{
    protected $table = 'master_arus_kas';
    protected $fillable = ['nama_akun', 'debit', 'kredit', 'parent_id'];

    public function children(): HasMany
    {
        return $this->hasMany(MasterArusKas::class, 'parent_id');
    }

    public function rek_debit(): BelongsTo
    {
        return $this->belongsTo(AkunLevel3::class, 'debit', 'kode_akun');
    }

    public function rek_kredit(): BelongsTo
    {
        return $this->belongsTo(AkunLevel3::class, 'kredit', 'kode_akun');
    }

    /**
     * Pakai relasi `rek_debit`/`rek_kredit` SEKALI untuk seluruh pohon.
     *
     * Kedua relasi itu `belongsTo` ke `akun_level_3`, yang sekarang tinggal di
     * database PUSAT sementara `master_arus_kas` per lokasi. Eloquent menjalankan
     * relasi pada koneksi model induknya, jadi `with()` akan mencari
     * `akun_level_3` di database lokasi dan gagal. Lazy load tidak menolong --
     * mekanismenya sama.
     *
     * Karena itu `akun_level_3` yang dibutuhkan diambil sekali di tengah, lalu
     * dipasang dengan `setRelation()`. Setelah itu `->rek_debit` dibaca dari
     * memori, dan pemanggil tidak perlu tahu bahwa tabelnya ada di database lain.
     *
     * `$all` boleh berupa collection hasil `get()` maupun satu baris.
     */
    public static function withRekeningAkun($all): mixed
    {
        $rows = $all instanceof \Illuminate\Support\Collection ? $all : collect([$all]);

        $kodes = $rows->flatMap(fn ($m) => [$m->debit, $m->kredit])
            ->filter()
            ->map(fn ($k) => (string) $k)
            ->unique()
            ->values();

        // Kosongkan dulu supaya tidak ada sisa relasi dari pemanggilan sebelumnya.
        foreach ($rows as $m) {
            $m->unsetRelation('rek_debit');
            $m->unsetRelation('rek_kredit');
        }

        if ($kodes->isEmpty()) {
            return $all;
        }

        $peta = AkunLevel3::whereIn('kode_akun', $kodes)->get()->keyBy('kode_akun');

        foreach ($rows as $m) {
            $m->setRelation('rek_debit', $peta->get((string) $m->debit));
            $m->setRelation('rek_kredit', $peta->get((string) $m->kredit));
        }

        return $all;
    }
}
