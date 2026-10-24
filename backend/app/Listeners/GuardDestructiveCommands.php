<?php

namespace App\Listeners;

use App\Console\Commands\MigrateCentral;
use App\Services\TenantManager;
use Illuminate\Console\Events\CommandStarting;
use RuntimeException;

/**
 * Pengaman terhadap perintah artisan yang menghapus data.
 *
 * Latar belakang: `php artisan migrate:fresh` yang dijalankan di LUAR
 * PHPUnit membaca `.env`, bukan `phpunit.xml`. Padahal `.env` berisi
 * `DB_DATABASE=astaloka_lokasi` -- database operasional yang nyata. Akibatnya
 * `migrate:fresh` menghapus seluruh tabel lokasi beserta isinya, dan karena
 * `phpunit.xml` hanya berlaku selama test berjalan, tidak ada yang menyetopnya.
 *
 * Perintah yang dipantau di sini semuanya menghapus isi database pada
 * koneksi tujuan: `db:wipe`, `migrate:fresh`, dan `migrate:refresh`
 * men-drop semua tabel, sedangkan `migrate:reset` dan `migrate:rollback`
 * menjalankan method `down()` -- yang di repo ini men-drop tabel dan kolom.
 *
 * Aturan mainnya sederhana dan sengaja ketat: nama database tujuan WAJIB
 * mengandung `testing` (case-insensitive). `astaloka_lokasi`, `astaloka`,
 * maupun `pamsides` tidak mengandung kata itu, jadi ketiganya ditolak.
 */
class GuardDestructiveCommands
{
    /**
     * Perintah yang isinya menghapus isi database pada koneksi tujuan.
     *
     * `migrate` biasa TIDAK ada di sini, dan itu bukan kelalaian.
     *
     * `migrate` hanya MENAMBAHKAN tabel yang belum ada -- tidak pernah
     * menghapus isi tabel yang sudah ada, kecuali lewat method `up()` yang
     * memang sengaja men-drop kolom (mis. `drop_max_users`). Karena itu
     * `TenantManager` boleh memanggilnya pada database lokasi yang nyata:
     * itu justru jalurnya untuk menyiapkan lokasi baru.
     *
     * Yang dicegah di sini adalah varian yang menghapus data tanpa
     * interaksi: `db:wipe`, `migrate:fresh`, `migrate:refresh`,
     * `migrate:reset`, dan `migrate:rollback`.
     *
     * @var list<string>
     */
    private const DESTRUCTIVE = [
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
    ];

    /**
     * Koneksi yang dipakai untuk provisioning internal.
     *
     * `tenant` dan `central` adalah dua jalur yang memang dipakai aplikasi
     * untuk menyiapkan skema: `TenantManager::migrateTenantDatabase()` dan
     * `MigrateCentral`. Keduanya memanggil `migrate` dengan `--database` yang
     * menunjuk koneksi ini, dan keduanya tidak merusak data yang sudah ada.
     *
     * Pengecualian ini TIDAK berlaku untuk perintah lain di daftar
     * `DESTRUCTIVE`. `migrate:fresh --database=central` menjatuhkan seluruh
     * tabel pusat -- dan itu harus tetap ditolak.
     *
     * @var list<string>
     */
    private const KONEKSI_PROVISIONING = ['tenant', 'central'];

    public function handle(CommandStarting $event): void
    {
        $command = $event->command;

        $menghapusSeluruhIsi = in_array($command, self::DESTRUCTIVE, true);

        $migrateBiasa = $command === 'migrate';

        if (! $menghapusSeluruhIsi && ! $migrateBiasa) {
            return;
        }

        /*
         * `migrate` tanpa `--force` di produksi hanya menampilkan konfirmasi
         * dan berhenti -- tidak ada yang tertulis. Yang berbahaya adalah
         * `--force`, karena itu persetujuan eksplisit operator untuk
         * menulis ke database yang nyata.
         *
         * Perintah lain di `DESTRUCTIVE` tidak punya pengecualian seperti
         * ini: `db:wipe` dan `migrate:fresh` Selalu menghapus seluruh isi
         * database begitu dijalankan, dengan atau tanpa `--force`.
         */
        if ($migrateBiasa && ! $this->hasFlag($event, 'force')) {
            return;
        }

        /*
         * Flag `--database=` TIDAK otomatis berarti aman.
         *
         * Dulu flag ini langsung berarti "periakan" -- begitu ada
         * `--database`, pemeriksaan dihentikan. Itu celah yang nyata, bukan
         * teori: `--database=mysql` menunjuk NAMA KONEKSI, dan koneksi
         * `mysql` itulah yang menyimpan `DB_DATABASE` dari `.env` --
         * yaitu `astaloka_lokasi`, database operasional yang berisi data
         * nyata. Jadi `php artisan migrate:fresh --database=mysql --force`
         * menjatuhkan seluruh isi database lokasi dan guard tidak
         * menyentuhnya.
         *
         * Bentuk `--database=central` memang sah untuk jalur migrasi pusat,
         * tapi jalur itu sudah dijaga terpisah oleh `GuardCentralDatabaseWrites`
         * yang memeriksa PASANGAN target + folder migrasi. Di sini yang
         * diperiksa hanya satu hal: nama database yang AKAN DIHAPUS.
         *
         * Jadi targetnya dibaca, apa pun bentuk flag-nya:
         *   - `--database=central`  -> config koneksi `central`
         *   - `--database=mysql`    -> config koneksi `mysql` (yaitu `.env`)
         *   - tanpa flag            -> koneksi default
         */
        $database = $this->targetDatabase($event);

        if ($database === null) {
            // Targetnya tidak bisa dibaca. Menolak lebih aman daripada
            // mengizinkan: nama database yang tidak diketahui tidak bisa
            // dipastikan aman.
            $this->abort($command, '(tidak terbaca)');
        }

        if (stripos($database, 'testing') !== false) {
            return;
        }

        /*
         * Jalur provisioning internal tetap boleh.
         *
         * Hanya berlaku untuk `migrate` -- perintah yang tidak menghapus
         * isi tabel yang sudah ada. `migrate:fresh`, `db:wipe`, dan
         * perintah lain di `DESTRUCTIVE` tetap ditolak di sini, berapa pun
         * nama koneksinya.
         *
         * Jalur yang sama dijaga terpisah oleh `GuardCentralDatabaseWrites`:
         * perintah yang menulis skema lokasi ke database pusat, atau
         * sebaliknya, ditolak di sana.
         */
        if ($migrateBiasa && $this->adalahKoneksiProvisioning($event)) {
            return;
        }

        $this->abort($command, $database);
    }

