import { computed, ref, readonly } from 'vue'
import { centralService } from '@/services/central.service'
import { formatRupiah } from '@/utils/format'

/**
 * Notifikasi panel pusat: badge lonceng + isi panel, dari SATU sumber.
 *
 * Kenapa ini ada
 * ==============
 * Dua tempat di panel pusat menampilkan hal yang sama: lonceng di topnav
 * (hanya angka) dan panel yang muncul saat diklik (daftar). Keduanya
 * perlu menjawab pertanyaan yang sama -- "ada berapa hal yang perlu
 * ditindaklanjuti?" -- dan keduanya punya kebutuhan waktu yang BERLAWAN:
 *
 *   - Badge harus terlihat SEBELUM panel dibuka. Kalau badge ikut dihitung
 *     di dalam panel, ia baru ada setelah lonceng diklik -- jadi orang
 *     tidak pernah tahu harus membuka panel.
 *   - Panel hanya ada setelah lonceng diklik, tapi harus bisa disegarkan
 *     sendiri tanpa menutup halaman.
 *
 * Kalau aturan "apa yang ikut dihitung" ditulis dua kali, dua salinan itu
 * PASTI akan berbeda setelah salah satunya diubah. Jadi aturannya diletakkan
 * di sini, sekali, dan keduanya membacanya.
 *
 * STATE DI MODUL, BUKAN STATE KOMPONEN
 * ------------------------------------
 * `items`/`count`/`loading` sengaja di luar fungsi, di tingkat modul.
 * Kalau jadi `ref()` di dalam `useCentralNotifications()`, setiap pemanggil
 * akan mendapat salinan sendiri: badge di topnav menghitung dari salinannya,
 * panel menghitung dari salinannya yang lain, dan keduanya diisi dari
 * request yang berbeda. Angka di lonceng dan angka di header panel bisa
 * jadi berbeda --
 * di panel pusat, dua angka yang beda bukan pembulatan, tapi kebohongan.
 * Konsekuensinya harus diketahui: karena state-nya modul, instance yang
 * dibuat di dalam komponen tidak ikut di-reset saat komponen di-unmount.
 * Itu justru yang diinginkan di sini (topnav tidak pernah di-unmount selama
 * sesi), tapi artinya composable ini hanya benar untuk SATU pengguna aktif
 * di satu tab -- persis seperti yang dibutuhkan panel pusat.
 *
 * SUMBER DATA: `/central/dashboard`, BUKAN ENDPOINT KHUSUS
 * ========================================================
 * Angka yang dibutuhkan sudah ada di dashboard, dan dihitung ulang server
 * setiap kali dipanggil. Endpoint notifikasi terpisah akan jadi permintaan
 * KEDUA ke data yang sama, dengan peluang besar memberi angka yang berbeda
 * kalau keduanya dipanggil pada saat yang tidak sama persis.
 *
 * Konsekuensi yang harus diterima: panel ini menampilkan HAL YANG BUKAAN
 * PERUBAH. Tagihan yang terlambat lalu dilunasi TIDAK muncul di sini.
 * Jangan pakai daftar ini sebagai sumber kebenaran tagihan -- untuk itu ada
 * `/central/tagihan`.
 */

/** Ambang "masa langganan hampir habis", dalam hari. */
const HAMPIR_HABIS_HARI = 30

/*
 * State modul. Lihat catatan panjang di atas soal kenapa ini bukan
 * `ref()` di dalam composable.
 */
const ringkasan = ref(null)
const rincian = ref([])
const loading = ref(false)
const error = ref('')

/** Cegah request tumpang tindih: badge + panel bisa memicu di detik sama. */
let berjalan = false

/**
 * Daftar notifikasi, disusun di frontend dari angka agregat server.
 *
 * Server mengembalikan angka; aturan "angka itu jadi kategori apa dan
 * seberapa serius" adalah keputusan TAMPILAN, dan fraksi berbeda tiap
 * layar. Menaruhnya di server berarti satu perubahan urutan prioritas ikut
 * mengubah isi endpoint yang dipakai dashboard juga.
 * Urutan di bawah adalah urutan KEPUTISAN, bukan urutan penulisan: yang
 * paling merusak bagi user diletakkan paling atas, karena daftar ini
 * dibaca dari atas tanpa banyak menggulir.
 */
