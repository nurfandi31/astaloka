<template>
  <div class="audit-page">
    <!-- ===== Toolbar ===== -->
    <div class="audit-toolbar">
      <div class="audit-toolbar__search">
        <font-awesome-icon icon="magnifying-glass" />
        <input
          v-model="filters.search"
          type="text"
          placeholder="Cari keterangan atau nama pelaku..."
          @keyup.enter="applyFilters"
        />
      </div>

      <div class="audit-filters">
        <select v-model="filters.action" class="audit-select">
          <option value="">Semua Aksi</option>
          <option v-for="a in availableActions" :key="a.value" :value="a.value">
            {{ a.label }}
          </option>
        </select>

        <select v-model="filters.actor_type" class="audit-select">
          <option value="">Semua Pelaku</option>
          <option value="superadmin">Superadmin (panel pusat)</option>
          <option value="user">User lokasi</option>
          <option value="system">Sistem</option>
        </select>

        <select v-model="filters.tenant_id" class="audit-select">
          <option value="">Semua Lokasi</option>
          <option v-for="t in tenants" :key="t.id" :value="t.id">{{ t.nama }}</option>
        </select>

        <select v-model="filters.days" class="audit-select">
          <option value="">Semua Waktu</option>
          <option value="1">24 Jam Terakhir</option>
          <option value="7">7 Hari Terakhir</option>
          <option value="30">30 Hari Terakhir</option>
        </select>

        <button
          v-if="hasActiveFilter"
          type="button"
          class="audit-reset"
          title="Hapus semua filter"
          @click="resetFilters"
        >
          <font-awesome-icon icon="rotate-left" />
        </button>
      </div>
    </div>

    <!-- ===== Keterangan read-only ===== -->
    <div class="audit-notice">
      <font-awesome-icon icon="lock" />
      <span>
        Log bersifat append-only: tidak ada endpoint untuk menghapus atau mengubahnya, dan tabelnya
        tidak punya soft delete. Baris yang dihapus oleh orang yang sedang diselidiki tidak berlaku
        sebagai bukti.
      </span>
    </div>

    <!-- ===== Tabel ===== -->
    <div class="audit-table-wrap">
      <table class="audit-table">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Aksi</th>
            <th>Pelaku</th>
            <th>Lokasi</th>
            <th>Keterangan</th>
            <th class="text-center">IP</th>
            <th class="text-center"></th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="loading">
            <td colspan="7" class="audit-state">
              <font-awesome-icon icon="spinner" spin />
              <span>Memuat jejak audit...</span>
            </td>
          </tr>

          <tr v-else-if="rows.length === 0">
            <td colspan="7" class="audit-state audit-state--empty">
              <font-awesome-icon icon="clipboard-list" class="audit-state__icon" />
              <p>
                {{
                  hasActiveFilter
                    ? 'Tidak ada baris yang cocok dengan filter ini.'
                    : 'Belum ada aktivitas yang tercatat.'
                }}
              </p>
            </td>
          </tr>

          <tr v-for="row in rows" :key="row.id" class="audit-row" @click="openDetail(row)">
            <td class="audit-time">
              <strong>{{ formatTanggal(row.created_at) }}</strong>
              <small>{{ formatWaktu(row.created_at) }}</small>
            </td>

            <td>
              <span class="audit-badge" :class="`audit-badge--${row.tone || 'default'}`">
                {{ row.label || row.action }}
              </span>
            </td>

            <td>
              <div class="audit-actor">
                <div class="audit-actor__avatar" :class="`audit-actor__avatar--${row.actor_type}`">
                  {{ initials(row.actor_name) }}
                </div>
                <div class="audit-actor__text">
                  <strong>{{ row.actor_name || actorLabel(row.actor_type) }}</strong>
                  <small>{{ roleLabel(row.actor_type) }}</small>
                </div>
              </div>
            </td>

            <td>
              <span v-if="row.tenant" class="audit-tenant">{{ row.tenant.nama }}</span>
              <span v-else class="audit-muted">Panel pusat</span>
            </td>

            <td class="audit-desc">{{ row.description }}</td>

            <td class="text-center audit-mono">{{ row.ip_address || '—' }}</td>

            <td class="text-center">
              <button type="button" class="audit-open" title="Lihat detail">
                <font-awesome-icon icon="chevron-right" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ===== Paginasi ===== -->
    <div v-if="meta.last_page > 1" class="audit-pagination">
      <button
        type="button"
        class="page-btn"
        :disabled="meta.current_page <= 1"
        @click="goToPage(meta.current_page - 1)"
      >
        <font-awesome-icon icon="chevron-left" />
      </button>

      <span class="page-info">
        Halaman {{ meta.current_page }} dari {{ meta.last_page }} · {{ meta.total }} baris
      </span>

      <button
        type="button"
        class="page-btn"
        :disabled="meta.current_page >= meta.last_page"
        @click="goToPage(meta.current_page + 1)"
      >
        <font-awesome-icon icon="chevron-right" />
      </button>
    </div>
  </div>

  <!-- ===== Detail ===== -->
  <BaseModal
    v-if="detail"
    title="Detail Audit"
    :subtitle="`#${detail.id} · ${formatTanggalLengkap(detail.created_at)}`"
    icon="clipboard-list"
    size="lg"
    @close="detail = null"
  >
    <template #default>
      <div class="audit-detail">
        <div class="audit-detail__head">
          <span class="audit-badge" :class="`audit-badge--${detail.tone || 'default'}`">
            {{ detail.label || detail.action }}
          </span>
          <code class="audit-detail__action">{{ detail.action }}</code>
        </div>

        <p class="audit-detail__desc">{{ detail.description }}</p>

        <dl class="audit-detail__grid">
          <div>
            <dt>Waktu</dt>
            <dd>{{ formatTanggalLengkap(detail.created_at) }}</dd>
          </div>
          <div>
            <dt>Pelaku</dt>
            <dd>
              {{ detail.actor_name || actorLabel(detail.actor_type) }}
              <small class="audit-muted">({{ roleLabel(detail.actor_type) }})</small>
            </dd>
          </div>
          <div>
            <dt>Lokasi</dt>
            <dd>{{ detail.tenant ? detail.tenant.nama : 'Panel pusat' }}</dd>
          </div>
          <div>
            <dt>Objek</dt>
            <dd>
              <code v-if="detail.subject_type">
                {{ detail.subject_type
                }}<template v-if="detail.subject_id">#{{ detail.subject_id }}</template>
              </code>
              <span v-else class="audit-muted">—</span>
            </dd>
          </div>
          <div>
            <dt>IP</dt>
            <dd class="audit-mono">{{ detail.ip_address || '—' }}</dd>
          </div>
          <div>
            <dt>User agent</dt>
            <dd class="audit-detail__ua">{{ detail.user_agent || '—' }}</dd>
          </div>
        </dl>

        <template v-if="hasProperties">
          <h4 class="audit-detail__subtitle">Rincian Perubahan</h4>
          <div class="audit-diff">
            <div v-for="(row, key) in diffRows" :key="key" class="audit-diff__row">
              <span class="audit-diff__field">{{ row.field }}</span>
              <span class="audit-diff__old">{{ row.sebelum }}</span>
              <font-awesome-icon icon="arrow-right" class="audit-diff__arrow" />
              <span class="audit-diff__new">{{ row.sesudah }}</span>
            </div>
          </div>
        </template>

        <template v-else-if="hasPlainProperties">
          <h4 class="audit-detail__subtitle">Rincian</h4>
          <table class="audit-props">
            <tbody>
              <tr v-for="(v, k) in detail.properties" :key="k">
                <th>{{ k }}</th>
                <td>{{ formatValue(v) }}</td>
              </tr>
            </tbody>
          </table>
        </template>
      </div>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { auditService, locationService } from '@/services/central.service'
