/**
 * MODAL UPDATE PAKET: isi form harus sampai ke induk.
 *
 * Bug yang dikunci test ini sudah terjadi di repo: `form` cuma `prop`
 * biasa, lalu modal menulis `form.newPackageId` dan `form.reason` langsung
 * ke dalamnya.
 *
 * Dua hal rusak karena itu:
 *
 *  1. Melewati aturan Vue. `vue/no-mutating-props` menandainya, dan memang
 *     ada alasannya -- kalau someday induknya menyalin form-nya (mis. untuk
 *     validasi) sebelum submit, perubahan di modal tidak ikut terbawa.
 *
 *  2. Tidak ada jalur resmi untuk mengubah form. Induknya hanya bisa
 *     mengamatinya lewat kebetulan -- objeknya kebetulan sama, jadi
 *     kebetulan ikut berubah. Tidak ada kontrak yang mengikat keduanya.
 *
 * Perbaikannya: `form` jadi `defineModel('form')`, jadi modal mendeklarasikan
 * event `update:form` dan induk memakai `v-model:form`.
 *
 * Kenapa butuh dua sisi dicek
 * ----------------------------
 * Perilaku "form berubah" sendiri tidak bisa membedakan versi lama dari
 * baru: pada keduanya, `form.newPackageId = pkg.id` mengubah objek yang
 * sama, jadi nilainya sampai ke induk apa pun caranya. Yang membedakan
 * adalah KONTRAKNYA:
 *
 *   - versi baru: modal mendeklarasikan `update:form` sebagai emit
 *   - versi baru: induk mengikat `v-model:form`
 *
 * Kalau hanya satu sisi yang diubah, `v-model:form` jadi tidak sah, atau
 * emit-nya tidak ada yang tangkap. Test di sini memeriksa keduanya, jadi
 * perubahan separuh tidak bisa lolos.
 */

import { strict as assert } from 'node:assert'
import { after, before, describe, it } from 'node:test'

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/*
 * `vueHarness.mjs` HARUS diimpor sebelum `vue` dan `@vue/test-utils`.
 *
 * `vue` menarik `@vue/runtime-dom`, dan modul itu menangkap `document`
 * tepat satu kali saat dievaluasi. Kalau `document` belum ada saat itu,
 * `doc` null untuk SELURUH proses -- dan gejalanya muncul jauh dari
 * penyebabnya (`Cannot read properties of null (reading 'createComment')`).
 *
 * `vueHarness.mjs` mengimpor `pasangDom.js` sebagai import pertama, jadi
 * DOM sudah ada sebelum `@vue/runtime-dom` dievaluasi.
 */
import { komponen, pasangDom, tutupDom } from './vueHarness.mjs'

import { reactive } from 'vue'
import { mount } from '@vue/test-utils'

/** Lokasi berkas modal, relatif terhadap `src/`. */
const JALUR_MODAL = 'presentations/views/app/admin/instalasi/partials/PackageUpdateModal.vue'

/** Lokasi berkas induk yang memakai modal ini. */
const JALUR_INDUK = 'presentations/views/app/admin/instalasi/dataInstalasi.vue'

/**
 * Paket-paket yang dipakai modal.
 *
 * `installation_fee` dan `monthly_abodemen` dipakai modal untuk menghitung
 * selisih biaya, jadi keduanya harus ada -- kalau tidak, computed di dalam
 * modal menghitung dari `undefined` dan hasilnya `NaN`.
 */
const DAFTAR_PAKET = [
  {
    id: 1,
    name: 'Paket Rumah',
    monthly_abodemen: 100000,
    installation_fee: 50000,
  },
  {
    id: 2,
    name: 'Paket Usaha',
    monthly_abodemen: 250000,
    installation_fee: 100000,
  },
  {
    id: 3,
    name: 'Paket Grosir',
    monthly_abodemen: 500000,
    installation_fee: 200000,
  },
]

/** Komponen modalnya, dimuat sekali di `before()`. */
let PackageUpdateModal

/**
 * Mount modal dengan `Teleport` dimatikan.
 *
 * Modal ini `<Teleport>` ke `<body>`, jadi tanpa stub isinya mendarat di
 * luar wrapper dan tidak bisa diperiksa lewat `wrapper`.
 */
function mountModal(form) {
  return mount(PackageUpdateModal, {
    props: {
      show: true,
      packages: DAFTAR_PAKET,
      loading: false,
      formatRupiah: (n) => `Rp${n}`,
      // `v-model:form` = `:form` + `@update:form`
      form,
      'onUpdate:form': (nilai) => {
        Object.assign(form, nilai)
      },
    },
    global: {
      stubs: { teleport: true },
    },
  })
}

