<template>
  <div class="detail-page">
    <div class="detail-back">
      <router-link to="/central/lokasi">
        <font-awesome-icon icon="arrow-left" />
        Kembali ke Daftar Lokasi
      </router-link>
    </div>

    <div v-if="loading" class="detail-card detail-card--state">
      <font-awesome-icon icon="spinner" spin />
      <span>Memuat detail lokasi...</span>
    </div>

    <template v-else-if="lokasi">
      <!-- ===== Header ===== -->
      <div class="detail-card detail-card--head">
        <div class="detail-head__main">
          <div class="detail-head__mark">
            <font-awesome-icon icon="location-dot" />
          </div>
          <div>
            <h2>{{ lokasi.label }}</h2>
            <p class="detail-head__slug">{{ lokasi.slug }}</p>
          </div>
        </div>

        <div class="detail-head__actions">
          <span
            class="status-pill"
            :class="lokasi.is_active ? 'status-pill--on' : 'status-pill--off'"
          >
            {{ lokasi.is_active ? 'Aktif' : 'Nonaktif' }}
          </span>
          <router-link
            :to="{ name: 'central-lokasi-edit', params: { id: lokasi.id } }"
            class="btn btn--ghost"
          >
            <font-awesome-icon icon="pen" />
            Ubah
          </router-link>
          <button type="button" class="btn btn--danger" @click="handleToggleActive">
            <font-awesome-icon :icon="lokasi.is_active ? 'ban' : 'rotate-left'" />
            {{ lokasi.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
          </button>
        </div>
      </div>

      <div class="detail-grid">
        <!-- ===== Informasi ===== -->
        <div class="detail-card">
          <div class="detail-card__header">
            <h3>Informasi Lokasi</h3>
          </div>

          <dl class="info-list">
            <div class="info-row">
              <dt>Nama Lembaga</dt>
              <dd>{{ lokasi.nama_lembaga || '—' }}</dd>
            </div>
            <div class="info-row">
              <dt>Domain Utama</dt>
              <dd>
                <a
                  v-if="lokasi.domain"
                  :href="`https://${lokasi.domain}`"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="info-link"
                >
                  {{ lokasi.domain }}
                  <font-awesome-icon icon="arrow-up-right-from-square" />
                </a>
                <span v-else>—</span>
              </dd>
            </div>
            <div class="info-row">
              <dt>Domain Alternatif</dt>
              <dd>{{ lokasi.domain_alternatif || '—' }}</dd>
            </div>
            <div class="info-row">
              <dt>Alamat</dt>
              <dd>{{ lokasi.alamat || '—' }}</dd>
            </div>
            <div class="info-row">
              <dt>Email</dt>
              <dd>{{ lokasi.email || '—' }}</dd>
            </div>
            <div class="info-row">
              <dt>Telepon</dt>
              <dd>{{ lokasi.telepon || '—' }}</dd>
            </div>
            <div class="info-row">
              <dt>Berlaku Sampai</dt>
              <dd>{{ lokasi.tgl_berakhir || 'Tanpa batas waktu' }}</dd>
            </div>
            <div class="info-row">
              <dt>Maksimum User</dt>
              <dd>{{ lokasi.max_users ?? 'Tanpa batas' }}</dd>
            </div>
          </dl>
        </div>

        <!-- ===== Infrastruktur ===== -->
        <div class="detail-card">
          <div class="detail-card__header">
            <h3>Infrastruktur</h3>
          </div>

          <div class="infra">
            <div class="infra-row">
              <span class="infra-label">Database</span>
              <code class="infra-value">{{ lokasi.database }}</code>
            </div>
            <div class="infra-row">
              <span class="infra-label">Status Database</span>
              <span
                class="infra-badge"
                :class="lokasi.database_ada ? 'infra-badge--ok' : 'infra-badge--warn'"
              >
                <font-awesome-icon
                  :icon="lokasi.database_ada ? 'circle-check' : 'circle-exclamation'"
                />
                {{ lokasi.database_ada ? 'Tersedia' : 'Tidak ditemukan' }}
              </span>
            </div>
          </div>

          <p v-if="!lokasi.database_ada" class="infra-warning">
            <font-awesome-icon icon="triangle-exclamation" />
            Database tidak ditemukan di server. Lokasi terdaftar di pusat tetapi tidak bisa
            dilayani. Periksa apakah database sudah benar-benar dibuat.
          </p>

          <div class="tenant-hint">
            <p class="tenant-hint__label">Identitas tenant saat development</p>
            <p class="tenant-hint__value">
              <code>{{ lokasi.slug }}</code>
            </p>
            <p class="tenant-hint__desc">
              Dikirim sebagai header <code>X-Tenant-Slug</code> atau query
              <code>?tenant=</code> saat <code>TENANCY_LOOKUP=slug</code>.
            </p>
          </div>
        </div>
      </div>

      <!-- ===== Tagihan ===== -->
      <div class="detail-card">
        <div class="detail-card__header">
          <div>
            <h3>Riwayat Tagihan</h3>
            <p class="detail-card__subtitle">
              {{ invoiceSummary.belumLunas }} belum lunas · Total tagihan
              {{ formatRupiah(invoiceSummary.total) }}
            </p>
          </div>
          <button type="button" class="btn btn--primary btn--sm" @click="showInvoiceModal = true">
            <font-awesome-icon icon="plus" />
            Terbitkan Tagihan
          </button>
        </div>

        <div v-if="invoiceLoading" class="detail-card__state">
          <font-awesome-icon icon="spinner" spin />
          <span>Memuat tagihan...</span>
        </div>

        <div v-else-if="invoices.length === 0" class="detail-card__state detail-card__state--empty">
          <font-awesome-icon icon="file-invoice" class="state-icon" />
          <p>Belum ada tagihan untuk lokasi ini.</p>
        </div>

        <div v-else class="invoice-table-wrap">
          <table class="invoice-table">
            <thead>
              <tr>
                <th>Nomor</th>
                <th>Terbit</th>
                <th>Jatuh Tempo</th>
                <th class="text-right">Jumlah</th>
                <th class="text-center">Status</th>
                <th class="text-right">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in invoices" :key="inv.id">
                <td>
                  <p class="inv-number">{{ inv.nomor }}</p>
                  <p v-if="inv.periode" class="inv-periode">Periode {{ inv.periode }}</p>
                </td>
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
                  <button
                    v-if="inv.status === 'UNPAID'"
                    type="button"
                    class="inv-action inv-action--pay"
                    @click="markPaid(inv)"
                  >
                    <font-awesome-icon icon="check" />
                    Tandai Lunas
                  </button>
                  <button
                    v-else
                    type="button"
                    class="inv-action inv-action--undo"
                    @click="markUnpaid(inv)"
                  >
                    <font-awesome-icon icon="rotate-left" />
                    Batalkan
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- ===== Modal terbitkan tagihan ===== -->
    <Teleport to="body">
      <div v-if="showInvoiceModal" class="modal-overlay" @click="showInvoiceModal = false">
        <div class="modal-box" @click.stop>
          <div class="modal-box__header">
            <div>
              <h3>Terbitkan Tagihan</h3>
              <p>Tagihan langganan untuk {{ lokasi?.label }}.</p>
            </div>
            <button type="button" class="modal-box__close" @click="showInvoiceModal = false">
              <font-awesome-icon icon="times" />
            </button>
          </div>

          <div class="modal-box__body">
            <div class="form-grid">
              <BaseInput
                v-model="invoiceForm.jumlah"
                label="Jumlah (Rp) *"
                type="number"
                placeholder="Contoh: 1500000"
                prefix-icon="money-bill"
                :error="invoiceErrors.jumlah"
              />
              <BaseInput
                v-model="invoiceForm.tgl_invoice"
                label="Tanggal Terbit *"
                type="date"
                :error="invoiceErrors.tgl_invoice"
              />
              <BaseInput
                v-model="invoiceForm.tgl_jatuh_tempo"
                label="Jatuh Tempo"
                type="date"
                :error="invoiceErrors.tgl_jatuh_tempo"
                hint="Kosongkan untuk mengikuti masa toleransi bawaan."
              />
              <BaseInput
                v-model="invoiceForm.periode"
                label="Periode"
                placeholder="Contoh: 2026-01"
                :error="invoiceErrors.periode"
              />
            </div>

            <BaseInput
              v-model="invoiceForm.keterangan"
              label="Keterangan"
              type="textarea"
              :rows="2"
              placeholder="Keterangan tagihan (opsional)"
              :error="invoiceErrors.keterangan"
            />

            <p class="modal-box__note">
              <font-awesome-icon icon="circle-info" />
              Nomor tagihan dibuat otomatis oleh sistem. Tagihan yang lewat jatuh tempo akan
              memblokir akses pengguna lokasi sampai dilunasi.
            </p>
          </div>

          <div class="modal-box__footer">
            <button type="button" class="btn btn--ghost" @click="showInvoiceModal = false">
              Batal
            </button>
            <button
              type="submit"
              class="btn btn--primary"
              :disabled="invoiceSaving"
              @click="submitInvoice"
            >
              <font-awesome-icon v-if="invoiceSaving" icon="spinner" spin />
              Terbitkan
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MySwal, showSuccessToast, showErrorToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { locationService, invoiceService } from '@/services/central.service'
import '@/assets/css/central-form.css'

const route = useRoute()
const router = useRouter()
const uiStore = useUiStore()

const loading = computed(() => uiStore.loading)
const invoiceLoading = ref(false)

const lokasi = ref(null)
const invoices = ref([])

const invoiceSummary = computed(() => {
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

/* ===== Data ===== */
const loadLocation = async () => {
  try {
    const res = await locationService.getById(route.params.id)
    lokasi.value = res.data?.data || null
  } catch (error) {
    showErrorToast(error)
    router.push('/central/lokasi')
  }
}

const loadInvoices = async () => {
  invoiceLoading.value = true

  try {
    const res = await invoiceService.getAll({ tenant_id: route.params.id })
    invoices.value = res.data?.data?.data || []
  } catch (error) {
    showErrorToast(error)
  } finally {
    invoiceLoading.value = false
  }
}

const handleToggleActive = async () => {
  if (lokasi.value.is_active) {
    const result = await MySwal.fire({
      title: 'Nonaktifkan Lokasi?',
      html: `Lokasi <strong>${lokasi.value.label}</strong> tidak lagi bisa diakses.<br>
        Database dan datanya <strong>tidak dihapus</strong>.`,
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
    const res = await locationService.deactivate(lokasi.value.id)
    showSuccessToast(res.data?.message || 'Status lokasi diperbarui.')
    await loadLocation()
  } catch (error) {
    showErrorToast(error)
  }
}

/* ===== Tagihan ===== */
const showInvoiceModal = ref(false)
const invoiceSaving = ref(false)
const invoiceErrors = ref({})

const today = () => new Date().toISOString().slice(0, 10)

const invoiceForm = reactive({
  jumlah: '',
  tgl_invoice: today(),
  tgl_jatuh_tempo: '',
  periode: '',
  keterangan: '',
})

const submitInvoice = async () => {
  invoiceErrors.value = {}

  if (!invoiceForm.jumlah || Number(invoiceForm.jumlah) <= 0) {
    invoiceErrors.value.jumlah = 'Jumlah wajib diisi dan lebih dari nol.'
    return
  }

  if (!invoiceForm.tgl_invoice) {
    invoiceErrors.value.tgl_invoice = 'Tanggal terbit wajib diisi.'
    return
  }

  invoiceSaving.value = true

  try {
    const res = await invoiceService.create({
      tenant_id: Number(route.params.id),
      jumlah: Number(invoiceForm.jumlah),
      tgl_invoice: invoiceForm.tgl_invoice,
      tgl_jatuh_tempo: invoiceForm.tgl_jatuh_tempo || null,
      periode: invoiceForm.periode.trim() || null,
      keterangan: invoiceForm.keterangan.trim() || null,
    })

    showSuccessToast(res.data?.message || 'Tagihan berhasil diterbitkan.')

    invoiceForm.jumlah = ''
    invoiceForm.tgl_jatuh_tempo = ''
    invoiceForm.periode = ''
    invoiceForm.keterangan = ''
    showInvoiceModal.value = false

    await loadInvoices()
  } catch (error) {
    if (error.response?.status === 422) {
      const fieldErrors = error.response.data?.errors || {}

      if (Object.keys(fieldErrors).length > 0) {
        invoiceErrors.value = Object.fromEntries(
          Object.entries(fieldErrors).map(([key, msgs]) => [key, msgs[0]]),
        )
        return
      }
    }

    // 409 = nomor tagihan bentrok. Ini kondisi sementara, bukan kegagalan
    // permanen, jadi cukup dilaporkan tanpa menutup modal -- admin bisa
    // menekan Terbitkan lagi.
    if (error.response?.status === 409) {
      showErrorToast(error)
      return
    }

    showErrorToast(error)
  } finally {
    invoiceSaving.value = false
  }
}

const markPaid = async (inv) => {
  const result = await MySwal.fire({
    title: 'Tandai Lunas?',
    html: `Tagihan <strong>${inv.nomor}</strong> sebesar
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
    await loadInvoices()
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
    await loadInvoices()
  } catch (error) {
    showErrorToast(error)
  }
}

onMounted(() => {
  loadLocation()
  loadInvoices()
})
</script>

<style scoped>
.detail-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 68rem;
  margin: 0 auto;
}

.detail-back a {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.8rem;
  font-weight: 700;
  color: #64748b;
  text-decoration: none;
}

.detail-back a:hover {
  color: #0284c7;
}

.detail-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  overflow: hidden;
}

.detail-card--state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 3rem;
  color: #94a3b8;
  font-size: 0.85rem;
  font-weight: 600;
}

.detail-card--state svg {
  color: #0ea5e9;
  font-size: 1.3rem;
}

.detail-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
}

.detail-card__header h3 {
  font-size: 0.95rem;
  font-weight: 800;
  color: #0f172a;
  margin: 0;
}

.detail-card__subtitle {
  font-size: 0.75rem;
  color: #94a3b8;
  margin: 0.15rem 0 0;
}

.detail-card__state {
  padding: 2.5rem 1.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  color: #94a3b8;
  font-size: 0.82rem;
  font-weight: 600;
}

.state-icon {
  font-size: 1.7rem;
  color: #cbd5e1;
}

/* ===== Header ===== */
.detail-card--head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem;
  flex-wrap: wrap;
}

.detail-head__main {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  min-width: 0;
}

.detail-head__mark {
  width: 3rem;
  height: 3rem;
  flex-shrink: 0;
  border-radius: 12px;
  background: linear-gradient(135deg, #0284c7, #0ea5e9);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
}

.detail-head__main h2 {
  font-size: 1.2rem;
  font-weight: 800;
  color: #0f172a;
  margin: 0;
}

.detail-head__slug {
  font-family: ui-monospace, monospace;
  font-size: 0.75rem;
  color: #94a3b8;
  margin: 0.15rem 0 0;
}

.detail-head__actions {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.btn--sm {
  padding: 0.45rem 0.85rem;
  font-size: 0.76rem;
}

/* ===== Grid ===== */
.detail-grid {
  display: grid;
  grid-template-columns: 1.3fr 1fr;
  gap: 1rem;
  align-items: start;
}

@media (max-width: 1100px) {
  .detail-grid {
    grid-template-columns: 1fr;
  }
}

/* ===== Info list ===== */
.info-list {
  margin: 0;
  padding: 0.5rem 1.25rem 1rem;
}

.info-row {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.6rem 0;
  border-bottom: 1px solid #f8fafc;
}

.info-row:last-child {
  border-bottom: none;
}

.info-row dt {
  font-size: 0.76rem;
  font-weight: 700;
  color: #94a3b8;
  flex-shrink: 0;
}

.info-row dd {
  font-size: 0.82rem;
  color: #334155;
  margin: 0;
  text-align: right;
  word-break: break-word;
}

.info-link {
  color: #0284c7;
  font-weight: 600;
  text-decoration: none;
}

.info-link:hover {
  text-decoration: underline;
}

/* ===== Infra ===== */
.infra {
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}

.infra-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.infra-label {
  font-size: 0.76rem;
  font-weight: 700;
  color: #94a3b8;
}

.infra-value {
  font-family: ui-monospace, monospace;
  font-size: 0.78rem;
  font-weight: 700;
  color: #334155;
  background: #f1f5f9;
  padding: 0.15rem 0.45rem;
  border-radius: 5px;
}

.infra-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.7rem;
  font-weight: 700;
}

.infra-badge--ok {
  color: #059669;
}

.infra-badge--warn {
  color: #d97706;
}

.infra-warning {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  margin: 0 1.25rem 1rem;
  padding: 0.7rem 0.85rem;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 9px;
  font-size: 0.74rem;
  line-height: 1.5;
  color: #92400e;
}

.infra-warning svg {
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.tenant-hint {
  margin: 0 1.25rem 1.25rem;
  padding: 0.8rem 0.9rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 9px;
}

.tenant-hint__label {
  font-size: 0.68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
  margin: 0 0 0.3rem;
}

.tenant-hint__value {
  margin: 0 0 0.4rem;
}

.tenant-hint__desc {
  font-size: 0.72rem;
  color: #64748b;
  line-height: 1.5;
  margin: 0;
}

.tenant-hint code {
  font-family: ui-monospace, monospace;
  font-size: 0.72rem;
  background: #e2e8f0;
  padding: 0.05rem 0.3rem;
  border-radius: 4px;
}

/* ===== Invoice table ===== */
.invoice-table-wrap {
  overflow-x: auto;
}

.invoice-table {
  width: 100%;
  border-collapse: collapse;
}

.invoice-table th {
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

.invoice-table td {
  padding: 0.75rem 1.25rem;
  font-size: 0.82rem;
  color: #475569;
  border-bottom: 1px solid #f1f5f9;
}

.inv-number {
  font-family: ui-monospace, monospace;
  font-size: 0.78rem;
  font-weight: 700;
  color: #0f172a;
  margin: 0;
}

.inv-periode {
  font-size: 0.7rem;
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

.inv-action {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.7rem;
  border-radius: 7px;
  border: 1px solid transparent;
  font-size: 0.72rem;
  font-weight: 700;
  transition: all 0.16s ease;
}

.inv-action--pay {
  background: #ecfdf5;
  border-color: #a7f3d0;
  color: #059669;
}

.inv-action--pay:hover {
  background: #d1fae5;
}

.inv-action--undo {
  background: #fff;
  border-color: #e2e8f0;
  color: #64748b;
}

.inv-action--undo:hover {
  background: #f8fafc;
  border-color: #cbd5e1;
}

/* ===== Modal ===== */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(2px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 1rem;
}

.modal-box {
  background: #fff;
  border-radius: 16px;
  width: 100%;
  max-width: 34rem;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.3);
}

.modal-box__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid #f1f5f9;
}

.modal-box__header h3 {
  font-size: 1.05rem;
  font-weight: 800;
  color: #0f172a;
  margin: 0;
}

.modal-box__header p {
  font-size: 0.78rem;
  color: #94a3b8;
  margin: 0.15rem 0 0;
}

.modal-box__close {
  background: none;
  border: none;
  color: #94a3b8;
  font-size: 1rem;
  padding: 0.25rem;
  transition: color 0.16s ease;
}

.modal-box__close:hover {
  color: #475569;
}

.modal-box__body {
  padding: 1.25rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.modal-box__note {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.7rem 0.85rem;
  background: var(--color-blue-50, #f0f9ff);
  border: 1px solid var(--color-blue-200, #bae6fd);
  border-radius: 9px;
  font-size: 0.73rem;
  line-height: 1.5;
  color: #0369a1;
  margin: 0;
}

.modal-box__note svg {
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.modal-box__footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  padding: 1rem 1.5rem;
  border-top: 1px solid #f1f5f9;
  background: #f8fafc;
}
</style>
