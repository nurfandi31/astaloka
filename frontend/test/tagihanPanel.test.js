import { strict as assert } from 'node:assert'
import { after, afterEach, before, beforeEach, describe, it } from 'node:test'

import { daftarkanPagarVirtual, komponen, pasangDom, tutupDom } from './vueHarness.mjs'

/*
 * PrimeVue diimpor di level teratas, bukan lewat `import()` di dalam
 * `before()`.
 *
 * Alasannya soal identitas modul: `primevue/select` yang dipakai
 * `BaseSelect` diimpor oleh SFC yang dimuat lewat loader Node, dan loader
 * itu meneruskan specifier itu apa adanya ke resolver Node -- sama seperti
 * import biasa. Keduanya harus menunjuk BERKAS yang sama.
 *
 * `pasangDom()` harus dipanggil SEBELUM `@vue/test-utils` dan PrimeVue
 * dievaluasi. Itu sudah dijamin oleh `vueHarness.mjs`, yang mengimpor
 * `pasangDom.js` sebagai import pertama.
 */
import PrimeVue from 'primevue/config'

/*
 * HALAMAN TAGIHAN PANEL PUSAT: halaman 2 tidak boleh kosong.
 *
 * Bug yang dikunci test ini benar-benar terjadi dan dilaporkan sebagai
 * "dashboard ada riwayat pembayaran lunas, sedangkan di tagihannya kok
 * ngk ada".
 *
 * =====================
 * BUGNYA DI MANA
 * =====================
 *
 * Halaman ini mengambil data yang SUDAH dipaginasi server
 * (`?page=2&per_page=10`), lalu handing it ke `DataTable`.
 *
 * `DataTable` secara bawaan memotong `data` sendiri di peramban:
 *
 *     data.slice((currentPage - 1) * perPage, currentPage * perPage)
 *
 * Itu benar untuk halaman yang datanya diambil utuh. Tapi di sini `data`
 * hanya berisi SATU halaman -- 10 baris. Jadi halaman 2 menjadi
 * `data.slice(10, 20)` dari data yang isinya 10 baris, hasilnya KOSONG.
 *
 * Headless tabelnya tetap menggambar kolom, tapi tidak ada satu pun baris di
 * bawahnya, dan tidak ada pesan apa pun yang menjelaskan kenapa.
 *
 * Yang hilang bukan datanya; yang hilang adalah BARISNYA di layar.
 *
 * =====================
 * MENGAPA TIDAK KETAHUAN
 * =====================
 *
 * Karena bentuk respons tidak berubah. Backend mengirim 10 baris dengan
 * benar, `total` dan `last_page` juga benar, dan tidak ada satu pun alat
 * yang gagal. Halaman 1 -- yang tidak pernah melewati `slice` -- tampil
 * sempurna. Bugnya baru muncul begitu total tagihan melewati satu halaman,
 * jadi test di browser biasa akan selalu hijau.
 *
 * Atribut `server-side` sudah ada di `DataTable` (dipakai `daftarTagihan.vue`
 * untuk masalah yang sama di panel lokasi). Halaman Tagihan Panel Pusat
 * satu-satunya yang lupa memakainya.
 *
 * Sisi server dari bug yang sama diuji di
 * `backend/tests/Feature/CentralInvoiceRingkasanTest.php`.
 */

/** Panggilan yang dicatat oleh tiruan service. */
let jejak

/** Toast yang muncul selama test. */
let toast

/**
 * Satu invoice, dengan bentuk persis seperti hasil `present()` di backend.
 *
 * Field yang boleh kosong tetap diisi apa adanya kalau memang ada di
 * backend, karena `CentralTagihanIndex.vue` membacanya saat render. `periode`
 * sengaja TIDAK ada di sini: `present()` sudah tidak mengirimnya, jadi
 * tiruan yang masih mengirimnya akan menguji bentuk yang tidak pernah lagi
 * muncul di produksi.
 */
