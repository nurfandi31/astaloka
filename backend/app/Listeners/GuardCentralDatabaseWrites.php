<?php

namespace App\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengaman terhadap migrasi LOKASI yang ditulis ke database PUSAT.
 *
 * Ini yang menutup celah yang membuat `astaloka` (pusat) berakhir berisi
 * skema lokasi -- bukan tabel `tenants`, tapi `customers`, `monthly_bills`,
 * `villages`, dan trigger `*_amount_debit`, sementara tabel pusatnya sendiri
 * tidak pernah dibuat.
 *
 * Membedakan tiga aturan:
 *
 *   1. Folder `database/migrations_tenant/` berisi skema LOKASI. Migrasinya
 *      memakai `Schema::table()` / `Schema::create()` yang mengikuti
 *      `database.default`.
 *
 *   2. Folder `database/migrations_central/` berisi skema PUSAT. Semuanya
 *      memanggil `Schema::connection('central')` secara eksplisit.
 *
 *   3. `migrate` yang menulis ke database pusat DANGIN tanpa `--path` akan
 *      menjalankan seluruh migrasi lokasi di sana, tanpa satu pun error.
 *
 * Aturannya sengaja memeriksa DUA hal sekaligus, karena keduanya
 * dibutuhkan dan salah satunya saja tidak cukup:
 *
 *   - Ke mana migrasi itu ditulis (nama database hasil resolusi
 *     `--database=` atau `database.default`).
 *   - Folder mana yang dipindai (`--path=`).
 *
 * Mengizinkan setiap panggilan yang menyebut `--database` -- cara yang paling
 * menggoda untuk menulis pemeriksaan ini -- justru membuka lubang yang sama
 * persis: `php artisan migrate --database=central` tanpa `--path` memang
 * menyebutkan targetnya, tapi target itu PUSAT dan folder yang dipindai
 * LOKASI. Karena itu menyebut `--database` tidak cukup; yang diperiksa adalah
 * PASANGAN target + path.
 *
 * Panggilan yang sah dan yang tetap diizinkan:
 *
 *   tenancy:migrate-central  ->  migrate --database=central --path=database/migrations_central
 *   TenantManager (tenant)   ->  migrate --database=tenant --path=database/migrations_tenant
 */
