import { strict as assert } from 'node:assert'
import { after, before, describe, it } from 'node:test'

import { komponen, pasangDom, tutupDom } from './vueHarness.mjs'

/*
 * APAKAH IKON DI POP-UP TAGIHAN BENAR-BENAR TER-RENDER?
 *
 * Yang diuji di sini bukan "apakah ikonnya terdaftar di library" -- itu
 * sudah dijamin oleh `main.js`. Yang diuji adalah HASIL AKHIR: apakah
 * `<font-awesome-icon>` benar-benar menghasilkan `<svg>` di dalam DOM.
 *
 * Alasannya penting. FontAwesome TIDAK pernah gagal diam-diam: kalau nama
 * ikon tidak ada di `library`, komponennya merender `<svg>` KOSONG (tanpa
 * `<path>`). Jadi "ikon tidak terlihat" punya dua sebab yang sama sekali
 * berbeda:
 *
 *   1. Ikon tidak terdaftar -> svg kosong.
 *   2. Ikon terdaftar tapi mewarisi `color` yang sama dengan latar ->
 *      svg berisi path, tapi tidak terlihat.
 *
 * Keduanya/gejalanya sama di layar: "ikonnya tidak muncul". Test ini
 * membedakan keduanya, karena perbaikannya beda total.
 *
 * TEST INI memakai komponen FontAwesome ASLI (bukan stub `<i />`), dan
 * `library.add()` sungguhan. Stub tidak bisa menjawab pertanyaan ini:
 * stub selalu merender sesuatu, jadi stub selalu "lulus" apa pun yang
 * terjadi di produksi.
 */

let mount
let flushPromises
let FontAwesomeIcon
let library
let fa
let CentralTagihanPopup

before(async () => {
  await pasangDom()

  const testUtils = await import('@vue/test-utils')
  mount = testUtils.mount
  flushPromises = testUtils.flushPromises

  const core = await import('@fortawesome/fontawesome-svg-core')
  const vueFa = await import('@fortawesome/vue-fontawesome')
  fa = await import('@fortawesome/free-solid-svg-icons')

  library = core.library
  FontAwesomeIcon = vueFa.FontAwesomeIcon

  /*
   * Daftar ikon yang dipakai pop-up tagihan, ditambah beberapa ikon lain
   * yang dipakai halaman panel pusat -- supaya test ini gagal kalau ada
   * ikon yang belum terdaftar, bukan cuma kalau ikon pop-up yang hilang.
   */
  library.add(
    fa.faCircleCheck,
    fa.faTriangleExclamation,
    fa.faFileInvoiceDollar,
    fa.faXmark,
    fa.faPaperPlane,
    fa.faClock,
    fa.faBuildingColumns,
    fa.faPenToSquare,
    fa.faPrint,
    fa.faCheck,
    fa.faRotateLeft,
    fa.faChevronRight,
    fa.faMagnifyingGlass,
    fa.faSpinner,
  )

  CentralTagihanPopup = await komponen(
    'presentations/views/central/components/CentralTagihanPopup.vue',
  )
})

after(async () => {
  await tutupDom()
})

