/**
 * Uji kompatibilitas runtime tema PrimeVue.
 *
 * Yang diuji di sini bukan "build-nya hijau", tapi "CSS-nya benar-benar
 * ter-generate saat aplikasi berjalan".
 *
 * Latar Belakang
 * --------------
 * PrimeVue 4 memakai `@primeuix/styled` 0.7.x; PrimeVue 5 memakai 1.x.
 * `@primeuix/themes` 3.x (aura) mensyaratkan `styled` 1.x. Kalau tema
 * yang terpasang dan PrimeVue yang terpasang tidak berasal dari era yang
 * sama, `vite build` tetap hijau -- Vite hanya membundle, tidak pernah
 * memanggil ThemeService -- tapi saat aplikasi benar-benar berjalan,
 * komponen PrimeVue bisa tampil tanpa gaya sama sekali.
 *
 * Cara Kerjakanya
 * --------------
 * Test memasang komponen PrimeVue sungguhan (Button, Select, InputNumber,
 * Toast) dengan preset Aura yang persis dipakai `main.js`, lalu memeriksa
 * bahwa ThemeService benar-benar menghasilkan CSS untuk komponen itu.
 *
 * `Theme` diambil dari instance yang SEDANG dipakai PrimeVue -- lihat
 * catatan panjang di helper di bawah. Mengambil dari root project bisa
 * menguji instance yang salah: lulus, padahal aplikasinya rusak.
 */

import './pasangDom.js'

import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { pathToFileURL } from 'node:url'

import { createApp, h } from 'vue'
import PrimeVue from 'primevue/config'
import Button from 'primevue/button'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Toast from 'primevue/toast'
import Aura from '@primeuix/themes/aura'

import { skalaWarna, WARNA_BAWAAN } from '../src/utils/themeColor.js'

/*
 * Cari instance @primeuix/styled yang SEDANG dipakai PrimeVue.
 *
 * pnpm mengisolasi dependensi per paket: `@primeuix/styled` tidak ada di
 * `node_modules/` root frontend. Yang ada adalah symlink di dalam paket
 * `@primevue/core`, ke versi yang PrimeVue itu sendiri pakai.
 *
 * Kalau test ini mengambil styled versi lain (mis. yang paling baru di
 * tree), dia akan menguji kombinasi yang TIDAK PERNAH dipakai aplikasi --
 * hasil pemeriksaannya jadi tidak berarti.
 */
const requireDariPrimeVue = createRequire(import.meta.resolve('primevue/config'))
const requireDariCore = createRequire(requireDariPrimeVue.resolve('@primevue/core'))
const styledUrl = pathToFileURL(requireDariCore.resolve('@primeuix/styled')).href

const { Theme } = await import(styledUrl)

const OPSI = {
  prefix: 'p',
  darkModeSelector: 'none',
  cssLayer: false,
}

/**
 * Pasang aplikasi mini dengan preset Aura, sama seperti `main.js`.
 *
 * `render` Given-nya adalah komponen yang dirender aplikasi -- jadi
 * `app.mount()` langsung menghasilkan elemennya.
 */
function pasangApp(render) {
  const app = createApp({
    render: render ?? (() => h('div')),
  })
  app.use(PrimeVue, {
    theme: {
      preset: Aura,
      options: OPSI,
    },
  })
  return app
}

/**
 * CSS komponen seperti yang PrimeVue memakainya saat aplikasi berjalan.
 */
function cssKomponen(nama) {
  return Theme.getComponent(nama, {
    theme: Theme.getPreset(),
    options: Theme.getOptions(),
  })
}

test('preset Aura terpasang menghasilkan CSS untuk komponen yang dipakai aplikasi', () => {
  pasangApp()

  // Komponen yang benar-benar dipakai aplikasi:
  // Button (tombol simpan), Select (dropdown), InputNumber, Toast, DataTable.
  const komponen = ['button', 'select', 'inputnumber', 'toast', 'datatable']

  for (const nama of komponen) {
    const hasil = cssKomponen(nama)

    assert.ok(
      hasil.css && hasil.css.length > 0,
      `komponen "${nama}" harus punya CSS yang ter-generate`,
    )
  }
})

