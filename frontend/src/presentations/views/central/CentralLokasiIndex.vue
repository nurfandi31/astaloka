<template>
  <div class="lokasi-wrap">
    <!--
      Tombol "Tambah Lokasi" DI LUAR kartu tabel, di baris sendiri di atasnya.

      Alasannya bukan sekadar tata letak. `DataTable` sudah membawa dua hal
      di dalam toolbar-nya: "Tampilkan ... data" di kiri dan kotak pencarian di
      kanan. Menitipkan tombol tambah lewat slot `search-actions` pernah
      dicoba -- hasilnya tombol duduk di dalam kartu, menempel pada kontrol
      yang isinya tidak terkait (menampilkan jumlah baris), sehingga tombol
      utama halaman terlihat seperti bagian dari kelompok angka itu.

      Yang dilakukan di sini: tombol diangkat ke luar, jadi kartu tabel
      kembali murni data, dan aksinya berdiri sendiri di tempat orang mengira
      akan mencari "tambah" -- pojok kanan, di atas tabel.

      Baris ini `justify-content: flex-end` karena memang tidak ada apa pun di
      sebelah kirinya: aksi utama halaman dan kontrol utama halaman sama-sama
      berada di ujung kanan.

      Tombolnya memakai ukuran STANDAR (`BaseButton` tanpa `size="sm"`), bukan
      ukuran kecil. Alasannya: "Tambah Lokasi" adalah aksi utama halaman ini --
      satu-satunya cara membuat baris baru -- dan `sm` di sini menjadikannya
      setipis tombol filter di dalam toolbar tabel, padahal keduanya tidak
      sekelas: yang satu membuat data, yang cuma memilih apa yang tampil.
      Warna biru gradien + bayangannya sudah sama persis dengan tombol utama
      di halaman panel pusat lain, jadi yang membedakan cuma ukurannya -- dan
      itu memang seharusnya berbeda.
    -->
    <div class="lokasi-topbar">
      <BaseButton
        to="/central/lokasi/tambah"
        variant="primary-gradient"
        icon="plus"
        class="lokasi-add!"
      >
        Tambah Lokasi
      </BaseButton>
    </div>

    <DataTable
      v-model="searchQuery"
      :data="filteredLocations"
      :columns="tableColumns"
      title=""
      v-model:current-page="currentPage"
      v-model:per-page="perPage"
      :total-entries="filteredLocations.length"
      search-placeholder="Cari nama, slug, atau domain..."
      :loading="loading"
      empty-title="Lokasi Tidak Ditemukan"
      empty-message="Mohon cek kembali kata kunci pencarian Anda atau daftarkan lokasi baru."
      empty-icon="location-dot"
      class="lokasi-table"
    >
      <template #column-lokasi="{ row }">
        <div class="flex flex-col">
          <span class="text-[13px]! font-bold! text-slate-800!">{{ row.label }}</span>
          <span class="font-mono! text-[11px]! text-slate-400!">{{ row.slug }}</span>
        </div>
      </template>

      <template #column-database="{ row }">
        <span class="font-mono! text-slate-600! text-[12px]!">{{ row.database }}</span>
        <font-awesome-icon
          v-if="row.database_ada"
          icon="circle-check"
          class="ml-1.5! text-emerald-500!"
          title="Database tersedia"
        />
        <font-awesome-icon
          v-else
          icon="circle-exclamation"
          class="ml-1.5! text-amber-500!"
          title="Database tidak ditemukan di server"
        />
      </template>

      <template #column-domain="{ row }">
        <span class="font-mono! text-slate-600! text-[12px]!">{{ row.domain || '—' }}</span>
      </template>

      <template #column-tagihan="{ row }">
        <span
          v-if="(row.tagihan_belum_lunas || 0) > 0"
          class="inline-block! px-2! py-0.5! rounded-full! text-[10px]! font-extrabold! uppercase! tracking-wide! bg-rose-50! text-rose-600!"
        >
          {{ row.tagihan_belum_lunas }} · {{ formatRupiah(row.total_tagihan) }}
        </span>
        <span v-else class="text-[12px]! font-bold! text-emerald-600!">Lunas</span>
      </template>

      <template #column-berakhir="{ row }">
        <span class="font-mono! text-slate-600! text-[12px]!">{{ row.tgl_berakhir || '∞' }}</span>
      </template>

      <template #column-status="{ row }">
        <span
          class="inline-block! px-2! py-0.5! rounded-full! text-[10px]! font-extrabold! uppercase! tracking-wide!"
          :class="
            row.is_active ? 'bg-emerald-50! text-emerald-600!' : 'bg-slate-100! text-slate-500!'
          "
        >
          {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
        </span>
      </template>

      <template #column-aksi="{ row }">
        <div class="flex items-center justify-end gap-2!">
          <BaseButton
            variant="ghost"
            size="sm"
            @click="goToDetail(row)"
            class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-blue-200! hover:bg-blue-50! text-slate-600! hover:text-blue-600! shadow-sm!"
            title="Lihat detail"
            icon="eye"
          />
          <BaseButton
            variant="ghost"
            size="sm"
            @click="goToEdit(row)"
            class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-blue-200! hover:bg-blue-50! text-slate-600! hover:text-blue-600! shadow-sm!"
            title="Ubah"
            icon="pen"
          />
          <BaseButton
            variant="ghost"
            size="sm"
            @click="handleToggleActive(row)"
            class="w-8! h-8! p-0! rounded-lg! border! border-slate-100! hover:border-red-200! hover:bg-red-50! text-slate-600! hover:text-red-600! shadow-sm!"
            :title="row.is_active ? 'Nonaktifkan' : 'Aktifkan kembali'"
            :icon="row.is_active ? 'ban' : 'rotate-left'"
          />
        </div>
      </template>
    </DataTable>

    <!--
      EmptyState bawaan DataTable punya tombol "Daftarkan Lokasi Pertama"
      yang hanya relevan saat tabel benar-benar kosong, bukan saat hasil
      pencarian tidak cocok. `DataTable` tidak menerima slot isi, jadi pemicunya
      ditiru di luar -- dan hanya saat tabel benar-benar kosong.
    -->
    <div v-if="!loading && locations.length === 0" class="lokasi-first">
      <router-link to="/central/lokasi/tambah" class="lokasi-first__cta">
        <font-awesome-icon icon="plus" />
        Daftarkan Lokasi Pertama
      </router-link>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { MySwal, showErrorToast, showSuccessToast } from '@/utils/swal'
import { useUiStore } from '@/stores/uiStore'
import { locationService } from '@/services/central.service'
import { formatRupiah } from '@/utils/format'
import DataTable from '@/presentations/components/ui/DataTable.vue'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'

const router = useRouter()
const uiStore = useUiStore()

const loading = computed(() => uiStore.loading)
const locations = ref([])

/*
 * State pagination & pencarian disamakan dengan `usePelanggan` supaya tabel
 * ini berperilaku sama persis dengan Data Pelanggan: kotak "Tampilkan
 * 10/25/50/100 data" di kiri, search di kanan, pagination di kaki kartu.
 *
 * Backend `/central/lokasi` BELUM berpaginasi -- `LocationController::index`
 * selalu `get()` seluruh tabel. Karena itu `serverSide` tidak diaktifkan dan
 * pemotongan halaman dikerjakan di sisi peramban oleh `DataTable` sendiri.
 * Mengaktifkannya tanpa mengubah backend akan membuat "Showing 1 to 10 of 500"
 * padahal isinya hanya satu halaman server -- angka yang lebih parah bohongnya
 * daripada tidak ada pagination sama sekali.
 *
 * Kalau nanti daftarnya tumbuh sampai ratusan tenant, yang perlu ditambahkan
 * `paginate()` + `search` di `LocationController::index` dan menyalakan prop
 * `server-side` di sini. Pagination peramban sudah cukup untuk sekarang.
 */
const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(10)

const tableColumns = [
  { key: 'lokasi', title: 'Lokasi' },
  { key: 'database', title: 'Database' },
  { key: 'domain', title: 'Domain' },
  { key: 'tagihan', title: 'Tunggakan', thClass: 'text-right!', tdClass: 'text-right!' },
  { key: 'berakhir', title: 'Berakhir' },
  { key: 'status', title: 'Status', thClass: 'text-center!', tdClass: 'text-center!' },
  { key: 'aksi', title: 'Aksi', thClass: 'text-right!', tdClass: 'text-right!' },
]

/*
 * Tidak ada filter status di halaman ini.
 *
 * Alasannya bukan kesederhanaan tampilan: select status hanya mengulang kolom
 * Status yang sudah ada di tabel, tapi menyingkirkan baris tanpa jejak alasan --
 * hasilnya daftar terlihat lebih pendek tanpa penjelasan, dan orang tetap
 * harus menggulir untuk mencari siapa yang nonaktif karena select-nya hilang.
 *
 * Daftar menampilkan seluruh lokasi apa adanya. Kalau sebuah lokasi
 * dinonaktifkan, efeknya sudah terlihat di kolom Status; menyembunyikannya
 * dari daftar bukan menambah kejelasan, hanya membuat orang mencari-cari.
 */
const filteredLocations = computed(() => {
  const keyword = searchQuery.value.trim().toLowerCase()

  if (!keyword) return locations.value

  return locations.value.filter(
    (row) =>
      row.label?.toLowerCase().includes(keyword) ||
      row.nama?.toLowerCase().includes(keyword) ||
      row.slug?.toLowerCase().includes(keyword) ||
      row.domain?.toLowerCase().includes(keyword) ||
      row.database?.toLowerCase().includes(keyword),
  )
})

/*
 * Mengganti kata kunci atau ukuran halaman mengembalikan ke halaman 1.
 *
 * Tanpa ini: sedang di halaman 3 lalu mengetik kata kunci yang hasilnya cuma 4
 * baris -- tabel tampil kosong dan pagination menulis "Showing 1 to 0 of
 * 4", padahal datanya ada. Kembali ke halaman 1 selalu benar karena baris
 * pertama hasil pencarian pasti ada kalau ada hasil sama sekali.
 *
 * `DataTable` tidak melakukan ini sendiri: ia memotong `data` sesuai
 * `currentPage` tanpa pernah memeriksa apakah halaman itu masih punya isi.
 * Halaman yang memfilter di sisi peramban (seperti `useDesa`) punya masalah
 * yang sama dan belum menyelesaikannya.
 */
watch([searchQuery, perPage], () => {
  currentPage.value = 1
})

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
/*
 * Lebar maksimum & jarak tepi diambil dari `--central-*` di `CentralLayout.vue`,
 * sama seperti halaman panel pusat yang lain. `.central-page` di
 * `central-page.css` sudah punya aturan yang persis sama; ditulis ulang di sini
 * supaya halaman ini tidak bergantung pada kelas global yang isinya masih
 * bisa berubah.
 */
.lokasi-wrap {
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
}

/*
 * Tujuh kolom butuh ruang, dan di layar sempit ruang itu tidak ada.
 *
 * 760px bukan angka acak: itu jumlah lebar minimum yang masih membuat
 * nama lokasi ("Tirto Mulo"), nama database, dan nominal tagihan terbaca
 * tanpa satu huruf pun terpotong. Di bawah itu, tabel menyusut ke 360px --
 * tiap kolom jadi ~50px, dan yang tersisa hanya satu huruf per kolom.
 *
 * `min-width` lebih baik daripada menyembunyikan kolom. Kolom yang
 * disembunyikan hilang tanpa jejak, jadi admin tidak tahu ada data yang
 * tidak ditampilkan; kolom yang tergulir masih bisa dibaca, dan bilah
 * gulir di bawah tabel memberi petunjuk bahwa masih ada isi di sebelah
 * kanan.
 *
 * Angka ini tidak dipakai tabel lain: `DataTable` sengaja tidak memberi
 * default, karena tabel tiga kolom di layar 360px akan ikut tergulir
 * tanpa alasan.
 */
.lokasi-table :deep(table) {
  min-width: 760px;
}

/*
 * Baris tombol tambah, di atas kartu tabel.
 *
 * Jarak ke bawah `0.85rem` bukan `1rem`: kartu tabel punya border sendiri, dan
 * jarak yang persis sama dengan jarak antar-kartu di halaman lain membuat
 * tombol terbaca sebagai kepala kartu kedua yang tidak pernah berisi.
 */
.lokasi-topbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  margin-bottom: 0.85rem;
}

