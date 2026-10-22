import {
  skalaWarna,
  warnaTeksDiAtas,
  normalisasiHex,
  gradienBrand,
  tripletRgb,
  WARNA_BAWAAN,
} from '@/utils/themeColor'

/**
 * Terapkan warna lokasi ke seluruh dokumen.
 *
 * Dipanggil sekali saat aplikasi dibuka, dan lagi setiap kali branding
 * berubah -- jadi warna tidak pernah "nyangkut" dari lokasi sebelumnya di
 * tab yang sama.
 *
 * Kenapa CSS variable, bukan warna yang ditulis langsung
 * ======================================================
 *
 * Seluruh warna di aplikasi ini sudah melewati `var(--color-primary)` atau
 * token PrimeVue `--p-primary-*`. Menimpa variabelnya di satu tempat
 * membuat sidebar, tombol, tabel, border, dan komponen PrimeVue ikut berubah
 * tanpa satu pun file lain yang disentuh.
 *
 * Kenapa hasilnya DIHITUNG, bukan menimpa apa adanya
 * =================================================
 *
 * Primer bawaan aplikasi ini bukan `primary.500` dari palet Aura, dan
 * turunannya juga bukan: `--color-primary-light` di `main.css` adalah
 * `#e0f2fe` (sky-100), bukan sky-50. Menimpa `--color-primary` saja akan
 * menghasilkan tombol yang warnanya benar tapi setiap latar dan border
 * turquoise yang menyertainya tetap biru -- dan itu justru lebih buruk
 * daripada tidak mengganti sama sekali, karena sekarang ada dua warna yang
 * tidak pernah sengaja dipilih.
 *
 * Jadi yang ditulis di sini adalah SKALA yang dihitung dari satu hue
 * (`utils/themeColor.js`), lalu dipasang ke token yang memang dipakai
 * aplikasi. Dengan begitu semua turunan berasal dari warna yang sama.
 *
 * Token PrimeVue
 * ==============
 *
 * Aura menyebut token warnanya `--p-primary-50` sampai `--p-primary-950`,
 * dan bentuk uppercase/underline-nya (`--p-primary-color`,
 * `--p-primary-contrast-color`) DIHITUNG dari skala itu saat tema dibuat.
 * Makanya hanya angka 50 sampai 900 yang ditulis di sini, dan hanya 500
 * yang perlu disamakan dengan warnanya sendiri.
 *
 * PrimeVue menulis token itu ke `document.documentElement.style`, jadi
 * nilai yang kita pasang lewat `style.setProperty` menimpaYDUP-nya dengan
 * prioritas yang sama -- daniakali yang menang adalah yang dipasang
 * terakhir. Karena itu tema ini dijalankan SETELAH `app.use(PrimeVue)`.
 */
