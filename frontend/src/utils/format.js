/**
 * Format angka untuk seluruh aplikasi -- SATU definisi, bukan satu per berkas.
 *
 * Kenapa harus disatukan
 * ---------------------
 *
 * `formatRupiah` pernah ditulis ulang di sembilan berkas (tujuh di panel
 * pusat, satu di `useCentralNotifications`, satu di
 * `CentralNotificationPanel`). Sembilan salinan dari satu aturan berarti
 * setiap perbaikan harus diulang sembilan kali, dan hanya satu yang akan
 * diingat -- persis yang sudah terjadi: `useFormatCurrency.js` punya versi
 * sendiri yang menangani `NaN` dan string secara BERBEDA.
 *
 * Yang paling merusak bukan banyaknya, tapi bedanya. Ada tiga perilaku
 * berbeda untuk input yang sama:
 *
 *   Intl 'currency' (9 salinan) -> "Rp 1.500"
 *   "Rp " + toLocaleString      -> "Rp 1.500"
 *   toLocaleString tanpa "Rp "  -> "1.500"
 *
 * Kolom uang jadi berbeda bentuk antara halaman yang satu dan halaman yang
 * lain, dan tidak ada yang salah: semuanya "benar". Untuk laporan yang
 * direkonsiliasi manual oleh admin, itu berarti angka yang sama tertulis
 * dengan beberapa bentuk berbeda.
 *
 * Pertahankan apa yang dipakai mayoritas
 * --------------------------------------
 *
 * Sembilan dari sebelas pemanggilan memakai `Intl.NumberFormat` dengan
 * `style: 'currency'`. Itu yang dipakai semua halaman panel pusat, jadi
 * itulah yang jadi implementasi tunggal. Varian `toLocaleString` yang lain
 * benar secara teknis tapi tidak boleh diam-diam diganti, karena
 *_that berarti memindahkan angka di halaman yang sudah benar.
 *
 * Varian yang memang berbeda diberi nama sendiri, bukan dipaksa disamakan:
 * angka pecahan pada laporan (`formatRupiahDecimal`), angka tanpa simbol
 * (`formatAngka`, untuk kolom yang judulnya sudah menyebut "Rp"), saldo
 * negatif berkurung (`formatRupiahNegatif`), dan ringkasan untuk kartu
 * statistik (`formatRupiahShort`).
 */

/** Simbol yang dipakai awalan, sama seperti hasil `Intl` style `currency`. */
const SIMBOL_RUPIAH = 'Rp '

/**
 * Formatter dibuat sekali, bukan tiap kali fungsi dipanggil.
 */
const formatterRupiah = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
})

/**
 * Rata-kan spasi yang tidak terlihat.
 *
 * `Intl` dengan `style: 'currency'` memisahkan "Rp" dan angka dengan
 * U+00A0 (non-breaking space), bukan spasi biasa. Sulit dilihat di diff dan
 * tidak terlihat sama sekali di teks yang dirender -- tapi ia merusak dua
 * hal yang keduanya muncul kemudian:
 *
 *   - Perbandingan string. `formatRupiah(x).startsWith('Rp ')` bernilai
 *     FALSE, karena spasinya beda jenis.
 *   - Ekspor ke CSV/PDF. Beberapa pustaka merender U+00A0 sebagai kotak
 *     atau menghilangkannya, jadi kolom nominal jadi "Rp1.500" atau
 *     "Rp□1.500" di laporan yang dicetak.
 *
 * Karena format ini dipakai sebagai satu-satunya sumber angka di seluruh
 * panel, ini Dirata-kan satu kali di sini -- bukan di setiap view.
 */
function rapikanSpasi(teks) {
  // Ditulis sebagai escape supaya tetap terlihat kalau berkas ini dibaca
  // tanpa menyorot karakter tak tercetak.
  return teks.replace(/\u00a0/g, ' ')
}

/**
 * Angka uang bulat dengan simbol -- bentuk baku.
 *
 * Dua digit desimal sengaja TIDAK dipakai: laporan keuangan lokasi ini
 * menyimpan nominal dalam rupiah penuh, dan desimal hanya muncul pada
 * laporan yang memang menampilkan dua pecahan. `formatRupiahDecimal()`
 * di bawah itu yang dipakai di sana.
 */
export function formatRupiah(value) {
  return rapikanSpasi(formatterRupiah.format(Number(value) || 0))
}

/**
 * Dua pecahan, tanpa simbol -- bentuk yang dipakai laporan keuangan.
 *
 * Tanpa simbol karena judul kolom sudah menyebut "Rp", jadi menambahkan
 * "Rp " lagi membuat baris terlalu lebar pada laporan A4 dan mendorong
 * kolom berikutnya keluar area cetak.
 */
export function formatRupiahDecimal(value, { withSymbol = false } = {}) {
  const teks = formatAngka(value, { fractionDigits: 2 })

  return withSymbol ? SIMBOL_RUPIAH + teks : teks
}

/**
 * Angka tanpa simbol.
 *
 * Dipakai kolom tabel yang judulnya sudah menyebut satuan -- misalnya
 * "Nominal (Rp)" -- sehingga "Rp 1.500" di dalamnya berarti simbol tampil
 * dua kali.
 */
export function formatAngka(value, { fractionDigits = 0 } = {}) {
  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(Number(value) || 0)
}

