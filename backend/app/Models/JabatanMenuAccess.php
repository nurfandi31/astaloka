<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu menu yang diizinkan untuk sebuah jabatan.
 *
 * KEKOSONGAN TABEL = SEMUA MENU BOLEH
 *
 * Ini bukan sekadar nilai default, tapi semantik yang disengaja:
 * `allowedMenuKeys()` mengembalikan null saat jabatan belum punya baris
 * sama sekali, dan null dibaca sebagai "belum pernah diatur" -- semua
 * menu tetap tampil. Lokasi yang baru di-migrate harus tetap bisa
 * dipakai tanpa perlu dikonfigurasi dulu.
 *
 * Begitu admin menyimpan walau hanya satu menu, baris itu menjadi
 * penanda bahwa jabatan itu sudah dikonfigurasi ketat: dari situ ke bawah
 * hanya key yang tertulis yang boleh.
 */
class JabatanMenuAccess extends Model
{
    protected $table = 'jabatan_menu_access';

    protected $fillable = [
        'jabatan_id',
        'menu_key',
    ];

    public function jabatan(): BelongsTo
    {
        return $this->belongsTo(Jabatan::class);
    }
}