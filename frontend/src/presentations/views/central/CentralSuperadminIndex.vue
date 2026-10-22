<template>
  <div class="ua">
    <!--
      Satu baris, bukan paragraf panjang di atas daftar.

      Yang sering dibutuhkan (ini daftar hanya user pusat) tetap tertulis di
      sini. Yang jarang dibutuhkan tapi penting (nonaktifkan = cabut token)
      pindah ke tooltip di sebelah ikon peringatan -- satu arahkan pointer,
      dan hilang lagi begitu pointer pergi.
    -->
    <p class="ua-hint">
      <font-awesome-icon icon="circle-info" class="ua-hint__icon" />

      <span class="ua-hint__text">
        Daftar ini <strong>hanya user pusat</strong>. Akun yang punya lokasi tidak muncul di sini
        &mdash; user lokasi diatur di aplikasi lokasi masing-masing.
      </span>

      <span
        class="ua-tip"
        tabindex="0"
        role="note"
        aria-label="Apa yang terjadi saat akun dinonaktifkan"
      >
        <font-awesome-icon icon="triangle-exclamation" class="ua-tip__trigger" />
        <span class="ua-tip__body">
          <strong>Menonaktifkan memutus akses, bukan hanya menutup pintu login.</strong>
          Semua token yang sudah terbit untuk akun itu dicabut &mdash; termasuk dari perangkat lain
          &mdash; jadi orang yang masih memegang token langsung keluar dari panel. Akunnya tidak
          dihapus dan bisa diaktifkan kembali kapan saja, tapi token lamanya tidak dikembalikan
          sehingga harus login ulang.
        </span>
      </span>
    </p>

    <!-- ===== Toolbar ===== -->
    <div class="ua-toolbar">
      <label class="ua-search">
        <font-awesome-icon icon="magnifying-glass" class="ua-search__icon" />
        <input v-model="searchQuery" type="search" placeholder="Cari nama atau email..." />
      </label>

      <!--
        Saringan status dihapus, bukan disembunyikan di dropdown.

        Daftar ini satu akun per admin pusat dan jarang lebih dari segelintir
        baris. Menyingkirkan yang nonaktif justru memunculkan masalah baru:
        akun yang aksesnya sudah dicabut jadi tidak terlihat di mana pun, dan
        satu-satunya cara menemukannya adalah mengetik namanya. Badge status
        di tiap kartu sudah menjawab "aktif atau mati" tanpa perlu klik.
      -->
      <button type="button" class="ua-add" @click="openAdd">
        <font-awesome-icon icon="plus" />
        Tambah User
      </button>
    </div>

    <!-- ===== Konten ===== -->
    <!--
      Kerangka kartu, bukan spinner di tengah layar.

      Daftar ini pendek -- satu akun per admin pusat, jarang lebih dari
      segelintir baris. Menunggu "Memuat..." berarti satu kedipan kosong
      di tempat yang tidak menjelaskan apa pun; beberapa kotak abu-abu
      menjelaskan bentuk apa yang akan muncul, jadi saat data tiba
      halamannya tidak melompat dari nol ke isi.
    -->
    <ul v-if="loading" class="ua-grid" aria-hidden="true">
      <li v-for="n in 4" :key="`sk-${n}`" class="ua-card ua-card--skeleton">
        <span class="ua-skeleton ua-skeleton--avatar" />
        <span class="ua-skeleton ua-skeleton--line ua-skeleton--w60" />
        <span class="ua-skeleton ua-skeleton--line ua-skeleton--w85" />
        <span class="ua-skeleton ua-skeleton--pill" />
      </li>
    </ul>

    <div v-else-if="error" class="ua-panel">
      <font-awesome-icon icon="circle-exclamation" class="ua-panel__icon" />
      <p class="ua-panel__title">Daftar user pusat tidak bisa dimuat</p>
      <p class="ua-panel__text">{{ error }}</p>
      <button type="button" class="ua-panel__action" @click="load">Coba lagi</button>
    </div>

    <div v-else-if="filtered.length === 0" class="ua-panel">
      <font-awesome-icon icon="user-shield" class="ua-panel__icon ua-panel__icon--muted" />
      <p class="ua-panel__title">
        {{ hasSearch ? 'Tidak ada user pusat yang cocok' : 'Belum ada user pusat selain Anda' }}
      </p>
      <p class="ua-panel__text">
        {{
          hasSearch
            ? 'Coba kata kunci lain, atau kosongkan kolom pencarian di atas.'
            : 'Tambahkan akun bila panel pusat perlu diakses lebih dari satu orang.'
        }}
      </p>
      <button v-if="hasSearch" type="button" class="ua-panel__action" @click="clearSearch">
        Bersihkan pencarian
      </button>
    </div>

    <ul v-else class="ua-grid">
      <li
        v-for="row in filtered"
        :key="row.id"
        class="ua-card"
        :class="{ 'ua-card--off': !row.is_active }"
      >
        <div class="ua-card__head">
          <span class="ua-avatar" :class="{ 'ua-avatar--off': !row.is_active }">
            {{ initialsOf(row) }}
          </span>

          <!--
            "Anda" hanya diberi tanda pada akun sendiri, karena itu satu
            informasi yang tidak bisa dibaca dari nama dan email: dua akun
            dengan nama serupa tetap berbeda Bedanya di sini.
          -->
          <span v-if="isSelf(row)" class="ua-card__self">Anda</span>
        </div>

        <p class="ua-card__name" :title="row.name">{{ row.name }}</p>

        <p class="ua-card__mail" :title="row.email">
          <font-awesome-icon icon="envelope" />
          <span>{{ row.email }}</span>
        </p>

        <p class="ua-card__status" :class="row.is_active ? 'is-on' : 'is-off'">
          <span class="ua-card__beacon" />
          {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
        </p>

        <p class="ua-card__time">Diperbarui {{ formatDate(row.updated_at) }}</p>

        <div class="ua-card__actions">
          <button
            type="button"
            class="ua-icon-btn"
            title="Ubah data akun"
            :aria-label="`Ubah data akun ${row.name}`"
            @click="openEdit(row)"
          >
            <font-awesome-icon icon="pen" />
          </button>

          <!--
            Tombol Nonaktifkan untuk akun sendiri sengaja mati, bukan disembunyikan.
            Sembunyi membuat orang mencari-cari tombol yang tidak pernah ada;
            mati dengan penjelasan di title memberi tahu aturan tersebut ada.
          -->
          <button
            type="button"
            class="ua-icon-btn ua-icon-btn--danger"
            :disabled="isSelf(row)"
            :title="
              isSelf(row)
                ? 'Akun yang sedang dipakai tidak bisa dinonaktifkan'
                : row.is_active
                  ? 'Nonaktifkan akun ini'
                  : 'Aktifkan kembali akun ini'
            "
            :aria-label="
              isSelf(row)
                ? 'Tidak bisa menonaktifkan akun sendiri'
                : row.is_active
                  ? `Nonaktifkan akun ${row.name}`
                  : `Aktifkan kembali akun ${row.name}`
            "
            @click="toggleActive(row)"
          >
            <font-awesome-icon :icon="row.is_active ? 'ban' : 'rotate-left'" />
          </button>
        </div>
      </li>
    </ul>

    <p v-if="!loading && !error && filtered.length" class="ua-count">
      Menampilkan <strong>{{ filtered.length }}</strong> dari {{ meta.total ?? users.length }} user
      pusat
      <template v-if="hasSearch">&middot; hasil pencarian</template>
    </p>

    <!-- ===== Dialog ===== -->
    <BaseModal
      v-if="formOpen"
      :title="editing ? 'Ubah User Pusat' : 'Tambah User Pusat'"
      :subtitle="editing ? editing.email : 'Akun baru untuk masuk ke panel pusat.'"
      icon="user-shield"
      size="md"
      @close="closeForm"
    >
      <template #default>
        <div class="ua-form">
          <label class="ua-field">
            <span class="ua-field__label">Nama</span>
            <input v-model="form.name" type="text" maxlength="191" />
          </label>

          <label class="ua-field">
            <span class="ua-field__label">Email</span>
            <input v-model="form.email" type="email" maxlength="191" />
          </label>

          <label class="ua-field">
            <span class="ua-field__label">
              {{ editing ? 'Password baru' : 'Password' }}
            </span>
            <input
              v-model="form.password"
              type="password"
              autocomplete="new-password"
              :placeholder="editing ? 'Kosongkan kalau tidak diubah' : ''"
            />
            <small class="ua-field__hint">
              {{
                editing
                  ? 'Mengisi password akan mencabut semua sesi akun ini, termasuk dari perangkat lain.'
                  : 'Minimal 8 karakter.'
              }}
            </small>
          </label>

          <label v-if="editing" class="ua-switch">
            <input v-model="form.is_active" type="checkbox" />
            <span class="ua-switch__track"><span class="ua-switch__thumb" /></span>
            <span class="ua-switch__text">
              <strong>Aktif</strong> &mdash; akun bisa masuk ke panel pusat.
              <span v-if="!form.is_active" class="ua-switch__warn">
                Menonaktifkan juga akan mencabut semua token yang sudah terbit untuk akun ini.
              </span>
            </span>
          </label>

          <p v-if="formError" class="ua-form__error">
            <font-awesome-icon icon="circle-exclamation" />
            <span>{{ formError }}</span>
          </p>
        </div>
      </template>

      <template #footer>
        <button type="button" class="ua-btn ua-btn--ghost" :disabled="saving" @click="closeForm">
          Batal
        </button>
        <button
          type="button"
          class="ua-btn ua-btn--save"
          :disabled="saving || !canSave"
          @click="save"
        >
          <font-awesome-icon v-if="saving" icon="spinner" spin />
          <span>Simpan</span>
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { MySwal, showErrorToast, showSuccessToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { superadminService } from '@/services/central.service'
import BaseModal from '@/presentations/components/ui/BaseModal.vue'

const uiStore = useUiStore()
const loading = computed(() => uiStore.loading)

const users = ref([])
const meta = ref({})
const error = ref('')

const searchQuery = ref('')

const formOpen = ref(false)
const editing = ref(null)
const saving = ref(false)
const formError = ref('')

const form = reactive({ name: '', email: '', password: '', is_active: true })

/*
 * Akun sendiri.
 *
 * Backend juga menolaknya, tapi menolak setelah form terlanjur terisi dan
 * tombol sudah ditekan. Menonaktifkan di sini membuat aturan itu terlihat
 * sebelum apa pun terjadi -- dan yang lebih penting, mencegah admin
 * membuang satu putaran untuk sesuatu yang memang tidak akan pernah berhasil.
 */
const isSelf = (row) => row.id === meta.value.self

/*
 * Hanya pencarian, tanpa saringan status.
 *
 * Saringan tidak pernah menyembunyikan apa yang tidak sengaja -- daftar ini
 * pendek dan masih terlihat utuh tanpa filter, sehingga "Nonaktif" hanya
 * menambah satu cara untuk tidak melihat akun yang justru perlu dicari.
 * Sebaliknya, "Semuanya tampil" membuat badge Aktif/Nonaktif di tiap kartu
 * tetap jadi sumber kebenaran yang dibaca tanpa klik.
 */
const filtered = computed(() => {
  const keyword = searchQuery.value.trim().toLowerCase()

  return users.value.filter((row) => {
    if (!keyword) return true

    return (
      String(row.name ?? '')
        .toLowerCase()
        .includes(keyword) ||
      String(row.email ?? '')
        .toLowerCase()
        .includes(keyword)
    )
  })
})

/*
 * Untuk membedakan "daftar memang kosong" dari "pencariannya tidak kena".
 *
 * Keduanya tampil sebagai kotak kosong, tapi sarannya berlawanan: satu
 * menawarkan tambah akun, yang lain menawarkan longgarkan kata kunci. Tanpa
 * penanda ini, admin yang sedang mencari namanya akan mengira datanya hilang.
 */
const hasSearch = computed(() => searchQuery.value.trim() !== '')

/*
 * Inisial untuk avatar.
 *
 * Nama di sini bisa satu kata ("Asta") atau berimam panjang dengan gelar,
 * jadi ambil dua huruf pertama saja -- bukan semua inisialnya, yang untuk
 * "Asta Brata Wigya" menghasilkan kotak berisi tiga huruf dan jadi lebih
 * sempit dari nama aslinya.
 */
const initialsOf = (row) => {
  const parts = String(row.name ?? '')
    .trim()
    .split(/\s+/)
    .filter(Boolean)

  if (parts.length === 0) return '?'

  return parts
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('')
}

/*
 * Tanggal dalam Bahasa Indonesia, tanpa jam.
 *
 * Menit dan detik tidak pernah dipakai di sini -- yang penting admin tahu
 * adalah kapan perubahan itu terjadi relative hari ini, bukan kapan tepat
 * dalam detik itu terjadi.
 */
const formatDate = (value) => {
  if (!value) return 'tidak diketahui'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return 'tidak diketahui'

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(date)
}

/*
 * Password tidak dikirim kalau kosong.
 *
 * Yang dikirim `''` akan dianggap "jangan sentuh" oleh backend, jadi
 * mengirimnya tidak merusak apa pun -- tapi memotongnya di sini membuat
 * bentuk request-nya sama persis dengan "tidak ada yang diubah", dan
 * tidak bergantung pada interpretasi backend terhadap string kosong.
 */
const canSave = computed(() => {
  if (form.name.trim() === '' || form.email.trim() === '') return false

  return editing.value ? true : form.password !== ''
})

const load = async () => {
  error.value = ''

  try {
    const res = await superadminService.getAll()

    users.value = res.data?.data ?? []
    meta.value = res.data?.meta ?? {}
  } catch (e) {
    users.value = []
    error.value = e?.response?.data?.message ?? 'Gagal memuat daftar user pusat.'
  }
}

const openAdd = () => {
  editing.value = null
  form.name = ''
  form.email = ''
  form.password = ''
  form.is_active = true
  formError.value = ''
  formOpen.value = true
}

const openEdit = (row) => {
  editing.value = row
  form.name = row.name ?? ''
  form.email = row.email ?? ''
  form.password = ''
  form.is_active = Boolean(row.is_active)
  formError.value = ''
  formOpen.value = true
}

const closeForm = () => {
  if (saving.value) return

  formOpen.value = false
  editing.value = null
  formError.value = ''
}

const clearSearch = () => {
  searchQuery.value = ''
}

const save = async () => {
  if (!canSave.value) return

  saving.value = true
  formError.value = ''

  try {
    const payload = {
      name: form.name.trim(),
      email: form.email.trim(),
    }

    if (form.password !== '') {
      payload.password = form.password
      payload.password_confirmation = form.password
    }

    if (editing.value) {
      /*
       * `is_active` hanya ikut kalau berubah. Backend membacanya sebagai
       * keputusan besar (mencabut token), jadi mengirim `true` untuk setiap
       * penyimpanan nama akan jadi tanda "aktifkan" bagi pembaca log --
       * meskipun tidak terjadi apa pun.
       */
      if (form.is_active !== Boolean(editing.value.is_active)) {
        payload.is_active = form.is_active
      }

      await superadminService.update(editing.value.id, payload)

      showSuccessToast('User pusat diperbarui.')
    } else {
      await superadminService.create(payload)

      showSuccessToast(`User pusat ${form.name.trim()} dibuat.`)
    }

    formOpen.value = false

    await load()
  } catch (e) {
    /*
     * 422 dari endpoint ini bukan selalu soal isian form.
     *
     * Dua keputusan yang ditolak server -- menonaktifkan akun sendiri dan
     * menonaktifkan user pusat aktif terakhir -- juga dibalas 422, dan
     * pesannya sengaja ditulis untuk dibaca, bukan untuk form. Menampilkan
     * apa adanya adalah satu-satunya cara admin paham kenapa jalan keluar
     * hanya lewat akses database.
     */
    formError.value = e?.response?.data?.message ?? 'Gagal menyimpan user pusat.'
  } finally {
    saving.value = false
  }
}

const toggleActive = async (row) => {
  if (isSelf(row)) return

  const next = !row.is_active

  const result = await MySwal.fire({
    title: next ? 'Aktifkan Kembali?' : 'Nonaktifkan Akun?',
    html: next
      ? `<strong>${row.name}</strong> bisa masuk ke panel pusat lagi. Token yang dicabut saat
         nonaktif tidak dikembalikan -- dia harus login ulang.`
      : `<strong>${row.name}</strong> akan kehilangan akses <em>sekarang</em>: semua token yang
         sudah terbit dicabut, termasuk dari perangkat lain. Akunnya tidak dihapus, dan
         bisa diaktifkan kembali kapan saja.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: next ? '#16a34a' : '#ef4444',
    cancelButtonColor: '#6b7280',
    confirmButtonText: next ? 'Ya, aktifkan' : 'Ya, nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!result.isConfirmed) return

  try {
    const res = await superadminService.update(row.id, { is_active: next })

    await load()

    showSuccessToast(
      res.data?.message ?? (next ? 'Akun diaktifkan kembali.' : 'Akun dinonaktifkan.'),
    )
  } catch (e) {
    // 422 di sini berarti aturan, bukan kegagalan yang perlu dicoba lagi.
    showErrorToast(e)
  }
}

onMounted(load)
</script>

<style scoped>
.ua {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  /* Lebar maksimum yang sama dengan halaman panel pusat yang lain. */
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
}

/* ===== Petunjuk ===== */
.ua-hint {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin: 0;
  padding: 0.6rem 0.85rem;
  border: 1px solid var(--color-blue-100, #e0f2fe);
  border-radius: var(--border-radius-lg, 0.75rem);
  background: var(--color-blue-50, #f0f9ff);
  color: var(--color-blue-800, #1e3a8a);
  font-size: 0.74rem;
  line-height: 1.55;
}

.ua-hint__icon {
  margin-top: 0.18rem;
  flex-shrink: 0;
  color: var(--color-primary-dark, #0284c7);
}

.ua-hint__text {
  flex: 1;
  min-width: 0;
}

/* Tooltip murni CSS: muncul saat pointer atau fokus di atasnya, hilang lagi
   sendiri setelah pointer pergi. Membuka panel yang harus ditutup manual
   hanya menambah satu langkah untuk membaca satu paragraf. */
.ua-tip {
  position: relative;
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
  width: 1.4rem;
  height: 1.4rem;
  margin-top: 0.05rem;
  border-radius: 50%;
  cursor: help;
  outline: none;
  color: var(--color-yellow-800, #92400e);
}

.ua-tip:focus-visible {
  box-shadow: 0 0 0 2px var(--color-primary-light, #e0f2fe);
}

.ua-tip__body {
  position: absolute;
  z-index: 20;
  bottom: calc(100% + 0.5rem);
  right: 0;
  width: min(20rem, 78vw);
  padding: 0.65rem 0.75rem;
  border-radius: var(--border-radius-md, 0.5rem);
  background: var(--color-gray-700, #374151);
  color: #fff;
  font-size: 0.72rem;
  line-height: 1.55;
  text-align: left;
  box-shadow: 0 12px 24px -8px rgba(15, 23, 42, 0.4);

  opacity: 0;
  visibility: hidden;
  transform: translateY(4px);
  transition:
    opacity 0.18s ease,
    transform 0.18s ease,
    visibility 0.18s;
}

.ua-tip:hover .ua-tip__body,
.ua-tip:focus-visible .ua-tip__body {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

/* ===== Toolbar ===== */
.ua-toolbar {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.ua-search {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex: 1;
  min-width: 12rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-lg, 0.75rem);
  background: var(--color-card-bg, #fff);
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.ua-search:focus-within {
  border-color: var(--color-primary, #0ea5e9);
  box-shadow: 0 0 0 3px var(--color-primary-light, #e0f2fe);
}

.ua-search__icon {
  color: var(--color-gray-400, #94a3b8);
  flex-shrink: 0;
}

.ua-search input {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  background: transparent;
  font-size: 0.8rem;
  color: var(--color-gray-700, #374151);
}

/* Native search menampilkan tombol silang sendiri di sebagian peramban;
   hilangkannya supaya ada satu cara saja untuk membersihkan pencarian. */
.ua-search input::-webkit-search-cancel-button {
  appearance: none;
}

.ua-add {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.55rem 0.9rem;
  border: none;
  border-radius: var(--border-radius-lg, 0.75rem);
  background: var(--color-primary-dark, #0284c7);
  color: #fff;
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.15s ease;
}

.ua-add:hover {
  background: var(--color-primary, #0ea5e9);
}

/* ===== Kartu ===== */
.ua-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.ua-card {
  display: flex;
  flex-direction: column;
  padding: 1rem;
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-lg, 0.75rem);
  /* Kartu akun adalah kartu utama halaman ini -- elevasi penuh, sama dengan
     empat kartu statistik di atasnya. */
  box-shadow: var(--central-shadow-card);
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    transform 0.15s ease;
}

.ua-card:hover {
  border-color: var(--color-gray-300, #cbd5e1);
  /* Hover ikut pasangan token, bukan angka sendiri -- supaya kartu ini dan
     kartu utama halaman lain terangkat dengan jarak yang sama. */
  box-shadow: var(--central-shadow-card-hover);
  transform: translateY(-1px);
}

/* Akun nonaktif meredup seluruhnya, bukan hanya badge-nya: yang sudah
   dicabut tidak boleh terlihat sepadat yang masih bisa masuk. */
.ua-card--off {
  background: var(--color-gray-50, #f8fafc);
}

.ua-card--off .ua-card__name {
  color: var(--color-gray-500, #6b7280);
}

.ua-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.7rem;
}

.ua-avatar {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: var(--border-radius-lg, 0.75rem);
  background: linear-gradient(135deg, #0ea5e9, #0284c7);
  color: #fff;
  font-size: 0.95rem;
  font-weight: 800;
  letter-spacing: 0.02em;
}

.ua-avatar--off {
  background: var(--color-gray-200, #e5e7eb);
  color: var(--color-gray-500, #6b7280);
}

.ua-card__self {
  padding: 0.1rem 0.45rem;
  border-radius: var(--border-radius-full, 9999px);
  background: var(--color-blue-100, #e0f2fe);
  color: var(--color-blue-800, #1e3a8a);
  font-size: 0.6rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.ua-card__name {
  margin: 0;
  font-size: 0.92rem;
  font-weight: 700;
  color: var(--color-gray-700, #374151);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ua-card__mail {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0.2rem 0 0;
  font-size: 0.73rem;
  color: var(--color-gray-500, #6b7280);
  min-width: 0;
}

.ua-card__mail span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ua-card__mail svg {
  flex-shrink: 0;
  color: var(--color-gray-400, #94a3b8);
}

.ua-card__status {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  align-self: flex-start;
  margin-top: 0.65rem;
  padding: 0.15rem 0.55rem;
  border-radius: var(--border-radius-full, 9999px);
  font-size: 0.68rem;
  font-weight: 700;
}

.ua-card__status.is-on {
  background: var(--color-green-50, #d1fae5);
  color: var(--color-green-800, #065f46);
}

.ua-card__status.is-off {
  background: var(--color-gray-200, #e5e7eb);
  color: var(--color-gray-500, #6b7280);
}

/* Titik di samping kata, bukan ikon: seukuran titik tidak butuh
   replacement atau ukuran font sendiri, jadi tidak pernah jadi "0px". */
.ua-card__beacon {
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 50%;
  background: currentColor;
}

.ua-card__time {
  margin: 0.55rem 0 0;
  font-size: 0.67rem;
  color: var(--color-gray-400, #94a3b8);
}

.ua-card__actions {
  display: flex;
  gap: 0.4rem;
  margin-top: 0.85rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--color-gray-100, #f1f5f9);
}

/*
 * Tombol aksi di kartu user: Edit dan Hapus.
 *
 * `min-height: 2.5rem` (40px), bukan sekadar `padding`. Tombol ini satu-
 * satunya cara mengubah atau menghapus sebuah akun dari daftar, jadi
 * keduanya adalah target sentuh utama di kartu -- dan `padding: 0.45rem`
 * dengan font 0.78rem menghasilkan tinggi ~31px, di bawah batas 40px
 * yang dipakai platform mobile sebagai target minimum. Di daftar kartu
 * satu kolom (mobile) tombolnya lebar penuh, jadi hanya tingginya
 * yang jadi masalah.
 *
 * `min-height` dan bukan `height`: tombol boleh tumbuh kalau isinya nanti
 * dua baris, sedangkan memaksa tinggi tetap akan membuat isinya terpotong
 * alih-alih muat.
 */
.ua-icon-btn {
  display: grid;
  place-items: center;
  flex: 1;
  min-height: 2.5rem;
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-md, 0.5rem);
  background: var(--color-card-bg, #fff);
  color: var(--color-gray-500, #6b7280);
  font-size: 0.78rem;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    color 0.15s ease,
    background 0.15s ease;
}

.ua-icon-btn:hover:not(:disabled) {
  border-color: var(--color-primary, #0ea5e9);
  color: var(--color-primary-dark, #0284c7);
  background: var(--color-primary-light, #e0f2fe);
}

.ua-icon-btn--danger:hover:not(:disabled) {
  border-color: var(--color-danger, #ef4444);
  color: var(--color-danger, #ef4444);
  background: var(--color-red-50, #fee2e2);
}

.ua-icon-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* ===== Kerangka ===== */
.ua-card--skeleton {
  gap: 0.55rem;
  min-height: 11.5rem;
}

.ua-skeleton {
  display: block;
  border-radius: var(--border-radius-sm, 0.375rem);
  background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 37%, #f1f5f9 63%);
  background-size: 400% 100%;
  animation: ua-pulse 1.3s ease-in-out infinite;
}

.ua-skeleton--avatar {
  width: 2.75rem;
  height: 2.75rem;
  border-radius: var(--border-radius-lg, 0.75rem);
}

.ua-skeleton--line {
  height: 0.55rem;
}

.ua-skeleton--w60 {
  width: 60%;
}

.ua-skeleton--w85 {
  width: 85%;
}

.ua-skeleton--pill {
  width: 4.2rem;
  height: 1.15rem;
  border-radius: var(--border-radius-full, 9999px);
}

@keyframes ua-pulse {
  0% {
    background-position: 100% 50%;
  }
  100% {
    background-position: 0 50%;
  }
}

/* ===== Keadaan kosong / gagal ===== */
.ua-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  padding: 3rem 1.5rem;
  text-align: center;
  background: var(--color-card-bg, #fff);
  border: 1px dashed var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-lg, 0.75rem);
}

.ua-panel__icon {
  font-size: 1.8rem;
  color: var(--color-danger, #ef4444);
  margin-bottom: 0.3rem;
}

.ua-panel__icon--muted {
  color: var(--color-gray-300, #cbd5e1);
}

.ua-panel__title {
  margin: 0;
  font-size: 0.92rem;
  font-weight: 700;
  color: var(--color-gray-700, #374151);
}

.ua-panel__text {
  margin: 0;
  max-width: 28rem;
  font-size: 0.78rem;
  line-height: 1.6;
  color: var(--color-gray-500, #6b7280);
}

.ua-panel__action {
  margin-top: 0.75rem;
  padding: 0.5rem 0.95rem;
  border: 1px solid var(--color-primary-dark, #0284c7);
  border-radius: var(--border-radius-md, 0.5rem);
  background: var(--color-card-bg, #fff);
  color: var(--color-primary-dark, #0284c7);
  font-size: 0.76rem;
  font-weight: 700;
  cursor: pointer;
  transition:
    background 0.15s ease,
    color 0.15s ease;
}

.ua-panel__action:hover {
  background: var(--color-primary-dark, #0284c7);
  color: #fff;
}

/* ===== Kaki daftar ===== */
.ua-count {
  margin: 0;
  font-size: 0.72rem;
  color: var(--color-gray-400, #94a3b8);
}

.ua-count strong {
  color: var(--color-gray-700, #374151);
}

/* ===== Form ===== */
.ua-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.ua-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.ua-field__label {
  font-size: 0.74rem;
  font-weight: 700;
  color: var(--color-gray-700, #374151);
}

.ua-field input {
  padding: 0.6rem 0.75rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-md, 0.5rem);
  font-size: 0.82rem;
  outline: none;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;
}

.ua-field input:focus {
  border-color: var(--color-primary, #0ea5e9);
  box-shadow: 0 0 0 3px var(--color-primary-light, #e0f2fe);
}

.ua-field__hint {
  font-size: 0.68rem;
  color: var(--color-gray-500, #6b7280);
  line-height: 1.5;
}

/* Sakelar, bukan kotak centang.
   Yang ditanyakan admin bukan "apakah kotaknya tercentang" melainkan
   "apakah akun ini menyala atau mati" -- dan sakelar menjawab dengan
   istilah yang sama dengan badge statusnya. */
.ua-switch {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.75rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-md, 0.5rem);
  background: var(--color-gray-50, #f8fafc);
  font-size: 0.74rem;
  color: var(--color-gray-700, #334155);
  line-height: 1.55;
  cursor: pointer;
}

.ua-switch input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}

.ua-switch__track {
  position: relative;
  flex-shrink: 0;
  width: 2.1rem;
  height: 1.2rem;
  margin-top: 0.05rem;
  border-radius: var(--border-radius-full, 9999px);
  background: var(--color-gray-300, #cbd5e1);
  transition: background 0.18s ease;
}

.ua-switch__thumb {
  position: absolute;
  top: 0.15rem;
  left: 0.15rem;
  width: 0.9rem;
  height: 0.9rem;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.25);
  transition: transform 0.18s ease;
}

.ua-switch input:checked + .ua-switch__track {
  background: var(--color-success, #10b981);
}

.ua-switch input:checked + .ua-switch__track .ua-switch__thumb {
  transform: translateX(0.9rem);
}

.ua-switch input:focus-visible + .ua-switch__track {
  box-shadow: 0 0 0 3px var(--color-primary-light, #e0f2fe);
}

.ua-switch__warn {
  display: block;
  margin-top: 0.3rem;
  font-size: 0.68rem;
  color: var(--color-yellow-800, #92400e);
}

.ua-form__error {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  padding: 0.65rem 0.75rem;
  border-radius: var(--border-radius-md, 0.5rem);
  background: var(--color-red-50, #fee2e2);
  border: 1px solid #fecaca;
  color: var(--color-red-800, #991b1b);
  font-size: 0.74rem;
  line-height: 1.5;
  margin: 0;
}

.ua-form__error svg {
  margin-top: 0.15rem;
  flex-shrink: 0;
}

/* ===== Tombol dialog ===== */
.ua-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.55rem 1rem;
  border-radius: var(--border-radius-md, 0.5rem);
  font-size: 0.78rem;
  font-weight: 700;
  cursor: pointer;
  border: none;
  /* Tanpa ini tombol utama berdiri datar di atas kartu putih: warna birunya
     jadi satu-satunya penanda tombol, dan tepinya menyatu dengan kartu. */
  box-shadow: var(--central-shadow-btn);
}

.ua-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.ua-btn--ghost {
  background: var(--color-gray-100, #f1f5f9);
  color: var(--color-gray-700, #374151);
}

.ua-btn--ghost:hover:not(:disabled) {
  box-shadow: var(--central-shadow-btn-hover);
}

.ua-btn--primary {
  background: var(--color-primary-dark, #0284c7);
  color: #fff;
  /* Bayangan ikut warna tombol, bukan abu tua: abu di atas biru menghasilkan
     bayangan kelabu yang terbaca sebagai noda, bukan cahaya dari tombolnya. */
  box-shadow: var(--central-shadow-btn-primary);
}

.ua-btn--primary:not(:disabled):hover {
  background: var(--color-primary, #0ea5e9);
  box-shadow: var(--central-shadow-btn-primary-hover);
}

/*
 * Tombol "Simpan" -- abu, sama dengan aplikasi lokasi.
 *
 * `ua-btn--primary` (biru) tidak dipakai untuk simpan, supaya tombol simpan
 * di panel pusat dan di aplikasi lokasi tidak berbeda warna. Lihat
 * `.btn--save` di `central-form.css`.
 */
.ua-btn--save {
  background: var(--central-btn-save);
  color: #fff;
  box-shadow: var(--central-shadow-btn-save);
}

.ua-btn--save:not(:disabled):hover {
  background: var(--central-btn-save-hover);
  box-shadow: var(--central-shadow-btn-save-hover);
}

/* ===== Responsif =====
   Toolbar jadi dua baris di layar sempit: kolom pencarian memenuhi lebar
   sendiri, tombol Tambah turun ke bawahnya. Tombolnya tetap penuh supaya
   tidak jadi target sentuh sempit di baris yang hanya berisi satu tombol. */
@media (max-width: 48rem) {
  .ua-search {
    min-width: 100%;
  }

  .ua-add {
    width: 100%;
    justify-content: center;
  }

  /*
   | Tooltip dijangkarkan ke KIRI pemicunya, bukan ke kiri halaman.
   |
   | Dulu rule ini memakai `left: -1.5rem`, yang berarti menaruh 24px di
   | kiri titik itu sendiri. Di 375px hasil pengukuran: kotak tooltip membentang
   | dari 300px sampai 588px, padahal layarnya cuma 375px -- jadi lebih dari
   | separuhnya keluar layar tepat pada saat tooltip itu sedang dibaca.
   |
   | Opsi paling aman di layar sempit: jangkarkan ke KANAN pemicunya
   | (`right: 0`), sama seperti di lebar normal. Pemicunya ada di dalam
   | `.ua-hint` yang jadi elemen penuh, jadi pada lebar sekecil 375px kotak
   | ini masih muat 18rem dan tidak perlu geser ke kiri sama sekali.
   |
   | `max-width` ikut `100vw` supaya di layar yang lebih sempit dari 18rem pun
   | (mis. 320px) tooltip tetap mengikuti lebar layar, bukan keluar lagi.
   */
  .ua-tip__body {
    right: 0;
    left: auto;
    width: min(18rem, calc(100vw - 2rem));
  }
}

/* Hormati preferensi sistem: untuk yang mengaktifkan reduced motion,
   animasi kerangka dimatikan dan diganti blok abu-abu diam yang tetap
   menunjukkan bentuk kartu. */
@media (prefers-reduced-motion: reduce) {
  .ua-skeleton {
    animation: none;
    background: var(--color-gray-100, #f1f5f9);
  }
}
</style>
