/**
 * Penjaga lisensi PrimeVue.
 *
 * Latar Belakang
 * --------------
 * PrimeVue 5 (major) TIDAK lagi MIT. Paket `primevue@5` menarik
 * `@primeui/license-manager`, dan `primevue/config` memanggil
 * `verifyLicense()` saat plugin dipasang.
 *
 * Kalau key tidak dikonfigurasi, hasilnya:
 *
 *   1. console warning "[PrimeUI] PrimeUI license is not configured."
 *   2. banner merah "Invalid PrimeUI License" DITEMPEL di pojok kanan
 *      bawah setiap halaman.
 *
 * Yang kedua itu yang berbahaya: ia dirender ke `document.body` DI LUAR
 * aplikasi, jadi tidak bisa disembunyikan dari CSS aplikasi dan tidak
 * hilang saat pengguna menutup modal atau pindah halaman.
 *
 * PrimeVue 4 tidak punya mekanisme ini sama sekali -- `license: MIT`,
 * tanpa `@primeui/license-manager`.
 *
 * Kenapa ini jadi test
 * --------------------
 * Upgrade ke v5 terlihat "hijau" dari sisi mana pun:
 *   - build sukses
 *   - 181 test lulus
 *   - eslint 0 error
 *   - semua komponen masih hidup dan punya styling
 *
 * Tidak ada satu pun dari itu yang menangkap banner yang menempel di
 * pojok layar. Kalau v5 terpasang tanpa keputusan lisensi yang sadar,
 * test ini gagal -- dan itu memang yang seharusnya terjadi.
 *
 * Test ini sengaja TIDAK menguji PrimeVue 5 secara umum. Yang diuji
 * satu hal: "apakah repo ini berada dalam kondisi berlisensi, atau
 * belum?" Kalau jawabannya berubah, itu harus jadi keputusan sadar.
 */
import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { pathToFileURL } from 'node:url'

// Harness DOM harus terpasang sebelum Vue diimpor.
await import(pathToFileURL(path.resolve('test/pasangDom.js')).href)

const { h, nextTick } = await import('vue')
const { mount } = await import('@vue/test-utils')

const pkg = JSON.parse(readFileSync(path.resolve('node_modules/primevue/package.json'), 'utf8'))

/**
 * PrimeVue 5 = punya license manager.
 *
 * Dicek dari dependency paket, bukan dari nomor versi -- nomor versi
 * bisa luput, dependency tidak.
 */
const PUNYA_LICENSE_MANAGER = Boolean(
  pkg.dependencies && pkg.dependencies['@primeui/license-manager'],
)

test('versi PrimeVue yang terpasang diketahui dan MIT atau punya license manager', () => {
  assert.ok(pkg.version, 'versi primevue harus terbaca')

  if (Number(pkg.version.split('.')[0]) >= 5) {
    assert.ok(
      PUNYA_LICENSE_MANAGER,
      `primevue ${pkg.version} major >= 5 WAJIB punya @primeui/license-manager`,
    )
  } else {
    assert.ok(
      !PUNYA_LICENSE_MANAGER,
      `primevue ${pkg.version} major < 5 tidak boleh punya @primeui/license-manager`,
    )
  }
})