/**
 * Nominal dengan tanda kurung untuk yang negatif.
 *
 * Bentuk ini bukan selera: laporan keuangan di Indonesia memakai kurung,
 * bukan tanda minus, dan backend sudah mengirim nilai negatif apa adanya.
 * Mengubahnya ke "-1.500,00" membuat angka itu terbaca sebagai pengurangan
 * pada konteks yang sudah menandai saldo minus.
 */
export function formatRupiahNegatif(value, { fractionDigits = 2 } = {}) {
  const n = Number(value) || 0
  const teks = new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(Math.abs(n))

  return n < 0 ? `(${teks})` : teks
}

/**
 * Ringkasan untuk kartu statistik dan tabel yang sempit.
 *
 * Satuan memakai bentuk yang lazim di Indonesia: `rb` (ribuan), `jt`
 * (juta), `M` (miliar). Satu angka desimal untuk `jt`/`M`, karena
 * "Rp 1,5jt" jauh lebih terbaca daripada "Rp 1.523.400". Di bawah ribuan
 * ditampilkan utuh -- tidak ada yang butuh "Rp 0,0rb".
 */
export function formatRupiahShort(value) {
  const n = Number(value) || 0
  const abs = Math.abs(n)

  // `toFixed(1)` memakai titik sebagai pemisah desimal, sementara titik
  // dipakai sebagai pemisah ribuan di `formatAngka` di atas. Digabung begitu,
  // kartu statistik menampilkan "Rp 1.5jt" di sebelah tabel yang menulis
  // "Rp 1.523.400" -- dua bentuk berbeda untuk angka yang sama, dan yang
  // salah tempat: pemisah desimal di format Indonesia adalah koma.
  const potong = (bagi, satuannya) => {
    const desimal = (n / bagi).toFixed(1).replace('.', ',').replace(/,0$/, '')
    return SIMBOL_RUPIAH + desimal + satuannya
  }

  if (abs >= 1_000_000_000) return potong(1_000_000_000, 'M')
  if (abs >= 1_000_000) return potong(1_000_000, 'jt')
  if (abs >= 1_000) return SIMBOL_RUPIAH + formatAngka(Math.round(n / 1_000)) + 'rb'

  return SIMBOL_RUPIAH + formatAngka(n)
}

/**
 * Bersihkan input uang dari form sebelum dikirim ke server.
 *
 * Dua pekerjaan, keduanya wajib: buang semua yang bukan angka, dan anggap
 * `1.234,56` dan `1,234.56` sebagai hal yang sama. Keyboard Indonesia
 * menghasilkan `1.234,56`, sementara `<input type="number">` memberi
 * `1234.56` di sebagian browser. Tanpa ini, nominal yang sama tersimpan
 * dengan nilai berbeda di server dan laporan tidak akan pernah cocok.
 */
/**
 * Apakah satu-satunya pemisah itu berarti ribuan, bukan desimal.
 *
 * Titik dan koma sama-sama ambigu, dan ini penyebab paling sering nominal
 * jadi seratus kali lebih kecil dari yang diketik admin.
 *
 * `1.500` hampir selalu berarti seribu lima ratus -- itulah bentuk yang
 * diketik orang Indonesia, dan itulah yang dikembalikan `formatRupiah()` di
 * atas. Tapi kalau dibaca sebagai desimal, nilainya jadi 1,5: seribu lima
 * ratus berubah menjadi satu koma lima, dan tidak ada satu pun tanda yang
 * menandainya.
 *
 * Aturannya: kalau pemisah itu berulang, sudah pasti ribuan. Kalau hanya
 * sekali,baru berarti ribuan kalau tepat tiga digit mengikutinya -- cukup
 * untuk membedakan `1.500` (seribu lima ratus) dari `1.5` dan `1.50` (satu
 * koma lima).
 */
function adalahPemisahRibuan(bersih, pemisah) {
  const bagian = bersih.split(pemisah)

  if (bagian.length > 2) return true
  if (bagian.length !== 2) return false

  return bagian[0].length >= 1
    && bagian[0].length <= 3
    && bagian[1].length === 3
}

export function parseAngkaUang(input) {
  if (input === null || input === undefined || input === '') return 0
  if (typeof input === 'number') return Number.isFinite(input) ? input : 0

  const mentah = String(input).trim()
  if (mentah === '') return 0

  // Buang semua yang bukan angka, koma, titik, atau tanda minus.
  const bersih = mentah.replace(/[^\d,.-]/g, '')
  if (bersih === '') return 0

  const adaKoma = bersih.includes(',')
  const adaTitik = bersih.includes('.')

  let normal
  if (adaKoma && adaTitik) {
    // Pemisah yang paling kanan adalah yang desimal.
    normal = bersih.lastIndexOf(',') > bersih.lastIndexOf('.')
      ? bersih.replace(/\./g, '').replace(',', '.')
      : bersih.replace(/,/g, '')
  } else if (adaKoma) {
    // Sama seperti titik: `1,500` adalah seribu lima ratus, bukan 1,5.
    // `1,5` dan `1,50` tetap desimal.
    normal = adalahPemisahRibuan(bersih, ',') ? bersih.replace(/,/g, '') : bersih.replace(',', '.')
  } else if (adaTitik) {
    normal = adalahPemisahRibuan(bersih, '.') ? bersih.replace(/\./g, '') : bersih
  } else {
    normal = bersih
  }

  const hasil = parseFloat(normal)
  return Number.isFinite(hasil) ? hasil : 0
}