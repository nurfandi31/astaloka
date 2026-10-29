import { ref, watch, onMounted } from 'vue'
import dashboardService from '@/services/dashboard.service'

/**
 * Fetch data untuk popup arsip dashboard admin (4 kotak statistik).
 *
 * Semua popup ini sebelumnya menyalin blok fetch/watch yang sama persis,
 * dan salinan itu membawa dua bug yang muncul justru karena disalin:
 *
 * 1. Ganti `per_page` TIDAK mengembalikan `currentPage` ke 1.
 *
 *    `DataTable` mengirim `update:perPage` DAN `update:currentPage`, tapi
 *    `currentPage` tidak ikut berubah. Jadi kalau user sedang di halaman
 *    terakhir (mis. halaman 19 dari 19 dengan per_page=10, total 190) lalu
 *    mengganti ke per_page=50, server menerima `page=19&per_page=50` --
 *    offset 900, sedangkan total hanya 190. Server membalas data kosong,
 *    tapi `DataTable` menghitung label dari state lokal:
 *    `Showing 901 to 190 of 190 entries`. Angka 901 lebih besar dari 190,
 *    dan tidak ada baris yang tampil.
 *
 * 2. Balasan request lama bisa menimpa balasan request baru.
 *
 *    Dua request berjalan bersamaan (mis. user ganti per_page lalu langsung
 *    klik halaman). Kalau request `per_page=10` selesai LEBIH LAMBAT dari
 *    request `per_page=50`, state akhirnyalah yang salah: label
 *    "Showing 51 to 100" padahal barinya 10 (dari per_page=10). Tidak ada
 *    yang salah di masing-masing request -- hanya urutannya.
 *
 * Solusinya di sini:
 *   - `per_page` berubah -> `currentPage` setback ke 1 (bug 1).
 *   - tiap request membawa nomor urut; hanya balasan dengan nomor urut
 *     terbaru yang boleh mengubah state (bug 2).
 *
 * @param {string} type          Tipe popup: instalasi | pemakaian | tunggakan | tagihan
 * @param {Object} [opts]
 * @param {Object} [opts.params] Parameter tambahan yang dikirim ke server
 *                                (mis. `{ month, year }` untuk pemakaian).
 * @param {Function} [opts.petakan] Pemeta baris server -> baris tabel.
 * @param {boolean} [opts.otomatis] Langsung fetch saat komponen mounted.
 */
export function usePopupData(type, { params = {}, petakan = null, otomatis = true } = {}) {
  const searchQuery = ref('')
  const currentPage = ref(1)
  const perPage = ref(10)
  const loading = ref(false)
  const serverTotal = ref(0)
  const totalPages = ref(1)
  const itemsList = ref([])

  /*
   * Nomor urut request.
   *
   * `requestId` bertambah setiap kali request baru dimulai. Saat balasan
   * datang, bandingkan dengan `latestRequestId`: kalau lebih kecil, balasan
   * itu sudah usang dan HARUS diabaikan -- tidak hanya tidak dipakai untuk
   * `itemsList`, tapi juga tidak boleh menyentuh `loading`, karena
   * `loading = false` dari balasan usang akan mematikan spinner milik
   * request yang masih jalan.
   */
  let requestId = 0
  let latestRequestId = 0

  const fetchData = async () => {
    const myId = ++requestId
    latestRequestId = myId

    try {
      loading.value = true

      const response = await dashboardService.getPopupData(type, {
        page: currentPage.value,
        per_page: perPage.value,
        search: searchQuery.value,
        ...params,
      })

      // Balasan usang: request yang lebih baru sudah dimulai. Abaikan.
      if (myId !== latestRequestId) return

      if (response?.success) {
        const raw = response.data || []
        itemsList.value = petakan ? raw.map(petakan) : raw
        serverTotal.value = response.meta?.total ?? itemsList.value.length
        totalPages.value = response.meta?.last_page ?? 1
      }
    } catch {
      // Diamkan: popup cukup menampilkan data yang terakhir sukses.
      // Tidak ada error yang bisa di-olleh di sini tanpa membuat
      // spinner menggantung atau pesan error menimpai tabel.
    } finally {
      // Hanya request terbaru boleh menurunkan spinner.
      if (myId === latestRequestId) {
        loading.value = false
      }
    }
  }

  /*
   * `per_page` DAN `currentPage` dipantau BERSAMA, tapi perbedaannya
   * dijaga di dalam watcher: saat `per_page` berubah, `currentPage`
   * disetel ke 1 DI DALAM watcher itu sendiri, sebelum fetch dijalankan.
   *
   * Kalau disetel dari luar (`watch(perPage, () => currentPage.value = 1)`),
   * Vue akan menjadwalkan watcher `currentPage` sebagai pekerjaan terpisah --
   * sehingga request dengan `page` lama bisa tetap terkirim.
   */
  watch([currentPage, perPage], (newVal, oldVal) => {
    const perPageChanged = oldVal && newVal[1] !== oldVal[1]

    if (perPageChanged && currentPage.value !== 1) {
      currentPage.value = 1
      // `currentPage` yang disetel di sini akan memicu watcher ini lagi.
      // Kembalilah sekarang: watcher kedua itu yang melakukan fetch dengan
      // page=1 yang benar.
      return
    }

    fetchData()
  })

  /*
   * Search di-debounce 300ms supaya mengetik tidak menembak request
   * di setiap ketukan, dan halaman dikembalikan ke 1 (hasil search
   * berbeda dari halaman lama).
   */
  let searchTimer = null
  watch(searchQuery, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      if (currentPage.value !== 1) {
        currentPage.value = 1
        return
      }
      fetchData()
    }, 300)
  })

  onMounted(() => {
    if (otomatis) fetchData()
  })

  return {
    searchQuery,
    currentPage,
    perPage,
    loading,
    serverTotal,
    totalPages,
    itemsList,
    fetchData,
  }
}