import BaseModal from '@/presentations/components/ui/BaseModal.vue'

const rows = ref([])
const availableActions = ref([])
const tenants = ref([])
const loading = ref(false)
const detail = ref(null)

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 25,
  total: 0,
})

const filters = reactive({
  action: '',
  actor_type: '',
  tenant_id: '',
  days: '',
  search: '',
})

const hasActiveFilter = computed(() => Object.values(filters).some((v) => v !== '' && v !== null))

/**
 * `properties` punya dua bentuk, dan keduanya sering muncul bersamaan.
 *
 * Untuk `*.update` isinya `changes: { field: { sebelum, sesudah } }` --
 * itu yang layak ditampilkan sebagai diff, karena admin mau tahu "apa yang
 * berubah", bukan "apa isi JSON-nya". Sisanya (slug, domain, error) adalah
 * nilai biasa dan cukup ditampilkan sebagai tabel key-value.
 *
 * Karena itu `changes` dikeluarkan dari tampilan key-value agar tidak
 * tampil dua kali.
 */
const diffRows = computed(() => {
  const changes = detail.value?.properties?.changes

  if (!changes || typeof changes !== 'object') return []

  return Object.entries(changes).map(([field, v]) => ({
    field,
    sebelum: formatValue(v?.sebelum),
    sesudah: formatValue(v?.sesudah),
  }))
})

