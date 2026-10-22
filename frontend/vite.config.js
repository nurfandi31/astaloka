import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  // Vite TIDAK mengisi `process.env` dari file `.env`. Nilai `VITE_*` hanya
  // tersedia lewat `import.meta.env` di dalam modul frontend. Karena itu
  // `server.proxy` yang membaca `process.env.VITE_BACKEND_URL` selalu
  // bernilai `undefined` dan jatuh ke fallback bawaan, bukan ke URL yang benar.
  //
  // `loadEnv` membaca `.env` sungguhan, jadi proxy dan frontend sekarang
  // pasti mengarah ke backend yang sama.
  const env = loadEnv(mode, process.cwd(), '')
  const backendTarget =
    env.VITE_PROXY_TARGET || env.VITE_BACKEND_URL || 'http://localhost/backend/public'

  /*
   * Host utama yang punya vhost di Laragon.
   *
   * Diambil dari `VITE_BACKEND_URL` supaya hanya ada satu sumber kebenaran:
   * nama domain yang sama dipakai untuk socket TCP dan juga Host yang
   * dikirim ke Apache. Kalau keduanya diisi terpisah, cepat atau lambat
   * keduanya berbeda, dan yang muncul cuma 404 dari Apache tanpa jejak.
   */
  const backendHost = new URL(backendTarget).hostname

  /*
   * Host yang layak diteruskan ke backend.
   *
   * Subdomain lokasi harus lewat apa adanya -- itu yang membawa identitas
   * tenant ke `TenantResolver::fromHost()`. Tapi host yang TIDAK punya
   * subdomain tidak boleh lewat apa adanya juga: `localhost` dan `127.0.0.1`
   * tidak terdaftar di vhost Laragon, jadi Apache menjawab 404.
   *
   * Dipakai lewat `configure` (lihat blok `proxy`) karena header Host
   * bergantung pada request -- `server.proxy[].headers` bersifat statis dan
   * hanya bisa nilainya untuk semua request sekaligus.
   */
  const hostForBackend = (req) => {
    // Header host bisa memuat port: `demo.astaloka.test:5173` -> `demo.astaloka.test`.
    const hostname = String(req.headers?.host || '')
      .replace(/:\d+$/, '')
      .trim()
      .toLowerCase()

    if (!hostname || ['localhost', '127.0.0.1', '::1', '[::1]'].includes(hostname)) {
      return backendHost
    }

    return hostname
  }

  /*
   * Terapkan `hostForBackend` ke tiap request yang lewat proxy.
   *
   * `Host` dan `X-Forwarded-Host` ditulis dengan nilai yang sama supaya
   * lapisan di depan Apache (kalau ada) dan `Request::getHost()` di Laravel
   * tidak berbeda pendapat soal tenant mana yang sedang dilayani.
   */
  const proxyRoute = () => ({
    target: backendTarget,
    // WAJIB false. `true` menimpa Host dengan milik `target`
    // (`astaloka.test`), dan itu menghapus identitas tenant tepat di tempat
    // backend membacanya.
    changeOrigin: false,
    configure: (proxy) => {
      proxy.on('proxyReq', (proxyReq, req) => {
        const host = hostForBackend(req)

        if (req.headers?.host) {
          proxyReq.setHeader('Host', host)
          proxyReq.setHeader('X-Forwarded-Host', host)
        }
      })
    },
  })

  return {
    plugins: [vue(), vueDevTools(), tailwindcss()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
        jquery: fileURLToPath(
          new URL(
            './node_modules/.pnpm/jquery@3.7.1/node_modules/jquery/dist/jquery.js',
            import.meta.url,
          ),
        ),
      },
      dedupe: ['jquery'],
    },
    optimizeDeps: {
      include: ['jquery', 'jstree'],
    },
    server: {
      // Dua dev server sempat berjalan bersamaan dari direktori yang sama
      // (5173 + 5174). Hanya satu yang memegang port 5173, dan browser yang
      // tersambung ke instance yang tidak memegang HMR socket akan gagal WS
      // lalu diam-diam memakai modul versi lama. `strictPort` membuat server
      // kedua gagal start dengan pesan jelas, bukan diam-diam pindah port.
      strictPort: true,

      /*
       * Host yang boleh dilayani oleh dev server ini.
       *
       * Vite memblokir host yang tidak dikenal sebagai proteksi DNS
       * rebinding: kalau server dev bisa diakses lewat host arbitrer,
       * situs mana pun bisa menulis skrip yang berjalan di origin
       * `localhost:5173` milikmu. That's the bug it protects against, and the
       * fix adalah menyebutkan host mana yang benar-benar dipakai.
       *
       * Di sini dibutuhkan karena aplikasi lokasi diakses lewat subdomain
       * (`demo.astaloka.test`), bukan `localhost` polos. Tanpa
       * daftar ini, seluruh subdomain lokasi akan diblokir dengan 403
       * "Blocked request" -- termasuk yang lewat proxy API.
       *
       * Pola `.test` cukup untuk development. Jangan pakai wildcard
       * `true` di produksi: dev server tidak boleh terbuka untuk host
       * apa pun.
       */
      allowedHosts: ['localhost', '127.0.0.1', 'astaloka.test', '.astaloka.test'],

      /*
       * WAJIB listen di `0.0.0.0`, bukan default Vite yang hanya `::1`.
       *
       * Default Vite hanya mendengarkan di IPv6 loopback, sedangkan
       * `demo.astaloka.test` di-resolve ke IPv4 `127.0.0.1` oleh hosts file
       * Windows. Akibatnya subdomain lokasi tidak bisa menjangkau dev server
       * sama sekali: `http://demo.astaloka.test:5173` connection refused,
       * sementara `http://localhost:5173` tetap jalan karena yang satu itu
       * memang jatuh ke `::1`.
       *
       * Gejalanya menyesatkan: development "jalan normal" di localhost, jadi
       * orang menyimpulkan subdomainnya salah konfigurasi atau belum
       * terdaftar di hosts file -- padahal keduanya sudah benar.
       *
       * `0.0.0.0`, bukan `::`: wildcard IPv6 di Windows sering tidak
       * menjangkau IPv4, dan hosts file di sini memakai IPv4.
       */
      host: '0.0.0.0',

      proxy: {
        '/api': proxyRoute(),
        '/storage': proxyRoute(),
      },
    },
  }
})