<?php

use App\Http\Middleware\BladeLiveReload;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnforceInvoiceStatus;
use App\Http\Middleware\EnforceMenuAccess;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        // Route panel pusat (ASTA BRATA) & tagihan langganan.
        // Dipisah karena domainnya berbeda: bekerja pada database pusat,
        // bukan data operasional lokasi.
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/central.php'));
        },
    )
    /*
     | Pendaftaran command artisan.
     |
     | Tanpa baris ini, folder `app/Console/Commands` TIDAK dipindai dan
     | setiap command yang tinggal di sana -- termasuk seluruh perintah
     | `tenancy:*` -- tidak akan muncul di `php artisan list`. Gejalanya
     | tidak jelas: class-nya ada, file-nya ada, tapi perintahnya "tidak
     | dikenal", dan mudah disalahartikan sebagai salah ketik nama.
     |
     | Argumen kosong berarti "pindai seluruh folder", yang memang yang
     | dikehendaki. Daftar per command TIDAK ditulis manual supaya class
     | baru ikut terdaftar begitu file-nya dibuat.
     */
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(HandleCors::class);

        /*
         * Request ke `api/*` tanpa token dibalas 401, tidak pernah redirect.
         *
         * Gejalanya kalau ini tidak diatur: satu request tanpa token -- curl
         * tanpa header `Authorization`, tab yang sessinya sudah kedaluwarsa --
         * membalas 500 dengan `Route [login] not defined` di log.
         * Penyebabnya `Authenticate::redirectTo()` bawaan yang memanggil
         * `route('login')`, dan route itu memang tidak pernah ada di aplikasi
         * ini: login adalah halaman Vue Router (`/login`), bukan route
         * Laravel.
         *
         * Kenapa 500 berbahaya di sini: axios di frontend membaca 401 sebagai
         * "sesi habis" lalu mengirim user ke halaman login, tapi 500 dibaca
         * sebagai "server error" -- spinner berhenti, tidak ada navigasi, dan
         * user hanya melihat layar yang tidak bereaksi.
         *
         * `null` membuat middleware membalas 401 dengan bentuk JSON, bukan
         * redirect. Path di luar `api/*` tetap dialihkan ke `/` supaya
         * perilaku bawaan tidak berubah di tempat lain.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        // Aktifkan throttle bawaan untuk seluruh grup `api`. Tanpa ini
        // tidak ada limiter yang berlaku pada route di routes/api.php.
        $middleware->throttleApi();

        /*
         * ResolveTenant dipasang di SELURUH grup `api`, bukan hanya per-route
         * lewat alias. Alasannya Sanctum: setiap route yang memakai
         * `auth:sanctum` mencari bearer token lewat `database.default`, dan
         * token lokasi tersimpan di database LOKASI -- bukan di database
         * pusat. Tanpa middleware ini di group `api`, semua route yang
         * sekarang belum menyebut alias `tenant` akan membaca token dari
         * database yang salah dan selalu membalas 401.
         *
         * Group `web` sengaja TIDAK diberi: landing page dan halaman statis
         * tidak butuh database lokasi, dan menambahkan query ke sana hanya
         * memperlambat halaman yang harus tetap cepat.
         */
        $middleware->api(prepend: [ResolveTenant::class]);

        /*
         * EnforceInvoiceStatus dipasang di grup `api`, bukan ditempelkan ke
         * tiap route lewat alias `invoice`.
         *
         * Dua alasannya, dan keduanya penting:
         *
         *  1. routes/api.php punya 8 grup middleware terpisah. Menempelkan
         *     alias ke tiap grup berarti mudah ada satu yang lupa, dan grup
         *     yang lupa itu justru yang jadi jalan pembuka.
         *
         *  2. Middleware yang hanya ada di priority list TIDAK PERNAH
         *     dijalankan. Priority list hanya mengurutkan middleware yang
         *     memang sudah menempel di route atau grup -- ia tidak
         *     menambahkannya. Karena itu daftarnya di `appendToPriorityList`
         *     saja tidak cukup; middleware HARUS juga ada di grup.
         *
         * Urutannya tetap benar: priority list menaruhnya SESUDAH auth,
         * karena middleware ini perlu `$request->user()` untuk mencabut token.
         */
        $middleware->api(append: [EnforceInvoiceStatus::class]);

        /*
         | EnforceMenuAccess menutup endpoint yang tidak diizinkan jabatan.
         |
         | DITEMPEL di grup `api` (bukan alias per-route) dengan alasan yang
         | sama seperti `EnforceInvoiceStatus` di atas: routes/api.php punya
         | banyak grup middleware terpisah, dan menempelkan alias ke tiap
         | grup berarti mudah ada satu yang lupa -- dan grup yang lupa itu
         | yang jadi jalan pembuka.
         |
         * priority list TIDAK cukup sendiri: middleware yang hanya ada di
         * sana tidak pernah dijalankan, karena priority list hanya mengurutkan
         | middleware yang memang sudah menempel. Harus juga ada di grup.
         |
         * Harus SETELAH auth, karena butuh `$request->user()` untuk tahu
         | jabatan pemanggil.
         */
        $middleware->api(append: [EnforceMenuAccess::class]);

        /*
         * Live reload Blade -- development only.
         *
         * Ditempel di grup `web`, bukan per-route, karena halaman yang perlu
         * dimuat ulang bisa muncul di mana saja. Middleware ini sendiri yang
         * memutuskan tidak aktif di luar `environment('local')`, jadi satu
         * pendaftaran di sini cukup tanpa perlu remembering menempelkan atau
         * melupakannya per halaman.
         *
         * Letakkan di `prepend` supaya ia berjalan SEBELUM middleware lain:
         * ia hanya memodifikasi response di akhir, jadi tidak boleh
         * digagalkan oleh middleware yang menolak request lebih dulu.
         */
        $middleware->web(prepend: [BladeLiveReload::class]);

        $middleware->alias([
            'role' => CheckRole::class,
            'tenant' => ResolveTenant::class,
            'superadmin' => EnsureSuperadmin::class,
            'invoice' => EnforceInvoiceStatus::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Prioritas Middleware
        |----------------------------------------------------------------------
        |
        | ResolveTenant wajib berjalan SEBELUM `auth:sanctum`.
        |
        | Sanctum mencari bearer token lewat `$model::findToken()`, dan model
        | `PersonalAccessToken` memakai `database.default`. Kalau auth jalan
        | lebih dulu, token dibaca dari database yang salah: token pusat tidak
        | akan pernah ditemukan (selalu 401), dan token lokasi bisa terbaca
        | dari database lokasi lain.
        |
        | Alias saja tidak menjamin urutan -- hanya priority list yang menyusunnya.
        |
        | Targetnya `AuthenticatesRequests`, bukan `Authenticate`. Yang
        | belakangan TIDAK ada di priority list bawaan Laravel, jadi
        | `prependToPriorityList` menolaknya secara diam-diam dan
        | ResolveTenant jatuh ke posisi TERAKHIR -- sesudah auth. Gejalanya
        | token superadmin selalu 401 walau login-nya sukses.
        |
        | `AuthenticatesRequests` adalah interface yang diimplementasikan
        | `Illuminate\Auth\Middleware\Authenticate`, jadi menaruh ResolveTenant
        | di Ahead dari interface itu akan menarik middleware auth apa pun --
        | termasuk Sanctum -- ke belakang ResolveTenant.
        |
        | `prependToPriorityList` dipakai, bukan `priority()`, karena yang
        | kedua MENGGANTI daftar bawaan Laravel seluruhnya -- termasuk
        | ThrottleRequests dan Authorize. Mengganti daftar itu diam-diam
        | mematikan rate limiter yang sedang aktif di grup `api`.
        |
        */
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ResolveTenant::class,
        );

        /*
         * EnforceInvoiceStatus ditambatkan SETELAH auth, bukan ditempelkan
         * per-route lewat alias `invoice`.
         *
         * Alias per-route dilewati karena routes/api.php punya 8 grup middleware
         * yang terpisah. Menempelkan alias ke tiap grup berarti mudah ada
         * satu grup yang lupa, dan grup yang lupa itu justru yang jadi
         * jalan pembuka. Memasangnya ke priority list berlaku untuk seluruh
         * grup `api` sekaligus.
         *
         * Harus setelah auth karena middleware ini mencabut token user yang
         * menunggak -- butuh `$request->user()` yang sudah ter-resolve.
         */
        $middleware->appendToPriorityList(
            AuthenticatesRequests::class,
            EnforceInvoiceStatus::class,
        );

        /*
         * EnforceMenuAccess juga butuh `$request->user()`, jadi harus
         * SETELAH auth.
         *
         * Urutannya terhadap `EnforceInvoiceStatus` tidak berpengaruh ke
         * hasil yang dilihat user: keduanya menolak request, dan yang
         * "__mana__" yang menolak lebih dulu tidak mengubah apa pun --
         * tagihan terlambat dan menu terlarang adalah dua hal yang
         * sama-sama membuat halaman tidak terbuka.
         */
        $middleware->appendToPriorityList(
            AuthenticatesRequests::class,
            EnforceMenuAccess::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
