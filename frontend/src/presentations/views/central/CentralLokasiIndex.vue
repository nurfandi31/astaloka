<template>
  <div class="lokasi-page">
    <div class="lokasi-toolbar">
      <div class="lokasi-toolbar__search">
        <font-awesome-icon icon="magnifying-glass" />
        <input v-model="searchQuery" type="text" placeholder="Cari nama, slug, atau domain..." />
      </div>

      <div class="lokasi-toolbar__filters">
        <select v-model="statusFilter" class="lokasi-filter">
          <option value="all">Semua Status</option>
          <option value="active">Aktif</option>
          <option value="inactive">Nonaktif</option>
        </select>

        <router-link to="/central/lokasi/tambah" class="lokasi-add">
          <font-awesome-icon icon="plus" />
          Tambah Lokasi
        </router-link>
      </div>
    </div>

    <div class="lokasi-table-wrap">
      <table class="lokasi-table">
        <thead>
          <tr>
            <th>Lokasi</th>
            <th>Database</th>
            <th>Domain</th>
            <th class="text-right">Tunggakan</th>
            <th>Berakhir</th>
            <th class="text-center">Status</th>
            <th class="text-right">Aksi</th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="loading">
            <td colspan="7" class="lokasi-state">
              <font-awesome-icon icon="spinner" spin />
              <span>Memuat daftar lokasi...</span>
            </td>
          </tr>

          <tr v-else-if="filteredLocations.length === 0">
            <td colspan="7" class="lokasi-state lokasi-state--empty">
              <font-awesome-icon icon="location-dot" class="lokasi-state__icon" />
              <p>
                {{
                  searchQuery || statusFilter !== 'all'
                    ? 'Tidak ada lokasi yang cocok dengan pencarian.'
                    : 'Belum ada lokasi terdaftar.'
                }}
              </p>
              <router-link
                v-if="!searchQuery && statusFilter === 'all'"
                to="/central/lokasi/tambah"
                class="lokasi-state__cta"
              >
                Daftarkan Lokasi Pertama
              </router-link>
            </td>
          </tr>

          <tr v-for="row in filteredLocations" v-else :key="row.id" class="lokasi-row">
            <td>
              <div class="lokasi-cell">
                <p class="lokasi-cell__name">{{ row.label }}</p>
                <p class="lokasi-cell__slug">{{ row.slug }}</p>
              </div>
            </td>
            <td>
              <span class="lokasi-mono">{{ row.database }}</span>
              <font-awesome-icon
                v-if="row.database_ada"
                icon="circle-check"
                class="lokasi-db-ok"
                title="Database tersedia"
              />
              <font-awesome-icon
                v-else
                icon="circle-exclamation"
                class="lokasi-db-missing"
                title="Database tidak ditemukan di server"
              />
            </td>
            <td>
              <span class="lokasi-mono">{{ row.domain || '—' }}</span>
            </td>
            <td class="text-right">
              <span
                v-if="(row.tagihan_belum_lunas || 0) > 0"
                class="lokasi-badge lokasi-badge--danger"
              >
                {{ row.tagihan_belum_lunas }} · {{ formatRupiah(row.total_tagihan) }}
              </span>
              <span v-else class="lokasi-muted">Lunas</span>
            </td>
            <td>
              <span class="lokasi-mono">{{ row.tgl_berakhir || '∞' }}</span>
            </td>
            <td class="text-center">
              <span
                class="lokasi-badge"
                :class="row.is_active ? 'lokasi-badge--success' : 'lokasi-badge--muted'"
              >
                {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
              </span>
            </td>
            <td class="text-right">
              <div class="lokasi-actions">
                <button type="button" title="Lihat detail" @click="goToDetail(row)">
                  <font-awesome-icon icon="eye" />
                </button>
                <button type="button" title="Ubah" @click="goToEdit(row)">
                  <font-awesome-icon icon="pen" />
                </button>
                <button
                  type="button"
                  title="Nonaktifkan"
                  class="lokasi-actions__danger"
                  @click="handleToggleActive(row)"
                >
                  <font-awesome-icon :icon="row.is_active ? 'ban' : 'rotate-left'" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="!loading && filteredLocations.length > 0" class="lokasi-count">
      Menampilkan {{ filteredLocations.length }} dari {{ locations.length }} lokasi
    </p>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { MySwal, showErrorToast, showSuccessToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { locationService } from '@/services/central.service'

const router = useRouter()
const uiStore = useUiStore()

const loading = computed(() => uiStore.loading)
const locations = ref([])
const searchQuery = ref('')
const statusFilter = ref('all')

const filteredLocations = computed(() => {
  const keyword = searchQuery.value.trim().toLowerCase()

  return locations.value.filter((row) => {
    if (statusFilter.value === 'active' && !row.is_active) return false
    if (statusFilter.value === 'inactive' && row.is_active) return false

    if (!keyword) return true

    return (
      row.label?.toLowerCase().includes(keyword) ||
      row.nama?.toLowerCase().includes(keyword) ||
      row.slug?.toLowerCase().includes(keyword) ||
      row.domain?.toLowerCase().includes(keyword) ||
      row.database?.toLowerCase().includes(keyword)
    )
  })
})

const formatRupiah = (value) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(value || 0))

const goToDetail = (row) => {
  router.push({ name: 'central-lokasi-detail', params: { id: row.id } })
}

const goToEdit = (row) => {
  router.push({ name: 'central-lokasi-edit', params: { id: row.id } })
}

/**
 * Menonaktifkan lokasi memakai DELETE, tapi efeknya SOFT di backend: is_active
 * di-set false, database dan datanya tidak disentuh. Sadari itu karena tombol
 * ikonnya berubah menjadi "aktifkan kembali" saat lokasi sudah nonaktif --
 * punitive wording "hapus" akan membuat user takut menekan tombol yang
 * sebenarnya tidak menghapus apa pun.
 */
const handleToggleActive = async (row) => {
  if (row.is_active) {
    const result = await MySwal.fire({
      title: 'Nonaktifkan Lokasi?',
      html: `Lokasi <strong>${row.label}</strong> tidak lagi bisa diakses framework ini.<br>
        Database dan seluruh datanya <strong>tidak dihapus</strong> dan bisa diaktifkan kembali kapan saja.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Ya, nonaktifkan',
      cancelButtonText: 'Batal',
    })

    if (!result.isConfirmed) return
  }

  try {
    const res = await locationService.deactivate(row.id)
    showSuccessToast(res.data?.message || 'Status lokasi diperbarui.')
    await loadData()
  } catch (error) {
    showErrorToast(error)
  }
}

const loadData = async () => {
  try {
    const res = await locationService.getAll()
    locations.value = res.data?.data || []
  } catch (error) {
    showErrorToast(error)
  }
}

onMounted(loadData)
</script>

<style scoped>
.lokasi-page {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  overflow: hidden;
}

.lokasi-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: wrap;
}

.lokasi-toolbar__search {
  position: relative;
  display: flex;
  align-items: center;
  flex: 1;
  min-width: 14rem;
}

.lokasi-toolbar__search svg {
  position: absolute;
  left: 0.75rem;
  color: #94a3b8;
  font-size: 0.82rem;
  pointer-events: none;
}

.lokasi-toolbar__search input {
  width: 100%;
  padding: 0.5rem 0.85rem 0.5rem 2.25rem;
  border: 1px solid #e2e8f0;
  border-radius: 9px;
  font-size: 0.83rem;
  background: #f8fafc;
  color: #0f172a;
  transition: all 0.18s ease;
}

.lokasi-toolbar__search input:focus {
  outline: none;
  border-color: #0284c7;
  background: #fff;
  box-shadow: 0 0 0 3px rgba(11, 122, 158, 0.1);
}

.lokasi-toolbar__filters {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.lokasi-filter {
  padding: 0.5rem 0.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 9px;
  font-size: 0.8rem;
  font-weight: 600;
  color: #475569;
  background: #fff;
}

.lokasi-add {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.55rem 0.95rem;
  border-radius: 9px;
  background: linear-gradient(135deg, #0284c7, #0ea5e9);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 700;
  text-decoration: none;
  white-space: nowrap;
  box-shadow: 0 6px 14px -6px rgba(14, 165, 233, 0.7);
}

.lokasi-add:hover {
  filter: brightness(1.08);
}

.lokasi-table-wrap {
  overflow-x: auto;
}

.lokasi-table {
  width: 100%;
  border-collapse: collapse;
}

.lokasi-table th {
  text-align: left;
  padding: 0.65rem 1.25rem;
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  white-space: nowrap;
}

.lokasi-table td {
  padding: 0.8rem 1.25rem;
  font-size: 0.82rem;
  color: #475569;
  border-bottom: 1px solid #f1f5f9;
}

.lokasi-row:last-child td {
  border-bottom: none;
}

.lokasi-row:hover {
  background: #f8fafc;
}

.lokasi-cell__name {
  font-size: 0.84rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}

.lokasi-cell__slug {
  font-family: ui-monospace, monospace;
  font-size: 0.7rem;
  color: #94a3b8;
  margin: 0.15rem 0 0;
}

.lokasi-mono {
  font-family: ui-monospace, monospace;
  font-size: 0.75rem;
  color: #64748b;
}

.lokasi-db-ok {
  color: #10b981;
  font-size: 0.75rem;
  margin-left: 0.35rem;
}

.lokasi-db-missing {
  color: #f59e0b;
  font-size: 0.75rem;
  margin-left: 0.35rem;
}

.lokasi-badge {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}

.lokasi-badge--success {
  background: #ecfdf5;
  color: #059669;
}
.lokasi-badge--danger {
  background: #fef2f2;
  color: #dc2626;
}
.lokasi-badge--muted {
  background: #f1f5f9;
  color: #64748b;
}

.lokasi-muted {
  color: #10b981;
  font-size: 0.72rem;
  font-weight: 700;
}

.text-right {
  text-align: right;
}
.text-center {
  text-align: center;
}

.lokasi-actions {
  display: inline-flex;
  gap: 0.3rem;
  justify-content: flex-end;
}

.lokasi-actions button {
  width: 1.9rem;
  height: 1.9rem;
  border-radius: 7px;
  border: 1px solid #e2e8f0;
  background: #fff;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.78rem;
  transition: all 0.16s ease;
}

.lokasi-actions button:hover {
  background: #f1f5f9;
  color: #0f172a;
  border-color: #cbd5e1;
}

.lokasi-actions__danger:hover {
  background: #fef2f2 !important;
  color: #dc2626 !important;
  border-color: color-mix(in srgb, var(--color-danger, #ef4444) 30%, transparent) !important;
}

.lokasi-state {
  padding: 2.5rem 1.5rem;
  text-align: center;
  color: #94a3b8;
  font-size: 0.82rem;
  font-weight: 600;
}

.lokasi-state svg {
  color: #0ea5e9;
}

.lokasi-state__icon {
  font-size: 1.8rem;
  color: #cbd5e1;
}

.lokasi-state__cta {
  display: inline-block;
  margin-top: 0.6rem;
  padding: 0.5rem 1rem;
  border-radius: 8px;
  background: #0284c7;
  color: #fff;
  font-size: 0.78rem;
  font-weight: 700;
  text-decoration: none;
}

.lokasi-count {
  padding: 0.75rem 1.25rem;
  border-top: 1px solid #f1f5f9;
  font-size: 0.73rem;
  color: #94a3b8;
  margin: 0;
}
</style>
