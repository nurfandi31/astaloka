/**
 * Turunan warna dari satu warna hex lokasi.
 *
 * Kenapa tidak menyimpan seluruh skala warna
 * =========================================
 *
 * Warna lokasi disimpan sebagai SATU nilai hex (lihat kolom `warna_utama`).
 * Seluruh turunannya -- versi terang untuk latar, versi gelap untuk teks di
 * atas latar terang, bayangan, dan warna isomer PrimeVue -- dihitung di sini
 * dari satu hue yang sama.
 *
 * Kalau skalanya ikut disimpan (sepuluh kolom), dua hal pasti terjadi. Pertama,
 * simpanannya bisa tidak cocok satu sama lain: admin memilih hijau, lalu menyimpan
 * biru muda yang tidak ada hubungannya dengan hijau itu. Kedua, dan lebih
 * buruk: warna primer PrimeVue berasal dari preset Aura yang punya palet
 * sendiri, jadi warna komponen PrimeVue tidak mungkin dicocokkan ke warna CSS
 * kustom tanpa override token yang sama persis.
 *
 * Cara kerja
 * ==========
 *
 * Daripada menebak "50 = 20% terang", angka lightness HSL yang dipakai di
 * sini DIJEPIT di kedua ujungnya dari lightness warna itu sendiri:
 *
 *   50 -> lightness + (100 - lightness) x 92%
 *   ...
 *   500 -> lightness itu sendiri, tidak digeser
 *   ...
 *   900 -> lightness - lightness x 72%
 *
 * Hasilnya: warna terang yang makin terang, warna gelap yang makin gelap,
 * dan semuanya tetap satu hue. Persis seperti palet yang sudah dipakai
 * Aura, hanya dengan langkah yang dihitung dari warna pilihan admin, bukan
 * diambil dari palet emerald/green/blue yang sudah ditentukan sebelumnya.
 *
 * Kenapa dijepit, bukan `+46` dan `-36` tetap
 * ==============================================
 *
 * Warna bawaan `#0ea5e9` punya lightness 62%, jadi `+46` masih muat. Tapi
 * warna yang terang tidak punya ruang itu: ungu `#8b5cf6` (L 61%) sudah
 * menyentuhnya, dan setiap warna di atas itu -- kuning pucat, hijau muda --
 * akan TERJEPIT di 100%.
 *
 * Dan penjepitan itu bukan satu warna yang sama pada dua token. Token 50
 * dan token 100 akan menjadi putih yang sama persis, dan perbedaannya
 * hilang. Efeknya di layar: latar badge, border, dan hover button berhenti
 * bisa dibedakan, dan tombol `secondary` ikut jadi warna utama karena
 * gradiennya berujung di token yang sudah putih.
 *
 * Yang perlu dijaga: untuk setiap warna yang mungkin dipilih admin,
 * kesembilan token di atas 500 harus BERBEDA dan berurutan. Warna admin
 * bisa secerah apa pun; ke-9 warnanya harus tetap bisa dibedakan dari yang
 * di sebelahnya.
 */

/** Warna bawaan. Harus sama dengan `Branding::DEFAULT_WARNA` di backend. */
export const WARNA_BAWAAN = '#0ea5e9'

/**
 * Pola hex yang sah.
 *
 * WAJIB 7 karakter (`#` + 6 digit). Enam karakter tanpa `#` ditolak, bukan
 * diterima lalu diperbaiki: nilai ini dipakai apa adanya di `color-mix()`
 * dan di regex token CSS, jadi bentuk yang berbeda menghasilkan warna yang
 * berbeda tanpa error apa pun yang menyinggungnya.
 */
const POLA_HEX = /^#[0-9a-fA-F]{6}$/

/*
 * Porsi lightness yang ditambahkan atau dikurangi per nama warna.
 *
 * Meniru palet Aura (50, 100, 200, ... 900) supaya komponen PrimeVue yang
 * memakai `--p-primary-300` sampai `--p-primary-900` punya pasangan yang
 * masuk akal -- bukan semua mengarah ke warna yang sama.
 *
 * Nilainya PORSI, bukan poin absolut, supaya bekerja untuk warna terang
 * maupun gelap (lihat catatan panjang di atas file). 500 bernilai 0:
 * warnanya sendiri, tanpa digeser.
 */
const LANGKAH = {
  50: { geser: 0.92, towards: 'light' },
  100: { geser: 0.76, towards: 'light' },
  200: { geser: 0.58, towards: 'light' },
  300: { geser: 0.4, towards: 'light' },
  400: { geser: 0.21, towards: 'light' },
  500: { geser: 0, towards: 'light' },
  600: { geser: 0.18, towards: 'dark' },
  700: { geser: 0.36, towards: 'dark' },
  800: { geser: 0.54, towards: 'dark' },
  900: { geser: 0.72, towards: 'dark' },
}