    /**
     * Apakah perintah ini ditulis untuk salah satu koneksi provisioning.
     *
     * Yang diperiksa adalah NAMA KONEKSI yang disebut di `--database`, bukan
     * nama database yang ditunjuknya. `tenant` menunjuk database lokasi --
     * `astaloka_lokasi` -- yang namanya tidak mengandung `testing`, tapi
     * memanggil `migrate` padanya adalah jalur provisioning yang sah.
     *
     * Nama database mentah yang kebetulan sama dengan nama koneksi tidak
     * bisa dibedakan dari sini, dan itu tidak masalah: nama database mentah
     * yang bukan nama koneksi yang dikenal tidak pernah dipakai di repo ini.
     *
     * @see TenantManager::migrateTenantDatabase()
     * @see MigrateCentral
     */
    private function adalahKoneksiProvisioning(CommandStarting $event): bool
    {
        $flag = $this->flagValue($event, 'database');

        if ($flag === null || $flag === '') {
            return false;
        }

        return in_array($flag, self::KONEKSI_PROVISIONING, true);
    }

    /**
     * Database yang akan dihapus oleh perintah ini.
     *
     * Mengikuti urutan yang sama dengan Laravel: `--database=` dibaca lebih
     * dulu. Nilainya bisa berupa NAMA KONEKSI (`mysql`, `central`, `tenant`)
     * atau nama database mentah -- dan untuk nama database mentah Laravel
     * membuat koneksi sekali pakai yang menunjuk nama itu apa adanya.
     *
     * Yang tidak boleh terjadi: nama database mentah lolos begitu saja
     * tanpa diperiksa. `astaloka_lokasi` tidak mengandung `testing`, jadi
     * harus ditolak -- persis seperti kalau flag-nya tidak diisi sama sekali.
     *
     * Koneksi yang tidak dikonfigurasi menghasilkan null. Itu tidak boleh
     * lolos begitu saja: tidak diketahui berarti tidak bisa dipastikan aman.
     */
    private function targetDatabase(CommandStarting $event): ?string
    {
        $flag = $this->flagValue($event, 'database');

        if ($flag !== null && $flag !== '') {
            $config = config('database.connections.'.$flag);

            if (is_array($config) && isset($config['database']) && is_string($config['database'])) {
                return $config['database'];
            }

            // Bukan nama koneksi yang dikenal -> diperlakukan sebagai nama
            // database mentah, persis seperti yang dilakukan Laravel.
            return $flag;
        }

        $default = (string) config('database.default');
        $config = config('database.connections.'.$default);

        if (is_array($config) && isset($config['database']) && is_string($config['database'])) {
            return $config['database'];
        }

        return $default;
    }

    /**
     * Nilai sebuah flag, atau null kalau tidak diisi.
     *
     * `getOption()` tidak bisa diandalkan di sini: pada `ArrayInput` yang
     * dibuat tanpa definisi option -- persis seperti yang dipakai test --
     * Symfony melempar `The "database" option does not exist`.
     *
     * `getParameterOption()` membaca nilai apa adanya dari baris argumen,
     * tanpa butuh definisi option. Bentuk tanpa `=` (`--database central`)
     * juga sah di Symfony, jadi keduanya ditangani.
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
     * Apakah flag ini ada, apa pun nilainya.
     *
     * `hasParameterOption()` memeriksa nama flag apa adanya, termasuk yang
     * bernilai `false`. Ini yang dipakai karena `getOptions()` tidak bisa
     * diandalkan: tanpa definisi option, hasilnya kosong.
     */
    private function hasFlag(CommandStarting $event, string $flag): bool
    {
        $input = $event->input;

        return $input->hasParameterOption('--'.$flag)
            || $input->hasParameterOption('-'.$flag);
    }

    /**
     * Hentikan perintah dengan pesan yang menjelaskan cara yang benar.
     */
    private function abort(string $command, string $database): void
    {
        throw new RuntimeException(sprintf(
            "\n  DITOLAK: `artisan %s` tidak boleh berjalan pada database `%s`.\n\n"
            ."  Perintah ini menghapus isi database, dan `%s` berisi\n"
            ."  data operasional yang nyata. `phpunit.xml` (DB_DATABASE=astaloka_testing)\n"
            ."  hanya dibaca selama test berjalan, jadi tidak melindungi perintah ini.\n\n"
            ."  Untuk database pengujian, jalankan lewat PHPUnit:\n"
            ."      php artisan test\n\n"
            ."  Untuk mengosongkan database pengujian secara manual:\n"
            ."      \$env:DB_DATABASE='astaloka_testing'; php artisan %s\n",
            $command,
            $database,
            $database,
            $command
        ));
    }
}
