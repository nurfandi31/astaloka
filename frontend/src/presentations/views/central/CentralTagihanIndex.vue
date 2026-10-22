<template>
  <div class="tagihan-page">
    <div class="tagihan-toolbar">
      <div class="tagihan-filters">
        <BaseSelect
          v-model="filters.status"
          :options="STATUS_OPTIONS"
          option-label="label"
          option-value="value"
          placeholder="Semua Status"
          :filter="false"
          :show-clear="false"
          class="tagihan-select"
          @change="resetPage"
        />

        <!--
             Lokasi punya nilai `null` untuk "Semua Lokasi", jadi daftar
             opsi ikut memuatnya (lihat TENANT_OPTIONS). showClear
             dimatikan karena "Semua" sudah ada di dalam daftar -- dua
             cara untuk hal yang sama hanya membingungkan.
        -->
        <BaseSelect
          v-model="filters.tenant_id"
          :options="TENANT_OPTIONS"
          option-label="label"
          option-value="value"
          placeholder="Semua Lokasi"
          filter-placeholder="Cari nama lokasi..."
          class="tagihan-select tagihan-select--lokasi"
          :show-clear="false"
          @change="resetPage"
        />
      </div>

      <div class="tagihan-summary">
        <span class="summary-item">
          Belum Lunas
          <strong>{{ summary.belumLunas }}</strong>
        </span>
        <span class="summary-sep"></span>
        <span class="summary-item summary-item--danger">
          Total Tunggakan
          <strong>{{ formatRupiah(summary.totalTunggakan) }}</strong>
        </span>
        <span class="summary-sep"></span>

        <!--
          "Sudah Lunas" ditambahkan supaya halaman ini menjawab pertanyaan
          yang sama dengan dashboard.

          Dashboard menampilkan "Sudah Lunas" di kartu komposisi status
          tagihan, dan itulah tempat orang pertama kali melihat "berapa
          yang sudah dibayar". Halaman Tagihan sebelumnya tidak punya
          angka itu sama sekali -- jadi pertanyaan itu hanya bisa
          dijawab di satu tempat, dan tempat kedua (daftar tagihan)
          terlihat seperti tidak punya jawaban.

          Angkanya diambil dari `ringkasan` milik server, bukan dari
          `invoices` yang isinya satu halaman. Dihitung dari sana, hasilnya
          benar selama total tagihan masih muat di satu halaman -- dan
          diam-diam salah setelah itu, persis seperti "{N} belum lunas"
          yang dulu dihitung dari baris yang sudah dipaginasi.
        -->
        <span class="summary-item summary-item--ok">
          Sudah Lunas
          <strong>{{ summary.lunas }}</strong>
        </span>
      </div>
    </div>

    <!--
         Tampilan HP: satu kartu per tagihan.

         Lima kolom tabel (termasuk "Jumlah" dan "Status") memang muat di
         390px, tapi isinya cuma nomor, lokasi, dan nominal -- tiga baris yang
         menjawab pertanyaan "apa dan berapa". Sisanya sekarang satu klik lagi
         di pop-up detail, dan klik itu harus tetap murah di layar kecil yang
         dipakai sambil berdiri di depan orang.
    -->
    <ul v-if="!loading && invoices.length" class="tagihan-cards">
      <li
        v-for="inv in invoices"
        :key="inv.id"
        class="tagihan-card"
        role="button"
        tabindex="0"
        @click="bukaDetail(inv)"
        @keydown.enter.prevent="bukaDetail(inv)"
        @keydown.space.prevent="bukaDetail(inv)"
      >
        <header class="tagihan-card__head">
          <div class="tagihan-card__ident">
            <p class="tagihan-card__number">{{ inv.nomor }}</p>
            <p class="tagihan-card__tenant">{{ inv.tenant?.label || 'Tidak diketahui' }}</p>
          </div>
          <span
            class="status-pill"
            :class="inv.status === 'PAID' ? 'status-pill--on' : 'status-pill--off'"
          >
            {{ inv.status === 'PAID' ? 'Lunas' : 'Belum Lunas' }}
          </span>
        </header>

        <p class="tagihan-card__amount">{{ formatRupiah(inv.jumlah) }}</p>

        <footer class="tagihan-card__foot">
          <span v-if="inv.terlambat" class="tagihan-card__late">
            <font-awesome-icon icon="triangle-exclamation" />
            Terlambat {{ Math.abs(inv.sisa_hari) }} hari
          </span>
          <span v-else-if="inv.tgl_jatuh_tempo" class="tagihan-card__due">
            <font-awesome-icon icon="clock" />
            Jatuh tempo {{ inv.tgl_jatuh_tempo }}
          </span>
          <span v-else class="tagihan-card__hint">
            <font-awesome-icon icon="magnifying-glass" />
            Ketuk untuk detail
          </span>
        </footer>
      </li>
    </ul>

    <!--
         Tampilan desktop.

         Sekarang memakai `DataTable` yang sama dengan daftar Lokasi dan Data
         Pelanggan, bukan `<table>` sendiri. Alasannya bukan tampilan: dengan
         tiga potongan terpisah, "seperti halaman lain" berarti menjaga tiga
         hal sekaligus (search, ukuran halaman, pagination) tetap sama di
         setiap file -- dan ketiganya sudah pernah berbeda-bedanya.

         Kolom yang dihapus TIDAK dikembalikan: "Jatuh Tempo", "Dibayar", dan
         "Aksi" tidak ada di tabel karena jarang dipakai untuk MEMBUKA daftar
         ini. Daftar tagihan dibuka untuk "siapa yang menunggak", dan itu sudah
         terbaca dari Status dan Jumlah di baris yang sama. Sisanya adalah
         pertanyaan yang muncul SETELAH orang memutuskan baris mana yang
         dilihat, jadi membayar satu kolom ekstra per baris hanya memperlebar
         tabel yang harus dibaca sekilas. Semuanya ada di pop-up detail.

         Search & "Tampilkan ... data" sekarang ikut hidup, sama seperti
         daftar Lokasi dan Data Pelanggan. Keduanya dulu dimatikan karena
         backend `/central/invoices` belum menerima parameter `search` --
         menyalakan kotak pencarian yang tidak pernah mengubah isi tabel lebih
         buruk daripada tidak ada kotak sama sekali. Sekarang
         `InvoiceController::index` menerimanya (mencari di nomor, keterangan,
         dan nama lokasi), jadi tidak ada alasan menahannya lagi.

         Toolbar yang sama pada `DataTable` juga membawa "Tampilkan 10/25/50/100
         data". Itu bukan sekadar ukuran tampilan: `per_page` ikut dikirim ke
         backend, jadi memilih 100 benar-benar mengambil 100 baris, bukan
         memotong 50 baris di peramban.

         Pagination TIDAK dimatikan: `DataTable` menggambar "Showing X to Y of Z
         entries" beserta tombol Prev/Next, menggantikan tombol
         "Sebelumnya / Berikutnya" buatan sendiri yang sebelumnya hidup di
         bawah kartu. Angkanya dibaca dari `meta` server, jadi tetap benar
         walau yang tampil hanya isi satu halaman.

         `server-side` WAJIB ada di sini, dan sekarang sudah ada.
         ---------------------
         DataTable secara bawaan memotong `data` sendiri di peramban:
         `data.slice((halaman - 1) * perPage, ...)`. Itu benar untuk halaman
         yang datanya diambil utuh -- tapi halaman ini datanya SUDAH dipaginasi
         di server, jadi `data` hanya berisi satu halaman saja.

         Tanpa `server-side`, baris yang tampil pada halaman 2 adalah
         `data.slice(10, 20)` dari data yang isinya 10 baris -- hasilnya
         KOSONG. Headless tabelnya tetap menggambar kolom, tapi tidak ada satu
         pun baris di bawahnya, dan tidak ada pesan apa pun yang menjelaskan
         kenapa.

         Gejalanya persis seperti yang dilaporkan: dashboard Central
         menampilkan riwayat pembayaran lunas (dashboard menghitung dari
         seluruh tabel), sementara halaman Tagihan "kosong" -- padahal
         datanya ada. Yang kosong bukan datanya; yang kosong adalah BARISNYA
         di layar.

         Pola yang sama sudah dipakai `daftarTagihan.vue` (panel admin lokasi)
         dan dipakai lagi di `PelangganIndex.vue` serta beberapa halaman
         arsip, jadi ini bukan aturan baru -- halaman Tagihan Panel Pusat
         satu-satunya yang lupa.
    -->
    <DataTable
      v-model="searchQuery"
      :data="invoices"
      :columns="tableColumns"
      title=""
      :current-page="page"
      :total-pages="meta.last_page"
      :total-entries="meta.total"
      :per-page="meta.per_page"
      :show-pagination="meta.last_page > 1"
      search-placeholder="Cari nomor, keterangan, atau lokasi..."
      :loading="loading"
      row-clickable
      empty-title="Tagihan Tidak Ditemukan"
      empty-message="Mohon cek kembali kata kunci pencarian atau filter yang dipilih."
      empty-icon="file-invoice-dollar"
      class="tagihan-datatable"
      server-side
      @row-click="bukaDetail"
      @update:current-page="onPageChange"
      @update:per-page="onPerPageChange"
    >
      <template #column-nomor="{ row }">
        <div class="flex flex-col">
          <span class="text-[13px]! font-bold! text-slate-800!">{{ row.nomor }}</span>
          <span v-if="row.keterangan" class="text-[11px]! text-slate-400!" :title="row.keterangan">
            {{ row.keterangan }}
          </span>
        </div>
      </template>

      <template #column-lokasi="{ row }">
        <div class="flex flex-col">
          <span class="text-[13px]! font-semibold! text-slate-700!">
            {{ row.tenant?.label || '—' }}
          </span>
          <span v-if="row.tenant?.slug" class="font-mono! text-[11px]! text-slate-400!">
            {{ row.tenant.slug }}
          </span>
        </div>
      </template>

      <template #column-status="{ row }">
        <span
          class="inline-block! px-2! py-0.5! rounded-full! text-[10px]! font-extrabold! uppercase! tracking-wide!"
          :class="
            row.status === 'PAID'
              ? 'bg-emerald-50! text-emerald-600!'
              : 'bg-amber-50! text-amber-600!'
          "
        >
          {{ row.status === 'PAID' ? 'Lunas' : 'Belum Lunas' }}
        </span>
      </template>

      <template #column-jumlah="{ row }">
        <span class="font-mono! text-[13px]! font-semibold! text-slate-700! whitespace-nowrap!">
          {{ formatRupiah(row.jumlah) }}
        </span>
      </template>

      <!--
        Penanda "klik" di ujung baris.

        Tidak ada tombol dan tidak ada ikon besar: satu klik di mana saja pada
        baris itu sudah membuka detail, jadi tanda panah besar di kolom kosong
        hanya menambah satu hal yang harus dipahami sebelum orang tahu bahwa
        baris ini bisa diklik. Panah kecil sudah cukup untuk itu.
      -->
      <template #column-detail>
        <font-awesome-icon icon="chevron-right" class="text-slate-300! text-[11px]!" />
      </template>
    </DataTable>

    <!--
      Dua dialog, masing-masing satu instance untuk seluruh halaman.

      Tagihan yang ditampilkan masing-masing TIDAK boleh dibagi: detail punya
      `invoiceTerpilih`, pembayaran punya `invoiceDibayar`. Semula keduanya
      membaca satu `ref` yang sama, dan karena `bukaBayar` menutup detail lebih
      dulu, form pembayaran terbuka dengan tagihan yang sudah terkosongkan --
      nominal 0, nomor kosong. Lihat catatan di `invoiceDibayar`.
    -->
    <CentralTagihanPopup
      :tampil="detailOpen"
      :invoice="invoiceTerpilih"
      @tutup="tutupDetail"
      @cetak="cetakYangTerbuka"
      @bayar="bukaBayar"
      @batal="batalkanYangTerbuka"
    />

    <CentralBayarDialog
      :tampil="bayarDialog"
      :invoice="invoiceDibayar"
      @tutup="tutupBayar"
      @selesai="setelahBayar"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { MySwal, showSuccessToast, showErrorToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { useAutoRefresh } from '@/composables/useAutoRefresh'
import { invoiceService, locationService } from '@/services/central.service'
import { formatRupiah } from '@/utils/format'
import BaseSelect from '@/presentations/components/ui/BaseSelect.vue'
import DataTable from '@/presentations/components/ui/DataTable.vue'
import CentralBayarDialog from '@/presentations/views/central/components/CentralBayarDialog.vue'
import CentralTagihanPopup from '@/presentations/views/central/components/CentralTagihanPopup.vue'
import '@/assets/css/central-form.css'

const route = useRoute()
const router = useRouter()
const uiStore = useUiStore()
const loading = computed(() => uiStore.loading)

const invoices = ref([])
const locations = ref([])
const page = ref(1)

/*
 * Kata kunci pencarian & ukuran halaman.
 *
 * `perPage` sengaja setter-nya memanggil server, bukan memotong baris di
 * peramban. `DataTable` memotong `data` kalau `perPage` tidak di-*bind* dua
 * arah, jadi kalau nilainya hanya disimpan lokal, tabel akan tetap menampilkan
 * seluruh isi halaman yang sama -- kotak "Tampilkan 10 data" bergerak tapi
 * jumlah barisnya tidak pernah berubah. Itu yang membuat `per_page` ikut dikirim
 * ke `InvoiceController::index`.
 *
 * Default 10, sama dengan daftar Lokasi dan Data Pelanggan.
 *
 * Ini perubahan dari 50. Angka lama sudah benar secara teknis -- `DataTable`
 * menghitung "Showing X to Y of Z" dari `perPage`, dan selama keduanya sama,
 * baris itu benar. Tapi 50 membuat halaman ini satu-satunya di panel pusat yang
 * mulai dari lima kali jumlah baris semua halaman lain, jadi kotak "Tampilkan
 * ... data" terlihat tidak sengaja berisi angka berbeda di satu halaman saja.
 *
 * Angka yang sama di semua halaman lebih berguna daripada angka yang optimal di
 * satu halaman: 10 sudah dipakai `usePelanggan` dan SELURUH halaman lain yang
 * memakai `DataTable`, jadi tidak ada yang perlu dipelajari ulang.
 *
 * Catatan: backend masih menerima `per_page` sampai 200, jadi memilih 100 lewat
 * kotak itu tetap benar-benar mengambil 100 baris dari server, bukan memotong
 * 10 baris yang sudah diunduh.
 */
const searchQuery = ref('')
const perPage = ref(10)

/*
 * Debounce 350ms untuk pencarian.
 *
 * Tanpa itu, mengetik "PDAM" menembak empat request dalam dua detik, dan tiap
 * balasan yang datang berbalik urutan bisa menuliskan hasil kata kunci yang
 * berbeda dari yang paling baru diketik. Pola yang sama dipakai `usePelanggan`.
 */
let searchTimer = null

watch(searchQuery, () => {
  if (searchTimer) clearTimeout(searchTimer)

  searchTimer = setTimeout(() => {
    // Searching selalu kembali ke halaman 1 -- hasil di halaman 5 bisa jadi
    // halaman 2 setelah disaring, dan halaman 4 bisa jadi tidak ada sama
    // sekali. Lihat `resetPage` untuk alasan yang sama berlaku saat filter
    // Select berubah.
    page.value = 1
    loadData()
  }, 350)
})

// Endpoint tagihan memakai pagination sisi server (10 per halaman, sesuai
// `perPage` di atas), jadi filter di sini harus memicu request ulang -- bukan
// sekadar menyembunyikan baris di tabel seperti pada daftar lokasi.
//
// Nilai kosong memakai `null`, bukan `''`. Select lama memakai `''`;
// PrimeVue memakai `null` untuk "tidak ada pilihan", jadi kalau filter
// tetap `''` maka request tetap terkirim tanpa parameter -- hasil
// kebetulan sama, tapi tombol "Hapus filter" akan tampak tidak
// melakukan apa-apa karena `''` tidak sama dengan nilai kosong PrimeVue.
const filters = reactive({ status: null, tenant_id: null })

/*
 * Filter awal dari URL.
 *
 * Halaman detail tenant menautkan ke sini dengan `?tenant_id=<id>`, supaya
 * "Riwayat Tagihan" membuka daftar yang sudah difilter lokasi itu -- bukan
 * seluruh tagihan panel pusat dengan lokasi yang harus dicari sendiri di
 * dropdown. Tanpa ini, tautannya membuka halaman yang benar tapi isinya
 * tidak, dan orang mengira tautannya salah.
 *
 * Dibaca sekali di `onMounted`, bukan jadi `watch`/`computed`: query ini
 * hanya jadi nilai awal, lalu filter di bawah yang jadi pegangan. Menjadikannya
 * dua sumber kebenaran membuat perubahan filter di UI langsung ditimpa lagi
 * oleh URL di muat ulang berikutnya.
 */
const tenantFromUrl = Number.parseInt(route.query.tenant_id, 10)
if (Number.isInteger(tenantFromUrl) && tenantFromUrl > 0) {
  filters.tenant_id = tenantFromUrl
}

/*
 * Tulis filter kembali ke URL saat berubah.
 *
 * Tanpa ini, URL `/tagihan?tenant_id=3` yang dibuka dari detail tenant akan
 * tetap tertulis `tenant_id=3` walau Select lokasi sudah dikembalikan ke
 * "Semua Lokasi" -- lalu reload mengembalikan filter yang tadi sudah
 * dibuang. Tautan yang disimpan atau dibagikan jadi bohong soal apa yang
 * sedang dilihat.
 *
 * `replace` bukan `push`: mengganti filter bukan berpindah ke halaman lain,
 * dan menumpuk tiap perubahan filter di riwayat peramban hanya menambah
 * langkah di tombol "kembali" yang tidak pernah dipakai.
 */
const syncFilterKeUrl = () => {
  const query = {}

  if (filters.status) query.status = filters.status
  if (filters.tenant_id) query.tenant_id = String(filters.tenant_id)

  router.replace({ query })
}

/*
 * Opsi filter.
 *
 * Status: tiga opsi statis, tanpa pencarian -- memilih antara "Belum
 * Lunas" dan "Lunas" tidak perlu mengetik, dan kotak pencarian hanya
 * menambah klik.
 *
 * Lokasi: daftar tumbuh seiring jumlah tenant, dan nama lokasinya
 * panjang serta mirip satu sama lain ("BPR Bergas Ayu" vs "PDAM Bergas
 * Ayu"). Di sini pencarian yang menentukan -- menggulir 40 item lebih
 * lambat daripada mengetik 3 huruf.
 *
 * Yang "Semua" ikut jadi opsi (`value: null`), bukan sekadar
 * `placeholder`: kalau "Semua Lokasi" hanya placeholder, select yang
 * terfilter tetap menampilkan "Semua Lokasi" padahal sedang menampilkan
 * satu lokasi saja. Dua Select dengan daftar berbeda tidak bisa
 * diperlakukan sama.
 */
const STATUS_OPTIONS = [
  { label: 'Semua Status', value: null },
  { label: 'Belum Lunas', value: 'UNPAID' },
  { label: 'Lunas', value: 'PAID' },
]

const TENANT_OPTIONS = computed(() => [
  { label: 'Semua Lokasi', value: null },
  ...locations.value.map((row) => ({
    label: row.label || row.nama || row.slug,
    value: row.id,
  })),
])

/*
 * Metadata paginator dari server.
 *
 * `per_page: 10` di sini hanya nilai sampai balasan pertama tiba -- bukan
 * penentu. Sesudah itu `loadData()` menimpanya dengan `body.per_page`, karena
 * angka yang benar untuk baris "Showing X to Y of Z" adalah angka yang
 * benar-benar dipakai server. Kalau nilai awal ini 50 sementara server bagi 10,
 * layar pertama akan sempat menulis "Showing 1 to 10 of 300" dengan
 * `perPage` yang salah -- dan tidak ada yang mengoreksinya sampai reload.
 */
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  from: 0,
  to: 0,
  total: 0,
})

