<?php

namespace App\Http\Middleware;

use App\Services\MenuAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak request API ke menu yang tidak diizinkan jabatan pemanggil.
 *
 * KENAPA PERLU, BUKAN CUMA MENYEMBUNYIKAN MENU
 *
 * Sidebar yang disembunyikan bukan pengaman. URL halaman masih bisa
 * diketik tangan, dan endpoint-nya tetap bisa dipanggil dari Postman atau
 * dari console browser. Kalau satu-satunya pembatas adalah tampilan, maka
 * "bendahara hanya boleh Transaksi" tidak benar-benar berlaku -- bendahara
 * tetap bisa mengubah jurnal umum dengan satu panggilan endpoint.
 *
 * Yang diperiksa di sini adalah ENDPOINT, bukan path halaman, karena
 * path halaman tidak pernah sampai ke server: yang sampai adalah
 * `GET /api/transactions`. Dipetakan ke menu lewat prefix di
 * `config/menu_access.php`.
 *
 * KAPAN TIDAK BERLAKU
 *
 *  - Role di luar `enforce_roles` (default: selain admin). Milik `CheckRole`.
 *  - Menu di `always_allowed_api`: dipakai bersama di luar area menu
 *    (judul sidebar, notifikasi, dropdown form). Memblokirnya merusak
 *    halaman yang justru terbuka.
 *  - Jabatan yang belum pernah dikonfigurasi. Semuanya terbuka sampai
 *    ada yang benar-benar mencentangnya, jadi lokasi yang belum memakai
 *    fitur ini tidak ikut berubah.
 *
 * CATATAN PENTING SOAL ROLE ADMIN
 *
 * Admin TIDAK diberi pengecualian di sini. Kelihatannya seperti sesuatu
 * yang perlu ditambahkan -- "admin harus selalu bisa akses" -- tapi itu
 * justru membuat fitur ini tidak berarti apa-apa: bendahara memakai role
 * `admin` justru supaya bisa memakai Jurnal Umum, jadi membebaskan admin
 * berarti membebaskan bendaharanya juga.
 *
 * Yang mencegah terkunci total bukan pengecualian role, melainkan menu
 * `dashboard` yang selalu di `always`: login selalu punya halaman yang
 * bisa dibuka, dan `menu-access/*` juga bebas supaya pengaturan yang
 * baru saja keliru bisa dibetulkan dari UI tanpa turun ke database.
 *
 */
