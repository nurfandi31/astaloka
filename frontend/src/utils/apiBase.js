/**
 * Basis URL API, ditentukan di SATU tempat.
 *
 * Kenapa file ini ada
 * ==================
 *
 * Dulu setiap file menghitung sendiri dari `import.meta.env.VITE_API_BASE_URL`,
 * dan tidak semuanya menghitungnya dengan cara yang sama:
 *
 *   - `utils/axios.js`               -> memakai nilainya apa adanya
 *   - `services/branding.service.js` -> memakai nilainya apa adanya
 *   - `utils/storage.js`             -> nilainya + '/api'
 *   - `utils/centralAxios.js`        -> nilainya + '/api'
 *
 * Dua dari empat menambahkan `/api`, dua lainnya tidak. Nilai yang sama
 * diberikan ke keempatnya menghasilkan dua basis URL yang BERBEDA --
 * dan semuanya kelihatan benar di log.
 *
 * BUG YANG DISEBABKANNYA
 * ======================
 *
 * `deploy.yml` menyuntik `VITE_API_BASE_URL=https://astaloka.siupk.com/api`
 * (sudah termasuk `/api`). Dua file yang menambahkan `/api` lagi
 * menghasilkan:
 *
 *     https://astaloka.siupk.com/api/api
 *
 * Yang terjadi di produksi:
 *
 *   - Semua request axios aplikasi LOKASI dan PANEL PUSAT.offset ke
 *     `/api/api/...`, dan nginx menjawab 404 -- karena tidak ada route
 *     yang dimulai dengan `/api/api`.
 *   - Semua URL file (logo, foto meter, PDF unduhan) ikut rusak, karena
 *     `API_ORIGIN` dihitung dari basis yang sudah salah.
 *   - Halaman login panel pusat TERLIHAT benar, karena ia memakai base
 *     URL yang tidak menambahkan `/api` lagi. Gejalanya muncul sebagai
 *     "halaman terbuka tapi semua data kosong" -- bukan sebagai error
 *     yang menunjuk ke penyebabnya.
 *
 * ATURAN SEKARANG
 * ================
 *
 * `VITE_API_BASE_URL` adalah ORIGIN backend, TANPA suffix `/api`:
 *
 *     VITE_API_BASE_URL=https://astaloka.siupk.com
 *
 * Basis API (dengan `/api`) dihitung di sini, satu kali, dan dipakai
 * semua file. Kalau nilainya sudah berakhiran `/api` -- mis. environment
 * lama yang menyetelnya begitu -- suffix itu TIDAK ditambahkan lagi,
 * sehingga bentuk salah itu tidak menghasilkan `/api/api`.
 *
 * Kosong = pakai origin sendiri (`window.location`), supaya subdomain
 * lokasi yang sedang dibuka ikut terbawa. Ini yang membuat SPA bisa
 * dilayani dari host mana saja tanpa build ulang.
 */

/**
 * Nilai `VITE_API_BASE_URL` dari environment build.
 *
 * Dibungkus try/catch karena `import.meta.env` hanya ada di Vite. Di Node
 * (unit test) objek itu tidak ada, dan membaca propertinya langsung akan
 * melempar `TypeError`. Formulirnya tetap ditulis apa adanya supaya Vite
 * bisa menggantinya dengan literal saat build.
 */
function configuredBaseUrl() {
  try {
    return import.meta.env.VITE_API_BASE_URL || ''
  } catch {
    return ''
  }
}

/**
 * Buang satu suffix `/api` di akhir URL.
 *
 * Dipakai supaya nilai yang sudah berbentuk `.../api` tidak jadi
 * `.../api/api`. Hanya SATU suffix yang dibuang: `.../api/api` yang
 * sengaja dibuat tidak akan menjadi `.../api`.
 */
function buangSuffixApi(url) {
  return url.replace(/\/api\/?$/i, '')
}

/**
 * Asal backend, tanpa suffix `/api`.
 *
 * Ini yang dipakai untuk membentuk URL file (logo, lampiran, PDF):
 * file berada di root backend, bukan di dalam folder `api`.
 *
 * Parameter `configured` ada supaya unit test bisa menguji bentuk yang
 * dipakai produksi tanpa harus menyetel environment build.
 */
export function apiOrigin(configured = configuredBaseUrl()) {
  if (configured) {
    return buangSuffixApi(configured.replace(/\/$/, ''))
  }

  if (typeof window !== 'undefined') {
    const { protocol, hostname, port } = window.location
    const asal = port ? `${protocol}//${hostname}:${port}` : `${protocol}//${hostname}`

    return asal
  }

  return ''
}

/**
 * Basis API lengkap, dengan suffix `/api`.
 *
 * Semua request axios dan semua URL file dibangun dari sini.
 */
export function apiBase(configured) {
  return `${apiOrigin(configured)}/api`
}
