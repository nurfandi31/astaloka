<template>
  <div class="form-page">
    <div class="form-page__back">
      <router-link to="/central/lokasi">
        <font-awesome-icon icon="arrow-left" />
        Kembali ke Daftar Lokasi
      </router-link>
    </div>

    <form class="form-card" @submit.prevent="handleSubmit">
      <!-- ===== Identitas ===== -->
      <section class="form-section">
        <div class="form-section__head">
          <div class="form-section__icon">
            <font-awesome-icon icon="location-dot" />
          </div>
          <div>
            <h2>Identitas Lokasi</h2>
            <p>Informasi utama yang akan ditampilkan di seluruh aplikasi.</p>
          </div>
        </div>

        <div class="form-grid">
          <BaseInput
            v-model="form.nama"
            label="Nama Lokasi *"
            placeholder="Contoh: Tirto Mulo"
            prefix-icon="location-dot"
            :error="errors.nama"
          />

          <BaseInput
            v-model="form.nama_lembaga"
            label="Nama Lembaga / BUMD"
            placeholder="Contoh: Perum Tirto Mulo"
            prefix-icon="building"
            :error="errors.nama_lembaga"
          />

          <BaseInput
            v-model="form.slug"
            label="Slug (URL)"
            placeholder="tirto-mulo"
            prefix-icon="link"
            :error="errors.slug"
            hint="Huruf kecil, angka, dan tanda hubung. Dipakai pada subdomain dan nama database."
          />

          <BaseInput
            v-model="form.domain"
            label="Domain Utama"
            placeholder="tirtomulo.astaloka.co.id"
            prefix-icon="globe"
            :error="errors.domain"
            hint="Host yang mengenali lokasi ini saat diakses dari browser."
          />

          <BaseInput
            v-model="form.domain_alternatif"
            label="Domain Alternatif"
            placeholder="tirto-mulo.astaloka.test"
            prefix-icon="globe"
            :error="errors.domain_alternatif"
            hint="Opsional. Dipakai saat domain utama belum aktif."
          />
        </div>

        <div class="slug-preview" v-if="slugPreview">
          <font-awesome-icon icon="circle-info" />
          <div>
            <p class="slug-preview__label">Database akan dibuat dengan nama:</p>
            <code class="slug-preview__value">{{ slugPreview }}</code>
          </div>
        </div>
      </section>

      <!-- ===== Kontak ===== -->
      <section class="form-section">
        <div class="form-section__head">
          <div class="form-section__icon form-section__icon--slate">
            <font-awesome-icon icon="address-book" />
          </div>
          <div>
            <h2>Kontak</h2>
            <p>Alamat dan kontak administrasi lokasi.</p>
          </div>
        </div>

        <div class="form-grid">
          <BaseInput
            v-model="form.alamat"
            label="Alamat"
            type="textarea"
            :rows="3"
            placeholder="Alamat lengkap kantordsaung lokasi"
            :error="errors.alamat"
          />

          <BaseInput
            v-model="form.email"
            label="Email"
            type="email"
            placeholder="kontak@lokasi.id"
            prefix-icon="envelope"
            :error="errors.email"
          />

          <BaseInput
            v-model="form.telepon"
            label="Telepon"
            placeholder="0812xxxxxxx"
            prefix-icon="phone"
            :error="errors.telepon"
          />
        </div>
      </section>

      <!-- ===== Langganan ===== -->
      <section class="form-section">
        <div class="form-section__head">
          <div class="form-section__icon form-section__icon--amber">
            <font-awesome-icon icon="calendar-days" />
          </div>
          <div>
            <h2>Langganan</h2>
            <p>Batas masa aktif dan kuota user lokasi.</p>
          </div>
        </div>

        <div class="form-grid">
          <BaseInput
            v-model="form.tgl_berakhir"
            label="Tanggal Berakhir"
            type="date"
            prefix-icon="calendar"
            :error="errors.tgl_berakhir"
            hint="Kosongkan untuk langganan tanpa batas waktu."
          />

          <BaseInput
            v-model="form.max_users"
            label="Maksimum User"
            type="number"
            placeholder="Contoh: 25"
            prefix-icon="users"
            :error="errors.max_users"
            hint="Kosongkan untuk tanpa batas kuota."
          />
        </div>
      </section>

      <!-- ===== Provisioning ===== -->
      <section class="form-section form-section--highlight">
        <div class="form-section__head">
          <div class="form-section__icon form-section__icon--green">
            <font-awesome-icon icon="database" />
          </div>
          <div>
            <h2>Penyiapan Database</h2>
            <p>Database lokasi disiapkan otomatis saat pendaftaran.</p>
          </div>
        </div>

        <!--
          | Bukan toggle, dan bukan checkbox.
          |
          | Opsi "siapkan nanti" pernah ada di sini, dan itu jebakan: lokasi
          | terdaftar di panel, terlihat aktif, tapi setiap request ke lokasi
          | itu gagal dengan error koneksi -- dan tidak ada halaman yang
          | menunjukkan hal itu. Superadmin baru tahu saat pelanggan menelepon.
          |
          | Backend sekarang menolak `provision: false` dengan 422, jadi
          | checkbox yang tersisa hanya akan menambah satu langkah yang selalu
          | berakhir sama. Yang ditampilkan sekarang adalah informasi: apa
          | yang akan dibuat, dan berapa lama.
        -->
        <ul class="provision-list">
          <li>
            <font-awesome-icon icon="check" />
            Database terpisah <code>{{ slugPreview.value || 'astaloka_lokasi_' + form.slug }}</code>
          </li>
          <li>
            <font-awesome-icon icon="check" />
            Seluruh migration aplikasi
          </li>
          <li>
            <font-awesome-icon icon="check" />
            Master data: chart of accounts, jabatan, jenis transaksi, arus kas
          </li>
          <li>
            <font-awesome-icon icon="check" />
            Identitas lokasi pada tabel <code>settings</code> (nama lembaga untuk kop laporan)
          </li>
        </ul>

        <div class="locked-note">
          <font-awesome-icon icon="circle-info" />
          <div>
            <p class="locked-note__title">Tidak bisa dilewati</p>
            <p class="locked-note__desc">
              Prosesnya berjalan sinkron di server dan memakan waktu sekitar 1-2 menit. Jangan tutup
              halaman selama proses berjalan. Semua langkah dicatat di jejak audit.
            </p>
          </div>
        </div>
      </section>

      <!-- ===== Admin Lokasi ===== -->
      <section class="form-section">
        <div class="form-section__head">
          <div class="form-section__icon form-section__icon--violet">
            <font-awesome-icon icon="user-shield" />
          </div>
          <div>
            <h2>Admin Lokasi</h2>
            <p>Akun pertama yang akan mengelola aplikasi di lokasi tersebut.</p>
          </div>
        </div>

        <div class="form-grid">
          <BaseInput
            v-model="form.admin_name"
            label="Nama Admin"
            placeholder="Nama lengkap admin lokasi"
            prefix-icon="user"
            :error="errors.admin_name"
          />

          <BaseInput
            v-model="form.admin_email"
            label="Email Admin"
            type="email"
            placeholder="admin@lokasi.id"
            prefix-icon="envelope"
            :error="errors.admin_email"
          />

          <BaseInput
            v-model="form.admin_password"
            label="Kata Sandi Admin"
            type="password"
            placeholder="Minimal 8 karakter"
            prefix-icon="lock"
            :error="errors.admin_password"
            hint="Boleh dikosongkan. Akun admin bisa dibuat nanti dengan tenancy:make-user."
          />
        </div>
      </section>

      <!-- ===== Aksi ===== -->
      <div class="form-actions">
        <router-link to="/central/lokasi" class="btn btn--ghost">Batal</router-link>
        <button type="submit" class="btn btn--primary" :disabled="saving">
          <font-awesome-icon v-if="saving" icon="spinner" spin />
          <font-awesome-icon v-else icon="check" />
          {{ saving ? 'Menyiapkan Lokasi...' : 'Daftarkan Lokasi' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { reactive, ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { MySwal } from '@/utils/swal'
import { locationService } from '@/services/central.service'
import '@/assets/css/central-form.css'

const router = useRouter()
const saving = ref(false)
const errors = ref({})

const form = reactive({
  nama: '',
  nama_lembaga: '',
  slug: '',
  domain: '',
  domain_alternatif: '',
  alamat: '',
  email: '',
  telepon: '',
  tgl_berakhir: '',
  max_users: '',
  admin_name: '',
  admin_email: '',
  admin_password: '',
})

/**
 * Pratinjau nama database mengikuti aturan yang sama dengan backend
 * (`LocationController::databaseNameFor`): prefix + slug dengan tanda hubung
 * diganti underscore, dipotong 64 karakter. Menampilkan pratinjau ini
 * mencegah kebingungan saat lokasi tersimpan dengan nama database yang tidak
 * seperti yang dikira admin.
 */
const slugPreview = computed(() => {
  const slug = form.slug.trim().toLowerCase()
  if (!slug) return ''
  return `astaloka_lokasi_${slug.replace(/-/g, '_')}`.slice(0, 64).replace(/_+$/, '')
})

const handleSubmit = async () => {
  errors.value = {}

  if (!form.nama.trim()) {
    errors.value.nama = 'Nama lokasi wajib diisi.'
    return
  }

  if (!form.slug.trim()) {
    errors.value.slug = 'Slug wajib diisi.'
    return
  }

  saving.value = true

  // Field kosong harus jadi null, bukan string kosong: backend memvalidasi
  // `email` dengan rule `email`, dan string kosong akan ditolak meski opsional.
  const payload = {
    nama: form.nama.trim(),
    nama_lembaga: form.nama_lembaga.trim() || null,
    slug: form.slug.trim().toLowerCase(),
    domain: form.domain.trim() || null,
    domain_alternatif: form.domain_alternatif.trim() || null,
    alamat: form.alamat.trim() || null,
    email: form.email.trim() || null,
    telepon: form.telepon.trim() || null,
    tgl_berakhir: form.tgl_berakhir || null,
    max_users: form.max_users ? Number(form.max_users) : null,
    admin_name: form.admin_name.trim() || null,
    admin_email: form.admin_email.trim() || null,
    admin_password: form.admin_password || null,
  }

  // Konfirmasi selalu ditampilkan, tanpa syarat. Sebelumnya konfirmasi ini
  // hanya muncul kalau checkbox provisioning aktif -- dan karena checkbox-nya
  // bisa dimatikan, ada jalur yang sama sekali tanpa peringatan untuk operasi yang
  // butuh 1-2 menit dan tidak bisa dibatalkan.
  const confirmed = await MySwal.fire({
    title: 'Siapkan Database Lokasi?',
    html: `Lokasi <strong>${form.nama}</strong> akan dibuat beserta database
      <code>${slugPreview.value || '-'}</code>, seluruh migration, dan master
      datanya.<br><br>
      Prosesnya berjalan sinkron di server dan dapat memakan waktu
      <strong>1-2 menit</strong>. Jangan tutup halaman selama proses berjalan.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#0284c7',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Ya, lanjutkan',
    cancelButtonText: 'Batal',
  })

  if (!confirmed.isConfirmed) {
    saving.value = false
    return
  }

  try {
    const res = await locationService.create(payload)

    await MySwal.fire({
      icon: 'success',
      title: 'Lokasi Berhasil Didaftarkan',
      html: res.data?.message || 'Lokasi baru siap digunakan.',
      confirmButtonColor: '#0284c7',
    })

    router.push({ name: 'central-lokasi-detail', params: { id: res.data.data.id } })
  } catch (error) {
    // 422 = validasi gagal. Tampilkan pesan per-field supaya admin tahu
    // bagian mana yang perlu diperbaiki, bukan hanya toast global.
    if (error.response?.status === 422) {
      const fieldErrors = error.response.data?.errors || {}

      if (Object.keys(fieldErrors).length > 0) {
        errors.value = Object.fromEntries(
          Object.entries(fieldErrors).map(([key, msgs]) => [key, msgs[0]]),
        )

        await MySwal.fire({
          icon: 'error',
          title: 'Data Belum Lengkap',
          text: error.response.data?.message || 'Periksa kembali isian yang ditandai.',
          confirmButtonColor: '#0284c7',
        })

        saving.value = false
        return
      }
    }

    await MySwal.fire({
      icon: 'error',
      title: 'Gagal Mendaftarkan Lokasi',
      text: error.response?.data?.message || 'Terjadi kesalahan pada server.',
      confirmButtonColor: '#0284c7',
    })
  } finally {
    saving.value = false
  }
}
</script>
