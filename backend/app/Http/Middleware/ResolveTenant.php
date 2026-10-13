<?php

namespace App\Http\Middleware;

use App\Models\Central\Tenant;
use App\Services\TenantManager;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arahkan koneksi database ke lokasi yang melayani request.
 *
 * URUTAN PENTING. Middleware ini wajib jalan SEBELUM `auth:sanctum`, karena
 * Sanctum mencari token lewat `$model::findToken()`, dan model
 * `PersonalAccessToken` memakai `database.default`. Kalau auth jalan lebih
 * dulu, token akan dibaca dari database yang salah.
 *
 * DAUA DUNIA TOKEN
 *
 * Token hidup di dua database berbeda dan keduanya memakai tabel
 * `personal_access_tokens` dengan kolom `id` yang sama:
 *
 *   - token lokasi  -> database lokasi
 *   - token pusat   -> database pusat (superadmin ASTA BRATA)
 *
 * Jadi middleware ini tidak hanya mengaktifkan lokasi -- ia juga HARUS
 * memilih dengan benar mana yang jadi `database.default`. Tanpa itu:
 *   - token pusat tidak ditemukan karena dibaca di database lokasi (401)
 *   - atau sebaliknya, token lokasi tidak ditemukan
 *
 * Karena `id` kedua tabel sama-sama autoincrement dari 1, token `1|abc` di
 * satu database bisa collide dengan token `1|xyz` di database lain. Angka
 * `id` pada prefix token memang tidak unik secara global, tapi Sanctum
 * memverifikasi hash `token` setelah menemukan baris, sehingga tabrakan id
 * hanya menambah satu query sia-sia -- bukan celah keamanan. Yang tetap
 * penting: database yang dipilih harus benar sejak awal.
 */
class ResolveTenant
{
    /**
     * Prefix path milik panel pusat. Request ke sini membaca database pusat.
     *
     * Dicek per-path, bukan per-role, karena role baru diketahui SETELAH
     * token ditemukan -- padahal keputusan database harus diambil sebelum
     * itu.
     */
    private const CENTRAL_PREFIX = 'api/central';

    /**
     * Endpoint tagihan milik USER LOKASI, bukan pusat.
     *
     * Harus dikecualikan dari CENTRAL_PREFIX. Tanpa ini, user lokasi yang
     * membuka halaman "tagihan saya" akan dicari di database pusat dan
     * mendapat 401 tepat saat dia paling butuh melihat tagihannya.
     */
    private const LOCATION_PATHS = [
        'api/tagihan-saya',
    ];

    public function __construct(
        private TenantResolver $resolver,
        private TenantManager $manager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();

        if ($this->isCentralPath($path)) {
            Config::set('database.default', (string) config('tenancy.central_connection', 'central'));

            return $next($request);
        }

        $tenant = $this->resolver->resolve($request);

        if ($tenant instanceof Tenant) {
            $this->manager->activate($tenant);

            // Dibagikan ke view/controller supaya UI bisa menampilkan nama
            // lokasi tanpa query ulang ke database pusat.
            $request->attributes->set('tenant', $tenant);
        }

        return $next($request);
    }

    private function isCentralPath(string $path): bool
    {
        foreach (self::LOCATION_PATHS as $loc) {
            if ($path === $loc || str_starts_with($path, $loc.'/')) {
                return false;
            }
        }

        return $path === self::CENTRAL_PREFIX || str_starts_with($path, self::CENTRAL_PREFIX.'/');
    }
}