export function terapkanTema(hex) {
  if (typeof document === 'undefined') return

  const warna = normalisasiHex(hex) ?? WARNA_BAWAAN
  const skala = skalaWarna(warna)
  const root = document.documentElement

  const set = (nama, nilai) => root.style.setProperty(nama, nilai)

  /* ===== Token aplikasi (main.css) ===== */
  set('--color-primary', skala['500'])
  set('--color-primary-dark', skala['700'])
  set('--color-primary-light', skala['100'])

  /*
   * `--color-primary-400` dan `--color-primary-600` ditambahkan karena
   * dipakai untuk gradien tombol (`.base-button--info-gradient`).
   *
   * `--color-primary-dark` (700) terlalu pekat untuk ujung terang gradien,
   * dan `--color-primary` (500) terlalu mediocre untuk ujung gelapnya --
   * hasilnya tombol terlihat rata, bukan bergradasi. 400 dan 600 memberi
   * rentang yang cukup untuk gradien terbaca tanpa harus mengarang warna
   * sendiri di setiap file.
   */
  set('--color-primary-400', skala['400'])
  set('--color-primary-600', skala['600'])
  set('--color-primary-700', skala['700'])

  /*
   * ===== Gradien brand =====
   *
   * Ini yang mengunci gradasi supaya tidak pernah jadi campur dua hue.
   *
   * Yang terjadi sebelumnya: sidebar dan tombol besar memakai gradien yang
   * ujungnya ditulis dari DUA token berbeda, misalnya
   * `linear-gradient(var(--color-primary), var(--color-blue-400))`. Untuk
   * lokasi yang warnanya biru, kedua token itu kebetulan sama-sama biru, jadi
   * masalahnya tidak pernah terlihat. Begitu lokasi memilih oranye, ujungnya
   * jadi oranye dan biru: gradasi kuning-ke-biru yang tidak terlihat seperti
   * satu warna di mana pun. Itu lebih buruk daripada warna biru polos,
   * karena sekarang ada DUA warna yang tidak pernah sengaja dipilih.
   *
   * Di sini kedua ujung diambil dari SKALA YANG SAMA, jadi yang kedua selalu
   * satu langkah lebih terang atau lebih pekat dari yang pertama. Gradiennya
   * terbaca sebagai satu warna, bukan dua warna yang kebetulan berdekatan.
   *
   * Kenapa yang disimpan adalah `linear-gradient(...)` utuh, bukan cuma dua
   * warnanya: CSS custom property bisa berisi seluruh nilai `background`, jadi
   * file lain cukup menulis `background: var(--brand-gradient)`. Kalau yang
   * disimpan hanya warnanya, setiap file harus mengulang `linear-gradient`
   * -- dan hampir pasti ada yang lupa. Tempat yang lupa itu kembali ke warna
   * yang tidak sengaja, persis masalah yang diselesaikan di sini.
   *
   * Tiga token, bukan satu, karena ada tiga kegunaan yang berbeda:
   * - `--brand-gradient`      : tombol besar dan aksen (400 -> 600)
   * - `--brand-gradient-deep` : sisi lebih pekat (500 -> 800)
   * - `--brand-gradient-soft` : latar aksen (200 -> 50), untuk panel dan card
   */
  set('--brand-gradient', gradienBrand(skala['400'], skala['600']))
  set('--brand-gradient-deep', gradienBrand(skala['500'], skala['800']))
  set('--brand-gradient-soft', gradienBrand(skala['200'], skala['50']))

  /*
   * ===== Token halaman login (login.css) =====
   *
   * Nama tokennya BERBEDA dari yang di atas: `--primary`, bukan
   * `--color-primary`. Dua nama itu bukan gaya penulisan -- keduanya benar,
   * hanya milik dua stylesheet yang berbeda, dan `login.css` memakai nama
   * yang lebih pendek.
   *
   * Kenapa harus ditulis di sini, bukan cuma dibiarkan `login.css` yang
   * menetapkannya sendiri: nilai default di `login.css` adalah `#0ea5e9`.
   * Kalau token ini tidak ditulis, seluruh halaman login -- badge, judul
   * sub, tombol masuk, latar, glow, dan focus ring -- tetap biru apa pun
   * warnanya lokasi. Gejalanya terlihat sangat menyesatkan: teks branding
   * (judul, sub judul) BERUBAH dengan benar karena ia dibaca dari JavaScript,
   * sementara warnanya membeku di biru. Seolah-olah hanya sebagian yang
   * berhasil disimpan.
   *
   * `login.css` tidak bisa menetapkannya sendiri dari nilai yang sama:
   * `main.js` tidak tahu kapan branding selesai dimuat, dan `login.css`
   * hanya punya warna bawaan sebagai titik awal.
   */
  set('--primary', skala['500'])
  set('--accent', skala['500'])

  /*
   * `--secondary` menentukan warna KEDUA dari gradien, dan gradien itu dipakai
   * di dua tempat yang paling terlihat: tombol "Masuk Sekarang" dan headline
   * besar di sisi kiri.
   *
   * Nilai bawaannya di `login.css` mencampur `--primary` dengan indigo
   * `#6366f1`. Untuk warna bawaan biru itu kebetulan tidak-too-clock, tapi
   * untuk lokasi oranye hasilnya ungu di ujung gradien -- dan gagalnya paling
   * jelas di tombol, yang separuh panjangnya jadi ungu.
   *
   * Jadi secondary diturunkan dari SKALA yang sama, bukan dari hue lain:
   * `700` memberi ujung gradien yang jelas satu hue tapi satu langkah lebih
   * pekat, sehingga gradiennya terbaca sebagai satu keluarga warna.
   */
  set('--secondary', skala['700'])

  /*
   * Skala biru ikut ditulis.
   *
   * Bukan karena aplikasi ini "berwarna biru" -- tapi karena ada belasan
   * tempat yang memakai `--color-blue-600` untuk teks di atas latar putih
   * (nomor KPI di dashboard, kode di panel pusat). Kalau warnanya tidak ikut
   * berubah, seluruh aplikasi akan memakai hijau untuk aksi dan biru untuk
   * angka, dan tidak ada yang kelihatan salah sampai keduanya dibandingkan
   * bersebelahan.
   *
   * 600 dipakai sebagai padanan "teks" karena itu yang dipakai sebagai warna
   * teks di seluruh file CSS.
   */
  set('--color-blue-800', skala['800'])
  set('--color-blue-600', skala['700'])
  set('--color-blue-500', skala['500'])
  set('--color-blue-400', skala['400'])
  set('--color-blue-300', skala['300'])
  set('--color-blue-200', skala['200'])
  set('--color-blue-100', skala['100'])
  set('--color-blue-50', skala['50'])

  /* ===== Token PrimeVue ===== */
  for (const [nama, nilai] of Object.entries(skala)) {
    set(`--p-primary-${nama}`, nilai)
  }

  /*
   * Warna teks di atas warna solid ikut dihitung, bukan selalu putih.
   *
   * Warna kuning pucat atau hijau muda tidak bisa memakai teks putih -- teks
   * putih di atasnya tidak terbaca. `warnaTeksDiAtas()` menghitung
   * kontrasnya, jadi tombol utama lokasi yang warnanya terang tetap punya
   * teks yang bisa dibaca. Tanpa ini, tombolnya akan terlihat "terang" tapi
   * isinya hilang tepat saat dipakai.
   */
  const teks = warnaTeksDiAtas(warna)

  set('--p-primary-contrast-color', teks)
  set('--color-on-primary', teks)

  /*
   * Rona warna solid dipakai untuk bayangan dan focus ring, jadi ditulis
   * sebagai RGB triplet -- bukan sebagai warna. `rgba()` butuh angka; kalau
   * warna heksadesimal yang ditulis, hasilnya `rgba(#0ea5e9, 0.4)` yang
   * tidak valid dan TIDAK menghasilkan error: bayangannya hilang begitu saja.
   */
  const rgb = tripletRgb(warna)
  set('--color-primary-rgb', rgb)
  set('--p-primary-rgb', rgb)
}