import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

import {
  WARNA_BAWAAN,
  hexToHsl,
  hslToHex,
  normalisasiHex,
  skalaWarna,
  warnaTeksDiAtas,
  gradienBrand,
  tripletRgb,
} from '../src/utils/themeColor.js'

/**
 * Warna lokasi menentukan identitas SELURUH aplikasi lokasi (sidebar,
 * tombol, tabel, komponen PrimeVue). Kalau satu turunannya salah bentuk,
 * yang terlihat bukan satu tombol yang salah warna -- tapi dua warna yang
 * tidak pernah sengaja dipilih berdiri bersebelahan.
 *
 * Test di sini mengunci sifat yang tidak boleh berubah: bentuk hex yang
 * diterima, kebalikan hex <-> HSL, dan urutan lightness skala 50-900.
 *
 * Jalankan: `node --test frontend/test/`
 */
describe('normalisasiHex', () => {
  it('menerima hanya 7 karakter: # lalu 6 digit heksadesimal', () => {
    assert.equal(normalisasiHex('#0ea5e9'), '#0ea5e9')
    assert.equal(normalisasiHex('#0EA5E9'), '#0ea5e9')
    assert.equal(normalisasiHex('  #0ea5e9  '), '#0ea5e9')
  })

  it('menolak bentuk yang tidak lengkap, bukan memperbaikinya', () => {
    /*
     * Enam karakter tanpa `#` DITOLAK, bukan diterima lalu ditambah `#`.
     *
     * Nilai ini dipakai apa adanya di `color-mix()` dan di token CSS, jadi
     * bentuk yang berbeda menghasilkan warna yang berbeda tanpa error apa
     * pun yang menyinggungnya -- persisonian yang paling sulit dilacak.
     */
    for (const salah of ['0ea5e9', '#0ea5e', '#0ea5e99', '', '#gggggg', 'rgb(0,0,0)']) {
      assert.equal(normalisasiHex(salah), null, `input: ${JSON.stringify(salah)}`)
    }
  })

  it('menolak nilai yang bukan string', () => {
    for (const salah of [null, undefined, 123, {}, [], true]) {
      assert.equal(normalisasiHex(salah), null, `input: ${String(salah)}`)
    }
  })
})

describe('hexToHsl dan hslToHex', () => {
  it('mengembalikan null untuk hex yang tidak sah, bukan menebak', () => {
    assert.equal(hexToHsl('#zzz'), null)
    assert.equal(hexToHsl(null), null)
  })

  it('membalik warna itu sendiri tanpa mengubahnya', () => {
    for (const hex of [WARNA_BAWAAN, '#10b981', '#ef4444', '#f59e0b', '#1e293b']) {
      const { h, s, l } = hexToHsl(hex)
      assert.equal(hslToHex(h, s, l), hex, `hex: ${hex}`)
    }
  })

  it('membaca abu-abu sebagai tanpa hue, lightness saja', () => {
    /*
     * `s` = 0 untuk abu-abu. Tanpa jalur khusus ini, pembagiannya
     * `1 - |2L - 1|` menghasilkan 0 (untuk L = 50%) atau mendekati 0,
     * dan hasil akhirnya `NaN` yang berjalan tanpa error sampai rgb-nya
     * jadi "NaNNaNNaN".
     */
    const abuAbu = hexToHsl('#808080')

    assert.equal(abuAbu.s, 0)
    assert.equal(hslToHex(abuAbu.h, 0, 50), '#808080')
  })

  it('membatasi hue, saturation, dan lightness ke 0-100/0-360', () => {
    assert.equal(hslToHex(-30, 50, 50), hslToHex(330, 50, 50))
    assert.equal(hslToHex(720, 50, 50), hslToHex(0, 50, 50))
    assert.equal(hslToHex(200, 400, 50), hslToHex(200, 100, 50))
    assert.equal(hslToHex(200, 50, 900), hslToHex(200, 50, 100))
  })
})

