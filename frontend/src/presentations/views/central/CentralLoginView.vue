<template>
  <div class="central-login">
    <div class="central-login__card">
      <div class="central-login__brand">
        <div class="central-login__mark">
          <font-awesome-icon icon="gem" />
        </div>
        <p class="central-login__kicker">Panel Pusat</p>
        <h1 class="central-login__title">ASTA BRATA</h1>
        <p class="central-login__subtitle">Kelola seluruh lokasi ASTALOKA dari satu tempat.</p>
      </div>

      <form class="central-login__form" @submit.prevent="handleLogin">
        <div class="field">
          <label for="central-email">Alamat Email</label>
          <div class="field__wrap">
            <span class="field__icon">
              <font-awesome-icon icon="user" />
            </span>
            <input
              id="central-email"
              v-model="form.email"
              type="email"
              placeholder="superadmin@astabrata.co.id"
              required
              autocomplete="username"
            />
          </div>
        </div>

        <div class="field">
          <label for="central-password">Kata Sandi</label>
          <div class="field__wrap">
            <span class="field__icon">
              <font-awesome-icon icon="lock" />
            </span>
            <input
              id="central-password"
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="••••••••"
              required
              autocomplete="current-password"
            />
            <button
              type="button"
              class="field__toggle"
              :aria-label="showPassword ? 'Sembunyikan sandi' : 'Tampilkan sandi'"
              @click="showPassword = !showPassword"
            >
              <font-awesome-icon :icon="showPassword ? 'eye-slash' : 'eye'" />
            </button>
          </div>
        </div>

        <button type="submit" class="central-login__submit" :disabled="loading">
          <font-awesome-icon v-if="loading" icon="spinner" spin />
          <span>{{ loading ? 'Memproses...' : 'Masuk ke Panel Pusat' }}</span>
        </button>
      </form>

      <div class="central-login__footer">
        <p>
          Halaman ini hanya untuk superadmin ASTA BRATA.
          <router-link to="/login">Masuk sebagai pengguna lokasi</router-link>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { MySwal } from '@/utils/swal'
import { centralAuthService } from '@/services/central.service'
import { isCentralSessionValid, clearCentralSession } from '@/utils/centralAxios'

const router = useRouter()

const form = reactive({ email: '', password: '' })
const loading = ref(false)
const showPassword = ref(false)