/**
 * Ubah hex ke HSL.
 *
 * @param {string} hex
 * @returns {{ h: number, s: number, l: number } | null}
 */
export function hexToHsl(hex) {
  if (typeof hex !== 'string') return null

  const bersih = hex.trim()

  if (!POLA_HEX.test(bersih)) return null

  const r = parseInt(bersih.slice(1, 3), 16) / 255
  const g = parseInt(bersih.slice(3, 5), 16) / 255
  const b = parseInt(bersih.slice(5, 7), 16) / 255

  const max = Math.max(r, g, b)
  const min = Math.min(r, g, b)
  const delta = max - min
  const l = (max + min) / 2

  // Abu-abu: tidak ada hue, jadi hsl hanya perlu lightness.
  if (delta === 0) return { h: 0, s: 0, l: l * 100 }

  const s = delta / (1 - Math.abs(2 * l - 1))

  let h
  if (max === r) h = ((g - b) / delta) % 6
  else if (max === g) h = (b - r) / delta + 2
  else h = (r - g) / delta + 4

  h *= 60
  if (h < 0) h += 360

  return { h, s: s * 100, l: l * 100 }
}

/**
 * Ubah HSL ke hex.
 *
 * @param {number} h 0-360
 * @param {number} s 0-100
 * @param {number} l 0-100
 * @returns {string}
 */
export function hslToHex(h, s, l) {
  const hNorm = ((h % 360) + 360) % 360
  const sNorm = Math.min(100, Math.max(0, s)) / 100
  const lNorm = Math.min(100, Math.max(0, l)) / 100

  const c = (1 - Math.abs(2 * lNorm - 1)) * sNorm
  const x = c * (1 - Math.abs(((hNorm / 60) % 2) - 1))
  const m = lNorm - c / 2

  let rgb
  if (hNorm < 60) rgb = [c, x, 0]
  else if (hNorm < 120) rgb = [x, c, 0]
  else if (hNorm < 180) rgb = [0, c, x]
  else if (hNorm < 240) rgb = [0, x, c]
  else if (hNorm < 300) rgb = [x, 0, c]
  else rgb = [c, 0, x]

  const toHex = (n) =>
    Math.round((n + m) * 255)
      .toString(16)
      .padStart(2, '0')

  return `#${toHex(rgb[0])}${toHex(rgb[1])}${toHex(rgb[2])}`
}

/**
 * Warna hex yang sah, atau `null` kalau tidak.
 *
 * Mengembalikan lowercase, jadi `#0EA5E9` dan `#0ea5e9` dianggap sama --
 * backend sudah menyimpan lowercase, tapi nilai bisa datang dari form yang
 * belum sempat dinormalkan.
 *
 * @param {unknown} hex
 * @returns {string | null}
 */
export function normalisasiHex(hex) {
  if (typeof hex !== 'string') return null

  const bersih = hex.trim()

  if (!POLA_HEX.test(bersih)) return null

  return bersih.toLowerCase()
}

/**
 * Seluruh skala warna dari satu hex.
 *
 * Kunci 50 sampai 900 yang dipakai Aura. Yang 500 adalah warnanya sendiri,
 * jadi admin yang memilih hijau benar-benar melihat hijau yang dia pilih di
 * tombol dan tautan -- bukan warna yang "mendekati" hijau.
 *
 * @param {unknown} hex
 * @returns {Record<string, string>}
 */
export function skalaWarna(hex) {
  const rgb = hexToHsl(normalisasiHex(hex) ?? WARNA_BAWAAN)

  const skala = {}

  for (const [nama, langkah] of Object.entries(LANGKAH)) {
    const ruang =
      langkah.towards === 'light' ? 100 - rgb.l : rgb.l

    const lightness =
      langkah.towards === 'light' ? rgb.l + ruang * langkah.geser : rgb.l - ruang * langkah.geser

    skala[nama] = hslToHex(rgb.h, rgb.s, lightness)
  }

  return skala
}

/**
 * Warna teks di atas warna solid.
 *
 * Dihitung dari kontras relatif WCAG, bukan dari "terang berarti teks putih".
 * Kuning pucat adalah kasus yang paling sering salah: jelas terang di mata,
 * tapi teks putih di atasnya tidak terbaca -- dan sebaliknya teks gelap
 * terbaca. Kalau keputusannya ditebak dari perceived brightness, warna
 * seperti itu berakhir dengan tombol yang teksnya hilang.
 *
 * Yang dikembalikan bukan `#0f172a` tapi `#1e293b` (slate-800) supaya
 *Warnanya konsisten dengan teks aplikasi -- `--color-text-main` di
 * `main.css`.
 *
 * Catatan soal warna bawaan: `#0ea5e9` (sky-500) memang mendapat teks
 * gelap secara matematis (kontras putih 2.6, kontras gelap 7.6). Ini bukan
 * bug: sesuai WCAG teks gelap di atas biru muda memang lebih terbaca. Yang
 * perlu diperhatikan, kontras 7.6 berarti **sangat** terbaca, bukan
 * sekadar "lumayan" -- dan tombol PrimeVue sendiri memakai
 * `primary.contrastColor` yang juga putih, jadi keduanya punya alasan yang
 * berbeda. Kalau nanti tombol biru tua terasa lebih cocok, itu pilihan
 * warna, bukan hasil kalkulasi yang salah.
 *
 * @param {unknown} hex
 * @returns {string} `#ffffff` atau `#1e293b`
 */