/** Satu invoice, bentuk sama seperti `present()` di backend. */
function invoice(status = 'UNPAID') {
  return {
    id: 1,
    nomor: 'INV/2026/0001',
    tenant: { id: 1, slug: 'pdam', nama: 'PDAM Uji', label: 'PDAM Uji' },
    jumlah: 10000,
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
 * Mount pop-up dengan komponen FontAwesome sungguhan.
 *
 * `teleport` dimatikan supaya isinya tetap di dalam wrapper -- kalau tidak,
 * elemennya mendarat di `<body>` dan tidak bisa diperiksa lewat `wrapper`.
 */
function mountPopup(inv) {
  return mount(CentralTagihanPopup, {
    props: { tampil: true, invoice: inv },
    global: {
      stubs: { teleport: true },
      components: { 'font-awesome-icon': FontAwesomeIcon },
    },
  })
}

/** Semua `<svg>` yang dirender, dengan atribut class-nya. */
function svgDi(wrapper) {
  return wrapper.findAll('svg')
}

describe('ikon di pop-up detail tagihan', () => {
  it('merender svg yang berisi path, bukan svg kosong', async () => {
    const wrapper = mountPopup(invoice())

    await flushPromises()

    const svg = svgDi(wrapper)

    assert.ok(svg.length > 0, 'pop-up harus merender minimal satu ikon')

    /*
     * Ini inti testnya. `<svg>` tanpa `<path>` = ikon tidak terdaftar di
     * library; FontAwesome merender kerangka kosong dalam kasus itu, dan
     * hasilnya di layar persis sama dengan "ikonnya hilang".
     */
    const kosong = svg.filter((s) => s.findAll('path').length === 0)

    assert.equal(
      kosong.length,
      0,
      `${kosong.length} ikon merender svg KOSONG (tanpa <path>) -- ikonnya tidak terdaftar di library FontAwesome`,
    )

    const isi = svg.map((s) => s.findAll('path').length)

    console.log('    ikon ter-render:', svg.length, '| path per ikon:', isi.join(','))
  })

  it('memiliki ikon pada setiap tempat yang seharusnya punya ikon', async () => {
    const wrapper = mountPopup(invoice())

    await flushPromises()

    const svg = svgDi(wrapper)

    console.log('    total svg:', svg.length)

    /*
     * Pop-up punya: mark (1), chip (0-1), close (1), 4-5 ikon di facts,
     * 1-2 ikon di kaki. Kalau jumlahnya turun drastis, ada ikon yang
     * hilang -- dan ini yang akanmemberi tahu letaknya.
     */
    assert.ok(
      svg.length >= 6,
      `hanya ${svg.length} ikon ter-render di pop-up -- seharusnya ada minimal 6 (mark, close, facts, kaki)`,
    )
  })

  it('ikon di kaki pop-up (Cetak / Tandai Lunas) benar-benar ada', async () => {
    const wrapper = mountPopup(invoice())

    await flushPromises()

    const kaki = wrapper.find('.tp__foot')

    assert.ok(kaki.exists(), 'kaki pop-up harus ada')

    const ikonKaki = kaki.findAll('svg')

    console.log('    ikon di kaki:', ikonKaki.length)

    assert.ok(ikonKaki.length >= 2, 'kaki pop-up harus punya ikon Cetak dan Tandai Lunas')
  })

  it('ikon di facts (Terbit / Jatuh Tempo / Dibayar) benar-benar ada', async () => {
    const wrapper = mountPopup(invoice())

    await flushPromises()

    const facts = wrapper.find('.tp__facts')

    assert.ok(facts.exists(), 'daftar fakta harus ada')

    const ikonFacts = facts.findAll('svg')

    console.log('    ikon di facts:', ikonFacts.length)

    assert.ok(ikonFacts.length >= 3, 'fakta harus punya ikon Terbit, Jatuh Tempo, dan Dibayar')
  })

  /*
   * BUG INI: ikon penanda di header hilang.
   *
   * Ikon itu datang dari:
   *
   *     const { ikonTunggakan, toneTunggakan } = computed(() => ({ ... }))
   *
   * Destruktur terhadap `ComputedRef` itu SALAH -- yang di-destructure adalah
   * objek ref-nya, bukan isi yang dikembalikan. `ikonTunggakan` jadi
   * `undefined`, lalu `<font-awesome-icon :icon="undefined" />` merender svg
   * KOSONG (tanpa `<path>`).
   *
   * Gejalanya tidak mencolok: elemennya tetap ada di DOM, tidak ada error
   * yang mengubah tampilan halaman, dan tempatnya masih menyisikan ruang --
   * jadi yang hilang hanya gambarnya.
   *
   * Test ini sengaja memakai komponen FontAwesome ASLI. Stub `<i />` yang
   * biasa dipakai test lain akan selalu merender sesuatu, jadi stub tidak
   * bisa membedakan "ikon ada" dari "ikon ter-render tapi kosong".
   */
  it('BUG INI: ikon penanda di header tidak boleh undefined', async () => {
    const wrapper = mountPopup(invoice())

    await flushPromises()

    const mark = wrapper.find('.tp__mark')

    assert.ok(mark.exists(), 'kotak penanda di header harus ada')

    const svg = mark.find('svg')

    assert.ok(svg.exists(), 'BUG INI: kotak penanda harus punya ikon')

    assert.ok(
      svg.findAll('path').length > 0,
      'BUG INI: ikon penanda ter-render KOSONG (svg tanpa <path>) -- '
        + '`ikonTunggakan` bernilai `undefined` karena `computed` di-destructure. '
        + 'Perbaikannya: pakai `computed(() => penanda.value.ikon)`, bukan '
        + '`const { ikonTunggakan } = computed(...)`.',
    )
  })

  it('ikon penanda mengikuti status tagihan', async () => {
    /*
     * Selain "tidak kosong", identitas ikonnya ikut diperiksa: penanda
     * tagihan belum lunas adalah `file-invoice-dollar`, sedangkan yang lunas
     * `circle-check`. Kalau keduanya tertukar, ikonnya tetap "ada" -- tapi
     * artinya jadi terbalik.
     */
    const belumLunas = mountPopup(invoice('UNPAID'))
    const lunas = mountPopup(invoice('PAID'))

    await flushPromises()

    const dataIkon = (wrapper) => wrapper.find('.tp__mark svg').attributes('data-icon')

    console.log('    belum lunas:', dataIkon(belumLunas), '| lunas:', dataIkon(lunas))

    assert.equal(
      dataIkon(belumLunas),
      'file-invoice-dollar',
      'tagihan belum lunas harus memakai ikon file-invoice-dollar',
    )
    assert.equal(dataIkon(lunas), 'circle-check', 'tagihan lunas harus memakai ikon circle-check')
  })

  it('tagihan terlambat memakai ikon peringatan', async () => {
    const terlambat = {
      ...invoice('UNPAID'),
      terlambat: true,
      sisa_hari: -5,
    }

    const wrapper = mountPopup(terlambat)

    await flushPromises()

    const dataIkon = wrapper.find('.tp__mark svg').attributes('data-icon')

    console.log('    terlambat:', dataIkon)

    assert.equal(
      dataIkon,
      'triangle-exclamation',
      'tagihan terlambat harus memakai ikon triangle-exclamation',
    )
  })
})
