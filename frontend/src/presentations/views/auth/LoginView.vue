<template>
  <div class="login-container">
    <div class="wrapper">
      <div class="left">
        <div class="badge">
          <span>ASTALOKA DIGITAL PLATFORM</span>
        </div>

        <div class="headline">
          <h1 class="black">{{ judul }}</h1>
          <h1 class="blue">{{ subJudul }}</h1>
        </div>

        <div class="desc-group">
          <p v-if="deskripsi" class="desc">{{ deskripsi }}</p>

          <div class="chips">
            <div class="chip">
              <font-awesome-icon icon="shield-halved" class="icon-blue" />
              <span>Kualitas Terjamin</span>
            </div>
            <div class="chip">
              <font-awesome-icon icon="check-circle" class="icon-green" />
              <span>Respon Cepat</span>
            </div>
          </div>
        </div>
      </div>

      <div class="right">
        <div class="card">
          <h2 class="card-title">Selamat Datang</h2>
          <p class="card-sub">Login untuk akses dashboard</p>

          <form @submit.prevent="handleLogin" class="form">
            <div class="form-group">
              <label for="email">Alamat Email</label>
              <div class="input-wrap">
                <span class="icon-left">
                  <font-awesome-icon icon="user" />
                </span>
                <input
                  id="email"
                  v-model="form.email"
                  type="email"
                  placeholder="Masukkan Alamat Email"
                  required
                  autocomplete="email"
                  :class="{ 'is-invalid': errors.email }"
                  :aria-invalid="Boolean(errors.email)"
                  :aria-describedby="errors.email ? 'email-error' : undefined"
                  @input="bersihkan('email')"
                />
              </div>
              <p v-if="errors.email" id="email-error" class="field-error">
                <font-awesome-icon icon="circle-exclamation" />
                {{ errors.email }}
              </p>
            </div>

            <div class="form-group">
              <div class="label-row">
                <label for="password">Kata Sandi</label>
                <!--
                  Belum ada alur lupa sandi: backend tidak punya endpoint
                  reset password. Yang ada hanya `PUT /me/password`, yaitu
                  mengganti sandi dari dalam halaman profil saat sudah login
                  -- bukan pemulihan akun yang terkunci.

                  Sebelumnya `href="#"`, jadi mengetiknya hanya menambah
                  `#` ke URL dan tidak terjadi apa-apa: tautan yang terlihat
                  berfungsi tapi tidak melakukan apa pun. Sekarang
                  dihapus dan diganti teks biasa, supaya halaman tidak
                  menjanjikan sesuatu yang tidak bisa dipakai.

                  Kalau nanti alurnya ditambahkan, ganti teks ini dengan
                  tautan sungguhan (bukan `href="#"`).
                -->
                <span class="forgot-note">Lupa sandi?</span>
              </div>
              <div class="input-wrap">
                <span class="icon-left">
                  <font-awesome-icon icon="lock" />
                </span>
                <input
                  id="password"
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  placeholder="••••••••"
                  required
                  autocomplete="current-password"
                  :class="{ 'is-invalid': errors.password }"
                  :aria-invalid="Boolean(errors.password)"
                  :aria-describedby="errors.password ? 'password-error' : undefined"
                  @input="bersihkan('password')"
                />
                <button
                  type="button"
                  class="eye-btn"
                  @click="togglePassword"
                  aria-label="Toggle password visibility"
                >
                  <font-awesome-icon :icon="showPassword ? 'eye-slash' : 'eye'" />
                </button>
              </div>
              <p v-if="errors.password" id="password-error" class="field-error">
                <font-awesome-icon icon="circle-exclamation" />
                {{ errors.password }}
              </p>
            </div>

            <button type="submit" class="btn-submit" :disabled="loading">
              <span v-if="!loading">Masuk Sekarang</span>
              <span v-else> <font-awesome-icon icon="spinner" spin /> Memproses... </span>
            </button>
          </form>

          <!--
            Blok "AKSES CEPAT" (Bayar Cepat / Daftar Akun) -- SEMENTARA
            BELUM BERFUNGSI.

            Saat ini kedua tombol ini murni tampilan: tidak ada `@click`,
            tidak ada `href`, dan backend juga belum punya endpoint
            pembayaran maupun pendaftaran publik (akun lokasi dibuat
            admin lokasi, bukan oleh pengunjung).

            Sengaja dibiarkan tampil, bukan disembunyikan, karena alurnya
            memang sudah direncanakan -- jadi lebih baik terlihat sekarang
            dan diklik kosong daripada muncul mendadak di kemudian hari.
            Tapi akibatnya harus jelas: menekan keduanya tidak melakukan
            apa-apa.

            Status ini TIDAK boleh dianggap selesai. Kalau nanti alurnya
            benar-benar ditambahkan, handler-nya harus menyatu dengan
            perubahan ini di satu commit yang sama -- jangan membiarkan
            tombol mati masuk ke produksi hanya karena "kelihatan beres".
          -->
          <div class="quick">
            <p class="quick-label">AKSES CEPAT</p>
            <div class="quick-btns">
              <button type="button" class="quick-btn">
                <div class="q-icon">
                  <font-awesome-icon icon="credit-card" />
                </div>
                <span>Bayar Cepat</span>
              </button>

              <button type="button" class="quick-btn">
                <div class="q-icon">
                  <font-awesome-icon icon="user-plus" />
                </div>
                <span>Daftar Akun</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@/utils/axios.js'