class EnforceMenuAccess
{
    public function __construct(private MenuAccessService $menu) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! $this->applies($user->role)) {
            return $next($request);
        }

        /*
         * `$request->path()` INI sudah memuat prefix grup `api`, jadi
         * nilainya `api/pelaporan`, bukan `pelaporan`.
         *
         * Ini persis alasan `tenancy.invoice_exempt_paths` menulis `api/login`
         * dan bukan `login`. Config di `menu_access.php` sengaja memakai
         * bentuk yang sama (tanpa `api/`) supaya readsnya enak -- jadi
         * prefix-nya dibuang di sini, di satu tempat, daripada menulis
         * ulang tiap prefix dengan awalan `api/`.
         *
         * Kalau lupa membuangnya, tidak ada satu pun prefix yang akan cocok:
         * gejalanya bukan 403 yang salah, tapi middleware yang SELALU lolos
         * tanpa memeriksa apa pun -- pemblokiran yang terlihat aktif tapi
         * sebenarnya tidak memblokir apa pun juga.
         */
        $path = $this->stripApiPrefix($this->normalize($request->path()));

        if ($this->isAlwaysAllowed($path)) {
            return $next($request);
        }

        $allowed = $this->menu->allowedKeysFor($user);

        // Semua menu boleh: tidak ada yang perlu diperiksa.
        if ($this->hasFullAccess($user, $allowed)) {
            return $next($request);
        }

        $pemilik = $this->menuKeysForPrefix($path);

        // Prefix yang tidak dimiliki menu mana pun -> biarkan lewat.
        // Contohnya endpoint milik role lain yang tidak tercakup daftar,
        // dan request yang tidak butuh hak menu. Menolaknya di sini akan
        // memblokir hal yang tidak pernah dimaksudkan untuk diblokir.
        if ($pemilik === []) {
            return $next($request);
        }

        // DIBLOKIR kalau SEMUA menu yang memakai prefix ini tidak
        // diizinkan. Kalau salah SATU boleh, endpointnya tetap dibuka.
        //
        // Contoh: `GET /accounts` dipakai CoA dan form Jurnal Umum. User
        // yang punya Jurnal Umum tapi tidak punya CoA tetap harus bisa
        // mengisi form jurnal -- memotongnya akan membuat dropdown akun
        // di halaman yang memang boleh dibuka kosong.
        foreach ($pemilik as $key) {
            if (in_array($key, $allowed, true)) {
                return $next($request);
            }
        }

        $namaMenu = collect($this->menu->catalog())
            ->firstWhere('key', $pemilik[0]);

        return response()->json([
            'success' => false,
            'message' => 'Menu '
                .($namaMenu['label'] ?? $pemilik[0])
                .' tidak diizinkan untuk jabatan Anda.',
            'code' => 'MENU_ACCESS_DENIED',
        ], 403);
    }

    /** Apakah role ini ikut aturan hak akses jabatan. */
    private function applies(?string $role): bool
    {
        if (! $role) {
            return false;
        }

        return in_array(trim($role), $this->menu->enforceRoles(), true);
    }

    private function normalize(string $path): string
    {
        return '/'.ltrim($path, '/');
    }

    /**
     * Buang prefix grup `api` dari path.
     *
     * Hanya satu tingkat, dan hanya kalau memang di awal. Path seperti
     * `api/central/...` jadi `central/...` -- bukan `central/.../...`,
     * yang akan membuat semua endpoint pusat terlihat seperti milik satu
     * menu. Itu memang tidak merusak apa pun (tidak ada prefix
     * `central/...` di katalog), tapi hasilnya membingungkan saat dibaca.
     */
    private function stripApiPrefix(string $path): string
    {
        return preg_replace('#^/api(?=/|$)#', '', $path) ?? $path;
    }

    /**
     * User ini punya akses penuh, sehingga tidak ada yang perlu diperiksa.
     *
     * Dicek lewat jumlah key, bukan dengan memanggil `allowedKeysFor()`
     * dua kali: akses penuh berarti daftar hasil == seluruh katalog.
     */
    private function hasFullAccess($user, array $allowed): bool
    {
        return count($allowed) === count($this->menu->catalog());
    }

    /**
     * Menu mana saja yang memakai prefix endpoint ini.
     *
     * Hasilnya hanya himpunan, tidak urutannya: yang penting semua
     * pemilik dikumpulkan, bukan yang pertama cocok.
     * @return array<int, string>
     */
    private function menuKeysForPrefix(string $path): array
    {
        $cocok = [];

        foreach ($this->menu->catalog() as $item) {
            foreach ((array) ($item['api'] ?? []) as $prefix) {
                if ($this->prefixMatches($path, $prefix)) {
                    $cocok[] = (string) $item['key'];
                    break;
                }
            }
        }

        // Panjang dulu supaya prefix yang paling spesifik diperiksa
        // lebih dulu. Tidak berpengaruh pada hasilnya (semua yang cocok
        // dikumpulkan), tapi menjaga urutannya stabil dan mudah dibaca
        // saat di-debug.
        usort($cocok, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $cocok;
    }

    /** Apakah sebuah prefix termasuk endpoint `always_allowed_api`. */
    private function isAlwaysAllowed(string $path): bool
    {
        foreach ((array) config('menu_access.always_allowed_api', []) as $prefix) {
            if ($this->prefixMatches($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cocokkan path dengan satu prefix dari config.
     *
     * `/` di depan selalu dinormalkan supaya prefix yang ditulis tanpa
     * garis miring depan tetap cocok -- bentuk yang tidak konsisten di config
     * akan terlihat sebagai "selalu lolos", bukan sebagai error.
     */
    private function prefixMatches(string $path, string $prefix): bool
    {
        $prefix = '/'.ltrim($prefix, '/');

        if ($prefix === '/') {
            return true;
        }

        if ($path === $prefix) {
            return true;
        }

        // `accounts/by-level` harus cocok dengan
        // `accounts/by-level/3`, tapi tidak boleh cocok dengan
        // `accounts-abc`.
        if (str_starts_with($path, $prefix.'/')) {
            return true;
        }

        /*
         * Wildcard satu segmen, mis. prefix
         * `installation-tickets/X/survey` cocok dengan path
         * `installation-tickets/12/survey`.
         *
         * `FNM_PATHNAME` itu wajib, bukan pilihan. Tanpa flag itu, `*`
         * di `fnmatch` juga menyeberangi `/`, jadi satu `*` di tengah
         * ikut mencocokkan jalur BERAPA pun -- dan endpoint milik menu
         * lain ikut terbuka hanya karena namanya mirip.
         */
        if (str_contains($prefix, '*')) {
            return fnmatch($prefix, $path, FNM_PATHNAME);
        }

        return false;
    }
}