class GuardCentralDatabaseWrites
{
    /**
     * Perintah yang menjalankan atau membaca migrasi.
     *
     * `migrate:status` ikut masuk daftar: ia tidak menulis apa pun, tapi
     * membaca tabel `migrations` -- dan dari situ operator bisa menyimpulkan
     * database pusat sudah rapi padahal isinya skemanya salah. Menahannya
     * lebih murah daripada dijelaskan setelahnya.
     *
     * @var list<string>
     */
    private const MIGRATION_COMMANDS = [
        'migrate',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:status',
    ];

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::MIGRATION_COMMANDS, true)) {
            return;
        }

        $central = (string) config('tenancy.central_database');

        if ($central === '') {
            return;
        }

        $target = $this->resolveTargetDatabase($event);

        /*
         * Dua arah, dua aturan. Yang satu sudah lama ada; yang kedua
         * ditambahkan bersamaan dengan pemindahan folder migrasi.
         *
         * Arah 1 -- skema LOKASI ditulis ke database PUSAT.
         * Terjadi kalau `--path` folder lokasi (atau tidak ada `--path`
         * sama sekali) bertemu `--database=central`. Karena migrasi lokasi
         * mengikuti `database.default`, hasilnya database pusat berisi
         * `customers`, `monthly_bills`, `villages`, dan trigger lokasi.
         *
         * Arah 2 -- skema PUSAT ditulis ke database LOKASI.
         * Terjadi kalau `--path` folder pusat bertemu target lokasi. Gejalanya
         * lebih sunyi dan karena itu lebih berbahaya: migrasi pusat memakai
         * `Schema::connection('central')`, jadi tabelnya memang dibuat di
         * PUSAT -- tapi catatan "sudah jalan"-nya ditulis ke tabel
         * `migrations` database LOKASI. Lokasi itu lalu mewarisi baris untuk
         * tabel yang tidak pernah dibuat di sana, dan provisioning berikutnya
         * tidak akan pernah menjalankannya.
         *
         * Dua-duanya tidak menghasilkan error, jadi tidak ada yang
         * memperingatkan. Keduanya sekarang ditutup di sini.
         */
        if ($target !== $central) {
            // Arah 2. `--database=` tidak wajib ada: tanpa flag pun,
            // `database.default` saat ini bisa saja sudah menunjuk pusat
            // karena middleware sudah menyalakan sebuah lokasi -- dan itu
            // justru keadaan yang paling mudah terjadi.
            if ($this->scansCentralMigrations($event)) {
                $this->abortPusatKeLokasi($event->command, (string) $target);
            }

            return;
        }

        // Arah 1. Menunjuk database pusat, tapi folder yang dipindai bukan
        // folder pusat -- ini bukan `tenancy:migrate-central`.
        if ($this->scansCentralMigrations($event)) {
            return;
        }

        $this->abort($event->command, $target);
    }

    /**
     * Database yang akan ditulisi oleh perintah ini.
     *
     * Mengikuti urutan yang sama dengan Laravel: `--database=` dibaca lebih
     * dulu, dan nilainya bisa berupa NAMA KONEKSI (`central`, `tenant`) atau
     * nama database mentah (`astaloka_lokasi`). Kalau berupa nama koneksi,
     * config-nya yang dicari; kalau tidak ada connection dengan nama itu,
     * Laravel memperlakukannya sebagai nama database MySQL -- jadi nilai itu
     * dipakai apa adanya.
     */
    private function resolveTargetDatabase(CommandStarting $event): ?string
    {
        $flag = $this->flagValue($event, 'database');

        if ($flag !== null && $flag !== '') {
            $config = config('database.connections.'.$flag);

            if (is_array($config) && isset($config['database'])) {
                return (string) $config['database'];
            }

            return $flag;
        }

        return $this->activeDatabase((string) config('database.default'));
    }

    /**
     * Apakah folder yang dipindai benar-benar folder migrasi pusat.
     *
     * Hanya `--path` yang eksplisit ke folder pusat yang dihitung benar.
     * Tanpa `--path`, Migrator memakai default-nya sendiri -- yaitu
     * `database/migrations/`, yang sejak folder migrasi dipindah ke
     * `database/migrations_tenant/` tidak lagi berisi apa pun.
     *
     * Yang dicocokkan adalah path yang benar-benar ada di disk, bukan
     * satu string yang nanti ditulis ulang lagi di sini. Path pusat dibaca
     * dari `config('tenancy.central_migrations_path')`, jadi memindahkan
     * folder cukup mengubah config -- dan tidak ada lagi tempat lain yang
     * harus diingat ikut diperbarui.
     *
     * Cara membandingkan: kedua path disamakan bentuknya lebih dulu
     * (backslash jadi `/`, tanpa slash di ujung). Perbandingan literal
     * saja tidak cukup karena pemanggil boleh menulis path absolut, atau
     * `./database/...`, atau path dengan slash di akhir -- semuanya folder
     * yang sama, dan semuanya akan ditolak kalau dibandingkan apa adanya.
     */
    private function scansCentralMigrations(CommandStarting $event): bool
    {
        $path = $this->flagValue($event, 'path');

        if ($path === null || $path === '') {
            return false;
        }

        return $this->normalisasiPath($path) === $this->normalisasiPath(
            (string) config('tenancy.central_migrations_path', 'database/migrations_central')
        );
    }

    /**
     * Bentuk path yang bisa dibandingkan: pemisah seragam, tanpa `./`,
     * tanpa slash di ujung, dan relatif terhadap root aplikasi kalau bisa.
     *
     * Path absolut dari `database_path()` dipangkas ke bagian relatifnya,
     * karena itu yang sama-sama dipakai Migrator dalam kedua bentuk
     * penulisan. Tanpa pemangkasan ini, pemanggil yang memakai
     * `database_path('migrations_central')` akan ditolak walau menunjuk
     * folder yang benar -- dan penolakan itu muncul sebagai "ditolak",
     * bukan sebagai "path-nya beda ejaan".
     */
    private function normalisasiPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        // Buang `./` di depan, kalau ada.
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        $path = trim($path, '/');

        // Path absolut dipangkas relatif terhadap root aplikasi.
        $root = str_replace('\\', '/', base_path());

        if ($root !== '' && str_starts_with($path, $root.'/')) {
            $path = substr($path, strlen($root) + 1);
        }

        return $path;
    }

    /**
     * Nama database yang benar-benar dibuka oleh koneksi itu.
     *
     * Exception ditelan karena kondisi itu normal, bukan berbahaya: database
     * pusat belum dibuat, jadi ada yang belum bisa dijalankan -- tapi belum
     * ada yang ditulis ke mana pun juga.
     */
    private function activeDatabase(string $connection): ?string
    {
        try {
            $name = DB::connection($connection)->getDatabaseName();
        } catch (\Throwable) {
            return null;
        }

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Nilai sebuah flag, atau null kalau tidak diisi.
     *
     * `getOption()` tidak bisa diandalkan di sini: pada `ArrayInput` yang
     * dibuat tanpa definisi option -- persis seperti yang dipakai test, dan
     * seperti input yang lolos ke listener sebelum command-nya selesai
     * mem-parsing -- Symfony melempar `The "database" option does not exist`.
     *
     * `getParameterOption()` membaca nilai apa adanya dari baris argumen,
     * tanpa butuh definisi option, jadi hasilnya konsisten untuk kedua
     * bentuk input. Itu yang dibutuhkan listener ini: membaca APA yang
     * pemanggil tulis, bukan apa yang Symfony sudah chew.
     *
     * Bentuk tanpa `=` (`--database central`) juga sah di Symfony, jadi
     * keduanya ditangani.
     */
    private function flagValue(CommandStarting $event, string $flag): ?string
    {
        $input = $event->input;

        $value = $input->getParameterOption(
            ['--'.$flag, '-'.$flag],
            null,
            true
        );

        if (! is_string($value) || $value === '') {
            return null;
        }

        // `--database central` (tanpa `=`) juga sah di Symfony.
        if ($value === '--'.$flag || $value === '-'.$flag) {
            $next = $input->getParameterOption(
                ['--'.$flag, '-'.$flag],
                false,
                true
            );

            return is_string($next) && $next !== '' ? $next : null;
        }

        return $value;
    }

    /**
     * Hentikan perintah dengan pesan yang menjelaskan cara yang benar.
     */
    private function abort(string $command, string $central): void
    {
        throw new RuntimeException(sprintf(
            "\n  DITOLAK: `artisan %s` akan menulis skema LOKASI ke database PUSAT `%s`.\n\n"
            ."  Migrasi di `database/migrations_tenant/` memakai koneksi tujuan, bukan\n"
            ."  `Schema::connection('central')`. Kalau dibiarkan, `%s` akan berisi\n"
            ."  `customers`, `monthly_bills`, `villages`, dan trigger lokasi,\n"
            ."  sementara tabel pusatnya (`tenants`, `audit_logs`, ...) tidak pernah\n"
            ."  dibuat -- persis kerusakan yang sudah pernah terjadi sekali.\n\n"
            ."  Untuk MIGRASI LOKASI, lewat tenant:\n"
            ."      php artisan tenancy:make <slug>\n"
            ."      php artisan tenancy:reseed-master <slug> --migrate\n\n"
            ."  Untuk MIGRASI PUSAT:\n"
            ."      php artisan tenancy:migrate-central --seed\n",
            $command,
            $central,
            $central
        ));
    }

    /**
     * Hentikan migrasi PUSAT yang mengarah ke database LOKASI.
     *
     * Pesannya menjelaskan yang sebenarnya terjadi, bukan kebalikannya.
     * Operator yang melihat tabel `tenants` hilang dari database lokasi akan
     * mengira migrasi lokasi yang salah, padahal yang salah justru arahnya.
     */
    private function abortPusatKeLokasi(string $command, string $target): void
    {
        throw new RuntimeException(sprintf(
            "\n  DITOLAK: `artisan %s` akan mencatat migrasi PUSAT ke database LOKASI `%s`.\n\n"
            ."  Migrasi di `%s` memakai `Schema::connection('central')`, jadi tabelnya\n"
            ."  memang dibuat di pusat -- tapi catatan \"sudah jalan\" ditulis ke tabel\n"
            ."  `migrations` koneksi yang SEDANG AKTIF. Kalau dibiarkan, `%s` mewarisi\n"
            ."  baris untuk tabel yang tidak pernah dibuat di sana, dan provisioning\n"
            ."  berikutnya tidak akan pernah menjalankannya.\n\n"
            ."  Untuk MIGRASI PUSAT:\n"
            ."      php artisan tenancy:migrate-central --seed\n\n"
            ."  Untuk MIGRASI LOKASI, lewat tenant:\n"
            ."      php artisan tenancy:reseed-master <slug> --migrate\n",
            $command,
            $target,
            (string) config('tenancy.central_migrations_path', 'database/migrations_central'),
            $target
        ));
    }
}