function invoiceFixtures(nomorUrut, status = 'UNPAID') {
  return {
    id: nomorUrut,
    nomor: `INV/2026/${String(nomorUrut).padStart(4, '0')}`,
    tenant: { id: 1, slug: 'pdam', nama: 'PDAM Uji', nama_lembaga: 'PDAM Uji Bersama', label: 'PDAM Uji' },
    jumlah: 100_000 * nomorUrut,
    status,
    keterangan: null,
    tgl_invoice: '2026-10-01',
    tgl_jatuh_tempo: '2026-10-15',
    deadline: '2026-10-20',
    terlambat: false,
    sisa_hari: 3,
    payment: null,
    items: [],
  }
}

/**
 * Balasan backend untuk satu halaman.
 *
 * Halaman dibaca dari `params` yang dikirim halaman, bukan dari closure
 * yang dibekukan saat pagarnya dipasang. Closure yang dibekukan begitu akan
 * selalu membalas halaman yang sama untuk permintaan berbeda -- persis
 * keadaan yang membuat test ini tidak bisa menguji apa pun.
 */
function balasHalaman(params = {}) {
  const halaman = Number(params.page) || 1
  const perHalaman = Number(params.per_page) || 10

  const semua = [
    invoiceFixtures(1),
    invoiceFixtures(2, 'PAID'),
    invoiceFixtures(3),
    invoiceFixtures(4),
    invoiceFixtures(5, 'PAID'),
    invoiceFixtures(6),
    invoiceFixtures(7),
    invoiceFixtures(8, 'PAID'),
    invoiceFixtures(9),
    invoiceFixtures(10, 'PAID'),
    // Lima baris lagi: tanpa ini tidak akan ada halaman kedua sama sekali,
    // dan test ini akan lulus tanpa pernah menguji apa pun.
    invoiceFixtures(11),
    invoiceFixtures(12, 'PAID'),
    invoiceFixtures(13),
    invoiceFixtures(14),
    invoiceFixtures(15, 'PAID'),
  ]

  const mulai = (halaman - 1) * perHalaman
  const isi = semua.slice(mulai, mulai + perHalaman)

  return {
    data: {
      success: true,
      data: {
        current_page: halaman,
        last_page: Math.ceil(semua.length / perHalaman),
        per_page: perHalaman,
        from: isi.length ? mulai + 1 : 0,
        to: mulai + isi.length,
        total: semua.length,
        data: isi,
      },
      /*
       * `ringkasan` sengaja TIDAK ikut berubah saat `status` disaring.
       *
       * Itu bukan keputusan tiruan: itu bentuk respons yang benar setelah
       * `InvoiceController` diperbaiki, dan halaman ini membacanya secara
       * terpisah. Test di sini tidak perlu memeriksa angkanya -- angka itu
       * sudah diuji di backend -- tapi tetap harus memakai bentuk yang
       * benar supaya kegagalan di layar ini tidak disalahkan ke hal yang
       * keliru.
       */
      ringkasan: {
        belumLunas: 8,
        totalTunggakan: 7_600_000,
        lunas: 7,
        totalLunas: 2_800_000,
      },
    },
  }
}

/** Lokasi seperti `locationService.getAll()` membalas di panel pusat. */
const DAFTAR_LOKASI = [
  { id: 1, slug: 'pdam', nama: 'PDAM Uji', label: 'PDAM Uji', is_active: true },
]

/** Reset jejak dan pasang penanganan bawaan sebelum tiap test. */
function pasangPagar() {
  jejak = { invoice: [], lokasi: [], bayar: [], batalkan: [] }
  toast = []

  globalThis.__jejak = jejak
  globalThis.__toast = toast
  globalThis.__balasHalaman = balasHalaman
}

let mount
let flushPromises
let createPinia
let setActivePinia
let createRouter
let createMemoryHistory
let DataTable
let CentralTagihanIndex
let BaseSelect