test('komponen PrimeVue ter-mount dengan gaya yang benar-benar ada', () => {
  const app = pasangApp(() =>
    h('div', [
      h(Button, { label: 'Simpan' }),
      h(InputNumber, { modelValue: 1000 }),
      h(Select, { options: [{ label: 'A', value: 'a' }], modelValue: 'a' }),
      h(Toast),
    ]),
  )
  const wadah = document.createElement('div')
  document.body.appendChild(wadah)

  app.mount(wadah)

  const tombol = wadah.querySelector('.p-button')
  assert.ok(tombol, 'tombol PrimeVue harus ter-render')

  /*
   * `Theme.getComponent()` mengembalikan dua hal yang BERBEDA:
   *
   *   `css`   -- blok `:root,:host{ --p-...: ... }`, yaitu TOKEN
   *             (nilai variabel) milik komponen itu.
   *   `style` -- aturan selektor (`.p-button { ... }`), yang berasal dari
   *             paket `@primeuix/styles` dan BUKAN dari preset.
   *
   * Yang membuktikan "tema benar-benar terpasang" adalah `css`: di
   * situ token primer preset muncul sebagai `var(--p-primary-color)`.
   * Kalau `css` kosong, komponen PrimeVue tampil tanpa token -- artinya
   * semua warnanya jatuh ke bawaan browser, bukan ke tema.
   */
  const gayaTombol = cssKomponen('button')

  assert.ok(
    gayaTombol.css.includes('var(--p-primary-color)'),
    'token tombol harus membaca --p-primary-color',
  )
  assert.ok(
    gayaTombol.css.includes('--p-button-primary-background'),
    'token tombol harus menyediakan --p-button-primary-background',
  )

  app.unmount()
  wadah.remove()
})

test('token primer preset ter-resolve ke CSS variable', () => {
  const common = Theme.getCommon('root', {
    theme: Theme.getPreset(),
    options: Theme.getOptions(),
  })

  assert.ok(common.semantic?.css, 'CSS semantik harus ter-generate')
  assert.ok(
    common.semantic.css.includes('--p-primary-color'),
    'CSS semantik harus memuat token --p-primary-color',
  )
})

test('tema tidak menghasilkan blok dark mode saat darkModeSelector none', () => {
  const common = Theme.getCommon('root', {
    theme: Theme.getPreset(),
    options: Theme.getOptions(),
  })

  const semuaCss = [
    common.primitive?.css ?? '',
    common.semantic?.css ?? '',
    common.global?.css ?? '',
  ].join('\n')

  assert.ok(
    !semuaCss.includes('prefers-color-scheme'),
    'tema tidak boleh memuat blok prefers-color-scheme (dark mode harus mati)',
  )
})

/*
 * Token yang ditulis aplikasi lewat `utils/applyTheme.js` harus benar-benar
 * ada di preset yang terpasang.
 *
 * `applyTheme.js` menulis `--p-primary-50` sampai `--p-primary-900` dan
 * `--p-primary-contrast-color` ke `document.documentElement.style`. Kalau
 * preset yang terpasang tidak punya token itu, warnanya tidak_error -- hanya
 * tidak terpakai, dan komponen PrimeVue tampil dengan warna bawaan tema
 * yang beda dari warna lokasi.
 */
test('token yang ditulis applyTheme.js ada di preset yang terpasang', () => {
  const common = Theme.getCommon('root', {
    theme: Theme.getPreset(),
    options: Theme.getOptions(),
  })

  const semuaCss = [
    common.primitive?.css ?? '',
    common.semantic?.css ?? '',
    common.global?.css ?? '',
  ].join('\n')

  const tokens = new Set(semuaCss.match(/--p-[a-zA-Z0-9-]+(?=\s*:)/g) ?? [])

  const skala = skalaWarna(WARNA_BAWAAN)
  const wajib = [
    ...Object.keys(skala).map((nama) => `--p-primary-${nama}`),
    '--p-primary-color',
    '--p-primary-contrast-color',
    '--p-text-color',
  ]

  for (const token of wajib) {
    assert.ok(tokens.has(token), `preset harus menyediakan token ${token}`)
  }
})

/*
 * Skala warna aplikasi harus tetap bisa dipakai sebagai token primer.
 *
 * Ini mengunci bahwa `applyTheme.js` dan presetPrimeVuewati agrees
 * soal nama token -- kalau salah satu berubah, warna lokasi tidak lagi
 * sampai ke komponen PrimeVue.
 */
test('warna lokasi benar-benar menimpa token primer preset', () => {
  const app = pasangApp()
  const wadah = document.createElement('div')
  document.body.appendChild(wadah)

  app.mount(wadah)

  const skala = skalaWarna('#f59e0b')

  for (const [nama, nilai] of Object.entries(skala)) {
    document.documentElement.style.setProperty(`--p-primary-${nama}`, nilai)
  }

  const gayaTombol = cssKomponen('button')

  // Token primer harus dipakai oleh gaya tombol, kalau tidak, warna
  // lokasi hanya mengganti sesuatu yang tidak pernah dibaca.
  assert.ok(
    gayaTombol.css.includes('var(--p-primary-color)'),
    'gaya tombol harus membaca --p-primary-color',
  )

  app.unmount()
  wadah.remove()
})