describe('skalaWarna', () => {
  it('nilai 500 ADALAH warna yang dipilih admin', () => {
    /*
     * Ini yang paling sering dianggap detail kecil dan sebenarnya bukan.
     * Kalau 500 digeser sedikit saja, admin yang memilih hijau tidak pernah
     * melihat hijau yang dia pilih di tombol -- dan warna yang tampil
     * selalu jadi "dekat hijau" tanpa pernah bisa ditebakbegitu saja.
     */
    assert.equal(skalaWarna('#10b981')['500'], '#10b981')
    assert.equal(skalaWarna(WARNA_BAWAAN)['500'], WARNA_BAWAAN)
  })

  it('memiliki seluruh kunci yang dipakai Aura dan PrimeVue', () => {
    const skala = skalaWarna(WARNA_BAWAAN)

    for (const kunci of [50, 100, 200, 300, 400, 500, 600, 700, 800, 900]) {
      assert.ok(skala[kunci], `kunci ${kunci} tidak ada`)
      assert.match(skala[kunci], /^#[0-9a-f]{6}$/, `kunci ${kunci} bukan hex yang sah`)
    }
  })

  it('monoton: makin besar angkanya, makin gelap warnanya', () => {
    /*
     * Token dipakai berurutan naik (latar 50, teks di atasnya 600, teks
     * utama 800), jadi huruf besarnya harus_sekali-kalinya mengikuti
     * lightness. Skala yang tidak monoton membuat tombol pada token 700
     * lebih terang dari teksnya pada token 300.
     */
    const urutan = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900]

    for (const hex of ['#10b981', '#f59e0b', '#8b5cf6', '#0ea5e9']) {
      const skala = skalaWarna(hex)

      for (let i = 1; i < urutan.length; i += 1) {
        const sebelum = hexToHsl(skala[urutan[i - 1]]).l
        const sesudah = hexToHsl(skala[urutan[i]]).l

        assert.ok(
          sesudah < sebelum,
          `${hex}: ${urutan[i - 1]} (L ${sebelum}) harus lebih terang dari ${urutan[i]} (L ${sesudah})`,
        )
      }
    }
  })

  it('menjepit lightness ke 0-100, tidak meluber', () => {
    /*
     * Warna yang sangat terang (kuning pucat) akan melewati 100 kalau tidak
     * dijepit, dan `hslToHex` akan menjepitnya sendiri -- hasilnya 500 bukan
     * lagi warnanya sendiri, dan seluruh skala ikut bergeser. Warna yang
     * sangat gelap punya masalah sama di ujung yang lain.
     */
    const terang = skalaWarna('#fde68a')

    assert.equal(terang['500'], '#fde68a')
    assert.ok(hexToHsl(terang['50']).l <= 100)
    assert.ok(hexToHsl(terang['900']).l >= 0)
  })

  it('memakai warna bawaan untuk hex yang tidak sah, bukan melempar', () => {
    // Branding yang gagal dimuat harus berakhir di warna bawaan, bukan
    // membuat seluruh tema hilang tanpa satu pun pesan.
    assert.deepEqual(skalaWarna('bukan-warna'), skalaWarna(WARNA_BAWAAN))
    assert.deepEqual(skalaWarna(null), skalaWarna(WARNA_BAWAAN))
  })

  it('ke-9 token tetap berbeda untuk warna terang yang masih punya rona', () => {
    /*
     * Ini yang PERNAH rusak, dan gejalanya tidak terlihat di layar penuh.
     *
     * Warna ungu `#8b5cf6` punya lightness 61%. Dengan langkah absolut
     * ("50 = +46"), token 50 dijepit di 100 dan token 100 ikut ke 100 --
     * keduanya putih yang sama. Latar badge dan hover button kehilangan
     * pembeda, dan tombol `secondary` ikut jadi warna utama karena
     * gradiennya berujung di token yang sudah putih.
     *
     * Yang diuji bukan warnanya (hex boleh berubah sewaktu-waktu kalau
     * langkahnya disesuaikan), tapi syaratnya: tidak ada dua token yang
     * lightness-nya sama.
     *
     * `#ffffff` SENGAJA tidak termasuk daftar ini. Putih sudah di lightness
     * 100 dan saturation 0: tidak ada warna yang lebih putih darinya, dan
     * tidak ada rona untuk dipertahankan. Lima tokenlighter dari putih
     * memang akan sama -- itu tidak bisa diperbaiki tanpa mengarang
     * warna yang tidak ada hubungannya dengan yang dipilih. Untuk warna
     * putih, token yang sama adalah jawaban yang benar, bukan kegagalan.
     * Yang harus dijaga untuk putih dan hitam hanya: 500 tetap utuh, dan
     * urutan lightness tetap turun (diuji test di sebelahnya).
     */
    for (const hex of ['#8b5cf6', '#f59e0b', '#fde68a', '#93c5fd', '#f0abfc']) {
      const skala = skalaWarna(hex)
      const lightness = Object.values(skala).map((w) => hexToHsl(w).l)

      const unik = new Set(lightness)

      assert.equal(
        unik.size,
        lightness.length,
        `${hex}: ada ${lightness.length - unik.size} token yang lightness-nya sama`,
      )
    }
  })

  it('menjaga 500 tetap utuh untuk warna paling terang dan paling gelap', () => {
    // Warna putih tidak punya ruang bergerak ke terang dan warna hitam tidak
    // punya ruang bergerak ke gelap. Tanpa penjepitan di `hslToHex` saja,
    // token 500 akan ikut bergeser dan warna yang dipilih admin hilang.
    assert.equal(skalaWarna('#ffffff')['500'], '#ffffff')
    assert.equal(skalaWarna('#000000')['500'], '#000000')
  })

  it('tetap monoton bahkan untuk putih dan hitam', () => {
    /*
     * Untuk warna yang tidak punya ruang di salah satu ujungnya, sisi itu
     * memang tidak bergerak -- tapi sisi lain harus tetap jalan, dan urutannya
     * tidak boleh terbalik. Tanpa ini, token gelap dari putih akan ikut
     * rusak karena "ruang" yang dihitung keliru.
     */
    for (const hex of ['#ffffff', '#000000', '#808080']) {
      const skala = skalaWarna(hex)
      const urutan = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900]

      for (let i = 1; i < urutan.length; i += 1) {
        const sebelum = hexToHsl(skala[urutan[i - 1]]).l
        const sesudah = hexToHsl(skala[urutan[i]]).l

        assert.ok(
          sesudah <= sebelum,
          `${hex}: ${urutan[i]} (L ${sesudah}) tidak boleh lebih terang dari ${urutan[i - 1]} (L ${sebelum})`,
        )
      }
    }
  })
})

