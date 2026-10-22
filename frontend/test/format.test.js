import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

import {
  formatAngka,
  formatRupiah,
  formatRupiahDecimal,
  formatRupiahNegatif,
  formatRupiahShort,
  parseAngkaUang,
} from '../src/utils/format.js'

/**
 * `formatRupiah` pernah ada di sembilan berkas, dan tiga perilaku berbeda
 * muncul dari sembilan salinan itu. Test ini mengunci bentuk yang sekarang
 * jadi satu-satunya, supaya view berikutnya yang menulis pemformatan ulang
 * akan langsung terlihat bedanya.
 *
 * Jalankan: `node --test frontend/test/`
 */
describe('formatRupiah', () => {
  it('memakai pemisah ribuan titik dan awalan Rp', () => {
    assert.equal(formatRupiah(1500), 'Rp 1.500')
    assert.equal(formatRupiah(1234567), 'Rp 1.234.567')
  })

  it('memakai spasi biasa, bukan non-breaking space', () => {
    // `Intl` memisahkan "Rp" dan angka dengan U+00A0. Kalau dibiarkan,
    // `startsWith('Rp ')` bernilai FALSE dan ekspor CSV bisa memunculkan
    // kotak di kolom nominal.
    assert.ok(!formatRupiah(1500).includes(' '))
  })

  it('tidak pernah melempar pada input yang salah bentuk', () => {
    // Input dari API pusat selalu bisa null saat ringkasan belum termuat,
    // dan `null` yang dijemput `toLocaleString` menghasilkan "null", bukan 0.
    for (const salah of [null, undefined, '', 'abc', NaN, {}, []]) {
      assert.equal(formatRupiah(salah), 'Rp 0', `input: ${String(salah)}`)
    }
  })

  it('membulatkan ke rupiah penuh, bukan ke desimal', () => {
    assert.equal(formatRupiah(1234.6), 'Rp 1.235')
    assert.equal(formatRupiah(1234.4), 'Rp 1.234')
  })

  it('mempertahankan tanda minus untuk nilai negatif', () => {
    // Backend mengirim saldo negatif apa adanya. Menyembunyikan tandanya
    // di sini membuat angka yang salah terlihat seperti yang benar.
    assert.equal(formatRupiah(-1500), '-Rp 1.500')
  })
})

describe('formatRupiahDecimal', () => {
  it('dua pecahan tanpa simbol', () => {
    assert.equal(formatRupiahDecimal(1500), '1.500,00')
  })

  it('bisa ditambah simbolnya', () => {
    assert.equal(formatRupiahDecimal(1500, { withSymbol: true }), 'Rp 1.500,00')
  })
})

describe('formatAngka', () => {
  it('tanpa simbol, sesuai judul kolom yang sudah menyebut satuan', () => {
    assert.equal(formatAngka(1500), '1.500')
  })

  it('bisa dua pecahan', () => {
    assert.equal(formatAngka(1500, { fractionDigits: 2 }), '1.500,00')
  })
})

describe('formatRupiahNegatif', () => {
  it('memakai kurung, bukan tanda minus', () => {
    // Laporan keuangan di Indonesia memakai kurung. Tanda minus di kolom
    // nominal terbaca sebagai pengurangan pada konteks yang sudah menandai
    // saldo minus.
    assert.equal(formatRupiahNegatif(-1500), '(1.500,00)')
    assert.equal(formatRupiahNegatif(1500), '1.500,00')
    assert.equal(formatRupiahNegatif(0), '0,00')
  })
})

describe('formatRupiahShort', () => {
  it('memakai satuan Indonesia', () => {
    assert.equal(formatRupiahShort(1_500_000), 'Rp 1,5jt')
    assert.equal(formatRupiahShort(1_500_000_000), 'Rp 1,5M')
  })

  it('memakai koma sebagai pemisah desimal, bukan titik', () => {
    // `toFixed(1)` menghasilkan "1.5", dan titik itu sudah dipakai sebagai
    // pemisah ribuan di tempat lain. Angka 1.500.000 dan 1,5jt adalah
    // berbeda 1000 kali.
    assert.equal(formatRupiahShort(1_523_400), 'Rp 1,5jt')
  })

  it('membulatkan ribuan ke ribuan terdekat', () => {
    assert.equal(formatRupiahShort(1_500), 'Rp 2rb')
  })

  it('di bawah ribuan ditampilkan utuh', () => {
    assert.equal(formatRupiahShort(999), 'Rp 999')
  })

  it('nol tidak jadi "Rp 0rb"', () => {
    assert.equal(formatRupiahShort(0), 'Rp 0')
    assert.equal(formatRupiahShort(null), 'Rp 0')
  })
})

describe('parseAngkaUang', () => {
  it('membaca kedua gaya penulisan sebagai angka yang sama', () => {
    assert.equal(parseAngkaUang('1.234,56'), 1234.56)
    assert.equal(parseAngkaUang('1234.56'), 1234.56)
    assert.equal(parseAngkaUang('1,234.56'), 1234.56)
  })

  it('membaca `1.500` sebagai seribu lima ratus, bukan satu koma lima', () => {
    // Ini yang paling penting di fungsi ini. Salah baca di sini berarti
    // nominal tersimpan seribu kali lebih kecil, dan tidak ada satu pun
    // tanda yang menandainya di layar.
    assert.equal(parseAngkaUang('1.500'), 1500)
    assert.equal(parseAngkaUang('1,500'), 1500)
    assert.equal(parseAngkaUang('Rp 1.500'), 1500)
  })

  it('tetap membaca desimal yang benar-benar desimal', () => {
    assert.equal(parseAngkaUang('1.5'), 1.5)
    assert.equal(parseAngkaUang('1.50'), 1.5)
    assert.equal(parseAngkaUang('1,5'), 1.5)
  })

  it('membaca pemisah ribuan yang berulang', () => {
    assert.equal(parseAngkaUang('1.500.000'), 1_500_000)
    assert.equal(parseAngkaUang('1,500,000'), 1_500_000)
    assert.equal(parseAngkaUang('1.234.567.890'), 1_234_567_890)
  })

  it('membuang karakter yang bukan bagian angka', () => {
    assert.equal(parseAngkaUang('  750  '), 750)
    assert.equal(parseAngkaUang('abc'), 0)
    assert.equal(parseAngkaUang(''), 0)
    assert.equal(parseAngkaUang(null), 0)
    assert.equal(parseAngkaUang(undefined), 0)
  })

  it('mempertahankan tanda minus', () => {
    assert.equal(parseAngkaUang('-1.500'), -1500)
    assert.equal(parseAngkaUang('-1.234,56'), -1234.56)
  })

  it('menerima angka apa adanya tanpa mengubahnya', () => {
    assert.equal(parseAngkaUang(1234.56), 1234.56)
    assert.equal(parseAngkaUang(0), 0)
  })
})

describe('bolak-balik format dan parse', () => {
  it('angka bulat kembali ke dirinya sendiri', () => {
    // Ini yang dipakai form edit: nilai tampil dibaca ulang lalu dikirim
    // balik. Kalau bolak-baliknya tidak stabil, setiap penyimpanan diam-diam
    // mengubah nominal.
    for (const n of [1, 999, 1_000, 1_500, 1_234_567, 1_234_567_890]) {
      assert.equal(parseAngkaUang(formatAngka(n)), n, `nilai: ${n}`)
      assert.equal(parseAngkaUang(formatRupiah(n)), n, `nilai: ${n}`)
    }
  })
})