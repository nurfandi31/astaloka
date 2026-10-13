<?php

namespace App\Services;

use App\Models\Central\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mengatur koneksi database lokasi yang sedang aktif, dan membuat database
 * baru saat sebuah lokasi didaftarkan.
 *
 * Cara kerjanya sengaja dibuat "tukar koneksi default", bukan mengikat
 * `$connection` pada tiap model. Alasannya: database lokasi punya skema yang
 * PERSIS sama dengan aplikasi aslinya, dan aplikasi itu tidak pernah
 * menyebut nama koneksi di query-nya -- `Customer::query()` memakai
 * `database.default`. Satu-satunya cara supaya seluruh 800-odd file yang
 * sudah ada langsung ikut ter-isolasi tanpa disentuh adalah dengan mengganti
 * default-nya.
 *
 * Konsekuensinya: `Setting::first()` tidak perlu diubah sama sekali, FK dan
 * trigger MySQL tetap utuh, dan logika pembayaran tidak tersentuh.
 */
class TenantManager
{
    /** Nama koneksi yang dipakai seluruh request untuk data lokasi. */
    public const CONNECTION = 'tenant';

    private ?Tenant $current = null;

    /** Lokasi yang sedang aktif di request ini, kalau sudah di-resolve. */
    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function isResolved(): bool
    {
        return $this->current !== null;
    }

    /**
     * Arahkan koneksi default ke database milik lokasi.
     *
     * Dipanggil oleh middleware `ResolveTenant` sebelum auth, jadi Sanctum
     * mencari token di database lokasi yang benar sejak awal.
     */
    public function activate(Tenant $tenant): void
    {
        $creds = $tenant->credentials();

        Config::set('database.connections.'.self::CONNECTION, array_merge(
            Config::get('database.connections.'.self::CONNECTION, []),
            [
                'driver' => 'mysql',
                'host' => $creds['host'],
                'port' => $creds['port'],
                'database' => $creds['database'],
                'username' => $creds['username'],
                'password' => $creds['password'],
            ]
        ));

        // Koneksi yang sudah dibuka dengan nama database lama harus dibuang,
        // kalau tidak PDO akan tetap memakai database sebelumnya despite
        // config yang sudah diganti.
        DB::purge(self::CONNECTION);

        // Dan ini inti mechanism-nya: seluruh model yang tidak menyebut
        // `$connection` ikut ter-redirect ke lokasi aktif.
        Config::set('database.default', self::CONNECTION);

        $this->current = $tenant;
    }

    /**
     * Kembalikan koneksi default ke database PUSAT.
     *
     * Dipakai oleh `EnsureSuperadmin` dan setelah operasi lintas-lokasi,
     * supaya request berikutnya tidak diam-diam membaca database lokasi.
     *
     * `central` yang jadi tujuan, bukan `mysql`. Keduanya menunjuk
     * DB_DATABASE -- yaitu database LOKASI -- jadi menulis `mysql` di sini
     * akan mengembalikan default ke lokasi, persis kebalikan dari maksudnya.
     */
    public function deactivate(): void
    {
        // Buang koneksi tenant YANG SUDAH DIBUKA supaya PDO tidak tetap
        // memegang database lokasi lama. Tanpa purge, `DB::connection(...)`
        // berikutnya masih memakai handle lama -- gejalanya query "sumber
        // daya" seperti information_schema.TRIGGERS mengembalikan hasil
        // dari database yang sudah ditinggalkan.
        DB::purge(self::CONNECTION);
        DB::purge(config('tenancy.central_connection', 'central'));

        Config::set('database.default', config('tenancy.central_connection', 'central'));

        $this->current = null;
    }