describe('warnaTeksDiAtas', () => {
  it('memilih kontras yang lebih tinggi, bukan "terang berarti putih"', () => {
    /*
     * Warna kuning pucat adalah kasus yang paling sering salah: jelas
     * terang di mata, tapi teks putih di atasnya tidak terbaca. Kalau
     * keputusannya ditebak dari kecerahan yang dilihat mata, tombolnya
     * terlihat "terang" tapi isinya hilang tepat saat dipakai.
     */
    assert.equal(warnaTeksDiAtas('#fde68a'), '#1e293b')
    assert.equal(warnaTeksDiAtas('#1e40af'), '#ffffff')
  })

  it('hanya mengembalikan dua nilai itu', () => {
    for (const hex of ['#0ea5e9', '#10b981', '#ef4444', '#808080', '#ffffff', '#000000']) {
      assert.ok(
        ['#ffffff', '#1e293b'].includes(warnaTeksDiAtas(hex)),
        `warnaTeksDiAtas(${hex}) menghasilkan nilai di luar daftar`,
      )
    }
  })

  it('memakai warna bawaan untuk input yang tidak sah', () => {
    assert.equal(warnaTeksDiAtas(''), warnaTeksDiAtas(WARNA_BAWAAN))
    assert.equal(warnaTeksDiAtas(undefined), warnaTeksDiAtas(WARNA_BAWAAN))
  })
})