/*
 * Angka ringkasan di toolbar.
 *
 * Diambil dari `ringkasan` milik server, BUKAN dijumlahkan dari `invoices`.
 *
 * `invoices` hanya isi satu halaman (10 baris sejak `perPage` diubah), jadi
 * menjumlahkannya di sini menghasilkan angka yang benar selama total tagihan
 * masih di bawah 10 dan diam-diam salah setelah itu -- sementara dashboard
 * menghitung dari seluruh tabel. Dua halaman berbeda untuk data yang sama,
 * tanpa error apa pun.
 *
 * `ringkasan` di backend memakai filter LOKASI dan PENCARIAN yang sama
 * dengan daftar, tapi TIDAK memakai filter status. Alasannya: filter status
 * ikut masuk akan membuat "Belum Lunas" dan "Total Tunggakan" jadi nol
 * begitu yang disaring adalah "Lunas" -- dan angka nol itu akan dibaca
 * sebagai "tidak ada tunggakan sama sekali".
 *
 * Jadi memfilter "Lunas" tidak mengubah angka ringkasan: angka itu tetap
 * menjawab "sepanjang ini, berapa yang belum dibayar" -- pertanyaan yang
 * sama dengan yang dijawab dashboard.
 */
const summary = reactive({
  belumLunas: 0,
  totalTunggakan: 0,
  lunas: 0,
  totalLunas: 0,
})

