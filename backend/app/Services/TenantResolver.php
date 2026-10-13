<?php

namespace App\Services;

use App\Models\Central\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Menentukan lokasi mana yang melayani request.
 *
 * Dua sumber identitas, berurutan dari yang paling kuat:
 *
 *   1. HOST   -- `tirto-mulo.astaloka.co.id`. Ini cara produksi, karena
 *                user tidak pernah memilih lokasi secara manual; browser
 *                yang membawa identitasnya lewat subdomain.
 *
 *   2. SLUG   -- header `X-Tenant-Slug` atau body/param. Ini jalur untuk
 *                development lokal, di mana semua lokasi diakses lewat
 *                `localhost` sehingga host tidak bisa membedakan.
 *
 * Header slug sengaja hanya dipercaya di luar produksi. Kalau tidak, siapa
 * saja bisa menambahkan `X-Tenant-Slug` di request dan membaca data lokasi
 * lain hanya dengan menebak slug-nya -- tanpa perlu punya akun di lokasi itu.
 */
class TenantResolver
{
    public function __construct(private TenantManager $manager) {}

    /**
     * Lokasi untuk request ini, atau null kalau tidak ada.
     *
     * Null BUKAN berarti error: request di domain pusat (landing page,
     * login superadmin, API admin) memang tidak punya lokasi.
     */
    public function resolve(Request $request): ?Tenant
    {
        $tenant = $this->fromHost($request);

        if ($tenant) {
            return $tenant;
        }

        return $this->fromSlug($request);
    }

    /** Sama seperti resolve(), tapi gagal keras kalau lokasi tidak ketemu. */
    public function resolveOrFail(Request $request): Tenant
    {
        $tenant = $this->resolve($request);

        if (! $tenant) {
            throw new RuntimeException(
                'Lokasi tidak ditemukan untuk host "'.$request->getHost().'". '
                .'Pastikan subdomain terdaftar di tabel `tenants` database pusat.'
            );
        }

        return $tenant;
    }

    /**
     * Cari lokasi dari host request.
     *
     * Host dicocokkan ke kolom `domain` dan `domain_alternatif`, bukan
     * dengan memotong subdomain. Menebak subdomain dari host lebih sederhana,
     * tapi salah begitu cepat: dengan domain `astaloka.co.id`, host
     * `www.astaloka.co.id` akan menghasilkan slug `www` yang tidak ada,
     * sedangkan `tirto-mulo.astaloka.co.id` menghasilkan `tirto-mulo` --
     * padahal nama lokasi belum tentu sama dengan slug subdomain-nya.
     *
     * Host SELALU diperiksa, di mode `host` maupun mode `slug`. Pencocokan
     * host hanya memperketat identitas: kalau tidak cocok, request tetap
     * boleh lanjut ke jalur slug. Yang bersifat opsional adalah sebaliknya --
     * di produksi slug tidak boleh dipercaya -- dan itu sudah ditangani
     * slugAllowed() di bawah.
     *
     * Tanpa pengecekan host tanpa syarat, `TENANCY_LOOKUP=slug` untuk
     * development membuat subdomain lokasi ikut dilayani halaman depan.
     * Petugas yang membuka `tirto-mulo.astaloka.test` akan melihat halaman
     * marketing di mana dia justru ingin langsung masuk login.
     */
    private function fromHost(Request $request): ?Tenant
    {
        $host = $request->getHost();

        if ($host === '') {
            return null;
        }

        // Satu query, bukan load-all-then-loop: daftar lokasi tumbuh
        // seiring pelanggan, dan tiap request tidak boleh ikut melambat.
        return Tenant::query()
            ->where('is_active', true)
            ->where(function ($q) use ($host) {
                $q->where('domain', $host)
                    ->orWhere('domain_alternatif', $host);
            })
            ->first();
    }

    /** Cari lokasi dari slug yang dikirim frontend. */
    private function fromSlug(Request $request): ?Tenant
    {
        $header = (string) config('tenancy.slug_header', 'X-Tenant-Slug');

        // Produksi hanya percaya host. Lokasi di sini hanya untuk frontend
        // dev, di mana `localhost` tidak bisa membedakan lokasi.
        if (! $this->slugAllowed()) {
            return null;
        }

        $slug = $request->header($header);

        if (! $slug) {
            // Body hanya untuk endpoint login, yang memang dijalankan
            // sebelum ada lokasi yang aktif.
            $slug = $request->input('tenant');
        }

        if (! $slug || ! is_string($slug)) {
            return null;
        }

        $slug = strtolower(trim($slug));

        // Slug bukan input bebas -- dia masuk ke query. Batasi pola supaya
        // tidak bisa dipakai untuk menggali atau menebak nama lain.
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) {
            return null;
        }

        return Tenant::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /** Apakah slug boleh dipercaya pada environment ini. */
    private function slugAllowed(): bool
    {
        if (config('tenancy.lookup') === 'slug') {
            return true;
        }

        return ! app()->environment('production');
    }

    /** Aktifkan lokasi untuk request ini. */
    public function activateFor(Request $request): ?Tenant
    {
        $tenant = $this->resolve($request);

        if ($tenant) {
            $this->manager->activate($tenant);
        }

        return $tenant;
    }
}
