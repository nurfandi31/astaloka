<template>
  <div class="central-dashboard">
    <!-- Kartu statistik -->
    <div class="stat-grid">
      <div class="dash-stat dash-stat--slate">
        <div class="dash-stat__icon">
          <font-awesome-icon icon="location-dot" />
        </div>
        <div class="dash-stat__body">
          <p class="dash-stat__label">Total Lokasi</p>
          <p class="dash-stat__value">{{ stats.lokasi }}</p>
          <p class="dash-stat__hint">{{ stats.aktif }} lokasi aktif</p>
        </div>
      </div>

      <div class="dash-stat dash-stat--green">
        <div class="dash-stat__icon">
          <font-awesome-icon icon="circle-check" />
        </div>
        <div class="dash-stat__body">
          <p class="dash-stat__label">Lokasi Aktif</p>
          <p class="dash-stat__value">{{ stats.aktif }}</p>
          <p class="dash-stat__hint">{{ stats.lokasi - stats.aktif }} nonaktif</p>
        </div>
      </div>

      <div class="dash-stat dash-stat--amber">
        <div class="dash-stat__icon">
          <font-awesome-icon icon="file-invoice-dollar" />
        </div>
        <div class="dash-stat__body">
          <p class="dash-stat__label">Tagihan Belum Lunas</p>
          <p class="dash-stat__value">{{ stats.belumLunas }}</p>
          <p class="dash-stat__hint">dari seluruh tagihan terbit</p>
        </div>
      </div>

      <div class="dash-stat dash-stat--red">
        <div class="dash-stat__icon">
          <font-awesome-icon icon="triangle-exclamation" />
        </div>
        <div class="dash-stat__body">
          <p class="dash-stat__label">Nilai Tunggakan</p>
          <p class="dash-stat__value">{{ formatRupiah(stats.nilaiTunggak) }}</p>
          <p class="dash-stat__hint">{{ stats.terlambat }} tagihan terlambat</p>
        </div>
      </div>
    </div>

    <!-- Tabel + ringkasan -->
    <div class="dash-grid">
      <div class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">Status Lokasi</h2>
            <p class="panel__subtitle">Ringkasan setiap lokasi terdaftar.</p>
          </div>
          <router-link to="/central/lokasi" class="panel__action">
            Kelola Lokasi
            <font-awesome-icon icon="arrow-right" />
          </router-link>
        </div>

        <div v-if="loading" class="panel__state">
          <font-awesome-icon icon="spinner" spin />
          <span>Memuat data lokasi...</span>
        </div>

        <div v-else-if="locations.length === 0" class="panel__state panel__state--empty">
          <font-awesome-icon icon="location-dot" class="panel__state-icon" />
          <p>Belum ada lokasi terdaftar.</p>
          <router-link to="/central/lokasi/tambah" class="panel__cta">
            Daftarkan Lokasi Pertama
          </router-link>
        </div>

        <div v-else class="panel__table-wrap">
          <table class="panel__table">
            <thead>
              <tr>
                <th>Lokasi</th>
                <th>Domain</th>
                <th class="text-right">Tagihan Menunggak</th>
                <th class="text-center">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in locations"
                :key="row.id"
                class="panel__row"
                @click="goToDetail(row)"
              >
                <td>
                  <p class="cell-main">{{ row.label }}</p>
                  <p class="cell-sub">{{ row.slug }}</p>
                </td>
                <td>
                  <span class="cell-mono">{{ row.domain || '—' }}</span>
                </td>
                <td class="text-right">
                  <span v-if="row.tagihan_belum_lunas > 0" class="badge badge--danger">
                    {{ row.tagihan_belum_lunas }} tagihan
                  </span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td class="text-center">
                  <span class="badge" :class="row.is_active ? 'badge--success' : 'badge--muted'">
                    {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Tagihan terlambat -->
      <div class="panel">
        <div class="panel__header">
          <div>
            <h2 class="panel__title">Perlu Perhatian</h2>
            <p class="panel__subtitle">Tagihan yang lewat jatuh tempo.</p>
          </div>
          <router-link to="/central/tagihan" class="panel__action">
            Semua
            <font-awesome-icon icon="arrow-right" />
          </router-link>
        </div>

        <div v-if="loading" class="panel__state">
          <font-awesome-icon icon="spinner" spin />
          <span>Memuat tagihan...</span>
        </div>

        <div v-else-if="overdueInvoices.length === 0" class="panel__state panel__state--empty">
          <font-awesome-icon icon="circle-check" class="panel__state-icon panel__state-icon--ok" />
          <p>Tidak ada tagihan terlambat.</p>
        </div>

        <div v-else class="panel__list">
          <div v-for="inv in overdueInvoices" :key="inv.id" class="panel__list-item">
            <div class="panel__list-main">
              <p class="cell-main">{{ inv.tenant?.label || 'Tidak diketahui' }}</p>
              <p class="cell-sub">{{ inv.nomor }}</p>
            </div>
            <div class="panel__list-side">
              <p class="panel__list-amount">{{ formatRupiah(inv.jumlah) }}</p>
              <p class="panel__list-meta">
                <font-awesome-icon icon="clock" /> {{ inv.sisa_hari }} hari
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { showErrorToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { centralService, locationService, invoiceService } from '@/services/central.service'

const router = useRouter()
const uiStore = useUiStore()

const loading = computed(() => uiStore.loading)

const locations = ref([])
const invoices = ref([])

const stats = reactive({
  lokasi: 0,
  aktif: 0,
  belumLunas: 0,
  nilaiTunggak: 0,
  terlambat: 0,
})

// Dashboard menampilkan maksimal 8 tagihan terlambat. Daftar penuh ada di
// halaman Tagihan -- dashboard cukup memberi gambaran, bukan reports baru.
const overdueInvoices = computed(() =>
  invoices.value.filter((i) => i.status === 'UNPAID' && i.terlambat).slice(0, 8),
)

const formatRupiah = (value) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(value || 0))

const goToDetail = (row) => {
  router.push({ name: 'central-lokasi-detail', params: { id: row.id } })
}

const loadData = async () => {
  try {
    const [overviewRes, lokasiRes, tagihanRes] = await Promise.all([
      centralService.getOverview(),
      locationService.getAll(),
      invoiceService.getAll({ status: 'UNPAID' }),
    ])

    const overview = overviewRes.data?.data || {}
    stats.lokasi = overview.lokasi ?? 0
    stats.aktif = overview.aktif ?? 0
    stats.belumLunas = overview.belumLunas ?? 0
    stats.nilaiTunggak = overview.nilaiTunggak ?? 0
    stats.terlambat = overview.terlambat ?? 0

    locations.value = lokasiRes.data?.data || []
    invoices.value = tagihanRes.data?.data?.data || []
  } catch (error) {
    showErrorToast(error)
  }
}

onMounted(loadData)
</script>

<style scoped>
.central-dashboard {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

/*
 | Prefiks `.dash-stat`, BUKAN `.stat-card`.
 |
 | `stat-card.css` dimuat global di `main.js` dan `.stat-card` di sana
 | punya `cursor: pointer`, `text-align: center`, `user-select: none`,
 | dan `transform` saat hover -- semuanya untuk kartu yang memang bisa
 | diklik (`<component stat-card.vue>` di dashboard lokasi).
 |
 | Kartu di halaman ini hanya angka, tidak punya aksi. Dengan nama yang
 | sama, gaya global itu bocor ke sini: pointer cursor di atas angka yang
 | tidak bisa diklik, dan kartu yang terlihat terangkat saat kursor lewat
 | memberikan kesan ada sesuatu yang terjadi padahal tidak ada. Styling
 | scoped tidak menolong karena aturan global lebih spesifik dalam hal
 | yang sama.
 */
.dash-stat {
  background: var(--color-card-bg, #fff);
  border-radius: 14px;
  padding: 1.25rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  border: 1px solid var(--color-border, #e2e8f0);
  border-left-width: 4px;
}

.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
  gap: 1rem;
}

.dash-stat--slate {
  border-left-color: #64748b;
}
.dash-stat--green {
  border-left-color: #10b981;
}
.dash-stat--amber {
  border-left-color: #f59e0b;
}
.dash-stat--red {
  border-left-color: #ef4444;
}

.dash-stat__icon {
  width: 2.85rem;
  height: 2.85rem;
  flex-shrink: 0;
  border-radius: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
}

.dash-stat--slate .dash-stat__icon {
  background: #f1f5f9;
  color: #475569;
}
.dash-stat--green .dash-stat__icon {
  background: #ecfdf5;
  color: #059669;
}
.dash-stat--amber .dash-stat__icon {
  background: #fffbeb;
  color: #d97706;
}
.dash-stat--red .dash-stat__icon {
  background: #fef2f2;
  color: #dc2626;
}

.dash-stat__body {
  min-width: 0;
}

.dash-stat__label {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
  margin: 0 0 0.2rem;
}

.dash-stat__value {
  font-size: 1.5rem;
  font-weight: 900;
  color: #0f172a;
  line-height: 1.15;
  margin: 0;
}

.dash-stat__hint {
  font-size: 0.7rem;
  color: #94a3b8;
  margin: 0.2rem 0 0;
}

/* ===== PANELS ===== */
.dash-grid {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 1.25rem;
  align-items: start;
}

@media (max-width: 1200px) {
  .dash-grid {
    grid-template-columns: 1fr;
  }
}

.panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  overflow: hidden;
}

.panel__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
}

.panel__title {
  font-size: 0.98rem;
  font-weight: 800;
  color: #0f172a;
  margin: 0;
}

.panel__subtitle {
  font-size: 0.75rem;
  color: #94a3b8;
  margin: 0.15rem 0 0;
}

.panel__action {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.75rem;
  font-weight: 700;
  color: #0284c7;
  text-decoration: none;
  white-space: nowrap;
}

.panel__action:hover {
  text-decoration: underline;
}

.panel__state {
  padding: 2.5rem 1.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  color: #94a3b8;
  font-size: 0.82rem;
  font-weight: 600;
}

.panel__state-icon {
  font-size: 1.8rem;
  color: #cbd5e1;
}

.panel__state-icon--ok {
  color: #10b981;
}

.panel__cta {
  margin-top: 0.4rem;
  padding: 0.5rem 1rem;
  border-radius: 8px;
  background: #0284c7;
  color: #fff;
  font-size: 0.78rem;
  font-weight: 700;
  text-decoration: none;
}

.panel__cta:hover {
  background: #0369a1;
}

.panel__table-wrap {
  overflow-x: auto;
}

.panel__table {
  width: 100%;
  border-collapse: collapse;
}

.panel__table th {
  text-align: left;
  padding: 0.6rem 1.25rem;
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  white-space: nowrap;
}

.panel__table td {
  padding: 0.7rem 1.25rem;
  font-size: 0.82rem;
  color: #475569;
  border-bottom: 1px solid #f1f5f9;
}

.panel__row {
  cursor: pointer;
  transition: background 0.15s ease;
}

.panel__row:hover {
  background: #f8fafc;
}

.cell-main {
  font-size: 0.83rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}

.cell-sub {
  font-size: 0.7rem;
  color: #94a3b8;
  margin: 0.1rem 0 0;
}

.cell-mono {
  font-family: ui-monospace, monospace;
  font-size: 0.75rem;
  color: #64748b;
}

.text-right {
  text-align: right;
}
.text-center {
  text-align: center;
}
.text-muted {
  color: #cbd5e1;
}

.badge {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}

.badge--success {
  background: #ecfdf5;
  color: #059669;
}
.badge--danger {
  background: #fef2f2;
  color: #dc2626;
}
.badge--muted {
  background: #f1f5f9;
  color: #64748b;
}

.panel__list {
  display: flex;
  flex-direction: column;
}

.panel__list-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
}

.panel__list-item:last-child {
  border-bottom: none;
}

.panel__list-main {
  min-width: 0;
}

.panel__list-side {
  text-align: right;
  flex-shrink: 0;
}

.panel__list-amount {
  font-size: 0.83rem;
  font-weight: 800;
  color: #dc2626;
  margin: 0;
}

.panel__list-meta {
  font-size: 0.68rem;
  color: #94a3b8;
  margin: 0.15rem 0 0;
}
</style>