before(async () => {
  pasangPagar()
  // `pasangDom()` memasang DOM ke `globalThis` sebagai efek sampingnya.
  // Nilai balikannya tidak perlu disimpan: yang dipakai component dan
  // `@vue/test-utils` adalah globalnya, bukan variabel lokal ini.
  await pasangDom()

  const testUtils = await import('@vue/test-utils')
  mount = testUtils.mount
  flushPromises = testUtils.flushPromises

  const routerModul = await import('vue-router')
  createRouter = routerModul.createRouter
  createMemoryHistory = routerModul.createMemoryHistory

  /*
   * PrimeVue sudah diimpor di level teratas file ini.
   *
   * Yang penting di sini hanya satu: plugin-nya HARUS dipasang lewat
   * `global.plugins`. Tanpa itu, `BaseSelect` (yang membungkus
   * `primevue/select`) membaca `useConfig()` dan mendapat `undefined`:
   *
   *     TypeError: Cannot read properties of undefined (reading 'config')
   *       at Proxy.$variant (@primevue/core/baseinput/index.mjs:31:115)
   *
   * yang menyebut paket PrimeVue, bukan filter mana pun di halaman ini --
   * padahal tidak ada yang salah pada filternya.
   *
   * `theme` tidak di-set. Yang diuji di sini perilaku, bukan warna, dan
   * `Aura` hanya menambah dependensi tanpa menambah apa pun.
   */

  /*
   * Pagar dipasang SEBELUM komponen diimpor. Komponen yang sudah ter-resolve
   * tidak pernah membaca pagarnya lagi.
   */
  daftarkanPagarVirtual(
    '@/services/central.service',
    `
    export const invoiceService = {
      async getAll(params = {}) {
        globalThis.__jejak.invoice.push(params)
        return globalThis.__balasHalaman(params)
      },
      async markPaid(id) {
        globalThis.__jejak.bayar.push(id)
        return { data: { success: true, message: 'ok' } }
      },
      async markUnpaid(id) {
        globalThis.__jejak.batalkan.push(id)
        return { data: { success: true, message: 'ok' } }
      },
      async openPdf() {
        return { data: { success: true } }
      },
    }

    export const locationService = {
      async getAll() {
        globalThis.__jejak.lokasi.push(1)
        return { data: { success: true, data: ${JSON.stringify(DAFTAR_LOKASI)} } }
      },
    }

    /*
     * \`rekeningService\` bukan milik halaman ini, tapi tetap harus ada.
     *
     * \`CentralBayarDialog\` -- anak dari halaman ini -- mengimpornya, dan
     * impor itu terjadi saat modul SFC-nya DIEVALUASI, bukan saat komponennya
     * dirender. Men-stub-nya di \`global.stubs\` tidak menolong: berkas SFC-nya
     * sudah diimpor sebelum \`mount()\` dipanggil.
     *
     * Gejalanya muncul jauh dari penyebabnya:
     *
     *     SyntaxError: The requested module '@/services/central.service'
     *     does not provide an export named 'rekeningService'
     *
     * yang menyebut modul tiruan, bukan dialog yang salah impor.
     */
    export const rekeningService = {
      async getAll() {
        return { data: { success: true, data: [] } }
      },
    }
  `,
  )

  daftarkanPagarVirtual(
    '@/utils/swal',
    `
    export const MySwal = { fire: async () => ({ isConfirmed: true }) }
    export const showErrorToast = (e) => globalThis.__toast.push(['error', e])
    export const showSuccessToast = (m) => globalThis.__toast.push(['success', m])
    export const showWarningToast = (m) => globalThis.__toast.push(['warning', m])
  `,
  )

  /*
   * `useAutoRefresh` di-poll dengan jeda 2 detik sebelum jadwal pertama
   * dijalankan. Test selesai jauh sebelum itu, jadi timer-nya tidak pernah
   * menyala dan tidak ada permintaan liar yang masuk ke `jejak`.
   *
   * Sengaja TIDAK ditiru: memalsuinya berarti test tidak lagi memeriksa
   * apa yang terjadi pada halaman ini saat di-poll, dan mock-nya akan
   * menutupi bug yang justru paling sering muncul di situ (respons lama
   * menimpa hasil filter yang baru).
   */
  const pinia = await import('pinia')
  createPinia = pinia.createPinia
  setActivePinia = pinia.setActivePinia

  DataTable = await komponen('presentations/components/ui/DataTable.vue')
  CentralTagihanIndex = await komponen('presentations/views/central/CentralTagihanIndex.vue')
  BaseSelect = await komponen('presentations/components/ui/BaseSelect.vue')
})

after(async () => {
  await tutupDom()
})