/*
 * `DataTable` sudah punya kartu sendiri (putih, rounded-xl, shadow-sm), jadi
 * tidak perlu dibungkus kartu kedua. Yang perlu disamakan cuma bayangan kartu
 * supaya halaman ini tidak terlihat lebih datar dari dashboard, tagihan, dan
 * struktur akun yang semuanya memakai `--central-shadow-card`.
 */
.lokasi-table :deep(.shadow-sm) {
  box-shadow: var(--central-shadow-card);
}

.lokasi-add {
  white-space: nowrap;
}

/*
 * "Daftarkan Lokasi Pertama" muncul DI BAWAH kartu, bukan di dalam kartu.
 *
 * `EmptyState` punya slot untuk aksi, tapi `DataTable` tidak meneruskan slot
 * itu ke bawah -- jadi tombolnya tidak bisa masuk ke dalam kartu. Taruh di
 * bawah adalah pilihan yang sengaja: kalau tombol ini masuk ke dalam baris
 * kosong yang sama dengan tabel, ia akan terlihat seperti baris data yang
 * salah ketik, bukan seperti ajakan membuat data pertama.
 */
.lokasi-first {
  display: flex;
  justify-content: center;
  padding: 1rem 0 0;
}

.lokasi-first__cta {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.55rem 1rem;
  border-radius: 9px;
  background: var(--color-primary, #0ea5e9);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 700;
  text-decoration: none;
  box-shadow: var(--central-shadow-btn-primary);
}

.lokasi-first__cta:hover {
  filter: brightness(1.08);
}
</style>
