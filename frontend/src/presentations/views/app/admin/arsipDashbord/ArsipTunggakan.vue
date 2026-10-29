<template>
  <!--
    TIDAK pakai `ContentCard` di sini.

    Panel popup sudah punya border, `rounded-2xl`, dan shadow sendiri. Kalau
    di dalamnya masih ada card, card itu menempel PERSIS di tepi panel
    (`selisihKiri/Kanan/Atas` = 0), sehingga:
      - border card dan border panel bertumpuk di garis yang sama
      - pojok lengkungan card jatuh di titik yang sama dengan pojok panel,
        jadi yang terlihat bukan "card di dalam panel" tapi lengkungan yang
        "nempel" dan membentuk sudut yang aneh
      - sisi bawah card juga menyentuh footer popup

    Jadi isinya jadi milik panel saja, dijauhkan 0.75rem (`p-3`) supaya
    tabelnya terlihat masuk ke dalam, bukan menempel di tepi.

    `min-h-0!` WAJIB: div ini flex item di dalam body popup yang punya
    `flex: 1`. Tanpa `min-h-0`, div tidak boleh menyusut di bawah tinggi
    isinya, jadi tabelnya meluber dan terpotong `overflow-hidden` -- tidak ada
    scrollbar, user cuma bisa lihat "segitu data"-nya.

    `h-full!` + `flex! flex-col!` bikin DataTable di dalamnya ikut mengisi
    ruang sisa, dan tabel itulah yang jadi elemen yang digulir.
  -->
  <div class="h-full! min-h-0! flex! flex-col! p-3!">
    <DataTable
      v-model="searchQuery"
      :data="itemsList"
      :columns="columns"
      title="Detail Arsip Tunggakan"
      searchPlaceholder="Cari nama atau nomor pelanggan..."
      v-model:current-page="currentPage"
      v-model:per-page="perPage"
      :total-entries="serverTotal"
      :total-pages="totalPages"
      :show-entries="true"
      :no-card="true"
      server-side
      :loading="loading"
      empty-title="Tidak Ada Tunggakan"
      empty-message="Belum ada pelanggan dengan tunggakan denda. Semua tagihan sudah lunas."
      empty-icon="file-invoice-dollar"
    >
      <template #column-nomorInduk="{ row }">
        <span class="text-[12px] text-slate-600 font-medium font-mono whitespace-nowrap">
          {{ row.nomorInduk }}
        </span>
      </template>
      <template #column-periodeLabel="{ row }">
        <span class="text-[12px] text-slate-600 font-medium whitespace-nowrap">
          {{ row.periodeLabel }}
        </span>
      </template>
      <template #column-tagihan="{ row }">
        <span
          :class="[
            'text-[12px] font-semibold font-mono whitespace-nowrap',
            row.tagihan > 0 ? 'text-slate-700' : 'text-slate-400',
          ]"
        >
          {{ formatRupiah(row.tagihan) }}
        </span>
      </template>
      <template #column-denda="{ row }">
        <span
          :class="[
            'text-[12px] font-semibold font-mono whitespace-nowrap',
            row.denda > 0 ? 'text-rose-600' : 'text-slate-400',
          ]"
        >
          {{ formatRupiah(row.denda) }}
        </span>
      </template>
      <template #column-total="{ row }">
        <span class="text-[12px] text-slate-800 font-bold font-mono whitespace-nowrap">
          {{ formatRupiah(row.total) }}
        </span>
      </template>
      <!-- Selalu "Belum Lunas": query backend sudah di-filter status='unpaid'. -->
      <template #column-status="{ row }">
        <span
          class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-md bg-rose-50 text-rose-600"
        >
          {{ row.status || 'Belum Lunas' }}
        </span>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import DataTable from '@/presentations/components/ui/DataTable.vue'
import { formatRupiah } from '@/composables/useFormatCurrency'
import { usePopupData } from '@/composables/usePopupData'

// Kolom nominal & tanggal rata kanan; header DataTable default text-left, jadi
// perlu `!` agar override benar-benar menang (sama seperti KelasIndex/detailPemakaianAir).
const columns = [
  { key: 'nomorInduk', title: 'No. Pelanggan' },
  { key: 'customer', title: 'Nama' },
  { key: 'alamat', title: 'Alamat' },
  {
    key: 'periodeLabel',
    title: 'Periode',
    thClass: 'whitespace-nowrap!',
  },
  {
    key: 'tagihan',
    title: 'Tagihan',
    thClass: 'text-right!',
    tdClass: 'text-right!',
  },
  {
    key: 'denda',
    title: 'Denda',
    thClass: 'text-right!',
    tdClass: 'text-right!',
  },
  {
    key: 'total',
    title: 'Total Tunggakan',
    thClass: 'text-right!',
    tdClass: 'text-right!',
  },
  { key: 'status', title: 'Status' },
]

// Pakai endpoint ringan DashboardController::popupData?type=tunggakan
// (filter & paginasi di SERVER — sebelumnya getAllBills() menarik semua halaman)
//
// Composable juga mengembalikan nomor halaman ke 1 saat `per_page` berubah,
// dan membuang balasan lama yang tiba belakangan. Lihat `usePopupData.js`.
const { searchQuery, currentPage, perPage, loading, serverTotal, totalPages, itemsList } =
  usePopupData('tunggakan')
</script>