/**
 * Komponen yang masih ter-mount saat test selesai.
 *
 * Halaman ini memakai `useAutoRefresh`, yang menjadwalkan poll pertama
 * dengan jeda 2 detik lalu mengulanginya tiap 45 detik. Timer itu
 * DIBERSIHKAN saat komponen di-unmount (`onBeforeUnmount` di
 * `useAutoRefresh`) -- tapi tidak ada yang meng-unmount komponennya.
 *
 * Akibatnya proses test tidak pernah selesai: Node menunggu handle yang
 * masih hidup, dan test file-nya berakhir dengan "test timed out" --
 * padahal semua test di dalamnya sudah hijau.
 *
 * Gejalanya menyesatkan karena tidak ada satupun yang gagal. Test
 * yang hijau tapi membuat process hang akan menggagalkan seluruh suite,
 * dan penyebabnya (timer polling) tidak ada hubungannya dengan apa pun
 * yang diuji.
 */
const wrapperHidup = []

/** Daftarkan wrapper supaya bisa di-unmount setelah test selesai. */
function simpan(wrapper) {
  wrapperHidup.push(wrapper)

  return wrapper
}

afterEach(() => {
  while (wrapperHidup.length > 0) {
    wrapperHidup.pop()?.unmount()
  }
})

/** Opsi global untuk kedua jenis komponen. */
function opsiGlobal() {
  return {
    mocks: {
      $route: { path: '/central/tagihan', params: {}, query: {} },
      $router: { push: () => {}, replace: () => {} },
    },
    stubs: {
      'font-awesome-icon': { template: '<i />' },
      /*
       * Dua dialog di halaman ini tidak ikut diuji di sini.
       *
       * Keduanya `Teleport` ke `<body>`, jadi isinya mendarat di luar
       * wrapper -- hasil pemeriksaan test jadi bergantung pada urutan
       * teleport, bukan pada apa yang diuji. Men-stub-nya membuat
       * pemeriksaan fokus pada tabel, yang memang target test ini.
       */
      CentralBayarDialog: true,
      CentralTagihanPopup: true,
    },
  }
}

/**
 * Mount halaman Tagihan dengan pinia aktif.
 *
 * Pinia dipasang DUA kali, dan keduanya dibutuhkan: `setActivePinia()`
 * memberi tahu `mount()` pinia mana yang aktif, tapi `useUiStore()` di
 * dalam `setup()` juga butuh instance-nya lewat `global.plugins`.
 */
async function mountHalaman() {
  const pinia = createPinia()

  setActivePinia(pinia)

  /*
   * Router sungguhan, bukan `global.mocks.$route`.
   *
   * Halaman ini memakai Composition API: `const route = useRoute()` lalu
   * membaca `route.query.tenant_id` di `setup()`. `global.mocks` hanya
   * memasang properti pada instance komponen -- tidak pernah menyuntik ke
   * `inject()`. Gejalanya:
   *
   *     TypeError: Cannot read properties of undefined (reading 'query')
   *       at setup (CentralTagihanIndex.vue:148:45)
   *
   * yang menyebut baris komponen, padahal penyebabnya mock-nya tidak
   * pernah sampai ke `useRoute()`.
   *
   * `createMemoryHistory` dipakai karena halaman ini memanggil
   * `router.replace()` setiap filter berubah, dan router dengan history
   * sungguhan akan menyentuh `window.location` yang JSDOM tidak sediakan
   * serunya.
   */
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/central/tagihan', component: CentralTagihanIndex }],
  })

  /*
   * Router harus benar-benar MENUJU ke rute-nya, bukan cuma didaftarkan.
   *
   * Halaman ini memanggil `router.replace()` setiap filter berubah, untuk
   * menulis filter ke URL. `replace()` tanpa rute yang sedang aktif akan
   * gagal dengan:
   *
   *     No match for {"query":{"status":"PAID"},"replace":true,...}
   *     while being at {"fullPath":"/","path":"/",...}
   *
   * Yang/artinya "tidak ada rute yang cocok" -- router masih di `/`, bukan
   * di `/central/tagihan`. Gejalanya muncul di test yang menguji filter,
   * padahal masalahnya di cara router-nya disiapkan.
   */
  await router.push('/central/tagihan')
  await router.isReady()

  const wrapper = mount(CentralTagihanIndex, {
    global: { ...opsiGlobal(), plugins: [pinia, router, PrimeVue] },
  })

  /*
   * Wrapper-nya didaftarkan SEGERA, bukan nanti.
   *
   * `simpan()` harus dipanggil tepat setelah `mount()` selesai: kalau
   * `mount()` melempar galat, wrapper-nya sudah ada tapi tidak pernah
   * terdaftar -- dan `afterEach` tidak punya apa pun untuk di-unmount,
   * sehingga timer poll miliknya menggantung sampai proses dibunuh.
   */
  return simpan(wrapper)
}

