<?php

use Database\Seeders\AccountsTableSeeder;
use Database\Seeders\AkunLevel1Seeder;
use Database\Seeders\AkunLevel2Seeder;
use Database\Seeders\AkunLevel3Seeder;
use Database\Seeders\CentralAccountSeeder;
use Database\Seeders\JabatanSeeder;
use Database\Seeders\JenisTransactionsSeeder;
use Database\Seeders\MasterArusKasSeeder;

return [

    /*
    |--------------------------------------------------------------------------
    | Central Database
    |--------------------------------------------------------------------------
    |
    | Database pusat adalah `astaloka` itu sendiri. Berisi data yang TIDAK
    | per lokasi: registry tenant, user superadmin, dan invoice langganan.
    |
    | Database setiap lokasi (tenant) terpisah secara fisik -- masing-masing
    | punya nama database sendiri, dengan awalan `astaloka_` lalu nama
    | singkat lokasi, dipisah underscore. Keduanya satu server MySQL,
    | bedanya hanya pada nama database.
    |
    |   astaloka              -> pusat (nama polos, tanpa underscore di
    |                            belakang karena tidak ada tenantnya)
    |   astaloka_lokasi       -> tenant `lokasi`
    |   astaloka_pamsimas     -> tenant `pamsimas`
    |
    | Yang membedakan keduanya bukan hanya nama database, tapi juga FOLDER
    | migrasinya -- lihat bagian `migrations_path` di bawah.
    |
    */

    'central_connection' => env('TENANCY_CENTRAL_CONNECTION', 'central'),

    'central_database' => env('TENANCY_CENTRAL_DATABASE', 'astaloka'),

    /*
    |--------------------------------------------------------------------------
    | Folder Migrasi
    |--------------------------------------------------------------------------
    |
    | Dua skema, dua folder, dan TIDAK ADA yang berbagi.
    |
    |   tenant  -> database/migrations_tenant/  (skema LOKASI)
    |   central -> database/migrations_central/ (skema PUSAT)
    |
    | Keduanya berada DI LUAR `database/migrations/`, dan itu adalah inti
    | dari aturan ini. Migrator bawaan Laravel memindai
    | `database/migrations/` sebagai default -- ia tidak tahu menebak mana
    | yang pusat dan mana yang lokasi. Begitu folder pusat ikut berada di
    | dalam jangkauan default, `php artisan migrate` biasa bisa menyalin
    | skema LOKASI ke database PUSAT tanpa satu pun error, dan hasilnya
    | database pusat yang berisi `customers`, `monthly_bills`, dan trigger
    | `amount`, sementara `tenants` / `audit_logs` tidak pernah ada.
    |
    | Itu bukan risiko teoretis: kedua arah kesalahan sudah pernah terjadi
    | di repo ini. Menjadikan folder luar sebagai satu-satunya lokasi
    | membuat `php artisan migrate` tanpa `--path` tidak lagi memiliki
    | satu pun file untuk dijalankan -- jadi harus selalu menyebut jalannya.
    | Dan `GuardCentralDatabaseWrites` menolak sisa jalan yang salah.
    |
    | Path disimpan RELATIF dengan pemisah `/`, bukan lewat `database_path()`.
    | `database_path()` mengembalikan backslash di Windows
    | (`C:\...\migrations_central`), dan Migrator mencocokkannya dengan
    | `glob()` ber-pemisah `/` -- tidak satu pun yang cocok, sehingga
    | semua migrasi dianggap tidak ada dan perintah keluar dengan status
    | SUKSES sambil mencetak "Nothing to migrate" padahal tabelnya belum
    | pernah dibuat. Gejalanya jauh dari penyebabnya, jadi yang dipakai di
    | sini adalah bentuk yang selalu sama dengan yang diberikan Migrator.
    |
    */

    'migrations_path' => 'database/migrations_tenant',

    'central_migrations_path' => 'database/migrations_central',

    /*
    |--------------------------------------------------------------------------
    | Prefiks Database Tenant
    |--------------------------------------------------------------------------
    |
    | Pola nama database per lokasi: `astaloka_` + <slug>
    | Contoh: slug `lokasi` -> `astaloka_lokasi`
    |         slug `pamsimas` -> `astaloka_pamsimas`
    |
    | Prefix memakai satu underscore, bukan dua. `astaloka_lokasi` dibaca
    | sebagai "database ASTALOKA milik lokasi `lokasi`", bukan "prefix
    | `astaloka_lokasi_` + slug" -- bentuk yang kedua memaksa semua tenant
    | diberi nama generik dan tidak pernah bisa memakai slug-nya sendiri
    | sebagai penanda.
    |
    | Panjang MySQL untuk nama database maksimal 64 karakter. Prefix +
    | slug lebih dari itu akan ditolak MySQL saat CREATE DATABASE, jadi
    | slug dipotong oleh TenantManager sebelum dipakai.
    |
    */

    'database_prefix' => env('TENANCY_DATABASE_PREFIX', 'astaloka_'),

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
    | Domain Panel Pusat
    |--------------------------------------------------------------------------
    |
    | Host yang dipakai panel pusat sendiri, dipisah dari domain lokasi.
    |
    | Domain ini tidak boleh didaftarkan sebagai domain tenant. Kenapa harus
    | dijaga eksplisit: kalau `panel.astaloka.test` tidak sengaja berakhir
    | jadi domain sebuah lokasi, superadmin yang membuka panel pusat akan
    |dilayani database lokasi -- tokennya dibaca di database yang salah dan
    | selalu 401, dengan pesan yang tidak pernah menyinggung soal domain.
    |
    | Sama untuk wildcard: `*.astaloka.test` akan menangkap `panel.astaloka.test`
    | selama `panel` hanya satu label di bawahnya. Itu dicek di
    | `DomainNormalizer::ensureAvailable()`.
    |
    | Kosongkan kalau panel pusat memang tidak punya domain terpisah -- misalnya
    | saat semua akses lewat localhost. `APP_URL` tetap ikut dibaca apa
    | adanya isinya, jadi daftar ini tidak wajib memuat domain yang sama.
    |
    */

    'central_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TENANCY_CENTRAL_HOSTS', '')),
    ))),

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
    | Template URL Frontend
    |--------------------------------------------------------------------------
    |
    | Bentuk dari `frontend_url` yang memakai host yang SEDANG diakses.
    | `{host}` diganti dengan host request apa adanya.
    |
    | Dev : http://{host}:5173
    | Prod: kosongkan -- path relatif sudah benar
    |
    | Kenapa nilai polos `http://localhost:5173` tidak cukup di development:
    |
    | Subdomain LOKASI membawa identitas tenant-nya. `demo.astaloka.test`
    | di-resolve ke tenant `lokasi` oleh `TenantResolver::fromHost()`, dan
    | itu yang membuat `GET /api/branding` membalas teks dan warna milik
    | lokasi itu. Begitu `/` dialihkan ke `localhost:5173`, identitas itu
    | hilang: host yang sampai ke backend tidak punya subdomain, dan
    | resolver tidak punya apa pun untuk dicocokkan.
    |
    | Gejalanya muncul jauh dari penyebabnya. Tidak ada error, tidak ada 500
    | -- hanya branding kembali ke bawaan, padahal panel pusat sudah
    | menyimpan teks dan warnanya dengan benar. Dan karena semua lokasi
    | dibuka lewat `localhost`, semuanya jadi tampil sama persis.
    |
    | Template menutup itu tanpa mengubah perilaku produksi: dikosongkan,
    | `frontend_url` dipakai apa adanya. Dan tidak ada penebakan diam-diam
    | di sini -- kalau ditanya "subdomain mana yang harus dipakai?",
    | jawabannya selalu "yang sedang dibuka", bukan hasil nebak dari
    | `frontend_url`.
    |
    | PENTING: `{host}` diganti host request APA ADANYA, bukan subdomain
    | yang ditebak. Redirect ini hanya berjalan kalau tenant sudah
    | ter-resolve -- artinya host-nya sudah dijamin punya subdomain yang
    | benar. Panel pusat tidak punya subdomain dan memang tidak pernah
    | lewat jalur ini.
    |
    */
    'frontend_url_template' => env('TENANCY_FRONTEND_URL_TEMPLATE'),

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
    |--------------------------------------------------------------------------
    | Kapan tagihan mengunci akses lokasi
    |--------------------------------------------------------------------------
    |
    | `true`  (default) -- setiap invoice berstatus UNPAID memblokir login dan
    |                     dashboard lokasi, sekecil apa pun nominalnya dan
    |                     walau belum lewat jatuh tempo.
    |
    | `false`           -- hanya invoice yang sudah lewat `deadline()` yang
    |                     memblokir. Ini perilaku lama, dan berguna untuk
    |                     lokasi yang tarifnya tahunan: dengan `true`, tagihan
    |                     tahunan akan mengunci akses selama 12 bulan.
    |
    | Yang berlaku di tiga tempat sekaligus -- login, middleware request, dan
    | ringkasan popup -- lewat `App\Services\InvoiceGate`. Jangan menyalin
    | syaratnya ke tempat lain; itu yang membuat ketiga tempat itu lama-lama
    | tidak sinkron.
    |
    */

    'invoice_block_on_unpaid' => env('TENANCY_INVOICE_BLOCK_ON_UNPAID', true) !== false
        && env('TENANCY_INVOICE_BLOCK_ON_UNPAID', true) !== 'false',

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
        // Branding halaman login. Endpoint publik tanpa sesi, jadi tidak ada
        // yang perlu dilindungi dari pemblokiran -- dan teks halaman login
        // justru tidak boleh hilang saat tagihan terlambat, karena di situlah
        // orang membukanya untuk melihat apa yang harus dibayar.
        'api/branding',
        // Login pintasan (`/login/user`). Middleware di sini hanya menahan
        // request yang sudah punya sesi -- endpoint ini justru tidak punya
        // sesi sama sekali, jadi tidak ada yang perlu dilindungi dari
        // pemblokiran. Aturan tagihannya tetap berlaku: `ShortcutLoginController`
        // memanggil `InvoiceGate` sendiri sebelum menerbitkan token.
        'api/login-user',
        'api/login-user/*',
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

        /*
         * Seeder yang menulis ke DATABASE PUSAT.
         *
         * Level 1-3 adalah kerangka chart of accounts yang sama untuk semua
         * lokasi, jadi hanya perlu diisi SATU KALI untuk seluruh sistem --
         * bukan sekali per lokasi. Kalau ikut daftar per lokasi di bawah, tiap
         * lokasi baru akan memicu truncate + insert ke tabel yang sama, dan
         * `tenancy:reseed-master` yang dijalankan untuk semua lokasi akan
         * membersihkannya berulang tanpa ada alasan.
         *
         * Seedernya sendiri sudah dikunci ke koneksi `central`, jadi daftarkan
         * di sini cukup; tidak perlu parameter `--database`.
         *
         * URUTAN PENTING: level 1 -> 2 -> 3. Masing-masing menyediakan
         * `id` yang dirujuk `parent_id` di level berikutnya.
         *
         * `CentralAccountSeeder` di akhir karena TIDAK bergantung pada
         * level 1-3 -- hanya `updateOrInsert` baris rekening penampung
         * pembayaran tagihan. Diletakkan paling akhir supaya tidak
         * pernah ikut menggagalkan chain level 1 -> 2 -> 3, yang
         * kegagalan satu barisnya berarti seluruh chart of accounts
         * kosong di semua lokasi.
         */
        'central_seeders' => [
            AkunLevel1Seeder::class,
            AkunLevel2Seeder::class,
            AkunLevel3Seeder::class,
            CentralAccountSeeder::class,
        ],

        /*
         * Seeder yang menulis ke DATABASE LOKASI -- satu kali per lokasi.
         *
         * Hanya master data. Tanpa data operasional.
         *
         * `AkunLevel3Seeder` TIDAK ada di sini karena level 3 sudah ada di
         * pusat. Yang mengisi level 4 (`accounts`) tetap `AccountsTableSeeder`,
         * dan `parent_id`-nya menunjuk `akun_level_3.id` di pusat -- nilainya
         * sudah pasti sama untuk semua lokasi, jadi aman.
         *
         * URUTAN PENTING. `AccountsTableSeeder` menyediakan `accounts.id` yang
         * dipakai `MasterArusKasSeeder` lewat kolom `debit`/`kredit` (yang
         * menyimpan `kode_akun` level 3), dan `JenisTransactionsSeeder` memakai
         * `accounts` juga.
         */
        'seeders' => [
            JabatanSeeder::class,
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

    /*
    |--------------------------------------------------------------------------
    | User Demo per Lokasi
    |--------------------------------------------------------------------------
    |
    | Empat akun contoh yang dibuat otomatis di SETIAP lokasi baru:
    |
    |   admin@gmail.com     role admin
    |   pelanggan@gmail.com role pelanggan
    |   surveyor@gmail.com  role surveyor
    |   teknisi@gmail.com   role teknisi
    |
    | Semuanya memakai password `password`.
    |
    | TUJUAN
    | ------
    | Supaya setiap lokasi yang baru didaftarkan langsung punya satu user per
    | role yang bisa dicoba -- tanpa perlu admin lokasi dibuat manual dulu,
    | dan tanpa perlu operator menghafal empat email berbeda.
    |
    | Passwordnya memang lemah, dan itu disengaja: `password` sudah dipakai
    | `UserSeeder` dan sudah tercatat di `shortcut_login.demo_passwords`, jadi
    | halaman login pintasan `/login/user` menampilkannya apa adanya. Inilah
    | yang membuat akun ini berguna untuk demo -- dan inilah alasan sakelar
    | di bawahnya ada.
    |
    | CATATAN SOAL KONFLIK DENGAN DAFTAR SEEDER PROVISIONING
    | ---------------------------------------------------
    | `tenancy.provision.seeders` di atas sengaja TIDAK memuat
    | `DemoUserSeeder`. Daftar itu wajib idempoten karena `tenancy:reseed-master`
    | menjalankannya untuk semua lokasi -- dan kalau akun demo ikut di sana,
    | `tenancy:reseed-master` akan MENGEMBALIKAN password setiap akun
    | demo ke `password` pada setiap lokasi produksi yang sudah mengganti
    | password-nya sendiri. Akun demo karena itu terpisah: ikut config
    | `demo_users.enabled` di sini, dan hanya ikut jalan kalau sakelar itu
    | menyala.
    |
    | MATIKAN DI PRODUKSI
    | -------------------
    | Set `TENANCY_DEMO_USERS=false`. Lokasi yang TELAH terlanjur punya
    | akun-akun ini tidak ikut terhapus; sakelar ini hanya berlaku untuk
    | lokasi yang dibuat sesudah dimatikan.
    |
    | Dua hal yang perlu diketahui sebelum mengganti bawaannya:
    |
    |  1. Email TIDAK boleh diubah lewat config. Emailnya ikut jadi kunci
    |     idempotensi di `DemoUserSeeder`, DAN email itu juga yang diketik
    |     orang saat login. Mengubahnya membuat satu lokasi punya akun
    |     contoh dengan nama berbeda setiap kali provisioning diulang.
    |
    |  2. Password bisa diganti lewat `TENANCY_DEMO_USERS_PASSWORD`.
    |
    */

    'demo_users' => [
        'enabled' => env('TENANCY_DEMO_USERS', true) !== false
            && env('TENANCY_DEMO_USERS', true) !== 'false',

        'password' => env('TENANCY_DEMO_USERS_PASSWORD', 'password'),

        /*
         * Satu email per role, persis seperti empat role di tabel `users`.
         *
         * Dibaca `DemoUserSeeder`, dan `tenancy:make-user` memakainya untuk
         * menolak email yang sudah dipakai akun demo -- supaya tidak pernah
         * ada dua akun berbeda dengan email sama di satu lokasi.
         */
        'emails' => [
            'admin' => 'admin@gmail.com',
            'pelanggan' => 'pelanggan@gmail.com',
            'surveyor' => 'surveyor@gmail.com',
            'teknisi' => 'teknisi@gmail.com',
        ],
    ],

];
