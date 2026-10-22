import { onMounted, onBeforeUnmount, ref } from 'vue'

/**
 * Auto-refresh (polling) untuk halaman yang isinya bisa berubah dari TAB LAIN.
 *
 * Kenapa ini ada
 * ==============
 * Vue tidak pernah "menyimak" database. Yang terjadi di panel lokasi hari ini
 * adalah: user menekan Simpan -> axios mengembalikan data -> response itu masuk
 * ke store -> komponen re-render. Semua reactivity itu berawal dari request
 * yang DIAMUKAI OLEH USER SENDIRI di tab yang sama. Begitu tab lain (atau user
 * lain di komputer lain) mengubah data, tidak ada yang memberi tahu tab ini --
 * satu-satunya jalan keluar adalah refresh manual.
 *
 * Ini polling ringan, bukan WebSocket. Alasannya pragmatis: data yang dituju
 * berubah sebentar-sekali (seseorang menonaktifkan lokasi, menandai tagihan
 * lunas), dan endpoint-nya sudah ada. WebSocket berarti menambah Reverb/Echo +
 * kanal + infrastruktur yang harus tetap hidup, untuk hemat-detik pada data
 * yang bisa menunggu 30 detik.
 *
 * Batasan yang disengaja
 * =======================
 * 1. HANYA untuk request baca. Polling yang bisa menulis akan mengubah data
 *    tanpa ada yang sengaja mengubahnya.
 * 2. Berhenti saat tab disembunyikan (`visibilitychange`). Tab di background
 *    tidak butuh data terbaru, dan request dari tab yang tidak terlihat hanya
 *    memboroskan kuota API `throttle:api` yang juga dipakai user lain.
 * 3. Jeda setelah tab kembali terlihat, bukan langsung fetch. User sering
 *    berpindah tab lalu langsung kembali -- memotret data saat dia sedang fokus
 *    membaca angka tidak ada gunanya.
 * 4. `silentLoading` wajib. Tanpa itu, tiap polling mengedipkan progress bar
 *    global (lihat interceptor di `utils/axios.js`).
 * 5. Diam-diam pada error. Refresh latar yang gagal karena jaringan sesaat
 *    tidak boleh memunculkan toast -- itu bukan informasi. Error yang terlihat
 *    di UI tetap milik request yang dipicu user.
 * 6. Ada jitter. Banyak tab yang kebetulan aktif bersamaan akan request
 *    bersamaan terus-menerus; meny-offset tiap timer dengan angka acak
 *    menyebarkannya.
 *
 * Cara pakai
 * ==========
 *   const { refreshNow } = useAutoRefresh(loadData, { interval: 30000 })
 *
 * `loadData` yang dipakai adalah closure milik komponen. Komponen yang
 * di-unmount (pindah halaman) membuat polling ikut berhenti lewat
 * `onBeforeUnmount` -- tidak ada timer global yang menggantung.
 */

/** Delay sebelum jadwal poll pertama dimulai. */
const WARMUP_MS = 2000

/** Delay sebelum poll yang dijadwalkan saat tab baru kembali terlihat. */
const RESUME_DELAY_MS = 500

export function useAutoRefresh(task, options = {}) {
  const { interval = 30000, enabled = true, warmup = WARMUP_MS, jitter = 0.2 } = options

  // False sampai komponen selesai mount. Tanpa ini, timer bisa menyala dalam
  // jangka waktu antara komponen dibuat dan `onMounted` dijalankan.
  const active = ref(false)

  let timerId = null
  let warmupId = null
  let running = false

  /**
   * Cegah request yang tumpang tindih.
   *
   * `running` di-set SEBELUM `await`, jadi dua tick yang datang berdekatan
   * (mis. timer menyala tepat saat tab baru diklik) tidak mengirim dua
   * request. Yang kedua melihat `running` masih true dan dilewati; poll
   * berikutnya akan mengambil data terbaru.
   */
  const run = async () => {
    if (!active.value || running) return
    running = true

    try {
      await task()
    } catch {
      // Sengaja ditelan. Lihat catatan nomor 5: kegagalan polling latar
      // bukan informasi yang boleh mengganggu layar. Halaman tetap menampilkan
      // data terakhir yang sukses, dan tick berikutnya akan mencoba lagi.
    } finally {
      running = false
    }
  }

  const stopTimer = () => {
    if (timerId !== null) {
      clearTimeout(timerId)
      timerId = null
    }
  }

  const clearWarmup = () => {
    if (warmupId !== null) {
      clearTimeout(warmupId)
      warmupId = null
    }
  }

  /** Jadwalkan satu tick berikutnya, dengan jitter. */
  const schedule = () => {
    stopTimer()
    if (!active.value) return

    // Jitter positif untuk sebagian dan negatif untuk sebagian lain: interval
    // rata-rata tetap `interval`, tapi client tidak pernah semua menembak di
    // detik yang sama.
    const offset = interval * jitter * (Math.random() * 2 - 1)
    timerId = setTimeout(
      async () => {
        await run()
        schedule()
      },
      Math.max(1000, interval + offset),
    )
  }

  const start = () => {
    if (active.value) return
    active.value = true

    // Poll pertama sengaja ditunda, bukan langsung jalan. Halaman yang baru
    // dibuka selalu menjalankan `loadData` sendiri di `onMounted`; poll di
    // detik 0 hanya mengulang request yang sama.
    if (warmup > 0) {
      warmupId = setTimeout(() => {
        warmupId = null
        schedule()
      }, warmup)
    } else {
      schedule()
    }
  }

  const stop = () => {
    active.value = false
    clearWarmup()
    stopTimer()
  }

  const handleVisibility = () => {
    if (document.hidden) {
      // Tab tidak terlihat: matikan timer, jangan cuma menunda. Request dari
      // tab background adalah request yang tidak ada yang menunggu hasilnya.
      stopTimer()
      return
    }

    // Kembali terlihat: poll sekali setelah jeda singkat, lalu lanjut jadwal.
    // Jeda itu mencegah tab yang diklik dua kali dalam waktu singkat mengirim
    // request bertumpuk.
    if (!active.value) return

    stopTimer()
    clearWarmup()

    warmupId = setTimeout(() => {
      warmupId = null
      run().finally(schedule)
    }, RESUME_DELAY_MS)
  }

  onMounted(() => {
    if (!enabled) return

    document.addEventListener('visibilitychange', handleVisibility)
    start()
  })

  onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', handleVisibility)
    stop()
  })

  return {
    /** Poll sekarang, di luar jadwal. Untuk refresh manual dari UI. */
    refreshNow: run,
    /** Apakah polling sedang aktif. Berguna untuk indikator "diperbarui otomatis". */
    polling: active,
    stop,
    start,
  }
}

export default useAutoRefresh
