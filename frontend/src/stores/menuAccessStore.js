import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import menuAccessService from '@/services/menuAccess.service'

/**
 * Menu Access Store - daftar menu yang boleh dibuka user yang sedang login.
 *
 * Datanya diambil dari backend, bukan ditulis di sini. Katalog menunya
 * (`config/menu_access.php`) adalah sumber yang sama dengan yang dipakai
 * middleware pemblokiran, jadi sidebar di frontend dan pemblokir di backend
 * tidak mungkin punya daftar yang berbeda.
 *
 * Store ini hanya membuka menu, bukan menjaga. Menyembunyikan menu dari
 * sidebar bukan pengaman: user masih bisa mengetik URL. Pengaman yang
 * sesungguhnya ada di backend, yang membalas 403 untuk endpoint menu itu.
 * Yang dilakukan store ini adalah jangan sampai user melihat menu yang
 * memang sudah tidak bisa dia buka -- supaya tidak ada tombol yang pasti gagal.
 */
export const useMenuAccessStore = defineStore('menuAccess', () => {
  /**
   * Katalog mentah dari server, sudah terpotong sesuai hak akses.
   * Item yang tidak diizinkan tetap ada di sini, tapi hanya punya `key`.
   */
  const items = ref([])

  /** Urutan group untuk ditampilkan. Diambil dari server juga. */
  const groups = ref([])

  const isLoading = ref(false)
  const isLoaded = ref(false)

  /**
   * Kenapa ada `loadError` dan bukan langsung `throw`.
   *
   * Sidebar bergantung pada store ini. Kalau katalog gagal diambil karena
   * jaringan, pilihan yang lebih buruk adalah sidebar kosong total -- user
 * mengira aplikasinya rusak, padahal mungkin cuma sesaat. Karena itu
   * kegagalan dibiarkan apa adanya (`items` tetap kosong) dan sidebar
   * memakai daftar defensif-nya sendiri. Menu yang "tidak ada" jauh lebih
   * mudah daripada aplikasi yang seluruh menunya hilang.
   */
  const loadError = ref(null)

  /** Menu yang boleh dibuka: punya label, jadi berarti `allowed`. */
  const allowedItems = computed(() => items.value.filter((item) => item.allowed))

  /** Hanya entri yang dirender sebagai menu sidebar. */
  const visibleItems = computed(() => allowedItems.value.filter((item) => !item.hidden))

  /** Key menu yang boleh, untuk pengecekan cepat. */
  const allowedKeys = computed(() => new Set(allowedItems.value.map((item) => item.key)))

  /**
   * Ambil katalog. Aman dipanggil berulang -- hanya fetch kalau belum pernah.
   *
   * Panggil ulang perlu setelah login (token baru) atau setelah admin
   * menyimpan perubahan hak akses, karena daftar menu ikut berubah.
   */
  const loadCatalog = async (force = false) => {
    if (isLoaded.value && !force) return
    if (isLoading.value) return

    try {
      isLoading.value = true
      loadError.value = null

      const res = await menuAccessService.getCatalog()
      const data = res?.data ?? res

      items.value = Array.isArray(data?.items) ? data.items : []
      groups.value = Array.isArray(data?.groups) ? data.groups : []

      isLoaded.value = true
    } catch (err) {
      // Sengaja tidak dilempar ke pemanggil.
      isLoaded.value = false
      loadError.value = err
      console.error('[menuAccessStore] gagal memuat katalog menu:', err)
    } finally {
      isLoading.value = false
    }
  }

  /** Menu ini boleh dibuka? */
  const allowsKey = (key) => {
    if (!key) return false
    if (!isLoaded.value) return true
    return allowedKeys.value.has(key)
  }

  /**
   * Halaman dengan path ini boleh dibuka?
   *
   * Hanya menolak path yang ADA di katalog tapi tidak diizinkan. Path yang
   * tidak dikenal katalognya dibiarkan lewat: daftar route frontend sudah
   * terpisah, dan menolak path tak dikenal akan ikut memblokir halaman
   * tanpa menu seperti preview laporan dan halaman cetak -- yang justru
   * perlu tetap bisa dibuka lewat URL.
   *
   * Bandingkan path penuh, bukan awalan. Kalau pakai `startsWith`, maka
   * `/app/data-desa` ikut cocok oleh `/app/data-desa/tambah` dan sebaliknya,
   * sehingga pencabutan satu halaman ikut menutup halaman lain yang tidak
   * ada hubungannya.
   */
  const allowsPath = (path) => {
    if (!isLoaded.value) return true
    if (!path) return true

    const needle = `/${String(path).replace(/^\/+/, '')}`
    const semuaItem = items.value.filter((item) => item.path)

    const adaDiKatalog = semuaItem.some((item) => item.path === needle)
    if (!adaDiKatalog) return true

    return allowedKeys.value.has(semuaItem.find((item) => item.path === needle).key)
  }

  /** Buang cache supaya pemanggilan berikutnya mengambil data baru. */
  const invalidate = () => {
    isLoaded.value = false
    items.value = []
    groups.value = []
  }

  return {
    items,
    groups,
    isLoading,
    isLoaded,
    loadError,
    allowedItems,
    visibleItems,
    allowedKeys,
    loadCatalog,
    allowsKey,
    allowsPath,
    invalidate,
  }
})

export default useMenuAccessStore