const handleLogin = async () => {
  if (!form.email || !form.password) {
    MySwal.fire({
      icon: 'warning',
      title: 'Perhatian',
      text: 'Email dan kata sandi wajib diisi.',
      confirmButtonColor: '#0284c7',
    })
    return
  }

  loading.value = true

  try {
    const body = await centralAuthService.login({
      email: form.email,
      password: form.password,
    })

    if (!body?.success) {
      throw new Error(body?.message || 'Login gagal.')
    }

    await MySwal.fire({
      toast: true,
      position: 'top-end',
      icon: 'success',
      title: 'Login Berhasil',
      text: `Selamat datang, ${body.data.user.name}`,
      showConfirmButton: false,
      timer: 2500,
      timerProgressBar: true,
      customClass: {
        popup: 'swal-toast-custom',
        title: 'swal-toast-title',
        container: 'swal-toast-container',
      },
    })

    router.push('/central')
  } catch (error) {
    // 422 = kredensial salah atau akun ada tapi bukan superadmin.
    // Backend sengaja menyamakan keduanya jadi pesan yang sama, supaya
    // email yang valid di database pusat tidak bisa dipetakan orang lain
    // hanya dengan mencoba login.
    const message =
      error.response?.data?.message ||
      error.response?.data?.errors?.email?.[0] ||
      'Email atau kata sandi salah.'

    MySwal.fire({
      icon: 'error',
      title: 'Akses Ditolak',
      text: message,
      confirmButtonColor: '#0284c7',
    })
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  const params = new URLSearchParams(window.location.search)

  // Sesi masih hidup -> tidak perlu login ulang.
  if (isCentralSessionValid()) {
    router.replace('/central')
    return
  }

  // Sisa sesi tidak valid (kedaluwarsa atau ditolak server) -> bersihkan
  // sebelum menampilkan form, supaya tidak ada token mati yang ikut terkirim
  // ke setiap request berikutnya.
  clearCentralSession()

  if (params.get('expired')) {
    MySwal.fire({
      toast: true,
      position: 'top-end',
      icon: 'warning',
      title: 'Sesi Berakhir',
      text: 'Silakan masuk kembali ke panel pusat.',
      showConfirmButton: false,
      timer: 3500,
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
</script>

<style scoped>
.central-login {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem 1rem;
  background:
    radial-gradient(circle at 15% 20%, rgba(11, 122, 158, 0.12), transparent 45%),
    radial-gradient(circle at 85% 80%, rgba(59, 130, 246, 0.1), transparent 45%), #f1f5f9;
}

.central-login__card {
  width: 100%;
  max-width: 26rem;
  background: #fff;
  border-radius: 20px;
  padding: 2.5rem 2rem;
  box-shadow:
    0 20px 45px -12px rgba(15, 23, 42, 0.18),
    0 0 0 1px rgba(15, 23, 42, 0.04);
}

.central-login__brand {
  text-align: center;
  margin-bottom: 2rem;
}

.central-login__mark {
  width: 3.5rem;
  height: 3.5rem;
  margin: 0 auto 1rem;
  border-radius: 14px;
  background: linear-gradient(135deg, #0284c7, #0ea5e9);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  box-shadow: 0 8px 20px -6px rgba(11, 122, 158, 0.6);
}

.central-login__kicker {
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #0284c7;
  margin: 0 0 0.25rem;
}

.central-login__title {
  font-size: 1.75rem;
  font-weight: 900;
  letter-spacing: 0.08em;
  color: #0f172a;
  margin: 0 0 0.5rem;
}

.central-login__subtitle {
  font-size: 0.85rem;
  color: #64748b;
  margin: 0;
}

.central-login__form {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
}

.field label {
  display: block;
  font-size: 0.75rem;
  font-weight: 700;
  color: #475569;
  margin-bottom: 0.4rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.field__wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.field__icon {
  position: absolute;
  left: 0.85rem;
  color: #94a3b8;
  font-size: 0.85rem;
  pointer-events: none;
}

.field input {
  width: 100%;
  padding: 0.7rem 2.5rem 0.7rem 2.4rem;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  font-size: 0.9rem;
  color: #0f172a;
  background: #f8fafc;
  transition: all 0.2s ease;
}

.field input::placeholder {
  color: #94a3b8;
}

.field input:focus {
  outline: none;
  border-color: #0284c7;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(11, 122, 158, 0.12);
}

.field__toggle {
  position: absolute;
  right: 0.75rem;
  background: none;
  border: none;
  color: #94a3b8;
  padding: 0.25rem;
  display: flex;
  transition: color 0.2s ease;
}

.field__toggle:hover {
  color: #475569;
}

.central-login__submit {
  margin-top: 0.4rem;
  padding: 0.8rem 1rem;
  border: none;
  border-radius: 10px;
  background: linear-gradient(135deg, #0284c7, #0ea5e9);
  color: #fff;
  font-size: 0.9rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  transition: all 0.2s ease;
  box-shadow: 0 8px 18px -8px rgba(11, 122, 158, 0.8);
}

.central-login__submit:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 12px 22px -8px rgba(11, 122, 158, 0.9);
}

.central-login__submit:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.central-login__footer {
  margin-top: 1.75rem;
  padding-top: 1.25rem;
  border-top: 1px solid #f1f5f9;
  text-align: center;
}

.central-login__footer p {
  font-size: 0.78rem;
  color: #94a3b8;
  margin: 0;
}

.central-login__footer a {
  color: #0284c7;
  font-weight: 700;
  text-decoration: none;
  margin-left: 0.25rem;
}

.central-login__footer a:hover {
  text-decoration: underline;
}
</style>
