/**
 * URL absolut untuk file statis di backend (logo, lampiran, foto unduhan).
 *
 * Kenapa file ini ada
 * ==================
 *
 * Halaman laporan (kop surat, bukti cetak) tidak boleh memakai URL relatif
 * seperti `src="/storage/..."`, karena dokumen itu sering dibuka lewat
 * `window.print()` atau disimpan sebagai PDF. Keduanya membuka dokumen di
 * luar SPA, dan URL relatif ikut hilang begitu tidak ada lagi halaman yang
 * menyediakannya -- hasilnya logo kosong tanpa pesan error.
 *
 * Kenapa BUKAN `import.meta.env.VITE_BACKEND_URL`
 * ===============================================
 *
 * Nilai itu hanya jadi target proxy Vite, dan sengaja TIDAK boleh dipakai
 * di sini. Kalau dipakai, laporan yang dibuka dari
 * `demo.astaloka.test:5173` akan menarik logo dari `astaloka.test` -- host
 * pusat, tanpa subdomain. Untuk panel pusat itu memang benar, tapi untuk
 * aplikasi lokasi berarti logo diambil dari lokasi yang salah.
 *
 * Yang dipakai di sini adalah origin sendiri. Dia selalu benar di dua mode:
 *
 *   - produksi  : SPA dan backend satu host, jadi origin = backend
 *   - dev       : subdomain lokasi ikut terbawa, dan proxy meneruskannya
 *
 * Dan `VITE_API_BASE_URL` tetap dipakai kalau diisi, karena itu override
 * eksplisit -- kalau memang backend ada di mesin lain, memaksa semua
 * request kesana memang itu yang diinginkan.
 */

/**
 * Basis API absolut.
 *
 * Falls back ke origin sendiri kalau tidak ada konfigurasi, bukan ke
 * `localhost` atau `127.0.0.1`. Fallback ke localhost terlihat benar
 * selama satu mesin, dan langsung rusak begitu SPA dibuka lewat nama host
 * lain -- persis kondisi panel pusat dan subdomain lokasi.
 */
function detectApiBase() {
  if (import.meta.env.VITE_API_BASE_URL) {
    return import.meta.env.VITE_API_BASE_URL.replace(/\/$/, '') + '/api'
  }

  if (typeof window !== 'undefined') {
    const { protocol, hostname, port } = window.location
    const asal = port ? `${protocol}//${hostname}:${port}` : `${protocol}//${hostname}`

    return `${asal}/api`
  }

  return '/api'
}

export const API_BASE = detectApiBase()

/**
 * Origin backend tanpa suffix `/api`.
 *
 * Dipakai laporan untuk membentuk URL file. Backend dan proxy memang bisa
 * berbeda port dari SPA, jadi port ikut dibawa -- kecuali saat portnya port
 * default HTTP, yang tidak perlu ditulis ulang.
 */
export const API_ORIGIN = (() => {
  try {
    return new URL(API_BASE).origin
  } catch {
    return API_BASE.replace(/\/api\/?$/, '').replace(/\/$/, '')
  }
})()

/**
 * URL absolut untuk sebuah file di backend.
 *
 * `path` boleh sudah berupa URL lengkap -- lalu dikembalikan apa adanya,
 * supaya `storageUrl('https://cdn.example.com/logo.png')` tidak dirusak
 * oleh prefix origin.
 *
 * Path relatif dibersihkan dari `/` di depan supaya tidak jadi `//`, yang
 * browser baca sebagai protocol-relative URL dan diarahkan ke host lain.
 */
export const storageUrl = (path) => {
  const clean = String(path ?? '').trim()

  if (!clean) return API_ORIGIN
  if (/^(https?:)?\/\//i.test(clean) || /^data:/i.test(clean)) return clean

  return `${API_ORIGIN}/${clean.replace(/^\/+/, '')}`
}

export default storageUrl
