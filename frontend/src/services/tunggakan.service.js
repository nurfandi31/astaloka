import api from '@/utils/axios'

/**
 * Tunggakan Service - Arsip piutang pelanggan yang belum tertagih.
 *
 * Endpoint dipisah dari `dashboard.service` karena punya LIFE CYCLE yang
 * berbeda: `dashboard/popup-data` dipakai untuk popup dashboard (dipakai
 * bersama admin & teknisi, dan selalu terbuka lewat
 * `always_allowed_api`), sedangkan `tunggakan/arsip` milik halaman Arsip
 * Tunggakan saja -- dan dikunci oleh menu `arsip.tunggakan`.
 */
export const tunggakanService = {
  /**
   * Ambil satu halaman Arsip Tunggakan.
   *
   * @param {Object} params
   * @param {number} [params.month]    Bulan pencatatan piutang (1-12)
   * @param {number} [params.year]     Tahun pencatatan piutang
   * @param {string} [params.search]   Kata kunci (mengandung, bukan diawali)
   * @param {number} [params.page]     Halaman (default 1)
   * @param {number} [params.per_page] Baris per halaman (default 10, max 100)
   */
  async getArsip(params = {}) {
    const response = await api.get('/tunggakan/arsip', { params })
    return response.data
  },
}

export default tunggakanService
