<?php

use App\Http\Controllers\Central\AuditLogController;
use App\Http\Controllers\Central\CentralAuthController;
use App\Http\Controllers\Central\InvoiceController;
use App\Http\Controllers\Central\LocationController;
use App\Http\Controllers\Central\PublicLocationController;
use App\Http\Controllers\MyInvoiceController;
use App\Models\Central\AuditLog;
use App\Models\Central\Tenant;
use App\Models\Central\TenantInvoice;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenancy & Panel Pusat (ASTA BRATA)
|--------------------------------------------------------------------------
|
| Dipisah ke file sendiri karena domainnya berbeda: ini bekerja pada
| DATABASE PUSAT (registry lokasi, user superadmin, invoice langganan), bukan
| pada data operasional lokasi. Middleware `superadmin` mengembalikan koneksi
| default ke database pusat dan membatasi akses hanya untuk role superadmin.
|
| Route `tenant` di sini menandai endpoint yang HANYA untuk domain pusat.
| Di subdomain lokasi, route yang sama tidak boleh aktif -- itu sebabnya
| middleware superadmin menolak role non-superadmin, dan halaman depan
| (web.php) yang mengarahkan subdomain ke login langsung.
|
*/

// --- Login superadmin -----------------------------------------------------
Route::prefix('central')->group(function () {
    Route::post('/login', [CentralAuthController::class, 'login'])
        ->middleware(['throttle:login', 'throttle:login-ip']);

    /*
     | Daftar lokasi untuk dropdown di landing page.
     |
     | Publik, tapi hanya mengembalikan nama + slug. Endpoint di dalam grup
     | `auth:sanctum` di bawah tetap holds data lengkap (email, alamat,
     | tagihan) -- jangan menggabungkan keduanya hanya agar landing page
     | lebih sederhana.
     |
     | Throttle-nya longgar (30/menit) karena setiap pengunjung halaman
     | depan memanggil ini sekali, tapi tidak dari peramban bot yang
     | memuat ulang terus.
     */
    Route::get('/lokasi', [PublicLocationController::class, 'index'])
        ->middleware('throttle:30,1');

    Route::middleware(['auth:sanctum', 'superadmin'])->group(function () {
        Route::post('/logout', [CentralAuthController::class, 'logout']);

        // Registry lokasi
        Route::apiResource('locations', LocationController::class);

        // Tagihan langganan
        Route::apiResource('invoices', InvoiceController::class)->except(['update', 'destroy']);
        Route::post('invoices/{invoice}/paid', [InvoiceController::class, 'markPaid']);
        Route::post('invoices/{invoice}/unpaid', [InvoiceController::class, 'markUnpaid']);

        // Ringkasan untuk dashboard pusat
        Route::get('overview', function () {
            $lokasi = Tenant::count();
            $aktif = Tenant::where('is_active', true)->count();
            $belumLunas = TenantInvoice::where('status', 'UNPAID')->count();
            $nilaiTunggak = (float) TenantInvoice::where('status', 'UNPAID')->sum('jumlah');

            $terlambat = TenantInvoice::query()
                ->where('status', 'UNPAID')
                ->get()
                ->filter(fn ($i) => $i->isOverdue())
                ->count();

            $auditHariIni = AuditLog::whereDate('created_at', today())->count();
            $auditGagal = AuditLog::whereDate('created_at', today())
                ->where('action', 'like', '%failed')
                ->count();

            return response()->json([
                'success' => true,
                'data' => compact(
                    'lokasi', 'aktif', 'belumLunas', 'nilaiTunggak', 'terlambat',
                    'auditHariIni', 'auditGagal'
                ),
            ]);
        });

        /*
         | Jejak audit.
         |
         | Hanya GET. Tidak ada endpoint create/update/delete -- tabelnya
         | append-only dan tidak punya soft delete. Menulis audit dari
         | endpoint yang bisa dipanggil siapa pun akan membuat log bisa
         | dipalsukan, dan log yang bisa dipalsukan sama sekali tidak
         | berguna saat investigasi.
         |
         | `audits/{id}` membuka satu baris lengkap termasuk `properties`.
         | Endpoint indeks sengaja tidak mengirim payload itu: isinya bisa
         | besar (diff Field sizable), dan halaman daftar hanya butuh
         | kolom ringkas.
         */
        Route::get('audits', [AuditLogController::class, 'index']);
        Route::get('audits/{audit}', [AuditLogController::class, 'show']);
        Route::get('audit-actions', [AuditLogController::class, 'actions']);
    });
});

/*
|--------------------------------------------------------------------------
| Tagihan Langganan -- Pandangan User Lokasi
|--------------------------------------------------------------------------
|
| Route ini dipakai user lokasi, bukan superadmin, dan TIDAK butuh tenant
| context di URL: lokasi ditentukan dari subdomain oleh ResolveTenant.
|
| Harus masuk daftar `tenancy.invoice_exempt_paths` supaya tetap bisa diakses
| ketika tagihan terlambat.
|
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('tagihan-saya', [MyInvoiceController::class, 'index']);
    Route::get('tagihan-saya/summary', [MyInvoiceController::class, 'summary']);
});
