import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

import { previewDatabaseName } from '../src/utils/databaseName.js'

/**
 * Pratinjau nama database di form tambah lokasi.
 *
 * Fungsi ini adalah SALINAN dari `TenantManager::databaseNameFor()` di
 * backend. Salinan itu tidak bisa dihilangkan -- memanggil endpoint hanya
 * untuk menampilkan satu baris teks akan menambah satu round-trip di setiap
 * ketikan -- tapi salinan yang tidak diuji adalah salinan yang akan melenceng
 * diam-diam.
 *
 * Yang paling penting dari test di bawah adalah bahwa onset-nya benar:
 * `previewDatabaseName` harus menghasilkan nama yang PERSIS sama dengan
 * `TenantManager::databaseNameFor()` untuk setiap slug yang sah. Kalau
 * berbeda, panel menampilkan satu nama sementara provisioning membuat nama
 * yang lain, dan tenant itu tidak akan pernah bisa dibuka.
 *
 * Jalankan: `node --test frontend/test/`
 */
describe('previewDatabaseName', () => {
  it('men turunkan nama database dari slug', () => {
    assert.equal(previewDatabaseName('tirtomulo'), 'astaloka_tirtomulo')
    assert.equal(previewDatabaseName('tirto-mulo'), 'astaloka_tirto_mulo')
    assert.equal(previewDatabaseName('a-b-c-d'), 'astaloka_a_b_c_d')
  })

  it('menyamakan hyphen dan underscore, seperti backend', () => {
    // Kontrak normalisasi: `tirto-mulo` dan `tirto_mulo` menunjuk lokasi yang
    // sama, jadi nama database-nya memang harus sama.
    assert.equal(
      previewDatabaseName('tirto-mulo'),
      previewDatabaseName('tirto_mulo'),
    )
  })

  it('memotong slug lebih dari 64 karakter seperti backend', () => {
    // Batas 64 adalah batas nama database MySQL. Melewatinya tidak akan
    // ditolak dengan pesan yang berguna -- provisioning gagal DI TENGAH,
    // setelah database dibuat.
    const hasil = previewDatabaseName('a'.repeat(120))
    assert.equal(hasil.length, 64)
    assert.ok(hasil.startsWith('astaloka_'))
  })

  it('tidak pernah berakhir dengan underscore setelah dipotong', () => {
    // `rtrim` ekor underscore adalah pembeda antara `substr()`-lalu-`rtrim()`
    // dan `rtrim()`-lalu-`substr()`. Untuk prefix bawaan hasilnya sama, tapi
    // untuk prefix yang diubah keduanya tidak.
    for (const panjang of [1, 5, 20, 54, 55, 56, 63, 64, 65, 70]) {
      const hasil = previewDatabaseName('a-b'.repeat(25).slice(0, panjang))
      assert.ok(
        !hasil.endsWith('_'),
        `panjang ${panjang}: nama berakhir underscore (${hasil})`,
      )
    }
  })

  it('mengembalikan string kosong untuk slug yang tidak menghasilkan nama', () => {
    // Pratinjau kosong membuat blok "Database:" hilang, dan itu lebih baik
    // daripada menampilkan `astaloka_` yang tidak akan pernah jadi nama.
    for (const kosong of ['', '   ', '___', '---', null, undefined]) {
      assert.equal(
        previewDatabaseName(kosong),
        '',
        `input: ${String(kosong)}`,
      )
    }
  })

  it('menghormati prefix dan batas yang diberikan', () => {
    // Kasus prefix yang TIDAK berakhir underscore. Hasilnya memang
    // `astalokatirto_mulo` -- tanpa pemisah -- dan itu benar: pemisah hanya
    // ada kalau prefix menyediakannya. Yang dijaga di sini justru bahwa
    // pemotongan + pembuangan underscore tetap berlaku untuk prefix seperti
    // ini, karena di situ `substr()`-lalu-`rtrim()` dan `rtrim()`-lalu-
    // `substr()` benar-benar berbeda hasilnya.
    assert.equal(previewDatabaseName('tirto-mulo', 'astaloka'), 'astalokatirto_mulo')

    const panjang = previewDatabaseName('a-b'.repeat(30), 'astaloka')
    assert.ok(panjang.length <= 64)
    assert.ok(!panjang.endsWith('_'), `nama berakhir underscore: ${panjang}`)
  })

  it('tidak melempar pada input yang salah bentuk', () => {
    // `form.slug` selalu string dari `<input>`, tapi form ini juga dipakai
    // ulang setelah `Object.assign` dari respons API. Kalau `slug` di sana
    // `null`, pratinjau tidak boleh membuat seluruh halaman gagal render.
    for (const salah of [null, undefined, 0, false, {}, []]) {
      assert.equal(typeof previewDatabaseName(salah), 'string')
    }
  })
})