/**
 * Baris tagihan yang benar-benar tergambar di tabel.
 *
 * `DataTable` juga merender baris "kosong" saat sedang memuat dan baris
 * "tidak ada data" saat jawabannya kosong. Keduanya bukan baris tagihan,
 * dan membiarkannya ikut terhitung membuat test ini lulus justru pada
 * keadaan yang paling salah.
 */
function barisTabel(wrapper) {
  const tabel = wrapper.findComponent(DataTable)

  return tabel.findAll('tbody > tr').filter((tr) => tr.findAll('td[colspan]').length === 0)
}

/**
 * Teks semua sel pada baris tabel ke-`n`.
 */
function isiBaris(wrapper) {
  return barisTabel(wrapper).map((tr) => tr.findAll('td').map((td) => td.text()))
}

/**
 * Tombol "Next" milik `DataTable`.
 *
 * Teksnya "Next", bukan "Berikutnya" -- `DataTable` memakai Bahasa Inggris
 * untuk baris paginationnya ("Showing X to Y of Z entries", "Previous",
 * "Next"), dan menebak-nebak label yang berbeda hanya membuat test gagal
 * dengan pesan yang salah.
 */
function tombolNext(wrapper) {
  return wrapper.findAll('button').find((b) => b.text().trim() === 'Next')
}

/**
 * Pilih "Lunas" di filter status.
 *
 * Filter status itu `BaseSelect`, yang membungkus `primevue/select` --
 * BUKAN `<select>` native. Jadi tidak ada elemen `select` untuk di-`setValue`.
 *
 * Yang bisa dilakukan tanpa membuka dropdown-nya adalah memancing
 * `update:modelValue` pada komponen `BaseSelect` itu, sama seperti yang
 * terjadi ketika pengguna memilih.
 *
 * `change` ikut dipancarkan, karena itulah yang memicu pemuatan ulang di
 * halaman ini: `BaseSelect` meng-emitting `change` SESUDAH
 * `update:modelValue`, dan halamannya memasang `@change="resetPage"`.
 * Hanya `update:modelValue` berarti `filters.status` berubah tapi
 * halaman tidak pernah meminta data baru -- dan testnya terlihat gagal
 * karena "filter status harus ikut dikirim ke server", padahal yang salah
 * adalah testnya, bukan halamannya.
 *
 * Dipanggil lewat `wrapper.findAllComponents(BaseSelect)` -- daftar
 * component-nya diambil dari wrapper, bukan dari `document`, karena
 * PrimeVue merender panel dropdown-nya di `body`.
 */
function pilihStatus(wrapper, nilai) {
  const pilih = wrapper.findAllComponents(BaseSelect)

  assert.ok(pilih.length >= 2, 'hanya ada satu filter, padahal harusnya ada filter status dan lokasi')

  // Yang pertama adalah filter status, yang kedua filter lokasi.
  pilih[0].vm.$emit('update:modelValue', nilai)
  pilih[0].vm.$emit('change', nilai)
}

/* ============================================================
 | DataTable: memotong data yang belum dipaginasi server
 |============================================================
 */

/*
 * Test ini menguji `DataTable` sendiri, bukan halamannya.
 *
 * Alasannya: gejala yang dilaporkan ("halaman tagihan kosong") muncul dari
 * komponen generik yang dipakai banyak halaman. Kalau hanya halamannya yang
 * diuji, perbaikan berikutnya bisa saja memindahkan paginasi ke tempat lain
 * dan perbaikannya berikutnya bisa saja memindahkan paginasi ke tempat
 * lain dan membuat halaman 2 kosong lagi -- dan tidak ada yang gagal.
 */