const items = computed(() => {
  const list = []
  const r = ringkasan.value

  /*
   * Nonaktif DAN database hilang sengaja dipisah, bukan digabung.
   *
   * Dari sisi user gejalanya sama persis (aplikasi tidak bisa dipakai),
   * tapi PENYEBAB dan penanganannya berbeda: yang satu dibalik dengan satu
   * klik, yang lain butuh membuat ulang database. Digabung jadi satu baris
   * "lokasi bermasalah" yang tidak bisa ditindaklanjuti per lokasi.
   */

  // Nonaktif: tenant yang tidak bisa dilayani membuat semua masalah lain
  // di bawahnya tidak bisa dibicarakan.
  const nonaktif = rincian.value.filter((t) => t.is_active === false)

  if (nonaktif.length > 0) {
    list.push({
      key: 'nonaktif',
      icon: 'ban',
      tone: 'danger',
      title: `${nonaktif.length} lokasi nonaktif`,
      desc:
        nonaktif
          .slice(0, 2)
          .map((t) => t.label || t.slug)
          .join(', ') + (nonaktif.length > 2 ? `, dan ${nonaktif.length - 2} lainnya` : ''),
      route: 'central-lokasi',
    })
  }

  // Database hilang: terdaftar tapi tidak bisa dilayani.
  const dbHilang = rincian.value.filter((t) => t.database_ada === false)

  if (dbHilang.length > 0) {
    list.push({
      key: 'db-hilang',
      icon: 'database',
      tone: 'danger',
      title: `${dbHilang.length} lokasi database-nya tidak ditemukan`,
      desc:
        dbHilang
          .slice(0, 2)
          .map((t) => t.label || t.slug)
          .join(', ') + (dbHilang.length > 2 ? `, dan ${dbHilang.length - 2} lainnya` : ''),
      route: 'central-lokasi',
    })
  }

  // Lewat jatuh tempo. `terlambat` adalah SUBDIRISI dari belum lunas, jadi
  // angka yang sama sengaja muncul di sini dan di baris "belum lunas" --
  // itu bukan penghitungan ganda, tapi dua tingkat keseriusan.
  if (Number(r?.tagihanTerlambat || 0) > 0) {
    list.push({
      key: 'terlambat',
      icon: 'clock',
      tone: 'warn',
      title: `${r.tagihanTerlambat} tagihan lewat jatuh tempo`,
      desc: `Sudah memblokir akses pengguna lokasi. Tunggakan total ${formatRupiah(r.nilaiTunggakan)}.`,
      route: 'central-tagihan',
    })
  }

  // Belum lunas tapi belum terlambat: bukan darurat, tapi uang yang belum
  // masuk. Hanya ditampilkan kalau jumlahnya MELAMPAUI yang terlambat --
  // kalau tidak, baris ini tidak menambah informasi apa pun.
  const belumLunas = Number(r?.tagihanBelumLunas || 0)

  if (belumLunas > Number(r?.tagihanTerlambat || 0)) {
    list.push({
      key: 'tunggakan',
      icon: 'file-invoice-dollar',
      tone: 'warn',
      title: `${belumLunas} tagihan belum lunas`,
      desc: `Total tunggakan ${formatRupiah(r?.nilaiTunggakan)} dari seluruh lokasi.`,
      route: 'central-tagihan',
    })
  }

  // Masa langganan hampir habis.
  //
  // Perbandingan dilakukan terhadap WAKTU LOKAL tengah malam, bukan
  // `new Date('2026-10-16')` langsung: string tanggal dibaca JS sebagai
  // tengah malam UTC, dan untuk zona di belakang UTC hasilnya bergeser ke
  // tanggal sebelumnya -- batas 30 hari jadi 29, dan hari terakhir salah
  // satu hari.
  const hampirHabis = rincian.value.filter((t) => {
    if (!t.tgl_berakhir) return false

    const m = String(t.tgl_berakhir).match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (!m) return false

    const batas = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
    if (Number.isNaN(batas.getTime())) return false

    const selisih = batas.getTime() - Date.now()
    // Yang lebih dekat dari itu sudah jadi pekerjaan bulan ini; yang lebih
    // jauh belum perlu mengganggu siapa pun.
    return selisih > 0 && selisih < HAMPIR_HABIS_HARI * 24 * 60 * 60 * 1000
  })

  if (hampirHabis.length > 0) {
    list.push({
      key: 'berakhir',
      icon: 'calendar-days',
      tone: 'info',
      title: `${hampirHabis.length} masa langganan berakhir 30 hari lagi`,
      desc:
        hampirHabis
          .slice(0, 2)
          .map((t) => `${t.label || t.slug} (${t.tgl_berakhir})`)
          .join(', ') + (hampirHabis.length > 2 ? `, dan ${hampirHabis.length - 2} lainnya` : ''),
      route: 'central-lokasi',
    })
  }

  return list
})

/** Jumlah hal yang perlu ditindaklanjuti. Dipakai badge lonceng. */
const count = computed(() => items.value.length)

/**
 * Ambil ulang dari `/central/dashboard`.
 *
 * `silent: true` wajib. Tanpa itu, polling badge di background akan
 * mengedipkan progress bar global (interceptor `utils/axios.js`) setiap 30
 * detik -- dan panel pusat yang setiap beberapa detik berkedip adalah
 * gangguan, bukan informasi.
 *
 * Kegagalan TIDAK menghapus data lama. Lonceng yang tiba-tiba hilang
 * setelah gangguan jaringan sesaat jauh lebih menyesatkan daripada lonceng
 * yang menampilkan angka sedikit basi; angka basi masih bisa diklik dan
 * ditanyakan, sedangkan lonceng yang hilang terlihat seperti "semua beres".
 */
const refresh = async () => {
  if (berjalan) return

  berjalan = true
  loading.value = true
  error.value = ''

  try {
    const res = await centralService.getDashboard({ silent: true })
    const body = res?.data?.data || {}

    ringkasan.value = body.ringkasan ?? null

    /*
     * `rincianLokasi` dikirim sebagai PETA yang kuncinya id lokasi
     * (`$hasil[$t->id]` di `DashboardController::rincianPerLokasi`), bukan
     * array. `Object.values` di sini wajib -- kalau diteruskan apa adanya,
     * `filter` dan `length` ikut gagal karena objek tidak punya keduanya.
     */
    rincian.value = Object.values(body.rincianLokasi || {})
  } catch (err) {
    error.value = err?.response?.data?.message || 'Gagal memuat notifikasi.'
  } finally {
    berjalan = false
    loading.value = false
  }
}

/**
 * Satu-satunya jalan masuk ke state modul ini.
 *
 * Dipanggil oleh topnav (untuk badge) dan oleh panel (untuk isi). Keduanya
 * membaca state yang sama, jadi tidak mungkin bedanya.
 */
export function useCentralNotifications() {
  return {
    /** Daftar notifikasi, sudah terurut dari yang paling mendesak. */
    items,
    /** Jumlahnya. Badge lonceng membacanya ini. */
    count,
    ringkasan: readonly(ringkasan),
    loading: readonly(loading),
    error: readonly(error),
    refresh,
  }
}

export default useCentralNotifications