describe('keputusan warna teks di atas warna bawaan', () => {
  it('warna bawaan memakai teks gelap, dan itu disengaja', () => {
    /*
     * `#0ea5e9` (sky-500) memang mendapat teks gelap secara matematis:
     * kontras putih 2.6, kontras gelap 7.6. Ini bukan bug -- sesuai WCAG
     * teks gelap di atas biru muda memang lebih terbaca, dan 7.6 berarti
     * SANGAT terbaca.
     *
     * Test ini mengunci keputusan itu supaya tidak ikut "diperbaiki" nanti
     * oleh orang yang mengira hasilnya salah, padahal komponen PrimeVue
     * punya aturan sendiri (`primary.contrastColor`) yang selalu putih.
     * Dua tempat itu berbeda alasan, bukan salah satu yang keliru.
     */
    assert.equal(warnaTeksDiAtas(WARNA_BAWAAN), '#1e293b')
  })
})
describe('gradienBrand', () => {
  it('kedua ujungnya satu hue, untuk warna yangsangat berbeda', () => {
    /*
     * Ini yang mengunci gradasi.
     *
     * Gejalanya kalau ini bocor: lokasi memilih oranye, dan gradasinya jadi
     * kuning-ke-biru -- warna yang tidak terlihat seperti satu warna di mana
     * pun, dan tidak pernah dipilih siapa pun. Untuk warna biru bagaimanapun
     * masalahnya tidak terlihat, karena kedua ujungnya kebetulan sama-sama
     * biru. Jadi test ini memakai oranye dan hijau, bukan warna bawaan.
     */
    for (const warna of ['#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#0ea5e9']) {
      const skala = skalaWarna(warna)
      const gradient = gradienBrand(skala['400'], skala['600'])

      const [, awal, akhir] = gradient.match(/([#][0-9a-f]{6}).*?([#][0-9a-f]{6})/)

      const hueAwal = hexToHsl(awal).h
      const hueAkhir = hexToHsl(akhir).h

      // Hue HSL melingkar: 350 dan 10 selisihnya 20, bukan 340.
      const selisih = Math.abs(hueAwal - hueAkhir)
      const selisihMelingkar = Math.min(selisih, 360 - selisih)

      assert.ok(
        selisihMelingkar < 1,
        `${warna}: hue ujung gradien selisih ${selisihMelingkar.toFixed(2)}`,
      )
    }
  })

  it('ujung gradien tidak sama, supaya gradiennya benar-benar terlihat', () => {
    const skala = skalaWarna('#f59e0b')

    assert.notEqual(skala['400'], skala['600'])
  })

  it('menulis color stop eksplisit dan sudut yang dikunci', () => {
    /*
     * Gradien tanpa color stop diturunkan dari sudutnya, dan hasilnya satu
     * warna polos -- gradiennya hilang tanpa error. Sudut yang dikunci
     * memastikan warna yang sama tampil sama setiap kali, bukan berbeda
     * tergantung kapan disimpan.
     */
    const gradient = gradienBrand('#38bdf8', '#2563eb')

    assert.equal(gradient, 'linear-gradient(135deg, #38bdf8 0%, #2563eb 100%)')
  })

  it('deterministik: input sama selalu menghasilkan output sama', () => {
    assert.equal(gradienBrand('#111111', '#222222'), gradienBrand('#111111', '#222222'))
  })
})

describe('tripletRgb', () => {
  it('menghasilkan tiga angka yang bisa dipakai rgb() dengan alpha', () => {
    /*
     * Tanpa ini, warna transparan ditulis sebagai `rgba(hex, 0.4)` yang
     * tidak valid. CSS tidak melaporkannya sebagai error -- diam-diam tidak
     * menghasilkan bayangan, dan sulit dilacak karena tidak ada yang gagal.
     */
    assert.equal(tripletRgb('#f59e0b'), '245 158 11')
  })

  it('memakai warna bawaan untuk input yang tidak sah, bukanmelempar undefined', () => {
    const rgb = tripletRgb('bukan-warna')

    assert.match(rgb, /^\d+ \d+ \d+$/)
    assert.equal(rgb, tripletRgb(WARNA_BAWAAN))
  })
})