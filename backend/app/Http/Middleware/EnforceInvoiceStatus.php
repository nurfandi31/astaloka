<?php

namespace App\Http\Middleware;

use App\Models\Central\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blokir akses aplikasi lokasi yang tagihan langganannya lewat jatuh tempo.
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
 */
class EnforceInvoiceStatus
{
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

        // Tagihan yang belum jatuh tempo TIDAK memblokir. Yang memblokir hanya
        // invoice yang sudah lewat deadline -- supaya lokasi tetap bisa dipakai
        // dan membayar, bukan terkunci sejak hari tagihan dibuat.
        $terlambat = $tenant->unpaidInvoices()
            ->get()
            ->filter(fn ($inv) => $inv->isOverdue());

        if ($terlambat->isEmpty()) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Tagihan langganan ASTALOKA untuk lokasi ini belum lunas. '
                .'Akses aplikasi dinonaktifkan sementara. '
                .'Silakan lakukan pembayaran kepada ASTA BRATA TEKNOLOGI.',
            'code' => 'INVOICE_OVERDUE',
            'invoices' => $terlambat->map(fn ($inv) => [
                'nomor' => $inv->nomor,
                'jumlah' => (float) $inv->jumlah,
                'tgl_invoice' => $inv->tgl_invoice?->toDateString(),
                'deadline' => $inv->deadline()->toDateString(),
                'telat' => $inv->daysRemaining(),
            ])->values(),
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
