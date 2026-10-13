import api from '@/utils/centralAxios'
import {
  CENTRAL_TOKEN_KEY,
  CENTRAL_EXPIRES_KEY,
  CENTRAL_USER_KEY,
  clearCentralSession,
} from '@/utils/centralAxios'

/**
 * Central Service - API untuk Panel Pusat ASTA BRATA.
 *
 * Semua endpoint di sini menuju ke database PUSAT (registry lokasi, user
 * superadmin, tagihan langganan), bukan ke database lokasi mana pun. Jangan
 * menambahkan endpoint operasional di sini.
 *
 * Auth: superadmin. Sesi disimpan di `central_token`, terpisah dari
 * `auth_token` milik lokasi.
 */
export const centralService = {
  /** Ringkasan angka untuk dashboard pusat. */
  async getOverview() {
    return await api.get('/central/overview')
  },
}

/**
 * Audit Service - Jejak aksi, read-only.
 *
 * Tidak ada create/update/delete, dan di backend memang tidak ada endpoint
 * untuk itu. Tabel `audit_logs` append-only: log yang bisa dihapus oleh
 * orang yang sedang diselidiki tidak berlaku sebagai bukti.
 *
 * Dua sisi aplikasi menulis ke tabel yang sama, jadi filter `actor_type`
 * dipakai untuk memisahkan aksi panel pusat dari aksi user lokasi.
 */
export const auditService = {
  /** Daftar audit. `params`: action, actor_type, tenant_id, from, to, search, per_page, page. */
  async getAll(params = {}) {
    return await api.get('/central/audits', { params })
  },

  /** Satu baris lengkap, termasuk `properties` (diff field yang berubah). */
  async getById(id) {
    return await api.get(`/central/audits/${id}`)
  },

  /** Daftar aksi yang pernah terjadi, untuk dropdown filter. */
  async getActions() {
    return await api.get('/central/audit-actions')
  },
}

/**
 * Location Service - Registry lokasi (tenant) beserta masanya.
 *
 * Auth: superadmin. Endpoint di bawah `auth:sanctum` + `superadmin`.
 */
export const locationService = {
  async getAll() {
    return await api.get('/central/locations')
  },

  async getById(id) {
    return await api.get(`/central/locations/${id}`)
  },

  async create(payload) {
    return await api.post('/central/locations', payload)
  },

  async update(id, payload) {
    return await api.put(`/central/locations/${id}`, payload)
  },

  /**
   * Menonaktifkan lokasi (soft delete). Data di database lokasi TIDAK dihapus.
   */
  async deactivate(id) {
    return await api.delete(`/central/locations/${id}`)
  },
}

/**
 * Invoice Service - Tagihan langganan dari pusat ke lokasi.
 *
 * Auth: superadmin.
 */
export const invoiceService = {
  async getAll(params = {}) {
    return await api.get('/central/invoices', { params })
  },

  async getById(id) {
    return await api.get(`/central/invoices/${id}`)
  },

  async create(payload) {
    return await api.post('/central/invoices', payload)
  },

  async markPaid(id, payload = {}) {
    return await api.post(`/central/invoices/${id}/paid`, payload)
  },

  async markUnpaid(id) {
    return await api.post(`/central/invoices/${id}/unpaid`)
  },
}

/**
 * Public Location Service - Endpoint PUBLIK tanpa login.
 *
 * Hanya mengembalikan nama + slug (dipakai halaman depan untuk dropdown
 * lokasi). Jangan dipakai di panel pusat -- datanya tidak lengkap.
 */
export const publicLocationService = {
  async getAll() {
    return await api.get('/central/lokasi')
  },
}

/**
 * Central Auth Service - Login/logout superadmin.
 *
 * PASANGAN DENGAN utils/centralAxios.js: token disimpan di `central_token`,
 * bukan `auth_token`. Keduanya hidup di tabel `personal_access_tokens` yang
 * sama-sama ada di database pusat DAN di setiap database lokasi dengan id
 * autoincrement yang sama, jadi bila keduanya berbagi key, sesi di satu sisi
 * bisa menimpa sesi sisi lain.
 */
export const centralAuthService = {
  async login(credentials) {
    const response = await api.post('/central/login', credentials)
    const body = response.data

    if (body?.success && body?.data?.token) {
      localStorage.setItem(CENTRAL_TOKEN_KEY, body.data.token)
      localStorage.setItem(CENTRAL_EXPIRES_KEY, (Date.now() + 8 * 60 * 60 * 1000).toString())
      localStorage.setItem(CENTRAL_USER_KEY, JSON.stringify(body.data.user))
    }

    return body
  },

  async logout() {
    try {
      return await api.post('/central/logout')
    } finally {
      // Sesi lokal dibersihkan apa pun hasil server. Kalau request gagal
      // (token sudah kedaluwarsa, jaringan putus), user TETAP harus keluar
      // dari panel -- menahan dia di sini karena server tidak merespons
      // justru meninggalkan sesi half-login.
      clearCentralSession()
    }
  },
}

export default centralService