test('aplikasi tidak menampilkan banner "Invalid PrimeUI License"', async () => {
  const PrimeVue = (await import('primevue/config')).default
  const Aura = (await import('@primeuix/themes/aura')).default
  const Button = (await import('primevue/button')).default

  const wrapper = mount(
    { render: () => h('div', [h(Button, { label: 'Simpan' })]) },
    {
      global: {
        plugins: [
          [
            PrimeVue,
            {
              theme: {
                preset: Aura,
                options: {
                  prefix: 'p',
                  darkModeSelector: 'none',
                  cssLayer: false,
                },
              },
            },
          ],
        ],
      },
    },
  )

  await nextTick()
  // Verifikasi lisensi selesai di promise, jadi tunggu siklus event.
  await new Promise((r) => setTimeout(r, 300))
  await nextTick()

  /*
   * Banner dirender di shadow root mode `closed`, jadi isinya tidak
   * bisa dibaca dari luar. Yang bisa diperiksa adalah host-nya:
   * `document.getElementById('p-license-host')`.
   *
   * Kalau elemen ini ada, banner sudah menempel di body.
   */
  const host = globalThis.document.getElementById('p-license-host')

  const adaBanner = Boolean(host)

  // Bersihkan supaya test lain tidak ikut terpengaruh.
  if (host) host.remove()
  wrapper.unmount()

  assert.equal(
    adaBanner,
    false,
    adaBanner
      ? 'Banner "Invalid PrimeUI License" muncul di halaman. ' +
          'PrimeVue 5 butuh license key yang dikonfigurasi lewat ' +
          'app.use(PrimeVue, { license: ... }). Tanpa itu, banner merah ' +
          'menempel di pojok kanan bawah SETIAP halaman.'
      : 'ok',
  )
})

test('PrimeVue yang dipakai tetap MIT, atau lisensinya sudah diurus', () => {
  /*
   * Dua paket yang bisa_selected ke PrimeUI:
   *   - `primevue` >= 5
   *   - `@primeuix/themes` >= 3
   *
   * Keduanya menarik `@primeui/license-manager` (atau setidaknya
   * `@primeuix/styled` 1.x yang Depending-nya). Keduanya juga berubah
   * lisensi dari MIT ke "SEE LICENSE IN LICENSE.md".
   *
   * Untuk `primevue` 5 ada banner yang menempel di halaman. Untuk
   * `themes` 3.x yang dipakai bareng primevue 4, banner TIDAK muncul --
   * tapi paketnya tetap non-MIT, dan kombinasi styled 1.x dengan
   * primevue 4 (yang masih pakai styled 0.7.x) adalah kombinasi yang
   * tidak didukung.
   *
   * Jadi untuk themes, pemeriksaan di sini bukan soal banner, tapi
   * soal: jangan sampai repo ini diam-diam memakai paket non-MIT.
   */
  const themes = JSON.parse(
    readFileSync(path.resolve('node_modules/@primeuix/themes/package.json'), 'utf8'),
  )

  const themesMajor = Number(themes.version.split('.')[0])
  const themesNonMit = themes.license !== 'MIT'

  if (Number(pkg.version.split('.')[0]) < 5 && themesMajor < 3) {
    assert.equal(pkg.license, 'MIT', 'primevue 4 dan sebelumnya harus tetap MIT')
    assert.equal(themes.license, 'MIT', '@primeuix/themes 2.x harus tetap MIT')
    return
  }

  /*
   * PrimeVue 5 / themes 3.x: lisensi tidak lagi MIT. Yang boleh terjadi
   * adalah repo ini SUDAH sengaja pinning ke versi itu DAN sudah
   * menyertakan kunci lisensinya di konfigurasi aplikasi.
   *
   * Karena kunci lisensi tidak ada di repo (benar -- ini rahasia),
   * yang bisa dicek di sini adalah: apakah ada tempat untuk menaruh
   * kunci itu?
   *
   * Selama `app.use(PrimeVue, { license: ... })` belum ada, test ini
   * gagal dengan pesan yang menjelaskan apa yang harus dilakukan.
   */
  const mainSrc = readFileSync(path.resolve('src/main.js'), 'utf8')

  const adaSlotLisensi = /license\s*:/.test(mainSrc)

  const paketNonMit = []
  if (Number(pkg.version.split('.')[0]) >= 5) paketNonMit.push(`primevue ${pkg.version}`)
  if (themesNonMit) paketNonMit.push(`@primeuix/themes ${themes.version}`)

  assert.ok(
    adaSlotLisensi,
    `${paketNonMit.join(' dan ')} butuh license key. ` +
      'Tambahkan `license: <kunci>` pada app.use(PrimeVue, ...) di src/main.js, ' +
      'atau turunkan ke primevue 4 + themes 2.x (keduanya MIT). Tanpa itu, ' +
      'banner merah "Invalid PrimeUI License" muncul di setiap halaman.',
  )
})