const hasProperties = computed(() => diffRows.value.length > 0)

const hasPlainProperties = computed(() => {
  const props = detail.value?.properties

  if (!props || typeof props !== 'object') return false

  return Object.entries(props).filter(([k]) => k !== 'changes').length > 0
})

const load = async (page = 1) => {
  loading.value = true

  try {
    const params = { page, per_page: meta.per_page }

    if (filters.action) params.action = filters.action
    if (filters.actor_type) params.actor_type = filters.actor_type
    if (filters.tenant_id) params.tenant_id = filters.tenant_id
    if (filters.search.trim()) params.search = filters.search.trim()

    /*
     * Rentang dikirim sebagai `from`, bukan `days`, karena backend menyimpan
     * batas bawah tanggal sebagai tanggal tanpa jam -- batas bawah dihitung
     * server dengan timezone yang sama dengan kolom `created_at`. Kalau
     * mengirim jumlah hari, batasnya jadi ikut zona waktu server dan
     * "7 hari terakhir" bisa berubah artinya cuma karena timezone.
     *
     * `toISOString()` TIDAK boleh dipakai di sini: itu mengubah ke UTC
     * dulu. Untuk WIB (UTC+7) tengah malam lokal = 17:00 UTC hari
     * sebelumnya, jadi tanggal yang dikirim mundur satu hari -- filter
     * "hari ini" diam-diam menjadi "kemarin", dan baris hari ini yang
     * paling baru justru tersembunyi dari layar yang seharusnya
     * menampilkan daftar terbaru.
     */
    if (filters.days) {
      const dari = new Date()
      dari.setDate(dari.getDate() - Number(filters.days) + 1)

      params.from = [
        dari.getFullYear(),
        String(dari.getMonth() + 1).padStart(2, '0'),
        String(dari.getDate()).padStart(2, '0'),
      ].join('-')
    }

    const res = await auditService.getAll(params)

    rows.value = res.data?.data?.items || []
    Object.assign(meta, res.data?.data?.meta || {})
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

const loadActions = async () => {
  try {
    const res = await auditService.getActions()
    availableActions.value = res.data?.data?.actions || []
  } catch {
    availableActions.value = []
  }
}

const applyFilters = () => load(1)

/*
 * `resetFilters()` sengaja tidak memanggil `load(1)` sendiri.
 *
 * Mengosongkan field di atas akan memicu `watch`, yang memanggil
 * `load(1)`. Kalau fungsi ini juga memanggilnya, menekan tombol reset
 * menembak dua request -- dan karena keduanya berbalas dengan urutan
 * yang tidak dijamin, layar bisa sempat menampilkan hasil request kedua
 * lalu tertimpa hasil yang pertama. Itu terlihat seperti "filter tidak
 * merespons".
 *
 * Porém `search` tidak ada di watch, jadi kalau hanya kotak pencarian
 * yang terisi, tidak ada yang memicu reload. Karena itu pemanggilan
 * `load(1)` di sini tetap perlu, DIKECUALIKAN saat field lain berubah.
 */
const resetFilters = () => {
  const adaFilterLain = filters.action || filters.actor_type || filters.tenant_id || filters.days

  filters.action = ''
  filters.actor_type = ''
  filters.tenant_id = ''
  filters.days = ''
  filters.search = ''

  if (!adaFilterLain) load(1)
}

const goToPage = (page) => {
  if (page < 1 || page > meta.last_page) return
  load(page)
}

const openDetail = async (row) => {
  try {
    const res = await auditService.getById(row.id)
    detail.value = res.data?.data || row
  } catch {
    detail.value = row
  }
}

const actorLabel = (type) =>
  ({ superadmin: 'Superadmin', user: 'User lokasi', system: 'Sistem' })[type] || 'Tidak diketahui'

const roleLabel = (type) =>
  ({ superadmin: 'Panel pusat', user: 'Aplikasi lokasi', system: 'Otomatis' })[type] || '-'

const initials = (name) =>
  (name || '?')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('')

const formatTanggal = (iso) => {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

const formatWaktu = (iso) => {
  if (!iso) return ''
  return new Date(iso).toLocaleTimeString('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

const formatTanggalLengkap = (iso) => {
  if (!iso) return '—'
  return `${formatTanggal(iso)} · ${formatWaktu(iso)}`
}

/**
 * Nilai audit bisa berupa apa saja: string, angka, boolean, null, atau
 * objek. Semua dirender lewat satu fungsi supaya tidak pernah tampil
 * sebagai "[object Object]" di panel yang dibaca saat menelusuri insiden.
 */
const formatValue = (v) => {
  if (v === null || v === undefined) return '(kosong)'
  if (v === '') return '(kosong)'
  if (typeof v === 'boolean') return v ? 'ya' : 'tidak'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

onMounted(async () => {
  await Promise.all([load(1), loadActions()])

  try {
    const res = await locationService.getAll()
    tenants.value = res.data?.data || []
  } catch {
    tenants.value = []
  }
})

/*
 * Halaman lain di panel pusat memakai store global untuk status loading,
 * yang juga dibaca sidebar lewat `useUiStore().loading`. Audit memakai
 * spinner lokal karena daftar di sini di-refresh lewat filter, bukan lewat
 * tombol aksi -- menyalakan spinner global saat filter berubah akan membuat
 * seluruh chrome ikut berkedip, padahal yang berubah cuma tabel.
 *
 * `watch` (bukan @change di setiap select) dipakai supaya tanggal filter
 * ikut memicu reload tanpa harus ditambah satu handler lagi setiap kali
 * ada filter baru -- dan supaya `resetFilters()`, yang mengisi lima field
 * sekaligus, tetap menghasilkan satu request bukan lima.
 *
 * `search` sengaja TIDAK ikut watch: mengetik di kotak pencarian memicu
 * request setiap ketikan, dan ini endpoint yang tidak murah (count + join
 * ke registry tenant). Ia tetap bisa disimpan lewat Enter, atau lewat
 * tombol reset.
 */
watch(
  () => [filters.action, filters.actor_type, filters.tenant_id, filters.days],
  () => load(1),
)
</script>

<style scoped>
.audit-page {
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: 14px;
  overflow: hidden;
}

/* ===== Toolbar ===== */
.audit-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
  flex-wrap: wrap;
}

.audit-toolbar__search {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex: 1;
  min-width: 14rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: 9px;
  color: var(--color-gray-400, #94a3b8);
}

.audit-toolbar__search input {
  flex: 1;
  border: none;
  outline: none;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-text-main, #1e293b);
  background: transparent;
  min-width: 0;
}

.audit-filters {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.audit-select {
  padding: 0.5rem 0.75rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: 9px;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-neutral-600, #475569);
  background: var(--color-card-bg, #fff);
  min-width: 9rem;
}

.audit-reset {
  padding: 0.5rem 0.7rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: 9px;
  background: var(--color-card-bg, #fff);
  color: var(--color-gray-500, #6b7280);
  font-size: 0.78rem;
  cursor: pointer;
  transition: all 0.18s ease;
}

.audit-reset:hover {
  background: var(--color-primary-light, #e0f2fe);
  color: var(--color-primary-dark, #0284c7);
  border-color: var(--color-primary, #0ea5e9);
}

/* ===== Keterangan read-only ===== */
.audit-notice {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  padding: 0.7rem 1.25rem;
  background: var(--color-blue-50, #f0f9ff);
  border-bottom: 1px solid var(--color-blue-100, #e0f2fe);
  font-size: 0.74rem;
  color: var(--color-blue-800, #1e3a8a);
  line-height: 1.55;
}

.audit-notice svg {
  margin-top: 0.15rem;
  flex-shrink: 0;
}

/* ===== Tabel ===== */
.audit-table-wrap {
  overflow-x: auto;
}

.audit-table {
  width: 100%;
  border-collapse: collapse;
}

.audit-table th {
  text-align: left;
  padding: 0.7rem 1rem;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: var(--color-gray-500, #6b7280);
  background: var(--color-gray-100, #f1f5f9);
  border-bottom: 1px solid var(--color-border, #e2e8f0);
  white-space: nowrap;
}

.audit-table td {
  padding: 0.7rem 1rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
  font-size: 0.8rem;
  vertical-align: middle;
}

.audit-row {
  cursor: pointer;
  transition: background 0.15s ease;
}

.audit-row:hover {
  background: var(--color-gray-100, #f1f5f9);
}

.audit-time strong {
  display: block;
  font-size: 0.76rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  white-space: nowrap;
}

.audit-time small {
  font-size: 0.68rem;
  color: var(--color-gray-400, #94a3b8);
  font-variant-numeric: tabular-nums;
}

.audit-actor {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.audit-actor__avatar {
  width: 1.85rem;
  height: 1.85rem;
  flex-shrink: 0;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.68rem;
  font-weight: 800;
  color: #fff;
}

.audit-actor__avatar--superadmin {
  background: var(--color-primary, #0ea5e9);
}

.audit-actor__avatar--user {
  background: var(--color-success, #10b981);
}

.audit-actor__avatar--system {
  background: var(--color-gray-500, #6b7280);
}

.audit-actor__text strong {
  display: block;
  font-size: 0.76rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  white-space: nowrap;
}

.audit-actor__text small {
  font-size: 0.66rem;
  color: var(--color-gray-400, #94a3b8);
}

.audit-tenant {
  font-weight: 600;
  color: var(--color-text-main, #1e293b);
}

.audit-desc {
  color: var(--color-neutral-600, #475569);
  max-width: 30rem;
  line-height: 1.5;
}

.audit-mono,
.audit-detail__action {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.72rem;
  color: var(--color-gray-500, #6b7280);
}

.audit-muted {
  color: var(--color-gray-400, #94a3b8);
}

.audit-open {
  border: none;
  background: transparent;
  color: var(--color-gray-400, #94a3b8);
  cursor: pointer;
  padding: 0.25rem;
  transition: color 0.15s ease;
}

.audit-row:hover .audit-open {
  color: var(--color-primary, #0ea5e9);
}

/* ===== Badge aksi ===== */
.audit-badge {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 9999px;
  font-size: 0.68rem;
  font-weight: 700;
  white-space: nowrap;
  background: var(--color-gray-100, #f1f5f9);
  color: var(--color-gray-700, #374151);
  border: 1px solid var(--color-gray-200, #e5e7eb);
}

.audit-badge--success {
  background: var(--color-green-50, #d1fae5);
  color: var(--color-green-800, #065f46);
  border-color: transparent;
}

.audit-badge--danger {
  background: var(--color-red-50, #fee2e2);
  color: var(--color-red-800, #991b1b);
  border-color: transparent;
}

.audit-badge--warning {
  background: var(--color-yellow-50, #fef3c7);
  color: var(--color-yellow-800, #92400e);
  border-color: transparent;
}

.audit-badge--info {
  background: var(--color-blue-100, #e0f2fe);
  color: var(--color-blue-800, #1e3a8a);
  border-color: transparent;
}

/* ===== Status ===== */
.audit-state {
  text-align: center;
  padding: 2.5rem 1rem;
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.82rem;
}

.audit-state svg {
  margin-right: 0.5rem;
}

.audit-state--empty .audit-state__icon {
  display: block;
  margin: 0 auto 0.75rem;
  font-size: 1.8rem;
}

/* ===== Paginasi ===== */
.audit-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.85rem;
  padding: 0.85rem 1.25rem;
  border-top: 1px solid var(--color-gray-100, #f1f5f9);
}

.page-btn {
  width: 2rem;
  height: 2rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-sm);
  background: var(--color-card-bg, #fff);
  color: var(--color-neutral-600, #475569);
  cursor: pointer;
  transition: all 0.18s ease;
}

.page-btn:hover:not(:disabled) {
  background: var(--color-primary-light, #e0f2fe);
  color: var(--color-primary-dark, #0284c7);
}

.page-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.page-info {
  font-size: 0.76rem;
  color: var(--color-gray-500, #6b7280);
}

/* ===== Detail ===== */
.audit-detail__head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-bottom: 0.75rem;
}

.audit-detail__desc {
  font-size: 0.85rem;
  color: var(--color-text-main, #1e293b);
  line-height: 1.6;
  margin: 0 0 1rem;
}

.audit-detail__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem 1.25rem;
  margin: 0 0 1.25rem;
  padding: 0.9rem 1rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-sm);
  background: var(--color-gray-100, #f1f5f9);
}

.audit-detail__grid dt {
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-gray-500, #6b7280);
  margin-bottom: 0.15rem;
}

.audit-detail__grid dd {
  margin: 0;
  font-size: 0.8rem;
  color: var(--color-text-main, #1e293b);
  word-break: break-word;
}

.audit-detail__grid code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.72rem;
}

.audit-detail__ua {
  font-size: 0.7rem !important;
  color: var(--color-gray-500, #6b7280) !important;
  line-height: 1.5;
}

.audit-detail__subtitle {
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-gray-500, #6b7280);
  margin: 0 0 0.6rem;
}

.audit-diff {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.audit-diff__row {
  display: grid;
  grid-template-columns: minmax(6rem, 10rem) 1fr auto 1fr;
  align-items: center;
  gap: 0.6rem;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-sm);
  font-size: 0.78rem;
}

.audit-diff__field {
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.72rem;
}

.audit-diff__old {
  padding: 0.2rem 0.45rem;
  border-radius: 4px;
  background: var(--color-red-50, #fee2e2);
  color: var(--color-red-800, #991b1b);
  text-decoration: line-through;
  word-break: break-word;
}

.audit-diff__arrow {
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.7rem;
}

.audit-diff__new {
  padding: 0.2rem 0.45rem;
  border-radius: 4px;
  background: var(--color-green-50, #d1fae5);
  color: var(--color-green-800, #065f46);
  word-break: break-word;
}

.audit-props {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.78rem;
}

.audit-props th,
.audit-props td {
  text-align: left;
  padding: 0.4rem 0.6rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
  vertical-align: top;
}

.audit-props th {
  width: 12rem;
  font-weight: 700;
  color: var(--color-gray-500, #6b7280);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.72rem;
}

.audit-props td {
  color: var(--color-text-main, #1e293b);
  word-break: break-word;
}

@media (max-width: 640px) {
  .audit-detail__grid {
    grid-template-columns: 1fr;
  }

  .audit-diff__row {
    grid-template-columns: 1fr;
    gap: 0.3rem;
  }

  .audit-diff__arrow {
    display: none;
  }

  .audit-select {
    min-width: 100%;
  }
}
</style>
