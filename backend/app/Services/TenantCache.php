<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Cache dengan dimensi lokasi.
 *
 * Kenapa perlu
 * ------------
 *
 * Kunci cache lama (`DashboardController`, `GenerateOverdueTransactions`)
 * hanya berisi tanggal atau user id:
 *
 *     'dashboard:stats:global:2026-10-20'
 *     "dashboard:finance:2026:10"
 *     'overdue_gen_run_summary'
 *
 * Di aplikasi satu-lokasi (pamsides-v2) itu aman. Di ASTALOKA tidak: semua
 * lokasi memakai satu cache store yang sama, jadi kunci tersebut milik SIAPA
 * pun dan bukan milik satu lokasi.
 *
 * Yang terjadi sekarang
 * ---------------------
 *
 * 1. `CACHE_STORE=database` dengan `DB_CACHE_CONNECTION` kosong membuat
 *    `CacheManager` resolve koneksi TANPA NAMA -- artinya koneksi default
 *    saat store itu pertama kali dibuat. `TenantManager::activate()` hanya
 *    memanggil `DB::purge('tenant')`; store cache tidak pernah dibangun
 *    ulang. Storepun terkunci ke database lokasi pertama yang menyentuh
 *    cache di proses itu.
 *
 * 2. Begitu `CACHE_STORE` diganti ke redis/memcached/file -- satu baris
 *    `.env` -- seluruh lokasi berbagi satu keyspace dan lokasi B membaca
 *    statistik, grafik pendapatan, dan nama pelanggan milik lokasi A.
 *
 * 3. Kunci berbasis user id (`auto_gen_overdue_..._{userId}`) bertabrakan
 *    begitu dua lokasi punya user dengan id sama. Seeder demo memakai
 *    email yang sama di semua lokasi, jadi `admin@gmail.com` = id 1 di
 *    mana-mana. Lokasi B lalu menyimpulkan proses sudah jalan dan SKIP
 *    generate tunggakannya sendiri -- tanpa error apa pun.
 *
 * Bentuk kunci
 * ------------
 *
 *     t{tenantId}:v{versi}:{nama-kunci}
 *
 * Bagian `versi` bergerak naik setiap kali data lokasi berubah secara
 * massal. Kunci lama tidak dihapus satu per satu -- ia menjadi tidak
 * terjangkau dan hilang sendiri bersama TTL-nya. Ini dipakai sebagai
 * pengganti pemindaian wildcard, yang tidak bisa dilakukan di semua cache
 * store (`file` misalnya tidak bisa mencari pola) dan yang tetap mahal
 * walau bisa.
 */
class TenantCache
{
    public function __construct(private TenantManager $manager) {}

    /**
     * Id lokasi aktif, atau `pusat` untuk database pusat.
     *
     * `pusat` juga dipakai ketika tidak ada lokasi aktif, supaya kunci tetap
     * deterministik dan tidak mungkin bertabrakan dengan lokasi manapun --
     * id lokasi berupa angka, tidak mungkin sama dengan `pusat`.
     */
    public function scope(): string
    {
        return (string) ($this->manager->current()?->getKey() ?? 'pusat');
    }

    /**
     * Nomor versi cache untuk lokasi aktif.
     *
     * Disimpan dengan TTL panjang supayanomor itu tidak hilang dan
     * memungkinkan nomor kembali ke 1. Bila nomor hilang, kunci lama yang
     * masih hidup (mis. TTL 1 jam) akan kembali terbaca -- jadi TTL di sini
     * sengaja jauh lebih besar daripada TTL cache mana pun yang dipakai.
     */
    public function version(): int
    {
        return (int) Cache::get($this->versionKey(), 1);
    }

    private function versionKey(): string
    {
        return 't'.$this->scope().':ver';
    }

    /**
     * Kunci cache milik lokasi aktif, lengkap dengan versi saat ini.
     */
    public function key(string $key): string
    {
        return 't'.$this->scope().':v'.$this->version().':'.$key;
    }

    /**
     * Naikkan versi cache lokasi aktif.
     *
     * Dipanggil setelah operasi yang mengubah basis data lokasi secara
     * massal -- generate piutang, rebuild tabel `amount`, impor data --
     * supaya laporan berikutnya tidak membaca angka yang sudah basi.
     *
     * Operasi ini sengaja memakai `forever()` membaca lalu menambah satu,
     * bukan `increment()`: `increment()` hanya tersedia di store yang
     * mendukung counter atomik (`redis`, `memcached`), sedangkan store
     * bawaan `database` TIDAK memilikinya dan akan melempar exception --
     * membuat cache justru menjadi sumber pemadaman (500) di produksi.
     * Seriesi satu sudah cukup: prose penulisan di sini selalu berurutan
     * di dalam satu request.
     */
    public function bump(): void
    {
        Cache::forever($this->versionKey(), $this->version() + 1);
    }

    public function remember(string $key, \DateTimeInterface|\DateInterval|int $ttl, \Closure $callback): mixed
    {
        return Cache::remember($this->key($key), $ttl, $callback);
    }

    public function put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int $ttl): mixed
    {
        return Cache::put($this->key($key), $value, $ttl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->key($key), $default);
    }

    public function forget(string $key): bool
    {
        return Cache::forget($this->key($key));
    }

    /**
     * Kunci yang TIDAK ikut naik versinya.
     *
     * Untuk flag kendali, bukan data turunan.
     *
     * Ini penting. Flag "generate piutang bulan ini sudah dijalankan" dan
     * mutex yang menggagalkan generate keduanya dibaca lewat versi. The
     * bump dipanggil tepat setelah generate selesai, jadi kalau flag memakai
     * kunci berversi, flag itu tertinggal satu versi di belakang dan tidak
     * pernah terbaca lagi. Gejalanya: generate jalan ulang setiap kali
     * endpoint dipanggil, karena "sudah pernah jalan" selalu dianggap belum.
     *
     * Data turunan (statistik dashboard, grafik keuangan) justru harus ikut
     * naik versi, supaya tidak ada yang membaca angka lama.
     */
    public function flagKey(string $key): string
    {
        return 't'.$this->scope().':f:'.$key;
    }

    public function flagGet(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->flagKey($key), $default);
    }

    public function flagPut(string $key, mixed $value, \DateTimeInterface|\DateInterval|int $ttl): mixed
    {
        return Cache::put($this->flagKey($key), $value, $ttl);
    }

    public function flagForget(string $key): bool
    {
        return Cache::forget($this->flagKey($key));
    }

    /**
     * Naikkan versi cache satu lokasi tertentu tanpa harus mengaktifkannya.
     *
     * Dipakai superadmin yang mengubah data satu lokasi lewat `runFor()` --
     * di sana lokasi AKTIF memang lokasi itu, jadi `bump()` biasa sudah
     * cukup. Fungsi ini untuk kasus di mana lokasi yang perlu diinvalidasi
     * bukan lokasi yang sedang aktif.
     */
    public function bumpFor(int|string $tenantId): void
    {
        Cache::forever('t'.$tenantId.':ver', (int) Cache::get('t'.$tenantId.':ver', 1) + 1);
    }
}