    /**
     * Jalankan callback dengan lokasi tertentu aktif, lalu pulihkan.
     *
     * Dipakai superadmin untuk membuka data satu lokasi tanpa lingering
     * koneksi di request berikutnya.
     *
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, callable $callback)
    {
        $sebelumnya = $this->current;
        $defaultSebelum = Config::get('database.default');

        $this->activate($tenant);

        try {
            return $callback();
        } finally {
            // finally, bukan sukses: kalau callback melempar exception,
            // koneksi tetap harus dibersihkan atau request berikutnya
            // (mis. setelah render error) akan membaca database lokasi.
            DB::purge(self::CONNECTION);
            Config::set('database.default', $defaultSebelum);
            $this->current = $sebelumnya;
        }
    }

    /**
     * Buat database MySQL baru untuk sebuah lokasi.
     *
     * Nama database memakai pola <prefix><slug>. Dua penyesuaian terjadi di
     * sini, keduanya wajib:
     *
     *  - slug `tirto-mulo` diubah jadi `tirto_mulo`. Slug dipakai di URL
     *    dan boleh mengandung hyphen, tapi nama database tidak boleh: MySQL
     *    menerima `-` hanya di dalam backtick, dan nama database disisipkan
     *    apa adanya ke CREATE DATABASE karena tidak bisa diparameterisasi.
     *    Kolom `slug` di registry tetap menyimpan bentuk aslinya.
     *
     *  - nama dipotong ke 64 karakter, batas MySQL. Sisa-sisa underscore
     *    di ujung dibersihkan supaya tidak menghasilkan nama seperti
     *    `astaloka_lokasi__`.
     */
    public function createDatabase(string $slug): string
    {
        $prefix = (string) config('tenancy.database_prefix', 'astaloka_lokasi_');
        $batas = (int) config('tenancy.max_database_name_length', 64);

        $slugUntukDb = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug)) ?? '';

        if ($slugUntukDb === '' || trim($slugUntukDb, '_') === '') {
            throw new RuntimeException("Slug `{$slug}` tidak menghasilkan nama database yang valid.");
        }

        $nama = rtrim($prefix.$slugUntukDb, '_');
        $nama = substr($nama, 0, $batas);

        return $this->createDatabaseNamed($nama);
    }

    /**
     * Buat database dengan nama yang sudah jadi (sudah tervalidasi).
     *
     * Dipisah dari createDatabase() supaya pemanggil yang sudah punya nama
     * final -- misalnya memindahkan database lama ke nama baru -- tidak
     * harus melewati normalisasi slug dua kali.
     */
    public function createDatabaseNamed(string $nama): string
    {
        $this->validateDatabaseName($nama);

        // CREATE DATABASE tidak bisa diparameterisasi, jadi nama wajib
        // divalidasi ketat lebih dulu (lihat validateDatabaseName).
        $sudahAda = DB::connection('central')->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$nama]
        );

        if ($sudahAda !== []) {
            throw new RuntimeException("Database `{$nama}` sudah dipakai lokasi lain.");
        }

        DB::connection('central')->statement(
            "CREATE DATABASE `{$nama}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );

        return $nama;
    }

    /**
     * Hapus database lokasi. Permanen -- tidak ada recycle bin.
     *
     * Mengembalikan true kalau database benar-benar dihapus, false kalau
     * memang tidak ada. Pemanggil butuh informasi itu untuk log audit: saat
     * provisioning gagal, "database sudah dibuang" dan "database memang
     * belum pernah ada" adalah dua kondisi yang berbeda, dan membingungkan
     * keduanya membuat lokasi setengah jadi yang menggantung terlihat bersih
     * di log.
     *
     * Melempar exception kalau nama tidak valid atau menunjuk database pusat
     * -- kegagalan itu tidak boleh disamarkan jadi false.
     */
    public function dropDatabase(string $nama): bool
    {
        $this->validateDatabaseName($nama);

        if ($nama === Config::get('tenancy.central_database')) {
            throw new RuntimeException('Refused: nama database pusat tidak boleh dihapus.');
        }

        if (! $this->databaseExists($nama)) {
            return false;
        }

        DB::connection('central')->statement("DROP DATABASE `{$nama}`");

        return true;
    }

    /** Apakah database dengan nama ini sudah ada di MySQL. */
    public function databaseExists(string $nama): bool
    {
        $ada = DB::connection('central')->select(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$nama]
        );

        return $ada !== [];
    }

    /**
     * Nama database hanya boleh [A-Za-z0-9_].
     *
     * Nama database disisipkan apa adanya ke `CREATE DATABASE` karena MySQL
     * tidak menerima placeholder di situ. Parameternya tidak bisa dianggap
     * aman hanya karena berasal dari registry -- registry itu bisa saja berisi
     * sisa input user yang tidak tervalidasi, dan satu backtick cukup untuk
     * mengubah statement.
     */
    private function validateDatabaseName(string $nama): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $nama)) {
            throw new RuntimeException(
                "Nama database tidak valid: `{$nama}`. Hanya huruf, angka, dan underscore yang diizinkan."
            );
        }

        if (strlen($nama) > 64) {
            throw new RuntimeException("Nama database terlalu panjang (maks 64 karakter): `{$nama}`.");
        }
    }

    /**
     * Jalankan seluruh migration aplikasi ke database lokasi tertentu.
     *
     * Dipanggil setelah CREATE DATABASE, supaya database lokasi baru punya
     * skema yang sama persis dengan lokasi lain.
     */
    public function migrateTenantDatabase(Tenant $tenant): void
    {
        $this->runFor($tenant, function () {
            Artisan::call('migrate', [
                '--database' => self::CONNECTION,
                '--force' => true,
            ]);
        });
    }

    /**
     * Jalankan seeder master di database lokasi baru.
     *
     * Seeder dipanggil satu per satu, bukan `db:seed`, karena `db:seed`
     * tanpa `--class` memakai DatabaseSeeder yang isinya peranggilan untuk
     * aplikasi single-tenant -- termasuk pelanggan contoh, meter reading, dan
     * struk. Menjalankannya di lokasi baru akan mengisi database produksi
     * dengan data fiktif.
     *
     * Daftar dan urutannya dari `config('tenancy.provision.seeders')`. Semua
     * seeder di sana WAJIB idempoten: provisioning juga dijalankan ulang lewat
     * `tenancy:reseed-master` untuk lokasi yang sudah terlanjur ada, dan
     * skemanya sudah terisi data.
     */
    public function seedTenantDatabase(Tenant $tenant): void
    {
        $seeders = (array) config('tenancy.provision.seeders', []);

        if ($seeders === []) {
            return;
        }

        $this->runFor($tenant, function () use ($seeders) {
            foreach ($seeders as $seeder) {
                if (! is_string($seeder) || ! class_exists($seeder)) {
                    continue;
                }

                Artisan::call('db:seed', [
                    '--class' => $seeder,
                    '--database' => self::CONNECTION,
                    '--force' => true,
                ]);
            }
        });
    }

    /**
     * Tulis identitas lokasi ke tabel `settings` miliknya sendiri.
     *
     * Tanpa langkah ini, laporan apa pun yang mencantumkan nama lembaga akan
     * keluar dengan isi default dari `SettingsSeeder` -- yaitu "ASTALOKA
     * Tirto Mulo" untuk semua lokasi, karena seeder itu masih memakai data contoh.
     * Lokasi baru harus punya identitas sendiri sejak baris pertama, bukan
     * menunggu admin ingat menggantinya lewat menu pengaturan.
     *
     * `id = 1` dipilih karena SELURUH kode aplikasi membaca `Setting::first()`
     * (20-an pemanggilan di BillingService, PelaporanController, SopController,
     * dst). Mengubahnya jadi key-value akan membuat semua pemanggilan itu ikut
     * harus diubah -- perubahan yang jauh lebih berisiko daripada menyelaraskan
     * kolom yang memang sudah tersedia di tabel.
     *
     * Aman dipanggil berulang: `updateOrInsert` menulis ulang isi yang sama,
     * jadi lokasi yang identitasnya sudah dikoreksi manual tidak ikut
     * ditimpa kalau perintah ini dijalankan tanpa tujuan eksplisit.
     */
    public function applyTenantIdentity(Tenant $tenant): void
    {
        $this->runFor($tenant, function () use ($tenant) {
            DB::connection(self::CONNECTION)->table('settings')->updateOrInsert(
                ['id' => 1],
                [
                    'key' => 'sop',
                    'value' => '',
                    'nama' => $tenant->nama,
                    'nama_lembaga' => $tenant->nama_lembaga ?: $tenant->nama,
                    'alamat' => $tenant->alamat,
                    'email' => $tenant->email,
                    'telepon' => $tenant->telepon,
                    'domain' => $tenant->domain,
                    'status_pembayaran' => true,
                    'batas_tagihan' => 10,
                    'toleransi_tunggakan' => (int) config('tenancy.invoice_grace_months', 1),
                    'logo' => null,
                    'pesan_tagihan' => 'Yth. {customer}, tagihan Anda bulan ini sebesar {jumlah_tagihan}. Mohon segera melakukan pembayaran sebelum jatuh tempo. Terima kasih.',
                    'pesan_pembayaran' => 'Yth. {customer}, pembayaran tagihan sebesar {tagihan} telah kami terima. Terima kasih.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }
}
