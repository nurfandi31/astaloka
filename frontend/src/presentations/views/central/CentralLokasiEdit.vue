<template>
  <div class="form-page">
    <div class="form-page__back">
      <router-link :to="{ name: 'central-lokasi-detail', params: { id: route.params.id } }">
        <font-awesome-icon icon="arrow-left" />
        Kembali ke Detail Lokasi
      </router-link>
    </div>

    <div v-if="loading" class="form-card form-card--state">
      <font-awesome-icon icon="spinner" spin />
      <span>Memuat data lokasi...</span>
    </div>

    <form v-else class="form-card" @submit.prevent="handleSubmit">
      <div class="form-card__head">
        <div>
          <h2>{{ form.nama || 'Ubah Lokasi' }}</h2>
          <p class="form-card__slug">{{ form.slug }}</p>
        </div>
        <span class="status-pill" :class="form.is_active ? 'status-pill--on' : 'status-pill--off'">
          {{ form.is_active ? 'Aktif' : 'Nonaktif' }}
        </span>
      </div>

      <!-- ===== Informasi tetap ===== -->
      <div class="locked-note">
        <font-awesome-icon icon="lock" />
        <div>
          <p class="locked-note__title">Slug dan nama database tidak dapat diubah</p>
          <p class="locked-note__desc">
            Keduanya sudah terikat ke data yang sudah berjalan. Mengubahnya berarti memindahkan
            database secara fisik dan berisiko merusak data yang ada.
          </p>
        </div>
      </div>

      <section class="form-section">
        <div class="form-section__head">
          <div class="form-section__icon form-section__icon--slate">
            <font-awesome-icon icon="location-dot" />
          </div>
          <div>
            <h3>Identitas Lokasi</h3>
          </div>
        </div>

        <div class="form-grid">
          <BaseInput v-model="form.nama" label="Nama Lokasi *" :error="errors.nama" />
          <BaseInput
            v-model="form.nama_lembaga"
            label="Nama Lembaga / BUMD"
            :error="errors.nama_lembaga"
          />
          <BaseInput
            v-model="form.slug"
            label="Slug"
            disabled
            :hint="`Database: ${form.database}`"
          />
          <BaseInput
            v-model="form.domain"
            label="Domain Utama"
            placeholder="tirtomulo.astaloka.co.id"
            prefix-icon="globe"
            :error="errors.domain"
          />
          <BaseInput
            v-model="form.domain_alternatif"
            label="Domain Alternatif"
            prefix-icon="globe"
            :error="errors.domain_alternatif"
          />
        </div>
      </section>

      <section class="form-section">
        <div class="form-section__head">
          <div class="form-section__icon form-section__icon--slate">
            <font-awesome-icon icon="address-book" />
          </div>
          <div>
            <h3>Kontak</h3>
          </div>
        </div>

        <div class="form-grid">
          <BaseInput
            v-model="form.alamat"
            label="Alamat"
            type="textarea"
            :rows="3"
            :error="errors.alamat"
          />
          <BaseInput
            v-model="form.email"
            label="Email"
            type="email"
            prefix-icon="envelope"
            :error="errors.email"
          />
          <BaseInput
            v-model="form.telepon"
            label="Telepon"
            prefix-icon="phone"
            :error="errors.telepon"
          />
        </div>
      </section>

      <section class="form-section">
        <div class="form-section__head">
          <div class="form-section__icon form-section__icon--amber">
            <font-awesome-icon icon="calendar-days" />
          </div>
          <div>
            <h3>Langganan &amp; Status</h3>
          </div>
        </div>

        <div class="form-grid">
          <BaseInput
            v-model="form.tgl_berakhir"
            label="Tanggal Berakhir"
            type="date"
            prefix-icon="calendar"
            :error="errors.tgl_berakhir"
            hint="Kosongkan untuk tanpa batas waktu."
          />
          <BaseInput
            v-model="form.max_users"
            label="Maksimum User"
            type="number"
            prefix-icon="users"
            :error="errors.max_users"
          />
        </div>

        <label class="toggle-row">
          <input v-model="form.is_active" type="checkbox" />
          <span class="toggle-row__box">
            <font-awesome-icon icon="check" />
          </span>
          <span class="toggle-row__text">
            <span class="toggle-row__title">Lokasi Aktif</span>
            <span class="toggle-row__desc">
              Lokasi nonaktif tidak lagi bisa diakses framework ini dan disembunyikan dari daftar
              publik. Datanya tetap utuh.
            </span>
          </span>
        </label>
      </section>

      <div class="form-actions">
        <router-link
          :to="{ name: 'central-lokasi-detail', params: { id: route.params.id } }"
          class="btn btn--ghost"
        >
          Batal
        </router-link>
        <button type="submit" class="btn btn--primary" :disabled="saving">
          <font-awesome-icon v-if="saving" icon="spinner" spin />
          <font-awesome-icon v-else icon="check" />
          {{ saving ? 'Menyimpan...' : 'Simpan Perubahan' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { reactive, ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MySwal, showSuccessToast, showErrorToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { locationService } from '@/services/central.service'
import '@/assets/css/central-form.css'

const route = useRoute()
const router = useRouter()
const uiStore = useUiStore()

const loading = computed(() => uiStore.loading)
const saving = ref(false)
const errors = ref({})

const form = reactive({
  nama: '',
  nama_lembaga: '',
  slug: '',
  database: '',
  domain: '',
  domain_alternatif: '',
  alamat: '',
  email: '',
  telepon: '',
  tgl_berakhir: '',
  max_users: '',
  is_active: true,
})

const loadData = async () => {
  try {
    const res = await locationService.getById(route.params.id)
    const data = res.data?.data

    if (!data) throw new Error('Data lokasi tidak ditemukan.')

    Object.assign(form, {
      nama: data.nama || '',
      nama_lembaga: data.nama_lembaga || '',
      slug: data.slug || '',
      database: data.database || '',
      domain: data.domain || '',
      domain_alternatif: data.domain_alternatif || '',
      alamat: data.alamat || '',
      email: data.email || '',
      telepon: data.telepon || '',
      tgl_berakhir: data.tgl_berakhir || '',
      max_users: data.max_users ?? '',
      is_active: Boolean(data.is_active),
    })
  } catch (error) {
    showErrorToast(error)
    router.push('/central/lokasi')
  }
}

const handleSubmit = async () => {
  errors.value = {}

  if (!form.nama.trim()) {
    errors.value.nama = 'Nama lokasi wajib diisi.'
    return
  }

  saving.value = true

  try {
    const res = await locationService.update(route.params.id, {
      nama: form.nama.trim(),
      nama_lembaga: form.nama_lembaga.trim() || null,
      domain: form.domain.trim() || null,
      domain_alternatif: form.domain_alternatif.trim() || null,
      alamat: form.alamat.trim() || null,
      email: form.email.trim() || null,
      telepon: form.telepon.trim() || null,
      tgl_berakhir: form.tgl_berakhir || null,
      max_users: form.max_users ? Number(form.max_users) : null,
      is_active: form.is_active,
    })

    showSuccessToast(res.data?.message || 'Data lokasi diperbarui.')
    router.push({ name: 'central-lokasi-detail', params: { id: route.params.id } })
  } catch (error) {
    if (error.response?.status === 422) {
      const fieldErrors = error.response.data?.errors || {}

      if (Object.keys(fieldErrors).length > 0) {
        errors.value = Object.fromEntries(
          Object.entries(fieldErrors).map(([key, msgs]) => [key, msgs[0]]),
        )

        MySwal.fire({
          icon: 'error',
          title: 'Data Belum Lengkap',
          text: error.response.data?.message || 'Periksa kembali isian yang ditandai.',
          confirmButtonColor: '#0284c7',
        })

        return
      }
    }

    MySwal.fire({
      icon: 'error',
      title: 'Gagal Menyimpan',
      text: error.response?.data?.message || 'Terjadi kesalahan pada server.',
      confirmButtonColor: '#0284c7',
    })
  } finally {
    saving.value = false
  }
}

onMounted(loadData)
</script>