/**
 * Form kosong seperti yang dibuat `useDataInstalasi`.
 *
 * `reactive` dipakai supaya dua hal yang dicek berlaku:
 *
 *   1. state yang sama bisa dibaca dari luar setelah render
 *   2. `v-model:form` menulis ke state itu, bukan ke salinan
 */
function formAwal() {
  return reactive({
    ticketId: 99,
    currentPackageId: 1,
    newPackageId: null,
    reason: '',
  })
}

/** Sumber berkas di dalam `src/`. */
function sumberDiSrc(jalur) {
  return readFileSync(fileURLToPath(new URL(`../src/${jalur}`, import.meta.url)), 'utf-8')
}

describe('modal update paket', () => {
  before(async () => {
    await pasangDom()

    PackageUpdateModal = await komponen(JALUR_MODAL)
  })

  after(async () => {
    await tutupDom()
  })

  it('formDimodelkanSebagaiModelBukanPropBiasa', () => {
    /*
     * Sisi kontrak di anak.
     *
     * `defineModel('form')` mendaftarkan `update:form` sebagai emit dan
     * tetap menaruh `form` di daftar prop (dengan `formModifiers`).
     * Kalau `form` cuma prop biasa, daftar emit tidak akan memuat
     * `update:form` -- dan `v-model:form` di induk jadi tidak sah.
     */
    assert.ok(
      PackageUpdateModal.emits?.includes('update:form'),
      `modal harus mendeklarasikan emit 'update:form', emit yang ada: ${JSON.stringify(PackageUpdateModal.emits)}`,
    )

    assert.ok(PackageUpdateModal.props?.form, 'modal harus tetap menerima form sebagai prop')
  })

  it('indukMengikatFormDenganVModel', () => {
    /*
     * Sisi kontrak di induk.
     *
     * `v-model:form` dikompilasi jadi `:form` + `@update:form`. Kalau induknya
     * cuma mengikat `:form`, perubahan dari modal tidak punya jalur resmi
     * untuk sampai ke `updateForm` milik induk.
     */
    const sumber = sumberDiSrc(JALUR_INDUK)

    assert.ok(
      /v-model:form\s*=\s*"updateForm"/.test(sumber),
      'induk harus mengikat form dengan v-model:form="updateForm"',
    )
  })

  it('perubahanPaketDiModalSampaiKeStateInduk', async () => {
    const form = formAwal()
    const wrapper = mountModal(form)

    // Modal harus menampilkan paket yang sedang dipakai.
    const teksModal = wrapper.text()
    assert.ok(
      teksModal.includes('Paket Rumah'),
      `modal harus menampilkan paket saat ini, bukan: ${teksModal.slice(0, 200)}`,
    )

    // Pilih paket baru: klik kartu paket.
    //
    // `availablePackages` menyaring paket yang sedang dipakai, jadi hanya
    // paket lain yang bisa dipilih -- dua dari tiga paket.
    const kartu = wrapper.findAll('button.radio-card')
    assert.equal(kartu.length, 2, 'hanya paket selain yang aktif yang bisa dipilih')

    assert.ok(
      wrapper.text().includes('Paket Usaha'),
      'paket yang belum dipakai harus ikut ditawarkan',
    )

    await kartu[0].trigger('click')

    assert.equal(form.newPackageId, 2, 'paket yang dipilih di modal harus sampai ke state induk')

    // Alasan yang diketik harus sampai juga.
    const textarea = wrapper.find('textarea')
    assert.ok(textarea.exists(), 'harus ada textarea untuk alasan perubahan')

    await textarea.setValue('Pelanggan pindah ke paket usaha')
    assert.equal(
      form.reason,
      'Pelanggan pindah ke paket usaha',
      'alasan yang diketik harus sampai ke state induk',
    )

    wrapper.unmount()
  })

  it('formYangBelumMemilihPaketBaruTidakBisaDisimpan', async () => {
    const form = formAwal()
    const wrapper = mountModal(form)

    const tombolSimpan = wrapper.findAll('button').find((b) => b.text().includes('Konfirmasi'))

    assert.ok(tombolSimpan, 'harus ada tombol konfirmasi')

    assert.equal(
      tombolSimpan.attributes('disabled'),
      '',
      'tombol simpan harus nonaktif selama belum ada paket baru yang dipilih',
    )

    wrapper.unmount()
  })
})
