import axios from 'axios'
import { useUiStore } from '@/stores/uiStore'
import { showInvoiceLockModal } from '@/composables/useInvoiceLock'
import { tenantSlugAktif } from '@/utils/tenantSlug'
import { apiBase } from '@/utils/apiBase'

/*
 * Basis API.
 *
 * `VITE_API_BASE_URL` DIBACA, `VITE_BACKEND_URL` SENGAJA TIDAK.
 *
 * Kenapa: `VITE_BACKEND_URL` hanya target proxy Vite -- nilainya host
 * backend (`http://astaloka.test`), bukan tempat SPA ini berada. Kalau
 * dipakai jadi base URL axios, SPA yang dibuka lewat
 * `demo.astaloka.test:5173` akan mengirim request ke `astaloka.test`:
 * host pusat, tanpa subdomain. Identitas lokasi hilang, `X-Tenant-Slug`
 * jadi satu-satunya pembawa, dan branding kembali ke bawaan.
 *
 * Perhitungannya DIPINDAH ke `utils/apiBase.js`, supaya bentuk basis URL
 * sama di semua file. Sebelumnya file ini memakai nilainya apa adanya,
 * sementara `utils/storage.js` dan `utils/centralAxios.js` menambahkan
 * `/api` lagi -- sehingga satu nilai `VITE_API_BASE_URL` menghasilkan dua
 * basis URL berbeda, dan `.../api/api` muncul di produksi.
 *
 * Port ikut dibawa karena di development Vite (:5173) berbeda dari
 * backend (port 80) dan proxy meneruskannya. Di produksi keduanya sama,
 * jadi `port` kosong dan URL-nya bersih.
 */
const baseURL = apiBase()

const axiosInstance = axios.create({
  baseURL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: false,
  // Timeout generously: proses simpan tagihan involves insert jurnal + trigger
  // `amount`, which historically took 3-8 detik. Default axios (0 = no timeout)
  // meant a hung request left the UI spinning forever with no feedback; 60s is
  // far above normal latency but still bounded so the user eventually gets a
  // retryable error instead of an infinite spinner.
  timeout: 60000,
})

/*
 * `config.silentLoading` -- request yang TIDAK boleh menyentuh spinner global.
 *
 * Kenapa perlu: `uiStore.loading` tidak hanya menggerakkan progress bar di
 * `App.vue`, tapi juga dipakai sebagai `v-if="loading"` di banyak tabel untuk
 * menampilkan placeholder "Memuat data...". Request yang dipicu pengguna boleh
 * menaikkan spinner -- itu memang yang dia tunggu. Tapi request yang dipicu
 * sistem sendiri (polling latar, lihat `composables/useAutoRefresh.js`) akan
 * membuat seluruh chrome berkedip setiap 30 detik tanpa ada yang memicunya.
 *
 * Penanda ini dibaca di kedua interceptor secara simetris: kalau request tidak
 * menaikkan penghitung di sini, response juga tidak boleh menurunkannya.
 * Melewatkan hanya satu sisi membuat `activeRequests` tidak pernah kembali ke 0
 * dan progress bar macet menyala permanen.
 */
const isSilent = (config) => config?.silentLoading === true

/**
 * Apakah URL ini mengarah ke endpoint panel pusat.
 *
 * Panel pusat selalu membaca database pusat, jadi ia tidak punya lokasi --
 * dan `X-Tenant-Slug` di sana hanya menambah satu query yang hasilnya
 * pasti tidak terpakai.
 *
 * Dicek dari awal URL, bukan `includes()`: `includes('/central')` ikut
 * cocok untuk path yang sekadar memuat kata itu.
 */
function isCentralApi(url) {
  if (typeof url !== 'string') return false

  return url.startsWith('/central') || url.includes('/api/central')
}

axiosInstance.interceptors.request.use(
  (config) => {
    if (!isSilent(config)) {
      useUiStore().setLoading(true)
    }

    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    /*
     * `X-Tenant-Slug`: identitas lokasi untuk backend.
     *
     * Backend mencari lokasi dari host subdomain lebih dulu, baru dari
     * header ini (`TenantResolver::resolve()`). Di produksi subdomain sudah
     * menjawabnya dan header ini diabaikan. Di development lokal tidak ada
     * subdomain per lokasi -- semuanya dilayani `localhost:5173` -- jadi
     * header inilah satu-satunya pembawa identitas.
     *
     * Tanpa header di mode itu, backend melihat "tidak ada lokasi aktif"
     * untuk setiap request, dan `GET /api/branding` akan selalu menjawab
     * branding bawaan: teks dan warna hasil editan panel pusat tidak akan
     * pernah muncul, padahal datanya tersimpan rapi di database.
     *
     * Hanya untuk URL API. Request ke `api/central/*` TIDAK boleh membawa
     * ini -- panel pusat membaca database pusat, dan header lokasi membuat
     * ia ikut mencoba me-resolve lokasi yang tidak ada hubungannya.
     */
    const slug = tenantSlugAktif()
    if (slug && !isCentralApi(config.url)) {
      config.headers['X-Tenant-Slug'] = slug
    }

    return config
  },
  (error) => {
    if (!isSilent(error.config)) {
      useUiStore().setLoading(false)
    }
    return Promise.reject(error)
  },
)

axiosInstance.interceptors.response.use(
  (response) => {
    if (!isSilent(response.config)) {
      useUiStore().setLoading(false)
    }
    return response
  },
  (error) => {
    if (!isSilent(error.config)) {
      useUiStore().setLoading(false)
    }

    if (error.response && error.response.status === 401) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user_data')
      localStorage.removeItem('user_role')
      localStorage.removeItem('auth_expires_at')

      if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
        window.location.href = '/login?logout=expired'
      }
    }

    /*
     * 402 = tagihan langganan belum lunas.
     *
     * TIDAK boleh diperlakukan seperti 401 di atas. Akun masih aktif dan
     * token masih sah; yang bermasalah adalah tagihannya. Menghapus token
     * di sini akan melempar user ke /login, yang tidak menyelesaikan apa pun
     * -- tagihannya tetap ada, dan sekarang ia harus mengetik ulang password
     * untuk melihat popup yang sama.
     *
     * Sebaliknya, 402 yang lolos tanpa penanganan hanya menghasilkan toast
     * error sesaat lalu halaman tetap tampil dengan data basi. Yang
     * dipanggil di sini adalah penampil popup penguncian, yang tidak
     * bisa ditutup dan hanya menyisakan "Salin rekening" serta
     * "Keluar". Popup itu juga yang menghentikan user bernavigasi
     * ke halaman lain.
     *
     * `silent` tidak dipakai di sini: 402 adalah kondisi yang WAJIB
     * sampai ke user, jangan sampai ditelan oleh request senyap dari
     * polling latar.
     */
    if (error.response && error.response.status === 402) {
      showInvoiceLockModal(error.response.data?.data ?? error.response.data?.tagihan ?? null)
    }

    return Promise.reject(error)
  },
)

export default axiosInstance
