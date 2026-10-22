<?php

namespace App\Support;

use App\Models\Central\Tenant;
use Illuminate\Support\Carbon;

/**
 * Angka-angka untuk bagian "sudah dipakai siapa" di halaman depan.
 *
 * Kenapa kelas ini ada
 * ====================
 *
 * Landing page punya aturan kejujuran yang tegas (lihat catatan panjang di
 * `resources/views/landing.blade.php`, section pratinjau): angka yang bukan
 * data nyata tidak boleh muncul sebagai klaim. Semua angka mockup di halaman
 * itu memakai class `mock` supaya jelas bukan data, dan bagian ini tidak
 * memuat mockup sama sekali -- jadi apa yang tampil HARUS berasal dari
 * database pusat.
 *
 * Yang dikembalikan di sini sudah berupa angka final, bukan query builder.
 * Pemanggilnya adalah Blade, yang tidak punya tempat untuk bercabang logika --
 * dan `@if` bertingkat di dalam template jauh lebih sulit dibaca daripada satu
 * pemanggilan method yang namanya sudah menjelaskan.
 *
 * Kenapa bagian ini tidak pernah menyebut nama lokasi
 * ===================================================
 *
 * Dua alasan, dan keduanya menutup diri sendiri, bukan sekadar preferensi:
 *
 *   1. Lokasi belum pernah menyetujui namanya tampil di halaman publik. Nama
 *      usaha adalah keputusan pemilik lokasi, bukan milik ASTALOKA -- dan
 *      registry pusat tidak punya kolom untuk "boleh tampil di halaman depan
 *      atau tidak".
 *   2. Domain lokasi adalah peta. Satu host yang lolos ke halaman publik
 *      berarti peta menuju subdomain yang bisa ditebak orang -- dan itu
 *      membuat halaman marketing berubah menjadi alat pemetaan.
 *
 * Jumlah, sebaliknya, tidak pernah membuka pintu ke identitas siapa pun.
 * "12 lokasi memakai ASTALOKA" memang hal yang ingin diketahui calon pembeli;
 * nama di antaranya belum tentu ingin ikut disebut.
 *
 * Kenapa TIDAK memakai cache
 * =========================
 *
 * `CACHE_STORE` di `.env` adalah `database`, dan cache store itu menulis ke
 * koneksi `database.default` -- yaitu database LOKASI. Halaman depan berada di
 * domain pusat, tidak punya lokasi aktif, jadi `database.default` di sana masih
 * menunjuk ke `astaloka_lokasi` (atau nama lain dari `.env`).
 *
 * Artinya: satu `Cache::remember()` di halaman ini akan menulis baris ke
 * database milik salah satu pelanggan setiap kali pengunjung membuka tab kedua.
 * Landing page harus bisa dibaca tanpa menyentuh database lokasi sama
 * sekali -- bukan hanya soal performance, tapi karena menulis ke sana dari
 * domain pusat tidak pernah punya alasan yang benar.
 *
 * Dua query aggregate di tabel yang hanya segede daftar pelanggan juga memang
 * tidak sebanding dengan masalah yang diselesaikan cache itu.
 */
class StatistikPengguna
{
    /**
     * Angka yang boleh ditampilkan di halaman depan.
     *
     * Semua nilai sudah final: `lokasi` dan `sektor` integer (bukan string,
     * supaya Blade bisa langsung dibandingkan dengan `>`), `sejak` integer
     * tahun atau `null` kalau belum ada lokasi yang pernah dipakai, dan
     * `pertama` boolean yang menyatakan apakah angkanya masih satu.
     *
     * `lokasi` menghitung tenant AKTIF saja, bukan seluruh baris. Lokasi yang
     * dinonaktifkan tidak dilayani lagi -- menghitungnya membuat halaman
     * mengklaim ada orang yang sudah tidak memakai ASTALOKA.
     *
     * @return array{lokasi:int, sektor:int, sejak:?int, pertama:bool}
     */
    public static function hitung(): array
    {
        $aktif = Tenant::query()->where('is_active', true);

        $lokasi = (int) (clone $aktif)->count();

        return [
            'lokasi' => $lokasi,

            /*
             * Yang dihitung hanya nilai yang benar-benar terisi.
             *
             * `sektor` nullable dan belum semua lokasi mengisinya, sedangkan
             * `COUNT(DISTINCT)` tetap menghitung NULL sebagai satu kelompok.
             * Tanpa filter di bawah, lokasi yang belum mengisi sektor akan
             * menambah angka "jenis usaha terlayani" tanpa membawa informasi
             * apa pun -- dan angkanya jadi lebih besar dari kenyataan, bukan
             * lebih kecil.
             */
            'sektor' => (int) (clone $aktif)
                ->whereNotNull('sektor')
                ->where('sektor', '!=', '')
                ->distinct()
                ->count('sektor'),

            /*
             * `min()` adalah aggregate, jadi PDO mengembalikannya sebagai
             * string mentah -- bukan objek Carbon, apa pun cast yang ada di
             * model. `Carbon::parse` menutup dua kemungkinan bentuk nilainya,
             * `Y-m-d` maupun timestamp, tergantung driver yang dipakai.
             */
            'sejak' => (function () use ($aktif) {
                $terlama = (clone $aktif)->whereNotNull('tgl_pakai')->min('tgl_pakai');

                return $terlama ? (int) Carbon::parse($terlama)->format('Y') : null;
            })(),

            /*
             * Apakah angkanya masih satu. Ini dipakai untuk MENGHINDARI
             * bunyi kalimat yang membuat angka satu terdengar sepi --
             * bukan untuk mengubah angkanya.
             *
             * "1 lokasi memakai ASTALOKA" dibaca sebagai "hanya satu",
             * sedangkan "lokasi pertama" dibaca sebagai pencapaian. Yang
             * berubah kalimatnya saja; angkanya tetap 1, dan test yang
             * mengunci kesesuaian angka dengan database tetap berlaku.
             *
             * Dihitung di sini, bukan di Blade, supaya tidak ada satu pun
             * angka di section ini yang bisa ditulis ulang di template.
             */
            'pertama' => $lokasi === 1,
        ];
    }