/*
 * Definisi kolom tabel.
 *
 * Kolom terakhir ("detail") tidak punya `key` di backend -- ia cuma tempat
 * panah kecil sebagai penanda bahwa baris itu bisa diklik. Karena `rowClickable`
 * sudah membuat seluruh baris jadi target klik, kolom itu hanya mengulang
 * petunjuk yang sama secara visual; isinya kosong untuk semua baris.
 */
const tableColumns = [
  { key: 'nomor', title: 'Nomor' },
  { key: 'lokasi', title: 'Lokasi' },
  { key: 'status', title: 'Status', thClass: 'text-center!', tdClass: 'text-center!' },
  { key: 'jumlah', title: 'Jumlah', thClass: 'text-right!', tdClass: 'text-right!' },
  { key: 'detail', title: '', thClass: 'w-8!', tdClass: 'text-right!' },
]

/*
 * Filter berubah: kembali ke halaman pertama, muat ulang, catat ke URL.
 *
 * Kembali ke halaman 1 bukan tambahan kecil: backend memaginasi sebelum
 * memfilter, jadi filter baru yang diterapkan di halaman 3 bisa saja tidak
 * punya baris sama sekali -- dan hasilnya "tagihan tidak ditemukan" padahal
 * datanya ada di halaman 1.
 */
