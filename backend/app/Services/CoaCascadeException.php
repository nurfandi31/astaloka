<?php

namespace App\Services;

use RuntimeException;

/**
 * Penghapusan struktur akun pusat tertahan oleh data yang sudah terpakai.
 *
 * Throwable dengan daftar penghalang, bukan response HTTP: yang memanggil
 * adalah {@see CoaCascadeService}, bukan HTTP. Controller yang memetakan
 * ini ke 409 -- lihat `CoaController::destroy()`.
 *
 * Menyimpan penghalang di exception (bukan cuma pesan teks) karena dialog
 * konfirmasi di panel pusat menampilkan DAFTAR akun yang menahan, bukan
 * kalimat ringkasan. Tanpa itu, superadmin tahu "tidak bisa dihapus" tapi
 * tidak tahu harus-who, dan satu-satunya jalan untuk mencari tahu adalah
 * membuka tiap lokasi satu per satu secara manual.
 */
class CoaCascadeException extends RuntimeException
{
    /**
     * @param  array<int,array<string,mixed>>  $blockers
     */
    public function __construct(string $message, private array $blockers = [])
    {
        parent::__construct($message);
    }

    /** @return array<int,array<string,mixed>> */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