export function warnaTeksDiAtas(hex) {
  const bersih = normalisasiHex(hex) ?? WARNA_BAWAAN
  const { r, g, b } = heksKeRgb(bersih)

  const kontrasPutih = rasioKontras(r, g, b, [255, 255, 255])
  const kontrasGelap = rasioKontras(r, g, b, [30, 41, 59])

  return kontrasPutih >= kontrasGelap ? '#ffffff' : '#1e293b'
}

/*
 * ==========================================================================
 * GRADIEN BRAND
 * ==========================================================================
 *
 * Kenapa gradasi punya helper sendiri
 * ==================================
 *
 * Gradasi yang "kelihatan benar" hampir selalu salah kalau kedua ujungnya
 * ditulis dari dua sumber berbeda. Contoh yang paling sering terjadi:
 * `linear-gradient(var(--color-primary), var(--color-blue-400))`. Untuk lokasi
 * yang warnanya biru, kedua token itu kebetulan sama-sama biru, jadi tidak
 * pernah terlihat masalahnya -- dan tidak akan pernah ketahuan sampai ada
 * lokasi yang warnanya oranye. Waktu itu ujungnya jadi oranye dan biru, dan
 * hasilnya gradasi kuning-ke-biru yang tidak terlihat seperti satu warna di
 * mana pun.
 *
 * Itu lebih buruk daripada warna biru polos, karena sekarang ada DUA warna di
 * layar yang tidak pernah ada orang yang memilihnya.
 *
 * Jadi aturan di file ini: dua ujung gradien brand SELALU dari satu
 * `skalaWarna()` yang sama, hue yang sama, hanya berbeda tingkat terang.
 *
 * Dipakai oleh dua tempat yang harus menampilkan hasil yang sama:
 * - `utils/applyTheme.js` -> menulis token `--brand-gradient*`
 * - `CentralBrandingEditor.vue` -> pratinjau di panel pusat
 *
 * Kalau gradasinya ditulis terpisah di dua tempat, keduanya pasti menjauh dari
 * satu sama lain, dan pratinjau di panel pusat mulai menjanjikan sesuatu yang
 * tidak lagi muncul di aplikasi. Pratinjau yang menyimpang lebih buruk
 * daripada tidak ada pratinjau sama sekali, karena orang akan menyimpan
 * apa yang dijanjikan pratinjau.
 */

/**
 * Gradien linear dari dua warna pada skala yang sama.
 *
 * @param {string} awal  warna dari `skalaWarna()`
 * @param {string} akhir warna dari `skalaWarna()` yang sama
 * @returns {string} nilai CSS `background`
 */
export function gradienBrand(awal, akhir) {
  return `linear-gradient(135deg, ${awal} 0%, ${akhir} 100%)`
}

/**
 * RGB triplet untuk `rgb(... / alpha)`.
 *
 * Dipakai untuk warna transparan. `rgba()` butuh angka; kalau yang
 * disisipkan warna heksadesimal, hasilnya `rgba(#f59e0b, 0.4)` yang TIDAK
 * valid dan tidak menghasilkan error -- bayangannya hilang begitu saja, dan
 * sulit dilacak karena tidak ada apa pun yang gagal di console.
 *
 * @param {unknown} hex
 * @returns {string} `"245 158 11"`
 */
export function tripletRgb(hex) {
  const bersih = normalisasiHex(hex) ?? WARNA_BAWAAN

  return [
    parseInt(bersih.slice(1, 3), 16),
    parseInt(bersih.slice(3, 5), 16),
    parseInt(bersih.slice(5, 7), 16),
  ].join(' ')
}

function heksKeRgb(hex) {
  return {
    r: parseInt(hex.slice(1, 3), 16),
    g: parseInt(hex.slice(3, 5), 16),
    b: parseInt(hex.slice(5, 7), 16),
  }
}

function rasioKontras(r, g, b, [lr, lg, lb]) {
  const l1 = luminansi([r, g, b])
  const l2 = luminansi([lr, lg, lb])

  const terang = Math.max(l1, l2)
  const gelap = Math.min(l1, l2)

  return (terang + 0.05) / (gelap + 0.05)
}

function luminansi([r, g, b]) {
  const kanal = (n) => {
    const c = n / 255

    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }

  return 0.2126 * kanal(r) + 0.7152 * kanal(g) + 0.0722 * kanal(b)
}
