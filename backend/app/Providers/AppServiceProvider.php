<?php

namespace App\Providers;

use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\MonthlyBill;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\PaymentObserver;
use App\Observers\TransactionObserver;
use App\Services\TenantManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerTenantManager();
    }

    /**
     * `TenantManager` WAJIB singleton, bukan `new` per pemakaian.
     *
     * Instance menyimpan satu hal yang tidak ada duplikatnya di tempat
     * lain: `$current`, yaitu lokasi yang sedang aktif untuk request ini.
     * Middleware `ResolveTenant` memanggil `activate()` pada instance yang
     * dia dapat dari container; kalau controller memanggil
     * `app(TenantManager::class)->current()`, dia harus dapat instance yang
     * SAMA.
     *
     * Tanpa baris di bawah, container membuat instance baru setiap kali
     * `TenantManager` di-resolve. Semua yang masih bekerja -- karena
     * `database.default` memang diubah secara global di `Config`, bukan di
     * objek itu sendiri -- tapi `current()` selalu mengembalikan null, dan
     * audit kehilangan `tenant_id` untuk semua aksi di aplikasi lokasi.
     * Gejalanya berupa data yang hilang tanpa error, bukan exception.
     *
     * Dibuat per-request (`scoped`), bukan seumur aplikasi: pada Octane /
     * queue worker, instance yang bertahan lintas request akan membawa
     * lokasi aktif dari request sebelumnya ke request berikutnya -- user
     * satu bisa diam-diam membaca database milik user lain.
     */
    private function registerTenantManager(): void
    {
        $this->app->scoped(TenantManager::class);
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
        Relation::enforceMorphMap([
            'payment' => Payment::class,
            'monthly_bill' => MonthlyBill::class,
            'customer' => Customer::class,
            'user' => User::class,
            'bill_payment' => BillPayment::class,
        ]);

        Transaction::observe(TransactionObserver::class);
        Payment::observe(PaymentObserver::class);
    }

    /**
     * Catatan penting soal migrasi database PUSAT.
     *
     * Folder `database/migrations/central/` SENGAJA TIDAK didaftarkan lewat
     * `loadMigrationsFrom()`, dan itu bukan kelalaian.
     *
     * `migrate` menulis catatan ke tabel `migrations` pada koneksi yang SEDANG
     * AKTIF, bukan ke koneksi yang dipakai di dalam migrasi. Jadi begitu folder
     * pusat ikut dipindai oleh `php artisan migrate` biasa:
     *
     *   1. Tabel `tenants`, `users` pusat, dan `audit_logs` tetap dibuat dengan
     *      benar, karena tiap migrasi memanggil `Schema::connection('central')`
     *      secara eksplisit.
     *   2. Tapi baris catatannya masuk ke tabel `migrations` milik LOKASI,
     *      karena `database.default` saat itu `mysql` (= `astaloka_lokasi`).
     *
     * Akibatnya setiap database lokasi baru mewarisi catatan "sudah jalan"
     * untuk migrasi yang tidak pernah jalan di sana -- dan provisioning
     * berikutnya tidak akan pernah menjalankannya, karena yang dibaca
     * `TenantManager::migrateTenantDatabase()` adalah `migrate` pada koneksi
     * lokasi.
     *
     * Karena itu migrasi pusat punya perintahnya sendiri:
     * `tenancy:migrate-central`, yang menjalankan
     * `migrate --database=central --path=.../central` sebagai satu langkah.
     * `--database` itu yang membuat catatan migrasi ikut masuk ke database
     * yang benar, bukan ke `database.default`.
     *
     * `@see \App\Console\Commands\MigrateCentral`
     */

    /**
     * Batasi percobaan brute-force pada endpoint autentikasi.
     *
     * Tanpa limiter ini, POST /login terbuka penuh: penyerang bisa
     * mencoba kombinasi email/password tanpa batas. Rate limit dihitung
     * per kombinasi email+IP supaya satu penyerang tidak bisa mengunci
     * akun orang lain dengan sengaja (keyBy email saja = DoS akun).
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by(
                $email.'|'.$request->ip()
            );
        });

        // Lapisan kedua per-IP, untuk menangkap penyerang yang memakai
        // banyak email berbeda dari satu mesin.
        RateLimiter::for('login-ip', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Endpoint berat yang menyimpan file, supaya tidak bisa dipakai
        // memenuhi disk atau membebani server dengan request beruntun.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Dipakai oleh throttleApi() di bootstrap/app.php, yang memasang
        // middleware `throttle:api` ke seluruh grup api. Tanpa daftar ini
        // setiap request yang tidak bisa dilayani limiter akan 500 dengan
        // MissingRateLimiterException, bukan 429.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }
}
