<?php

use App\Http\Middleware\BladeLiveReload;
use App\Support\StatistikPengguna;
use App\Services\BladeWatch;
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

        if (! $spa) {
            return redirect('/login');
        }

        /*
         * `frontend_url_template`, kalau diisi, menggantikan `frontend_url`
         * dengan host yang sedang diakses.
         *
         * Kenapa ini harus ada, dan kenapa pakai template alih-alih selalu
         * menebak:
         *
         * Nilai polos `http://localhost:5173` membuang subdomain yang dipakai
         * petugas. `demo.astaloka.test` dilempar ke `localhost:5173`, dan
         * begitu request menyentuh backend, `TenantResolver::fromHost()`
         * tidak punya apa-apa untuk dicocokkan -- tidak ada subdomain, tidak
         * ada domain persis. Identitas lokasi hilang di titik ini, dan
         * gejalanya muncul jauh dari penyebabnya: branding kembali ke bawaan
         * padahal teks dan warnanya tersimpan rapi di database pusat.
         *
         * Tapi menebak "selalu pakai subdomain sekarang" juga salah, dan
         * lebih damaging lagi: begitu port ikut terbawa, `demo.astaloka.test`
         * jadi `http://demo.astaloka.test/login` tanpa port 5173, yaitu
         * backend yang menjawab dengan 404 SPA -- atau, kalau `frontend_url`
         * menunjuk mesin orang (`http://192.168.1.5:5173` untuk dev server
         * bersama), memaksa semua orang masuk lewat subdomain mesin itu.
         *
         * Karena itu pertukaran host harus EKSPLISIT lewat template. Kalau
         * template tidak diisi, perilaku lama dipertahankan dan tidak ada
         * yang berubah diam-diam.
         */
        $template = config('tenancy.frontend_url_template');

        if ($template) {
            return redirect(str_replace('{host}', $request->getHost(), $template).'/login');
        }

        return redirect(rtrim($spa, '/').'/login');
    }

    /*
     * Angka untuk bagian "sudah dipakai siapa" di halaman depan.
     *
     * Dihitung di route, bukan di dalam Blade, supaya halaman yang tidak
     * memakai angka ini tidak pernah membayarkannya, dan supaya test bisa
     * mengunci angkanya tanpa harus merender 250 baris HTML.
     *
     * `@see StatistikPengguna` untuk kenapa yang dikembalikan hanya jumlah,
     * tanpa nama lokasi maupun domain.
     */
    return view('landing', [
        'pengguna' => StatistikPengguna::hitung(),
    ]);
})->name('landing');

/*
|--------------------------------------------------------------------------
| Live reload Blade (development only)
|--------------------------------------------------------------------------
|
| Menjawab satu pertanyaan saja: "apakah ada file Blade yang lebih baru dari
| waktu halaman ini dirender?".
|
| Endpoint-nya di luar grup `api` dan DI DALAM `web` supaya tetap tanpa
| auth -- halaman depan memang tanpa auth, dan live reload tidak boleh
| mengubah itu.
|
| Yang membuat ini aman di produksi bukan middleware yang menyembunyikan,
| tapi pemeriksaan environment di sini: kalau `app()->environment('local')`
| false, route ini dibalas 404. Bukan 403, bukan response kosong -- keduanya
| masih mengonfirmasi bahwa ada sesuatu di alamat itu. 404 indistinguishable
| dari route yang memang tidak pernah ada.
|
| Response-nya sengaja sangat kecil. Satu integer dan satu boolean; nama file
| tidak pernah keluar dari server.
|
*/
Route::get(BladeLiveReload::watchPath(), function (Request $request) {
    // PENTING: dicek SEBELUM apa pun diproses. Di produksi, endpoint ini
    // harus sama sekali tidak ada.
    if (! app()->environment('local') || app()->runningUnitTests()) {
        abort(404);
    }

    $since = $request->query('since');
    $since = is_numeric($since) ? (int) $since : null;

    return response()->json([
        'changed' => app(BladeWatch::class)->changedSince($since),
        'latest' => app(BladeWatch::class)->latestMtime(),
    ])->header('Cache-Control', 'no-store');
})->name('blade-watch');