const resetPage = () => {
  page.value = 1
  syncFilterKeUrl()
  loadData()
}

/*
 * Pagination pindah dari tombol buatan sendiri ke tombol milik `DataTable`.
 *
 * `DataTable` menyimpan nomor halaman di dalam dirinya sendiri, dan menuliskannya
 * keluar lewat event `update:current-page`. Yang dipakai di sini adalah event
 * itu, bukan `v-model:current-page`, karena sumber kebenaran tetap `page` milik
 * halaman ini -- `page` juga dibaca `loadData()` untuk mengirim `?page=` ke
 * backend, dan `resetPage()` menaruhnya kembali ke 1. Dua state halaman yang
 * berjalan bersamaan akan membuat tombol menggambar nomor yang berbeda dari
 * yang benar-benar diminta ke server.
 *
 * Perhatikan bahwa `meta.current_page` TIDAK dipakai di sini: ia diisi dari
 * balasan server, jadi penaikannya baru berlaku setelah request selesai --
 * tombol akan tertinggal satu klik di belakang kalau nilainya ditulis balik.
 */
const onPageChange = (target) => {
  if (target === page.value) return

  page.value = target
  loadData()
}

/*
 * "Tampilkan ... data" berubah: memuat ulang dari server.
 *
 * Dua hal terjadi di sini dan keduanya wajib:
 *
 * 1. `per_page` ikut dikirim. Kalau tidak, backend tetap membalas dengan 10
 *    baris dan kotak yang bergerak tidak mengubah apa pun.
 *
 * 2. Kembali ke halaman 1. Memakai halaman 3 dengan ukuran 10 membuat
 *    `?page=3&per_page=10` -- posisi yang menunjuk ke baris 21-30 dari daftar
 *    yang isinya 7 baris. Halaman kosong tanpa pesan adalah hasil yang paling
 *    membingungkan dari form yang bisa dibuat salah.
 */