import { MySwal, showErrorToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { useOverdueGenNotification } from '@/composables/useOverdueGenNotification'
import { showInvoiceLockModal } from '@/composables/useInvoiceLock'
import { useLoginError } from '@/composables/useLoginError'
import { getDashboardRoute } from '@/router/dashboardRoutes'
import { useBranding } from '@/composables/useBranding'
import { terapkanTema } from '@/utils/applyTheme'
import { simpanTenantSlug } from '@/utils/tenantSlug'
import '@/assets/css/login.css'

const router = useRouter()
const uiStore = useUiStore()
const { checkOverdueGenOnMount } = useOverdueGenNotification()
const { muatBranding, judulLogin, subJudulLogin, deskripsiLogin, warnaLokasi } = useBranding()

/*
 * Isi headline ikut lokasi.
 *
 * Dipakai `computed`, bukan `ref` yang diisi sekali di `onMounted`: nilainya
 * berasal dari request yang belum selesai saat komponen ini dirender, jadi
 * kalau hanya diambil sekali, teks yang tampil di detik pertama adalah teks
 * bawaan -- dan begitu request selesai, teksnya berganti di depan mata.
 * `computed` membaca lagi setiap branding berubah.
 *
 * `terapkanTema` dipanggil di sini, bukan hanya di `main.js`. Halaman ini
 * dirender tanpa guard, jadi tidak selalu melewati bootstrap di `main.js`
 * (jika itu sudah selesai, pemanggilan kedua hanya menulis nilai yang sama).
 * Yang penting: CSS login memakai warna lokasi, dan warna itu harus sudah
 * terpasang sebelum halaman ini tampil.
 */
const judul = computed(() => judulLogin())
const subJudul = computed(() => subJudulLogin())
const deskripsi = computed(() => deskripsiLogin())

const form = ref({
  email: '',
  password: '',
})

const loading = ref(false)
const showPassword = ref(false)

/*
 * Pesan kegagalan login menempel di bawah inputnya sendiri.
 *
 * Lihat `composables/useLoginError.js` untuk alasannya. Ringkasnya: popup
 * "Akses Ditolak" menutupi kolom yang salah, jadi user harus menutup dialog
 * dulu sebelum bisa mengoreksinya.
 */
const { errors, bersihkan, terapkan } = useLoginError()

const togglePassword = () => {
  showPassword.value = !showPassword.value
}

// Sumber tunggal yang sama dengan router (lihat router/dashboardRoutes.js).
// Jangan diduplikasi di sini — itulah penyebab surveyor punya 2 URL berbeda.

onMounted(() => {
  const urlParams = new URLSearchParams(window.location.search)
  if (urlParams.get('logout')) {
    const name = urlParams.get('name')
    MySwal.fire({
      toast: true,
      position: 'top-start',
      icon: 'success',
      title: 'Logout Berhasil',
      text: name ? `Terima kasih, ${name}` : 'Terima kasih!',
      showConfirmButton: false,
      timer: 3000,
      timerProgressBar: true,
      customClass: {
        popup: 'swal-toast-custom',
        title: 'swal-toast-title',
        container: 'swal-toast-container',
      },
    })
    window.history.replaceState({}, document.title, window.location.pathname)
  }
})

/*
 * Branding di-load di sini juga, bukan hanya di `main.js`.
 *
 * Alasannya urutan: `mount()` selesai lebih dulu dari request apa pun, jadi
 * tanpa pemanggilan tambahan ini halaman login dirender dalam warna bawaan
 * lalu berubah warna beberapa ratus milidetik kemudian. Untuk tombol login
 * yang warnanya ikut berubah, kedipan itu terlihat.
 *
 * `muatBranding()` diam-diam melakukan dedupe, jadi pemanggilan ganda dari
 * `main.js` dan dari sini tetap satu request.
 */
muatBranding().then(() => {
  terapkanTema(warnaLokasi())
})

const handleLogin = async () => {
  /*
   * Kolom kosong juga ditampilkan inline, bukan lewat popup.
   *
   * Popup "Oops... Email dan password wajib diisi!" menutupi kedua kolom
   * yang justru harus diisi -- persis masalah yang diselesaikan oleh sisa
   * perubahan ini. Dua kolom kosong ditandai sekaligus supaya yang perlu
   * diperbaiki terlihat tanpa perlu ditebak.
   *
   * Validasi di sini hanya untuk memberi arahan dengan cepat; penentuannya
   * tetap `$request->validate()` di backend.
   */
  errors.email = form.value.email ? '' : 'Alamat email wajib diisi.'
  errors.password = form.value.password ? '' : 'Kata sandi wajib diisi.'

  if (errors.email || errors.password) {
    document.getElementById(errors.email ? 'email' : 'password')?.focus()
    return
  }

  loading.value = true
  try {
    const response = await axios.post('/login', {
      email: form.value.email,
      password: form.value.password,
    })

    const res = response.data

    if (res.success) {
      localStorage.setItem('auth_token', res.data.token)
      const expireTime = Date.now() + 8 * 60 * 60 * 1000
      localStorage.setItem('auth_expires_at', expireTime.toString())
      uiStore.setUserData(res.data.user)
      uiStore.setUserRole(res.data.user.role)

      /*
       * Slug lokasi disimpan agar request berikutnya bisa membawa
       * identitasnya (lihat `utils/tenantSlug.js`).
       *
       * Di development lokal tidak ada subdomain per lokasi, jadi tanpa ini
       * backend tidak bisa tahu lokasi mana yang dilayani -- dan endpoint
       * branding akan selalu menjawab bawaan.
       */
      simpanTenantSlug(res.data.tenant_slug)

      MySwal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Login Berhasil',
        text: `Selamat datang, ${res.data.user.name}`,
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        customClass: {
          popup: 'swal-toast-custom',
          title: 'swal-toast-title',
          container: 'swal-toast-container',
        },
      })

      // Pindah ke dashboard dulu supaya user langsung melihat hasil login.
      const redirectRoute = getDashboardRoute(res.data.user.role)
      router.push(redirectRoute)

      /*
       * Generate piutang SUDAH berjalan di backend.
       *
       * `POST /api/login` memanggil `ReceivableGenerator::runIfDue()` dan
       * mengembalikan hasilnya di `data.piutang_tunggakan`. Jadi di sini
       * TIDAK lagi dikirim request generate kedua -- yang akan menjalankan
       * pekerjaan yang sama dua kali.
       *
       * Yang tersisa hanyalah menampilkan hasilnya. Dipanggil setelah
       * `requestAnimationFrame` supaya popup tidak competes dengan
       * navigasi ke dashboard dan toast "Login Berhasil".
       *
       * `piutang_tunggakan` bernilai `null` kalau:
       *   - belum tanggal generate bulan ini, atau
       *   - sudah dijalankan hari ini, atau
       *   - prosesnya gagal.
       *
       * Ketiganya kondisi normal yang tidak perlu popup apa pun, dan
       * `checkOverdueGenOnMount()` tidak menampilkan apa pun untuknya.
       *
       * Pengecekan role TIDAK dilakukan di sini. `checkOverdueGenOnMount`
       * sudah berhenti sendiri untuk non-admin SEBELUM apa pun, jadi
       * teknisi tidak melihat popup dan tidak membuang waktu.
       */
      await new Promise((resolve) => requestAnimationFrame(resolve))
      checkOverdueGenOnMount(res.data.piutang_tunggakan)
    } else {
      throw new Error(res.message || 'Login Gagal')
    }
  } catch (error) {
    /*
     * 402 = kredensial BENAR, tapi tagihan langganan belum lunas.
     *
     * Ditangani sebelum sisa bloknya karena inilah yang diminta: user tidak
     * masuk ke aplikasi, tapi tetap tahu persis apa yang harus dibayar. Di
     * bawahnya pesan kegagalan kredensial sengaja tidak lewat popup -- dan
     * popup tagihan pun tetap jadi satu-satunya dialog di halaman ini, karena
     * di sini user memang punya sesuatu yang harus dilakukan (bayar atau
     * keluar), bukan sekadar mencoba lagi.
     *
     * `return` di sini penting. Tanpa itu blok error generic ikut berjalan dan
     * menampilkan pesan kedua di atas dialog tagihan -- dan pesan yang
     * menuding kolom sementara user sedang sibuk membaca nominal tagihan.
     *
     * Cookie dan token TIDAK ikut dibersihkan di sini: login memang gagal
     * jadi token juga tidak pernah terbit. Yang dibersihkan hanya kalau popup
     * ditutup lewat tombol "Keluar".
     */
    if (error.response?.status === 402) {
      await showInvoiceLockModal(error.response.data?.data, {
        logout: () => {
          window.location.href = '/login?logout=invoice'
        },
      })
      return
    }

    /*
     * Kegagalan yang bisa ditunjuk ke kolom tampil sebagai pesan di bawah
     * kolom itu (422 dari validasi/kredensial, 429 dari throttle).
     *
     * `terapkan()` mengembalikan `true` kalau sudah menanganinya. Yang
     * tersisa adalah kegagalan yang tidak punya kolom -- jaringan putus,
     * timeout, 500 -- dan itu memang tidak bisa diletakkan di bawah input
     * tertentu, jadi toast adalah tempat yang benar untuknya.
     *
     * Toast, bukan popup dengan tombol, karena user tidak punya keputusan
     * yang harus dibuat di sini: yang harus dilakukan sudah jelas, yaitu
     * mencoba lagi. Tombol "OK" hanya menambah satu klik yang tidak menambah
     * informasi apa pun.
     */
    if (!terapkan(error)) {
      showErrorToast(error.response?.data?.message || 'Gagal menghubungi server.')
    }

    /*
     * Isi kata sandi dikosongkan setelah percobaan gagal.
     *
     * Alasannya bukan keamanan saja: pesan "Kata sandi salah" di bawah kolom
     * itu hanya berarti isi kolomnya masih yang salah, jadi membiarkannya
     * ada hanya menghasilkan satu percobaan lagi dengan isi yang sama. Email
     * dibiarkan supaya tidak melelahkan pada percobaan berulang yang memang
     * gagal.
     *
     * Kursor dialihkan ke kolom yang benar-benar bermasalah, bukan selalu ke
     * kata sandi. Kalau yang salah adalah email, melodokkan kata sandi --
     * yang isinya sudah benar -- hanya membuat orang mengoreksi kolom yang
     * tidak perlu diperbaiki. (`terapkan()` sudah mengembalikan `true` di
     * sini, jadi pasti ada tepat satu `errors` yang terisi.)
     *
     * Pada 429 tidak ada yang dikoreksi sama sekali: emailnya sudah benar,
     * dan yang hilang bukan isinya tapi kesabaran menunggu batasnya.
     */
    if (error.response?.status !== 429) {
      if (errors.password) {
        form.value.password = ''
      }

      document.getElementById(errors.password ? 'password' : 'email')?.focus()
    }
  } finally {
    loading.value = false
  }
}
</script>
