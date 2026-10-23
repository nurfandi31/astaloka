import axios from 'axios'
import { useUiStore } from '@/stores/uiStore'
import { apiBase } from '@/utils/apiBase'

/**
 * Axios KHUSUS panel pusat (ASTA BRATA).
 *
 * Kenapa tidak pakai instance di `@/utils/axios.js`?
 *
 * Token pusat dan token lokasi memakai tabel `personal_access_tokens` dengan
 * nama sama di DUA database berbeda, dan keduanya autoincrement dari 1. Kalau
 * keduanya berbagi `auth_token` di localStorage, sesi login di lokasi bisa
 * menimpa sesi pusat (atau sebaliknya) hanya dengan logout lalu login lagi.
 * Pemisahan ini bukan kerapian -- ia yang memastikan revoke token di satu sisi
 * tidak ikut mencabut akses di sisi lain.
 *
 * Instans ini TIDAK mengirim `X-Tenant-Slug`. Request ke `api/central/*`
 * memang dibaca dari database pusat oleh middleware ResolveTenant, jadi
 * header lokasi justru akan membuat frontend pusat ikut mencoba resolve lokasi.
 */

/*
 * Basis API panel pusat.
 *
 * Bentuknya sama dengan `utils/axios.js`, dan `VITE_BACKEND_URL` juga
 * SENGAJA TIDAK dipakai di sini.
 *
 * Untuk panel pusat hasilnya kebetulan sama -- host pusat memang yang
 * benar. Tapi bukan karena nilai itu dibaca: kalau dibiarkan, begitu panel
 * dibuka lewat `localhost:5173`, base URL akan memakai host backend yang
 * tercatat di `.env`, dan dua nilai itu bisa berbeda tanpa ada yang
 * memberitahu.
 *
 * `ResolveTenant` sudah memaksa path `api/central/*` ke database pusat
 * apa pun host-nya, jadi ini bukan celah keamanan -- tapi tetap sumber
 * bug yang mahal: respons 404 dari host yang salah jauh lebih sulit ditebak
 * penyebabnya daripada "salah subdomain".
 *
 * Perhitungannya memakai `utils/apiBase.js`, sama dengan `utils/axios.js`.
 * Sebelumnya file ini menambahkan `/api` ke `VITE_API_BASE_URL` sedangkan
 * `utils/axios.js` tidak -- jadi panel pusat dan aplikasi lokasi menembak
 * dua basis URL yang berbeda dari nilai yang sama. Di produksi
 * `VITE_API_BASE_URL` sudah berakhiran `/api`, sehingga hasilnya
 * `https://astaloka.siupk.com/api/api` dan seluruh request panel pusat
 * dibalas 404.
 */
const baseURL = apiBase()

const centralApi = axios.create({
  baseURL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: false,
  // Provisioning lokasi baru menjalankan seluruh migration tenant di dalam
  // satu request. Di mesin lokal itu bisa 30-120 detik, jadi batas atasnya
  // dibuat jauh lebih longgar daripada instance lokasi (60 detik) yang
  // hanya menjalankan query biasa.
  timeout: 180000,
})

export const CENTRAL_TOKEN_KEY = 'central_token'
export const CENTRAL_EXPIRES_KEY = 'central_expires_at'
export const CENTRAL_USER_KEY = 'central_user'

/*
 * `config.silentLoading` -- request yang TIDAK boleh menyentuh spinner global.
 *
 * Sama seperti di `utils/axios.js`: `uiStore.loading` juga menggerakkan
 * progress bar di `App.vue`, dan panel pusat membacanya sebagai
 * `v-if="loading"` di tabel lokasi/tagihan. Polling latar di
 * `composables/useAutoRefresh.js` harus lewat tanpa menaikkan spinner, atau
 * chrome akan berkedip sendiri tiap 30 detik.
 *
 * Penanda harus dibaca simetris di kedua interceptor: kalau request tidak
 * menaikkan `activeRequests`, response juga tidak boleh menurunkannya --
 * kalau tidak, penghitungnya tidak pernah balik ke 0 dan progress bar macet.
 */
const isSilent = (config) => config?.silentLoading === true

centralApi.interceptors.request.use(
  (config) => {
    if (!isSilent(config)) {
      useUiStore().setLoading(true)
    }

    const token = localStorage.getItem(CENTRAL_TOKEN_KEY)
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
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

centralApi.interceptors.response.use(
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

    const status = error.response?.status

    // 403 = sudah login tapi bukan superadmin. Ini kondisi yang perlu
    // dibedakan dari 401: memaksa logout di 403 akan membuang sesi yang
    // sebenarnya masih valid, dan user tidak akan bisa membedakan
    // "sesi saya habis" dari "akun saya tidak punya akses".
    if (status === 401) {
      clearCentralSession()

      if (typeof window !== 'undefined' && window.location.pathname !== '/central/login') {
        window.location.href = '/central/login?expired=1'
      }
    }

    return Promise.reject(error)
  },
)

/** Hapus seluruh jejak sesi panel pusat di browser. */
export function clearCentralSession() {
  localStorage.removeItem(CENTRAL_TOKEN_KEY)
  localStorage.removeItem(CENTRAL_EXPIRES_KEY)
  localStorage.removeItem(CENTRAL_USER_KEY)
}

/**
 * Sesi pusat dianggap kedaluwarsa setelah 8 jam, sama seperti sesi lokasi.
 * Token Sanctum di sisi server tidak punya masa berlaku, jadi batas ini murni
 * sisi klien untuk membedakan "login lagi" dari "paksa login ulang".
 */
export function isCentralSessionValid() {
  const token = localStorage.getItem(CENTRAL_TOKEN_KEY)
  if (!token) return false

  const expiresAt = localStorage.getItem(CENTRAL_EXPIRES_KEY)
  if (expiresAt && Date.now() > parseInt(expiresAt, 10)) return false

  return true
}

/** Ambil user panel pusat yang tersimpan, atau null. */
export function getCentralUser() {
  try {
    return JSON.parse(localStorage.getItem(CENTRAL_USER_KEY) || 'null')
  } catch {
    return null
  }
}

export default centralApi
