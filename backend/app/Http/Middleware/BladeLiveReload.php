<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Live reload untuk halaman Blade saat development.
 *
 * Masalah yang diselesaikan
 * =========================
 * Halaman depan (`resources/views/landing.blade.php`) dirender ulang dari nol
 * setiap kali diminta, jadi isinya SELALU terbaru -- begitu browser
 * meminta. Yang tidak ada adalah pemberitahuan ke browser bahwa "kode baru
 * sudah ada, silakan refresh sendiri".
 *
 * Aplikasi Vue tidak menghadapi ini karena Vite punya HMR: dev server menahan
 * koneksi WebSocket dan menyuruh browser reload saat modul berubah. Blade tidak
 * punya apa-apa, dan mengedit 2.300 baris marketing page lalu menekan Ctrl+R
 * adalah pekerjaan yang sangat mudah terlewat -- karena halaman yang tampil
 * masih "benar", hanya saja bukan versi yang baru diedit.
 *
 * Cara kerjanya
 * =============
 * Suntikan kecil di akhir `</body>` yang setiap ~1 detik bertanya ke
 * `__blade-watch` apakah ada file Blade yang lebih baru dari waktu halaman
 * dirender. Kalau ya, `location.reload()`.
 *
 * Yang SENGAJA tidak dilakukan
 * ============================
 * 1. TIDAK aktif di produksi. Seluruh middleware ini adalah `no-op` di luar
 *    `environment('local')`. Endpoint yang menyertainya juga diblokir di
 *    produksi -- bukan hanya disembunyikan, karena `config('app.env')` bisa
 *    keliru diset di server sungguhan.
 * 2. TIDAK melaporkan path file ke browser. Balasan hanya berisi "ya/tidak"
 *    plus mtime. Kalau sempat membocorkan nama file, itu memberi peta direktori
 *    ke siapa pun yang bisa menyentuh endpoint ini.
 * 3. TIDAK menyuntik ke response JSON. Yang dilewatkan hanya HTML; redirect dan
 *    JSON dari grup `web` dilewatkan apa adanya.
 * 4. TIDAK memakai WebSocket. Endpointnya tanpa state dan bisa ditangani
 *    infrastruktur apa pun yang ada. Reload cukup butuh polling 1 detik, yang
 *    biayanya jauh lebih kecil dibanding menyiapkan channel push.
 *
 * Scope file yang dipantau sengaja dibuat sempit: hanya Blade dan config
 * tenancy. Mengawasi seluruh `resources/` berarti perubahan pada aset yang
 * tidak dipakai halaman ini (mis. view cetak PDF) tetap memicu reload
 * yang tidak perlu.
 */
class BladeLiveReload
{
    /** Token acak per proses: nama endpoint berubah tiap `artisan serve`. */
    private const TOKEN_PATH = '__blade-watch';

    /** Versi skrip. Naikkan kalau isi script berubah dan browser perlu memuat ulang. */
    private const SCRIPT_VERSION = '1';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || ! str_contains($content, '</body>')) {
            return $response;
        }

        $response->setContent(str_replace('</body>', $this->script()."\n</body>", $content));

        // Tanpa ini, browser bisa saja menyimpan halaman lama dan menghapusnya
        // dari cache sesuai header default. Polling berikutnya akan membandingkan
        // mtime dengan versi yang tersimpan itu, bukan dengan yang benar-benar
        // dirender -- dan reload tidak pernah terjadi.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Apakah response ini layak disuntik.
     *
     * Syaratnya sengaja berlapis: `local` dulu, baru cek tipe response. Cek
     * tipe dulu akan membuka pintu ke endpoint JSON dan redirect yang hanya
     * kebetulan lewat `web` group.
     */
    private function shouldInject(Request $request, Response $response): bool
    {
        if (! $this->isLocalEnvironment()) {
            return false;
        }

        if (! $request->isMethod('GET')) {
            return false;
        }

        // Redirect tidak punya body HTML untuk disuntik.
        if (! $response instanceof Response || $response->isRedirection()) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html');
    }

    /**
     * `app()->environment('local')` sengaja dipakai, bukan `config('app.debug')`.
     *
     * Keduanya sering diset `true` di server development yang kebetulan publik,
     * tapi hanya `local` yang bisa dipertanggungjawabkan. Kalau environment
     * production punya `APP_DEBUG=true` (mis. karena masih masa development),
     * tidak ada alasan untuk mengaktifkan reload otomatis yang bisa dipanggil
     * siapa pun dari internet publik.
     */
    private function isLocalEnvironment(): bool
    {
        return app()->environment('local') && ! app()->runningUnitTests();
    }

    /** Path endpoint yang membalas pertanyaan "ada perubahan?". */
    public static function watchPath(): string
    {
        return self::TOKEN_PATH;
    }

    private function script(): string
    {
        $endpoint = '/'.self::TOKEN_PATH;
        $version = self::SCRIPT_VERSION;

        return <<<HTML
<script>
/*
 * Live reload Blade -- lihat App\\Http\\Middleware\\BladeLiveReload.
 *
 * Dirancang gagal diam: kalau endpoint tidak ada (server belum restart, atau
 * middleware-nya sengaja dimatikan), polling berhenti setelah beberapa
 * kegagalan berturut-turut. Tanpa itu, halaman development akan menembak
 * endpoint 404 selamanya tanpa terlihat why-nya, dan itu lebih buruk daripada
 * tidak ada fiturnya.
 */
(function () {
    var ENDPOINT = '{$endpoint}';
    var VERSION = '{$version}';
    var INTERVAL = 1000;
    var MAX_GAGAL = 3;

    var gagal = 0;
    var berhenti = false;

    function berhentiPermanen() {
        berhenti = true;
        clearInterval(timer);
    }

    function tanya() {
        if (berhenti) return;
        if (document.hidden) return;

        fetch(ENDPOINT + '?v=' + VERSION, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        })
            .then(function (r) {
                if (!r.ok) throw new Error('status ' + r.status);

                return r.json();
            })
            .then(function (body) {
                if (berhenti) return;

                gagal = 0;

                // `changed` ditegakkan di server lewat perbandingan mtime.
                // Browser tidak pernah tahu nama file apa pun yang berubah.
                if (body && body.changed) {
                    location.reload();
                }
            })
            .catch(function () {
                gagal++;

                if (gagal >= MAX_GAGAL) berhentiPermanen();
            });
    }

    var timer = setInterval(tanya, INTERVAL);

    // `document.hidden` di atas sudah menahan request saat tab tidak terlihat,
    // tapi timer tetap berjalan. Mematikannya sepenuhnya lebih hemat.
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearInterval(timer);
        } else if (!berhenti) {
            timer = setInterval(tanya, INTERVAL);
            tanya();
        }
    });
})();
</script>
HTML;
    }
}
