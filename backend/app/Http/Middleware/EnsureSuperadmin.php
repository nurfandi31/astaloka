<?php

namespace App\Http\Middleware;

use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

        /*
         * Akun yang dinonaktifkan harus kehilangan aksesNYA, bukan hanya
         * tidak bisa masuk lagi.
         *
         * Token panel pusat berlaku 8 jam. Menonaktifkan akun di tabel tanpa
         * mencabut token yang sudah terbit berarti orang itu tetap punya
         * akses sampai tokennya kedaluwarsa -- keputusan "nonaktifkan akun
         * ini" jadi tidak berlaku untuk akses yang paling penting untuk
         * dicabut: akses yang sudah ada.
         *
         * `revokeTokens()` di `SuperadminController` sudah mencabutnya saat
         * statusnya diubah. Pengecekan di sini adalah jaring pengaman untuk
         * akun yang dinonaktifkan dari luar aplikasi -- mis. diedit langsung
         * di database, atau ditonjolkan oleh superadmin yang kehilangan
         * akses shell-nya.
         *
         * Token milik request yang sedang berjalan ikut dihapus, jadi
         * request ini sendiri tetap lanjut (dipakai untuk membalas dengan
         * 403, bukan logout mendadak), tapi request berikutnya sudah tidak
         * punya token valid.
         */
        if (! $this->isActive($user)) {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Akun Anda sudah dinonaktifkan. Hubungi administrator pusat lain untuk mengaktifkannya kembali.',
            ], 403);
        }

        /*
         * Route pusat SELALU membaca database pusat. Kalau request ini pernah
         * lewat middleware ResolveTenant (mis. lewat `api` group), default
         * mungkin sudah diarahkan ke database lokasi -- dan `tenants`/
         * `tenant_invoices` tidak ada di sana.
         *
         * Karena itu model central dikunci `$connection = 'central'`, tapi
         * pemanggilan DB::table('...') dan query mentah tidak ikut terlindungi.
         * Guard di sini menutup celah itu.
         *
         * Nilai default yang lama disimpan dan dipulihkan setelah request
         * selesai. Tanpa itu, `database.default` ikut berubah permanen --
         * yang pada request biasa hanya berarti request berikutnya membaca
         * tabel yang salah, tapi pada test itu berarti `RefreshDatabase`
         * membungkus transaksi di koneksi pusat sementara migrasi men-drop
         * koneksi lokasi: keduanya lalu saling mengunci.
         */
        $defaultSebelum = config('database.default');

        $this->manager->deactivate();

        $request->attributes->set('scope', 'central');

        try {
            return $next($request);
        } finally {
            // `finally`, bukan setelah `$next` sukses: kalau controller melempar
            // exception, default tetap harus kembali atau seluruh request
            // berikutnya -- termasuk render halaman error -- membaca database
            // pusat tanpa sengaja.
            config(['database.default' => $defaultSebelum]);
        }
    }

    /**
     * Status aktif user pusat, dibaca dari database pusat.
     *
     * Query terpisah, bukan `$user->is_active`: model `User` dipakai oleh dua
     * aplikasi dengan satu model yang sama, dan `database.default` saat
     * middleware ini berjalan belum tentu menunjuk database pusat. Tabel
     * `users` di database lokasi tidak punya kolom ini sama sekali --
     * membaca dari sana akan menyalahartikan akun lokasi yang superadmin
     * sebagai "aktif", karena tidak ada nilainya untuk dibaca.
     *
     * Kolom `is_active` baru ada sejak migration
     * `2026_10_14_000003`. Kalau belum ada -- database pusat yang belum
     * dimigrasikan penuh -- hasilnya dianggap aktif, supaya middleware ini
     * tidak ikut mengunci panel yang masih berfungsi.
     */
    private function isActive(object $user): bool
    {
        try {
            $central = DB::connection((string) config('tenancy.central_connection', 'central'));

            if (! $central->getSchemaBuilder()->hasColumn('users', 'is_active')) {
                return true;
            }

            $nilai = $central->table('users')
                ->where('id', $user->id)
                ->value('is_active');

            // Barisnya hilang (dihapus dari luar aplikasi) diperlakukan sama
            // dengan akun nonaktif: lebih baik ditolak daripada membiarkan
            // entri yang sudah tidak ada ekstensinya dipakai.
            return $nilai !== null && (bool) $nilai;
        } catch (Throwable $e) {
            report($e);

            // Gagal membaca TIDAK boleh mengunci panel. Menolak di sini
            // membuat satu masalah kecil (koneksi sesaat) menjadi semua
            // superadmin terkunci dari aplikasi mereka sendiri.
            return true;
        }
    }
}
