<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `tenant_domains` di DATABASE PUSAT -- daftar domain custom tiap lokasi.
 *
 * MENGAPA TABEL TERPISAH, BUKAN KOLOM BARU DI `tenants`
 *
 * Kolom `domain` / `domain_alternatif` yang sudah ada hanya menyediakan DUA
 * slot, dan itu tidak cukup untuk kasus yang paling umum:
 *
 *   - satu lokasi butuh domain produksi, domain staging, dan domain lama
 *     yang masih tersimpan di bookmark petugas;
 *   - pengalihan domain harus bisa dilakukan satu per satu, bukan dengan
 *     menimpa kolom yang sama;
 *   - wildcard `*.pamsides.test` sengaja terpisah dari domain persis,
 *     karena keduanya berbeda sifat: yang persis adalah identitas, wildcard
 *     adalah catch-all yang harus bisa dimatikan tanpa mematikan domain
 *     aslinya.
 *
 * Kalau semuanya ditumpuk di JSON satu kolom (pola SIDBM), resolver tidak
 * bisa memakai index -- dia harus memuat seluruh lokasi lalu mencocokkan satu
 * per satu di PHP, pada SETIAP request, termasuk request aset dan API.
 * Satu query dengan index unik jauh lebih murah, dan daftar lokasi akan terus
 * tumbuh seiring pelanggan.
 *
 * MIGRASI DATA LAMA
 *
 * Isi `domain` dan `domain_alternatif` yang sudah ada disalin ke sini pada
 * `up()`, ditandai `is_primary = true` untuk domain utama. Ini bukan
 * pemindahan: kedua kolom lama TIDAK dihapus dan tetap dibaca sebagai
 * cadangan oleh TenantResolver. Alasannya dua:
 *
 *   1. Kalau migrasi ini perlu di-rollback, tidak ada data yang hilang --
 *      registry lama masih utuh.
 *   2. Lokasi yang ditambahkan lewat `php artisan tenancy:make` (bukan lewat
 *      panel) tidak melewati controller, sehingga tidak selalu punya baris
 *      di sini. Fallback menutup celah itu tanpa perlu perubahan di sana.
 *
 * Normalisasi host (lowercase, buang skema/path/port) dilakukan di dalam
 * migrasi karena baris lama bisa saja tersimpan tidak konsisten -- kolom lama
 * tidak pernah memvalidasi format sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        if (Schema::connection($central)->hasTable('tenant_domains')) {
            return;
        }

        Schema::connection($central)->create('tenant_domains', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();

            /*
             * Host LITERAL -- bukan pola. Contoh: `pamsides.test`,
             * `demo.astaloka.test`, `demo.internet.test`.
             *
             * Disimpan tanpa skema dan tanpa path (`https://x.test/login`
             * -> `x.test`) supaya bisa dibandingkan langsung dengan
             * `$request->getHost()`, yang hasilnya sudah polos.
             */
            $table->string('host', 191);

            /*
             * Benar berarti host-nya wildcard: `*.astaloka.test`.
             *
             * Disimpan sebagai kolom boolean, bukan tries wildcard di dalam
             * string, supaya query exact match tetap bisa memakai index dan
             * query wildcard bisa menyaring kandidat lebih dulu.
             */
            $table->boolean('is_wildcard')->default(false);

            /*
             * Domain utama lokasi ini. Ditampilkan pertama di panel dan dipakai
             * saat menulis identitas lokasi ke tabel `settings` miliknya sendiri.
             *
             * HANYA satu domain aktif yang boleh punya ini -- lihat
             * TenantDomainController::makePrimary().
             */
            $table->boolean('is_primary')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * UNIK PER HOST. Ini penjaga terakhir saat resolusi.
             *
             * Kalau dua lokasi boleh punya host yang sama, request ke host itu
             * akan sampai ke lokasi yang mana? `first()` selalu mengembalikan
             * satu baris tanpa error -- lokasi kedua diam-diam tidak bisa
             * diakses lewat domainnya selamanya, dan tidak ada yang
             * mengatakannya. Index unik membuat tabrakan kembali menjadi error
             * yang terlihat saat domain disimpan, bukan menetap diam-diam.
             *
             * Panjang `host` 191 dipilih karena batas maksimum VARCHAR
             * terindeks utf8mb4 di InnoDB dengan format lama adalah 767 byte,
             * dan 191 * 4 = 764 byte masih muat.
             */
            $table->unique(['host'], 'tenant_domains_host_unique');
        });

        /*
         * Satu lokasi bisa punya banyak domain, dan panel perlu mengambil
         * semuanya sekaligus untuk halaman detail. Tanpa index (tenant_id),
         * tiap domain yang ditampilkan melakukan scan tabel penuh.
         */
        Schema::connection($central)->table('tenant_domains', function (Blueprint $table) {
            $table->index(['tenant_id', 'is_active'], 'tenant_domains_tenant_active_index');
        });

        $this->migrateLegacyDomains($central);
    }

    public function down(): void
    {
        $central = (string) config('tenancy.central_connection', 'central');

        Schema::connection($central)->dropIfExists('tenant_domains');
    }

    /**
     * Salin `domain` / `domain_alternatif` ke tabel baru.
     *
     * Sengaja TIDAK menghapus kolom lama: keduanya jadi fallback baca (lihat
     * docblock atas). Migrasi ini hanya menambahkan sumber kebenaran baru di
     * samping yang lama, jadi `down()` cukup dropping tabel -- tidak ada data
     * yang perlu dikembalikan ke tempat semula.
     */
    private function migrateLegacyDomains(string $central): void
    {
        $taken = [];

        foreach (DB::connection($central)->table('tenants')->get() as $tenant) {
            $sumber = [
                ['host' => $tenant->domain, 'primary' => true],
                ['host' => $tenant->domain_alternatif, 'primary' => false],
            ];

            foreach ($sumber as $slot) {
                $host = $this->normalizeHost($slot['host']);

                if ($host === null) {
                    continue;
                }

                /*
                 * Host yang sudah dipakai lokasi lain dilewati, bukan membuat
                 * migrasi gagal. Data lama memang bisa saja duplikat --
                 * tidak ada constraint yang mencegahnya --
                 *
                 * Melewatkan satu baris berarti lokasi itu kehilangan domain
                 * lamanya di daftar baru, yang masih bisa dibaca lewat kolom
                 * lamanya. Membatalkan migrasi karena satu baris bermasalah
                 * berarti TIDAK ADA lokasi yang punya domain baru sama sekali.
                 */
                if (isset($taken[$host])) {
                    continue;
                }

                $taken[$host] = true;

                $now = now();

                DB::connection($central)->table('tenant_domains')->insertOrIgnore([
                    'tenant_id' => $tenant->id,
                    'host' => $host,
                    'is_wildcard' => false,
                    'is_primary' => $slot['primary'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Bersihkan host dari input mentah.
     *
     * Kolom lama diisi dari form yang tidak pernah memvalidasi format, jadi
     * isinya bisa saja `https://Tirto-Mulo.Astaloka.test/login`. Pencocokan
     * host membandingkan dengan `strtolower`, menyisakan huruf besar atau
     * skema di sini berarti domain yang berhasil dimigrasikan justru tidak
     * pernah ditemukan saat request datang.
     *
     * @return string|null Host yang sudah bersih, atau null kalau tidak layak
     */
    private function normalizeHost(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $host = strtolower(trim($raw));

        if ($host === '') {
            return null;
        }

        // Buang skema. Skema dicocokkan lebih dulu lewat regex supaya
        // `http://` tidak ikut terpotong oleh explode('/') di bawah.
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host);

        // Buang path, query, dan fragment.
        $host = explode('/', $host)[0];

        // Buang port. Penting untuk development: `localhost:5173` adalah host
        // Vite yang tidak boleh ikut terimpan sebagai domain lokasi.
        $host = explode(':', $host)[0];

        $host = rtrim($host, '.');

        return $host === '' ? null : $host;
    }
};