describe('DataTable dengan server-side', () => {
  beforeEach(pasangPagar)

  it('menampilkan seluruh data yang dikirim, tanpa memotong lagi', () => {
    /*
     * Tanpa `serverSide`, `paginatedData` menjadi `data.slice(10, 20)`
     * dari data 10 baris -- hasilnya kosong, dan tabel menulis pesan
     * "tidak ada data" padahal backend JUSTRU mengirim 10 baris.
     */
    const tanpaServerSide = simpan(mount(DataTable, {
      props: {
        data: Array.from({ length: 10 }, (_, i) => ({ id: i + 11, nama: `Baris ${i + 11}` })),
        columns: [{ key: 'nama', title: 'Nomor' }],
        currentPage: 2,
        totalPages: 2,
        perPage: 10,
      },
      global: opsiGlobal(),
    }))

    assert.equal(
      barisTabel(tanpaServerSide).length,
      0,
      'kontrol: tanpa server-side halaman 2 memang kosong -- inilah gejalanya yang dilaporkan',
    )

    const denganServerSide = simpan(mount(DataTable, {
      props: {
        data: Array.from({ length: 10 }, (_, i) => ({ id: i + 11, nama: `Baris ${i + 11}` })),
        columns: [{ key: 'nama', title: 'Nomor' }],
        currentPage: 2,
        totalPages: 2,
        perPage: 10,
        serverSide: true,
      },
      global: opsiGlobal(),
    }))

    assert.equal(
      barisTabel(denganServerSide).length,
      10,
      'dengan server-side, 10 baris yang dikirim harus semuanya tampil',
    )
    assert.match(barisTabel(denganServerSide)[0].text(), /Baris 11/, 'baris halaman 2 harus tampil utuh')
  })

  it('tetap menampilkan semua data saat belum ada halaman kedua', () => {
    // `serverSide` tidak boleh merusak halaman 1, yang memang masih bisa
    // dipotong di peramban tanpa salah. Kalau atribut ini merusak halaman 1,
    // perbaikannya lebih buruk dari bug aslinya.
    const wrapper = mount(DataTable, {
      props: {
        data: [{ id: 1, nama: 'Satu' }, { id: 2, nama: 'Dua' }],
        columns: [{ key: 'nama', title: 'Nomor' }],
        currentPage: 1,
        totalPages: 1,
        perPage: 10,
        serverSide: true,
      },
      global: opsiGlobal(),
    })

    assert.equal(barisTabel(wrapper).length, 2, 'halaman 1 harus tetap menampilkan semua baris')
  })
})

/* ============================================================
 | HALAMAN TAGIHAN: paginasi dan ringkasan
 |============================================================
 */

