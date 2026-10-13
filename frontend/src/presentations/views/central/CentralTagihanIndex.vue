<template>
  <div class="tagihan-page">
    <div class="tagihan-toolbar">
      <div class="tagihan-filters">
        <select v-model="filters.status" class="tagihan-select" @change="resetPage">
          <option value="">Semua Status</option>
          <option value="UNPAID">Belum Lunas</option>
          <option value="PAID">Lunas</option>
        </select>

        <select v-model="filters.tenant_id" class="tagihan-select" @change="resetPage">
          <option value="">Semua Lokasi</option>
          <option v-for="row in locations" :key="row.id" :value="row.id">
            {{ row.label }}
          </option>
        </select>
      </div>

      <div class="tagihan-summary">
        <span class="summary-item">
          Belum Lunas
          <strong>{{ summary.belumLunas }}</strong>
        </span>
        <span class="summary-sep"></span>
        <span class="summary-item summary-item--danger">
          Total Tunggakan
          <strong>{{ formatRupiah(summary.total) }}</strong>
        </span>
      </div>
    </div>

    <div class="tagihan-table-wrap">
      <table class="tagihan-table">
        <thead>
          <tr>
            <th>Nomor</th>
            <th>Lokasi</th>
            <th>Periode</th>
            <th>Terbit</th>
            <th>Jatuh Tempo</th>
            <th class="text-right">Jumlah</th>
            <th class="text-center">Status</th>
            <th class="text-right">Aksi</th>
          </tr>
        </thead>

        <tbody>
          <tr v-if="loading">
            <td colspan="8" class="tagihan-state">
              <font-awesome-icon icon="spinner" spin />
              <span>Memuat tagihan...</span>
            </td>
          </tr>

          <tr v-else-if="invoices.length === 0">
            <td colspan="8" class="tagihan-state tagihan-state--empty">
              <font-awesome-icon icon="file-invoice-dollar" class="state-icon" />
              <p>Belum ada tagihan yang cocok dengan filter.</p>
            </td>
          </tr>

          <tr v-for="inv in invoices" v-else :key="inv.id" class="tagihan-row">
            <td>
              <p class="inv-number">{{ inv.nomor }}</p>
              <p v-if="inv.keterangan" class="inv-note" :title="inv.keterangan">
                {{ inv.keterangan }}
              </p>
            </td>
            <td>
              <p class="inv-tenant">{{ inv.tenant?.label || '—' }}</p>
              <p v-if="inv.tenant?.slug" class="inv-slug">{{ inv.tenant.slug }}</p>
            </td>
            <td class="text-mono">{{ inv.periode || '—' }}</td>
            <td class="text-mono">{{ inv.tgl_invoice || '—' }}</td>
            <td class="text-mono">
              {{ inv.tgl_jatuh_tempo || inv.deadline }}
              <span v-if="inv.terlambat" class="inv-overdue">
                <font-awesome-icon icon="triangle-exclamation" />
                {{ inv.sisa_hari }} hari
              </span>
            </td>
            <td class="text-right text-amount">{{ formatRupiah(inv.jumlah) }}</td>
            <td class="text-center">
              <span
                class="status-pill"
                :class="inv.status === 'PAID' ? 'status-pill--on' : 'status-pill--off'"
              >
                {{ inv.status === 'PAID' ? 'Lunas' : 'Belum Lunas' }}
              </span>
            </td>
            <td class="text-right">
              <div class="tagihan-actions">
                <button
                  v-if="inv.status === 'UNPAID'"
                  type="button"
                  class="action action--pay"
                  title="Tandai lunas"
                  @click="markPaid(inv)"
                >
                  <font-awesome-icon icon="check" />
                </button>
                <button
                  v-else
                  type="button"
                  class="action action--undo"
                  title="Batalkan status lunas"
                  @click="markUnpaid(inv)"
                >
                  <font-awesome-icon icon="rotate-left" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="!loading && meta.last_page > 1" class="tagihan-pagination">
      <button
        type="button"
        class="page-btn"
        :disabled="meta.current_page <= 1"
        @click="goToPage(meta.current_page - 1)"
      >
        <font-awesome-icon icon="chevron-left" />
        Sebelumnya
      </button>

      <span class="page-info"> Halaman {{ meta.current_page }} dari {{ meta.last_page }} </span>

      <button
        type="button"
        class="page-btn"
        :disabled="meta.current_page >= meta.last_page"
        @click="goToPage(meta.current_page + 1)"
      >
        Berikutnya
        <font-awesome-icon icon="chevron-right" />
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { MySwal, showSuccessToast, showErrorToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { invoiceService, locationService } from '@/services/central.service'

const uiStore = useUiStore()
const loading = computed(() => uiStore.loading)

const invoices = ref([])
const locations = ref([])
const page = ref(1)

// Endpoint tagihan memakai pagination sisi server (50 per halaman), jadi
// filter di sini harus memicu request ulang -- bukan sekadar menyembunyikan
// baris di tabel seperti pada daftar lokasi.
const filters = reactive({ status: '', tenant_id: '' })

const meta = reactive({
  current_page: 1,
  last_page: 1,
  from: 0,
  to: 0,
  total: 0,
})

const summary = computed(() => {
  const unpaid = invoices.value.filter((i) => i.status === 'UNPAID')
  return {
    belumLunas: unpaid.length,
    total: unpaid.reduce((sum, i) => sum + Number(i.jumlah || 0), 0),
  }
})

const formatRupiah = (value) =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(Number(value || 0))

const resetPage = () => {
  page.value = 1
  loadData()
}

const goToPage = (target) => {
  page.value = target
  loadData()
}

const loadData = async () => {
  try {
    const params = { page: page.value }
    if (filters.status) params.status = filters.status
    if (filters.tenant_id) params.tenant_id = filters.tenant_id

    const res = await invoiceService.getAll(params)
    const body = res.data?.data || {}

    invoices.value = body.data || []
    Object.assign(meta, {
      current_page: body.current_page ?? 1,
      last_page: body.last_page ?? 1,
      from: body.from ?? 0,
      to: body.to ?? 0,
      total: body.total ?? 0,
    })
  } catch (error) {
    showErrorToast(error)
  }
}

const loadLocations = async () => {
  try {
    const res = await locationService.getAll()
    locations.value = res.data?.data || []
  } catch {
    // Dropdown lokasi hanya pelengkap -- daftar tagihan tetap bisa dibaca
    // tanpa filter lokasi. Kegagalannya sengaja ditelan tanpa toast agar
    // user tidak mengira seluruh halaman gagal dimuat.
    locations.value = []
  }
}

const markPaid = async (inv) => {
  const result = await MySwal.fire({
    title: 'Tandai Lunas?',
    html: `Tagihan <strong>${inv.nomor}</strong> untuk
      <strong>${inv.tenant?.label || 'lokasi'}</strong> sebesar
      <strong>${formatRupiah(inv.jumlah)}</strong> akan ditandai lunas.`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#0284c7',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Ya, tandai lunas',
    cancelButtonText: 'Batal',
  })

  if (!result.isConfirmed) return

  try {
    const res = await invoiceService.markPaid(inv.id)
    showSuccessToast(res.data?.message || 'Tagihan ditandai lunas.')
    await loadData()
  } catch (error) {
    showErrorToast(error)
  }
}

const markUnpaid = async (inv) => {
  const result = await MySwal.fire({
    title: 'Batalkan Status Lunas?',
    html: `Tagihan <strong>${inv.nomor}</strong> akan kembali menjadi belum lunas
      dan bisa memblokir akses pengguna lokasi bila lewat jatuh tempo.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#ef4444',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Ya, batalkan',
    cancelButtonText: 'Batal',
  })

  if (!result.isConfirmed) return

  try {
    const res = await invoiceService.markUnpaid(inv.id)
    showSuccessToast(res.data?.message || 'Status tagihan dikembalikan.')
    await loadData()
  } catch (error) {
    showErrorToast(error)
  }
}

onMounted(() => {
  loadLocations()
  loadData()
})
</script>

<style scoped>
.tagihan-page {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  overflow: hidden;
}

.tagihan-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: wrap;
}

.tagihan-filters {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.tagihan-select {
  padding: 0.5rem 0.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 9px;
  font-size: 0.8rem;
  font-weight: 600;
  color: #475569;
  background: #fff;
  min-width: 9rem;
}

.tagihan-summary {
  display: flex;
  align-items: center;
  gap: 0.9rem;
}

.summary-item {
  font-size: 0.72rem;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.summary-item strong {
  display: block;
  font-size: 0.95rem;
  font-weight: 900;
  color: #0f172a;
  text-transform: none;
  letter-spacing: 0;
  margin-top: 0.1rem;
}

.summary-item--danger strong {
  color: #dc2626;
}

.summary-sep {
  width: 1px;
  height: 1.9rem;
  background: #e2e8f0;
}

.tagihan-table-wrap {
  overflow-x: auto;
}

.tagihan-table {
  width: 100%;
  border-collapse: collapse;
}

.tagihan-table th {
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

.tagihan-table td {
  padding: 0.75rem 1.25rem;
  font-size: 0.82rem;
  color: #475569;
  border-bottom: 1px solid #f1f5f9;
}

.tagihan-row:hover {
  background: #f8fafc;
}

.inv-number {
  font-family: ui-monospace, monospace;
  font-size: 0.78rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}

.inv-note {
  font-size: 0.68rem;
  color: #94a3b8;
  margin: 0.1rem 0 0;
  max-width: 12rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.inv-tenant {
  font-size: 0.82rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}

.inv-slug {
  font-family: ui-monospace, monospace;
  font-size: 0.68rem;
  color: #94a3b8;
  margin: 0.1rem 0 0;
}

.inv-overdue {
  display: block;
  font-size: 0.68rem;
  font-weight: 700;
  color: #dc2626;
  margin-top: 0.15rem;
}

.text-mono {
  font-family: ui-monospace, monospace;
  font-size: 0.76rem;
  color: #64748b;
}

.text-right {
  text-align: right;
}

.text-center {
  text-align: center;
}

.text-amount {
  font-weight: 800;
  color: #0f172a;
}

.tagihan-actions {
  display: inline-flex;
  gap: 0.3rem;
  justify-content: flex-end;
}

.action {
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

.action--pay:hover {
  background: #ecfdf5;
  color: #059669;
  border-color: #a7f3d0;
}

.action--undo:hover {
  background: #f8fafc;
  color: #0f172a;
  border-color: #cbd5e1;
}

.tagihan-state {
  padding: 2.5rem 1.5rem;
  text-align: center;
  color: #94a3b8;
  font-size: 0.82rem;
  font-weight: 600;
}

.tagihan-state svg {
  color: #0ea5e9;
}

.state-icon {
  font-size: 1.8rem;
  color: #cbd5e1;
}

.tagihan-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.85rem 1.25rem;
  border-top: 1px solid #f1f5f9;
}

.page-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.45rem 0.85rem;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
  color: #475569;
  font-size: 0.76rem;
  font-weight: 700;
  transition: all 0.16s ease;
}

.page-btn:hover:not(:disabled) {
  background: #f8fafc;
  border-color: #cbd5e1;
}

.page-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.page-info {
  font-size: 0.75rem;
  color: #94a3b8;
  font-weight: 600;
}

@media (max-width: 768px) {
  .tagihan-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .tagihan-summary {
    justify-content: space-between;
  }
}
</style>
