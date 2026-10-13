<?php

use Database\Seeders\AccountsTableSeeder;
use Database\Seeders\AkunLevel1Seeder;
use Database\Seeders\AkunLevel2Seeder;
use Database\Seeders\AkunLevel3Seeder;
use Database\Seeders\JabatanSeeder;
use Database\Seeders\JenisTransactionsSeeder;
use Database\Seeders\MasterArusKasSeeder;

return [

    /*
    |--------------------------------------------------------------------------
    | Central Database
    |--------------------------------------------------------------------------
    |
    | Database "pusat" milik ASTA BRATA. Berisi data yang TIDAK per lokasi:
    | registry tenant, user superadmin, dan invoice langganan.
    |
    | Database setiap lokasi (tenant) terpisah secara fisik -- masing-masing
    | punya nama database sendiri. Keduanya satu server MySQL, bedanya hanya
    | pada nama database.
    |
    */

    'central_connection' => env('TENANCY_CENTRAL_CONNECTION', 'central'),

    'central_database' => env('TENANCY_CENTRAL_DATABASE', 'astaloka_pusat'),

    /*
    |--------------------------------------------------------------------------
    | Prefiks Database Tenant
    |--------------------------------------------------------------------------
    |
    | Pola nama database per lokasi: <prefix><slug>
    | Contoh: astaloka_tirto_mulo
    |
    | Panjang MySQL untuk nama database maksimal 64 karakter. Prefix +
    | slug lebih dari itu akan ditolak MySQL saat CREATE DATABASE, jadi
    | slug dipotong oleh TenantManager sebelum dipakai.
    |
    */

    'database_prefix' => env('TENANCY_DATABASE_PREFIX', 'astaloka_lokasi_'),

    'max_database_name_length' => 64,

    /*
    |--------------------------------------------------------------------------
    | Reserved Slugs
    |--------------------------------------------------------------------------
    |
    | Slug yang tidak boleh dipakai tenant karena berpotensi bentrok dengan
    | nama database milik sistem (mis. `central`, `mysql`, `information_schema`).
    |
    */

    'reserved_slugs' => [
        'central',
        'pusat',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
        'test',
        'laravel',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Lookup
    |--------------------------------------------------------------------------
    |
    | Cara frontend mengirim identitas tenant saat login.
    |
    |   'host'  -> tenant dicari dari subdomain / host request.
    |               Contoh: tirtomulo.astaloka.test  ->  slug `tirtomulo`
    |               Cocok untuk produksi di belakang reverse proxy.
    |
    |   'slug'  -> frontend mengirim slug secara eksplisit di header `X-Tenant-Slug`
    |               atau body/param login. Dicukupkan untuk development lokal,
    |               di mana beberapa lokasi diakses lewat `localhost` sehingga
    |               host tidak bisa membedakan lokasi.
    |
    | Header slug sudah tidak dipercaya di produksi, tanpa
    | bergantung nilai ini -- lihat TenantResolver::slugAllowed(). Nilai ini
    | hanya memilih jalur development.
    |
    | Host tetap dicoba lebih dulu di kedua mode, jadi subdomain lokasi tidak
    | pernah ikut dilayani halaman depan saat `slug` masih aktif.
    |
    */

    'lookup' => env('TENANCY_LOOKUP', 'host'),

    'slug_header' => 'X-Tenant-Slug',

    /*
    |--------------------------------------------------------------------------
    | URL Frontend (SPA)
    |--------------------------------------------------------------------------
    |
    | Aplikasi lokasi bukan Blade Laravel, tapi SPA Vue (Vite). Jadi saat
    | subdomain lokasi diarahkan ke login, tujuannya adalah server SPA --
    | bukan route Laravel.
    |
    | Dev : Vite dev server, biasanya http://astaloka.test:5173
    | Prod: hasil `vite build` diserve dari host yang sama dengan backend
    |
    | Kalau dikosongkan, `/` di subdomain lokasi diarahkan ke path relatif
    | `/login`, yang benar HANYA kalau SPA dilayani dari host yang sama --
    | yaitu produksi. Di development, tanpa nilai ini pengunjung mendarat
    | di 404 Laravel.
    |
    */
    'frontend_url' => env('TENANCY_FRONTEND_URL'),

    /*
    |--------------------------------------------------------------------------
    | Invoice Langganan
    |--------------------------------------------------------------------------
    |
    | Balaan dari tenant yang invoice langganannya sudah lewat jatuh tempo.
    | Meniru perilaku SIDBM: toleransi 1 bulan sejak tanggal invoice, atau
    | sampai `tgl_lunas` bila diisi dan lebih besar dari batas toleransi.
    |
    */

    'invoice_grace_months' => (int) env('TENANCY_INVOICE_GRACE_MONTHS', 1),

    /*
    | Route yang tetap boleh diakses meski invoice belum dibayar.
    |
    | Tanpa daftar ini user terkunci total tanpa jalan keluar. Yang TERLAUT
    | penting `api/logout`: kalau logout ikut diblokir, satu-satunya cara
    | keluar dari aplikasi hilang, dan user yang tagihannya terlambat bisa
    | mencoba pounded memakai akunnya sendiri -- persis yang harus dicegah.
    |
    | `api/refresh` dan `api/me` ikut dikecualikan karena frontend memanggilnya
    | saat aplikasi dibuka; memblokirnya akan membuat user yang telat bayar
    | bahkan tidak bisa melihat namanya sendiri untuk menemukan jalan keluar.
    |
    */

    // Prefix path mengikuti grup `api` Laravel, yang di repo ini TIDAK memakai
    // `/v1` (lihat bootstrap/app.php). Kalau prefix di sini salah, user yang
    // tagihannya terlambat tidak akan bisa logout -- middleware memblokir
    // sebelum controller sempat jalan.
    'invoice_exempt_paths' => [
        'api/tagihan-saya',
        'api/tagihan-saya/*',
        'api/login',
        'api/logout',
        'api/refresh',
        'api/me',
        'api/me/*',
        'api/health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provisioning
    |--------------------------------------------------------------------------
    |
    | Apa yang dijalankan di database lokasi yang baru dibuat. Migration dan
    | seeder master WAJIB keduanya: database tanpa skema tidak bisa dipakai,
    | dan database berskema tapi tanpa master data membuat user langsung
    | menabrak halaman kosong (jurnal tanpa chart of accounts, form jabatan
    | tanpa isi).
    |
    | Daftar seeder TIDAK boleh memuat data contoh -- pelanggan, meterreading,
    | struk, transaksi. Kalau peerlu contoh, paketkan sebagai seeder terpisah
    | dan jalankan manual. Lokasi produksi yang terisi data contoh adalah
    | sumber kebingungan saat migrasi.
    |
    | Semua seeder di sini wajib idempoten (truncate lalu insert, atau
    | updateOrCreate) karena dipakai lagi oleh perintah
    | `tenancy:reseed-master`, bukan hanya saat lokasi baru dibuat.
    |
    | URUTAN PENTING. `AkunLevel1Seeder` menyediakan parent (id 1-5) yang
    | dirujuk `AkunLevel2Seeder`; level 2 dirujuk level 3; `AkunLevel3Seeder`
    | menyediakan `accounts.id` yang dirujuk `AccountsTableSeeder` lewat
    | `parent_id`. Seedernya dipanggil berurutan, bukan acak.
    |
    */

    'provision' => [
        'run_migrations' => true,
        'run_seeders' => true,

        // Hanya master data. Tanpa data operasional.
        'seeders' => [
            JabatanSeeder::class,
            AkunLevel1Seeder::class,
            AkunLevel2Seeder::class,
            AkunLevel3Seeder::class,
            AccountsTableSeeder::class,
            JenisTransactionsSeeder::class,
            MasterArusKasSeeder::class,
        ],
    ],

    /*
    | Session key tempat tenant aktif disimpan selama request.
    |
    */

    'session_key' => 'tenant_slug',

];