describe('halaman tagihan panel pusat', () => {
  beforeEach(pasangPagar)

  it('meminta halaman pertama dan menampilkannya utuh', async () => {
    const wrapper = await mountHalaman()

    await flushPromises()

    assert.equal(jejak.invoice.length, 1, 'halaman harus memuat daftar tagihan saat dibuka')

    assert.equal(
      barisTabel(wrapper).length,
      10,
      'halaman pertama harus menampilkan 10 baris yang dikirim backend',
    )
  })

  it('BUG INI: halaman kedua harus menampilkan barisnya sendiri, bukan kosong', async () => {
    const wrapper = await mountHalaman()

    await flushPromises()

    /*
     * Kontrol: baris halaman 1 memang ada, jadi "kosong" di langkah
     * berikutnya tidak bisa lolos sebagai "tidak ada data sama sekali".
     */
    const isiHalaman1 = isiBaris(wrapper)

    assert.equal(isiHalaman1.length, 10, 'kontrol: halaman 1 harus berisi 10 baris')

    // Pindah ke halaman 2 lewat tombol milik `DataTable`.
    const tombolBerikutnya = tombolNext(wrapper)

    assert.ok(tombolBerikutnya, 'harus ada tombol "Next" untuk pindah ke halaman berikutnya')

    await tombolBerikutnya.trigger('click')
    await flushPromises()

    const isiHalaman2 = isiBaris(wrapper)

    assert.equal(
      isiHalaman2.length,
      5,
      'BUG INI: halaman 2 kosong. `DataTable` memotong `data` yang sudah dipaginasi server -- '
      + 'butuh `server-side` di `<DataTable>`.',
    )

    // Backend mengirim tagihan 11-15 di halaman 2, jadi nomor itu harus
    // terlihat -- bukan hanya "sesuatu" yang tidak kosong.
    assert.match(
      isiHalaman2.map((sel) => sel.join(' ')).join(' '),
      /INV\/2026\/0011/,
      'BUG INI: halaman 2 harus menampilkan tagihan milik halaman itu, bukan mengulang halaman 1',
    )

    assert.doesNotMatch(
      isiHalaman2.map((sel) => sel.join(' ')).join(' '),
      /INV\/2026\/0001\b/,
      'BUG INI: halaman 2 mengulang baris halaman 1',
    )
  })

  it('mengirim per_page ke server saat ukuran halaman diubah', async () => {
    /*
     * Kalau `per_page` tidak ikut dikirim, kotak "Tampilkan ... data"
     * bergerak tapi jumlah barisnya tidak pernah berubah -- dan test
     * "tombol berfungsi" akan tetap hijau karena memang ada tombolnya.
     */
    const wrapper = await mountHalaman()

    await flushPromises()

    const pengubahUkuran = wrapper
      .findAll('select')
      .find((s) => s.findAll('option').some((o) => o.text().includes('25')))

    assert.ok(pengubahUkuran, 'harus ada pengubah ukuran halaman di `DataTable`')

    await pengubahUkuran.setValue('25')
    await flushPromises()

    const terakhir = jejak.invoice[jejak.invoice.length - 1]

    assert.equal(
      Number(terakhir.per_page),
      25,
      'per_page harus ikut dikirim ke server',
    )
    assert.equal(Number(terakhir.page), 1, 'mengubah ukuran halaman harus kembali ke halaman 1')
  })

  it('menampilkan angka lunas dari ringkasan server, bukan dari baris halaman ini', async () => {
    /*
     * Angka ini harus datang dari `ringkasan` server.
     *
     * Kalau dijumlahkan dari `invoices`, angkanya hanya benar selama total
     * tagihan muat di satu halaman -- dan itu justru keadaan yang membuat
     * dashboard dan Tagihan terlihat sama saat diuji. Setelah 15 baris,
     * hitungan dari satu halaman menyimpang, persis seperti gejala yang
     * dilaporkan.
     */
    const wrapper = await mountHalaman()

    await flushPromises()

    const teks = wrapper.text()

    assert.match(teks, /Sudah Lunas/, 'toolbar harus menampilkan angka "Sudah Lunas"')
    assert.match(
      teks,
      /Sudah Lunas\s*7/,
      'angka lunas harus diambil dari `ringkasan`, yaitu 7 -- bukan dari 3 baris lunas di halaman ini',
    )
    assert.match(teks, /Belum Lunas\s*8/, 'angka belum lunas juga harus dari `ringkasan`')
  })

  it('menampilkan ringkasan yang sama setelah pindah halaman', async () => {
    /*
     * Kontrol untuk test di atas: kalau angkanya ikut berubah saat
     * halaman berpindah, berarti ia dihitung dari isi halaman dan bukan
     * dari server.
     */
    const wrapper = await mountHalaman()

    await flushPromises()

    const tombolBerikutnya = tombolNext(wrapper)

    assert.ok(tombolBerikutnya, 'harus ada tombol "Next" untuk pindah ke halaman berikutnya')

    await tombolBerikutnya.trigger('click')
    await flushPromises()

    assert.match(
      wrapper.text(),
      /Sudah Lunas\s*7/,
      'BUG INI: angka lunas ikut berubah saat halaman berpindah -- berarti dihitung dari satu halaman',
    )
  })

  it('meminta ulang dari server saat filter status berubah', async () => {
    const wrapper = await mountHalaman()

    await flushPromises()

    pilihStatus(wrapper, 'PAID')
    await flushPromises()

    const terakhir = jejak.invoice[jejak.invoice.length - 1]

    assert.equal(
      terakhir.status,
      'PAID',
      'filter status harus ikut dikirim ke server',
    )
    assert.equal(Number(terakhir.page), 1, 'filter baru harus mengembalikan ke halaman 1')
  })

  it('permintaan yang berhasil tidak memunculkan toast', async () => {
    await mountHalaman()

    await flushPromises()

    assert.equal(toast.length, 0, 'memuat halaman tidak boleh memunculkan toast')
  })
})
