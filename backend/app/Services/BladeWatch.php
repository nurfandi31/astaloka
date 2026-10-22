<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Deteksi perubahan file Blade untuk live reload.
 *
 * Dipakai oleh `App\Http\Middleware\BladeLiveReload` dan endpoint-nya. Yang
 * dikembalikan ke browser HANYA "ya/tidak" plus satu timestamp -- nama file
 * tidak pernah keluar dari server. Kalau bocor, endpoint ini jadi peta
 * direktori untuk siapa pun yang bisa mengakses endpoint ini.
 *
 * Kenapa mtime, bukan hash isi file
 * ==============================
 * `filemtime()` cukup dan jauh lebih murah: satu stat per file, tanpa membaca
 * isi. Hash isi seluruh direktori `views/` tiap detik untuk halaman marketing
 * yang 2.300 baris itu boros tanpa manfaat -- mtime berubah persis ketika
 * isi berubah, dan tidak ada editor yang bisa mengubah isi tanpa menyentuh
 * mtime.
 *
 * Cache 1 detik
 * =============
 * Tanpa cache, satu reload halaman memicu beberapa request polling sekaligus
 * (semua tab yang masih terbuka), dan tiap request itu membaca mtime dari
 * seluruh file views. Cache 1 detik membuat semua request di detik yang sama
 * membaca hasil yang sama -- cukup untuk reload yang tetap terasa instan, dan
 * tetap menggating jumlah scan di disk.
 *
 * Kenapa cache IN-PROCESS, bukan `cache()` Laravel
 * ===============================================
 * Ini bukan preferensi gaya, dan sebelumnya adalah bug yang nyata.
 *
 * `CACHE_STORE=database` di `.env`, dan store `database` memakai
 * `database.default` ketika `DB_CACHE_CONNECTION` kosong -- dan `database.default`
 * adalah HAL YANG BERUBAH-UBAH. `TenantManager::activate()` mengarahkan
 * default ke database lokasi, `deactivate()` mengembalikannya ke `central`.
 *
 * Akibatnya cache live reload pernah menulis ke database LOKASI:
 *   key = laravel-cache-blade_watch.latest_mtime
 *
 * Dan lebih buruk, database pusat (`astaloka`) tidak punya tabel `cache`. Request yang
 * datang saat default = `central` berakhir dengan `SQLSTATE[42S02] Base table
 * or view not found` -- bukan 500 yang jelas, tapi polling yang diam-diam mati.
 *
 * Memakai cache in-process membuat kelas ini benar-benar bebas database:
 * satu `stat()` per file, nol query, nol kemungkinan menulis ke tenant mana
 * pun. Untuk satu nilai turunan dari mtime, ini pilihan yang tepat -- nilai
 * yang hilang di akhir request adalah kehilangan nol, karena request
 * berikutnya tinggal scan ulang.
 */
class BladeWatch
{
    /** File dan folder yang dipantau, relatif terhadap base path Laravel. */
    private const WATCHED = [
        'resources/views',
        'config/tenancy.php',
    ];

    /** Umur cache hasil scan, dalam detik. */
    private const CACHE_TTL = 1;

    /**
     * Hasil scan, disimpan per-instance.
     *
     * Sengaja `static`: service ini di-resolve per request oleh container, jadi
     * instance property akan selalu kosong dan cache tidak pernah bekerja.
     * `static` membuatnya bertahan selama satu proses `php artisan serve` --
     * persis lifetime yang dibutuhkan.
     *
     * Aman dari kebocoran antar-request: isinya satu integer turunan dari
     * mtime file, bukan data pengguna.
     */
    private static ?int $tercache = null;

    private static int $tercacheUmur = 0;

    /**
     * mtime terbaru di antara semua file yang dipantau.
     *
     * Mengembalikan `null` kalau tidak ada file sama pun -- lebih mungkin
     * terjadi di proyek yang baru di-clone dan `views/` masih kosong. Caller
     * harus memperlakukannya sebagai "tidak ada perubahan", bukan error.
     */
    public function latestMtime(): ?int
    {
        $sekarang = time();

        if (self::$tercache !== null && ($sekarang - self::$tercacheUmur) < self::CACHE_TTL) {
            return self::$tercache;
        }

        self::$tercache = $this->scan();
        self::$tercacheUmur = $sekarang;

        return self::$tercache;
    }

    /**
     * Apakah ada file yang lebih baru dari `$since`.
     *
     * `$since` adalah nilai `latest_mtime()` yang waktu lalu, yang dikirim
     * browser bersama request. Bandingkan di sini, bukan di JavaScript, supaya
     * logika "kapan harus reload" hanya ada di satu tempat.
     */
    public function changedSince(?int $since): bool
    {
        $latest = $this->latestMtime();

        // Tanpa `$since`, browser baru pertama kali memuat halaman: belum ada
        // baseline untuk dibandingkan, jadi tidak ada yang perlu di-reload.
        if ($since === null || $latest === null) {
            return false;
        }

        return $latest > $since;
    }

    /**
     * Hitung mtime terbaru di seluruh path yang dipantau.
     *
     * Di-refresh dari cache, bukan dipanggil langsung per request.
     */
    private function scan(): ?int
    {
        $terbaru = null;

        foreach (self::WATCHED as $relatif) {
            $path = base_path($relatif);

            if (is_file($path)) {
                $terbaru = max($terbaru, (int) filemtime($path));

                continue;
            }

            if (! File::isDirectory($path)) {
                continue;
            }

            // `allFiles()` menerima wildcard, tapi wildcard di dalam path
            // yang mengandung `[` akan dianggap pola karakter. Karena itu
            // pola ditempatkan sebagai satu string utuh, bukan dirangkai.
            foreach (File::allFiles($path, '*.blade.php') as $file) {
                $terbaru = max($terbaru, (int) $file->getMTime());
            }
        }

        return $terbaru;
    }
}