    /**
     * Kalimat yang menyambung angka dengan artinya.
     *
     * Dua bentuk, dan pemisahnya bukan Tata Bahasa -- melainkan cara angka
     * satu dibaca orang.
     *
     * "1 lokasi sudah memakai ASTALOKA" berakhir di kata "memakai", dan
     * kata itu selalu punya konteks lain di kepalanya: kalau tidak di sini,
     * berarti di tempat lain. Pembaca yang belum kenal ASTALOKA
     * menyimpulkan "baru satu" -- dan seluruh section ini justru sedang
     * membangun kredibilitas, bukan memicu keraguan.
     *
     * Bentuk kedua tidak menyebut jumlahnya sama sekali. "Lokasi pertama
     * yang memakai ASTALOKA" menyatakan hal yang sama persis -- satu lokasi,
     * belum ada yang kedua -- tapi menyebutnya sebagai POSISI, bukan
     * sebagai kekurangan. Angka "1" di sebelahnya tetap tampil apa adanya,
     * jadi tidak ada yang disembunyikan.
     *
     * Bentuk kedua hanya dipakai kalau memang angkanya satu. Mulai ada
     * dua lokasi, kalimatnya kembali ke bentuk jamak -- "2 lokasi sudah
     * memakai" adalah jawaban yang benar dan tidak perlu dipoles.
     *
     * Angka nol TIDAK memakai bentuk kedua: belum ada lokasi yang memakai
     * sama sekali, dan "lokasi pertama yang memakai" akan mengklaim ada
     * yang belum ada. Untuk nol, kalimat jamak apa adanya adalah
     * satu-satunya yang jujur.
     */
    public static function kalimatLokasi(int $jumlah): string
    {
        if ($jumlah === 1) {
            return 'lokasi pertama yang memakai ASTALOKA untuk mencatat operasional dan keuangan usaha.';
        }

        return 'lokasi sudah memakai ASTALOKA untuk mencatat operasional dan keuangan usaha.';
    }

    /**
     * Judul section, mengikuti keadaan angkanya.
     *
     * Judulnya ikut berubah karena yang sedang dibahas adalah
     * KREDIBILITAS, dan kredibilitas tidak dibangun dengan kalimat yang
     * bertentangan dengan datanya.
     *
     * "Sudah dipakai oleh banyak usaha" di atas angka satu adalah klaim
     * yang tidak ada pendukungnya -- dan orang tidak perlu menghitung
     * untuk merasa itu berlebihan.
     *
     * Sebaliknya, judul yang jujur untuk angka satu bisa terdengar lemah
     * kalau habis kata "satu". Karena itu judulnya menyebut proses, bukan
     * kebetulan: mulai dari satu lokasi, lalu bertambah. Kalimat kedua
     * hanya muncul kalau memang angkanya sudah bertambah.
     *
     * Nol memakai judul kedua. Belum ada lokasi yang memakai sama sekali,
     * dan "dimulai dari satu lokasi" akan menjanjikan sesuatu yang belum
     * terjadi.
     *
     * @param int $jumlah angka `lokasi` yang sudah dihitung
     */
    public static function judulSection(int $jumlah): string
    {
        return $jumlah === 1
            ? 'Dimulai dari satu lokasi'
            : 'Sudah dipakai oleh banyak usaha';
    }
}
