import axios from 'axios'
import { useUiStore } from '@/stores/uiStore'

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

const baseURL = (() => {
  if (import.meta.env.VITE_API_BASE_URL)
    return `${import.meta.env.VITE_API_BASE_URL.replace(/\/$/, '')}/api`
  if (import.meta.env.VITE_BACKEND_URL)
    return `${import.meta.env.VITE_BACKEND_URL.replace(/\/$/, '')}/api`
  if (typeof window !== 'undefined') {
    const { protocol, hostname } = window.location
    return `${protocol}//${hostname}/api`
  }
  return '/api'
})()

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

centralApi.interceptors.request.use(
  (config) => {
    const uiStore = useUiStore()
    uiStore.setLoading(true)

    const token = localStorage.getItem(CENTRAL_TOKEN_KEY)
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    return config
  },
  (error) => {
    useUiStore().setLoading(false)
    return Promise.reject(error)
  },
)

centralApi.interceptors.response.use(
  (response) => {
    useUiStore().setLoading(false)
    return response
  },
  (error) => {
    useUiStore().setLoading(false)

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
