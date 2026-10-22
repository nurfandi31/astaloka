/**
 * `import.meta.env` untuk modul yang dimuat lewat loader Node.
 *
 * Vite mengganti `import.meta.env` di setiap modul yang diprosesnya. Loader
 * Node tidak, jadi kode aplikasi yang membacanya berakhir begini:
 *
 *     const api = import.meta.env.VITE_API_BASE_URL
 *                                     ^
 *     TypeError: Cannot read properties of undefined (reading 'VITE_API_BASE_URL')
 *
 * Yang menyesatkan: error itu muncul sebagai GAGAL IMPORT modul, jauh dari
 * baris yang membacanya. Untuk modul yang tidak melakukan HTTP sama sekali --
 * store, utils, komponen yang tidak pernah memanggil API -- error ini tidak
 * pernah muncul, jadi test lain tetap hijau.
 *
 * `import.meta` di ESM asli hanya punya `url` dan `resolve`. `env` tidak ada,
 * dan tidak bisa ditambahkan ke modul yang sudah dimuat Node -- jadi yang
 * bisa dilakukan di sini adalah menyediakan nilainya, lalu menyisipkannya ke
 * kode sumber sebelum Node mengurainya.
 *
 * Nilai diambil dari berkas `.env` yang benar-benar ada di repo. Menebak
 * default akan membuat test hijau di satu tempat dan merah di tempat lain.
 */

import { existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/** Isi `import.meta.env`. Berisi nilai yang dibaca, bukan tebakan. */
const env = {
  MODE: 'test',
  BASE_URL: '/',
  DEV: false,
  PROD: false,
  SSR: false,
}

/**
 * Baca satu berkas `.env` dan salin variabelnya ke `env`.
 *
 * Formatnya persis yang dipakai Vite: `KEY=value` per baris, `#` untuk
 * komentar, `export ` di depan nama variabel diperbolehkan.
 *
 * Nilai dibungkus kutip dibuang -- `VITE_API_BASE_URL="http://x"` harus
 * menghasilkan string `http://x`, bukan string yang masih memuat tanda
 * kutip. Ini yang paling sering terlewat, dan gejalanya adalah URL yang
 * salah secara senyap, bukan error.
 */
function bacaEnv(namaBerkas) {
  if (!existsSync(namaBerkas)) {
    return
  }

  for (const baris of readFileSync(namaBerkas, 'utf-8').split(/\r?\n/)) {
    const bersih = baris.trim()

    if (bersih === '' || bersih.startsWith('#')) {
      continue
    }

    const sama = bersih.indexOf('=')

    if (sama === -1) {
      continue
    }

    const nama = bersih.slice(0, sama).replace(/^export\s+/, '').trim()

    let nilai = bersih.slice(sama + 1).trim()
    const kutip = nilai[0]

    if ((kutip === '"' || kutip === "'") && nilai[nilai.length - 1] === kutip) {
      nilai = nilai.slice(1, -1)
    }

    if (nama) {
      env[nama] = nilai
    }
  }
}

/** Akar repo frontend, tempat berkas `.env` berada. */
const AKAR = new URL('../', import.meta.url)

for (const nama of ['.env', '.env.local', '.env.test']) {
  bacaEnv(fileURLToPath(new URL(nama, AKAR)))
}

export default env