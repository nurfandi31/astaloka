<?php

use App\Services\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman Web
|--------------------------------------------------------------------------
|
| Yang hidup di sini hanya dua hal:
|
|   1. Landing page ASTALOKA -- hanya di domain pusat, tanpa auth
|   2. Health check -- /up (dipakai load balancer & monitoring)
|
| Login TIDAK ada di sini. Aplikasi lokasi adalah SPA Vue yang punya
| LoginView sendiri; halaman login Blade pernah dibuat tapi dihapus karena
| tidak kompatibel dengan frontend: SPA menyimpan token di
| `localStorage.auth_token`, sedangkan halaman Blade menulis ke storage
| sendiri. Dua kunci yang berbeda = dua sesi yang berbeda = user yang
| "sudah login" tapi tetap dikick balik ke halaman login.
|
| Seluruh API ada di routes/api.php, dan panel pusat ada di routes/central.php.
|
*/

/*
| Domain pusat vs subdomain lokasi
|
| Lokasi punya domain sendiri dan TIDAK boleh melihat landing page: begitu
| petugas desa membuka subdomain lokasinya, dia harus langsung masuk ke
| aplikasi. Menampilkan marketing di sana hanya menambah satu klik sebelum
| pekerjaan yang benar-benar mau dia lakukan.
|
| "/" dicek terhadap daftar lokasi di database pusat. Kalau host cocok dengan
| salah satu lokasi, request dialihkan ke login SPA. One indexed statement
| per request -- jauh lebih murah daripada satu klik yang salah.
|
| ResolveTenant TIDAK ikut di sini: middleware itu milik grup `api` dan
| sengaja tidak menempel di `web` supaya query registry tidak jalan untuk
| aset statis maupun health check.
|
*/
Route::get('/', function (Request $request) {
    $tenant = app(TenantResolver::class)->resolve($request);

    if ($tenant) {
        // SPA yang memutuskan sisanya: router Vue membuka /login atau
        // langsung ke dashboard sesuai token yang sudah tersimpan.
        //
        // `frontend_url` hanya wajib di development, saat Vite dev server
        // hidup di port berbeda. Di produksi SPA dilayani dari host yang
        // sama, jadi path relatif benar dan tidak perlu URL absolut.
        $spa = config('tenancy.frontend_url');

        return $spa
            ? redirect(rtrim($spa, '/').'/login')
            : redirect('/login');
    }

    return view('landing');
})->name('landing');
