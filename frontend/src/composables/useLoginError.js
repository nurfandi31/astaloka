/**
 * Memetakan kegagalan login ke pesan per-field.
 *
 * Dulu kegagalan kredensial membuka popup "Akses Ditolak" yang menumpuk di
 * atas form. Masalahnya bukan cuma estetika: popup menutupi input yang
 * memang salah, jadi user harus menutup dialog dulu, baru bisa melihat field
 * yang perlu diperbaiki dan mengoreksinya. Sekarang pesannya menempel di
 * bawah inputnya sendiri, jadi koreksi bisa langsung dilakukan tanpa
 * langkah tambahan.
 *
 * Aturannya satu: 422 -> pesan per-field, sisanya -> toast. Tidak ada
 * branch yang memunculkan popup untuk kegagalan login yang bisa dijelaskan
 * dengan satu kalimat di bawah sebuah input.
 *
 * Dipakai bersama oleh dua halaman login (lokasi dan panel pusat) supaya
 * bentuk pesan, ikon, dan penempatan aria-nya tidak bisa berbeda antara
 * keduanya -- kalau berbeda, perbaikan akan selalu diterapkan dua kali
 * dengan dua bentuk yang tidak sama.
 */
import { reactive } from 'vue'

/**
 * Bentuk respons yang dipakai backend.
 *
 * Laravel mengirim `errors` sebagai peta field -> array pesan. Yang dipakai
 * hanya pesan pertama per field: form login punya satu baris per field, jadi
 * menampilkan semuanya sekaligus hanya memperpanjang tinggi form tanpa
 * menambah informasi.
 *
 * Mengembalikan `null` kalau pesannya tidak bisa ditunjuk ke field --
 * pemanggil yang memutuskan apa yang harus dilakukan kalau begitu.
 */
export function petakanFieldError(error) {
  // 422 = validasi atau kredensial salah. Hanya status ini yang membawa
  // kunci `errors` per-field; status lain harus diperlakukan sebagai
  // kegagalan yang tidak bisa dikaitkan ke kolom tertentu.
  if (error?.response?.status !== 422) return null

  const perField = error.response?.data?.errors || {}

  const hasil = Object.fromEntries(
    Object.keys(perField)
      .filter((field) => Array.isArray(perField[field]) && perField[field].length > 0)
      .map((field) => [field, perField[field][0]]),
  )

  return Object.keys(hasil).length > 0 ? hasil : null
}

/**
 * Pesan untuk permintaan yang terlalu sering.
 *
 * 429 = kena `throttle:login` atau `throttle:login-ip`, dan pesan bawaan
 * Laravel ("Too Many Attempts.") tidak menjelaskan batasnya. Angka
 * batasnya sudah tertulis di bawah form (lihat catatan yang sama di
 * `CentralLoginView.vue`), jadi kalimat di sini cukup mengarahkan ke sana
 * tanpa mengulang angkanya -- mengulangnya membuat dua tempat yang harus
 * selalu sama dan bisa berbeda diam-diam saat salah satunya diubah.
 *
 * Ditaruh di field `email` karena user tidak bisa memperbaiki apa pun
 * sebelum batasnya lewat: yang harus ia lakukan adalah menunggu, bukan
 * memperbaiki isi kolom.
 */
export const PESAN_TERLAMBAT = 'Terlalu banyak percobaan. Tunggu satu menit lalu coba lagi.'

/**
 * State error untuk satu form login.
 *
 * `reactive`, bukan `ref`, supaya `errors.email` bisa dipakai langsung di
 * template tanpa `.value` -- sama seperti yang sudah dipakai
 * `CentralLoginView.vue`.
 */
export function useLoginError() {
  const errors = reactive({ email: '', password: '' })

  /** Kosongkan error satu field. Dipanggil saat user mulai mengetik ulang. */
  const bersihkan = (field) => {
    errors[field] = ''
  }

  /**
   * Terapkan kegagalan dari server ke state form.
   *
   * Mengembalikan `true` kalau pesannya sudah menempel di bawah input yang
   * tepat. `false` berarti pemanggil harus menampilkannya lewat toast --
   * biasanya karena kegagalannya jaringan atau timeout, yang memang tidak
   * bisa dikaitkan ke field mana pun.
   */
  const terapkan = (error) => {
    errors.email = ''
    errors.password = ''

    if (error?.response?.status === 429) {
      errors.email = PESAN_TERLAMBAT
      return true
    }

    const perField = petakanFieldError(error)

    if (!perField) return false

    errors.email = perField.email || ''
    errors.password = perField.password || ''

    return true
  }

  return { errors, bersihkan, terapkan }
}
