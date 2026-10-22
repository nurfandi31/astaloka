import api from '@/utils/axios'

/**
 * Hak Akses Menu Lokasi.
 *
 * Daftar menu TIDAK ditulis di frontend. Semuanya diambil dari
 * `GET /menu-access/catalog`, yang membaca `config/menu_access.php` di
 * backend -- file yang sama dipakai middleware untuk memblokir endpoint.
 *
 * Kenapa begitu, bukan sekadar agar "konsisten": kalau frontend punya
 * daftarnya sendiri, akan ada saat keduanya berbeda, dan gejalanya menipu
 * dari dua arah sekaligus. Menu disembunyikan tapi endpoint-nya masih
 * terbuka (user buka URL), atau endpoint diblokir tapi menunya tetap tampil
 * (user klik, lalu dapat 403). Keduanya terlihat sebagai bug yang tidak
 * berhubungan, padahal akarnya satu.
 *
 * Konsekuensi: menambah menu berarti menambah entry di
 * `config/menu_access.php`. Sidebar ikut dapat, lengkap dengan ikon dan
 * urutannya, tanpa menyentuh kode frontend.
 */
export const menuAccessService = {
  /**
   * Katalog menu + hak akses user yang sedang login.
   *
   * Terbuka untuk semua role, bukan hanya admin: sidebar dirender juga oleh
   * teknisi, surveyor, dan pelanggan, dan mereka butuh menunya. Yang
   * dikembalikan sudah terpotong sesuai hak akses -- item yang tidak
   * diizinkan hanya membawa key-nya, tanpa label maupun path, jadi tidak
   * ada informasi yang bocor.
   */
  async getCatalog() {
    const response = await api.get('/menu-access/catalog')
    return response.data
  },

  /*
   * Daftar jabatan + menu yang boleh untuk masing-masing. `role:admin`.
   *
   * READ-ONLY sekarang: endpoint yang bisa mengubah sudah tidak ada lagi.
   * Penulisan pindah ke panel pusat (`centralMenuAccessService` di
   * `central.service.js`) karena tabelnya milik database tiap lokasi --
   * menyetel dari sini menuntut satu sesi per lokasi.
   *
   * Yang tersisa dipakai untuk MEMBACA hak akses yang berlaku di lokasi ini,
   * supaya admin lokasi bisa melihat aturan yang sudah disetel di panel pusat.
   */
  async getAll() {
    const response = await api.get('/menu-access')
    return response.data
  },
}

export default menuAccessService
