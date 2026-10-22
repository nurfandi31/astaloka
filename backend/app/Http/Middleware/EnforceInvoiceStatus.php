<?php

namespace App\Http\Middleware;

use App\Models\Central\Tenant;
use App\Services\InvoiceGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blokir akses aplikasi lokasi yang masih punya tagihan langganan.
 *
 * Meniru `IdentifyTenant` di SIDBM, dengan satu perbedaan penting: blokir
 * terjadi di level RESPONSE, bukan dengan mencabut token.
 *
 * MENCABUT TOKEN ITU SALAH
 *
 * Force-logout paling mudah ditulis dengan `$user->currentAccessToken()->delete()`.
 * Tapi begitu token dihapus, `auth:sanctum` pada request berikutnya membalas
 * 401 -- termasuk untuk `tagihan-saya` dan `logout` yang sengaja dikecualikan.
 * Akibatnya user terkunci total tanpa jalan keluar: tidak bisa melihat apa
 * yang harus dibayar, tidak bisa keluar dari aplikasi, dan tidak punya kontak
 * untuk menghubungi pusat. Itu pengucilan, bukan penagihan.
 *
 * Yang benar: token tetap hidup, tapi setiap request yang tidak dikecualikan
 * dibalas 402 beserta rincian tagihannya. Efeknya sama untuk pengguna --
 * aplikasi tidak bisa dipakai -- tapi status lunas langsung memulihkan akses
 * tanpa perlu login ulang, dan user selalu bisa melihat tagihannya.
 *
 * Syarat pemblokiran TIDAK ditulis di sini: semuanya lewat {@see InvoiceGate}
 * supaya aturan yang sama berlaku di login, middleware ini, dan popup.
 */
class EnforceInvoiceStatus
{
    public function __construct(private InvoiceGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->attributes->get('tenant');

        // Request di domain pusat tidak punya langganan; biarkan lewat.
        if (! $tenant instanceof Tenant) {
            return $next($request);
        }

        // Dicek SEBELUM query tagihan, supaya endpoint yang memang harus
        // selalu bisa diakses tidak pernah bergantung pada koneksi ke pusat.
        if ($this->isExempt($request)) {
            return $next($request);
        }

        $blocking = $this->gate->blocking($tenant);

        if ($blocking->isEmpty()) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => $this->gate->message($blocking),
            'code' => 'INVOICE_UNPAID',
            // Kode lama tetap disertakan supaya klien yang sudah ditulis
            // terhadap `INVOICE_OVERDUE` tidak ikut mati. Tidak ada yang
            // memakainya di frontend saat ini, tapi mengubah nilai enum
            // tanpa alasan adalah jenis perubahan yang mahal nanti.
            'legacy_code' => 'INVOICE_OVERDUE',
            'tagihan' => $this->gate->payload($tenant),
        ], 402);
    }

    /**
     * Rute yang tetap boleh diakses meski terlambat.
     *
     * Tanpa ini user terkunci total tanpa jalan keluar: tidak bisa logout,
     * tidak bisa melihat tagihannya, tidak bisa menghubungi pusat. Daftar
     * ini lives in `config/tenancy.php` supaya bisa diubah tanpa menyentuh
     * kode.
     */
    private function isExempt(Request $request): bool
    {
        // `$request->path()` SELALU diawali `/`, sedangkan config ditulis
        // tanpa garis miring depan (`api/login`) karena itu yang enak dibaca.
        // Tanpa normalisasi di kedua sisi, tidak satu pun exempt path akan
        // cocok -- gejalanya user terkunci total, persis yang seharusnya
        // dicegah oleh daftar ini.
        $path = '/'.ltrim($request->path(), '/');

        foreach ((array) config('tenancy.invoice_exempt_paths', []) as $exempt) {
            $exempt = '/'.ltrim($exempt, '/');

            if ($path === $exempt || fnmatch($exempt, $path)) {
                return true;
            }
        }

        return false;
    }
}