const onPerPageChange = (size) => {
  if (size === perPage.value) return

  perPage.value = size
  page.value = 1
  loadData()
}

const loadData = async (options = {}) => {
  const silent = options.silent === true

  try {
    const params = { page: page.value, per_page: perPage.value }
    if (filters.status) params.status = filters.status
    if (filters.tenant_id) params.tenant_id = filters.tenant_id

    const keyword = searchQuery.value.trim()
    if (keyword !== '') params.search = keyword

    const res = await invoiceService.getAll(params, { silent })
    const body = res.data?.data || {}

    invoices.value = body.data || []
    Object.assign(meta, {
      current_page: body.current_page ?? 1,
      last_page: body.last_page ?? 1,
      // `per_page` ikut dibaca supaya baris "Showing X to Y of Z entries"
      // menghitung lebar halaman dari angka server, bukan dari default 10
      // milik `DataTable`. Tanpa ini, setelah orang memilih 25 baris, baris itu
      // akan tetap menulis "Showing 1 to 10 of 1200".
      //
      // Fallback-nya `10`, bukan angka lama 50: kalau server tidak mengirim
      // field ini, satu-satunya `per_page` yang benar adalah yang baru
      // dikirim di baris atas -- bukan angka yang pernah jadi default backend.
      per_page: body.per_page ?? perPage.value,
      from: body.from ?? 0,
      to: body.to ?? 0,
      total: body.total ?? 0,
    })

    /*
     * Angka tunggakan datang terpisah dari `data` paginator. Kalau backend
     * belum mengirim `ringkasan` (mis. cache service worker masih menyimpan
     * respons lama), nilainya dibiarkan 0 -- bukan dihitung ulang dari baris
     * halaman ini, karena itulah perhitungan yang salah yang sengaja
     * dihapus di sini.
     */
    const ringkasan = res.data?.ringkasan
    if (ringkasan) {
      summary.belumLunas = Number(ringkasan.belumLunas || 0)
      summary.totalTunggakan = Number(ringkasan.totalTunggakan || 0)
      summary.lunas = Number(ringkasan.lunas || 0)
      summary.totalLunas = Number(ringkasan.totalLunas || 0)
    }

    /*
     * Halaman yang sedang dibuka bisa hilang kalau tagihan di dalamnya sudah
     * dilunasi dari tab lain: `UNPAID` di halaman 3 jadi kosong setelah
     * tersisa 2 halaman. Poll diam-diam tidak akan membuat user terjebak di
     * halaman kosong, tapi memaksa mundur halaman saat dia sedang membaca
     * baris terakhir juga terasa terduga.
     *
     * Karena itu koreksi hanya terjadi kalau halaman benar-benar habis dan
     * masih ada halaman sebelumnya -- kondisi yang jelas salah, bukan sekadar
     * berubah. Perubahan jumlah baris biasa dibiarkan sampai user yang
     * menekan paginasi.
     */
    if (invoices.value.length === 0 && meta.current_page > (meta.last_page || 1)) {
      page.value = meta.last_page || 1
    }
  } catch (error) {
    // Poll latar gagal harus diam; lihat catatan di `useAutoRefresh`.
    if (silent) return

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

/*
 * Tagihan yang sedang dibuka di pop-up detail.
 *
 * `null` berarti pop-up tertutup. Satu penanda, bukan boolean terpisah --
 * dua penanda (terbuka/tidak + objek) bisa tidak sinkron, dan ketika tidak
 * sinkron yang muncul adalah pop-up kosong yang tidak bisa ditutup. Sama
 * seperti `popupRincian` di `CentralDashboardView.vue`.
 *
 * PUNYA SENDIRI, tidak dipakai bersama dengan dialog pembayaran. Semula satu
 * `ref` dipakai keduanya, dan itu sebab bugnya: `bukaBayar` menutup detail
 * lebih dulu, jadi tagihannya jadi `null` -- dan form pembayaran yang
 * langsung dibuka sesudahnya menerima `null`, sehingga nominal, nomor, dan
 * nama lokasinya kosong. Formnya terbuka tapi tidak tahu tagihan yang mana.
 */
const invoiceTerpilih = ref(null)

const detailOpen = computed(() => invoiceTerpilih.value !== null)

const bukaDetail = (inv) => {
  invoiceTerpilih.value = inv
}

const tutupDetail = () => {
  invoiceTerpilih.value = null
}

/*
 * "Tandai Lunas" tidak lagi memakai konfirmasi SweetAlert.
 *
 * SweetAlert hanya bisa menampilkan tombol ya/tidak, sementara
 * pertanyaan yang harus dijawab di sini bukan "yakin?" tapi "dibayar
 * pakai apa, tanggal berapa, masuk ke rekening mana" -- tiga isian.
 * Menjawab tiga isian di dialog yang isinya tombol tidak mungkin, dan
 * memaksanya berarti menerima pembayaran tanpa tahu dari mana uangnya
 * masuk.
 *
 * Dialognya berbentuk, jadi siapa pun yang mengonfirmasi meninggalkan
 * catatan yang bisa dibaca: tanggal, metode, dan rekening.
 */
const bayarDialog = ref(false)

/*
 * Tagihan yang sedang dibayar.
 *
 * PUNYA SENDIRI, terpisah dari `invoiceTerpilih` milik pop-up detail.
 *
 * Alasannya bukan kerapian: kedua dialog ini hidup bersamaan selama beberapa
 * perpindahan -- dari pop-up detail, tombol "Tandai Lunas" menutup detail lalu
 * membuka dialog pembayaran. Kalau keduanya berbagi satu `ref`, penutupannya
 * yang pertama akan menghapus tagihan yang justru dibutuhkan yang kedua, dan
 * formnya terbuka dengan `invoice = null`: nominal 0, nomor kosong, nama
 * lokasi kosong. Form tetap bisa dikirim, dan yang terkirim adalah
 * pembayaran untuk tagihan yang tidak diketahui -- ditolak backend dengan
 * pesan yang membingungkan, atau, lebih buruk, pembayaran yang tercatat
 * pada tagihan yang salah.
 */
const invoiceDibayar = ref(null)

/*
 * Membuka dialog pembayaran.
 *
 * Dua pemanggil, dua bentuk:
 *
 *   bukaBayar(inv)    dari daftar tagihan (blokir langsung)
 *   bukaBayar()       dari kaki pop-up detail
 *
 * Bentuk tanpa argumen membaca `invoiceTerpilih`, karena pemanggil dari
 * pop-up tidak perlu menyalin tagihannya ke tempat lain -- sudah ada di sana.
 */
const bukaBayar = (inv = null) => {
  const tagihan = inv || invoiceTerpilih.value

  // Tanpa tagihan tidak ada yang bisa dibayar. Membuka form kosong lebih buruk
  // daripada tidak membukanya: formnya terlihat bisa diisi, lalu gagal saat
  // disimpan dengan pesan yang tidak menjelaskan apa pun.
  if (!tagihan) return

  /*
   * Pop-up detail ditutup DULU, sebelum dialog pembayaran dibuka.
   *
   * Keduanya di `Teleport` ke `<body>` dengan z-index yang sama, jadi yang
   * dirender belakangan ada di atasnya. Kalau detail dibiarkan terbuka, yang
   * menutupi dialog pembayaran adalah panel detail -- dan tombol "Catat
   * Pembayaran" jadi tidak bisa diklik karena panel lain menutupinya.
   *
   * Urutan ini aman sekarang karena tagihannya sudah disalin ke
   * `invoiceDibayar` pada baris di atas, SEBELUM detail ditutup.
   */
  tutupDetail()

  invoiceDibayar.value = tagihan
  bayarDialog.value = true
}

const tutupBayar = () => {
  bayarDialog.value = false
  invoiceDibayar.value = null
}

const setelahBayar = async (terbayar) => {
  tutupBayar()

  showSuccessToast(
    terbayar?.tgl_lunas ? `Pembayaran ${terbayar.tgl_lunas} dicatat.` : 'Pembayaran dicatat.',
  )

  await loadData()
}

/** Cetak dari kaki pop-up detail. */
const cetakYangTerbuka = () => {
  if (invoiceTerpilih.value) printInvoice(invoiceTerpilih.value)
}

/*
 * Batalkan lunas dari kaki pop-up detail.
 *
 * Handler ini menutup detail SETELAH konfirmasi berhasil, bukan sebelum:
 * kalau pop-up ditutup duluan dan orang ternyata menekan "Batal", daftar
 * tagihan yang kembali ke belakang tidak lagi menampilkan baris yang tadi
 * dipilih -- dan pop-up-nya sudah hilang, jadi tidak ada jalan kembali tanpa
 * mencari baris itu dari awal.
 */
const batalkanYangTerbuka = async () => {
  if (invoiceTerpilih.value) await markUnpaid(invoiceTerpilih.value)
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

/*
 * Auto-refresh daftar tagihan.
 *
 * Status lunas/belum lunas paling sering berubah dari tab lain. Daftar tagihan
 * biasanya dibuka untuk "cek ada yang menunggak", bukan untuk mengedit. Poll
 * senyap supaya `loading` (yang di sini `computed(() => uiStore.loading)`)
 * tidak menutupi tabel dengan "Memuat data..." tiap beberapa detik.
 *
 * Interval 45 detik, bukan 30: halaman ini memakai paginasi, dan poll terlalu
 * sering membuat user berpindah halaman saat sedang membaca baris.
 */
useAutoRefresh(() => loadData({ silent: true }), { interval: 45000 })

/**
 * Cetak invoice.
 *
 * Dipanggil langsung dari handler klik, tanpa `await` di depan
 * `window.open` -- peramban memblokir popup yang dibuka setelah ada
 * jeda, dan request axios pastilah menambah jeda itu.
 */
const printInvoice = async (inv) => {
  try {
    await invoiceService.openPdf(inv.id)
  } catch (error) {
    showErrorToast(
      error?.response?.status === 401
        ? 'Sesi panel pusat habis. Login ulang untuk mencetak.'
        : error,
    )
  }
}
</script>

<style scoped>
.tagihan-page {
  /* Lebar maksimum yang sama dengan halaman panel pusat yang lain. */
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  /* Elevasi penuh, sama dengan kartu utama halaman lain. Nilai dari
     CentralLayout.vue -- dulu kartu tagihan satu-satunya yang tidak punya
     bayangan sama sekali, jadi kelihatan lebih "datar" dari kartu tenant
     di sebelahnya. */
  box-shadow: var(--central-shadow-card);
  overflow: hidden;
}

/*
 * Toolbar: filter di kiri, ringkasan di kanan, satu baris.
 *
 * `flex-wrap: nowrap` di `.tagihan-toolbar` yang memastikan dua kelompok ini
 * tidak pernah saling menyingkir ke baris berbeda -- tidak ada kondisi
 * di mana ringkasan turun ke bawah filter hanya karena layar sedikit
 * lebih sempit.
 *
 * Filter SENDIRI boleh membungkus, dan itu yang membuatnya tetap terbaca
 * saat layar sempit: lebih baik filter turun ke baris kedua (masih
 * kelihatan penuh) daripada terjepit sampai labelnya terpotong.
 *
 * CATATAN: semua aturan di bawah menyasar select yang dirender
 * `BaseSelect.vue`, jadi scoped style halaman ini TIDAK bisa menyentuhnya
 * langsung. Alasannya di `.tagihan-filters` di bawah.
 */
.tagihan-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: nowrap;
}

.tagihan-filters {
  display: flex;
  gap: 0.6rem;
  flex-wrap: wrap;
  /* Tanpa ini, `.tagihan-filters` ikut melar karena `.tagihan-toolbar`
     `nowrap`: flex item punya `min-width: auto` sehingga menolak menyusut
     di bawah lebar isi, dan dua select yang saling berebut ruang membuat
     kelompok ini mendorong ringkasan keluar layar. */
  min-width: 0;
}

/*
 * ==========================================================================
 * LEBAR FILTER -- harus lewat `:deep()`, dan itu bukan pilihan gaya
 * ==========================================================================
 *
 * `BaseSelect.vue` merender elemen `<div class="p-select ...">` dari dalam
 * file-nya sendiri. Elemen itu punya `data-v-BASESELECT`, bukan
 * `data-v-CENTRALTAGIHAN`.
 *
 * Dan elemen root komponen anak TIDAK mewarisi scope-id parent. Diverifikasi
 * dengan render sungguhan (`@vue/server-renderer`):
 *
 *   - child SATU root   -> `data-v-child data-v-induk`  (dua-duanya)
 *   - child DUA root    -> `data-v-child` saja          (scope parent hilang)
 *
 * `BaseSelect` punya dua root (`<Select>` + `<small>` untuk pesan validasi),
 * jadi ia termasuk kasus kedua. Akibatnya aturan `.tagihan-select` yang
 * tertulis scoped di file ini dikompilasi jadi
 *
 *     .tagihan-select[data-v-CENTRALTAGIHAN] { ... }
 *
 * dan TIDAK PERNAH cocok dengan elemen select yang benar-benar dirender.
 * Bukan karena class-nya hilang -- `class="tagihan-select"` sampai, tapi
 * karena selektor itu menuntut atribut yang elemennya tidak punya. Gejalanya
 * persis seperti "CSS-nya ada tapi tidak jalan", tanpa satu pun error.
 *
 * Aturan yang menyasar isi komponen anak SELALU perlu `:deep()` di sini.
 * Tanpa dia, perubahan lebar di bawah diam-diam tidak berlaku.
 */
.tagihan-filters :deep(.tagihan-select) {
  width: 10rem;
  max-width: 100%;
}

/*
 * Nama lokasi jauh lebih panjang dari kode status: "PAMSIDES Tirto Mulo"
 * butuh ruang, "Semua Status" tidak. Satu lebar untuk keduanya membuat
 * salah satunya selalu kelihatan berlebihan.
 *
 * `flex: 0 0 auto` mencegah select ikut melebar saat toolbar punya sisa
 * ruang. Tanpa itu `flex-basis: auto` + `width` membuat elemen tetap
 * bisa tumbuh mengikuti sisa ruang yang sebenarnya tidak ada -- bentuknya
 * berubah-ubah tiap kali jendela di-resize.
 */
.tagihan-filters :deep(.tagihan-select--lokasi) {
  width: 16rem;
  flex: 0 0 auto;
}

.tagihan-summary {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  /* Ringkasan tidak boleh dipotong/ditekan oleh filter yang melebar --
     angkanya ("Rp 7.575.000") justru bagian yang paling mudah salah
     terbaca kalau squeezed. */
  flex-shrink: 0;
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

/*
 * "Sudah Lunas" hijau, mengikuti warna yang dipakai dashboard untuk
 * kelompok "Sudah Lunas".
 *
 * Hijau di sini bukan hiasan: warna itu yang membedakan "sudah dibayar"
 * dari "belum dibayar" tanpa harus membaca labelnya. Kalau angka lunas
 * ditulis dengan warna yang sama seperti angka tunggakan, tiga angka di
 * toolbar jadi satu blok yang sama secara visual -- dan orang yang
 * butuh cepat tahu "ini sudah beres atau belum" harus membacanya satu per
 * satu.
 */
.summary-item--ok strong {
  color: #059669;
}

.summary-sep {
  width: 1px;
  height: 1.9rem;
  background: #e2e8f0;
}

/* ===== KARTU (hanya di layar sempit) =====
|
| Kartu ini dulu memuat delapan informasi sekaligus: tanggal terbit, jatuh
| tempo, tanggal bayar, rekening tujuan, plus tiga tombol aksi. Di 390px itu
| jadi daftar panjang yang isinya berulang -- dan lima di antaranya sekarang
| tinggal satu klik di pop-up detail.
|
| Yang tersisa di sini tiga hal saja: identitas (nomor + lokasi), nominal,
| dan satu petunjuk singkat. Sisanya dibaca dari pop-up, yang sekaligus satu
| tempat untuk menjalankan aksi.
|
| Disembunyikan lewat CSS, bukan `v-if`, supaya `invoices` tidak
| dirender dua kali di DOM.
*/
.tagihan-cards {
  display: none;
  list-style: none;
  margin: 0;
  padding: 1rem;
  gap: 0.6rem;
}

.tagihan-card {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.8rem 0.85rem;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  /* Kartu di dalam kartu utama -- pasangan `--central-shadow-sm`, sama seperti
     kartu tenant di tampilan HP. */
  box-shadow: var(--central-shadow-sm);
  /* Penanda bahwa ini bukan teks, tapi target klik. `cursor: pointer` saja
     sudah cukup di sini; warna teks tidak berubah karena kartu HP sering
     dipakai sambil berdiri di depan orang, dan highlight biru yang menyala
     saat tidak sengaja tersentuh lebih mengganggu daripada membantu. */
  cursor: pointer;
  transition:
    border-color 0.16s ease,
    box-shadow 0.16s ease,
    transform 0.16s ease;
}

.tagihan-card:hover {
  border-color: #bae6fd;
  box-shadow: var(--central-shadow-sm-hover);
}

.tagihan-card:active {
  transform: scale(0.99);
}

.tagihan-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
}

.tagihan-card__ident {
  min-width: 0;
}

.tagihan-card__number {
  margin: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.76rem;
  font-weight: 700;
  color: #0f172a;
  overflow-wrap: anywhere;
}

.tagihan-card__tenant {
  margin: 0.1rem 0 0;
  font-size: 0.8rem;
  font-weight: 700;
  color: #0f172a;
}

.tagihan-card__amount {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 800;
  color: #0f172a;
}

/*
 * Kaki kartu: satu baris petunjuk, bukan tiga tombol.
 *
 * Kedua aksi yang tersisa (tandai lunas, batalkan lunas) pindah ke kaki
 * pop-up detail -- jadi hanya ada satu tempat untuk menjalankan keduanya,
 * bukan satu tempat di kartu HP dan satu lagi di pop-up.
 */
.tagihan-card__foot {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.15rem;
  padding-top: 0.4rem;
  border-top: 1px solid #f1f5f9;
  font-size: 0.68rem;
  font-weight: 700;
}

.tagihan-card__late,
.tagihan-card__due,
.tagihan-card__hint {
  display: inline-flex;
  align-items: center;
  gap: 0.28rem;
  min-width: 0;
}

.tagihan-card__late {
  color: #dc2626;
}

.tagihan-card__due {
  color: #64748b;
}

/*
 * Petunjuk "ketuk untuk detail" -- hanya tampil kalau tagihan belum terlambat
 * dan tidak punya tanggal jatuh tempo eksplisit. Tanpa keterangan ini, kartu
 * yang isinya cuma tiga baris bisa terbaca sebagai dokumen, bukan sesuatu
 * yang bisa diklik.
 */
.tagihan-card__hint {
  color: #94a3b8;
  font-weight: 600;
}

/*
 * Kartu DataTable.
 *
 * `DataTable` sudah membawa kartunya sendiri, jadi yang tersisa di sini hanya
 * dua hal: bayangan yang disamakan dengan halaman panel pusat lain, dan
 * penyembunyian tabel di layar sempit.
 *
 * Aturan tabel, baris, dan pagination yang dulu ada di file ini sudah dihapus
 * -- semuanya sekarang milik `DataTable`. Tidak satu pun selector itu masih dipakai
 * template, dan membiarkannya hanya jadi jebakan untuk orang berikutnya yang
 * mengira masih ada yang bisa diubah di sini.
 */
.tagihan-datatable :deep(.shadow-sm) {
  box-shadow: var(--central-shadow-card);
}

/*
 * Tampilan HP tetap berupa kartu, bukan tabel sempit.
 *
 * Lima kolom (termasuk "Jumlah" dan "Status") memang muat di 390px, tapi
 * isinya cuma nomor, lokasi, dan nominal -- tiga baris yang menjawab pertanyaan
 * "apa dan berapa". Sisanya sekarang satu klik lagi di pop-up detail, dan klik
 * itu harus tetap murah di layar kecil yang dipakai sambil berdiri di depan
 * orang.
 *
 * Disembunyikan lewat CSS (bukan `v-if`) supaya `invoices` tidak di-render dua
 * kali.
 */
@media (max-width: 48rem) {
  .tagihan-datatable {
    display: none;
  }

  .tagihan-cards {
    display: flex;
    flex-direction: column;
  }
}

/*
 * 48rem, sama seperti blok tepat di atasnya.
 *
 * Dua blok ini mengunci hal yang mestinya bergantian bersamaan: blok
 * pertama menyembunyikan tabel dan menampilkan kartu, blok kedua menyusun
 * ulang toolbar. Batasnya harus identik.
 *
 * Ditulis `768px` sebelumnya. Pada zoom 100% sama saja dengan `48rem`,
 * tapi `px` ikut ter-skala saat zoom atau ukuran font perangkat berubah
 * sedangkan `rem` tidak -- jadi di lebar 760-767px pada zoom 110%,
 * kartu sudah tampil sementara toolbar masih tersusun mendatar.
 */
@media (max-width: 48rem) {
  .tagihan-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .tagihan-filters,
  .tagihan-summary {
    width: 100%;
  }

  /*
   * Ditumpuk, bukan berbagi lebar.
   *
   * Dua select berbagi 390px berarti masing-masing ~153px, dan
   * "Semua Lokasi" -- 12 karakter termasuk ruang -- tidak muat di
   * 153px. Label yang terpotong jadi "Semua Lo..." persis seperti
   * masalah yang `BaseSelect` ini diciptakan untuk diselesaikan:
   * user tidak bisa memastikan sedang memfilter apa.
   *
   * Menumpuk memberi tiap select lebar penuh. `min-width: 0` ikut
   * ditulis karena tanpa itu `flex-direction: column` tidak mengubah
   * apa pun: lebar tetap 10rem/16rem, bukan mengikuti kolomnya.
   *
   * `:deep()` di sini bukan opsional -- alasannya sama seperti di blok
   * lebar filter di atas. Tanpa dia, aturan mobile ini juga tidak akan
   * berlaku sama sekali.
   */
  .tagihan-filters {
    flex-direction: column;
  }

  .tagihan-filters :deep(.tagihan-select) {
    width: 100%;
    min-width: 0;
  }

  .tagihan-summary {
    justify-content: space-between;
  }
}

/*
 * Bawah 26rem (416px): kartu kehilangan bayangan hover.
 *
 * `:active` (translate 0.99) yang tetap bekerja di layar sentuh, tapi
 * `box-shadow` yang lebih tebal membuat tepi kartu terlihat bersinggungan
 * dengan kartu di bawahnya di ruang yang hanya ~250px. Nominal dan identitas
 * sudah terbaca tanpa perubahan bayangan itu.
 */
@media (max-width: 26rem) {
  .tagihan-card:hover {
    box-shadow: var(--central-shadow-sm);
  }
}
</style>
