<?php

namespace App\Http\Middleware;

use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi akses ke panel pusat (ASTA BRATA).
 *
 * Superadmin adalah user yang TIDAK punya lokasi. Ia hidup di database pusat
 * dengan role `superadmin`, dan operate pada registry lokasi -- bukan pada
 * data operasional lokasi mana pun.
 *
 * Pemisahan ini yang membuat `role:admin` yang sudah ada tetap utuh sebagai
 * "admin satu lokasi". Tanpa middleware ini, menabuh role `superadmin` ke
 * enum `users` saja akan membuat user pusat ikut masuk ke semua route
 * operasional, karena `CheckRole` hanya membandingkan string role.
 */
class EnsureSuperadmin
{
    public function __construct(private TenantManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'superadmin') {
            return response()->json([
                'success' => false,
                'message' => 'Halaman ini hanya untuk administrator pusat ASTA BRATA.',
            ], 403);
        }

        // Route pusat SELALU membaca database pusat. Kalau request ini pernah
        // lewat middleware ResolveTenant (mis. lewat `api` group), default
        // mungkin sudah diarahkan ke database lokasi -- dan `tenants`/
        // `tenant_invoices` tidak ada di sana.
        //
        // Karena itu model central dikunci `$connection = 'central'`, tapi
        // pemanggilan DB::table('...') dan query mentah tidak ikut terlindungi.
        // Guard di sini menutup celah itu.
        $this->manager->deactivate();

        $request->attributes->set('scope', 'central');

        return $next($request);
    }
}
